<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/procurement_rfq_queries.php';
$supplier_id = supplier_require_login();
$sid = (int) $supplier_id;
ensure_procurement_rfq_tables($conn);
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'submit_quotation') {
    $rfq_id  = (int) ($_POST['rfq_id'] ?? 0);
    $prices  = $_POST['unit_price'] ?? []; // [rfq_item_id => price string]
    $validity = trim($_POST['validity_date'] ?? '');
    $lead_time = trim($_POST['lead_time_days'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Only allow quoting on an RFQ this supplier was actually invited to,
    // and only while it's still open.
    $invite = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT rs.id, f.status AS rfq_status, f.rfq_number
          FROM procurement_rfq_suppliers rs
          JOIN procurement_rfqs f ON f.rfq_id = rs.rfq_id
         WHERE rs.rfq_id = $rfq_id AND rs.supplier_id = $sid"));

    if (!$invite) {
        $msg = 'error:You were not invited to this RFQ.';
    } elseif ($invite['rfq_status'] !== 'open') {
        $msg = 'error:This RFQ is no longer open for quotations.';
    } else {
        $rfqItems = [];
        $ires = mysqli_query($conn, "SELECT rfq_item_id FROM procurement_rfq_items WHERE rfq_id = $rfq_id");
        if ($ires) while ($i = mysqli_fetch_assoc($ires)) $rfqItems[] = (int) $i['rfq_item_id'];

        $lineItems = [];
        $total = 0.0;
        $bad = false;
        foreach ($rfqItems as $itemId) {
            $raw = trim((string) ($prices[$itemId] ?? ''));
            if ($raw === '' || !is_numeric($raw) || (float) $raw < 0) { $bad = true; break; }
            $price = round((float) $raw, 2);
            $lineItems[$itemId] = $price;
            $total += $price; // per-unit price × qty handled below once qty is known
        }

        if ($bad) {
            $msg = 'error:Enter a valid unit price for every item.';
        } elseif ($validity !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validity)) {
            $msg = 'error:Invalid validity date.';
        } else {
            // Recompute totals against qty_needed (line_total = price × qty),
            // not just summed unit prices.
            $qtyMap = [];
            $qres = mysqli_query($conn, "SELECT rfq_item_id, qty_needed FROM procurement_rfq_items WHERE rfq_id = $rfq_id");
            if ($qres) while ($q = mysqli_fetch_assoc($qres)) $qtyMap[(int) $q['rfq_item_id']] = (float) $q['qty_needed'];

            $total_amount = 0.0;
            foreach ($lineItems as $itemId => $price) {
                $total_amount += round($price * ($qtyMap[$itemId] ?? 0), 2);
            }

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
                mysqli_begin_transaction($conn);
                $failed = null;
                $leadTimeOrNull = $lead_time !== '' ? (int) $lead_time : null;
                $validityOrNull = $validity !== '' ? $validity : null;
                $notesOrNull = $notes !== '' ? $notes : null;

                $existing = mysqli_fetch_assoc(mysqli_query($conn, "SELECT quotation_id, attachment_path FROM supplier_quotations WHERE rfq_id = $rfq_id AND supplier_id = $sid"));
                if ($existing) {
                    $quotation_id = (int) $existing['quotation_id'];
                    if ($attachment === null) $attachment = $existing['attachment_path']; // keep the old file if none re-uploaded
                    $upd = mysqli_prepare($conn,
                        "UPDATE supplier_quotations SET total_amount=?, validity_date=?, lead_time_days=?, notes=?, attachment_path=?, status='submitted' WHERE quotation_id=?");
                    mysqli_stmt_bind_param($upd, 'dsissi', $total_amount, $validityOrNull, $leadTimeOrNull, $notesOrNull, $attachment, $quotation_id);
                    mysqli_stmt_execute($upd);
                    mysqli_stmt_close($upd);
                    mysqli_query($conn, "DELETE FROM supplier_quotation_items WHERE quotation_id = $quotation_id");
                } else {
                    $ins = mysqli_prepare($conn,
                        "INSERT INTO supplier_quotations (rfq_id, supplier_id, total_amount, validity_date, lead_time_days, notes, attachment_path) VALUES (?,?,?,?,?,?,?)");
                    mysqli_stmt_bind_param($ins, 'iidsiss', $rfq_id, $sid, $total_amount, $validityOrNull, $leadTimeOrNull, $notesOrNull, $attachment);
                    mysqli_stmt_execute($ins);
                    $quotation_id = mysqli_insert_id($conn);
                    mysqli_stmt_close($ins);
                }

                $itemStmt = mysqli_prepare($conn,
                    "INSERT INTO supplier_quotation_items (quotation_id, rfq_item_id, unit_price, line_total) VALUES (?,?,?,?)");
                foreach ($lineItems as $itemId => $price) {
                    $lineTotal = round($price * ($qtyMap[$itemId] ?? 0), 2);
                    mysqli_stmt_bind_param($itemStmt, 'iidd', $quotation_id, $itemId, $price, $lineTotal);
                    if (!mysqli_stmt_execute($itemStmt)) { $failed = 'Could not save quotation line items.'; break; }
                }
                mysqli_stmt_close($itemStmt);

                if ($failed) {
                    mysqli_rollback($conn);
                    $msg = 'error:' . $failed;
                } else {
                    mysqli_query($conn, "UPDATE procurement_rfq_suppliers SET status='quoted' WHERE rfq_id=$rfq_id AND supplier_id=$sid");
                    mysqli_commit($conn);
                    supplier_log_activity($conn, $sid, 'quotation_submitted', 'rfq',
                        'Quotation submitted for ' . $invite['rfq_number'], (string) $rfq_id);
                    $msg = 'success:Quotation submitted.';
                }
            }
        }
    }
}

