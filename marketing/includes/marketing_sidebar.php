<?php
/** Sidebar nav for the Marketing module. $activePage is set by the including page. */
$activePage = $activePage ?? '';
$navItems = [
    'dashboard' => [
        'label' => 'Dashboard',
        'href'  => 'Marketing_Dashboard.php',
        'icon'  => '<rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect>',
    ],
    'campaigns' => [
        'label' => 'Campaigns',
        'href'  => 'campaign_list.php',
        'icon'  => '<path d="M11 5.88V19.24a1.76 1.76 0 0 1-3.417.592l-2.147-6.15"></path><path d="M18 13a3 3 0 1 0 0-6"></path><path d="M5.436 13.683A4.001 4.001 0 0 1 7 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 0 1-1.564-.317z"></path>',
    ],
    'upload' => [
        'label' => 'Upload New Ad',
        'href'  => 'campaign_upload.php',
        'icon'  => '<polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>',
    ],
];
?>
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="sidebar-logo-row">
      <span style="display:flex;align-items:center;gap:8px;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" style="color:var(--gold);flex-shrink:0;">
          <path d="M17.5 19H9a5 5 0 1 1 1.9-9.62A5.5 5.5 0 1 1 17.5 19z"></path>
        </svg>
        <span>CloudCup<span> Marketing</span></span>
      </span>
    </div>
  </div>

  <?php $_mkt_overview_open = in_array($activePage, array_keys($navItems), true); ?>
  <div class="sidebar-section">
    <div class="sidebar-section-label sidebar-section-toggle" data-section="mkt-overview" role="button" tabindex="0"
         aria-expanded="<?= $_mkt_overview_open ? 'true' : 'false' ?>"
         onclick="toggleSidebarSection('mkt-overview')"
         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleSidebarSection('mkt-overview')}">
      <span class="sidebar-toggle-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $navItems['dashboard']['icon'] ?></svg></span>
      <span class="sidebar-toggle-text">Overview</span>
      <svg class="sidebar-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
    </div>
    <div class="sidebar-section-items<?= $_mkt_overview_open ? '' : ' collapsed' ?>" id="mkt-overview">
      <div>
    <?php foreach ($navItems as $key => $item): ?>
      <a href="<?= htmlspecialchars($item['href']) ?>"
         class="nav-item <?= $activePage === $key ? 'active' : '' ?>"
         data-label="<?= htmlspecialchars($item['label']) ?>">
        <span class="icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round"><?= $item['icon'] ?></svg>
        </span>
        <span class="nav-label"><?= htmlspecialchars($item['label']) ?></span>
      </a>
    <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="sidebar-footer">
    <div class="user-card">
      <div class="user-avatar"><?= htmlspecialchars($_SESSION['initials'] ?? 'M') ?></div>
      <div class="user-info">
        <strong><?= htmlspecialchars($_SESSION['full_name'] ?? 'Marketing') ?></strong>
        <span>Marketing Team</span>
      </div>
      <a href="../finance/logout.php" id="logoutBtn" class="logout-btn" title="Log out" aria-label="Log out">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
          <polyline points="16 17 21 12 16 7"></polyline>
          <line x1="21" y1="12" x2="9" y2="12"></line>
        </svg>
      </a>
    </div>
  </div>
</aside>

<link rel="stylesheet" href="../css/sidebar-dropdown.css"/>
<script src="../js/sidebar-dropdown.js"></script>
<script src="../js/sidebar-scroll-persist.js"></script>

<script>
(function () {
  var logoutBtn = document.getElementById('logoutBtn');
  if (!logoutBtn || typeof Swal === 'undefined') return;

  logoutBtn.addEventListener('click', function (e) {
    e.preventDefault();
    var href = this.getAttribute('href');

    Swal.fire({
      title: 'Log out?',
      text: "You'll need to sign back in to access the Marketing Portal.",
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Log out',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#b8453a',
      cancelButtonColor: '#6b6156',
      reverseButtons: true
    }).then(function (result) {
      if (result.isConfirmed) {
        window.location.href = href;
      }
    });
  });
})();
</script>