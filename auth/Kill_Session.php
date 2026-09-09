<?php
// ── KILL SESSION ──────────────────────────────────────────────────
// Hit by the session guard (see js/tab_session_guard.js) whenever a
// browser loads a protected page without the shared localStorage
// "cc_authed" marker — i.e. this browser never logged in, or was
// logged out (explicitly, or by clearing site data), even if the PHP
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
