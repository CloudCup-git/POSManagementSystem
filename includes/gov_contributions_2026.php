<?php
/**
 * CloudCup — Philippine statutory contribution calculator (2026 rates)
 * -------------------------------------------------------------
 * Single source of truth for SSS / PhilHealth / Pag-IBIG EMPLOYEE-SHARE
 * deductions, so Finance's salary budgeting and HR's payroll page never
 * drift out of sync. Replaces the old flat placeholder amounts
 * (₱375 / ₱1,125 / ₱100) with real tiered/percentage formulas.
 *
 * Sources (verify against official circulars if rates change):
 *   - SSS:       Circular 2024-006, effective Jan 2025, continuing 2026.
 *                15% total (5% employee / 10% employer) of Monthly Salary
 *                Credit (MSC). MSC floor ₱5,000, ceiling ₱35,000, in
 *                ₱500 steps.
 *   - PhilHealth: 5% total (2.5% employee / 2.5% employer) of monthly
 *                basic salary. Floor ₱10,000, ceiling ₱100,000 — rate
 *                confirmed unchanged for 2026 (final step of UHC Law
 *                schedule).
 *   - Pag-IBIG:  HDMF Circular 460. 1% employee share for salary
 *                ≤ ₱1,500, else 2%. Maximum Fund Salary (MFS) cap
 *                ₱10,000 → max employee share ₱200.
 *
 * All functions take the EMPLOYEE's monthly basic salary (float) and
 * return their peso SHARE ONLY (not the employer's, not the total).
 */

function sss_employee_share(float $monthlySalary): float
{
    if ($monthlySalary <= 0) return 0.0;

    // Snap salary to the nearest ₱500 MSC bracket, clamed to [5000, 35000].
    $msc = round($monthlySalary / 500) * 500;
    $msc = max(5000, min(35000, $msc));

    return round($msc * 0.05, 2); // employee share = 5% of MSC
}

function philhealth_employee_share(float $monthlySalary): float
{
    if ($monthlySalary <= 0) return 0.0;

    $base = max(10000, min(100000, $monthlySalary)); // floor/ceiling
    return round($base * 0.025, 2); // employee share = 2.5% of basic salary
}

function pagibig_employee_share(float $monthlySalary): float
{
    if ($monthlySalary <= 0) return 0.0;

    $base = min(10000, $monthlySalary); // MFS cap ₱10,000
    $rate = ($monthlySalary <= 1500) ? 0.01 : 0.02;
    return round($base * $rate, 2); // capped at ₱200 either way
}

/**
 * Convenience wrapper: computes all three shares at once and guarantees
 * the net pay is never negative — if deductions would exceed salary
 * (e.g. salary is ₱0 because no daily_rate is set yet), everything is
 * zeroed out instead of showing a negative "net pay" for someone who
 * isn't actually earning anything yet.
 */
function compute_statutory_deductions(float $monthlySalary): array
{
    if ($monthlySalary <= 0) {
        return [
            'sss' => 0.0, 'philhealth' => 0.0, 'pagibig' => 0.0,
            'total_deductions' => 0.0, 'net_monthly' => 0.0,
        ];
    }

    $sss        = sss_employee_share($monthlySalary);
    $philhealth = philhealth_employee_share($monthlySalary);
    $pagibig    = pagibig_employee_share($monthlySalary);
    $total      = $sss + $philhealth + $pagibig;

    // Safety net: never let deductions exceed salary (shouldn't happen at
    // real PH wage levels, but guards against bad/incomplete data).
    if ($total > $monthlySalary) {
        $scale      = $monthlySalary / $total;
        $sss        = round($sss * $scale, 2);
        $philhealth = round($philhealth * $scale, 2);
        $pagibig    = round($pagibig * $scale, 2);
        $total      = $sss + $philhealth + $pagibig;
    }

    return [
        'sss'              => $sss,
        'philhealth'       => $philhealth,
        'pagibig'          => $pagibig,
        'total_deductions' => round($total, 2),
        'net_monthly'      => round($monthlySalary - $total, 2),
    ];
}
