<?php
/**
 * CloudCup — Manager Finance: Transactions
 * -------------------------------------------------------------
 * Same data layer as the standalone Finance module's Transactions
 * tab (../finance/finance_transactions.php), rendered inside the
 * admin panel's own sidebar/topbar/layout instead of the Finance
 * portal's.
 * -------------------------------------------------------------
 */
require __DIR__ . '/../finance/includes/finance_data.php'; // session/role check + $pdo + all KPI vars + money()

/* -----------------------------------------------------------
   Extra access gate — this admin-panel-styled view is manager-only
   now (branch-scoped operational finance). finance_data.php's own
   check still allows 'finance' and 'admin' through since it's a
   shared file used by other pages, so this page needs its own
   stricter check on top of that.
----------------------------------------------------------- */
if (strtolower($_SESSION['role'] ?? '') !== 'manager') {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restricted — CloudCup Manager</title>
    <link rel="stylesheet" href="../css/admin_page.css">
    <link rel="stylesheet" href="../finance/css/finance.css">
    </head>
    <body>
      <div style="max-width:520px;margin:80px auto;text-align:center;font-family:sans-serif;color:#241f19">
        <h2>Restricted</h2>
        <p>This page is only available to Manager accounts.</p>
      </div>
    </body>
    </html>
    <?php
    exit;
}

$active_page = 'finance-transactions';
$full_name   = $_SESSION['full_name'] ?? 'Manager';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<script src="../js/sidebar-toggle.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transactions — CloudCup Manager</title>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="../finance/css/finance.css">
</head>
<body>

<?php
if (file_exists(__DIR__ . '/Sidebar_Manager.php')) {
  require_once __DIR__ . '/Sidebar_Manager.php';
} else {
  echo '<div style="background:#f4e3d3;border-bottom:1px solid #a6650f;padding:10px 32px;font-size:13px;color:#a6650f">'
     . '<strong>Sidebar not found.</strong> Expected <code>Sidebar_Manager.php</code> in this folder.</div>';
}
?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
      <h1>Transactions</h1>
    </div>
  </div>

  <div class="content">

    <?php include __DIR__ . '/../finance/includes/finance_filterbar.php'; ?>

    <div class="section-header"><h2>Transactions</h2><a class="btn-export" href="../finance/finance_export.php?report=transactions&<?= htmlspecialchars($rangeQuery) ?>">⭳ Export to Excel</a><div class="line"></div></div>
    <div class="panel" style="margin-bottom:16px;">
      <div class="panel-title">Recent Transactions</div>
      <?php if ($recentOrders): ?>
      <table>
        <thead>
          <tr><th>Order #</th><th>Date &amp; Time</th><th>Cashier</th><th>Type</th><th>Payment</th><th>Status</th><th style="text-align:right;">Amount</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recentOrders as $o):
            $pm = $o['payment_method'] ?: 'unspecified';
          ?>
          <tr>
            <td>#<?= (int) $o['order_id'] ?></td>
            <td class="muted"><?= (new DateTime($o['ordered_at']))->format('M d, Y g:i A') ?></td>
            <td><?= htmlspecialchars($o['cashier'] ?? '—') ?></td>
            <td class="muted"><?= ucfirst(str_replace('_', ' ', $o['order_type'])) ?></td>
            <td><span class="badge badge-<?= htmlspecialchars($pm) ?>"><?= htmlspecialchars($pm) ?></span></td>
            <td><span class="badge badge-<?= htmlspecialchars($o['status']) ?>"><?= htmlspecialchars($o['status']) ?></span></td>
            <td class="num"><?= money($o['total_amount']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
        <div class="empty-state">No transactions in this range.</div>
      <?php endif; ?>
    </div>

  </div>
</div>
</body>
</html>
