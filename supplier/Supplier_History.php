<?php
require_once __DIR__ . '/config.php';
$supplier_id = supplier_require_login();
$sid = (int)$supplier_id;

$pos = [];
$res = mysqli_query($conn, "
    SELECT po.*, b.branch_name,
           inv.invoice_number, inv.payment_status AS invoice_payment_status
      FROM procurement_purchase_orders po
      JOIN branches b ON b.branch_id = po.branch_id
 LEFT JOIN supplier_invoices inv ON inv.po_id = po.po_id
     WHERE po.supplier_id = $sid
     ORDER BY po.created_at DESC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $pos[] = $r;

$activePage = 'history';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>History — Supplier Portal</title>
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
      <h1 class="page-title">Full History</h1>
    </div>
  </div>

  <div class="content">
    <div class="widget">
      <div class="widget-header">
        <div class="widget-title">Every request, quotation, PO, delivery, and invoice on your account</div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>PO Number</th><th>Branch</th><th>Issued</th><th>Response</th><th>Delivery</th><th>Invoice</th><th>Payment</th></tr>
          </thead>
          <tbody>
            <?php if (empty($pos)): ?>
              <tr><td colspan="7" class="empty-state">No records yet.</td></tr>
            <?php else: foreach ($pos as $po): ?>
              <tr>
                <td><?= htmlspecialchars($po['po_number'] ?? ('#' . $po['po_id'])) ?></td>
                <td><?= htmlspecialchars($po['branch_name']) ?></td>
                <td><?= date('M j, Y', strtotime($po['created_at'])) ?></td>
                <td><span class="status-pill pill-<?= $po['supplier_response_status'] ?>"><?= ucfirst($po['supplier_response_status']) ?></span></td>
                <td><span class="status-pill pill-<?= $po['delivery_status'] ?>"><?= ucfirst(str_replace('_', ' ', $po['delivery_status'])) ?></span></td>
                <td><?= $po['invoice_number'] ? htmlspecialchars($po['invoice_number']) : '—' ?></td>
                <td><?= $po['invoice_payment_status'] ? '<span class="status-pill pill-' . $po['invoice_payment_status'] . '">' . ucfirst($po['invoice_payment_status']) . '</span>' : '—' ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script src="../js/lucide-init.js"></script>
</body>
</html>
