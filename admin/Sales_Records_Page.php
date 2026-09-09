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
        'dollar'      => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
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
    $admin_sales_total = (int)(mysqli_fetch_assoc($count_res)['c'] ?? 0);
    $admin_total_pages = max(1, (int)ceil($admin_sales_total / $admin_per_page));
    $admin_page        = min($admin_page, $admin_total_pages);
    $offset            = ($admin_page - 1) * $admin_per_page;

    $res = mysqli_query($conn,
        "SELECT o.order_id, o.total_amount, o.payment_method, o.amount_tendered,
                o.change_due, o.status, o.order_type, o.notes, o.ordered_at,
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
         FROM orders o WHERE $where");
    $admin_stats = mysqli_fetch_assoc($stats_res) ?? [];

    // Trailing revenue trend (last 7 days vs the 7 days before that) + daily
    // sparkline points — a real, computed trend, independent of the filter
    // bar above, matching the "vs last week" reading on the revenue card.
    $admin_spark    = array_fill(0, 7, 0.0);
    $admin_rev_pct  = null;
    $trend_res = mysqli_query($conn,
        "SELECT DATE(ordered_at) AS d, COALESCE(SUM(total_amount),0) AS rev
         FROM orders
         WHERE ordered_at >= (CURDATE() - INTERVAL 13 DAY)
         GROUP BY DATE(ordered_at)");
    if ($trend_res) {
        $by_day = [];
        while ($t = mysqli_fetch_assoc($trend_res)) $by_day[$t['d']] = (float)$t['rev'];
        $this_week = 0.0; $last_week = 0.0;
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $admin_spark[6-$i] = $by_day[$d] ?? 0.0;
            $this_week += $admin_spark[6-$i];
        }
        for ($i = 13; $i >= 7; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $last_week += $by_day[$d] ?? 0.0;
        }
        if ($last_week > 0) $admin_rev_pct = number_format((($this_week - $last_week) / $last_week) * 100, 1);
    }
}

