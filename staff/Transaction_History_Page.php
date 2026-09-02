<?php
/**
 * CloudCup — My Transaction History (Staff)
 * -------------------------------------------------------------
 * Read-only view of a cashier's own past sales — the staff-scoped
 * counterpart to Admin's Sales_Records_Page.php. Same filters,
 * expandable item rows, and pagination pattern, but every query is
 * pinned to orders.employee_id = the logged-in employee, so staff
 * can only ever see transactions they personally rang up.
 */
session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$is_staff_session = isset($_SESSION['role'], $_SESSION['full_name'])
    && (isset($_SESSION['employee_id']) || isset($_SESSION['user_id']))
    && strtolower($_SESSION['role']) === 'employee';

// Same routing as Sales_Processing_Page.php: admins/managers already have
// the full Sales Records view in the Admin panel, and inventory_staff
// never had POS access — so neither role belongs on this page.
if (!$is_staff_session) {
    if (isset($_SESSION['role']) && in_array(strtolower($_SESSION['role']), ['admin', 'manager'], true)) {
        header('Location: ../admin/Sales_Records_Page.php');
    } elseif (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'inventory_staff') {
        header('Location: ../admin/Inventory_Management_Page.php');
    } else {
        header('Location: ../auth/Login_Page.php');
    }
    exit;
}

require_once __DIR__ . '/../includes/DB_Connect.php';

$employee_id = (int) ($_SESSION['employee_id'] ?? $_SESSION['user_id']);
$full_name   = $_SESSION['full_name'] ?? 'Employee';
$active_page = 'emp_history';

// ── FILTERS + QUERY ─────────────────────────────────────────────
$sales       = [];
$sales_total = 0;
$total_pages = 1;
$page        = max(1, (int) ($_GET['page'] ?? 1));
$per_page    = 20;
$date_from   = trim($_GET['date_from'] ?? '');
$date_to     = trim($_GET['date_to']   ?? '');
$method      = trim($_GET['method']    ?? '');
$search      = trim($_GET['q']         ?? '');
$stats       = [];

