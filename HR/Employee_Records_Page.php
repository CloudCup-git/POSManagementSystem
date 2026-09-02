<?php
require_once __DIR__ . '/../admin/Permissions.php';
require_hr_login();
require_permission('manage_employees'); // admin + manager
require_once __DIR__ . '/../includes/DB_Connect.php';
require_once __DIR__ . '/../includes/HR_Reference_Data.php';
require_once __DIR__ . '/../includes/Leave_Status_Sync.php';

// NOTE: assumes `employees.employment_type` exists. If it doesn't yet, add it with:
//   ALTER TABLE employees ADD COLUMN employment_type VARCHAR(20) NOT NULL DEFAULT 'full-time';
// Drives the AM/PM shift-period rule in HR/Schedule_Page.php: full-time shifts
// must cross from AM into PM, part-time shifts may sit entirely within one period.
//
// NOTE: assumes `employees.termination_date` and `employees.termination_reason` exist.
// If they don't yet, add them with:
//   ALTER TABLE employees ADD COLUMN termination_date DATE NULL;
//   ALTER TABLE employees ADD COLUMN termination_reason VARCHAR(255) NULL;
// These are only set when employment_status = 'terminated' and are cleared
// automatically if the status is changed back to active/on_leave.

$active_page  = 'hr_records';
$can_salary   = has_permission('manage_salary'); // admin only
$msg          = '';

// Status filter tab — drives which slice of the roster is shown (and how it's sorted).
$view = $_GET['view'] ?? 'active';
if (!in_array($view, ['active', 'on_leave', 'terminated'], true)) $view = 'active';

