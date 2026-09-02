<?php
/**
 * Supplier Portal — Stock Checks (procurement redesign, supplier side of
 * manager/Assign_Supplier_Page.php).
 * -------------------------------------------------------------
 * A supplier only ever sees requests assigned to them that are sitting
 * at SUPPLIER_STOCK_CHECKING with supplier_check_status = 'pending' —
 * i.e. it's genuinely their turn to respond. Two responses:
 *   - confirm_full : every item is available: enter a unit price per
 *     item + a shipping fee, optionally attach a quotation document.
 *     Transitions the request to QUOTATION_RECEIVED.
 *   - partial      : report an available_quantity per item (some may
 *     be 0). Status stays at SUPPLIER_STOCK_CHECKING — the Store
 *     Manager decides what to do next on Quotation_Review_Page.php.
 * Ownership is enforced by scoping every query/UPDATE to
 * supplier_id = $sid, never trusted from the browser.
 * -------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/procurement_queries.php';

$supplier_id = supplier_require_login();
$sid = (int) $supplier_id;
$actor_user_id = (int) ($_SESSION['user_id'] ?? 0);
$actor_name    = $_SESSION['full_name'] ?? 'Supplier';

ensure_procurement_tables($conn);

$msg = '';

// ── POST: confirm_full ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'confirm_full') {
    $request_id   = (int) ($_POST['request_id'] ?? 0);
    $prices       = $_POST['unit_price'] ?? []; // [item_id => price string]
    $shipping_fee = trim((string) ($_POST['shipping_fee'] ?? ''));
    $notes        = trim((string) ($_POST['notes'] ?? ''));
    if (mb_strlen($notes) > 255) $notes = mb_substr($notes, 0, 255);

    if ($shipping_fee === '' || !is_numeric($shipping_fee) || (float) $shipping_fee < 0) {
        $msg = 'error:Enter a valid shipping fee (0 or more).';
    } else {
        $stmt = mysqli_prepare($conn,
            'SELECT request_id, status, supplier_id, supplier_check_status FROM procurement_requests WHERE request_id = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($stmt, 'i', $request_id);
        mysqli_stmt_execute($stmt);
        $req = mysqli_stmt_get_result($stmt)->fetch_assoc();
        mysqli_stmt_close($stmt);

        if (!$req || (int) $req['supplier_id'] !== $sid) {
            $msg = 'error:Request not found.';
        } elseif ($req['status'] !== PROC_STATUS_SUPPLIER_STOCK_CHECKING || $req['supplier_check_status'] !== 'pending') {
            $msg = 'error:This request is no longer awaiting your response.';
        } else {
            $items = [];
            $istmt = mysqli_prepare($conn, 'SELECT item_id, item_name, unit, qty_requested FROM procurement_request_items WHERE request_id = ? ORDER BY item_id ASC');
            mysqli_stmt_bind_param($istmt, 'i', $request_id);
            mysqli_stmt_execute($istmt);
            $items = mysqli_stmt_get_result($istmt)->fetch_all(MYSQLI_ASSOC);
            mysqli_stmt_close($istmt);

            $failed = empty($items) ? 'This request has no line items.' : null;
            $quotation_amount = 0.0;
            if (!$failed) {
                foreach ($items as $it) {
                    $raw = trim((string) ($prices[$it['item_id']] ?? ''));
                    if ($raw === '' || !is_numeric($raw) || (float) $raw < 0) {
                        $failed = 'Enter a valid unit price (0 or more) for every item.';
                        break;
                    }
                    $quotation_amount += round((float) $raw, 2) * (float) $it['qty_requested'];
                }
            }

            $attachment_path = null;
            if (!$failed && !empty($_FILES['attachment']['name'])) {
                $up = supplier_handle_upload($_FILES['attachment']);
                if (!$up['ok']) {
                    $failed = $up['error'];
                } else {
                    $attachment_path = $up['path'];
                }
            }

            if ($failed) {
                $msg = 'error:' . $failed;
            } else {
                $old_status = $req['status'];
                $next = procurement_next_status($old_status, 'supplier_quoted');

                if (!$next || !procurement_can_transition($old_status, $next)) {
                    $msg = 'error:That action is not valid for this request\'s current state.';
                } else {
                    $upd = mysqli_prepare($conn,
                        "UPDATE procurement_requests
                         SET status = ?, supplier_check_status = 'stock_confirmed',
                             quotation_amount = ?, shipping_fee = ?, quotation_attachment_path = ?, quotation_notes = ?
                         WHERE request_id = ? AND status = ? AND supplier_id = ?"
                    );
                    $__shipping = round((float) $shipping_fee, 2);
                    mysqli_stmt_bind_param($upd, 'sddssisi',
                        $next, $quotation_amount, $__shipping, $attachment_path, $notes, $request_id, $old_status, $sid
                    );
                    mysqli_stmt_execute($upd);
                    $changed = mysqli_stmt_affected_rows($upd);
                    mysqli_stmt_close($upd);

                    if ($changed === 1) {
                        procurement_log_audit($conn, $request_id, $actor_user_id, $actor_name, 'supplier_quoted', $old_status, $next,
                            'Quotation: ₱' . number_format($quotation_amount, 2) . ' + ₱' . number_format($__shipping, 2) . ' shipping');
                        supplier_log_activity($conn, $sid, 'stock_check_confirmed', 'stock_checks',
                            'Confirmed stock and quoted REQ-' . str_pad((string) $request_id, 4, '0', STR_PAD_LEFT), (string) $request_id);
                        $msg = 'success:Quotation submitted.';
                    } else {
                        $msg = 'error:This request was already updated — refresh and try again.';
                    }
                }
            }
        }
    }
}

// ── POST: partial ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'partial') {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $available  = $_POST['available_qty'] ?? []; // [item_id => qty string]
    $notes      = trim((string) ($_POST['notes'] ?? ''));
    if (mb_strlen($notes) > 255) $notes = mb_substr($notes, 0, 255);

    $stmt = mysqli_prepare($conn,
        'SELECT request_id, status, supplier_id, supplier_check_status FROM procurement_requests WHERE request_id = ? LIMIT 1'
    );
    mysqli_stmt_bind_param($stmt, 'i', $request_id);
    mysqli_stmt_execute($stmt);
    $req = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);

    if (!$req || (int) $req['supplier_id'] !== $sid) {
        $msg = 'error:Request not found.';
    } elseif ($req['status'] !== PROC_STATUS_SUPPLIER_STOCK_CHECKING || $req['supplier_check_status'] !== 'pending') {
        $msg = 'error:This request is no longer awaiting your response.';
    } else {
        $items = [];
        $istmt = mysqli_prepare($conn, 'SELECT item_id, qty_requested FROM procurement_request_items WHERE request_id = ? ORDER BY item_id ASC');
        mysqli_stmt_bind_param($istmt, 'i', $request_id);
        mysqli_stmt_execute($istmt);
        $items = mysqli_stmt_get_result($istmt)->fetch_all(MYSQLI_ASSOC);
        mysqli_stmt_close($istmt);

        $clean = [];
        $failed = empty($items) ? 'This request has no line items.' : null;
        if (!$failed) {
            foreach ($items as $it) {
                $raw = trim((string) ($available[$it['item_id']] ?? ''));
                $qty = $raw === '' ? 0.0 : (float) $raw;
                if (!is_numeric($raw === '' ? '0' : $raw) || $qty < 0 || $qty > (float) $it['qty_requested']) {
                    $failed = 'Enter a valid available quantity (0 up to the requested amount) for every item.';
                    break;
                }
                $clean[(int) $it['item_id']] = $qty;
            }
        }

        if ($failed) {
            $msg = 'error:' . $failed;
        } else {
            mysqli_begin_transaction($conn);
            $ok = true;
            $ustmt = mysqli_prepare($conn, 'UPDATE procurement_request_items SET available_quantity = ? WHERE item_id = ? AND request_id = ?');
            foreach ($clean as $item_id => $qty) {
                mysqli_stmt_bind_param($ustmt, 'dii', $qty, $item_id, $request_id);
                if (!mysqli_stmt_execute($ustmt)) { $ok = false; break; }
            }
            mysqli_stmt_close($ustmt);

            if ($ok) {
                $upd = mysqli_prepare($conn,
                    "UPDATE procurement_requests SET supplier_check_status = 'partial_stock', quotation_notes = ?
                     WHERE request_id = ? AND status = ? AND supplier_id = ? AND supplier_check_status = 'pending'"
                );
                $__status = PROC_STATUS_SUPPLIER_STOCK_CHECKING;
                mysqli_stmt_bind_param($upd, 'sisi', $notes, $request_id, $__status, $sid);
                mysqli_stmt_execute($upd);
                $ok = mysqli_stmt_affected_rows($upd) === 1;
                mysqli_stmt_close($upd);
            }

            if ($ok) {
                mysqli_commit($conn);
                procurement_log_audit($conn, $request_id, $actor_user_id, $actor_name, 'partial_stock_reported', PROC_STATUS_SUPPLIER_STOCK_CHECKING, PROC_STATUS_SUPPLIER_STOCK_CHECKING,
                    $notes !== '' ? $notes : null);
                supplier_log_activity($conn, $sid, 'partial_stock_reported', 'stock_checks',
                    'Reported partial stock for REQ-' . str_pad((string) $request_id, 4, '0', STR_PAD_LEFT), (string) $request_id);
                $msg = 'success:Partial stock reported. Your Store Manager contact will decide how to proceed.';
            } else {
                mysqli_rollback($conn);
                $msg = 'error:This request was already updated — refresh and try again.';
            }
        }
    }
}

// ── Queue data ───────────────────────────────────────────────────────
$queue = [];
$__status = PROC_STATUS_SUPPLIER_STOCK_CHECKING;
$stmt = mysqli_prepare($conn,
    "SELECT r.request_id, r.created_at, b.branch_name
     FROM procurement_requests r
     LEFT JOIN branches b ON b.branch_id = r.branch_id
     WHERE r.supplier_id = ? AND r.status = ? AND r.supplier_check_status = 'pending'
     ORDER BY r.created_at ASC"
);
mysqli_stmt_bind_param($stmt, 'is', $sid, $__status);
mysqli_stmt_execute($stmt);
$queue = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

foreach ($queue as &$r) {
    $istmt = mysqli_prepare($conn, 'SELECT item_id, item_name, unit, qty_requested FROM procurement_request_items WHERE request_id = ? ORDER BY item_id ASC');
    mysqli_stmt_bind_param($istmt, 'i', $r['request_id']);
    mysqli_stmt_execute($istmt);
    $r['items'] = mysqli_stmt_get_result($istmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($istmt);
}
unset($r);

$activePage = 'stockcheck';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Stock Checks — Supplier Portal</title>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="css/supplier.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    .stock-card{border:1px solid var(--hr-border,#e9e3d8);border-radius:10px;padding:16px;margin-bottom:14px;}
    .stock-card-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;}
    .stock-item-row{display:flex;align-items:center;gap:10px;margin-bottom:6px;font-size:13px;}
    .stock-item-row .item-name{flex:1;}
    .stock-item-row input[type=number]{width:110px;}
    .resp-toggle{display:flex;gap:8px;margin-bottom:12px;}
    .resp-toggle button{flex:1;padding:8px;border-radius:8px;border:1px solid var(--hr-border,#e9e3d8);background:#fff;cursor:pointer;font-size:13px;font-weight:600;}
    .resp-toggle button.active{background:#b8703f;color:#fff;border-color:#b8703f;}
    .resp-panel{display:none;}
    .resp-panel.active{display:block;}
  </style>
</head>
<body>
<script src="../js/sidebar-toggle.js"></script>
<?php require_once __DIR__ . '/includes/supplier_sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle sidebar"><i data-lucide="menu"></i></button>
      <h1 class="page-title">Stock Checks</h1>
    </div>
  </div>

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>"><?= htmlspecialchars($mm) ?></div>
    <?php endif; ?>

    <?php if (empty($queue)): ?>
      <div class="widget"><div class="table-wrap"><div class="empty-state" style="padding:40px;text-align:center;">No requests are waiting for your response right now.</div></div></div>
    <?php else: foreach ($queue as $r): $formId = 'req' . $r['request_id']; ?>
      <div class="stock-card">
        <div class="stock-card-head">
          <strong>REQ-<?= str_pad((string) $r['request_id'], 4, '0', STR_PAD_LEFT) ?></strong>
          <span class="field-hint"><?= htmlspecialchars($r['branch_name'] ?? 'Unknown Branch') ?> — <?= date('M j, Y', strtotime($r['created_at'])) ?></span>
        </div>

        <div class="resp-toggle">
          <button type="button" class="active" onclick="switchPanel('<?= $formId ?>','full')" id="<?= $formId ?>-btn-full">All items available</button>
          <button type="button" onclick="switchPanel('<?= $formId ?>','partial')" id="<?= $formId ?>-btn-partial">Some items unavailable</button>
        </div>

        <form method="POST" action="" id="<?= $formId ?>-full" class="resp-panel active" enctype="multipart/form-data" onsubmit="return confirm('Submit this quotation?');">
          <input type="hidden" name="act" value="confirm_full"/>
          <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>"/>
          <?php foreach ($r['items'] as $it): ?>
            <div class="stock-item-row">
              <span class="item-name"><?= htmlspecialchars($it['item_name']) ?> — <?= (float) $it['qty_requested'] + 0 ?> <?= htmlspecialchars($it['unit']) ?></span>
              <input type="number" step="0.01" min="0" name="unit_price[<?= (int) $it['item_id'] ?>]" placeholder="Unit price" required/>
            </div>
          <?php endforeach; ?>
          <div class="form-group">
            <label>Shipping Fee *</label>
            <input type="number" step="0.01" min="0" name="shipping_fee" required/>
          </div>
          <div class="form-group">
            <label>Notes (optional)</label>
            <input type="text" name="notes" maxlength="255"/>
          </div>
          <div class="form-group">
            <label>Quotation Document (optional — PDF/JPG/PNG)</label>
            <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png"/>
          </div>
          <button type="submit" class="btn-primary">Submit Quotation</button>
        </form>

        <form method="POST" action="" id="<?= $formId ?>-partial" class="resp-panel" onsubmit="return confirm('Report partial stock for this request?');">
          <input type="hidden" name="act" value="partial"/>
          <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>"/>
          <?php foreach ($r['items'] as $it): ?>
            <div class="stock-item-row">
              <span class="item-name"><?= htmlspecialchars($it['item_name']) ?> — requested <?= (float) $it['qty_requested'] + 0 ?> <?= htmlspecialchars($it['unit']) ?></span>
              <input type="number" step="0.01" min="0" max="<?= (float) $it['qty_requested'] + 0 ?>" name="available_qty[<?= (int) $it['item_id'] ?>]" placeholder="Available qty" value="<?= (float) $it['qty_requested'] + 0 ?>" required/>
            </div>
          <?php endforeach; ?>
          <div class="form-group">
            <label>Notes (optional)</label>
            <input type="text" name="notes" maxlength="255" placeholder="Why the shortfall, expected restock, etc."/>
          </div>
          <button type="submit" class="btn-primary">Report Partial Stock</button>
        </form>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<script>
function switchPanel(formId, which) {
  document.getElementById(formId + '-full').classList.toggle('active', which === 'full');
  document.getElementById(formId + '-partial').classList.toggle('active', which === 'partial');
  document.getElementById(formId + '-btn-full').classList.toggle('active', which === 'full');
  document.getElementById(formId + '-btn-partial').classList.toggle('active', which === 'partial');
}
</script>
<script src="../js/lucide-init.js"></script>
</body>
</html>
