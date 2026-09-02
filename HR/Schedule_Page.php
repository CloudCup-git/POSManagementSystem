<?php
// ── SCHEDULE PAGE ──────────────────────────────────────────────────
// One page, two audiences, same as Attendance_Page.php:
//   - Any logged-in HR-system user (employee/manager/hr_admin) can see
//     their own weekly schedule as a read-only grid.
//   - Users with the 'manage_schedule' permission (managers/hr_admin)
//     additionally get an employee picker + shift builder to create the
//     schedule for a newly-hired (or existing) employee.
// CSS lives in ../css/schedule_page.css, JS lives in ../js/schedule_page.js.
require_once __DIR__ . '/../admin/Permissions.php';
require_hr_login();
require_permission('view_own_schedule'); // NOTE: add this permission key to Permissions.php and
                                          // grant it to hr_admin, manager, AND employee — same idea
                                          // as 'clock_self' in Attendance_Page.php, so every logged-in
                                          // HR-system user can open this page and see their own schedule.
require_once __DIR__ . '/../includes/DB_Connect.php';

$active_page = 'hr_schedule';
$uid         = current_hr_user_id();
$role        = current_role();
$full_name   = $_SESSION['full_name'] ?? 'User';

// Managers/HR admins get the builder UI below the grid.
// NOTE: add a 'manage_schedule' permission key to Permissions.php (hr_admin + manager only).
$can_manage  = has_permission('manage_schedule');

// A banner message set by save_shift/delete_shift/copy_week below is
// stashed in the session and read back exactly once here. Combined with
// the redirect-after-POST at the bottom of the handler block, this means
// refreshing the page after an action just re-loads it plainly — no
// "Shift removed." (or similar) firing again, and no browser "Resubmit
// form?" prompt.
$msg = $_SESSION['sched_flash'] ?? '';
unset($_SESSION['sched_flash']);
$DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Shift-length rule: a part-time shift must run 4–6 hours, a full-time
// shift must run 8–9 hours. Relies on `employees.employment_type`.
const SCHED_PART_TIME_MIN_HOURS = 4;
const SCHED_PART_TIME_MAX_HOURS = 6;
const SCHED_FULL_TIME_MIN_HOURS = 8;
const SCHED_FULL_TIME_MAX_HOURS = 9;

// Returns '' if the start/end pair is a valid length for this employment
// type, or an error message otherwise.
function sched_duration_violation(string $start, string $end, string $employment_type): string {
    $hours = (strtotime($end) - strtotime($start)) / 3600;

    if ($employment_type === 'part-time') {
        if ($hours >= SCHED_PART_TIME_MIN_HOURS && $hours <= SCHED_PART_TIME_MAX_HOURS) return '';
        return "A part-time shift must be between " . SCHED_PART_TIME_MIN_HOURS . " and " . SCHED_PART_TIME_MAX_HOURS . " hours long. This shift is " . round($hours, 1) . " hours.";
    }

    if ($hours >= SCHED_FULL_TIME_MIN_HOURS && $hours <= SCHED_FULL_TIME_MAX_HOURS) return '';
    return "A full-time shift must be between " . SCHED_FULL_TIME_MIN_HOURS . " and " . SCHED_FULL_TIME_MAX_HOURS . " hours long. This shift is " . round($hours, 1) . " hours.";
}

// NOTE: assumes a `schedules` table with these columns. If it doesn't exist yet, create it with:
//
//   CREATE TABLE schedules (
//     schedule_id INT AUTO_INCREMENT PRIMARY KEY,
//     employee_id INT NOT NULL,
//     day_of_week VARCHAR(10) NOT NULL,   -- 'Monday' .. 'Sunday'
//     start_time  TIME NOT NULL,
//     end_time    TIME NOT NULL,
//     label       VARCHAR(100) NOT NULL DEFAULT 'Shift',
//     color       VARCHAR(7) NOT NULL DEFAULT '#2f6690',
//     created_by  INT NULL,
//     created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
//     INDEX idx_schedules_employee (employee_id)
//   ) ENGINE=InnoDB;
//
// No inline FOREIGN KEY on purpose — it fails with errno 150 unless employee_id's
// type/engine/charset match users.user_id exactly, and none of the other HR tables
// (attendance, employees) rely on one either. Referential integrity is handled in
// PHP here, same as the rest of the module. If your `users.user_id` is INT AUTO_INCREMENT
// on an InnoDB table and you want the constraint anyway, add it after creating the
// table with:
//   ALTER TABLE schedules ADD CONSTRAINT fk_schedules_employee
//     FOREIGN KEY (employee_id) REFERENCES users(user_id) ON DELETE CASCADE;
//
// This is a recurring WEEKLY template (day-of-week based, like the sample schedule
// image), not tied to specific calendar dates. Adjust if you need date-specific shifts.

