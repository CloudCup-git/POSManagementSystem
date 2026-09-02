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
