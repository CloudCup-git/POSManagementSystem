<?php
// ── RFQ / CANVASS / QUOTATION (Procurement S9) ───────────────────────
// Optional alternate path between FINAL_APPROVED and PO_ISSUED: instead
// of Admin issuing a PO straight to one supplier (admin/Issue_Purchase_
// Order.php), Admin can send the request out to several suppliers as an
// RFQ, let them submit price quotations, compare them ("canvass"), and
// award the winner — which then creates the PO via the exact same
// procurement_issue_po_from_lines() the direct-issue path uses.
//
// procurement_requests.status deliberately never changes while an RFQ is
// open — it only moves FINAL_APPROVED -> PO_ISSUED at award time, so this
// file adds no new PROC_STATUS_* value and touches nothing in
// includes/procurement_workflow.php. RFQ/quotation data is metadata
// bolted on the side, same pattern as procurement_receipts for receiving.

require_once __DIR__ . '/procurement_queries.php';
require_once __DIR__ . '/supplier_queries.php';

function ensure_procurement_rfq_tables(mysqli $conn): void
{
    static $ready = false;
    if ($ready) return;

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS procurement_rfqs (
        rfq_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rfq_number VARCHAR(20) NULL UNIQUE,
        request_id INT UNSIGNED NOT NULL,
        status ENUM('open','awarded','cancelled') NOT NULL DEFAULT 'open',
        quotation_deadline DATE NULL,
        notes VARCHAR(255) NULL,
        created_by INT UNSIGNED NOT NULL,
        created_by_name VARCHAR(150) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        awarded_supplier_id INT UNSIGNED NULL,
        awarded_po_id INT UNSIGNED NULL,
        awarded_at DATETIME NULL,
        INDEX idx_proc_rfq_request (request_id),
        INDEX idx_proc_rfq_status (status),
        CONSTRAINT fk_proc_rfq_request FOREIGN KEY (request_id) REFERENCES procurement_requests(request_id),
        CONSTRAINT fk_proc_rfq_awarded_supplier FOREIGN KEY (awarded_supplier_id) REFERENCES suppliers(supplier_id) ON DELETE SET NULL,
        CONSTRAINT fk_proc_rfq_awarded_po FOREIGN KEY (awarded_po_id) REFERENCES procurement_purchase_orders(po_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Snapshotted from procurement_request_items at RFQ-creation time — same
    // "freeze the line items at this step" convention as procurement_po_items,
    // so a later edit to the original request can't silently reshape an RFQ
    // suppliers have already started quoting against.
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS procurement_rfq_items (
        rfq_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rfq_id INT UNSIGNED NOT NULL,
        request_item_id INT UNSIGNED NULL,
        item_name VARCHAR(150) NOT NULL,
        unit VARCHAR(50) NOT NULL,
        qty_needed DECIMAL(10,2) NOT NULL,
        INDEX idx_proc_rfq_item_rfq (rfq_id),
        CONSTRAINT fk_proc_rfq_item_rfq FOREIGN KEY (rfq_id) REFERENCES procurement_rfqs(rfq_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS procurement_rfq_suppliers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rfq_id INT UNSIGNED NOT NULL,
        supplier_id INT UNSIGNED NOT NULL,
        invited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status ENUM('invited','quoted','declined','awarded','not_awarded') NOT NULL DEFAULT 'invited',
        UNIQUE KEY uq_proc_rfq_supplier (rfq_id, supplier_id),
        CONSTRAINT fk_proc_rfq_sup_rfq FOREIGN KEY (rfq_id) REFERENCES procurement_rfqs(rfq_id) ON DELETE CASCADE,
        CONSTRAINT fk_proc_rfq_sup_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // One row per supplier per RFQ — resubmitting before the deadline
    // updates this row (upsert) rather than inserting a new one; there's no
    // business reason to keep a superseded draft quotation around.
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS supplier_quotations (
        quotation_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rfq_id INT UNSIGNED NOT NULL,
        supplier_id INT UNSIGNED NOT NULL,
        submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        validity_date DATE NULL,
        lead_time_days INT UNSIGNED NULL,
        notes VARCHAR(255) NULL,
        attachment_path VARCHAR(255) NULL,
        status ENUM('submitted','awarded','not_awarded') NOT NULL DEFAULT 'submitted',
        UNIQUE KEY uq_supplier_quotation (rfq_id, supplier_id),
        CONSTRAINT fk_supplier_quotation_rfq FOREIGN KEY (rfq_id) REFERENCES procurement_rfqs(rfq_id) ON DELETE CASCADE,
        CONSTRAINT fk_supplier_quotation_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS supplier_quotation_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        quotation_id INT UNSIGNED NOT NULL,
        rfq_item_id INT UNSIGNED NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
        notes VARCHAR(255) NULL,
        UNIQUE KEY uq_quotation_item (quotation_id, rfq_item_id),
        CONSTRAINT fk_quotation_item_quotation FOREIGN KEY (quotation_id) REFERENCES supplier_quotations(quotation_id) ON DELETE CASCADE,
        CONSTRAINT fk_quotation_item_rfq_item FOREIGN KEY (rfq_item_id) REFERENCES procurement_rfq_items(rfq_item_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ready = true;
}

/** Display/stored RFQ number format: RFQ-000001 (zero-padded rfq_id). */
function procurement_rfq_number(int $rfqId): string
{
    return 'RFQ-' . str_pad((string) $rfqId, 6, '0', STR_PAD_LEFT);
}

/** The open RFQ for a request, or null. Used to stop a second RFQ (or a
 *  direct issue) from being started while one is already in flight. */
function procurement_open_rfq_for_request(mysqli $conn, int $requestId): ?array
{
    $res = mysqli_query($conn, "SELECT * FROM procurement_rfqs WHERE request_id = $requestId AND status = 'open' LIMIT 1");
    return $res ? (mysqli_fetch_assoc($res) ?: null) : null;
}
