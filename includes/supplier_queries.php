<?php
// ── SUPPLIER MODULE — schema bootstrap + shared helpers ─────────────
// Mirrors the ensure_*()/procurement_log_audit() pattern already used by
// includes/procurement_queries.php: every table here is created lazily
// and idempotently, so a fresh environment (or one restored from an
// older dump) self-heals on first load instead of fataling.
//
// Existing procurement tables (procurement_purchase_orders, etc.) are
// NOT duplicated here — this file only owns tables/columns that are
// genuinely new to the Supplier module. See docs/procurement/STATUS.md
// for how this plugs into the existing approval chain.

require_once __DIR__ . '/DB_Connect.php';

function ensure_supplier_tables(mysqli $conn): void
{
    static $ready = false;
    if ($ready) return;

    // `suppliers` itself already exists (created outside this codebase's
    // bootstrap pattern) — these are the S1 additions on top of it.
    $cols = [
        'supplier_code'        => "ALTER TABLE suppliers ADD COLUMN supplier_code VARCHAR(20) NULL UNIQUE AFTER supplier_id",
        'accreditation_status' => "ALTER TABLE suppliers ADD COLUMN accreditation_status ENUM('pending','accredited','rejected') NOT NULL DEFAULT 'pending' AFTER address",
        'product_categories'   => "ALTER TABLE suppliers ADD COLUMN product_categories VARCHAR(255) NULL AFTER accreditation_status",
    ];
    foreach ($cols as $col => $ddl) {
        $c = mysqli_query($conn, "SHOW COLUMNS FROM suppliers LIKE '$col'");
        if (!$c || mysqli_num_rows($c) === 0) mysqli_query($conn, $ddl);
    }
    // status started as ENUM('active','inactive') pre-Supplier-module; widen it
    // to cover the pending-approval / suspended states Admin needs.
    $statusCol = mysqli_query($conn, "SHOW COLUMNS FROM suppliers LIKE 'status'");
    $statusRow = $statusCol ? mysqli_fetch_assoc($statusCol) : null;
    if ($statusRow && strpos($statusRow['Type'], 'pending') === false) {
        mysqli_query($conn, "ALTER TABLE suppliers MODIFY COLUMN status ENUM('pending','active','suspended','inactive') NOT NULL DEFAULT 'pending'");
    }

    // users -> suppliers link (S1). Nullable: only set for role='supplier' rows.
    $uc = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'supplier_id'");
    if (!$uc || mysqli_num_rows($uc) === 0) {
        mysqli_query($conn, "ALTER TABLE users ADD COLUMN supplier_id INT UNSIGNED NULL AFTER branch_id");
        mysqli_query($conn, "ALTER TABLE users ADD CONSTRAINT fk_users_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE SET NULL");
    }

    // Supplier-side extensions to the existing PO table (S6): response to
    // the order, and the supplier's own outbound delivery status. This is
    // deliberately separate from procurement_receipts/_receipt_items,
    // which record the BRANCH's inbound receiving/verification — the two
    // are complementary views of the same physical delivery, not the same
    // fact, so they get their own columns rather than being conflated.
    $poCols = [
        'supplier_id'                    => "ALTER TABLE procurement_purchase_orders ADD COLUMN supplier_id INT UNSIGNED NULL AFTER supplier_name",
        'supplier_response_status'       => "ALTER TABLE procurement_purchase_orders ADD COLUMN supplier_response_status ENUM('pending','confirmed','accepted','rejected','revised') NOT NULL DEFAULT 'pending' AFTER supplier_id",
        'supplier_response_date'         => "ALTER TABLE procurement_purchase_orders ADD COLUMN supplier_response_date DATETIME NULL AFTER supplier_response_status",
        'supplier_revised_delivery_date' => "ALTER TABLE procurement_purchase_orders ADD COLUMN supplier_revised_delivery_date DATE NULL AFTER supplier_response_date",
        'supplier_response_remarks'      => "ALTER TABLE procurement_purchase_orders ADD COLUMN supplier_response_remarks VARCHAR(255) NULL AFTER supplier_revised_delivery_date",
        'delivery_status'                => "ALTER TABLE procurement_purchase_orders ADD COLUMN delivery_status ENUM('pending','confirmed','preparing','in_transit','partially_delivered','delivered','delayed','cancelled') NOT NULL DEFAULT 'pending' AFTER supplier_response_remarks",
        'delivery_status_updated_at'     => "ALTER TABLE procurement_purchase_orders ADD COLUMN delivery_status_updated_at DATETIME NULL AFTER delivery_status",
        'delivery_reference'             => "ALTER TABLE procurement_purchase_orders ADD COLUMN delivery_reference VARCHAR(60) NULL AFTER delivery_status_updated_at",
    ];
    foreach ($poCols as $col => $ddl) {
        $c = mysqli_query($conn, "SHOW COLUMNS FROM procurement_purchase_orders LIKE '$col'");
        if (!$c || mysqli_num_rows($c) === 0) mysqli_query($conn, $ddl);
    }
    $fk = mysqli_query($conn, "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_proc_po_supplier'");
    if (!$fk || mysqli_num_rows($fk) === 0) {
        mysqli_query($conn, "ALTER TABLE procurement_purchase_orders ADD CONSTRAINT fk_proc_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE SET NULL");
    }

    // Branch Delivery Status page needs a human-readable branch code.
    $bc = mysqli_query($conn, "SHOW COLUMNS FROM branches LIKE 'branch_code'");
    if (!$bc || mysqli_num_rows($bc) === 0) {
        mysqli_query($conn, "ALTER TABLE branches ADD COLUMN branch_code VARCHAR(20) NULL AFTER branch_name");
    }

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS supplier_documents (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT UNSIGNED NOT NULL,
        document_type VARCHAR(60) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        uploaded_by INT UNSIGNED NOT NULL,
        uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_supplier_documents_supplier (supplier_id),
        CONSTRAINT fk_supplier_documents_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS supplier_products (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT UNSIGNED NOT NULL,
        product_name VARCHAR(150) NOT NULL,
        category VARCHAR(100) NULL,
        stock_quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        unit VARCHAR(30) NOT NULL,
        reorder_level DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        lead_time_days INT UNSIGNED NOT NULL DEFAULT 0,
        availability_status ENUM('available','limited','out_of_stock') NOT NULL DEFAULT 'available',
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_supplier_products_supplier (supplier_id),
        CONSTRAINT fk_supplier_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS supplier_delivery_proofs (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        po_id INT UNSIGNED NOT NULL,
        uploaded_by INT UNSIGNED NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        document_type ENUM('receipt','invoice','photo','other') NOT NULL DEFAULT 'other',
        description VARCHAR(255) NULL,
        uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_supplier_delivery_proofs_po (po_id),
        CONSTRAINT fk_supplier_delivery_proofs_po FOREIGN KEY (po_id) REFERENCES procurement_purchase_orders(po_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS supplier_invoices (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(30) NOT NULL UNIQUE,
        supplier_id INT UNSIGNED NOT NULL,
        po_id INT UNSIGNED NOT NULL,
        invoice_date DATE NOT NULL,
        due_date DATE NULL,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        payment_status ENUM('unpaid','pending','paid','overdue') NOT NULL DEFAULT 'unpaid',
        attachment_path VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_supplier_invoices_supplier (supplier_id),
        INDEX idx_supplier_invoices_po (po_id),
        CONSTRAINT fk_supplier_invoices_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE CASCADE,
        CONSTRAINT fk_supplier_invoices_po FOREIGN KEY (po_id) REFERENCES procurement_purchase_orders(po_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Dedicated append-only log for franchisers' supply-chain audit trail —
    // kept separate from the generic activity_log table since that one's
    // `domain` ENUM has no 'supplier' value and mixing concerns would make
    // both harder to query cleanly.
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS supplier_activity_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        action_type VARCHAR(60) NOT NULL,
        module VARCHAR(60) NOT NULL,
        description TEXT NULL,
        reference_id VARCHAR(80) NULL,
        ip_address VARCHAR(45) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_supplier_activity_supplier (supplier_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ready = true;
}

/** The signed-in supplier's supplier_id, or null if not a supplier session. */
function supplier_current_supplier_id($conn = null): ?int
{
    if ($conn === null) { global $conn; }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0 || !$conn) return null;

    // Re-read from the DB rather than trusting a session-cached value —
    // same rationale as procurement_auth.php: a suspended/reassigned
    // account should lose access the moment the row changes, not at
    // next login.
    $res = mysqli_query($conn, "SELECT supplier_id FROM users WHERE user_id = " . $uid . " AND role = 'supplier' AND is_active = 1 LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return $row && $row['supplier_id'] !== null ? (int)$row['supplier_id'] : null;
}

const SUPPLIER_UPLOAD_MAX_BYTES  = 5 * 1024 * 1024; // 5MB, same cap as careers/Apply_Page.php's resume upload
const SUPPLIER_UPLOAD_ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];
const SUPPLIER_UPLOAD_ALLOWED_MIME = ['application/pdf', 'image/jpeg', 'image/png'];

/**
 * Validate + store one uploaded supplier document (delivery receipt,
 * invoice scan, proof-of-delivery photo, accreditation document).
 * Same shape as careers/Apply_Page.php's resume upload: extension AND
 * sniffed-MIME allowlist (never trust $_FILES[...]['type']), random
 * filename (original name discarded), stored under uploads/supplier_documents/
 * which has its own .htaccess denying execution.
 *
 * Returns ['ok'=>true,'path'=>'uploads/supplier_documents/xxx.pdf','original'=>'invoice.pdf']
 * or ['ok'=>false,'error'=>'human-readable reason'].
 */
function supplier_handle_upload(array $file): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file selected.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed — try again.'];
    }
    if ($file['size'] > SUPPLIER_UPLOAD_MAX_BYTES) {
        return ['ok' => false, 'error' => 'File must be under 5MB.'];
    }
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : '';
    if (!in_array($ext, SUPPLIER_UPLOAD_ALLOWED_EXT, true) || ($mime && !in_array($mime, SUPPLIER_UPLOAD_ALLOWED_MIME, true))) {
        return ['ok' => false, 'error' => 'File must be a PDF, JPG, or PNG.'];
    }

    $dir = __DIR__ . '/../uploads/supplier_documents/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $safeName = 'supdoc_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $safeName)) {
        return ['ok' => false, 'error' => 'Could not save the file — try again.'];
    }

    return ['ok' => true, 'path' => 'uploads/supplier_documents/' . $safeName, 'original' => $file['name']];
}

/**
 * Append one row to the supplier audit trail. Call this for every
 * supplier-initiated action worth reconstructing later: logins, request
 * responses, delivery status changes, uploads, stock edits, invoice
 * submissions, profile changes.
 */
function supplier_log_activity(mysqli $conn, int $supplierId, string $actionType, string $module, string $description, ?string $referenceId = null): void
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = mysqli_prepare($conn,
        "INSERT INTO supplier_activity_logs (supplier_id, user_id, action_type, module, description, reference_id, ip_address)
         VALUES (?,?,?,?,?,?,?)");
    mysqli_stmt_bind_param($stmt, 'iisssss', $supplierId, $userId, $actionType, $module, $description, $referenceId, $ip);
    mysqli_stmt_execute($stmt);
}
