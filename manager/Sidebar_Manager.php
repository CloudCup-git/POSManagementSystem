<?php
if (!isset($_SESSION['employee_id'])) {
    $_SESSION['employee_id'] = $_SESSION['admin_id']
                            ?? $_SESSION['user_id']
                            ?? 0;
}
if (!isset($_SESSION['full_name'])) {
    $_SESSION['full_name'] = $_SESSION['admin_name']
                          ?? $_SESSION['username']
                          ?? 'Admin';
}
// Ensure role is always set for downstream pages
if (!isset($_SESSION['role'])) {
    $_SESSION['role'] = 'manager';
}
// ──────────────────────────────────────────────────────────────────────────────
// Permissions.php lives in admin/ (shared infrastructure file, not an
// admin-only page) — every module's sidebar requires it from there.
require_once __DIR__ . '/../admin/Permissions.php';


$_admin_name     = $_SESSION['full_name']     ?? 'Manager';
$_admin_initials = strtoupper(substr($_admin_name, 0, 1));
$_active         = $active_page ?? '';
$_role           = current_role();
// This sidebar is only ever included from pages already gated to
// role === 'manager', so this is just a defensive re-check, not an
// access boundary by itself.
$_is_manager_role  = ($_role === 'manager');

// Fetch live inventory badge count (items with low/out-of-stock)
// and pending-leave badge for the HR section. Admin-panel-only data
// (inventory) is skipped for manager/employee sessions since those
// pages aren't part of their nav anyway.
$_inv_badge = 0;
if (isset($conn) && $_is_manager_role) {
    $r = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM inventory WHERE quantity <= reorder_level");
    if ($r) $_inv_badge = (int)(mysqli_fetch_assoc($r)['cnt'] ?? 0);
}

$_leave_badge = 0;
if (isset($conn) && has_permission('manage_leave_requests')) {
    $lr = mysqli_query($conn, "SHOW TABLES LIKE 'leave_requests'");
    if ($lr && mysqli_num_rows($lr) > 0) {
        $cr = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM leave_requests WHERE status = 'pending'");
        if ($cr) $_leave_badge = (int)(mysqli_fetch_assoc($cr)['cnt'] ?? 0);
    }
}

// Fetch unread staff "low stock" reports for the notification bell (admin only)
$_alerts = [];
$_alerts_count = 0;
if (isset($conn) && $_is_manager_role) {
    $ar = mysqli_query($conn, "SHOW TABLES LIKE 'stock_alerts'");
    if ($ar && mysqli_num_rows($ar) > 0) {
        $cr = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM stock_alerts WHERE status = 'unread'");
        if ($cr) $_alerts_count = (int)(mysqli_fetch_assoc($cr)['cnt'] ?? 0);

        $lr2 = mysqli_query($conn,
            "SELECT * FROM stock_alerts WHERE status = 'unread' ORDER BY created_at DESC LIMIT 8");
        if ($lr2) while ($row = mysqli_fetch_assoc($lr2)) $_alerts[] = $row;
    }
}

// SVG icon library
$_svg = [
    'dashboard' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>',
    'inventory'  => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
    'sales'      => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
    'menu'       => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><line x1="6" y1="2" x2="6" y2="4"/><line x1="10" y1="2" x2="10" y2="4"/><line x1="14" y1="2" x2="14" y2="4"/></svg>',
    'reports'    => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
    'accounts'   => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'records'    => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
    'attendance' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
    'leave'      => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="m9 16 2 2 4-4"/></svg>',
    'payroll'    => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>',
    'finance'    => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
    'cashflow'   => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>',
    'transactions' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
    'balance'    => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18"/><path d="M3 7h4l-2 5a2.5 2.5 0 0 0 5 0L8 7"/><path d="M17 7h4l-2 5a2.5 2.5 0 0 0 5 0L19 7"/><path d="M3 7h18"/><path d="M6 21h12"/></svg>',
];

