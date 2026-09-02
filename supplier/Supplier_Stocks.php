<?php
require_once __DIR__ . '/config.php';
$supplier_id = supplier_require_login();
$sid = (int)$supplier_id;
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim($_POST['product_name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $qty      = (float)($_POST['stock_quantity'] ?? 0);
        $unit     = trim($_POST['unit'] ?? '');
        $reorder  = (float)($_POST['reorder_level'] ?? 0);
        $lead     = (int)($_POST['lead_time_days'] ?? 0);
        $avail    = in_array($_POST['availability_status'] ?? '', ['available','limited','out_of_stock'], true) ? $_POST['availability_status'] : 'available';

        if ($name === '' || $unit === '') {
            $msg = 'error:Product name and unit are required.';
        } elseif ($id) {
            $stmt = mysqli_prepare($conn,
                "UPDATE supplier_products SET product_name=?, category=?, stock_quantity=?, unit=?, reorder_level=?, lead_time_days=?, availability_status=?
                  WHERE id=? AND supplier_id=?");
            mysqli_stmt_bind_param($stmt, 'ssdsdisii', $name, $category, $qty, $unit, $reorder, $lead, $avail, $id, $sid);
            mysqli_stmt_execute($stmt);
            supplier_log_activity($conn, $sid, 'stock_updated', 'stocks', "Updated $name (qty: $qty $unit)", (string)$id);
            $msg = 'success:Product updated.';
        } else {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO supplier_products (supplier_id, product_name, category, stock_quantity, unit, reorder_level, lead_time_days, availability_status)
                 VALUES (?,?,?,?,?,?,?,?)");
            mysqli_stmt_bind_param($stmt, 'issdsdis', $sid, $name, $category, $qty, $unit, $reorder, $lead, $avail);
            mysqli_stmt_execute($stmt);
            supplier_log_activity($conn, $sid, 'stock_added', 'stocks', "Added $name (qty: $qty $unit)", (string)mysqli_insert_id($conn));
            $msg = 'success:Product added.';
        }
    } elseif ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT product_name FROM supplier_products WHERE id=$id AND supplier_id=$sid"));
        if ($row) {
            mysqli_query($conn, "DELETE FROM supplier_products WHERE id=$id AND supplier_id=$sid");
            supplier_log_activity($conn, $sid, 'stock_removed', 'stocks', 'Removed ' . $row['product_name'], (string)$id);
            $msg = 'success:Product removed.';
        }
    }
}

$products = [];
$res = mysqli_query($conn, "SELECT * FROM supplier_products WHERE supplier_id = $sid ORDER BY product_name");
if ($res) while ($r = mysqli_fetch_assoc($res)) $products[] = $r;

$activePage = 'stocks';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>My Stock — Supplier Portal</title>
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
      <h1 class="page-title">My Stock</h1>
    </div>
    <button class="btn-primary" onclick="openProductModal()">+ Add Product</button>
  </div>

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>"><?= htmlspecialchars($mm) ?></div>
    <?php endif; ?>

    <div class="widget">
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Product</th><th>Category</th><th>Stock</th><th>Reorder Level</th><th>Lead Time</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
            <?php if (empty($products)): ?>
              <tr><td colspan="7" class="empty-state">No products listed yet.</td></tr>
            <?php else: foreach ($products as $p): ?>
              <tr>
                <td><?= htmlspecialchars($p['product_name']) ?></td>
                <td><?= htmlspecialchars($p['category'] ?? '—') ?></td>
                <td><?= $p['stock_quantity'] + 0 ?> <?= htmlspecialchars($p['unit']) ?></td>
                <td><?= $p['reorder_level'] + 0 ?> <?= htmlspecialchars($p['unit']) ?></td>
                <td><?= (int)$p['lead_time_days'] ?> day<?= (int)$p['lead_time_days'] === 1 ? '' : 's' ?></td>
                <td><span class="status-pill pill-<?= $p['availability_status'] ?>"><?= ucfirst(str_replace('_', ' ', $p['availability_status'])) ?></span></td>
                <td>
                  <div class="action-group">
                    <button class="btn-ghost btn-sm" onclick='openProductModal(<?= htmlspecialchars(json_encode($p)) ?>)'>Edit</button>
                    <form method="POST" class="delete-product-form" style="display:inline" data-name="<?= htmlspecialchars($p['product_name'], ENT_QUOTES) ?>">
                      <input type="hidden" name="act" value="delete">
                      <input type="hidden" name="id" value="<?= $p['id'] ?>">
                      <button type="submit" class="btn-ghost btn-sm danger">Remove</button>
                    </form>
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

<div class="modal-overlay" id="modal-product">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title" id="product-modal-title">Add Product</span>
      <button class="modal-close" onclick="closeModal('modal-product')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" id="p-id">
      <div class="form-group">
        <label>Product Name *</label>
        <input type="text" name="product_name" id="p-name" required maxlength="150">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Category</label>
          <input type="text" name="category" id="p-category" maxlength="100">
        </div>
        <div class="form-group">
          <label>Unit *</label>
          <input type="text" name="unit" id="p-unit" required maxlength="30" placeholder="e.g. kg, box, pcs">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Stock Quantity</label>
          <input type="number" step="0.01" min="0" name="stock_quantity" id="p-qty">
        </div>
        <div class="form-group">
          <label>Reorder Level</label>
          <input type="number" step="0.01" min="0" name="reorder_level" id="p-reorder">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Lead Time (days)</label>
          <input type="number" min="0" name="lead_time_days" id="p-lead">
        </div>
        <div class="form-group">
          <label>Availability</label>
          <select name="availability_status" id="p-avail">
            <option value="available">Available</option>
            <option value="limited">Limited</option>
            <option value="out_of_stock">Out of Stock</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" onclick="closeModal('modal-product')">Cancel</button>
        <button type="submit" class="btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function openProductModal(p) {
  document.getElementById('product-modal-title').textContent = p ? 'Edit Product' : 'Add Product';
  document.getElementById('p-id').value = p ? p.id : '';
  document.getElementById('p-name').value = p ? p.product_name : '';
  document.getElementById('p-category').value = p ? (p.category || '') : '';
  document.getElementById('p-unit').value = p ? p.unit : '';
  document.getElementById('p-qty').value = p ? p.stock_quantity : 0;
  document.getElementById('p-reorder').value = p ? p.reorder_level : 0;
  document.getElementById('p-lead').value = p ? p.lead_time_days : 0;
  document.getElementById('p-avail').value = p ? p.availability_status : 'available';
  document.getElementById('modal-product').classList.add('open');
}
document.querySelectorAll('.modal-overlay').forEach(function (m) {
  m.addEventListener('click', function (e) { if (e.target === m) m.classList.remove('open'); });
});
document.querySelectorAll('.delete-product-form').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    Swal.fire({
      title: 'Remove this product?',
      html: '<b>' + form.dataset.name + '</b> will be removed from your stock list.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, remove it',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#b8453a',
      reverseButtons: true
    }).then(function (result) { if (result.isConfirmed) form.submit(); });
  });
});
</script>
<script src="../js/lucide-init.js"></script>
</body>
</html>
