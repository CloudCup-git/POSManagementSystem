<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'manager'], true)) {
  header('Location: Admin_Login.php');
  exit;
}
require_once __DIR__ . '/../includes/DB_Connect.php';
$active_page = 'reports';


$range = $_GET['range'] ?? 'today';
switch ($range) {
  case 'week':
    $date_from = date('Y-m-d', strtotime('-7 days'));
    $date_to = date('Y-m-d');
    break;
  case 'month':
    $date_from = date('Y-m-01');
    $date_to = date('Y-m-d');
    break;
  case 'custom':
    $from_input = $_GET['from'] ?? date('Y-m-d');
    $to_input   = $_GET['to']   ?? date('Y-m-d');
    $date_from  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_input) ? $from_input : date('Y-m-d');
    $date_to    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_input)   ? $to_input   : date('Y-m-d');
    break;
  default: // today
    $date_from = date('Y-m-d');
    $date_to = date('Y-m-d');
}

// ── KPI queries ──
function q($conn, $sql, $params = [], $types = ''): array
{
  if (!$conn) {
    error_log('SQL error: no database connection | Query: ' . $sql);
    return [];
  }
  if ($params) {
    $s = mysqli_prepare($conn, $sql);
    if (!$s) {
      error_log('SQL prepare error: ' . mysqli_error($conn) . ' | Query: ' . $sql);
      return [];
    }
    mysqli_stmt_bind_param($s, $types, ...$params);
    mysqli_stmt_execute($s);
    $res = mysqli_stmt_get_result($s);
    return $res ? (mysqli_fetch_assoc($res) ?: []) : [];
  }
  $res = mysqli_query($conn, $sql);
  return $res ? (mysqli_fetch_assoc($res) ?: []) : [];
}

// Total revenue
$rev = q(
  $conn,
  "SELECT COALESCE(SUM(total_amount),0) AS total FROM orders WHERE status='completed' AND DATE(ordered_at) BETWEEN ? AND ?",
  [$date_from, $date_to],
  'ss'
);
$total_revenue = $rev['total'] ?? 0;

// Total orders
$ord = q(
  $conn,
  "SELECT COUNT(*) AS cnt FROM orders WHERE DATE(ordered_at) BETWEEN ? AND ?",
  [$date_from, $date_to],
  'ss'
);
$total_orders = $ord['cnt'] ?? 0;

// Completed orders
$comp = q(
  $conn,
  "SELECT COUNT(*) AS cnt FROM orders WHERE status='completed' AND DATE(ordered_at) BETWEEN ? AND ?",
  [$date_from, $date_to],
  'ss'
);
$completed = $comp['cnt'] ?? 0;

// Avg order value
$avg_val = $total_orders > 0 ? round($total_revenue / max($completed, 1), 2) : 0;

// Top selling items
function q_all($conn, $sql, $params = [], $types = ''): array
{
  if (!$conn) {
    error_log('SQL error: no database connection | Query: ' . $sql);
    return [];
  }
  $s = mysqli_prepare($conn, $sql);
  if (!$s) {
    error_log('SQL prepare error: ' . mysqli_error($conn) . ' | Query: ' . $sql);
    return [];
  }
  if ($params) mysqli_stmt_bind_param($s, $types, ...$params);
  mysqli_stmt_execute($s);
  $res = mysqli_stmt_get_result($s);
  return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
}

$top_items = q_all(
  $conn,
  "SELECT oi.item_name, SUM(oi.quantity) AS qty, SUM(oi.subtotal) AS revenue
   FROM order_items oi
   JOIN orders o ON o.order_id = oi.order_id
   WHERE o.status='completed' AND DATE(o.ordered_at) BETWEEN ? AND ?
   GROUP BY oi.item_name ORDER BY qty DESC LIMIT 8",
  [$date_from, $date_to],
  'ss'
);

// Recent orders
$recent_orders = q_all(
  $conn,
  "SELECT o.order_id, o.ordered_at, o.total_amount, o.status, u.full_name AS cashier_name
   FROM orders o LEFT JOIN users u ON u.user_id = o.employee_id
   WHERE DATE(o.ordered_at) BETWEEN ? AND ?
   ORDER BY o.ordered_at DESC LIMIT 15",
  [$date_from, $date_to],
  'ss'
);

