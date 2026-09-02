<?php
/**
 * CloudCup — Finance: Cost of Goods Sold
 */
require __DIR__ . '/includes/finance_data.php';

$activePage = 'cogs';
$pageTitle  = 'Finance — Cost of Goods Sold';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<script src="../js/sidebar-toggle.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cost of Goods Sold — CloudCup Finance</title>
<script src="js/chart.umd.min.js"></script>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="css/finance.css">
</head>
<body>

  <?php include __DIR__ . '/includes/finance_sidebar.php'; ?>

  <div class="main">
    <?php include __DIR__ . '/includes/finance_topbar.php'; ?>

    <div class="content">

      <?php include __DIR__ . '/includes/finance_filterbar.php'; ?>

      <div class="kpi-grid" style="margin-bottom:16px;">
        <div class="kpi-card">
          <div class="kpi-top">
            <div><div class="kpi-label">COST OF GOODS SOLD</div></div>
            <div class="kpi-icon icon-red">₱</div>
          </div>
          <div class="kpi-value"><?= money($cogs) ?></div>
          <div class="kpi-sub">from ingredient &amp; supply usage</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top">
            <div><div class="kpi-label">GROSS PROFIT</div></div>
            <div class="kpi-icon icon-green">₱</div>
          </div>
          <div class="kpi-value <?= $grossProfit >= 0 ? 'value-green' : 'value-red' ?>"><?= money($grossProfit) ?></div>
          <div class="kpi-sub"><?= number_format($grossMargin, 1) ?>% gross margin</div>
        </div>
      </div>

      <div class="section-header"><h2>Cost of Goods Sold</h2><a class="btn-export" href="finance_export.php?report=cogs&<?= htmlspecialchars($rangeQuery) ?>">⭳ Export to Excel</a><div class="line"></div></div>
      <div class="grid-2b">
        <div class="panel">
          <div class="panel-title">Top Selling Items</div>
          <?php if ($topItems): ?>
          <table>
            <thead><tr><th>Item</th><th>Category</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Revenue</th></tr></thead>
            <tbody>
              <?php foreach ($topItems as $it): ?>
              <tr>
                <td><?= htmlspecialchars($it['item_name']) ?></td>
                <td class="muted"><?= htmlspecialchars($it['category']) ?></td>
                <td style="text-align:right;"><?= (int) $it['qty'] ?></td>
                <td class="num"><?= money($it['revenue']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
            <div class="empty-state">No item sales in this range.</div>
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
      </div>

    </div>
  </div>

<script>
window.financeData = {
  cogsByCategory: <?= $cogsByCategory ? json_encode([
      'labels' => array_map(fn($r) => $r['category'] ?: 'Uncategorized', $cogsByCategory),
      'data'   => array_map(fn($r) => (float) $r['cost'], $cogsByCategory),
  ]) : 'null' ?>
};
</script>
<script src="js/finance.js"></script>
</body>
</html>
