<?php
/**
 * CloudCup — Finance: Cash Flow
 */
require __DIR__ . '/includes/finance_data.php';

$activePage = 'cashflow';
$pageTitle  = 'Finance — Cash Flow';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<script src="../js/sidebar-toggle.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cash Flow — CloudCup Finance</title>
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
            <div><div class="kpi-label">NET CASH FLOW (CASH)</div></div>
            <div class="kpi-icon <?= $netCashFlow >= 0 ? 'icon-green' : 'icon-red' ?>">₱</div>
          </div>
          <div class="kpi-value <?= $netCashFlow >= 0 ? 'value-green' : 'value-red' ?>"><?= money($netCashFlow) ?></div>
          <div class="kpi-sub">cash in <?= money($cashIn) ?> − cash out <?= money($cashOutExpenses) ?></div>
        </div>
      </div>

      <div class="section-header"><h2>Cash Flow</h2><a class="btn-export" href="finance_export.php?report=cashflow&<?= htmlspecialchars($rangeQuery) ?>">⭳ Export to Excel</a><div class="line"></div></div>
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
