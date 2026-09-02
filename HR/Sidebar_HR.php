<?php
// ── HR MODULE SIDEBAR ─────────────────────────────────────────────
// Used ONLY by the HR module pages (HR_Dashboard, Employee_Accounts,
// Employee_Records, Attendance, Leave_Management, Payroll). This is
// intentionally a separate file from Sidebar_Admin.php — the HR
// module is its own standalone section reached only through
// HR_Login.php, and never appears in the Store Admin (POS) sidebar.
require_once __DIR__ . '/../admin/Permissions.php';

$_hr_name     = $_SESSION['full_name'] ?? 'User';
$_hr_initials = strtoupper(substr($_hr_name, 0, 1));
$_hr_role     = current_role();
$_hr_active   = $active_page ?? '';

$_leave_badge = 0;
if (isset($conn) && has_permission('manage_leave_requests')) {
  $lr = mysqli_query($conn, "SHOW TABLES LIKE 'leave_requests'");
  if ($lr && mysqli_num_rows($lr) > 0) {
    $cr = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM leave_requests WHERE status = 'pending'");
    if ($cr) $_leave_badge = (int)(mysqli_fetch_assoc($cr)['cnt'] ?? 0);
  }
}

$_jobs_badge = 0;
if (isset($conn) && has_permission('manage_job_postings')) {
  $jt = mysqli_query($conn, "SHOW TABLES LIKE 'job_applications'");
  if ($jt && mysqli_num_rows($jt) > 0) {
    $jc = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM job_applications WHERE status = 'new'");
    if ($jc) $_jobs_badge = (int)(mysqli_fetch_assoc($jc)['cnt'] ?? 0);
  }
}

$_hr_svg = [
  'dashboard'  => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>',
  'accounts'   => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
  'records'    => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
  'attendance' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
  'leave'      => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="m9 16 2 2 4-4"/></svg>',
  'schedule'   => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="14" x2="8" y2="14"/><line x1="12" y1="14" x2="12" y2="14"/><line x1="16" y1="14" x2="16" y2="14"/><line x1="8" y1="18" x2="8" y2="18"/><line x1="12" y1="18" x2="12" y2="18"/></svg>',
  'payroll'    => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>',
  'jobs'       => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
  'switch'     => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>',
];

function _hr_nav(string $href, string $icon, string $label, string $key, string $active, int $badge = 0): string
{
  global $_hr_svg;
  $cls = ($active === $key) ? 'nav-item active' : 'nav-item';
  $bdg = $badge > 0 ? '<span class="badge">' . $badge . '</span>' : '';
  $svg = $_hr_svg[$icon] ?? '';
  return '<a href="' . $href . '" class="' . $cls . '" data-label="' . htmlspecialchars($label) . '"><span class="icon">' . $svg . '</span><span class="nav-label"> ' . $label . ' ' . $bdg . '</span></a>';
}
?>
<aside class="sidebar">
  <div class="sidebar-logo-row">
    <div class="sidebar-logo" style="cursor:pointer" onclick="window.location.href='../HR/HR_Dashboard.php'">
      <span class="sidebar-logo-text">Cloud<span>Cup</span>
      <div style="font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:rgba(255,255,255,.35);margin-top:2px;font-family:'Inter',sans-serif;font-weight:700">HR Portal</div>
      </span>
      <span class="sidebar-logo-cup" aria-hidden="true">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <g class="cc-steam">
            <path d="M9 1.5c0 1-1 1-1 2s1 1 1 2" stroke="rgba(255,255,255,.55)" stroke-width="1.3" stroke-linecap="round"/>
            <path d="M13 1.5c0 1-1 1-1 2s1 1 1 2" stroke="rgba(255,255,255,.55)" stroke-width="1.3" stroke-linecap="round"/>
          </g>
          <rect class="cc-cup-fill" x="5.2" y="9.2" width="10.6" height="8.6" rx="1.2"/>
          <path d="M4 8h13v7a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4V8z" stroke="var(--gold)" stroke-width="1.6" fill="none"/>
          <path d="M17 10.2h1.4a2.3 2.3 0 0 1 0 4.6H17" stroke="var(--gold)" stroke-width="1.6" fill="none"/>
          <line x1="6" y1="19" x2="12" y2="19" stroke="var(--gold)" stroke-width="1.4" stroke-linecap="round" opacity=".5"/>
        </svg>
      </span>
    </div>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">Overview</div>
    <?= _hr_nav('../HR/HR_Dashboard.php', 'dashboard', 'Dashboard', 'hr_dashboard', $_hr_active) ?>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">People</div>
    <?php if (has_permission('manage_accounts')): ?>
      <?= _hr_nav('../HR/Employee_Accounts_Page.php', 'accounts', 'Accounts &amp; Roles', 'hr_accounts', $_hr_active) ?>
    <?php endif; ?>
    <?php if (has_permission('manage_employees')): ?>
      <?= _hr_nav('../HR/Employee_Records_Page.php', 'records', 'Employee Records', 'hr_records', $_hr_active) ?>
    <?php endif; ?>
    <?= _hr_nav('../HR/Attendance_Page.php', 'attendance', 'Attendance', 'hr_attendance', $_hr_active) ?>
    <?= _hr_nav('../HR/Schedule_Page.php', 'schedule', 'Schedule', 'hr_schedule', $_hr_active) ?>
    <?= _hr_nav('../HR/Leave_Management_Page.php', 'leave', 'Leave Management', 'hr_leave', $_hr_active, $_leave_badge) ?>
    <?php if (has_permission('manage_job_postings')): ?>
      <?= _hr_nav('../HR/Job_Postings_Page.php', 'jobs', 'Job Postings', 'hr_job_postings', $_hr_active) ?>
      <?= _hr_nav('../HR/Applications_Page.php', 'records', 'Applicants', 'hr_applications', $_hr_active, $_jobs_badge) ?>
    <?php endif; ?>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">Pay</div>
    <?php if (has_permission('view_all_payroll') || has_permission('view_own_payroll')): ?>
      <?= _hr_nav('../HR/Payroll_Page.php', 'payroll', 'Payroll', 'hr_payroll', $_hr_active) ?>
    <?php endif; ?>
  </div>

  <?php if ($_hr_role === 'admin'): ?>
    <div class="sidebar-section">
      <a href="../admin/Admin_Dashboard.php" class="nav-item" data-label="Store Admin">
        <span class="icon"><?= $_hr_svg['switch'] ?></span>
        <span class="nav-label"> ← Store Admin</span>
      </a>
    </div>
  <?php endif; ?>

  <div class="sidebar-footer">
    <div class="user-card">
      <div class="user-avatar"><?= htmlspecialchars($_hr_initials) ?></div>
      <div class="user-info">
        <strong><?= htmlspecialchars($_hr_name) ?></strong>
        <span><?= htmlspecialchars(role_label($_hr_role)) ?></span>
      </div>
      <a href="../auth/Logout_Page.php" class="logout-btn" title="Logout" style="text-decoration:none"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
          <polyline points="16 17 21 12 16 7" />
          <line x1="21" y1="12" x2="9" y2="12" />
        </svg></a>
    </div>
  </div>
</aside>
<script>
  if (typeof Swal === 'undefined') {
    document.write('<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"><\/script>');
  }
</script>
<script>
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('a.logout-btn, a[href="../auth/Logout_Page.php"], a[href$="/../auth/Logout_Page.php"]').forEach(function(link) {
      link.addEventListener('click', function(e) {
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
        }).then(function(result) {
          if (result.isConfirmed) window.location.href = href;
        });
      });
    });
  });
</script>