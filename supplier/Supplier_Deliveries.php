<?php
require_once __DIR__ . '/config.php';
$supplier_id = supplier_require_login();
$sid = (int)$supplier_id;
$msg = '';

$DELIVERY_STATUSES = ['pending','confirmed','preparing','in_transit','partially_delivered','delivered','delayed','cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'update_status') {
    $po_id  = (int)($_POST['po_id'] ?? 0);
    $status = $_POST['delivery_status'] ?? '';
    $ref    = trim($_POST['delivery_reference'] ?? '');

    if (!$po_id || !in_array($status, $DELIVERY_STATUSES, true)) {
        $msg = 'error:Invalid delivery status.';
    } else {
        $stmt = mysqli_prepare($conn,
            "UPDATE procurement_purchase_orders
                SET delivery_status = ?, delivery_status_updated_at = NOW(), delivery_reference = ?
              WHERE po_id = ? AND supplier_id = ?");
        mysqli_stmt_bind_param($stmt, 'ssii', $status, $ref, $po_id, $sid);
        mysqli_stmt_execute($stmt);
        if (mysqli_stmt_affected_rows($stmt) > 0) {
            $poRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT po_number FROM procurement_purchase_orders WHERE po_id = $po_id"));
            supplier_log_activity($conn, $sid, 'delivery_status_updated', 'deliveries',
                'Delivery status for ' . ($poRow['po_number'] ?? "#$po_id") . ' set to ' . $status, (string)$po_id);
            $msg = 'success:Delivery status updated.';
        } else {
            $msg = 'error:Could not find that purchase order.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'upload_proof') {
    $po_id = (int)($_POST['po_id'] ?? 0);
    $type  = $_POST['document_type'] ?? 'other';
    $desc  = trim($_POST['description'] ?? '');

    // Confirm the PO actually belongs to this supplier before touching the filesystem.
    $owns = $po_id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT po_number FROM procurement_purchase_orders WHERE po_id = $po_id AND supplier_id = $sid")) : null;
    if (!$owns) {
        $msg = 'error:Invalid purchase order.';
    } else {
        $upload = supplier_handle_upload($_FILES['proof_file'] ?? []);
        if (!$upload['ok']) {
            $msg = 'error:' . $upload['error'];
        } else {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO supplier_delivery_proofs (po_id, uploaded_by, file_path, original_filename, document_type, description)
                 VALUES (?,?,?,?,?,?)");
            $userId = (int)($_SESSION['user_id'] ?? 0);
            mysqli_stmt_bind_param($stmt, 'iissss', $po_id, $userId, $upload['path'], $upload['original'], $type, $desc);
            mysqli_stmt_execute($stmt);
            supplier_log_activity($conn, $sid, 'proof_uploaded', 'deliveries',
                'Uploaded ' . $type . ' for ' . $owns['po_number'], (string)$po_id);
            $msg = 'success:Document uploaded.';
        }
    }
}

$pos = [];
$res = mysqli_query($conn, "
    SELECT po.*, b.branch_name
      FROM procurement_purchase_orders po
      JOIN branches b ON b.branch_id = po.branch_id
     WHERE po.supplier_id = $sid AND po.supplier_response_status IN ('confirmed','accepted')
     ORDER BY (po.delivery_status = 'delivered') ASC, po.expected_delivery_date ASC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $pos[] = $r;

$proofs_by_po = [];
if ($pos) {
    $ids = implode(',', array_map('intval', array_column($pos, 'po_id')));
    $pres = mysqli_query($conn, "SELECT * FROM supplier_delivery_proofs WHERE po_id IN ($ids) ORDER BY uploaded_at DESC");
    if ($pres) while ($p = mysqli_fetch_assoc($pres)) $proofs_by_po[$p['po_id']][] = $p;
}

$activePage = 'deliveries';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Deliveries — Supplier Portal</title>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="css/supplier.css"/>
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
<script src="../js/sidebar-toggle.js"></script>
<?php require_once __DIR__ . '/includes/supplier_sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle sidebar"><i data-lucide="menu"></i></button>
      <h1 class="page-title">Deliveries</h1>
    </div>
  </div>

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>"><?= htmlspecialchars($mm) ?></div>
    <?php endif; ?>
    <div style="font-size:12px;color:var(--text-light);margin-bottom:14px">Only confirmed/accepted orders appear here — respond to a request first from <a href="Supplier_Requests.php">Requests &amp; POs</a>.</div>

    <?php if (empty($pos)): ?>
      <div class="widget"><div class="empty-state">No confirmed orders yet.</div></div>
    <?php else: foreach ($pos as $po): ?>
      <div class="widget">
        <div class="widget-header">
          <div>
            <div class="widget-title"><?= htmlspecialchars($po['po_number'] ?? ('#' . $po['po_id'])) ?> — <?= htmlspecialchars($po['branch_name']) ?></div>
            <div class="page-sub">Expected: <?= $po['expected_delivery_date'] ? date('M j, Y', strtotime($po['expected_delivery_date'])) : '—' ?></div>
          </div>
          <span class="status-pill pill-<?= $po['delivery_status'] ?>"><?= ucfirst(str_replace('_', ' ', $po['delivery_status'])) ?></span>
        </div>

        <div class="form-row">
          <form method="POST">
            <input type="hidden" name="act" value="update_status">
            <input type="hidden" name="po_id" value="<?= $po['po_id'] ?>">
            <div class="form-group">
              <label>Delivery Status</label>
              <select name="delivery_status">
                <?php foreach ($DELIVERY_STATUSES as $s): ?>
                  <option value="<?= $s ?>" <?= $po['delivery_status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Delivery Reference</label>
              <input type="text" name="delivery_reference" value="<?= htmlspecialchars($po['delivery_reference'] ?? '') ?>" placeholder="Tracking / DR number" maxlength="60">
            </div>
            <button type="submit" class="btn-primary">Save Status</button>
          </form>

          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="act" value="upload_proof">
            <input type="hidden" name="po_id" value="<?= $po['po_id'] ?>">
            <div class="form-group">
              <label>Document Type</label>
              <select name="document_type">
                <option value="receipt">Delivery Receipt</option>
                <option value="invoice">Invoice</option>
                <option value="photo">Photo</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="form-group">
              <label>File (PDF/JPG/PNG, max 5MB)</label>
              <input type="file" name="proof_file" accept=".pdf,.jpg,.jpeg,.png" required>
            </div>
            <div class="form-group">
              <label>Description</label>
              <input type="text" name="description" maxlength="200" placeholder="Optional note">
            </div>
            <button type="submit" class="btn-primary">Upload Proof</button>
          </form>
        </div>

        <?php $proofs = $proofs_by_po[$po['po_id']] ?? []; if ($proofs): ?>
          <div class="doc-list" style="margin-top:14px">
            <?php foreach ($proofs as $doc): ?>
              <div class="doc-row">
                <span><?= ucfirst($doc['document_type']) ?> — <?= htmlspecialchars($doc['original_filename']) ?><?= $doc['description'] ? ' (' . htmlspecialchars($doc['description']) . ')' : '' ?></span>
                <a class="btn-ghost btn-sm" href="../<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" rel="noopener">View</a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
<script src="../js/lucide-init.js"></script>
</body>
</html>
