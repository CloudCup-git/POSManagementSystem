<?php
/**
 * Procurement module — identity & stage resolution.
 *
 * Never trust $_SESSION['role'] / ['position'] / ['branch_id'] directly for
 * authorization. Session only supplies the user_id lookup key; role,
 * position, and branch are re-read from the database on every call so a
 * mid-session role/branch change (or a tampered session) can't grant stale
 * access. Mirrors the pattern in manager/Manager_Dashboard.php.
 */

require_once __DIR__ . '/DB_Connect.php';

const PROC_STAGE_INVENTORY_STAFF   = 'inventory_staff';
const PROC_STAGE_STORE_MANAGER     = 'store_manager';
const PROC_STAGE_AREA_OPS_MANAGER  = 'area_ops_manager';
const PROC_STAGE_FINANCE_OFFICER   = 'finance_officer';
const PROC_STAGE_FINANCE_MANAGER   = 'finance_manager';
const PROC_STAGE_ADMIN             = 'admin';

/**
 * Resolve the current session user's identity fresh from the database:
 * user_id, role, branch_id, position_id, position_name.
 * Returns null if not logged in or the account no longer exists/is inactive.
 */
function procurement_current_user(): ?array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) return null;

    global $conn;
    if (!$conn) return null;

    $stmt = mysqli_prepare($conn,
        "SELECT u.user_id, u.full_name, u.role, u.branch_id, u.is_active,
                e.position_id, p.position_name
         FROM users u
         LEFT JOIN employees e ON e.employee_id = u.user_id
         LEFT JOIN hr_positions p ON p.position_id = e.position_id
         WHERE u.user_id = ? LIMIT 1"
    );
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, 'i', $uid);
    mysqli_stmt_execute($stmt);
    $row = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);

    if (!$row || (int) $row['is_active'] === 0) return null;

    return [
        'user_id'       => (int) $row['user_id'],
        'full_name'     => $row['full_name'],
        'role'          => strtolower($row['role']),
        'branch_id'     => $row['branch_id'] !== null ? (int) $row['branch_id'] : null,
        'position_id'   => $row['position_id'] !== null ? (int) $row['position_id'] : null,
        'position_name' => $row['position_name'],
    ];
}

/**
 * Map role + position to exactly one procurement stage, or null if the
 * account doesn't match any known procurement stage (access denied by
 * default — no fallback guessing).
 */
function procurement_stage_for_user(array $user): ?string {
    $role = $user['role'];
    $pos  = $user['position_name'];

    if ($role === 'admin') {
        return PROC_STAGE_ADMIN;
    }
    if ($role === 'inventory_staff' || ($role === 'employee' && $pos === 'Inventory Staff')) {
        return PROC_STAGE_INVENTORY_STAFF;
    }
    if ($role === 'manager' && $pos === 'Store Manager') {
        return PROC_STAGE_STORE_MANAGER;
    }
    if ($role === 'manager' && ((int) ($user['position_id'] ?? 0) === 10 || $pos === 'Area / Operations Manager')) {
        return PROC_STAGE_AREA_OPS_MANAGER;
    }
    if ($role === 'finance' && $pos === 'Finance Officer') {
        return PROC_STAGE_FINANCE_OFFICER;
    }
    if ($role === 'finance' && $pos === 'Finance Head') {
        return PROC_STAGE_FINANCE_MANAGER;
    }
    return null;
}

/**
 * Hard gate for a page or POST action. Redirects (or exits with JSON 403
 * for AJAX/JSON requests) unless the current user resolves to one of the
 * allowed stages. Returns the resolved user array on success (so callers
 * don't have to look it up twice).
 */
function procurement_require_stage(array $allowedStages): array {
    $user = procurement_current_user();
    $stage = $user ? procurement_stage_for_user($user) : null;

    if (!$user || !$stage || !in_array($stage, $allowedStages, true)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (($_SERVER['CONTENT_TYPE'] ?? '') === 'application/json')) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You do not have permission to do that.']);
            exit;
        }
        // A logged-in supplier landing on a procurement staff/manager page
        // (bookmark, stale link) bounces to their own portal, not back to
        // the login form — suppliers never resolve to a procurement stage.
        $role = $user['role'] ?? '';
        header('Location: ' . ($role === 'supplier' ? '../supplier/Supplier_Dashboard.php' : '../auth/Login_Page.php?error=forbidden'));
        exit;
    }

    $user['stage'] = $stage;
    return $user;
}
