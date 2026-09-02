<?php
require_once __DIR__ . '/../admin/Permissions.php';
require_hr_login();
require_permission('view_own_payroll');
require_once __DIR__ . '/../includes/DB_Connect.php';

$active_page = 'hr_payroll';
$uid         = current_hr_user_id();
$role        = current_role();
$can_manage  = has_permission('run_payroll');
$msg         = '';

const COMPANY_NAME = 'CloudCup';

// Runtime computation defaults — HR can override every one of these before
// saving. None of these are stored as separate columns; only the resulting
// peso amounts they produce (basic_pay, overtime_pay, etc.) get saved,
// matching your existing `payroll` table.
const DEFAULT_RATE_PER_HOUR = 100.00;
const DEFAULT_STD_HOURS_DAY = 8.00;
const DEFAULT_OT_MULTIPLIER = 1.25;
const DEFAULT_LATE_PENALTY  = 50.00;   // peso deduction per late instance
const APPROX_WORKDAYS_PER_MONTH = 22;  // used only to estimate a "monthly basic
                                        // salary" for looking up SSS/PhilHealth/
                                        // Pag-IBIG brackets, which are official
                                        // government schedules based on MONTHLY
                                        // pay, not the per-period gross.

// ── Government contribution tables (2026 rates) ─────────────────────
// SSS:        15% of Monthly Salary Credit (MSC) — 5% employee / 10% employer,
//             MSC in ₱500 steps from ₱5,000 to ₱35,000, plus employer-only EC
//             (₱10 if MSC < ₱15,000, ₱30 if ≥ ₱15,000). RA 11199 / Circular 2024-006.
// PhilHealth: 5% of monthly basic salary, split 50/50 (2.5% each), floor
//             ₱10,000 / ceiling ₱100,000. RA 11223 (UHC Act), 2026 schedule.
// Pag-IBIG:   2% employee + 2% employer of monthly compensation (1% employee
//             if salary ≤ ₱1,500), capped at Maximum Fund Salary ₱10,000
//             (so max ₱200 per side). RA 9679 / HDMF Circular No. 460.
// ⚠ These change periodically — re-check against the official SSS/PhilHealth/
// Pag-IBIG circulars if this project is ever used past 2026. All computed
// amounts below remain editable per payslip before saving.

function sss_msc(float $monthly_salary): float {
    return min(35000, max(5000, ceil($monthly_salary / 500) * 500));
}
function sss_employee_monthly(float $monthly_salary): float {
    return round(sss_msc($monthly_salary) * 0.05, 2);
}
function sss_employer_monthly(float $monthly_salary): float {
    $msc = sss_msc($monthly_salary);
    $ec  = $msc < 15000 ? 10 : 30;
    return round($msc * 0.10, 2) + $ec;
}
function philhealth_employee_monthly(float $monthly_salary): float {
    $base = min(100000, max(10000, $monthly_salary));
    return round($base * 0.025, 2);
}
function philhealth_employer_monthly(float $monthly_salary): float {
    return philhealth_employee_monthly($monthly_salary); // 2.5% each, same formula
}
function pagibig_employee_monthly(float $monthly_salary): float {
    $base = min(10000, $monthly_salary);
    $rate = $monthly_salary <= 1500 ? 0.01 : 0.02;
    return round($base * $rate, 2);
}
function pagibig_employer_monthly(float $monthly_salary): float {
    return round(min(10000, $monthly_salary) * 0.02, 2);
}

$PERIOD_DIVISORS = ['weekly' => 4, 'semi_monthly' => 2, 'monthly' => 1];

$payroll_table_ready = false;
if ($conn) {
    $chk = mysqli_query($conn, "SHOW TABLES LIKE 'payroll'");
    $payroll_table_ready = $chk && mysqli_num_rows($chk) > 0;
}

// ── Detect optional columns so this page works with your table as-is,
// and upgrades automatically the moment you add them. ────────────────
function get_table_columns(mysqli $conn, string $table): array {
    $cols = [];
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $cols[] = $r['Field'];
    return $cols;
}
$payroll_cols = $payroll_table_ready ? get_table_columns($conn, 'payroll') : [];
$has_split_deductions = in_array('late_deduction', $payroll_cols, true) && in_array('absence_deduction', $payroll_cols, true);
$has_bank_tax_cols    = in_array('bank_details', $payroll_cols, true) && in_array('tax_number', $payroll_cols, true);

// ── Employees (HR/manager only, for the search-picker) ──────────────
$employees = [];
if ($conn && $can_manage) {
    $er = mysqli_query($conn,
        "SELECT user_id, full_name, role FROM users WHERE role IN ('employee','manager') AND is_active = 1 ORDER BY full_name");
    if ($er) while ($r = mysqli_fetch_assoc($er)) $employees[] = $r;
}

// ─────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────
function get_scheduled_days(mysqli $conn, int $employee_id): array {
    $days = [];
    $chk = mysqli_query($conn, "SHOW TABLES LIKE 'schedules'");
    if (!$chk || mysqli_num_rows($chk) === 0) return $days;
    $s = mysqli_prepare($conn, "SELECT day_of_week, start_time, end_time FROM schedules WHERE employee_id=?");
    if (!$s) return $days;
    mysqli_stmt_bind_param($s, 'i', $employee_id);
    mysqli_stmt_execute($s);
    $res = mysqli_stmt_get_result($s);
    if ($res) while ($r = mysqli_fetch_assoc($res)) {
        $hrs = (strtotime($r['end_time']) - strtotime($r['start_time'])) / 3600;
        $days[$r['day_of_week']] = ($days[$r['day_of_week']] ?? 0) + max(0, $hrs);
    }
    return $days;
}

