<?php
/**
 * CloudCup — Finance: Transactions
 */
require __DIR__ . '/includes/finance_data.php';

$activePage = 'transactions';
$pageTitle  = 'Finance — Transactions';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<script src="../js/sidebar-toggle.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transactions — CloudCup Finance</title>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="css/finance.css">
</head>
<body>

  <?php include __DIR__ . '/includes/finance_sidebar.php'; ?>

  <div class="main">
    <?php include __DIR__ . '/includes/finance_topbar.php'; ?>

    <div class="content">

      <?php include __DIR__ . '/includes/finance_filterbar.php'; ?>

      <div class="section-header"><h2>Transactions</h2><a class="btn-export" href="finance_export.php?report=transactions&<?= htmlspecialchars($rangeQuery) ?>">⭳ Export to Excel</a><div class="line"></div></div>
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
