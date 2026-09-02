<?php
/**
 * CloudCup — Finance: Budgeting & Forecasting data layer
 * Requires finance_data.php first for session/login check, $pdo,
 * money(), $rangeQuery (sidebar still needs it even though this
 * page uses a fiscal-year selector, not the from/to range filter).
 *
 * ASSUMPTION (flagged to the user): budget categories are matched
 * against the RAW `operating_expenses.category` strings actually
 * stored in the DB (e.g. "Rent", "Maintenance") — not the display
 * labels finance_data.php's $categoryLabelMap renders on the OpEx
 * page (e.g. "Rent or Mortgage"). If expense_add_operating.php's
 * dropdown uses different exact strings than the list below, the
 * category names here need to be updated to match, or Budget vs
 * Actual will show 0 actuals for a mismatched category.
 */

require __DIR__ . '/../includes/finance_data.php';

const BUDGET_CATEGORIES = [
    'Rent',
    'Utilities',
    'Maintenance',
    'Marketing',
    'Supplies',
    'Insurance',
    'Transportation',
    'Other',
];

/* Nicer labels for display only — comparisons still use the raw
   BUDGET_CATEGORIES strings above. */
const BUDGET_CATEGORY_LABELS = [
    'Rent'           => 'Rent / Mortgage',
    'Utilities'      => 'Utilities',
    'Maintenance'    => 'Maintenance & Repairs',
    'Marketing'      => 'Marketing',
    'Supplies'       => 'Supplies',
    'Insurance'      => 'Insurance',
    'Transportation' => 'Transportation',
    'Other'          => 'Other',
];

