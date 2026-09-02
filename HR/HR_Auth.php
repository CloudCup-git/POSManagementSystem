<?php
// ── HR / RBAC HELPER LIBRARY ─────────────────────────────────────
// Include this on any page that needs fine-grained permission checks
// (HR_Page.php, and optionally Sales/Inventory/Menu pages once they're
// ready to move beyond the coarse admin/employee split).
//
// Design notes:
// - `users.role` (enum admin/employee) is left untouched everywhere
//   else in the app — every existing session_start() guard
//   (`strtolower($_SESSION['role']) === 'admin'`) keeps working as-is.
// - `users.role_id` is the new fine-grained layer: it points at
//   `hr_roles`, which is wired to `hr_permissions` via
//   `hr_role_permissions`. This module is additive, not a rewrite.
// - Permissions are cached per-request in a static array so a page
//   that calls has_permission() ten times only hits the DB once per
//   user per request.

/**
 * Returns the full set of permission keys granted to a user via their
 * assigned role, e.g. ['sales.process', 'menu.view', ...].
 */
function get_user_permissions($conn, int $user_id): array {
    static $cache = [];
    if (isset($cache[$user_id])) return $cache[$user_id];

    $perms = [];
    if ($conn && $user_id > 0) {
        $stmt = mysqli_prepare($conn,
            "SELECT p.perm_key
             FROM users u
             JOIN hr_role_permissions rp ON rp.role_id = u.role_id
             JOIN hr_permissions p       ON p.permission_id = rp.permission_id
             WHERE u.user_id = ? AND u.status = 'active'");
        mysqli_stmt_bind_param($stmt, 'i', $user_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) while ($row = mysqli_fetch_assoc($res)) $perms[] = $row['perm_key'];
    }
    $cache[$user_id] = $perms;
    return $perms;
}

/**
 * Check a single permission. Super Admins (role_name = 'Super Admin')
 * always pass, as a safety net so the HR module can never lock itself
 * out entirely.
 */
function has_permission($conn, int $user_id, string $perm_key, bool $skip_super_override = false): bool {
    if (!$skip_super_override && $conn && $user_id > 0) {
        $r = mysqli_query($conn,
            "SELECT hr.role_name FROM users u JOIN hr_roles hr ON hr.role_id = u.role_id
             WHERE u.user_id = " . (int)$user_id);
        $row = $r ? mysqli_fetch_assoc($r) : null;
        if ($row && $row['role_name'] === 'Super Admin') return true;
    }
    return in_array($perm_key, get_user_permissions($conn, $user_id), true);
}

/**
 * Hard-stop a page/action if the permission is missing. Use for whole-page
 * gates; for inline UI (hide a button), just call has_permission() directly.
 */
function require_permission($conn, int $user_id, string $perm_key): void {
    if (!has_permission($conn, $user_id, $perm_key)) {
        http_response_code(403);
        $__back_role = strtolower($_SESSION['role'] ?? '');
        $__back_href = match ($__back_role) {
            'admin'   => '../admin/Admin_Dashboard.php',
            'manager' => '../manager/Manager_Dashboard.php',
            default   => '../HR/HR_Dashboard.php',
        };
        die('<div style="font-family:sans-serif;padding:60px;text-align:center;color:#6b2a20">'
          . '<h2>403 — Access Denied</h2><p>Your role does not include this permission: <code>'
          . htmlspecialchars($perm_key) . '</code></p><a href="' . htmlspecialchars($__back_href) . '">← Back to Dashboard</a></div>');
    }
}

/**
 * Records an entry in hr_audit_log. Call this after any account or
 * role/permission change so admin actions stay traceable.
 */
function log_hr_action($conn, int $actor_id, ?int $target_id, string $action, string $details = ''): void {
    if (!$conn) return;
    $stmt = mysqli_prepare($conn,
        "INSERT INTO hr_audit_log (actor_id, target_id, action, details) VALUES (?,?,?,?)");
    mysqli_stmt_bind_param($stmt, 'iiss', $actor_id, $target_id, $action, $details);
    mysqli_stmt_execute($stmt);
}
