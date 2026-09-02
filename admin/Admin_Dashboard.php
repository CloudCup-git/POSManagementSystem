<?php
session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../auth/Role_Panel.php');
  exit;
}
require_once __DIR__ . '/../includes/DB_Connect.php';
require_once __DIR__ . '/_Dashboard_Data.php';

$full_name   = $_SESSION['full_name'] ?? 'Manager';
$initials    = strtoupper(substr($full_name, 0, 1));
$active_page = 'dashboard';

// ── Live dashboard data, straight from the database ────────────────
$cc_periods = [
  'today' => cc_dashboard_period($conn, 'today'),
  'month' => cc_dashboard_period($conn, 'month'),
  'year'  => cc_dashboard_period($conn, 'year'),
];
$cc_branches = cc_dashboard_branches($conn);
$cc_activity = cc_dashboard_activity($conn);

// ── Low-stock alert bar (unchanged behavior, now restyled) ─────────
$inv_row = safe_fetch_assoc(safe_query($conn,
  "SELECT SUM(quantity <= reorder_level) AS low_count FROM inventory"));
$inv_low = (int)($inv_row['low_count'] ?? 0);
$low_items = [];
$low_items_result = safe_query($conn,
  "SELECT item_name FROM inventory WHERE quantity <= reorder_level ORDER BY quantity ASC LIMIT 3");
if ($low_items_result) while ($r = mysqli_fetch_assoc($low_items_result)) $low_items[] = $r['item_name'];

