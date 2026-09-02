<?php
/**
 * CloudCup — Finance shared data layer
 * Session/auth check, date-range resolution, and every DB query used
 * across the Finance pages. Each page includes this once, then only
 * renders the section(s) it needs from the resulting variables.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../config.php'; // creates $pdo (PDO connection)

/* -----------------------------------------------------------
   0. Require login — uses the SAME session as the rest of
   CloudCup (auth/Login_Page.php), not a separate Finance login.
   Finance staff, plus admin/manager for oversight, can get in.
----------------------------------------------------------- */
if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role'] ?? ''), ['finance', 'admin', 'manager'], true)) {
    header('Location: ../auth/Login_Page.php');
    exit;
}

/* -----------------------------------------------------------
   1. Resolve the selected date range
----------------------------------------------------------- */
$range = $_GET['range'] ?? 'this_month';
$today = new DateTime('today');

switch ($range) {
    case 'today':
        $from = clone $today;
        $to   = clone $today;
        break;
    case 'this_week':
        $from = (clone $today)->modify('monday this week');
        $to   = clone $today;
        break;
    case 'this_year':
        $from = new DateTime($today->format('Y') . '-01-01');
        $to   = clone $today;
        break;
    case 'custom':
        $from = DateTime::createFromFormat('Y-m-d', $_GET['from'] ?? '') ?: (clone $today)->modify('first day of this month');
        $to   = DateTime::createFromFormat('Y-m-d', $_GET['to'] ?? '')   ?: clone $today;
        break;
    case 'this_month':
    default:
        $range = 'this_month';
        $from  = new DateTime($today->format('Y-m') . '-01');
        $to    = clone $today;
        break;
}
if ($from > $to) { [$from, $to] = [$to, $from]; }

$fromStr = $from->format('Y-m-d');
$toStr   = $to->format('Y-m-d');

/* Query string used to carry the current range across Finance pages */
$rangeQuery = http_build_query(['range' => $range, 'from' => $fromStr, 'to' => $toStr]);

