<?php
/**
 * Procurement module — data foundation (C3).
 *
 * Lazily creates the procurement-specific tables the first time they're
 * needed, same pattern as includes/Activity_Log.php's ensure_activity_log().
 * These are intentionally separate from the legacy `restock_requests` table
 * used by Manager/Finance Inventory pages — that table is untouched.
 *
 * `status` is stored as the literal PROC_STATUS_* string from
 * procurement_workflow.php, never a DB ENUM — the state machine in PHP is
 * the single source of truth for legal values/transitions, not the schema.
 */

require_once __DIR__ . '/procurement_workflow.php';

function ensure_procurement_tables(mysqli $conn): void {
    static $ready = false;
    if ($ready) return;

    // Header: one row per restock/purchase request. Branch-scoped from the
    // requester at creation time (inventory itself is branch-scoped too,
    // per the live schema, but the request keeps its own copy so branch
    // scope doesn't change retroactively if an item is re-shelved).
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS procurement_requests (
        request_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        branch_id INT UNSIGNED NOT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'DRAFT',
        requested_by INT UNSIGNED NOT NULL,
        requested_by_name VARCHAR(150) NOT NULL,
        last_actor_id INT UNSIGNED NULL,
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_proc_req_branch_status (branch_id, status),
        INDEX idx_proc_req_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Line items. inventory_id/item_name/unit are snapshotted at request
    // time so history stays readable even if the catalog item is later
    // renamed, re-priced, or deactivated.
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS procurement_request_items (
        item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL,
        inventory_id INT UNSIGNED NOT NULL,
        item_name VARCHAR(150) NOT NULL,
        unit VARCHAR(50) NOT NULL,
        qty_requested DECIMAL(10,2) NOT NULL,
        note VARCHAR(200) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_proc_item_request (request_id),
        CONSTRAINT fk_proc_item_request FOREIGN KEY (request_id)
            REFERENCES procurement_requests(request_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Append-only audit trail dedicated to procurement. Every status
    // transition writes exactly one row here — nothing in this module ever
    // UPDATEs or DELETEs a row in this table.
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS procurement_request_audit (
        audit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL,
        actor_id INT UNSIGNED NOT NULL,
        actor_name VARCHAR(150) NOT NULL,
        action VARCHAR(60) NOT NULL,
        old_status VARCHAR(40) NULL,
        new_status VARCHAR(40) NOT NULL,
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_proc_audit_request (request_id, created_at),
        CONSTRAINT fk_proc_audit_request FOREIGN KEY (request_id)
            REFERENCES procurement_requests(request_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    ensure_procurement_finance_columns($conn);
    ensure_procurement_po_tables($conn);
    ensure_procurement_po_fulfillment_columns($conn);
    ensure_procurement_receiving_tables($conn);
    ensure_procurement_payment_columns($conn);
    ensure_procurement_v2_tables($conn);

    $ready = true;
}

/**
 * Procurement process redesign additions: supplier stock-check/quotation
 * ahead of Finance review, and Store-Manager-led delivery discrepancy
 * resolution with the supplier. Same conditional-ALTER / CREATE TABLE IF
 * NOT EXISTS safety-net pattern as the rest of this file.
 */
function ensure_procurement_v2_tables(mysqli $conn): void {
    foreach ([
        'supplier_id'               => "ALTER TABLE procurement_requests ADD COLUMN supplier_id INT UNSIGNED NULL",
        'supplier_check_status'     => "ALTER TABLE procurement_requests ADD COLUMN supplier_check_status ENUM('not_sent','pending','stock_confirmed','partial_stock','declined') NOT NULL DEFAULT 'not_sent'",
        'quotation_amount'          => "ALTER TABLE procurement_requests ADD COLUMN quotation_amount DECIMAL(12,2) NULL",
        'shipping_fee'              => "ALTER TABLE procurement_requests ADD COLUMN shipping_fee DECIMAL(10,2) NULL",
        'quotation_attachment_path' => "ALTER TABLE procurement_requests ADD COLUMN quotation_attachment_path VARCHAR(255) NULL",
        'quotation_notes'           => "ALTER TABLE procurement_requests ADD COLUMN quotation_notes VARCHAR(255) NULL",
    ] as $col => $ddl) {
        $c = mysqli_query($conn, "SHOW COLUMNS FROM procurement_requests LIKE '$col'");
        if (!$c || mysqli_num_rows($c) === 0) mysqli_query($conn, $ddl);
    }
    $fk = mysqli_query($conn, "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_proc_req_supplier'");
    if (!$fk || mysqli_num_rows($fk) === 0) {
        mysqli_query($conn, "ALTER TABLE procurement_requests ADD CONSTRAINT fk_proc_req_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE SET NULL");
    }

    $c = mysqli_query($conn, "SHOW COLUMNS FROM procurement_request_items LIKE 'available_quantity'");
    if (!$c || mysqli_num_rows($c) === 0) {
        mysqli_query($conn, "ALTER TABLE procurement_request_items ADD COLUMN available_quantity DECIMAL(10,2) NULL");
    }

    // Per-item price the assigned supplier already quoted during the stock
    // check (see supplier/Procurement_Hub.php's confirm_full handler). Once
    // set, Finance Head's direct-issue form locks that item's unit cost
    // instead of asking for it again — the price was already agreed with
    // the supplier, re-typing it invites a mismatch with what was quoted.
    $c = mysqli_query($conn, "SHOW COLUMNS FROM procurement_request_items LIKE 'quoted_unit_price'");
    if (!$c || mysqli_num_rows($c) === 0) {
        mysqli_query($conn, "ALTER TABLE procurement_request_items ADD COLUMN quoted_unit_price DECIMAL(10,2) NULL");
    }

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS procurement_delivery_issues (
        issue_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL,
        po_id INT UNSIGNED NOT NULL,
        status ENUM('open','resolved') NOT NULL DEFAULT 'open',
        raised_by INT UNSIGNED NOT NULL,
        raised_by_name VARCHAR(150) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at TIMESTAMP NULL,
        INDEX idx_proc_issue_request (request_id),
        INDEX idx_proc_issue_po (po_id, status),
        CONSTRAINT fk_proc_issue_request FOREIGN KEY (request_id)
            REFERENCES procurement_requests(request_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS procurement_delivery_issue_messages (
        message_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        issue_id INT UNSIGNED NOT NULL,
        sender_type ENUM('store_manager','supplier') NOT NULL,
        sender_user_id INT UNSIGNED NULL,
        sender_supplier_id INT UNSIGNED NULL,
        message TEXT NOT NULL,
        requested_action ENUM('replacement','refund','credit','return','backorder') NULL,
        attachment_path VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_proc_issue_msg_issue (issue_id, created_at),
        CONSTRAINT fk_proc_issue_msg_issue FOREIGN KEY (issue_id)
            REFERENCES procurement_delivery_issues(issue_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * C17 — Receiving. One row per receiving *event* (a PO can be received
 * across multiple visits for partial deliveries), with one item-row per
 * PO line item received in that event. Cumulative qty_received per
 * po_item is computed by SUMing procurement_receipt_items across all
 * receipts for that PO — never stored redundantly on procurement_po_items,
 * so there's one source of truth. No stock table is touched here (C18).
 */
function ensure_procurement_receiving_tables(mysqli $conn): void {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS procurement_receipts (
        receipt_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        po_id INT UNSIGNED NOT NULL,
        request_id INT UNSIGNED NOT NULL,
        branch_id INT UNSIGNED NOT NULL,
        received_by INT UNSIGNED NOT NULL,
        received_by_name VARCHAR(150) NOT NULL,
        delivery_reference VARCHAR(100) NULL,
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_proc_receipt_po (po_id),
        CONSTRAINT fk_proc_receipt_po FOREIGN KEY (po_id)
            REFERENCES procurement_purchase_orders(po_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS procurement_receipt_items (
        receipt_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        receipt_id INT UNSIGNED NOT NULL,
        po_item_id INT UNSIGNED NOT NULL,
        item_name VARCHAR(150) NOT NULL,
        unit VARCHAR(50) NOT NULL,
        qty_received DECIMAL(10,2) NOT NULL DEFAULT 0,
        item_condition ENUM('good','damaged','missing') NOT NULL DEFAULT 'good',
        note VARCHAR(200) NULL,
        INDEX idx_proc_receipt_item_receipt (receipt_id),
        INDEX idx_proc_receipt_item_po_item (po_item_id),
        CONSTRAINT fk_proc_receipt_item_receipt FOREIGN KEY (receipt_id)
            REFERENCES procurement_receipts(receipt_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * C15 — Supplier fulfillment tracking. Metadata only: does not touch
 * procurement_requests.status (stays PO_ISSUED) and does not touch stock.
 * `sent_at` doubles as the "was this PO ever marked sent" flag — setting
 * it again is a no-op (checked in Purchase_Order_List.php), not a second
 * audit row.
 */
function ensure_procurement_po_fulfillment_columns(mysqli $conn): void {
    foreach ([
        'sent_at'                 => "ALTER TABLE procurement_purchase_orders ADD COLUMN sent_at TIMESTAMP NULL",
        'delivery_note_reference' => "ALTER TABLE procurement_purchase_orders ADD COLUMN delivery_note_reference VARCHAR(100) NULL",
    ] as $col => $ddl) {
        $c = mysqli_query($conn, "SHOW COLUMNS FROM procurement_purchase_orders LIKE '$col'");
        if (!$c || mysqli_num_rows($c) === 0) mysqli_query($conn, $ddl);
    }
}

/**
 * C13 — Purchase Order data foundation.
 *
 * One PO per request: `request_id` is UNIQUE, which is the hard duplicate-
 * issuance guard (backed at the application layer in C14 by also checking
 * request status = FINAL_APPROVED before the insert). PO items snapshot
 * name/unit from procurement_request_items plus a unit_cost entered by
 * Admin at issuance time (C14) — request items carry no price themselves.
 */
function ensure_procurement_po_tables(mysqli $conn): void {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS procurement_purchase_orders (
        po_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        po_number VARCHAR(20) NULL,
        request_id INT UNSIGNED NOT NULL,
        branch_id INT UNSIGNED NOT NULL,
        supplier_name VARCHAR(150) NOT NULL,
        expected_delivery_date DATE NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'ISSUED',
        total_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        issued_by INT UNSIGNED NOT NULL,
        issued_by_name VARCHAR(150) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_proc_po_request (request_id),
        UNIQUE KEY uq_proc_po_number (po_number),
        INDEX idx_proc_po_branch_status (branch_id, status),
        CONSTRAINT fk_proc_po_request FOREIGN KEY (request_id)
            REFERENCES procurement_requests(request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS procurement_po_items (
        po_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        po_id INT UNSIGNED NOT NULL,
        request_item_id INT UNSIGNED NULL,
        item_name VARCHAR(150) NOT NULL,
        unit VARCHAR(50) NOT NULL,
        qty_ordered DECIMAL(10,2) NOT NULL,
        unit_cost DECIMAL(10,2) NOT NULL,
        line_total DECIMAL(12,2) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_proc_po_item_po (po_id),
        CONSTRAINT fk_proc_po_item_po FOREIGN KEY (po_id)
            REFERENCES procurement_purchase_orders(po_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Display/stored PO number format: PO-000001 (zero-padded po_id). */
function procurement_po_number(int $poId): string {
    return 'PO-' . str_pad((string) $poId, 6, '0', STR_PAD_LEFT);
}

/**
 * C10 — Finance Officer review fields. Added onto the existing
 * procurement_requests header (one evolving record) rather than a side
 * table, since PO issuance (C13+) needs to read this alongside the rest
 * of the request. Same conditional-ALTER safety-net pattern already used
 * elsewhere in this codebase (see finance/finance_restock_approvals.php).
 */
function ensure_procurement_finance_columns(mysqli $conn): void {
    foreach ([
        'estimated_cost'      => "ALTER TABLE procurement_requests ADD COLUMN estimated_cost DECIMAL(12,2) NULL",
        'budget_status'       => "ALTER TABLE procurement_requests ADD COLUMN budget_status VARCHAR(20) NULL",
        'quotation_reference' => "ALTER TABLE procurement_requests ADD COLUMN quotation_reference VARCHAR(100) NULL",
        'finance_notes'       => "ALTER TABLE procurement_requests ADD COLUMN finance_notes VARCHAR(255) NULL",
    ] as $col => $ddl) {
        $c = mysqli_query($conn, "SHOW COLUMNS FROM procurement_requests LIKE '$col'");
        if (!$c || mysqli_num_rows($c) === 0) mysqli_query($conn, $ddl);
    }
}

/**
 * C20 — Payment processing fields. Two-step split: Finance Officer
 * records invoice/payment details (RECEIVED_VERIFIED -> PAYMENT_PENDING),
 * Finance Manager confirms disbursement (PAYMENT_PENDING -> PAID). Added
 * onto procurement_requests, same conditional-ALTER pattern as the C10
 * finance columns above.
 */
function ensure_procurement_payment_columns(mysqli $conn): void {
    foreach ([
        'invoice_reference'    => "ALTER TABLE procurement_requests ADD COLUMN invoice_reference VARCHAR(100) NULL",
        'payment_method'       => "ALTER TABLE procurement_requests ADD COLUMN payment_method VARCHAR(30) NULL",
        'payment_amount'       => "ALTER TABLE procurement_requests ADD COLUMN payment_amount DECIMAL(12,2) NULL",
        'payment_recorded_by'  => "ALTER TABLE procurement_requests ADD COLUMN payment_recorded_by INT UNSIGNED NULL",
        'payment_recorded_at'  => "ALTER TABLE procurement_requests ADD COLUMN payment_recorded_at TIMESTAMP NULL",
        'payment_confirmed_by' => "ALTER TABLE procurement_requests ADD COLUMN payment_confirmed_by INT UNSIGNED NULL",
        'payment_confirmed_at' => "ALTER TABLE procurement_requests ADD COLUMN payment_confirmed_at TIMESTAMP NULL",
        'payment_notes'        => "ALTER TABLE procurement_requests ADD COLUMN payment_notes VARCHAR(255) NULL",
    ] as $col => $ddl) {
        $c = mysqli_query($conn, "SHOW COLUMNS FROM procurement_requests LIKE '$col'");
        if (!$c || mysqli_num_rows($c) === 0) mysqli_query($conn, $ddl);
    }
}

/**
 * Create the Purchase Order for a FINAL_APPROVED request and advance it to
 * PO_ISSUED — the one piece of work shared by "issue directly"
 * (finance/Issue_Purchase_Order.php) and "award an RFQ"
 * (finance/RFQ_Canvass_Page.php), which differ only in *where the line
 * prices came from* (typed in by hand vs. taken from a winning quotation).
 *
 * $lineData: array of ['request_item_id'=>?int, 'item_name'=>string,
 * 'unit'=>string, 'qty_ordered'=>float, 'unit_cost'=>float,
 * 'line_total'=>float] — already validated/computed by the caller.
 *
 * Re-verifies the request is still FINAL_APPROVED with no existing PO
 * under a row lock before writing anything — never trusts the caller's
 * own read of the status, since either caller may race with the other.
 *
 * Returns ['ok'=>bool, 'po_id'=>?int, 'po_number'=>?string, 'error'=>?string].
 */
function procurement_issue_po_from_lines(
    mysqli $conn,
    int $requestId,
    int $branchId,
    int $supplierId,
    string $supplierName,
    ?string $expectedDate,
    array $lineData,
    int $userId,
    string $userName
): array {
    mysqli_begin_transaction($conn);
    $failed = null;
    $po_id = null;
    $po_number = null;

    $lock = mysqli_query($conn, "SELECT status FROM procurement_requests WHERE request_id = $requestId FOR UPDATE");
    $req  = $lock ? mysqli_fetch_assoc($lock) : null;
    if (!$req) {
        $failed = 'Request not found.';
    } elseif ($req['status'] !== PROC_STATUS_FINAL_APPROVED) {
        $failed = 'This request is no longer awaiting a Purchase Order (status changed).';
    } else {
        $existing = mysqli_query($conn, "SELECT po_id FROM procurement_purchase_orders WHERE request_id = $requestId");
        if ($existing && mysqli_num_rows($existing) > 0) {
            $failed = 'A Purchase Order already exists for this request.';
        }
    }

    if (!$failed) {
        $exp = $expectedDate !== null && $expectedDate !== '' ? $expectedDate : null;
        $total_cost = array_sum(array_column($lineData, 'line_total'));
        $ins = mysqli_prepare($conn,
            'INSERT INTO procurement_purchase_orders
                (request_id, branch_id, supplier_name, supplier_id, expected_delivery_date, total_cost, issued_by, issued_by_name)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        mysqli_stmt_bind_param($ins, 'iisisdis',
            $requestId, $branchId, $supplierName, $supplierId, $exp, $total_cost, $userId, $userName
        );
        if (!mysqli_stmt_execute($ins)) {
            $failed = 'A Purchase Order already exists for this request.';
        } else {
            $po_id = mysqli_insert_id($conn);
        }
        mysqli_stmt_close($ins);
    }

    if (!$failed) {
        $po_number = procurement_po_number($po_id);
        $upd = mysqli_prepare($conn, 'UPDATE procurement_purchase_orders SET po_number = ? WHERE po_id = ?');
        mysqli_stmt_bind_param($upd, 'si', $po_number, $po_id);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);

        $itemStmt = mysqli_prepare($conn,
            'INSERT INTO procurement_po_items (po_id, request_item_id, item_name, unit, qty_ordered, unit_cost, line_total)
             VALUES (?,?,?,?,?,?,?)'
        );
        foreach ($lineData as $ld) {
            $requestItemId = $ld['request_item_id'] ?? null;
            mysqli_stmt_bind_param($itemStmt, 'iissddd',
                $po_id, $requestItemId, $ld['item_name'], $ld['unit'], $ld['qty_ordered'], $ld['unit_cost'], $ld['line_total']
            );
            if (!mysqli_stmt_execute($itemStmt)) {
                $failed = 'Failed to save PO line items.';
                break;
            }
        }
        mysqli_stmt_close($itemStmt);
    }

    $old_status = PROC_STATUS_FINAL_APPROVED;
    $next = null;
    if (!$failed) {
        $next = procurement_next_status($old_status, 'approve'); // PO_ISSUED
        if (!$next || !procurement_can_transition($old_status, $next)) {
            $failed = 'Internal error: invalid status transition.';
        } else {
            $stUpd = mysqli_prepare($conn,
                'UPDATE procurement_requests SET status = ?, last_actor_id = ? WHERE request_id = ? AND status = ?'
            );
            mysqli_stmt_bind_param($stUpd, 'siis', $next, $userId, $requestId, $old_status);
            mysqli_stmt_execute($stUpd);
            $changed = mysqli_stmt_affected_rows($stUpd);
            mysqli_stmt_close($stUpd);
            if ($changed !== 1) {
                $failed = 'This request changed status during issuance — refresh and try again.';
            }
        }
    }

    if ($failed) {
        mysqli_rollback($conn);
        return ['ok' => false, 'po_id' => null, 'po_number' => null, 'error' => $failed];
    }

    mysqli_commit($conn);
    procurement_log_audit($conn, $requestId, $userId, $userName, 'po_issued', $old_status, $next,
        $po_number . ' — supplier: ' . $supplierName);
    return ['ok' => true, 'po_id' => $po_id, 'po_number' => $po_number, 'error' => null];
}

/**
 * Writes one append-only audit row. Every status-changing action in this
 * module must call this — never update procurement_requests.status without
 * a matching audit row.
 */
function procurement_log_audit(
    mysqli $conn,
    int $requestId,
    int $actorId,
    string $actorName,
    string $action,
    ?string $oldStatus,
    string $newStatus,
    ?string $notes = null
): void {
    ensure_procurement_tables($conn);
    $stmt = mysqli_prepare($conn,
        'INSERT INTO procurement_request_audit
            (request_id, actor_id, actor_name, action, old_status, new_status, notes)
         VALUES (?,?,?,?,?,?,?)'
    );
    if (!$stmt) return;
    mysqli_stmt_bind_param($stmt, 'iisssss', $requestId, $actorId, $actorName, $action, $oldStatus, $newStatus, $notes);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}
