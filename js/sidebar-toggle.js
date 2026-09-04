// Restore the collapsed/expanded state from the last page as soon as this
// script runs (it loads before the sidebar markup, so the sidebar renders
// in the right state immediately — no flash of the wrong layout, and no
// "reopens itself" jump when navigating between sections like Finance).
(function () {
  try {
    if (localStorage.getItem('cc_sidebar_hidden') === '1') {
      document.body.classList.add('sidebar-hidden');
    }
  } catch (e) { /* storage unavailable — just keep the default expanded state */ }
})();

// ── Keep the sidebar's scroll position across page navigations ──────────
// This is a normal multi-page app: every click on a nav link is a full
// page reload, so the browser paints a brand-new sidebar at scrollTop 0
// every time. That's what made the sidebar look like it was "jumping"
// or "scrolling back to the top" on every navigation, even though the
// user never touched it. We save the scroll position as the user
// scrolls and restore it the instant the new page's sidebar exists, so
// it just stays put instead.
(function () {
  try {
    var pathParts = window.location.pathname.split('/').filter(Boolean);
    var moduleKey = pathParts.length > 1 ? pathParts[pathParts.length - 2] : 'default';
    var storageKey = 'cc_sidebar_scroll_' + moduleKey;

    var scrollEl = document.querySelector('.sidebar-nav-scroll') || document.querySelector('aside.sidebar');
    if (!scrollEl) return;

    var saved = localStorage.getItem(storageKey);
    if (saved !== null) {
      var y = parseInt(saved, 10);
      if (!isNaN(y)) scrollEl.scrollTop = y; // set instantly, no animation — no "moving"
    }

    var saveTimer = null;
    scrollEl.addEventListener('scroll', function () {
      if (saveTimer) clearTimeout(saveTimer);
      saveTimer = setTimeout(function () {
        try { localStorage.setItem(storageKey, String(scrollEl.scrollTop)); } catch (e) {}
      }, 100);
    }, { passive: true });

    // Belt-and-suspenders in case a click navigates away before the
    // debounced save above has a chance to fire.
    var persistNow = function () {
      try { localStorage.setItem(storageKey, String(scrollEl.scrollTop)); } catch (e) {}
    };
    window.addEventListener('pagehide', persistNow);
    window.addEventListener('beforeunload', persistNow);
  } catch (e) { /* storage unavailable — sidebar just resets scroll like before */ }
})();

function toggleSidebar() {
  var body = document.body;

  // Expanding back open: no animation, just restore instantly.
  if (body.classList.contains('sidebar-hidden')) {
    body.classList.remove('sidebar-hidden');
    try { localStorage.setItem('cc_sidebar_hidden', '0'); } catch (e) {}
    return;
  }

  // Collapsing: play the "logo turns into a coffee cup" animation once,
  // then hide the Cloud Cup wordmark and collapse the sidebar.
  if (body.classList.contains('sidebar-cup-animating')) return; // ignore double-clicks mid-animation
  body.classList.add('sidebar-cup-animating');
  setTimeout(function () {
    body.classList.remove('sidebar-cup-animating');
    body.classList.add('sidebar-hidden');
    try { localStorage.setItem('cc_sidebar_hidden', '1'); } catch (e) {}
  }, 850);
}
