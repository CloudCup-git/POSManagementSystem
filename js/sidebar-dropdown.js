// ── Collapsible sidebar sections ─────────────────────────────────────────
// Shared by every module's sidebar. A section becomes a dropdown when the
// PHP that renders it wraps the section's links as:
//   <div class="sidebar-section-label sidebar-section-toggle" data-section="ID" ...>
//   <div class="sidebar-section-items" id="ID"><div>...items...</div></div>
//
// The server already decides the *initial* open/closed state on each page
// load (the group holding the current page always starts open, see the
// $..._open flags in each sidebar file). This file only has two jobs:
//   1. Toggle a section open/closed when its header is clicked or
//      activated from the keyboard.
//   2. Remember sections the user manually closed, across page loads —
//      but never re-collapse whichever section holds the page that's
//      currently open, so navigating never hides the link you just used.

function toggleSidebarSection(id) {
  var panel = document.getElementById(id);
  var header = document.querySelector('.sidebar-section-toggle[data-section="' + id + '"]');
  if (!panel || !header) return;

  var collapsed = panel.classList.toggle('collapsed');
  header.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

  try {
    var closed = JSON.parse(localStorage.getItem('cc_sidebar_closed_sections') || '[]');
    closed = closed.filter(function (x) { return x !== id; });
    if (collapsed) closed.push(id);
    localStorage.setItem('cc_sidebar_closed_sections', JSON.stringify(closed));
  } catch (e) { /* storage unavailable — toggle still works for this page view */ }
}

(function () {
  var closed;
  try {
    closed = JSON.parse(localStorage.getItem('cc_sidebar_closed_sections') || '[]');
  } catch (e) {
    return;
  }
  closed.forEach(function (id) {
    var panel = document.getElementById(id);
    // Skip restoring "closed" for a section that contains the active
    // page — the server already opened it for a reason.
    if (!panel || panel.querySelector('.nav-item.active')) return;
    panel.classList.add('collapsed');
    var header = document.querySelector('.sidebar-section-toggle[data-section="' + id + '"]');
    if (header) header.setAttribute('aria-expanded', 'false');
  });
})();