$hour = (int)date('G');
$daypart = $hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening');
$first_name = trim(explode(' ', $full_name)[0]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Dashboard — Cloud Cup</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/admin_dashboard_redesign.css"/>
</head>
<body>

<script src="../js/sidebar-toggle.js"></script>
<?php
if (file_exists('../admin/Sidebar_Admin.php')) {
  require_once '../admin/Sidebar_Admin.php';
} else {
  echo '<div style="background:#f4e3d3;border-bottom:1px solid #a6650f;padding:10px 32px;font-size:13px;color:#a6650f">'
     . '<strong>Sidebar not found.</strong> Expected <code>Sidebar_Admin.php</code> in this folder. The dashboard will still load without it.'
     . '</div>';
}
?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <div>
        <h1>Good <?= $daypart ?>, <?= htmlspecialchars($first_name) ?></h1>
        <p>Here's how Cloud Cup is brewing right now.</p>
      </div>
    </div>
    <div class="topbar-right" style="gap:10px;">

      <div class="cc-dropdown" id="ccPeriodDropdown">
        <div class="cc-dd-btn" id="ccPeriodBtn">
          <span id="ccPeriodLabel">Today</span><span class="cc-chev">▾</span>
        </div>
        <div class="cc-dd-menu">
          <div class="cc-dd-option selected" data-period="today">Today</div>
          <div class="cc-dd-option" data-period="month">This Month</div>
          <div class="cc-dd-option" data-period="year">This Year</div>
        </div>
      </div>

      <div class="cc-dropdown" id="ccActivityDropdown">
        <div class="cc-icon-btn" id="ccActivityBtn" title="Recent Activity">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        </div>
        <div class="cc-panel-menu">
          <div class="cc-panel-menu-title-row">
            <div class="cc-panel-menu-title">Recent Activity</div>
            <a href="Activity_Log_Page.php" class="alert-mark-all" style="text-decoration:none">View all</a>
          </div>
          <?php if (empty($cc_activity)): ?>
            <div class="cc-panel-menu-empty">No recent activity.</div>
          <?php else: foreach ($cc_activity as $a): ?>
            <div class="cc-activity-item">
              <div class="cc-adot"></div>
              <div><div class="cc-activity-text"><?= $a['text'] ?></div><div class="cc-activity-time"><?= htmlspecialchars($a['time']) ?></div></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <?php if ($_is_admin_role ?? false): ?>
      <div class="cc-dropdown" id="ccAlertDropdown">
        <div class="cc-icon-btn" id="ccAlertBtn" title="Low stock reports">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
          <?php if ($_alerts_count > 0): ?><span class="cc-icon-btn-dot"></span><?php endif; ?>
        </div>
        <div class="cc-panel-menu">
          <div class="cc-panel-menu-title-row">
            <div class="cc-panel-menu-title">Low Stock Reports</div>
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

      <div class="cc-date-pill">🗓 <?= date('F j, Y') ?></div>
    </div>
  </div>

  <div class="content">

    <!-- ALERT -->
    <?php if ($inv_low > 0): ?>
    <div class="alert-bar">
      <div class="alert-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#a6650f" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
      <div class="alert-text">
        <strong>Low Stock Alert:</strong>
        <?= $inv_low ?> item<?= $inv_low > 1 ? 's are' : ' is' ?> below minimum stock threshold
        <?php if (!empty($low_items)): ?>
          — <em><?= htmlspecialchars(implode(', ', $low_items)) ?><?= $inv_low > count($low_items) ? '…' : '' ?></em>
        <?php endif; ?>
        <a href="Inventory_Management_Page.php" style="color:var(--caramel);font-weight:600;"> View Inventory →</a>
      </div>
      <div class="alert-close" onclick="this.parentElement.style.display='none'"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div>
    </div>
    <?php endif; ?>

    <!-- TOTAL REVENUE -->
    <section class="cc-panel">
      <div class="cc-section-top">
        <div>
          <div class="cc-eyebrow">Total Revenue <span class="cc-period-tag" id="ccRevPeriodTag">Today</span></div>
          <div class="cc-revenue-value cc-fade-swap" id="ccRevenueValue"><span class="cc-rv-currency">₱</span><span id="ccRevenueInt">0</span><span class="cc-rv-decimal">.00</span></div>
          <div class="cc-revenue-change cc-fade-swap" id="ccRevenueChange">▲ 0% vs previous period</div>
        </div>
        <div class="cc-legend">
          <span><i class="cc-legend-dot" style="background:var(--sage)"></i>Revenue</span>
          <span><i class="cc-legend-dot" style="background:var(--sky)"></i>Peak</span>
        </div>
      </div>
      <div class="cc-chart-wrap cc-fade-swap" id="ccChartWrap">
        <div class="cc-chart" id="ccRevenueChart"></div>
        <div class="cc-chart-highlight" id="ccChartHighlight"></div>
        <div class="cc-chart-tooltip" id="ccChartTooltip"></div>
      </div>
    </section>

    <!-- ORDERS -->
    <section class="cc-panel">
      <div class="cc-section-top">
        <div>
          <div class="cc-eyebrow">Orders <span class="cc-period-tag" id="ccOrdersPeriodTag">Today</span></div>
          <div class="cc-orders-count-wrap cc-fade-swap" id="ccOrdersCountWrap">
            <span class="cc-orders-count" id="ccOrdersCount">0</span>
            <span class="cc-orders-count-label">Orders</span>
          </div>
        </div>
        <a class="cc-panel-link" href="Sales_Records_Page.php">View All Orders →</a>
      </div>
      <div class="cc-fade-swap" id="ccOrdersListWrap">
        <table class="cc-orders-table">
          <thead><tr><th>Order ID</th><th>Served By</th><th>Item(s)</th><th>Payment</th><th>Amount</th><th>Status</th></tr></thead>
          <tbody id="ccOrdersTableBody"></tbody>
        </table>
      </div>
    </section>

    <!-- TOP SELLING ITEM -->
    <section class="cc-panel">
      <div class="cc-eyebrow" style="margin-bottom:14px;">Top Selling Item <span class="cc-period-tag" id="ccTopPeriodTag">Today</span></div>
      <div class="cc-fade-swap" id="ccTopSection"></div>
    </section>

    <!-- BRANCHES -->
    <section>
      <div class="cc-eyebrow" style="margin-bottom:14px;">Branches</div>
      <?php if (empty($cc_branches)): ?>
        <div class="cc-panel" style="text-align:center;color:var(--ink-soft);font-size:13px;">
          No branches have been added yet. <a class="cc-panel-link" href="Branch_Management_Page.php">Add one →</a>
        </div>
      <?php else: ?>
      <div class="cc-branch-grid">
        <?php foreach ($cc_branches as $b): ?>
        <div class="cc-branch-card">
          <div class="cc-branch-top-row">
            <div>
              <div class="cc-branch-name"><?= htmlspecialchars($b['name']) ?></div>
              <div class="cc-branch-loc">📍 <?= htmlspecialchars($b['address']) ?></div>
            </div>
            <span class="cc-branch-status-pill <?= $b['open'] ? '' : 'closed' ?>"><?= $b['open'] ? 'Open' : 'Closed' ?></span>
          </div>
          <?php if ($b['hours']): ?>
            <div class="cc-branch-hours">🕒 <?= htmlspecialchars($b['hours']) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

  </div>
</div>

<script>
  window.CC_DASHBOARD = { periods: <?= json_encode($cc_periods, JSON_HEX_TAG | JSON_HEX_APOS) ?> };
</script>
<script src="../js/admin_dashboard_redesign.js"></script>
</body>
</html>
