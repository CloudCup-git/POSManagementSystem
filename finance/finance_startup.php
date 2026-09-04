<?php
/**
 * CloudCup — Finance: Startup & Capital Costs
 */
require __DIR__ . '/includes/finance_data.php';

$activePage = 'startup';
$pageTitle  = 'Finance — Startup & Capital Costs';

$startupCategories = [
    'Equipment', 'Permits & Licenses', 'Initial Buildout', 'Furniture & Fixtures', 'Other',
];
$startupPaymentMethods = [
    'cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya', 'card' => 'Card', 'bank_transfer' => 'Bank Transfer',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Startup & Capital Costs — CloudCup Finance</title>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="css/finance.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
  #addStartupModalOverlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(20, 15, 10, 0.55);
    z-index: 1000;
    align-items: center;
    justify-content: center;
  }
  #addStartupModalOverlay.open { display: flex; }
  #addStartupModal {
    background: #fff;
    width: 100%;
    max-width: 480px;
    margin: 16px;
    border-radius: 12px;
    box-shadow: 0 12px 40px rgba(0,0,0,0.25);
    max-height: 90vh;
    overflow-y: auto;
  }
  #addStartupModal .modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 22px;
    border-bottom: 1px solid #eee;
  }
  #addStartupModal .modal-head h3 { margin: 0; font-size: 18px; }
  #addStartupModal .modal-close-btn {
    background: none; border: none; font-size: 20px; line-height: 1;
    cursor: pointer; color: #888;
  }
  #addStartupModal .modal-body { padding: 20px 22px; }
  #addStartupModal .modal-sub {
    font-size: 13px; color: #777; margin-bottom: 16px;
  }
  #addStartupModal label {
    display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #444;
  }
  #addStartupModal .row2 {
    display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
  }
  #addStartupModal select,
  #addStartupModal input[type="number"],
  #addStartupModal input[type="date"],
  #addStartupModal textarea {
    width: 100%;
    padding: 9px 10px;
    border: 1px solid #ccc;
    border-radius: 8px;
    font-size: 14px;
    margin-bottom: 14px;
    box-sizing: border-box;
    font-family: inherit;
  }
  #addStartupModal textarea { resize: vertical; }
  #addStartupModal .modal-error {
    background: #fdecea; color: #B00020; border: 1px solid #f5c6cb;
    padding: 10px 12px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; display: none;
  }
  #addStartupModal .modal-actions {
    display: flex; justify-content: flex-end; gap: 10px; margin-top: 4px;
  }
  #addStartupModal .btn-cancel {
    background: #f0f0f0; border: none; padding: 10px 16px; border-radius: 8px;
    cursor: pointer; font-size: 14px;
  }
  #addStartupModal .btn-save {
    background: var(--accent, #4f8a8f); color: #fff; border: none; padding: 10px 18px;
    border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600;
  }
  #addStartupModal .btn-save[disabled] { opacity: 0.6; cursor: not-allowed; }
