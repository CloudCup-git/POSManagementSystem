<?php
/**
 * Procurement — Area/Operations Manager Validation (C9)
 * -------------------------------------------------------------
 * Area/Ops Manager only. Validates requests already approved by a Store
 * Manager. Cross-branch by design — no assigned-branch table exists
 * anywhere in this codebase, so nothing today restricts this role to a
 * single branch. Shows the branch on each row for context.
 * Same self-approval + guarded-UPDATE pattern as the Store Manager queue.
 * -------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/procurement_queries.php';

$user = procurement_require_stage([PROC_STAGE_AREA_OPS_MANAGER]);

if (!$conn) {
    http_response_code(503);
    die('Database connection unavailable. Please check that MySQL/MariaDB is running, then refresh this page.');
}

ensure_procurement_tables($conn);

$user_id   = $user['user_id'];
$full_name = $user['full_name'];

$msg = '';

// ── POST: decide ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'decide') {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $decision   = $_POST['decision'] ?? '';
    $note       = trim($_POST['note'] ?? '');
    if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

    if (!in_array($decision, ['approve', 'reject', 'return'], true)) {
        $msg = 'error:Invalid decision.';
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
        } elseif (procurement_required_stage($req['status']) !== PROC_STAGE_AREA_OPS_MANAGER) {
            $msg = 'error:This request is no longer pending your review — someone may have already acted on it.';
        } elseif (procurement_is_self_approval($req, $user_id)) {
            $msg = 'error:You cannot act on your own request.';
        } elseif (in_array($decision, ['reject', 'return'], true) && $note === '') {
            $msg = 'error:A note is required when rejecting or returning a request.';
        } else {
            $old_status = $req['status'];
            $next = procurement_next_status($old_status, $decision);

            if (!$next || !procurement_can_transition($old_status, $next)) {
                $msg = 'error:That decision is not valid for this request\'s current state.';
            } else {
                $upd = mysqli_prepare($conn,
                    'UPDATE procurement_requests SET status = ?, last_actor_id = ?
                     WHERE request_id = ? AND status = ?'
                );
                mysqli_stmt_bind_param($upd, 'siis', $next, $user_id, $request_id, $old_status);
                mysqli_stmt_execute($upd);
                $changed = mysqli_stmt_affected_rows($upd);
                mysqli_stmt_close($upd);

                if ($changed === 1) {
                    $action_label = ['approve' => 'area_ops_approved', 'reject' => 'rejected', 'return' => 'returned_for_revision'][$decision];
                    procurement_log_audit($conn, $request_id, $user_id, $full_name, $action_label, $old_status, $next, $note !== '' ? $note : null);
                    $msg = 'success:Request ' . ($decision === 'approve' ? 'validated' : ($decision === 'reject' ? 'rejected' : 'returned for revision')) . '.';
                } else {
                    $msg = 'error:This request was already decided or changed — refresh and try again.';
                }
            }
        }
    }
}

// ── Queue data (cross-branch) ───────────────────────────────────────
// Built from PROC_STAGE_FOR_STATUS rather than a hardcoded status, so this
// queue self-empties if the workflow no longer routes anything to this
// stage (the Area/Ops Manager step was removed from the official
// workflow — see procurement_workflow.php) instead of showing stale,
// un-actionable rows.
$__area_statuses = array_keys(array_filter(PROC_STAGE_FOR_STATUS, fn($s) => $s === PROC_STAGE_AREA_OPS_MANAGER));
$queue = [];
if ($__area_statuses) {
    $placeholders = implode(',', array_fill(0, count($__area_statuses), '?'));
    $stmt = mysqli_prepare($conn,
        "SELECT r.request_id, r.requested_by_name, r.notes, r.created_at, r.branch_id, b.branch_name
         FROM procurement_requests r
         LEFT JOIN branches b ON b.branch_id = r.branch_id
         WHERE r.status IN ($placeholders)
         ORDER BY r.created_at ASC"
    );
    mysqli_stmt_bind_param($stmt, str_repeat('s', count($__area_statuses)), ...$__area_statuses);
    mysqli_stmt_execute($stmt);
    $queue = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
}

foreach ($queue as &$r) {
    $istmt = mysqli_prepare($conn, 'SELECT item_name, unit, qty_requested FROM procurement_request_items WHERE request_id = ? ORDER BY item_id ASC');
    mysqli_stmt_bind_param($istmt, 'i', $r['request_id']);
    mysqli_stmt_execute($istmt);
    $r['items'] = mysqli_stmt_get_result($istmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($istmt);
}
unset($r);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Area/Ops Validation — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../css/admin_page.css" />
  <link rel="stylesheet" href="../css/inventory_management.css" />
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <style>
    .req-card{border:1px solid var(--border,#e5e7eb);border-radius:10px;padding:16px;margin-bottom:14px;}
    .req-card-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;}
    .req-items{margin:10px 0;font-size:13px;color:var(--text-light);}
    .req-items li{margin-bottom:2px;}
    .req-actions{display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap;}
    .req-actions input[type=text]{flex:1;min-width:200px;}
    .btn-reject{background:#fdeaea;color:#c0392b;border:1px solid #f3c6c6;}
    .btn-return{background:#fff4e5;color:#b45300;border:1px solid #f0d6a8;}
    .branch-tag{font-size:12px;color:var(--text-light);}
  </style>
</head>

<body>
  <?php $active_page = 'proc-validation'; require_once __DIR__ . '/Sidebar_Manager.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-left">
        <div class="topbar-title">Area/Ops Validation — All Branches</div>
      </div>
    </div>

    <div class="content">
      <?php if ($msg): [$kind, $text] = explode(':', $msg, 2); ?>
        <div class="alert-strip">
          <div class="alert-card <?= $kind === 'success' ? '' : 'danger' ?>">
            <div class="alert-icon"><i data-lucide="<?= $kind === 'success' ? 'circle-check' : 'circle-alert' ?>"></i></div>
            <div><?= htmlspecialchars($text) ?></div>
          </div>
        </div>
      <?php endif; ?>

      <?php if (empty($queue)): ?>
        <div class="table-wrap" style="padding:40px;text-align:center;color:var(--text-light);">
          No requests waiting for validation.
        </div>
      <?php else: foreach ($queue as $r): ?>
        <div class="req-card">
          <div class="req-card-head">
            <div>
              <strong>REQ-<?= str_pad((string) $r['request_id'], 4, '0', STR_PAD_LEFT) ?></strong>
              — <span class="branch-tag"><?= htmlspecialchars($r['branch_name'] ?? 'Unknown Branch') ?></span>
              — requested by <?= htmlspecialchars($r['requested_by_name']) ?>
              on <?= date('M d, Y', strtotime($r['created_at'])) ?>
            </div>
            <span class="req-pill" style="background:#e7f1ff;color:#1a5fb4;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:600;">STORE MANAGER APPROVED</span>
          </div>

          <?php if (!empty($r['notes'])): ?>
            <div style="font-size:13px;color:var(--text-light);margin-bottom:6px;">Requester note: <?= htmlspecialchars($r['notes']) ?></div>
          <?php endif; ?>

          <ul class="req-items">
            <?php foreach ($r['items'] as $it): ?>
              <li><?= htmlspecialchars($it['item_name']) ?> — <?= (float) $it['qty_requested'] + 0 ?> <?= htmlspecialchars($it['unit']) ?></li>
            <?php endforeach; ?>
          </ul>

          <form method="POST" action="" class="req-actions" onsubmit="return confirm('Confirm this decision?');">
            <input type="hidden" name="act" value="decide" />
            <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>" />
            <input type="text" name="note" maxlength="255" placeholder="Note (required for reject/return)" />
            <button type="submit" name="decision" value="approve" class="btn-save">Validate</button>
            <button type="submit" name="decision" value="return" class="btn-return">Return for Revision</button>
            <button type="submit" name="decision" value="reject" class="btn-reject">Reject</button>
          </form>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <script>lucide.createIcons();</script>
</body>
</html>
