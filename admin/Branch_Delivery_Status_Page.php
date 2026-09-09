<?php
require_once __DIR__ . '/../includes/procurement_auth.php';
require_once __DIR__ . '/../includes/supplier_queries.php';
require_once __DIR__ . '/Permissions.php';

$user = procurement_require_stage([PROC_STAGE_STORE_MANAGER, PROC_STAGE_AREA_OPS_MANAGER, PROC_STAGE_ADMIN]);
if ($conn) ensure_supplier_tables($conn);

// Store Manager only sees their own branch; Area/Ops and Admin see everything —
// same scoping rule already used across the rest of the procurement module
// (see manager/Approval_Queue.php).
$branch_filter = '';
if ($user['stage'] === PROC_STAGE_STORE_MANAGER) {
    $branch_filter = 'AND po.branch_id = ' . (int)$user['branch_id'];
}

$rows = [];
$res = mysqli_query($conn, "
    SELECT po.po_id, po.po_number, po.branch_id, b.branch_name, b.branch_code,
           po.delivery_reference, po.delivery_status, po.expected_delivery_date,
           po.delivery_status_updated_at, po.supplier_response_remarks, po.supplier_name,
           s.name AS linked_supplier_name
      FROM procurement_purchase_orders po
      JOIN branches b ON b.branch_id = po.branch_id
 LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id
     WHERE 1=1 $branch_filter
     ORDER BY po.created_at DESC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;

$po_ids = array_column($rows, 'po_id');
$requested = [];   // po_id -> "item (qty unit), ..."
$requested_qty = []; // po_id -> total requested qty
$delivered_qty = []; // po_id -> total delivered qty
$receiver = [];      // po_id -> last receiver name
$actual_date = [];   // po_id -> last receipt date

if ($po_ids) {
    $ids = implode(',', array_map('intval', $po_ids));

    $ires = mysqli_query($conn, "SELECT po_id, item_name, unit, qty_ordered FROM procurement_po_items WHERE po_id IN ($ids)");
    if ($ires) while ($i = mysqli_fetch_assoc($ires)) {
        $requested[$i['po_id']][] = $i['item_name'] . ' (' . ($i['qty_ordered'] + 0) . ' ' . $i['unit'] . ')';
        $requested_qty[$i['po_id']] = ($requested_qty[$i['po_id']] ?? 0) + (float)$i['qty_ordered'];
    }

    $rres = mysqli_query($conn, "
        SELECT r.po_id, r.received_by_name, r.created_at, SUM(ri.qty_received) AS qty
          FROM procurement_receipts r
          JOIN procurement_receipt_items ri ON ri.receipt_id = r.receipt_id
         WHERE r.po_id IN ($ids)
      GROUP BY r.po_id");
    if ($rres) {
        while ($row = mysqli_fetch_assoc($rres)) {
            $delivered_qty[$row['po_id']] = (float)$row['qty'];
            $receiver[$row['po_id']] = $row['received_by_name'];
            $actual_date[$row['po_id']] = $row['created_at'];
        }
    }
}

function receiving_status_for(?float $requestedQty, ?float $deliveredQty): string {
    if ($deliveredQty === null) return 'Pending Verification';
    if ($requestedQty !== null && $deliveredQty >= $requestedQty) return 'Received';
    return 'Partially Received';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Branch Delivery Status</title>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../supplier/css/supplier.css"/>
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    /* ── Table layout fix ────────────────────────────────── */
    .table-wrap{
      overflow-x:auto;
    }
    .table-wrap table{
      table-layout:fixed;
      width:100%;
      min-width:1500px;
      border-collapse:separate;
      border-spacing:0;
    }
    .table-wrap table th,
    .table-wrap table td{
      padding:14px 16px;
      vertical-align:top;
      word-wrap:break-word;
      overflow-wrap:break-word;
    }
    .table-wrap table th{
      white-space:normal;
      line-height:1.3;
    }

    /* ── Table rows ───────────────────────────────────────── */
    .table-wrap table tbody tr{
      transition:background-color .18s ease;
      animation:po-row-in .35s ease both;
    }
    .table-wrap table tbody tr:hover{
      background-color:#faf5ea;
    }
    .table-wrap table tbody tr:nth-child(1){ animation-delay:.02s; }
    .table-wrap table tbody tr:nth-child(2){ animation-delay:.06s; }
    .table-wrap table tbody tr:nth-child(3){ animation-delay:.10s; }
    .table-wrap table tbody tr:nth-child(4){ animation-delay:.14s; }
    .table-wrap table tbody tr:nth-child(5){ animation-delay:.18s; }
    .table-wrap table tbody tr:nth-child(6){ animation-delay:.22s; }
    .table-wrap table tbody tr:nth-child(n+7){ animation-delay:.24s; }
    @keyframes po-row-in{
      from{ opacity:0; transform:translateY(4px); }
      to{ opacity:1; transform:translateY(0); }
    }

    /* ── Status pills ─────────────────────────────────────── */
    .status-pill{
      display:inline-flex;
      align-items:center;
      border-radius:20px;
      padding:4px 12px;
      font-size:12px;
      font-weight:600;
      letter-spacing:.2px;
      transition:transform .15s ease, box-shadow .15s ease;
    }
    .table-wrap table tbody tr:hover .status-pill{
      transform:translateY(-1px);
    }
    .pill-pending{
      background:#fff4e5;
      color:#a15c00;
      animation:pill-pulse 2.6s ease-in-out infinite;
    }
    .pill-partially_delivered{
      background:#fdecd6;
      color:#b15a1f;
    }
    .pill-delivered{
      background:#e6f7ec;
      color:#1a7f45;
    }
    @keyframes pill-pulse{
      0%, 100% { box-shadow:0 0 0 0 rgba(161,92,0,0.16); }
      50%      { box-shadow:0 0 0 4px rgba(161,92,0,0); }
    }

    @media (prefers-reduced-motion: reduce){
      .table-wrap table tbody tr,
      .status-pill{
        animation:none !important;
        transition:none !important;
        transform:none !important;
      }
    }
  </style>
</head>
<body>
<script src="../js/sidebar-toggle.js"></script>
<?php
$active_page = 'proc-delivery';
if (strtolower($_SESSION['role'] ?? '') === 'admin') {
    require __DIR__ . '/Sidebar_Admin.php';
} else {
    require __DIR__ . '/../manager/Sidebar_Manager.php';
}
?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <h1 class="page-title">Branch Delivery Status</h1>
    </div>
  </div>

  <div class="content">
    <div class="widget">
      <div class="table-wrap">
        <table>
          <colgroup>
            <col style="width:110px">  <!-- Branch -->
            <col style="width:70px">   <!-- Code -->
            <col style="width:100px">  <!-- PO Number -->
            <col style="width:110px">  <!-- Delivery Ref -->
            <col style="width:140px">  <!-- Supplier -->
            <col style="width:260px">  <!-- Requested Items -->
            <col style="width:70px">   <!-- Req Qty -->
            <col style="width:90px">   <!-- Delivered Qty -->
            <col style="width:110px">  <!-- Delivery Status -->
            <col style="width:95px">   <!-- Expected -->
            <col style="width:95px">   <!-- Actual -->
            <col style="width:120px">  <!-- Receiver -->
            <col style="width:130px">  <!-- Receiving Status -->
            <col style="width:100px">  <!-- Remarks -->
          </colgroup>
          <thead>
            <tr>
              <th>Branch</th><th>Code</th><th>PO Number</th><th>Delivery Ref.</th><th>Supplier</th>
              <th>Requested Items</th><th>Req. Qty</th><th>Delivered Qty</th><th>Delivery Status</th>
              <th>Expected</th><th>Actual</th><th>Receiver</th><th>Receiving Status</th><th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="14" class="empty-state">No purchase orders found<?= $branch_filter ? ' for your branch' : '' ?>.</td></tr>
            <?php else: foreach ($rows as $r):
              $poId = $r['po_id'];
              $reqQty = $requested_qty[$poId] ?? null;
              $delQty = $delivered_qty[$poId] ?? null;
              $recvStatus = receiving_status_for($reqQty, $delQty);
              $recvClass = ['Received' => 'pill-delivered', 'Partially Received' => 'pill-partially_delivered', 'Pending Verification' => 'pill-pending'][$recvStatus];
            ?>
              <tr>
                <td><?= htmlspecialchars($r['branch_name']) ?></td>
                <td><?= htmlspecialchars($r['branch_code'] ?? '—') ?></td>
                <td><?= htmlspecialchars($r['po_number'] ?? ('#' . $poId)) ?></td>
                <td><?= htmlspecialchars($r['delivery_reference'] ?? '—') ?></td>
                <td><?= htmlspecialchars($r['linked_supplier_name'] ?? $r['supplier_name']) ?></td>
                <td><?= htmlspecialchars(implode(', ', $requested[$poId] ?? [])) ?: '—' ?></td>
                <td><?= $reqQty !== null ? $reqQty + 0 : '—' ?></td>
                <td><?= $delQty !== null ? $delQty + 0 : '—' ?></td>
                <td><span class="status-pill pill-<?= $r['delivery_status'] ?>"><?= ucfirst(str_replace('_', ' ', $r['delivery_status'])) ?></span></td>
                <td><?= $r['expected_delivery_date'] ? date('M j, Y', strtotime($r['expected_delivery_date'])) : '—' ?></td>
                <td><?= isset($actual_date[$poId]) ? date('M j, Y', strtotime($actual_date[$poId])) : '—' ?></td>
                <td><?= htmlspecialchars($receiver[$poId] ?? '—') ?></td>
                <td><span class="status-pill <?= $recvClass ?>"><?= $recvStatus ?></span></td>
                <td><?= htmlspecialchars($r['supplier_response_remarks'] ?? '—') ?></td>
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
