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
$range_capped = false;
if ($can_view_all) {
    // Back-compat: an old bookmark/link with just ?date=... still works —
    // treated as both ends of a single-day range.
    $date_from = $_GET['date_from'] ?? $_GET['date'] ?? date('Y-m-d');
    $date_to   = $_GET['date_to']   ?? $_GET['date'] ?? date('Y-m-d');
    foreach ([&$date_from, &$date_to] as &$d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d > date('Y-m-d')) $d = date('Y-m-d');
    }
    unset($d);
    if ($date_to < $date_from) [$date_from, $date_to] = [$date_to, $date_from];

    // Cap how far back a single request can span — same limit as
    // hr_build_attendance_range()'s internal safety cap, checked here too
    // so the page can tell HR why a huge range got trimmed.
    $span_days = (strtotime($date_to) - strtotime($date_from)) / 86400 + 1;
    if ($span_days > 92) {
        $date_from = date('Y-m-d', strtotime($date_to . ' -91 day'));
        $range_capped = true;
    }

    $view_date  = $date_to; // used by the CSV export link and a couple of labels below
    $emp_filter = (int)($_GET['employee'] ?? 0);

    $rows = ($date_from === $date_to)
        ? hr_build_attendance_sheet($conn, $date_from, $emp_filter)
        : hr_build_attendance_range($conn, $date_from, $date_to, $emp_filter);
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

