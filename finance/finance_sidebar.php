<?php
require_once __DIR__ . '/../../admin/Permissions.php';

$_fin_name     = $_SESSION['full_name'] ?? 'User';
$_fin_initials = strtoupper(substr($_fin_name, 0, 1));
$_fin_role     = current_role();
$_fin_active   = $activePage ?? '';

$_fin_svg = [
  'dashboard'    => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>',
  'revenue'      => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
  'cogs'         => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>',
  'opex'         => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
  'cashflow'     => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>',
  'balance'      => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18"/><path d="M3 7h4l-2 5a2.5 2.5 0 0 0 5 0L8 7"/><path d="M17 7h4l-2 5a2.5 2.5 0 0 0 5 0L19 7"/><path d="M3 7h18"/><path d="M6 21h12"/></svg>',
  'startup'      => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"/><line x1="9" y1="6" x2="9" y2="6"/><line x1="15" y1="6" x2="15" y2="6"/><line x1="9" y1="10" x2="9" y2="10"/><line x1="15" y1="10" x2="15" y2="10"/><line x1="9" y1="14" x2="9" y2="14"/><line x1="15" y1="14" x2="15" y2="14"/><path d="M9 22v-4h6v4"/></svg>',
  'transactions' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
  'salary'       => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/><path d="M12 12v.01"/></svg>',
  'budgeting'    => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/><path d="M12 3v3"/></svg>',
  'payroll'      => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>',
  'approval'     => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
  'processing'   => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>',
  'switch'       => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>',
];

function _fin_nav(string $href, string $icon, string $label, string $key, string $active, string $rangeQuery, int $badge = 0): string
{
  global $_fin_svg;
  $cls   = ($active === $key) ? 'nav-item active' : 'nav-item';
  $svg   = $_fin_svg[$icon] ?? '';
  $href  = $href . '?' . htmlspecialchars($rangeQuery);
  $badgeHtml = $badge > 0 ? '<span class="nav-badge">' . ($badge > 99 ? '99+' : $badge) . '</span>' : '';
  return '<a href="' . $href . '" class="' . $cls . '" data-label="' . htmlspecialchars($label) . '"><span class="icon">' . $svg . '</span><span class="nav-label"> ' . $label . $badgeHtml . '</span></a>';
}

// Pending-restock badge shown on the "Inventory Restocks" nav item, same
// idea as the numbered badges elsewhere in CloudCup (e.g. HR's Leave
// Management) — count of restock requests still waiting on Finance.
// $pdo may not be set on every page that includes this sidebar, so this
// is defensive: no PDO (or the table isn't there yet) just means no badge.
$_fin_restock_badge = 0;
if (isset($pdo) && $pdo instanceof PDO) {
  try {
    $_fin_restock_badge = (int) $pdo->query("SELECT COUNT(*) FROM restock_requests WHERE status = 'pending'")->fetchColumn();
  } catch (Throwable $e) {
    $_fin_restock_badge = 0;
  }
}
?>
<style>
/* Responsive fix: keep the logout / user card reachable no matter the
   display/browser zoom level or nav content length. The sidebar becomes
   a column with a scrollable middle section instead of letting content
   overflow the viewport and push the footer out of reach. Mirrors
   Sidebar_Employee.php / Sidebar_HR.php so every module's sidebar
   behaves the same way. */
