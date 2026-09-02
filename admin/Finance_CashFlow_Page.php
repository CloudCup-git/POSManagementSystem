<?php
/**
 * CloudCup — Admin Finance: Cash Flow
 * -------------------------------------------------------------
 * Same data layer as the standalone Finance module's Cash Flow tab
 * (../finance/finance_cashflow.php), rendered inside the admin
 * panel's own sidebar/topbar/layout instead of the Finance portal's.
 * -------------------------------------------------------------
 */
require __DIR__ . '/../finance/includes/finance_data.php'; // session/role check + $pdo + all KPI vars + money()

$active_page = 'finance-cashflow';
$full_name   = $_SESSION['full_name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cash Flow — CloudCup Admin</title>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="../finance/css/finance.css">
</head>
<body class="cc-admin-shell">
<script src="../js/sidebar-toggle.js"></script>

<?php
if (file_exists(__DIR__ . '/Sidebar_Admin.php')) {
  require_once __DIR__ . '/Sidebar_Admin.php';
} else {
  echo '<div style="background:#f4e3d3;border-bottom:1px solid #a6650f;padding:10px 32px;font-size:13px;color:#a6650f">'
     . '<strong>Sidebar not found.</strong> Expected <code>Sidebar_Admin.php</code> in this folder.</div>';
}
?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <h1>Cash Flow</h1>
    </div>
  </div>

  <div class="content">

    <?php include __DIR__ . '/../finance/includes/finance_filterbar.php'; ?>

    <div class="kpi-grid" style="margin-bottom:16px;">
      <div class="kpi-card">
        <div class="kpi-top">
          <div><div class="kpi-label">NET CASH FLOW (CASH)</div></div>
          <div class="kpi-icon <?= $netCashFlow >= 0 ? 'icon-green' : 'icon-red' ?>">₱</div>
        </div>
        <div class="kpi-value <?= $netCashFlow >= 0 ? 'value-green' : 'value-red' ?>"><?= money($netCashFlow) ?></div>
        <div class="kpi-sub">cash in <?= money($cashIn) ?> − cash out <?= money($cashOutExpenses) ?></div>
      </div>
    </div>

    <div class="section-header"><h2>Cash Flow</h2><a class="btn-export" href="../finance/finance_export.php?report=cashflow&<?= htmlspecialchars($rangeQuery) ?>">⭳ Export to Excel</a><div class="line"></div></div>
    <div class="panel" style="margin-bottom:16px;">
      <div class="panel-sub" style="margin-bottom:16px;">Cash actually moving in and out of the register — separate from GCash/card/bank transactions, which settle outside the drawer.</div>
      <table>
        <thead><tr><th>Flow</th><th style="text-align:right;">Amount</th></tr></thead>
        <tbody>
          <tr><td>Cash sales received</td><td class="num value-green"><?= money($cashIn) ?></td></tr>
          <tr><td>Cash paid out for expenses</td><td class="num value-red">− <?= money($cashOutExpenses) ?></td></tr>
          <tr><td><strong>Net cash movement</strong></td><td class="num <?= $netCashFlow >= 0 ? 'value-green' : 'value-red' ?>"><strong><?= money($netCashFlow) ?></strong></td></tr>
        </tbody>
      </table>
    </div>

  </div>
</div>
</body>
</html>
