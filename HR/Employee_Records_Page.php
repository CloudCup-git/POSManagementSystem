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
    "SELECT u.user_id, u.full_name, u.role, u.is_active, u.profile_photo, e.*
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

// ── Attendance snapshot for the profile popup ─────────────────────
// Pulled once for every employee in the current view (last 30 days) so
// clicking a row can show a quick attendance ring + recent history
// without a separate page load or AJAX round-trip.
$att_summary = []; // employee_id => ['present'=>,'late'=>,'absent'=>,'on_leave'=>,'hours'=>,'rate'=>]
$att_recent  = []; // employee_id => up to 8 most-recent attendance rows
$display_ids = array_map(function ($r) { return (int)$r['user_id']; }, $display);
if (!empty($display_ids)) {
    $id_list = implode(',', $display_ids);
    $ares = mysqli_query($conn,
        "SELECT employee_id, work_date, time_in, time_out, hours_worked, status
         FROM attendance
         WHERE employee_id IN ($id_list) AND work_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
         ORDER BY work_date DESC");
    if ($ares) {
        while ($a = mysqli_fetch_assoc($ares)) {
            $eid = (int)$a['employee_id'];
            if (!isset($att_summary[$eid])) {
                $att_summary[$eid] = ['present' => 0, 'late' => 0, 'absent' => 0, 'on_leave' => 0, 'hours' => 0.0];
            }
            if (isset($att_summary[$eid][$a['status']])) $att_summary[$eid][$a['status']]++;
            $att_summary[$eid]['hours'] += (float)($a['hours_worked'] ?? 0);
            if (!isset($att_recent[$eid])) $att_recent[$eid] = [];
            if (count($att_recent[$eid]) < 8) $att_recent[$eid][] = $a;
        }
    }
    // Attendance rate = present+late out of every day actually marked
    // (present/late/absent) — on-leave days aren't counted against it.
    foreach ($att_summary as $eid => &$s) {
        $marked = $s['present'] + $s['late'] + $s['absent'];
        $s['rate'] = $marked > 0 ? (int)round((($s['present'] + $s['late']) / $marked) * 100) : null;
    }
    unset($s);
}

// ── Presentation helpers for the redesigned roster list ──────────
// Initials + a deterministic accent color per person (seeded off their
// department so colleagues in the same department read as a group),
// used for the avatar chip in place of a plain table row.
function er_initials(string $name): string {
    $parts = array_values(array_filter(preg_split('/\s+/', trim($name))));
    if (empty($parts)) return '?';
    $first = mb_substr($parts[0], 0, 1);
    $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}
$er_palette = ['#7A3B8F', '#2f6690', '#3F8F5F', '#b8703f', '#8a3428', '#3a2d1e', '#5F5E5A'];
function er_avatar_color(array $palette, string $seed): string {
    if ($seed === '') $seed = 'staff';
    return $palette[crc32($seed) % count($palette)];
}
// Short "2y 3m" / "5 mos" / "12 days" tenure label, same convention used
// on the employee's own My Account page.
function er_tenure(?string $date_hired): string {
    if (empty($date_hired)) return '—';
    $hired  = new DateTime($date_hired);
    $diff   = $hired->diff(new DateTime('today'));
    $months = ($diff->y * 12) + $diff->m;
    if ($diff->y >= 1)   return $diff->y . 'y ' . $diff->m . 'm';
    if ($months >= 1)    return $months . ' mo' . ($months === 1 ? '' : 's');
    return $diff->d . ' day' . ($diff->d === 1 ? '' : 's');
}

// Average tenure across active staff, for the KPI strip — gives HR a
// sense of overall team seniority at a glance.
$tenure_months_sum = 0; $tenure_n = 0;
foreach ($records as $r) {
    if (($r['employment_status'] ?? 'active') === 'terminated' || empty($r['date_hired'])) continue;
    $d = (new DateTime($r['date_hired']))->diff(new DateTime('today'));
    $tenure_months_sum += ($d->y * 12) + $d->m;
    $tenure_n++;
}
$avg_tenure_label = '—';
if ($tenure_n > 0) {
    $avg_months = round($tenure_months_sum / $tenure_n);
    $avg_tenure_label = $avg_months >= 12 ? round($avg_months / 12, 1) . ' yrs' : $avg_months . ' mos';
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
  <title>Employee Records — Cloud Cup HR</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/hr_module.css"/>
  <style>
    /* ── Employee Records — avatar-based roster list, replacing the
       plain table with a more scannable, HR-system-style layout.
       Scoped under .er- so nothing here touches shared admin_page.css /
       hr_module.css rules other pages rely on. ──── */

    .er-kpi-grid {
      display:grid; grid-template-columns:repeat(4, 1fr); gap:12px; margin-bottom:20px;
    }
    .er-kpi-card {
      display:flex; align-items:center; gap:12px;
      background: var(--white); border:1px solid var(--cream); border-radius:12px; padding:16px 18px;
      box-shadow: 0 2px 10px rgba(11,30,51,0.05);
    }
    .er-kpi-icon {
      width:38px; height:38px; border-radius:10px; flex-shrink:0;
      display:flex; align-items:center; justify-content:center;
      background: var(--kpi-dim, #eef2f5); color: var(--kpi-color, var(--brown-mid,#2a2016));
    }
    .er-kpi-icon svg { width:18px; height:18px; }
    .er-kpi-num { font-family:'Fraunces', serif; font-size:22px; font-weight:700; color:var(--text); line-height:1.1; }
    .er-kpi-label { font-size:11.5px; color:var(--text-light); margin-top:2px; }
    @media (max-width: 900px) {
      .er-kpi-grid { grid-template-columns:repeat(2, 1fr); }
    }

    .er-toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:14px 0 18px; }
    .er-search-wrap {
      flex:1; min-width:220px; display:flex; align-items:center; gap:8px;
      background: var(--white); border:1px solid var(--cream); border-radius:8px; padding:0 12px;
    }
    .er-search-wrap input { border:none; outline:none; padding:9px 0; font-size:13px; font-family:inherit; width:100%; background:transparent; }
    .er-search-wrap svg { flex-shrink:0; color:var(--text-light); width:15px; height:15px; }
    .er-count-note { font-size:12px; color:var(--text-light); white-space:nowrap; }

    .er-list-head {
      display:flex; align-items:center; gap:14px; padding:0 16px 8px;
      font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--text-light);
    }
    .er-list { display:flex; flex-direction:column; gap:8px; }
    .er-row {
      display:flex; align-items:center; gap:14px;
      background: var(--white); border:1px solid var(--cream); border-radius:12px; padding:12px 16px;
      transition: box-shadow .15s, border-color .15s;
      cursor:pointer;
    }
    .er-row:hover { box-shadow: 0 3px 14px rgba(11,30,51,0.07); border-color: rgba(200,147,90,.35); }
    .er-row:hover .er-chevron { color: var(--brown-mid,#2a2016); transform: translateX(2px); }
    .er-row:focus-visible { outline:2px solid #2f6690; outline-offset:2px; }

    .er-avatar {
      width:38px; height:38px; border-radius:50%; flex-shrink:0; overflow:hidden;
      display:flex; align-items:center; justify-content:center;
      font-family:'Fraunces', serif; font-size:13px; font-weight:700; color:#fff;
    }
    .er-avatar img { width:100%; height:100%; object-fit:cover; border-radius:50%; display:block; }

    .er-identity { flex:1.6; min-width:0; }
    .er-name-line { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .er-name { font-size:13.5px; font-weight:600; color:var(--text); }
    .er-role-tag {
      font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.02em;
      padding:2px 7px; border-radius:999px; background:rgba(59,130,192,.1); color:var(--text-light);
    }
    .er-meta { font-size:12px; color:var(--text-light); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .er-meta-term { color:#8a3428; }
    .er-meta-term svg { width:11px; height:11px; vertical-align:-1.5px; margin-right:3px; }

    .er-col-type { flex:0.8; min-width:78px; }
    .er-type-badge {
      display:inline-flex; font-size:11px; font-weight:600; padding:4px 10px; border-radius:999px;
      background: var(--cream-light,#faf8f4); color: var(--brown-mid,#2a2016); border:1px solid var(--cream);
    }

    .er-col-status { flex:0.9; min-width:96px; }
    .er-col-tenure { flex:0.8; min-width:70px; font-size:12.5px; color:var(--text-light); }

    .er-chevron {
      flex-shrink:0; width:18px; height:18px; color:var(--text-light);
      display:flex; align-items:center; justify-content:center; transition: transform .15s, color .15s;
    }
    .er-chevron svg { width:100%; height:100%; }

    .er-empty { display:flex; flex-direction:column; align-items:center; gap:8px; padding:48px 12px; color:var(--text-light); text-align:center; }
    .er-empty svg { width:34px; height:34px; opacity:.5; }

    @media (max-width: 900px) {
      .er-col-tenure, .er-col-type { display:none; }
      .er-list-head { display:none; }
    }

    /* ── Smooth open/close animation for the profile + edit popups ──
       Scoped to these two modals by ID (rather than touching the
       shared .modal-overlay-admin class) so nothing else in the app
       is affected. Keeps the overlay in the layout at all times and
       fades/scales it via opacity + transform instead of relying on
       an abrupt display:none → flex swap. */
    #profileModal, #editModal {
      display: flex !important;
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
      transition: opacity .22s ease, visibility 0s linear .22s;
    }
    #profileModal.open, #editModal.open {
      opacity: 1;
      visibility: visible;
      pointer-events: auto;
      transition: opacity .22s ease, visibility 0s linear 0s;
    }
    #profileModal .modal-admin-box, #editModal .modal-admin-box {
      transform: translateY(16px) scale(.96);
      opacity: 0;
      transition: transform .28s cubic-bezier(.16,1,.3,1), opacity .22s ease;
    }
    #profileModal.open .modal-admin-box, #editModal.open .modal-admin-box {
      transform: translateY(0) scale(1);
      opacity: 1;
    }
    @media (prefers-reduced-motion: reduce) {
      #profileModal, #editModal,
      #profileModal .modal-admin-box, #editModal .modal-admin-box {
        transition: opacity .12s ease !important;
        transform: none !important;
      }
    }

    /* ── Employee profile popup ──────────────────────────────────
       Opens when a roster row is clicked: avatar + attendance ring
       up top, quick stat chips, a details grid, then recent history. */
    .pm-modal-box { max-width:700px; }
    .pm-body { max-height:66vh; overflow-y:auto; padding-right:4px; margin-right:-4px; }

    .pm-identity {
      display:flex; align-items:flex-start; gap:16px;
      padding-bottom:18px; margin-bottom:18px; border-bottom:1px solid var(--cream);
    }
    .pm-avatar {
      width:72px; height:72px; border-radius:50%; flex-shrink:0; overflow:hidden;
      display:flex; align-items:center; justify-content:center;
      font-family:'Fraunces', serif; font-size:24px; font-weight:700; color:#fff;
      box-shadow: 0 3px 12px rgba(11,30,51,.14);
    }
    .pm-avatar img { width:100%; height:100%; object-fit:cover; border-radius:50%; display:block; }
    .pm-identity-info { flex:1; min-width:0; }
    .pm-name-line { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .pm-name { font-family:'Fraunces', serif; font-size:19px; font-weight:700; color:var(--text); }
    .pm-position { font-size:13px; color:var(--text-light); margin-top:3px; }
    .pm-badges { display:flex; gap:6px; flex-wrap:wrap; margin-top:9px; }
    .pm-badges .status-pill, .pm-badges .er-type-badge { font-size:10.5px; }

    .pm-ring-wrap { flex-shrink:0; }
    .pm-ring {
      --pct:0; --ring-color:#2f7a4f;
      width:86px; height:86px; border-radius:50%;
      background: conic-gradient(var(--ring-color) calc(var(--pct) * 1%), var(--cream, #ece4d6) 0);
      display:flex; align-items:center; justify-content:center;
      transition: background .3s;
    }
    .pm-ring-inner {
      width:66px; height:66px; border-radius:50%; background:var(--white);
      display:flex; flex-direction:column; align-items:center; justify-content:center;
    }
    .pm-ring-pct { font-family:'IBM Plex Mono', monospace; font-size:15px; font-weight:700; color:var(--text); line-height:1; }
    .pm-ring-lbl { font-size:8.5px; color:var(--text-light); text-transform:uppercase; letter-spacing:.03em; margin-top:3px; }

    .pm-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; margin-bottom:22px; }
    .pm-stat {
      background: var(--cream-light,#faf8f4); border:1px solid var(--cream); border-radius:10px;
      padding:10px 6px; text-align:center;
    }
    .pm-stat .num { font-family:'IBM Plex Mono', monospace; font-size:16px; font-weight:700; color:var(--text); line-height:1.1; }
    .pm-stat .num.warn { color:#b8703f; }
    .pm-stat .num.bad { color:#8a3428; }
    .pm-stat .num.info { color:#2c5f86; }
    .pm-stat .lbl { font-size:9.5px; color:var(--text-light); margin-top:4px; text-transform:uppercase; letter-spacing:.02em; }

    .pm-section-label {
      font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--text-light);
      margin:0 0 10px; display:flex; align-items:baseline; gap:6px;
    }
    .pm-section-label .sub { text-transform:none; font-weight:500; letter-spacing:0; font-size:10.5px; color:var(--text-light); }

    .pm-details-grid { display:grid; grid-template-columns:1fr 1fr; gap:13px 22px; margin-bottom:22px; }
    .pm-detail-item { min-width:0; }
    .pm-detail-item .k { font-size:10.5px; color:var(--text-light); text-transform:uppercase; letter-spacing:.03em; margin-bottom:3px; }
    .pm-detail-item .v { font-size:13.5px; color:var(--text); font-weight:500; word-break:break-word; }
    .pm-detail-item.full { grid-column:1 / -1; }
    .pm-detail-item.pm-term .v { color:#8a3428; }

    .pm-recent-list { display:flex; flex-direction:column; gap:6px; }
    .pm-recent-row {
      display:flex; align-items:center; gap:10px; padding:8px 10px;
      border:1px solid var(--cream); border-radius:8px; font-size:12.5px;
    }
    .pm-recent-date { flex:0 0 76px; font-weight:600; color:var(--text); }
    .pm-recent-time { flex:1; min-width:0; color:var(--text-light); font-family:'IBM Plex Mono', monospace; font-size:11.5px; }
    .pm-recent-hours { flex:0 0 52px; text-align:right; font-family:'IBM Plex Mono', monospace; color:var(--text); }
    .pm-recent-row .status-pill { flex-shrink:0; font-size:10px; padding:2px 8px; }
    .pm-empty-note { font-size:12.5px; color:var(--text-light); padding:16px 4px; text-align:center; }

    .pm-actions { display:flex; justify-content:flex-end; gap:10px; padding-top:16px; margin-top:4px; border-top:1px solid var(--cream); }

    @media (max-width:640px) {
      .pm-identity { flex-wrap:wrap; }
      .pm-ring-wrap { order:3; width:100%; display:flex; justify-content:center; margin-top:4px; }
      .pm-stats { grid-template-columns:repeat(2,1fr); }
      .pm-details-grid { grid-template-columns:1fr; }
      .pm-recent-time { display:none; }
    }

    /* ── Sectioned edit modal ── */
    .er-modal-box { max-width:620px; }
    .er-section-label {
      font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--text-light);
      margin:20px 0 10px; display:flex; align-items:center; gap:6px;
    }
    .er-section-label:first-of-type { margin-top:2px; }
    .er-section-label svg { width:13px; height:13px; }
    .er-divider { border:none; border-top:1px solid var(--cream); margin:18px 0 0; }
  </style>
</head>
<body>

<?php require_once '../HR/Sidebar_HR.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
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

    <!-- ── KPI strip — headcount at a glance ── -->
    <div class="er-kpi-grid">
      <div class="er-kpi-card">
        <div class="er-kpi-icon" style="--kpi-color:#7A3B8F; --kpi-dim:#F1E7F5;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div>
          <div class="er-kpi-num"><?= $counts['all'] ?></div>
          <div class="er-kpi-label">Total Staff</div>
        </div>
      </div>
      <div class="er-kpi-card">
        <div class="er-kpi-icon" style="--kpi-color:#2f7a4f; --kpi-dim:#E4F3EA;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div>
          <div class="er-kpi-num"><?= $counts['active'] ?></div>
          <div class="er-kpi-label">Active</div>
        </div>
      </div>
      <div class="er-kpi-card">
        <div class="er-kpi-icon" style="--kpi-color:#b8703f; --kpi-dim:#F5EBE1;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        </div>
        <div>
          <div class="er-kpi-num"><?= $counts['on_leave'] ?></div>
          <div class="er-kpi-label">On Leave</div>
        </div>
      </div>
      <div class="er-kpi-card">
        <div class="er-kpi-icon" style="--kpi-color:#2f6690; --kpi-dim:#E6EEF3;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div>
          <div class="er-kpi-num"><?= $avg_tenure_label ?></div>
          <div class="er-kpi-label">Avg. Tenure (active)</div>
        </div>
      </div>
    </div>

    <div class="filter-tabs">
      <?php foreach ($tab_labels as $key => $label): ?>
        <a class="filter-tab<?= $view === $key ? ' active' : '' ?>" href="?view=<?= $key ?>">
          <?= $label ?> <span class="count"><?= $counts[$key] ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="widget">
      <div class="widget-header">
        <div>
          <div class="widget-title">Staff Profiles — <?= $tab_labels[$view] ?></div>
          <div style="font-size:12px;color:var(--hr-text-light);margin-top:2px">New accounts are created from Accounts &amp; Roles</div>
        </div>
      </div>

      <div class="er-toolbar">
        <div class="er-search-wrap">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" id="erSearch" placeholder="Search by name, position, or department…">
        </div>
        <div class="er-count-note" id="erCountNote"><?= count($display) ?> shown</div>
      </div>

      <?php if (!empty($display)): ?>
      <div class="er-list-head">
        <div style="width:38px"></div>
        <div class="er-identity">Employee</div>
        <div class="er-col-type">Type</div>
        <div class="er-col-status">Status</div>
        <div class="er-col-tenure">Tenure</div>
      </div>
      <?php endif; ?>

      <div class="er-list" id="erList">
        <?php if (empty($display)): ?>
          <div class="er-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <div><?= empty($records) ? 'No employee records yet. Create accounts first in Accounts &amp; Roles.' : 'No employees in this view.' ?></div>
          </div>
        <?php else: foreach ($display as $r):
            $es = $r['employment_status'] ?? 'active';
            $pc = ['active'=>'pill-active','on_leave'=>'pill-onleave','terminated'=>'pill-terminated'][$es] ?? 'pill-active';
            $et = $r['employment_type'] ?? 'full-time';
            $avatar_color = er_avatar_color($er_palette, $r['department'] ?? $r['full_name']);
            $search_blob  = strtolower($r['full_name'] . ' ' . ($r['position'] ?? '') . ' ' . ($r['department'] ?? ''));

            // Everything the profile popup needs, bundled onto the row so a
            // click can render it instantly — same pattern as the edit modal.
            $prof                       = $r;
            $prof['avatar_color']       = $avatar_color;
            $prof['avatar_initials']    = er_initials($r['full_name']);
            $prof['role_label']        = role_label($r['role']);
            $prof['tenure_label']       = er_tenure($r['date_hired'] ?? null);
            $prof['can_salary']         = $can_salary;
            $prof['attendance_summary'] = $att_summary[(int)$r['user_id']] ?? ['present'=>0,'late'=>0,'absent'=>0,'on_leave'=>0,'hours'=>0,'rate'=>null];
            $prof['attendance_recent']  = $att_recent[(int)$r['user_id']] ?? [];
        ?>
        <div class="er-row" data-search="<?= htmlspecialchars($search_blob) ?>" data-profile="<?= htmlspecialchars(json_encode($prof), ENT_QUOTES, 'UTF-8') ?>" tabindex="0" role="button" aria-label="View profile for <?= htmlspecialchars($r['full_name'], ENT_QUOTES) ?>">
          <div class="er-avatar" style="<?= empty($r['profile_photo']) ? 'background:'.$avatar_color : '' ?>">
            <?php if (!empty($r['profile_photo'])): ?>
              <img src="<?= htmlspecialchars($r['profile_photo'], ENT_QUOTES) ?>" alt="">
            <?php else: ?>
              <?= htmlspecialchars(er_initials($r['full_name'])) ?>
            <?php endif; ?>
          </div>

          <div class="er-identity">
            <div class="er-name-line">
              <span class="er-name"><?= htmlspecialchars($r['full_name']) ?></span>
              <span class="er-role-tag"><?= role_label($r['role']) ?></span>
            </div>
            <div class="er-meta"><?= htmlspecialchars(($r['position'] ?? '—')) ?> · <?= htmlspecialchars(($r['department'] ?? '—')) ?></div>
            <?php if ($show_term_cols && !empty($r['termination_date'])): ?>
              <div class="er-meta er-meta-term">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= htmlspecialchars(date('M j, Y', strtotime($r['termination_date']))) ?> — <?= htmlspecialchars($r['termination_reason'] ?? 'No reason given') ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="er-col-type">
            <span class="er-type-badge"><?= $et === 'part-time' ? 'Part-time' : 'Full-time' ?></span>
          </div>

          <div class="er-col-status">
            <span class="status-pill <?= $pc ?>"><?= ucfirst(str_replace('_',' ',$es)) ?></span>
          </div>

          <div class="er-col-tenure"><?= htmlspecialchars(er_tenure($r['date_hired'] ?? null)) ?></div>

          <div class="er-chevron" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay-admin" id="profileModal">
  <div class="modal-admin-box pm-modal-box">
    <div class="modal-admin-header">
      <span>Employee Profile</span>
      <button class="modal-close-btn" onclick="document.getElementById('profileModal').classList.remove('open')">✕</button>
    </div>

    <div class="pm-body">
      <div class="pm-identity">
        <div class="pm-avatar" id="pmAvatar"></div>
        <div class="pm-identity-info">
          <div class="pm-name-line">
            <span class="pm-name" id="pmName"></span>
            <span class="er-role-tag" id="pmRoleTag"></span>
          </div>
          <div class="pm-position" id="pmPosition"></div>
          <div class="pm-badges" id="pmBadges"></div>
        </div>
        <div class="pm-ring-wrap">
          <div class="pm-ring" id="pmRing">
            <div class="pm-ring-inner">
              <span class="pm-ring-pct" id="pmRingPct">—</span>
              <span class="pm-ring-lbl">Attendance</span>
            </div>
          </div>
        </div>
      </div>

      <div class="pm-stats" id="pmStats"></div>

      <div class="pm-section-label">Employment Details</div>
      <div class="pm-details-grid" id="pmDetails"></div>

      <div class="pm-section-label">Recent Attendance <span class="sub">— last 30 days</span></div>
      <div class="pm-recent-list" id="pmRecent"></div>
    </div>

    <div class="pm-actions">
      <button type="button" class="btn btn-ghost" onclick="document.getElementById('profileModal').classList.remove('open')">Close</button>
      <button type="button" class="btn btn-primary" id="pmEditBtn">Edit Record</button>
    </div>
  </div>
</div>

<div class="modal-overlay-admin" id="editModal">
  <div class="modal-admin-box er-modal-box">
    <div class="modal-admin-header">
      <span id="editModalTitle">Edit Employee Record</span>
      <button class="modal-close-btn" onclick="document.getElementById('editModal').classList.remove('open')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="act" value="save_record">
      <input type="hidden" name="employee_id" id="f_employee_id">

      <div class="er-section-label">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
        Role &amp; Department
      </div>
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

      <div class="er-section-label">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        Contact Information
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

      <div class="er-section-label">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Employment Status
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
      <div id="terminationFields" style="display:none">
        <div class="er-section-label">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          Termination Details
        </div>
        <div class="form-row">
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
      </div>
      <div class="er-section-label">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        Compensation
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
  wrap.style.display = isTerminated ? 'block' : 'none';
  document.getElementById('f_term_date').required = isTerminated;
  document.getElementById('f_term_reason').required = isTerminated;
}

// Live client-side search across the currently active status tab —
// matches on name, position, or department.
(function () {
  var input = document.getElementById('erSearch');
  var rows  = document.querySelectorAll('#erList .er-row');
  var note  = document.getElementById('erCountNote');
  if (!input) return;

  input.addEventListener('input', function () {
    var term = input.value.trim().toLowerCase();
    var visible = 0;
    rows.forEach(function (row) {
      var show = !term || row.dataset.search.indexOf(term) !== -1;
      row.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    note.textContent = visible + ' shown';
  });
})();

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

/* ── Employee profile popup ─────────────────────────────────────── */
var PM_STATUS_LABEL = { present: 'Present', late: 'Late', absent: 'Absent', on_leave: 'On Leave' };
var PM_STATUS_CLASS = { present: 'pill-active', late: 'pill-onleave', absent: 'pill-terminated', on_leave: 'pill-onleave' };

function pmTitleCase(s) {
  return String(s || '').replace('_', ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
}
function pmDate(s, opts) {
  if (!s) return '—';
  return new Date(s + 'T00:00:00').toLocaleDateString('en-US', opts || { month: 'short', day: 'numeric', year: 'numeric' });
}
function pmTime(s) {
  if (!s) return '—';
  return new Date(s.replace(' ', 'T')).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}

function openProfileModal(rec) {
  // Avatar — real photo if the employee has one, otherwise the same
  // initials-on-color chip used in the roster row.
  var avatar = document.getElementById('pmAvatar');
  if (rec.profile_photo) {
    avatar.style.background = 'transparent';
    avatar.innerHTML = '<img src="' + rec.profile_photo + '" alt="">';
  } else {
    avatar.style.background = rec.avatar_color || '#5F5E5A';
    avatar.textContent = rec.avatar_initials || '?';
  }

  document.getElementById('pmName').textContent = rec.full_name || '—';
  document.getElementById('pmRoleTag').textContent = rec.role_label || rec.role || '';
  document.getElementById('pmPosition').textContent = (rec.position || '—') + ' · ' + (rec.department || '—');

  var es = rec.employment_status || 'active';
  var pillClass = { active: 'pill-active', on_leave: 'pill-onleave', terminated: 'pill-terminated' }[es] || 'pill-active';
  var etLabel = rec.employment_type === 'part-time' ? 'Part-time' : 'Full-time';
  document.getElementById('pmBadges').innerHTML =
    '<span class="status-pill ' + pillClass + '">' + pmTitleCase(es) + '</span>' +
    '<span class="er-type-badge">' + etLabel + '</span>';

  // Attendance ring — % of marked days (last 30) that were present/late.
  var summary = rec.attendance_summary || { present: 0, late: 0, absent: 0, on_leave: 0, hours: 0, rate: null };
  var ring = document.getElementById('pmRing');
  var pct = summary.rate;
  var ringColor = pct === null ? '#a89e8f' : (pct >= 90 ? '#2f7a4f' : (pct >= 75 ? '#b8703f' : '#8a3428'));
  ring.style.setProperty('--pct', pct === null ? 0 : pct);
  ring.style.setProperty('--ring-color', ringColor);
  document.getElementById('pmRingPct').textContent = pct === null ? '—' : (pct + '%');

  document.getElementById('pmStats').innerHTML =
    '<div class="pm-stat"><div class="num">' + summary.present + '</div><div class="lbl">Present</div></div>' +
    '<div class="pm-stat"><div class="num warn">' + summary.late + '</div><div class="lbl">Late</div></div>' +
    '<div class="pm-stat"><div class="num bad">' + summary.absent + '</div><div class="lbl">Absent</div></div>' +
    '<div class="pm-stat"><div class="num info">' + Number(summary.hours || 0).toFixed(1) + 'h</div><div class="lbl">Hours (30d)</div></div>';

  var details = [
    ['Contact Number', rec.contact_number || '—'],
    ['Date Hired', pmDate(rec.date_hired)],
    ['Tenure', rec.tenure_label || '—'],
    ['Employment Type', etLabel]
  ];
  if (rec.can_salary) {
    details.push(['Daily Rate', '₱' + Number(rec.daily_rate || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })]);
  }
  var html = details.map(function (d) {
    return '<div class="pm-detail-item"><div class="k">' + d[0] + '</div><div class="v">' + d[1] + '</div></div>';
  }).join('');
  html += '<div class="pm-detail-item full"><div class="k">Address</div><div class="v">' + (rec.address || '—') + '</div></div>';
  if (es === 'terminated' && rec.termination_date) {
    html += '<div class="pm-detail-item full pm-term"><div class="k">Termination</div><div class="v">' +
      pmDate(rec.termination_date) + ' — ' + (rec.termination_reason || 'No reason given') + '</div></div>';
  }
  document.getElementById('pmDetails').innerHTML = html;

  var recent = rec.attendance_recent || [];
  var recentEl = document.getElementById('pmRecent');
  if (!recent.length) {
    recentEl.innerHTML = '<div class="pm-empty-note">No attendance records in the last 30 days.</div>';
  } else {
    recentEl.innerHTML = recent.map(function (a) {
      var cls = PM_STATUS_CLASS[a.status] || 'pill-active';
      var lbl = PM_STATUS_LABEL[a.status] || pmTitleCase(a.status);
      var hrs = a.hours_worked > 0 ? Number(a.hours_worked).toFixed(2) + 'h' : '—';
      return '<div class="pm-recent-row">' +
        '<span class="pm-recent-date">' + pmDate(a.work_date, { month: 'short', day: 'numeric' }) + '</span>' +
        '<span class="pm-recent-time">' + pmTime(a.time_in) + ' – ' + pmTime(a.time_out) + '</span>' +
        '<span class="pm-recent-hours">' + hrs + '</span>' +
        '<span class="status-pill ' + cls + '">' + lbl + '</span>' +
        '</div>';
    }).join('');
  }

  var editBtn = document.getElementById('pmEditBtn');
  if (es === 'terminated') {
    editBtn.style.display = 'none';
  } else {
    editBtn.style.display = '';
    editBtn.onclick = function () {
      document.getElementById('profileModal').classList.remove('open');
      openEditModal(rec);
    };
  }

  document.getElementById('profileModal').classList.add('open');
}

document.querySelectorAll('.er-row').forEach(function (row) {
  function trigger() {
    var data = row.dataset.profile;
    if (!data) return;
    try { openProfileModal(JSON.parse(data)); } catch (err) { /* malformed payload — ignore */ }
  }
  row.addEventListener('click', trigger);
  row.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); trigger(); }
  });
});
</script>

<script src="../js/msg_banner_autodismiss.js"></script>
<script src="../js/theme-toggle.js"></script>
</body>
</html>