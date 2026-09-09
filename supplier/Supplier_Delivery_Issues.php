<?php
/**
 * Supplier Portal — Delivery Issues (supplier side of
 * manager/Discrepancy_Resolution_Page.php).
 * -------------------------------------------------------------
 * Lists open discrepancy threads on this supplier's purchase orders and
 * lets them reply — with a message and, optionally, which of the five
 * resolution actions they're actually taking. Ownership is enforced by
 * joining through procurement_purchase_orders.supplier_id = $sid, never
 * trusted from the browser. Replying does not change the request's
 * status — only the Store Manager's "Mark Resolved" does that (see
 * Discrepancy_Resolution_Page.php).
 * -------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/procurement_queries.php';

$supplier_id = supplier_require_login();
$sid = (int) $supplier_id;

$msg = '';
$ACTIONS = ['replacement' => 'Replacement', 'refund' => 'Refund', 'credit' => 'Store Credit', 'return' => 'Return Item', 'backorder' => 'Backorder'];

// ── POST: reply ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'reply') {
    $issue_id = (int) ($_POST['issue_id'] ?? 0);
    $message  = trim($_POST['message'] ?? '');
    $action   = $_POST['requested_action'] ?? '';
    $action   = array_key_exists($action, $ACTIONS) ? $action : null;

    $issue = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT i.issue_id, i.status, i.po_id
          FROM procurement_delivery_issues i
          JOIN procurement_purchase_orders po ON po.po_id = i.po_id
         WHERE i.issue_id = " . $issue_id . " AND po.supplier_id = " . $sid . " LIMIT 1"));

    if (!$issue) {
        $msg = 'error:Issue not found.';
    } elseif ($issue['status'] !== 'open') {
        $msg = 'error:This issue has already been resolved.';
    } elseif ($message === '') {
        $msg = 'error:Enter a reply message.';
    } else {
        $attachment_path = null;
        if (!empty($_FILES['attachment']['name'])) {
            $up = supplier_handle_upload($_FILES['attachment']);
            if (!$up['ok']) {
                $msg = 'error:' . $up['error'];
            } else {
                $attachment_path = $up['path'];
            }
        }

        if ($msg === '') {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO procurement_delivery_issue_messages
                    (issue_id, sender_type, sender_supplier_id, message, requested_action, attachment_path)
                 VALUES (?, 'supplier', ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt, 'iisss', $issue_id, $sid, $message, $action, $attachment_path);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            supplier_log_activity($conn, $sid, 'delivery_issue_replied', 'delivery_issues',
                'Replied to delivery issue #' . $issue_id, (string) $issue_id);
            $msg = 'success:Reply sent.';
        }
    }
}

// ── Data: open issues on this supplier's POs ─────────────────────────
$issues = [];
$res = mysqli_query($conn, "
    SELECT i.issue_id, i.request_id, i.po_id, i.created_at, po.po_number, b.branch_name
      FROM procurement_delivery_issues i
      JOIN procurement_purchase_orders po ON po.po_id = i.po_id
      LEFT JOIN branches b ON b.branch_id = po.branch_id
     WHERE po.supplier_id = $sid AND i.status = 'open'
     ORDER BY i.created_at ASC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $issues[] = $r;

foreach ($issues as &$i) {
    $mres = mysqli_query($conn, "SELECT sender_type, message, requested_action, attachment_path, created_at
        FROM procurement_delivery_issue_messages WHERE issue_id = " . (int) $i['issue_id'] . " ORDER BY created_at ASC");
    $i['thread'] = [];
    if ($mres) while ($m = mysqli_fetch_assoc($mres)) $i['thread'][] = $m;
}
unset($i);

$activePage = 'issues';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Delivery Issues — Supplier Portal</title>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="css/supplier.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    .issue-card{border:1px solid var(--hr-border,#e9e3d8);border-radius:10px;padding:16px;margin-bottom:14px;}
    .issue-card-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;}
    .thread{border:1px solid var(--hr-border,#e9e3d8);border-radius:8px;padding:10px 12px;margin:10px 0;background:#faf8f4;}
    .thread-msg{font-size:13px;padding:6px 0;border-bottom:1px solid #eee;}
    .thread-msg:last-child{border-bottom:none;}
    .thread-msg .who{font-weight:700;}
    .thread-msg.store_manager .who{color:#7a5b00;}
    .thread-msg.supplier .who{color:#1a5fb4;}
  </style>
</head>
<body>
<script src="../js/sidebar-toggle.js"></script>
<?php require_once __DIR__ . '/includes/supplier_sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle sidebar"><i data-lucide="menu"></i></button>
      <h1 class="page-title">Delivery Issues</h1>
    </div>
  </div>

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>"><?= htmlspecialchars($mm) ?></div>
    <?php endif; ?>

    <?php if (empty($issues)): ?>
      <div class="widget"><div class="table-wrap"><div class="empty-state" style="padding:40px;text-align:center;">No open delivery issues right now.</div></div></div>
    <?php else: foreach ($issues as $i): ?>
      <div class="issue-card">
        <div class="issue-card-head">
          <div><strong><?= htmlspecialchars($i['po_number'] ?? ('#' . $i['po_id'])) ?></strong> — REQ-<?= str_pad((string) $i['request_id'], 4, '0', STR_PAD_LEFT) ?></div>
          <span class="field-hint"><?= htmlspecialchars($i['branch_name'] ?? '') ?></span>
        </div>

        <div class="thread">
          <?php foreach ($i['thread'] as $m): ?>
            <div class="thread-msg <?= $m['sender_type'] ?>">
              <span class="who"><?= $m['sender_type'] === 'supplier' ? 'You' : 'Store Manager' ?></span>
              <?php if ($m['requested_action']): ?> — <b><?= htmlspecialchars($ACTIONS[$m['requested_action']] ?? $m['requested_action']) ?></b><?php endif; ?>
              <div><?= nl2br(htmlspecialchars($m['message'])) ?></div>
              <?php if ($m['attachment_path']): ?><a href="../<?= htmlspecialchars($m['attachment_path']) ?>" target="_blank" rel="noopener">Attachment →</a><?php endif; ?>
              <div class="field-hint"><?= date('M j, g:ia', strtotime($m['created_at'])) ?></div>
            </div>
          <?php endforeach; ?>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" onsubmit="return confirm('Send this reply?');">
          <input type="hidden" name="act" value="reply"/>
          <input type="hidden" name="issue_id" value="<?= (int) $i['issue_id'] ?>"/>
          <div class="form-group">
            <label>What are you doing about it? (optional)</label>
            <select name="requested_action">
              <option value="">— Not specifying —</option>
              <?php foreach ($ACTIONS as $k => $label): ?>
                <option value="<?= $k ?>"><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Message *</label>
            <textarea name="message" rows="2" required></textarea>
          </div>
          <div class="form-group">
            <label>Attachment (optional)</label>
            <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png"/>
          </div>
          <button type="submit" class="btn-primary">Send Reply</button>
        </form>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
<script src="../js/lucide-init.js"></script>
</body>
</html>
