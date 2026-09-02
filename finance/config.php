<?php
/**
 * CloudCup — Finance DB connection
 * -------------------------------------------------------------
 * The rest of CloudCup (HR, Sales, Login, etc.) connects with
 * mysqli via DB_Connect.php, which defines DB_HOST / DB_USER /
 * DB_PASS / DB_NAME and opens $conn.
 *
 * The Finance module's queries (finance_data.php, expense_add_*.php)
 * are all written against PDO ($pdo->prepare()). Rather than
 * duplicating credentials here, we pull them from DB_Connect.php
 * and open a second connection (PDO, same database) just for
 * Finance. $conn (mysqli) stays available too in case any Finance
 * file ever needs it directly.
 *
 * Confirmed project structure (inventory2/):
 *   inventory2/
 *   ├── admin/
 *   ├── auth/
 *   ├── finance/       ← this module
 *   ├── HR/
 *   ├── includes/
 *   │   └── DB_Connect.php
 *   └── ...
 * -------------------------------------------------------------
 */

require __DIR__ . '/../includes/DB_Connect.php'; // creates $conn (mysqli) + DB_HOST/DB_USER/DB_PASS/DB_NAME
require_once __DIR__ . '/../includes/Activity_Log.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // In production you'd log this instead of echoing it directly.
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}
