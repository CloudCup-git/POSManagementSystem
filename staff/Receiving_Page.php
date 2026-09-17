<?php
/**
 * Procurement — Receiving Queue + Receive/Verify form (C16 + C17)
 * -------------------------------------------------------------
 * Inventory Staff only, branch-scoped (their own branch_id, resolved
 * fresh from the DB — never from a URL/POST param). Lists issued POs
 * for their branch that still need receiving, with a form to record a
 * receiving event per PO. Submission goes to Receive_Verify_Action.php
 * via AJAX. No stock change here — that's C18.
 * -------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/procurement_queries.php';

$user = procurement_require_stage([PROC_STAGE_INVENTORY_STAFF]);

if (!$conn) {
    http_response_code(503);
    die('Database connection unavailable. Please check that MySQL/MariaDB is running, then refresh this page.');
}

ensure_procurement_tables($conn);

$full_name = $user['full_name'];
$branch_id = $user['branch_id'];

$pos = [];
if ($branch_id) {
    $stmt = mysqli_prepare($conn,
        "SELECT po.po_id, po.po_number, po.request_id, po.supplier_name,
                po.expected_delivery_date, po.sent_at, po.delivery_note_reference,
                r.status AS request_status
         FROM procurement_purchase_orders po
         JOIN procurement_requests r ON r.request_id = po.request_id
         WHERE po.branch_id = ? AND r.status IN (?, ?)
         ORDER BY po.created_at ASC"
    );
    $__st1 = PROC_STATUS_PO_ISSUED; $__st2 = PROC_STATUS_PARTIALLY_RECEIVED;
    mysqli_stmt_bind_param($stmt, 'iss', $branch_id, $__st1, $__st2);
    mysqli_stmt_execute($stmt);
    $pos = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    foreach ($pos as &$po) {
        $istmt = mysqli_prepare($conn,
            "SELECT poi.po_item_id, poi.item_name, poi.unit, poi.qty_ordered,
                    COALESCE((SELECT SUM(ri.qty_received) FROM procurement_receipt_items ri
                              JOIN procurement_receipts r ON r.receipt_id = ri.receipt_id
                              WHERE r.po_id = poi.po_id AND ri.po_item_id = poi.po_item_id), 0) AS already_received
             FROM procurement_po_items poi WHERE poi.po_id = ? ORDER BY poi.po_item_id ASC"
        );
        mysqli_stmt_bind_param($istmt, 'i', $po['po_id']);
        mysqli_stmt_execute($istmt);
        $po['items'] = mysqli_stmt_get_result($istmt)->fetch_all(MYSQLI_ASSOC);
        mysqli_stmt_close($istmt);
    }
    unset($po);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Receiving — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../css/admin_page.css" />
  <link rel="stylesheet" href="../css/inventory_management.css" />
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <style>
    .req-card{border:1px solid var(--border,#e5e7eb);border-radius:10px;padding:16px;margin-bottom:14px;}
    .req-card-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;flex-wrap:wrap;gap:6px;}
    .req-items{margin:10px 0;font-size:13px;color:var(--text-light);}
    .req-items li{margin-bottom:2px;}
    .sent-badge{background:#e6f7ec;color:#1a7f45;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:600;}
    .pending-badge{background:#fff4e5;color:#a15c00;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:600;}
    .receive-table{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px;}
    .receive-table th,.receive-table td{padding:6px 8px;border-bottom:1px solid var(--border,#e5e7eb);text-align:left;}
    .receive-result.ok{color:#1a7f45;}
    .receive-result.err{color:#c0392b;}
  </style>
</head>

<body>
  <script src="../js/sidebar-restore.js"></script>
  <?php $active_page = 'inv_receiving'; require_once __DIR__ . '/../includes/Sidebar_Inventory_Staff.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-left">
        <div class="topbar-title">Receiving</div>
      </div>
    </div>

    <div class="content">
      <?php if (empty($pos)): ?>
        <div class="table-wrap" style="padding:40px;text-align:center;color:var(--text-light);">
          No Purchase Orders waiting to be received for your branch.
        </div>
      <?php else: foreach ($pos as $po): ?>
        <div class="req-card">
          <div class="req-card-head">
            <div>
              <strong><?= htmlspecialchars($po['po_number']) ?></strong>
              (REQ-<?= str_pad((string) $po['request_id'], 4, '0', STR_PAD_LEFT) ?>)
              — supplier: <?= htmlspecialchars($po['supplier_name']) ?>
            </div>
            <span class="<?= $po['sent_at'] ? 'sent-badge' : 'pending-badge' ?>">
              <?= $po['sent_at'] ? 'SENT BY SUPPLIER' : 'NOT YET SENT' ?>
            </span>
          </div>

          <div style="font-size:13px;color:var(--text-light);">
            <?php if ($po['expected_delivery_date']): ?>Expected: <?= date('M d, Y', strtotime($po['expected_delivery_date'])) ?><?php else: ?>No expected delivery date set<?php endif; ?>
            <?php if ($po['delivery_note_reference']): ?> · Delivery note: <?= htmlspecialchars($po['delivery_note_reference']) ?><?php endif; ?>
            · Status: <?= htmlspecialchars(str_replace('_', ' ', $po['request_status'])) ?>
          </div>

          <form class="receive-form" data-po-id="<?= (int) $po['po_id'] ?>">
            <table class="receive-table">
              <thead><tr><th>Item</th><th>Ordered</th><th>Already received</th><th>Good qty</th><th>Damaged qty</th><th>Missing qty</th><th>Note</th></tr></thead>
              <tbody>
                <?php foreach ($po['items'] as $it): $remaining = max(0, (float) $it['qty_ordered'] - (float) $it['already_received']); ?>
                <tr>
                  <td><?= htmlspecialchars($it['item_name']) ?> (<?= htmlspecialchars($it['unit']) ?>)</td>
                  <td><?= (float) $it['qty_ordered'] + 0 ?></td>
                  <td><?= (float) $it['already_received'] + 0 ?></td>
                  <td><input type="number" step="0.01" min="0" name="qty_good" data-po-item-id="<?= (int) $it['po_item_id'] ?>" value="<?= $remaining ?>" style="width:75px"></td>
                  <td><input type="number" step="0.01" min="0" name="qty_damaged" data-po-item-id="<?= (int) $it['po_item_id'] ?>" value="0" style="width:75px"></td>
                  <td><input type="number" step="0.01" min="0" name="qty_missing" data-po-item-id="<?= (int) $it['po_item_id'] ?>" value="0" style="width:75px"></td>
                  <td><input type="text" name="note" data-po-item-id="<?= (int) $it['po_item_id'] ?>" placeholder="optional" style="width:120px"></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <p style="font-size:12px;color:var(--text-light);margin-top:4px;">Good qty defaults to the remaining ordered amount — adjust it and fill in Damaged/Missing if the delivery isn't fully good. The three quantities for a line can't add up to more than what's still remaining.</p>
            <div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap;">
              <input type="text" name="delivery_reference" placeholder="Delivery reference / DR number" style="flex:1;min-width:180px;">
              <input type="text" name="notes" placeholder="Notes (optional)" style="flex:1;min-width:180px;">
              <button type="submit" class="btn-primary">Record receiving</button>
            </div>
            <div class="receive-result" style="margin-top:8px;font-size:13px;"></div>
          </form>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <script>lucide.createIcons();</script>
  <script>
    document.querySelectorAll('.receive-form').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        const poId = form.dataset.poId;
        const lines = {};
        form.querySelectorAll('[data-po-item-id]').forEach(function (el) {
          const id = el.dataset.poItemId;
          lines[id] = lines[id] || {};
          if (el.name === 'qty_good') lines[id].qty_good = el.value || '0';
          if (el.name === 'qty_damaged') lines[id].qty_damaged = el.value || '0';
          if (el.name === 'qty_missing') lines[id].qty_missing = el.value || '0';
          if (el.name === 'note') lines[id].note = el.value;
        });

        const body = new URLSearchParams();
        body.set('po_id', poId);
        body.set('delivery_reference', form.delivery_reference.value);
        body.set('notes', form.notes.value);
        Object.keys(lines).forEach(function (id) {
          body.set('lines[' + id + '][qty_good]', lines[id].qty_good || '0');
          body.set('lines[' + id + '][qty_damaged]', lines[id].qty_damaged || '0');
          body.set('lines[' + id + '][qty_missing]', lines[id].qty_missing || '0');
          body.set('lines[' + id + '][note]', lines[id].note || '');
        });

        const resultEl = form.querySelector('.receive-result');
        resultEl.textContent = 'Saving...';
        resultEl.className = 'receive-result';

        fetch('Receive_Verify_Action.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
          body: body.toString()
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.success) {
              resultEl.textContent = 'Saved. New status: ' + data.new_status.replace(/_/g, ' ');
              resultEl.className = 'receive-result ok';
              setTimeout(function () { window.location.reload(); }, 1200);
            } else {
              resultEl.textContent = data.message || 'Something went wrong.';
              resultEl.className = 'receive-result err';
            }
          })
          .catch(function () {
            resultEl.textContent = 'Network error — please try again.';
            resultEl.className = 'receive-result err';
          });
      });
    });
  </script>
</body>
</html>