// Daily breakdown (for the chart)
$daily_data = q_all(
  $conn,
  "SELECT DATE(ordered_at) AS day, SUM(total_amount) AS revenue, COUNT(*) AS orders
   FROM orders WHERE status='completed' AND DATE(ordered_at) BETWEEN ? AND ?
   GROUP BY DATE(ordered_at) ORDER BY day ASC LIMIT 30",
  [$date_from, $date_to],
  'ss'
);

// Low stock items
$low_res = $conn ? mysqli_query(
  $conn,
  "SELECT item_name, quantity, reorder_level, unit FROM inventory WHERE quantity <= reorder_level ORDER BY quantity ASC LIMIT 8"
) : false;
$low_stock = $low_res ? mysqli_fetch_all($low_res, MYSQLI_ASSOC) : [];

// Payment method breakdown
$payment_methods = q_all(
  $conn,
  "SELECT payment_method, COUNT(*) AS cnt, SUM(total_amount) AS total
   FROM orders WHERE status='completed' AND DATE(ordered_at) BETWEEN ? AND ?
   GROUP BY payment_method",
  [$date_from, $date_to],
  'ss'
);

$max_rev = 1;
foreach ($daily_data as $d) $max_rev = max($max_rev, (float)$d['revenue']);

$range_labels = [
  'today'  => 'Today',
  'week'   => 'Last 7 Days',
  'month'  => 'This Month',
  'custom' => date('M j', strtotime($date_from)) . ' – ' . date('M j', strtotime($date_to)),
];
$current_label = $range_labels[$range] ?? 'Today';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Reports — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../css/admin_page.css" />
  <link rel="stylesheet" href="../css/reports_page.css" />
</head>

