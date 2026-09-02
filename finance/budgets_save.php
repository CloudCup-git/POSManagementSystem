<?php
/**
 * CloudCup — Finance: Save budget grid (AJAX)
 * Expects POST JSON: { year: 2026, budgets: { "Rent": {"1": 5000, "2": 5000, ...}, ... } }
 * Upserts one row per category+month via ON DUPLICATE KEY UPDATE,
 * relying on the uniq_category_year_month key from the migration.
 */
require __DIR__ . '/includes/finance_data.php'; // session/login check + $pdo
require __DIR__ . '/includes/finance_budgeting_data.php'; // BUDGET_CATEGORIES

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}

// Only finance/admin can write budgets; manager stays read-only here
// (mirrors the "manager = view_all_payroll read-only" pattern used
// in HR's Permissions.php).
$role = strtolower($_SESSION['role'] ?? '');
if (!in_array($role, ['finance', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to edit budgets.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$year    = (int) ($input['year'] ?? 0);
$budgets = $input['budgets'] ?? [];

if ($year < 2000 || $year > 2100 || !is_array($budgets)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

$stmt = $pdo->prepare("
    INSERT INTO budgets (category, budget_year, budget_month, amount, created_by, updated_by)
    VALUES (:category, :year, :month, :amount, :uid, :uid)
    ON DUPLICATE KEY UPDATE amount = VALUES(amount), updated_by = VALUES(updated_by)
");

$pdo->beginTransaction();
try {
    foreach ($budgets as $category => $months) {
        if (!in_array($category, BUDGET_CATEGORIES, true)) continue; // reject unknown categories
        if (!is_array($months)) continue;
        foreach ($months as $month => $amount) {
            $month = (int) $month;
            if ($month < 1 || $month > 12) continue;
            $amount = round((float) $amount, 2);
            if ($amount < 0) continue; // budgets shouldn't be negative
            $stmt->execute([
                ':category' => $category,
                ':year'     => $year,
                ':month'    => $month,
                ':amount'   => $amount,
                ':uid'      => $userId ?: null,
            ]);
        }
    }
    $pdo->commit();
    log_activity($conn, 'money', 'updated', 'budget', $year, 'Saved budget plan for ' . $year . '.');
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Save failed: ' . $e->getMessage()]);
}
