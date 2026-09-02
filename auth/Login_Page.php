<?php
// ── UNIFIED LOGIN ─────────────────────────────────────────────────
// Single entry point for all roles: admin, manager, hr_admin, employee.
// Verifies credentials against the `users` table, then routes each
// role to its correct landing page:
//   admin             -> Admin_Dashboard.php
//   manager           -> Manager_Dashboard.php
//   hr_admin         -> HR_Dashboard.php
//   employee         -> Sales_Processing_Page.php
//   inventory_staff  -> Inventory_Management_Page.php (read-only, inventory only)
//
// Replaces Admin_Login.php, HR_Login.php, and Staff_Login.php.

session_start();
require_once __DIR__ . '/../includes/DB_Connect.php';

// Map each role to its landing page.
//   admin            -> admin/Admin_Dashboard.php
//   manager          -> manager/Manager_Dashboard.php
//   hr_admin         -> HR_Dashboard.php
//   employee         -> Sales_Processing_Page.php
//                        (except HR Position = "Inventory Staff", which
//                        goes to manager/Inventory_Management_Page.php
//                        instead — see $position param below)
//   inventory_staff  -> manager/Inventory_Management_Page.php (read-only, inventory only)
//
// $position is the HR `employees.position` value (e.g. "Barista",
// "Inventory Staff"). It only matters for role='employee' — everyone
// else is routed by role alone. Accounts don't need the separate
// 'inventory_staff' role just to land on the Inventory page; an
// 'employee' whose HR Position is "Inventory Staff" gets routed there
// automatically.
function role_redirect(string $role, string $position = ''): string
{
  switch ($role) {
    case 'admin':
      return '../admin/Admin_Dashboard.php';
    case 'manager':
      return '../manager/Manager_Dashboard.php';
    case 'hr_admin':
      return '../hr/HR_Dashboard.php';
    case 'employee':
      if ($position === 'Inventory Staff') {
        return '../manager/Inventory_Management_Page.php';
      }
      return '../staff/Sales_Processing_Page.php';
    case 'inventory_staff':
      return '../manager/Inventory_Management_Page.php';
    case 'finance':
      return '../finance/finance.php';
    case 'marketing':
      return '../marketing/Marketing_Dashboard.php';
    default:
      return '../auth/Login_Page.php';
  }
}