</style>
</head>
<body>

  <?php include __DIR__ . '/includes/finance_sidebar.php'; ?>

  <div class="main">
    <?php include __DIR__ . '/includes/finance_topbar.php'; ?>

    <div class="content">

      <?php include __DIR__ . '/includes/finance_filterbar.php'; ?>

      <div class="kpi-grid" style="margin-bottom:16px;">
        <div class="kpi-card">
          <div class="kpi-top">
            <div><div class="kpi-label">TOTAL INVESTED</div></div>
            <div class="kpi-icon icon-orange">₱</div>
          </div>
          <div class="kpi-value"><?= money($totalStartupCosts) ?></div>
          <div class="kpi-sub">reference only — not deducted from Net Profit</div>
        </div>
      </div>

      <div class="section-header"><h2>Startup &amp; Capital Costs</h2><div class="line"></div>
        <a class="btn-export" href="finance_export.php?report=startup&<?= htmlspecialchars($rangeQuery) ?>">⭳ Export to Excel</a>
        <button type="button" class="btn-add" id="btnOpenAddStartup">+ Add Startup / Capital Expense</button>
      </div>
      <div class="panel" style="margin-bottom:16px;">
        <div class="panel-sub" style="margin-bottom:14px;">One-time investment (equipment, permits, initial buildout) — reference only, not deducted from recurring Net Profit.</div>
        <?php if ($startupRows): ?>
        <table>
          <thead><tr><th>Date</th><th>Category</th><th>Description</th><th style="text-align:right;">Amount</th></tr></thead>
          <tbody>
            <?php foreach ($startupRows as $s): ?>
            <tr>
              <td class="muted"><?= (new DateTime($s['expense_date']))->format('M d, Y') ?></td>
              <td><?= htmlspecialchars($s['category']) ?></td>
              <td class="muted"><?= htmlspecialchars($s['description'] ?: '—') ?></td>
              <td class="num"><?= money($s['amount']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr><td colspan="3"><strong>Total invested</strong></td><td class="num"><strong><?= money($totalStartupCosts) ?></strong></td></tr>
          </tbody>
        </table>
        <?php else: ?>
          <div class="empty-state">No startup or capital costs logged yet.</div>
        <?php endif; ?>
      </div>

    </div>
  </div>

  <!-- Add Startup / Capital Expense modal -->
  <div id="addStartupModalOverlay">
    <div id="addStartupModal">
      <div class="modal-head">
        <h3>Log a Startup / Capital Expense</h3>
        <button type="button" class="modal-close-btn" id="closeAddStartupModal">&times;</button>
      </div>
      <div class="modal-body">
        <div class="modal-sub">Equipment, permits, initial buildout, etc. — one-time investment, tracked separately and never deducted from recurring Net Profit.</div>
        <div class="modal-error" id="addStartupModalError"></div>

        <form id="addStartupForm">
          <input type="hidden" name="return_range" value="<?= htmlspecialchars($range) ?>">

          <div class="row2">
            <div>
              <label for="modal_category">Category</label>
              <select name="category" id="modal_category" required>
                <option value="">Select category…</option>
                <?php foreach ($startupCategories as $c): ?>
                  <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="modal_amount">Amount (₱)</label>
              <input type="number" step="0.01" min="0.01" name="amount" id="modal_amount" required>
            </div>
          </div>

          <label for="modal_description">Description (optional)</label>
          <textarea name="description" id="modal_description" rows="2" placeholder="e.g. Espresso machine"></textarea>

          <div class="row2">
            <div>
              <label for="modal_payment_method">Paid Via</label>
              <select name="payment_method" id="modal_payment_method">
                <?php foreach ($startupPaymentMethods as $val => $label): ?>
                  <option value="<?= $val ?>" <?= $val === 'cash' ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="modal_expense_date">Date</label>
              <input type="date" name="expense_date" id="modal_expense_date" required>
            </div>
          </div>

          <div class="modal-actions">
            <button type="button" class="btn-cancel" id="cancelAddStartupModal">Cancel</button>
            <button type="submit" class="btn-save" id="saveAddStartupBtn">Save Startup / Capital Expense</button>
          </div>
        </form>
      </div>
    </div>
  </div>

<script>
(function () {
  const overlay   = document.getElementById('addStartupModalOverlay');
  const openBtn   = document.getElementById('btnOpenAddStartup');
  const closeBtn  = document.getElementById('closeAddStartupModal');
  const cancelBtn = document.getElementById('cancelAddStartupModal');
  const form      = document.getElementById('addStartupForm');
  const saveBtn   = document.getElementById('saveAddStartupBtn');
  const errorBox  = document.getElementById('addStartupModalError');
  const dateInput = document.getElementById('modal_expense_date');

  function openModal() {
    errorBox.style.display = 'none';
    errorBox.textContent = '';
    if (!dateInput.value) {
      dateInput.value = new Date().toISOString().slice(0, 10);
    }
    overlay.classList.add('open');
  }

  function closeModal() {
    overlay.classList.remove('open');
    form.reset();
    errorBox.style.display = 'none';
  }

  openBtn.addEventListener('click', openModal);
  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) closeModal();
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    errorBox.style.display = 'none';
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving…';

    fetch('expense_add_startup.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: new FormData(form)
    })
      .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
      .then(function (result) {
        if (result.ok && result.data.success) {
          window.location.href = result.data.redirect || 'finance_startup.php?added=1';
        } else {
          errorBox.textContent = result.data.error || 'Could not save expense. Please check the form and try again.';
          errorBox.style.display = 'block';
          saveBtn.disabled = false;
          saveBtn.textContent = 'Save Startup / Capital Expense';
        }
      })
      .catch(function () {
        errorBox.textContent = 'Network error — please try again.';
        errorBox.style.display = 'block';
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save Startup / Capital Expense';
      });
  });
})();
</script>

<?php if (($_GET['added'] ?? '') === '1'): ?>
<script>
Swal.fire({
  icon: 'success',
  title: 'Expense logged',
  text: 'Startup / capital expense saved successfully.',
  timer: 3000,
  timerProgressBar: true,
  toast: true,
  position: 'top-end',
  showConfirmButton: false
});
</script>
<?php endif; ?>
</body>
</html>
