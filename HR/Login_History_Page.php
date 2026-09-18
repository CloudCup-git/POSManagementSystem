<?php
require_once __DIR__ . '/../admin/Permissions.php';
require_hr_login();
require_once __DIR__ . '/../includes/DB_Connect.php';

// ── Login History — self-service, every role ────────────────────
// Reads the same `activity_log` table the rest of the app already writes
// business-changing actions into (see includes/Activity_Log.php); auth/Login_Page.php
// now logs a 'login' row on every successful sign-in. Scoped to the
// current user's own rows only — this is a personal "when/where did I
// sign in" view, not the admin-wide audit trail.
$my_id     = current_hr_user_id();
$full_name = $_SESSION['full_name'] ?? 'Account';

$logins = [];
$res = mysqli_prepare($conn,
    "SELECT created_at, details FROM activity_log
     WHERE actor_id = ? AND domain = 'system' AND action = 'login'
     ORDER BY created_at DESC LIMIT 50");
if ($res) {
    mysqli_stmt_bind_param($res, 'i', $my_id);
    mysqli_stmt_execute($res);
    $result = mysqli_stmt_get_result($res);
    while ($row = mysqli_fetch_assoc($result)) {
        $meta = json_decode($row['details'] ?? '', true) ?: [];
        $logins[] = [
            'created_at' => $row['created_at'],
            'ip'         => $meta['ip'] ?? '',
            'ua'         => $meta['user_agent'] ?? '',
        ];
    }
    mysqli_stmt_close($res);
}

// Quick, dependency-free "browser · OS" summary from the raw user-agent
// string — good enough for a self-service list, not meant to be exact.
function login_history_describe_ua(string $ua): string {
    if ($ua === '') return 'Unknown device';
    $browser = 'Unknown browser';
    if (stripos($ua, 'Edg/') !== false) $browser = 'Edge';
    elseif (stripos($ua, 'Chrome/') !== false && stripos($ua, 'Chromium') === false) $browser = 'Chrome';
    elseif (stripos($ua, 'Firefox/') !== false) $browser = 'Firefox';
    elseif (stripos($ua, 'Safari/') !== false && stripos($ua, 'Chrome') === false) $browser = 'Safari';

    $os = 'Unknown OS';
    if (stripos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (stripos($ua, 'Mac OS') !== false) $os = 'macOS';
    elseif (stripos($ua, 'Android') !== false) $os = 'Android';
    elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) $os = 'iOS';
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';

    return $browser . ' · ' . $os;
}

$active_page = 'hr_login_history';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="../js/tab_session_guard.js"></script>
  <script>(function(){try{var t=localStorage.getItem('cloudcup-theme')||'light';document.documentElement.classList.toggle('dark-mode',t==='dark');document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="stylesheet" href="../css/theme.css"/>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Login History — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/hr_module.css"/>
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    .lh-page { max-width: 720px; margin: 0 auto; }
    .lh-card {
      background: var(--white); border-radius:20px; padding:6px 22px;
      box-shadow: 0 8px 28px rgba(11,30,51,0.07); border:1px solid rgba(44,92,130,0.05);
    }
    .lh-intro { font-size:13px; color:var(--text-light,#2f6690); margin:0 0 18px; }
    .lh-row { display:flex; align-items:center; justify-content:space-between; gap:14px; padding:16px 0; border-bottom:1px solid var(--cream,#f4e3d3); }
    .lh-row:last-child { border-bottom:none; }
    .lh-left { display:flex; align-items:center; gap:12px; min-width:0; }
    .lh-icon {
      width:36px; height:36px; border-radius:10px; flex-shrink:0;
      background:var(--cream-light,#faf8f4); color:var(--caramel);
      display:flex; align-items:center; justify-content:center;
    }
    .lh-icon svg { width:16px; height:16px; }
    .lh-text strong { display:block; font-size:13.5px; font-weight:700; color:var(--text,#161009); }
    .lh-text span { font-size:11.5px; color:var(--text-light,#2f6690); }
    .lh-time { font-size:12px; color:var(--text-light,#2f6690); text-align:right; white-space:nowrap; flex-shrink:0; }
    .lh-empty { text-align:center; padding:50px 20px; color:var(--text-light,#2f6690); font-size:13px; }
    .lh-empty svg { width:34px; height:34px; margin-bottom:10px; color:var(--cream,#f4e3d3); }
  </style>
</head>
<body>
<?php
  $_lh_sidebar = match (current_role()) {
      'admin'           => '/../admin/Sidebar_Admin.php',
      'manager'         => '/../manager/Sidebar_Manager.php',
      'hr_admin'        => '/Sidebar_HR.php',
      'finance'         => '/../finance/includes/finance_sidebar.php',
      'marketing'       => '/../marketing/includes/marketing_sidebar.php',
      'inventory_staff' => '/../includes/Sidebar_Inventory_Staff.php',
      default           => '/../staff/Sidebar_Employee.php',
  };
  if (current_role() === 'finance' && !isset($rangeQuery)) {
      $rangeQuery = '';
  }
  require_once __DIR__ . $_lh_sidebar;
?>
<script src="../js/lucide-init.js"></script>
<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <h1>Login History</h1>
    </div>
  </div>
  <div class="content lh-page">
    <p class="lh-intro">Recent sign-ins to your account, most recent first. If anything here doesn&rsquo;t look like you, change your password right away.</p>
    <div class="lh-card">
      <?php if (empty($logins)): ?>
        <div class="lh-empty">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <div>No login history recorded yet. New sign-ins will start showing up here.</div>
        </div>
      <?php else: foreach ($logins as $i => $l): ?>
        <div class="lh-row">
          <div class="lh-left">
            <div class="lh-icon"><i data-lucide="log-in"></i></div>
            <div class="lh-text">
              <strong><?= $i === 0 ? 'Current session' : 'Signed in' ?> · <?= htmlspecialchars(login_history_describe_ua($l['ua'])) ?></strong>
              <span><?= htmlspecialchars($l['ip'] ?: 'IP unavailable') ?></span>
            </div>
          </div>
          <div class="lh-time"><?= date('M j, Y', strtotime($l['created_at'])) ?><br><?= date('g:i A', strtotime($l['created_at'])) ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
<script>lucide.createIcons();</script>
<script src="../js/theme-toggle.js"></script>
</body>
</html>
