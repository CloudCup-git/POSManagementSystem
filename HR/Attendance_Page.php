<?php
require_once __DIR__ . '/../admin/Permissions.php';
require_hr_login();
// Clocking in/out is a floor-staff action now — hr_admin and manager (the
// "admin and hr" roles) only need this page to view the team attendance
// sheet, not to punch a clock themselves. Checked by role directly rather
// than has_permission('clock_self'): hr_admin bypasses every permission
// check in has_permission(), so removing 'clock_self' from its list alone
// wouldn't actually hide the clock-in card for that role.
if (!has_permission('clock_self') && !has_permission('view_all_attendance')) {
    header('Location: HR_Dashboard.php?error=forbidden');
    exit;
}
require_once __DIR__ .'/../includes/DB_Connect.php';
require_once __DIR__ . '/../includes/Attendance_Sheet_Data.php';

$active_page = 'hr_attendance';
$uid         = current_hr_user_id();
$role        = current_role();
$can_view_all  = has_permission('view_all_attendance');
$can_clock_self = ($role === 'employee'); // admin/HR no longer clock themselves in
$msg = '';

if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST' && $can_clock_self) {
    $act = $_POST['act'] ?? '';

    if ($act === 'clock_in') {
        $existing = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM attendance WHERE employee_id=$uid AND work_date=CURDATE()"));
        if ($existing && $existing['time_in']) {
            $msg = 'error:You already clocked in today.';
        } else {
            $now = date('Y-m-d H:i:s');
            $status = (date('H') >= 9) ? 'late' : 'present'; // shop opens 9am, illustrative cutoff
            $s = mysqli_prepare($conn,
                "INSERT INTO attendance (employee_id, work_date, time_in, status) VALUES (?, CURDATE(), ?, ?)
                 ON DUPLICATE KEY UPDATE time_in=VALUES(time_in), status=VALUES(status)");
            mysqli_stmt_bind_param($s, 'iss', $uid, $now, $status);
            mysqli_stmt_execute($s);
            $msg = 'success:Clocked in at ' . date('g:i A') . '.';
        }
    } elseif ($act === 'clock_out') {
        $existing = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM attendance WHERE employee_id=$uid AND work_date=CURDATE()"));
        if (!$existing || !$existing['time_in']) {
            $msg = 'error:You need to clock in first.';
        } elseif ($existing['time_out']) {
            $msg = 'error:You already clocked out today.';
        } else {
            $now   = date('Y-m-d H:i:s');
            $hours = round((strtotime($now) - strtotime($existing['time_in'])) / 3600, 2);
            $s = mysqli_prepare($conn, "UPDATE attendance SET time_out=?, hours_worked=? WHERE employee_id=? AND work_date=CURDATE()");
            mysqli_stmt_bind_param($s, 'sdi', $now, $hours, $uid);
            mysqli_stmt_execute($s);
            $msg = 'success:Clocked out at ' . date('g:i A') . '. Hours worked: ' . $hours . '.';
        }
    }
}

$today = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM attendance WHERE employee_id=$uid AND work_date=CURDATE()"));
$clocked_in  = $today && $today['time_in'] && !$today['time_out'];
$clocked_out = $today && $today['time_out'];