// Confirm the table actually exists before running any query against it — without
// this check, a missing table makes every mysqli_prepare() below return false, and
// mysqli_stmt_bind_param(false, ...) is a fatal TypeError instead of a clean message.
$schedules_table_ready = false;
if ($conn) {
    $chk = mysqli_query($conn, "SHOW TABLES LIKE 'schedules'");
    $schedules_table_ready = $chk && mysqli_num_rows($chk) > 0;
}

$GRID_START_HOUR = 7;   // grid starts at 7:00 AM
$GRID_END_HOUR   = 24;  // grid ends at midnight
$PX_PER_HOUR     = 48;

// Quick-pick presets for the shift builder's color swatches — purely a
// manual color override; independent of the shift type below.
$COLOR_PRESETS = [
    ['label' => 'Blue',   'color' => '#2f6690'],
    ['label' => 'Purple', 'color' => '#b8703f'],
    ['label' => 'Pink',   'color' => '#db2777'],
    ['label' => 'Green',  'color' => '#2f6f4e'],
    ['label' => 'Amber',  'color' => '#d97706'],
    ['label' => 'Red',    'color' => '#b8453a'],
];

// Every shift is one of these types — the dropdown in the builder only
// ever offers these — each with a sensible default color that auto-fills
// the color picker when picked (still overridable via the swatches above).
$SHIFT_LABELS = [
    'Opening Shift' => '#2f6690', // blue
    'Closing Shift' => '#d97706', // amber
    'Day Off'       => '#6b6156', // gray
];

