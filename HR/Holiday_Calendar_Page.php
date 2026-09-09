<?php
// ── HOLIDAY CALENDAR ──────────────────────────────────────────────
// Shared, company-wide calendar of holidays (not per-employee, unlike
// leave requests). Any logged-in HR-system user can view it — the
// attendance status resolver checks this table so a holiday date
// overrides late/undertime/absent for everyone that day. Only
// hr_admin/manager (permission 'manage_holidays') can add/edit/remove
// entries.
require_once __DIR__ . '/../admin/Permissions.php';
require_hr_login();
require_permission('view_own_schedule'); // same broad "any logged-in HR user" gate as Schedule_Page.php
require_once __DIR__ . '/../includes/DB_Connect.php';

$active_page = 'hr_holidays';
$can_manage  = has_permission('manage_holidays');
$uid         = current_hr_user_id();

// NOTE: assumes a `holidays` table with these columns. If it doesn't exist yet, create it with:
//
//   CREATE TABLE holidays (
//     holiday_id    INT AUTO_INCREMENT PRIMARY KEY,
//     holiday_date  DATE NOT NULL UNIQUE,
//     name          VARCHAR(150) NOT NULL,
//     type          ENUM('regular','special_non_working') NOT NULL DEFAULT 'regular',
//     created_by    INT NULL,
//     created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
//   ) ENGINE=InnoDB;
//
// One row per calendar date (UNIQUE on holiday_date) — a date is either a
// holiday or it isn't, company-wide, same idea as `schedules` in
// Schedule_Page.php: no inline FOREIGN KEY, checked with SHOW TABLES first
// so a missing table gives a clean message instead of a fatal error.
$holidays_table_ready = false;
if ($conn) {
    $chk = mysqli_query($conn, "SHOW TABLES LIKE 'holidays'");
    $holidays_table_ready = $chk && mysqli_num_rows($chk) > 0;
}

$msg = $_SESSION['holiday_flash'] ?? '';
unset($_SESSION['holiday_flash']);

$TYPE_LABELS = ['regular' => 'Regular Holiday', 'special_non_working' => 'Special Non-Working Day'];

// Official Philippine regular holidays & special (non-working) days —
// per Malacañang Proclamation No. 1006 (2026). Movable dates (Lunar New
// Year, Holy Week, Eid'l Fitr/Adha, National Heroes Day) are fixed to
// their proclaimed date for that specific year, so this list is keyed
// per year and needs a new entry added here once next year's
// proclamation is released. "Load PH Holidays" below only offers a
// year that has a preset here.
const PH_HOLIDAYS_BY_YEAR = [
    2026 => [
        ['date' => '2026-01-01', 'name' => "New Year's Day",                         'type' => 'regular'],
        ['date' => '2026-02-17', 'name' => 'Chinese New Year',                        'type' => 'special_non_working'],
        ['date' => '2026-03-20', 'name' => "Eid'l Fitr",                              'type' => 'regular'],
        ['date' => '2026-04-02', 'name' => 'Maundy Thursday',                         'type' => 'regular'],
        ['date' => '2026-04-03', 'name' => 'Good Friday',                             'type' => 'regular'],
        ['date' => '2026-04-04', 'name' => 'Black Saturday',                          'type' => 'special_non_working'],
        ['date' => '2026-04-09', 'name' => 'Araw ng Kagitingan',                      'type' => 'regular'],
        ['date' => '2026-05-01', 'name' => 'Labor Day',                               'type' => 'regular'],
        ['date' => '2026-05-27', 'name' => "Eid'l Adha",                              'type' => 'regular'],
        ['date' => '2026-06-12', 'name' => 'Independence Day',                        'type' => 'regular'],
        ['date' => '2026-08-21', 'name' => 'Ninoy Aquino Day',                        'type' => 'special_non_working'],
        ['date' => '2026-08-31', 'name' => 'National Heroes Day',                     'type' => 'regular'],
        ['date' => '2026-11-01', 'name' => "All Saints' Day",                         'type' => 'special_non_working'],
        ['date' => '2026-11-02', 'name' => "All Souls' Day",                          'type' => 'special_non_working'],
        ['date' => '2026-11-30', 'name' => 'Bonifacio Day',                           'type' => 'regular'],
        ['date' => '2026-12-08', 'name' => 'Feast of the Immaculate Conception',      'type' => 'special_non_working'],
        ['date' => '2026-12-24', 'name' => 'Christmas Eve',                           'type' => 'special_non_working'],
        ['date' => '2026-12-25', 'name' => 'Christmas Day',                           'type' => 'regular'],
        ['date' => '2026-12-30', 'name' => 'Rizal Day',                               'type' => 'regular'],
        ['date' => '2026-12-31', 'name' => 'Last Day of the Year',                    'type' => 'special_non_working'],
    ],
];

