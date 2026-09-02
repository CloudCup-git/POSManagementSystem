<?php
// ── SUPPLIER PORTAL SIDEBAR ──────────────────────────────────────────
// Shared by every page under supplier/ — mirrors finance/includes/
// finance_sidebar.php's shape (logo block, nav helper, footer user-card
// + SweetAlert2 logout) so the Supplier Portal reads as a sibling module
// rather than a bolted-on afterthought. This sidebar is only ever
// require'd from a page that already called supplier_require_login(),
// so it doesn't re-authorize — just renders.
require_once __DIR__ . '/../../admin/Permissions.php';

$_sup_name     = $_SESSION['full_name'] ?? 'Supplier';
$_sup_initials = strtoupper(substr($_sup_name, 0, 1));
$_sup_active   = $activePage ?? '';

$_sup_svg = [
  'dashboard'  => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>',
  'requests'   => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
  'stockcheck' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
  'issues'     => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
  'rfqs'       => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><line x1="8" y1="9" x2="16" y2="9"/><line x1="8" y1="13" x2="13" y2="13"/></svg>',
  'deliveries' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>',
  'stocks'     => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8V21H3V8"/><path d="M1 3h22v5H1z"/><line x1="10" y1="12" x2="14" y2="12"/></svg>',
  'invoices'   => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
  'history'    => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
  'activity'   => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>',
  'profile'    => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
];

function _sup_nav(string $href, string $icon, string $label, string $key, string $active, int $badge = 0): string
{
  global $_sup_svg;
  $cls = ($active === $key) ? 'nav-item active' : 'nav-item';
  $svg = $_sup_svg[$icon] ?? '';
  $badgeHtml = $badge > 0 ? '<span class="nav-badge">' . ($badge > 99 ? '99+' : $badge) . '</span>' : '';
  return '<a href="' . $href . '" class="' . $cls . '" data-label="' . htmlspecialchars($label) . '"><span class="icon">' . $svg . '</span><span class="nav-label"> ' . $label . $badgeHtml . '</span></a>';
}
?>
<aside class="sidebar">
  <div class="sidebar-logo-row">
    <div class="sidebar-logo" style="cursor:pointer" onclick="window.location.href='Supplier_Dashboard.php'">
      <span class="sidebar-logo-text">Cloud<span>Cup</span>
      <div style="font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:rgba(255,255,255,.35);margin-top:2px;font-family:'Inter',sans-serif;font-weight:700">Supplier Portal</div>
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
    <?= _sup_nav('Supplier_Dashboard.php', 'dashboard', 'Dashboard', 'dashboard', $_sup_active) ?>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">Orders</div>
    <?= _sup_nav('Supplier_RFQs.php', 'rfqs', 'RFQs', 'rfqs', $_sup_active) ?>
    <?= _sup_nav('Supplier_Stock_Checks.php', 'stockcheck', 'Stock Checks', 'stockcheck', $_sup_active) ?>
    <?= _sup_nav('Supplier_Requests.php', 'requests', 'Requests & POs', 'requests', $_sup_active) ?>
    <?= _sup_nav('Supplier_Deliveries.php', 'deliveries', 'Deliveries', 'deliveries', $_sup_active) ?>
    <?= _sup_nav('Supplier_Delivery_Issues.php', 'issues', 'Delivery Issues', 'issues', $_sup_active) ?>
    <?= _sup_nav('Supplier_History.php', 'history', 'History', 'history', $_sup_active) ?>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">Business</div>
    <?= _sup_nav('Supplier_Stocks.php', 'stocks', 'My Stock', 'stocks', $_sup_active) ?>
    <?= _sup_nav('Supplier_Invoices.php', 'invoices', 'Invoices', 'invoices', $_sup_active) ?>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">Account</div>
    <?= _sup_nav('Supplier_Profile.php', 'profile', 'Company Profile', 'profile', $_sup_active) ?>
    <?= _sup_nav('Supplier_Activity_Log.php', 'activity', 'Activity Log', 'activity', $_sup_active) ?>
  </div>

  <div class="sidebar-footer">
    <div class="user-card">
      <div class="user-avatar"><?= htmlspecialchars($_sup_initials) ?></div>
      <div class="user-info">
        <strong><?= htmlspecialchars($_sup_name) ?></strong>
        <span><?= htmlspecialchars(role_label(current_role())) ?></span>
      </div>
      <a href="../auth/Logout_Page.php" class="logout-btn" title="Logout" style="text-decoration:none"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
          <polyline points="16 17 21 12 16 7" />
          <line x1="21" y1="12" x2="9" y2="12" />
        </svg></a>
    </div>
  </div>
</aside>
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
    document.querySelectorAll('a.logout-btn').forEach(function(link) {
      link.addEventListener('click', function(e) {
        e.preventDefault();
        const href = this.getAttribute('href');
        Swal.fire({
          title: 'Log out?',
          text: "You'll need to sign in again to access the Supplier portal.",
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