// ── Employees list (HR/manager only, for the picker) ───────────────
// Terminated employees are excluded here on purpose, same as Accounts &
// Roles — once Employee Records marks someone Terminated, they shouldn't
// be pickable for scheduling (or show up in the All Employees roster)
// even if `is_active` hasn't been flipped for some other reason.
$employees = [];
$employee_types = []; // user_id => 'full-time' | 'part-time', for the Add/Edit Shift modal
if ($conn && $can_manage) {
    $er = mysqli_query($conn,
        "SELECT u.user_id, u.full_name, u.role, e.employment_type
         FROM users u
         LEFT JOIN employees e ON e.employee_id = u.user_id
         WHERE u.role = 'employee' AND u.is_active = 1
           AND COALESCE(e.employment_status, 'active') <> 'terminated'
         ORDER BY u.full_name");
    if ($er) while ($r = mysqli_fetch_assoc($er)) {
        $employees[] = $r;
        $employee_types[(int)$r['user_id']] = ($r['employment_type'] === 'part-time') ? 'part-time' : 'full-time';
    }
}

// Who are we looking at? HR/managers pick via ?employee=, everyone else sees themselves only.
if ($can_manage) {
    $sel_employee = (int)($_GET['employee'] ?? ($employees[0]['user_id'] ?? 0));
} else {
    $sel_employee = $uid;
}

// Managers/HR admins can also flip to an "All Employees" roster view
// (?view=all) that shows everyone's week at once instead of one
// employee's grid. Anyone without manage_schedule always sees their own
// single schedule, same as before.
$view = ($can_manage && ($_GET['view'] ?? '') === 'all') ? 'all' : 'single';

// ── Handle builder actions (HR/manager only) ────────────────────────
if ($conn && $schedules_table_ready && $can_manage && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'save_shift') {
        $schedule_id = (int)($_POST['schedule_id'] ?? 0);
        $emp_id      = (int)($_POST['employee_id'] ?? 0);
        // Multiple days can be checked at once so a recurring shift (e.g.
        // every Opening Shift, Mon-Fri) can be built in one save instead
        // of repeating this form once per day.
        $days        = array_values(array_filter((array)($_POST['days'] ?? []), function ($d) use ($DAYS) {
            return in_array($d, $DAYS, true);
        }));
        $start       = $_POST['start_time'] ?? '';
        $end         = $_POST['end_time'] ?? '';
        $label       = array_key_exists($_POST['label'] ?? '', $SHIFT_LABELS) ? $_POST['label'] : '';
        $color       = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : ($SHIFT_LABELS[$label] ?? '#2f6690');
        // Day Off isn't a worked shift, so it has no meaningful time range —
        // the form hides the time inputs for it, and here it's given a
        // fixed full-day placeholder instead of validating a real range.
        $is_day_off  = ($label === 'Day Off');
        if ($is_day_off) {
            $start = sprintf('%02d:00', $GRID_START_HOUR);
            $end   = $GRID_END_HOUR >= 24 ? '23:59' : sprintf('%02d:00', $GRID_END_HOUR);
        }

        $blocked = false;
        if (!$emp_id || !$days || $label === '' || (!$is_day_off && (!$start || !$end))) {
            $msg = 'error:Please choose an employee, at least one day, and a shift type' . ($is_day_off ? '.' : ', plus both start and end times.');
            $blocked = true;
        } elseif (!$is_day_off && strtotime($start) >= strtotime($end)) {
            $msg = 'error:End time must be after start time.';
            $blocked = true;
        } elseif (!$is_day_off) {
            $emp_type = 'full-time';
            $ts = mysqli_prepare($conn, "SELECT employment_type FROM employees WHERE employee_id=?");
            if ($ts) {
                mysqli_stmt_bind_param($ts, 'i', $emp_id);
                mysqli_stmt_execute($ts);
                $tres = mysqli_stmt_get_result($ts);
                if ($tres && ($trow = mysqli_fetch_assoc($tres)) && $trow['employment_type']) {
                    $emp_type = $trow['employment_type'];
                }
            }
            $duration_error = sched_duration_violation($start, $end, $emp_type);
            if ($duration_error !== '') {
                $msg = 'error:' . $duration_error;
                $blocked = true;
            }
        }

        // A "Day Off" and any other shift can't sit on the same day for the
        // same employee — check this after the checks above pass, so we
        // don't stack a confusing second error on top of an already-invalid
        // form. When editing, exclude the row being edited from the check
        // (a shift doesn't conflict with itself).
        if (!$blocked) {
            $conflict_days = [];
            foreach ($days as $day) {
                $cs = mysqli_prepare($conn,
                    "SELECT label FROM schedules WHERE employee_id=? AND day_of_week=?" . ($schedule_id ? " AND schedule_id<>?" : ""));
                if ($cs) {
                    if ($schedule_id) {
                        mysqli_stmt_bind_param($cs, 'isi', $emp_id, $day, $schedule_id);
                    } else {
                        mysqli_stmt_bind_param($cs, 'is', $emp_id, $day);
                    }
                    mysqli_stmt_execute($cs);
                    $cres = mysqli_stmt_get_result($cs);
                    if ($cres) {
                        while ($crow = mysqli_fetch_assoc($cres)) {
                            if ($is_day_off || $crow['label'] === 'Day Off') {
                                $conflict_days[] = $day;
                                break;
                            }
                        }
                    }
                }
            }
            if ($conflict_days) {
                $conflict_days = array_unique($conflict_days);
                $blocked = true;
                $plural = count($conflict_days) > 1;
                $msg = $is_day_off
                    ? 'error:' . implode(', ', $conflict_days) . ' already ' . ($plural ? 'have shifts' : 'has a shift') . ' scheduled — remove ' . ($plural ? 'them' : 'it') . ' before adding a Day Off there.'
                    : 'error:' . implode(', ', $conflict_days) . ' already ' . ($plural ? 'have a' : 'has a') . ' Day Off scheduled — remove it before adding another shift there.';
            }
        }

        if (!$blocked) {
            if ($schedule_id) {
                // Editing always applies to the one existing row — only
                // the first checked day is used (the UI only lets you
                // check one when editing).
                $day = $days[0];
                $s = mysqli_prepare($conn,
                    "UPDATE schedules SET employee_id=?, day_of_week=?, start_time=?, end_time=?, label=?, color=? WHERE schedule_id=?");
                if ($s) {
                    mysqli_stmt_bind_param($s, 'isssssi', $emp_id, $day, $start, $end, $label, $color, $schedule_id);
                    $msg = mysqli_stmt_execute($s) ? 'success:Shift updated.' : 'error:Could not update shift. Please try again.';
                } else {
                    $msg = 'error:Could not update shift. Please try again.';
                }
            } else {
                // Adding: insert one row per checked day.
                $s = mysqli_prepare($conn,
                    "INSERT INTO schedules (employee_id, day_of_week, start_time, end_time, label, color, created_by) VALUES (?,?,?,?,?,?,?)");
                $added = 0;
                if ($s) {
                    foreach ($days as $day) {
                        mysqli_stmt_bind_param($s, 'isssssi', $emp_id, $day, $start, $end, $label, $color, $uid);
                        if (mysqli_stmt_execute($s)) $added++;
                    }
                }
                $msg = $added
                    ? 'success:' . $added . ' shift' . ($added === 1 ? '' : 's') . ' added.'
                    : 'error:Could not add shift. Please try again.';
            }
        }
        $sel_employee = $emp_id ?: $sel_employee;

    } elseif ($act === 'delete_shift') {
        $schedule_id = (int)($_POST['schedule_id'] ?? 0);
        $sel_employee = (int)($_POST['employee_id'] ?? $sel_employee);
        if ($schedule_id) {
            $s = mysqli_prepare($conn, "DELETE FROM schedules WHERE schedule_id=?");
            if ($s) {
                mysqli_stmt_bind_param($s, 'i', $schedule_id);
                $msg = mysqli_stmt_execute($s) ? 'success:Shift removed.' : 'error:Could not remove shift. Please try again.';
            } else {
                $msg = 'error:Could not remove shift. Please try again.';
            }
        }

    } elseif ($act === 'copy_week') {
        // Duplicate every shift from one employee onto another — handy right after
        // hiring someone into a role that already has a template schedule.
        $from_id = (int)($_POST['from_employee'] ?? 0);
        $to_id   = (int)($_POST['employee_id'] ?? 0);
        if ($from_id && $to_id && $from_id !== $to_id) {
            $src = mysqli_query($conn,
                "SELECT day_of_week, start_time, end_time, label, color FROM schedules WHERE employee_id=" . $from_id);
            $ins = mysqli_prepare($conn,
                "INSERT INTO schedules (employee_id, day_of_week, start_time, end_time, label, color, created_by) VALUES (?,?,?,?,?,?,?)");
            if ($src && $ins) {
                while ($row = mysqli_fetch_assoc($src)) {
                    mysqli_stmt_bind_param($ins, 'isssssi', $to_id, $row['day_of_week'], $row['start_time'], $row['end_time'], $row['label'], $row['color'], $uid);
                    mysqli_stmt_execute($ins);
                }
                $msg = 'success:Schedule copied.';
            } else {
                $msg = 'error:Could not copy schedule. Please try again.';
            }
            $sel_employee = $to_id;
        } else {
            $msg = 'error:Choose two different employees to copy between.';
        }
    }
} elseif ($conn && !$schedules_table_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = 'error:The schedules table hasn\'t been created in the database yet, so nothing was saved.';
}

