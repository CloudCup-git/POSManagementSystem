<?php
/**
 * CloudCup — Records of Sales (Admin)
 * Split out from Admin_Page.php so "Records of Sales" is its own
 * page instead of a section admins had to scroll to on the Dashboard.
 */
session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'manager'], true)) {
  header('Location: Role_Panel.php');
  exit;
}
require_once __DIR__ . '/../includes/DB_Connect.php';
$full_name   = $_SESSION['full_name'] ?? 'Manager';
$initials    = strtoupper(substr($full_name, 0, 1));
$active_page = 'sales';

function icon(string $name, int $size = 16): string {
    $paths = [
        'alert'       => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12" y2="17.01"/>',
        'bar-chart'   => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
        'search'      => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'receipt'     => '<path d="M4 2v20l2.5-1.5L9 22l2.5-1.5L14 22l2.5-1.5L19 22V2l-2.5 1.5L14 2l-2.5 1.5L9 2 6.5 3.5Z"/><line x1="8" y1="7" x2="16" y2="7"/><line x1="8" y1="11" x2="16" y2="11"/><line x1="8" y1="15" x2="13" y2="15"/>',
        'calendar'    => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'chevron-down'=> '<polyline points="6 9 12 15 18 9"/>',
        'arrow-right' => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
        'refresh'     => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
    ];
    $d = $paths[$name] ?? $paths['alert'];
    return '<svg class="svg-icon" width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$d.'</svg>';
}

// ── SALES RECORDS ──────────────────────────────────────────────
$admin_sales        = [];
$admin_sales_total  = 0;
$admin_total_pages  = 1;
$admin_page         = max(1, (int)($_GET['page'] ?? 1));
$admin_per_page     = 20;
$admin_date_from    = trim($_GET['date_from'] ?? '');
$admin_date_to      = trim($_GET['date_to']   ?? '');
$admin_method       = trim($_GET['method']    ?? '');
$admin_search       = trim($_GET['q']         ?? '');
$admin_stats        = [];