$rows = [];
$sheet_counts = null;
$view_date = date('Y-m-d');
if ($can_view_all) {
    $view_date = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $view_date) || $view_date > date('Y-m-d')) {
        $view_date = date('Y-m-d');
    }
    $emp_filter = (int)($_GET['employee'] ?? 0);

    $rows = hr_build_attendance_sheet($conn, $view_date, $emp_filter);
    $sheet_counts = hr_attendance_sheet_counts($rows);

    $employees = [];
    $er = mysqli_query($conn,
        "SELECT u.user_id, u.full_name FROM users u
         LEFT JOIN employees e ON e.employee_id = u.user_id
         WHERE u.role IN ('employee','manager')
           AND COALESCE(e.employment_status, 'active') <> 'terminated'
         ORDER BY u.full_name");
    if ($er) while ($r = mysqli_fetch_assoc($er)) $employees[] = $r;
} else {
    $res = mysqli_query($conn, "SELECT * FROM attendance WHERE employee_id=$uid ORDER BY work_date DESC LIMIT 30");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script>(function(){try{var t=localStorage.getItem('cloudcup-theme')||'light';document.documentElement.classList.toggle('dark-mode',t==='dark');document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="stylesheet" href="../css/theme.css"/>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Attendance — Cloud Cup HR</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/hr_module.css"/>
  <link rel="stylesheet" href="../css/hr_attendance.css"/>
</head>
<body>

<script src="../js/sidebar-toggle.js"></script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<?php if (current_role() === 'employee') { require_once '../staff/Sidebar_Employee.php'; } else { require_once '../HR/Sidebar_HR.php'; } ?>
<script src="../js/lucide-init.js"></script>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      
      <h1>Attendance</h1>
    </div>
    <div class="topbar-right"><div class="topbar-date"><?= date('F j, Y') ?></div></div>
  </div>

  <?php // tabs removed — navigation now lives in the sidebar ?>

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>"><?= $mm ?></div>
    <?php endif; ?>

    <?php if ($can_clock_self): ?>
    <div class="clock-card">
      <div>
        <div class="clock-time"><?= date('g:i A') ?></div>
        <div class="clock-sub"><?= $clocked_out ? 'Shift complete for today ✓' : ($clocked_in ? 'Currently clocked in since ' . date('g:i A', strtotime($today['time_in'])) : 'You have not clocked in yet') ?></div>
      </div>
      <form method="POST">
        <?php if (!$today || !$today['time_in']): ?>
          <input type="hidden" name="act" value="clock_in">
          <button type="submit" class="btn" style="background:#114516;color:var(--hr-caramel-dark)">Clock In</button>
        <?php elseif (!$today['time_out']): ?>
          <input type="hidden" name="act" value="clock_out">
          <button type="submit" class="btn" style="background:#083257;color:var(--hr-caramel-dark)">Clock Out</button>
        <?php else: ?>
          <button type="button" class="btn" disabled style="background:rgba(255,255,255,.3);color:#fff">Done for today</button>
        <?php endif; ?>
      </form>
    </div>
    <?php endif; ?>

    <div class="widget">
      <div class="widget-header">
        <div class="widget-title"><?= $can_view_all ? 'Team Attendance Sheet' : 'My Attendance History' ?></div>
        <?php if ($can_view_all): ?>
        <form method="GET" class="attendance-filters">
          <input type="date" name="date" value="<?= htmlspecialchars($view_date) ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()">
          <select name="employee" onchange="this.form.submit()">
            <option value="0">All Staff</option>
            <?php foreach ($employees as $e): ?>
              <option value="<?= $e['user_id'] ?>" <?= ($_GET['employee'] ?? '') == $e['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <a class="btn btn-ghost btn-sm" style="text-decoration:none" href="attendance_export.php?date=<?= urlencode($view_date) ?>&amp;employee=<?= (int)($_GET['employee'] ?? 0) ?>">⬇ Export to Excel</a>
        </form>
        <?php endif; ?>
      </div>

      <?php if ($can_view_all && $sheet_counts !== null): ?>
      <div class="att-summary">
        <div class="att-chip"><span class="dot" style="background:var(--success)"></span>Present <b><?= $sheet_counts['present'] ?></b></div>
        <div class="att-chip"><span class="dot" style="background:var(--warning)"></span>Late <b><?= $sheet_counts['late'] ?></b></div>
        <div class="att-chip"><span class="dot" style="background:var(--danger)"></span>Absent <b><?= $sheet_counts['absent'] ?></b></div>
        <div class="att-chip"><span class="dot" style="background:var(--caramel)"></span>On Leave <b><?= $sheet_counts['on_leave'] ?></b></div>
        <?php if ($sheet_counts['pending'] > 0): ?>
        <div class="att-chip"><span class="dot" style="background:var(--text-light)"></span>Not Yet Clocked In <b><?= $sheet_counts['pending'] ?></b></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <table>
        <thead>
          <tr>
            <?php if ($can_view_all): ?><th>Employee</th><?php endif; ?>
            <th>Date</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $pill_map = ['present'=>'pill-present','late'=>'pill-pending','absent'=>'pill-absent','on_leave'=>'pill-onleave','pending'=>'pill-scheduled'];
            $label_map = ['present'=>'Present','late'=>'Late','absent'=>'Absent','on_leave'=>'On Leave','pending'=>'Not Yet'];
          ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="6" class="empty-state"><?= $can_view_all ? 'No active staff for this date.' : 'No attendance records yet.' ?></td></tr>
          <?php else: foreach ($rows as $r):
              $status = $r['status'];
              $pc = $pill_map[$status] ?? 'pill-present';
              $label = $can_view_all ? ($label_map[$status] ?? ucfirst($status)) : ucfirst(str_replace('_',' ',$status));
          ?>
          <tr>
            <?php if ($can_view_all): ?><td><?= htmlspecialchars($r['full_name']) ?></td><?php endif; ?>
            <td><?= date('M d, Y', strtotime($r['work_date'])) ?></td>
            <td><?= $r['time_in'] ? date('g:i A', strtotime($r['time_in'])) : '—' ?></td>
            <td><?= $r['time_out'] ? date('g:i A', strtotime($r['time_out'])) : '—' ?></td>
            <td><?= $r['hours_worked'] > 0 ? number_format($r['hours_worked'], 2) : '—' ?></td>
            <td><span class="status-pill <?= $pc ?>"><?= $label ?></span></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="../js/msg_banner_autodismiss.js"></script>
<script src="../js/theme-toggle.js"></script>
</body>
</html>