// Restore the collapsed/expanded sidebar state saved from the last page.
// This runs as soon as it loads (before the sidebar markup, since it's
// included right above the sidebar include on every page that uses it),
// so the sidebar renders already-collapsed when it should be — instead of
// flashing open and then snapping shut, or reopening itself on navigation.
(function () {
  try {
    if (localStorage.getItem('cc_sidebar_hidden') === '1') {
      document.body.classList.add('sidebar-hidden');
    }
  } catch (e) { /* storage unavailable — just keep the default expanded state */ }
})();

// ── Keep the sidebar's scroll position across page navigations ──────────
// Every nav click is a full page reload here, so the browser normally
// paints a fresh sidebar scrolled to the top each time — that's what
// made it look like it was "moving" / "scrolling back to the top" on
// every navigation. Save the scroll position as the user scrolls and
// restore it instantly on the next page instead.
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
      if (!isNaN(y)) scrollEl.scrollTop = y; // instant, no animation
    }

    var saveTimer = null;
    scrollEl.addEventListener('scroll', function () {
      if (saveTimer) clearTimeout(saveTimer);
      saveTimer = setTimeout(function () {
        try { localStorage.setItem(storageKey, String(scrollEl.scrollTop)); } catch (e) {}
      }, 100);
    }, { passive: true });

    var persistNow = function () {
      try { localStorage.setItem(storageKey, String(scrollEl.scrollTop)); } catch (e) {}
    };
    window.addEventListener('pagehide', persistNow);
    window.addEventListener('beforeunload', persistNow);
  } catch (e) { /* storage unavailable — sidebar just resets scroll like before */ }
})();
