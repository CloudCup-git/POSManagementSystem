<?php
/**
 * CloudCup — Add Operating Expense
 * Dedicated form for recurring costs (rent, utilities, marketing,
 * maintenance, supplies, insurance, transportation, etc.) that count
 * toward Net Profit for the period they're logged in.
 *
 * This is intentionally a separate page/function from
 * expense_add_startup.php — no shared type-toggle, no way to
 * accidentally submit the wrong expense_type.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/config.php';

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role'] ?? ''), ['finance', 'admin', 'manager'], true)) {
    header('Location: ../auth/Login_Page.php');
    exit;
}

const EXPENSE_TYPE = 'operating';

/* Per Rhea's Operating Expenses merge spec, only these 3 categories are
   manually logged here — Cost of Goods Sold and Labor & Payroll are
   computed automatically elsewhere (inventory usage / payroll) and
   never entered through this form. Marketing, Supplies, Insurance,
   Transportation, and Other were removed from the picklist since they
   fall outside the approved 5-category breakdown. */
$categories = [
    'Rent or Mortgage', 'Utilities', 'Maintenance and Repairs',
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

        header('Location: finance_opex.php?' . http_build_query(['range' => $_POST['return_range'] ?? 'this_month', 'added' => '1']) . '#expenses-added');
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
<title>Add Operating Expense — CloudCup Finance</title>
<link rel="stylesheet" href="css/expense.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
  <div class="topbar">
    <span>CloudCup Finance</span>
    <a href="finance_opex.php?range=<?= htmlspecialchars($returnRange) ?>">&larr; Back to Operating Expenses</a>
  </div>

  <div class="wrap">
    <div class="card">
      <h1>Log an Operating Expense</h1>
      <div class="sub">Rent, utilities, marketing, maintenance, supplies, insurance, transportation, etc. — recurring costs that count toward Net Profit for this period.</div>

      <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <form method="post" id="opexForm">
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
        <textarea name="description" id="description" rows="2" placeholder="e.g. Month electricity bill"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>

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

        <button type="submit" id="opexSubmitBtn">Save Operating Expense</button>
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

<script>
(function () {
  const form = document.getElementById('opexForm');
  if (!form) return;

  const categoryEl = document.getElementById('category');
  const amountEl   = document.getElementById('amount');
  const dateEl     = document.getElementById('expense_date');
  const methodEl   = document.getElementById('payment_method');

  const paymentLabels = {
    cash: 'Cash', gcash: 'GCash', maya: 'Maya',
    card: 'Card', bank_transfer: 'Bank Transfer'
  };

  function formatMoney(n) {
    const num = parseFloat(n) || 0;
    return '₱' + num.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function formatDate(dstr) {
    if (!dstr) return '—';
    const d = new Date(dstr + 'T00:00:00');
    if (isNaN(d)) return dstr;
    return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
  }

  form.addEventListener('submit', function (e) {
    // Let the native "required" validation run first; if the form
    // isn't valid, don't intercept with our own confirmation.
    if (!form.checkValidity()) return;

    e.preventDefault();

    const category = categoryEl.value || '—';
    const amount   = formatMoney(amountEl.value);
    const date     = formatDate(dateEl.value);
    const method   = paymentLabels[methodEl.value] || methodEl.value;

    Swal.fire({
      icon: 'question',
      title: 'Confirm operating expense',
      html:
        '<div style="text-align:left; font-size:14px; line-height:1.9;">' +
        '<strong>Category:</strong> ' + category + '<br>' +
        '<strong>Amount:</strong> ' + amount + '<br>' +
        '<strong>Date:</strong> ' + date + '<br>' +
        '<strong>Paid Via:</strong> ' + method +
        '</div>',
      showCancelButton: true,
      confirmButtonText: 'Save Expense',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#3a2d1e',
      cancelButtonColor: '#B00020',
      reverseButtons: true
    }).then(function (result) {
      if (result.isConfirmed) {
        // Native submit() does not re-trigger this 'submit' listener,
        // so this won't loop back into the confirmation dialog.
        form.submit();
      }
    });
  });
})();
</script>
</body>
</html>