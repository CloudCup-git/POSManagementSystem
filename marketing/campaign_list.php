<?php
/**
 * CloudCup — Manage all marketing campaigns.
 */
require __DIR__ . '/config.php';
marketing_require_login();

$activePage = 'campaigns';
$pageTitle  = 'Marketing — Campaigns';
$campaigns  = marketing_all_campaigns($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Campaigns — CloudCup Marketing</title>
<link rel="stylesheet" href="css/admin_page.css">
<link rel="stylesheet" href="css/sidebar_admin.css">
<link rel="stylesheet" href="css/marketing.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<script src="../js/sidebar-restore.js"></script>

  <?php include __DIR__ . '/includes/marketing_sidebar.php'; ?>

  <div class="main">
    <?php include __DIR__ . '/includes/marketing_topbar.php'; ?>

    <div class="content">

      <div class="section-header">
        <h2>All Campaigns</h2>
        <a class="btn-add" href="campaign_upload.php">+ Upload New</a>
        <div class="line"></div>
      </div>

      <div class="panel">
        <?php if ($campaigns): ?>
          <table>
            <thead>
              <tr><th>Campaign</th><th>Type</th><th>Status</th><th>CTA</th><th>Uploaded</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($campaigns as $c): ?>
                <tr>
                  <td>
                    <div class="thumb-cell">
                      <?php $thumb = $c['thumbnail_path'] ?: ($c['media_type'] === 'poster' ? $c['file_path'] : null); ?>
                      <?php if ($thumb): ?>
                        <img class="thumb-img" src="<?= htmlspecialchars($thumb) ?>" alt="">
                      <?php else: ?>
                        <div class="thumb-img">
                          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                               stroke-linecap="round" stroke-linejoin="round">
                            <path d="M23 7l-7 5 7 5V7z"></path>
                            <rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>
                          </svg>
                        </div>
                      <?php endif; ?>
                      <span class="thumb-title"><?= htmlspecialchars($c['title']) ?></span>
                    </div>
                  </td>
                  <td><span class="badge badge-<?= $c['media_type'] ?>"><?= ucfirst($c['media_type']) ?></span></td>
                  <td>
                    <span class="badge badge-<?= $c['is_active'] ? 'active' : 'inactive' ?>"><?= $c['is_active'] ? 'Active' : 'Inactive' ?></span>
                    <?php if ($c['is_featured']): ?><span class="badge badge-featured">Featured</span><?php endif; ?>
                  </td>
                  <td class="muted"><?= htmlspecialchars($c['cta_label']) ?></td>
                  <td class="muted"><?= (new DateTime($c['created_at']))->format('M d, Y') ?></td>
                  <td>
                    <div class="row-actions">
                      <form method="post" action="campaign_action.php">
                        <input type="hidden" name="campaign_id" value="<?= $c['campaign_id'] ?>">
                        <input type="hidden" name="action" value="feature">
                        <button type="submit" class="action-btn star <?= $c['is_featured'] ? 'is-featured' : '' ?>">
                          <svg width="13" height="13" viewBox="0 0 24 24"
                               fill="<?= $c['is_featured'] ? 'currentColor' : 'none' ?>"
                               stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="12 2 15.09 8.63 22 9.24 16.5 13.97 18.18 21 12 17.27 5.82 21 7.5 13.97 2 9.24 8.91 8.63 12 2"></polygon>
                          </svg>
                          <?= $c['is_featured'] ? 'Featured' : 'Feature' ?>
                        </button>
                      </form>
                      <form method="post" action="campaign_action.php" class="js-toggle-active-form"
                            data-active="<?= $c['is_active'] ? '1' : '0' ?>"
                            data-title="<?= htmlspecialchars($c['title'], ENT_QUOTES) ?>">
                        <input type="hidden" name="campaign_id" value="<?= $c['campaign_id'] ?>">
                        <input type="hidden" name="action" value="toggle_active">
                        <button type="submit" class="action-btn"><?= $c['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                      </form>
                      <form method="post" action="campaign_action.php" class="js-archive-form"
                            data-title="<?= htmlspecialchars($c['title'], ENT_QUOTES) ?>">
                        <input type="hidden" name="campaign_id" value="<?= $c['campaign_id'] ?>">
                        <input type="hidden" name="action" value="archive">
                        <button type="submit" class="action-btn danger">
                          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                               stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="21 8 21 21 3 21 3 8"></polyline>
                            <rect x="1" y="3" width="22" height="5"></rect>
                            <line x1="10" y1="12" x2="14" y2="12"></line>
                          </svg>
                          Archive
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div class="empty-state">No campaigns yet. <a href="campaign_upload.php" style="color:var(--blue-700);font-weight:700;">Upload your first ad →</a></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

<script>
(function () {
  if (typeof Swal === 'undefined') return;

  var Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3200,
    timerProgressBar: true
  });

  // ---- Post-redirect status toasts ----
  var params = new URLSearchParams(window.location.search);
  if (params.has('uploaded')) {
    Toast.fire({ icon: 'success', title: 'Campaign uploaded successfully' });
  }
  if (params.has('activated')) {
    Toast.fire({ icon: 'success', title: 'Campaign activated' });
  }
  if (params.has('deactivated')) {
    Toast.fire({ icon: 'info', title: 'Campaign deactivated' });
  }
  if (params.has('archived')) {
    Toast.fire({ icon: 'success', title: 'Campaign archived' });
  }

  // ---- Activate / Deactivate confirm ----
  document.querySelectorAll('.js-toggle-active-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var isActive = form.dataset.active === '1';
      var title = form.dataset.title;

      Swal.fire({
        title: isActive ? 'Deactivate campaign?' : 'Activate campaign?',
        html: isActive
          ? 'This will pull <strong>' + title + '</strong> out of the live rotation.'
          : '<strong>' + title + '</strong> will start showing to customers again.',
        icon: isActive ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: isActive ? 'Deactivate' : 'Activate',
        cancelButtonText: 'Cancel',
        confirmButtonColor: isActive ? '#a6650f' : '#2f6f4e',
        cancelButtonColor: '#6b6156',
        reverseButtons: true
      }).then(function (result) {
        if (result.isConfirmed) form.submit();
      });
    });
  });

  // ---- Archive confirm (replaces permanent delete) ----
  document.querySelectorAll('.js-archive-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var title = form.dataset.title;

      Swal.fire({
        title: 'Archive this campaign?',
        html: '<strong>' + title + '</strong> will be moved to the archive and taken off the live site. Nothing is permanently deleted — you can restore it later.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Archive',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#b8453a',
        cancelButtonColor: '#6b6156',
        reverseButtons: true
      }).then(function (result) {
        if (result.isConfirmed) form.submit();
      });
    });
  });
})();
</script>

</body>
</html>