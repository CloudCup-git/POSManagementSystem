<?php
// ── HR MODULE — ROLE-BASED ACCESS CONTROL (RBAC) ─────────────────────────
// Single source of truth for "who can do what" across the HR module.
// Every HR page includes this file and calls require_permission(...)
// (or require_hr_login()) at the very top, before any output or query.
//
// Roles: hr_admin > manager > employee. Permissions are additive per role,
// not inherited automatically — each role's list below is explicit so
// it's easy to audit exactly what a role can touch.
//
// Note: 'hr_admin' is intentionally distinct from the POS 'admin' role
// used by Admin_Login.php/Admin_Page.php — the two systems never share
// a role value, so an HR account can never satisfy a POS admin check
// and vice versa.

const HR_PERMISSIONS = [
    'hr_admin' => [
        'view_dashboard',
        'manage_accounts',       // create/deactivate logins, assign roles
        'assign_roles',
        'manage_employees',      // edit HR profile fields (position, dept, salary...)
        'manage_salary',         // edit daily_rate / pay-sensitive fields
        'view_all_attendance',
        'clock_self',
        'view_own_schedule',
        'manage_schedule',       // build/edit weekly schedules for any employee
        'manage_leave_requests', // approve / reject
        'apply_leave',
        'run_payroll',
        'view_all_payroll',
        'view_own_payroll',
    ],
    'manager' => [
        'view_dashboard',
        'manage_employees',      // can edit position/department/contact, NOT salary
        'view_all_attendance',
        'clock_self',
        'view_own_schedule',
        'manage_schedule',       // build/edit weekly schedules for any employee
        'manage_leave_requests', // can approve/reject own team's requests
        'apply_leave',
        'view_all_payroll',      // read-only, enforced at the page level
        'view_own_payroll',
    ],
    'employee' => [
        'view_dashboard',
        'clock_self',
        'view_own_attendance',
        'view_own_schedule',
        'apply_leave',
        'view_own_payroll',
    ],
];

function current_role(): string {
    return strtolower($_SESSION['role'] ?? '');
}

function current_hr_user_id(): int {
    return (int)($_SESSION['user_id'] ?? $_SESSION['employee_id'] ?? 0);
}

function has_permission(string $perm): bool {
    $role = current_role();
    // hr_admin is the module's top-level HR Admin — always passes, so the HR
    // module can never lock itself out regardless of what's in
    // HR_PERMISSIONS above.
    if ($role === 'hr_admin') return true;
    return in_array($perm, HR_PERMISSIONS[$role] ?? [], true);
}

// Hard gate for an entire page or POST action. Redirects (pages) or
// exits with a JSON error (AJAX) if the current role lacks $perm.
function require_permission(string $perm): void {
    if (!has_permission($perm)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (($_SERVER['CONTENT_TYPE'] ?? '') === 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'You do not have permission to do that.']);
            exit;
        }
        header('Location: HR_Dashboard.php?error=forbidden');
        exit;
    }
}

// Every HR page (except HR_Login.php) starts with this.
function require_hr_login(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // Prevent the browser from serving this page out of its back/forward
    // cache (bfcache) or disk cache. Without this, clicking Back/Forward
    // after logout can re-display the last-rendered dashboard HTML
    // straight from browser memory, without ever hitting the server (and
    // therefore without ever re-running the session check below).
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    $role = current_role();
    if (!isset($_SESSION['user_id']) && !isset($_SESSION['employee_id'])) {
        header('Location: HR_Login.php');
        exit;
    }
    if (!in_array($role, ['hr_admin', 'manager', 'employee'], true)) {
        header('Location: HR_Login.php');
        exit;
    }
}

function role_label(string $role): string {
    return match ($role) {
        'hr_admin' => 'HR Admin',
        'manager'  => 'Manager',
        'employee' => 'Employee',
        'finance'  => 'Finance',
        default    => ucfirst($role),
    };
}