if ($conn && $holidays_table_ready && $_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_holiday') {
        $date = trim($_POST['holiday_date'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $type = ($_POST['type'] ?? 'regular') === 'special_non_working' ? 'special_non_working' : 'regular';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $_SESSION['holiday_flash'] = 'error:Please pick a valid date.';
        } elseif ($name === '') {
            $_SESSION['holiday_flash'] = 'error:Please enter a holiday name.';
        } else {
            $s = mysqli_prepare($conn,
                "INSERT INTO holidays (holiday_date, name, type, created_by) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE name=VALUES(name), type=VALUES(type)");
            mysqli_stmt_bind_param($s, 'sssi', $date, $name, $type, $uid);
            mysqli_stmt_execute($s);
            $_SESSION['holiday_flash'] = 'success:Holiday saved for ' . date('M j, Y', strtotime($date)) . '.';
        }
    } elseif ($action === 'delete_holiday') {
        $holiday_id = (int) ($_POST['holiday_id'] ?? 0);
        $s = mysqli_prepare($conn, "DELETE FROM holidays WHERE holiday_id=?");
        mysqli_stmt_bind_param($s, 'i', $holiday_id);
        mysqli_stmt_execute($s);
        $_SESSION['holiday_flash'] = 'success:Holiday removed.';
    } elseif ($action === 'seed_ph_holidays') {
        $year = (int) ($_POST['year'] ?? 0);
        $preset = PH_HOLIDAYS_BY_YEAR[$year] ?? null;

        if (!$preset) {
            $_SESSION['holiday_flash'] = "error:No preset PH holiday list yet for $year.";
        } else {
            // INSERT IGNORE: a date the HR team already customized manually
            // keeps whatever they set — this only fills in the gaps.
            $s = mysqli_prepare($conn,
                "INSERT IGNORE INTO holidays (holiday_date, name, type, created_by) VALUES (?,?,?,?)");
            $added = 0;
            foreach ($preset as $h) {
                mysqli_stmt_bind_param($s, 'sssi', $h['date'], $h['name'], $h['type'], $uid);
                mysqli_stmt_execute($s);
                if (mysqli_stmt_affected_rows($s) > 0) $added++;
            }
            $skipped = count($preset) - $added;
            $_SESSION['holiday_flash'] = 'success:Added ' . $added . ' PH holiday' . ($added === 1 ? '' : 's') . " for $year."
                . ($skipped > 0 ? " ($skipped already on the calendar were left as is.)" : '');
        }
    }

    header('Location: Holiday_Calendar_Page.php?month=' . urlencode($_GET['month'] ?? date('Y-m')));
    exit;
}

// ── Month being viewed ──────────────────────────────────────────
$month_param = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month_param)) $month_param = date('Y-m');
[$view_year, $view_month] = array_map('intval', explode('-', $month_param));
$month_start = sprintf('%04d-%02d-01', $view_year, $view_month);
$days_in_month = (int) date('t', strtotime($month_start));
$first_dow = (int) date('N', strtotime($month_start)); // 1 (Mon) .. 7 (Sun)

$prev_month = date('Y-m', strtotime($month_start . ' -1 month'));
$next_month = date('Y-m', strtotime($month_start . ' +1 month'));

