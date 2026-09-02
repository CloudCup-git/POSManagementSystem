<div class="topbar">
      <div class="topbar-left">
        <button class="sidebar-toggle-btn" onclick="document.body.classList.toggle('finance-sidebar-open')" title="Toggle sidebar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
        <div class="page-title"><?= htmlspecialchars($pageTitle) ?></div>
      </div>
      <div style="display:flex; align-items:center; gap:12px;">
        <div class="user-pill"><span class="dot"></span> <?= htmlspecialchars($currentUser) ?></div>
      </div>
    </div>