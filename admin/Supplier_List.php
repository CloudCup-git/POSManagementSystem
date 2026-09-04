<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../auth/Login_Page.php');
    exit;
}
require_once __DIR__ . '/../includes/DB_Connect.php';
require_once __DIR__ . '/../includes/supplier_queries.php';
require_once __DIR__ . '/Permissions.php';
if ($conn) ensure_supplier_tables($conn);
$active_page = 'proc-suppliers';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'set_status') {
        $id     = (int)($_POST['supplier_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $accred = $_POST['accreditation_status'] ?? null;

        if ($id && in_array($status, ['pending','active','suspended','inactive'], true)) {
            if ($accred && in_array($accred, ['pending','accredited','rejected'], true)) {
                $stmt = mysqli_prepare($conn, "UPDATE suppliers SET status=?, accreditation_status=? WHERE supplier_id=?");
                mysqli_stmt_bind_param($stmt, 'ssi', $status, $accred, $id);
            } else {
                $stmt = mysqli_prepare($conn, "UPDATE suppliers SET status=? WHERE supplier_id=?");
                mysqli_stmt_bind_param($stmt, 'si', $status, $id);
            }
            mysqli_stmt_execute($stmt);
            // Suspending/deactivating the profile also locks the login immediately.
            $userActive = in_array($status, ['active','pending'], true) ? 1 : 0;
            mysqli_query($conn, "UPDATE users SET is_active = $userActive WHERE supplier_id = $id");
            $msg = 'success:Supplier status updated.';
        }
    } elseif ($act === 'delete') {
        $id = (int)($_POST['supplier_id'] ?? 0);
        if ($id) {
            mysqli_query($conn, "UPDATE users SET is_active = 0 WHERE supplier_id = $id");
            mysqli_query($conn, "DELETE FROM suppliers WHERE supplier_id = $id");
            $msg = 'success:Supplier deleted.';
        }
    }
}

$suppliers = [];
$res = mysqli_query($conn, "SELECT s.*, u.username FROM suppliers s LEFT JOIN users u ON u.supplier_id = s.supplier_id ORDER BY s.created_at DESC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $suppliers[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Suppliers — Cloud Cup Admin</title>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../supplier/css/supplier.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
<script src="../js/sidebar-toggle.js"></script><?php require __DIR__ . '/Sidebar_Admin.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle sidebar"><i data-lucide="menu"></i></button>
      <h1 class="page-title">Suppliers</h1>
    </div>
  </div>

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>"><?= htmlspecialchars($mm) ?></div>
    <?php endif; ?>

    <div class="widget">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Company</th><th>Code</th><th>Login</th><th>Contact</th><th>Categories</th><th>Account Status</th><th>Accreditation</th><th></th></tr></thead>
          <tbody>
            <?php if (empty($suppliers)): ?>
              <tr><td colspan="8" class="empty-state">No suppliers yet.</td></tr>
            <?php else: foreach ($suppliers as $s): ?>
              <tr>
                <td><?= htmlspecialchars($s['name']) ?></td>
                <td><?= htmlspecialchars($s['supplier_code'] ?? '—') ?></td>
                <td><?= $s['username'] ? '@' . htmlspecialchars($s['username']) : '—' ?></td>
                <td><?= htmlspecialchars($s['contact_person'] ?? '—') ?><?= $s['phone'] ? ' · ' . htmlspecialchars($s['phone']) : '' ?></td>
                <td><?= htmlspecialchars($s['product_categories'] ?? '—') ?></td>
                <td><span class="status-pill pill-<?= $s['status'] ?>"><?= ucfirst($s['status']) ?></span></td>
                <td><span class="status-pill pill-<?= $s['accreditation_status'] ?>"><?= ucfirst($s['accreditation_status']) ?></span></td>
                <td>
                  <div class="action-group">
                    <?php if ($s['status'] === 'pending'): ?>
                      <button class="btn-ghost btn-sm" onclick="setStatus(<?= $s['supplier_id'] ?>, 'active', 'accredited')">Approve</button>
                    <?php elseif ($s['status'] === 'active'): ?>
                      <button class="btn-ghost btn-sm danger" onclick="setStatus(<?= $s['supplier_id'] ?>, 'suspended')">Suspend</button>
                    <?php elseif ($s['status'] === 'suspended'): ?>
                      <button class="btn-ghost btn-sm" onclick="setStatus(<?= $s['supplier_id'] ?>, 'active')">Reactivate</button>
                    <?php endif; ?>
                    <button class="btn-ghost btn-sm danger" onclick="deleteSupplier(<?= $s['supplier_id'] ?>, '<?= htmlspecialchars(addslashes($s['name'])) ?>')">Delete</button>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<form method="POST" id="form-status" style="display:none">
  <input type="hidden" name="act" value="set_status">
  <input type="hidden" name="supplier_id" id="status-supplier-id">
  <input type="hidden" name="status" id="status-value">
  <input type="hidden" name="accreditation_status" id="status-accred-value">
</form>
<form method="POST" id="form-delete" style="display:none">
  <input type="hidden" name="act" value="delete">
  <input type="hidden" name="supplier_id" id="delete-supplier-id">
</form>

<script>
function setStatus(id, status, accred) {
  var label = status === 'active' ? 'approve/reactivate' : 'suspend';
  Swal.fire({
    title: 'Confirm',
    text: 'Are you sure you want to ' + label + ' this supplier?',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Yes',
    confirmButtonColor: '#b8703f',
    reverseButtons: true
  }).then(function (result) {
    if (result.isConfirmed) {
      document.getElementById('status-supplier-id').value = id;
      document.getElementById('status-value').value = status;
      document.getElementById('status-accred-value').value = accred || '';
      document.getElementById('form-status').submit();
    }
  });
}
function deleteSupplier(id, name) {
  Swal.fire({
    title: 'Delete supplier?',
    html: '<b>' + name + '</b> and all of their products/invoices/documents will be permanently removed. Their historical purchase orders stay on record.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Yes, delete it',
    confirmButtonColor: '#b8453a',
    reverseButtons: true
  }).then(function (result) {
    if (result.isConfirmed) {
      document.getElementById('delete-supplier-id').value = id;
      document.getElementById('form-delete').submit();
    }
  });
}
</script>
<script src="../js/lucide-init.js"></script>
</body>
</html>
