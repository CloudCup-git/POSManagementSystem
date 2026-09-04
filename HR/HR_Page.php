<?php


session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');


if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role'] ?? ''), ['hr_admin', 'manager', 'employee'], true)) {
  header('Location: HR_Login.php');
  exit;
}
require_once __DIR__ . '/../includes/DB_Connect.php';
require_once __DIR__ . '/../HR/HR_Auth.php';

$full_name   = $_SESSION['full_name'] ?? 'Admin';
$current_uid = (int)$_SESSION['user_id'];
$active_page = 'hr';

if (!has_permission($conn, $current_uid, 'hr.view')) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:60px;text-align:center;color:#6b2a20">'
      . '<h2>403 — Access Denied</h2><p>Your role does not include HR module access.</p>'
      . '<a href="HR_Dashboard.php">← Back to Dashboard</a></div>');
}

function safe_query($conn, string $sql) {
  if (!$conn) { error_log('SQL error: no database connection | Query: ' . $sql); return false; }
  $result = mysqli_query($conn, $sql);
  if ($result === false) { error_log('SQL error: ' . mysqli_error($conn) . ' | Query: ' . $sql); return false; }
  return $result;
}

function hr_username_exists($conn, string $username, int $exclude_id = 0): bool {
    if (!$conn || $username === '') return false;
    $s = mysqli_prepare($conn, "SELECT user_id FROM users WHERE LOWER(username) = LOWER(?) AND user_id != ? LIMIT 1");
    mysqli_stmt_bind_param($s, 'si', $username, $exclude_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    return $r && mysqli_fetch_assoc($r) ? true : false;
}

function hr_get_role($conn, int $role_id): ?array {
    if ($role_id <= 0) return null;
    $r = mysqli_query($conn, "SELECT * FROM hr_roles WHERE role_id=" . (int)$role_id);
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return $row ?: null;
}

// ── POST ACTIONS ─────────────────────────────────────────────────
$hr_msg = '';

if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['hr_act'] ?? '';

    // ── CREATE ACCOUNT ──────────────────────────────────────────
    if ($act === 'create_account') {
        if (!has_permission($conn, $current_uid, 'hr.create_account')) {
            $hr_msg = 'error:You do not have permission to create accounts.';
        } else {
            $f_name   = trim($_POST['f_name']     ?? '');
            $f_email  = trim($_POST['f_email']    ?? '');
            $f_phone  = trim($_POST['f_phone']    ?? '');
            $f_user   = trim($_POST['f_username'] ?? '');
            $f_pass   = $_POST['f_password']      ?? '';
            $f_pos    = trim($_POST['f_position'] ?? '');
            $f_roleid = (int)($_POST['f_role_id'] ?? 0);
            $f_hired  = trim($_POST['f_hired']    ?? '') ?: null;
            $role_row = hr_get_role($conn, $f_roleid);

            if (!$f_name || !$f_user || !$f_pass || !$role_row) {
                $hr_msg = 'error:Full name, username, password, and role are required.';
            } elseif (strlen($f_pass) < 8) {
                $hr_msg = 'error:Password must be at least 8 characters.';
            } elseif (hr_username_exists($conn, $f_user)) {
                $hr_msg = 'error:Username "' . $f_user . '" is already taken.';
            } else {
                $hash      = password_hash($f_pass, PASSWORD_DEFAULT);
                $base_role = $role_row['base_role'];

                $stmt = mysqli_prepare($conn,
                    "INSERT INTO users (full_name, email, phone, username, password, role, position, role_id, status, hired_at, created_by)
                     VALUES (?,?,?,?,?,?,?,?, 'active', ?, ?)");
                //                s      s      s      s      s     s     s      i         s        i
                mysqli_stmt_bind_param($stmt, 'sssssssisi',
                    $f_name, $f_email, $f_phone, $f_user, $hash, $base_role, $f_pos, $f_roleid, $f_hired, $current_uid);

                if (mysqli_stmt_execute($stmt)) {
                    $new_id = (int)mysqli_insert_id($conn);
                    log_hr_action($conn, $current_uid, $new_id, 'create_account',
                        "Created account '{$f_user}' ({$f_name}) with role '{$role_row['role_name']}'");
                    $hr_msg = 'success:Account for "' . $f_name . '" created successfully.';
                } else {
                    $hr_msg = 'error:Could not create account. Please try again.';
                }
            }
        }
    }

    // ── EDIT ACCOUNT (profile + role reassignment + optional password reset) ──
    elseif ($act === 'edit_account') {
        if (!has_permission($conn, $current_uid, 'hr.edit_account')) {
            $hr_msg = 'error:You do not have permission to edit accounts.';
        } else {
            $uid      = (int)($_POST['user_id']    ?? 0);
            $f_name   = trim($_POST['f_name']       ?? '');
            $f_email  = trim($_POST['f_email']      ?? '');
            $f_phone  = trim($_POST['f_phone']      ?? '');
            $f_pos    = trim($_POST['f_position']   ?? '');
            $f_roleid = (int)($_POST['f_role_id']   ?? 0);
            $f_hired  = trim($_POST['f_hired']      ?? '') ?: null;
            $f_pass   = $_POST['f_password']        ?? '';
            $role_row = hr_get_role($conn, $f_roleid);

            if (!$uid || !$f_name || !$role_row) {
                $hr_msg = 'error:Name and role are required.';
            } elseif ($f_pass !== '' && strlen($f_pass) < 8) {
                $hr_msg = 'error:New password must be at least 8 characters.';
            } elseif ($uid === $current_uid && $role_row['role_name'] !== 'Super Admin') {
                // Prevent an admin from locking themselves out of HR by demoting their own account
                $hr_msg = 'error:You cannot change your own role away from Super Admin.';
            } else {
                $base_role = $role_row['base_role'];

                if ($f_pass !== '') {
                    $hash = password_hash($f_pass, PASSWORD_DEFAULT);
                    $stmt = mysqli_prepare($conn,
                        "UPDATE users SET full_name=?, email=?, phone=?, position=?, role=?, role_id=?, hired_at=?, password=?
                         WHERE user_id=?");
                    //                    s      s      s      s        s     i        s       s        i
                    mysqli_stmt_bind_param($stmt, 'sssssiss' . 'i', $f_name, $f_email, $f_phone, $f_pos, $base_role, $f_roleid, $f_hired, $hash, $uid);
                } else {
                    $stmt = mysqli_prepare($conn,
                        "UPDATE users SET full_name=?, email=?, phone=?, position=?, role=?, role_id=?, hired_at=?
                         WHERE user_id=?");
                    //                    s      s      s      s        s     i        s       i
                    mysqli_stmt_bind_param($stmt, 'sssssis' . 'i', $f_name, $f_email, $f_phone, $f_pos, $base_role, $f_roleid, $f_hired, $uid);
                }

                if (mysqli_stmt_execute($stmt)) {
                    log_hr_action($conn, $current_uid, $uid, 'edit_account',
                        "Updated account #{$uid}: name='{$f_name}', role='{$role_row['role_name']}'" . ($f_pass !== '' ? ' (password reset)' : ''));
                    $hr_msg = 'success:Account updated successfully.';
                } else {
                    $hr_msg = 'error:Could not update account. Please try again.';
                }
            }
        }
    }

    // ── SUSPEND / REACTIVATE ────────────────────────────────────
    elseif ($act === 'toggle_status') {
        if (!has_permission($conn, $current_uid, 'hr.suspend_account')) {
            $hr_msg = 'error:You do not have permission to suspend or reactivate accounts.';
        } else {
            $uid    = (int)($_POST['user_id'] ?? 0);
            $newst  = ($_POST['new_status'] ?? '') === 'active' ? 'active' : 'suspended';
            if ($uid && $uid !== $current_uid) {
                $stmt = mysqli_prepare($conn, "UPDATE users SET status=? WHERE user_id=?");
                mysqli_stmt_bind_param($stmt, 'si', $newst, $uid);
                mysqli_stmt_execute($stmt);
                log_hr_action($conn, $current_uid, $uid, 'toggle_status', "Set status to '{$newst}'");
                $hr_msg = 'success:Account status updated.';
            } elseif ($uid === $current_uid) {
                $hr_msg = 'error:You cannot suspend your own account.';
            }
        }
    }

    // ── DELETE ACCOUNT ───────────────────────────────────────────
    elseif ($act === 'delete_account') {
        if (!has_permission($conn, $current_uid, 'hr.delete_account')) {
            $hr_msg = 'error:You do not have permission to delete accounts.';
        } else {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid === $current_uid) {
                $hr_msg = 'error:You cannot delete your own account.';
            } elseif ($uid > 0) {
                // orders/inventory_log/stock_alerts hold FKs to user_id — archive
                // instead of a hard delete so historical sales/inventory records
                // don't break or orphan.
                $stmt = mysqli_prepare($conn, "UPDATE users SET status='archived' WHERE user_id=?");
                mysqli_stmt_bind_param($stmt, 'i', $uid);
                mysqli_stmt_execute($stmt);
                log_hr_action($conn, $current_uid, $uid, 'delete_account', 'Archived (soft-deleted) account');
                $hr_msg = 'success:Account removed.';
            }
        }
    }

    // ── CREATE / EDIT ROLE (role & permission management) ──────
    elseif ($act === 'save_role') {
        if (!has_permission($conn, $current_uid, 'hr.manage_roles')) {
            $hr_msg = 'error:You do not have permission to manage roles.';
        } else {
            $role_id   = (int)($_POST['role_id'] ?? 0);
            $role_name = trim($_POST['role_name'] ?? '');
            $base_role = ($_POST['base_role'] ?? '') === 'hr_admin' ? 'hr_admin' : 'employee';
            $desc      = trim($_POST['description'] ?? '');
            $perm_ids  = array_map('intval', $_POST['permissions'] ?? []);

            if (!$role_name) {
                $hr_msg = 'error:Role name is required.';
            } else {
                if ($role_id > 0) {
                    $existing = hr_get_role($conn, $role_id);
                    if ($existing && (int)$existing['is_system'] === 1) {
                        $hr_msg = 'error:System roles (Super Admin, Cashier) cannot be renamed or have their base type changed.';
                    } else {
                        $stmt = mysqli_prepare($conn, "UPDATE hr_roles SET role_name=?, base_role=?, description=? WHERE role_id=?");
                        mysqli_stmt_bind_param($stmt, 'sssi', $role_name, $base_role, $desc, $role_id);
                        mysqli_stmt_execute($stmt);
                    }
                } else {
                    $stmt = mysqli_prepare($conn, "INSERT INTO hr_roles (role_name, base_role, description, is_system) VALUES (?,?,?,0)");
                    mysqli_stmt_bind_param($stmt, 'sss', $role_name, $base_role, $desc);
                    mysqli_stmt_execute($stmt);
                    $role_id = (int)mysqli_insert_id($conn);
                }

                if ($hr_msg === '') {
                    mysqli_query($conn, "DELETE FROM hr_role_permissions WHERE role_id=" . (int)$role_id);
                    if (!empty($perm_ids)) {
                        $vals = [];
                        foreach ($perm_ids as $pid) $vals[] = "($role_id, " . (int)$pid . ")";
                        mysqli_query($conn, "INSERT INTO hr_role_permissions (role_id, permission_id) VALUES " . implode(',', $vals));
                    }
                    log_hr_action($conn, $current_uid, null, 'save_role', "Saved role '{$role_name}' with " . count($perm_ids) . " permission(s)");
                    $hr_msg = 'success:Role saved successfully.';
                }
            }
        }
    }

    // ── DELETE ROLE ──────────────────────────────────────────────
    elseif ($act === 'delete_role') {
        if (!has_permission($conn, $current_uid, 'hr.manage_roles')) {
            $hr_msg = 'error:You do not have permission to manage roles.';
        } else {
            $role_id = (int)($_POST['role_id'] ?? 0);
            $existing = hr_get_role($conn, $role_id);
            if (!$existing) {
                $hr_msg = 'error:Role not found.';
            } elseif ((int)$existing['is_system'] === 1) {
                $hr_msg = 'error:System roles cannot be deleted.';
            } else {
                $in_use = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE role_id=" . (int)$role_id));
                if ((int)($in_use['c'] ?? 0) > 0) {
                    $hr_msg = 'error:This role is assigned to ' . $in_use['c'] . ' account(s). Reassign them first.';
                } else {
                    mysqli_query($conn, "DELETE FROM hr_roles WHERE role_id=" . (int)$role_id);
                    log_hr_action($conn, $current_uid, null, 'delete_role', "Deleted role '{$existing['role_name']}'");
                    $hr_msg = 'success:Role deleted.';
                }
            }
        }
    }
}

// ── FETCH: staff directory (with search/filter) ─────────────────
$search  = trim($_GET['q']      ?? '');
$role_f  = (int)($_GET['role']  ?? 0);
$stat_f  = trim($_GET['status'] ?? '');

$where = '1=1'; 
if ($search !== '') {
    $like = '%' . mysqli_real_escape_string($conn, $search) . '%';
    $where .= " AND (u.full_name LIKE '$like' OR u.username LIKE '$like' OR u.email LIKE '$like')";
}
if ($role_f > 0)  $where .= " AND u.role_id = " . $role_f;
if (in_array($stat_f, ['active','suspended','archived'], true)) $where .= " AND u.status = '$stat_f'";

$staff_result = safe_query($conn, "
    SELECT u.user_id, u.full_name, u.email, u.phone, u.username, u.role, u.position,
           u.status, u.hired_at, u.created_at, hr.role_id, hr.role_name, hr.base_role
    FROM users u
    LEFT JOIN hr_roles hr ON hr.role_id = u.role_id
    WHERE $where
    ORDER BY u.status = 'archived', u.full_name ASC");
$staff = [];
if ($staff_result) while ($r = mysqli_fetch_assoc($staff_result)) $staff[] = $r;

// ── FETCH: all roles + permission catalog (for modals) ───────────
$roles = [];
$rr = safe_query($conn, "SELECT * FROM hr_roles ORDER BY is_system DESC, role_name ASC");
if ($rr) while ($r = mysqli_fetch_assoc($rr)) $roles[] = $r;

$all_perms = [];
$pr = safe_query($conn, "SELECT * FROM hr_permissions ORDER BY perm_group, perm_label");
if ($pr) while ($r = mysqli_fetch_assoc($pr)) $all_perms[$r['perm_group']][] = $r;

$role_perm_map = []; // role_id => [perm_id, perm_id, ...]
$rpr = safe_query($conn, "SELECT role_id, permission_id FROM hr_role_permissions");
if ($rpr) while ($r = mysqli_fetch_assoc($rpr)) $role_perm_map[$r['role_id']][] = (int)$r['permission_id'];

// ── FETCH: recent audit log ───────────────────────────────────────
$audit = [];
$ar = safe_query($conn, "
    SELECT a.*, u1.full_name AS actor_name, u2.full_name AS target_name
    FROM hr_audit_log a
    LEFT JOIN users u1 ON u1.user_id = a.actor_id
    LEFT JOIN users u2 ON u2.user_id = a.target_id
    ORDER BY a.created_at DESC LIMIT 15");
if ($ar) while ($r = mysqli_fetch_assoc($ar)) $audit[] = $r;

// ── Permission flags for the current viewer (drives UI visibility) ─
$can_create  = has_permission($conn, $current_uid, 'hr.create_account');
$can_edit    = has_permission($conn, $current_uid, 'hr.edit_account');
$can_suspend = has_permission($conn, $current_uid, 'hr.suspend_account');
$can_delete  = has_permission($conn, $current_uid, 'hr.delete_account');
$can_roles   = has_permission($conn, $current_uid, 'hr.manage_roles');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script>(function(){try{var t=localStorage.getItem('cloudcup-theme')||'light';document.documentElement.classList.toggle('dark-mode',t==='dark');document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="stylesheet" href="../css/theme.css"/>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>HR Department — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="css/admin_page.css"/>
  <link rel="stylesheet" href="css/hr_module.css"/>
</head>
<body>

<?php
if (file_exists('Sidebar_HR.php')) {
  require_once '../HR/Sidebar_HR.php';
} else {
  echo '<div style="background:#f4e3d3;border-bottom:1px solid #a6650f;padding:10px 32px;font-size:13px;color:#a6650f">'
     . '<strong>Sidebar not found.</strong> Expected <code>Sidebar_HR.php</code> in this folder.</div>';
}
?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <h1>HR Department</h1>
    </div>
    <div class="topbar-right">
      <div class="topbar-date"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:5px"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> <?= date('F j, Y') ?></div>
    </div>
  </div>

  <div class="content">

    <div class="hr-tabs">
      <button class="hr-tab active" data-tab="staff" onclick="switchHrTab('staff', this)">Staff Directory</button>
      <?php if ($can_roles): ?><button class="hr-tab" data-tab="roles" onclick="switchHrTab('roles', this)">Roles &amp; Permissions</button><?php endif; ?>
      <button class="hr-tab" data-tab="audit" onclick="switchHrTab('audit', this)">Audit Log</button>
    </div>

    <!-- ══════════════════ STAFF DIRECTORY TAB ══════════════════ -->
    <div class="hr-tab-panel" id="hr-tab-staff">
      <div class="widget" style="margin-top:0">
        <div class="widget-header" style="align-items:flex-start;flex-wrap:wrap;gap:12px">
          <div>
            <div class="widget-title">Staff Accounts</div>
            <div style="font-size:12px;color:var(--text-light);margin-top:2px">Create and manage admin &amp; employee logins with role-based permissions</div>
          </div>
          <?php if ($can_create): ?>
          <button class="btn-add-item" onclick="openAccountModal()">+ New Account</button>
          <?php endif; ?>
        </div>

        <?php if ($hr_msg): [$mt, $mm] = explode(':', $hr_msg, 2); ?>
        <div class="menu-msg <?= $mt ?>"><?= htmlspecialchars($mm) ?></div>
        <?php endif; ?>

        <form method="GET" class="hr-filter-row">
          <input type="text" name="q" placeholder="Search name, username, email…" value="<?= htmlspecialchars($search) ?>">
          <select name="role">
            <option value="0">All Roles</option>
            <?php foreach ($roles as $rl): ?>
            <option value="<?= $rl['role_id'] ?>" <?= $role_f === (int)$rl['role_id'] ? 'selected' : '' ?>><?= htmlspecialchars($rl['role_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="status">
            <option value="">All Statuses</option>
            <option value="active"    <?= $stat_f === 'active'    ? 'selected' : '' ?>>Active</option>
            <option value="suspended" <?= $stat_f === 'suspended' ? 'selected' : '' ?>>Suspended</option>
            <option value="archived"  <?= $stat_f === 'archived'  ? 'selected' : '' ?>>Archived</option>
          </select>
          <button type="submit" class="hr-filter-btn">Filter</button>
        </form>

        <table class="hr-table">
          <thead>
            <tr>
              <th>Name</th><th>Username</th><th>Role</th><th>Position</th><th>Status</th><th>Hired</th><th></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($staff)): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--text-light);padding:24px 0">No accounts match your filters.</td></tr>
            <?php else: foreach ($staff as $u):
              $st_cls = ['active'=>'st-active','suspended'=>'st-suspend','archived'=>'st-archive'][$u['status']] ?? 'st-active';
              $init   = strtoupper(substr($u['full_name'], 0, 1));
            ?>
            <tr>
              <td>
                <div class="hr-name-cell">
                  <div class="hr-avatar"><?= htmlspecialchars($init) ?></div>
                  <div>
                    <div class="hr-name"><?= htmlspecialchars($u['full_name']) ?></div>
                    <div class="hr-email"><?= htmlspecialchars($u['email'] ?: '—') ?></div>
                  </div>
                </div>
              </td>
              <td><?= htmlspecialchars($u['username']) ?></td>
              <td><span class="role-pill"><?= htmlspecialchars($u['role_name'] ?? ucfirst($u['role'])) ?></span></td>
              <td><?= htmlspecialchars($u['position'] ?: '—') ?></td>
              <td><span class="status-pill-hr <?= $st_cls ?>"><?= ucfirst($u['status']) ?></span></td>
              <td><?= $u['hired_at'] ? date('M d, Y', strtotime($u['hired_at'])) : '—' ?></td>
              <td>
                <div class="hr-action-group">
                  <?php if ($can_edit): ?>
                    <button class="menu-btn menu-btn-edit" onclick='openAccountModal(<?= json_encode($u) ?>)'>Edit</button>
                  <?php endif; ?>
                  <?php if ($can_suspend && $u['user_id'] != $current_uid && $u['status'] !== 'archived'): ?>
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="hr_act" value="toggle_status">
                      <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                      <input type="hidden" name="new_status" value="<?= $u['status'] === 'active' ? 'suspended' : 'active' ?>">
                      <button type="submit" class="menu-btn menu-btn-toggle <?= $u['status'] === 'active' ? 'off' : 'on' ?>">
                        <?= $u['status'] === 'active' ? 'Suspend' : 'Reactivate' ?>
                      </button>
                    </form>
                  <?php endif; ?>
                  <?php if ($can_delete && $u['user_id'] != $current_uid && $u['status'] !== 'archived'): ?>
                    <button class="menu-btn menu-btn-del" onclick="confirmDeleteAccount(<?= $u['user_id'] ?>, '<?= htmlspecialchars(addslashes($u['full_name'])) ?>')">Delete</button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ══════════════════ ROLES & PERMISSIONS TAB ══════════════════ -->
    <?php if ($can_roles): ?>
    <div class="hr-tab-panel" id="hr-tab-roles" style="display:none">
      <div class="widget" style="margin-top:0">
        <div class="widget-header">
          <div>
            <div class="widget-title">Roles &amp; Permissions</div>
            <div style="font-size:12px;color:var(--text-light);margin-top:2px">Each role bundles a fixed set of permissions. Assign roles to accounts from the Staff tab.</div>
          </div>
          <button class="btn-add-item" onclick="openRoleModal()">+ New Role</button>
        </div>

        <div class="role-grid">
          <?php foreach ($roles as $rl):
            $rperms = $role_perm_map[$rl['role_id']] ?? [];
            $count_in_use = 0;
            foreach ($staff as $u) if ((int)($u['role_id'] ?? 0) === (int)$rl['role_id']) $count_in_use++;
          ?>
          <div class="role-card">
            <div class="role-card-head">
              <div>
                <div class="role-card-title"><?= htmlspecialchars($rl['role_name']) ?> <?php if ($rl['is_system']): ?><span class="sys-badge">SYSTEM</span><?php endif; ?></div>
                <div class="role-card-sub"><?= htmlspecialchars($rl['base_role'] === 'hr_admin' ? 'Super Admin' : ucfirst($rl['base_role'])) ?> · <?= count($rperms) ?> permission(s) · <?= $count_in_use ?> account(s)</div>
              </div>
              <div class="hr-action-group">
                <button class="menu-btn menu-btn-edit" onclick='openRoleModal(<?= json_encode($rl) ?>, <?= json_encode($rperms) ?>)'>Edit</button>
                <?php if (!$rl['is_system']): ?>
                <button class="menu-btn menu-btn-del" onclick="confirmDeleteRole(<?= $rl['role_id'] ?>, '<?= htmlspecialchars(addslashes($rl['role_name'])) ?>')">Delete</button>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($rl['description']): ?><p class="role-card-desc"><?= htmlspecialchars($rl['description']) ?></p><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ══════════════════ AUDIT LOG TAB ══════════════════ -->
    <div class="hr-tab-panel" id="hr-tab-audit" style="display:none">
      <div class="widget" style="margin-top:0">
        <div class="widget-header">
          <div class="widget-title">Recent HR Activity</div>
        </div>
        <table class="hr-table">
          <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Target</th><th>Details</th></tr></thead>
          <tbody>
            <?php if (empty($audit)): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--text-light);padding:24px 0">No activity recorded yet.</td></tr>
            <?php else: foreach ($audit as $a): ?>
            <tr>
              <td><?= date('M d, g:i A', strtotime($a['created_at'])) ?></td>
              <td><?= htmlspecialchars($a['actor_name'] ?? '—') ?></td>
              <td><span class="role-pill"><?= htmlspecialchars(str_replace('_', ' ', $a['action'])) ?></span></td>
              <td><?= htmlspecialchars($a['target_name'] ?? '—') ?></td>
              <td class="audit-details"><?= htmlspecialchars($a['details'] ?? '') ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<!-- ══════════════════ ACCOUNT MODAL (create/edit) ══════════════════ -->
<div class="modal-overlay-admin" id="accountModal" onclick="if(event.target===this)closeAccountModal()">
  <div class="modal-admin-box">
    <div class="modal-admin-header">
      <span id="accountModalTitle">New Account</span>
      <button class="modal-close-btn" onclick="closeAccountModal()">✕</button>
    </div>
    <form method="POST" id="accountForm">
      <input type="hidden" name="hr_act" id="accountAct" value="create_account">
      <input type="hidden" name="user_id" id="acc_user_id" value="">

      <div class="form-row">
        <div class="form-group-admin">
          <label>Full Name *</label>
          <input type="text" name="f_name" id="acc_name" required placeholder="e.g. Juan Dela Cruz">
        </div>
        <div class="form-group-admin">
          <label>Position</label>
          <input type="text" name="f_position" id="acc_position" placeholder="e.g. Barista">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group-admin">
          <label>Email</label>
          <input type="email" name="f_email" id="acc_email" placeholder="name@example.com">
        </div>
        <div class="form-group-admin">
          <label>Phone</label>
          <input type="text" name="f_phone" id="acc_phone" placeholder="09xx xxx xxxx">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group-admin">
          <label>Username *</label>
          <input type="text" name="f_username" id="acc_username" required autocomplete="off">
        </div>
        <div class="form-group-admin">
          <label id="acc_password_label">Password *</label>
          <input type="password" name="f_password" id="acc_password" autocomplete="new-password" placeholder="Min. 8 characters">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group-admin">
          <label>Role *</label>
          <select name="f_role_id" id="acc_role_id" required>
            <option value="">— Select role —</option>
            <?php foreach ($roles as $rl): ?>
            <option value="<?= $rl['role_id'] ?>"><?= htmlspecialchars($rl['role_name']) ?> (<?= htmlspecialchars($rl['base_role'] === 'hr_admin' ? 'Super Admin' : ucfirst($rl['base_role'])) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group-admin">
          <label>Hire Date</label>
          <input type="date" name="f_hired" id="acc_hired">
        </div>
      </div>

      <div class="modal-admin-actions">
        <button type="button" class="modal-btn-ghost-admin" onclick="closeAccountModal()">Cancel</button>
        <button type="submit" class="modal-btn-primary-admin" id="accountModalSubmit">Create Account</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════ ROLE MODAL (create/edit + permission checkboxes) ══════════════════ -->
<?php if ($can_roles): ?>
<div class="modal-overlay-admin" id="roleModal" onclick="if(event.target===this)closeRoleModal()">
  <div class="modal-admin-box modal-admin-box-wide">
    <div class="modal-admin-header">
      <span id="roleModalTitle">New Role</span>
      <button class="modal-close-btn" onclick="closeRoleModal()">✕</button>
    </div>
    <form method="POST" id="roleForm">
      <input type="hidden" name="hr_act" value="save_role">
      <input type="hidden" name="role_id" id="role_role_id" value="">

      <div class="form-row">
        <div class="form-group-admin">
          <label>Role Name *</label>
          <input type="text" name="role_name" id="role_name" required placeholder="e.g. Shift Supervisor">
        </div>
        <div class="form-group-admin">
          <label>Base Access Level *</label>
          <select name="base_role" id="role_base">
            <option value="employee">Employee (staff-side pages)</option>
            <option value="hr_admin">Super Admin (dashboard + management pages)</option>
          </select>
        </div>
      </div>
      <div class="form-group-admin">
        <label>Description</label>
        <input type="text" name="description" id="role_description" placeholder="Short description of this role's purpose">
      </div>

      <div class="form-group-admin">
        <label>Permissions</label>
        <div class="perm-grid" id="permGrid">
          <?php foreach ($all_perms as $group => $perms): ?>
          <div class="perm-group">
            <div class="perm-group-title"><?= htmlspecialchars($group) ?></div>
            <?php foreach ($perms as $p): ?>
            <label class="perm-check">
              <input type="checkbox" name="permissions[]" value="<?= $p['permission_id'] ?>" class="perm-checkbox">
              <?= htmlspecialchars($p['perm_label']) ?>
            </label>
            <?php endforeach; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="modal-admin-actions">
        <button type="button" class="modal-btn-ghost-admin" onclick="closeRoleModal()">Cancel</button>
        <button type="submit" class="modal-btn-primary-admin">Save Role</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- HIDDEN FORMS (submitted by JS) -->
<form method="POST" id="deleteAccountForm" style="display:none">
  <input type="hidden" name="hr_act" value="delete_account">
  <input type="hidden" name="user_id" id="deleteAccountId">
</form>
<form method="POST" id="deleteRoleForm" style="display:none">
  <input type="hidden" name="hr_act" value="delete_role">
  <input type="hidden" name="role_id" id="deleteRoleId">
</form>

<script>
  const EXISTING_USERNAMES = <?= json_encode(array_column($staff, 'username')) ?>;
  const ROLE_PERM_MAP = <?= json_encode($role_perm_map, JSON_FORCE_OBJECT) ?>;
</script>
<script src="js/hr_module.js"></script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('a.logout-btn, a[href="Logout_Page.php"], a[href$="/Logout_Page.php"]').forEach(function (link) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      const href = this.getAttribute('href');
      Swal.fire({
        title: 'Log out?',
        text: "You'll need to sign in again to access the HR portal.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, log out',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#b8703f',
        cancelButtonColor: '#6b6156',
        reverseButtons: true
      }).then(function (result) {
        if (result.isConfirmed) window.location.href = href;
      });
    });
  });
});
</script>

<script src="../js/theme-toggle.js"></script>
</body>
</html>