.sidebar{display:flex!important;flex-direction:column!important;height:100vh!important;height:100dvh!important;max-height:100vh!important;max-height:100dvh!important;overflow:hidden!important;}
.sidebar-logo-row,.sidebar-logo{flex-shrink:0;}
.sidebar-nav-scroll{flex:1 1 auto;overflow-y:auto;overflow-x:hidden;min-height:0;-webkit-overflow-scrolling:touch;}
.sidebar-nav-scroll::-webkit-scrollbar{width:6px;}
.sidebar-nav-scroll::-webkit-scrollbar-thumb{background:rgba(255,255,255,.18);border-radius:3px;}
.sidebar-footer{flex-shrink:0;}
/* Header row (avatar chip + wordmark + collapse toggle), matching the staff/HR/admin sidebar */
.sidebar-logo-row{display:flex;align-items:center;justify-content:space-between;gap:6px;padding-right:18px;}
.sidebar-toggle-btn-inner{width:32px;height:32px;border-radius:9px;flex-shrink:0;background:transparent;border:none;color:rgba(255,255,255,.7);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:color .15s;}
.sidebar-toggle-btn-inner:hover{color:#fff;}
body.sidebar-hidden .sidebar-toggle-btn-inner{display:none;}
body.sidebar-hidden .sidebar-logo-row{justify-content:center;padding-right:0;}
body.sidebar-hidden .sidebar-logo-brand{margin-left:6px;}
.nav-badge{
  display:inline-flex;align-items:center;justify-content:center;
  min-width:18px;height:18px;padding:0 5px;margin-left:6px;
  border-radius:20px;background:#b8703f;color:#fff;
  font-size:10.5px;font-weight:700;line-height:1;vertical-align:middle;
}
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
        <span class="sidebar-logo-textwrap" style="cursor:pointer" onclick="window.location.href='finance.php'">
          <span class="sidebar-logo-title">Cloud Cup</span>
          <span class="sidebar-logo-subtitle">Finance Portal</span>
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
    <?= _fin_nav('finance.php', 'dashboard', 'Dashboard', 'dashboard', $_fin_active, $rangeQuery) ?>
  </div>

  <?php
    $_fin_reports_keys = ['revenue', 'opex', 'cashflow', 'balance', 'startup', 'transactions', 'salary_budget', 'budgeting'];
    $_fin_reports_open = in_array($_fin_active, $_fin_reports_keys, true);
  ?>
  <div class="sidebar-section">
    <div class="sidebar-section-label sidebar-section-toggle" data-section="fin-reports" role="button" tabindex="0"
         aria-expanded="<?= $_fin_reports_open ? 'true' : 'false' ?>"
         onclick="toggleSidebarSection('fin-reports')"
         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleSidebarSection('fin-reports')}">
      <span>Reports</span>
      <svg class="sidebar-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
    </div>
    <div class="sidebar-section-items<?= $_fin_reports_open ? '' : ' collapsed' ?>" id="fin-reports">
      <div>
    <?= _fin_nav('finance_revenue.php',      'revenue',      'Revenue',                'revenue',      $_fin_active, $rangeQuery) ?>
    <?= _fin_nav('finance_opex.php',         'opex',         'Operating Expenses',     'opex',         $_fin_active, $rangeQuery) ?>
    <?= _fin_nav('finance_cashflow.php',     'cashflow',     'Cash Flow',              'cashflow',     $_fin_active, $rangeQuery) ?>
    <?= _fin_nav('finance_balance.php',      'balance',      'Balance Sheet',          'balance',      $_fin_active, $rangeQuery) ?>
    <?= _fin_nav('finance_startup.php',      'startup',      'Startup & Capital',      'startup',      $_fin_active, $rangeQuery) ?>
    <?= _fin_nav('finance_transactions.php', 'transactions', 'Transactions',           'transactions', $_fin_active, $rangeQuery) ?>
    <?= _fin_nav('finance_salary_budget.php', 'salary',   'Salary Budget',       'salary_budget', $_fin_active, $rangeQuery) ?>
    <?= _fin_nav('finance_budgeting.php',    'budgeting',    'Budgeting & Forecasting', 'budgeting',    $_fin_active, $rangeQuery) ?>
      </div>
    </div>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">Pay</div>
    <?= _fin_nav('finance_payroll.php', 'payroll', 'Payroll & Loans', 'payroll', $_fin_active, $rangeQuery) ?>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">Procurement</div>
    <?= _fin_nav('Procurement_Hub.php', 'cogs', 'Procurement Hub', 'proc-hub', $_fin_active, $rangeQuery) ?>
  </div>

  <?php
    $_fin_cfm_keys = ['cfm_approval', 'cfm_processing', 'cfm_restock', 'history'];
    $_fin_cfm_open = in_array($_fin_active, $_fin_cfm_keys, true);
  ?>
  <div class="sidebar-section">
    <div class="sidebar-section-label sidebar-section-toggle" data-section="fin-cfm" role="button" tabindex="0"
         aria-expanded="<?= $_fin_cfm_open ? 'true' : 'false' ?>"
         onclick="toggleSidebarSection('fin-cfm')"
         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleSidebarSection('fin-cfm')}">
      <span>Cash Flow Management</span>
      <svg class="sidebar-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
    </div>
    <div class="sidebar-section-items<?= $_fin_cfm_open ? '' : ' collapsed' ?>" id="fin-cfm">
      <div>
    <?= _fin_nav('finance_payroll_approval.php',   'approval',   'Payroll and Employee Loans', 'cfm_approval',   $_fin_active, $rangeQuery) ?>
    <?= _fin_nav('finance_payroll_processing.php', 'processing', 'Payroll Processing',         'cfm_processing', $_fin_active, $rangeQuery) ?>
    <?= _fin_nav('finance_restock_approvals.php',  'cogs',       'Inventory Restocks',         'cfm_restock',    $_fin_active, $rangeQuery, $_fin_restock_badge) ?>
    <?= _fin_nav('finance_activity_log.php',  'transactions', 'Activity History',          'history',        $_fin_active, $rangeQuery) ?>
      </div>
    </div>
  </div>

  <?php if (in_array($_fin_role, ['admin', 'manager'], true)): ?>
    <div class="sidebar-section">
      <a href="../admin/Admin_Page.php" class="nav-item" data-label="Back to POS">
        <span class="icon"><?= $_fin_svg['switch'] ?></span>
        <span class="nav-label"> ← Back to POS</span>
      </a>
    </div>
  <?php endif; ?>
  </div>

  <div class="sidebar-footer">
    <div class="user-card" style="position:relative">
      <a href="../HR/Employee_Accounts_Page.php" class="user-avatar" title="My Account" style="text-decoration:none"><?= htmlspecialchars($_fin_initials) ?></a>
      <a href="../HR/Employee_Accounts_Page.php" class="user-info" title="My Account" style="text-decoration:none">
        <strong><?= htmlspecialchars($_fin_name) ?></strong>
        <span><?= htmlspecialchars(role_label($_fin_role)) ?></span>
      </a>
      <a id="fin-logout-btn" href="../auth/Logout_Page.php" class="logout-btn" title="Logout" style="text-decoration:none"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
          <polyline points="16 17 21 12 16 7" />
          <line x1="21" y1="12" x2="9" y2="12" />
        </svg></a>
    </div>
  </div>
</aside>
<link rel="stylesheet" href="../css/sidebar-dropdown.css"/>
<script src="../js/sidebar-dropdown.js"></script>
<script src="../js/sidebar-scroll-persist.js"></script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Sidebar collapse/expand + avatar → coffee cup morph animation, matching
// Sidebar_Employee.php / Sidebar_HR.php so this behaves identically no
// matter which module the user is in.
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
// immediate navigation. Lives here (in the shared sidebar) so every
// Finance page that includes this sidebar gets the same prompt.
document.getElementById('fin-logout-btn').addEventListener('click', function (e) {
  e.preventDefault();
  var href = this.getAttribute('href');
  Swal.fire({
    title: 'Log out?',
    text: "You'll need to sign in again to access the Finance portal.",
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
</script>