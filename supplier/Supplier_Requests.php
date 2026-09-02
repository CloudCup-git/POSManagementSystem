<?php
require_once __DIR__ . '/config.php';
$supplier_id = supplier_require_login();
$sid = (int)$supplier_id;
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'respond') {
    $po_id    = (int)($_POST['po_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $remarks  = trim($_POST['remarks'] ?? '');
    $revised  = trim($_POST['revised_date'] ?? '');

    $valid = ['confirm' => 'confirmed', 'accept' => 'accepted', 'reject' => 'rejected', 'revise' => 'revised'];

    // Ownership check happens in the WHERE clause itself (supplier_id = $sid) —
    // a supplier can never respond to another supplier's PO even with a
    // forged po_id, since the UPDATE simply matches zero rows.
    if (!$po_id || !isset($valid[$decision])) {
        $msg = 'error:Invalid request.';
    } elseif (in_array($decision, ['reject', 'revise'], true) && $remarks === '') {
        $msg = 'error:Please provide a reason/remarks.';
    } elseif ($decision === 'revise' && (!$revised || !DateTime::createFromFormat('Y-m-d', $revised))) {
        $msg = 'error:Please provide a valid revised delivery date.';
    } else {
        $status = $valid[$decision];
        $revisedDate = $decision === 'revise' ? $revised : null;
        $stmt = mysqli_prepare($conn,
            "UPDATE procurement_purchase_orders
                SET supplier_response_status = ?, supplier_response_date = NOW(),
                    supplier_revised_delivery_date = ?, supplier_response_remarks = ?
              WHERE po_id = ? AND supplier_id = ?");
        mysqli_stmt_bind_param($stmt, 'sssii', $status, $revisedDate, $remarks, $po_id, $sid);
        mysqli_stmt_execute($stmt);

        if (mysqli_stmt_affected_rows($stmt) > 0) {
            $poRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT po_number FROM procurement_purchase_orders WHERE po_id = $po_id"));
            supplier_log_activity($conn, $sid, 'po_' . $decision, 'requests',
                'Purchase order ' . ($poRow['po_number'] ?? "#$po_id") . ' marked ' . $status . ($remarks ? " — $remarks" : ''),
                (string)$po_id);
            $msg = 'success:Response recorded.';
        } else {
            $msg = 'error:Could not find that purchase order.';
        }
    }
}

$pos = [];
$res = mysqli_query($conn, "
    SELECT po.*, b.branch_name
      FROM procurement_purchase_orders po
      JOIN branches b ON b.branch_id = po.branch_id
     WHERE po.supplier_id = $sid
     ORDER BY (po.supplier_response_status = 'pending') DESC, po.created_at DESC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $pos[] = $r;

$items_by_po = [];
if ($pos) {
    $ids = implode(',', array_map('intval', array_column($pos, 'po_id')));
    $ires = mysqli_query($conn, "SELECT * FROM procurement_po_items WHERE po_id IN ($ids)");
    if ($ires) while ($i = mysqli_fetch_assoc($ires)) $items_by_po[$i['po_id']][] = $i;
}

$activePage = 'requests';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Requests & POs — Supplier Portal</title>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="css/supplier.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
<script src="../js/sidebar-toggle.js"></script>
<?php require_once __DIR__ . '/includes/supplier_sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle sidebar"><i data-lucide="menu"></i></button>
      <h1 class="page-title">Requests & Purchase Orders</h1>
    </div>
  </div>

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>"><?= htmlspecialchars($mm) ?></div>
    <?php endif; ?>

    <div class="widget">
      <div class="widget-header">
        <div class="widget-title">Purchase Orders assigned to you</div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>PO Number</th><th>Branch</th><th>Items</th><th>Total</th><th>Expected Delivery</th><th>Your Response</th><th></th></tr>
          </thead>
          <tbody>
            <?php if (empty($pos)): ?>
              <tr><td colspan="7" class="empty-state">No purchase orders have been assigned to you yet.</td></tr>
            <?php else: foreach ($pos as $po): $items = $items_by_po[$po['po_id']] ?? []; ?>
              <tr>
                <td><?= htmlspecialchars($po['po_number'] ?? ('#' . $po['po_id'])) ?></td>
                <td><?= htmlspecialchars($po['branch_name']) ?></td>
                <td><?= implode(', ', array_map(fn($i) => htmlspecialchars($i['item_name']) . ' (' . ($i['qty_ordered'] + 0) . ' ' . htmlspecialchars($i['unit']) . ')', $items)) ?: '—' ?></td>
                <td>₱<?= number_format((float)$po['total_cost'], 2) ?></td>
                <td><?= $po['expected_delivery_date'] ? date('M j, Y', strtotime($po['expected_delivery_date'])) : '—' ?></td>
                <td>
                  <span class="status-pill pill-<?= $po['supplier_response_status'] ?>"><?= ucfirst($po['supplier_response_status']) ?></span>
                  <?php if ($po['supplier_response_status'] === 'revised' && $po['supplier_revised_delivery_date']): ?>
                    <div class="field-hint">Proposed: <?= date('M j, Y', strtotime($po['supplier_revised_delivery_date'])) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <button class="btn-ghost btn-sm" onclick='openRespondModal(<?= htmlspecialchars(json_encode(["po_id" => $po['po_id'], "po_number" => $po['po_number'] ?? ('#' . $po['po_id'])])) ?>)'>Respond</button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modal-respond">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Respond — <span id="respond-po-number"></span></span>
      <button class="modal-close" onclick="closeModal('modal-respond')">✕</button>
    </div>
    <form method="POST" id="form-respond">
      <input type="hidden" name="act" value="respond">
      <input type="hidden" name="po_id" id="respond-po-id">
      <div class="form-group">
        <label>Decision *</label>
        <select name="decision" id="respond-decision" required onchange="toggleRespondFields()">
          <option value="">— Select —</option>
          <option value="confirm">Confirm order</option>
          <option value="accept">Accept order</option>
          <option value="revise">Propose revised delivery date</option>
          <option value="reject">Reject order</option>
        </select>
      </div>
      <div class="form-group" id="revised-date-group" style="display:none">
        <label>Revised Delivery Date *</label>
        <input type="date" name="revised_date" id="respond-revised-date">
      </div>
      <div class="form-group" id="remarks-group" style="display:none">
        <label>Remarks *</label>
        <textarea name="remarks" id="respond-remarks" rows="3" placeholder="Reason for the delay/rejection…"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" onclick="closeModal('modal-respond')">Cancel</button>
        <button type="submit" class="btn-primary">Submit Response</button>
      </div>
    </form>
  </div>
</div>

<script>
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function openRespondModal(po) {
  document.getElementById('respond-po-id').value = po.po_id;
  document.getElementById('respond-po-number').textContent = po.po_number;
  document.getElementById('respond-decision').value = '';
  document.getElementById('respond-remarks').value = '';
  document.getElementById('respond-revised-date').value = '';
  toggleRespondFields();
  document.getElementById('modal-respond').classList.add('open');
}
function toggleRespondFields() {
  var d = document.getElementById('respond-decision').value;
  document.getElementById('revised-date-group').style.display = d === 'revise' ? '' : 'none';
  document.getElementById('remarks-group').style.display = (d === 'revise' || d === 'reject') ? '' : 'none';
}
document.querySelectorAll('.modal-overlay').forEach(function (m) {
  m.addEventListener('click', function (e) { if (e.target === m) m.classList.remove('open'); });
});
document.getElementById('form-respond').addEventListener('submit', function (e) {
  var d = document.getElementById('respond-decision').value;
  if ((d === 'revise' || d === 'reject') && !document.getElementById('respond-remarks').value.trim()) {
    e.preventDefault();
    Swal.fire({ icon: 'error', title: 'Remarks required', text: 'Please explain the reason.', confirmButtonColor: '#b8703f' });
  }
});
</script>
<script src="../js/lucide-init.js"></script>
</body>
</html>
