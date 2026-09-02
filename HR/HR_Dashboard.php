<?php
require_once __DIR__ . '/../admin/Permissions.php';
require_hr_login();
require_once __DIR__ . '/../includes/DB_Connect.php';

$full_name   = $_SESSION['full_name'] ?? 'User';
$role        = current_role();
$uid         = current_hr_user_id();
$active_page = 'hr_dashboard';

function safe_query($conn, string $sql) {
    if (!$conn) { error_log('SQL error: no database connection | Query: ' . $sql); return false; }
    $result = mysqli_query($conn, $sql);
    if ($result === false) { error_log('SQL error: ' . mysqli_error($conn) . ' | Query: ' . $sql); return false; }
    return $result;
}
function safe_fetch($result): array {
    if ($result === false) return [];
    $row = mysqli_fetch_assoc($result);
    return $row ?: [];
}

$is_manager_view = has_permission('view_all_attendance'); // admin + manager

if ($is_manager_view) {
    $headcount = (int)(safe_fetch(safe_query($conn,
        "SELECT COUNT(*) c FROM users u LEFT JOIN employees e ON e.employee_id = u.user_id
         WHERE u.role IN ('employee','manager') AND u.is_active = 1
           AND COALESCE(e.employment_status, 'active') <> 'terminated'"))['c'] ?? 0);

    $on_leave_today = (int)(safe_fetch(safe_query($conn,
        "SELECT COUNT(*) c FROM leave_requests WHERE status='approved' AND CURDATE() BETWEEN date_from AND date_to"))['c'] ?? 0);

    $pending_leave = (int)(safe_fetch(safe_query($conn,
        "SELECT COUNT(*) c FROM leave_requests WHERE status='pending'"))['c'] ?? 0);

    $present_today = (int)(safe_fetch(safe_query($conn,
        "SELECT COUNT(*) c FROM attendance WHERE work_date = CURDATE() AND time_in IS NOT NULL"))['c'] ?? 0);

    $attendance_pct = $headcount > 0 ? round(($present_today / $headcount) * 100) : 0;

    $recent_leave = [];
    $rl = safe_query($conn,
        "SELECT lr.*, u.full_name FROM leave_requests lr
         JOIN users u ON u.user_id = lr.employee_id
         ORDER BY lr.created_at DESC LIMIT 6");
    if ($rl) while ($r = mysqli_fetch_assoc($rl)) $recent_leave[] = $r;

    // Staff Roster / headcount widgets are a "who's currently on the team" view —
    // terminated employees are excluded here, and so are managers: this list is
    // specifically the employee roster, not a general staff directory. Managers
    // are managed from Employee Records / Accounts & Roles instead.
    $roster = [];
    $rr = safe_query($conn,
        "SELECT u.user_id, u.full_name, u.role, e.position, e.department, e.employment_status
         FROM users u LEFT JOIN employees e ON e.employee_id = u.user_id
         WHERE u.role = 'employee'
           AND COALESCE(e.employment_status, 'active') <> 'terminated'
         ORDER BY u.full_name LIMIT 8");
    if ($rr) while ($r = mysqli_fetch_assoc($rr)) $roster[] = $r;

    $roster_total = (int)(safe_fetch(safe_query($conn,
        "SELECT COUNT(*) c FROM users u LEFT JOIN employees e ON e.employee_id = u.user_id
         WHERE u.role = 'employee'
           AND COALESCE(e.employment_status, 'active') <> 'terminated'"))['c'] ?? 0);

    $can_decide_leave = has_permission('manage_leave_requests');

    $pending_approvals = [];
    $pa = safe_query($conn,
        "SELECT lr.*, u.full_name FROM leave_requests lr
         JOIN users u ON u.user_id = lr.employee_id
         WHERE lr.status = 'pending' ORDER BY lr.created_at ASC LIMIT 5");
    if ($pa) while ($r = mysqli_fetch_assoc($pa)) $pending_approvals[] = $r;

    // Attendance for the last 7 days (Mon..Sun-style trailing window, ending today)
    $attendance_week = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $attendance_week[$d] = ['label' => ($i === 0 ? 'Today' : date('D', strtotime($d))), 'count' => 0];
    }
    $aw = safe_query($conn,
        "SELECT work_date, COUNT(*) c FROM attendance
         WHERE work_date BETWEEN CURDATE() - INTERVAL 6 DAY AND CURDATE() AND time_in IS NOT NULL
         GROUP BY work_date");
    if ($aw) while ($r = mysqli_fetch_assoc($aw)) {
        $d = date('Y-m-d', strtotime($r['work_date']));
        if (isset($attendance_week[$d])) $attendance_week[$d]['count'] = (int)$r['c'];
    }
} else {
    // Employee self-service snapshot
    $me = safe_fetch(safe_query($conn, "SELECT * FROM employees WHERE employee_id = $uid"));
    $today_att = safe_fetch(safe_query($conn, "SELECT * FROM attendance WHERE employee_id = $uid AND work_date = CURDATE()"));
    $my_pending = (int)(safe_fetch(safe_query($conn,
        "SELECT COUNT(*) c FROM leave_requests WHERE employee_id = $uid AND status='pending'"))['c'] ?? 0);
    $last_payslip = safe_fetch(safe_query($conn,
        "SELECT * FROM payroll WHERE employee_id = $uid ORDER BY period_end DESC LIMIT 1"));
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
  <title>HR Dashboard — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/hr_module.css"/>
  <link rel="stylesheet" href="../css/hr_dashboard.css"/>
</head>
<body>

<script src="../js/sidebar-toggle.js"></script>
<?php require_once '../HR/Sidebar_HR.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
      <h1>HR Dashboard</h1>
      <span class="role-pill"><?= htmlspecialchars(role_label($role)) ?></span>
    </div>
    <div class="topbar-right">
      <div class="topbar-date"><?= date('F j, Y') ?></div>
    </div>
  </div>

  <div class="content">
    <?php if (($_GET['error'] ?? '') === 'forbidden'): ?>
      <div class="msg-banner error">You don't have permission to view that page.</div>
    <?php endif; ?>

    <?php if ($is_manager_view): ?>

      <div class="page-head">
        <div>
          <h1>Dashboard</h1>
          <div class="sub">Overview of staffing, attendance, and leave for <?= date('F j, Y') ?>.</div>
        </div>
        <a href="../HR/Employee_Accounts_Page.php" class="btn btn-primary" style="text-decoration:none">+ Add Employee</a>
      </div>

      <div class="kpi-row">
        <div class="kpi-card" style="--accent-bg: rgba(181,113,61,.1)">
          <div class="kpi-top">
            <div class="kpi-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#b8703f" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
            <div class="kpi-trend flat">Active</div>
          </div>
          <div class="kpi-val"><?= $headcount ?></div>
          <div class="kpi-label">Active staff</div>
        </div>
        <div class="kpi-card" style="--accent-bg: rgba(34,197,94,.08)">
          <div class="kpi-top">
            <div class="kpi-icon" style="background:rgba(34,197,94,.1)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2f6f4e" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
            <div class="kpi-trend flat"><?= $present_today ?> / <?= $headcount ?></div>
          </div>
          <div class="kpi-val"><?= $attendance_pct ?>%</div>
          <div class="kpi-label">Present today</div>
        </div>
        <div class="kpi-card" style="--accent-bg: rgba(245,158,11,.1)">
          <div class="kpi-top">
            <div class="kpi-icon" style="background:rgba(245,158,11,.12)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#a6650f" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg></div>
            <div class="kpi-trend flat"><?= $pending_leave > 0 ? 'Needs review' : 'All clear' ?></div>
          </div>
          <div class="kpi-val"><?= $pending_leave ?></div>
          <div class="kpi-label">Pending leave requests</div>
        </div>
        <div class="kpi-card" style="--accent-bg: rgba(59,130,192,.1)">
          <div class="kpi-top">
            <div class="kpi-icon" style="background:rgba(59,130,192,.1)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#b8703f" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/></svg></div>
            <div class="kpi-trend flat"><?= $on_leave_today === 0 ? 'Full team' : 'Away' ?></div>
          </div>
          <div class="kpi-val"><?= $on_leave_today ?></div>
          <div class="kpi-label">On leave today</div>
        </div>
      </div>

      <div class="hrd-grid">
        <div class="col-left">

          <div class="panel">
            <div class="panel-head">
              <h2>Attendance — Last 7 Days</h2>
              <span class="tag">This week</span>
            </div>
            <div class="bars">
              <?php
                $max_c = max(1, max(array_column($attendance_week, 'count')));
                foreach ($attendance_week as $d => $info):
                  $pct = max(4, round(($info['count'] / max(1, $headcount)) * 100));
                  $is_today = $info['label'] === 'Today';
              ?>
                <div class="bar-col">
                  <div class="bar-fill<?= $is_today ? ' today' : '' ?>" style="height:<?= $pct ?>%" title="<?= $info['count'] ?> present"></div>
                  <div class="bar-day"><?= htmlspecialchars($info['label']) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="panel">
            <div class="panel-head">
              <h2>Staff Roster</h2>
              <a class="widget-action" href="../HR/Employee_Records_Page.php">View all →</a>
            </div>
            <table>
              <thead><tr><th>Employee</th><th>Role</th><th>Position</th><th>Status</th></tr></thead>
              <tbody>
                <?php if (empty($roster)): ?>
                  <tr><td colspan="4" class="empty-state">No staff records yet.</td></tr>
                <?php else: foreach ($roster as $r):
                  $es = $r['employment_status'] ?? 'active';
                  $pc = ['active'=>'pill-active','on_leave'=>'pill-onleave','terminated'=>'pill-terminated'][$es] ?? 'pill-active';
                  $name_parts = preg_split('/\s+/', trim($r['full_name']));
                  $initials = strtoupper((($name_parts[0][0] ?? '') . ($name_parts[1][0] ?? $name_parts[0][1] ?? '')));
                ?>
                <tr>
                  <td class="person">
                    <span class="init"><?= htmlspecialchars($initials) ?></span>
                    <div><div class="p-name"><?= htmlspecialchars($r['full_name']) ?></div><div class="p-sub"><?= role_label($r['role']) ?></div></div>
                  </td>
                  <td class="hrd-muted"><?= htmlspecialchars($r['department'] ?? '—') ?></td>
                  <td><?= $r['position'] ? htmlspecialchars($r['position']) : '<span class="hrd-muted">Not set</span>' ?></td>
                  <td><span class="status-pill <?= $pc ?>"><?= ucfirst(str_replace('_',' ',$es)) ?></span></td>
                </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
            <div class="table-foot">
              <span>Showing <?= count($roster) ?> of <?= $roster_total ?> employees</span>
              <a href="../HR/Employee_Records_Page.php" class="widget-action">Next →</a>
            </div>
          </div>

        </div>

        <div class="col-right">

          <div class="panel">
            <div class="panel-head"><h2>Present Today</h2></div>
            <?php
              $circumference = 2 * M_PI * 15.9;
              $dash = $headcount > 0 ? round(($present_today / $headcount) * $circumference, 1) : 0;
            ?>
            <div class="donut-wrap">
              <svg width="88" height="88" viewBox="0 0 42 42">
                <circle cx="21" cy="21" r="15.9" fill="none" stroke="#F4E9DD" stroke-width="5"/>
                <circle cx="21" cy="21" r="15.9" fill="none" stroke="#F79009" stroke-width="5"
                  stroke-dasharray="<?= $dash ?> <?= round($circumference,1) ?>" stroke-linecap="round" transform="rotate(-90 21 21)"/>
                <text x="21" y="24" text-anchor="middle" font-size="8" font-weight="700" fill="#101828"><?= $attendance_pct ?>%</text>
              </svg>
              <div class="donut-legend">
                <div class="leg-row"><span class="lk"><span class="leg-dot" style="background:#F79009"></span>Clocked in</span><b><?= $present_today ?></b></div>
                <div class="leg-row"><span class="lk"><span class="leg-dot" style="background:#F4E9DD"></span>Not yet</span><b><?= max(0, $headcount - $present_today) ?></b></div>
              </div>
            </div>
          </div>

          <div class="panel">
            <div class="panel-head">
              <h2>Pending Approvals</h2>
              <a class="widget-action" href="../HR/Leave_Management_Page.php">See all →</a>
            </div>
            <?php if (empty($pending_approvals)): ?>
              <div class="empty-state" style="padding:12px 0">No pending requests.</div>
            <?php else: foreach ($pending_approvals as $r):
              $name_parts = preg_split('/\s+/', trim($r['full_name']));
              $initials = strtoupper((($name_parts[0][0] ?? '') . ($name_parts[1][0] ?? $name_parts[0][1] ?? '')));
            ?>
              <div class="appr">
                <span class="init"><?= htmlspecialchars($initials) ?></span>
                <div><div class="p-name"><?= htmlspecialchars($r['full_name']) ?></div><div class="p-sub"><?= ucfirst($r['leave_type']) ?> · <?= date('M j', strtotime($r['date_from'])) ?>–<?= date('j', strtotime($r['date_to'])) ?></div></div>
                <?php if ($can_decide_leave): ?>
                <div class="appr-actions">
                  <form method="post" action="../HR/Leave_Management_Page.php" style="display:contents">
                    <input type="hidden" name="act" value="decide">
                    <input type="hidden" name="leave_id" value="<?= (int)$r['leave_id'] ?>">
                    <input type="hidden" name="decision" value="approved">
                    <button type="submit" class="abtn ok" title="Approve"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></button>
                  </form>
                  <form method="post" action="../HR/Leave_Management_Page.php" style="display:contents">
                    <input type="hidden" name="act" value="decide">
                    <input type="hidden" name="leave_id" value="<?= (int)$r['leave_id'] ?>">
                    <input type="hidden" name="decision" value="rejected">
                    <button type="submit" class="abtn no" title="Reject"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
                  </form>
                </div>
                <?php endif; ?>
              </div>
            <?php endforeach; endif; ?>
          </div>

          <div class="panel">
            <div class="panel-head"><h2>Quick Links</h2></div>
            <div class="quick-links">
              <a class="qlink" href="../HR/Leave_Management_Page.php"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>Review leave requests</a>
              <a class="qlink" href="../HR/Employee_Accounts_Page.php"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Add a new employee</a>
              <a class="qlink" href="../HR/Job_Postings_Page.php"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M20 6H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2Z"/></svg>Post a job opening</a>
            </div>
          </div>

        </div>
      </div>

    <?php else: ?>
      <!-- EMPLOYEE SELF-SERVICE SNAPSHOT -->
      <div class="kpi-grid">
        <div class="kpi-card" style="--accent-bg: rgba(34,197,94,.08)">
          <div class="kpi-val"><?= empty($today_att) ? '—' : (($today_att['time_in'] ?? null) ? 'Clocked In' : 'Not yet') ?></div>
          <div class="kpi-label">Today's Attendance</div>
        </div>
        <div class="kpi-card" style="--accent-bg: rgba(245,158,11,.1)">
          <div class="kpi-val"><?= $my_pending ?></div>
          <div class="kpi-label">Pending Leave Requests</div>
        </div>
        <div class="kpi-card" style="--accent-bg: rgba(59,130,192,.1)">
          <div class="kpi-val"><?= number_format((float)($me['vacation_leave_balance'] ?? 0), 1) ?></div>
          <div class="kpi-label">Vacation Leave Balance</div>
        </div>
        <div class="kpi-card" style="--accent-bg: rgba(181,113,61,.1)">
          <div class="kpi-val"><?= empty($last_payslip) ? '—' : '₱' . number_format($last_payslip['net_pay'], 2) ?></div>
          <div class="kpi-label">Last Payslip (Net)</div>
        </div>
      </div>

      <div class="widget">
        <div class="widget-title" style="margin-bottom:10px">Quick Actions</div>
        <div style="display:flex;gap:12px;flex-wrap:wrap">
          <a href="../HR/Attendance_Page.php" class="btn btn-primary" style="text-decoration:none">Clock In / Out</a>
          <a href="../HR/Leave_Management_Page.php" class="btn btn-ghost" style="text-decoration:none">Request Leave</a>
          <a href="../HR/Payroll_Page.php" class="btn btn-ghost" style="text-decoration:none">View Payslips</a>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('a.logout-btn, a[href="../auth/Logout_Page.php"], a[href$="/Logout_Page.php"]').forEach(function (link) {
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
<script src="../js/msg_banner_autodismiss.js"></script>
<script src="../js/theme-toggle.js"></script>
</body>
</html>