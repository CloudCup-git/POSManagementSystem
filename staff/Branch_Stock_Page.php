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
require_once __DIR__ . '/../includes/procurement_queries.php'; // stock_alerts table + dedup helper

// Hard gate: only Inventory Staff may view this page. Resolves role/
// position/branch fresh from the DB — a tampered session can't grant
// access or leak another branch's stock.
$user = procurement_require_stage([PROC_STAGE_INVENTORY_STAFF]);

if (!$conn) {
    http_response_code(503);
    die('Database connection unavailable. Please check that MySQL/MariaDB is running, then refresh this page.');
}

ensure_stock_alerts_table($conn);

$branch_id = $user['branch_id'];
$user_id   = $user['user_id'];
$full_name = $user['full_name'];

// ── Flag for Restock — traditional POST-reloads-page, matching this
// page's existing style (no AJAX/fetch elsewhere on this page). Branch
// ownership is re-verified server-side (WHERE inventory_id=? AND
// branch_id=? using the DB-resolved $branch_id above) and quantity/
// reorder_level/item_name are re-read fresh rather than trusting anything
// the client posted, same convention as this file's read-only query below.
$msg = '';
if ($branch_id !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'flag_restock') {
    $inv_id = (int) ($_POST['inventory_id'] ?? 0);

    if (!$inv_id) {
        $msg = 'error:Invalid item.';
    } else {
        $stmt = mysqli_prepare($conn,
            'SELECT item_name, quantity, reorder_level FROM inventory
             WHERE inventory_id = ? AND branch_id = ? AND is_active = 1 LIMIT 1'
        );
        mysqli_stmt_bind_param($stmt, 'ii', $inv_id, $branch_id);
        mysqli_stmt_execute($stmt);
        $item = mysqli_stmt_get_result($stmt)->fetch_assoc();
        mysqli_stmt_close($stmt);

        if (!$item) {
            $msg = 'error:Item not found in your branch.';
        } elseif (stock_alert_dedup_recent($conn, $inv_id)) {
            $msg = 'success:Manager has already been notified about "' . $item['item_name'] . '" in the last 6 hours.';
        } else {
            $stmt = mysqli_prepare($conn,
                'INSERT INTO stock_alerts (inventory_id, branch_id, item_name, quantity_at_report, reorder_level, reported_by, reporter_name)
                 VALUES (?,?,?,?,?,?,?)'
            );
            mysqli_stmt_bind_param($stmt, 'iisddis',
                $inv_id, $branch_id, $item['item_name'], $item['quantity'], $item['reorder_level'], $user_id, $full_name
            );
            if (mysqli_stmt_execute($stmt)) {
                $msg = 'success:Flagged "' . $item['item_name'] . '" for restock. Your Store Manager will see it in New Request.';
            } else {
                $msg = 'error:Something went wrong flagging this item — try again.';
            }
            mysqli_stmt_close($stmt);
        }
    }
}

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
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Branch Stock Check — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../css/admin_page.css" />
  <link rel="stylesheet" href="../css/inventory_management.css" />
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <style>
    .stock-flag-btn{background:var(--mc-amber-soft,#fff4e5);color:var(--mc-amber,#b45300);border:1px solid rgba(201,134,47,0.3);border-radius:8px;padding:6px 12px;font-size:12.5px;font-weight:600;cursor:pointer;white-space:nowrap;}
    .stock-flag-btn:hover{background:var(--mc-amber,#b45300);color:#fff;}
    .pr-none{color:var(--text-light,#9a9187);}
  </style>
</head>

<body>
  <script src="../js/sidebar-restore.js"></script>
  <?php $active_page = 'inv_stock'; require_once __DIR__ . '/../includes/Sidebar_Inventory_Staff.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-left">
        <div class="topbar-title">Branch Stock — <?= htmlspecialchars($branch_name) ?></div>
      </div>
    </div>

    <div class="content">
      <div class="staff-banner">
        <i data-lucide="info"></i> <strong>Read-only:</strong> This shows current stock for your branch only. Use "Flag for Restock" below on any low/out-of-stock item to notify your Store Manager — it will show up in their New Request tab.
      </div>

      <?php if ($msg !== ''): [$msg_type, $msg_text] = explode(':', $msg, 2); ?>
        <div class="alert-card <?= $msg_type === 'success' ? 'info' : 'danger' ?>" style="margin-bottom:16px;">
          <div class="alert-icon"><i data-lucide="<?= $msg_type === 'success' ? 'check-circle' : 'circle-alert' ?>"></i></div>
          <div><?= htmlspecialchars($msg_text) ?></div>
        </div>
      <?php endif; ?>

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
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($items)): ?>
              <tr>
                <td colspan="6" style="text-align:center;padding:40px;color:var(--text-light);">No inventory items found for this branch.</td>
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
                <td>
                  <?php if ($s_class === 's-low' || $s_class === 's-out'): ?>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="act" value="flag_restock" />
                      <input type="hidden" name="inventory_id" value="<?= (int) $item['inventory_id'] ?>" />
                      <button type="submit" class="stock-flag-btn">Flag for Restock</button>
                    </form>
                  <?php else: ?>
                    <span class="pr-none">&mdash;</span>
                  <?php endif; ?>
                </td>
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