// Walks every date in the period, cross-references `attendance` against the
// employee's scheduled days, and returns per-day rows + the tallies payroll needs.
function compute_attendance_breakdown(mysqli $conn, int $employee_id, string $start, string $end, array $scheduled_days, float $std_hours): array {
    $att_by_date = [];
    $s = mysqli_prepare($conn, "SELECT * FROM attendance WHERE employee_id=? AND work_date BETWEEN ? AND ?");
    if ($s) {
        mysqli_stmt_bind_param($s, 'iss', $employee_id, $start, $end);
        mysqli_stmt_execute($s);
        $res = mysqli_stmt_get_result($s);
        if ($res) while ($r = mysqli_fetch_assoc($res)) $att_by_date[$r['work_date']] = $r;
    }

    $rows = [];
    $present = $late = $absent = $auto_leave = 0;
    $regular_hours = $overtime_hours = 0.0;

    $cursor = new DateTime($start);
    $end_dt = new DateTime($end);
    while ($cursor <= $end_dt) {
        $date = $cursor->format('Y-m-d');
        $dow  = $cursor->format('l');
        $is_scheduled = isset($scheduled_days[$dow]);
        $row  = $att_by_date[$date] ?? null;

        if ($row) {
            $hours = (float)($row['hours_worked'] ?? 0);
            switch ($row['status']) {
                case 'late':
                    $late++; $present++;
                    $regular_hours += min($hours, $std_hours);
                    $overtime_hours += max(0, $hours - $std_hours);
                    break;
                case 'on_leave':
                    $auto_leave++;
                    break;
                case 'absent':
                    $absent++;
                    break;
                default: // present
                    $present++;
                    $regular_hours += min($hours, $std_hours);
                    $overtime_hours += max(0, $hours - $std_hours);
            }
            $rows[] = [
                'date' => $date, 'day' => $dow, 'scheduled' => $is_scheduled,
                'time_in' => $row['time_in'], 'time_out' => $row['time_out'],
                'hours' => $hours, 'status' => $row['status'],
            ];
        } elseif ($is_scheduled) {
            $absent++;
            $rows[] = [
                'date' => $date, 'day' => $dow, 'scheduled' => true,
                'time_in' => null, 'time_out' => null, 'hours' => 0, 'status' => 'absent',
            ];
        }
        $cursor->modify('+1 day');
    }

    return [
        'rows' => $rows,
        'present_days' => $present, 'late_days' => $late, 'absent_days' => $absent,
        'auto_leave_days' => $auto_leave,
        'regular_hours' => round($regular_hours, 2), 'overtime_hours' => round($overtime_hours, 2),
    ];
}

function money(float $n): string { return number_format($n, 2); }

// ─────────────────────────────────────────────────────────────────
// Preview (compute, not yet saved) — triggered by GET from the picker
// ─────────────────────────────────────────────────────────────────
$preview = null;
$sel_employee_id = (int)($_GET['employee_id'] ?? 0);
$period_start = $_GET['period_start'] ?? date('Y-m-01');
$period_end   = $_GET['period_end']   ?? date('Y-m-d');
$period_type  = in_array($_GET['period_type'] ?? '', array_keys($PERIOD_DIVISORS), true) ? $_GET['period_type'] : 'semi_monthly';

if ($can_manage && $conn && $sel_employee_id && $period_start && $period_end && ($_GET['act'] ?? '') === 'compute') {
    if (strtotime($period_start) > strtotime($period_end)) {
        $msg = 'error:Period start must be before the period end.';
    } else {
        $scheduled_days = get_scheduled_days($conn, $sel_employee_id);
        $expected_weekly_hours = array_sum($scheduled_days);
        $bd = compute_attendance_breakdown($conn, $sel_employee_id, $period_start, $period_end, $scheduled_days, DEFAULT_STD_HOURS_DAY);

        $emp_name = 'Employee';
        foreach ($employees as $e) if ((int)$e['user_id'] === $sel_employee_id) { $emp_name = $e['full_name']; break; }

        $divisor = $PERIOD_DIVISORS[$period_type];

        // SSS/PhilHealth/Pag-IBIG brackets are based on the employee's fixed,
        // official monthly salary — NOT on whatever rate_per_hour/std_hours
        // HR happens to type in for this specific run (that's only for
        // computing THIS period's gross pay from actual attendance). Pull
        // the employee's real daily_rate from `employees` for that.
        $employee_daily_rate = 0.0;
        $dr = mysqli_prepare($conn, "SELECT daily_rate FROM employees WHERE employee_id = ?");
        if ($dr) {
            mysqli_stmt_bind_param($dr, 'i', $sel_employee_id);
            mysqli_stmt_execute($dr);
            $dres = mysqli_stmt_get_result($dr);
            if ($dres && ($row = mysqli_fetch_assoc($dres))) $employee_daily_rate = (float) $row['daily_rate'];
        }

        $has_official_rate = $employee_daily_rate > 0;
        $monthly_basic_estimate = $has_official_rate
            ? $employee_daily_rate * APPROX_WORKDAYS_PER_MONTH
            : DEFAULT_RATE_PER_HOUR * DEFAULT_STD_HOURS_DAY * APPROX_WORKDAYS_PER_MONTH; // fallback: employee has no daily_rate set yet in HR records

        $preview = [
            'employee_id' => $sel_employee_id, 'employee_name' => $emp_name,
            'period_start' => $period_start, 'period_end' => $period_end, 'period_type' => $period_type,
            'expected_weekly_hours' => $expected_weekly_hours,
            'rate_per_hour' => $has_official_rate ? round($employee_daily_rate / DEFAULT_STD_HOURS_DAY, 2) : DEFAULT_RATE_PER_HOUR,
            'std_hours' => DEFAULT_STD_HOURS_DAY,
            'ot_mult' => DEFAULT_OT_MULTIPLIER,
            'late_penalty' => DEFAULT_LATE_PENALTY,
            'employee_daily_rate' => $employee_daily_rate,
            'has_official_rate' => $has_official_rate,
            'sss' => round(sss_employee_monthly($monthly_basic_estimate) / $divisor, 2),
            'philhealth' => round(philhealth_employee_monthly($monthly_basic_estimate) / $divisor, 2),
            'pagibig' => round(pagibig_employee_monthly($monthly_basic_estimate) / $divisor, 2),
            'employer_sss' => round(sss_employer_monthly($monthly_basic_estimate) / $divisor, 2),
            'employer_philhealth' => round(philhealth_employer_monthly($monthly_basic_estimate) / $divisor, 2),
            'employer_pagibig' => round(pagibig_employer_monthly($monthly_basic_estimate) / $divisor, 2),
        ] + $bd;
    }
}

