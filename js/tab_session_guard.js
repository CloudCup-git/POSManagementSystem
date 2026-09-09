// ── SHARED-BROWSER SESSION GUARD ─────────────────────────────────
// Marks a login as active using localStorage (shared by every tab in
// this browser, unlike sessionStorage), so opening a page in another
// tab — via ctrl/middle-click, or dragging a sidebar link out onto
// the tab strip, which does NOT inherit sessionStorage the way a
// plain click does — lands on the page you actually opened instead
// of getting bounced to Kill_Session.php and taking every other tab's
// session down with it.
//
//   - On login, Login_Page.php sets localStorage.cc_authed = '1'.
//   - Every protected page runs this check FIRST, before rendering
//     anything sensitive. If the marker isn't there, this browser
//     was never logged in (or was explicitly logged out) — kick it
//     to Kill_Session.php, which destroys the underlying session and
//     returns to Login.
//   - Logging out explicitly (Logout_Page.php) clears cc_authed,
//     which fires a 'storage' event in every other open tab — each
//     one follows to Login right away instead of quietly staying on
//     a page whose server session is already gone.
//
// Must be loaded synchronously in <head>, before the rest of the
// page, so a stale/foreign tab never gets to see protected content.
(function () {
  if (localStorage.getItem('cc_authed') !== '1') {
    window.location.replace('../auth/Kill_Session.php');
    return;
  }

  window.addEventListener('storage', function (e) {
    if (e.key === 'cc_authed' && !e.newValue) {
      window.location.replace('../auth/Kill_Session.php');
    }
  });
})();
