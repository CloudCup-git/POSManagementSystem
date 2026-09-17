<?php
/**
 * Cash Flow Overview module — data foundation.
 *
 * Lazily creates the module's own tables the first time they're needed,
 * same pattern as includes/Activity_Log.php's ensure_activity_log() and
 * includes/procurement_queries.php's ensure_procurement_tables(). Entirely
 * separate from Procurement's tables — this module never touches them.
 *
 * Posting a Cash Flow entry writes into the EXISTING operating_expenses /
 * owner_equity_transactions tables (not a parallel ledger), so the Finance
 * module's Balance Sheet / OpEx / Revenue pages pick up posted entries
 * automatically with zero changes to those pages.
 */

require_once __DIR__ . '/cashflow_workflow.php';
require_once __DIR__ . '/Activity_Log.php';

function ensure_cashflow_tables(mysqli $conn): void {
    static $ready = false;
    if ($ready) return;

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cash_flow_entries (
        entry_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        reference_number VARCHAR(40) NULL,
        entry_type ENUM('cash_in','cash_out') NOT NULL,
        category VARCHAR(80) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        payment_method ENUM('cash','gcash_ewallet','card','bank_transfer','other') NOT NULL,
        transaction_date DATE NOT NULL,
        description VARCHAR(255) NOT NULL,
        attachment_path VARCHAR(255) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        created_by INT UNSIGNED NOT NULL,
        created_by_name VARCHAR(150) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_by INT UNSIGNED NULL,
        reviewed_by_name VARCHAR(150) NULL,
        reviewed_at TIMESTAMP NULL,
        review_note VARCHAR(255) NULL,
        posted_at TIMESTAMP NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_cf_status_date (status, transaction_date),
        INDEX idx_cf_reference (reference_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Append-only audit trail. Every transition writes exactly one row —
    // nothing in this module ever UPDATEs or DELETEs a row here.
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cash_flow_audit (
        audit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        entry_id INT UNSIGNED NOT NULL,
        actor_id INT UNSIGNED NOT NULL,
        actor_name VARCHAR(150) NOT NULL,
        action VARCHAR(60) NOT NULL,
        old_status VARCHAR(40) NULL,
        new_status VARCHAR(40) NOT NULL,
        old_amount DECIMAL(12,2) NULL,
        new_amount DECIMAL(12,2) NULL,
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cf_audit_entry (entry_id, created_at),
        CONSTRAINT fk_cf_audit_entry FOREIGN KEY (entry_id) REFERENCES cash_flow_entries(entry_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // entry_id here is nullable and has NO foreign key — a notification can
    // point at a cash_flow_entries row OR (from Stage 2 on) a
    // cash_flow_reconciliation row; the two tables have separate,
    // overlapping auto-increment ranges, so a single FK can't cover both.
    // recipient_user_id / recipient_stage + message is enough to act on a
    // notification without needing to dereference entry_id at all.
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cash_flow_notifications (
        notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        entry_id INT UNSIGNED NULL,
        recipient_stage ENUM('finance_officer','finance_head') NOT NULL,
        recipient_user_id INT UNSIGNED NULL,
        message VARCHAR(255) NOT NULL,
        status ENUM('unread','read') NOT NULL DEFAULT 'unread',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        read_at TIMESTAMP NULL,
        INDEX idx_cf_notif_recipient (recipient_stage, recipient_user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── Stage 2: reconciliation (cash / GCash-e-wallet / bank) ──────────
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cash_flow_reconciliation (
        recon_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        channel ENUM('cash','gcash_ewallet','bank_transfer') NOT NULL,
        business_date DATE NOT NULL,
        expected_amount DECIMAL(12,2) NOT NULL,
        actual_amount DECIMAL(12,2) NOT NULL,
        variance DECIMAL(12,2) NOT NULL,
        settlement_reference VARCHAR(80) NULL,
        proof_attachment_path VARCHAR(255) NULL,
        status ENUM('matched','discrepancy','resolved') NOT NULL DEFAULT 'matched',
        explanation VARCHAR(255) NULL,
        created_by INT UNSIGNED NOT NULL,
        created_by_name VARCHAR(150) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_by INT UNSIGNED NULL,
        resolved_by_name VARCHAR(150) NULL,
        resolved_at TIMESTAMP NULL,
        resolution_note VARCHAR(255) NULL,
        UNIQUE KEY uq_cfr_channel_date (channel, business_date),
        INDEX idx_cfr_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Own audit table (not cash_flow_audit — that table's entry_id has a
    // foreign key into cash_flow_entries, which a recon_id would violate).
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cash_flow_reconciliation_audit (
        audit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        recon_id INT UNSIGNED NOT NULL,
        actor_id INT UNSIGNED NOT NULL,
        actor_name VARCHAR(150) NOT NULL,
        action VARCHAR(60) NOT NULL,
        old_status VARCHAR(40) NULL,
        new_status VARCHAR(40) NOT NULL,
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cfr_audit_recon (recon_id, created_at),
        CONSTRAINT fk_cfr_audit_recon FOREIGN KEY (recon_id) REFERENCES cash_flow_reconciliation(recon_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ready = true;
}

function cashflow_reference_number(int $entryId): string {
    return 'CF-' . str_pad((string) $entryId, 6, '0', STR_PAD_LEFT);
}

/** Appends one row to cash_flow_audit AND to the shared activity_log (so it
 *  shows up in finance/finance_activity_log.php with zero page changes). */
function cashflow_log_audit(
    mysqli $conn, int $entryId, int $actorId, string $actorName, string $action,
    ?string $oldStatus, string $newStatus, ?float $oldAmount, ?float $newAmount, ?string $notes = null
): void {
    $stmt = mysqli_prepare($conn, "INSERT INTO cash_flow_audit
        (entry_id, actor_id, actor_name, action, old_status, new_status, old_amount, new_amount, notes)
        VALUES (?,?,?,?,?,?,?,?,?)");
    mysqli_stmt_bind_param($stmt, 'iisssssss', $entryId, $actorId, $actorName, $action, $oldStatus, $newStatus, $oldAmount, $newAmount, $notes);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    log_activity($conn, 'money', $action, 'cashflow_entry', $entryId, $notes ?? ($action . ' cash flow entry'), $actorId, $actorName);
}

function cashflow_notify(mysqli $conn, string $recipientStage, int $entryId, ?int $recipientUserId, string $message): void {
    $stmt = mysqli_prepare($conn, "INSERT INTO cash_flow_notifications
        (entry_id, recipient_stage, recipient_user_id, message) VALUES (?,?,?,?)");
    mysqli_stmt_bind_param($stmt, 'isis', $entryId, $recipientStage, $recipientUserId, $message);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/** Looks for a live (non-rejected) entry with the same reference number,
 *  amount, date, and payment method. Returns the matching row or null. */
function cashflow_check_possible_duplicate(mysqli $conn, string $refNumber, float $amount, string $date, string $method): ?array {
    if ($refNumber === '') return null;
    $stmt = mysqli_prepare($conn, "SELECT entry_id, reference_number, status FROM cash_flow_entries
        WHERE reference_number = ? AND amount = ? AND transaction_date = ? AND payment_method = ?
          AND status <> 'rejected'
        LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'sdss', $refNumber, $amount, $date, $method);
    mysqli_stmt_execute($stmt);
    $row = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/** True if $date falls inside a range that's already been closed. */
function cashflow_date_in_closed_period(mysqli $conn, string $date): bool {
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM cash_flow_entries WHERE status = 'closed' AND transaction_date = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $date);
    mysqli_stmt_execute($stmt);
    $found = (bool) mysqli_stmt_get_result($stmt)->fetch_row();
    mysqli_stmt_close($stmt);
    return $found;
}

/** Maps a cash_flow_entries.payment_method value to a valid
 *  operating_expenses.payment_method enum value (cash, card, gcash, maya, bank_transfer). */
function cashflow_map_expense_payment_method(string $method): string {
    return match ($method) {
        'gcash_ewallet' => 'gcash',
        'card' => 'card',
        'bank_transfer' => 'bank_transfer',
        default => 'cash', // 'cash' and 'other' both fall back to cash
    };
}

/**
 * Posts an Approved entry: writes its financial side-effect into the
 * existing operating_expenses (cash_out) or owner_equity_transactions
 * (cash_in) tables, transaction-wrapped so a failure never leaves a
 * half-posted entry. Mirrors finance_restock_approvals.php's
 * approveOneRestockRequest() transaction shape, using $conn (mysqli)
 * instead of $pdo since this module's own queries are all mysqli.
 *
 * Returns the posting note for the flash message. Throws on failure —
 * caller is responsible for rolling back the entry's own status update.
 */
function cashflow_post_entry(mysqli $conn, array $entry, int $actorId): string {
    if ($entry['entry_type'] === 'cash_out') {
        $method = cashflow_map_expense_payment_method($entry['payment_method']);
        $desc = 'Cash Flow ' . cashflow_reference_number((int) $entry['entry_id']) . ': ' . $entry['description'];
        $stmt = mysqli_prepare($conn, "INSERT INTO operating_expenses
            (expense_type, category, description, amount, payment_method, expense_date, recorded_by)
            VALUES ('operating', ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'ssdssi', $entry['category'], $desc, $entry['amount'], $method, $entry['transaction_date'], $actorId);
        if (!mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); throw new Exception('Could not record the cash-out expense.'); }
        mysqli_stmt_close($stmt);
        return 'Recorded as an operating expense of ' . number_format((float) $entry['amount'], 2) . '.';
    }

    // cash_in — treated as an owner contribution / misc cash income.
    $note = 'Cash Flow ' . cashflow_reference_number((int) $entry['entry_id']) . ': ' . $entry['description'];
    $stmt = mysqli_prepare($conn, "INSERT INTO owner_equity_transactions
        (tx_type, amount, tx_date, note, recorded_by) VALUES ('contribution', ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'dssi', $entry['amount'], $entry['transaction_date'], $note, $actorId);
    if (!mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); throw new Exception('Could not record the cash-in transaction.'); }
    mysqli_stmt_close($stmt);
    return 'Recorded as a cash-in transaction of ' . number_format((float) $entry['amount'], 2) . '.';
}

/** Validates a cash-in/cash-out submission per the Cash Flow spec's rules.
 *  Returns [valid(bool), errors(string[])]. */
function cashflow_validate_entry(mysqli $conn, array $post): array {
    $errors = [];

    $type = $post['entry_type'] ?? '';
    if (!in_array($type, ['cash_in', 'cash_out'], true)) $errors[] = 'Please select a transaction type.';

    $amountRaw = trim((string) ($post['amount'] ?? ''));
    if ($amountRaw === '' || !is_numeric($amountRaw) || (float) $amountRaw <= 0) {
        $errors[] = 'Please enter an amount greater than ₱0.00.';
    } elseif (!preg_match('/^\d+(\.\d{1,2})?$/', $amountRaw)) {
        $errors[] = 'Amount must have at most two decimal places.';
    }

    $category = trim((string) ($post['category'] ?? ''));
    if ($category === '') $errors[] = 'Please select a cash-flow category.';

    $method = $post['payment_method'] ?? '';
    if (!in_array($method, ['cash', 'gcash_ewallet', 'card', 'bank_transfer', 'other'], true)) {
        $errors[] = 'Please select a valid payment method.';
    }

    $date = trim((string) ($post['transaction_date'] ?? ''));
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        $errors[] = 'Please enter a valid transaction date.';
    } elseif (cashflow_date_in_closed_period($conn, $date)) {
        $errors[] = 'You cannot submit this entry because the transaction date belongs to a closed period.';
    }

    $description = trim((string) ($post['description'] ?? ''));
    if ($description === '') $errors[] = 'Please enter a description or purpose for this entry.';

    return [count($errors) === 0, $errors];
}

/* =============================================================
   Stage 2 — Reconciliation, attachments
   ============================================================= */

/** System-computed "expected" amount for a channel/day, or null when
 *  there's no automatic POS-side source to compare against (bank
 *  transfers are manually recorded on both sides — see the plan). */
function cashflow_expected_amount(mysqli $conn, string $channel, string $date): ?float {
    if ($channel === 'cash') {
        $stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(total_amount),0) t FROM orders WHERE payment_method='cash' AND status='completed' AND DATE(ordered_at) = ?");
    } elseif ($channel === 'gcash_ewallet') {
        $stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(total_amount),0) t FROM orders WHERE payment_method IN ('gcash','maya') AND status='completed' AND DATE(ordered_at) = ?");
    } else {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $date);
    mysqli_stmt_execute($stmt);
    $t = (float) (mysqli_stmt_get_result($stmt)->fetch_assoc()['t'] ?? 0);
    mysqli_stmt_close($stmt);
    return $t;
}

const CASHFLOW_UPLOAD_MAX_BYTES   = 5 * 1024 * 1024; // 5MB, same cap as supplier_handle_upload()
const CASHFLOW_UPLOAD_ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];
const CASHFLOW_UPLOAD_ALLOWED_MIME = ['application/pdf', 'image/jpeg', 'image/png'];

/**
 * Validate + store one uploaded Finance attachment (receipt, invoice,
 * bank-statement proof). Mirrors includes/supplier_queries.php's
 * supplier_handle_upload(): extension AND sniffed-MIME allowlist (never
 * trusts $_FILES[...]['type']), random filename, stored under
 * uploads/cashflow_attachments/ (own .htaccess denying execution).
 *
 * Returns ['ok'=>true,'path'=>'uploads/cashflow_attachments/xxx.pdf','original'=>'receipt.pdf']
 * or ['ok'=>false,'error'=>'human-readable reason'].
 */
function cashflow_handle_upload(array $file): array {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file selected.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed — try again.'];
    }
    if ($file['size'] > CASHFLOW_UPLOAD_MAX_BYTES) {
        return ['ok' => false, 'error' => 'File must be under 5MB.'];
    }
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : '';
    if (!in_array($ext, CASHFLOW_UPLOAD_ALLOWED_EXT, true) || ($mime && !in_array($mime, CASHFLOW_UPLOAD_ALLOWED_MIME, true))) {
        return ['ok' => false, 'error' => 'File must be a PDF, JPG, or PNG.'];
    }

    $dir = __DIR__ . '/../uploads/cashflow_attachments/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $safeName = 'cfatt_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $safeName)) {
        return ['ok' => false, 'error' => 'Could not save the file — try again.'];
    }

    return ['ok' => true, 'path' => 'uploads/cashflow_attachments/' . $safeName, 'original' => $file['name']];
}

/** Count of unresolved reconciliation discrepancies in a date range —
 *  the Close Period gate refuses to close while this is > 0. */
function cashflow_has_open_discrepancy(mysqli $conn, string $from, string $to): int {
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) c FROM cash_flow_reconciliation WHERE status = 'discrepancy' AND business_date BETWEEN ? AND ?");
    mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
    mysqli_stmt_execute($stmt);
    $c = (int) (mysqli_stmt_get_result($stmt)->fetch_assoc()['c'] ?? 0);
    mysqli_stmt_close($stmt);
    return $c;
}

/** Appends one row to cash_flow_reconciliation_audit AND to the shared
 *  activity_log (same Activity History integration as cashflow_log_audit()). */
function cashflow_log_reconciliation_audit(
    mysqli $conn, int $reconId, int $actorId, string $actorName, string $action,
    ?string $oldStatus, string $newStatus, ?string $notes = null
): void {
    $stmt = mysqli_prepare($conn, "INSERT INTO cash_flow_reconciliation_audit
        (recon_id, actor_id, actor_name, action, old_status, new_status, notes)
        VALUES (?,?,?,?,?,?,?)");
    mysqli_stmt_bind_param($stmt, 'iisssss', $reconId, $actorId, $actorName, $action, $oldStatus, $newStatus, $notes);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    log_activity($conn, 'money', $action, 'cashflow_reconciliation', $reconId, $notes ?? ($action . ' cash flow reconciliation'), $actorId, $actorName);
}
