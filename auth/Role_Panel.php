<?php
require_once __DIR__ . '/../admin/Permissions.php';
require_hr_login();
require_permission('manage_accounts'); // hr_admin only
require_once __DIR__ . '/../includes/DB_Connect.php';

$full_name   = $_SESSION['full_name'] ?? 'Admin';
$my_id       = current_hr_user_id();
$active_page = 'hr_accounts';
$msg         = '';

function acct_username_exists($conn, string $username, int $exclude_id = 0): bool {
    if (!$conn || $username === '') return false;
    $s = mysqli_prepare($conn, "SELECT user_id FROM users WHERE username = ? AND user_id != ? LIMIT 1");
    mysqli_stmt_bind_param($s, 'si', $username, $exclude_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    return $r && mysqli_fetch_assoc($r) ? true : false;
}

if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'create') {
        $name     = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = in_array($_POST['role'] ?? '', ['hr_admin','manager','employee','inventory_staff'], true) ? $_POST['role'] : 'employee';

        if (!$name || !$username || strlen($password) < 6) {
            $msg = 'error:Name, username, and a password of at least 6 characters are required.';
        } elseif (acct_username_exists($conn, $username)) {
            $msg = 'error:Username "' . htmlspecialchars($username) . '" is already taken.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn,
                "INSERT INTO users (full_name, username, password, role, is_active) VALUES (?,?,?,?,1)");
            mysqli_stmt_bind_param($stmt, 'ssss', $name, $username, $hash, $role);
            if (mysqli_stmt_execute($stmt)) {
                $new_id = (int)mysqli_insert_id($conn);
                // Give every non-admin an HR profile row so Employee Records has something to show.
                $ins = mysqli_prepare($conn,
                    "INSERT INTO employees (employee_id, employment_status, date_hired) VALUES (?, 'active', CURDATE())");
                mysqli_stmt_bind_param($ins, 'i', $new_id);
                mysqli_stmt_execute($ins);
                $msg = 'success:Account created for ' . htmlspecialchars($name) . '.';
            } else {
                $msg = 'error:Could not create account. Please try again.';
            }
        }
    } elseif ($act === 'change_role') {
        $target = (int)($_POST['user_id'] ?? 0);
        $role   = in_array($_POST['role'] ?? '', ['hr_admin','manager','employee','inventory_staff'], true) ? $_POST['role'] : null;
        if ($target && $role) {
            if ($target === $my_id && $role !== 'hr_admin') {
                $msg = 'error:You can\'t remove your own HR Administrator role.';
            } else {
                mysqli_query($conn, "UPDATE users SET role='" . mysqli_real_escape_string($conn, $role) . "' WHERE user_id=$target");
                $msg = 'success:Role updated.';
            }
        }
    } elseif ($act === 'toggle_active') {
        $target = (int)($_POST['user_id'] ?? 0);
        $newval = (int)($_POST['is_active'] ?? 1);
        if ($target === $my_id && $newval === 0) {
            $msg = 'error:You can\'t deactivate your own account.';
        } elseif ($target) {
            mysqli_query($conn, "UPDATE users SET is_active=$newval WHERE user_id=$target");
            $msg = 'success:Account ' . ($newval ? 'reactivated' : 'deactivated') . '.';
        }
    } elseif ($act === 'reset_password') {
        $target   = (int)($_POST['user_id'] ?? 0);
        $password = $_POST['password'] ?? '';
        if ($target && strlen($password) >= 6) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $s = mysqli_prepare($conn, "UPDATE users SET password=? WHERE user_id=?");
            mysqli_stmt_bind_param($s, 'si', $hash, $target);
            mysqli_stmt_execute($s);
            $msg = 'success:Password reset.';
        } else {
            $msg = 'error:Password must be at least 6 characters.';
        }
    }
}

$accounts = [];
$res = mysqli_query($conn, "SELECT user_id, full_name, username, role, is_active, created_at FROM users ORDER BY FIELD(role,'hr_admin','manager','employee','inventory_staff'), full_name");
if ($res) while ($r = mysqli_fetch_assoc($res)) $accounts[] = $r;