const MONTH_LABELS = [1=>'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

/* -----------------------------------------------------------
   1. Resolve selected fiscal year (separate from the sidebar's
      date-range filter — budgeting/forecasting are year-scoped)
----------------------------------------------------------- */
$budgetYear = isset($_GET['year']) ? (int) $_GET['year'] : (int) $today->format('Y');
if ($budgetYear < 2000 || $budgetYear > 2100) {
    $budgetYear = (int) $today->format('Y');
}
$currentYear  = (int) $today->format('Y');
$currentMonth = (int) $today->format('n');

/* -----------------------------------------------------------
   2. Load saved budgets for the year -> $budgetGrid[category][month]
----------------------------------------------------------- */
$budgetGrid = [];
foreach (BUDGET_CATEGORIES as $cat) {
    for ($m = 1; $m <= 12; $m++) { $budgetGrid[$cat][$m] = 0.00; }
}
$stmt = $pdo->prepare("
    SELECT category, budget_month, amount
    FROM budgets
    WHERE budget_year = :year
");
$stmt->execute([':year' => $budgetYear]);
foreach ($stmt->fetchAll() as $row) {
    if (isset($budgetGrid[$row['category']])) {
        $budgetGrid[$row['category']][(int) $row['budget_month']] = (float) $row['amount'];
    }
}

/* -----------------------------------------------------------
   3. Load actuals for the year -> $actualGrid[category][month]
      (operating_expenses only — COGS and Labor/Payroll are NOT
      user-loggable categories here, they're computed elsewhere,
      so they're intentionally not part of this budget grid)
----------------------------------------------------------- */
$actualGrid = [];
foreach (BUDGET_CATEGORIES as $cat) {
    for ($m = 1; $m <= 12; $m++) { $actualGrid[$cat][$m] = 0.00; }
}
$stmt = $pdo->prepare("
    SELECT category, MONTH(expense_date) AS m, SUM(amount) AS total
    FROM operating_expenses
    WHERE expense_type = 'operating'
      AND YEAR(expense_date) = :year
    GROUP BY category, m
");
$stmt->execute([':year' => $budgetYear]);
foreach ($stmt->fetchAll() as $row) {
    $cat = $row['category'];
    if (isset($actualGrid[$cat])) {
        $actualGrid[$cat][(int) $row['m']] = (float) $row['total'];
    }
    // categories logged that AREN'T in BUDGET_CATEGORIES fall through
    // silently here — surfaced separately below so nothing is hidden.
}

/* Any category actually used in operating_expenses this year that
   isn't in our BUDGET_CATEGORIES list — surfaces schema-mismatch
   from the assumption noted at the top of this file. */
$stmt = $pdo->prepare("
    SELECT DISTINCT category
    FROM operating_expenses
    WHERE expense_type = 'operating' AND YEAR(expense_date) = :year
");
$stmt->execute([':year' => $budgetYear]);
$unmatchedCategories = array_values(array_diff(
    array_column($stmt->fetchAll(), 'category'),
    BUDGET_CATEGORIES
));

/* -----------------------------------------------------------
   4. Variance grid + row/column totals
----------------------------------------------------------- */
$varianceGrid = [];
$budgetRowTotal = [];
$actualRowTotal = [];
foreach (BUDGET_CATEGORIES as $cat) {
    $budgetRowTotal[$cat] = array_sum($budgetGrid[$cat]);
    $actualRowTotal[$cat] = array_sum($actualGrid[$cat]);
    for ($m = 1; $m <= 12; $m++) {
        $b = $budgetGrid[$cat][$m];
        $a = $actualGrid[$cat][$m];
        $varianceGrid[$cat][$m] = $a - $b; // positive = over budget
    }
}
$budgetYearTotal = array_sum($budgetRowTotal);
$actualYearTotal = array_sum($actualRowTotal);

/* -----------------------------------------------------------
   4b. Dashboard aggregates (KPI cards, charts, top expenses)
----------------------------------------------------------- */
$monthlyBudgetTotal = [];
$monthlyActualTotal = [];
for ($m = 1; $m <= 12; $m++) {
    $mb = 0.0; $ma = 0.0;
    foreach (BUDGET_CATEGORIES as $cat) {
        $mb += $budgetGrid[$cat][$m];
        $ma += $actualGrid[$cat][$m];
    }
    $monthlyBudgetTotal[$m] = $mb;
    $monthlyActualTotal[$m] = $ma;
}

$categoriesOverBudget = 0;
foreach (BUDGET_CATEGORIES as $cat) {
    if ($budgetRowTotal[$cat] > 0 && $actualRowTotal[$cat] > $budgetRowTotal[$cat]) {
        $categoriesOverBudget++;
    }
}

$budgetRemaining = $budgetYearTotal - $actualYearTotal;

/* Top expense categories by actual spend, for the ranked list panel */
$topExpenseCategories = BUDGET_CATEGORIES;
usort($topExpenseCategories, fn($a, $b) => $actualRowTotal[$b] <=> $actualRowTotal[$a]);
$topExpenseCategories = array_slice($topExpenseCategories, 0, 5);
$maxExpenseAmount = $actualRowTotal[$topExpenseCategories[0] ?? ''] ?? 0;

/* -----------------------------------------------------------
   5. Forecasting — least-squares linear trend on daily revenue,
      extrapolated to fill out the rest of the fiscal year.
----------------------------------------------------------- */
$dailyAll = $pdo->query("
    SELECT DATE(ordered_at) AS d, SUM(total_amount) AS rev
    FROM orders
    WHERE status = 'completed'
    GROUP BY d
    ORDER BY d
")->fetchAll();

$forecast = [
    'available'       => false,
    'confidence'      => 'None',
    'daysOfHistory'   => 0,
    'slope'           => 0.0,
    'intercept'       => 0.0,
    'firstDate'       => null,
    'cogsRatio'       => 0.0,
    'avgMonthlyOpEx'  => 0.0,
    'monthly'         => [], // 1..12 => ['actual'=>bool,'revenue'=>..,'cogs'=>..,'opex'=>..,'net'=>..]
    'yearRevenue'     => 0.0,
    'yearCogs'        => 0.0,
    'yearOpEx'        => 0.0,
    'yearNetProfit'   => 0.0,
];

if (count($dailyAll) >= 2) {
    $firstDate = new DateTime($dailyAll[0]['d']);
    $n = count($dailyAll);
    $sumX = $sumY = $sumXY = $sumX2 = 0.0;
    foreach ($dailyAll as $row) {
        $x = (float) $firstDate->diff(new DateTime($row['d']))->days;
        $y = (float) $row['rev'];
        $sumX += $x; $sumY += $y; $sumXY += $x * $y; $sumX2 += $x * $x;
    }
    $denom = ($n * $sumX2) - ($sumX * $sumX);
    if (abs($denom) > 0.0001) {
        $slope     = (($n * $sumXY) - ($sumX * $sumY)) / $denom;
        $intercept = ($sumY - ($slope * $sumX)) / $n;
    } else {
        $slope     = 0.0;
        $intercept = $n > 0 ? $sumY / $n : 0.0; // flat average, not enough spread to fit a trend
    }

    $daysOfHistory = $n;
    $confidence = $daysOfHistory >= 60 ? 'High' : ($daysOfHistory >= 14 ? 'Medium' : 'Low');

    /* All-time COGS-to-revenue ratio, applied to forecasted revenue */
    $atRevenueAll = (float) $pdo->query("
        SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='completed'
    ")->fetchColumn();
    $atCogsAll = (float) $pdo->query("
        SELECT COALESCE(SUM(ABS(il.qty_change) * i.cost_per_unit),0)
        FROM inventory_log il JOIN inventory i ON il.inventory_id = i.inventory_id
        WHERE il.change_type = 'usage' AND i.cost_per_unit IS NOT NULL
    ")->fetchColumn();
    $cogsRatio = $atRevenueAll > 0 ? ($atCogsAll / $atRevenueAll) : 0.0;

    /* Average monthly OpEx (logged expenses + payroll cost), based
       on months that actually have any activity, all-time. */
    $monthsWithOpEx = (int) $pdo->query("
        SELECT COUNT(DISTINCT DATE_FORMAT(expense_date, '%Y-%m'))
        FROM operating_expenses WHERE expense_type = 'operating'
    ")->fetchColumn();
    $totalLoggedOpExAll = (float) $pdo->query("
        SELECT COALESCE(SUM(amount),0) FROM operating_expenses WHERE expense_type='operating'
    ")->fetchColumn();
    $monthsWithPayroll = (int) $pdo->query("
        SELECT COUNT(DISTINCT DATE_FORMAT(period_start, '%Y-%m'))
        FROM payroll WHERE status = 'released'
    ")->fetchColumn();
    $totalPayrollAll = (float) $pdo->query("
        SELECT COALESCE(SUM(gross_pay + employer_contributions_total),0)
        FROM payroll WHERE status = 'released'
    ")->fetchColumn();

    $avgMonthlyLoggedOpEx = $monthsWithOpEx  > 0 ? $totalLoggedOpExAll / $monthsWithOpEx  : $totalLoggedOpExAll;
    $avgMonthlyPayroll    = $monthsWithPayroll > 0 ? $totalPayrollAll / $monthsWithPayroll : $totalPayrollAll;
    $avgMonthlyOpEx       = $avgMonthlyLoggedOpEx + $avgMonthlyPayroll;

    /* Actual revenue per month for the selected year (for months
       already in the past / current) */
    $stmt = $pdo->prepare("
        SELECT MONTH(ordered_at) AS m, COALESCE(SUM(total_amount),0) AS rev
        FROM orders
        WHERE status = 'completed' AND YEAR(ordered_at) = :year
        GROUP BY m
    ");
    $stmt->execute([':year' => $budgetYear]);
    $actualRevenueByMonth = array_column($stmt->fetchAll(), 'rev', 'm');

    $monthly = [];
    $yearRevenue = 0.0;
    for ($m = 1; $m <= 12; $m++) {
        $isPastOrCurrentMonth = ($budgetYear < $currentYear)
            || ($budgetYear === $currentYear && $m < $currentMonth)
            || ($budgetYear === $currentYear && $m === $currentMonth); // current month: use actual-so-far, no partial trend mixing (kept simple)

        if ($isPastOrCurrentMonth && isset($actualRevenueByMonth[$m])) {
            $monthRevenue = (float) $actualRevenueByMonth[$m];
            $isActual = true;
        } elseif ($isPastOrCurrentMonth) {
            $monthRevenue = 0.0; // past month, genuinely zero activity
            $isActual = true;
        } else {
            // Future month — project via trend line at the midpoint day of that month
            $midDate = new DateTime(sprintf('%04d-%02d-15', $budgetYear, $m));
            $x = (float) $firstDate->diff($midDate)->days;
            $dailyProjected = max(0.0, $intercept + ($slope * $x));
            $daysInMonth = (int) (new DateTime(sprintf('%04d-%02d-01', $budgetYear, $m)))->format('t');
            $monthRevenue = $dailyProjected * $daysInMonth;
            $isActual = false;
        }

        $monthCogs = $monthRevenue * $cogsRatio;
        // Note: OpEx is not broken out per-month here — the year-level
        // OpEx forecast below sums actual OpEx for elapsed months plus
        // avgMonthlyOpEx × remaining months, which is simpler and avoids
        // double-counting the current (partial) month.

        $monthly[$m] = [
            'actual'  => $isActual,
            'revenue' => $monthRevenue,
            'cogs'    => $monthCogs,
        ];
        $yearRevenue += $monthRevenue;
    }

    /* OpEx for the year: actual logged+payroll for past/current
       months, average projection for remaining future months */
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount),0) FROM operating_expenses
        WHERE expense_type='operating' AND YEAR(expense_date) = :year
    ");
    $stmt->execute([':year' => $budgetYear]);
    $actualLoggedOpExYear = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(gross_pay + employer_contributions_total),0)
        FROM payroll WHERE status='released' AND YEAR(period_start) = :year
    ");
    $stmt->execute([':year' => $budgetYear]);
    $actualPayrollYear = (float) $stmt->fetchColumn();

    $monthsElapsedThisYear = ($budgetYear < $currentYear) ? 12
        : (($budgetYear > $currentYear) ? 0 : $currentMonth);
    $monthsRemaining = 12 - $monthsElapsedThisYear;

    $yearOpEx  = $actualLoggedOpExYear + $actualPayrollYear + ($avgMonthlyOpEx * $monthsRemaining);
    $yearCogs  = $yearRevenue * $cogsRatio;
    $yearNet   = $yearRevenue - $yearCogs - $yearOpEx;

    $forecast = [
        'available'      => true,
        'confidence'     => $confidence,
        'daysOfHistory'  => $daysOfHistory,
        'slope'          => $slope,
        'intercept'      => $intercept,
        'firstDate'      => $firstDate->format('Y-m-d'),
        'cogsRatio'      => $cogsRatio,
        'avgMonthlyOpEx' => $avgMonthlyOpEx,
        'monthly'        => $monthly,
        'yearRevenue'    => $yearRevenue,
        'yearCogs'       => $yearCogs,
        'yearOpEx'       => $yearOpEx,
        'yearNetProfit'  => $yearNet,
        'monthsRemaining'=> $monthsRemaining,
    ];
}
