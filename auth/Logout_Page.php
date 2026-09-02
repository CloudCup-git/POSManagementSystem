<?php
// ── LOGOUT ────────────────────────────────────────────────────────
session_start();

// Clear all session variables
$_SESSION = [];

// Destroy the session cookie itself (not just the data) — without this
// the browser keeps sending the old session ID after logout
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy the session on the server
session_destroy();

// Prevent this response (and the page the user is coming from) from
// being served out of the browser's cache via Back/Forward
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Logging out…</title>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.5/sweetalert2.all.min.js"></script>
</head>
<body>
  <script>
    // Session is already destroyed server-side at this point; this is
    // just a brief, reassuring loading state before we send the user
    // back to the login page.
    var target = '../auth/Login_Page.php';

    function goToTarget() {
      window.location.replace(target);
    }

    if (window.Swal) {
      Swal.fire({
        title: 'Logging out...',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: function () { Swal.showLoading(); }
      });
      setTimeout(goToTarget, 1200);
    } else {
      // SweetAlert2 failed to load (e.g. offline) — don't strand the user.
      goToTarget();
    }
  </script>
  <noscript>
    <meta http-equiv="refresh" content="0;url=../auth/Login_Page.php">
    <p>Logging out… <a href="../auth/Login_Page.php">Continue</a></p>
  </noscript>
</body>
</html>
<?php
exit;