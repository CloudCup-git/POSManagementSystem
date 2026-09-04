<?php
// ── EMPLOYEE SIDEBAR ───────────────────────────────────────────────
// Used by employee-facing pages (Sales_Processing_Page, Leave_Management_Page)
// for staff who don't have HR/management permissions. Kept separate from
// Sidebar_HR.php and Sidebar_Admin.php so rank-and-file employees only ever
// see the menu items relevant to them (Sales/POS, Leave Request), and so the
// sidebar looks and behaves the same no matter which page they're on.
require_once __DIR__ . '/../admin/Permissions.php';

$_emp_name     = $_SESSION['full_name'] ?? 'User';
$_emp_initials = strtoupper(substr($_emp_name, 0, 1));
$_emp_active   = $active_page ?? '';

function _emp_nav(string $href, string $icon, string $label, string $key, string $active): string {
    $cls = ($active === $key) ? 'nav-item active' : 'nav-item';
    return '<a href="' . $href . '" class="' . $cls . '" data-label="' . htmlspecialchars($label) . '"><span class="icon"><i data-lucide="' . $icon . '"></i></span><span class="nav-label"> ' . $label . '</span></a>';
}
?>
<style>
/* Responsive fix: keep the logout / user card reachable no matter the
   display/browser zoom level or nav content length. The sidebar becomes
   a column with a scrollable middle section instead of letting content
   overflow the viewport and push the footer out of reach. */
.sidebar{display:flex!important;flex-direction:column!important;height:100vh!important;height:100dvh!important;max-height:100vh!important;max-height:100dvh!important;overflow:hidden!important;}
.sidebar-logo-row,.sidebar-logo{flex-shrink:0;}
.sidebar-nav-scroll{flex:1 1 auto;overflow-y:auto;overflow-x:hidden;min-height:0;-webkit-overflow-scrolling:touch;}
.sidebar-nav-scroll::-webkit-scrollbar{width:6px;}
.sidebar-nav-scroll::-webkit-scrollbar-thumb{background:rgba(255,255,255,.18);border-radius:3px;}
.sidebar-footer{flex-shrink:0;}
/* Header row (avatar chip + wordmark + collapse toggle), matching the admin sidebar */
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
        <span class="sidebar-logo-textwrap" style="cursor:pointer" onclick="window.location.href='../staff/Sales_Processing_Page.php'">
          <span class="sidebar-logo-title">Cloud Cup</span>
          <span class="sidebar-logo-subtitle">Shop Console</span>
        </span>
      </span>
    </div>
    <button type="button" class="sidebar-toggle-btn-inner" onclick="collapseSidebar()" title="Collapse sidebar">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
    </button>
  </div>

  <div class="sidebar-nav-scroll">
  <?php if (in_array($_emp_active, ['emp_sales', 'emp_history'], true)): ?>
  <div class="sidebar-section">
    <div class="sidebar-section-label">My Station</div>
    <?= _emp_nav('../staff/Sales_Processing_Page.php', 'shopping-cart', 'Sales / POS', 'emp_sales', $_emp_active) ?>
    <?= _emp_nav('../staff/Transaction_History_Page.php', 'history', 'Transaction History', 'emp_history', $_emp_active) ?>
  </div>
  <?php elseif (in_array($_emp_active, ['proc-stock', 'proc-receiving'], true)): ?>
  <div class="sidebar-section">
    <div class="sidebar-section-label">Procurement</div>
    <?= _emp_nav('../staff/Branch_Stock_Page.php', 'package', 'Branch Stock', 'proc-stock', $_emp_active) ?>
    <?= _emp_nav('../staff/Receiving_Page.php', 'truck', 'Receiving', 'proc-receiving', $_emp_active) ?>
  </div>
  <?php else: ?>
  <div class="sidebar-section">
    <div class="sidebar-section-label">My Account</div>
    <?= _emp_nav('../HR/Employee_Accounts_Page.php',     'user',          'My Account',    'hr_account',    $_emp_active) ?>
    <?= _emp_nav('../HR/Attendance_Page.php',           'clock',          'Attendance',    'hr_attendance', $_emp_active) ?>
    <?= _emp_nav('../HR/Schedule_Page.php',              'calendar',      'My Schedule',   'hr_schedule',   $_emp_active) ?>
    <?= _emp_nav('../HR/Leave_Management_Page.php',      'clipboard-check','Leave Request','hr_leave',      $_emp_active) ?>
  </div>
  <?php endif; ?>
  </div>

  <div class="sidebar-footer">
    <div class="user-card" style="position:relative">
      <a href="../HR/Employee_Accounts_Page.php" class="user-avatar" title="My Account" style="text-decoration:none"><?= htmlspecialchars($_emp_initials) ?></a>
      <a href="../HR/Employee_Accounts_Page.php" class="user-info" title="My Account" style="text-decoration:none">
        <strong><?= htmlspecialchars($_emp_name) ?></strong>
        <span>Employee</span>
      </a>
      <a id="staff-logout-btn" href="../auth/Logout_Page.php" class="logout-btn" title="Logout" style="text-decoration:none"><i data-lucide="log-out"></i></a>
    </div>
  </div>
</aside>
<script src="../js/sidebar-scroll-persist.js"></script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Sidebar collapse/expand + avatar → coffee cup morph animation, matching
// the admin sidebar (js/sidebar_admin.js). This sidebar reuses the same
// avatar chip markup (#sidebarAvatar / .cc-avatar-cup / .cc-steamline) as
// admin's redesign, so it needs the same JS driving it.
//
// The collapse toggle button (.sidebar-toggle-btn-inner, in the logo row
// above) lives here in the shared sidebar rather than being copy-pasted
// into every employee page's topbar. It used to be duplicated per-page
// and wired to the legacy toggleSidebar() in js/sidebar-toggle.js, which
// targets older markup (.sidebar-logo-cup) this sidebar doesn't have —
// that's why collapsing used to just stall and snap shut with no
// animation. Centralizing it here means every page that includes this
// sidebar gets the same, correctly-animated collapse behavior for free.
function collapseSidebar() {
  document.body.classList.add('sidebar-hidden');
  try { localStorage.setItem('cc_sidebar_hidden', '1'); } catch (e) { /* storage unavailable — collapse still works */ }
  brewSidebarLogo();
}

// Clicking the avatar chip only re-opens a collapsed sidebar — it never
// navigates. The "Cloud Cup" wordmark next to it is the home link.
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
// immediate navigation. Lives here (in the shared sidebar) so every
// employee-facing page that includes this sidebar gets the same prompt.
document.getElementById('staff-logout-btn').addEventListener('click', function (e) {
  e.preventDefault();
  var href = this.getAttribute('href');
  Swal.fire({
    title: 'Log out?',
    text: "You'll need to sign in again to access your account.",
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Yes, log out',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#628e90',
    cancelButtonColor: '#6b6156',
    reverseButtons: true
  }).then(function (result) {
    if (result.isConfirmed) window.location.href = href;
  });
});
</script>