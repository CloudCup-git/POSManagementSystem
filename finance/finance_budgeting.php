<?php
/**
 * CloudCup — Finance: Budgeting & Forecasting
 * Fiscal-year scoped (not the from/to range filter the other
 * report pages use), so this page has its own year selector
 * instead of including finance_filterbar.php.
 */
require __DIR__ . '/includes/finance_budgeting_data.php';

$activePage = 'budgeting';
$pageTitle  = 'Finance — Budgeting & Forecasting';
$canEditBudgets = in_array(strtolower($_SESSION['role'] ?? ''), ['finance', 'admin'], true);

$confBadgeClass = 'confidence-' . $forecast['confidence'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<script src="../js/tab_session_guard.js"></script>
<script src="../js/sidebar-toggle.js"></script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Budgeting &amp; Forecasting — CloudCup Finance</title>
<script src="js/chart.umd.min.js"></script>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="css/finance.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
  .year-switcher { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
  .year-switcher-left { display:flex; align-items:center; gap:12px; }
  .year-switcher select { padding:8px 14px; border-radius:8px; border:1px solid rgba(44,92,130,0.15); font-size:13.5px; font-weight:600; color:var(--text); background:var(--white); }
  .confidence-badge { font-size:11px; font-weight:700; padding:3px 10px; border-radius:50px; margin-left:8px; }
  .confidence-High   { background: rgba(34,197,94,0.12);  color: var(--success); }
  .confidence-Medium { background: rgba(245,158,11,0.12); color: var(--warning); }
  .confidence-Low, .confidence-None { background: rgba(239,68,68,0.12); color: var(--danger); }
  .assumption-note { font-size:12px; color:var(--text-light); background:var(--cream-light); border:1px solid rgba(44,92,130,0.08); border-radius:10px; padding:10px 14px; margin-bottom:16px; }
</style>
</head>
<body>

  <?php include __DIR__ . '/includes/finance_sidebar.php'; ?>

  <div class="main">
    <?php include __DIR__ . '/includes/finance_topbar.php'; ?>

    <div class="content">

      <div class="year-switcher">
        <div class="year-switcher-left">
          <form method="get" id="yearForm" style="display:flex; align-items:center; gap:10px;">
            <label for="yearSelect" style="font-size:13px; color:var(--text-mid); font-weight:600;">Fiscal Year</label>
            <select name="year" id="yearSelect" onchange="document.getElementById('yearForm').submit()">
              <?php for ($y = $currentYear - 2; $y <= $currentYear + 2; $y++): ?>
                <option value="<?= $y ?>" <?= $y === $budgetYear ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </form>
        </div>
      </div>

      <?php if ($unmatchedCategories): ?>
        <div class="assumption-note">
          ⚠ Note: <?= count($unmatchedCategories) ?> category name<?= count($unmatchedCategories) === 1 ? '' : 's' ?> logged in Operating Expenses for <?= $budgetYear ?> —
          <strong><?= htmlspecialchars(implode(', ', $unmatchedCategories)) ?></strong> — don't match the budget category list.
          Their actuals aren't reflected below. Update <code>BUDGET_CATEGORIES</code> in <code>includes/finance_budgeting_data.php</code> to match your real category names.
        </div>
      <?php endif; ?>

      <!-- ===== FORECAST ===== -->
      <div class="section-header">
        <h2>Forecast — <?= $budgetYear ?><span class="confidence-badge <?= $confBadgeClass ?>">Confidence: <?= htmlspecialchars($forecast['confidence']) ?></span></h2>
        <div class="line"></div>
      </div>

      <?php if (!$forecast['available']): ?>
        <div class="empty-state">Not enough order history yet to build a forecast (need at least 2 days with completed orders).</div>
      <?php else: ?>
        <div class="panel-sub" style="margin-bottom:16px;">
          Based on <?= $forecast['daysOfHistory'] ?> day<?= $forecast['daysOfHistory'] === 1 ? '' : 's' ?> of order history since <?= (new DateTime($forecast['firstDate']))->format('M d, Y') ?>.
          Revenue is projected with a linear trend line fitted to daily sales; COGS uses your all-time COGS-to-revenue ratio (<?= number_format($forecast['cogsRatio'] * 100, 1) ?>%); Operating Expenses uses your average logged monthly OpEx + payroll cost.
          <?php if ($forecast['confidence'] === 'Low'): ?>
            <strong>With under 14 days of history, treat this as a rough directional estimate, not a firm number.</strong>
          <?php endif; ?>
        </div>

        <div class="kpi-grid" style="margin-bottom:16px;">
          <div class="kpi-card">
            <div class="kpi-top"><div><div class="kpi-label">FORECASTED REVENUE (<?= $budgetYear ?>)</div></div><div class="kpi-icon icon-blue">₱</div></div>
            <div class="kpi-value"><?= money($forecast['yearRevenue']) ?></div>
            <div class="kpi-sub"><?= $forecast['monthsRemaining'] ?> month<?= $forecast['monthsRemaining'] === 1 ? '' : 's' ?> projected, rest actual</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-top"><div><div class="kpi-label">FORECASTED COGS</div></div><div class="kpi-icon icon-red">₱</div></div>
            <div class="kpi-value"><?= money($forecast['yearCogs']) ?></div>
            <div class="kpi-sub">at <?= number_format($forecast['cogsRatio'] * 100, 1) ?>% of revenue</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-top"><div><div class="kpi-label">FORECASTED OPERATING EXPENSES</div></div><div class="kpi-icon icon-orange">₱</div></div>
            <div class="kpi-value"><?= money($forecast['yearOpEx']) ?></div>
            <div class="kpi-sub">avg <?= money($forecast['avgMonthlyOpEx']) ?>/mo projected</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-top"><div><div class="kpi-label">FORECASTED NET PROFIT</div></div><div class="kpi-icon <?= $forecast['yearNetProfit'] >= 0 ? 'icon-green' : 'icon-red' ?>"><?= $forecast['yearNetProfit'] >= 0 ? '₱' : '!' ?></div></div>
            <div class="kpi-value <?= $forecast['yearNetProfit'] >= 0 ? 'value-green' : 'value-red' ?>"><?= money($forecast['yearNetProfit']) ?></div>
            <div class="kpi-sub">revenue − COGS − OpEx</div>
          </div>
        </div>

        <div class="panel" style="margin-bottom:28px;">
          <div class="panel-title">Monthly Revenue — Actual vs Projected</div>
          <div class="chart-box"><canvas id="forecastChart"></canvas></div>
        </div>
      <?php endif; ?>

      <!-- ===== BUDGETING OVERVIEW ===== -->
      <div class="section-header"><h2>Budgeting Overview — <?= $budgetYear ?></h2><div class="line"></div>
        <a href="finance_budget_planner.php?year=<?= $budgetYear ?>"><button type="button" class="btn-add">✎ Open Budget Planner</button></a>
      </div>
      <div class="panel-sub" style="margin-bottom:14px;">
        <?= $canEditBudgets
            ? 'Enter or edit monthly budgets in the Budget Planner. This page is a read-only summary of how actuals compare.'
            : 'Read-only summary. Only Finance and Admin roles can edit budgets in the Budget Planner.' ?>
      </div>

      <div class="kpi-grid" style="margin-bottom:16px;">
        <div class="kpi-card">
          <div class="kpi-top"><div><div class="kpi-label">TOTAL BUDGETED</div></div><div class="kpi-icon icon-blue">₱</div></div>
          <div class="kpi-value"><?= money($budgetYearTotal) ?></div>
          <div class="kpi-sub">across <?= count(BUDGET_CATEGORIES) ?> categories, <?= $budgetYear ?></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top"><div><div class="kpi-label">TOTAL ACTUAL SPEND</div></div><div class="kpi-icon icon-orange">₱</div></div>
          <div class="kpi-value"><?= money($actualYearTotal) ?></div>
          <div class="kpi-sub">logged operating expenses, <?= $budgetYear ?></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top"><div><div class="kpi-label">BUDGET REMAINING</div></div><div class="kpi-icon <?= $budgetRemaining >= 0 ? 'icon-green' : 'icon-red' ?>"><?= $budgetRemaining >= 0 ? '₱' : '!' ?></div></div>
          <div class="kpi-value <?= $budgetRemaining >= 0 ? 'value-green' : 'value-red' ?>"><?= money($budgetRemaining) ?></div>
          <div class="kpi-sub">budgeted − actual</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top"><div><div class="kpi-label">CATEGORIES OVER BUDGET</div></div><div class="kpi-icon <?= $categoriesOverBudget > 0 ? 'icon-red' : 'icon-green' ?>"><?= $categoriesOverBudget > 0 ? '!' : '₱' ?></div></div>
          <div class="kpi-value <?= $categoriesOverBudget > 0 ? 'value-red' : 'value-green' ?>"><?= $categoriesOverBudget ?> / <?= count(BUDGET_CATEGORIES) ?></div>
          <div class="kpi-sub">actual exceeds budget</div>
        </div>
      </div>

      <div class="grid-2" style="margin-bottom:16px;">
        <div class="panel">
          <div class="panel-title">Actual Spend by Category</div>
          <?php if ($actualYearTotal > 0): ?>
            <div class="chart-box small"><canvas id="allocationChart"></canvas></div>
          <?php else: ?>
            <div class="empty-state">No operating expenses logged for <?= $budgetYear ?> yet.</div>
          <?php endif; ?>
        </div>
        <div class="panel">
          <div class="panel-title">Budget vs Actual by Category</div>
          <?php if ($budgetYearTotal > 0 || $actualYearTotal > 0): ?>
            <div class="chart-box"><canvas id="budgetVsActualChart"></canvas></div>
          <?php else: ?>
            <div class="empty-state">No budgets or actuals to compare yet.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="grid-3-1" style="margin-bottom:28px;">
        <div class="panel">
          <div class="panel-title">Monthly Budget vs Actual Trend</div>
          <?php if ($budgetYearTotal > 0 || $actualYearTotal > 0): ?>
            <div class="chart-box"><canvas id="monthlyTrendChart"></canvas></div>
          <?php else: ?>
            <div class="empty-state">Nothing to chart yet.</div>
          <?php endif; ?>
        </div>
        <div class="panel">
          <div class="panel-title">Highest Expenses</div>
          <?php if ($maxExpenseAmount > 0): ?>
            <?php foreach ($topExpenseCategories as $i => $cat): ?>
              <div class="top-item">
                <div class="top-item-rank <?= $i === 0 ? 'gold' : '' ?>"><?= $i + 1 ?></div>
                <div style="flex:1;">
                  <div class="top-item-name"><?= htmlspecialchars(BUDGET_CATEGORY_LABELS[$cat]) ?></div>
                  <div class="top-item-count"><?= money($actualRowTotal[$cat]) ?></div>
                  <div class="top-item-bar"><div class="top-item-fill" style="width: <?= $maxExpenseAmount > 0 ? round(($actualRowTotal[$cat] / $maxExpenseAmount) * 100) : 0 ?>%;"></div></div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="empty-state">No expenses logged for <?= $budgetYear ?> yet.</div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>

<script>
window.budgetingData = {
  year: <?= $budgetYear ?>,
  forecast: <?= $forecast['available'] ? json_encode([
      'labels'  => array_map(fn($m) => MONTH_LABELS[$m], array_keys($forecast['monthly'])),
      'revenue' => array_values(array_map(fn($r) => round($r['revenue'], 2), $forecast['monthly'])),
      'actual'  => array_values(array_map(fn($r) => $r['actual'], $forecast['monthly'])),
  ]) : 'null' ?>,
  allocation: <?= $actualYearTotal > 0 ? json_encode([
      'labels' => array_values(array_map(fn($c) => BUDGET_CATEGORY_LABELS[$c], array_filter(BUDGET_CATEGORIES, fn($c) => $actualRowTotal[$c] > 0))),
      'data'   => array_values(array_filter(array_map(fn($c) => $actualRowTotal[$c] > 0 ? round($actualRowTotal[$c], 2) : null, BUDGET_CATEGORIES))),
  ]) : 'null' ?>,
  budgetVsActual: <?= json_encode([
      'labels'  => array_map(fn($c) => BUDGET_CATEGORY_LABELS[$c], BUDGET_CATEGORIES),
      'budget'  => array_map(fn($c) => round($budgetRowTotal[$c], 2), BUDGET_CATEGORIES),
      'actual'  => array_map(fn($c) => round($actualRowTotal[$c], 2), BUDGET_CATEGORIES),
  ]) ?>,
  monthlyTrend: <?= json_encode([
      'labels' => array_values(MONTH_LABELS),
      'budget' => array_map(fn($m) => round($monthlyBudgetTotal[$m], 2), range(1, 12)),
      'actual' => array_map(fn($m) => round($monthlyActualTotal[$m], 2), range(1, 12)),
  ]) ?>
};
</script>
<script src="js/finance_budgeting.js"></script>
</body>
</html>