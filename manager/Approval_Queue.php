<?php
/**
 * Procurement — Store Manager Approval Queue (C7 + C8)
 * -------------------------------------------------------------
 * Store Manager only. Shows SUBMITTED requests for their own branch
 * (resolved server-side) with line-item details, and lets them
 * approve / reject / return each one. Every decision is re-verified
 * at action time: branch match, correct pending stage, not their own
 * request, and a single guarded UPDATE to block duplicate/concurrent
 * decisions. One audit row per decision.
 * -------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/procurement_queries.php';

$user = procurement_require_stage([PROC_STAGE_STORE_MANAGER]);

if (!$conn) {
    http_response_code(503);
    die('Database connection unavailable. Please check that MySQL/MariaDB is running, then refresh this page.');
}

ensure_procurement_tables($conn);

$branch_id = $user['branch_id'];
$user_id   = $user['user_id'];
$full_name = $user['full_name'];

$msg = '';

if (!$branch_id) {
    $msg = 'error:Your account has no branch assigned — this must be fixed before you can review requests.';
}

// ── POST: decide ─────────────────────────────────────────────────
if ($branch_id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'decide') {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $decision   = $_POST['decision'] ?? '';
    $note       = trim($_POST['note'] ?? '');
    if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

    if (!in_array($decision, ['approve', 'reject', 'return'], true)) {
        $msg = 'error:Invalid decision.';
    } else {
        $stmt = mysqli_prepare($conn,
            'SELECT request_id, branch_id, status, requested_by, last_actor_id FROM procurement_requests WHERE request_id = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($stmt, 'i', $request_id);
        mysqli_stmt_execute($stmt);
        $req = mysqli_stmt_get_result($stmt)->fetch_assoc();
        mysqli_stmt_close($stmt);

        if (!$req) {
            $msg = 'error:Request not found.';
        } elseif ((int) $req['branch_id'] !== $branch_id) {
            $msg = 'error:That request does not belong to your branch.';
        } elseif (procurement_required_stage($req['status']) !== PROC_STAGE_STORE_MANAGER) {
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
                     WHERE request_id = ? AND status = ? AND branch_id = ?'
                );
                mysqli_stmt_bind_param($upd, 'siisi', $next, $user_id, $request_id, $old_status, $branch_id);
                mysqli_stmt_execute($upd);
                $changed = mysqli_stmt_affected_rows($upd);
                mysqli_stmt_close($upd);

                if ($changed === 1) {
                    $action_label = ['approve' => 'store_manager_approved', 'reject' => 'rejected', 'return' => 'returned_for_revision'][$decision];
                    procurement_log_audit($conn, $request_id, $user_id, $full_name, $action_label, $old_status, $next, $note !== '' ? $note : null);
                    $msg = 'success:Request ' . ($decision === 'approve' ? 'approved' : ($decision === 'reject' ? 'rejected' : 'returned for revision')) . '.';
                } else {
                    $msg = 'error:This request was already decided or changed — refresh and try again.';
                }
            }
        }
    }
}

// ── Queue data ───────────────────────────────────────────────────
$queue = [];
if ($branch_id) {
    $stmt = mysqli_prepare($conn,
        'SELECT request_id, requested_by_name, notes, created_at
         FROM procurement_requests
         WHERE branch_id = ? AND status = ?
         ORDER BY created_at ASC'
    );
    $__status = PROC_STATUS_SUBMITTED;
    mysqli_stmt_bind_param($stmt, 'is', $branch_id, $__status);
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
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Purchase Approvals — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../css/admin_page.css" />
  <link rel="stylesheet" href="../css/inventory_management.css" />
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .req-card{border:1px solid var(--border,#e5e7eb);border-radius:10px;padding:16px;margin-bottom:14px;background:#fff;}
    .req-card-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;}
    .req-items{margin:10px 0;font-size:13px;color:var(--text-light);}
    .req-items li{margin-bottom:2px;}

    .req-actions{display:flex;gap:10px;align-items:center;margin-top:12px;flex-wrap:wrap;}

    .req-actions input[type=text]{
      flex:1;
      min-width:220px;
      padding:10px 14px;
      font-size:13px;
      font-family:inherit;
      color:#3a2e28;
      background:#faf8f6;
      border:1px solid #ddd3ca;
      border-radius:8px;
      outline:none;
      transition:border-color .15s ease, background .15s ease, box-shadow .15s ease;
    }
    .req-actions input[type=text]::placeholder{ color:#a89c90; }
    .req-actions input[type=text]:hover{ border-color:#c9bcae; }
    .req-actions input[type=text]:focus{
      background:#fff;
      border-color:#8a6d5c;
      box-shadow:0 0 0 3px rgba(138,109,92,0.15);
    }

    /* Shared base for all three decision buttons so they render as matching pills,
       differing only by color scheme — not by whether they happen to inherit
       padding/radius from an unrelated global class. */
    .req-actions button{
      appearance:none;
      font-family:inherit;
      font-size:13px;
      font-weight:600;
      line-height:1;
      padding:11px 18px;
      border-radius:8px;
      cursor:pointer;
      white-space:nowrap;
      transition:transform .04s ease, filter .15s ease, background .15s ease, box-shadow .15s ease;
    }
    .req-actions button:hover{ filter:brightness(0.97); }
    .req-actions button:active{ transform:translateY(1px); }
    .req-actions button:focus-visible{
      outline:none;
      box-shadow:0 0 0 3px rgba(0,0,0,0.15);
    }

    .btn-save{
      background:#2e2118;
      color:#fff;
      border:1px solid #2e2118;
    }
    .btn-save:hover{ background:#42301f; }

    .btn-return{
      background:#fff4e5;
      color:#b45300;
      border:1px solid #f0d6a8;
    }
    .btn-return:hover{ background:#fdecd2; }

    .btn-reject{
      background:#fdeaea;
      color:#c0392b;
      border:1px solid #f3c6c6;
    }
    .btn-reject:hover{ background:#fbdada; }

    @media (max-width:480px){
      .req-actions{ flex-direction:column; align-items:stretch; }
      .req-actions input[type=text]{ min-width:0; }
      .req-actions button{ width:100%; }
    }
  </style>
</head>

<body>
  <?php $active_page = 'proc-approval'; require_once __DIR__ . '/Sidebar_Manager.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-left">
        <div class="topbar-title">Purchase Approvals</div>
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

      <?php if ($branch_id): ?>
        <?php if (empty($queue)): ?>
          <div class="table-wrap" style="padding:40px;text-align:center;color:var(--text-light);">
            No requests waiting for your review.
          </div>
        <?php else: foreach ($queue as $r): ?>
          <div class="req-card">
            <div class="req-card-head">
              <div>
                <strong>REQ-<?= str_pad((string) $r['request_id'], 4, '0', STR_PAD_LEFT) ?></strong>
                — requested by <?= htmlspecialchars($r['requested_by_name']) ?>
                on <?= date('M d, Y', strtotime($r['created_at'])) ?>
              </div>
              <span class="req-pill" style="background:#e7f1ff;color:#1a5fb4;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:600;">SUBMITTED</span>
            </div>

            <?php if (!empty($r['notes'])): ?>
              <div style="font-size:13px;color:var(--text-light);margin-bottom:6px;">Requester note: <?= htmlspecialchars($r['notes']) ?></div>
            <?php endif; ?>

            <ul class="req-items">
              <?php foreach ($r['items'] as $it): ?>
                <li><?= htmlspecialchars($it['item_name']) ?> — <?= (float) $it['qty_requested'] + 0 ?> <?= htmlspecialchars($it['unit']) ?></li>
              <?php endforeach; ?>
            </ul>

            <form method="POST" action="" class="req-actions" data-approval-form>
              <input type="hidden" name="act" value="decide" />
              <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>" />
              <input type="text" name="note" maxlength="255" placeholder="Note (required for reject/return)" />
              <button type="button" data-decision="approve" class="btn-save">Approve</button>
              <button type="button" data-decision="return" class="btn-return">Return for Revision</button>
              <button type="button" data-decision="reject" class="btn-reject">Reject</button>
            </form>
          </div>
        <?php endforeach; endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <script>
    lucide.createIcons();

    const DECISION_META = {
      approve: {
        title: 'Approve this request?',
        icon: 'question',
        confirmText: 'Yes, approve it',
        confirmColor: '#2e2118',
        requiresNote: false
      },
      return: {
        title: 'Return for revision?',
        icon: 'warning',
        confirmText: 'Yes, return it',
        confirmColor: '#b45300',
        requiresNote: true
      },
      reject: {
        title: 'Reject this request?',
        icon: 'warning',
        confirmText: 'Yes, reject it',
        confirmColor: '#c0392b',
        requiresNote: true
      }
    };

    document.querySelectorAll('form[data-approval-form]').forEach(function (form) {
      const noteInput = form.querySelector('input[name="note"]');

      form.querySelectorAll('button[data-decision]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const decision = btn.dataset.decision;
          const meta = DECISION_META[decision];
          const note = noteInput.value.trim();

          if (meta.requiresNote && note === '') {
            Swal.fire({
              icon: 'error',
              title: 'Note required',
              text: 'Please add a note explaining why before ' + (decision === 'reject' ? 'rejecting' : 'returning') + ' this request.',
              confirmButtonColor: '#2e2118'
            });
            return;
          }

          Swal.fire({
            title: meta.title,
            text: note !== '' ? 'Note: ' + note : 'This action cannot be undone.',
            icon: meta.icon,
            showCancelButton: true,
            confirmButtonText: meta.confirmText,
            confirmButtonColor: meta.confirmColor,
            cancelButtonColor: '#999',
            reverseButtons: true
          }).then(function (result) {
            if (!result.isConfirmed) return;

            let hidden = form.querySelector('input[name="decision"]');
            if (!hidden) {
              hidden = document.createElement('input');
              hidden.type = 'hidden';
              hidden.name = 'decision';
              form.appendChild(hidden);
            }
            hidden.value = decision;
            form.submit();
          });
        });
      });
    });
  </script>
</body>
</html>