// --- Personal period stats (self view only, derived from the last-30-days rows above) ---
$my_total_hours = 0;
$my_present = 0;
$my_late    = 0;
$my_absent  = 0;
if (!$can_view_all) {
    foreach ($rows as $r) {
        $my_total_hours += (float)($r['hours_worked'] ?? 0);
        if ($r['status'] === 'present') $my_present++;
        elseif ($r['status'] === 'late') $my_late++;
        elseif ($r['status'] === 'absent') $my_absent++;
    }
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
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/hr_module.css"/>
  <link rel="stylesheet" href="../css/hr_attendance.css"/>
  <style>
    /* Cloud Cup attendance theme — scoped so it never leaks into the sidebar/app chrome */
    .att-page{
      --att-cream:#F5EFE6;
      --att-panel:#FFFFFF;
      --att-espresso:#3C2317;
      --att-espresso-soft:#6B4A38;
      --att-terracotta:#628E90;
      --att-terracotta-dark:#4E7274;
      --att-forest:#628E90;
      --att-amber:#8A5A3C;
      --att-rose:#6B2E22;
      --att-sky:#B4CDE6;
      --att-border:#E3DCCF;
      --att-text-light:#8A7666;
      font-family:'Inter',sans-serif;
      color:var(--att-espresso);
    }
    .att-page *{box-sizing:border-box;}

    /* Hero clock card */
    .att-hero{
      background:var(--att-espresso);
      border-radius:16px;
      padding:28px 32px;
      display:flex;justify-content:space-between;align-items:center;gap:24px;
      margin-bottom:20px;position:relative;overflow:hidden;
    }
    .att-hero::after{
      content:"";position:absolute;right:-60px;top:-60px;width:220px;height:220px;
      border-radius:50%;background:radial-gradient(circle,rgba(180,205,230,.20),transparent 70%);
      pointer-events:none;
    }
    .att-hero-left{position:relative;z-index:1;}
    .att-status-row{display:flex;align-items:center;gap:8px;margin-bottom:10px;}
    .att-status-dot{width:8px;height:8px;border-radius:50%;background:#D9B99A;box-shadow:0 0 0 4px rgba(217,185,154,.22);}
    .att-status-dot.in{background:#8FC0C2;box-shadow:0 0 0 4px rgba(143,192,194,.22);}
    .att-status-text{font-size:13px;color:#CBB9AC;font-weight:500;}
    .att-hero-time{font-family:'JetBrains Mono',monospace;font-size:40px;font-weight:500;color:#fff;letter-spacing:-1px;line-height:1;}
    .att-hero-meta{color:#B3A091;font-size:13px;margin-top:8px;}
    .att-hero-meta b{color:#EAE0D6;font-weight:600;}
    .att-btn-clock{
      position:relative;z-index:1;
      background:var(--att-terracotta);color:#fff;border:none;
      padding:14px 26px;border-radius:10px;font-size:14.5px;font-weight:600;
      cursor:pointer;transition:background .15s ease;display:flex;align-items:center;gap:8px;
      font-family:'Inter',sans-serif;
    }
    .att-btn-clock:hover:not(:disabled){background:var(--att-terracotta-dark);}
    .att-btn-clock.out{background:var(--att-rose);}
    .att-btn-clock.out:hover{background:#54221A;}
    .att-btn-clock:disabled{background:rgba(255,255,255,.15);color:#CBB9AC;cursor:default;}
    .att-btn-clock svg{width:16px;height:16px;}

    /* Stat strip */
    .att-stats{
      display:grid;grid-template-columns:repeat(4,1fr);gap:1px;
      background:var(--att-border);border:1px solid var(--att-border);border-radius:14px;
      overflow:hidden;margin-bottom:24px;
    }
    .att-stat{background:var(--att-panel);padding:18px 22px;border-top:3px solid var(--att-sky);}
    .att-stat .val{font-family:'Playfair Display',serif;font-size:24px;font-weight:700;color:var(--att-espresso);}
    .att-stat .val .unit{font-size:14px;color:var(--att-text-light);}
    .att-stat .lbl{font-size:12.5px;color:var(--att-text-light);margin-top:2px;}
    .att-stat .val.warn{color:var(--att-amber);}
    .att-stat .val.bad{color:var(--att-rose);}

    /* History panel */
    .att-panel{background:var(--att-panel);border:1px solid var(--att-border);border-radius:16px;padding:26px 28px 8px;}
    .att-panel-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px;}
    .att-panel-head h2{font-family:'Playfair Display',serif;font-size:18px;font-weight:700;margin:0;color:var(--att-espresso);}
    .att-panel-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
    .att-chip-btn{
      border:1px solid var(--att-border);background:var(--att-cream);color:var(--att-espresso-soft);
      font-size:12.5px;font-weight:600;padding:7px 12px;border-radius:8px;cursor:pointer;
      font-family:'Inter',sans-serif;text-decoration:none;
    }
    .att-chip-btn.ghost{background:transparent;}
    .att-quickrange{position:relative;}
    .att-quickrange-menu{
      display:none;position:absolute;top:calc(100% + 6px);left:0;z-index:20;
      background:var(--att-panel);border:1px solid var(--att-border);border-radius:10px;
      box-shadow:0 8px 24px rgba(60,35,23,.12);padding:6px;min-width:150px;
    }
    .att-quickrange-menu.show{display:block;}
    .att-quickrange-menu button{
      display:block;width:100%;text-align:left;background:none;border:none;
      padding:8px 10px;border-radius:7px;font-size:12.5px;font-weight:500;
      color:var(--att-espresso-soft);cursor:pointer;font-family:'Inter',sans-serif;
    }
    .att-quickrange-menu button:hover{background:var(--att-cream);color:var(--att-espresso);}

    .att-table{width:100%;border-collapse:collapse;}
    .att-table thead th{
      text-align:left;font-size:11.5px;font-weight:600;letter-spacing:.03em;
      color:var(--att-text-light);padding:0 10px 10px;border-bottom:1px solid var(--att-border);
    }
    .att-table tbody td{padding:15px 10px;font-size:13.5px;border-bottom:1px solid #EFE8DB;vertical-align:middle;color:var(--att-espresso);}
    .att-table tbody tr:last-child td{border-bottom:none;}
    .att-table .date-cell .dow{color:var(--att-text-light);font-size:11.5px;display:block;margin-top:1px;}
    .att-table .muted{color:var(--att-text-light);}
    .att-table .hours{font-family:'JetBrains Mono',monospace;font-size:13px;}
    .att-empty{text-align:center;padding:30px 10px;color:var(--att-text-light);}

    .att-status-pill{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:600;}
    .att-status-pill .dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
    .att-status-pill.present{color:var(--att-forest);}
    .att-status-pill.present .dot{background:var(--att-forest);}
    .att-status-pill.late{color:var(--att-amber);}
    .att-status-pill.late .dot{background:var(--att-amber);}
    .att-status-pill.absent{color:var(--att-rose);}
    .att-status-pill.absent .dot{background:var(--att-rose);}
    .att-status-pill.onleave{color:#7A5FA0;}
    .att-status-pill.onleave .dot{background:#7A5FA0;}
    .att-status-pill.open{color:#4E7195;}
    .att-status-pill.open .dot{background:#7FA0C2;}

    .att-table tfoot td{padding:14px 10px;font-size:12.5px;color:var(--att-text-light);border-top:1px solid var(--att-border);}
    .att-table tfoot b{color:var(--att-espresso);font-weight:600;}

    @media (max-width:720px){
      .att-stats{grid-template-columns:repeat(2,1fr);}
      .att-hero{flex-direction:column;align-items:flex-start;}
      .att-table thead{display:none;}
      .att-table tbody tr{display:block;padding:14px 0;border-bottom:1px solid var(--att-border);}
      .att-table tbody td{display:flex;justify-content:space-between;padding:4px 0;border:none;}
      .att-table tbody td::before{content:attr(data-label);color:var(--att-text-light);font-size:12px;}
    }
  </style>
</head>
<body>

<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php if (current_role() === 'employee') { require_once '../staff/Sidebar_Employee.php'; } else { require_once '../HR/Sidebar_HR.php'; } ?>
<script src="../js/lucide-init.js"></script>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <h1>Attendance</h1>
    </div>
    <div class="topbar-right"><div class="topbar-date"><?= date('F j, Y') ?></div></div>
  </div>

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>" style="display:none"><?= htmlspecialchars($mm) ?></div>
    <?php endif; ?>

    <div class="att-page">

      <?php if ($can_clock_self): ?>
      <div class="att-hero">
        <div class="att-hero-left">
          <div class="att-status-row">
            <span class="att-status-dot <?= $clocked_in ? 'in' : '' ?>"></span>
            <span class="att-status-text">
              <?php if ($clocked_out): ?>
                Shift complete for today ✓
              <?php elseif ($clocked_in): ?>
                Clocked in since <?= date('g:i A', strtotime($today['time_in'])) ?>
              <?php else: ?>
                Not clocked in
              <?php endif; ?>
            </span>
          </div>
          <div class="att-hero-time" id="att-clock"><?= date('g:i A') ?></div>
          <div class="att-hero-meta">
            <?php if ($clocked_out): ?>
              You worked <b><?= number_format($today['hours_worked'], 2) ?>h</b> today
            <?php elseif ($clocked_in): ?>
              Clocked in at <b><?= date('g:i A', strtotime($today['time_in'])) ?></b>
            <?php else: ?>
              You haven't clocked in yet today
            <?php endif; ?>
          </div>
        </div>
        <form method="POST" id="attClockForm">
          <?php if (!$today || !$today['time_in']): ?>
            <input type="hidden" name="act" value="clock_in">
            <button type="button" class="att-btn-clock in" onclick="confirmClock('clock_in')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
              Clock In
            </button>
          <?php elseif (!$today['time_out']): ?>
            <input type="hidden" name="act" value="clock_out">
            <button type="button" class="att-btn-clock out" onclick="confirmClock('clock_out')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
              Clock Out
            </button>
          <?php else: ?>
            <button type="button" class="att-btn-clock" disabled>Done for today</button>
          <?php endif; ?>
        </form>
      </div>
      <?php endif; ?>

      <?php if ($can_view_all && $sheet_counts !== null): ?>
      <div class="att-stats">
        <div class="att-stat"><div class="val"><?= $sheet_counts['present'] ?></div><div class="lbl">Present</div></div>
        <div class="att-stat"><div class="val warn"><?= $sheet_counts['late'] ?></div><div class="lbl">Late</div></div>
        <div class="att-stat"><div class="val bad"><?= $sheet_counts['absent'] ?></div><div class="lbl">Absent</div></div>
        <div class="att-stat"><div class="val"><?= $sheet_counts['on_leave'] ?></div><div class="lbl">On Leave</div></div>
      </div>
      <?php if ($sheet_counts['pending'] > 0): ?>
      <div class="att-hero-meta" style="margin:-14px 0 20px;color:var(--att-text-light);"><?= $sheet_counts['pending'] ?> staff not yet clocked in for this date.</div>
      <?php endif; ?>
      <?php elseif (!$can_view_all): ?>
      <div class="att-stats">
        <div class="att-stat"><div class="val"><?= number_format($my_total_hours, 1) ?><span class="unit">h</span></div><div class="lbl">Hours (last 30 days)</div></div>
        <div class="att-stat"><div class="val"><?= $my_present ?></div><div class="lbl">Days present</div></div>
        <div class="att-stat"><div class="val warn"><?= $my_late ?></div><div class="lbl">Days late</div></div>
        <div class="att-stat"><div class="val bad"><?= $my_absent ?></div><div class="lbl">Days absent</div></div>
      </div>
      <?php endif; ?>

      <?php if ($range_capped): ?>
        <div class="msg-banner error" style="margin-top:14px">That range was more than 92 days, so it was trimmed to the most recent 92 days ending <?= date('M j, Y', strtotime($date_to)) ?>.</div>
      <?php endif; ?>

      <div class="att-panel">
        <div class="att-panel-head">
          <h2><?= $can_view_all ? 'Team Attendance Sheet' : 'My Attendance History' ?></h2>
          <?php if ($can_view_all): ?>
          <form method="GET" class="att-panel-actions" id="attFilterForm">
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()" class="att-chip-btn" title="From">
            <span style="color:var(--att-text-light)">→</span>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()" class="att-chip-btn" title="To">
            <select name="employee" onchange="this.form.submit()" class="att-chip-btn">
              <option value="0">All Staff</option>
              <?php foreach ($employees as $e): ?>
                <option value="<?= $e['user_id'] ?>" <?= ($_GET['employee'] ?? '') == $e['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="att-quickrange">
              <button type="button" class="att-chip-btn" onclick="document.getElementById('attQuickMenu').classList.toggle('show')">Quick range ▾</button>
              <div class="att-quickrange-menu" id="attQuickMenu">
                <button type="button" data-preset="today">Today</button>
                <button type="button" data-preset="7d">Last 7 days</button>
                <button type="button" data-preset="30d">Last 30 days</button>
                <button type="button" data-preset="month">This month</button>
              </div>
            </div>
            <a class="att-chip-btn ghost" href="attendance_export.php?date_from=<?= urlencode($date_from) ?>&amp;date_to=<?= urlencode($date_to) ?>&amp;employee=<?= (int)($_GET['employee'] ?? 0) ?>">⬇ Export CSV</a>
          </form>
          <?php endif; ?>
        </div>

        <table class="att-table">
          <thead>
            <tr>
              <?php if ($can_view_all): ?><th>Employee</th><?php endif; ?>
              <th>Date</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $status_class = ['present'=>'present','late'=>'late','absent'=>'absent','on_leave'=>'onleave','pending'=>'open'];
              $label_map = ['present'=>'Present','late'=>'Late','absent'=>'Absent','on_leave'=>'On Leave','pending'=>'Not Yet'];
              $colcount = $can_view_all ? 6 : 5;
            ?>
            <?php if (empty($rows)): ?>
              <tr><td colspan="<?= $colcount ?>" class="att-empty"><?= $can_view_all ? 'No active staff for this range.' : 'No attendance records yet.' ?></td></tr>
            <?php else: foreach ($rows as $r):
                $status = $r['status'];
                $sc = $status_class[$status] ?? 'present';
                $label = $can_view_all ? ($label_map[$status] ?? ucfirst($status)) : ucfirst(str_replace('_',' ',$status));
                $dow = date('l', strtotime($r['work_date']));
                $still_clocked_in = $r['time_in'] && !$r['time_out'];
            ?>
            <tr>
              <?php if ($can_view_all): ?><td data-label="Employee"><?= htmlspecialchars($r['full_name']) ?></td><?php endif; ?>
              <td data-label="Date" class="date-cell"><?= date('M d, Y', strtotime($r['work_date'])) ?><span class="dow"><?= $dow ?></span></td>
              <td data-label="Time In"><?= $r['time_in'] ? date('g:i A', strtotime($r['time_in'])) : '—' ?></td>
              <td data-label="Time Out" class="<?= $r['time_out'] ? '' : 'muted' ?>"><?= $r['time_out'] ? date('g:i A', strtotime($r['time_out'])) : ($still_clocked_in ? 'Still clocked in' : '—') ?></td>
              <td data-label="Hours" class="hours <?= $r['hours_worked'] > 0 ? '' : 'muted' ?>"><?= $r['hours_worked'] > 0 ? number_format($r['hours_worked'], 2) : '—' ?></td>
              <td data-label="Status"><span class="att-status-pill <?= $sc ?>"><span class="dot"></span><?= $label ?></span></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="<?= $colcount ?>">
                Showing <?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?><?= $can_view_all ? ($date_from === $date_to ? ' for ' . date('M j, Y', strtotime($date_to)) : ' from ' . date('M j', strtotime($date_from)) . ' to ' . date('M j, Y', strtotime($date_to))) : ' (last 30 days)' ?>
                <?php if (!$can_view_all): ?>&nbsp;·&nbsp; Total logged: <b><?= number_format($my_total_hours, 2) ?>h</b><?php endif; ?>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

    </div>
  </div>
</div>

<script>
  (function(){
    var elClock = document.getElementById('att-clock');
    if (!elClock) return;
    function tick(){ elClock.textContent = new Date().toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'}); }
    tick(); setInterval(tick, 1000 * 30);
  })();
</script>

<script>
  // Quick date-range presets for the Team Attendance Sheet filter.
  (function(){
    var form = document.getElementById('attFilterForm');
    if (!form) return;
    var fromEl = form.querySelector('[name="date_from"]');
    var toEl   = form.querySelector('[name="date_to"]');
    var fmt = function(d){ return d.toISOString().slice(0, 10); };

    document.querySelectorAll('.att-quickrange-menu button[data-preset]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var today = new Date();
        var from = new Date(today), to = new Date(today);
        if (btn.dataset.preset === 'today') {
          // from = to = today
        } else if (btn.dataset.preset === '7d') {
          from.setDate(from.getDate() - 6);
        } else if (btn.dataset.preset === '30d') {
          from.setDate(from.getDate() - 29);
        } else if (btn.dataset.preset === 'month') {
          from = new Date(today.getFullYear(), today.getMonth(), 1);
        }
        fromEl.value = fmt(from);
        toEl.value   = fmt(to);
        form.submit();
      });
    });

    document.addEventListener('click', function(e){
      var menu = document.getElementById('attQuickMenu');
      if (menu && menu.classList.contains('show') && !menu.parentElement.contains(e.target)) {
        menu.classList.remove('show');
      }
    });
  })();
</script>

<script>
  // Confirm before punching, then submit the real form.
  function confirmClock(act){
    const isIn = act === 'clock_in';
    Swal.fire({
      title: isIn ? 'Clock in now?' : 'Clock out now?',
      text: isIn ? "You're about to start your shift." : "You're about to end your shift.",
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: isIn ? 'Yes, clock in' : 'Yes, clock out',
      cancelButtonText: 'Cancel',
      confirmButtonColor: isIn ? '#628E90' : '#6B2E22',
      reverseButtons: true
    }).then((result) => {
      if (result.isConfirmed) document.getElementById('attClockForm').submit();
    });
  }

  // Result of the last clock action (set server-side after the POST/redirect).
  <?php if ($msg): ?>
  document.addEventListener('DOMContentLoaded', () => {
    Swal.fire({
      title: <?= $mt === 'success' ? "'Done!'" : "'Oops!'" ?>,
      text: <?= json_encode($mm, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      icon: <?= $mt === 'success' ? "'success'" : "'error'" ?>,
      confirmButtonColor: '#628E90',
      timer: <?= $mt === 'success' ? '2500' : 'undefined' ?>,
      timerProgressBar: <?= $mt === 'success' ? 'true' : 'false' ?>
    });
  });
  <?php endif; ?>
</script>
<script src="../js/msg_banner_autodismiss.js"></script>
<script src="../js/theme-toggle.js"></script>
</body>
</html>