// ── Post/Redirect/Get ────────────────────────────────────────────────
// Every POST above ends here: stash the banner message in the session
// and redirect to the same page as a fresh GET. This is what stops a
// browser refresh from resubmitting the form (which would otherwise
// re-run the delete/save and show a stale "Shift removed." / "Shift
// updated." notification for an action that isn't actually happening
// again).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['sched_flash'] = $msg;
    $redirect_qs = $can_manage ? ('?view=' . $view . '&employee=' . (int)$sel_employee) : '';
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . $redirect_qs);
    exit;
}

// ── Fetch shifts for the selected employee ──────────────────────────
$shifts_by_day = array_fill_keys($DAYS, []);
if ($conn && $schedules_table_ready && $sel_employee) {
    $s = mysqli_prepare($conn, "SELECT * FROM schedules WHERE employee_id=? ORDER BY start_time");
    if ($s) {
        mysqli_stmt_bind_param($s, 'i', $sel_employee);
        mysqli_stmt_execute($s);
        $res = mysqli_stmt_get_result($s);
        if ($res) while ($r = mysqli_fetch_assoc($res)) {
            if (isset($shifts_by_day[$r['day_of_week']])) $shifts_by_day[$r['day_of_week']][] = $r;
        }
    }
}

// ── Fetch every employee's shifts for the "All Employees" roster view ──
// Keyed by employee_id => day_of_week => [shift rows], so the overview
// table below can just look up $all_shifts[$emp_id][$day] per cell.
$all_shifts = [];
if ($conn && $schedules_table_ready && $can_manage && $view === 'all') {
    $ar = mysqli_query($conn, "SELECT * FROM schedules ORDER BY start_time");
    if ($ar) while ($r = mysqli_fetch_assoc($ar)) {
        $all_shifts[(int)$r['employee_id']][$r['day_of_week']][] = $r;
    }
}

$sel_employee_name = $full_name;
if ($can_manage) {
    foreach ($employees as $e) {
        if ((int)$e['user_id'] === $sel_employee) { $sel_employee_name = $e['full_name']; break; }
    }
}