if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'save_record') {
    $emp_id     = (int)($_POST['employee_id'] ?? 0);
    $position   = trim($_POST['position'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $contact    = trim($_POST['contact_number'] ?? '');
    $address    = trim($_POST['address'] ?? '');
    // Date hired is not editable from this form — it's set once when the employee
    // record is created and intentionally excluded from the UPDATE below.
    $status     = in_array($_POST['employment_status'] ?? '', ['active','on_leave','terminated'], true) ? $_POST['employment_status'] : 'active';
    $emp_type   = in_array($_POST['employment_type'] ?? '', ['full-time','part-time'], true) ? $_POST['employment_type'] : 'full-time';

    // Termination date/reason only apply — and are only required — when status is 'terminated'.
    // Switching status away from 'terminated' clears them out.
    $term_date   = null;
    $term_reason = null;
    if ($status === 'terminated') {
        $term_date   = $_POST['termination_date'] ?? '';
        $term_reason = trim($_POST['termination_reason'] ?? '');
    }

    if (!$emp_id) {
        $msg = 'error:Invalid employee.';
    } elseif ($status === 'terminated' && (!$term_date || $term_date > date('Y-m-d'))) {
        $msg = 'error:Please provide a valid termination date (not in the future).';
    } elseif ($status === 'terminated' && $term_reason === '') {
        $msg = 'error:Please provide a reason for termination.';
    } else {
        if ($can_salary) {
            $rate = (float)($_POST['daily_rate'] ?? 0);
            $s = mysqli_prepare($conn,
                "UPDATE employees SET position=?, department=?, contact_number=?, address=?, employment_status=?, employment_type=?, daily_rate=?, termination_date=?, termination_reason=? WHERE employee_id=?");
            mysqli_stmt_bind_param($s, 'ssssssdssi', $position, $department, $contact, $address, $status, $emp_type, $rate, $term_date, $term_reason, $emp_id);
        } else {
            // Managers cannot change pay rate.
            $s = mysqli_prepare($conn,
                "UPDATE employees SET position=?, department=?, contact_number=?, address=?, employment_status=?, employment_type=?, termination_date=?, termination_reason=? WHERE employee_id=?");
            mysqli_stmt_bind_param($s, 'ssssssssi', $position, $department, $contact, $address, $status, $emp_type, $term_date, $term_reason, $emp_id);
        }
        mysqli_stmt_execute($s);
        $msg = 'success:Employee record updated.';

        // Terminating someone also locks their login — a terminated employee shouldn't
        // still be able to sign in. Skip the (rare) case of an HR user editing their own
        // record so nobody can accidentally lock themselves out from this form.
        if ($status === 'terminated' && $emp_id !== current_hr_user_id()) {
            mysqli_query($conn, "UPDATE users SET is_active=0 WHERE user_id=" . (int)$emp_id);
        }
    }
}

// Keep "On Leave" in sync with approved leave requests before we read
// the roster below, so this page reflects the same status Leave
// Management just set (or that has kicked in / lapsed since the last visit).
sync_employee_leave_statuses($conn);

$records = [];
$res = mysqli_query($conn,
    "SELECT u.user_id, u.full_name, u.role, u.is_active, e.*
     FROM users u
     LEFT JOIN employees e ON e.employee_id = u.user_id
     WHERE u.role IN ('employee','manager')
     ORDER BY u.full_name");
if ($res) while ($r = mysqli_fetch_assoc($res)) $records[] = $r;

// Tab counts, computed from the full roster regardless of the active filter.
$counts = ['all' => count($records), 'active' => 0, 'on_leave' => 0, 'terminated' => 0];
foreach ($records as $r) {
    $es = $r['employment_status'] ?? 'active';
    if (isset($counts[$es])) $counts[$es]++;
}

// Apply the active tab filter, and sort terminated employees most-recent-first
// so the newest terminations are easy to find without hunting through the list.
$display = array_values(array_filter($records, function ($r) use ($view) {
    $es = $r['employment_status'] ?? 'active';
    return $es === $view;
}));
if ($view === 'terminated') {
    usort($display, function ($a, $b) {
        return strcmp($b['termination_date'] ?? '', $a['termination_date'] ?? '');
    });
}
$show_term_cols = ($view === 'terminated');

$tab_labels = ['active' => 'Active', 'on_leave' => 'On Leave', 'terminated' => 'Terminated'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script>(function(){try{var t=localStorage.getItem('cloudcup-theme')||'light';document.documentElement.classList.toggle('dark-mode',t==='dark');document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="stylesheet" href="../css/theme.css"/>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Employee Records — Cloud Cup HR</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/hr_module.css"/>
</head>
<body>

<script src="../js/sidebar-toggle.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php require_once '../HR/Sidebar_HR.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle-btn" onclick="toggleSidebar()">☰</button>
      <h1>Employee Records</h1>
    </div>
    <div class="topbar-right"><div class="topbar-date"><?= date('F j, Y') ?></div></div>
  </div>

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>"><?= $mm ?></div>
    <?php endif; ?>
    <?php if (!$can_salary): ?>
      <div class="msg-banner" style="background:rgba(59,130,192,.08);color:#2c5f86">As a Manager, you can update position, department, and contact info. Pay rate changes require an Admin.</div>
    <?php endif; ?>

    <div class="filter-tabs">
      <?php foreach ($tab_labels as $key => $label): ?>
        <a class="filter-tab<?= $view === $key ? ' active' : '' ?>" href="?view=<?= $key ?>">
          <?= $label ?> <span class="count"><?= $counts[$key] ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="widget">
      <div class="widget-header">
        <div class="widget-title">Staff Profiles — <?= $tab_labels[$view] ?></div>
        <div style="font-size:12px;color:var(--hr-text-light)">New accounts are created from Accounts &amp; Roles</div>
      </div>
      <table>
        <thead>
          <tr>
            <th>Name</th><th>Role</th><th>Position</th><th>Department</th><th>Type</th><th>Status</th><th>Daily Rate</th>
            <?php if ($show_term_cols): ?><th>Terminated On</th><th>Reason</th><?php endif; ?>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($display)): ?>
            <tr><td colspan="<?= $show_term_cols ? 10 : 8 ?>" class="empty-state">
              <?= empty($records) ? 'No employee records yet. Create accounts first in Accounts &amp; Roles.' : 'No employees in this view.' ?>
            </td></tr>
          <?php else: foreach ($display as $r): $es = $r['employment_status'] ?? 'active'; $pc = ['active'=>'pill-active','on_leave'=>'pill-onleave','terminated'=>'pill-terminated'][$es] ?? 'pill-active'; $et = $r['employment_type'] ?? 'full-time'; ?>
          <tr>
            <td><?= htmlspecialchars($r['full_name']) ?></td>
            <td><?= role_label($r['role']) ?></td>
            <td><?= htmlspecialchars($r['position'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['department'] ?? '—') ?></td>
            <td><?= $et === 'part-time' ? 'Part-time' : 'Full-time' ?></td>
            <td><span class="status-pill <?= $pc ?>"><?= ucfirst(str_replace('_',' ',$es)) ?></span></td>
            <td>₱<?= number_format((float)($r['daily_rate'] ?? 0), 2) ?></td>
            <?php if ($show_term_cols): ?>
              <td><?= !empty($r['termination_date']) ? htmlspecialchars(date('M j, Y', strtotime($r['termination_date']))) : '—' ?></td>
              <td><?= htmlspecialchars($r['termination_reason'] ?? '—') ?></td>
            <?php endif; ?>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <?php if ($es !== 'terminated'): ?><button class="btn btn-ghost btn-sm" onclick='openEditModal(<?= json_encode($r) ?>)'>Edit</button><?php endif; ?>
                <a class="btn btn-ghost btn-sm employee-record-link" href="Employee_Application_Record.php?id=<?= (int)$r['user_id'] ?>" data-name="<?= htmlspecialchars($r['full_name'], ENT_QUOTES) ?>">Employee Record</a>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal-overlay-admin" id="editModal">
  <div class="modal-admin-box">
    <div class="modal-admin-header">
      <span id="editModalTitle">Edit Employee Record</span>
      <button class="modal-close-btn" onclick="document.getElementById('editModal').classList.remove('open')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="act" value="save_record">
      <input type="hidden" name="employee_id" id="f_employee_id">
      <div class="form-row">
        <div class="form-group-admin">
          <label>Position</label>
          <select name="position" id="f_position">
            <option value="">— Select position —</option>
            <?php foreach (HR_POSITIONS as $pos): ?>
              <option value="<?= htmlspecialchars($pos) ?>"><?= htmlspecialchars($pos) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group-admin">
          <label>Department</label>
          <select name="department" id="f_department">
            <option value="">— Select department —</option>
            <?php foreach (HR_DEPARTMENTS as $dept): ?>
              <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group-admin">
          <label>Contact Number</label>
          <input type="text" name="contact_number" id="f_contact">
        </div>
        <div class="form-group-admin">
          <label>Date Hired</label>
          <input type="date" id="f_hired" readonly disabled>
          <span class="field-hint">Set when the record was created — not editable here.</span>
        </div>
      </div>
      <div class="form-group-admin">
        <label>Address</label>
        <input type="text" name="address" id="f_address">
      </div>
      <div class="form-row">
        <div class="form-group-admin">
          <label>Employment Status</label>
          <select name="employment_status" id="f_status" onchange="toggleTerminationFields()">
            <option value="active">Active</option>
            <option value="on_leave">On Leave</option>
            <option value="terminated">Terminated</option>
          </select>
        </div>
        <div class="form-group-admin">
          <label>Employment Type</label>
          <select name="employment_type" id="f_emp_type">
            <option value="full-time">Full-time</option>
            <option value="part-time">Part-time</option>
          </select>
        </div>
      </div>
      <div class="form-row" id="terminationFields" style="display:none">
        <div class="form-group-admin">
          <label>Termination Date *</label>
          <input type="date" name="termination_date" id="f_term_date" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group-admin">
          <label>Reason for Termination *</label>
          <select name="termination_reason" id="f_term_reason">
            <option value="">Select a reason…</option>
            <optgroup label="Voluntary">
              <option value="Resignation">Resignation</option>
              <option value="Retirement">Retirement</option>
            </optgroup>
            <optgroup label="End of Contract">
              <option value="End of Contract">End of Contract (fixed-term expired)</option>
            </optgroup>
            <optgroup label="Involuntary — For Cause">
              <option value="Misconduct">Misconduct</option>
              <option value="Poor Performance">Poor Performance</option>
              <option value="Policy Violation">Policy Violation</option>
              <option value="Absenteeism">Absenteeism</option>
            </optgroup>
            <optgroup label="Involuntary — No Cause">
              <option value="Redundancy">Redundancy</option>
              <option value="Retrenchment">Retrenchment</option>
              <option value="Business Closure">Business Closure</option>
            </optgroup>
            <optgroup label="Other">
              <option value="Mutual Agreement">Mutual Agreement</option>
              <option value="Other">Other</option>
            </optgroup>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group-admin">
          <label>Daily Rate (₱)<?= $can_salary ? ' *' : ' (admin only)' ?></label>
          <input type="number" step="0.01" min="0" name="daily_rate" id="f_rate" <?= $can_salary ? '' : 'disabled' ?>>
        </div>
      </div>
      <div class="modal-admin-actions">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('editModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function setDropdownValue(selectEl, value) {
  value = value || '';
  var hasOption = Array.prototype.some.call(selectEl.options, function (o) { return o.value === value; });
  if (value && !hasOption) {
    var custom = document.createElement('option');
    custom.value = value;
    custom.textContent = value + ' (existing)';
    selectEl.appendChild(custom);
  }
  selectEl.value = value;
}

function toggleTerminationFields() {
  var isTerminated = document.getElementById('f_status').value === 'terminated';
  var wrap = document.getElementById('terminationFields');
  wrap.style.display = isTerminated ? 'flex' : 'none';
  document.getElementById('f_term_date').required = isTerminated;
  document.getElementById('f_term_reason').required = isTerminated;
}

function openEditModal(rec) {
  document.getElementById('editModalTitle').textContent = 'Edit — ' + rec.full_name;
  document.getElementById('f_employee_id').value = rec.user_id;
  setDropdownValue(document.getElementById('f_position'), rec.position);
  setDropdownValue(document.getElementById('f_department'), rec.department);
  document.getElementById('f_contact').value = rec.contact_number || '';
  document.getElementById('f_hired').value = rec.date_hired || '';
  document.getElementById('f_address').value = rec.address || '';
  document.getElementById('f_status').value = rec.employment_status || 'active';
  document.getElementById('f_emp_type').value = rec.employment_type || 'full-time';
  document.getElementById('f_rate').value = rec.daily_rate || 0;
  document.getElementById('f_term_date').value = rec.termination_date || '';
  setDropdownValue(document.getElementById('f_term_reason'), rec.termination_reason || '');
  toggleTerminationFields();
  document.getElementById('editModal').classList.add('open');
}
document.querySelectorAll('.modal-overlay-admin').forEach(function(m){
  m.addEventListener('click', function(e){ if (e.target === m) m.classList.remove('open'); });
});

document.querySelectorAll('.employee-record-link').forEach(function (link) {
  link.addEventListener('click', function (e) {
    e.preventDefault();
    Swal.fire({
      title: 'View employee record?',
      html: 'Open the full application record for <b>' + link.dataset.name + '</b>?',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, view it',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#2f6690',
      reverseButtons: true
    }).then(function (result) {
      if (result.isConfirmed) window.location.href = link.href;
    });
  });
});
</script>

<script src="../js/msg_banner_autodismiss.js"></script>
<script src="../js/theme-toggle.js"></script>
</body>
</html>