/* -----------------------------------------------------------
   2. Revenue (completed orders only)
----------------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount),0) AS revenue, COUNT(*) AS order_count
    FROM orders
    WHERE status = 'completed'
      AND DATE(ordered_at) BETWEEN :from AND :to
");
$stmt->execute([':from' => $fromStr, ':to' => $toStr]);
$revRow   = $stmt->fetch();
$revenue  = (float) $revRow['revenue'];
$orderCnt = (int) $revRow['order_count'];

/* Revenue per day, for the trend chart */
$stmt = $pdo->prepare("
    SELECT DATE(ordered_at) AS d, SUM(total_amount) AS rev
    FROM orders
    WHERE status = 'completed'
      AND DATE(ordered_at) BETWEEN :from AND :to
    GROUP BY d
    ORDER BY d
");
$stmt->execute([':from' => $fromStr, ':to' => $toStr]);
$dailyRevenue = $stmt->fetchAll();

/* Revenue by payment method */
$stmt = $pdo->prepare("
    SELECT
      CASE WHEN payment_method IS NULL OR payment_method = '' THEN 'unspecified' ELSE payment_method END AS method,
      SUM(total_amount) AS total,
      COUNT(*) AS cnt
    FROM orders
    WHERE status = 'completed'
      AND DATE(ordered_at) BETWEEN :from AND :to
    GROUP BY method
    ORDER BY total DESC
");
$stmt->execute([':from' => $fromStr, ':to' => $toStr]);
$byPaymentMethod = $stmt->fetchAll();

/* -----------------------------------------------------------
   3. Cost of Goods Sold — derived from inventory usage logs
      (qty auto-deducted per order) x cost_per_unit
----------------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(ABS(il.qty_change) * i.cost_per_unit),0) AS cogs
    FROM inventory_log il
    JOIN inventory i ON il.inventory_id = i.inventory_id
    WHERE il.change_type = 'usage'
      AND i.cost_per_unit IS NOT NULL
      AND DATE(il.created_at) BETWEEN :from AND :to
");
$stmt->execute([':from' => $fromStr, ':to' => $toStr]);
$cogs = (float) $stmt->fetch()['cogs'];

/* COGS broken down by inventory category */
$stmt = $pdo->prepare("
    SELECT i.category, SUM(ABS(il.qty_change) * i.cost_per_unit) AS cost
    FROM inventory_log il
    JOIN inventory i ON il.inventory_id = i.inventory_id
    WHERE il.change_type = 'usage'
      AND i.cost_per_unit IS NOT NULL
      AND DATE(il.created_at) BETWEEN :from AND :to
    GROUP BY i.category
    ORDER BY cost DESC
");
$stmt->execute([':from' => $fromStr, ':to' => $toStr]);
$cogsByCategory = $stmt->fetchAll();

/* -----------------------------------------------------------
   4. Payroll cost (released payslips whose pay period
      overlaps the selected range)
----------------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT
      COALESCE(SUM(gross_pay),0)                    AS gross,
      COALESCE(SUM(net_pay),0)                       AS net,
      COALESCE(SUM(total_deductions),0)              AS deductions,
      COALESCE(SUM(employer_contributions_total),0)  AS employer_cost,
      COUNT(*)                                       AS payslip_count
    FROM payroll
    WHERE status = 'released'
      AND period_start <= :to
      AND period_end   >= :from
");
$stmt->execute([':from' => $fromStr, ':to' => $toStr]);
$payrollRow      = $stmt->fetch();
$payrollGross    = (float) $payrollRow['gross'];
$payrollEmployer = (float) $payrollRow['employer_cost'];
$payrollCost     = $payrollGross + $payrollEmployer; // total cost to the business
$payslipCount    = (int) $payrollRow['payslip_count'];

$stmt = $pdo->prepare("
    SELECT p.*, u.full_name
    FROM payroll p
    JOIN users u ON p.employee_id = u.user_id
    WHERE p.status = 'released'
      AND p.period_start <= :to
      AND p.period_end   >= :from
    ORDER BY p.period_start DESC
");
$stmt->execute([':from' => $fromStr, ':to' => $toStr]);
$payrollRows = $stmt->fetchAll();

/* -----------------------------------------------------------
   5. Employee loans (current standing, not date-ranged —
      there is no payment-history table yet in the schema)
----------------------------------------------------------- */
$loanRows = $pdo->query("
    SELECT l.loan_id, u.full_name, l.principal, l.monthly_installment,
           l.remaining_balance, l.status, l.created_at
    FROM loans l
    JOIN users u ON l.employee_id = u.user_id
    WHERE l.status = 'active'
    ORDER BY l.created_at DESC
")->fetchAll();

$outstandingLoans = array_sum(array_column($loanRows, 'remaining_balance'));

/* -----------------------------------------------------------
   5b. Operating expenses (rent, utilities, marketing, etc.)
       logged in the new operating_expenses table
----------------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
    FROM operating_expenses
    WHERE expense_type = 'operating'
      AND expense_date BETWEEN :from AND :to
");
$stmt->execute([':from' => $fromStr, ':to' => $toStr]);
$opExRow   = $stmt->fetch();
$loggedOpEx = (float) $opExRow['total'];
$opExCount  = (int) $opExRow['cnt'];

$stmt = $pdo->prepare("
    SELECT category, SUM(amount) AS total
    FROM operating_expenses
    WHERE expense_type = 'operating'
      AND expense_date BETWEEN :from AND :to
    GROUP BY category
    ORDER BY total DESC
");
$stmt->execute([':from' => $fromStr, ':to' => $toStr]);
$opExByCategory = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT oe.*, u.full_name AS recorded_by_name
    FROM operating_expenses oe
    LEFT JOIN users u ON oe.recorded_by = u.user_id
    WHERE oe.expense_type = 'operating'
      AND oe.expense_date BETWEEN :from AND :to
    ORDER BY oe.expense_date DESC, oe.expense_id DESC
");
$stmt->execute([':from' => $fromStr, ':to' => $toStr]);
$opExRows = $stmt->fetchAll();

/* Startup / capital costs — informational, shown separately, not
   subtracted from recurring Net Profit */
$startupRows = $pdo->query("
    SELECT oe.*, u.full_name AS recorded_by_name
    FROM operating_expenses oe
    LEFT JOIN users u ON oe.recorded_by = u.user_id
    WHERE oe.expense_type = 'startup'
    ORDER BY oe.expense_date ASC
")->fetchAll();
$totalStartupCosts = array_sum(array_column($startupRows, 'amount'));

/* Total Operating Expenses = staff wages (payroll cost) + everything
   logged in operating_expenses for the period, per the standard
   "OpEx = rent + utilities + wages + marketing + ..." definition */
$totalOpEx = $payrollCost + $loggedOpEx;

/* Unified Operating Expenses breakdown — DISPLAY ONLY.
   Per Rhea's spec, exactly 5 categories may appear here:
     Cost of Goods Sold, Labor and Payroll, Rent or Mortgage,
     Utilities, Maintenance and Repairs.
   Anything else logged (Marketing, Supplies, Insurance,
   Transportation, Other) is deliberately excluded from this view.
   $categoryLabelMap normalizes older stored category strings
   ("Rent", "Maintenance") to the new labels so existing logged
   rows still group correctly without needing a data migration.
   This does NOT change the P&L math anywhere else on this page —
   Gross Profit is still Revenue − COGS, and Net Profit is still
   Gross Profit − Total OpEx ($totalOpEx above is untouched). */
$categoryLabelMap = [
    'rent'                    => 'Rent or Mortgage',
    'rent or mortgage'        => 'Rent or Mortgage',
    'mortgage'                => 'Rent or Mortgage',
    'utilities'                => 'Utilities',
    'maintenance'              => 'Maintenance and Repairs',
    'maintenance and repairs'  => 'Maintenance and Repairs',
    'maintenance & repairs'    => 'Maintenance and Repairs',
];

$opexUnifiedTotals = []; // label => running total
if ($cogs > 0) {
    $opexUnifiedTotals['Cost of Goods Sold'] = ($opexUnifiedTotals['Cost of Goods Sold'] ?? 0) + $cogs;
}
if ($payrollCost > 0) {
    $opexUnifiedTotals['Labor and Payroll'] = ($opexUnifiedTotals['Labor and Payroll'] ?? 0) + $payrollCost;
}
foreach ($opExByCategory as $row) {
    $key = strtolower(trim($row['category']));
    if (!isset($categoryLabelMap[$key])) continue; // not one of the 5 approved categories — excluded
    $label = $categoryLabelMap[$key];
    $opexUnifiedTotals[$label] = ($opexUnifiedTotals[$label] ?? 0) + (float) $row['total'];
}

$opexUnifiedBreakdown = [];
foreach ($opexUnifiedTotals as $label => $total) {
    $opexUnifiedBreakdown[] = ['category' => $label, 'total' => $total];
}
usort($opexUnifiedBreakdown, fn($a, $b) => $b['total'] <=> $a['total']);

/* -----------------------------------------------------------
   5c. Cash flow — actual cash movement for the period
----------------------------------------------------------- */
$cashIn = 0.0;
foreach ($byPaymentMethod as $pm) {
    if ($pm['method'] === 'cash') { $cashIn = (float) $pm['total']; break; }
}
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount),0) AS total
    FROM operating_expenses
    WHERE payment_method = 'cash'
      AND expense_type = 'operating'
      AND expense_date BETWEEN :from AND :to
");
$stmt->execute([':from' => $fromStr, ':to' => $toStr]);
$cashOutExpenses = (float) $stmt->fetch()['total'];
$netCashFlow = $cashIn - $cashOutExpenses;

/* -----------------------------------------------------------
   6. Top selling items (by revenue) in range
----------------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT mi.item_name, mi.category, SUM(oi.quantity) AS qty, SUM(oi.subtotal) AS revenue
    FROM order_items oi
    JOIN orders o      ON oi.order_id = o.order_id
    JOIN menu_items mi ON oi.item_id  = mi.item_id
    WHERE o.status = 'completed'
      AND DATE(o.ordered_at) BETWEEN :from AND :to
    GROUP BY mi.item_id, mi.item_name, mi.category
    ORDER BY revenue DESC
    LIMIT 8
");
$stmt->execute([':from' => $fromStr, ':to' => $toStr]);
$topItems = $stmt->fetchAll();

/* -----------------------------------------------------------
   7. Recent transactions
----------------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT o.order_id, o.ordered_at, o.total_amount, o.payment_method,
           o.order_type, o.status, u.full_name AS cashier
    FROM orders o
    LEFT JOIN users u ON o.employee_id = u.user_id
    WHERE DATE(o.ordered_at) BETWEEN :from AND :to
    ORDER BY o.ordered_at DESC
    LIMIT 15
");
$stmt->execute([':from' => $fromStr, ':to' => $toStr]);
$recentOrders = $stmt->fetchAll();

/* -----------------------------------------------------------
   8. Final P&L figures
----------------------------------------------------------- */
$grossProfit = $revenue - $cogs;
$netProfit   = $grossProfit - $totalOpEx;
$margin      = $revenue > 0 ? ($netProfit / $revenue) * 100 : 0;
$grossMargin = $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0;

function money($n) { return '₱' . number_format((float) $n, 2); }

$currentUser = $_SESSION['full_name'] ?? 'Finance User';

/* -----------------------------------------------------------
   9. Balance Sheet — snapshot as of TODAY, not range-bound.
      (Assets = Liabilities + Equity)
----------------------------------------------------------- */

/* 9a. Cash on hand — opening balance + all-time cash in/out.
   Uses the same 'cash' payment-method definition as Cash Flow. */
$openingCash = (float) ($pdo->query("
    SELECT setting_value FROM finance_settings WHERE setting_key = 'opening_cash_balance'
")->fetchColumn() ?: 0);

$allTimeCashRevenue = (float) $pdo->query("
    SELECT COALESCE(SUM(total_amount),0) FROM orders
    WHERE status = 'completed' AND payment_method = 'cash'
")->fetchColumn();

$allTimeCashExpenses = (float) $pdo->query("
    SELECT COALESCE(SUM(amount),0) FROM operating_expenses
    WHERE payment_method = 'cash'
")->fetchColumn(); // covers both 'operating' and 'startup' cash spend

$allTimeOwnerCashContrib = (float) $pdo->query("
    SELECT COALESCE(SUM(amount),0) FROM owner_equity_transactions WHERE tx_type = 'contribution'
")->fetchColumn();

$allTimeOwnerCashDraws = (float) $pdo->query("
    SELECT COALESCE(SUM(amount),0) FROM owner_equity_transactions WHERE tx_type = 'draw'
")->fetchColumn();

$cashOnHand = $openingCash + $allTimeCashRevenue - $allTimeCashExpenses
            + $allTimeOwnerCashContrib - $allTimeOwnerCashDraws;

/* 9b. Inventory value — best-effort. Different installs name the
   "current stock" column differently, so this is guarded: if the
   column doesn't exist yet, we show the line as "not available"
   instead of breaking the whole Balance Sheet page. */
$inventoryValue = null; // null = "not available", not "zero"
try {
    $inventoryValue = (float) $pdo->query("
        SELECT COALESCE(SUM(quantity * cost_per_unit),0) FROM inventory
    ")->fetchColumn();
} catch (PDOException $e) {
    $inventoryValue = null;
}

/* 9c. Fixed assets, net of straight-line depreciation to today */
$assetRows = $pdo->query("
    SELECT * FROM assets WHERE disposed_at IS NULL ORDER BY purchase_date ASC
")->fetchAll();

foreach ($assetRows as &$a) {
    $purchased   = new DateTime($a['purchase_date']);
    $ageYears    = max(0, ($today->getTimestamp() - $purchased->getTimestamp()) / (365.25 * 86400));
    $lifeYears   = max(0.1, (float) $a['useful_life_years']);
    $depreciated = min((float) $a['cost'], (float) $a['cost'] * ($ageYears / $lifeYears));
    $a['book_value'] = round((float) $a['cost'] - $depreciated, 2);
}
unset($a);
$totalFixedAssetsNet = array_sum(array_column($assetRows, 'book_value'));
$totalFixedAssetsCost = array_sum(array_column($assetRows, 'cost'));

/* 9d. Liabilities — unpaid supplier bills, tax accruals, business loans */
$liabilityRows = $pdo->query("
    SELECT * FROM liabilities WHERE status = 'unpaid' ORDER BY due_date ASC
")->fetchAll();
$totalLiabilities = array_sum(array_column($liabilityRows, 'amount'));

$liabilitiesByType = $pdo->query("
    SELECT liability_type, COALESCE(SUM(amount),0) AS total
    FROM liabilities WHERE status = 'unpaid'
    GROUP BY liability_type
")->fetchAll();

/* 9e. Employee loans, reused from section 5 — an asset (receivable),
   since employees owe the business, not the other way around. */
$loansReceivable = $outstandingLoans;

/* 9f. Owner's equity
   NOTE: startup costs are intentionally NOT added here. They're
   already deducted from cash on hand above (allTimeCashExpenses
   covers both 'operating' and 'startup' cash spend), so adding
   them again as "capital" double-counted every peso of cash-paid
   startup spend — inflating Equity with nothing on the Assets
   side to back it, which is what threw the balance sheet out of
   balance. Owner Capital is just what the owner actually put in. */
$ownerCapital = $allTimeOwnerCashContrib;
$ownerDraws   = $allTimeOwnerCashDraws;

/* Retained earnings = cumulative Net Profit since day one (all-time,
   not range-bound) — same P&L formula as section 8, just unfiltered. */
$atRevenue = (float) $pdo->query("
    SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status = 'completed'
")->fetchColumn();

$atCogs = (float) $pdo->query("
    SELECT COALESCE(SUM(ABS(il.qty_change) * i.cost_per_unit),0)
    FROM inventory_log il JOIN inventory i ON il.inventory_id = i.inventory_id
    WHERE il.change_type = 'usage' AND i.cost_per_unit IS NOT NULL
")->fetchColumn();

$atPayroll = (float) $pdo->query("
    SELECT COALESCE(SUM(gross_pay + employer_contributions_total),0)
    FROM payroll WHERE status = 'released'
")->fetchColumn();

$atOpEx = (float) $pdo->query("
    SELECT COALESCE(SUM(amount),0) FROM operating_expenses WHERE expense_type = 'operating'
")->fetchColumn();

$retainedEarnings = $atRevenue - $atCogs - ($atPayroll + $atOpEx);

$totalEquity = $ownerCapital - $ownerDraws + $retainedEarnings;

/* 9g. Totals */
$totalAssets = $cashOnHand + ($inventoryValue ?? 0) + $totalFixedAssetsNet + $loansReceivable;
$totalLiabAndEquity = $totalLiabilities + $totalEquity;
$balanceCheckDiff = $totalAssets - $totalLiabAndEquity;

/* -----------------------------------------------------------
   9h. Classified breakdown (current vs. non-current) + ratios,
   used by the "Classified Breakdown & Ratios" section.

   Current Assets    = cash + inventory + employee loans receivable
                        (all convertible to cash / collectible within
                        a normal operating cycle)
   Non-Current Assets = fixed assets, net of depreciation

   Current Liabilities   = unpaid liabilities due within 12 months
                            of today (or with no due date set, since
                            those default to "due now")
   Long-Term Liabilities = unpaid liabilities due beyond 12 months
----------------------------------------------------------- */
$totalCurrentAssets = $cashOnHand + ($inventoryValue ?? 0) + $loansReceivable;
// $totalFixedAssetsNet already computed in 9c and doubles as Non-Current Assets

$twelveMonthsOut = (clone $today)->modify('+12 months');
$totalCurrentLiabilities = 0.0;
$totalLongTermLiabilities = 0.0;
foreach ($liabilityRows as $l) {
    $due = $l['due_date'] ? new DateTime($l['due_date']) : null;
    if ($due === null || $due <= $twelveMonthsOut) {
        $totalCurrentLiabilities += (float) $l['amount'];
    } else {
        $totalLongTermLiabilities += (float) $l['amount'];
    }
}

$currentRatio  = $totalCurrentLiabilities > 0 ? $totalCurrentAssets / $totalCurrentLiabilities : null;
$workingCapital = $totalCurrentAssets - $totalCurrentLiabilities;
$debtToEquity  = $totalEquity != 0 ? $totalLiabilities / $totalEquity : null;