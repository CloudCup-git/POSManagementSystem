<?php
session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'manager') {
  header('Location: ../auth/Role_Panel.php');
  exit;
}
require_once __DIR__ . '/../includes/DB_Connect.php';
$full_name = $_SESSION['full_name'] ?? 'Manager';
$initials  = strtoupper(substr($full_name, 0, 1));
$active_page = 'dashboard';

function safe_query($conn, string $sql) {
  if (!$conn) {
    error_log('SQL error: no database connection | Query: ' . $sql);
    return false;
  }
  $result = mysqli_query($conn, $sql);
  if ($result === false) {
    error_log('SQL error: ' . mysqli_error($conn) . ' | Query: ' . $sql);
    return false;
  }
  return $result;
}
function safe_fetch_assoc($result): array {
  if ($result === false) return [];
  $row = mysqli_fetch_assoc($result);
  return $row ?: [];
}

// ── KPI: Today's revenue & order count ───────────────────────
$kpi = safe_fetch_assoc(safe_query($conn,
  "SELECT
     COALESCE(SUM(total_amount), 0)  AS revenue_today,
     COUNT(*)                         AS orders_today
   FROM orders
   WHERE DATE(ordered_at) = CURDATE() AND status = 'completed'"));
$revenue_today = (float)($kpi['revenue_today'] ?? 0);
$orders_today  = (int)($kpi['orders_today'] ?? 0);

// Yesterday comparison for trend
$yest = safe_fetch_assoc(safe_query($conn,
  "SELECT
     COALESCE(SUM(total_amount), 0) AS rev_yest,
     COUNT(*)                        AS ord_yest
   FROM orders
   WHERE DATE(ordered_at) = CURDATE() - INTERVAL 1 DAY AND status = 'completed'"));
$rev_yest = (float)($yest['rev_yest'] ?? 0);
$ord_yest = (int)($yest['ord_yest'] ?? 0);

function pct_change(float $now, float $prev): string {
  if ($prev == 0) return $now > 0 ? '+New' : '—';
  $p = (($now - $prev) / $prev) * 100;
  return ($p >= 0 ? '↑ ' : '↓ ') . abs(round($p, 1)) . '%';
}
function trend_class(float $now, float $prev): string {
  return ($now >= $prev) ? 'trend-up' : 'trend-down';
}

$rev_trend_txt   = pct_change($revenue_today, $rev_yest);
$rev_trend_cls   = trend_class($revenue_today, $rev_yest);
$ord_trend_txt   = pct_change($orders_today, $ord_yest);
$ord_trend_cls   = trend_class($orders_today, $ord_yest);

// ── KPI: Inventory – total items & low-stock count ───────────
$inv_row = safe_fetch_assoc(safe_query($conn,
  "SELECT COUNT(*) AS total,
          SUM(quantity <= reorder_level) AS low_count
   FROM inventory"));
$inv_total    = (int)($inv_row['total'] ?? 0);
$inv_low      = (int)($inv_row['low_count'] ?? 0);

// ── Weekly sales chart (last 7 days) ─────────────────────────
$weekly_result = safe_query($conn,
  "SELECT DATE(ordered_at) AS day, COALESCE(SUM(total_amount),0) AS total
   FROM orders
   WHERE ordered_at >= CURDATE() - INTERVAL 6 DAY AND status = 'completed'
   GROUP BY DATE(ordered_at)");
$weekly_map = [];
if ($weekly_result) while ($r = mysqli_fetch_assoc($weekly_result)) $weekly_map[$r['day']] = (float)$r['total'];
$weekly_days = []; $weekly_totals = [];
for ($i = 6; $i >= 0; $i--) {
  $d = date('Y-m-d', strtotime("-$i days"));
  $weekly_days[]   = date('D', strtotime($d));
  $weekly_totals[] = $weekly_map[$d] ?? 0;
}
$max_weekly = max(array_merge($weekly_totals, [1])); // avoid div/0

// ── Top selling items ─────────────────────────────────────────
$top_result = safe_query($conn,
  "SELECT item_name, SUM(quantity) AS sold
   FROM order_items
   GROUP BY item_name
   ORDER BY sold DESC
   LIMIT 5");
$top_items = [];
if ($top_result) while ($r = mysqli_fetch_assoc($top_result)) $top_items[] = $r;
$max_sold = !empty($top_items) ? (int)$top_items[0]['sold'] : 1;

// ── Recent orders ─────────────────────────────────────────────
$recent_result = safe_query($conn,
  "SELECT o.order_id, o.total_amount, o.status, o.ordered_at,
          GROUP_CONCAT(oi.item_name ORDER BY oi.item_name SEPARATOR ', ') AS items_list
   FROM orders o
   LEFT JOIN order_items oi ON oi.order_id = o.order_id
   GROUP BY o.order_id
   ORDER BY o.ordered_at DESC
   LIMIT 7");
$recent_orders = [];
if ($recent_result) while ($r = mysqli_fetch_assoc($recent_result)) $recent_orders[] = $r;

// ── Low-stock items for alert bar ────────────────────────────
$low_items_result = safe_query($conn,
  "SELECT item_name FROM inventory WHERE quantity <= reorder_level ORDER BY quantity ASC LIMIT 3");
$low_items = [];
if ($low_items_result) while ($r = mysqli_fetch_assoc($low_items_result)) $low_items[] = $r['item_name'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manager Dashboard — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
</head>
<body>


<script src="../js/sidebar-toggle.js"></script>
<?php
if (file_exists('../manager/Sidebar_Manager.php')) {
  require_once '../manager/Sidebar_Manager.php';
} else {
  echo '<div style="background:#f4e3d3;border-bottom:1px solid #a6650f;padding:10px 32px;font-size:13px;color:#a6650f">'
     . '<strong>Sidebar not found.</strong> Expected <code>Sidebar_Manager.php</code> in this folder. The dashboard will still load without it.'
     . '</div>';
}
?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
      <h1>Dashboard</h1>
    </div>
    <div class="topbar-right">
      <div class="topbar-date"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:5px"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> <?= date('F j, Y') ?></div>
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

    <!-- KPI CARDS -->
    <div class="kpi-grid">
      <div class="kpi-card" style="--accent-bg: rgba(34,197,94,0.06)">
        <div class="kpi-top">
          <div class="kpi-icon" style="background:rgba(34,197,94,0.1)"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#2f6f4e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
          <div class="kpi-trend <?= $rev_trend_cls ?>"><?= htmlspecialchars($rev_trend_txt) ?></div>
        </div>
        <div class="kpi-val">₱<?= number_format($revenue_today, 2) ?></div>
        <div class="kpi-label">Today's Revenue</div>
      </div>
      <div class="kpi-card" style="--accent-bg: rgba(59,130,246,0.06)">
        <div class="kpi-top">
          <div class="kpi-icon" style="background:rgba(59,130,246,0.1)"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#3B82F6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.94a2 2 0 0 0 2 1.61h9.58a2 2 0 0 0 2-1.61l1.4-7.39H5.12"/></svg></div>
          <div class="kpi-trend <?= $ord_trend_cls ?>"><?= htmlspecialchars($ord_trend_txt) ?></div>
        </div>
        <div class="kpi-val"><?= $orders_today ?></div>
        <div class="kpi-label">Orders Today</div>
      </div>
      <div class="kpi-card" style="--accent-bg: rgba(239,68,68,0.06)">
        <div class="kpi-top">
          <div class="kpi-icon" style="background:rgba(239,68,68,0.1)"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#b8453a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="M3.3 7 12 12l8.7-5"/><path d="M12 22V12"/></svg></div>
          <?php if ($inv_low > 0): ?>
            <div class="kpi-trend trend-down">↓ <?= $inv_low ?> low</div>
          <?php else: ?>
            <div class="kpi-trend trend-up">✓ OK</div>
          <?php endif; ?>
        </div>
        <div class="kpi-val"><?= $inv_total ?></div>
        <div class="kpi-label">Inventory Items</div>
      </div>
    </div>

    <!-- SALES CHART + TOP ITEMS -->
    <div class="grid-3-1">
      <div class="widget">
        <div class="widget-header">
          <div class="widget-title">Weekly Sales Overview</div>
          <a href="../admin/Reports_Page.php" class="widget-action">Full Report →</a>
        </div>
        <div class="chart-area">
          <?php foreach ($weekly_totals as $i => $amt):
            $pct = $max_weekly > 0 ? round(($amt / $max_weekly) * 90) + 5 : 5;
            $is_today = ($i === 6);
          ?>
          <div class="bar <?= $is_today ? 'highlight' : '' ?>" style="height:<?= $pct ?>%"
               title="₱<?= number_format($amt, 2) ?>"></div>
          <?php endforeach; ?>
        </div>
        <div class="chart-labels">
          <?php foreach ($weekly_days as $d): ?>
            <span><?= $d ?></span>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="widget">
        <div class="widget-header">
          <div class="widget-title">Top Selling Items</div>
          <a href="../admin/Reports_Page.php" class="widget-action">See all</a>
        </div>
        <div>
          <?php if (empty($top_items)): ?>
            <p style="color:var(--text-light);font-size:13px;text-align:center;padding:24px 0">No sales data yet.</p>
          <?php else: foreach ($top_items as $rank => $item):
            $pct  = round(($item['sold'] / $max_sold) * 100);
            $gold = $rank === 0 ? 'gold' : '';
          ?>
          <div class="top-item">
            <div class="top-item-rank <?= $gold ?>"><?= $rank + 1 ?></div>
            <div style="flex:1">
              <div class="top-item-name"><?= htmlspecialchars($item['item_name']) ?></div>
              <div class="top-item-bar"><div class="top-item-fill" style="width:<?= $pct ?>%"></div></div>
            </div>
            <div class="top-item-count"><?= (int)$item['sold'] ?> sold</div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <!-- RECENT ORDERS + ACTIVITY -->
    <div class="grid-2">
      <div class="widget">
        <div class="widget-header">
          <div class="widget-title">Recent Orders</div>
          <a href="Reports_Page.php" class="widget-action">View all →</a>
        </div>
        <table>
          <thead>
            <tr>
              <th>Order ID</th>
              <th>Item</th>
              <th>Amount</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recent_orders)): ?>
            <tr><td colspan="4" style="text-align:center;color:var(--text-light);padding:24px 0">No orders yet.</td></tr>
            <?php else: foreach ($recent_orders as $o):
              $oid     = '#ORD-' . str_pad($o['order_id'], 4, '0', STR_PAD_LEFT);
              $status  = $o['status'] ?? 'completed';
              $pill    = match($status) {
                'completed' => 'pill-done',
                'pending'   => 'pill-pending',
                'cancelled' => 'pill-cancel',
                default     => 'pill-done'
              };
              $label   = ucfirst($status);
              // Truncate long item lists
              $items_display = mb_strlen($o['items_list']) > 32
                ? mb_substr($o['items_list'], 0, 32) . '…'
                : $o['items_list'];
            ?>
            <tr>
              <td class="order-id"><?= htmlspecialchars($oid) ?></td>
              <td><?= htmlspecialchars($items_display) ?></td>
              <td>₱<?= number_format($o['total_amount'], 2) ?></td>
              <td><span class="status-pill <?= $pill ?>"><?= $label ?></span></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="widget">
        <div class="widget-header">
          <div class="widget-title">Recent Activity</div>
          <a href="#" class="widget-action">Clear</a>
        </div>
        <div class="activity-list">
          <?php
          // Build activity feed: recent completed orders + low-stock warnings
          $activity = [];

          // Recent completed orders
          $act_orders = safe_query($conn,
            "SELECT o.order_id, o.total_amount, o.status, o.ordered_at, u.full_name AS cashier
             FROM orders o
             LEFT JOIN users u ON u.user_id = o.employee_id
             ORDER BY o.ordered_at DESC LIMIT 5");
          if ($act_orders) while ($r = mysqli_fetch_assoc($act_orders)) {
            $oid = '#ORD-' . str_pad($r['order_id'], 4, '0', STR_PAD_LEFT);
            $dot = $r['status'] === 'completed' ? 'dot-blue' : ($r['status'] === 'cancelled' ? 'dot-red' : 'dot-yellow');
            $msg = $r['status'] === 'completed'
              ? "<strong>{$oid}</strong> completed" . ($r['cashier'] ? " by {$r['cashier']}" : '') . " — ₱" . number_format($r['total_amount'], 2)
              : "<strong>{$oid}</strong> " . $r['status'];
            $activity[] = ['dot' => $dot, 'msg' => $msg, 'ts' => $r['ordered_at']];
          }

          // Low-stock warnings
          $act_inv = safe_query($conn,
            "SELECT item_name, quantity, reorder_level FROM inventory
             WHERE quantity <= reorder_level ORDER BY quantity ASC LIMIT 3");
          if ($act_inv) while ($r = mysqli_fetch_assoc($act_inv)) {
            $activity[] = [
              'dot' => 'dot-yellow',
              'msg' => "<strong>" . htmlspecialchars($r['item_name']) . "</strong> stock low — {$r['quantity']} unit(s) remaining (min {$r['reorder_level']})",
              'ts'  => null
            ];
          }

          if (empty($activity)):
          ?>
            <p style="color:var(--text-light);font-size:13px;text-align:center;padding:24px 0">No recent activity.</p>
          <?php else: foreach ($activity as $a):
            // Human-readable time
            if ($a['ts']) {
              $diff = time() - strtotime($a['ts']);
              if ($diff < 60)           $time_txt = 'just now';
              elseif ($diff < 3600)     $time_txt = floor($diff/60) . ' min ago';
              elseif ($diff < 86400)    $time_txt = floor($diff/3600) . ' hr' . (floor($diff/3600)>1?'s':'') . ' ago';
              else                      $time_txt = date('M j', strtotime($a['ts']));
            } else {
              $time_txt = 'alert';
            }
          ?>
          <div class="activity-item">
            <div class="activity-dot <?= $a['dot'] ?>"></div>
            <div class="activity-text"><?= $a['msg'] ?></div>
            <div class="activity-time"><?= $time_txt ?></div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

  </div>
</div>