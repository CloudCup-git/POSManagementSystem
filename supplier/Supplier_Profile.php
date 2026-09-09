<?php
require_once __DIR__ . '/config.php';
$supplier_id = supplier_require_login();
$sid = (int)$supplier_id;
$msg = '';

// Only the "soft" contact-detail fields are editable here. Company name,
// account status, and accreditation status are Procurement/Admin-controlled
// (see admin/Supplier_List.php) — a supplier can't self-approve or
// self-reactivate their own account.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'update_profile') {
    $contact  = trim($_POST['contact_person'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $categories = trim($_POST['product_categories'] ?? '');

    $stmt = mysqli_prepare($conn,
        "UPDATE suppliers SET contact_person=?, phone=?, email=?, address=?, product_categories=? WHERE supplier_id=?");
    mysqli_stmt_bind_param($stmt, 'sssssi', $contact, $phone, $email, $address, $categories, $sid);
    mysqli_stmt_execute($stmt);
    supplier_log_activity($conn, $sid, 'profile_updated', 'profile', 'Contact details updated.');
    $msg = 'success:Profile updated.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'upload_document') {
    $type = trim($_POST['document_type'] ?? 'Other');
    $upload = supplier_handle_upload($_FILES['document'] ?? []);
    if (!$upload['ok']) {
        $msg = 'error:' . $upload['error'];
    } else {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $stmt = mysqli_prepare($conn,
            "INSERT INTO supplier_documents (supplier_id, document_type, file_path, original_filename, uploaded_by) VALUES (?,?,?,?,?)");
        $path = $upload['path'];
        $original = $upload['original'];
        mysqli_stmt_bind_param($stmt, 'isssi', $sid, $type, $path, $original, $userId);
        mysqli_stmt_execute($stmt);
        supplier_log_activity($conn, $sid, 'document_uploaded', 'profile', "Uploaded document: $type");
        $msg = 'success:Document uploaded.';
    }
}

$sup = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM suppliers WHERE supplier_id = $sid"));

$documents = [];
$dres = mysqli_query($conn, "SELECT * FROM supplier_documents WHERE supplier_id = $sid ORDER BY uploaded_at DESC");
if ($dres) while ($d = mysqli_fetch_assoc($dres)) $documents[] = $d;

$activePage = 'profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Company Profile — Supplier Portal</title>
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
      <h1 class="page-title">Company Profile</h1>
    </div>
  </div>

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>"><?= htmlspecialchars($mm) ?></div>
    <?php endif; ?>

    <div class="widget">
      <div class="widget-header">
        <div class="widget-title">Company Details</div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Company Name</label><input type="text" value="<?= htmlspecialchars($sup['name']) ?>" disabled></div>
        <div class="form-group"><label>Supplier Code</label><input type="text" value="<?= htmlspecialchars($sup['supplier_code'] ?? '—') ?>" disabled></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Account Status</label><input type="text" value="<?= ucfirst($sup['status']) ?>" disabled></div>
        <div class="form-group"><label>Accreditation Status</label><input type="text" value="<?= ucfirst($sup['accreditation_status']) ?>" disabled></div>
      </div>
      <div class="field-hint" style="margin-bottom:16px">Company name and status fields are managed by Procurement/Admin — contact them to make changes.</div>

      <form method="POST">
        <input type="hidden" name="act" value="update_profile">
        <div class="form-row">
          <div class="form-group"><label>Contact Person</label><input type="text" name="contact_person" value="<?= htmlspecialchars($sup['contact_person'] ?? '') ?>" maxlength="150"></div>
          <div class="form-group"><label>Phone</label><input type="text" name="phone" value="<?= htmlspecialchars($sup['phone'] ?? '') ?>" maxlength="30"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($sup['email'] ?? '') ?>" maxlength="150"></div>
          <div class="form-group"><label>Product Categories</label><input type="text" name="product_categories" value="<?= htmlspecialchars($sup['product_categories'] ?? '') ?>" placeholder="e.g. Coffee Beans, Dairy" maxlength="255"></div>
        </div>
        <div class="form-group"><label>Address</label><textarea name="address" rows="2" maxlength="255"><?= htmlspecialchars($sup['address'] ?? '') ?></textarea></div>
        <button type="submit" class="btn-primary">Save Changes</button>
      </form>
    </div>

    <div class="widget">
      <div class="widget-header">
        <div class="widget-title">Business Documents</div>
      </div>
      <form method="POST" enctype="multipart/form-data" class="form-row" style="align-items:end;margin-bottom:16px">
        <input type="hidden" name="act" value="upload_document">
        <div class="form-group">
          <label>Document Type</label>
          <input type="text" name="document_type" placeholder="e.g. Business Permit, Accreditation Certificate" maxlength="60" required>
        </div>
        <div class="form-group">
          <label>File (PDF/JPG/PNG, max 5MB)</label>
          <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" required>
        </div>
        <button type="submit" class="btn-primary" style="height:fit-content">Upload</button>
      </form>
      <div class="doc-list">
        <?php if (empty($documents)): ?>
          <div class="empty-state">No documents uploaded yet.</div>
        <?php else: foreach ($documents as $doc): ?>
          <div class="doc-row">
            <span><?= htmlspecialchars($doc['document_type']) ?> — <?= htmlspecialchars($doc['original_filename']) ?></span>
            <a class="btn-ghost btn-sm" href="../<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" rel="noopener">View</a>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>
<script src="../js/lucide-init.js"></script>
</body>
</html>
