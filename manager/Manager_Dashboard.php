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

// ── Unread "low stock" reports for the notification bell ────
$alerts = [];
$alerts_count = 0;
$ar = safe_query($conn, "SHOW TABLES LIKE 'stock_alerts'");
if ($ar && mysqli_num_rows($ar) > 0) {
  $cr = safe_query($conn, "SELECT COUNT(*) AS cnt FROM stock_alerts WHERE status = 'unread'");
  if ($cr) $alerts_count = (int)(mysqli_fetch_assoc($cr)['cnt'] ?? 0);
  $lr2 = safe_query($conn, "SELECT * FROM stock_alerts WHERE status = 'unread' ORDER BY created_at DESC LIMIT 8");
  if ($lr2) while ($row = mysqli_fetch_assoc($lr2)) $alerts[] = $row;
}

$alert_badge_html = '';
if ($alerts_count > 0) {
  $alert_badge_label = $alerts_count > 9 ? '9+' : (string) $alerts_count;
  $alert_badge_html  = '<span class="alert-bell-badge">' . htmlspecialchars($alert_badge_label) . '</span>';
}

$alert_mark_all_html = '';
if ($alerts_count > 0) {
  $alert_mark_all_html = '<button type="button" class="alert-mark-all" onclick="ackAllAlerts()">Mark all read</button>';
}