// ─────────────────────────────────────────────────────────────────
// Save a DRAFT payslip — attendance-derived numbers are recomputed
// server-side from the DB (never trusted from POST); only the
// HR-entered override fields are trusted.
// ─────────────────────────────────────────────────────────────────
if ($can_manage && $conn && $payroll_table_ready && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'save_payroll') {
    $employee_id  = (int)($_POST['employee_id'] ?? 0);
    $p_start      = $_POST['period_start'] ?? '';
    $p_end        = $_POST['period_end'] ?? '';
    $rate         = max(0, (float)($_POST['rate_per_hour'] ?? DEFAULT_RATE_PER_HOUR));
    $std_hours    = max(0.5, (float)($_POST['std_hours'] ?? DEFAULT_STD_HOURS_DAY));
    $ot_mult      = max(1, (float)($_POST['ot_mult'] ?? DEFAULT_OT_MULTIPLIER));
    $late_penalty = max(0, (float)($_POST['late_penalty'] ?? DEFAULT_LATE_PENALTY));
    $paid_leave_override = $_POST['paid_leave_days'] !== '' ? max(0, (int)$_POST['paid_leave_days']) : null;
    $sss          = max(0, (float)($_POST['sss'] ?? 0));
    $philhealth   = max(0, (float)($_POST['philhealth'] ?? 0));
    $pagibig      = max(0, (float)($_POST['pagibig'] ?? 0));
    $income_tax   = max(0, (float)($_POST['income_tax'] ?? 0));
    $loan_ded     = max(0, (float)($_POST['loan_deduction'] ?? 0));
    $employer_sss = max(0, (float)($_POST['employer_sss'] ?? 0));
    $employer_phic = max(0, (float)($_POST['employer_philhealth'] ?? 0));
    $employer_pagibig = max(0, (float)($_POST['employer_pagibig'] ?? 0));
    $bank_details = trim($_POST['bank_details'] ?? '');
    $tax_number   = trim($_POST['tax_number'] ?? '');

    if (!$employee_id || !$p_start || !$p_end || strtotime($p_start) > strtotime($p_end)) {
        $msg = 'error:Missing or invalid employee/period. Please compute a preview first.';
    } else {
        $scheduled_days = get_scheduled_days($conn, $employee_id);
        $bd = compute_attendance_breakdown($conn, $employee_id, $p_start, $p_end, $scheduled_days, $std_hours);
        $paid_leave_days = $paid_leave_override ?? $bd['auto_leave_days'];
        $days_worked = $bd['present_days'];

        $basic_pay      = round($bd['regular_hours'] * $rate, 2);
        $overtime_pay   = round($bd['overtime_hours'] * $rate * $ot_mult, 2);
        $allowance      = round($paid_leave_days * $std_hours * $rate, 2); // paid leave pay, stored in `allowance`
        $gross_pay      = round($basic_pay + $overtime_pay + $allowance, 2);

        $late_deduction = round($bd['late_days'] * $late_penalty, 2); // genuine penalty — the late day IS paid (hours logged), this is on top of that

        // Absent days already earn ₱0 in basic_pay above (no hours were logged
        // for them), so a separate peso deduction here would dock the SAME
        // day twice — once by paying nothing, again by subtracting a full
        // day's wage from what's left. Absences show up in the report simply
        // as "no pay for that day," not as an additional penalty.
        $absence_deduction = 0.0;

        // Can't legally withhold SSS/PhilHealth/Pag-IBIG/tax from a period
        // where the employee earned nothing (no attendance/schedule at all,
        // fully on unpaid leave, etc.) — there's no wage to deduct from or
        // base an employer contribution on.
        if ($gross_pay <= 0) {
            $sss = $philhealth = $pagibig = $income_tax = 0.0;
            $employer_sss = $employer_phic = $employer_pagibig = 0.0;
        }

        // Fold late+absence into loan_deduction only if the dedicated columns
        // don't exist yet — see the ALTER TABLE note at the top of this file.
        $loan_deduction_final = $has_split_deductions ? $loan_ded : round($loan_ded + $late_deduction + $absence_deduction, 2);

        $total_deductions = round($sss + $philhealth + $pagibig + $income_tax + $loan_deduction_final
            + ($has_split_deductions ? ($late_deduction + $absence_deduction) : 0), 2);

        // Deductions (esp. a large loan installment) should never push pay
        // below ₱0 in a single period — cap them at what's actually available
        // and flag it so HR/Finance knows to spread the rest over later runs.
        $deduction_cap_warning = '';
        if ($total_deductions > $gross_pay) {
            $deferred_amount = round($total_deductions - $gross_pay, 2);
            $total_deductions = $gross_pay;
            $deduction_cap_warning = ' ⚠ Deductions exceeded gross pay — capped at ₱0 net. ₱' . money($deferred_amount) . ' was NOT applied this period; carry it over manually to the next payslip.';
        }
        $net_pay = round($gross_pay - $total_deductions, 2);

        // Build the INSERT dynamically around whichever optional columns exist.
        $cols = ['employee_id','period_start','period_end','days_worked','basic_pay','overtime_pay',
                 'allowance','gross_pay','sss_employee','philhealth_employee','pagibig_employee',
                 'income_tax','loan_deduction','total_deductions','net_pay',
                 'employer_sss','employer_philhealth','employer_pagibig','status','generated_by'];
        $types  = 'issiddddddddddddddsi';
        $params = [$employee_id, $p_start, $p_end, $days_worked, $basic_pay, $overtime_pay,
                   $allowance, $gross_pay, $sss, $philhealth, $pagibig,
                   $income_tax, $loan_deduction_final, $total_deductions, $net_pay,
                   $employer_sss, $employer_phic, $employer_pagibig, 'draft', $uid];

        if ($has_split_deductions) {
            $cols[] = 'late_deduction'; $types .= 'd'; $params[] = $late_deduction;
            $cols[] = 'absence_deduction'; $types .= 'd'; $params[] = $absence_deduction;
        }
        if ($has_bank_tax_cols) {
            $cols[] = 'bank_details'; $types .= 's'; $params[] = $bank_details;
            $cols[] = 'tax_number'; $types .= 's'; $params[] = $tax_number;
        }

        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $sql = "INSERT INTO payroll (" . implode(',', $cols) . ") VALUES ($placeholders)";
        $s = mysqli_prepare($conn, $sql);
        if ($s) {
            $bind = [$s, $types];
            foreach ($params as $k => $v) $bind[] = &$params[$k];
            call_user_func_array('mysqli_stmt_bind_param', $bind);
            if (mysqli_stmt_execute($s)) {
                $msg = 'success:Draft payslip saved for ' . date('M j', strtotime($p_start)) . '–' . date('M j, Y', strtotime($p_end)) . '. It now awaits approval and release from Finance.' . $deduction_cap_warning;
            } else {
                $msg = 'error:Could not save the payslip — ' . mysqli_stmt_error($s);
            }
        } else {
            $msg = 'error:Could not save the payslip — ' . mysqli_error($conn);
        }
        $sel_employee_id = $employee_id; $period_start = $p_start; $period_end = $p_end;
    }
} elseif ($conn && !$payroll_table_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = 'error:The payroll table hasn\'t been created in the database yet, so nothing was saved.';
}