$holidays_by_day = []; // day-of-month => holiday row
if ($conn && $holidays_table_ready) {
    $month_end = date('Y-m-t', strtotime($month_start));
    $r = mysqli_query($conn,
        "SELECT * FROM holidays WHERE holiday_date BETWEEN '$month_start' AND '$month_end' ORDER BY holiday_date");
    if ($r) while ($row = mysqli_fetch_assoc($r)) {
        $d = (int) date('j', strtotime($row['holiday_date']));
        $holidays_by_day[$d] = $row;
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
  <title>Holiday Calendar — Cloud Cup HR</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/hr_module.css"/>
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .hcal-page{
      font-family:'Inter',sans-serif;
      background:var(--cream-light);
      border-radius:18px;
      padding:20px;
    }
    .hcal-nav-btn{ background:var(--white,#fff);border:1px solid rgba(44,92,130,.15);border-radius:9px;width:34px;height:34px;display:flex;align-items:center;justify-content:center;cursor:pointer;text-decoration:none;color:inherit; }
    .hcal-topbar{ display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px; }
    .hcal-nav{ display:flex;align-items:center;gap:10px; }
    .hcal-nav select{
      font-family:'Fraunces',serif;font-weight:700;font-size:16px;
      border:1px solid rgba(44,92,130,.15);border-radius:9px;
      background:var(--white,#fff);color:inherit;padding:8px 12px;cursor:pointer;
    }
    .hcal-legend{ display:flex;align-items:center;gap:14px;font-size:12.5px;color:var(--text-light,#8A7666); }
    .hcal-legend span{ display:inline-flex;align-items:center;gap:5px; }
    .hcal-dot{ width:8px;height:8px;border-radius:50%; }
    .hcal-dot.regular{ background:#6B2E22; }
    .hcal-dot.special{ background:#8A5A3C; }

    .hcal-grid{ display:grid;grid-template-columns:repeat(7,1fr);gap:8px; }
    .hcal-dow{ text-align:center;font-size:11.5px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text-light,#8A7666);padding-bottom:6px; }
    .hcal-cell{
      min-height:88px;border-radius:10px;border:1px solid rgba(255,255,255,.35);
      background:rgba(255,255,255,.42);
      backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px);
      padding:8px;position:relative;
    }
    html.dark-mode .hcal-cell{
      background:rgba(20,36,51,.55);
      border-color:rgba(255,255,255,.08);
    }
    .hcal-cell.empty{ background:transparent;border:none;backdrop-filter:none; }
    .hcal-cell.is-today{ border-color:#628E90; box-shadow:0 0 0 1px #628E90 inset; }
    .hcal-cell-day{ font-size:12.5px;font-weight:600;color:var(--text-light,#8A7666); }
    .hcal-cell.has-holiday{ background:rgba(107,46,34,.16); }
    .hcal-cell.has-holiday.special{ background:rgba(138,90,60,.16); }
    html.dark-mode .hcal-cell.has-holiday{ background:rgba(107,46,34,.35); }
    html.dark-mode .hcal-cell.has-holiday.special{ background:rgba(138,90,60,.35); }
    .hcal-holiday-name{ font-size:11.5px;font-weight:600;color:#6B2E22;margin-top:6px;line-height:1.3;word-break:break-word; }
    .hcal-holiday-type{ font-size:9.5px;color:var(--text-light,#8A7666);margin-top:2px; }
    .hcal-cell-add{
      position:absolute;top:6px;right:6px;width:20px;height:20px;border-radius:50%;
      border:1px dashed rgba(44,92,130,.25);background:transparent;color:var(--text-light,#8A7666);
      display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:13px;line-height:1;
    }
    .hcal-cell-add:hover{ border-color:var(--caramel,#B8763E);color:var(--caramel,#B8763E); }
    .hcal-cell.can-click{ cursor:pointer; }
  </style>
</head>
<body>

<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<?php require_once __DIR__ . '/Sidebar_HR.php'; ?>
<script src="../js/lucide-init.js"></script>

<div class="main">
  <div class="topbar">
    <div class="topbar-left"><h1>Holiday Calendar</h1></div>
    <div class="topbar-right"><div class="topbar-date"><?= date('F j, Y') ?></div></div>
  </div>

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>" style="display:none"><?= htmlspecialchars($mm) ?></div>
    <?php endif; ?>

    <?php if (!$holidays_table_ready): ?>
      <div class="msg-banner error">
        The <code>holidays</code> table doesn't exist in the database yet, so no holiday can be shown or saved.
        <?php if ($can_manage): ?>Ask your developer to run the <code>CREATE TABLE holidays (...)</code> statement noted at the top of this file, then reload this page.<?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="hcal-page">
      <div class="hcal-topbar">
        <div class="hcal-nav">
          <select id="hcalMonthSelect" onchange="hcalNavigate()">
            <?php
              $MONTH_NAMES = ['January','February','March','April','May','June','July','August','September','October','November','December'];
              foreach ($MONTH_NAMES as $mi => $mname):
            ?>
              <option value="<?= str_pad($mi + 1, 2, '0', STR_PAD_LEFT) ?>" <?= ($mi + 1) === $view_month ? 'selected' : '' ?>><?= $mname ?></option>
            <?php endforeach; ?>
          </select>
          <select id="hcalYearSelect" onchange="hcalNavigate()">
            <?php
              $year_lo = min($view_year - 5, (int) date('Y') - 5);
              $year_hi = max($view_year + 5, (int) date('Y') + 5);
              for ($y = $year_lo; $y <= $year_hi; $y++):
            ?>
              <option value="<?= $y ?>" <?= $y === $view_year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
          <a class="btn btn-ghost btn-sm" href="?month=<?= date('Y-m') ?>">Today</a>
        </div>
        <div class="hcal-legend">
          <span><span class="hcal-dot regular"></span> Regular Holiday</span>
          <span><span class="hcal-dot special"></span> Special Non-Working Day</span>
          <?php if ($can_manage && isset(PH_HOLIDAYS_BY_YEAR[$view_year])): ?>
            <button type="button" class="btn btn-ghost btn-sm" onclick="seedPhHolidays(<?= $view_year ?>)">Load PH Holidays (<?= $view_year ?>)</button>
          <?php endif; ?>
        </div>
      </div>

      <div class="hcal-grid">
        <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
          <div class="hcal-dow"><?= $d ?></div>
        <?php endforeach; ?>

        <?php for ($i = 1; $i < $first_dow; $i++): ?>
          <div class="hcal-cell empty"></div>
        <?php endfor; ?>

        <?php for ($day = 1; $day <= $days_in_month; $day++):
          $date_str = sprintf('%04d-%02d-%02d', $view_year, $view_month, $day);
          $h = $holidays_by_day[$day] ?? null;
          $is_today = $date_str === date('Y-m-d');
          $cls = 'hcal-cell';
          if ($is_today) $cls .= ' is-today';
          if ($h) $cls .= ' has-holiday' . ($h['type'] === 'special_non_working' ? ' special' : '');
          if ($can_manage) $cls .= ' can-click';
        ?>
        <div class="<?= $cls ?>" <?= $can_manage ? 'onclick="openHolidayModal(\'' . $date_str . '\', ' . ($h ? htmlspecialchars(json_encode($h), ENT_QUOTES) : 'null') . ')"' : '' ?>>
          <div class="hcal-cell-day"><?= $day ?></div>
          <?php if ($h): ?>
            <div class="hcal-holiday-name"><?= htmlspecialchars($h['name']) ?></div>
            <div class="hcal-holiday-type"><?= $TYPE_LABELS[$h['type']] ?? ucfirst($h['type']) ?></div>
          <?php elseif ($can_manage): ?>
            <button type="button" class="hcal-cell-add" onclick="event.stopPropagation(); openHolidayModal('<?= $date_str ?>', null)" title="Add holiday">+</button>
          <?php endif; ?>
        </div>
        <?php endfor; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($can_manage): ?>
<!-- ADD / EDIT HOLIDAY MODAL -->
<div class="modal-overlay-admin" id="holidayModal">
  <div class="modal-admin-box">
    <div class="modal-admin-header">
      <span id="holidayModalTitle">Add Holiday</span>
      <button type="button" class="modal-close-btn" onclick="closeHolidayModal()">✕</button>
    </div>
    <form method="POST" id="holidayForm">
      <input type="hidden" name="action" value="save_holiday">
      <input type="hidden" name="holiday_date" id="hDate">
      <div class="form-group-admin">
        <label>Date</label>
        <input type="text" id="hDateDisplay" disabled>
      </div>
      <div class="form-group-admin">
        <label>Holiday name</label>
        <input type="text" name="name" id="hName" placeholder="e.g. New Year's Day" required maxlength="150">
      </div>
      <div class="form-group-admin">
        <label>Type</label>
        <select name="type" id="hType">
          <option value="regular">Regular Holiday</option>
          <option value="special_non_working">Special Non-Working Day</option>
        </select>
      </div>
      <div class="modal-admin-actions">
        <button type="button" class="btn btn-danger" id="hDeleteBtn" style="display:none" onclick="deleteHoliday()">Remove</button>
        <button type="button" class="btn btn-ghost" onclick="closeHolidayModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>
<form method="POST" id="deleteHolidayForm" style="display:none">
  <input type="hidden" name="action" value="delete_holiday">
  <input type="hidden" name="holiday_id" id="hDeleteId">
</form>
<form method="POST" id="seedHolidayForm" style="display:none">
  <input type="hidden" name="action" value="seed_ph_holidays">
  <input type="hidden" name="year" id="seedYear">
</form>
<?php endif; ?>

<script>
  function hcalNavigate(){
    const m = document.getElementById('hcalMonthSelect').value;
    const y = document.getElementById('hcalYearSelect').value;
    window.location.href = '?month=' + y + '-' + m;
  }

<?php if ($can_manage): ?>
  function openHolidayModal(dateStr, holiday){
    document.getElementById('hDate').value = dateStr;
    const d = new Date(dateStr + 'T00:00:00');
    document.getElementById('hDateDisplay').value = d.toLocaleDateString('en-US', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
    document.getElementById('hName').value = holiday ? holiday.name : '';
    document.getElementById('hType').value = holiday ? holiday.type : 'regular';
    document.getElementById('holidayModalTitle').textContent = holiday ? 'Edit Holiday' : 'Add Holiday';
    document.getElementById('hDeleteBtn').style.display = holiday ? 'inline-flex' : 'none';
    document.getElementById('hDeleteBtn').dataset.id = holiday ? holiday.holiday_id : '';
    document.getElementById('holidayModal').style.display = 'flex';
  }
  function closeHolidayModal(){
    document.getElementById('holidayModal').style.display = 'none';
  }
  function deleteHoliday(){
    const id = document.getElementById('hDeleteBtn').dataset.id;
    if (!id) return;
    Swal.fire({
      title: 'Remove this holiday?',
      text: 'This date will go back to a normal working day.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, remove',
      confirmButtonColor: '#6B2E22',
      reverseButtons: true
    }).then((result) => {
      if (result.isConfirmed) {
        document.getElementById('hDeleteId').value = id;
        document.getElementById('deleteHolidayForm').submit();
      }
    });
  }
  document.getElementById('holidayModal').addEventListener('click', (e) => {
    if (e.target.id === 'holidayModal') closeHolidayModal();
  });

  function seedPhHolidays(year){
    Swal.fire({
      title: `Load PH holidays for ${year}?`,
      text: 'This fills in the standard regular holidays and special non-working days for the year. Dates you already customized will be left as is.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, load them',
      confirmButtonColor: '#628E90',
      reverseButtons: true
    }).then((result) => {
      if (result.isConfirmed) {
        document.getElementById('seedYear').value = year;
        document.getElementById('seedHolidayForm').submit();
      }
    });
  }
<?php endif; ?>

<?php if ($msg): ?>
  document.addEventListener('DOMContentLoaded', () => {
    Swal.fire({
      title: <?= $mt === 'success' ? "'Done!'" : "'Oops!'" ?>,
      text: <?= json_encode($mm, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      icon: <?= $mt === 'success' ? "'success'" : "'error'" ?>,
      confirmButtonColor: '#628E90',
      timer: <?= $mt === 'success' ? '2200' : 'undefined' ?>,
      timerProgressBar: <?= $mt === 'success' ? 'true' : 'false' ?>
    });
  });
<?php endif; ?>
</script>
<script src="../js/theme-toggle.js"></script>
</body>
</html>