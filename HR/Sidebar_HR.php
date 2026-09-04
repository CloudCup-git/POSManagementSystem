<?php
// ── HR MODULE SIDEBAR ─────────────────────────────────────────────
// Used ONLY by the HR module pages (HR_Dashboard, Employee_Accounts,
// Employee_Records, Attendance, Leave_Management, Payroll). This is
// intentionally a separate file from Sidebar_Admin.php — the HR
// module is its own standalone section reached only through
// HR_Login.php, and never appears in the Store Admin (POS) sidebar.
//
// Structure/behavior (collapse-to-rail toggle, avatar → cup morph,
// scrollable nav with a pinned footer) mirrors Sidebar_Employee.php
// so every module's sidebar looks and behaves the same way. Only the
// nav content below (Overview/People/Pay sections, icons, permission
// checks, badges) is HR-specific.
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
<style>
/* Responsive fix: keep the logout / user card reachable no matter the
   display/browser zoom level or nav content length. The sidebar becomes
   a column with a scrollable middle section instead of letting content
   overflow the viewport and push the footer out of reach. Mirrors
   Sidebar_Employee.php so every module's sidebar behaves the same. */
.sidebar{display:flex!important;flex-direction:column!important;height:100vh!important;height:100dvh!important;max-height:100vh!important;max-height:100dvh!important;overflow:hidden!important;}
.sidebar-logo-row,.sidebar-logo{flex-shrink:0;}
.sidebar-nav-scroll{flex:1 1 auto;overflow-y:auto;overflow-x:hidden;min-height:0;-webkit-overflow-scrolling:touch;}
.sidebar-nav-scroll::-webkit-scrollbar{width:6px;}
.sidebar-nav-scroll::-webkit-scrollbar-thumb{background:rgba(255,255,255,.18);border-radius:3px;}
.sidebar-footer{flex-shrink:0;}
/* Header row (avatar chip + wordmark + collapse toggle), matching the staff/admin sidebar */
.sidebar-logo-row{display:flex;align-items:center;justify-content:space-between;gap:6px;padding-right:18px;}
.sidebar-toggle-btn-inner{width:32px;height:32px;border-radius:9px;flex-shrink:0;background:transparent;border:none;color:rgba(255,255,255,.7);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:color .15s;}
.sidebar-toggle-btn-inner:hover{color:#fff;}
body.sidebar-hidden .sidebar-toggle-btn-inner{display:none;}
body.sidebar-hidden .sidebar-logo-row{justify-content:center;padding-right:0;}
body.sidebar-hidden .sidebar-logo-brand{margin-left:6px;}
</style>
<aside class="sidebar">
  <div class="sidebar-logo-row">
    <div class="sidebar-logo">
      <span class="sidebar-logo-brand">
        <span class="sidebar-avatar" id="sidebarAvatar" title="Cloud Cup" onclick="handleAvatarClick()">
          <span class="cc-avatar-label">CC</span>
          <span class="cc-avatar-cup" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path class="cc-steamline s1" d="M9 2c0 1.2-1 1.4-1 2.6S9 6.2 9 7.4"/>
              <path class="cc-steamline s2" d="M12.5 2c0 1.2-1 1.4-1 2.6s1 1.6 1 2.8"/>
              <path class="cc-steamline s3" d="M16 2c0 1.2-1 1.4-1 2.6s1 1.6 1 2.8"/>
              <path d="M4 10h13v4a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5v-4Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
              <path d="M17 11.5h1.5a2 2 0 0 1 0 4H17" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
              <path d="M3.5 21.5h14" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
            </svg>
          </span>
        </span>
        <span class="sidebar-logo-textwrap" style="cursor:pointer" onclick="window.location.href='../HR/HR_Dashboard.php'">
          <span class="sidebar-logo-title">Cloud Cup</span>
          <span class="sidebar-logo-subtitle">HR Portal</span>
        </span>
      </span>
    </div>
    <button type="button" class="sidebar-toggle-btn-inner" onclick="collapseSidebar()" title="Collapse sidebar">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
    </button>
  </div>

  <div class="sidebar-nav-scroll">
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
  </div>

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
<script src="../js/sidebar-scroll-persist.js"></script>
<script>
  if (typeof Swal === 'undefined') {
    document.write('<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"><\/script>');
  }
</script>
<script>
// Sidebar collapse/expand + avatar → coffee cup morph animation, matching
// Sidebar_Employee.php (and the admin sidebar's js/sidebar_admin.js) so
// this behaves identically no matter which module the user is in.
//
// This replaces the old per-page ☰ button + js/sidebar-toggle.js pairing
// that used to live in each HR page's topbar: that legacy script targeted
// older markup (.sidebar-logo-cup) this sidebar no longer has, so it
// would stall and snap shut with no animation. Centralizing the working
// toggle here — as a single button in the sidebar header — means every
// HR page gets the same, correctly-animated collapse behavior for free.
function collapseSidebar() {
  document.body.classList.add('sidebar-hidden');
  try { localStorage.setItem('cc_sidebar_hidden', '1'); } catch (e) { /* storage unavailable — collapse still works */ }
  brewSidebarLogo();
}

// Clicking the avatar chip only re-opens a collapsed sidebar — it never
// navigates. The "Cloud Cup" wordmark next to it is the dashboard link.
function handleAvatarClick() {
  if (document.body.classList.contains('sidebar-hidden')) {
    document.body.classList.remove('sidebar-hidden');
    try { localStorage.setItem('cc_sidebar_hidden', '0'); } catch (e) { /* storage unavailable — expand still works */ }
  }
}

function brewSidebarLogo() {
  var mark = document.getElementById('sidebarAvatar');
  if (!mark || mark.classList.contains('brewing')) return; // let a running animation finish
  mark.classList.add('brewing');
  setTimeout(function () { mark.classList.add('show-cup'); }, 310);
  mark.addEventListener('animationend', function done() {
    mark.classList.remove('brewing');
    mark.removeEventListener('animationend', done);
  });
}

// If the sidebar was already collapsed on a previous visit, show the cup
// immediately (no flip) so the logo matches the collapsed rail on load.
(function () {
  if (document.body.classList.contains('sidebar-hidden')) {
    var mark = document.getElementById('sidebarAvatar');
    if (mark) mark.classList.add('show-cup');
  }
})();

// Confirm before logging out, via a SweetAlert dialog rather than an
// immediate navigation.
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
