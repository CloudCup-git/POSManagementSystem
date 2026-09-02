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
