// ── TAB-SCOPED SESSION GUARD ─────────────────────────────────────
// sessionStorage is unique per browser tab and is wiped the moment
// that tab closes (unlike the PHP session cookie, which survives
// until the whole browser process ends). We use that difference to
// make "closing the tab" actually log the user out:
//
//   - On a real login, Login_Page.php sets sessionStorage.cc_authed
//     for that tab before redirecting into the app.
//   - Every protected page runs this check FIRST, before rendering
//     anything sensitive. If the marker isn't there, this tab was
//     never logged in (fresh tab, or the tab that logged in was
//     closed and a new one opened) — so kick it to Kill_Session.php,
//     which destroys the underlying session and returns to Login.
//
// Must be loaded synchronously in <head>, before the rest of the
// page, so a stale/foreign tab never gets to see protected content.
(function () {
  if (sessionStorage.getItem('cc_authed') !== '1') {
    window.location.replace('../auth/Kill_Session.php');
  }
})();