// Converts a start/end time into an absolutely-positioned top/height for the grid.
function schedule_block_style(string $start, string $end, int $gridStartHour, int $pxPerHour): string {
    $startMin     = ((int)date('H', strtotime($start))) * 60 + (int)date('i', strtotime($start));
    $endMin       = ((int)date('H', strtotime($end)))   * 60 + (int)date('i', strtotime($end));
    $gridStartMin = $gridStartHour * 60;
    $top    = max(0, ($startMin - $gridStartMin) / 60 * $pxPerHour);
    $height = max(22, ($endMin - $startMin) / 60 * $pxPerHour);
    return "top:{$top}px;height:{$height}px;";
}

$time_labels = [];
for ($h = $GRID_START_HOUR; $h <= $GRID_END_HOUR; $h++) {
    $time_labels[] = date('g:i A', strtotime(sprintf('%02d:00', $h % 24)));
}
$grid_height = ($GRID_END_HOUR - $GRID_START_HOUR) * $PX_PER_HOUR;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script>(function(){try{var t=localStorage.getItem('cloudcup-theme')||'light';document.documentElement.classList.toggle('dark-mode',t==='dark');document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="stylesheet" href="../css/theme.css"/>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= $can_manage ? 'Schedule Management' : 'My Schedule' ?> — Cloud Cup HR</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/hr_module.css"/>
  <link rel="stylesheet" href="../css/schedule_page.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<script src="../js/sidebar-toggle.js"></script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<?php if (current_role() === 'employee') { require_once '../staff/Sidebar_Employee.php'; } else { require_once '../HR/Sidebar_HR.php'; } ?>
<script src="../js/lucide-init.js"></script>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      
      <h1><?= $can_manage ? 'Schedule Management' : 'My Schedule' ?></h1>
    </div>
    <div class="topbar-right"><div class="topbar-date"><?= date('F j, Y') ?></div></div>
  </div>

  <?php // tabs removed — navigation now lives in the sidebar ?>

  <div class="content">
    <?php if (!$schedules_table_ready): ?>
      <div class="msg-banner error">
        The <code>schedules</code> table doesn't exist in the database yet, so no schedule can be shown or saved.
        <?php if ($can_manage): ?>Ask your developer to run the <code>CREATE TABLE schedules (...)</code> statement at the top of this file, then reload this page.<?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>"><?= htmlspecialchars($mm) ?></div>
    <?php endif; ?>

    <?php if ($can_manage): ?>
    <!-- ── HR / Manager: employee picker + actions ───────────────── -->
    <div class="widget">
      <div class="widget-header">
        <div>
          <div class="widget-title">Build a Weekly Schedule</div>
          <div style="font-size:12px;color:var(--hr-text-light);margin-top:2px"><?= $view === 'all' ? 'Everyone\'s recurring weekly schedule at a glance.' : 'Pick an employee to view or edit their recurring weekly schedule.' ?></div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <?php
            $sched_btn_base = 'display:inline-block;text-decoration:none;font-family:inherit;cursor:pointer;transition:opacity .15s;padding:6px 12px;font-size:12.5px;font-weight:600;border-radius:7px;';
            $sched_btn_active = $sched_btn_base . 'border:1px solid var(--caramel,#b8703f);background:var(--caramel,#b8703f);color:#fff;';
            $sched_btn_inactive = $sched_btn_base . 'border:1px solid rgba(44,92,130,.15);background:var(--white,#fff);color:var(--text,#2c3e50);';
          ?>
          <a href="?view=single&employee=<?= (int)$sel_employee ?>" style="<?= $view === 'single' ? $sched_btn_active : $sched_btn_inactive ?>">Single Employee</a>
          <a href="?view=all" style="<?= $view === 'all' ? $sched_btn_active : $sched_btn_inactive ?>">All Employees</a>
          <?php if (!empty($employees)): ?>
          <?php if ($view === 'single'): ?>
          <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('copyModal').classList.add('open')">Copy From…</button>
          <?php endif; ?>
          <button class="btn btn-primary btn-sm" type="button" onclick="openShiftModal()">+ Add Shift</button>
          <?php endif; ?>
        </div>
      </div>

      <?php if (empty($employees)): ?>
        <div class="empty-state">No active employees yet. Create an account first in Accounts &amp; Roles.</div>
      <?php elseif ($view === 'single'): ?>
        <form method="GET" style="margin-top:14px">
          <input type="hidden" name="view" value="single">
          <select name="employee" onchange="this.form.submit()" style="padding:9px 12px;border-radius:8px;border:1px solid var(--hr-border);font-size:13px;font-family:inherit;min-width:240px">
            <?php foreach ($employees as $e): ?>
              <option value="<?= $e['user_id'] ?>" <?= $sel_employee == $e['user_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($e['full_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($view === 'single'): ?>
    <!-- ── Weekly grid (read-only for employees, same view HR sees) ─ -->
    <div class="widget">
      <div class="widget-header">
        <div class="widget-title"><?= $can_manage ? htmlspecialchars($sel_employee_name) . "'s Schedule" : 'My Weekly Schedule' ?></div>
      </div>

      <?php if (!$sel_employee): ?>
        <div class="empty-state">No employee selected.</div>
      <?php else: ?>
      <div class="sched-grid-wrap">
        <div class="sched-grid" style="grid-template-columns:70px repeat(7, 1fr)">
          <div class="sched-corner"></div>
          <?php foreach ($DAYS as $d): ?>
            <div class="sched-day-head"><?= $d ?></div>
          <?php endforeach; ?>

          <div class="sched-times" style="height:<?= $grid_height ?>px">
            <?php foreach ($time_labels as $tl): ?>
              <div class="sched-time-row" style="height:<?= $PX_PER_HOUR ?>px"><?= $tl ?></div>
            <?php endforeach; ?>
          </div>

          <?php foreach ($DAYS as $d): ?>
            <div class="sched-day-col" style="height:<?= $grid_height ?>px" data-day="<?= $d ?>">
              <?php for ($i = 1; $i < ($GRID_END_HOUR - $GRID_START_HOUR); $i++): ?>
                <div class="sched-hour-line" style="top:<?= $i * $PX_PER_HOUR ?>px"></div>
              <?php endfor; ?>
              <?php foreach ($shifts_by_day[$d] as $sh): ?>
                <div class="sched-block<?= $can_manage ? ' editable' : '' ?>"
                     style="<?= schedule_block_style($sh['start_time'], $sh['end_time'], $GRID_START_HOUR, $PX_PER_HOUR) ?>background:<?= htmlspecialchars($sh['color']) ?>"
                     <?php if ($can_manage): ?>
                     onclick='openShiftModal(<?= json_encode([
                        'schedule_id' => $sh['schedule_id'],
                        'employee_id' => $sel_employee,
                        'day_of_week' => $sh['day_of_week'],
                        'start_time'  => substr($sh['start_time'], 0, 5),
                        'end_time'    => substr($sh['end_time'], 0, 5),
                        'label'       => $sh['label'],
                        'color'       => $sh['color'],
                     ]) ?>)'
                     <?php endif; ?>>
                  <div class="sched-block-label"><?= htmlspecialchars($sh['label']) ?></div>
                  <?php if ($sh['label'] !== 'Day Off'): ?>
                  <div class="sched-block-time"><?= date('g:i A', strtotime($sh['start_time'])) ?>–<?= date('g:i A', strtotime($sh['end_time'])) ?></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php
        $has_any_shift = false;
        foreach ($shifts_by_day as $day_shifts) if (!empty($day_shifts)) $has_any_shift = true;
      ?>
      <?php if (!$has_any_shift): ?>
        <div class="empty-state" style="margin-top:14px"><?= $can_manage ? 'No shifts scheduled yet. Click "+ Add Shift" to build this week.' : 'No shifts scheduled for you yet. Check back once HR sets up your schedule.' ?></div>
      <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php if ($can_manage && $sel_employee): ?>
    <!-- ── Shift list (easier bulk edit/delete than clicking tiny grid blocks) ── -->
    <div class="widget">
      <div class="widget-title" style="margin-bottom:10px">All Shifts</div>
      <table>
        <thead><tr><th>Day</th><th>Start</th><th>End</th><th>Label</th><th></th><th>Actions</th></tr></thead>
        <tbody>
          <?php $any = false; foreach ($DAYS as $d): foreach ($shifts_by_day[$d] as $sh): $any = true; ?>
          <tr>
            <td><?= $d ?></td>
            <td><?= $sh['label'] === 'Day Off' ? '—' : date('g:i A', strtotime($sh['start_time'])) ?></td>
            <td><?= $sh['label'] === 'Day Off' ? '—' : date('g:i A', strtotime($sh['end_time'])) ?></td>
            <td><?= htmlspecialchars($sh['label']) ?></td>
            <td><span class="sched-swatch" style="background:<?= htmlspecialchars($sh['color']) ?>"></span></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <button class="btn btn-ghost btn-sm" type="button"
                  onclick='openShiftModal(<?= json_encode([
                    "schedule_id" => $sh["schedule_id"],
                    "employee_id" => $sel_employee,
                    "day_of_week" => $sh["day_of_week"],
                    "start_time"  => substr($sh["start_time"], 0, 5),
                    "end_time"    => substr($sh["end_time"], 0, 5),
                    "label"       => $sh["label"],
                    "color"       => $sh["color"],
                  ]) ?>)'>Edit</button>
                <form method="POST" class="delete-shift-form" data-name="<?= htmlspecialchars($d . ' ' . $sh['label'], ENT_QUOTES) ?>">
                  <input type="hidden" name="act" value="delete_shift">
                  <input type="hidden" name="schedule_id" value="<?= $sh['schedule_id'] ?>">
                  <input type="hidden" name="employee_id" value="<?= $sel_employee ?>">
                  <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; endforeach; ?>
          <?php if (!$any): ?>
            <tr><td colspan="6" class="empty-state">No shifts yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php endif; // $view === 'single' ?>

    <?php if ($can_manage && $view === 'all'): ?>
    <!-- ── All Employees: everyone's week in one roster table ──────── -->
    <div class="widget">
      <div class="widget-header">
        <div class="widget-title">All Employees — This Week</div>
      </div>

      <?php if (empty($employees)): ?>
        <div class="empty-state">No active employees yet. Create an account first in Accounts &amp; Roles.</div>
      <?php else: ?>
      <div style="overflow-x:auto">
        <table class="sched-overview-table">
          <thead>
            <tr>
              <th class="sched-ov-emp-head">Employee</th>
              <?php foreach ($DAYS as $d): ?>
                <th><?= substr($d, 0, 3) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($employees as $e): $eid = (int)$e['user_id']; ?>
            <tr>
              <td class="sched-ov-emp-head">
                <?= htmlspecialchars($e['full_name']) ?>
                <span class="sched-emp-type-badge <?= $employee_types[$eid] ?? 'full-time' ?>"><?= ($employee_types[$eid] ?? 'full-time') === 'part-time' ? 'PT' : 'FT' ?></span>
              </td>
              <?php foreach ($DAYS as $d): $dayShifts = $all_shifts[$eid][$d] ?? []; ?>
                <td class="sched-ov-day">
                  <?php if (empty($dayShifts)): ?>
                    <span class="sched-ov-empty">—</span>
                  <?php else: foreach ($dayShifts as $sh): ?>
                    <div class="sched-ov-chip" style="background:<?= htmlspecialchars($sh['color']) ?>"
                         onclick='openShiftModal(<?= json_encode([
                            "schedule_id" => $sh["schedule_id"],
                            "employee_id" => $eid,
                            "day_of_week" => $sh["day_of_week"],
                            "start_time"  => substr($sh["start_time"], 0, 5),
                            "end_time"    => substr($sh["end_time"], 0, 5),
                            "label"       => $sh["label"],
                            "color"       => $sh["color"],
                         ]) ?>)'>
                      <div class="sched-ov-chip-label"><?= htmlspecialchars($sh['label']) ?></div>
                      <?php if ($sh['label'] !== 'Day Off'): ?>
                        <div class="sched-ov-chip-time"><?= date('g:i A', strtotime($sh['start_time'])) ?>–<?= date('g:i A', strtotime($sh['end_time'])) ?></div>
                      <?php endif; ?>
                      <form method="POST" class="delete-shift-form sched-ov-chip-del" data-name="<?= htmlspecialchars($d . ' ' . $sh['label'], ENT_QUOTES) ?>" onclick="event.stopPropagation()">
                        <input type="hidden" name="act" value="delete_shift">
                        <input type="hidden" name="schedule_id" value="<?= $sh['schedule_id'] ?>">
                        <input type="hidden" name="employee_id" value="<?= $eid ?>">
                        <button type="submit" title="Delete this shift">×</button>
                      </form>
                    </div>
                  <?php endforeach; endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="form-hint">Click a shift to edit it, or use the × to remove it.</div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($can_manage): ?>