<body>

  <script src="../js/sidebar-toggle.js"></script>
  <?php
  if (file_exists('../admin/Sidebar_Admin.php')) {
    require_once '../admin/Sidebar_Admin.php';
  } else {
    echo '<div style="background:#f4e3d3;border-bottom:1px solid #a6650f;padding:10px 32px;font-size:13px;color:#a6650f">'
      . '<strong>Sidebar not found.</strong> Expected <code>Sidebar_Admin.php</code> in this folder. The page will still load without it.'
      . '</div>';
  }
  ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-left">
        <h1>Reports</h1>
      </div>
      <div class="topbar-right">
        <button onclick="window.print()" style="display:flex;align-items:center;gap:6px;padding:8px 18px;border:1.5px solid rgba(44,92,130,0.12);border-radius:8px;background:var(--white);font-size:13px;font-weight:600;color:var(--text-mid);cursor:pointer;"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="6 9 6 2 18 2 18 9" />
            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
            <rect x="6" y="14" width="12" height="8" />
          </svg> Print</button>
      </div>
    </div>

    <div class="content">

      <!-- FILTER BAR -->
      <form method="GET" class="filter-bar">
        <a href="?range=today" class="filter-btn <?= $range === 'today' ? 'active' : '' ?>">Today</a>
        <a href="?range=week" class="filter-btn <?= $range === 'week' ? 'active' : '' ?>">Last 7 Days</a>
        <a href="?range=month" class="filter-btn <?= $range === 'month' ? 'active' : '' ?>">This Month</a>
        <div class="custom-range">
          <input type="hidden" name="range" value="custom" />
          <input type="date" name="from" value="<?= htmlspecialchars($date_from) ?>" />
          <span style="color:var(--text-light);font-size:13px;">to</span>
          <input type="date" name="to" value="<?= htmlspecialchars($date_to) ?>" />
          <button type="submit" class="btn-go">Go</button>
        </div>
      </form>

      <!-- KPI CARDS -->
      <div class="kpi-grid">
        <div class="kpi-card" style="--accent-bg:rgba(34,197,94,0.06)">
          <div class="kpi-top">
            <div class="kpi-icon" style="background:rgba(34,197,94,0.1)"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2f6f4e" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="1" x2="12" y2="23" />
                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
              </svg></div>
          </div>
          <div class="kpi-val"><span class="kpi-currency">₱</span><?= number_format($total_revenue, 2) ?></div>
          <div class="kpi-label">Total Revenue</div>
          <div class="kpi-sub"><?= htmlspecialchars($current_label) ?></div>
        </div>
        <div class="kpi-card" style="--accent-bg:rgba(59,130,246,0.06)">
          <div class="kpi-top">
            <div class="kpi-icon" style="background:rgba(59,130,246,0.1)"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#3B82F6" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="9" cy="21" r="1" />
                <circle cx="20" cy="21" r="1" />
                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
              </svg></div>
          </div>
          <div class="kpi-val"><?= number_format($total_orders) ?></div>
          <div class="kpi-label">Total Orders</div>
          <div class="kpi-sub"><?= number_format($completed) ?> completed</div>
        </div>
        <div class="kpi-card" style="--accent-bg:rgba(245,158,11,0.06)">
          <div class="kpi-top">
            <div class="kpi-icon" style="background:rgba(245,158,11,0.1)"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#a6650f" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="20" x2="18" y2="10" />
                <line x1="12" y1="20" x2="12" y2="4" />
                <line x1="6" y1="20" x2="6" y2="14" />
              </svg></div>
          </div>
          <div class="kpi-val"><span class="kpi-currency">₱</span><?= number_format($avg_val, 2) ?></div>
          <div class="kpi-label">Avg Order Value</div>
          <div class="kpi-sub">Per completed order</div>
        </div>
        <div class="kpi-card" style="--accent-bg:rgba(239,68,68,0.06)">
          <div class="kpi-top">
            <div class="kpi-icon" style="background:rgba(239,68,68,0.1)"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#b8453a" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                <line x1="12" y1="9" x2="12" y2="13" />
                <line x1="12" y1="17" x2="12.01" y2="17" />
              </svg></div>
          </div>
          <div class="kpi-val"><?= count($low_stock) ?></div>
          <div class="kpi-label">Low Stock Items</div>
          <div class="kpi-sub">At or below reorder level</div>
        </div>
      </div>

      <!-- REVENUE CHART + TOP ITEMS -->
      <div class="grid-3-1">
        <div class="widget">
          <div class="widget-header">
            <div>
              <div class="widget-title">Revenue Over Time</div>
              <div class="widget-sub"><?= htmlspecialchars($current_label) ?></div>
            </div>
          </div>
          <?php if (empty($daily_data)): ?>
            <div class="chart-empty">No sales data for this period.</div>
          <?php else: ?>
            <div class="chart-wrap">
              <?php foreach ($daily_data as $d):
                $pct = $max_rev > 0 ? round(((float)$d['revenue'] / $max_rev) * 100) : 0;
                $label = date('M j', strtotime($d['day']));
              ?>
                <div class="chart-bar-col" title="<?= $label ?>: ₱<?= number_format($d['revenue'], 2) ?>">
                  <div class="chart-bar" style="height:<?= max($pct, 3) ?>%"></div>
                  <div class="chart-day-label"><?= $label ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="widget">
          <div class="widget-header">
            <div class="widget-title">Payment Methods</div>
          </div>
          <?php if (empty($payment_methods)): ?>
            <div class="empty-state">
              <div class="icon"><svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="1" y="4" width="22" height="16" rx="2" ry="2" />
                  <line x1="1" y1="10" x2="23" y2="10" />
                </svg></div>
              <p>No payment data.</p>
            </div>
          <?php else: ?>
            <?php foreach ($payment_methods as $pm): ?>
              <div class="pay-row">
                <div>
                  <div class="pay-name"><?= htmlspecialchars(ucfirst($pm['payment_method'] ?? 'Unknown')) ?></div>
                  <div class="pay-count"><?= number_format($pm['cnt']) ?> orders</div>
                </div>
                <div class="pay-amount">₱<?= number_format($pm['total'], 2) ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- TOP ITEMS + LOW STOCK -->
      <div class="grid-2">
        <div class="widget">
          <div class="widget-header">
            <div class="widget-title">Top Selling Items</div>
            <div class="widget-sub"><?= htmlspecialchars($current_label) ?></div>
          </div>
          <?php if (empty($top_items)): ?>
            <div class="empty-state">
              <div class="icon"><svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M18 8h1a4 4 0 0 1 0 8h-1" />
                  <path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z" />
                  <line x1="6" y1="1" x2="6" y2="4" />
                  <line x1="10" y1="1" x2="10" y2="4" />
                  <line x1="14" y1="1" x2="14" y2="4" />
                </svg></div>
              <p>No sales data for this period.</p>
            </div>
            <?php else:
            $max_qty = max(array_column($top_items, 'qty'));
            foreach ($top_items as $i => $item):
              $pct = $max_qty > 0 ? round(($item['qty'] / $max_qty) * 100) : 0;
            ?>
              <div class="item-row">
                <div class="item-rank <?= $i === 0 ? 'gold' : '' ?>"><?= $i + 1 ?></div>
                <div class="item-name"><?= htmlspecialchars($item['item_name']) ?></div>
                <div class="item-bar-wrap">
                  <div class="item-bar-track">
                    <div class="item-bar-fill" style="width:<?= $pct ?>%"></div>
                  </div>
                </div>
                <div class="item-count"><?= number_format($item['qty']) ?> sold</div>
              </div>
          <?php endforeach;
          endif; ?>
        </div>

        <div class="widget">
          <div class="widget-header">
            <div class="widget-title">Low Stock Alerts</div>
          </div>
          <?php if (empty($low_stock)): ?>
            <div class="empty-state">
              <div class="icon"><svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#2f6f4e" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                  <polyline points="22 4 12 14.01 9 11.01" />
                </svg></div>
              <p>All inventory levels are healthy.</p>
            </div>
          <?php else: ?>
            <table>
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Stock</th>
                  <th>Reorder</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($low_stock as $ls): ?>
                  <tr>
                    <td><?= htmlspecialchars($ls['item_name']) ?></td>
                    <td><?= (int)$ls['quantity'] ?> <?= htmlspecialchars($ls['unit'] ?? '') ?></td>
                    <td><?= (int)$ls['reorder_level'] ?></td>
                    <td>
                      <?php if ((int)$ls['quantity'] === 0): ?>
                        <span class="pill pill-out">Out of Stock</span>
                      <?php else: ?>
                        <span class="pill pill-low">Low</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <!-- RECENT ORDERS TABLE -->
      <div class="widget">
        <div class="widget-header">
          <div class="widget-title">Order History</div>
          <div class="widget-sub"><?= count($recent_orders) ?> most recent orders</div>
        </div>
        <?php if (empty($recent_orders)): ?>
          <div class="empty-state">
            <div class="icon"><svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="9" cy="21" r="1" />
                <circle cx="20" cy="21" r="1" />
                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
              </svg></div>
            <p>No orders in this period.</p>
          </div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Order ID</th>
                <th>Date &amp; Time</th>
                <th>Cashier</th>
                <th>Amount</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recent_orders as $o): ?>
                <tr>
                  <td style="color:var(--caramel);font-weight:600;">#<?= str_pad($o['order_id'], 4, '0', STR_PAD_LEFT) ?></td>
                  <td style="color:var(--text-light);font-size:13px;"><?= date('M j, Y g:i A', strtotime($o['ordered_at'])) ?></td>
                  <td><?= htmlspecialchars($o['cashier_name'] ?? '—') ?></td>
                  <td style="font-weight:600;">₱<?= number_format($o['total_amount'], 2) ?></td>
                  <td>
                    <?php
                    $s = strtolower($o['status'] ?? '');
                    $cls = $s === 'completed' ? 'pill-done' : ($s === 'pending' ? 'pill-pending' : 'pill-cancel');
                    ?>
                    <span class="pill <?= $cls ?>"><?= ucfirst($o['status']) ?></span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    </div>
  </div>

</body>

</html>