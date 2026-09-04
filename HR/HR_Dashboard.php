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

// Employee Performance rings are shown per employee, each scaled against a
// sensible cap so the ring fills proportionally rather than just showing a
// raw number. Adjust the caps to match realistic targets for your team.
function perf_ring_pct(string $key, float $value): int {
    if ($key === 'attendance') return (int)min(100, max(0, round($value)));
    $caps = ['overtime' => 40, 'tardiness' => 20, 'workhours' => 8];
    $cap = $caps[$key] ?? 100;
    return (int)min(100, max(0, round(($value / $cap) * 100)));
}
function perf_ring_display(string $key, float $value): string {
    return match ($key) {
        'attendance' => round($value) . '%',
        'tardiness'  => (string) round($value),
        default      => number_format($value, 1) . 'h',
    };
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

    // ── Recruitment funnel widgets ──────────────────────────────────────
    // NOTE: adjust table/column names below (job_postings / applicants)
    // to match your actual recruitment module schema. safe_query() logs
    // and returns false on a missing table, so these safely default to 0
    // until that module is wired in.
    $job_openings = (int)(safe_fetch(safe_query($conn,
        "SELECT COUNT(*) c FROM job_postings WHERE status = 'open'"))['c'] ?? 0);
    $job_openings_new = (int)(safe_fetch(safe_query($conn,
        "SELECT COUNT(*) c FROM job_postings WHERE status = 'open' AND created_at >= CURDATE() - INTERVAL 7 DAY"))['c'] ?? 0);

    $applicant_responses = (int)(safe_fetch(safe_query($conn,
        "SELECT COUNT(*) c FROM applicants WHERE created_at >= CURDATE() - INTERVAL 7 DAY"))['c'] ?? 0);

    $applicant_requests = (int)(safe_fetch(safe_query($conn,
        "SELECT COUNT(*) c FROM applicants WHERE status = 'pending'"))['c'] ?? 0);

    $applicant_accepted = (int)(safe_fetch(safe_query($conn,
        "SELECT COUNT(*) c FROM applicants
         WHERE status = 'accepted' AND MONTH(updated_at) = MONTH(CURDATE()) AND YEAR(updated_at) = YEAR(CURDATE())"))['c'] ?? 0);

    // ── Per-employee performance metrics (Attendance / Overtime / Tardiness / Work Hours) ──
    // Powers the "circle type" rings shown next to each employee in the
    // directory — one set of 4 rings per person, not a company-wide average.
    // NOTE: assumes `overtime_hours` and `scheduled_time_in` columns on
    // `attendance` — adjust to match your schema.
    $perf_by_employee = [];
    $roster_ids = array_map('intval', array_column($roster, 'user_id'));
    if (!empty($roster_ids)) {
        $id_list = implode(',', $roster_ids);
        $pf = safe_query($conn,
            "SELECT employee_id,
                ROUND(SUM(CASE WHEN time_in IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*) * 100) attendance_pct,
                ROUND(SUM(CASE WHEN work_date >= CURDATE() - INTERVAL 6 DAY THEN COALESCE(overtime_hours,0) ELSE 0 END), 1) overtime_hours,
                SUM(CASE WHEN scheduled_time_in IS NOT NULL AND time_in > scheduled_time_in THEN 1 ELSE 0 END) tardiness_count,
                ROUND(AVG(CASE WHEN work_date >= CURDATE() - INTERVAL 6 DAY AND time_out IS NOT NULL THEN TIMESTAMPDIFF(HOUR, time_in, time_out) END), 1) workhours_avg
             FROM attendance
             WHERE employee_id IN ($id_list) AND work_date BETWEEN CURDATE() - INTERVAL 29 DAY AND CURDATE()
             GROUP BY employee_id");
        if ($pf) while ($r = mysqli_fetch_assoc($pf)) {
            $perf_by_employee[(int)$r['employee_id']] = [
                'attendance' => (float)($r['attendance_pct'] ?? 0),
                'overtime'   => (float)($r['overtime_hours'] ?? 0),
                'tardiness'  => (float)($r['tardiness_count'] ?? 0),
                'workhours'  => (float)($r['workhours_avg'] ?? 0),
            ];
        }

        // Who's clocked in today, for the "On shift / Off today" status
        // shown next to each person in the Employee Performance list.
        $present_today_ids = [];
        $pt = safe_query($conn,
            "SELECT employee_id FROM attendance
             WHERE work_date = CURDATE() AND time_in IS NOT NULL AND employee_id IN ($id_list)");
        if ($pt) while ($r = mysqli_fetch_assoc($pt)) $present_today_ids[(int)$r['employee_id']] = true;
    }

    // Team-wide performance averages/totals for the Employee Performance
    // overview rings (separate from each person's own numbers above).
    $team_perf_count = max(1, count($perf_by_employee));
    $team_attendance_avg = $team_perf_count > 0
        ? round(array_sum(array_column($perf_by_employee, 'attendance')) / $team_perf_count) : 0;
    $team_overtime_total  = round(array_sum(array_column($perf_by_employee, 'overtime')), 1);
    $team_tardiness_total = (int)array_sum(array_column($perf_by_employee, 'tardiness'));
    $team_workhours_total = round(array_sum(array_column($perf_by_employee, 'workhours')), 1);

    $ring_defs = [
        ['key' => 'attendance', 'label' => 'Attendance', 'color' => 'var(--success)', 'track' => 'rgba(34,197,94,.15)'],
        ['key' => 'overtime',   'label' => 'Overtime',    'color' => 'var(--caramel)', 'track' => 'rgba(181,113,61,.15)'],
        ['key' => 'tardiness',  'label' => 'Tardiness',   'color' => 'var(--danger)',  'track' => 'rgba(239,68,68,.15)'],
        ['key' => 'workhours',  'label' => 'Work Hours',  'color' => '#3b7fc0',        'track' => 'rgba(59,130,192,.15)'],
    ];
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
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Source+Serif+4:opsz,wght@8..60,500;8..60,600;8..60,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/hr_module.css"/>
  <link rel="stylesheet" href="../css/hr_dashboard.css"/>
</head>
<body>

<?php require_once '../HR/Sidebar_HR.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
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

      <div class="hrd-v4">

      <div class="page-header">
        <div>
          <h1>HR Admin Dashboard Overview</h1>
          <p>Recruitment pipeline and workforce performance for <?= date('F j, Y') ?>.</p>
        </div>
        <a href="../HR/Employee_Accounts_Page.php" class="btn-v4-primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>Add employee</a>
      </div>

      <!-- KPI BLOCK -->
      <div class="kpi-block">

        <!-- HERO: workforce headline -->
        <div class="hero-stat">
          <div class="top-row">
            <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="9" cy="8" r="3"/><path d="M3.5 20c0-3.6 2.6-6 5.5-6s5.5 2.4 5.5 6"/><path d="M16 8.2a3 3 0 1 1 3.3 5"/><path d="M15 14.3c2.7.3 4.5 2.6 4.5 5.7"/></svg></div>
            <span class="delta"><?= $attendance_pct ?>% present today</span>
          </div>
          <div>
            <div class="num"><?= $headcount ?></div>
            <div class="label">Active staff across all shifts</div>
          </div>
          <div class="hero-substats">
            <div class="hero-sub">
              <div class="hs-val"><?= $on_leave_today ?></div>
              <div class="hs-label">On leave today</div>
            </div>
            <div class="hero-sub">
              <div class="hs-val<?= $pending_leave > 0 ? ' notice' : '' ?>"><?= $pending_leave ?></div>
              <div class="hs-label">Pending leave</div>
            </div>
          </div>
        </div>

        <!-- GROUPED RECRUITMENT SNAPSHOT -->
        <div class="kpi-strip">
          <div class="strip-head">
            <span class="title">Recruitment snapshot</span>
            <a href="../HR/Job_Postings_Page.php">View job postings →</a>
          </div>
          <div class="kpi-cells">
            <div class="kpi-cell" style="--kpi-color:#628E90; --kpi-dim:#E1EBEB;">
              <div class="top-row">
                <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="3.5" y="7" width="17" height="12.5" rx="1.6"/><path d="M8.5 7V5.3a1.7 1.7 0 0 1 1.7-1.7h3.6a1.7 1.7 0 0 1 1.7 1.7V7"/></svg></div>
                <span class="tag"><?= $job_openings_new > 0 ? $job_openings_new . ' new' : 'Open' ?></span>
              </div>
              <div class="num"><?= $job_openings ?></div>
              <div class="label">Job openings</div>
            </div>
            <div class="kpi-cell" style="--kpi-color:#7CA3C4; --kpi-dim:#EAF2FA;">
              <div class="top-row">
                <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="3.5" y="5" width="17" height="14" rx="1.6"/><path d="M4 6.5l8 6 8-6"/></svg></div>
                <span class="tag"><?= $applicant_requests > 0 ? 'Needs review' : 'All clear' ?></span>
              </div>
              <div class="num"><?= $applicant_requests ?></div>
              <div class="label">Applicant requests</div>
            </div>
            <div class="kpi-cell" style="--kpi-color:#628E90; --kpi-dim:#E1EBEB;">
              <div class="top-row">
                <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="8.5"/><path d="M8.5 12.3l2.2 2.2 4.8-5"/></svg></div>
                <span class="tag">This month</span>
              </div>
              <div class="num"><?= $applicant_accepted ?></div>
              <div class="label">Applicants accepted</div>
            </div>
            <div class="kpi-cell" style="--kpi-color:#A9805F; --kpi-dim:#F1E7DC;">
              <div class="top-row">
                <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M4 5.5h16v10.5H9.5L5 20v-4H4z"/></svg></div>
                <span class="tag">This week</span>
              </div>
              <div class="num"><?= $applicant_responses ?></div>
              <div class="label">Applicant responses</div>
            </div>
          </div>
        </div>

      </div>

      <!-- GRID -->
      <div class="grid">

        <!-- LEFT COLUMN -->
        <div>

          <div class="panel">
            <div class="panel-head">
              <h2>Recruitment pipeline</h2>
              <a class="link" href="../HR/Job_Postings_Page.php">View job postings →</a>
            </div>
            <div class="panel-body">
              <div class="stepper">
                <div class="step<?= $job_openings > 0 ? ' filled' : '' ?>">
                  <div class="step-dot">1</div>
                  <div class="step-num"><?= $job_openings ?></div>
                  <div class="step-label">Job openings</div>
                  <div class="step-sub"><?= $job_openings_new > 0 ? $job_openings_new . ' new this week' : 'No new postings' ?></div>
                </div>
                <div class="step<?= $applicant_requests > 0 ? ' filled' : '' ?>">
                  <div class="connector"></div>
                  <div class="step-dot">2</div>
                  <div class="step-num"><?= $applicant_requests ?></div>
                  <div class="step-label">Applicant requests</div>
                  <div class="step-sub"><?= $applicant_requests > 0 ? 'Needs review' : 'Awaiting applicants' ?></div>
                </div>
                <div class="step<?= $applicant_accepted > 0 ? ' filled' : '' ?>">
                  <div class="connector"></div>
                  <div class="step-dot">3</div>
                  <div class="step-num"><?= $applicant_accepted ?></div>
                  <div class="step-label">Accepted</div>
                  <div class="step-sub">Offers this month</div>
                </div>
                <div class="step<?= $applicant_responses > 0 ? ' filled' : '' ?>">
                  <div class="connector"></div>
                  <div class="step-dot">4</div>
                  <div class="step-num"><?= $applicant_responses ?></div>
                  <div class="step-label">Responses</div>
                  <div class="step-sub">Candidate replies</div>
                </div>
              </div>
            </div>
          </div>

          <div class="panel">
            <div class="panel-head">
              <h2>Attendance — last 7 days</h2>
              <span class="tag">This week</span>
            </div>
            <div class="panel-body">
              <div class="week-chart">
                <?php foreach ($attendance_week as $d => $info):
                  $pct = max(4, round(($info['count'] / max(1, $headcount)) * 100));
                  $is_today = $info['label'] === 'Today';
                ?>
                  <div class="wc-col">
                    <div class="wc-bar<?= $is_today ? ' today' : '' ?>" style="height:<?= $pct ?>%" title="<?= $info['count'] ?> present"></div>
                    <div class="wc-day<?= $is_today ? ' today' : '' ?>"><?= htmlspecialchars($info['label']) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if (array_sum(array_column($attendance_week, 'count')) === 0): ?>
                <div class="empty-state-v4">No clock-ins recorded yet this week</div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Kept in this column, side-by-side, so they flow right under
               Attendance instead of leaving a gap while the right column
               (Employee performance) is still rendering. -->
          <div class="subgrid">

            <div class="panel">
              <div class="panel-head">
                <h2>Pending Approvals</h2>
                <a class="link" href="../HR/Leave_Management_Page.php">See all →</a>
              </div>
              <?php if (empty($pending_approvals)): ?>
                <div class="empty-state-v4" style="padding:16px 0">No pending requests.</div>
              <?php else: foreach ($pending_approvals as $r):
                $name_parts = preg_split('/\s+/', trim($r['full_name']));
                $initials = strtoupper((($name_parts[0][0] ?? '') . ($name_parts[1][0] ?? $name_parts[0][1] ?? '')));
              ?>
                <div class="appr">
                  <div class="emp-avatar" style="background:#628E90;width:30px;height:30px;font-size:11px;"><?= htmlspecialchars($initials) ?></div>
                  <div><div class="p-name"><?= htmlspecialchars($r['full_name']) ?></div><div class="p-sub"><?= ucfirst($r['leave_type']) ?> · <?= date('M j', strtotime($r['date_from'])) ?>–<?= date('j', strtotime($r['date_to'])) ?></div></div>
                  <?php if ($can_decide_leave): ?>
                  <div class="appr-actions">
                    <form method="post" action="../HR/Leave_Management_Page.php" style="display:contents">
                      <input type="hidden" name="act" value="decide">
                      <input type="hidden" name="leave_id" value="<?= (int)$r['leave_id'] ?>">
                      <input type="hidden" name="decision" value="approved">
                      <button type="submit" class="abtn-v4 ok" title="Approve"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#628E90" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></button>
                    </form>
                    <form method="post" action="../HR/Leave_Management_Page.php" style="display:contents">
                      <input type="hidden" name="act" value="decide">
                      <input type="hidden" name="leave_id" value="<?= (int)$r['leave_id'] ?>">
                      <input type="hidden" name="decision" value="rejected">
                      <button type="submit" class="abtn-v4 no" title="Reject"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#B4553C" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
                    </form>
                  </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; endif; ?>
            </div>

            <div class="panel">
              <div class="panel-head"><h2>Quick Links</h2></div>
              <div class="quick-links">
                <a class="qlink-v4" href="../HR/Leave_Management_Page.php"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>Review leave requests</a>
                <a class="qlink-v4" href="../HR/Employee_Accounts_Page.php"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Add a new employee</a>
                <a class="qlink-v4" href="../HR/Job_Postings_Page.php"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M20 6H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2Z"/></svg>Post a job opening</a>
              </div>
            </div>

          </div>

        </div>

        <!-- RIGHT COLUMN — EMPLOYEE PERFORMANCE -->
        <div>

          <div class="panel">
            <div class="panel-head">
              <h2>Employee performance</h2>

              <a class="link" href="../HR/Employee_Records_Page.php">View all →</a>
            </div>

            <?php
              $avatar_colors = ['#628E90', '#7CA3C4', '#A9805F', '#4A2A1C'];
              $team_gauges = [
                ['label' => 'Attendance', 'sub' => 'Team average',       'value' => $team_attendance_avg . '%', 'pct' => $team_attendance_avg / 100,                                       'color' => '#628E90'],
                ['label' => 'Overtime',   'sub' => 'Total this month',   'value' => $team_overtime_total . 'h', 'pct' => $team_overtime_total / max(1, $headcount * 10),                    'color' => '#7CA3C4'],
                ['label' => 'Tardiness',  'sub' => 'Incidents (30d)',    'value' => $team_tardiness_total,      'pct' => 1 - (min($team_tardiness_total, max(5, $headcount * 2)) / max(5, $headcount * 2)), 'color' => '#4A2A1C'],
                ['label' => 'Work hours', 'sub' => 'Total logged',       'value' => $team_workhours_total . 'h','pct' => $team_workhours_total / max(1, $headcount * 45),                   'color' => '#A9805F'],
              ];
            ?>
            <?php if (!empty($roster)): ?>
            <div class="overall-gauges">
              <?php foreach ($team_gauges as $g):
                $gpct = max(0, min(1, (float)$g['pct']));
                $r_ = 35.2; $circ = 2 * M_PI * $r_;
                $dash = round($gpct * $circ, 1);
              ?>
                <div class="overall-gauge">
                  <div class="ring-wrap">
                    <svg width="84" height="84" viewBox="0 0 84 84">
                      <circle cx="42" cy="42" r="<?= $r_ ?>" fill="none" stroke="#EFE6DC" stroke-width="7"/>
                      <circle cx="42" cy="42" r="<?= $r_ ?>" fill="none" stroke="<?= $g['color'] ?>" stroke-width="7"
                        stroke-linecap="round" stroke-dasharray="<?= $dash ?> <?= round($circ, 1) ?>" transform="rotate(-90 42 42)"/>
                    </svg>
                    <div class="ring-val"><?= htmlspecialchars((string)$g['value']) ?></div>
                  </div>
                  <div class="ring-label"><?= htmlspecialchars($g['label']) ?></div>
                  <div class="ring-sub"><?= htmlspecialchars($g['sub']) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="profile-list">
              <?php if (empty($roster)): ?>
                <div class="empty-state-v4">No staff records yet.</div>
              <?php else: foreach ($roster as $i => $r):
                $es = $r['employment_status'] ?? 'active';
                $name_parts = preg_split('/\s+/', trim($r['full_name']));
                $initials = strtoupper((($name_parts[0][0] ?? '') . ($name_parts[1][0] ?? $name_parts[0][1] ?? '')));
                $eid = (int)$r['user_id'];
                $avatar_color = $avatar_colors[$i % count($avatar_colors)];
                if ($es === 'on_leave') { $status_class = 'onleave'; $status_text = 'On leave'; }
                elseif (!empty($present_today_ids[$eid])) { $status_class = 'on'; $status_text = 'On shift'; }
                else { $status_class = 'off'; $status_text = 'Off today'; }
              ?>
                <div class="profile-row">
                  <div class="emp-avatar" style="background:<?= $avatar_color ?>"><?= htmlspecialchars($initials) ?></div>
                  <div class="emp-info">
                    <div class="emp-name"><?= htmlspecialchars($r['full_name']) ?> <span class="status-dot <?= $status_class ?>"></span></div>
                    <div class="emp-role"><?= $r['position'] ? htmlspecialchars($r['position']) : 'Not set' ?> · <?= htmlspecialchars($r['department'] ?? '—') ?></div>
                  </div>
                  <span class="status-text <?= $status_class ?>"><?= $status_text ?></span>
                </div>
              <?php endforeach; endif; ?>
            </div>
            <div class="footnote">Showing <?= count($roster) ?> of <?= $roster_total ?> employees</div>
          </div>

        </div>
      </div>

      </div><!-- /.hrd-v4 -->

    <?php else: ?>
      <!-- EMPLOYEE SELF-SERVICE SNAPSHOT -->
      <div class="hrd-v4">
      <div class="kpi-grid-v4">
        <div class="kpi-card-v4">
          <div class="num"><?= empty($today_att) ? '—' : (($today_att['time_in'] ?? null) ? 'Clocked In' : 'Not yet') ?></div>
          <div class="label">Today's Attendance</div>
        </div>
        <div class="kpi-card-v4">
          <div class="num"><?= $my_pending ?></div>
          <div class="label">Pending Leave Requests</div>
        </div>
        <div class="kpi-card-v4">
          <div class="num"><?= number_format((float)($me['vacation_leave_balance'] ?? 0), 1) ?></div>
          <div class="label">Vacation Leave Balance</div>
        </div>
        <div class="kpi-card-v4">
          <div class="num"><?= empty($last_payslip) ? '—' : '₱' . number_format($last_payslip['net_pay'], 2) ?></div>
          <div class="label">Last Payslip (Net)</div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h2>Quick Actions</h2></div>
        <div class="panel-body">
          <div class="quick-actions-v4">
            <a href="../HR/Attendance_Page.php" class="btn-v4-primary">Clock In / Out</a>
            <a href="../HR/Leave_Management_Page.php" class="btn-v4-ghost">Request Leave</a>
            <a href="../HR/Payroll_Page.php" class="btn-v4-ghost">View Payslips</a>
          </div>
        </div>
      </div>
      </div><!-- /.hrd-v4 -->
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