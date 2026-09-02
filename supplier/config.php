<?php
/**
 * CloudCup — Supplier Portal bootstrap
 * -------------------------------------------------------------
 * DB connection + session/role gate for every page under supplier/.
 * Uses mysqli ($conn), matching includes/procurement_queries.php and
 * includes/procurement_auth.php — the Supplier Portal reads/writes the
 * same procurement_* tables those files own, so it stays on the same
 * driver rather than mixing in a parallel PDO connection.
 */

require_once __DIR__ . '/../includes/DB_Connect.php';
require_once __DIR__ . '/../includes/supplier_queries.php';

if ($conn) ensure_supplier_tables($conn);

/**
 * Require the current session to be an active supplier account, and
 * resolve it to a supplier_id. Redirects to login if not authenticated
 * as a supplier at all; never trusts $_SESSION['supplier_id'] alone —
 * re-reads role/status fresh from `users` so a suspended account loses
 * access immediately, not just at next login (same rationale as
 * includes/procurement_auth.php's procurement_current_user()).
 *
 * Returns the resolved supplier_id.
 */
function supplier_require_login(): int
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    global $conn;
    $supplierId = $conn ? supplier_current_supplier_id($conn) : null;

    if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'supplier' || !$supplierId) {
        header('Location: ../auth/Login_Page.php');
        exit;
    }

    return $supplierId;
}
