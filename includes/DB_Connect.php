<?php
// ── Database Configuration ──────────────────────────────────────
// Update these values once your database is ready.
define('DB_HOST', 'localhost');
define('DB_USER', 'root');        // your MySQL username
define('DB_PASS', '');            // your MySQL password
define('DB_NAME', 'cloudcup_db');

// PHP's date()/time() and MySQL's NOW()/CURDATE() must agree on what
// "today" is, or attendance/leave/holiday logic silently breaks: the
// clock-in handler stamps work_date with MySQL's CURDATE(), while the
// HR attendance sheet looks a row up by PHP's date('Y-m-d') and decides
// "Not Yet" whenever nothing matches that date — which is exactly what
// happens if the two disagree (e.g. PHP defaults to UTC while MySQL's
// server clock is already on the next calendar day in PH time). Pinning
// both to the same offset here, once, keeps every page's date math
// consistent regardless of what the underlying server clocks are set to.
date_default_timezone_set('Asia/Manila');

// On PHP 8.1+, mysqli throws exceptions on connection errors by default
// (the old @-suppression trick no longer silences it). Catch it instead
// so a missing/unreachable database degrades to $conn = false instead
// of a fatal error. All pages using $conn must guard with: if ($conn) { ... }
mysqli_report(MYSQLI_REPORT_OFF);

try {
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} catch (mysqli_sql_exception $e) {
    $conn = false;
}

if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');
    mysqli_query($conn, "SET time_zone = '+08:00'"); // matches date_default_timezone_set() above
}