if ($conn) {
    $where  = '1=1';
    if ($admin_date_from !== '') $where .= " AND DATE(o.ordered_at) >= '" . mysqli_real_escape_string($conn, $admin_date_from) . "'";
    if ($admin_date_to   !== '') $where .= " AND DATE(o.ordered_at) <= '" . mysqli_real_escape_string($conn, $admin_date_to)   . "'";
    if ($admin_method    !== '') $where .= " AND o.payment_method = '"    . mysqli_real_escape_string($conn, $admin_method)    . "'";
    if ($admin_search    !== '') $where .= " AND (o.order_id LIKE '%" . mysqli_real_escape_string($conn, $admin_search) . "%' OR u.full_name LIKE '%" . mysqli_real_escape_string($conn, $admin_search) . "%')";

    $count_res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM orders o LEFT JOIN users u ON o.employee_id = u.user_id WHERE $where");
    if (!$count_res) error_log('Sales_Records_Page count query failed: ' . mysqli_error($conn));
    $admin_sales_total = $count_res ? (int)(mysqli_fetch_assoc($count_res)['c'] ?? 0) : 0;
    $admin_total_pages = max(1, (int)ceil($admin_sales_total / $admin_per_page));
    $admin_page        = min($admin_page, $admin_total_pages);
    $offset            = ($admin_page - 1) * $admin_per_page;

    $res = mysqli_query($conn,
        "SELECT o.order_id, o.total_amount, o.payment_method, o.amount_tendered,
                o.change_due, o.status, o.notes, o.ordered_at,
                COALESCE(u.full_name, 'Unknown') AS cashier_name
         FROM orders o
         LEFT JOIN users u ON o.employee_id = u.user_id
         WHERE $where
         ORDER BY o.ordered_at DESC
         LIMIT $admin_per_page OFFSET $offset");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            // Fetch items for this order
            $items_res = mysqli_query($conn,
                "SELECT item_name, quantity, unit_price, subtotal
                 FROM order_items WHERE order_id = " . (int)$r['order_id']);
            $r['items'] = [];
            if ($items_res) while ($it = mysqli_fetch_assoc($items_res)) $r['items'][] = $it;
            $admin_sales[] = $r;
        }
    }

    // Summary stats
    $stats_res = mysqli_query($conn,
        "SELECT COUNT(*) AS total_orders,
                COALESCE(SUM(total_amount),0) AS total_revenue,
                COALESCE(SUM(CASE WHEN payment_method='cash'  THEN total_amount ELSE 0 END),0) AS cash_total,
                COALESCE(SUM(CASE WHEN payment_method='gcash' THEN total_amount ELSE 0 END),0) AS gcash_total,
                COALESCE(SUM(CASE WHEN payment_method='maya'  THEN total_amount ELSE 0 END),0) AS maya_total,
                COALESCE(SUM(CASE WHEN payment_method='card'  THEN total_amount ELSE 0 END),0) AS card_total
         FROM orders o
         LEFT JOIN users u ON o.employee_id = u.user_id
         WHERE $where");
    if (!$stats_res) error_log('Sales_Records_Page stats query failed: ' . mysqli_error($conn));
    $admin_stats = $stats_res ? (mysqli_fetch_assoc($stats_res) ?? []) : [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Records of Sales — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/sales_processing.css?v=3"/>
</head>
<body>

<?php
if (file_exists(__DIR__ . '/Sidebar_Manager.php')) {
  require_once __DIR__ . '/Sidebar_Manager.php';
} else {
  echo '<div style="background:#f4e3d3;border-bottom:1px solid #a6650f;padding:10px 32px;font-size:13px;color:#a6650f">'
     . '<strong>Sidebar not found.</strong> Expected <code>Sidebar_Manager.php</code> in this folder.</div>';
}
?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <h1>Records of Sales</h1>
    </div>
    <div class="topbar-right">
      <div class="topbar-date"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:5px"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> <?= date('F j, Y') ?></div>
    </div>
  </div>

  <div class="content">

    <div class="widget">
      <div class="widget-header">
        <div class="widget-title">Sales Records</div>
        <a href="Reports_Page.php" class="widget-action">View Reports <?= icon('arrow-right', 14) ?></a>
      </div>

      <!-- SUMMARY STATS -->
      <?php if (!empty($admin_stats)): ?>
      <div class="sales-stats-row">
        <div class="stat-card">
          <div class="stat-label">Total Orders</div>
          <div class="stat-value"><?= number_format((int)$admin_stats['total_orders']) ?></div>
        </div>
        <div class="stat-card stat-card--revenue">
          <div class="stat-label">Total Revenue</div>
          <div class="stat-value">₱<?= number_format((float)$admin_stats['total_revenue'], 2) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Cash</div>
          <div class="stat-value">₱<?= number_format((float)$admin_stats['cash_total'], 2) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">GCash</div>
          <div class="stat-value">₱<?= number_format((float)$admin_stats['gcash_total'], 2) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Maya</div>
          <div class="stat-value">₱<?= number_format((float)$admin_stats['maya_total'], 2) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Card</div>
          <div class="stat-value">₱<?= number_format((float)$admin_stats['card_total'], 2) ?></div>
        </div>
      </div>
      <?php endif; ?>

      <!-- FILTER BAR -->
      <form method="GET" action="" class="sales-filter-bar">
        <div class="filter-bar-left">
          <div class="filter-group">
            <label>Search</label>
            <div class="search-row">
              <div class="search-wrap">
                <?= icon('search', 14) ?>
                <input type="text" name="q" value="<?= htmlspecialchars($admin_search) ?>" placeholder="Order # or cashier…"/>
              </div>
              <button type="submit" class="btn-search-icon" aria-label="Search"><?= icon('search', 15) ?></button>
            </div>
          </div>
          <div class="filter-divider"></div>
        </div>

        <div class="filter-bar-right">
        <div class="filter-group field-range" id="rangeBox">
          <label>Date Range</label>
          <div class="box range-toggle" id="rangeToggle" tabindex="0">
            <?= icon('calendar', 15) ?>
            <span class="date-display <?= $admin_date_from === '' ? 'placeholder' : '' ?>" id="fromDisplay"><?= $admin_date_from !== '' ? date('m/d/Y', strtotime($admin_date_from)) : 'mm/dd/yyyy' ?></span>
            <span class="range-sep">–</span>
            <span class="date-display <?= $admin_date_to === '' ? 'placeholder' : '' ?>" id="toDisplay"><?= $admin_date_to !== '' ? date('m/d/Y', strtotime($admin_date_to)) : 'mm/dd/yyyy' ?></span>
          </div>
          <input type="hidden" name="date_from" id="dateFromInput" value="<?= htmlspecialchars($admin_date_from) ?>"/>
          <input type="hidden" name="date_to" id="dateToInput" value="<?= htmlspecialchars($admin_date_to) ?>"/>

          <div class="cal-pop" id="calPop">
            <div class="cal-head">
              <div class="cal-title" id="calTitle">&nbsp;</div>
              <div class="cal-nav">
                <button type="button" id="calPrev" aria-label="Previous month">‹</button>
                <button type="button" id="calNext" aria-label="Next month">›</button>
              </div>
            </div>
            <div class="cal-weekdays"><span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span></div>
            <div class="cal-grid" id="calGrid"></div>
            <div class="cal-foot">
              <span class="cal-link" id="calClear">Clear</span>
              <span class="cal-hint" id="calHint">Pick a start date</span>
            </div>
          </div>
        </div>

        <div class="filter-group field-payment" id="paymentField">
          <label>Payment</label>
          <div class="box select-box" id="paymentBox" tabindex="0">
            <span class="payment-dot" id="paymentDot"></span>
            <span id="paymentValue"><?= $admin_method === '' ? 'All' : ucfirst($admin_method) ?></span>
            <?= icon('chevron-down', 14) ?>
          </div>
          <input type="hidden" name="method" id="methodInput" value="<?= htmlspecialchars($admin_method) ?>"/>
          <div class="select-pop" id="paymentPop">
            <div class="select-opt <?= $admin_method === '' ? 'active' : '' ?>" data-value="" data-dot="#9c9184">All</div>
            <?php
              $pay_dots = ['cash' => '#2f6f4e', 'card' => '#2f6690', 'gcash' => '#b8703f', 'maya' => '#a6650f'];
              foreach (['cash','card','gcash','maya'] as $m):
            ?>
              <div class="select-opt <?= $admin_method === $m ? 'active' : '' ?>" data-value="<?= $m ?>" data-dot="<?= $pay_dots[$m] ?>"><?= ucfirst($m) ?></div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="filter-actions">
          <a href="Sales_Records_Page.php" class="btn-icon-reset" title="Clear filters" aria-label="Clear filters"><?= icon('refresh', 15) ?></a>
          <button type="submit" class="btn-filter">Filter<span class="dot"></span></button>
        </div>
        </div>
      </form>

      <!-- TRANSACTIONS TABLE -->
      <div class="sales-table-wrap">
        <?php if (empty($admin_sales)): ?>
        <div style="text-align:center;padding:60px 20px;color:var(--text-light)">
          <?= icon('receipt', 48) ?>
          <p style="margin-top:12px">No transactions found.</p>
        </div>
        <?php else: ?>
        <table class="sales-table">
          <thead>
            <tr>
              <th>Order #</th>
              <th>Date &amp; Time</th>
              <th>Cashier</th>
              <th>Items</th>
              <th>Payment</th>
              <th style="text-align:right">Total</th>
              <th style="text-align:right">Tendered</th>
              <th style="text-align:right">Change</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($admin_sales as $sale): ?>
            <tr class="sales-row" onclick="toggleOrderItems(this)">
              <td><strong>#<?= (int)$sale['order_id'] ?></strong></td>
              <td><?= date('M d, Y g:i A', strtotime($sale['ordered_at'])) ?></td>
              <td><?= htmlspecialchars($sale['cashier_name']) ?></td>
              <td class="items-preview">
                <?php
                  $names = array_map(fn($i) => $i['quantity'].'× '.$i['item_name'], $sale['items']);
                  echo htmlspecialchars(implode(', ', array_slice($names, 0, 3)));
                  if (count($names) > 3) echo ' <span class="more-badge">+' . (count($names)-3) . ' more</span>';
                ?>
              </td>
              <td>
                <span class="pay-badge pay-badge--<?= htmlspecialchars($sale['payment_method']) ?>">
                  <?= strtoupper(htmlspecialchars($sale['payment_method'])) ?>
                </span>
              </td>
              <td style="text-align:right"><strong>₱<?= number_format((float)$sale['total_amount'], 2) ?></strong></td>
              <td style="text-align:right">₱<?= number_format((float)$sale['amount_tendered'], 2) ?></td>
              <td style="text-align:right">₱<?= number_format((float)$sale['change_due'], 2) ?></td>
              <td><span class="status-badge status-badge--<?= htmlspecialchars($sale['status']) ?>"><?= ucfirst(htmlspecialchars($sale['status'])) ?></span></td>
            </tr>
            <!-- Expandable items row -->
            <tr class="items-detail-row" style="display:none">
              <td colspan="9">
                <table class="items-detail-table">
                  <thead><tr><th>Item</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th></tr></thead>
                  <tbody>
                    <?php foreach ($sale['items'] as $it): ?>
                    <tr>
                      <td><?= htmlspecialchars($it['item_name']) ?></td>
                      <td><?= (int)$it['quantity'] ?></td>
                      <td>₱<?= number_format((float)$it['unit_price'], 2) ?></td>
                      <td>₱<?= number_format((float)$it['subtotal'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ($sale['notes']): ?>
                    <tr><td colspan="4" style="color:var(--text-light);font-style:italic">Note: <?= htmlspecialchars($sale['notes']) ?></td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <!-- PAGINATION -->
        <?php $start = ($admin_page - 1) * $admin_per_page + 1; $end = min($admin_page * $admin_per_page, $admin_sales_total); ?>
        <div class="pagination">
          <div class="pagination-info">Showing <?= $start ?>–<?= $end ?> of <?= $admin_sales_total ?> transactions</div>
          <div class="page-btns">
            <?php if ($admin_page > 1): ?>
              <a class="page-btn" href="?page=<?= $admin_page-1 ?>&q=<?= urlencode($admin_search) ?>&date_from=<?= urlencode($admin_date_from) ?>&date_to=<?= urlencode($admin_date_to) ?>&method=<?= urlencode($admin_method) ?>">‹</a>
            <?php endif; ?>
            <?php for ($i = max(1, $admin_page-2); $i <= min($admin_total_pages, $admin_page+2); $i++): ?>
              <a class="page-btn <?= $i === $admin_page ? 'active' : '' ?>"
                 href="?page=<?= $i ?>&q=<?= urlencode($admin_search) ?>&date_from=<?= urlencode($admin_date_from) ?>&date_to=<?= urlencode($admin_date_to) ?>&method=<?= urlencode($admin_method) ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($admin_page < $admin_total_pages): ?>
              <a class="page-btn" href="?page=<?= $admin_page+1 ?>&q=<?= urlencode($admin_search) ?>&date_from=<?= urlencode($admin_date_from) ?>&date_to=<?= urlencode($admin_date_to) ?>&method=<?= urlencode($admin_method) ?>">›</a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<script src="../js/sales_records_admin.js?v=3"></script>
</body>
</html>