function _nav(string $href, string $icon, string $label, string $key, string $active, int $badge = 0): string {
    global $_svg;
    $cls  = ($active === $key) ? 'nav-item active' : 'nav-item';
    $bdg  = $badge > 0 ? '<span class="badge">' . $badge . '</span>' : '';
    $svg  = $_svg[$icon] ?? '';
    return '<a href="' . $href . '" class="' . $cls . '" data-label="' . htmlspecialchars($label) . '"><span class="icon">' . $svg . '</span><span class="nav-label"> ' . $label . ' ' . $bdg . '</span></a>';
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
</style>
<aside class="sidebar">
  <div class="sidebar-logo-row">
    <div class="sidebar-logo" style="cursor:pointer" onclick="window.location.href='Manager_Dashboard.php'">
      <span class="sidebar-logo-text">Cloud
      <span>Cup</span>
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
    <?php if ($_is_manager_role): ?>
    <div class="alert-bell-wrap">
      <button type="button" class="alert-bell" id="alertBellBtn" title="Low stock reports">
        <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        <?php if ($_alerts_count > 0): ?><span class="alert-bell-badge"><?= $_alerts_count > 9 ? '9+' : $_alerts_count ?></span><?php endif; ?>
      </button>
      <div class="alert-dropdown" id="alertDropdown">
        <div class="alert-dropdown-header">
          <span>Low Stock Reports</span>
          <?php if ($_alerts_count > 0): ?>
            <button type="button" class="alert-mark-all" onclick="ackAllAlerts()">Mark all read</button>
          <?php endif; ?>
        </div>
        <div class="alert-dropdown-list" id="alertDropdownList">
          <?php if (empty($_alerts)): ?>
            <div class="alert-empty">No low stock reports right now 🎉</div>
          <?php else: foreach ($_alerts as $a): ?>
            <div class="alert-item" data-alert-id="<?= $a['alert_id'] ?>">
              <div class="alert-item-icon">⚠️</div>
              <div class="alert-item-body">
                <strong><?= htmlspecialchars($a['item_name']) ?></strong>
                <span><?= htmlspecialchars($a['reporter_name']) ?> reported <?= (float)$a['quantity_at_report'] ?> left (reorder at <?= (float)$a['reorder_level'] ?>)</span>
                <em><?= date('M d, g:i A', strtotime($a['created_at'])) ?></em>
              </div>
              <button type="button" class="alert-ack-btn" title="Mark as read" onclick="ackAlert(<?= $a['alert_id'] ?>, this)">✓</button>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="sidebar-nav-scroll">
  <?php if ($_is_manager_role): ?>
  <div class="sidebar-section">
    <div class="sidebar-section-label">Overview</div>
    <?= _nav('Manager_Dashboard.php',         'dashboard', 'Dashboard',    'dashboard', $_active) ?>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">Management</div>
    <?= _nav('Inventory_Management_Page.php', 'inventory', 'Inventory',    'inventory',  $_active, $_inv_badge) ?>
    <?= _nav('Sales_Records_Page.php',    'sales',     'Records of Sales',  'sales',      $_active) ?>
    <?= _nav('Item_Availability_Page.php', 'availability', 'Item Availability', 'availability', $_active) ?>
    <?= _nav('../admin/Activity_Log_Page.php', 'reports', 'Activity History', 'history', $_active) ?>
  </div>
  <?php endif; ?>

  <?php if ($_is_manager_role): ?>
  <div class="sidebar-section">
    <div class="sidebar-section-label">Finance</div>
    <?= _nav('Finance_Revenue_Page.php',      'finance',      'Revenue',       'finance-revenue',      $_active) ?>
    <?= _nav('Finance_CashFlow_Page.php',     'cashflow',     'Cash Flow',     'finance-cashflow',     $_active) ?>
    <?= _nav('Finance_Transactions_Page.php', 'transactions', 'Transactions',  'finance-transactions', $_active) ?>
  </div>
  <?php endif; ?>
  </div>

  <div class="sidebar-footer">
    <div class="user-card">
      <div class="user-avatar"><?= htmlspecialchars($_admin_initials) ?></div>
      <div class="user-info">
        <strong><?= htmlspecialchars($_admin_name) ?></strong>
        <span><?= htmlspecialchars(role_label($_role)) ?></span>
      </div>
      <a href="../auth/Logout_Page.php" id="admin-logout-btn" class="logout-btn" title="Logout" style="text-decoration:none"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></a>
    </div>
  </div>
</aside>

<!-- SweetAlert2 confirmation for Logout -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="../css/sidebar_admin.css"/>
<script src="../js/sidebar_admin.js"></script>