<?php
/**
 * CloudCup — Marketing DB connection
 * -------------------------------------------------------------
 * Same pattern as finance/config.php: pull credentials from
 * includes/DB_Connect.php and open a PDO connection for the
 * Marketing module's prepared statements. $conn (mysqli) stays
 * available too in case a Marketing file ever needs it directly.
 *
 * Expected location: marketing/config.php (sibling to finance/, HR/, etc.)
 * -------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/DB_Connect.php'; // creates $conn (mysqli) + DB_HOST/DB_USER/DB_PASS/DB_NAME

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
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

/**
 * Require the current session to be a marketing (or admin) user.
 * Include this at the top of any page in marketing/ that should be
 * gated — the same way other modules trust $_SESSION['role'].
 */
function marketing_require_login(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['marketing', 'admin'], true)) {
        // A logged-in supplier landing here (bookmark, stale link) bounces to
        // their own portal, not back to the login form.
        $role = $_SESSION['role'] ?? '';
        header('Location: ' . ($role === 'supplier' ? '../supplier/Supplier_Dashboard.php' : '../auth/Login_Page.php'));
        exit;
    }
}

/**
 * Return all marketing campaigns, newest first.
 * Used by campaign_list.php and other manage/upload pages
 * that need the full list without a date-range filter.
 */
function marketing_all_campaigns(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM marketing_campaigns ORDER BY created_at DESC")->fetchAll();
}