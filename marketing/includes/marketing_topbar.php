<?php
/**
 * Topbar for the Marketing module — houses the sidebar burger toggle.
 * NOTE: this file wasn't in your upload but is include()'d by the other
 * pages, so it's added here new. If you already have one elsewhere,
 * merge the toggle button + script into it instead of overwriting.
 */
?>
<div class="topbar">
  <div class="topbar-left">
    <button type="button" class="sidebar-toggle-btn" id="sidebarToggle" aria-label="Toggle sidebar">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <line x1="3" y1="6" x2="21" y2="6"></line>
        <line x1="3" y1="12" x2="21" y2="12"></line>
        <line x1="3" y1="18" x2="21" y2="18"></line>
      </svg>
    </button>
    <div>
      <h1><?= htmlspecialchars($pageTitle ?? 'Marketing') ?></h1>
    </div>
  </div>
  <div class="topbar-right">
    <div class="topbar-date"><?= (new DateTime())->format('l, M j, Y') ?></div>
  </div>
</div>

<script>
(function () {
  var body = document.body;
  var btn = document.getElementById('sidebarToggle');
  var STORAGE_KEY = 'mkt_sidebar_hidden';

  if (localStorage.getItem(STORAGE_KEY) === '1') {
    body.classList.add('sidebar-hidden');
  }
  if (btn) {
    btn.addEventListener('click', function () {
      body.classList.toggle('sidebar-hidden');
      localStorage.setItem(STORAGE_KEY, body.classList.contains('sidebar-hidden') ? '1' : '0');
    });
  }
})();
</script>