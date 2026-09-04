<?php
/**
 * CloudCup — Finance Dashboard
 * Visual overview of the selected period: hero KPIs, margin rings,
 * a colored stat strip, an operating-expense breakdown, and charts
 * for revenue trend, payment mix, COGS/OpEx composition, top sellers,
 * cash flow, and profit composition. Every detailed breakdown still
 * lives on its own page, linked from the sidebar — this page is the
 * "at a glance" summary.
 */
require __DIR__ . '/includes/finance_data.php';

$activePage = 'dashboard';
$pageTitle  = 'Finance — Dashboard';

/* ---- ring percentages for the two margin rings (clamped 0–100 for the CSS conic-gradient) ---- */
$grossRingPct = max(0, min(100, $grossMargin));
$netRingPct   = max(0, min(100, $margin));
$netRingColor = $netProfit >= 0 ? 'var(--green-600)' : 'var(--red-600)';

/* ---- % of revenue for the COGS / OpEx mini stat cards ---- */
$cogsPctOfRev = $revenue > 0 ? ($cogs / $revenue) * 100 : 0;
$opexPctOfRev = $revenue > 0 ? ($totalOpEx / $revenue) * 100 : 0;

/* ---- breakdown bar scaling (relative to the largest category) ---- */
$breakdownMax = $opexUnifiedBreakdown ? max(array_column($opexUnifiedBreakdown, 'total')) : 0;

/* ---- cash flow mini-bar scaling ---- */
$cfMax = max($cashIn, $cashOutExpenses, 1);