// NOTE: releasing a payslip (draft -> released) no longer happens here.
// It's now handled by Finance's CFM pages (finance_payroll_approval.php /
// finance_payroll_processing.php), so approval + distribution has a single
// owner. HR only computes/saves drafts and displays status below.

if ($can_manage && $conn && $payroll_table_ready && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'delete_payroll') {
    $pid = (int)($_POST['payroll_id'] ?? 0);
    if ($pid) {
        $s = mysqli_prepare($conn, "DELETE FROM payroll WHERE payroll_id=?");
        if ($s) { mysqli_stmt_bind_param($s, 'i', $pid); mysqli_stmt_execute($s); }
        $msg = 'success:Payslip deleted.';
    }
}

// ── Saved payslips list ──────────────────────────────────────────
$payslips = [];
if ($conn && $payroll_table_ready) {
    if ($can_manage) {
        $filter_emp = (int)($_GET['view_employee'] ?? 0);
        $where = $filter_emp ? "WHERE p.employee_id = $filter_emp" : '';
        $res = mysqli_query($conn, "SELECT p.*, u.full_name FROM payroll p JOIN users u ON u.user_id = p.employee_id
            $where ORDER BY p.period_end DESC, u.full_name LIMIT 100");
    } else {
        $s = mysqli_prepare($conn, "SELECT p.*, u.full_name FROM payroll p JOIN users u ON u.user_id = p.employee_id
            WHERE p.employee_id=? AND p.status='released' ORDER BY p.period_end DESC LIMIT 50");
        mysqli_stmt_bind_param($s, 'i', $uid);
        mysqli_stmt_execute($s);
        $res = mysqli_stmt_get_result($s);
    }
    if ($res) while ($r = mysqli_fetch_assoc($res)) $payslips[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script>(function(){try{var t=localStorage.getItem('cloudcup-theme')||'light';document.documentElement.classList.toggle('dark-mode',t==='dark');document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="stylesheet" href="../css/theme.css"/>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= $can_manage ? 'Payroll Management' : 'My Payslips' ?> — Cloud Cup HR</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/hr_module.css"/>
  <link rel="stylesheet" href="../css/payroll_page.css"/>
  <style>
    .payslip-sheet{--ps-blue:#1c4d80;--ps-blue-dark:#123a63;border:6px solid var(--ps-blue);border-radius:4px;padding:28px 34px;max-width:640px;margin:0 auto;background:#f4e3d3;color:#1c2b3a;font-family:Inter,Arial,sans-serif}
    .payslip-sheet .ps-label{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#5f80a3;margin-bottom:14px}
    .ps-logo{display:flex;align-items:center;gap:10px;margin-bottom:2px}
    .ps-logo svg{color:var(--ps-blue-dark);flex-shrink:0}
    .payslip-sheet h1{font-family:'Fraunces',serif;font-size:30px;margin:0;color:var(--ps-blue-dark)}
    .payslip-sheet h2{font-family:'Fraunces',serif;font-size:18px;letter-spacing:.08em;margin:6px 0 18px;font-weight:700;color:var(--ps-blue)}
    .ps-fields{font-size:13.5px;line-height:2;margin-bottom:16px}
    .ps-fields .ps-row{display:flex;gap:24px}
    .ps-fields .ps-row > div{flex:1}
    .ps-fields b{font-weight:600;color:var(--ps-blue-dark)}
    .ps-fields input{border:none;border-bottom:1px dotted #7fa3c7;background:transparent;font-family:inherit;font-size:13.5px;width:100%;padding:1px 2px;color:#1c2b3a}
    .ps-table{width:100%;border-collapse:collapse;font-size:13px;margin-top:6px}
    .ps-table th{background:#d3e6f7;color:var(--ps-blue-dark);text-align:left;padding:7px 8px;border:1px solid #b9d5ec;font-weight:600}
    .ps-table td{padding:6px 8px;border:1px solid #cfe2f2}
    .ps-table td.amt{text-align:right}
    .ps-total-row td{font-weight:700;background:#dcecf9}
    .ps-net{margin-top:14px;display:flex;justify-content:space-between;border-top:2px solid var(--ps-blue);padding-top:10px;font-size:16px;font-weight:700;color:var(--ps-blue-dark)}
    .status-pill.pill-draft{background:#fff3cd;color:#8a6300}
    .status-pill.pill-released{background:#d3e6f7;color:#123a63}
    .emp-autocomplete{position:relative}
    .emp-suggestions{
      display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:40;
      max-height:240px;overflow-y:auto;background:var(--white);border:1px solid var(--hr-border, var(--cream));
      border-radius:8px;box-shadow:0 10px 24px rgba(11,30,51,0.16);padding:6px;
    }
    .emp-suggestions.open{display:block}
    .emp-suggestion{
      padding:9px 10px;border-radius:6px;font-size:13px;color:var(--text);cursor:pointer;
    }
    .emp-suggestion:hover,
    .emp-suggestion.active{background:var(--cream-light);color:var(--text)}
    .emp-suggestions .emp-suggestion-empty{padding:9px 10px;font-size:12.5px;color:var(--text-light);cursor:default}
    @media print{
      body *{visibility:hidden}
      #payslipPrintArea, #payslipPrintArea *{visibility:visible}
      #payslipPrintArea{position:absolute;top:0;left:0;width:100%}
      .modal-admin-header, .modal-admin-actions .btn-ghost{display:none !important}
      .ps-fields input{border:none}
    }
  </style>
</head>
<body>

<script src="../js/sidebar-toggle.js"></script>
<?php require_once '../HR/Sidebar_HR.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle-btn" onclick="toggleSidebar()">☰</button>
      <h1><?= $can_manage ? 'Payroll Management' : 'My Payslips' ?></h1>
    </div>
    <div class="topbar-right"><div class="topbar-date"><?= date('F j, Y') ?></div></div>
  </div>

  <div class="content">
    <?php if (!$payroll_table_ready): ?>
      <div class="msg-banner error">
        The <code>payroll</code> table doesn't exist in the database yet, so payroll can't be run or viewed.
      </div>
    <?php elseif ($can_manage && !$has_split_deductions): ?>
      <div class="msg-banner" style="background:#fff3cd;color:#8a6300">
        Tip: your <code>payroll</code> table doesn't have <code>late_deduction</code> / <code>absence_deduction</code> columns yet, so those amounts are currently folded into <code>loan_deduction</code> on save. Add the two columns any time (see the ALTER TABLE note at the top of this file) and this page will start storing them separately automatically.
      </div>
    <?php endif; ?>

    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>"><?= htmlspecialchars($mm) ?></div>
    <?php endif; ?>

    <?php if ($can_manage): ?>
    <!-- ── Step 1: pick employee + pay period ─────────────────────── -->
    <div class="widget">
      <div class="widget-title">Run Payroll</div>
      <div style="font-size:12.5px;color:var(--hr-text-light);margin:2px 0 14px">Search an employee, choose the pay period, then compute — hours, lates, and absences are pulled straight from Attendance and cross-checked against their Schedule.</div>
      <form method="GET" id="pickerForm">
        <input type="hidden" name="act" value="compute">
        <div class="form-row" style="align-items:flex-end;gap:14px;flex-wrap:wrap">
          <div class="form-group-admin emp-autocomplete" style="min-width:240px">
            <label>Employee *</label>
            <input type="text" id="employeeSearchInput" placeholder="Type a name to search…"
                   value="<?= $sel_employee_id && $preview ? htmlspecialchars($preview['employee_name']) : '' ?>"
                   autocomplete="off" required
                   style="padding:9px 12px;border-radius:8px;border:1px solid var(--hr-border, var(--cream));font-size:13px;font-family:inherit;width:100%">
            <div id="employeeSuggestions" class="emp-suggestions" role="listbox"></div>
            <input type="hidden" name="employee_id" id="employee_id_field" value="<?= $sel_employee_id ?: '' ?>">
            <script type="application/json" id="employeeListData"><?= json_encode(array_map(fn($e) => ['id' => $e['user_id'], 'name' => $e['full_name']], $employees)) ?></script>
          </div>
          <div class="form-group-admin">
            <label>Period Start *</label>
            <input type="date" name="period_start" value="<?= htmlspecialchars($period_start) ?>" required>
          </div>
          <div class="form-group-admin">
            <label>Period End *</label>
            <input type="date" name="period_end" value="<?= htmlspecialchars($period_end) ?>" required>
          </div>
          <div class="form-group-admin">
            <label>Pay Cycle</label>
            <select name="period_type">
              <?php foreach ($PERIOD_DIVISORS as $k => $d): ?>
                <option value="<?= $k ?>" <?= $period_type === $k ? 'selected' : '' ?>><?= ucwords(str_replace('_',' ',$k)) ?></option>
              <?php endforeach; ?>
            </select>
            <div style="font-size:11px;color:var(--hr-text-light);margin-top:3px">Only used to suggest default SSS/PhilHealth/Pag-IBIG amounts below.</div>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Compute Preview</button>
        </div>
      </form>
    </div>

    <?php if ($preview): ?>
    <!-- ── Step 2: review the computed breakdown, override, and save ── -->
    <div class="widget">
      <div class="widget-header">
        <div class="widget-title"><?= htmlspecialchars($preview['employee_name']) ?> — <?= date('M j', strtotime($preview['period_start'])) ?> to <?= date('M j, Y', strtotime($preview['period_end'])) ?></div>
      </div>

      <table style="margin-bottom:16px">
        <thead><tr><th>Date</th><th>Day</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Status</th></tr></thead>
        <tbody>
          <?php if (empty($preview['rows'])): ?>
            <tr><td colspan="6" class="empty-state">No scheduled work days or attendance records in this period.</td></tr>
          <?php else: foreach ($preview['rows'] as $r):
            $pc = ['present'=>'pill-present','late'=>'pill-pending','absent'=>'pill-absent','on_leave'=>'pill-onleave'][$r['status']] ?? 'pill-present'; ?>
          <tr>
            <td><?= date('M d, Y', strtotime($r['date'])) ?></td>
            <td><?= $r['day'] ?></td>
            <td><?= $r['time_in'] ? date('g:i A', strtotime($r['time_in'])) : '—' ?></td>
            <td><?= $r['time_out'] ? date('g:i A', strtotime($r['time_out'])) : '—' ?></td>
            <td><?= $r['hours'] > 0 ? number_format($r['hours'], 2) : '—' ?></td>
            <td><span class="status-pill <?= $pc ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
      <div style="font-size:12.5px;color:var(--hr-text-light);margin-bottom:16px">
        Tally: <strong><?= $preview['present_days'] ?></strong> present · <strong><?= $preview['late_days'] ?></strong> late · <strong><?= $preview['absent_days'] ?></strong> absent · <strong><?= $preview['auto_leave_days'] ?></strong> on leave (from Attendance) · scheduled ~<strong><?= number_format($preview['expected_weekly_hours'],1) ?></strong> hrs/week (from Schedule).
      </div>

      <form method="POST" id="saveForm">
        <input type="hidden" name="act" value="save_payroll">
        <input type="hidden" name="employee_id" value="<?= $preview['employee_id'] ?>">
        <input type="hidden" name="period_start" value="<?= $preview['period_start'] ?>">
        <input type="hidden" name="period_end" value="<?= $preview['period_end'] ?>">

        <div class="form-row" style="flex-wrap:wrap;gap:14px">
          <div class="form-group-admin"><label>Rate / Hour (₱)</label>
            <input type="number" step="0.01" name="rate_per_hour" id="rate_per_hour" value="<?= $preview['rate_per_hour'] ?>"></div>
          <div class="form-group-admin"><label>Standard Hours / Day</label>
            <input type="number" step="0.5" name="std_hours" id="std_hours" value="<?= $preview['std_hours'] ?>"></div>
          <div class="form-group-admin"><label>Overtime Multiplier</label>
            <input type="number" step="0.05" name="ot_mult" value="<?= $preview['ot_mult'] ?>"></div>
          <div class="form-group-admin"><label>Late Penalty / Instance (₱)</label>
            <input type="number" step="0.01" name="late_penalty" value="<?= $preview['late_penalty'] ?>"></div>
          <div class="form-group-admin"><label>Paid Leave Days</label>
            <input type="number" step="1" name="paid_leave_days" value="<?= $preview['auto_leave_days'] ?>">
            <div style="font-size:11px;color:var(--hr-text-light);margin-top:3px">Auto-detected from "On Leave" attendance status — adjust if needed.</div></div>
        </div>

        <div class="form-row" style="flex-wrap:wrap;gap:14px;margin-top:6px">
          <div style="flex-basis:100%;font-size:11.5px;font-weight:700;color:var(--hr-text-light);text-transform:uppercase;letter-spacing:.5px;margin-top:6px">
            Employee Contributions (deducted from pay)
          </div>
          <div style="flex-basis:100%;font-size:11px;color:var(--hr-text-light);margin-top:-6px;">
            <?php if ($preview['has_official_rate']): ?>
              Based on <?= htmlspecialchars($preview['employee_name']) ?>'s official daily rate on file (₱<?= number_format($preview['employee_daily_rate'], 2) ?>/day) — this stays fixed regardless of attendance this period. Editable below if you need to override.
            <?php else: ?>
              ⚠ No official daily rate on file for <?= htmlspecialchars($preview['employee_name']) ?> yet (Employee Records) — these are rough placeholders. Set their daily rate first, then re-run Compute Preview for accurate figures.
            <?php endif; ?>
          </div>
          <div class="form-group-admin"><label>SSS (₱)</label>
            <input type="number" step="0.01" name="sss" id="sss" value="<?= $preview['sss'] ?>"></div>
          <div class="form-group-admin"><label>PhilHealth (₱)</label>
            <input type="number" step="0.01" name="philhealth" id="philhealth" value="<?= $preview['philhealth'] ?>"></div>
          <div class="form-group-admin"><label>Pag-IBIG (₱)</label>
            <input type="number" step="0.01" name="pagibig" id="pagibig" value="<?= $preview['pagibig'] ?>"></div>
          <div class="form-group-admin"><label>Withholding Tax (₱)</label>
            <input type="number" step="0.01" name="income_tax" value="0"></div>
          <div class="form-group-admin"><label>Loan Deduction (₱)</label>
            <input type="number" step="0.01" name="loan_deduction" value="0"></div>
        </div>

        <div class="form-row" style="flex-wrap:wrap;gap:14px;margin-top:6px">
          <div style="flex-basis:100%;font-size:11.5px;font-weight:700;color:var(--hr-text-light);text-transform:uppercase;letter-spacing:.5px;margin-top:6px">Employer Contributions (business cost, not deducted from employee)</div>
          <div class="form-group-admin"><label>Employer SSS Share (₱)</label>
            <input type="number" step="0.01" name="employer_sss" id="employer_sss" value="<?= $preview['employer_sss'] ?>"></div>
          <div class="form-group-admin"><label>Employer PhilHealth Share (₱)</label>
            <input type="number" step="0.01" name="employer_philhealth" id="employer_philhealth" value="<?= $preview['employer_philhealth'] ?>"></div>
          <div class="form-group-admin"><label>Employer Pag-IBIG Share (₱)</label>
            <input type="number" step="0.01" name="employer_pagibig" id="employer_pagibig" value="<?= $preview['employer_pagibig'] ?>"></div>
        </div>

        <?php if ($has_bank_tax_cols): ?>
        <div class="form-row" style="flex-wrap:wrap;gap:14px;margin-top:6px">
          <div class="form-group-admin" style="flex:1"><label>Bank Details</label>
            <input type="text" name="bank_details" placeholder="Bank name — account no."></div>
          <div class="form-group-admin" style="flex:1"><label>Tax Number (TIN)</label>
            <input type="text" name="tax_number"></div>
        </div>
        <?php endif; ?>

        <div class="modal-admin-actions" style="justify-content:flex-start;margin-top:10px">
          <button type="submit" class="btn btn-primary">Save as Draft</button>
        </div>
      </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- ── Saved payslips ──────────────────────────────────────────── -->
    <div class="widget">
      <div class="widget-header">
        <div class="widget-title"><?= $can_manage ? 'All Payslips' : 'My Payslips' ?></div>
        <?php if ($can_manage && !empty($employees)): ?>
        <form method="GET">
          <select name="view_employee" onchange="this.form.submit()" style="padding:8px 10px;border-radius:8px;border:1px solid var(--hr-border);font-size:13px">
            <option value="0">All Staff</option>
            <?php foreach ($employees as $e): ?>
              <option value="<?= $e['user_id'] ?>" <?= ($_GET['view_employee'] ?? '') == $e['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
      </div>
      <table>
        <thead>
          <tr>
            <?php if ($can_manage): ?><th>Employee</th><?php endif; ?>
            <th>Pay Period</th><th>Net Pay</th><th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($payslips)): ?>
            <tr><td colspan="5" class="empty-state">No payslips yet.</td></tr>
          <?php else: foreach ($payslips as $p): ?>
          <tr>
            <?php if ($can_manage): ?><td><?= htmlspecialchars($p['full_name']) ?></td><?php endif; ?>
            <td><?= date('M j', strtotime($p['period_start'])) ?> – <?= date('M j, Y', strtotime($p['period_end'])) ?></td>
            <td>₱<?= money((float)$p['net_pay']) ?></td>
            <td><span class="status-pill pill-<?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span></td>
            <td style="display:flex;gap:6px;flex-wrap:wrap">
              <button type="button" class="btn btn-ghost btn-sm" onclick='openPayslip(<?= json_encode($p) ?>)'>View Payslip</button>
              <?php if ($can_manage && $p['status'] === 'draft'): ?>
              <span class="status-pill" style="background:#f4e3d3;color:#b8703f;font-size:11px;">Awaiting Finance approval</span>
              <?php endif; ?>
              <?php if ($can_manage): ?>
              <form method="POST" onsubmit="return confirm('Delete this payslip? This cannot be undone.')">
                <input type="hidden" name="act" value="delete_payroll">
                <input type="hidden" name="payroll_id" value="<?= $p['payroll_id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- PAYSLIP MODAL / PRINT TEMPLATE -->
<div class="modal-overlay-admin" id="payslipModal">
  <div class="modal-admin-box" style="max-width:700px">
    <div class="modal-admin-header">
      <span>Payslip</span>
      <button class="modal-close-btn" onclick="document.getElementById('payslipModal').classList.remove('open')">✕</button>
    </div>

    <div id="payslipPrintArea">
      <div class="payslip-sheet">
        <div class="ps-logo">
          <svg width="30" height="22" viewBox="0 0 24 17" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M6.7 15.3C4.2 15.3 2.2 13.3 2.2 10.8C2.2 8.55 3.85 6.7 6 6.35C6.55 3.75 8.85 1.7 11.7 1.7C14.85 1.7 17.45 4.05 17.8 7.05C20.1 7.35 21.9 9.3 21.9 11.65C21.9 13.65 20.25 15.3 18.25 15.3H6.7Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
          </svg>
          <h1><?= htmlspecialchars(COMPANY_NAME) ?></h1>
        </div>
        <h2>PAYSLIP</h2>

        <div class="ps-fields">
          <div class="ps-row"><div><b>Employee:</b> <span id="ps_employee"></span></div><div><b>Pay Period:</b> <span id="ps_period"></span></div></div>
          <div class="ps-row"><div><b>ID Number:</b> <span id="ps_id"></span></div><div><b>Days Worked:</b> <span id="ps_days"></span></div></div>
          <div class="ps-row">
            <div><b>Bank Details:</b> <input type="text" id="ps_bank" placeholder="Type here before printing"></div>
            <div><b>Tax Number:</b> <input type="text" id="ps_tax" placeholder="Type here before printing"></div>
          </div>
        </div>

        <table class="ps-table">
          <thead><tr><th>Earnings</th><th class="amt">Amount</th><th>Deductions</th><th class="amt">Amount</th></tr></thead>
          <tbody>
            <tr><td>Regular Pay</td><td class="amt" id="ps_basic"></td><td>SSS</td><td class="amt" id="ps_sss"></td></tr>
            <tr><td>Overtime Pay</td><td class="amt" id="ps_ot"></td><td>PhilHealth</td><td class="amt" id="ps_phic"></td></tr>
            <tr><td>Paid Leave / Allowance</td><td class="amt" id="ps_allow"></td><td>Pag-IBIG</td><td class="amt" id="ps_pagibig"></td></tr>
            <tr><td></td><td class="amt"></td><td>Withholding Tax</td><td class="amt" id="ps_tax_ded"></td></tr>
            <tr id="ps_late_row"><td></td><td class="amt"></td><td>Late / Tardiness</td><td class="amt" id="ps_late"></td></tr>
            <tr id="ps_absent_row"><td></td><td class="amt"></td><td>Absences</td><td class="amt" id="ps_absent"></td></tr>
            <tr><td></td><td class="amt"></td><td id="ps_loan_label">Loan Deduction</td><td class="amt" id="ps_loan"></td></tr>
            <tr class="ps-total-row"><td>Gross Earnings</td><td class="amt" id="ps_gross"></td><td>Total Deductions</td><td class="amt" id="ps_total_ded"></td></tr>
          </tbody>
        </table>

        <div class="ps-net"><span>Net Salary Transferred</span><span id="ps_net"></span></div>
        <div id="ps_draft_banner" style="display:none;margin-top:14px;padding:10px 14px;background:#f4e3d3;color:#b8703f;font-weight:700;font-size:13px;text-align:center;border-radius:6px;border:1px dashed #E0A868;">
          ⚠ DRAFT — NOT YET APPROVED BY FINANCE. Not valid for release or record-keeping.
        </div>
        <div style="font-size:10.5px;color:#999;margin-top:16px">Status: <span id="ps_status"></span></div>
      </div>
    </div>

    <div class="modal-admin-actions">
      <button type="button" class="btn btn-ghost" onclick="document.getElementById('payslipModal').classList.remove('open')">Close</button>
      <button type="button" class="btn btn-primary" id="printPayslipBtn" onclick="window.print()">Print</button>
    </div>
    <div id="printBlockedNote" style="display:none;text-align:right;font-size:11.5px;color:#b8703f;margin-top:6px">
      Still awaiting Finance approval — printing unlocks once it's released.
    </div>
  </div>
</div>

<script>
(function () {
  const input   = document.getElementById('employeeSearchInput');
  const box     = document.getElementById('employeeSuggestions');
  const field   = document.getElementById('employee_id_field');
  const dataEl  = document.getElementById('employeeListData');
  if (!input || !box || !field || !dataEl) return;

  const employees = JSON.parse(dataEl.textContent || '[]');
  let activeIndex = -1;

  function closeBox() {
    box.classList.remove('open');
    box.innerHTML = '';
    activeIndex = -1;
  }

  function selectEmployee(emp) {
    input.value = emp.name;
    field.value = emp.id;
    closeBox();
  }

  function renderSuggestions() {
    const q = input.value.trim().toLowerCase();
    const matches = q ? employees.filter(e => e.name.toLowerCase().includes(q)) : employees;

    box.innerHTML = '';
    if (!matches.length) {
      const empty = document.createElement('div');
      empty.className = 'emp-suggestion-empty';
      empty.textContent = 'No matching employees';
      box.appendChild(empty);
    } else {
      matches.slice(0, 30).forEach((emp, i) => {
        const item = document.createElement('div');
        item.className = 'emp-suggestion';
        item.setAttribute('role', 'option');
        item.textContent = emp.name;
        item.dataset.index = i;
        item.addEventListener('mousedown', function (e) {
          e.preventDefault();
          selectEmployee(emp);
        });
        box.appendChild(item);
      });
    }
    activeIndex = -1;
    box.classList.add('open');
  }

  function onEmployeySearchInput() {
    field.value = '';
    const exact = employees.find(e => e.name === input.value);
    if (exact) field.value = exact.id;
    renderSuggestions();
  }

  input.addEventListener('input', onEmployeySearchInput);
  input.addEventListener('focus', renderSuggestions);

  input.addEventListener('keydown', function (e) {
    const items = box.querySelectorAll('.emp-suggestion');
    if (!items.length) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      activeIndex = Math.min(activeIndex + 1, items.length - 1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      activeIndex = Math.max(activeIndex - 1, 0);
    } else if (e.key === 'Enter') {
      if (activeIndex >= 0 && items[activeIndex]) {
        e.preventDefault();
        items[activeIndex].dispatchEvent(new Event('mousedown'));
      }
      return;
    } else if (e.key === 'Escape') {
      closeBox();
      return;
    } else {
      return;
    }
    items.forEach(i => i.classList.remove('active'));
    items[activeIndex].classList.add('active');
    items[activeIndex].scrollIntoView({ block: 'nearest' });
  });

  document.addEventListener('click', function (e) {
    if (e.target !== input && !box.contains(e.target)) closeBox();
  });
})();

document.getElementById('pickerForm')?.addEventListener('submit', function (e) {
  if (!document.getElementById('employee_id_field').value) {
    e.preventDefault();
    alert('Please pick an employee from the search suggestions.');
  }
});

const HAS_SPLIT_DEDUCTIONS = <?= $has_split_deductions ? 'true' : 'false' ?>;

function peso(n) { return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtDate(d) { return new Date(d + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); }

function openPayslip(p) {
  document.getElementById('ps_employee').textContent = p.full_name;
  document.getElementById('ps_period').textContent   = fmtDate(p.period_start) + ' – ' + fmtDate(p.period_end);
  document.getElementById('ps_id').textContent        = p.employee_id;
  document.getElementById('ps_days').textContent      = p.days_worked;
  document.getElementById('ps_bank').value            = p.bank_details || '';
  document.getElementById('ps_tax').value             = p.tax_number || '';

  document.getElementById('ps_basic').textContent  = peso(p.basic_pay);
  document.getElementById('ps_ot').textContent     = peso(p.overtime_pay);
  document.getElementById('ps_allow').textContent  = peso(p.allowance);
  document.getElementById('ps_gross').textContent  = peso(p.gross_pay);

  document.getElementById('ps_sss').textContent      = peso(p.sss_employee);
  document.getElementById('ps_phic').textContent     = peso(p.philhealth_employee);
  document.getElementById('ps_pagibig').textContent  = peso(p.pagibig_employee);
  document.getElementById('ps_tax_ded').textContent  = peso(p.income_tax);

  if (HAS_SPLIT_DEDUCTIONS) {
    document.getElementById('ps_late_row').style.display = '';
    document.getElementById('ps_absent_row').style.display = '';
    document.getElementById('ps_late').textContent   = peso(p.late_deduction);
    document.getElementById('ps_absent').textContent = peso(p.absence_deduction);
    document.getElementById('ps_loan_label').textContent = 'Loan Deduction';
  } else {
    document.getElementById('ps_late_row').style.display = 'none';
    document.getElementById('ps_absent_row').style.display = 'none';
    document.getElementById('ps_loan_label').textContent = 'Loan / Late / Absence Deductions';
  }
  document.getElementById('ps_loan').textContent = peso(p.loan_deduction);
  document.getElementById('ps_total_ded').textContent = peso(p.total_deductions);
  document.getElementById('ps_net').textContent      = peso(p.net_pay);
  document.getElementById('ps_status').textContent   = p.status;

  // Printing an unapproved payslip could hand an employee numbers Finance
  // hasn't signed off on yet — only allow it once status is 'released'.
  const printBtn = document.getElementById('printPayslipBtn');
  const isReleased = p.status === 'released';
  printBtn.disabled = !isReleased;
  printBtn.style.opacity = isReleased ? '1' : '0.5';
  printBtn.style.cursor = isReleased ? 'pointer' : 'not-allowed';
  document.getElementById('printBlockedNote').style.display = isReleased ? 'none' : 'block';
  document.getElementById('ps_draft_banner').style.display = isReleased ? 'none' : 'block';

  document.getElementById('payslipModal').classList.add('open');
}
</script>

<script src="../js/msg_banner_autodismiss.js"></script>
<script src="../js/theme-toggle.js"></script>
</body>
</html>