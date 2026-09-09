<?php
require_once __DIR__ . '/config.php';
$supplier_id = supplier_require_login();
$sid = (int)$supplier_id;
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'create') {
    $po_id      = (int)($_POST['po_id'] ?? 0);
    $inv_number = trim($_POST['invoice_number'] ?? '');
    $inv_date   = trim($_POST['invoice_date'] ?? '');
    $due_date   = trim($_POST['due_date'] ?? '');
    $amount     = (float)($_POST['total_amount'] ?? 0);

    $owns = $po_id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT po_number FROM procurement_purchase_orders WHERE po_id = $po_id AND supplier_id = $sid")) : null;

    if (!$owns) {
        $msg = 'error:Invalid purchase order.';
    } elseif ($inv_number === '' || $inv_date === '' || $amount <= 0) {
        $msg = 'error:Invoice number, date, and amount are required.';
    } else {
        $attachment = null;
        if (!empty($_FILES['attachment']['name'])) {
            $upload = supplier_handle_upload($_FILES['attachment']);
            if (!$upload['ok']) {
                $msg = 'error:' . $upload['error'];
            } else {
                $attachment = $upload['path'];
            }
        }
        if ($msg === '') {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO supplier_invoices (invoice_number, supplier_id, po_id, invoice_date, due_date, total_amount, attachment_path)
                 VALUES (?,?,?,?,?,?,?)");
            $dueDateOrNull = $due_date !== '' ? $due_date : null;
            mysqli_stmt_bind_param($stmt, 'siissds', $inv_number, $sid, $po_id, $inv_date, $dueDateOrNull, $amount, $attachment);
            if (mysqli_stmt_execute($stmt)) {
                supplier_log_activity($conn, $sid, 'invoice_submitted', 'invoices',
                    "Invoice $inv_number submitted for " . $owns['po_number'], (string)$po_id);
                $msg = 'success:Invoice submitted.';
            } else {
                $msg = mysqli_errno($conn) === 1062 ? 'error:That invoice number is already used.' : 'error:Could not save the invoice.';
            }
        }
    }
}

$invoices = [];
$res = mysqli_query($conn, "
    SELECT inv.*, po.po_number
      FROM supplier_invoices inv
      JOIN procurement_purchase_orders po ON po.po_id = inv.po_id
     WHERE inv.supplier_id = $sid
     ORDER BY inv.created_at DESC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $invoices[] = $r;

// POs eligible for a new invoice — delivered (or in progress) orders without one yet.
$eligible_pos = [];
$eres = mysqli_query($conn, "
    SELECT po.po_id, po.po_number, po.total_cost
      FROM procurement_purchase_orders po
     WHERE po.supplier_id = $sid
       AND po.po_id NOT IN (SELECT po_id FROM supplier_invoices WHERE supplier_id = $sid)
     ORDER BY po.created_at DESC");
if ($eres) while ($r = mysqli_fetch_assoc($eres)) $eligible_pos[] = $r;

$activePage = 'invoices';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Invoices — Supplier Portal</title>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="css/supplier.css"/>
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
<script src="../js/sidebar-toggle.js"></script>
<?php require_once __DIR__ . '/includes/supplier_sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle sidebar"><i data-lucide="menu"></i></button>
      <h1 class="page-title">Invoices</h1>
    </div>
    <button class="btn-primary" onclick="document.getElementById('modal-invoice').classList.add('open')">+ New Invoice</button>
  </div>

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>"><?= htmlspecialchars($mm) ?></div>
    <?php endif; ?>

    <div class="widget">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Invoice #</th><th>PO Number</th><th>Invoice Date</th><th>Due Date</th><th>Total</th><th>Payment Status</th><th></th></tr></thead>
          <tbody>
            <?php if (empty($invoices)): ?>
              <tr><td colspan="7" class="empty-state">No invoices submitted yet.</td></tr>
            <?php else: foreach ($invoices as $inv): ?>
              <tr>
                <td><?= htmlspecialchars($inv['invoice_number']) ?></td>
                <td><?= htmlspecialchars($inv['po_number'] ?? '—') ?></td>
                <td><?= date('M j, Y', strtotime($inv['invoice_date'])) ?></td>
                <td><?= $inv['due_date'] ? date('M j, Y', strtotime($inv['due_date'])) : '—' ?></td>
                <td>₱<?= number_format((float)$inv['total_amount'], 2) ?></td>
                <td><span class="status-pill pill-<?= $inv['payment_status'] ?>"><?= ucfirst($inv['payment_status']) ?></span></td>
                <td><?php if ($inv['attachment_path']): ?><a class="btn-ghost btn-sm" href="../<?= htmlspecialchars($inv['attachment_path']) ?>" target="_blank" rel="noopener">View File</a><?php endif; ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modal-invoice">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">New Invoice</span>
      <button class="modal-close" onclick="document.getElementById('modal-invoice').classList.remove('open')">✕</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="act" value="create">
      <div class="form-group">
        <label>Purchase Order *</label>
        <select name="po_id" id="inv-po" required onchange="document.getElementById('inv-amount').value = this.options[this.selectedIndex].dataset.total || ''">
          <option value="">— Select PO —</option>
          <?php foreach ($eligible_pos as $p): ?>
            <option value="<?= $p['po_id'] ?>" data-total="<?= (float)$p['total_cost'] ?>"><?= htmlspecialchars($p['po_number'] ?? ('#' . $p['po_id'])) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (empty($eligible_pos)): ?><div class="field-hint">Every PO already has an invoice on file.</div><?php endif; ?>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Invoice Number *</label>
          <input type="text" name="invoice_number" required maxlength="30">
        </div>
        <div class="form-group">
          <label>Total Amount *</label>
          <input type="number" step="0.01" min="0.01" name="total_amount" id="inv-amount" required>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Invoice Date *</label>
          <input type="date" name="invoice_date" required max="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
          <label>Payment Due Date</label>
          <input type="date" name="due_date">
        </div>
      </div>
      <div class="form-group">
        <label>Attachment (PDF/JPG/PNG, optional)</label>
        <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" onclick="document.getElementById('modal-invoice').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn-primary">Submit Invoice</button>
      </div>
    </form>
  </div>
</div>
<script>
document.querySelectorAll('.modal-overlay').forEach(function (m) {
  m.addEventListener('click', function (e) { if (e.target === m) m.classList.remove('open'); });
});
</script>
<script src="../js/lucide-init.js"></script>
</body>
</html>
