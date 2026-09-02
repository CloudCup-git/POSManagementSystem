<?php
/**
 * CloudCup — Finance: Cash Flow Management
 * Page: Inventory Restocks (Restock Approvals)
 * -------------------------------------------------------------
 * Manager files a restock request from Inventory Management
 * (status='pending', stock NOT yet updated). Finance reviews it
 * here and either:
 *   - Approves  -> applies the quantity/cost update to `inventory`,
 *     writes the usual `inventory_log` entry, AND records the
 *     matching financial side: a cash expense (expense_type=
 *     'inventory_purchase', so it hits Cash on Hand but NOT Net
 *     Profit/OpEx — COGS still recognizes the cost later, on use)
 *     or an Accounts Payable liability if bought on credit.
 *   - Rejects   -> just marks it rejected, no stock/financial change.
 *
 * Finance-only (not Manager) — the whole point is a second set of
 * eyes on money leaving/owed by the business, so the requester
 * shouldn't also be the approver.
 * -------------------------------------------------------------
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/config.php'; // creates $pdo (PDO) + $conn (mysqli)

// $conn (mysqli) degrades to false on connection failure instead of throwing
// (see includes/DB_Connect.php) — every page using $conn must guard for it,
// and this page uses $conn directly below for the schema safety-net checks.
if (!$conn) {
    http_response_code(503);
    die('Database connection unavailable. Please check that MySQL/MariaDB is running and that the cloudcup_db database exists, then refresh this page.');
}

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role'] ?? ''), ['finance', 'admin'], true)) {
    header('Location: ../auth/Login_Page.php');
    exit;
}

$activePage  = 'cfm_restock';
$pageTitle   = 'Cash Flow Management — Inventory Restocks';
$rangeQuery  = '';
$currentUser = $_SESSION['full_name'] ?? 'Finance User';
$msg = '';

function money($n) { return '₱' . number_format((float) $n, 2); }

// Safety net: these tables/columns should already exist from the
// Inventory Management page, but this page can be the first thing that
// runs on a fresh install, so create them here too if missing.
$conn->query("CREATE TABLE IF NOT EXISTS restock_requests (
  request_id INT AUTO_INCREMENT PRIMARY KEY,
  inventory_id INT NOT NULL,
  item_name VARCHAR(150) NOT NULL,
  qty_added DECIMAL(10,2) NOT NULL,
  new_cost DECIMAL(10,2) NULL,
  supplier_name VARCHAR(150) NOT NULL,
  delivery_date DATE NOT NULL,
  payment_type ENUM('cash','credit') NOT NULL,
  note VARCHAR(200) NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  requested_by INT NOT NULL,
  requested_by_name VARCHAR(150) NOT NULL,
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT NULL,
  reviewed_by_name VARCHAR(150) NULL,
  reviewed_at TIMESTAMP NULL,
  review_note VARCHAR(200) NULL
)");
foreach ([
    'supplier_name' => "ALTER TABLE inventory_log ADD COLUMN supplier_name VARCHAR(150) NULL",
    'delivery_date' => "ALTER TABLE inventory_log ADD COLUMN delivery_date DATE NULL",
] as $col => $ddl) {
    $c = $conn->query("SHOW COLUMNS FROM inventory_log LIKE '$col'");
    if (!$c || $c->num_rows === 0) $conn->query($ddl);
}
// batch_id: groups requests filed together via Manager's "Bulk Restock" so
// they can be approved/rejected here as one submission instead of row by
// row. Same safety-net pattern as above — created by Inventory Management,
// but this page can technically run first on a fresh install.
$bc = $conn->query("SHOW COLUMNS FROM restock_requests LIKE 'batch_id'");
if (!$bc || $bc->num_rows === 0) {
    $conn->query("ALTER TABLE restock_requests ADD COLUMN batch_id VARCHAR(40) NULL, ADD INDEX idx_restock_batch_id (batch_id)");
}

/* -----------------------------------------------------------
   Approve ONE pending restock request row. No transaction handling in
   here — the caller (single-approve or approve-all-in-batch) wraps this
   in its own beginTransaction/commit so a batch is all-or-nothing.
   Returns the Finance-side note (cash expense / AP / no-cost-on-file)
   for that one row. Throws on any failure.
----------------------------------------------------------- */
function approveOneRestockRequest(PDO $pdo, array $req, int $reviewer_id, string $reviewer_name): string {
    $itemStmt = $pdo->prepare("SELECT quantity, cost_per_unit FROM inventory WHERE inventory_id = :id");
    $itemStmt->execute([':id' => $req['inventory_id']]);
    $item = $itemStmt->fetch();
    if (!$item) throw new Exception('"' . $req['item_name'] . '": that inventory item no longer exists.');

    $newQty   = (float) $item['quantity'] + (float) $req['qty_added'];
    $costUsed = $req['new_cost'] !== null
        ? (float) $req['new_cost']
        : ($item['cost_per_unit'] !== null ? (float) $item['cost_per_unit'] : null);

    $upd = $pdo->prepare("UPDATE inventory SET quantity = :qty, cost_per_unit = COALESCE(:cost, cost_per_unit) WHERE inventory_id = :id");
    $upd->execute([':qty' => $newQty, ':cost' => $req['new_cost'], ':id' => $req['inventory_id']]);

    $logNote = 'Restocked ' . $req['qty_added'] . ' ' . $req['item_name'] . ' via ' . $req['supplier_name']
        . ' (delivered ' . $req['delivery_date'] . ')'
        . ($req['new_cost'] !== null ? ', cost updated to ' . money($req['new_cost']) : '')
        . ($req['note'] ? ' — ' . $req['note'] : '')
        . ' (requested by ' . $req['requested_by_name'] . ', approved by ' . $reviewer_name . ')';

    $log = $pdo->prepare("INSERT INTO inventory_log (inventory_id, employee_id, change_type, qty_change, notes, supplier_name, delivery_date)
        VALUES (:inv, :emp, 'restock', :qty, :notes, :sup, :date)");
    $log->execute([
        ':inv' => $req['inventory_id'], ':emp' => $reviewer_id, ':qty' => $req['qty_added'],
        ':notes' => $logNote, ':sup' => $req['supplier_name'], ':date' => $req['delivery_date'],
    ]);

    $totalCost = $costUsed !== null ? round($costUsed * (float) $req['qty_added'], 2) : null;
    $finNote   = '';

    if ($totalCost !== null && $req['payment_type'] === 'cash') {
        // expense_type='inventory_purchase' (not 'operating') so this
        // reduces Cash on Hand but is excluded from Net Profit/OpEx —
        // COGS already recognizes the cost separately when the stock
        // is actually used, so counting it here too would double it up.
        $exp = $pdo->prepare("INSERT INTO operating_expenses
            (expense_type, category, description, amount, payment_method, expense_date, recorded_by)
            VALUES ('inventory_purchase', 'Inventory Purchase', :desc, :amt, 'cash', :date, :user)");
        $exp->execute([
            ':desc' => 'Restock: ' . $req['item_name'] . ' from ' . $req['supplier_name'],
            ':amt'  => $totalCost, ':date' => $req['delivery_date'], ':user' => $reviewer_id,
        ]);
        $finNote = ' Cash expense of ' . money($totalCost) . ' recorded.';
    } elseif ($totalCost !== null && $req['payment_type'] === 'credit') {
        $hasDesc = false;
        try { $pdo->query("SELECT description FROM liabilities LIMIT 1"); $hasDesc = true; } catch (Exception $e) { $hasDesc = false; }
        $dueDate = (new DateTime($req['delivery_date']))->modify('+30 days')->format('Y-m-d');
        if ($hasDesc) {
            $liab = $pdo->prepare("INSERT INTO liabilities (payee, liability_type, amount, status, due_date, description)
                VALUES (:payee, 'Accounts Payable', :amt, 'unpaid', :due, :desc)");
            $liab->execute([':payee' => $req['supplier_name'], ':amt' => $totalCost, ':due' => $dueDate, ':desc' => 'Restock: ' . $req['item_name']]);
        } else {
            $liab = $pdo->prepare("INSERT INTO liabilities (payee, liability_type, amount, status, due_date)
                VALUES (:payee, 'Accounts Payable', :amt, 'unpaid', :due)");
            $liab->execute([':payee' => $req['supplier_name'], ':amt' => $totalCost, ':due' => $dueDate]);
        }
        $finNote = ' Accounts Payable of ' . money($totalCost) . ' logged, due ' . $dueDate . '.';
    } elseif ($totalCost === null) {
        $finNote = ' No cost is on file for "' . $req['item_name'] . '" — no Finance entry was created for it, follow up separately.';
    }

    $updReq = $pdo->prepare("UPDATE restock_requests
        SET status = 'approved', reviewed_by = :rb, reviewed_by_name = :rbn, reviewed_at = NOW()
        WHERE request_id = :id");
    $updReq->execute([':rb' => $reviewer_id, ':rbn' => $reviewer_name, ':id' => $req['request_id']]);

    return $finNote;
}

/* -----------------------------------------------------------
   Approve a single pending restock request
----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'approve_restock') {
    $rid           = (int) ($_POST['request_id'] ?? 0);
    $reviewer_id   = (int) ($_SESSION['user_id'] ?? 0);
    $reviewer_name = $currentUser;

    $stmt = $pdo->prepare("SELECT * FROM restock_requests WHERE request_id = :id AND status = 'pending'");
    $stmt->execute([':id' => $rid]);
    $req = $stmt->fetch();

    if (!$req) {
        $msg = 'error:That request is no longer pending (already reviewed, or does not exist).';
    } else {
        try {
            $pdo->beginTransaction();
            $finNote = approveOneRestockRequest($pdo, $req, $reviewer_id, $reviewer_name);
            $pdo->commit();
            log_activity($conn, 'money', 'approved', 'restock_request', $rid, 'Approved inventory restock and recorded its financial impact.');
            $msg = 'success:Restock approved — stock updated.' . $finNote;
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = 'error:Could not approve this request: ' . $e->getMessage();
        }
    }
}

/* -----------------------------------------------------------
   Approve ALL pending requests in a batch (Manager's "Bulk Restock")
   in one go. All-or-nothing — if any single item in the batch fails,
   nothing in the batch is applied.
----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'approve_batch') {
    $batch_id      = trim($_POST['batch_id'] ?? '');
    $reviewer_id   = (int) ($_SESSION['user_id'] ?? 0);
    $reviewer_name = $currentUser;

    $stmt = $pdo->prepare("SELECT * FROM restock_requests WHERE batch_id = :bid AND status = 'pending' ORDER BY request_id ASC");
    $stmt->execute([':bid' => $batch_id]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        $msg = 'error:That batch has no pending requests left (already reviewed, or does not exist).';
    } else {
        try {
            $pdo->beginTransaction();
            $notes = [];
            foreach ($rows as $req) {
                $notes[] = approveOneRestockRequest($pdo, $req, $reviewer_id, $reviewer_name);
            }
            $pdo->commit();
            log_activity($conn, 'money', 'approved', 'restock_batch', $batch_id, 'Approved ' . count($rows) . ' inventory restock requests.');
            $n = count($rows);
            $msg = 'success:' . $n . ' restock request' . ($n > 1 ? 's' : '') . ' approved — stock updated for the whole batch.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = 'error:Could not approve this batch, nothing was applied: ' . $e->getMessage();
        }
    }
}

/* -----------------------------------------------------------
   Reject ALL pending requests in a batch
----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'reject_batch') {
    $batch_id = trim($_POST['batch_id'] ?? '');
    $note     = trim($_POST['review_note'] ?? '');
    $stmt = $pdo->prepare("UPDATE restock_requests
        SET status = 'rejected', reviewed_by = :rb, reviewed_by_name = :rbn, reviewed_at = NOW(), review_note = :note
        WHERE batch_id = :bid AND status = 'pending'");
    $stmt->execute([':rb' => $_SESSION['user_id'] ?? 0, ':rbn' => $currentUser, ':note' => $note !== '' ? $note : null, ':bid' => $batch_id]);
    $n = $stmt->rowCount();
    $msg = $n
        ? 'success:' . $n . ' restock request' . ($n > 1 ? 's' : '') . ' rejected. No changes were made to inventory.'
        : 'error:That batch has no pending requests left.';
}

/* -----------------------------------------------------------
   Reject a pending restock request
----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'reject_restock') {
    $rid  = (int) ($_POST['request_id'] ?? 0);
    $note = trim($_POST['review_note'] ?? '');
    $stmt = $pdo->prepare("UPDATE restock_requests
        SET status = 'rejected', reviewed_by = :rb, reviewed_by_name = :rbn, reviewed_at = NOW(), review_note = :note
        WHERE request_id = :id AND status = 'pending'");
    $stmt->execute([':rb' => $_SESSION['user_id'] ?? 0, ':rbn' => $currentUser, ':note' => $note !== '' ? $note : null, ':id' => $rid]);
    $msg = $stmt->rowCount()
        ? 'success:Restock request rejected. No changes were made to inventory.'
        : 'error:That request is no longer pending.';
}

/* -----------------------------------------------------------
   Data for the page
----------------------------------------------------------- */
$pendingRows = $pdo->query("SELECT * FROM restock_requests WHERE status = 'pending' ORDER BY requested_at ASC")->fetchAll();
$recentReviewedRows = $pdo->query("SELECT * FROM restock_requests WHERE status IN ('approved','rejected') ORDER BY reviewed_at DESC LIMIT 10")->fetchAll();

$pendingCashTotal = 0.0;
foreach ($pendingRows as $p) {
    // best-effort estimate for the KPI card only — actual amount is
    // computed at approval time using the live cost_per_unit if new_cost
    // wasn't given.
    if ($p['new_cost'] !== null) $pendingCashTotal += (float) $p['new_cost'] * (float) $p['qty_added'];
}

// Group pending rows into batches — everything sharing a batch_id (filed
// together via Manager's "Bulk Restock") becomes one card with an
// Approve All / Reject All pair; a request filed one-at-a-time (no
// batch_id) just becomes its own single-item "batch" so the same card
// markup handles both cases.
$pendingBatches = [];
foreach ($pendingRows as $r) {
    $key = $r['batch_id'] !== null && $r['batch_id'] !== '' ? $r['batch_id'] : ('single_' . $r['request_id']);
    if (!isset($pendingBatches[$key])) {
        $pendingBatches[$key] = [
            'batch_id'     => $r['batch_id'],
            'is_batch'     => $r['batch_id'] !== null && $r['batch_id'] !== '',
            'supplier'     => $r['supplier_name'],
            'requested_by' => $r['requested_by_name'],
            'requested_at' => $r['requested_at'],
            'items'        => [],
        ];
    }
    $pendingBatches[$key]['items'][] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<script src="../js/sidebar-toggle.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inventory Restocks — CloudCup Finance</title>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="css/finance.css">
<style>
  .status-pill{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
  .pill-pending{background:#f4e3d3;color:#b8703f;}
  .pill-approved{background:#E6F4EA;color:#2f6f4e;}
  .pill-rejected{background:#FDE8E8;color:#C0392B;}

  /* Batch grouping cards */
  .batch-card{border:1px solid var(--border,#e9e3d8);border-radius:12px;margin-bottom:14px;overflow:hidden;}
  .batch-card:last-child{margin-bottom:0;}
  .batch-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;background:#FAFAFA;border-bottom:1px solid var(--border,#e9e3d8);flex-wrap:wrap;}
  .batch-head-info{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
  .batch-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:#EAF1FB;color:#2C5FA8;}
  .batch-meta{font-size:12.5px;color:var(--muted-text,#6b6156);}
  .batch-actions{display:flex;gap:8px;}
  .batch-card table{margin:0;}
  .batch-card .panel-sub{display:none;}
</style>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

  <?php include __DIR__ . '/includes/finance_sidebar.php'; ?>

  <div class="main">
    <?php include __DIR__ . '/includes/finance_topbar.php'; ?>

    <div class="content">

      <div class="kpi-grid" style="margin-bottom:16px;">
        <div class="kpi-card">
          <div class="kpi-top">
            <div><div class="kpi-label">PENDING REQUESTS</div></div>
            <div class="kpi-icon icon-orange">!</div>
          </div>
          <div class="kpi-value"><?= count($pendingRows) ?></div>
          <div class="kpi-sub">Awaiting your review</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top">
            <div><div class="kpi-label">PENDING BATCHES</div></div>
            <div class="kpi-icon icon-orange">▤</div>
          </div>
          <div class="kpi-value"><?= count($pendingBatches) ?></div>
          <div class="kpi-sub">Submissions waiting on you</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top">
            <div><div class="kpi-label">EST. VALUE PENDING</div></div>
            <div class="kpi-icon icon-orange">₱</div>
          </div>
          <div class="kpi-value"><?= money($pendingCashTotal) ?></div>
          <div class="kpi-sub">Only counts requests with a cost entered</div>
        </div>
      </div>

      <div class="section-header"><h2>Restock Requests — Pending Approval</h2><div class="line"></div></div>
      <div class="panel">
        <div class="panel-sub">Filed by Manager from Inventory Management. Approving updates stock immediately and records the cash expense or payable — nothing changes until you act on it. Requests filed together as one batch are grouped below; use Approve All / Reject All to act on the whole batch, or approve items one at a time.</div>
        <?php if ($pendingBatches): foreach ($pendingBatches as $batchKey => $batch): ?>
          <div class="batch-card">
            <div class="batch-head">
              <div class="batch-head-info">
                <?php if ($batch['is_batch']): ?>
                  <span class="batch-badge">Batch · <?= count($batch['items']) ?> item<?= count($batch['items']) > 1 ? 's' : '' ?></span>
                <?php else: ?>
                  <span class="batch-badge" style="background:#faf8f4;color:#666;">Single request</span>
                <?php endif; ?>
                <span class="batch-meta"><?= htmlspecialchars($batch['supplier']) ?> · requested by <?= htmlspecialchars($batch['requested_by']) ?> · <?= (new DateTime($batch['requested_at']))->format('M d, Y g:ia') ?></span>
              </div>
              <?php if ($batch['is_batch']): ?>
              <div class="batch-actions">
                <form method="POST" class="js-approve-batch-form" style="display:inline;">
                  <input type="hidden" name="act" value="approve_batch">
                  <input type="hidden" name="batch_id" value="<?= htmlspecialchars($batch['batch_id']) ?>">
                  <button type="submit" class="btn btn-primary btn-sm">Approve All</button>
                </form>
                <button type="button" class="btn btn-ghost btn-sm js-reject-batch-btn" data-batch-id="<?= htmlspecialchars($batch['batch_id']) ?>">Reject All</button>
              </div>
              <?php endif; ?>
            </div>
            <table>
              <thead><tr><th>Item</th><th>Qty</th><th>Delivery</th><th>Payment</th><th style="text-align:right;">Cost/Unit</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($batch['items'] as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['item_name']) ?><?php if ($r['note']): ?><div class="muted" style="font-size:11.5px;"><?= htmlspecialchars($r['note']) ?></div><?php endif; ?></td>
                  <td class="num">+<?= $r['qty_added'] + 0 ?></td>
                  <td class="muted"><?= (new DateTime($r['delivery_date']))->format('M d, Y') ?></td>
                  <td><?= $r['payment_type'] === 'cash' ? 'Cash' : 'Credit' ?></td>
                  <td class="num"><?= $r['new_cost'] !== null ? money($r['new_cost']) : '<span class="muted">keep current</span>' ?></td>
                  <td style="white-space:nowrap;">
                    <form method="POST" class="js-approve-form" style="display:inline;">
                      <input type="hidden" name="act" value="approve_restock">
                      <input type="hidden" name="request_id" value="<?= $r['request_id'] ?>">
                      <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                    </form>
                    <button type="button" class="btn btn-ghost btn-sm js-reject-btn" data-id="<?= $r['request_id'] ?>">Reject</button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endforeach; else: ?>
          <div class="empty-state">No restock requests waiting for approval right now.</div>
        <?php endif; ?>
      </div>

      <div class="section-header" style="margin-top:24px;"><h2>Recently Reviewed</h2><div class="line"></div></div>
      <div class="panel">
        <?php if ($recentReviewedRows): ?>
        <table>
          <thead><tr><th>Item</th><th>Qty</th><th>Supplier</th><th>Payment</th><th>Status</th><th>Reviewed By</th><th>Reviewed</th></tr></thead>
          <tbody>
            <?php foreach ($recentReviewedRows as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['item_name']) ?></td>
              <td class="num">+<?= $r['qty_added'] + 0 ?></td>
              <td><?= htmlspecialchars($r['supplier_name']) ?></td>
              <td><?= $r['payment_type'] === 'cash' ? 'Cash' : 'Credit' ?></td>
              <td><span class="status-pill pill-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
              <td class="muted"><?= htmlspecialchars($r['reviewed_by_name'] ?? '—') ?></td>
              <td class="muted"><?= $r['reviewed_at'] ? (new DateTime($r['reviewed_at']))->format('M d, Y g:ia') : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <div class="empty-state">Nothing reviewed yet.</div>
        <?php endif; ?>
      </div>

    </div>
  </div>

<script>
document.querySelectorAll('.js-approve-form').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    Swal.fire({
      title: 'Approve this restock?',
      text: 'This updates the stock quantity immediately and records the matching cash expense or payable.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, approve',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#2f6f4e',
      reverseButtons: true
    }).then(function (result) {
      if (result.isConfirmed) form.submit();
    });
  });
});

document.querySelectorAll('.js-reject-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var id = btn.getAttribute('data-id');
    Swal.fire({
      title: 'Reject this request?',
      input: 'text',
      inputPlaceholder: 'Reason (optional)',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Reject',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#C0392B',
      reverseButtons: true
    }).then(function (result) {
      if (result.isConfirmed) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML =
          '<input type="hidden" name="act" value="reject_restock">' +
          '<input type="hidden" name="request_id" value="' + id + '">' +
          '<input type="hidden" name="review_note" value="' + (result.value || '').replace(/"/g, '&quot;') + '">';
        document.body.appendChild(form);
        form.submit();
      }
    });
  });
});

document.querySelectorAll('.js-approve-batch-form').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    Swal.fire({
      title: 'Approve this whole batch?',
      text: 'This updates stock for every item in the batch and records the matching cash expense or payable for each. All-or-nothing — if one item fails, nothing in the batch is applied.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, approve all',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#2f6f4e',
      reverseButtons: true
    }).then(function (result) {
      if (result.isConfirmed) form.submit();
    });
  });
});

document.querySelectorAll('.js-reject-batch-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var batchId = btn.getAttribute('data-batch-id');
    Swal.fire({
      title: 'Reject this whole batch?',
      input: 'text',
      inputPlaceholder: 'Reason (optional)',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Reject All',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#C0392B',
      reverseButtons: true
    }).then(function (result) {
      if (result.isConfirmed) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML =
          '<input type="hidden" name="act" value="reject_batch">' +
          '<input type="hidden" name="batch_id" value="' + batchId + '">' +
          '<input type="hidden" name="review_note" value="' + (result.value || '').replace(/"/g, '&quot;') + '">';
        document.body.appendChild(form);
        form.submit();
      }
    });
  });
});

<?php if ($msg): [$type, $text] = explode(':', $msg, 2); ?>
Swal.fire({
  icon: '<?= $type === 'success' ? 'success' : 'error' ?>',
  title: '<?= $type === 'success' ? 'Success' : 'Error' ?>',
  text: <?= json_encode($text) ?>,
  timer: 4000,
  timerProgressBar: true,
  toast: true,
  position: 'top-end',
  showConfirmButton: false
});
<?php endif; ?>
</script>
</body>
</html>