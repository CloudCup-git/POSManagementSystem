<?php
// ── KILL SESSION ──────────────────────────────────────────────────
// Hit by the tab-scoped session guard (see js/tab_session_guard.js)
// whenever a browser tab loads a protected page without this tab's
// sessionStorage "cc_authed" marker — i.e. the tab was closed and
// reopened (or the page was opened fresh) after a previous login.
// sessionStorage is cleared by the browser when a tab closes, so its
// absence here means "this tab never logged in," even if the PHP
// session cookie itself is still technically alive.
//
// Destroys the session entirely and sends the user back to the
// login screen.

session_start();
$_SESSION = [];

if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

session_destroy();

header('Location: Login_Page.php');
exit;
