// ── Keep the sidebar's scroll position across page navigations ──────────
// This is a normal multi-page app: every click on a nav link is a full
// page reload, so the browser paints a brand-new sidebar at scrollTop 0
// every time. That's what made the sidebar look like it was "jumping"
// or "scrolling back to the top" on every navigation, even though the
// user never touched it.
//
// Two cases should NOT reuse the saved position and land at the top
// instead:
//   1. A hard refresh (F5 / reload button) of the current page.
//   2. Logging out — the next sign-in should start at the top, not
//      wherever the sidebar happened to be scrolled to before.
// A normal click on a nav link (or browser back/forward) should still
// restore the saved position, including when it was scrolled to the
// bottom.
//
// IMPORTANT: this file must be included AFTER the <aside class="sidebar">
// markup on the page (e.g. right after the closing </aside> tag), NOT
// before it like sidebar-toggle.js. It needs to query the actual sidebar
// element, which doesn't exist yet if this runs earlier in <head> or at
// the top of <body>.
(function () {
  try {
    var pathParts = window.location.pathname.split('/').filter(Boolean);
    var moduleKey = pathParts.length > 1 ? pathParts[pathParts.length - 2] : 'default';
    var storageKey = 'cc_sidebar_scroll_' + moduleKey;

    var scrollEl = document.querySelector('.sidebar-nav-scroll') || document.querySelector('aside.sidebar');
    if (!scrollEl) return;

    // Was this load a hard refresh, as opposed to a normal link click or
    // back/forward navigation? Only a refresh should skip restoring.
    var isReload = false;
    try {
      var navEntries = performance.getEntriesByType && performance.getEntriesByType('navigation');
      if (navEntries && navEntries.length) {
        isReload = navEntries[0].type === 'reload';
      } else if (performance.navigation) { // older browsers without the Navigation Timing L2 API
        isReload = performance.navigation.type === 1;
      }
    } catch (e) { /* if we can't tell, fall back to restoring as before */ }

    if (isReload) {
      // Drop the stale position instead of reusing it, so the sidebar
      // renders at the top on this load.
      try { localStorage.removeItem(storageKey); } catch (e) {}
    } else {
      var saved = localStorage.getItem(storageKey);
      if (saved !== null) {
        var y = parseInt(saved, 10);
        if (!isNaN(y)) scrollEl.scrollTop = y; // set instantly, no animation — no "moving"
      }
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

    // Logging out should always land back at the top next time someone
    // signs in, no matter where the sidebar was scrolled to.
    var logoutLinks = document.querySelectorAll('.logout-btn, #admin-logout-btn');
    for (var i = 0; i < logoutLinks.length; i++) {
      logoutLinks[i].addEventListener('click', function () {
        try { localStorage.removeItem(storageKey); } catch (e) {}
      });
    }
  } catch (e) { /* storage unavailable — sidebar just resets scroll like before */ }
})();
