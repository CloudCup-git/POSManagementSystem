<?php
/**
 * Procurement — Branch Stock Check (C4)
 * -------------------------------------------------------------
 * Read-only. Inventory Staff only. Shows current stock for their own
 * branch (resolved server-side, never from $_SESSION directly) so they
 * know what's running low. No create/edit/delete here — restock requests
 * are filed by the Store Manager (manager/Purchase_Request_Form.php)
 * under the redesigned workflow, not by Inventory Staff.
 * -------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/procurement_auth.php';

// Hard gate: only Inventory Staff may view this page. Resolves role/
// position/branch fresh from the DB — a tampered session can't grant
// access or leak another branch's stock.
$user = procurement_require_stage([PROC_STAGE_INVENTORY_STAFF]);

if (!$conn) {
    http_response_code(503);
    die('Database connection unavailable. Please check that MySQL/MariaDB is running, then refresh this page.');
}

$branch_id = $user['branch_id'];
$full_name = $user['full_name'];

// Branch name for the page header — falls back gracefully if not found.
$branch_name = 'My Branch';
if ($branch_id !== null) {
    $stmt = mysqli_prepare($conn, 'SELECT branch_name FROM branches WHERE branch_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $branch_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);
    if ($row) $branch_name = $row['branch_name'];
}

// Stock for this branch only. Server-side parameterized query — branch_id
// comes from the DB-resolved user above, never from the browser.
$items = [];
if ($branch_id !== null) {
    $stmt = mysqli_prepare($conn,
        "SELECT inventory_id, item_name, category, unit, quantity, reorder_level, cost_per_unit
         FROM inventory
         WHERE branch_id = ? AND is_active = 1
         ORDER BY item_name ASC"
    );
    mysqli_stmt_bind_param($stmt, 'i', $branch_id);
    mysqli_stmt_execute($stmt);
    $items = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
}

function stock_status(float $qty, float $reorder): array {
    if ($qty == 0)        return ['s-out', '● Out of Stock'];
    if ($qty <= $reorder) return ['s-low', '● Low Stock'];
    return                       ['s-ok',  '● In Stock'];
}

$out_count = 0;
$low_count = 0;
foreach ($items as $it) {
    [$cls] = stock_status((float) $it['quantity'], (float) $it['reorder_level']);
    if ($cls === 's-out') $out_count++;
    elseif ($cls === 's-low') $low_count++;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Branch Stock Check — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../css/inventory_management.css" />
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
</head>

<body>
  <script src="../js/sidebar-toggle.js"></script>
  <?php $active_page = 'proc-stock'; require_once __DIR__ . '/Sidebar_Employee.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-left">
        <div class="topbar-title">Branch Stock — <?= htmlspecialchars($branch_name) ?></div>
      </div>
    </div>

    <div class="content">
      <div class="staff-banner">
        <i data-lucide="info"></i> <strong>Read-only:</strong> This shows current stock for your branch only. To request a restock, use Purchase Requests (coming soon).
      </div>

      <?php if ($out_count || $low_count): ?>
        <div class="alert-strip">
          <?php if ($out_count): ?>
            <div class="alert-card danger">
              <div class="alert-icon"><i data-lucide="circle-alert"></i></div>
              <div><strong><?= $out_count ?></strong> item<?= $out_count === 1 ? '' : 's' ?> out of stock</div>
            </div>
          <?php endif; ?>
          <?php if ($low_count): ?>
            <div class="alert-card warning">
              <div class="alert-icon"><i data-lucide="triangle-alert"></i></div>
              <div><strong><?= $low_count ?></strong> item<?= $low_count === 1 ? '' : 's' ?> low on stock</div>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Item</th>
              <th>Category</th>
              <th>Unit</th>
              <th>Stock</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($items)): ?>
              <tr>
                <td colspan="5" style="text-align:center;padding:40px;color:var(--text-light);">No inventory items found for this branch.</td>
              </tr>
            <?php else: foreach ($items as $item):
              [$s_class, $s_label] = stock_status((float) $item['quantity'], (float) $item['reorder_level']);
              $row_class = $s_class === 's-low' ? 'row-low' : ($s_class === 's-out' ? 'row-out' : '');
            ?>
              <tr class="<?= $row_class ?>">
                <td>
                  <div class="item-cell">
                    <div class="item-icon" style="background:rgba(59,130,192,0.08);color:var(--caramel)"><i data-lucide="package"></i></div>
                    <div>
                      <div class="item-name"><?= htmlspecialchars($item['item_name']) ?></div>
                      <div class="item-sku">ID-<?= str_pad((string) $item['inventory_id'], 3, '0', STR_PAD_LEFT) ?></div>
                    </div>
                  </div>
                </td>
                <td><span class="category-badge"><?= htmlspecialchars($item['category'] ?? '—') ?></span></td>
                <td><?= htmlspecialchars($item['unit']) ?></td>
                <td><span class="stock-num"><?= $item['quantity'] + 0 ?> <?= htmlspecialchars($item['unit']) ?></span></td>
                <td><span class="stock-status <?= $s_class ?>"><?= $s_label ?></span></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <script>lucide.createIcons();</script>
</body>
</html>
