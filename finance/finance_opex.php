<?php
/**
 * CloudCup — Finance: Operating Expenses
 */
require __DIR__ . '/includes/finance_data.php';

$activePage = 'opex';
$pageTitle  = 'Finance — Operating Expenses';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Operating Expenses — CloudCup Finance</title>
<script src="js/chart.umd.min.js"></script>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="css/finance.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            <div><div class="kpi-label">OPERATING EXPENSES</div></div>
            <div class="kpi-icon icon-orange">₱</div>
          </div>
          <div class="kpi-value"><?= money($totalOpEx) ?></div>
          <div class="kpi-sub">wages <?= money($payrollCost) ?> + other <?= money($loggedOpEx) ?></div>
        </div>
      </div>

      <div class="section-header"><h2>Operating Expenses</h2><div class="line"></div>
        <a href="expense_add_operating.php?range=<?= htmlspecialchars($range) ?>"><button type="button" class="btn-add">+ Add Operating Expense</button></a>
      </div>
      <div class="grid-2">
        <div class="panel">
          <div class="panel-title">Logged Expenses</div>
          <div class="panel-sub">Rent or Mortgage, Utilities, and Maintenance and Repairs — Cost of Goods Sold and Labor &amp; Payroll are tracked automatically and don't need to be logged here.</div>
          <?php if ($opExRows): ?>
          <table>
            <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Paid Via</th><th style="text-align:right;">Amount</th></tr></thead>
            <tbody>
              <?php foreach ($opExRows as $e): ?>
              <tr>
                <td class="muted"><?= (new DateTime($e['expense_date']))->format('M d, Y') ?></td>
                <td><?= htmlspecialchars($e['category']) ?></td>
                <td class="muted"><?= htmlspecialchars($e['description'] ?: '—') ?></td>
                <td><span class="badge badge-<?= htmlspecialchars($e['payment_method']) ?>"><?= htmlspecialchars(str_replace('_', ' ', $e['payment_method'])) ?></span></td>
                <td class="num"><?= money($e['amount']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
            <div class="empty-state">No operating expenses logged for this range yet. Click "+ Add Expense" to record rent, utilities, or other bills.</div>
          <?php endif; ?>
        </div>
        <div class="panel">
          <div class="panel-title">Full Cost Breakdown</div>
          <div class="panel-sub">The 5 categories under Operating Expenses: Cost of Goods Sold, Labor and Payroll, Rent or Mortgage, Utilities, and Maintenance and Repairs — Gross Profit above is still Revenue − COGS on its own.</div>
          <?php if ($opexUnifiedBreakdown): ?>
            <table>
              <tbody>
                <?php foreach ($opexUnifiedBreakdown as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['category']) ?></td>
                  <td class="num"><?= money($row['total']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <div class="chart-box small" style="margin-top:14px;"><canvas id="opExChart"></canvas></div>
          <?php else: ?>
            <div class="empty-state">Nothing to chart yet.</div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>

<script>
window.financeData = {
  opExByCategory: <?= $opexUnifiedBreakdown ? json_encode([
      'labels' => array_map(fn($r) => $r['category'], $opexUnifiedBreakdown),
      'data'   => array_map(fn($r) => (float) $r['total'], $opexUnifiedBreakdown),
  ]) : 'null' ?>
};
</script>
<script src="js/finance.js"></script>
<?php if (($_GET['added'] ?? '') === '1'): ?>
<script>
Swal.fire({
  icon: 'success',
  title: 'Expense logged',
  text: 'Operating expense saved successfully.',
  timer: 3000,
  timerProgressBar: true,
  toast: true,
  position: 'top-end',
  showConfirmButton: false
});
</script>
<?php endif; ?>
</body>
</html>