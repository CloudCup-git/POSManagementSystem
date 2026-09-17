<?php
/**
 * Cash Flow Overview module — identity & stage resolution.
 *
 * Mirrors includes/procurement_auth.php: never trust $_SESSION['role']/
 * ['position'] directly for authorization. Session only supplies the
 * user_id lookup key; role and position are re-read from the database on
 * every call so a stale/tampered session can't grant access. A separate
 * file from procurement_auth.php on purpose — this module is never allowed
 * to modify Procurement's code, only mirror its pattern.
 */

require_once __DIR__ . '/DB_Connect.php';

const CF_STAGE_FINANCE_OFFICER = 'finance_officer';
const CF_STAGE_FINANCE_HEAD    = 'finance_head';

/**
 * Resolve the current session user's identity fresh from the database:
 * user_id, full_name, role, position_name. Returns null if not logged in
 * or the account no longer exists/is inactive.
 */
function cashflow_current_user(): ?array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) return null;

    global $conn;
    if (!$conn) return null;

    $stmt = mysqli_prepare($conn,
        "SELECT u.user_id, u.full_name, u.role, u.is_active,
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
        'position_name' => $row['position_name'],
    ];
}

/**
 * Map role + position to exactly one Cash Flow stage, or null if the
 * account doesn't match any known stage (access denied by default — no
 * fallback guessing). Admin resolves to Finance Head so there's always a
 * backstop reviewer, matching how Admin oversees Procurement.
 */
function cashflow_stage_for_user(array $user): ?string {
    $role = $user['role'];
    $pos  = $user['position_name'];

    if ($role === 'admin') return CF_STAGE_FINANCE_HEAD;
    if ($role === 'finance' && $pos === 'Finance Officer') return CF_STAGE_FINANCE_OFFICER;
    if ($role === 'finance' && $pos === 'Finance Head')    return CF_STAGE_FINANCE_HEAD;
    return null;
}

/**
 * Hard gate for a page or POST action. Redirects (or exits with JSON 403
 * for AJAX/JSON requests) unless the current user resolves to one of the
 * allowed stages. Returns the resolved user array on success.
 */
function cashflow_require_stage(array $allowedStages): array {
    $user  = cashflow_current_user();
    $stage = $user ? cashflow_stage_for_user($user) : null;

    if (!$user || !$stage || !in_array($stage, $allowedStages, true)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (($_SERVER['CONTENT_TYPE'] ?? '') === 'application/json')) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Insufficient permission: you do not have access to this action.']);
            exit;
        }
        header('Location: ../auth/Login_Page.php?error=forbidden');
        exit;
    }

    $user['stage'] = $stage;
    return $user;
}
