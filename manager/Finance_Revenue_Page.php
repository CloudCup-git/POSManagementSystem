<?php
/**
 * CloudCup — Manager Finance: Revenue
 * -------------------------------------------------------------
 * Same data layer as the standalone Finance module's Revenue tab
 * (../finance/finance_revenue.php), rendered inside the admin
 * panel's own sidebar/topbar/layout instead of the Finance portal's.
 * -------------------------------------------------------------
 */
require __DIR__ . '/../finance/includes/finance_data.php'; // session/role check + $pdo + all KPI vars + money()

/* -----------------------------------------------------------
   Extra access gate — this admin-panel-styled view is manager-only
   now (branch-scoped operational finance). finance_data.php's own
   check still allows 'finance' and 'admin' through since it's a
   shared file used by other pages, so this page needs its own
   stricter check on top of that.
----------------------------------------------------------- */
if (strtolower($_SESSION['role'] ?? '') !== 'manager') {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restricted — CloudCup Manager</title>
    <link rel="stylesheet" href="../css/admin_page.css">
    <link rel="stylesheet" href="../finance/css/finance.css">
    </head>
    <body>
      <div style="max-width:520px;margin:80px auto;text-align:center;font-family:sans-serif;color:#241f19">
        <h2>Restricted</h2>
        <p>This page is only available to Manager accounts.</p>
      </div>
    </body>
    </html>
    <?php
    exit;
}

$active_page = 'finance-revenue';
$full_name   = $_SESSION['full_name'] ?? 'Manager';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Revenue — CloudCup Manager</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="../finance/css/finance.css">
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
      <h1>Revenue</h1>
    </div>
  </div>

  <div class="content">

    <?php include __DIR__ . '/../finance/includes/finance_filterbar.php'; ?>

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

    <div class="section-header"><h2>Revenue</h2><a class="btn-export" href="../finance/finance_export.php?report=revenue&<?= htmlspecialchars($rangeQuery) ?>">⭳ Export to Excel</a><div class="line"></div></div>
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
<script src="../finance/js/finance.js"></script>
</body>
</html>