// Group accounts by role so we can render section headers + role tabs like the mock.
$role_group_labels = ['hr_admin' => 'HR Administrators', 'manager' => 'Managers', 'employee' => 'Employees', 'inventory_staff' => 'Inventory Staff'];
$grouped = ['hr_admin' => [], 'manager' => [], 'employee' => [], 'inventory_staff' => []];
foreach ($accounts as $a) {
    $r = in_array($a['role'], ['hr_admin','manager','employee','inventory_staff'], true) ? $a['role'] : 'employee';
    $grouped[$r][] = $a;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Accounts &amp; Roles — Cloud Cup HR</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/hr_module.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .role-tab { background:#fff; border:1px solid var(--hr-border); color:var(--hr-text-light); }
    .role-tab.active { background:var(--hr-primary, #2f6690); border-color:var(--hr-primary, #2f6690); color:#fff; }
  </style>
</head>
<body>

<script src="../js/sidebar-toggle.js"></script>
<?php require_once __DIR__ . '/../HR/Sidebar_HR.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle-btn" onclick="toggleSidebar()">☰</button>
      <h1>Accounts &amp; Roles</h1>
    </div>
    <div class="topbar-right"><div class="topbar-date"><?= date('F j, Y') ?></div></div>
  </div>

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>"><?= $mm ?></div>
    <?php endif; ?>

    <div class="widget">
      <div class="widget-header">
        <div>
          <div class="widget-title">Staff Accounts (RBAC)</div>
          <div style="font-size:12px;color:var(--hr-text-light);margin-top:2px">Create logins and assign a role: HR Administrator, Manager, Employee, or Inventory Staff (limited to the Inventory page).</div>
        </div>
        <button class="btn btn-primary" onclick="document.getElementById('createModal').classList.add('open')">+ New Account</button>
      </div>

      <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:14px 0 16px">
        <input type="text" id="acctSearch" placeholder="Search by name or username…"
               style="flex:1;min-width:220px;padding:9px 12px;border-radius:8px;border:1px solid var(--hr-border);font-size:13px;font-family:inherit">
        <div class="role-tabs" style="display:flex;gap:6px;flex-wrap:wrap">
          <button type="button" class="btn btn-sm role-tab active" data-role="all">All (<?= count($accounts) ?>)</button>
          <?php foreach ($role_group_labels as $rk => $rlabel): ?>
            <button type="button" class="btn btn-sm role-tab" data-role="<?= $rk ?>"><?= $rlabel ?> (<?= count($grouped[$rk]) ?>)</button>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if (empty($accounts)): ?>
        <div class="empty-state">No accounts yet.</div>
      <?php endif; ?>
      <div id="acctNoResults" class="empty-state" style="display:none">No matching accounts.</div>

      <?php foreach ($role_group_labels as $rk => $rlabel): ?>
      <div class="role-section" data-role-section="<?= $rk ?>" style="<?= empty($grouped[$rk]) ? 'display:none' : '' ?>">
        <div style="font-size:13px;font-weight:600;color:var(--hr-text-light);margin:18px 0 8px;text-transform:uppercase;letter-spacing:.03em"><?= $rlabel ?></div>
        <table>
          <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($grouped[$rk] as $a): $active = (int)$a['is_active'] === 1; ?>
            <tr class="acct-row" data-role="<?= $rk ?>" data-search="<?= htmlspecialchars(strtolower($a['full_name'] . ' ' . $a['username'])) ?>">
              <td><?= htmlspecialchars($a['full_name']) ?><?= $a['user_id'] == $my_id ? ' <span style="color:var(--hr-text-light)">(you)</span>' : '' ?></td>
              <td><?= htmlspecialchars($a['username']) ?></td>
              <td>
                <form method="POST" style="display:inline-flex;align-items:center;gap:6px">
                  <input type="hidden" name="act" value="change_role">
                  <input type="hidden" name="user_id" value="<?= $a['user_id'] ?>">
                  <select name="role" onchange="this.form.submit()" style="padding:6px 8px;border-radius:7px;border:1px solid var(--hr-border);font-size:12.5px" <?= $a['user_id'] == $my_id ? 'disabled' : '' ?>>
                    <?php foreach (['hr_admin','manager','employee','inventory_staff'] as $rl): ?>
                      <option value="<?= $rl ?>" <?= $a['role'] === $rl ? 'selected' : '' ?>><?= role_label($rl) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              </td>
              <td><span class="status-pill <?= $active ? 'pill-active' : 'pill-inactive' ?>"><?= $active ? 'Active' : 'Deactivated' ?></span></td>
              <td><?= date('M d, Y', strtotime($a['created_at'])) ?></td>
              <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                  <button class="btn btn-ghost btn-sm" onclick="openResetModal(<?= $a['user_id'] ?>, '<?= addslashes($a['full_name']) ?>')">Reset Password</button>
                  <form method="POST" style="display:inline" class="toggle-active-form"
                        data-action="<?= $active ? 'Deactivate' : 'Reactivate' ?>"
                        data-name="<?= htmlspecialchars($a['full_name'], ENT_QUOTES) ?>">
                    <input type="hidden" name="act" value="toggle_active">
                    <input type="hidden" name="user_id" value="<?= $a['user_id'] ?>">
                    <input type="hidden" name="is_active" value="<?= $active ? 0 : 1 ?>">
                    <button type="submit" class="btn btn-sm <?= $active ? 'btn-danger' : 'btn-ghost' ?>" <?= $a['user_id'] == $my_id ? 'disabled' : '' ?>>
                      <?= $active ? 'Deactivate' : 'Reactivate' ?>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- CREATE MODAL -->
<div class="modal-overlay-admin" id="createModal">
  <div class="modal-admin-box">
    <div class="modal-admin-header">
      <span>Create New Account</span>
      <button class="modal-close-btn" onclick="document.getElementById('createModal').classList.remove('open')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="act" value="create">
      <div class="form-group-admin">
        <label>Full Name *</label>
        <input type="text" name="full_name" required placeholder="e.g. Juan Dela Cruz">
      </div>
      <div class="form-row">
        <div class="form-group-admin">
          <label>Username *</label>
          <input type="text" name="username" required autocomplete="off">
        </div>
        <div class="form-group-admin">
          <label>Temporary Password *</label>
          <input type="text" name="password" required minlength="6" placeholder="min. 6 characters">
        </div>
      </div>
      <div class="form-group-admin">
        <label>Role *</label>
        <select name="role" required>
          <option value="employee">Employee</option>
          <option value="inventory_staff">Inventory Staff (inventory page only)</option>
          <option value="manager">Manager</option>
          <option value="hr_admin">HR Administrator</option>
        </select>
      </div>
      <div class="modal-admin-actions">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('createModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Account</button>
      </div>
    </form>
  </div>
</div>

<!-- RESET PASSWORD MODAL -->
<div class="modal-overlay-admin" id="resetModal">
  <div class="modal-admin-box">
    <div class="modal-admin-header">
      <span id="resetModalTitle">Reset Password</span>
      <button class="modal-close-btn" onclick="document.getElementById('resetModal').classList.remove('open')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="act" value="reset_password">
      <input type="hidden" name="user_id" id="resetUserId">
      <div class="form-group-admin">
        <label>New Password *</label>
        <input type="text" name="password" required minlength="6" placeholder="min. 6 characters">
      </div>
      <div class="modal-admin-actions">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('resetModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Reset Password</button>
      </div>
    </form>
  </div>
</div>

<script>
function openResetModal(id, name) {
  document.getElementById('resetUserId').value = id;
  document.getElementById('resetModalTitle').textContent = 'Reset Password — ' + name;
  document.getElementById('resetModal').classList.add('open');
}
document.querySelectorAll('.modal-overlay-admin').forEach(function(m){
  m.addEventListener('click', function(e){ if (e.target === m) m.classList.remove('open'); });
});

// --- Role tabs + search filtering ---
(function(){
  var searchInput = document.getElementById('acctSearch');
  var tabs         = document.querySelectorAll('.role-tab');
  var sections     = document.querySelectorAll('.role-section');
  var noResults    = document.getElementById('acctNoResults');
  var activeRole   = 'all';

  function applyFilter() {
    var term = searchInput.value.trim().toLowerCase();
    var anyVisible = false;

    sections.forEach(function(section){
      var role = section.dataset.roleSection;
      var sectionMatchesRole = (activeRole === 'all' || activeRole === role);
      var rows = section.querySelectorAll('.acct-row');
      var visibleInSection = 0;

      rows.forEach(function(row){
        var matchesSearch = !term || row.dataset.search.indexOf(term) !== -1;
        var show = sectionMatchesRole && matchesSearch;
        row.style.display = show ? '' : 'none';
        if (show) visibleInSection++;
      });

      var sectionHasRows = rows.length > 0;
      section.style.display = (sectionMatchesRole && sectionHasRows && visibleInSection > 0) ? '' : 'none';
      if (visibleInSection > 0) anyVisible = true;
    });

    noResults.style.display = anyVisible ? 'none' : '';
  }

  tabs.forEach(function(tab){
    tab.addEventListener('click', function(){
      tabs.forEach(function(t){ t.classList.remove('active'); });
      tab.classList.add('active');
      activeRole = tab.dataset.role;
      applyFilter();
    });
  });

  searchInput.addEventListener('input', applyFilter);
})();

// --- SweetAlert confirmation for Deactivate / Reactivate ---
document.querySelectorAll('.toggle-active-form').forEach(function(form){
  form.addEventListener('submit', function(e){
    e.preventDefault();
    var action = form.dataset.action; // "Deactivate" or "Reactivate"
    var name   = form.dataset.name;
    var isDeactivate = action === 'Deactivate';

    Swal.fire({
      title: action + ' account?',
      html: 'Are you sure you want to <b>' + action.toLowerCase() + '</b> the account of <b>' + name + '</b>?',
      icon: isDeactivate ? 'warning' : 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, ' + action.toLowerCase() + ' it',
      cancelButtonText: 'Cancel',
      confirmButtonColor: isDeactivate ? '#d33' : '#2f6690',
      reverseButtons: true
    }).then(function(result){
      if (result.isConfirmed) form.submit();
    });
  });
});
</script>
<script src="../js/msg_banner_autodismiss.js"></script>

</body>
</html>