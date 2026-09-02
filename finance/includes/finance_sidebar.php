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
<aside class="sidebar">
  <div class="sidebar-logo-row">
    <div class="sidebar-logo" style="cursor:pointer" onclick="window.location.href='finance.php'">
      <span class="sidebar-logo-text">Cloud<span>Cup</span>
      <div style="font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:rgba(255,255,255,.35);margin-top:2px;font-family:'Inter',sans-serif;font-weight:700">Finance Portal</div>
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
    <?= _fin_nav('finance.php', 'dashboard', 'Dashboard', 'dashboard', $_fin_active, $rangeQuery) ?>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">Reports</div>
    <?= _fin_nav('finance_revenue.php',      'revenue',      'Revenue',                'revenue',      $_fin_active, $rangeQuery) ?>
    <?= _fin_nav('finance_opex.php',         'opex',         'Operating Expenses',     'opex',         $_fin_active, $rangeQuery) ?>
    <?= _fin_nav('finance_cashflow.php',     'cashflow',     'Cash Flow',              'cashflow',     $_fin_active, $rangeQuery) ?>
    <?= _fin_nav('finance_balance.php',      'balance',      'Balance Sheet',          'balance',      $_fin_active, $rangeQuery) ?>
    <?= _fin_nav('finance_startup.php',      'startup',      'Startup & Capital',      'startup',      $_fin_active, $rangeQuery) ?>
    <?= _fin_nav('finance_transactions.php', 'transactions', 'Transactions',           'transactions', $_fin_active, $rangeQuery) ?>
    <?= _fin_nav('finance_salary_budget.php', 'salary',   'Salary Budget',       'salary_budget', $_fin_active, $rangeQuery) ?>
    <?= _fin_nav('finance_budgeting.php',    'budgeting',    'Budgeting & Forecasting', 'budgeting',    $_fin_active, $rangeQuery) ?>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">Pay</div>
    <?= _fin_nav('finance_payroll.php', 'payroll', 'Payroll & Loans', 'payroll', $_fin_active, $rangeQuery) ?>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">Cash Flow Management</div>
    <?= _fin_nav('finance_payroll_approval.php',   'approval',   'Payroll and Employee Loans', 'cfm_approval',   $_fin_active, $rangeQuery) ?>
    <?= _fin_nav('finance_payroll_processing.php', 'processing', 'Payroll Processing',         'cfm_processing', $_fin_active, $rangeQuery) ?>
    <?= _fin_nav('finance_restock_approvals.php',  'cogs',       'Inventory Restocks',         'cfm_restock',    $_fin_active, $rangeQuery, $_fin_restock_badge) ?>
    <?= _fin_nav('../admin/Activity_Log_Page.php',  'transactions', 'Activity History',          'history',        $_fin_active, $rangeQuery) ?>
  </div>

  <?php if (in_array($_fin_role, ['admin', 'manager'], true)): ?>
    <div class="sidebar-section">
      <a href="../admin/Admin_Page.php" class="nav-item" data-label="Back to POS">
        <span class="icon"><?= $_fin_svg['switch'] ?></span>
        <span class="nav-label"> ← Back to POS</span>
      </a>
    </div>
  <?php endif; ?>

  <div class="sidebar-footer">
    <div class="user-card">
      <div class="user-avatar"><?= htmlspecialchars($_fin_initials) ?></div>
      <div class="user-info">
        <strong><?= htmlspecialchars($_fin_name) ?></strong>
        <span><?= htmlspecialchars(role_label($_fin_role)) ?></span>
      </div>
      <a href="../auth/Logout_Page.php" class="logout-btn" title="Logout" style="text-decoration:none"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
          <polyline points="16 17 21 12 16 7" />
          <line x1="21" y1="12" x2="9" y2="12" />
        </svg></a>
    </div>
  </div>
</aside>
<!-- SweetAlert2 confirmation for Logout -->
<link rel="stylesheet" href="../css/sidebar_admin.css" />
<style>
  .nav-badge{
    display:inline-flex;align-items:center;justify-content:center;
    min-width:18px;height:18px;padding:0 5px;margin-left:6px;
    border-radius:20px;background:#b8703f;color:#fff;
    font-size:10.5px;font-weight:700;line-height:1;vertical-align:middle;
  }
</style>
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
          text: "You'll need to sign in again to access the Finance portal.",
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