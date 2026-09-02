<?php
require_once __DIR__ . '/config.php';
$supplier_id = supplier_require_login();
$sid = (int)$supplier_id;

$logs = [];
$res = mysqli_query($conn, "SELECT * FROM supplier_activity_logs WHERE supplier_id = $sid ORDER BY created_at DESC LIMIT 250");
if ($res) while ($r = mysqli_fetch_assoc($res)) $logs[] = $r;

$activePage = 'activity';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Activity Log — Supplier Portal</title>
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
      <h1 class="page-title">Activity Log</h1>
    </div>
  </div>

  <div class="content">
    <div class="widget">
      <div class="widget-header">
        <div class="widget-title">Your account's activity — logins, responses, uploads, updates</div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>When</th><th>Module</th><th>Action</th><th>Description</th></tr></thead>
          <tbody>
            <?php if (empty($logs)): ?>
              <tr><td colspan="4" class="empty-state">No activity recorded yet.</td></tr>
            <?php else: foreach ($logs as $l): ?>
              <tr>
                <td><?= date('M j, Y g:ia', strtotime($l['created_at'])) ?></td>
                <td><?= htmlspecialchars(ucfirst($l['module'])) ?></td>
                <td><?= htmlspecialchars(str_replace('_', ' ', $l['action_type'])) ?></td>
                <td><?= htmlspecialchars($l['description']) ?></td>
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
