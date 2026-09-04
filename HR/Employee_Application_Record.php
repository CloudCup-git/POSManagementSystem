<?php
// ── EMPLOYEE APPLICATION RECORD ─────────────────────────────────────────
// Read-only view of everything an employee submitted on their job
// application (address, availability, skills, experience, resume, gov
// clearances, etc.) — the info that gets filled out at Apply and doesn't
// otherwise show up anywhere in Employee Records. Linked via
// job_applications.hired_employee_id, which is set once, at hire time,
// in Applications_Page.php.
require_once __DIR__ . '/../admin/Permissions.php';
require_hr_login();
require_permission('manage_employees'); // same gate as Employee Records
require_once __DIR__ . '/../includes/DB_Connect.php';

$active_page = 'hr_records';
$employee_id = (int)($_GET['id'] ?? 0);

if (!$conn || $employee_id <= 0) {
    http_response_code(404);
    exit('Not found.');
}

$emp_stmt = mysqli_prepare($conn, "SELECT full_name FROM users WHERE user_id = ?");
mysqli_stmt_bind_param($emp_stmt, 'i', $employee_id);
mysqli_stmt_execute($emp_stmt);
$employee = mysqli_fetch_assoc(mysqli_stmt_get_result($emp_stmt));

if (!$employee) {
    http_response_code(404);
    exit('Employee not found.');
}

// Same self-healing column as Applications_Page.php — added here too so
// this page doesn't depend on Applications_Page.php having run first.
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM job_applications LIKE 'hired_employee_id'");
if ($col_check && mysqli_num_rows($col_check) === 0) {
    mysqli_query($conn, "ALTER TABLE job_applications ADD COLUMN hired_employee_id INT NULL");
}

$app = null;
$app_stmt = mysqli_prepare($conn,
    "SELECT ja.*, jp.title AS job_title
     FROM job_applications ja
     LEFT JOIN job_postings jp ON jp.job_id = ja.job_id
     WHERE ja.hired_employee_id = ?
     ORDER BY ja.submitted_at DESC LIMIT 1");
if ($app_stmt) {
    mysqli_stmt_bind_param($app_stmt, 'i', $employee_id);
    mysqli_stmt_execute($app_stmt);
    $app = mysqli_fetch_assoc(mysqli_stmt_get_result($app_stmt));
}

function field($label, $value) {
    echo '<div class="form-group-admin"><label>' . htmlspecialchars($label) . '</label>';
    echo '<div class="record-value">' . ($value !== '' && $value !== null ? nl2br(htmlspecialchars($value)) : '<span class="record-empty">—</span>') . '</div></div>';
}
function chips($label, $csv) {
    $items = array_filter(array_map('trim', explode(',', (string)$csv)));
    echo '<div class="form-group-admin"><label>' . htmlspecialchars($label) . '</label><div>';
    if ($items) {
        foreach ($items as $item) echo '<span class="record-chip">' . htmlspecialchars($item) . '</span>';
    } else {
        echo '<span class="record-empty">—</span>';
    }
    echo '</div></div>';
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
  <title>Employee Record — <?= htmlspecialchars($employee['full_name']) ?> — Cloud Cup HR</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/hr_module.css"/>
  <style>
    .record-section{ margin-bottom:28px; }
    .record-section h3{ font-size:14px;color:var(--caramel);text-transform:uppercase;letter-spacing:.03em;margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid rgba(44,92,130,.1); }
    .record-value{ font-size:14px;color:var(--text);padding:9px 0; }
    .record-empty{ color:var(--text-light); }
    .record-chip{ display:inline-block;background:rgba(59,130,192,.08);color:var(--text);border:1px solid rgba(44,92,130,.15);border-radius:999px;padding:5px 12px;font-size:12.5px;font-weight:500;margin:0 6px 6px 0; }
    .record-header{ display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:24px; }
    .record-header h2{ font-family:'Fraunces',serif;margin:0 0 4px; }
    .record-header .sub{ color:var(--text-light);font-size:13px; }
  </style>
</head>
<body>

<?php require_once '../HR/Sidebar_HR.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <h1>Employee Record</h1>
    </div>
    <div class="topbar-right"><a class="btn btn-ghost btn-sm" href="Employee_Records_Page.php" style="text-decoration:none;display:inline-block;">← Back to Employee Records</a></div>
  </div>

  <div class="content">
    <?php if (!$app): ?>
      <div class="widget">
        <div class="record-header">
          <div><h2><?= htmlspecialchars($employee['full_name']) ?></h2><div class="sub">No application on file</div></div>
        </div>
        <p class="record-value">This employee's account wasn't created through the Careers application pipeline, so there's no submitted application to show here.</p>
      </div>
    <?php else: ?>
      <div class="widget">
        <div class="record-header">
          <div>
            <h2><?= htmlspecialchars($employee['full_name']) ?></h2>
            <div class="sub">Applied for <?= htmlspecialchars($app['job_title'] ?? 'a position') ?> · Submitted <?= date('M j, Y', strtotime($app['submitted_at'])) ?></div>
          </div>
          <?php if (!empty($app['resume_path'])): ?>
            <a class="btn btn-primary btn-sm" href="View_Resume.php?id=<?= (int)$app['application_id'] ?>">Download Resume</a>
          <?php endif; ?>
        </div>

        <div class="record-section">
          <h3>Basic &amp; Contact Information</h3>
          <div class="form-row">
            <?php field('Email Address', $app['email']); ?>
            <?php field('Mobile Number', $app['phone']); ?>
          </div>
          <?php field('Complete Address', $app['address']); ?>
        </div>

        <div class="record-section">
          <h3>Schedule Availability</h3>
          <?php chips('Shifts They Can Work', $app['shift_preference']); ?>
          <?php chips('Weekly Availability', $app['availability_days']); ?>
        </div>

        <div class="record-section">
          <h3>Applicant Status &amp; Education</h3>
          <div class="form-row">
            <?php field('Current Status', $app['applicant_status'] ? ucfirst($app['applicant_status']) : ''); ?>
            <?php field('School', $app['school_name']); ?>
          </div>
          <?php field('Course', $app['course']); ?>
        </div>

        <div class="record-section">
          <h3>Cafe Experience &amp; Core Skills</h3>
          <?php field('Prior Barista Experience', $app['barista_experience'] === 'yes' ? 'Yes' : ($app['barista_experience'] === 'no' ? 'No' : '')); ?>
          <?php chips('Skills', $app['skills']); ?>
          <?php field('Cover Message', $app['cover_message']); ?>
        </div>

        <div class="record-section">
          <h3>Legal &amp; Pre-Employment Documents</h3>
          <?php chips('Government Clearances Confirmed', $app['gov_clearances']); ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="../js/theme-toggle.js"></script>
</body>
</html>
