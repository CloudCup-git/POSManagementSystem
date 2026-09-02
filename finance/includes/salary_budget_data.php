<?php
/**
 * CloudCup — Finance: Employee Salary Budgeting data
 * -------------------------------------------------------------
 * Read-only visibility for Finance into current headcount cost, so
 * Finance can monitor the salary budget without waiting for HR to
 * actually run a payroll. Pulls daily_rate from HR's `employees` table
 * (editing that stays in HR/Employee_Records_Page.php — this page never
 * writes to it) and estimates a standard month using real 2026 PH
 * statutory-contribution formulas from includes/gov_contributions_2026.php
 * (shared with HR/Payroll_Page.php).
 *
 * Expects $pdo (from finance_data.php) and compute_statutory_deductions()
 * (from ../includes/gov_contributions_2026.php) to already be loaded by
 * whichever page includes this file.
 */

const BUDGET_WORKING_DAYS_PER_MONTH = 22;      // typical PH working-day count used for the estimate
// SSS / PhilHealth / Pag-IBIG are now computed per-person via real 2026
// tiered formulas in includes/gov_contributions_2026.php (shared with
// HR/Payroll_Page.php), instead of flat placeholder amounts.

$salaryRows = [];
try {
    $stmt = $pdo->query("
        SELECT u.user_id, u.full_name, u.role,
               e.position, e.department, e.employment_status, e.daily_rate
        FROM users u
        LEFT JOIN employees e ON e.employee_id = u.user_id
        WHERE u.role IN ('employee', 'manager')
          AND u.is_active = 1
        ORDER BY u.full_name
    ");
    $raw = $stmt->fetchAll();
} catch (PDOException $e) {
    $raw = [];
}

foreach ($raw as $r) {
    $dailyRate     = (float) ($r['daily_rate'] ?? 0);
    $monthlySalary = round($dailyRate * BUDGET_WORKING_DAYS_PER_MONTH, 2);
    $ded           = compute_statutory_deductions($monthlySalary);

    $salaryRows[] = [
        'user_id'            => $r['user_id'],
        'full_name'          => $r['full_name'],
        'position'           => $r['position'],
        'department'         => $r['department'],
        'employment_status'  => $r['employment_status'],
        'daily_rate'         => $dailyRate,
        'monthly_salary'     => $monthlySalary,
        'sss'                => $ded['sss'],
        'philhealth'         => $ded['philhealth'],
        'pagibig'            => $ded['pagibig'],
        'net_monthly'        => $ded['net_monthly'],
        'no_rate_set'        => $dailyRate <= 0, // flag for UI: not yet configured in HR
    ];
}

$budgetTotals = [
    'monthly_salary' => array_sum(array_column($salaryRows, 'monthly_salary')),
    'sss'            => array_sum(array_column($salaryRows, 'sss')),
    'philhealth'     => array_sum(array_column($salaryRows, 'philhealth')),
    'pagibig'        => array_sum(array_column($salaryRows, 'pagibig')),
    'net_monthly'    => array_sum(array_column($salaryRows, 'net_monthly')),
];
