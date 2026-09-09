<?php
// Buffer everything from here on. If anything upstream (DB_Connect.php,
// a stray warning, whitespace before this tag, etc.) prints text before
// we're ready to answer an AJAX request, we can wipe it out below and
// send back clean JSON instead of JSON-with-garbage-in-front-of-it.
ob_start();

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

// Never let the browser serve this page from its cache or bfcache.
// Without this, mashing the Back button can land on a frozen snapshot
// of this page from before the redirect-if-already-authed script had a
// chance to run (or interrupt it mid-navigation) — forcing a fresh
// fetch every time means that script reliably runs on every single
// Back press, no matter how many in a row.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

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

// Requests sent via the fetch() call below carry this header, so the
// same endpoint can answer with JSON instead of re-rendering the whole
// page — that's what lets a wrong password show inline without a reload.
$is_ajax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

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

    if (!$user || !password_verify($password, $user['password'] ?? '')) {
      $error = 'Incorrect Username or Password.';
     }elseif ((int)($user['is_active'] ?? 1) === 0) {
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

      // AJAX path: hand the target back as JSON. The page never reloads —
      // the client marks this tab as authenticated and navigates itself.
      if ($is_ajax) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'target' => $target, 'firstName' => $parts[0]]);
        exit;
      }
      ?>
      <!DOCTYPE html>
      <html lang="en">
      <head>
        <meta charset="UTF-8" />
        <title>Signing in…</title>
        <script>
          // Mark this browser as authenticated for every tab — see
          // js/tab_session_guard.js. Also remember where we landed, so
          // Landing_Page.php can jump straight here in one hop if Back
          // is pressed later, instead of bouncing through this page
          // again first.
          localStorage.setItem('cc_authed', '1');
          localStorage.setItem('cc_authed_target', <?= json_encode($target) ?>);
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

  // AJAX path: every failure above (bad input, no DB, bad credentials,
  // deactivated account) lands here with $error set. Answer with JSON
  // instead of falling through to a full page re-render.
  if ($is_ajax) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
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
    // Server thinks a session is active — confirm this browser actually
    // holds the shared login marker (see js/tab_session_guard.js) before
    // skipping the form.
    //
    // Also re-run this on 'pageshow' with persisted=true: pressing Back
    // into this page restores it straight from the browser's bfcache,
    // which does NOT re-execute a normal <script> block, only fires
    // pageshow — without this, Back could leave an already-logged-in
    // user staring at the login form instead of their dashboard.
    function _ccMaybeSkipLogin() {
      if (localStorage.getItem('cc_authed') === '1') {
        localStorage.setItem('cc_authed_target', <?= json_encode($already_authed_target) ?>);
        window.location.replace(<?= json_encode($already_authed_target) ?>);
      }
    }
    _ccMaybeSkipLogin();
    window.addEventListener('pageshow', function (e) {
      if (e.persisted) _ccMaybeSkipLogin();
    });
    // Belt-and-suspenders for browsers/back-forward implementations that
    // restore a cached page without firing 'pageshow' at all.
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') _ccMaybeSkipLogin();
    });
    window.addEventListener('focus', _ccMaybeSkipLogin);
  </script>
  <?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,500&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/cloudcup_login.css" />
  <style>
    /* Inline error state, added for the AJAX login flow — additive only,
       shouldn't collide with anything already in cloudcup_login.css. */
    .input-wrap.field-invalid input {
      border-color: #b3452c !important;
      box-shadow: 0 0 0 3px rgba(179, 69, 44, 0.14) !important;
    }
    .card.shake {
      /* Keep the page's cardIn entrance animation running alongside the
         shake, instead of the 'animation' shorthand replacing it outright.
         cardIn is what holds the card at opacity:1 (via forwards) after
         its initial fade-in — replacing it here was dropping the card
         back to its base opacity:0 the moment a wrong password shook it. */
      animation: cardIn .7s cubic-bezier(.2,.7,.2,1) .25s forwards,
                 cc-shake 0.4s ease;
    }
    @keyframes cc-shake {
      10%, 90% { transform: translateX(-1px); }
      20%, 80% { transform: translateX(2px); }
      30%, 50%, 70% { transform: translateX(-4px); }
      40%, 60% { transform: translateX(4px); }
    }
    #submitBtn[data-state="idle"] .btn-icon { display: none; }
    #submitBtn[data-state="loading"] .btn-label,
    #submitBtn[data-state="success"] .btn-label { opacity: 0; }
    #submitBtn[data-state="loading"] .btn-icon,
    #submitBtn[data-state="success"] .btn-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    #submitBtn[data-state="loading"] .spinner { opacity: 1; transform: scale(1); }
    #submitBtn[data-state="success"] .spinner { opacity: 0; transform: scale(.6); }
    #submitBtn[data-state="success"] .check { opacity: 1; transform: scale(1); }
    #submitBtn[data-state="success"] .check path {
      animation: draw .35s ease .1s forwards;
    }
    #submitBtn[data-state="success"] { background: var(--sage-dark); }
    .error-banner {
      opacity: 0;
      max-height: 0;
      margin-bottom: 0;
      padding-top: 0;
      padding-bottom: 0;
      border-width: 0;
      transform: translateY(-6px);
      overflow: hidden;
      transition: opacity .25s ease, transform .25s ease, max-height .35s ease,
        padding .35s ease, margin .35s ease, border-width .35s ease;
    }
    .error-banner.visible {
      opacity: 1;
      max-height: 80px;
      margin: 0 0 20px;
      padding: 11px 14px;
      border-width: 1px;
      transform: translateY(0);
    }

    /* ── LOGIN TRANSITION OVERLAY ──────────────────────────────────
       Same visual language as Logout_Page.php's "signing out" screen
       (dark card, mug + steam, status line that crosses over into a
       checkmark) so the two ends of a session bookend each other.
       The mug fills instead of draining, and it points at the target
       dashboard instead of back to the login form. Keeps its own
       --lt- prefixed variables/classes/keyframes so nothing here can
       collide with the light-theme login card above. */
    .login-transition{
      position:fixed; inset:0; z-index:50;
      display:none; align-items:center; justify-content:center;
      opacity:0;
      transition: opacity .4s ease;
      background:
        radial-gradient(ellipse 900px 600px at 50% 20%, rgba(98,142,144,0.12), transparent 60%),
        #241609;
      font-family:'Work Sans', 'Inter', sans-serif;
    }
    .login-transition.active{ display:flex; }
    .login-transition.active.fade-in{ opacity:1; }
    .login-transition::before{
      content:''; position:absolute; inset:0;
      background-image:radial-gradient(circle, rgba(245,239,230,0.035) 1px, transparent 1px);
      background-size:26px 26px;
      pointer-events:none;
    }
    .lt-card{
      position:relative; width:380px; max-width:90vw;
      padding:52px 40px 40px;
      background:linear-gradient(180deg, #3c2317, #4f3021);
      border-radius:6px;
      border:1px solid rgba(242,226,200,0.08);
      box-shadow:0 40px 80px -30px rgba(0,0,0,.6), 0 0 0 1px rgba(0,0,0,.2);
      text-align:center;
      opacity:0;
      animation: ltCardIn .6s ease-out forwards, ltCardOut .5s ease-in 2.6s forwards;
    }
    @keyframes ltCardIn{ 0%{opacity:0; transform:translateY(6px);} 100%{opacity:1; transform:translateY(0);} }
    @keyframes ltCardOut{ 0%{opacity:1; transform:translateY(0);} 100%{opacity:0; transform:translateY(-4px);} }
    .lt-card::before{
      content:''; position:absolute; top:0; left:10%; right:10%; height:2px;
      background:linear-gradient(90deg, transparent, #7fa6a8, transparent);
      opacity:.7;
    }
    .lt-stage{ position:relative; width:180px; height:150px; margin:0 auto 28px; }
    .lt-steam{ position:absolute; top:-46px; left:0; width:100%; height:56px; animation:ltSteamLife 1.6s ease-in-out .4s forwards; opacity:0; }
    .lt-steam path{ fill:none; stroke:#b4cde6; stroke-width:2.5; stroke-linecap:round; opacity:0; }
    .lt-steam .s1{ animation:ltRise 1.6s ease-in .4s forwards; }
    .lt-steam .s2{ animation:ltRise 1.6s ease-in .7s forwards; }
    .lt-steam .s3{ animation:ltRise 1.6s ease-in 1.0s forwards; }
    @keyframes ltRise{ 0%{opacity:0; transform:translateY(6px) scaleY(.8);} 22%{opacity:.55;} 80%{opacity:.12;} 100%{opacity:0; transform:translateY(-30px) scaleY(1.15);} }
    @keyframes ltSteamLife{ 0%{opacity:1;} 75%{opacity:1;} 100%{opacity:0;} }
    .lt-mug-wrap{ position:absolute; bottom:14px; left:50%; transform:translateX(-50%); width:150px; height:104px; }
    /* The liquid is a body rect + a wavy top edge riding together in one
       group: the group's own translateY animates the fill level (empty
       at the mug's bottom up to the resting "full" line), while the
       wave path underneath it scrolls sideways on a loop, so the
       surface actually ripples instead of sitting flat as it rises. */
    .lt-liquid-group{
      transform-box: fill-box;
      transform: translateY(73px);
      animation: ltFill 1.6s cubic-bezier(.65,0,.35,1) forwards;
    }
    @keyframes ltFill{ 0%{transform:translateY(73px);} 15%{transform:translateY(73px);} 78%{transform:translateY(0);} 100%{transform:translateY(0);} }
    .lt-wave{ animation: ltWaveMove 1.8s linear infinite; }
    @keyframes ltWaveMove{ from{transform:translateX(0);} to{transform:translateX(-30px);} }
    .lt-ripple{ opacity:0; transform-origin:center; animation: ltRippleGrow .6s ease-out 1.55s forwards; }
    @keyframes ltRippleGrow{ 0%{opacity:0; transform:scale(.4);} 20%{opacity:.6; transform:scale(.65);} 100%{opacity:0; transform:scale(1.3);} }
    .lt-card h1{ font-family:'Fraunces', serif; font-weight:500; font-size:26px; letter-spacing:.2px; margin:0 0 10px; color:#f5efe6; }
    .lt-sub{ position:relative; margin:0; height:20px; font-size:14px; line-height:20px; }
    .lt-sub .lt-msg{ position:absolute; top:0; left:0; right:0; color:#b4cde6; white-space:nowrap; }
    .lt-msg-progress{ opacity:1; animation: ltFadeOut .35s ease-in 1.85s forwards; }
    .lt-msg-done{ opacity:0; animation: ltFadeIn .4s ease-out 2.0s forwards; }
    @keyframes ltFadeOut{ to{opacity:0;} }
    @keyframes ltFadeIn{ to{opacity:1;} }
    .lt-status-icon{ position:relative; display:inline-block; width:16px; height:16px; margin-left:6px; vertical-align:-3px; }
    .lt-dots{ position:absolute; inset:0; display:inline-flex; align-items:center; gap:5px; animation: ltFadeOut .3s ease-in 1.85s forwards; }
    .lt-dots span{ width:4px; height:4px; border-radius:50%; background:#7fa6a8; display:inline-block; animation: ltDotPulse 1.4s ease-in-out infinite; }
    .lt-dots span:nth-child(2){ animation-delay:.18s; }
    .lt-dots span:nth-child(3){ animation-delay:.36s; }
    @keyframes ltDotPulse{ 0%,80%,100%{opacity:.25; transform:translateY(0);} 40%{opacity:1; transform:translateY(-2px);} }
    .lt-check{ position:absolute; inset:0; opacity:0; transform:scale(.6); animation: ltCheckIn .4s cubic-bezier(.34,1.56,.64,1) 2.0s forwards; }
    @keyframes ltCheckIn{ to{opacity:1; transform:scale(1);} }
    .lt-check circle{ fill:none; stroke:#7fa6a8; stroke-width:1.6; stroke-dasharray:44; stroke-dashoffset:44; animation: ltDrawRing .45s ease-out 2.0s forwards; }
    .lt-check path{ fill:none; stroke:#7fa6a8; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; stroke-dasharray:12; stroke-dashoffset:12; animation: ltDrawTick .3s ease-out 2.25s forwards; }
    @keyframes ltDrawRing{ to{stroke-dashoffset:0;} }
    @keyframes ltDrawTick{ to{stroke-dashoffset:0;} }
    @media (prefers-reduced-motion: reduce){
      .login-transition *{ animation:none !important; }
      .lt-card{ opacity:1; }
      .lt-steam{ opacity:0; }
      .lt-liquid-group{ transform:translateY(0); }
      .lt-msg-progress{ opacity:0; }
      .lt-msg-done{ opacity:1; }
      .lt-dots{ opacity:0; }
      .lt-check{ opacity:1; transform:scale(1); }
      .lt-check circle, .lt-check path{ stroke-dashoffset:0; }
    }
  </style>
</head>

<body>

  <!-- LOGIN TRANSITION OVERLAY (shown on successful login, mirrors Logout_Page.php) -->
  <div class="login-transition" id="loginTransition" aria-hidden="true">
    <div class="lt-card">
      <div class="lt-stage">
        <svg class="lt-steam" viewBox="0 0 150 56">
          <path class="s1" d="M55 50 C 48 40, 62 34, 55 24 C 49 15, 60 10, 56 2" />
          <path class="s2" d="M75 50 C 68 39, 83 33, 75 22 C 69 13, 81 8, 76 0" />
          <path class="s3" d="M95 50 C 88 40, 102 34, 95 24 C 89 15, 100 10, 96 2" />
        </svg>
        <svg class="lt-mug-wrap" viewBox="0 0 150 104">
          <ellipse cx="75" cy="96" rx="62" ry="6" fill="#140d08"/>
          <ellipse cx="75" cy="94" rx="58" ry="5.5" fill="#241609"/>
          <ellipse class="lt-ripple" cx="75" cy="14" rx="16" ry="4.5" fill="none" stroke="#628e90" stroke-width="1.4"/>
          <path d="M124 30 C 148 30, 148 62, 124 62" fill="none" stroke="#e3d9c8" stroke-width="8" stroke-linecap="round"/>
          <path d="M124 30 C 148 30, 148 62, 124 62" fill="none" stroke="#cbb89e" stroke-width="8" stroke-linecap="round" opacity="0.35" stroke-dasharray="1 200"/>
          <path d="M20 12 L 128 12 L 121 74 C 121 84, 108 90, 74 90 C 40 90, 27 84, 27 74 Z"
                fill="#f5efe6" stroke="#b4cde6" stroke-width="1.5"/>
          <clipPath id="ltMugInner">
            <path d="M23.5 15 L 124.5 15 L 118 73 C 118 81.5, 106 87, 74 87 C 42 87, 30 81.5, 30 73 Z"/>
          </clipPath>
          <g clip-path="url(#ltMugInner)">
            <g class="lt-liquid-group">
              <path class="lt-wave" d="M -20,14 Q -5,9 10,14 T 40,14 T 70,14 T 100,14 T 130,14 T 160,14 V 104 H -20 Z" fill="#2c1a10"/>
            </g>
          </g>
          <path d="M20 12 L 128 12" stroke="#fffaf1" stroke-width="2" stroke-linecap="round" opacity="0.7"/>
        </svg>
      </div>
      <h1>Signing in</h1>
      <p class="lt-sub">
        <span class="lt-msg lt-msg-progress">Brewing your session
          <span class="lt-status-icon">
            <span class="lt-dots"><span></span><span></span><span></span></span>
          </span>
        </span>
        <span class="lt-msg lt-msg-done"><span id="ltWelcome">Welcome back!</span>
          <span class="lt-status-icon">
            <svg class="lt-check" viewBox="0 0 16 16" width="16" height="16">
              <circle cx="8" cy="8" r="7" />
              <path d="M4.5 8.3 L7 10.8 L11.5 5.8" />
            </svg>
          </span>
        </span>
      </p>
    </div>
  </div>

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
        <svg viewBox="0 0 24 24"><path d="M6 8h9a1 1 0 011 1v5a4 4 0 01-4 4h-3a4 4 0 01-4-4v-6z"/><path d="M16 10h1.5a2.5 2.5 0 010 5H16"/><path d="M6 6h9"/></svg>
      </div>
      <h1>Login</h1>
      <p class="sub">Enter your credentials to continue</p>
      <div class="divider"><span></span><span></span><span></span></div>

      <div class="error-banner<?= $error ? ' visible' : '' ?>" id="errorBanner">
        <?= htmlspecialchars($error) ?>
      </div>

      <form class="login-form" method="POST" action="" id="loginForm" novalidate>
        <div class="field f1">
          <label for="username">Username</label>
          <div class="input-wrap" id="usernameWrap">
            <span class="field-icon"><svg viewBox="0 0 24 24" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/></svg></span>
            <input type="text" id="username" name="username" autocomplete="username" required autofocus
              value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username'], ENT_QUOTES, 'UTF-8') : '' ?>" />
          </div>
        </div>
        <div class="field f2">
          <label for="password">Password</label>
          <div class="input-wrap" id="passwordWrap">
            <span class="field-icon"><svg viewBox="0 0 24 24" stroke-width="1.8"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></svg></span>
            <input type="password" id="password" name="password" autocomplete="current-password" required />
            <button type="button" id="togglePassword" class="eye" tabindex="-1" aria-label="Show password">
              <svg viewBox="0 0 24 24"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
        <button type="submit" class="submit" id="submitBtn" data-state="idle">
          <span class="btn-label">Log In</span>
          <span class="btn-icon">
            <span class="spinner"></span>
            <svg class="check" viewBox="0 0 24 24"><path d="M4 12l6 6L20 6"/></svg>
          </span>
        </button>
      </form>
      <p class="foot">Trouble logging in? Ask your administrator.</p>
    </div>
    <p class="page-footer">CloudCup POS &middot; <b>internal use only</b></p>
  </div>

  <script src="../js/auth.js?v=3"></script>
  <script src="../js/cloudcup_login_fx.js"></script>
  <script>
    // AJAX submit: keeps the page from reloading, so a wrong password
    // just shows inline instead of flashing a fresh page load.
    (function () {
      var form       = document.getElementById('loginForm');
      var submitBtn  = document.getElementById('submitBtn');
      var errorBox   = document.getElementById('errorBanner');
      var userWrap   = document.getElementById('usernameWrap');
      var passWrap   = document.getElementById('passwordWrap');
      var passInput  = document.getElementById('password');
      var card       = document.getElementById('card');

      if (!form) return;

      function clearInvalid() {
        userWrap.classList.remove('field-invalid');
        passWrap.classList.remove('field-invalid');
      }

      function showError(message) {
        errorBox.textContent = message;
        errorBox.classList.add('visible');
        userWrap.classList.add('field-invalid');
        passWrap.classList.add('field-invalid');
        card.classList.remove('shake');
        // restart the shake animation even if it just played
        void card.offsetWidth;
        card.classList.add('shake');
      }

      function hideError() {
        errorBox.classList.remove('visible');
      }

      form.addEventListener('input', function () {
        if (errorBox.classList.contains('visible')) {
          hideError();
          clearInvalid();
        }
      });

      // Real recorded pour SFX (trimmed from a Foley pour recording),
      // same pattern as PRINTER_SOUND_SRC in js/sales_processing.js.
      var POUR_SOUND_SRC = '../sounds/coffee-pour.mp3';
      var pourAudioEl = null;
      function playPourSound() {
        try {
          if (!pourAudioEl) {
            pourAudioEl = new Audio(POUR_SOUND_SRC);
            pourAudioEl.preload = 'auto';
          }
          pourAudioEl.currentTime = 0;
          pourAudioEl.volume = 0.6;
          var playPromise = pourAudioEl.play();
          if (playPromise && playPromise.catch) {
            playPromise.catch(function () { /* autoplay blocked; ignore */ });
          }
        } catch (e) { /* audio unavailable — silent transition still works */ }
      }

      function runLoginTransition(target, firstName) {
        var overlay = document.getElementById('loginTransition');
        var welcomeEl = document.getElementById('ltWelcome');
        if (welcomeEl) {
          welcomeEl.textContent = firstName ? ('Welcome back, ' + firstName + '!') : 'Welcome back!';
        }
        if (!overlay) {
          window.location.replace(target);
          return;
        }
        overlay.classList.add('active');
        overlay.setAttribute('aria-hidden', 'false');
        // display just flipped to flex, which is also what starts the
        // mug/steam/checkmark animations — wait a couple of frames before
        // fading the backdrop in so that start lines up with what's visible,
        // instead of the overlay easing in on an already-finished animation.
        requestAnimationFrame(function () {
          requestAnimationFrame(function () {
            overlay.classList.add('fade-in');
          });
        });
        // Mirrors Logout_Page.php: let the pour, the checkmark, and the
        // card's own fade-out finish before navigating.
        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (!reduceMotion) {
          // Fill animation (ltFill) sits idle until 15% of its 1.6s, then
          // pours through to 78% — line the sound up with that window.
          setTimeout(playPourSound, 240);
        }
        setTimeout(function () {
          window.location.replace(target);
        }, reduceMotion ? 700 : 3100);
      }

      form.addEventListener('submit', function (evt) {
        evt.preventDefault();

        submitBtn.disabled = true;
        submitBtn.dataset.state = 'loading';

        fetch('', {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: new FormData(form)
        })
          .then(function (res) {
            return res.text().then(function (raw) {
              var data;
              try {
                data = JSON.parse(raw);
              } catch (parseErr) {
                // The response wasn't clean JSON — most likely a PHP
                // notice/warning got printed ahead of it. Surfacing this
                // beats silently falling back to a real submit, which
                // just reproduces the reload with no clue why.
                console.error('Login response was not valid JSON:', raw);
                throw new Error('parse-failure');
              }
              return data;
            });
          })
          .then(function (data) {
            if (data.success) {
              submitBtn.dataset.state = 'success';
              localStorage.setItem('cc_authed', '1');
              localStorage.setItem('cc_authed_target', data.target);
              runLoginTransition(data.target, data.firstName);
            } else {
              submitBtn.dataset.state = 'idle';
              submitBtn.disabled = false;
              passInput.value = '';
              passInput.focus();
              showError(data.error || 'Something went wrong. Please try again.');
            }
          })
          .catch(function (err) {
            submitBtn.dataset.state = 'idle';
            submitBtn.disabled = false;
            if (err && err.message === 'parse-failure') {
              showError('Unexpected server response. Please try again.');
              return;
            }
            // A genuine network failure (offline, DNS, etc.) — this is the
            // only case where falling back to a real submit makes sense.
            form.submit();
          });
      });
    })();
  </script>

</body>

</html>