// Pre-package each visible order's data for the receipt popup — no AJAX
// round-trip needed since we already fetched everything above.
$admin_receipt_data = [];
foreach ($admin_sales as $sale) {
    $admin_receipt_data[(int) $sale['order_id']] = [
        'order_id'        => (int) $sale['order_id'],
        'ordered_at'      => $sale['ordered_at'],
        'payment_method'  => $sale['payment_method'],
        'order_type'      => $sale['order_type'] ?? 'dine_in',
        'total_amount'    => (float) $sale['total_amount'],
        'amount_tendered' => (float) $sale['amount_tendered'],
        'change_due'      => (float) $sale['change_due'],
        'status'          => $sale['status'],
        'cashier'         => $sale['cashier_name'],
        'items'           => array_map(fn($it) => [
            'name'     => $it['item_name'],
            'qty'      => (int) $it['quantity'],
            'price'    => (float) $it['unit_price'],
            'subtotal' => (float) $it['subtotal'],
        ], $sale['items']),
    ];
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
  <style>
    /* ---- Receipt button + view-only popup (same pattern as Staff's Transaction History) ---- */
    .th-receipt-btn{
      display:inline-flex; align-items:center; gap:5px;
      background:var(--cream-light,#F4EBDB); border:1px solid rgba(44,92,130,.12);
      color:var(--caramel-dark,#96602F); font-size:11.5px; font-weight:600;
      padding:6px 10px; border-radius:8px; cursor:pointer;
      font-family:'Inter', sans-serif; transition:all .15s ease;
    }
    .th-receipt-btn:hover{ border-color:var(--caramel,#B8763E); background:#fff; transform:translateY(-1px); }

    #viewReceiptModal .receipt-modal{
      width:340px; max-height:88vh; display:flex; flex-direction:column;
    }
    #viewReceiptModal .receipt-window{
      height:auto !important; overflow-y:auto; max-height:calc(88vh - 20px);
    }
    #viewReceiptModal .receipt-window::after{ content:none; }
    .th-receipt-close{
      position:absolute; top:10px; right:10px; z-index:2;
      width:26px; height:26px; border-radius:50%; border:none;
      background:rgba(43,23,16,0.06); color:var(--text-light,#8A7666);
      display:flex; align-items:center; justify-content:center;
      cursor:pointer; transition:background .15s ease;
    }
    .th-receipt-close:hover{ background:rgba(43,23,16,0.12); color:var(--text,#2B1710); }
    .vr-void-stamp{
      position:absolute; top:38%; left:50%; transform:translate(-50%,-50%) rotate(-18deg);
      font-family:'IBM Plex Mono', monospace; font-size:44px; font-weight:800;
      letter-spacing:6px; color:#B4503D; border:5px solid #B4503D;
      padding:6px 18px; border-radius:10px; opacity:.75;
      pointer-events:none; z-index:5; mix-blend-mode:multiply;
    }
  </style>
</head>
<body>

<script src="../js/sidebar-toggle.js"></script>
<?php
if (file_exists('../admin/Sidebar_Admin.php')) {
  require_once '../admin/Sidebar_Admin.php';
} else {
  echo '<div style="background:#f5efe6;border-bottom:1px solid #a6650f;padding:10px 32px;font-size:13px;color:#a6650f">'
     . '<strong>Sidebar not found.</strong> Expected <code>Sidebar_Admin.php</code> in this folder.</div>';
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
        <a href="Admin_Dashboard.php" class="widget-action">View Dashboard <?= icon('arrow-right', 14) ?></a>
      </div>

      <!-- SUMMARY STATS -->
      <?php if (!empty($admin_stats)):
        $rev_total   = (float)$admin_stats['total_revenue'];
        $cash_total  = (float)$admin_stats['cash_total'];
        $gcash_total = (float)$admin_stats['gcash_total'];
        $mc_total    = (float)$admin_stats['maya_total'] + (float)$admin_stats['card_total'];
        $pct = fn($part) => $rev_total > 0 ? number_format(($part / $rev_total) * 100, 1) : null;
        $cash_pct  = $pct($cash_total);
        $gcash_pct = $pct($gcash_total);
        $mc_pct    = $pct($mc_total);
      ?>
      <div class="sales-stats-row">
        <div class="stat-card stat-card--orders">
          <div class="stat-icon"><?= icon('receipt', 16) ?></div>
          <div class="stat-label">Total Orders</div>
          <div class="stat-value"><?= number_format((int)$admin_stats['total_orders']) ?></div>
        </div>
        <div class="stat-card stat-card--revenue">
          <div class="stat-icon"><?= icon('dollar', 16) ?></div>
          <div class="stat-label">Total Revenue</div>
          <div class="stat-value">₱<?= number_format($rev_total, 2) ?></div>
          <?php if ($admin_rev_pct !== null): ?>
            <div class="stat-trend"><?= $admin_rev_pct >= 0 ? '↑' : '↓' ?> <?= abs($admin_rev_pct) ?>% <span class="vs">vs last week</span></div>
          <?php else: ?>
            <div class="stat-trend flat">— <span class="vs">Not enough data yet</span></div>
          <?php endif; ?>
          <?php
            $spark_max = max($admin_spark) ?: 1;
            $spark_pts = [];
            foreach ($admin_spark as $i => $v) {
              $x = 4 + $i * (72 / 6);
              $y = 44 - (($v / $spark_max) * 34);
              $spark_pts[] = round($x,1) . ',' . round($y,1);
            }
            $spark_line = implode(' ', $spark_pts);
            $spark_fill = '4,48 ' . $spark_line . ' 76,48';
          ?>
          <svg class="revenue-spark" width="80" height="52" viewBox="0 0 80 52" fill="none" aria-hidden="true">
            <polygon points="<?= $spark_fill ?>" fill="rgba(98,142,144,0.25)"/>
            <polyline points="<?= $spark_line ?>" stroke="var(--caramel,#628e90)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
          </svg>
        </div>
        <div class="stat-card stat-card--cash">
          <div class="stat-icon"><?= icon('receipt', 16) ?></div>
          <div class="stat-label">Cash</div>
          <div class="stat-value">₱<?= number_format($cash_total, 2) ?></div>
          <?php if ($cash_pct !== null): ?>
            <div class="stat-trend"><?= $cash_pct ?>% <span class="vs">of total</span></div>
          <?php else: ?>
            <div class="stat-trend flat">— <span class="vs">No activity this period</span></div>
          <?php endif; ?>
        </div>
        <div class="stat-card stat-card--gcash">
          <div class="stat-icon"><?= icon('receipt', 16) ?></div>
          <div class="stat-label">GCash</div>
          <div class="stat-value">₱<?= number_format($gcash_total, 2) ?></div>
          <?php if ($gcash_pct !== null): ?>
            <div class="stat-trend"><?= $gcash_pct ?>% <span class="vs">of total</span></div>
          <?php else: ?>
            <div class="stat-trend flat">— <span class="vs">No activity this period</span></div>
          <?php endif; ?>
        </div>
        <div class="stat-card stat-card--maya">
          <div class="stat-icon"><?= icon('receipt', 16) ?></div>
          <div class="stat-label">Maya / Card</div>
          <div class="stat-value">₱<?= number_format($mc_total, 2) ?></div>
          <?php if ($mc_pct !== null): ?>
            <div class="stat-trend"><?= $mc_pct ?>% <span class="vs">of total</span></div>
          <?php else: ?>
            <div class="stat-trend flat">— <span class="vs">No activity this period</span></div>
          <?php endif; ?>
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
              $pay_dots = ['cash' => '#2f6f4e', 'card' => '#2f6690', 'gcash' => '#628e90', 'maya' => '#a6650f'];
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
              <th></th>
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
              <td>
                <button type="button" class="th-receipt-btn" title="View receipt"
                        onclick="event.stopPropagation(); viewReceipt(<?= (int) $sale['order_id'] ?>)">
                  <?= icon('receipt', 14) ?> Receipt
                </button>
              </td>
            </tr>
            <!-- Expandable items row -->
            <tr class="items-detail-row" style="display:none">
              <td colspan="10">
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

<!-- VIEW RECEIPT MODAL (read-only lookup — same look as the live checkout receipt) -->
<div class="modal-overlay" id="viewReceiptModal">
  <div class="receipt-modal">
    <button type="button" class="th-receipt-close" onclick="closeViewReceipt()" aria-label="Close">✕</button>
    <div class="vr-void-stamp" id="vrVoidStamp" style="display:none">VOID</div>
    <div class="receipt-window">
      <div class="receipt-content" id="vrContent">
        <div class="receipt-header">
          <div class="receipt-logo">Cloud<span>Cup</span></div>
          <div class="receipt-tagline">Thank you for your visit!</div>
        </div>
        <div class="receipt-order-type-wrap">
          <span class="receipt-order-type" id="vrOrderType"></span>
        </div>
        <hr class="receipt-divider"/>
        <div class="receipt-meta" id="vrMeta"></div>
        <hr class="receipt-divider"/>
        <table class="receipt-items">
          <thead><tr><th>Item</th><th>Qty</th><th>Amount</th></tr></thead>
          <tbody id="vrItems"></tbody>
        </table>
        <hr class="receipt-divider"/>
        <div class="receipt-totals" id="vrTotals"></div>
        <div class="receipt-footer">
          <strong>Enjoy your order!</strong>
          We'd love to see you again soon.
          <div class="powered">Powered by Cloud Cup POS</div>
        </div>
        <div class="modal-actions modal-actions-inline">
          <button class="modal-btn modal-btn-ghost" onclick="printViewReceipt()"><?= icon('receipt', 14) ?> Print</button>
          <button class="modal-btn modal-btn-primary" onclick="closeViewReceipt()">Close</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  const ADMIN_RECEIPT_DATA = <?= json_encode($admin_receipt_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  const payLabelMap  = { cash:'Cash', card:'Card', gcash:'GCash', maya:'Maya' };
  const typeLabelMap = { dine_in:'Dine In', takeout:'Takeout', delivery:'Delivery' };

  function viewReceipt(orderId){
    const data = ADMIN_RECEIPT_DATA[orderId];
    if (!data) return;

    document.getElementById('vrVoidStamp').style.display = (data.status === 'voided') ? 'block' : 'none';

    const ordered  = new Date(data.ordered_at.replace(' ', 'T'));
    const dateStr  = ordered.toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' });
    const timeStr  = ordered.toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit' });
    const payLabel  = payLabelMap[data.payment_method] || data.payment_method || 'Unspecified';
    const typeLabel = typeLabelMap[data.order_type] || data.order_type;

    document.getElementById('vrOrderType').textContent = typeLabel;

    document.getElementById('vrMeta').innerHTML = `
      <span><span>Order No.</span><strong>#${String(data.order_id).padStart(4,'0')}</strong></span>
      <span><span>Date</span><span>${dateStr}</span></span>
      <span><span>Time</span><span>${timeStr}</span></span>
      <span><span>Cashier</span><span>${data.cashier}</span></span>
      <span><span>Payment</span><span>${payLabel}</span></span>`;

    document.getElementById('vrItems').innerHTML = data.items.map(i =>
      `<tr><td>${i.name}</td><td>${i.qty}</td><td>₱${i.subtotal.toFixed(2)}</td></tr>`
    ).join('');

    const subtotal = data.items.reduce((s, i) => s + i.subtotal, 0);
    const otherCharges = data.total_amount - subtotal;

    document.getElementById('vrTotals').innerHTML = `
      <div class="receipt-total-row"><span>Subtotal</span><span>₱${subtotal.toFixed(2)}</span></div>
      ${Math.abs(otherCharges) > 0.005 ? `<div class="receipt-total-row"><span>Tax / Adjustments</span><span>₱${otherCharges.toFixed(2)}</span></div>` : ''}
      <div class="receipt-total-row grand"><span>TOTAL</span><span>₱${data.total_amount.toFixed(2)}</span></div>
      ${data.payment_method === 'cash' ? `
      <div class="receipt-total-row"><span>Cash Tendered</span><span>₱${data.amount_tendered.toFixed(2)}</span></div>
      <div class="receipt-total-row change"><span>Change</span><span>₱${data.change_due.toFixed(2)}</span></div>` : ''}`;

    document.getElementById('viewReceiptModal').classList.add('show');
  }

  function closeViewReceipt(){
    document.getElementById('viewReceiptModal').classList.remove('show');
  }

  function printViewReceipt(){
    const content = document.getElementById('vrContent').innerHTML;
    const w = window.open('', '_blank', 'width=400,height=600');
    w.document.write(`<!DOCTYPE html><html><head>
      <title>Receipt</title>
      <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@700&family=Inter:wght@400;600;700&display=swap" rel="stylesheet"/>
      <style>
        body{font-family:'Inter',sans-serif;padding:20px;max-width:320px;margin:auto}
        .receipt-logo{font-family:'Fraunces',serif;font-size:24px;font-weight:700;color:#161009;text-align:center}
        .receipt-logo span{color:#b8703f}
        .receipt-tagline{text-align:center;font-size:11px;color:#2f6690;margin-top:2px}
        hr,.receipt-divider{border:none;border-top:1px dashed #ccc;margin:10px 0}
        table{width:100%;border-collapse:collapse;font-size:12px}
        th{text-align:left;font-size:9.5px;color:#999;text-transform:uppercase;letter-spacing:.7px;padding-bottom:6px;border-bottom:1px solid #eee}
        th:nth-child(2){text-align:center} th:last-child{text-align:right}
        td{padding:5px 0;vertical-align:top;border-bottom:1px solid #f3f3f3}
        td:nth-child(2){text-align:center;color:#999} td:last-child{text-align:right;font-weight:700}
        .receipt-order-type-wrap{text-align:center;margin:8px 0 4px}
        .receipt-order-type{display:inline-block;font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:#b8703f;border:1px solid #b8703f;padding:3px 10px;border-radius:50px}
        .receipt-meta span{display:flex;justify-content:space-between;font-size:11px;color:#666;margin-bottom:3.5px}
        .receipt-meta span>span:first-child{font-weight:600;color:#335270}
        .receipt-meta span>strong{color:#b8703f;font-size:12px}
        .receipt-total-row{display:flex;justify-content:space-between;font-size:12px;color:#666;padding:3.5px 0}
        .receipt-total-row span:last-child{font-weight:500;color:#161009}
        .receipt-total-row.grand{font-size:15px;font-weight:800;color:#000;border-top:2px solid #eee;padding-top:8px;margin-top:5px}
        .receipt-total-row.grand span:last-child{color:#b8703f;font-size:16px}
        .receipt-total-row.change{color:#2f6f4e;font-weight:700}
        .receipt-total-row.change span:last-child{color:#2f6f4e}
        .receipt-footer{text-align:center;margin-top:14px;padding-top:12px;border-top:1px dashed #ccc;font-size:11px;color:#999;line-height:1.6}
        .receipt-footer strong{display:block;font-size:13px;color:#333;margin-bottom:3px}
        .powered{font-size:9.5px;color:#bbb;margin-top:5px}
        .modal-actions{display:none}
      </style>
    </head><body>${content}</body></html>`);
    w.document.close();
    w.focus();
    setTimeout(() => { w.print(); w.close(); }, 600);
  }

  document.addEventListener('click', (e) => {
    if (e.target === document.getElementById('viewReceiptModal')) closeViewReceipt();
  });
</script>

<script src="../js/sales_records_admin.js?v=3"></script>
</body>
</html>