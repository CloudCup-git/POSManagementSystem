<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/procurement_rfq_queries.php';
$supplier_id = supplier_require_login();
ensure_procurement_rfq_tables($conn);

$sup = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM suppliers WHERE supplier_id = $supplier_id"));

function sup_count(mysqli $conn, string $where): int {
    $r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM procurement_purchase_orders WHERE $where");
    return $r ? (int)(mysqli_fetch_assoc($r)['c'] ?? 0) : 0;
}

$sid = (int)$supplier_id;
$pending_requests   = sup_count($conn, "supplier_id = $sid AND supplier_response_status = 'pending'");
$awaiting_confirm   = sup_count($conn, "supplier_id = $sid AND supplier_response_status = 'revised'");
$active_pos         = sup_count($conn, "supplier_id = $sid AND delivery_status NOT IN ('delivered','cancelled')");
$in_transit         = sup_count($conn, "supplier_id = $sid AND delivery_status = 'in_transit'");
$delayed            = sup_count($conn, "supplier_id = $sid AND delivery_status = 'delayed'");
$completed          = sup_count($conn, "supplier_id = $sid AND delivery_status = 'delivered'");

$inv_res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM supplier_invoices WHERE supplier_id = $sid AND payment_status IN ('unpaid','pending')");
$pending_invoices = $inv_res ? (int)(mysqli_fetch_assoc($inv_res)['c'] ?? 0) : 0;

$stock_res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM supplier_products WHERE supplier_id = $sid AND availability_status IN ('limited','out_of_stock')");
$low_stock = $stock_res ? (int)(mysqli_fetch_assoc($stock_res)['c'] ?? 0) : 0;

$rfq_res = mysqli_query($conn, "
    SELECT COUNT(*) AS c FROM procurement_rfq_suppliers rs
      JOIN procurement_rfqs f ON f.rfq_id = rs.rfq_id
     WHERE rs.supplier_id = $sid AND f.status = 'open' AND rs.status = 'invited'");
$open_rfqs = $rfq_res ? (int)(mysqli_fetch_assoc($rfq_res)['c'] ?? 0) : 0;

$activity = [];
$act_res = mysqli_query($conn, "SELECT * FROM supplier_activity_logs WHERE supplier_id = $sid ORDER BY created_at DESC LIMIT 8");
if ($act_res) while ($r = mysqli_fetch_assoc($act_res)) $activity[] = $r;

$branch_summary = [];
$bs_res = mysqli_query($conn, "
    SELECT b.branch_name, COUNT(*) AS total,
           SUM(po.delivery_status IN ('in_transit','preparing','confirmed')) AS in_progress,
           SUM(po.delivery_status = 'delayed') AS delayed_count,
           SUM(po.delivery_status = 'delivered') AS delivered_count
      FROM procurement_purchase_orders po
      JOIN branches b ON b.branch_id = po.branch_id
     WHERE po.supplier_id = $sid
  GROUP BY b.branch_id, b.branch_name
  ORDER BY b.branch_name");
if ($bs_res) while ($r = mysqli_fetch_assoc($bs_res)) $branch_summary[] = $r;

$activePage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dashboard — Supplier Portal</title>
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
      <h1 class="page-title">Welcome, <?= htmlspecialchars($sup['name'] ?? 'Supplier') ?></h1>
    </div>
    <div class="topbar-right"><span class="page-sub"><?= date('F j, Y') ?></span></div>
  </div>

  <div class="content">
    <?php if (($sup['status'] ?? '') === 'pending'): ?>
      <div class="msg-banner error">Your account is still pending approval. Some actions may be limited until Procurement/Admin activates it.</div>
    <?php elseif (($sup['status'] ?? '') === 'suspended'): ?>
      <div class="msg-banner error">Your account has been suspended. Contact Procurement/Admin for details.</div>
    <?php endif; ?>

    <div class="kpi-grid">
      <div class="kpi-card"><div class="kpi-label">Open RFQs</div><div class="kpi-value"><?= $open_rfqs ?></div></div>
      <div class="kpi-card"><div class="kpi-label">Pending Requests</div><div class="kpi-value"><?= $pending_requests ?></div></div>
      <div class="kpi-card"><div class="kpi-label">Awaiting Confirmation</div><div class="kpi-value"><?= $awaiting_confirm ?></div></div>
      <div class="kpi-card"><div class="kpi-label">Active Purchase Orders</div><div class="kpi-value"><?= $active_pos ?></div></div>
      <div class="kpi-card"><div class="kpi-label">In Transit</div><div class="kpi-value"><?= $in_transit ?></div></div>
      <div class="kpi-card warn"><div class="kpi-label">Delayed Deliveries</div><div class="kpi-value"><?= $delayed ?></div></div>
      <div class="kpi-card success"><div class="kpi-label">Completed Deliveries</div><div class="kpi-value"><?= $completed ?></div></div>
      <div class="kpi-card"><div class="kpi-label">Pending Invoices</div><div class="kpi-value"><?= $pending_invoices ?></div></div>
      <div class="kpi-card danger"><div class="kpi-label">Low-Stock Items</div><div class="kpi-value"><?= $low_stock ?></div></div>
    </div>

    <div class="widget">
      <div class="widget-header">
        <div class="widget-title">Branch Delivery Summary</div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Branch</th><th>Total POs</th><th>In Progress</th><th>Delayed</th><th>Delivered</th></tr></thead>
          <tbody>
            <?php if (empty($branch_summary)): ?>
              <tr><td colspan="5" class="empty-state">No purchase orders assigned to any branch yet.</td></tr>
            <?php else: foreach ($branch_summary as $b): ?>
              <tr>
                <td><?= htmlspecialchars($b['branch_name']) ?></td>
                <td><?= (int)$b['total'] ?></td>
                <td><?= (int)$b['in_progress'] ?></td>
                <td><?= (int)$b['delayed_count'] ?></td>
                <td><?= (int)$b['delivered_count'] ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="widget">
      <div class="widget-header">
        <div class="widget-title">Recent Activity</div>
        <a href="Supplier_Activity_Log.php" class="btn-ghost btn-sm">View all</a>
      </div>
      <div class="activity-list">
        <?php if (empty($activity)): ?>
          <div class="empty-state">No activity recorded yet.</div>
        <?php else: foreach ($activity as $a): ?>
          <div class="activity-row">
            <span class="activity-time"><?= date('M j, g:ia', strtotime($a['created_at'])) ?></span>
            <span class="activity-desc"><?= htmlspecialchars($a['description']) ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>
<script src="../js/lucide-init.js"></script>
</body>
</html>