// Already logged in server-side? Only auto-skip the form if THIS TAB is the
// one that logged in (checked client-side below via sessionStorage). The PHP
// session cookie can still be alive after a tab is closed, so we can't trust
// it alone — otherwise closing and reopening a tab would silently sign the
// person back in.
$already_authed_target = null;
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
  $already_authed_target = role_redirect(strtolower($_SESSION['role']), $_SESSION['position'] ?? '');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';

  if ($username === '' || $password === '') {
    $error = 'Please enter both username and password.';
  } elseif (!$conn) {
    $error = 'No database connection. Set up your database first.';
  } else {
    $stmt = mysqli_prepare(
      $conn,
      "SELECT u.user_id, u.full_name, u.username, u.password, u.role, u.role_id, u.is_active,
              e.position
             FROM users u
             LEFT JOIN employees e ON e.employee_id = u.user_id
             WHERE u.username = ? AND u.role IN ('admin', 'manager', 'hr_admin', 'employee', 'inventory_staff', 'finance', 'marketing')
             LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 's', $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user   = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if (!$user || !password_verify($password, $user['password'])) {
      // Same message either way — don't reveal whether the username exists.
      $error = 'Incorrect username or password.';
    } elseif ((int)($user['is_active'] ?? 1) === 0) {
      $error = 'This account has been deactivated. Contact an administrator.';
    } else {
      $role     = strtolower($user['role']);
      $position = $user['position'] ?? '';
      $parts    = preg_split('/\s+/', trim($user['full_name']));
      $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));

      // Prevent session fixation on privilege change.
      session_regenerate_id(true);

      $_SESSION['user_id']   = (int)$user['user_id'];
      // Kept for backward compatibility with pages that read employee_id
      // (e.g. Sales_Processing_Page.php from the old Staff_Login.php).
      $_SESSION['employee_id'] = (int)$user['user_id'];
      $_SESSION['full_name'] = $user['full_name'];
      $_SESSION['role']      = $role;
      $_SESSION['role_id']   = (int)$user['role_id'];
      $_SESSION['position']  = $position; // HR Position (e.g. "Inventory Staff") — drives routing for role='employee'
      $_SESSION['initials']  = $initials;

      $target = role_redirect($role, $position);
      ?>
      <!DOCTYPE html>
      <html lang="en">
      <head>
        <meta charset="UTF-8" />
        <title>Signing in…</title>
        <script>
          // Mark THIS tab as authenticated. sessionStorage is cleared the
          // moment this tab closes, which is what makes the tab-scoped
          // logout work — see js/tab_session_guard.js.
          sessionStorage.setItem('cc_authed', '1');
          window.location.replace(<?= json_encode($target) ?>);
        </script>
      </head>
      <body>
        <noscript>
          <meta http-equiv="refresh" content="0;url=<?= htmlspecialchars($target, ENT_QUOTES, 'UTF-8') ?>">
          <p>JavaScript is required. <a href="<?= htmlspecialchars($target, ENT_QUOTES, 'UTF-8') ?>">Continue</a></p>
        </noscript>
      </body>
      </html>
      <?php
      exit;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login — Cloud Cup</title>
  <?php if ($already_authed_target): ?>
  <script>
    // Server thinks a session is active, but only trust it for THIS tab if
    // this tab is the one that actually set the marker (see js/tab_session_guard.js).
    if (sessionStorage.getItem('cc_authed') === '1') {
      window.location.replace(<?= json_encode($already_authed_target) ?>);
    }
  </script>
  <?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,500&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/cloudcup_login.css" />
</head>

<body>

  <!-- LEFT PANEL -->
  <div class="left" id="leftPanel">
    <div class="top-bar"></div>
    <div class="grain"></div>
    <div class="glow"></div>

    <div class="brand">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="1.6"><path d="M6 14a4 4 0 010-8 5 5 0 019.6-1.5A4.5 4.5 0 0119 14H6z"/></svg>
      CloudCup
    </div>

    <div class="cup-deco" aria-hidden="true">
      <svg viewBox="0 0 200 200">
        <path class="cup-steam cs1" d="M120 60 C110 48, 124 40, 116 26"/>
        <path class="cup-steam cs2" d="M138 62 C128 50, 142 42, 134 28"/>
        <path class="cup-steam cs3" d="M156 60 C146 48, 160 40, 152 26"/>
        <path class="cup-outline" d="M70 90h100a0 0 0 010 0v20a45 45 0 01-45 45h-10a45 45 0 01-45-45V90z"/>
        <path class="cup-outline" d="M170 100h14a16 16 0 010 32h-14"/>
        <ellipse class="cup-outline" cx="120" cy="90" rx="50" ry="8"/>
      </svg>
    </div>

    <div class="beans" id="beansLayer" aria-hidden="true">
      <div class="bean bean-a"><svg viewBox="0 0 70 100"><ellipse cx="35" cy="50" rx="28" ry="48" stroke="#faf8f4" stroke-width="3" fill="none"/><path d="M35 6 C27 30, 43 70, 35 94" stroke="#faf8f4" stroke-width="3" fill="none"/></svg></div>
      <div class="bean bean-b"><svg viewBox="0 0 70 100"><ellipse cx="35" cy="50" rx="28" ry="48" stroke="#faf8f4" stroke-width="3" fill="none"/><path d="M35 6 C27 30, 43 70, 35 94" stroke="#faf8f4" stroke-width="3" fill="none"/></svg></div>
      <div class="bean bean-c"><svg viewBox="0 0 70 100"><ellipse cx="35" cy="50" rx="28" ry="48" stroke="#faf8f4" stroke-width="3" fill="none"/><path d="M35 6 C27 30, 43 70, 35 94" stroke="#faf8f4" stroke-width="3" fill="none"/></svg></div>
      <div class="bean bean-d"><svg viewBox="0 0 70 100"><ellipse cx="35" cy="50" rx="28" ry="48" stroke="#faf8f4" stroke-width="3" fill="none"/><path d="M35 6 C27 30, 43 70, 35 94" stroke="#faf8f4" stroke-width="3" fill="none"/></svg></div>
      <div class="bean bean-e"><svg viewBox="0 0 70 100"><ellipse cx="35" cy="50" rx="28" ry="48" stroke="#faf8f4" stroke-width="3" fill="none"/><path d="M35 6 C27 30, 43 70, 35 94" stroke="#faf8f4" stroke-width="3" fill="none"/></svg></div>
      <div class="bean bean-f"><svg viewBox="0 0 70 100"><ellipse cx="35" cy="50" rx="28" ry="48" stroke="#faf8f4" stroke-width="3" fill="none"/><path d="M35 6 C27 30, 43 70, 35 94" stroke="#faf8f4" stroke-width="3" fill="none"/></svg></div>
      <div class="bean bean-g"><svg viewBox="0 0 70 100"><ellipse cx="35" cy="50" rx="28" ry="48" stroke="#faf8f4" stroke-width="3" fill="none"/><path d="M35 6 C27 30, 43 70, 35 94" stroke="#faf8f4" stroke-width="3" fill="none"/></svg></div>
    </div>

    <h1 class="headline">Your shift starts
      <span class="accent-wrap">
        <svg class="steam" viewBox="0 0 30 36" aria-hidden="true">
          <path class="p1" d="M8 30 C4 22, 12 20, 8 12"/>
          <path class="p2" d="M15 32 C11 24, 19 22, 15 14"/>
          <path class="p3" d="M22 30 C18 22, 26 20, 22 12"/>
        </svg>
        <em>here.</em>
      </span>
    </h1>
    <p class="subcopy">All under one roof, like a good cup of coffee should be.</p>
  </div>

  <!-- RIGHT PANEL -->
  <div class="right">
    <div class="right-grain"></div>
    <div class="right-glow"></div>
    <div class="bean-accent ba-1" aria-hidden="true"><svg viewBox="0 0 70 100"><ellipse cx="35" cy="50" rx="28" ry="48" stroke-width="3"/><path d="M35 6 C27 30, 43 70, 35 94" stroke-width="3"/></svg></div>
    <div class="bean-accent ba-2" aria-hidden="true"><svg viewBox="0 0 70 100"><ellipse cx="35" cy="50" rx="28" ry="48" stroke-width="3"/><path d="M35 6 C27 30, 43 70, 35 94" stroke-width="3"/></svg></div>
    <div class="bean-accent ba-3" aria-hidden="true"><svg viewBox="0 0 70 100"><ellipse cx="35" cy="50" rx="28" ry="48" stroke-width="3"/><path d="M35 6 C27 30, 43 70, 35 94" stroke-width="3"/></svg></div>

    <a class="back-btn" href="../auth/Landing_Page.php" title="Go back home" aria-label="Go back home">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M18.36 6.64a9 9 0 1 1-12.73 0" />
        <line x1="12" y1="2" x2="12" y2="12" />
      </svg>
    </a>

    <div class="card" id="card">
      <div class="badge">
        <svg class="badge-steam" viewBox="0 0 20 20" aria-hidden="true">
          <path class="s1" d="M6 18 C3 13, 8 11, 6 6"/>
          <path class="s2" d="M13 18 C10 13, 15 11, 13 6"/>
        </svg>
        <svg viewBox="0 0 24 24"><path d="M6 10h11a3 3 0 010 6H6z"/><path d="M6 10v6a3 3 0 003 3h4a3 3 0 003-3"/><path d="M6 10V7"/></svg>
      </div>
      <h1>Login</h1>
      <p class="sub">Enter your credentials to continue</p>
      <div class="divider"><span></span><span></span><span></span></div>

      <?php if ($error): ?>
        <div class="error-banner"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form class="login-form" method="POST" action="">
        <div class="field f1">
          <label for="username">Username</label>
          <div class="input-wrap">
            <span class="field-icon"><svg viewBox="0 0 24 24" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/></svg></span>
            <input type="text" id="username" name="username" autocomplete="username" required autofocus
              value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username'], ENT_QUOTES, 'UTF-8') : '' ?>" />
          </div>
        </div>
        <div class="field f2">
          <label for="password">Password</label>
          <div class="input-wrap">
            <span class="field-icon"><svg viewBox="0 0 24 24" stroke-width="1.8"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></svg></span>
            <input type="password" id="password" name="password" autocomplete="current-password" required />
            <button type="button" id="togglePassword" class="eye" tabindex="-1" aria-label="Show password">
              <svg viewBox="0 0 24 24"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
        <button type="submit" class="submit" id="submitBtn">
          <span class="btn-label">Log In</span>
          <span class="spinner"></span>
          <svg class="check" viewBox="0 0 24 24"><path d="M4 12l6 6L20 6"/></svg>
        </button>
      </form>
      <p class="foot">Trouble logging in? Ask your administrator.</p>
    </div>
    <p class="page-footer">CloudCup POS &middot; <b>internal use only</b></p>
  </div>

  <script src="../js/auth.js?v=3"></script>
  <script src="../js/cloudcup_login_fx.js"></script>

</body>

</html>
