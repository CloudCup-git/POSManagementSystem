<?php
/**
 * CloudCup — Add Startup / Capital Expense
 * Dedicated form for one-time investment (equipment, permits, initial
 * buildout, etc.). Tracked as a running total for Capital Investment —
 * never subtracted from recurring Net Profit.
 *
 * This is intentionally a separate page/function from
 * expense_add_operating.php — no shared type-toggle, no way to
 * accidentally submit the wrong expense_type.
 *
 * Supports two modes:
 *  - Normal form POST (full page, redirect on success) — kept as a
 *    fallback for direct navigation / no-JS.
 *  - AJAX POST (X-Requested-With: XMLHttpRequest) used by the
 *    "Add Startup / Capital Expense" modal on finance_startup.php —
 *    returns JSON instead of redirecting or rendering HTML.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/config.php';

$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role'] ?? ''), ['finance', 'admin', 'manager'], true)) {
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Your session has expired. Please log in again.']);
        exit;
    }
    header('Location: ../auth/Login_Page.php');
    exit;
}

const EXPENSE_TYPE = 'startup';

$categories = [
    'Equipment', 'Permits & Licenses', 'Initial Buildout', 'Furniture & Fixtures', 'Other',
];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category      = trim($_POST['category'] ?? '');
    $description   = trim($_POST['description'] ?? '');
    $amount        = $_POST['amount'] ?? '';
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    $expenseDate   = $_POST['expense_date'] ?? '';

    $allowedMethods = ['cash', 'card', 'gcash', 'maya', 'bank_transfer'];

    if ($category === '' || !is_numeric($amount) || (float) $amount <= 0 || $expenseDate === '') {
        $error = 'Please fill in category, a valid amount, and a date.';
    } elseif (!in_array($paymentMethod, $allowedMethods, true)) {
        $error = 'Invalid payment method.';
    }

    if ($error !== '') {
        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
        // fall through to render the full page with $error below
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO operating_expenses
              (expense_type, category, description, amount, payment_method, expense_date, recorded_by)
            VALUES (:type, :category, :description, :amount, :method, :date, :user)
        ");
        $stmt->execute([
            ':type'        => EXPENSE_TYPE,
            ':category'    => $category,
            ':description' => $description !== '' ? $description : null,
            ':amount'      => (float) $amount,
            ':method'      => $paymentMethod,
            ':date'        => $expenseDate,
            ':user'        => $_SESSION['user_id'],
        ]);
        log_activity($conn, 'money', 'added', 'expense', $pdo->lastInsertId(), 'Recorded ' . EXPENSE_TYPE . ' expense: ' . $category . ' (₱' . $amount . ').');

        $redirect = 'finance_startup.php?' . http_build_query(['range' => $_POST['return_range'] ?? 'this_month', 'added' => '1']) . '#expenses-added';

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'redirect' => $redirect]);
            exit;
        }

        header('Location: ' . $redirect);
        exit;
    }
}

$currentUser = $_SESSION['full_name'] ?? 'Finance User';
$returnRange = $_GET['range'] ?? 'this_month';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Startup / Capital Expense — CloudCup Finance</title>
<link rel="stylesheet" href="css/expense.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
  <div class="topbar">
    <span>CloudCup Finance</span>
    <a href="finance_startup.php?range=<?= htmlspecialchars($returnRange) ?>">&larr; Back to Startup &amp; Capital Costs</a>
  </div>

  <div class="wrap">
    <div class="card">
      <h1>Log a Startup / Capital Expense</h1>
      <div class="sub">Equipment, permits, initial buildout, etc. — one-time investment, tracked separately and never deducted from recurring Net Profit.</div>

      <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <form method="post">
        <input type="hidden" name="return_range" value="<?= htmlspecialchars($returnRange) ?>">

        <div class="row2">
          <div>
            <label for="category">Category</label>
            <select name="category" id="category" required>
              <option value="">Select category…</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= (($_POST['category'] ?? '') === $c) ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="amount">Amount (₱)</label>
            <input type="number" step="0.01" min="0.01" name="amount" id="amount" value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" required>
          </div>
        </div>

        <label for="description">Description (optional)</label>
        <textarea name="description" id="description" rows="2" placeholder="e.g. Espresso machine"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>

        <div class="row2">
          <div>
            <label for="payment_method">Paid Via</label>
            <select name="payment_method" id="payment_method">
              <?php foreach (['cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya', 'card' => 'Card', 'bank_transfer' => 'Bank Transfer'] as $val => $label): ?>
                <option value="<?= $val ?>" <?= (($_POST['payment_method'] ?? 'cash') === $val) ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="expense_date">Date</label>
            <input type="date" name="expense_date" id="expense_date" value="<?= htmlspecialchars($_POST['expense_date'] ?? date('Y-m-d')) ?>" required>
          </div>
        </div>

        <button type="submit">Save Startup / Capital Expense</button>
      </form>
    </div>
  </div>

<?php if ($error): ?>
<script>
Swal.fire({
  icon: 'error',
  title: 'Cannot save expense',
  text: <?= json_encode($error) ?>,
  confirmButtonColor: '#B00020'
});
</script>
<?php endif; ?>
</body>
</html>
