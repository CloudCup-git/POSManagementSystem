<?php
/**
 * CloudCup — HR: Daily Attendance Sheet data
 * -------------------------------------------------------------
 * Builds a full roster snapshot for a single work date so HR sees
 * everyone who was supposed to be on the clock that day — not just
 * the people who happened to punch in. Shared by Attendance_Page.php
 * (on-screen table) and attendance_export.php (Excel download) so the
 * two can never show different numbers.
 *
 * Status per employee, for the given $view_date:
 *   present  — has an attendance row with time_in, status='present'
 *   late     — has an attendance row with time_in, status='late'
 *   on_leave — no clock-in, but an approved leave request covers this date
 *   pending  — no clock-in yet, but $view_date is today (day isn't over)
 *   absent   — no clock-in, no approved leave, and the date has already passed
 *
 * Employees who weren't hired yet as of $view_date are left out entirely
 * (an absence before someone's start date isn't a real absence).
 */
function hr_build_attendance_sheet(mysqli $conn, string $view_date, int $emp_filter = 0): array {
    $view_date = mysqli_real_escape_string($conn, $view_date);

    $staff_list = [];
    $sl = mysqli_query($conn,
        "SELECT u.user_id, u.full_name
         FROM users u
         LEFT JOIN employees e ON e.employee_id = u.user_id
         WHERE u.role IN ('employee','manager')
           AND COALESCE(e.employment_status, 'active') <> 'terminated'
           AND (e.date_hired IS NULL OR e.date_hired <= '$view_date')
         ORDER BY u.full_name");
    if ($sl) while ($r = mysqli_fetch_assoc($sl)) $staff_list[(int)$r['user_id']] = $r['full_name'];

    if ($emp_filter > 0) {
        $staff_list = isset($staff_list[$emp_filter]) ? [$emp_filter => $staff_list[$emp_filter]] : [];
    }

    $att_by_emp = [];
    if (!empty($staff_list)) {
        $ar = mysqli_query($conn, "SELECT * FROM attendance WHERE work_date = '$view_date'");
        if ($ar) while ($r = mysqli_fetch_assoc($ar)) $att_by_emp[(int)$r['employee_id']] = $r;
    }

    $leave_by_emp = [];
    if (!empty($staff_list)) {
        $lr = mysqli_query($conn,
            "SELECT employee_id FROM leave_requests WHERE status='approved' AND '$view_date' BETWEEN date_from AND date_to");
        if ($lr) while ($r = mysqli_fetch_assoc($lr)) $leave_by_emp[(int)$r['employee_id']] = true;
    }

    $is_today = ($view_date === date('Y-m-d'));

    $sheet = [];
    foreach ($staff_list as $emp_id => $full_name) {
        $a = $att_by_emp[$emp_id] ?? null;

        if ($a && $a['time_in']) {
            $status = in_array($a['status'], ['present', 'late'], true) ? $a['status'] : 'present';
        } elseif (isset($leave_by_emp[$emp_id])) {
            $status = 'on_leave';
        } elseif ($is_today) {
            $status = 'pending';
        } else {
            $status = 'absent';
        }

        $sheet[] = [
            'employee_id'  => $emp_id,
            'full_name'    => $full_name,
            'work_date'    => $view_date,
            'time_in'      => $a['time_in']      ?? null,
            'time_out'     => $a['time_out']     ?? null,
            'hours_worked' => $a['hours_worked'] ?? null,
            'status'       => $status,
        ];
    }

    return $sheet;
}

/** Present / Late / Absent / On Leave / Pending counts for the summary chips. */
function hr_attendance_sheet_counts(array $sheet): array {
    $counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'on_leave' => 0, 'pending' => 0];
    foreach ($sheet as $row) {
        if (isset($counts[$row['status']])) $counts[$row['status']]++;
    }
    return $counts;
}

/**
 * Same roster snapshot as hr_build_attendance_sheet(), but across a
 * whole date range instead of a single day — this is what lets HR
 * actually filter back through previous records (a week, a payroll
 * period, a whole month) instead of clicking one day at a time.
 * Internally just calls the single-day builder once per date in the
 * range and flattens the result, so every status rule (present/late/
 * absent/on_leave/pending) stays exactly in sync with the daily sheet —
 * there's only one place that logic lives. Capped at 92 days (~3
 * months) so a mistyped range can't hammer the DB with hundreds of
 * queries.
 */
function hr_build_attendance_range(mysqli $conn, string $date_from, string $date_to, int $emp_filter = 0): array {
    if ($date_to < $date_from) [$date_from, $date_to] = [$date_to, $date_from];

    $cursor = new DateTime($date_from);
    $end    = new DateTime($date_to);
    $max_days = 92;

    $sheet = [];
    $days = 0;
    while ($cursor <= $end && $days < $max_days) {
        $sheet = array_merge($sheet, hr_build_attendance_sheet($conn, $cursor->format('Y-m-d'), $emp_filter));
        $cursor->modify('+1 day');
        $days++;
    }

    // Most recent day first, then by name, so a multi-day range reads
    // top-down the same way the single-day sheet already does.
    usort($sheet, fn($a, $b) => [$b['work_date'], $a['full_name']] <=> [$a['work_date'], $b['full_name']]);

    return $sheet;
}