<!-- ADD / EDIT SHIFT MODAL -->
<div class="modal-overlay-admin" id="shiftModal">
  <div class="modal-admin-box">
    <div class="modal-admin-header">
      <span id="shiftModalTitle">Add Shift</span>
      <button class="modal-close-btn" onclick="document.getElementById('shiftModal').classList.remove('open')">✕</button>
    </div>
    <form method="POST" id="shiftForm">
      <input type="hidden" name="act" value="save_shift">
      <input type="hidden" name="schedule_id" id="f_schedule_id" value="">

      <div class="form-group-admin">
        <label>Employee * <span id="empTypeBadge" class="sched-emp-type-badge"></span></label>
        <select name="employee_id" id="f_employee_id" required>
          <?php foreach ($employees as $e): ?>
            <option value="<?= $e['user_id'] ?>" <?= $sel_employee == $e['user_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($e['full_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group-admin">
        <label>Day(s) *</label>
        <div class="sched-day-picker" id="dayPicker">
          <?php foreach ($DAYS as $d): ?>
            <label class="sched-day-chip">
              <input type="checkbox" name="days[]" value="<?= $d ?>" class="f_day_checkbox">
              <span><?= substr($d, 0, 3) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="form-hint" id="dayPickerHint">Pick one or more days — the same shift is added to each.</div>
      </div>

      <div class="form-row" id="timeFieldsRow">
        <div class="form-group-admin">
          <label>Start Time *</label>
          <input type="time" name="start_time" id="f_start" required>
        </div>
        <div class="form-group-admin">
          <label>End Time *</label>
          <input type="time" name="end_time" id="f_end" required>
        </div>
      </div>
      <div class="form-hint" id="dayOffHint" style="display:none">Day Off shifts don't need a time range.</div>

      <div class="form-group-admin">
        <label>Shift Type *</label>
        <select name="label" id="f_label" required>
          <option value="">— Select shift type —</option>
          <?php foreach (array_keys($SHIFT_LABELS) as $label_name): ?>
            <option value="<?= htmlspecialchars($label_name) ?>"><?= htmlspecialchars($label_name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group-admin">
        <label>Color</label>
        <div class="sched-color-picker" id="colorPicker">
          <?php foreach ($COLOR_PRESETS as $p): ?>
            <span class="sched-color-dot" data-color="<?= $p['color'] ?>" style="background:<?= $p['color'] ?>" title="<?= htmlspecialchars($p['label']) ?>"></span>
          <?php endforeach; ?>
        </div>
        <input type="color" name="color" id="f_color" value="#2f6690" style="margin-top:8px;width:56px;height:32px;padding:0;border:1px solid var(--hr-border);border-radius:6px">
      </div>

      <div class="modal-admin-actions">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('shiftModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Shift</button>
      </div>
    </form>
  </div>
</div>

<!-- COPY WEEK MODAL -->
<div class="modal-overlay-admin" id="copyModal">
  <div class="modal-admin-box">
    <div class="modal-admin-header">
      <span>Copy Schedule From Another Employee</span>
      <button class="modal-close-btn" onclick="document.getElementById('copyModal').classList.remove('open')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="act" value="copy_week">
      <input type="hidden" name="employee_id" value="<?= $sel_employee ?>">
      <div class="form-group-admin">
        <label>Copy shifts from *</label>
        <select name="from_employee" required>
          <?php foreach ($employees as $e): if ((int)$e['user_id'] === $sel_employee) continue; ?>
            <option value="<?= $e['user_id'] ?>"><?= htmlspecialchars($e['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="font-size:12.5px;color:var(--hr-text-light);margin-bottom:14px">This adds a copy of every shift onto <strong><?= htmlspecialchars($sel_employee_name) ?></strong>'s schedule — it won't remove anything already there.</div>
      <div class="modal-admin-actions">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('copyModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Copy Shifts</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
var SCHED_DEFAULT_EMPLOYEE = <?= (int)$sel_employee ?>;
var SCHED_EMPLOYEE_TYPES = <?= json_encode($employee_types) ?>; // { "<user_id>": "full-time"|"part-time" }
var SCHED_LABEL_COLORS = <?= json_encode($SHIFT_LABELS) ?>;
var SCHED_PART_TIME_MIN_HOURS = <?= (int)SCHED_PART_TIME_MIN_HOURS ?>;
var SCHED_PART_TIME_MAX_HOURS = <?= (int)SCHED_PART_TIME_MAX_HOURS ?>;
var SCHED_FULL_TIME_MIN_HOURS = <?= (int)SCHED_FULL_TIME_MIN_HOURS ?>;
var SCHED_FULL_TIME_MAX_HOURS = <?= (int)SCHED_FULL_TIME_MAX_HOURS ?>;
</script>
<script src="../js/schedule_page.js"></script>
<script src="../js/msg_banner_autodismiss.js"></script>
<script src="../js/theme-toggle.js"></script>
</body>
</html>