$rfqs = [];
$res = mysqli_query($conn, "
    SELECT f.rfq_id, f.rfq_number, f.status, f.quotation_deadline, f.notes,
           rs.status AS my_status, b.branch_name
      FROM procurement_rfq_suppliers rs
      JOIN procurement_rfqs f ON f.rfq_id = rs.rfq_id
      JOIN procurement_requests r ON r.request_id = f.request_id
      LEFT JOIN branches b ON b.branch_id = r.branch_id
     WHERE rs.supplier_id = $sid
     ORDER BY (f.status = 'open') DESC, f.created_at DESC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $rfqs[] = $r;

$items_by_rfq = [];
$my_quotes_by_rfq = [];
if ($rfqs) {
    $ids = implode(',', array_map(fn($r) => (int) $r['rfq_id'], $rfqs));
    $ires = mysqli_query($conn, "SELECT * FROM procurement_rfq_items WHERE rfq_id IN ($ids) ORDER BY rfq_item_id");
    if ($ires) while ($i = mysqli_fetch_assoc($ires)) $items_by_rfq[$i['rfq_id']][] = $i;

    $qres = mysqli_query($conn, "SELECT * FROM supplier_quotations WHERE rfq_id IN ($ids) AND supplier_id = $sid");
    if ($qres) while ($q = mysqli_fetch_assoc($qres)) $my_quotes_by_rfq[$q['rfq_id']] = $q;

    if ($my_quotes_by_rfq) {
        $qids = implode(',', array_map(fn($q) => (int) $q['quotation_id'], $my_quotes_by_rfq));
        $qires = mysqli_query($conn, "SELECT * FROM supplier_quotation_items WHERE quotation_id IN ($qids)");
        $my_quote_items = [];
        if ($qires) while ($qi = mysqli_fetch_assoc($qires)) $my_quote_items[$qi['quotation_id']][$qi['rfq_item_id']] = $qi;
    } else {
        $my_quote_items = [];
    }
}

$activePage = 'rfqs';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>RFQs — Supplier Portal</title>
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
      <h1 class="page-title">Requests for Quotation</h1>
    </div>
  </div>

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>"><?= htmlspecialchars($mm) ?></div>
    <?php endif; ?>

    <?php if (empty($rfqs)): ?>
      <div class="widget"><div class="empty-state">No RFQs invited yet.</div></div>
    <?php else: foreach ($rfqs as $r): $items = $items_by_rfq[$r['rfq_id']] ?? []; $mine = $my_quotes_by_rfq[$r['rfq_id']] ?? null;
      // Pill reflects the RFQ's outcome from this supplier's point of view,
      // not the raw table status — "cancelled" and "not invited to award"
      // both need their own label even though neither is a DB status value.
      if ($r['status'] === 'cancelled') { $pillClass = 'cancelled'; $pillText = 'Cancelled'; }
      elseif ($r['status'] === 'awarded') {
        $pillClass = $r['my_status'] === 'awarded' ? 'accepted' : 'rejected';
        $pillText  = $r['my_status'] === 'awarded' ? 'Awarded to you' : 'Not awarded';
      } else {
        $pillClass = $r['my_status'] === 'quoted' ? 'accepted' : 'pending';
        $pillText  = ucfirst($r['my_status']);
      }
    ?>
      <div class="widget">
        <div class="widget-header">
          <div>
            <div class="widget-title"><?= htmlspecialchars($r['rfq_number'] ?? ('#' . $r['rfq_id'])) ?> — <?= htmlspecialchars($r['branch_name'] ?? 'Unknown Branch') ?></div>
            <div class="page-sub"><?= $r['quotation_deadline'] ? 'Deadline: ' . date('M j, Y', strtotime($r['quotation_deadline'])) : 'No deadline set' ?></div>
          </div>
          <span class="status-pill pill-<?= $pillClass ?>">
            <?= $pillText ?>
          </span>
        </div>
        <?php if ($r['notes']): ?><div style="font-size:12.5px;color:var(--text-light);margin-bottom:10px;">Notes: <?= htmlspecialchars($r['notes']) ?></div><?php endif; ?>

        <div class="table-wrap">
          <table>
            <thead><tr><th>Item</th><th>Qty Needed</th><th>Your Unit Price</th></tr></thead>
            <tbody>
              <?php foreach ($items as $it): $qi = $mine ? ($my_quote_items[$mine['quotation_id']][$it['rfq_item_id']] ?? null) : null; ?>
                <tr>
                  <td><?= htmlspecialchars($it['item_name']) ?></td>
                  <td><?= (float) $it['qty_needed'] + 0 ?> <?= htmlspecialchars($it['unit']) ?></td>
                  <td><?= $qi ? '₱' . number_format((float) $qi['unit_price'], 2) : '—' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($r['status'] === 'open'): ?>
          <button class="btn-primary" style="margin-top:12px;" onclick='openQuoteModal(<?= htmlspecialchars(json_encode(["rfq_id" => $r['rfq_id'], "rfq_number" => $r['rfq_number'], "items" => $items, "mine" => $mine, "my_items" => $mine ? ($my_quote_items[$mine['quotation_id']] ?? new stdClass()) : new stdClass()])) ?>)">
            <?= $mine ? 'Update Quotation' : 'Submit Quotation' ?>
          </button>
        <?php elseif ($mine && $mine['attachment_path']): ?>
          <a class="btn-ghost btn-sm" href="../<?= htmlspecialchars($mine['attachment_path']) ?>" target="_blank" rel="noopener">View submitted attachment</a>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div class="modal-overlay" id="modal-quote">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Quotation — <span id="quote-rfq-number"></span></span>
      <button class="modal-close" onclick="closeModal('modal-quote')">✕</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="act" value="submit_quotation">
      <input type="hidden" name="rfq_id" id="quote-rfq-id">
      <div id="quote-items"></div>
      <div class="form-row">
        <div class="form-group">
          <label>Validity Date</label>
          <input type="date" name="validity_date" id="quote-validity">
        </div>
        <div class="form-group">
          <label>Lead Time (days)</label>
          <input type="number" min="0" name="lead_time_days" id="quote-lead-time">
        </div>
      </div>
      <div class="form-group">
        <label>Notes</label>
        <textarea name="notes" id="quote-notes" rows="2" maxlength="255"></textarea>
      </div>
      <div class="form-group">
        <label>Attachment (PDF/JPG/PNG, optional)</label>
        <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" onclick="closeModal('modal-quote')">Cancel</button>
        <button type="submit" class="btn-primary">Submit</button>
      </div>
    </form>
  </div>
</div>

<script>
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function openQuoteModal(data) {
  document.getElementById('quote-rfq-id').value = data.rfq_id;
  document.getElementById('quote-rfq-number').textContent = data.rfq_number;
  document.getElementById('quote-validity').value = data.mine ? (data.mine.validity_date || '') : '';
  document.getElementById('quote-lead-time').value = data.mine ? (data.mine.lead_time_days || '') : '';
  document.getElementById('quote-notes').value = data.mine ? (data.mine.notes || '') : '';

  var wrap = document.getElementById('quote-items');
  wrap.innerHTML = '';
  data.items.forEach(function (it) {
    var existing = data.my_items ? data.my_items[it.rfq_item_id] : null;
    var group = document.createElement('div');
    group.className = 'form-group';
    var label = document.createElement('label');
    label.textContent = it.item_name + ' (' + parseFloat(it.qty_needed) + ' ' + it.unit + ') — Unit Price *';
    var input = document.createElement('input');
    input.type = 'number'; input.step = '0.01'; input.min = '0'; input.required = true;
    input.name = 'unit_price[' + it.rfq_item_id + ']';
    input.value = existing ? existing.unit_price : '';
    group.appendChild(label);
    group.appendChild(input);
    wrap.appendChild(group);
  });

  document.getElementById('modal-quote').classList.add('open');
}
document.querySelectorAll('.modal-overlay').forEach(function (m) {
  m.addEventListener('click', function (e) { if (e.target === m) m.classList.remove('open'); });
});
</script>
<script src="../js/lucide-init.js"></script>
</body>
</html>