$alert_list_html = '';
if (empty($alerts)) {
  $alert_list_html = '<div class="alert-empty">No low stock reports right now &#127881;</div>';
} else {
  foreach ($alerts as $a) {
    $alert_list_html .= '<div class="alert-item" data-alert-id="' . (int) $a['alert_id'] . '">'
      . '<div class="alert-item-icon">&#9888;&#65039;</div>'
      . '<div class="alert-item-body">'
      . '<strong>' . htmlspecialchars($a['item_name']) . '</strong>'
      . '<span>' . htmlspecialchars($a['reporter_name']) . ' reported ' . (float) $a['quantity_at_report'] . ' left (reorder at ' . (float) $a['reorder_level'] . ')</span>'
      . '<em>' . htmlspecialchars(date('M d, g:i A', strtotime($a['created_at']))) . '</em>'
      . '</div>'
      . '<button type="button" class="alert-ack-btn" title="Mark as read" onclick="ackAlert(' . (int) $a['alert_id'] . ', this)">&#10003;</button>'
      . '</div>';
  }
}

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
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
  <style>
    /* ── Cloud Cup design system (applied over admin_page.css) ── */
    :root{
      --brown:#3C2317;
      --brown-tint:#EFE6DE;
      --teal:#628E90;
      --teal-tint:#E4EDED;
      --blue:#B4CDE6;
      --blue-tint:#EEF4FA;
      --cream:#F5EFE6;
      --surface:#FFFFFF;
      --border:#E7DECF;
      --ink:#2A1B12;
      --ink-secondary:#7A6A5C;
      --ink-muted:#A69A8B;
      --radius:12px;
      /* re-point the pre-existing theme vars so the rest of the page inherits the new palette */
      --caramel:var(--teal);
      --text-light:var(--ink-secondary);
    }
    body{background:var(--cream); color:var(--ink); font-variant-numeric:tabular-nums;}
    .content{ font-family:"Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }

    .topbar-date{ color:var(--ink-secondary); }

    /* Alert bar */
    .alert-bar{ background:var(--brown-tint); border:1px solid var(--border); border-radius:var(--radius); }
    .alert-text strong{ color:var(--brown); }

    /* Alert bell + dropdown */
    .alert-bell{ color:var(--ink-secondary); }
    .alert-bell-badge{ background:var(--brown); }
    .alert-dropdown{ border:1px solid var(--border); border-radius:var(--radius); box-shadow:0 10px 28px rgba(60,35,23,.12); }
    .alert-mark-all{ color:var(--teal); }
    .alert-item-icon{ background:var(--brown-tint); border-radius:8px; }
    .alert-ack-btn{ color:var(--teal); }

    /* KPI + widget cards */
    .kpi-card, .widget{
      background:var(--surface); border:1px solid var(--border); border-radius:var(--radius);
      opacity:0; transform:translateY(10px);
      animation:cc-riseIn .5s cubic-bezier(.2,.7,.3,1) forwards;
      transition:border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }
    .kpi-card:hover, .widget:hover{ border-color:#DCD0BC; box-shadow:0 6px 18px rgba(60,35,23,.07); transform:translateY(-2px); }
    @keyframes cc-riseIn{ to{ opacity:1; transform:translateY(0); } }
    .kpi-grid .kpi-card:nth-child(1){ animation-delay:.02s; }
    .kpi-grid .kpi-card:nth-child(2){ animation-delay:.08s; }
    .kpi-grid .kpi-card:nth-child(3){ animation-delay:.14s; }
    .grid-3-1 .widget:nth-child(1){ animation-delay:.18s; }
    .grid-3-1 .widget:nth-child(2){ animation-delay:.24s; }
    .grid-2 .widget{ animation-delay:.3s; }

    .kpi-icon{ border-radius:9px; transition:transform .2s ease; }
    .kpi-card:hover .kpi-icon{ transform:scale(1.08) rotate(-4deg); }
    .kpi-val{ color:var(--ink); }
    .kpi-label{ color:var(--ink-secondary); }
    .trend-up{ color:var(--teal); }
    .trend-down{ color:#b8453a; }

    .inv-mini-bar{ height:5px; border-radius:4px; background:var(--blue-tint); overflow:hidden; margin-top:10px; }
    .inv-mini-fill{ height:100%; background:var(--teal); border-radius:4px; transition:width .6s cubic-bezier(.2,.7,.3,1); }
    .inv-mini-legend{ display:flex; justify-content:space-between; font-size:11px; color:var(--ink-muted); margin-top:6px; }

    .widget-title{ color:var(--ink); }
    .widget-action{ color:var(--teal); }
    .widget-action:hover{ color:#4C7476; }

    .chart-area{ position:relative; }
    #weeklyChart{ width:100%!important; height:100%!important; }

    .top-item-fill{ background:var(--teal); border-radius:6px; }
    .top-item-rank{ background:var(--teal-tint); color:#3E6567; }
    .top-item-rank.gold{ background:var(--brown-tint); color:var(--brown); }
    .top-item-count{ color:var(--ink-secondary); }

    table{ font-variant-numeric:tabular-nums; }
    th{ color:var(--ink-muted); border-bottom:1px solid var(--border); }
    td{ border-bottom:1px solid var(--border); }
    tbody tr{ transition:background .15s ease; }
    tbody tr:hover{ background:var(--cream); }
    .order-id{ color:var(--teal); font-weight:500; }
    .status-pill{ border-radius:20px; font-weight:600; }
    .pill-done{ background:var(--teal-tint); color:#3E6567; }
    .pill-pending{ background:var(--blue-tint); color:#4E6E8C; }
    .pill-cancel{ background:var(--brown-tint); color:var(--brown); }

    .activity-item{ border-radius:8px; transition:background .15s ease; }
    .activity-item:hover{ background:var(--cream); }
    .dot-blue{ background:var(--teal); }
    .dot-yellow{ background:var(--blue); }
    .dot-red{ background:var(--brown); }

    /* Live indicator, from the Inventory & Revenue mockup */
    .cc-live-dot{ width:7px; height:7px; border-radius:50%; background:var(--teal); position:relative; flex-shrink:0; display:inline-block; }
    .cc-live-dot::after{ content:""; position:absolute; inset:0; border-radius:50%; background:var(--teal); animation:cc-pulse 2s ease-out infinite; }
    @keyframes cc-pulse{ 0%{ transform:scale(1); opacity:.7; } 100%{ transform:scale(2.8); opacity:0; } }
  </style>
</head>
<body>


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
      <div>
        <h1 style="margin:0;">Dashboard</h1>
        <p style="font-size:13px;color:var(--ink-secondary);margin-top:2px;display:flex;align-items:center;gap:7px;">
          <span class="cc-live-dot"></span>Cloud Cup manager console — live
        </p>
      </div>
    </div>
    <div class="topbar-right">
      <div class="topbar-alert-bell-wrap" style="position:static;margin-right:2px;">
        <button type="button" class="alert-bell" id="alertBellBtn" title="Low stock reports">
          <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
          <?= $alert_badge_html ?>
        </button>
        <div class="alert-dropdown" id="alertDropdown">
          <div class="alert-dropdown-header">
            <span>Low Stock Reports</span>
            <?= $alert_mark_all_html ?>
          </div>
          <div class="alert-dropdown-list" id="alertDropdownList">
            <?= $alert_list_html ?>
          </div>
        </div>
      </div>
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
        <div class="kpi-val"><span class="count-target" data-count="<?= $revenue_today ?>" data-decimals="2" data-prefix="₱">₱0.00</span></div>
        <div class="kpi-label">Today's Revenue</div>
      </div>
      <div class="kpi-card" style="--accent-bg: rgba(59,130,246,0.06)">
        <div class="kpi-top">
          <div class="kpi-icon" style="background:rgba(59,130,246,0.1)"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#3B82F6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.94a2 2 0 0 0 2 1.61h9.58a2 2 0 0 0 2-1.61l1.4-7.39H5.12"/></svg></div>
          <div class="kpi-trend <?= $ord_trend_cls ?>"><?= htmlspecialchars($ord_trend_txt) ?></div>
        </div>
        <div class="kpi-val"><span class="count-target" data-count="<?= $orders_today ?>" data-decimals="0">0</span></div>
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
        <div class="kpi-val"><span class="count-target" data-count="<?= $inv_total ?>" data-decimals="0">0</span></div>
        <div class="kpi-label">Inventory Items</div>
        <?php $inv_available = max($inv_total - $inv_low, 0); $inv_avail_pct = $inv_total > 0 ? round(($inv_available / $inv_total) * 100) : 0; ?>
        <div class="inv-mini-bar"><div class="inv-mini-fill" style="width:<?= $inv_avail_pct ?>%"></div></div>
        <div class="inv-mini-legend"><span><?= $inv_available ?> in stock</span><span><?= $inv_low ?> low</span></div>
      </div>
    </div>

    <!-- SALES CHART + TOP ITEMS -->
    <div class="grid-3-1">
      <div class="widget">
        <div class="widget-header">
          <div class="widget-title">Weekly Sales Overview</div>
          <a href="../admin/Reports_Page.php" class="widget-action">Full Report →</a>
        </div>
        <div class="chart-area" style="height:200px;">
          <canvas id="weeklyChart"></canvas>
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

<script>
  function animateCount(el, target, duration, decimals, prefix){
    const start = performance.now();
    function tick(now){
      const p = Math.min((now - start) / duration, 1);
      const eased = 1 - Math.pow(1 - p, 3);
      const val = target * eased;
      el.textContent = (prefix || '') + val.toLocaleString(undefined, {
        minimumFractionDigits: decimals, maximumFractionDigits: decimals
      });
      if (p < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  }
  document.querySelectorAll('.count-target').forEach(el => {
    const target = parseFloat(el.dataset.count || '0');
    const decimals = parseInt(el.dataset.decimals || '0', 10);
    const prefix = el.dataset.prefix || '';
    animateCount(el, target, 900, decimals, prefix);
  });

  const weeklyDays   = <?= json_encode($weekly_days) ?>;
  const weeklyTotals = <?= json_encode(array_map('floatval', $weekly_totals)) ?>;

  new Chart(document.getElementById('weeklyChart'), {
    type: 'bar',
    data: {
      labels: weeklyDays,
      datasets: [{
        data: weeklyTotals,
        backgroundColor: weeklyDays.map((_, i) => i === weeklyDays.length - 1 ? '#3C2317' : '#628E90'),
        borderRadius: 4,
        maxBarThickness: 30,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 700, easing: 'easeOutCubic' },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (ctx) => '₱' + ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) } }
      },
      scales: {
        x: { grid: { display: false }, ticks: { color: '#7A6A5C', font: { size: 11.5 } } },
        y: { grid: { color: '#EFE9DD' }, ticks: { color: '#7A6A5C', font: { size: 11 }, callback: v => '₱' + v.toLocaleString() } }
      }
    }
  });
</script>
</body>
</html>