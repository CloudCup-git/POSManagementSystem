<?php
/**
 * Procurement — Resolve Discrepancy (C22)
 * -------------------------------------------------------------
 * Admin only. Manual resolution for requests stuck in
 * DELIVERY_DISCREPANCY (a receiving event flagged damaged/missing
 * items — see Receive_Verify_Action.php). No automatic re-receiving
 * path exists (deliberately, per user decision) — Admin reviews the
 * flagged item detail and records a resolution decision:
 *   - accept_as_is : business accepts the shortage/damage, proceeds
 *                    with only what was actually received in good
 *                    condition (already the true stock state — this
 *                    just unblocks the workflow).
 *   - write_off     : same data/stock outcome, but the note records
 *                    that the missing/damaged qty is formally written
 *                    off rather than silently accepted.
 * Both resolution types transition DELIVERY_DISCREPANCY -> RECEIVED_
 * VERIFIED (so the request becomes payment-eligible) via the
 * resolve_discrepancy decision added to procurement_workflow.php.
 * A note is required either way, for the audit trail. Self-approval
 * blocked (actor cannot be the request's last_actor_id) for
 * consistency with every other stage in this module, even though in
 * practice Admin sits outside the normal approval chain here.
 * -------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/procurement_queries.php';

$user = procurement_require_stage([PROC_STAGE_ADMIN]);

if (!$conn) {
    http_response_code(503);
    die('Database connection unavailable. Please check that MySQL/MariaDB is running, then refresh this page.');
}
ensure_procurement_tables($conn);

$user_id   = $user['user_id'];
$full_name = $user['full_name'];
$msg = '';
$RESOLUTIONS = ['accept_as_is' => 'Accept as-is', 'write_off' => 'Write off shortage'];

// ── POST: resolve (DELIVERY_DISCREPANCY -> RECEIVED_VERIFIED) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'resolve') {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $resolution = $_POST['resolution'] ?? '';
    $note       = trim((string) ($_POST['note'] ?? ''));
    if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);
    $resolution = array_key_exists($resolution, $RESOLUTIONS) ? $resolution : null;

    if ($resolution === null) {
        $msg = 'error:Select a resolution type.';
    } elseif ($note === '') {
        $msg = 'error:A note explaining the resolution is required.';
    } else {
        $stmt = mysqli_prepare($conn,
            'SELECT request_id, status, requested_by, last_actor_id FROM procurement_requests WHERE request_id = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($stmt, 'i', $request_id);
        mysqli_stmt_execute($stmt);
        $req = mysqli_stmt_get_result($stmt)->fetch_assoc();
        mysqli_stmt_close($stmt);

        if (!$req) {
            $msg = 'error:Request not found.';
        } elseif (procurement_required_stage($req['status']) !== PROC_STAGE_ADMIN || $req['status'] !== PROC_STATUS_DELIVERY_DISCREPANCY) {
            $msg = 'error:This request is not awaiting discrepancy resolution — someone may have already acted on it.';
        } elseif (procurement_is_self_approval($req, $user_id)) {
            $msg = 'error:You cannot resolve a request you last acted on.';
        } else {
            $old_status = $req['status'];
            $next = procurement_next_status($old_status, 'resolve_discrepancy');

            if (!$next || !procurement_can_transition($old_status, $next)) {
                $msg = 'error:That action is not valid for this request\'s current state.';
            } else {
                $upd = mysqli_prepare($conn, 'UPDATE procurement_requests SET status = ?, last_actor_id = ? WHERE request_id = ? AND status = ?');
                mysqli_stmt_bind_param($upd, 'siis', $next, $user_id, $request_id, $old_status);
                mysqli_stmt_execute($upd);
                $changed = mysqli_stmt_affected_rows($upd);
                mysqli_stmt_close($upd);

                if ($changed === 1) {
                    procurement_log_audit($conn, $request_id, $user_id, $full_name, 'discrepancy_resolved', $old_status, $next,
                        $RESOLUTIONS[$resolution] . ' — ' . $note);
                    $msg = 'success:Discrepancy resolved. Request is now eligible for payment.';
                } else {
                    $msg = 'error:This request was already updated — refresh and try again.';
                }
            }
        }
    }
}

// ── Queue data (cross-branch, DELIVERY_DISCREPANCY requests) ────────
// Built from PROC_STAGE_FOR_STATUS rather than a hardcoded status, so this
// queue self-empties now that discrepancy resolution is Store-Manager-led
// (see manager/Discrepancy_Resolution_Page.php) instead of showing stale,
// un-actionable rows to Admin.
$__admin_discrepancy_statuses = (procurement_required_stage(PROC_STATUS_DELIVERY_DISCREPANCY) === PROC_STAGE_ADMIN)
    ? [PROC_STATUS_DELIVERY_DISCREPANCY] : [];
$queue = [];
if ($__admin_discrepancy_statuses) {
    $placeholders = implode(',', array_fill(0, count($__admin_discrepancy_statuses), '?'));
    $stmt = mysqli_prepare($conn,
        "SELECT r.request_id, r.requested_by_name, r.branch_id, b.branch_name,
                po.po_id, po.po_number, po.supplier_name
         FROM procurement_requests r
         LEFT JOIN branches b ON b.branch_id = r.branch_id
         JOIN procurement_purchase_orders po ON po.request_id = r.request_id
         WHERE r.status IN ($placeholders)
         ORDER BY r.updated_at ASC"
    );
    mysqli_stmt_bind_param($stmt, str_repeat('s', count($__admin_discrepancy_statuses)), ...$__admin_discrepancy_statuses);
    mysqli_stmt_execute($stmt);
    $queue = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
}

// For each, pull the flagged (bad-condition) receipt lines only.
foreach ($queue as &$r) {
    $istmt = mysqli_prepare($conn,
        'SELECT ri.item_name, ri.unit, ri.qty_received, ri.item_condition, ri.note
         FROM procurement_receipt_items ri
         JOIN procurement_receipts rec ON rec.receipt_id = ri.receipt_id
         WHERE rec.po_id = ? AND ri.item_condition <> "good"
         ORDER BY ri.receipt_item_id ASC'
    );
    mysqli_stmt_bind_param($istmt, 'i', $r['po_id']);
    mysqli_stmt_execute($istmt);
    $r['flagged_items'] = mysqli_stmt_get_result($istmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($istmt);
}
unset($r);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Resolve Discrepancies — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../css/admin_page.css" />
  <link rel="stylesheet" href="../css/inventory_management.css" />
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <style>
    .req-card{border:1px solid var(--border,#e5e7eb);border-radius:10px;padding:16px;margin-bottom:14px;}
    .req-card-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;}
    .req-items{margin:10px 0;font-size:13px;color:var(--text-light);}
    .req-items li{margin-bottom:2px;}
    .branch-tag{font-size:12px;color:var(--text-light);}
    .status-badge{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11.5px;font-weight:600;background:#fdeaea;color:#c0392b;}
    .cond-badge{display:inline-block;padding:1px 7px;border-radius:10px;font-size:11px;font-weight:600;margin-left:6px;}
    .cond-damaged{background:#fdeaea;color:#c0392b;}
    .cond-missing{background:#fff4e5;color:#b45300;}
    .alert-banner{padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:14px;}
    .alert-success{background:#e7f5ec;color:#1e7e42;border:1px solid #b7e3c6;}
    .alert-error{background:#fdeaea;color:#c0392b;border:1px solid #f3c6c6;}
  </style>
</head>

<body>
  <script src="../js/sidebar-toggle.js"></script>
  <?php $active_page = 'proc-discrepancy'; require_once __DIR__ . '/Sidebar_Admin.php'; ?>
  <div class="main">
    <div class="topbar">
      <div class="topbar-left">
        <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
        <h1>Resolve Discrepancies</h1>
      </div>
    </div>
  <div class="content">
  <div class="page-wrap">
    <p class="req-items">Requests where a delivery had damaged or missing items. Review and record a resolution to unblock payment.</p>

    <?php if ($msg): [$type, $text] = explode(':', $msg, 2); ?>
      <div class="alert-banner <?= $type === 'success' ? 'alert-success' : 'alert-error' ?>"><?= htmlspecialchars($text) ?></div>
    <?php endif; ?>

    <?php if (empty($queue)): ?>
      <p>No delivery discrepancies are currently awaiting resolution.</p>
    <?php else: foreach ($queue as $r): ?>
      <div class="req-card">
        <div class="req-card-head">
          <div>
            <strong><?= htmlspecialchars($r['po_number'] ?? ('PO-' . str_pad((string)$r['po_id'], 6, '0', STR_PAD_LEFT))) ?></strong>
            &middot; Request #<?= (int) $r['request_id'] ?>
            <span class="branch-tag"> — <?= htmlspecialchars($r['branch_name'] ?? 'Unknown Branch') ?></span>
          </div>
          <span class="status-badge">DELIVERY DISCREPANCY</span>
        </div>
        <div class="req-items">
          Requested by <?= htmlspecialchars($r['requested_by_name']) ?> &middot; Supplier: <?= htmlspecialchars($r['supplier_name']) ?>
        </div>
        <ul class="req-items">
          <?php foreach ($r['flagged_items'] as $it): ?>
            <li><?= htmlspecialchars($it['item_name']) ?> — <?= htmlspecialchars($it['qty_received']) ?> <?= htmlspecialchars($it['unit']) ?>
              <span class="cond-badge cond-<?= htmlspecialchars($it['item_condition']) ?>"><?= strtoupper(htmlspecialchars($it['item_condition'])) ?></span>
              <?php if ($it['note']): ?> — <?= htmlspecialchars($it['note']) ?><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>

        <form method="post" style="margin-top:12px;">
          <input type="hidden" name="act" value="resolve">
          <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <select name="resolution" required style="flex:1;min-width:180px;">
              <option value="">Resolution…</option>
              <?php foreach ($RESOLUTIONS as $k => $label): ?>
                <option value="<?= $k ?>"><?= $label ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="note" placeholder="Note explaining the resolution (required)" required style="flex:2;min-width:200px;">
          </div>
          <button type="submit" style="margin-top:8px;">Resolve</button>
        </form>
      </div>
    <?php endforeach; endif; ?>
  </div>
  </div>
  </div>
</body>

</html>
