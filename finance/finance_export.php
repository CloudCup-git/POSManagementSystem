<?php
/**
 * CloudCup — Finance: Excel export
 * -------------------------------------------------------------
 * One controller for every report's "Export to Excel" button.
 * finance_data.php already enforces the finance/admin/manager login
 * gate and resolves the selected date range before this file does
 * anything else, so every export respects the same range + security
 * as the page it was exported from.
 *
 *   finance_export.php?report=revenue&range=this_month
 *   finance_export.php?report=balance
 *   finance_export.php?report=tax&year=2026
 *   finance_export.php?report=salary_budget
 */
require __DIR__ . '/includes/finance_data.php';
require __DIR__ . '/includes/xlsx_writer.php';

$report      = $_GET['report'] ?? '';
$rangeLabel  = $from->format('M d, Y') . ' to ' . $to->format('M d, Y');
$rangeSuffix = $fromStr . '_to_' . $toStr;

switch ($report) {

    case 'revenue':
        $rows = [];
        foreach ($dailyRevenue as $r) $rows[] = [$r['d'], (float) $r['rev']];
        $rows[] = ['TOTAL', $revenue];
        export_finance_xlsx("Revenue_{$rangeSuffix}.xlsx", ['Date', 'Revenue'], $rows, "Revenue — {$rangeLabel}");
        break;

    case 'cogs':
        $rows = [];
        foreach ($cogsByCategory as $r) $rows[] = [$r['category'], (float) $r['cost']];
        $rows[] = ['TOTAL COGS', $cogs];
        export_finance_xlsx("COGS_{$rangeSuffix}.xlsx", ['Category', 'Cost'], $rows, "Cost of Goods Sold — {$rangeLabel}");
        break;

    case 'opex':
        $rows = [];
        foreach ($opExRows as $r) {
            $rows[] = [$r['expense_date'], $r['category'], $r['description'] ?? '', (float) $r['amount'], $r['payment_method'] ?? '', $r['recorded_by_name'] ?? ''];
        }
        $rows[] = ['', '', 'TOTAL', $loggedOpEx, '', ''];
        export_finance_xlsx("OperatingExpenses_{$rangeSuffix}.xlsx",
            ['Date', 'Category', 'Description', 'Amount', 'Payment Method', 'Recorded By'], $rows, "Operating Expenses — {$rangeLabel}");
        break;

    case 'cashflow':
        $rows = [
            ['Cash In', $cashIn],
            ['Cash Out (Expenses)', $cashOutExpenses],
            ['Net Cash Flow', $netCashFlow],
        ];
        export_finance_xlsx("CashFlow_{$rangeSuffix}.xlsx", ['Metric', 'Amount'], $rows, "Cash Flow — {$rangeLabel}");
        break;

    case 'startup':
        $rows = [];
        foreach ($startupRows as $r) $rows[] = [$r['expense_date'], $r['category'], $r['description'] ?? '', (float) $r['amount'], $r['recorded_by_name'] ?? ''];
        $rows[] = ['', '', 'TOTAL', $totalStartupCosts, ''];
        export_finance_xlsx('StartupCosts.xlsx', ['Date', 'Category', 'Description', 'Amount', 'Recorded By'], $rows, 'Startup & Capital Costs');
        break;

    case 'transactions':
        $rows = [];
        foreach ($recentOrders as $r) {
            $rows[] = [$r['order_id'], $r['ordered_at'], $r['cashier'] ?? '', $r['order_type'], $r['payment_method'], $r['status'], (float) $r['total_amount']];
        }
        export_finance_xlsx("Transactions_{$rangeSuffix}.xlsx",
            ['Order ID', 'Date/Time', 'Cashier', 'Type', 'Payment Method', 'Status', 'Amount'], $rows, "Transactions — {$rangeLabel}");
        break;

    case 'payroll':
        $rows = [];
        foreach ($payrollRows as $p) {
            $rows[] = [
                $p['full_name'], $p['period_start'], $p['period_end'], (float) $p['gross_pay'],
                (float) ($p['sss_employee'] ?? 0), (float) ($p['philhealth_employee'] ?? 0), (float) ($p['pagibig_employee'] ?? 0),
                (float) ($p['income_tax'] ?? 0), (float) ($p['loan_deduction'] ?? 0), (float) $p['total_deductions'], (float) $p['net_pay'],
            ];
        }
        export_finance_xlsx("Payroll_{$rangeSuffix}.xlsx",
            ['Employee', 'Period Start', 'Period End', 'Gross Pay', 'SSS', 'PhilHealth', 'Pag-IBIG', 'Income Tax', 'Loan Deduction', 'Total Deductions', 'Net Pay'],
            $rows, "Payroll & Loans — {$rangeLabel}");
        break;

    case 'balance':
        // Same Admin/Manager-only gate as the Balance Sheet page itself.
        if (!in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'manager'], true)) {
            http_response_code(403);
            exit('Balance Sheet export is restricted to Admin/Manager accounts.');
        }
        $rows = [
            ['ASSETS', 'Cash on hand', $cashOnHand],
            ['ASSETS', 'Inventory value', $inventoryValue ?? 0],
            ['ASSETS', 'Fixed assets (net)', $totalFixedAssetsNet],
            ['ASSETS', 'Employee loans receivable', $loansReceivable],
            ['ASSETS', 'TOTAL ASSETS', $totalAssets],
        ];
        foreach ($liabilityRows as $l) $rows[] = ['LIABILITIES', $l['payee'] . ' (' . $l['liability_type'] . ')', (float) $l['amount']];
        $rows[] = ['LIABILITIES', 'TOTAL LIABILITIES', $totalLiabilities];
        $rows[] = ['EQUITY', 'Owner capital', $ownerCapital];
        $rows[] = ['EQUITY', 'Owner draws', -$ownerDraws];
        $rows[] = ['EQUITY', 'Retained earnings', $retainedEarnings];
        $rows[] = ['EQUITY', 'TOTAL EQUITY', $totalEquity];
        $rows[] = ['RATIOS', 'Current Ratio', $currentRatio !== null ? round($currentRatio, 2) : 'n/a'];
        $rows[] = ['RATIOS', 'Working Capital', $workingCapital];
        $rows[] = ['RATIOS', 'Debt-to-Equity', $debtToEquity !== null ? round($debtToEquity, 2) : 'n/a'];
        export_finance_xlsx('BalanceSheet_' . $today->format('Y-m-d') . '.xlsx', ['Section', 'Line Item', 'Amount'], $rows, 'Balance Sheet — as of ' . $today->format('M d, Y'));
        break;

    case 'tax':
        require __DIR__ . '/includes/tax_data.php';
        $rows = [];
        foreach ($monthlyRevenue as $m) $rows[] = [$m['label'], (float) $m['total']];
        $rows[] = ['Annual Gross Sales', $annualGross];
        $rows[] = ['Percentage Tax Rate', '3%'];
        $rows[] = ['Annual Tax Due', $taxDue];
        export_finance_xlsx("AnnualTax_{$taxYear}.xlsx", ['Month', 'Gross Sales'], $rows, "Annual Tax — {$taxYear}");
        break;

    case 'salary_budget':
        require __DIR__ . '/includes/salary_budget_data.php';
        $rows = [];
        foreach ($salaryRows as $r) {
            $rows[] = [$r['full_name'], $r['position'] ?? '', $r['department'] ?? '', $r['monthly_salary'], $r['sss'], $r['philhealth'], $r['pagibig'], $r['net_monthly']];
        }
        $rows[] = ['TOTAL', '', '', $budgetTotals['monthly_salary'], $budgetTotals['sss'], $budgetTotals['philhealth'], $budgetTotals['pagibig'], $budgetTotals['net_monthly']];
        export_finance_xlsx('SalaryBudget.xlsx',
            ['Employee', 'Position', 'Department', 'Est. Monthly Salary', 'SSS (Employee)', 'PhilHealth (Employee)', 'Pag-IBIG (Employee)', 'Est. Net Monthly'],
            $rows, 'Employee Salary Budgeting');
        break;

    default:
        http_response_code(400);
        exit('Unknown report type.');
}
