<?php
/**
 * CloudCup — HR: Attendance Sheet Excel export
 * -------------------------------------------------------------
 * Exports the same attendance sheet shown on Attendance_Page.php (via
 * the shared Attendance_Sheet_Data.php builder) so the download always
 * matches whatever date-range/employee filter HR had on screen.
 *
 *   attendance_export.php?date_from=2026-08-01&date_to=2026-08-16
 *   attendance_export.php?date_from=2026-08-16&date_to=2026-08-16&employee=12
 *   attendance_export.php?date=2026-08-16                 (old single-date link, still works)
 */
require_once __DIR__ . '/../admin/Permissions.php';
require_hr_login();
require_permission('view_all_attendance');
require_once __DIR__ . '/../includes/DB_Connect.php';
require_once __DIR__ . '/../includes/Attendance_Sheet_Data.php';
require_once __DIR__ . '/../finance/includes/xlsx_writer.php';

$date_from = $_GET['date_from'] ?? $_GET['date'] ?? date('Y-m-d');
$date_to   = $_GET['date_to']   ?? $_GET['date'] ?? date('Y-m-d');
foreach ([&$date_from, &$date_to] as &$d) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d > date('Y-m-d')) $d = date('Y-m-d');
}
unset($d);
if ($date_to < $date_from) [$date_from, $date_to] = [$date_to, $date_from];
if ((strtotime($date_to) - strtotime($date_from)) / 86400 + 1 > 92) {
    $date_from = date('Y-m-d', strtotime($date_to . ' -91 day'));
}
$emp_filter = (int)($_GET['employee'] ?? 0);

$sheet = ($date_from === $date_to)
    ? hr_build_attendance_sheet($conn, $date_from, $emp_filter)
    : hr_build_attendance_range($conn, $date_from, $date_to, $emp_filter);

$label_map = ['present' => 'Present', 'late' => 'Late', 'absent' => 'Absent', 'on_leave' => 'On Leave', 'pending' => 'Not Yet Clocked In'];

$rows = [];
foreach ($sheet as $r) {
    $rows[] = [
        $r['full_name'],
        date('M d, Y', strtotime($r['work_date'])),
        $r['time_in']  ? date('g:i A', strtotime($r['time_in']))  : '—',
        $r['time_out'] ? date('g:i A', strtotime($r['time_out'])) : '—',
        $r['hours_worked'] > 0 ? round((float) $r['hours_worked'], 2) : '—',
        $label_map[$r['status']] ?? ucfirst($r['status']),
    ];
}

$prettyRange = ($date_from === $date_to)
    ? date('M d, Y', strtotime($date_to))
    : date('M d, Y', strtotime($date_from)) . ' – ' . date('M d, Y', strtotime($date_to));
export_finance_xlsx(
    "Attendance_{$date_from}_to_{$date_to}.xlsx",
    ['Employee', 'Date', 'Time In', 'Time Out', 'Hours', 'Status'],
    $rows,
    "Attendance Sheet — {$prettyRange}"
);