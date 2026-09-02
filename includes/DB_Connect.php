<?php
// ── Database Configuration ──────────────────────────────────────
// Update these values once your database is ready.
define('DB_HOST', 'localhost');
define('DB_USER', 'root');        // your MySQL username
define('DB_PASS', '');            // your MySQL password
define('DB_NAME', 'cloudcup_db');

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
}
