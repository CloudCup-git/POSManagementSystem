<?php
/**
 * CloudCup — Finance: Startup & Capital Costs
 */
require __DIR__ . '/includes/finance_data.php';

$activePage = 'startup';
$pageTitle  = 'Finance — Startup & Capital Costs';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<script src="../js/sidebar-toggle.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Startup & Capital Costs — CloudCup Finance</title>
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
            <div><div class="kpi-label">TOTAL INVESTED</div></div>
            <div class="kpi-icon icon-orange">₱</div>
          </div>
          <div class="kpi-value"><?= money($totalStartupCosts) ?></div>
          <div class="kpi-sub">reference only — not deducted from Net Profit</div>
        </div>
      </div>

      <div class="section-header"><h2>Startup &amp; Capital Costs</h2><div class="line"></div>
        <a class="btn-export" href="finance_export.php?report=startup&<?= htmlspecialchars($rangeQuery) ?>">⭳ Export to Excel</a>
        <a href="expense_add_startup.php?range=<?= htmlspecialchars($range) ?>"><button type="button" class="btn-add">+ Add Startup / Capital Expense</button></a>
      </div>
      <div class="panel" style="margin-bottom:16px;">
        <div class="panel-sub" style="margin-bottom:14px;">One-time investment (equipment, permits, initial buildout) — reference only, not deducted from recurring Net Profit.</div>
        <?php if ($startupRows): ?>
        <table>
          <thead><tr><th>Date</th><th>Category</th><th>Description</th><th style="text-align:right;">Amount</th></tr></thead>
          <tbody>
            <?php foreach ($startupRows as $s): ?>
            <tr>
              <td class="muted"><?= (new DateTime($s['expense_date']))->format('M d, Y') ?></td>
              <td><?= htmlspecialchars($s['category']) ?></td>
              <td class="muted"><?= htmlspecialchars($s['description'] ?: '—') ?></td>
              <td class="num"><?= money($s['amount']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr><td colspan="3"><strong>Total invested</strong></td><td class="num"><strong><?= money($totalStartupCosts) ?></strong></td></tr>
          </tbody>
        </table>
        <?php else: ?>
          <div class="empty-state">No startup or capital costs logged yet.</div>
        <?php endif; ?>
      </div>

    </div>
  </div>

<?php if (($_GET['added'] ?? '') === '1'): ?>
<script>
Swal.fire({
  icon: 'success',
  title: 'Expense logged',
  text: 'Startup / capital expense saved successfully.',
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
