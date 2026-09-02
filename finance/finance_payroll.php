<?php
/**
 * CloudCup — Finance: Payroll & Loans
 */
require __DIR__ . '/includes/finance_data.php';

$activePage = 'payroll';
$pageTitle  = 'Finance — Payroll & Loans';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<script src="../js/sidebar-toggle.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payroll & Loans — CloudCup Finance</title>
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
            <div><div class="kpi-label">OUTSTANDING LOANS</div></div>
            <div class="kpi-icon icon-orange">₱</div>
          </div>
          <div class="kpi-value"><?= money($outstandingLoans) ?></div>
          <div class="kpi-sub"><?= count($loanRows) ?> active employee loan<?= count($loanRows) === 1 ? '' : 's' ?></div>
        </div>
      </div>

      <div class="section-header"><h2>Payroll &amp; Loans</h2><a class="btn-export" href="finance_export.php?report=payroll&<?= htmlspecialchars($rangeQuery) ?>">⭳ Export to Excel</a><div class="line"></div></div>
      <div class="grid-2b">
        <div class="panel">
          <div class="panel-title">Payroll (Released)</div>
          <div class="panel-sub">Payslips whose pay period overlaps the selected range</div>
          <?php if ($payrollRows): ?>
          <table>
            <thead><tr><th>Employee</th><th>Period</th><th style="text-align:right;">Gross Pay</th><th style="text-align:right;">Net Pay</th></tr></thead>
            <tbody>
              <?php foreach ($payrollRows as $p): ?>
              <tr>
                <td><?= htmlspecialchars($p['full_name']) ?></td>
                <td class="muted"><?= (new DateTime($p['period_start']))->format('M d') ?>–<?= (new DateTime($p['period_end']))->format('M d, Y') ?></td>
                <td class="num"><?= money($p['gross_pay']) ?></td>
                <td class="num"><?= money($p['net_pay']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
            <div class="empty-state">No released payroll for this period yet.</div>
          <?php endif; ?>
        </div>

        <div class="panel">
          <div class="panel-title">Active Employee Loans</div>
          <div class="panel-sub">Current standing — not restricted to the selected range</div>
          <?php if ($loanRows): ?>
          <table>
            <thead><tr><th>Employee</th><th style="text-align:right;">Principal</th><th style="text-align:right;">Monthly</th><th style="text-align:right;">Balance</th></tr></thead>
            <tbody>
              <?php foreach ($loanRows as $l): ?>
              <tr>
                <td><?= htmlspecialchars($l['full_name']) ?></td>
                <td class="num"><?= money($l['principal']) ?></td>
                <td class="num"><?= money($l['monthly_installment']) ?></td>
                <td class="num"><?= money($l['remaining_balance']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
            <div class="empty-state">No active employee loans.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="footnote">
        Payroll cost includes employer-side SSS/PhilHealth/Pag-IBIG contributions. Loan balances are shown for
        reference and are not subtracted from Net Profit — installments are already recovered through payroll deductions.
      </div>

    </div>
  </div>
</body>
</html>
