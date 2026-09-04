<?php
/**
 * CloudCup — Manager: Activity History
 * Manager-scoped view of the shared activity_log table (see
 * ../includes/Activity_Log.php). Same data Admin's Activity_Log_Page.php
 * shows to the 'store_manager' audience, but rendered inside the Manager
 * module's own layout instead of sending managers out to Admin.
 */
session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'manager') {
  header('Location: ../auth/Role_Panel.php');
  exit;
}
require_once __DIR__ . '/../includes/DB_Connect.php';
require_once __DIR__ . '/../includes/Activity_Log.php';

$full_name   = $_SESSION['full_name'] ?? 'Manager';
$initials    = strtoupper(substr($full_name, 0, 1));
$active_page = 'history';

ensure_activity_log($conn);

$activity_rows = [];
$stmt = mysqli_prepare($conn, "SELECT * FROM activity_log WHERE FIND_IN_SET('store_manager', audience) ORDER BY created_at DESC LIMIT 250");
if ($stmt) {
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) $activity_rows[] = $row;
    mysqli_stmt_close($stmt);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Activity History — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
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

  <div class="main">
    <div class="topbar">
      <div class="topbar-left">
        <div class="page-title">Activity History</div>
      </div>
      <div style="display:flex; align-items:center; gap:12px;">
        <div class="user-pill"><span class="dot"></span> <?= htmlspecialchars($full_name) ?></div>
      </div>
    </div>

    <div class="content">
      <div class="widget">
        <div class="widget-header">
          <div>
            <div class="widget-title">Recent Manager Activity</div>
            <div style="font-size:12.5px;color:var(--text-light);margin-top:2px;">Updates, approvals, and additions logged for the Manager role — most recent first.</div>
          </div>
        </div>
        <?php if ($activity_rows): ?>
        <table>
          <thead>
            <tr><th>When</th><th>Action</th><th>Record</th><th>Details</th><th>By</th></tr>
          </thead>
          <tbody>
            <?php foreach ($activity_rows as $row): ?>
            <tr>
              <td class="order-id"><?= htmlspecialchars(date('M d, Y g:i A', strtotime($row['created_at']))) ?></td>
              <td><span class="status-pill pill-done"><?= htmlspecialchars(ucfirst($row['action'])) ?></span></td>
              <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['target_type']))) ?><?= $row['target_id'] ? ' #' . htmlspecialchars($row['target_id']) : '' ?></td>
              <td><?= htmlspecialchars($row['details'] ?: '—') ?></td>
              <td><?= htmlspecialchars($row['actor_name'] ?: 'System') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <div style="padding:48px 24px;text-align:center;color:var(--text-light);">
            <div style="font-size:34px;line-height:1;margin-bottom:10px;">🗒️</div>
            <div style="font-weight:700;color:var(--text-dark,#2f261c);margin-bottom:4px;">Nothing logged yet</div>
            <div style="font-size:13px;">Manager activity — approvals, restocks, and updates — will show up here as it happens.</div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</body>
</html>
