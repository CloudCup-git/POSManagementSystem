<?php
/**
 * CloudCup — Finance: Revenue
 */
require __DIR__ . '/includes/finance_data.php';

$activePage = 'revenue';
$pageTitle  = 'Finance — Revenue';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Revenue — CloudCup Finance</title>
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
            <div><div class="kpi-label">TOTAL REVENUE</div></div>
            <div class="kpi-icon icon-blue">₱</div>
          </div>
          <div class="kpi-value"><?= money($revenue) ?></div>
          <div class="kpi-sub"><?= $orderCnt ?> completed orders</div>
        </div>
      </div>

      <div class="section-header"><h2>Revenue</h2><a class="btn-export" href="finance_export.php?report=revenue&<?= htmlspecialchars($rangeQuery) ?>">⭳ Export to Excel</a><div class="line"></div></div>
      <div class="grid-2">
        <div class="panel">
          <div class="panel-title">Revenue Trend</div>
          <div class="chart-box"><canvas id="revenueChart"></canvas></div>
        </div>
        <div class="panel">
          <div class="panel-title">Revenue by Payment Method</div>
          <?php if ($byPaymentMethod): ?>
            <div class="chart-box small"><canvas id="paymentChart"></canvas></div>
          <?php else: ?>
            <div class="empty-state">No completed orders in this range.</div>
          <?php endif; ?>
        </div>
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
  ]) : 'null' ?>
};
</script>
<script src="js/finance.js"></script>
</body>
</html>
