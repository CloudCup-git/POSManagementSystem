<?php
/**
 * CloudCup — Finance: Employee Salary Budgeting
 * Read-only visibility into current headcount cost for Finance —
 * a standard-month estimate, not tied to the date filter above.
 * Editing salary rates stays in HR (HR/Employee_Records_Page.php);
 * this page is monitoring only.
 */
require __DIR__ . '/includes/finance_data.php';
require __DIR__ . '/../includes/gov_contributions_2026.php';
require __DIR__ . '/includes/salary_budget_data.php';

$activePage = 'salary_budget';
$pageTitle  = 'Finance — Salary Budgeting';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<script src="../js/sidebar-toggle.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Salary Budgeting — CloudCup Finance</title>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="css/finance.css">
</head>
<body>

  <?php include __DIR__ . '/includes/finance_sidebar.php'; ?>

  <div class="main">
    <?php include __DIR__ . '/includes/finance_topbar.php'; ?>

    <div class="content">

      <div class="section-header" style="margin-top:0;">
        <h2>Employee Salary Budgeting</h2>
        <div class="line"></div>
        <a class="btn-export" href="finance_export.php?report=salary_budget">⭳ Export to Excel</a>
      </div>
      <div class="panel-sub" style="margin:-10px 0 20px 2px;">
        Monitoring only, for Finance's own budgeting — a standard month's estimate, not tied to the date filter
        above. Rates come from HR's employee records.
      </div>

      <div class="kpi-grid" style="margin-bottom:16px;">
        <div class="kpi-card">
          <div class="kpi-top">
            <div><div class="kpi-label">BUDGETED MONTHLY PAYROLL</div></div>
            <div class="kpi-icon icon-blue">₱</div>
          </div>
          <div class="kpi-value"><?= money($budgetTotals['monthly_salary']) ?></div>
          <div class="kpi-sub"><?= count($salaryRows) ?> active employee<?= count($salaryRows) === 1 ? '' : 's' ?></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top">
            <div><div class="kpi-label">TOTAL EST. DEDUCTIONS</div></div>
            <div class="kpi-icon icon-red">₱</div>
          </div>
          <div class="kpi-value"><?= money($budgetTotals['sss'] + $budgetTotals['philhealth'] + $budgetTotals['pagibig']) ?></div>
          <div class="kpi-sub">SSS + PhilHealth + Pag-IBIG (employee share)</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top">
            <div><div class="kpi-label">EST. NET MONTHLY PAYOUT</div></div>
            <div class="kpi-icon icon-green">₱</div>
          </div>
          <div class="kpi-value value-green"><?= money($budgetTotals['net_monthly']) ?></div>
          <div class="kpi-sub">after standard deductions</div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-title">Salary List &amp; Deductions</div>
        <?php if ($salaryRows): ?>
        <table>
          <thead>
            <tr>
              <th>Employee</th><th>Position</th><th>Department</th>
              <th style="text-align:right;">Monthly Salary</th>
              <th style="text-align:right;">SSS</th>
              <th style="text-align:right;">PhilHealth</th>
              <th style="text-align:right;">Pag-IBIG</th>
              <th style="text-align:right;">Est. Net</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($salaryRows as $r): ?>
            <tr<?= $r['no_rate_set'] ? ' class="muted" title="No daily_rate set in HR Employee Records yet"' : '' ?>>
              <td><?= htmlspecialchars($r['full_name']) ?><?= $r['no_rate_set'] ? ' <span class="badge badge-warn">no rate set</span>' : '' ?></td>
              <td class="muted"><?= htmlspecialchars($r['position'] ?? '—') ?></td>
              <td class="muted"><?= htmlspecialchars($r['department'] ?? '—') ?></td>
              <td class="num"><?= money($r['monthly_salary']) ?></td>
              <td class="num value-red">− <?= money($r['sss']) ?></td>
              <td class="num value-red">− <?= money($r['philhealth']) ?></td>
              <td class="num value-red">− <?= money($r['pagibig']) ?></td>
              <td class="num"><strong><?= money($r['net_monthly']) ?></strong></td>
            </tr>
            <?php endforeach; ?>
            <tr>
              <td colspan="3"><strong>Total</strong></td>
              <td class="num"><strong><?= money($budgetTotals['monthly_salary']) ?></strong></td>
              <td class="num value-red">− <?= money($budgetTotals['sss']) ?></td>
              <td class="num value-red">− <?= money($budgetTotals['philhealth']) ?></td>
              <td class="num value-red">− <?= money($budgetTotals['pagibig']) ?></td>
              <td class="num"><strong><?= money($budgetTotals['net_monthly']) ?></strong></td>
            </tr>
          </tbody>
        </table>
        <?php else: ?>
          <div class="empty-state">No active employees found, or the HR <code>employees</code> table isn't linked yet.</div>
        <?php endif; ?>
      </div>

      
    </div>
  </div>
</body>
</html>