/* ---- profit composition donut (revenue split into COGS / OpEx / Net Profit) ---- */
$profitComposition = null;
if ($revenue > 0) {
    $profitComposition = [
        'labels' => ['Cost of Goods Sold', 'Operating Expenses', 'Net Profit'],
        'data'   => [round($cogs, 2), round($totalOpEx, 2), round(max(0, $netProfit), 2)],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Finance — CloudCup</title>
<script src="js/chart.umd.min.js"></script>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="css/finance.css">
</head>
<body>

  <?php include __DIR__ . '/includes/finance_sidebar.php'; ?>

  <!-- ===================== MAIN ===================== -->
  <div class="main">
    <?php include __DIR__ . '/includes/finance_topbar.php'; ?>

    <div class="content">

      <?php include __DIR__ . '/includes/finance_filterbar.php'; ?>

      <!-- ===== Hero row: revenue + margin rings ===== -->
      <div class="hero-grid">
        <div class="hero-card">
          <div class="hero-icon-circle">₱</div>
          <div>
            <div class="hero-label">Total Revenue</div>
            <div class="hero-value"><?= money($revenue) ?></div>
            <div class="hero-sub"><?= $orderCnt ?> completed order<?= $orderCnt === 1 ? '' : 's' ?></div>
          </div>
        </div>

        <div class="ring-card">
          <div class="ring-visual" style="background:conic-gradient(var(--blue-600) <?= $grossRingPct ?>%, var(--page-bg) <?= $grossRingPct ?>%);">
            <div class="ring-inner"><div class="ring-pct"><?= number_format($grossMargin, 0) ?>%</div></div>
          </div>
          <div>
            <div class="ring-label">Gross Margin</div>
            <div class="ring-value <?= $grossProfit >= 0 ? 'value-green' : 'value-red' ?>"><?= money($grossProfit) ?></div>
            <div class="ring-sub">Revenue − COGS</div>
          </div>
        </div>

        <div class="ring-card">
          <div class="ring-visual" style="background:conic-gradient(<?= $netRingColor ?> <?= $netRingPct ?>%, var(--page-bg) <?= $netRingPct ?>%);">
            <div class="ring-inner"><div class="ring-pct"><?= number_format($margin, 0) ?>%</div></div>
          </div>
          <div>
            <div class="ring-label">Net Margin</div>
            <div class="ring-value <?= $netProfit >= 0 ? 'value-green' : 'value-red' ?>"><?= money($netProfit) ?></div>
            <div class="ring-sub">After all operating expenses</div>
          </div>
        </div>
      </div>

      <div class="dash-section-label">Cost Overview</div>

      <!-- ===== Stat strip + OpEx breakdown + revenue trend ===== -->
      <div class="overview-row">
        <div class="stat-strip">
          <div class="stat-mini stat-mini-cogs">
            <div class="stat-mini-label">Cost of Goods Sold</div>
            <div class="stat-mini-value"><?= money($cogs) ?></div>
            <div class="stat-mini-sub"><?= number_format($cogsPctOfRev, 1) ?>% of revenue</div>
          </div>
          <div class="stat-mini stat-mini-opex">
            <div class="stat-mini-label">Operating Expenses</div>
            <div class="stat-mini-value"><?= money($totalOpEx) ?></div>
            <div class="stat-mini-sub"><?= number_format($opexPctOfRev, 1) ?>% of revenue</div>
          </div>
          <div class="stat-mini stat-mini-loans">
            <div class="stat-mini-label">Outstanding Loans</div>
            <div class="stat-mini-value"><?= money($outstandingLoans) ?></div>
            <div class="stat-mini-sub"><?= count($loanRows) ?> active loan<?= count($loanRows) === 1 ? '' : 's' ?></div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-title">Operating Expense Breakdown</div>
          <div class="panel-sub">Cost of Goods Sold, Labor &amp; Payroll, Rent, Utilities, Maintenance</div>
          <?php if ($opexUnifiedBreakdown): ?>
            <div class="breakdown-list">
              <?php foreach ($opexUnifiedBreakdown as $row):
                $barPct = $breakdownMax > 0 ? ($row['total'] / $breakdownMax) * 100 : 0;
              ?>
                <div class="breakdown-row">
                  <div class="breakdown-row-top">
                    <span class="cat"><?= htmlspecialchars($row['category']) ?></span>
                    <span class="amt"><?= money($row['total']) ?></span>
                  </div>
                  <div class="breakdown-bar-track"><div class="breakdown-bar-fill" style="width:<?= $barPct ?>%;"></div></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="empty-state">Nothing logged for this range yet.</div>
          <?php endif; ?>
        </div>

        <div class="panel">
          <div class="panel-title">Revenue Trend</div>
          <?php if ($dailyRevenue): ?>
            <div class="chart-box"><canvas id="revenueChart"></canvas></div>
          <?php else: ?>
            <div class="empty-state">No completed orders in this range.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="dash-section-label">Breakdowns</div>

      <!-- ===== Composition donuts ===== -->
      <div class="grid-3">
        <div class="panel">
          <div class="panel-title">Revenue by Payment Method</div>
          <?php if ($byPaymentMethod): ?>
            <div class="chart-box small"><canvas id="paymentChart"></canvas></div>
          <?php else: ?>
            <div class="empty-state">No completed orders in this range.</div>
          <?php endif; ?>
        </div>
        <div class="panel">
          <div class="panel-title">COGS by Category</div>
          <?php if ($cogsByCategory): ?>
            <div class="chart-box small"><canvas id="cogsChart"></canvas></div>
          <?php else: ?>
            <div class="empty-state">No inventory usage recorded in this range.</div>
          <?php endif; ?>
        </div>
        <div class="panel">
          <div class="panel-title">Operating Expenses by Category</div>
          <?php if ($opexUnifiedBreakdown): ?>
            <div class="chart-box small"><canvas id="opExChart"></canvas></div>
          <?php else: ?>
            <div class="empty-state">Nothing logged for this range yet.</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ===== Top items + cash flow + profit composition ===== -->
      <div class="grid-3">
        <div class="panel">
          <div class="panel-title">Top Selling Items</div>
          <?php if ($topItems): ?>
            <div class="chart-box small"><canvas id="topItemsChart"></canvas></div>
          <?php else: ?>
            <div class="empty-state">No item sales in this range.</div>
          <?php endif; ?>
        </div>

        <div class="panel">
          <div class="panel-title">Cash Flow (Cash Only)</div>
          <div class="cf-mini-row">
            <div class="cf-mini-top"><span class="cf-mini-name">Cash In</span><span class="cf-mini-amt"><?= money($cashIn) ?></span></div>
            <div class="cf-mini-track"><div class="cf-mini-fill in" style="width:<?= ($cashIn / $cfMax) * 100 ?>%;"></div></div>
          </div>
          <div class="cf-mini-row">
            <div class="cf-mini-top"><span class="cf-mini-name">Cash Out</span><span class="cf-mini-amt"><?= money($cashOutExpenses) ?></span></div>
            <div class="cf-mini-track"><div class="cf-mini-fill out" style="width:<?= ($cashOutExpenses / $cfMax) * 100 ?>%;"></div></div>
          </div>
          <div class="cf-net-box">
            <span class="cf-net-label">Net Cash Flow</span>
            <span class="cf-net-value <?= $netCashFlow >= 0 ? 'value-green' : 'value-red' ?>"><?= money($netCashFlow) ?></span>
          </div>
        </div>

        <div class="panel">
          <div class="panel-title">Revenue Composition</div>
          <?php if ($profitComposition): ?>
            <div class="chart-box small"><canvas id="profitCompositionChart"></canvas></div>
          <?php else: ?>
            <div class="empty-state">No revenue in this range yet.</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ===== Recent transactions ===== -->
      <div class="section-header"><h2>Recent Transactions</h2><a class="btn-export" href="finance_transactions.php?<?= htmlspecialchars($rangeQuery) ?>">View All →</a><div class="line"></div></div>
      <div class="panel">
        <?php if ($recentOrders): ?>
          <table>
            <thead><tr><th>Order</th><th>Date</th><th>Cashier</th><th>Type</th><th>Method</th><th style="text-align:right;">Amount</th></tr></thead>
            <tbody>
              <?php foreach (array_slice($recentOrders, 0, 6) as $o): ?>
              <tr>
                <td>#<?= (int) $o['order_id'] ?></td>
                <td class="muted"><?= (new DateTime($o['ordered_at']))->format('M d, g:i A') ?></td>
                <td><?= htmlspecialchars($o['cashier'] ?? '—') ?></td>
                <td class="muted"><?= htmlspecialchars(ucfirst($o['order_type'] ?? '—')) ?></td>
                <td><span class="badge badge-<?= htmlspecialchars($o['payment_method'] ?: 'unspecified') ?>"><?= htmlspecialchars(str_replace('_', ' ', $o['payment_method'] ?: 'unspecified')) ?></span></td>
                <td class="num"><?= money($o['total_amount']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div class="empty-state">No transactions in this range.</div>
        <?php endif; ?>
      </div>

      <div class="footnote">
        Net Profit = Revenue − COGS − Operating Expenses (staff wages via Payroll + logged rent/utilities/marketing/etc).
        Use the sidebar to drill into Revenue, COGS, Operating Expenses, Cash Flow, Startup Costs, Transactions, or Payroll &amp; Loans.
      </div>

    </div>
  </div>

<script>
window.financeData = {
  revenue: <?= $dailyRevenue ? json_encode([
      'labels' => array_map(fn($r) => (new DateTime($r['d']))->format('M d'), $dailyRevenue),
      'data'   => array_map(fn($r) => (float) $r['rev'], $dailyRevenue),
  ]) : 'null' ?>,
  paymentMethod: <?= $byPaymentMethod ? json_encode([
      'labels' => array_map(fn($r) => ucfirst($r['method']), $byPaymentMethod),
      'data'   => array_map(fn($r) => (float) $r['total'], $byPaymentMethod),
  ]) : 'null' ?>,
  cogsByCategory: <?= $cogsByCategory ? json_encode([
      'labels' => array_map(fn($r) => $r['category'] ?: 'Uncategorized', $cogsByCategory),
      'data'   => array_map(fn($r) => (float) $r['cost'], $cogsByCategory),
  ]) : 'null' ?>,
  opExByCategory: <?= $opexUnifiedBreakdown ? json_encode([
      'labels' => array_map(fn($r) => $r['category'], $opexUnifiedBreakdown),
      'data'   => array_map(fn($r) => (float) $r['total'], $opexUnifiedBreakdown),
  ]) : 'null' ?>,
  topItems: <?= $topItems ? json_encode([
      'labels' => array_map(fn($r) => $r['item_name'], $topItems),
      'data'   => array_map(fn($r) => (float) $r['revenue'], $topItems),
  ]) : 'null' ?>,
  profitComposition: <?= $profitComposition ? json_encode($profitComposition) : 'null' ?>
};
</script>
<script src="js/finance.js"></script>
</body>
</html>