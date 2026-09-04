<?php
/**
 * CloudCup — Finance: Activity History
 * Finance-scoped view of the shared activity_log table (see
 * ../includes/Activity_Log.php). Same data Admin's Activity_Log_Page.php
 * shows to the 'finance' audience, but rendered inside the Finance
 * portal's own layout instead of sending Finance users out to Admin.
 */
require __DIR__ . '/includes/finance_data.php';

ensure_activity_log($conn);

$activity_rows = [];
$stmt = mysqli_prepare($conn, "SELECT * FROM activity_log WHERE FIND_IN_SET('finance', audience) ORDER BY created_at DESC LIMIT 250");
if ($stmt) {
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) $activity_rows[] = $row;
    mysqli_stmt_close($stmt);
}

$activePage = 'history';
$pageTitle  = 'Finance — Activity History';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Activity History — CloudCup Finance</title>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="css/finance.css">
<style>
  .badge-action{ background:var(--blue-50); color:var(--blue-700); }
</style>
</head>
<body>

  <?php include __DIR__ . '/includes/finance_sidebar.php'; ?>

  <div class="main">
    <?php include __DIR__ . '/includes/finance_topbar.php'; ?>

    <div class="content">

      <div class="section-header"><h2>Activity History</h2><div class="line"></div></div>
      <div class="panel">
        <div class="panel-title">Recent Finance Activity</div>
        <div class="panel-sub">Updates, approvals, and additions logged against Finance — most recent first.</div>
        <?php if ($activity_rows): ?>
        <table>
          <thead>
            <tr><th>When</th><th>Action</th><th>Record</th><th>Details</th><th>By</th></tr>
          </thead>
          <tbody>
            <?php foreach ($activity_rows as $row): ?>
            <tr>
              <td class="muted"><?= htmlspecialchars(date('M d, Y g:i A', strtotime($row['created_at']))) ?></td>
              <td><span class="badge badge-action"><?= htmlspecialchars(ucfirst($row['action'])) ?></span></td>
              <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['target_type']))) ?><?= $row['target_id'] ? ' #' . htmlspecialchars($row['target_id']) : '' ?></td>
              <td><?= htmlspecialchars($row['details'] ?: '—') ?></td>
              <td class="muted"><?= htmlspecialchars($row['actor_name'] ?: 'System') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <div class="empty-state">No finance activity has been recorded yet.</div>
        <?php endif; ?>
      </div>

    </div>
  </div>

</body>
</html>
