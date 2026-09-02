<?php
/**
 * CloudCup — Finance: Budget Planner
 * Deliberately NOT in the sidebar — same pattern as
 * expense_add_operating.php: reached only via a button on its
 * parent report page (here, finance_budgeting.php). $activePage
 * is still set to 'budgeting' so the sidebar highlights the
 * right parent item while the user is on this sub-page.
 */
require __DIR__ . '/includes/finance_budgeting_data.php';

$activePage = 'budgeting';
$pageTitle  = 'Finance — Budget Planner';
$canEditBudgets = in_array(strtolower($_SESSION['role'] ?? ''), ['finance', 'admin'], true);

/* Small planner-only summary stats — kept local to this file so we
   don't have to touch includes/finance_budgeting_data.php. */
$budgetRemainingPlanner = $budgetYearTotal - $actualYearTotal;
$categoriesOverBudgetPlanner = 0;
foreach (BUDGET_CATEGORIES as $__cat) {
    if ($budgetRowTotal[$__cat] > 0 && $actualRowTotal[$__cat] > $budgetRowTotal[$__cat]) {
        $categoriesOverBudgetPlanner++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<script src="../js/tab_session_guard.js"></script>
<script src="../js/sidebar-toggle.js"></script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Budget Planner — CloudCup Finance</title>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="css/finance.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
  .back-link{
    display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600;
    color:var(--text-mid); text-decoration:none; margin-bottom:18px;
  }
  .back-link:hover{ color:var(--caramel); }

  /* ---------- year switcher ---------- */
  .year-switcher{
    display:flex; align-items:center; justify-content:space-between; gap:12px;
    margin-bottom:22px; flex-wrap:wrap;
  }
  .year-switcher-left{ display:flex; align-items:center; gap:10px; }
  .year-switcher label{ font-size:12.5px; color:var(--text-mid); font-weight:700; text-transform:uppercase; letter-spacing:.03em; }
  .year-nav{ display:flex; align-items:center; gap:6px; }
  .year-nav-btn{
    width:30px; height:30px; border-radius:8px; border:1px solid rgba(44,92,130,0.15);
    background:var(--white); color:var(--text); font-size:14px; font-weight:700; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
  }
  .year-nav-btn:hover{ background:var(--cream-light); }
  .year-switcher select{
    padding:8px 14px; border-radius:8px; border:1px solid rgba(44,92,130,0.15);
    font-size:13.5px; font-weight:700; color:var(--text); background:var(--white);
  }

  /* ---------- tabs ---------- */
  .planner-tabs{ display:flex; gap:6px; background:var(--cream-light); padding:5px; border-radius:12px; width:fit-content; margin-bottom:20px; }
  .planner-tab{
    padding:9px 18px; border-radius:9px; border:none; background:transparent;
    font-size:13.5px; font-weight:700; color:var(--text-mid); cursor:pointer;
  }
  .planner-tab.active{ background:var(--navy-800, #1d1610); color:#fff; }
  .tab-panel{ display:none; }
  .tab-panel.active{ display:block; }

  /* ---------- KPI row (reuses .kpi-card from finance.css) ---------- */
  .planner-kpis{ margin-bottom:22px; }

  /* ---------- grid ---------- */
  .grid-table-wrap{ overflow:auto; max-height:520px; border-radius:10px; }
  table.budget-grid{ min-width:960px; border-collapse:separate; border-spacing:0; }
  table.budget-grid thead th{
    position:sticky; top:0; z-index:2;
    background:var(--navy-800, #1d1610); color:#fff;
    font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em;
    padding:11px 8px; text-align:right; border:none;
  }
  table.budget-grid thead th:first-child{ text-align:left; position:sticky; left:0; z-index:3; }
  table.budget-grid th:last-child, table.budget-grid td:last-child{ text-align:right; }
  table.budget-grid td{ text-align:right; padding:9px 6px; font-size:13px; font-variant-numeric:tabular-nums; border-bottom:1px solid var(--cream-light); }
  table.budget-grid td:first-child{
    text-align:left; position:sticky; left:0; z-index:1; background:var(--white);
    font-weight:600; color:var(--text); white-space:nowrap;
  }
  table.budget-grid tbody tr:nth-child(even) td{ background:var(--cream-light); }
  table.budget-grid tbody tr:nth-child(even) td:first-child{ background:var(--cream-light); }
  table.budget-grid tbody tr:hover td{ background:rgba(59,130,192,0.06); }
  table.budget-grid tbody tr:hover td:first-child{ background:rgba(59,130,192,0.06); }
  table.budget-grid input{
    width:74px; text-align:right; border:none; border-bottom:1.5px solid rgba(44,92,130,0.15);
    border-radius:0; padding:5px 4px; font-size:12.5px; background:transparent;
    font-variant-numeric:tabular-nums; transition:border-color .15s, background-color .15s;
  }
  table.budget-grid input:focus{ outline:none; border-bottom-color:var(--blue-600, #b8703f); background:rgba(59,130,192,0.07); }
  table.budget-grid input:disabled{ border-bottom-color:transparent; color:var(--text-mid); }
  table.budget-grid td.row-total-cell{ background:rgba(59,130,192,0.08) !important; font-weight:800; color:var(--navy-800, #1d1610); }
  table.budget-grid tfoot td{ position:sticky; bottom:0; background:var(--navy-900, #161009); color:#fff; font-weight:800; border:none; padding:11px 8px; }
  table.budget-grid tfoot td:first-child{ position:sticky; left:0; bottom:0; z-index:4; }

  /* ---------- sticky save bar ---------- */
  .save-bar{
    position:sticky; bottom:0; display:flex; align-items:center; gap:14px;
    margin-top:16px; padding:14px 18px; background:#fff; border:1px solid rgba(44,92,130,0.1);
    border-radius:12px; box-shadow:0 -4px 14px rgba(15,36,56,0.06);
  }
  .save-status{ font-size:12.5px; font-weight:600; color:var(--text-light); }
  .save-status.ok{ color:var(--success); }
  .save-status.err{ color:var(--danger); }

  /* ---------- variance list ---------- */
  .variance-list{ display:flex; flex-direction:column; gap:10px; }
  .variance-row{
    display:grid; grid-template-columns:1.4fr 1fr 1fr auto; align-items:center; gap:14px;
    padding:14px 16px; border:1px solid rgba(44,92,130,0.08); border-radius:12px; background:#fff;
  }
  .variance-cat{ font-weight:700; color:var(--text); font-size:13.5px; }
  .variance-nums{ font-size:12px; color:var(--text-mid); }
  .variance-nums b{ color:var(--text); font-weight:700; }
  .variance-bar-track{ height:8px; border-radius:99px; background:var(--cream-light); overflow:hidden; }
  .variance-bar-fill{ height:100%; border-radius:99px; }
  .variance-bar-fill.ok{ background:var(--success); }
  .variance-bar-fill.warn{ background:var(--warning); }
  .variance-bar-fill.over{ background:var(--danger); }
  .variance-pct{ font-size:13px; font-weight:800; text-align:right; min-width:64px; }
  .variance-pct.ok{ color:var(--success); }
  .variance-pct.warn{ color:var(--warning); }
  .variance-pct.over{ color:var(--danger); }
  .variance-pct .sub{ display:block; font-size:10.5px; font-weight:600; color:var(--text-light); }
  @media (max-width:760px){ .variance-row{ grid-template-columns:1fr; gap:6px; } .variance-pct{ text-align:left; } }

  /* ---------- calculator trigger ---------- */
  .btn-calc {
    display:inline-flex; align-items:center; gap:7px;
    background:var(--white); color:var(--text); border:1px solid rgba(44,92,130,0.15);
    border-radius:9px; padding:8px 14px; font-size:13px; font-weight:700; cursor:pointer;
  }
  .btn-calc:hover { background:var(--cream-light); border-color:rgba(44,92,130,0.3); }
  .btn-calc svg { width:15px; height:15px; }

  /* ---------- calculator floating panel (draggable, no backdrop) ---------- */
  .calc-overlay {
    display:none; position:fixed; top:120px; right:40px; z-index:999;
  }
  .calc-overlay.open { display:block; }
  .calc-panel {
    width:260px; background:var(--white); border-radius:16px; overflow:hidden;
    box-shadow:0 18px 50px rgba(11,30,51,0.35); font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
  }
  .calc-header {
    display:flex; align-items:center; justify-content:space-between;
    background:var(--navy-800, #1d1610); color:#fff; padding:12px 14px; font-weight:700; font-size:13px;
    cursor:move; user-select:none; touch-action:none;
  }
  .calc-close { background:none; border:none; color:#fff; font-size:16px; cursor:pointer; line-height:1; opacity:.8; }
  .calc-close:hover { opacity:1; }
  .calc-display-wrap { background:var(--navy-900, #161009); color:#fff; padding:18px 16px 12px; text-align:right; }
  .calc-sub-display { font-size:12px; color:rgba(255,255,255,0.5); min-height:14px; }
  .calc-display { font-size:30px; font-weight:700; font-variant-numeric:tabular-nums; word-break:break-all; }
  .calc-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:1px; background:var(--border, #e9e3d8); }
  .calc-btn {
    border:none; background:var(--white); padding:16px 0; font-size:16px; font-weight:600;
    color:var(--text); cursor:pointer;
  }
  .calc-btn:hover { background:var(--cream-light); }
  .calc-btn.op { background:var(--blue-50, #f4e3d3); color:var(--blue-700, #c98a5c); font-weight:800; }
  .calc-btn.op:hover { background:#dceafa; }
  .calc-btn.accent { background:var(--navy-800, #1d1610); color:#fff; }
  .calc-btn.accent:hover { background:var(--navy-900, #161009); }
  .calc-btn.wide { grid-column:span 2; }
</style>
</head>
<body>

  <?php include __DIR__ . '/includes/finance_sidebar.php'; ?>

  <div class="main">
    <?php include __DIR__ . '/includes/finance_topbar.php'; ?>

    <div class="content">

      <a href="finance_budgeting.php?year=<?= $budgetYear ?>" class="back-link">← Back to Budgeting &amp; Forecasting</a>

      <div class="year-switcher">
        <div class="year-switcher-left">
          <label for="yearSelect">Fiscal Year</label>
          <form method="get" id="yearForm" class="year-nav">
            <button type="button" class="year-nav-btn" onclick="stepYear(-1)" aria-label="Previous year">‹</button>
            <select name="year" id="yearSelect" onchange="document.getElementById('yearForm').submit()">
              <?php for ($y = $currentYear - 2; $y <= $currentYear + 2; $y++): ?>
                <option value="<?= $y ?>" <?= $y === $budgetYear ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
            <button type="button" class="year-nav-btn" onclick="stepYear(1)" aria-label="Next year">›</button>
          </form>
        </div>
        <button type="button" class="btn-calc" id="openCalcBtn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"></rect><line x1="8" y1="6" x2="16" y2="6"></line><line x1="8" y1="10" x2="8" y2="10.01"></line><line x1="12" y1="10" x2="12" y2="10.01"></line><line x1="16" y1="10" x2="16" y2="10.01"></line><line x1="8" y1="14" x2="8" y2="14.01"></line><line x1="12" y1="14" x2="12" y2="14.01"></line><line x1="16" y1="14" x2="16" y2="14.01"></line><line x1="8" y1="18" x2="8" y2="18.01"></line><line x1="12" y1="18" x2="12" y2="18.01"></line><line x1="16" y1="18" x2="16" y2="18.01"></line></svg>
          Calculator
        </button>
      </div>

      <?php if ($unmatchedCategories): ?>
        <div class="assumption-note" style="font-size:12px; color:var(--text-light); background:var(--cream-light); border:1px solid rgba(44,92,130,0.08); border-radius:10px; padding:10px 14px; margin-bottom:16px;">
          ⚠ Note: <?= count($unmatchedCategories) ?> category name<?= count($unmatchedCategories) === 1 ? '' : 's' ?> logged in Operating Expenses for <?= $budgetYear ?> —
          <strong><?= htmlspecialchars(implode(', ', $unmatchedCategories)) ?></strong> — don't match the budget category list below.
        </div>
      <?php endif; ?>

      <!-- ===== SUMMARY KPIs ===== -->
      <div class="kpi-grid planner-kpis">
        <div class="kpi-card">
          <div class="kpi-top"><div><div class="kpi-label">TOTAL BUDGETED</div></div><div class="kpi-icon icon-blue">₱</div></div>
          <div class="kpi-value"><?= money($budgetYearTotal) ?></div>
          <div class="kpi-sub"><?= $budgetYear ?> fiscal year</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top"><div><div class="kpi-label">ACTUAL SO FAR</div></div><div class="kpi-icon icon-orange">₱</div></div>
          <div class="kpi-value"><?= money($actualYearTotal) ?></div>
          <div class="kpi-sub">logged operating expenses</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top"><div><div class="kpi-label">REMAINING</div></div><div class="kpi-icon <?= $budgetRemainingPlanner >= 0 ? 'icon-green' : 'icon-red' ?>"><?= $budgetRemainingPlanner >= 0 ? '₱' : '!' ?></div></div>
          <div class="kpi-value <?= $budgetRemainingPlanner >= 0 ? 'value-green' : 'value-red' ?>"><?= money($budgetRemainingPlanner) ?></div>
          <div class="kpi-sub">budgeted − actual</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top"><div><div class="kpi-label">OVER BUDGET</div></div><div class="kpi-icon <?= $categoriesOverBudgetPlanner > 0 ? 'icon-red' : 'icon-green' ?>"><?= $categoriesOverBudgetPlanner > 0 ? '!' : '₱' ?></div></div>
          <div class="kpi-value <?= $categoriesOverBudgetPlanner > 0 ? 'value-red' : 'value-green' ?>"><?= $categoriesOverBudgetPlanner ?> / <?= count(BUDGET_CATEGORIES) ?></div>
          <div class="kpi-sub">categories</div>
        </div>
      </div>

      <!-- ===== TABS ===== -->
      <div class="planner-tabs">
        <button type="button" class="planner-tab active" data-tab="plan" onclick="switchTab('plan')">Plan Budgets</button>
        <button type="button" class="planner-tab" data-tab="compare" onclick="switchTab('compare')">Budget vs Actual</button>
      </div>

      <!-- ===== TAB: PLAN BUDGETS ===== -->
      <div class="tab-panel active" id="tab-plan">
        <div class="panel-sub" style="margin-bottom:14px;">
          Enter a monthly budget per category for the fiscal year.
          <?= $canEditBudgets ? '' : ' You have read-only access — only Finance and Admin roles can edit budgets.' ?>
        </div>

        <div class="panel">
          <div class="grid-table-wrap">
            <table class="budget-grid" id="budgetGrid">
              <thead>
                <tr>
                  <th>Category</th>
                  <?php foreach (MONTH_LABELS as $m => $label): ?><th><?= $label ?></th><?php endforeach; ?>
                  <th>Year Total</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (BUDGET_CATEGORIES as $cat): ?>
                <tr>
                  <td><?= htmlspecialchars(BUDGET_CATEGORY_LABELS[$cat]) ?></td>
                  <?php foreach (MONTH_LABELS as $m => $label): ?>
                  <td>
                    <input type="number" min="0" step="0.01"
                           data-category="<?= htmlspecialchars($cat) ?>" data-month="<?= $m ?>"
                           value="<?= number_format($budgetGrid[$cat][$m], 2, '.', '') ?>"
                           <?= $canEditBudgets ? '' : 'disabled' ?>>
                  </td>
                  <?php endforeach; ?>
                  <td class="row-total-cell" data-row-total="<?= htmlspecialchars($cat) ?>"><?= money($budgetRowTotal[$cat]) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <td>Total</td>
                  <?php for ($m = 1; $m <= 12; $m++):
                    $colTotal = 0; foreach (BUDGET_CATEGORIES as $cat) { $colTotal += $budgetGrid[$cat][$m]; }
                  ?>
                  <td data-col-total="<?= $m ?>"><?= money($colTotal) ?></td>
                  <?php endfor; ?>
                  <td id="grandTotal"><?= money($budgetYearTotal) ?></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <?php if ($canEditBudgets): ?>
          <div class="save-bar">
            <button type="button" class="btn-add" id="saveBudgetsBtn">Save Budgets</button>
            <span class="save-status" id="saveStatus"></span>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ===== TAB: BUDGET VS ACTUAL ===== -->
      <div class="tab-panel" id="tab-compare">
        <div class="panel-sub" style="margin-bottom:14px;">
          How actual spend compares to budget so far this year. Green = within budget, amber = approaching the limit, red = over budget.
        </div>

        <div class="variance-list">
          <?php foreach (BUDGET_CATEGORIES as $cat):
            $b = $budgetRowTotal[$cat]; $a = $actualRowTotal[$cat];
            $pct = $b > 0 ? ($a / $b) * 100 : ($a > 0 ? 100 : 0);
            $barWidth = min(100, $pct);
            $state = $pct >= 100 ? 'over' : ($pct >= 80 ? 'warn' : 'ok');
          ?>
          <div class="variance-row">
            <div>
              <div class="variance-cat"><?= htmlspecialchars(BUDGET_CATEGORY_LABELS[$cat]) ?></div>
              <div class="variance-nums"><b><?= money($a) ?></b> of <?= money($b) ?> budgeted</div>
            </div>
            <div class="variance-bar-track"><div class="variance-bar-fill <?= $state ?>" style="width:<?= round($barWidth) ?>%;"></div></div>
            <div class="variance-nums" style="text-align:right;">
              <?= $b > 0 ? ($a - $b >= 0 ? '+' : '') . money($a - $b) : '—' ?>
            </div>
            <div class="variance-pct <?= $state ?>">
              <?= $b > 0 ? number_format($pct, 0) . '%' : '—' ?>
              <span class="sub"><?= $state === 'over' ? 'over budget' : ($state === 'warn' ? 'near limit' : 'on track') ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div>
  </div>
   <!-- ===== CALCULATOR POPUP ===== -->
  <div class="calc-overlay" id="calcOverlay">
    <div class="calc-panel">
      <div class="calc-header">
        Calculator
        <button type="button" class="calc-close" id="calcCloseBtn" aria-label="Close calculator">✕</button>
      </div>
      <div class="calc-display-wrap">
        <div class="calc-sub-display" id="calcSubDisplay"></div>
        <div class="calc-display" id="calcDisplay">0</div>
      </div>
      <div class="calc-grid">
        <button type="button" class="calc-btn accent" data-action="clear">C</button>
        <button type="button" class="calc-btn accent" data-action="sign">±</button>
        <button type="button" class="calc-btn accent" data-action="percent">%</button>
        <button type="button" class="calc-btn op" data-op="÷">÷</button>

        <button type="button" class="calc-btn" data-digit="7">7</button>
        <button type="button" class="calc-btn" data-digit="8">8</button>
        <button type="button" class="calc-btn" data-digit="9">9</button>
        <button type="button" class="calc-btn op" data-op="×">×</button>

        <button type="button" class="calc-btn" data-digit="4">4</button>
        <button type="button" class="calc-btn" data-digit="5">5</button>
        <button type="button" class="calc-btn" data-digit="6">6</button>
        <button type="button" class="calc-btn op" data-op="−">−</button>

        <button type="button" class="calc-btn" data-digit="1">1</button>
        <button type="button" class="calc-btn" data-digit="2">2</button>
        <button type="button" class="calc-btn" data-digit="3">3</button>
        <button type="button" class="calc-btn op" data-op="+">+</button>

        <button type="button" class="calc-btn wide" data-digit="0">0</button>
        <button type="button" class="calc-btn" data-digit=".">.</button>
        <button type="button" class="calc-btn accent" data-action="back">⌫</button>
        <button type="button" class="calc-btn accent" data-action="equals" style="grid-column: 4;">=</button>
      </div>
    </div>
  </div>

<script>
function switchTab(name) {
  document.querySelectorAll('.planner-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === 'tab-' + name));
}
function stepYear(delta) {
  const sel = document.getElementById('yearSelect');
  const opts = Array.from(sel.options);
  const idx = opts.findIndex(o => o.selected);
  const next = idx + delta;
  if (next >= 0 && next < opts.length) {
    sel.selectedIndex = next;
    document.getElementById('yearForm').submit();
  }
}
</script>
<script>
window.budgetingData = { year: <?= $budgetYear ?> };
</script>
<script src="js/finance_budgeting.js"></script>
<script src="js/calculator.js"></script>
</body>
</html>