if ($conn) {
    // employee_id is always the logged-in staff's own int id — every
    // other filter is user input, so those still go through
    // mysqli_real_escape_string same as Admin's Sales_Records_Page.php.
    $where = "o.employee_id = " . $employee_id;
    if ($date_from !== '') $where .= " AND DATE(o.ordered_at) >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
    if ($date_to   !== '') $where .= " AND DATE(o.ordered_at) <= '" . mysqli_real_escape_string($conn, $date_to)   . "'";
    if ($method    !== '') $where .= " AND o.payment_method = '"    . mysqli_real_escape_string($conn, $method)    . "'";
    if ($search    !== '') $where .= " AND o.order_id LIKE '%" . mysqli_real_escape_string($conn, $search) . "%'";

    $count_res   = mysqli_query($conn, "SELECT COUNT(*) AS c FROM orders o WHERE $where");
    $sales_total = (int) (mysqli_fetch_assoc($count_res)['c'] ?? 0);
    $total_pages = max(1, (int) ceil($sales_total / $per_page));
    $page        = min($page, $total_pages);
    $offset      = ($page - 1) * $per_page;

    $res = mysqli_query($conn,
        "SELECT o.order_id, o.total_amount, o.payment_method, o.amount_tendered,
                o.change_due, o.status, o.order_type, o.notes, o.ordered_at
         FROM orders o
         WHERE $where
         ORDER BY o.ordered_at DESC
         LIMIT $per_page OFFSET $offset");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $items_res = mysqli_query($conn,
                "SELECT item_name, quantity, unit_price, subtotal
                 FROM order_items WHERE order_id = " . (int) $r['order_id']);
            $r['items'] = [];
            if ($items_res) while ($it = mysqli_fetch_assoc($items_res)) $r['items'][] = $it;
            $sales[] = $r;
        }
    }

    // Summary stats — same shape as Admin's version, scoped to this employee.
    $stats_res = mysqli_query($conn,
        "SELECT COUNT(*) AS total_orders,
                COALESCE(SUM(total_amount),0) AS total_revenue,
                COALESCE(SUM(CASE WHEN payment_method='cash'  THEN total_amount ELSE 0 END),0) AS cash_total,
                COALESCE(SUM(CASE WHEN payment_method='gcash' THEN total_amount ELSE 0 END),0) AS gcash_total,
                COALESCE(SUM(CASE WHEN payment_method='maya'  THEN total_amount ELSE 0 END),0) AS maya_total,
                COALESCE(SUM(CASE WHEN payment_method='card'  THEN total_amount ELSE 0 END),0) AS card_total
         FROM orders o WHERE $where");
    $stats = mysqli_fetch_assoc($stats_res) ?? [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Transaction History — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/sales_processing.css"/>
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
</head>
<body>

<?php require_once __DIR__ . '/Sidebar_Employee.php'; ?>
<script src="../js/lucide-init.js"></script>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <h1>Transaction History</h1>
    </div>
    <div class="topbar-right">
      <div class="topbar-date"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:5px"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> <?= date('F j, Y') ?></div>
    </div>
  </div>

  <div class="content">

    <div class="widget">
      <div class="widget-header">
        <div class="widget-title">My Sales</div>
        <div style="font-size:12px;color:var(--text-light)">Only transactions you personally processed</div>
      </div>

      <?php if (!empty($stats)): ?>
      <div class="sales-stats-row">
        <div class="stat-card">
          <div class="stat-label">Total Orders</div>
          <div class="stat-value"><?= number_format((int) $stats['total_orders']) ?></div>
        </div>
        <div class="stat-card stat-card--revenue">
          <div class="stat-label">Total Sales</div>
          <div class="stat-value">₱<?= number_format((float) $stats['total_revenue'], 2) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Cash</div>
          <div class="stat-value">₱<?= number_format((float) $stats['cash_total'], 2) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">GCash</div>
          <div class="stat-value">₱<?= number_format((float) $stats['gcash_total'], 2) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Maya</div>
          <div class="stat-value">₱<?= number_format((float) $stats['maya_total'], 2) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Card</div>
          <div class="stat-value">₱<?= number_format((float) $stats['card_total'], 2) ?></div>
        </div>
      </div>
      <?php endif; ?>

      <!-- FILTER BAR -->
      <form method="GET" action="" class="sales-filter-bar">
        <div class="filter-group">
          <label>Search</label>
          <div class="search-wrap">
            <i data-lucide="search" style="width:14px;height:14px"></i>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Order #…"/>
          </div>
        </div>
        <div class="filter-group">
          <label>From</label>
          <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"/>
        </div>
        <div class="filter-group">
          <label>To</label>
          <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"/>
        </div>
        <div class="filter-group">
          <label>Payment</label>
          <select name="method">
            <option value="">All</option>
            <?php foreach (['cash', 'card', 'gcash', 'maya'] as $m): ?>
              <option value="<?= $m ?>" <?= $method === $m ? 'selected' : '' ?>><?= ucfirst($m) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-actions">
          <button type="submit" class="btn-filter">Filter</button>
          <a href="Transaction_History_Page.php" class="btn-clear">Clear</a>
        </div>
      </form>

      <!-- TRANSACTIONS TABLE -->
      <div class="sales-table-wrap">
        <?php if (empty($sales)): ?>
        <div style="text-align:center;padding:60px 20px;color:var(--text-light)">
          <i data-lucide="receipt" style="width:48px;height:48px"></i>
          <p style="margin-top:12px">No transactions found.</p>
        </div>
        <?php else: ?>
        <table class="sales-table">
          <thead>
            <tr>
              <th>Order #</th>
              <th>Date &amp; Time</th>
              <th>Items</th>
              <th>Type</th>
              <th>Payment</th>
              <th style="text-align:right">Total</th>
              <th style="text-align:right">Tendered</th>
              <th style="text-align:right">Change</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sales as $sale): $pm = $sale['payment_method'] ?: 'unspecified'; ?>
            <tr class="sales-row" onclick="toggleOrderItems(this)">
              <td><strong>#<?= (int) $sale['order_id'] ?></strong></td>
              <td><?= date('M d, Y g:i A', strtotime($sale['ordered_at'])) ?></td>
              <td class="items-preview">
                <?php
                  $names = array_map(fn($i) => $i['quantity'] . '× ' . $i['item_name'], $sale['items']);
                  echo htmlspecialchars(implode(', ', array_slice($names, 0, 3)));
                  if (count($names) > 3) echo ' <span class="more-badge">+' . (count($names) - 3) . ' more</span>';
                ?>
              </td>
              <td class="muted"><?= ucfirst(str_replace('_', ' ', $sale['order_type'])) ?></td>
              <td>
                <span class="pay-badge pay-badge--<?= htmlspecialchars($pm) ?>">
                  <?= strtoupper(htmlspecialchars($pm)) ?>
                </span>
              </td>
              <td style="text-align:right"><strong>₱<?= number_format((float) $sale['total_amount'], 2) ?></strong></td>
              <td style="text-align:right">₱<?= number_format((float) $sale['amount_tendered'], 2) ?></td>
              <td style="text-align:right">₱<?= number_format((float) $sale['change_due'], 2) ?></td>
              <td><span class="status-badge status-badge--<?= htmlspecialchars($sale['status']) ?>"><?= ucfirst(htmlspecialchars($sale['status'])) ?></span></td>
            </tr>
            <!-- Expandable items row -->
            <tr class="items-detail-row" style="display:none">
              <td colspan="9">
                <table class="items-detail-table">
                  <thead><tr><th>Item</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th></tr></thead>
                  <tbody>
                    <?php foreach ($sale['items'] as $it): ?>
                    <tr>
                      <td><?= htmlspecialchars($it['item_name']) ?></td>
                      <td><?= (int) $it['quantity'] ?></td>
                      <td>₱<?= number_format((float) $it['unit_price'], 2) ?></td>
                      <td>₱<?= number_format((float) $it['subtotal'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ($sale['notes']): ?>
                    <tr><td colspan="4" style="color:var(--text-light);font-style:italic">Note: <?= htmlspecialchars($sale['notes']) ?></td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <!-- PAGINATION -->
        <?php $start = ($page - 1) * $per_page + 1; $end = min($page * $per_page, $sales_total); ?>
        <div class="pagination">
          <div class="pagination-info">Showing <?= $start ?>–<?= $end ?> of <?= $sales_total ?> transactions</div>
          <div class="page-btns">
            <?php if ($page > 1): ?>
              <a class="page-btn" href="?page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&method=<?= urlencode($method) ?>">‹</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
              <a class="page-btn <?= $i === $page ? 'active' : '' ?>"
                 href="?page=<?= $i ?>&q=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&method=<?= urlencode($method) ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
              <a class="page-btn" href="?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&method=<?= urlencode($method) ?>">›</a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<script src="../js/sales_records_admin.js"></script>
</body>
</html>