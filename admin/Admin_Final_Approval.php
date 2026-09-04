<?php
/**
 * Procurement — Admin/Owner Final Approval (C12) — RETIRED.
 * -------------------------------------------------------------
 * Owner (role='admin') was removed from the procurement approval chain
 * entirely — Finance Head now gives final approval directly (see
 * finance/Finance_Head_Final_Approval.php) and also issues the Purchase
 * Order. PROC_STATUS_FINANCE_MANAGER_RECOMMEND was removed from
 * PROC_FORWARD_PATH, so no request can ever reach it again; this page's
 * queue (still filtered on that exact status) is therefore permanently
 * empty. Left in place non-destructively, same pattern as
 * manager/Area_Ops_Validation.php, rather than deleted — it still loads
 * cleanly for role='admin', it just never has anything to show.
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
$BUDGET_LABELS = ['within_budget' => 'Within Budget', 'over_budget' => 'Over Budget'];

// ── POST: decide (approve/reject only — no "return" at this stage) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'decide') {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $decision   = $_POST['decision'] ?? '';
    $note       = trim($_POST['note'] ?? '');
    if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

    if (!in_array($decision, ['approve', 'reject'], true)) {
        $msg = 'error:Invalid decision. Only final approval or rejection is allowed at this stage.';
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
        } elseif (procurement_required_stage($req['status']) !== PROC_STAGE_ADMIN || $req['status'] !== PROC_STATUS_FINANCE_MANAGER_RECOMMEND) {
            $msg = 'error:This request is no longer pending your final approval — someone may have already acted on it.';
        } elseif (procurement_is_self_approval($req, $user_id)) {
            $msg = 'error:You cannot act on your own request.';
        } elseif ($decision === 'reject' && $note === '') {
            $msg = 'error:A note is required when rejecting a request.';
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
                    $action_label = $decision === 'approve' ? 'final_approved' : 'rejected';
                    procurement_log_audit($conn, $request_id, $user_id, $full_name, $action_label, $old_status, $next, $note !== '' ? $note : null);
                    $msg = 'success:Request ' . ($decision === 'approve' ? 'given final approval. A Purchase Order can now be issued.' : 'rejected') . '.';
                } else {
                    $msg = 'error:This request was already decided or changed — refresh and try again.';
                }
            }
        }
    }
}

// ── Queue data (cross-branch) ───────────────────────────────────────
$stmt = mysqli_prepare($conn,
    'SELECT r.request_id, r.requested_by_name, r.notes, r.created_at, r.branch_id, b.branch_name,
            r.estimated_cost, r.budget_status, r.quotation_reference, r.finance_notes
     FROM procurement_requests r
     LEFT JOIN branches b ON b.branch_id = r.branch_id
     WHERE r.status = ?
     ORDER BY r.created_at ASC'
);
$__status = PROC_STATUS_FINANCE_MANAGER_RECOMMEND;
mysqli_stmt_bind_param($stmt, 's', $__status);
mysqli_stmt_execute($stmt);
$queue = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

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
  <title>Owner Final Approval — Cloud Cup</title>
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
    .branch-tag{font-size:12px;color:var(--text-light);}
    .finance-box{background:#f7f8fa;border-radius:8px;padding:10px 14px;font-size:13px;margin:10px 0;display:flex;gap:20px;flex-wrap:wrap;}
    .finance-box b{color:var(--ink,#222);}
  </style>
</head>

<body>
  <script src="../js/sidebar-toggle.js"></script>
  <?php $active_page = 'proc-final'; require_once __DIR__ . '/Sidebar_Admin.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-left">
        <div class="topbar-title">Owner Final Approval — All Branches</div>
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
          No requests waiting for final approval.
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
            <span class="req-pill" style="background:#e7f1ff;color:#1a5fb4;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:600;">FINANCE MANAGER RECOMMENDED</span>
          </div>

          <?php if (!empty($r['notes'])): ?>
            <div style="font-size:13px;color:var(--text-light);margin-bottom:6px;">Requester note: <?= htmlspecialchars($r['notes']) ?></div>
          <?php endif; ?>

          <ul class="req-items">
            <?php foreach ($r['items'] as $it): ?>
              <li><?= htmlspecialchars($it['item_name']) ?> — <?= (float) $it['qty_requested'] + 0 ?> <?= htmlspecialchars($it['unit']) ?></li>
            <?php endforeach; ?>
          </ul>

          <div class="finance-box">
            <div>Est. Cost: <b><?= $r['estimated_cost'] !== null ? '₱' . number_format((float) $r['estimated_cost'], 2) : '—' ?></b></div>
            <div>Budget: <b><?= htmlspecialchars($BUDGET_LABELS[$r['budget_status']] ?? '—') ?></b></div>
            <div>Quotation Ref: <b><?= htmlspecialchars($r['quotation_reference'] ?: '—') ?></b></div>
            <div>Finance Note: <b><?= htmlspecialchars($r['finance_notes'] ?: '—') ?></b></div>
          </div>

          <form method="POST" action="" class="req-actions" onsubmit="return confirm('Confirm this decision? This is the final approval step.');">
            <input type="hidden" name="act" value="decide" />
            <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>" />
            <input type="text" name="note" maxlength="255" placeholder="Note (required for reject)" />
            <button type="submit" name="decision" value="approve" class="btn-save">Owner Final Approve</button>
            <button type="submit" name="decision" value="reject" class="btn-reject">Reject</button>
          </form>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <script>lucide.createIcons();</script>
</body>
</html>
