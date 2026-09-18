<?php
require_once __DIR__ . '/../admin/Permissions.php';
require_hr_login();
require_once __DIR__ . '/../includes/DB_Connect.php';

// ── Self-service branch ─────────────────────────────────────────
// Every role's bottom-left sidebar profile links here with `?me=1` to see
// their own "My Account" card (profile + change password) instead of the
// full RBAC accounts table below, which stays gated behind 'manage_accounts'.
// 'employee' also lands here with no param, since that's the only page
// their role's sidebar has ever pointed the avatar at.
if (current_role() === 'employee' || isset($_GET['me'])) {
    $my_id     = current_hr_user_id();
    $full_name = $_SESSION['full_name'] ?? 'Employee';
    $own_msg   = '';

    // ── Helper: handle an uploaded profile photo ───────────────────
    // Same validation/convention as Menu_Control_Page.php's menu_upload_image()
    // — 5MB limit, jpeg/png/gif/webp only, random filename, saved under images/.
    // Returns ['ok'=>true,'path'=>string|null] (path null = no file chosen,
    // keep existing photo) or ['ok'=>false] if a file was chosen but invalid.
    function profile_upload_photo(array $file): array {
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true, 'path' => null];
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'path' => null];
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            return ['ok' => false, 'path' => null];
        }
        $allowed = ['image/jpeg' => 'jpg'];
        $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : $file['type'];
        if (!isset($allowed[$mime])) {
            return ['ok' => false, 'path' => null];
        }
        $destDir = __DIR__ . '/../images/';
        if (!is_dir($destDir)) mkdir($destDir, 0755, true);
        $filename = 'profile_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($file['tmp_name'], $destDir . $filename)) {
            return ['ok' => false, 'path' => null];
        }
        return ['ok' => true, 'path' => '../images/' . $filename];
    }

    // NOTE: assumes the `employees` table has `birth_date` (DATE) and
    // `contact_number` (VARCHAR) columns. Rename these two column names
    // below if your schema uses different ones. Also assumes `users` has
    // a `profile_photo` (VARCHAR) column — see the ALTER TABLE note below.
    if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'update_profile') {
        $birth_date   = $_POST['birth_date'] ?? '';
        $contact      = trim($_POST['contact_number'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $remove_photo = ($_POST['remove_photo'] ?? '') === '1';
        $photo        = $remove_photo ? ['ok' => true, 'path' => null] : profile_upload_photo($_FILES['profile_photo'] ?? []);

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $own_msg = 'error:Please enter a valid email address.';
        } elseif (!$photo['ok']) {
            $own_msg = 'error:Could not upload photo. Use a JPEG image under 5MB.';
        } else {
            $u = mysqli_prepare($conn, "UPDATE employees SET birth_date=?, contact_number=? WHERE employee_id=?");
            mysqli_stmt_bind_param($u, 'ssi', $birth_date, $contact, $my_id);
            $ok1 = mysqli_stmt_execute($u);

            $e = mysqli_prepare($conn, "UPDATE users SET email=? WHERE user_id=?");
            $email_or_null = $email !== '' ? $email : null;
            mysqli_stmt_bind_param($e, 'si', $email_or_null, $my_id);
            $ok3 = mysqli_stmt_execute($e);

            $ok2 = true;
            if ($remove_photo) {
                $p = mysqli_prepare($conn, "UPDATE users SET profile_photo=NULL WHERE user_id=?");
                mysqli_stmt_bind_param($p, 'i', $my_id);
                $ok2 = mysqli_stmt_execute($p);
            } elseif ($photo['path'] !== null) {
                $p = mysqli_prepare($conn, "UPDATE users SET profile_photo=? WHERE user_id=?");
                mysqli_stmt_bind_param($p, 'si', $photo['path'], $my_id);
                $ok2 = mysqli_stmt_execute($p);
            }

            $own_msg = ($ok1 && $ok2 && $ok3) ? 'success:Profile updated.' : 'error:Could not update profile. Please try again.';
        }
    }

    if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'change_own_password') {
        $current  = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        $s2 = mysqli_prepare($conn, "SELECT password FROM users WHERE user_id = ?");
        mysqli_stmt_bind_param($s2, 'i', $my_id);
        mysqli_stmt_execute($s2);
        $res2 = mysqli_stmt_get_result($s2);
        $row  = $res2 ? mysqli_fetch_assoc($res2) : null;

        if (!$row || !password_verify($current, $row['password'])) {
            $own_msg = 'error:Current password is incorrect.';
        } elseif (strlen($new) < 6) {
            $own_msg = 'error:New password must be at least 6 characters.';
        } elseif ($new !== $confirm) {
            $own_msg = 'error:New password and confirmation do not match.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $u = mysqli_prepare($conn, "UPDATE users SET password=? WHERE user_id=?");
            mysqli_stmt_bind_param($u, 'si', $hash, $my_id);
            mysqli_stmt_execute($u);
            $own_msg = 'success:Password updated.';
        }
    }

    // Guard against a schema mismatch (missing column, etc.) instead of a
    // white-screen fatal error: mysqli_query() returns false on a bad query,
    // and mysqli_fetch_assoc(false) is what throws the TypeError. Log the
    // real SQL error for debugging, show the page with safe defaults.
    $profile_query = mysqli_query($conn,
        "SELECT e.birth_date, e.contact_number, e.position, e.department, e.date_hired,
                e.vacation_leave_balance, e.sick_leave_balance, u.profile_photo, u.email
         FROM employees e
         JOIN users u ON u.user_id = e.employee_id
         WHERE e.employee_id = $my_id");
    if ($profile_query === false) {
        error_log('Employee_Accounts_Page profile query failed: ' . mysqli_error($conn));
        $my_profile = [];
        if (!$own_msg) {
            $own_msg = 'error:Could not load your full profile (a database column may be missing). Contact your admin.';
        }
    } else {
        $my_profile = mysqli_fetch_assoc($profile_query) ?: [];
    }

    // Tenure — "X mos" / "X yrs Y mos" since date_hired, for the profile stat.
    $tenure_label = '—';
    if (!empty($my_profile['date_hired'])) {
        $hired = new DateTime($my_profile['date_hired']);
        $diff  = $hired->diff(new DateTime('today'));
        $months = ($diff->y * 12) + $diff->m;
        if ($diff->y >= 1) {
            $tenure_label = $diff->y . 'y ' . $diff->m . 'm';
        } elseif ($months >= 1) {
            $tenure_label = $months . ' mo' . ($months === 1 ? '' : 's');
        } else {
            $tenure_label = $diff->d . ' day' . ($diff->d === 1 ? '' : 's');
        }
    }

    $initials = strtoupper(substr($full_name, 0, 1));
    $tagline  = trim((($my_profile['position'] ?? '') ?: 'Staff Member') . (!empty($my_profile['department']) ? ' · ' . $my_profile['department'] : ''));

    $member_since = '';
    if (!empty($my_profile['date_hired'])) {
        $member_since = 'Since ' . (new DateTime($my_profile['date_hired']))->format('M Y');
    }

    // Today's attendance status, for the quick indicator next to the
    // Attendance quicklink — same table/columns Attendance_Page.php uses.
    $att_query = mysqli_query($conn,
        "SELECT time_in, time_out FROM attendance WHERE employee_id = $my_id AND work_date = CURDATE()");
    $today_att = $att_query ? (mysqli_fetch_assoc($att_query) ?: []) : [];
    if (!empty($today_att['time_out'])) {
        $attendance_status = 'done';
        $attendance_label  = 'Shift complete';
    } elseif (!empty($today_att['time_in'])) {
        $attendance_status = 'in';
        $attendance_label  = 'Clocked in';
    } else {
        $attendance_status = 'out';
        $attendance_label  = 'Not clocked in';
    }
    // Which tab to show first — reopen Security after a failed/successful
    // password change instead of always resetting to Profile.
    $active_tab = (($_POST['act'] ?? '') === 'change_own_password') ? 'security' : 'profile';
    $active_page = 'hr_account';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
  <script src="../js/tab_session_guard.js"></script>
  <script>(function(){try{var t=localStorage.getItem('cloudcup-theme')||'light';document.documentElement.classList.toggle('dark-mode',t==='dark');document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="stylesheet" href="../css/theme.css"/>
      <meta charset="UTF-8"/>
      <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
      <title>My Account — Cloud Cup</title>
      <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
      <link rel="stylesheet" href="../css/admin_page.css"/>
      <link rel="stylesheet" href="../css/hr_module.css"/>
      <script src="https://unpkg.com/lucide@latest"></script>
      <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
      <style>
        /* ── My Account v6 — a 3-card dashboard layout (tall profile card
           on the left, two stacked list cards on the right), modeled after
           a reference "My profile / My xPay accounts / My bills" mockup.
           Same color tokens as the rest of the app (caramel/gold gradient
           for the primary action instead of the reference's pink/orange),
           set on a soft cream page background so the white cards pop the
           way they do in the reference. Scoped under .ma- so nothing here
           touches admin_page.css / hr_module.css. ── */
        /* Fixed at one consistent size on every screen — this was
           previously stretched wider on big monitors trying to "use the
           space", but that just made the cards a different size depending
           on the viewer's window. Keep it pinned to the reference size
           instead, same for every role/screen (it'll just sit centered
           with more cream margin on a wide screen, which is fine). */
        .ma-page { max-width: 980px; margin: 0 auto; }

        .ma-grid { display:grid; grid-template-columns: 1fr 1.3fr; gap:20px; align-items:start; }
        @media (max-width: 820px) {
          .ma-grid { grid-template-columns: 1fr; }
        }

        .ma-card {
          background: var(--white); border-radius:20px; padding:28px;
          box-shadow: 0 8px 28px rgba(11,30,51,0.07); border:1px solid rgba(44,92,130,0.05);
        }

        /* ── Left: My Profile card ── */
        /* .ma-profile-photo is the positioning wrapper (so the camera
           button/menu can overlap the frame's corner); #myacctAvatarBox is
           the actual rounded image frame the photo-preview JS replaces the
           contents of — kept separate so swapping in a preview <img> never
           wipes out the camera button/menu next to it. */
        .ma-profile-photo { position:relative; }
        .ma-profile-photo #myacctAvatarBox {
          width:100%; aspect-ratio: 1 / 1; border-radius:16px; overflow:hidden;
          background: var(--brown-dark);
          display:flex; align-items:center; justify-content:center;
        }
        .ma-profile-photo img { width:100%; height:100%; object-fit:cover; }
        .ma-profile-photo span { font-size:76px; font-weight:700; color: var(--gold); font-family:'Fraunces', serif; }
        .ma-photo-btn {
          position:absolute; bottom:12px; right:12px; z-index:3;
          width:38px; height:38px; border-radius:50%;
          background: var(--white); color:var(--brown-mid,#2a2016); border:none;
          display:flex; align-items:center; justify-content:center; cursor:pointer;
          box-shadow: 0 3px 10px rgba(11,30,51,0.3); transition: transform .15s;
        }
        .ma-photo-btn:hover { transform: scale(1.08); color:var(--caramel); }
        .ma-photo-btn svg { width:17px; height:17px; }
        .ma-photo-btn input[type=file] { display:none; }

        .ma-photo-menu {
          display:none; position:absolute; bottom:48px; right:10px;
          background: var(--white); border-radius:11px; overflow:hidden; min-width:172px; z-index:6;
          box-shadow:0 10px 28px rgba(11,30,51,0.25); text-align:left;
        }
        .ma-photo-menu.show { display:block; }
        .ma-photo-menu-item {
          display:flex; align-items:center; gap:10px; width:100%; padding:12px 16px;
          background:none; border:none; font-size:14px; font-weight:600;
          color:var(--text,#161009); cursor:pointer; text-align:left; transition:background .15s;
        }
        .ma-photo-menu-item:hover { background:var(--cream-light,#faf8f4); }
        .ma-photo-menu-item + .ma-photo-menu-item { border-top:1px solid var(--cream,#f4e3d3); }
        .ma-photo-menu-item svg { width:15px; height:15px; flex-shrink:0; }
        .ma-photo-menu-item.danger { color:var(--danger,#b8453a); }
        .ma-photo-menu-item.danger:hover { background:rgba(239,68,68,.06); }

        .ma-profile-head { display:flex; justify-content:space-between; align-items:flex-start; margin-top:20px; }
        .ma-profile-title { font-size:17px; font-weight:700; color:var(--text,#161009); margin:0; }
        .ma-profile-meta { font-size:12px; color:var(--text-light,#2f6690); text-align:right; line-height:1.5; }

        /* One field per row — each field's hint text (e.g. the contact
           number format hint) now sits directly under that same field
           instead of spanning under an unrelated neighbor in a shared
           two-column row. */
        .ma-field-row { display:flex; border-top:1px solid var(--cream,#f4e3d3); padding-top:16px; margin-top:16px; }
        .ma-field-row .ma-field-col { flex:1; min-width:0; }
        .ma-field-icon { display:flex; align-items:center; gap:11px; }
        .ma-field-icon svg { width:16px; height:16px; color:var(--text-light,#2f6690); flex-shrink:0; }
        .ma-field-icon input {
          border:none; background:none; padding:0; font-size:15px; font-weight:600; color:var(--text,#161009);
          width:100%; box-sizing:border-box;
        }
        .ma-field-icon input::placeholder { color:var(--text-light,#2f6690); font-weight:500; }
        .ma-field-icon input:focus { outline:none; }
        .ma-field-icon input[type=date] { color:var(--text,#161009); }
        .ma-field-hint.ma-field-hint-indent { padding-left:24px; }

        .ma-status-row {
          display:flex; align-items:center; justify-content:space-between; gap:10px;
          border-top:1px solid var(--cream,#f4e3d3); padding-top:16px; margin-top:16px;
        }
        .ma-status-left { display:flex; align-items:center; gap:9px; min-width:0; }
        .ma-status-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
        .ma-status-dot.att-in   { background:#2f6f4e; }
        .ma-status-dot.att-out  { background:#b8453a; }
        .ma-status-dot.att-done { background:var(--caramel); }
        .ma-status-label { font-size:15px; font-weight:600; color:var(--text,#161009); }

        .ma-field-hint { font-size:12.5px; color:var(--text-light,#2f6690); margin-top:5px; min-height:16px; line-height:16px; transition:color .15s; }
        .ma-field-hint.invalid { color:var(--danger,#b8453a); font-weight:600; }
        .ma-field-hint.valid { color:#2f6f4e; font-weight:600; }

        .ma-save-btn {
          width:100%; margin-top:20px; border:none; border-radius:14px; padding:16px;
          font-family:inherit; font-size:16px; font-weight:700; color:#fff; cursor:pointer;
          background: linear-gradient(90deg, var(--caramel), var(--gold));
          transition: opacity .15s, transform .15s;
        }
        .ma-save-btn:hover { transform: translateY(-1px); }
        .ma-save-btn:disabled { opacity:.65; cursor:not-allowed; pointer-events:none; transform:none; }

        /* ── Right: list cards (Leave Balances / Account & Security) ── */
        .ma-list-card-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
        .ma-list-card-head h2 { font-size:18px; font-weight:700; color:var(--text,#161009); margin:0; }
        .ma-list-card-badge {
          font-size:12px; font-weight:700; color:var(--text-light,#2f6690); background:var(--cream-light,#faf8f4);
          padding:5px 13px; border-radius:999px;
        }

        .ma-list-row {
          display:flex; align-items:center; justify-content:space-between; gap:16px;
          padding:17px 0; border-bottom:1px solid var(--cream,#f4e3d3);
        }
        .ma-list-row:last-child { border-bottom:none; }
        .ma-list-left { display:flex; align-items:center; gap:12px; min-width:0; }
        .ma-list-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; background:var(--caramel); }
        .ma-list-dot.att-in   { background:#2f6f4e; }
        .ma-list-dot.att-out  { background:#b8453a; }
        .ma-list-dot.att-done { background:var(--caramel); }
        .ma-list-text strong { display:block; font-size:15px; font-weight:700; color:var(--text,#161009); }
        .ma-list-text span { font-size:12.5px; color:var(--text-light,#2f6690); }

        .ma-pill {
          flex-shrink:0; display:inline-flex; align-items:center; justify-content:center;
          font-size:13px; font-weight:700; padding:9px 19px; border-radius:999px; color:#fff;
          white-space:nowrap; border:none; cursor:default;
          background: linear-gradient(90deg, var(--caramel), var(--gold));
        }
        .ma-pill.good { background:linear-gradient(90deg, #3fae6b, #63cf8a); }
        .ma-pill.bad  { background:linear-gradient(90deg, #d9576a, #ef7c8b); }
        .ma-pill.neutral { background:var(--cream-light,#faf8f4); color:var(--text,#161009); }
        .ma-pill.action { cursor:pointer; transition: transform .15s; }
        .ma-pill.action:hover { transform: translateY(-1px); }

        /* Password change as a centered popup, opened by the "Change"
           pill on the Account & Security row. The overlay fades in while
           the modal itself scales up from slightly-small with a small
           overshoot (cubic-bezier back-ease) for a springy "pop" feel,
           instead of the list pushing open in place. */
        .ma-modal-overlay {
          position:fixed; inset:0; z-index:50; display:flex; align-items:center; justify-content:center;
          padding:20px; background:rgba(22,16,9,0.45);
          opacity:0; visibility:hidden; transition: opacity .22s ease, visibility 0s linear .22s;
        }
        .ma-modal-overlay.show {
          opacity:1; visibility:visible; transition: opacity .22s ease, visibility 0s linear 0s;
        }
        .ma-modal {
          width:100%; max-width:420px; max-height:90vh; overflow-y:auto;
          background:var(--white); border-radius:20px; padding:26px 28px;
          box-shadow: 0 24px 60px rgba(11,30,51,0.28);
          transform: scale(.85) translateY(14px); opacity:0;
          transition: transform .3s cubic-bezier(.34,1.56,.64,1), opacity .22s ease;
        }
        .ma-modal-overlay.show .ma-modal { transform: scale(1) translateY(0); opacity:1; }
        .ma-modal-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:4px; }
        .ma-modal-head h2 { font-size:16px; font-weight:700; color:var(--text,#161009); margin:0; }
        .ma-modal-close {
          width:28px; height:28px; border-radius:50%; border:none; background:var(--cream-light,#faf8f4);
          color:var(--text-light,#2f6690); display:flex; align-items:center; justify-content:center;
          cursor:pointer; transition: background .15s, color .15s;
        }
        .ma-modal-close:hover { background:var(--cream,#f4e3d3); color:var(--text,#161009); }
        .ma-modal-close svg { width:14px; height:14px; }

        .ma-pwd-field { margin-bottom:14px; }
        .ma-pwd-field:last-of-type { margin-bottom:0; }
        .ma-pwd-field label { display:block; font-size:11.5px; font-weight:600; color:var(--text,#161009); margin-bottom:6px; }
        .ma-pwd-wrap { position:relative; }
        .ma-pwd-wrap input {
          width:100%; box-sizing:border-box; padding:11px 38px 11px 14px;
          font-family:inherit; font-size:13.5px; color:var(--text,#161009);
          background:var(--white); border:1.5px solid var(--cream,#f4e3d3); border-radius:10px;
          transition: border-color .15s, box-shadow .15s;
        }
        .ma-pwd-wrap input::placeholder { color:var(--text-light,#2f6690); }
        .ma-pwd-wrap input:focus { outline:none; border-color:var(--caramel); box-shadow:0 0 0 3px rgba(200,147,90,0.15); }
        .ma-pwd-toggle {
          position:absolute; right:10px; top:50%; transform:translateY(-50%);
          background:none; border:none; padding:2px; cursor:pointer;
          color:var(--text-light,#2f6690); display:flex; align-items:center; justify-content:center;
        }
        .ma-pwd-toggle:hover { color:var(--brown-mid,#2a2016); }
        .ma-pwd-toggle svg { width:16px; height:16px; }
        .ma-pwd-toggle .icon-off { display:none; }
        .ma-pwd-toggle.shown .icon-on  { display:none; }
        .ma-pwd-toggle.shown .icon-off { display:block; }
        .ma-pwd-strength-label { visibility:hidden; font-size:11px; font-weight:700; margin-top:6px; min-height:14px; line-height:14px; }
        .ma-pwd-strength-label.show { visibility:visible; }
        .ma-modal .ma-save-btn { margin-top:4px; }
      </style>
    </head>
    <body>
    <?php
      // Show whichever sidebar matches the visiting role, so a manager/finance/etc.
      // account looking at their own "My Account" card still sees their normal
      // navigation, not the employee POS sidebar.
      $_myacct_sidebar = match (current_role()) {
          'admin'           => '/../admin/Sidebar_Admin.php',
          'manager'         => '/../manager/Sidebar_Manager.php',
          'hr_admin'        => '/Sidebar_HR.php',
          'finance'         => '/../finance/includes/finance_sidebar.php',
          'marketing'       => '/../marketing/includes/marketing_sidebar.php',
          'inventory_staff' => '/../includes/Sidebar_Inventory_Staff.php',
          default           => '/../staff/Sidebar_Employee.php',
      };
      // finance_sidebar.php's nav links normally carry the date range a
      // finance/*.php page set up via finance_data.php; there's no range
      // context here, so give it an empty one instead of an undefined var.
      if (current_role() === 'finance' && !isset($rangeQuery)) {
          $rangeQuery = '';
      }
      require_once __DIR__ . $_myacct_sidebar;
      // Where "Back" returns to — each role's own home page, since this
      // page is now reachable from every role's sidebar, not just the POS.
      [$_myacct_back_href, $_myacct_back_label] = match (current_role()) {
          'admin'           => ['../admin/Admin_Page.php', 'Back to Dashboard'],
          'manager'         => ['../manager/Manager_Dashboard.php', 'Back to Dashboard'],
          'hr_admin'        => ['HR_Dashboard.php', 'Back to Dashboard'],
          'finance'         => ['../finance/finance.php', 'Back to Dashboard'],
          'marketing'       => ['../marketing/Marketing_Dashboard.php', 'Back to Dashboard'],
          'inventory_staff' => ['../manager/Inventory_Management_Page.php', 'Back to Dashboard'],
          default           => ['../staff/Sales_Processing_Page.php', 'Back to POS'],
      };
    ?>
    <script src="../js/lucide-init.js"></script>
    <div class="main">
      <div class="topbar">
        <div class="topbar-left">

          <h1>My Account</h1>
        </div>
        <div class="topbar-right" style="display:flex;gap:10px">
          <a href="<?= htmlspecialchars($_myacct_back_href) ?>" class="btn-secondary" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;border:1px solid var(--hr-border,#e9e3d8);color:var(--text,#241f19);background:#fff">
            <i data-lucide="arrow-left" style="width:15px;height:15px"></i> <?= htmlspecialchars($_myacct_back_label) ?>
          </a>
        </div>
      </div>
      <div class="content ma-page">
        <?php if ($own_msg): [$mt, $mm] = explode(':', $own_msg, 2); ?>
        <script>window.ccOwnMsg = { type: <?= json_encode($mt) ?>, text: <?= json_encode($mm) ?> };</script>
        <?php endif; ?>

        <div class="ma-grid">
          <!-- ── Left: My Profile card ── -->
          <div class="ma-card">
            <div class="ma-profile-photo">
              <div id="myacctAvatarBox">
                <?php if (!empty($my_profile['profile_photo'])): ?>
                  <img src="<?= htmlspecialchars($my_profile['profile_photo']) ?>" alt="<?= htmlspecialchars($full_name) ?>" id="myacctAvatarImg">
                <?php else: ?>
                  <span id="myacctAvatarInitials"><?= htmlspecialchars($initials) ?></span>
                <?php endif; ?>
              </div>

              <?php if (!empty($my_profile['profile_photo'])): ?>
              <button type="button" class="ma-photo-btn" id="myacctPhotoMenuBtn" title="Photo options">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
              </button>
              <?php else: ?>
              <label class="ma-photo-btn" title="Add photo">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                <input type="file" name="profile_photo" id="myacctPhotoInput" accept="image/jpeg" form="myacctProfileForm">
              </label>
              <?php endif; ?>
              <?php if (!empty($my_profile['profile_photo'])): ?>
              <div class="ma-photo-menu" id="myacctPhotoMenu">
                <button type="button" class="ma-photo-menu-item" id="myacctChoosePhotoBtn">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                  Change Photo
                </button>
                <button type="button" class="ma-photo-menu-item danger" id="myacctRemovePhotoBtn">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                  Remove Photo
                </button>
              </div>
              <input type="file" name="profile_photo" id="myacctPhotoInput" accept="image/jpeg" form="myacctProfileForm" style="display:none">
              <?php endif; ?>
            </div>

            <div class="ma-profile-head">
              <h2 class="ma-profile-title">My Profile</h2>
              <div class="ma-profile-meta">
                <?= htmlspecialchars(role_label(current_role())) ?> · ID <?= str_pad((string)$my_id, 4, '0', STR_PAD_LEFT) ?><br>
                <?= $member_since ? htmlspecialchars($member_since) : '' ?>
              </div>
            </div>

            <form method="POST" id="myacctProfileForm" enctype="multipart/form-data">
              <input type="hidden" name="act" value="update_profile">
              <input type="hidden" name="remove_photo" id="myacctRemovePhotoField" value="0">

              <div class="ma-field-row">
                <div class="ma-field-col ma-field-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                  <input type="text" value="<?= htmlspecialchars($full_name) ?>" readonly title="Name changes go through your administrator">
                </div>
              </div>

              <div class="ma-field-row">
                <div class="ma-field-col ma-field-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                  <input type="text" name="contact_number" id="myacctContactNumber" value="<?= htmlspecialchars($my_profile['contact_number'] ?? '') ?>" placeholder="+1 - contact number" inputmode="numeric" maxlength="13">
                </div>
              </div>
              <div class="ma-field-hint ma-field-hint-indent" id="myacctContactHint">Format: 09XX XXX XXXX</div>

              <div class="ma-field-row">
                <div class="ma-field-col ma-field-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 6 12 13 2 6"/><path d="M2 6h20v12H2z"/></svg>
                  <input type="email" name="email" value="<?= htmlspecialchars($my_profile['email'] ?? '') ?>" placeholder="you@example.com">
                </div>
              </div>

              <div class="ma-field-row">
                <div class="ma-field-col ma-field-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                  <input type="date" name="birth_date" value="<?= htmlspecialchars($my_profile['birth_date'] ?? '') ?>" max="<?= date('Y-m-d') ?>">
                </div>
              </div>

              <div class="ma-status-row">
                <div class="ma-status-left">
                  <span class="ma-status-dot att-<?= $attendance_status ?>"></span>
                  <span class="ma-status-label"><?= htmlspecialchars($attendance_label) ?></span>
                </div>
                <span class="ma-pill <?= $attendance_status === 'in' ? 'good' : ($attendance_status === 'out' ? 'bad' : '') ?>">Today</span>
              </div>

              <button type="submit" class="ma-save-btn">Save</button>
            </form>
          </div>

          <!-- ── Right: two stacked list cards ── -->
          <div style="display:flex; flex-direction:column; gap:20px;">
            <div class="ma-card">
              <div class="ma-list-card-head">
                <h2>My Leave Balances</h2>
                <span class="ma-list-card-badge">This year</span>
              </div>
              <div class="ma-list-row">
                <div class="ma-list-left">
                  <span class="ma-list-dot att-in"></span>
                  <div class="ma-list-text"><strong>Vacation leave</strong><span>Available balance</span></div>
                </div>
                <span class="ma-pill good"><?= number_format((float) ($my_profile['vacation_leave_balance'] ?? 0), 1) ?> days</span>
              </div>
              <div class="ma-list-row">
                <div class="ma-list-left">
                  <span class="ma-list-dot att-done"></span>
                  <div class="ma-list-text"><strong>Sick leave</strong><span>Available balance</span></div>
                </div>
                <span class="ma-pill"><?= number_format((float) ($my_profile['sick_leave_balance'] ?? 0), 1) ?> days</span>
              </div>
              <div class="ma-list-row">
                <div class="ma-list-left">
                  <span class="ma-list-dot"></span>
                  <div class="ma-list-text"><strong>Tenure</strong><span>Time with Cloud Cup</span></div>
                </div>
                <span class="ma-pill neutral"><?= htmlspecialchars($tenure_label) ?></span>
              </div>
            </div>

            <div class="ma-card">
              <div class="ma-list-card-head">
                <h2>Account &amp; Security</h2>
              </div>
              <div class="ma-list-row">
                <div class="ma-list-left">
                  <span class="ma-list-dot"></span>
                  <div class="ma-list-text"><strong>Role</strong><span>Access level</span></div>
                </div>
                <span class="ma-pill neutral"><?= htmlspecialchars(role_label(current_role())) ?></span>
              </div>
              <div class="ma-list-row">
                <div class="ma-list-left">
                  <span class="ma-list-dot att-<?= $attendance_status ?>"></span>
                  <div class="ma-list-text"><strong>Attendance</strong><span>Today&rsquo;s status</span></div>
                </div>
                <span class="ma-pill <?= $attendance_status === 'in' ? 'good' : ($attendance_status === 'out' ? 'bad' : '') ?>"><?= htmlspecialchars($attendance_label) ?></span>
              </div>
              <div class="ma-list-row">
                <div class="ma-list-left">
                  <span class="ma-list-dot"></span>
                  <div class="ma-list-text"><strong>Password</strong><span>Last changed unknown</span></div>
                </div>
                <button type="button" class="ma-pill action" id="maPwdToggleBtn">Change</button>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Password change — a centered popup instead of an inline
             drop-down panel, opened by the "Change" pill above. Lives
             outside .ma-grid since it's position:fixed over the page. ── -->
        <div class="ma-modal-overlay <?= $active_tab === 'security' ? 'show' : '' ?>" id="maPwdModalOverlay">
          <div class="ma-modal" id="maPwdModal">
            <div class="ma-modal-head">
              <h2>Change password</h2>
              <button type="button" class="ma-modal-close" id="maPwdModalClose" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
            </div>
            <p class="ma-panel-intro" style="margin:0 0 20px">Choose a password you don&rsquo;t use anywhere else on the register.</p>
            <form method="POST" id="myacctPasswordForm">
              <input type="hidden" name="act" value="change_own_password">
              <div class="ma-pwd-field">
                <label>Current password&nbsp;*</label>
                <div class="ma-pwd-wrap">
                  <input type="password" name="current_password" required>
                  <button type="button" class="ma-pwd-toggle" aria-label="Show password">
                    <svg class="icon-on" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="icon-off" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.6 21.6 0 0 1 5.06-6.06M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.6 21.6 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                  </button>
                </div>
              </div>
              <div class="ma-pwd-field">
                <label>New password&nbsp;*</label>
                <div class="ma-pwd-wrap">
                  <input type="password" name="new_password" id="myacctNewPassword" required minlength="6" placeholder="min. 6 characters">
                  <button type="button" class="ma-pwd-toggle" aria-label="Show password">
                    <svg class="icon-on" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="icon-off" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.6 21.6 0 0 1 5.06-6.06M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.6 21.6 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                  </button>
                </div>
                <div class="ma-pwd-strength-label" id="myacctPwdStrengthLabel"></div>
              </div>
              <div class="ma-pwd-field">
                <label>Confirm new password&nbsp;*</label>
                <div class="ma-pwd-wrap">
                  <input type="password" name="confirm_password" required minlength="6">
                  <button type="button" class="ma-pwd-toggle" aria-label="Show password">
                    <svg class="icon-on" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="icon-off" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.6 21.6 0 0 1 5.06-6.06M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.6 21.6 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                  </button>
                </div>
              </div>
              <button type="submit" class="ma-save-btn">Update Password</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <script>
    lucide.createIcons();

    // Every Save Profile / Update Password result comes back as a full
    // page reload (see window.ccOwnMsg, set inline above from $own_msg) —
    // pop it as a SweetAlert instead of a quiet static banner so every
    // change gets a clear confirmation.
    if (window.ccOwnMsg) {
      Swal.fire({
        icon: window.ccOwnMsg.type === 'success' ? 'success' : 'error',
        title: window.ccOwnMsg.type === 'success' ? 'Saved' : 'Something went wrong',
        text: window.ccOwnMsg.text,
        confirmButtonColor: 'var(--caramel, #C8935A)',
        timer: window.ccOwnMsg.type === 'success' ? 2200 : undefined,
        timerProgressBar: window.ccOwnMsg.type === 'success'
      });
    }

    // "Change" pill on the Password row pops the password-change modal
    // open (CSS handles the fade + spring-scale "pop" via .show); closed
    // by its × button, clicking the backdrop, or Escape.
    (function () {
      var btn      = document.getElementById('maPwdToggleBtn');
      var overlay  = document.getElementById('maPwdModalOverlay');
      var modal    = document.getElementById('maPwdModal');
      var closeBtn = document.getElementById('maPwdModalClose');
      if (!btn || !overlay || !modal) return;

      function openModal() { overlay.classList.add('show'); }
      function closeModal() { overlay.classList.remove('show'); }

      btn.addEventListener('click', openModal);
      if (closeBtn) closeBtn.addEventListener('click', closeModal);
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeModal(); // click on the backdrop, not the modal itself
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('show')) closeModal();
      });
    })();

    // Show/hide toggle for every password field in the Change Password form.
    document.querySelectorAll('.ma-pwd-toggle').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = btn.closest('.ma-pwd-wrap').querySelector('input');
        var shown = input.type === 'text';
        input.type = shown ? 'password' : 'text';
        btn.classList.toggle('shown', !shown);
        btn.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');
      });
    });

    // Photo picked via the camera button → show a live preview first,
    // only actually saved when "Save photo" is clicked.
    (function () {
      var photoInput   = document.getElementById('myacctPhotoInput');
      var form         = document.getElementById('myacctProfileForm');
      var avatarBox    = document.getElementById('myacctAvatarBox');
      var removeField  = document.getElementById('myacctRemovePhotoField');
      var menuBtn      = document.getElementById('myacctPhotoMenuBtn');
      var menu         = document.getElementById('myacctPhotoMenu');
      var chooseBtn    = document.getElementById('myacctChoosePhotoBtn');
      if (!photoInput || !form || !avatarBox) return;

      var originalAvatarHtml = avatarBox.innerHTML; // to restore on Cancel

      // Camera button (only present once a photo exists) opens a small
      // menu with "Change Photo" / "Remove Photo" instead of a separate
      // always-visible "x" badge.
      if (menuBtn && menu) {
        menuBtn.addEventListener('click', function (e) {
          e.stopPropagation();
          menu.classList.toggle('show');
        });
        document.addEventListener('click', function (e) {
          if (menu.classList.contains('show') && !menu.contains(e.target) && e.target !== menuBtn) {
            menu.classList.remove('show');
          }
        });
      }
      if (chooseBtn) {
        chooseBtn.addEventListener('click', function () {
          if (menu) menu.classList.remove('show');
          photoInput.click();
        });
      }

      photoInput.addEventListener('change', function () {
        var file = photoInput.files && photoInput.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (ev) {
          var previewSrc = ev.target.result;
          avatarBox.innerHTML = '<img src="' + previewSrc + '" alt="Preview">';

          // Ask to save or cancel via a SweetAlert instead of an inline button bar.
          Swal.fire({
            title: 'Save this photo?',
            imageUrl: previewSrc,
            imageWidth: 160,
            imageHeight: 160,
            imageAlt: 'Preview',
            showCancelButton: true,
            confirmButtonText: 'Save photo',
            cancelButtonText: 'Cancel',
            confirmButtonColor: 'var(--caramel, #C8935A)',
            cancelButtonColor: '#9c9184',
            reverseButtons: true
          }).then(function (result) {
            if (result.isConfirmed) {
              Swal.fire({
                title: 'Updating photo…',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => Swal.showLoading()
              });
              form.submit();
            } else {
              photoInput.value = '';
              avatarBox.innerHTML = originalAvatarHtml;
            }
          });
        };
        reader.readAsDataURL(file);
      });

      // Remove existing photo — confirm, then submit with remove_photo=1
      // (no new file involved).
      var removeBtn = document.getElementById('myacctRemovePhotoBtn');
      if (removeBtn && removeField) {
        removeBtn.addEventListener('click', function () {
          if (menu) menu.classList.remove('show');
          Swal.fire({
            title: 'Remove your photo?',
            text: "You'll go back to your initials until you upload a new one.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, remove it',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#b8453a',
            cancelButtonColor: '#9c9184',
            reverseButtons: true
          }).then(function (result) {
            if (!result.isConfirmed) return;
            removeField.value = '1';
            Swal.fire({
              title: 'Removing photo…',
              allowOutsideClick: false,
              allowEscapeKey: false,
              showConfirmButton: false,
              didOpen: () => Swal.showLoading()
            });
            form.submit();
          });
        });
      }
    })();

    // Password strength label for the New Password field.
    (function () {
      var input = document.getElementById('myacctNewPassword');
      var label = document.getElementById('myacctPwdStrengthLabel');
      if (!input || !label) return;

      var levels = [
        { text: '',        color: '' },
        { text: 'Weak',    color: '#b8453a' },
        { text: 'Fair',    color: '#a6650f' },
        { text: 'Good',    color: '#b8703f' },
        { text: 'Strong',  color: '#2f6f4e' }
      ];

      input.addEventListener('input', function () {
        var val = input.value;
        var hasValue = val.length > 0;
        label.classList.toggle('show', hasValue);
        if (!hasValue) return;

        var score = 0;
        if (val.length >= 6)  score++;
        if (val.length >= 10) score++;
        if (/[a-z]/.test(val) && /[A-Z]/.test(val)) score++;
        if (/[0-9]/.test(val) && /[^A-Za-z0-9]/.test(val)) score++;

        label.textContent = levels[score].text;
        label.style.color = levels[score].color;
      });
    })();

    // Contact number — soft PH mobile format hint, non-blocking.
    // Accepts 09XXXXXXXXX or +639XXXXXXXXX (spaces allowed while typing).
    (function () {
      var input = document.getElementById('myacctContactNumber');
      var hint  = document.getElementById('myacctContactHint');
      if (!input || !hint) return;

      var defaultText = hint.textContent;
      var phPattern = /^(09\d{9}|\+639\d{9})$/;

      input.addEventListener('input', function () {
        var digitsOnly = input.value.replace(/[\s-]/g, '');
        hint.classList.remove('valid', 'invalid');
        if (digitsOnly === '') {
          hint.textContent = defaultText;
          return;
        }
        if (phPattern.test(digitsOnly)) {
          hint.textContent = 'Looks good';
          hint.classList.add('valid');
        } else {
          hint.textContent = 'Format: 09XX XXX XXXX';
          hint.classList.add('invalid');
        }
      });
    })();

    // Disable + relabel a form's submit button the moment it's submitted,
    // so a slow request (or a network hiccup) can't be double-submitted
    // by an extra click before the page navigates away.
    [
      { formId: 'myacctProfileForm',  savingText: 'Saving…' },
      { formId: 'myacctPasswordForm', savingText: 'Updating…' }
    ].forEach(function (cfg) {
      var form = document.getElementById(cfg.formId);
      if (!form) return;
      form.addEventListener('submit', function () {
        var btn = form.querySelector('button[type="submit"]');
        if (!btn || btn.classList.contains('is-saving')) return;
        btn.dataset.originalText = btn.textContent;
        btn.textContent = cfg.savingText;
        btn.classList.add('is-saving');
        btn.disabled = true;
      });
    });

    </script>
    <script src="../js/msg_banner_autodismiss.js"></script>
    <script src="../js/theme-toggle.js"></script>
</body>
    </html>
    <?php
    exit;
}

// ── HR Admin / Manager: full RBAC management page below ──────────
require_permission('manage_accounts'); // hr_admin only

$full_name   = $_SESSION['full_name'] ?? 'Admin';
$my_id       = current_hr_user_id();
$active_page = 'hr_accounts';
$msg         = '';

function acct_username_exists($conn, string $username, int $exclude_id = 0): bool {
    if (!$conn || $username === '') return false;
    $s = mysqli_prepare($conn, "SELECT user_id FROM users WHERE username = ? AND user_id != ? LIMIT 1");
    mysqli_stmt_bind_param($s, 'si', $username, $exclude_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    return $r && mysqli_fetch_assoc($r) ? true : false;
}

if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'create') {
        $name     = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = in_array($_POST['role'] ?? '', ['admin','hr_admin','manager','finance','supplier','employee'], true) ? $_POST['role'] : 'employee';

        if (!$name || !$username || strlen($password) < 6) {
            $msg = 'error:Name, username, and a password of at least 6 characters are required.';
        } elseif (acct_username_exists($conn, $username)) {
            $msg = 'error:Username "' . htmlspecialchars($username) . '" is already taken.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn,
                "INSERT INTO users (full_name, username, password, role, is_active) VALUES (?,?,?,?,1)");
            mysqli_stmt_bind_param($stmt, 'ssss', $name, $username, $hash, $role);
            if (mysqli_stmt_execute($stmt)) {
                $new_id = (int)mysqli_insert_id($conn);
                // Give every non-admin an HR profile row so Employee Records has something to show.
                $ins = mysqli_prepare($conn,
                    "INSERT INTO employees (employee_id, employment_status, date_hired) VALUES (?, 'active', CURDATE())");
                mysqli_stmt_bind_param($ins, 'i', $new_id);
                mysqli_stmt_execute($ins);
                $msg = 'success:Account created for ' . htmlspecialchars($name) . '.';
            } else {
                $msg = 'error:Could not create account. Please try again.';
            }
        }
    } elseif ($act === 'change_role') {
        $target = (int)($_POST['user_id'] ?? 0);
        $role   = in_array($_POST['role'] ?? '', ['admin','hr_admin','manager','finance','supplier','employee'], true) ? $_POST['role'] : null;
        if ($target && $role) {
            if ($target === $my_id && $role !== 'hr_admin') {
                $msg = 'error:You can\'t remove your own HR Administrator role.';
            } else {
                mysqli_query($conn, "UPDATE users SET role='" . mysqli_real_escape_string($conn, $role) . "' WHERE user_id=$target");
                $msg = 'success:Role updated.';
            }
        }
    } elseif ($act === 'toggle_active') {
        $target = (int)($_POST['user_id'] ?? 0);
        $newval = (int)($_POST['is_active'] ?? 1);
        if ($target === $my_id && $newval === 0) {
            $msg = 'error:You can\'t deactivate your own account.';
        } elseif ($target) {
            mysqli_query($conn, "UPDATE users SET is_active=$newval WHERE user_id=$target");
            $msg = 'success:Account ' . ($newval ? 'reactivated' : 'deactivated') . '.';
        }
    } elseif ($act === 'reset_password') {
        $target   = (int)($_POST['user_id'] ?? 0);
        $password = $_POST['password'] ?? '';
        if ($target && strlen($password) >= 6) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $s = mysqli_prepare($conn, "UPDATE users SET password=? WHERE user_id=?");
            mysqli_stmt_bind_param($s, 'si', $hash, $target);
            mysqli_stmt_execute($s);
            $msg = 'success:Password reset.';
        } else {
            $msg = 'error:Password must be at least 6 characters.';
        }
    }
}

$accounts = [];
// Terminated employees are excluded here on purpose — once Employee Records marks
// someone Terminated, they no longer belong in the active Accounts & Roles list.
// They're still fully visible/searchable from the Terminated tab in Employee Records.
$res = mysqli_query($conn,
    "SELECT u.user_id, u.full_name, u.username, u.role, u.is_active, u.created_at
     FROM users u
     LEFT JOIN employees e ON e.employee_id = u.user_id
     WHERE COALESCE(e.employment_status, 'active') <> 'terminated'
     ORDER BY FIELD(u.role,'admin','hr_admin','manager','finance','supplier','employee'), u.full_name");
if ($res) while ($r = mysqli_fetch_assoc($res)) $accounts[] = $r;

// Group accounts by role so we can render section headers + role tabs like the mock.
// 'admin' is a distinct top-level role from 'hr_admin' elsewhere in this app
// (see Login_Page.php's role whitelist) — it needs its own bucket here too,
// or it falls into "Employees" and its <select> silently shows the wrong role.
$role_group_labels = ['admin' => 'Administrators', 'hr_admin' => 'HR Administrators', 'manager' => 'Managers', 'finance' => 'Finance', 'supplier' => 'Suppliers', 'employee' => 'Employees'];
$grouped = ['admin' => [], 'hr_admin' => [], 'manager' => [], 'finance' => [], 'supplier' => [], 'employee' => []];
foreach ($accounts as $a) {
    $r = in_array($a['role'], ['admin','hr_admin','manager','finance','supplier','employee'], true) ? $a['role'] : 'employee';
    $grouped[$r][] = $a;
}

// Avatar initials + accent color derived from whatever's on the account —
// no photo upload here (that's on the employee self-service view above),
// so the "picture" is generated from the name that was actually typed in.
function acct_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $parts = array_values(array_filter($parts));
    if (empty($parts)) return '?';
    $first = mb_substr($parts[0], 0, 1);
    $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}
$role_colors = [
    'admin'    => '#7A3B8F',
    'hr_admin' => '#3a2d1e',
    'manager'  => '#b8703f',
    'finance'  => '#3F8F5F',
    'supplier' => '#2f6690',
    'employee' => '#5F5E5A',
];

// role_label() (from Permissions.php) may not know about 'supplier' yet if
// that file hasn't been updated — fall back to a local label instead of
// letting an unrecognized role print blank/ugly text in the <select>.
function acct_role_label(string $role): string {
    if ($role === 'supplier') return 'Supplier';
    return function_exists('role_label') ? role_label($role) : ucfirst(str_replace('_', ' ', $role));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="../js/tab_session_guard.js"></script>
  <script>(function(){try{var t=localStorage.getItem('cloudcup-theme')||'light';document.documentElement.classList.toggle('dark-mode',t==='dark');document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="stylesheet" href="../css/theme.css"/>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Accounts &amp; Roles — Cloud Cup HR</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/hr_module.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .role-tab { background: var(--white); border:1px solid var(--cream); color:var(--text-light); border-radius:7px; padding:7px 13px; font-size:12px; font-weight:600; cursor:pointer; font-family:inherit; }
    .role-tab.active { background:var(--brown); border-color:var(--brown); color:#fff; }

    .acct-search-wrap { flex:1; min-width:220px; display:flex; align-items:center; gap:8px; background: var(--white); border:1px solid var(--cream); border-radius:8px; padding:0 12px; }
    .acct-search-wrap input { border:none; outline:none; padding:9px 0; font-size:13px; font-family:inherit; width:100%; background:transparent; }
    .acct-search-wrap svg { flex-shrink:0; color:var(--text-light); }

    .role-section { margin-top: 28px; }
    .role-section:first-of-type { margin-top: 4px; }
    .role-section-label { font-size:12.5px; font-weight:700; color:var(--text-light); margin:0 0 10px; text-transform:uppercase; letter-spacing:.03em; }

    .acct-card-list { display:flex; flex-direction:column; gap:8px; }
    .acct-row {
      display:flex; align-items:center; gap:12px;
      background: var(--white); border:1px solid var(--cream); border-radius:10px; padding:10px 14px;
    }
    .acct-avatar {
      width:34px; height:34px; border-radius:50%; flex-shrink:0;
      display:flex; align-items:center; justify-content:center;
      font-size:12px; font-weight:700; color:#fff;
    }
    .acct-identity { flex:1; min-width:0; }
    .acct-name { font-size:13.5px; font-weight:600; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .acct-username { font-size:11.5px; color:var(--text-light); }
    .acct-role-select {
      border:none; border-radius:6px; padding:5px 10px; font-size:11.5px; font-weight:600;
      font-family:inherit; cursor:pointer; flex-shrink:0; appearance:none; -webkit-appearance:none;
      background-repeat:no-repeat; background-position:right 8px center; background-size:9px;
      padding-right:22px;
    }
    .acct-role-select:disabled { cursor:default; opacity:.55; }
    .acct-status { display:inline-flex; align-items:center; gap:6px; font-size:11.5px; font-weight:600; flex-shrink:0; width:92px; }
    .acct-status-dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
    .acct-date { font-size:11.5px; color:var(--text-light); flex-shrink:0; width:84px; }
    .acct-actions { display:flex; gap:6px; flex-shrink:0; }
    .acct-icon-btn {
      width:30px; height:30px; border-radius:7px; border:1px solid var(--cream); background: var(--white);
      display:flex; align-items:center; justify-content:center; cursor:pointer; color:var(--text-mid);
    }
    .acct-icon-btn.danger { border-color:rgba(239,68,68,.3); color:var(--danger); }
    .acct-icon-btn.success { border-color:rgba(34,197,94,.3); color:var(--success); }
    .acct-icon-btn:disabled { opacity:.4; cursor:not-allowed; }

    @media (max-width: 720px) {
      .acct-date { display:none; }
    }
  </style>
</head>
<body>

<?php require_once '../HR/Sidebar_HR.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <h1>Accounts &amp; Roles</h1>
    </div>
    <div class="topbar-right"><div class="topbar-date"><?= date('F j, Y') ?></div></div>
  </div>

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>"><?= $mm ?></div>
    <?php endif; ?>

    <div class="widget">
      <div class="widget-header">
        <div>
          <div class="widget-title">Staff Accounts (RBAC)</div>
          <div style="font-size:12px;color:var(--text-light);margin-top:2px">Create logins and assign a role: Admin, HR Administrator, Manager, Finance, Supplier, or Employee.</div>
        </div>
        <button class="btn btn-primary" onclick="document.getElementById('createModal').classList.add('open')">+ New Account</button>
      </div>

      <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:14px 0 16px">
        <div class="acct-search-wrap">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" id="acctSearch" placeholder="Search by name or username…">
        </div>
        <div class="role-tabs" style="display:flex;gap:6px;flex-wrap:wrap">
          <button type="button" class="role-tab active" data-role="all">All (<?= count($accounts) ?>)</button>
          <?php foreach ($role_group_labels as $rk => $rlabel): ?>
            <button type="button" class="role-tab" data-role="<?= $rk ?>"><?= $rlabel ?> (<?= count($grouped[$rk]) ?>)</button>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if (empty($accounts)): ?>
        <div class="empty-state">No accounts yet.</div>
      <?php endif; ?>
      <div id="acctNoResults" class="empty-state" style="display:none">No matching accounts.</div>

      <?php foreach ($role_group_labels as $rk => $rlabel): $accent = $role_colors[$rk]; ?>
      <div class="role-section" data-role-section="<?= $rk ?>" style="<?= empty($grouped[$rk]) ? 'display:none' : '' ?>">
        <div class="role-section-label"><?= $rlabel ?></div>
        <div class="acct-card-list">
          <?php foreach ($grouped[$rk] as $a): $active = (int)$a['is_active'] === 1; $is_me = $a['user_id'] == $my_id; ?>
          <div class="acct-row" data-role="<?= $rk ?>" data-search="<?= htmlspecialchars(strtolower($a['full_name'] . ' ' . $a['username'])) ?>">
            <div class="acct-avatar" style="background:<?= $accent ?>"><?= htmlspecialchars(acct_initials($a['full_name'])) ?></div>
            <div class="acct-identity">
              <div class="acct-name"><?= htmlspecialchars($a['full_name']) ?><?= $is_me ? ' <span style="color:var(--text-light);font-weight:400">(you)</span>' : '' ?></div>
              <div class="acct-username">@<?= htmlspecialchars($a['username']) ?></div>
            </div>

            <form method="POST" style="display:inline-flex">
              <input type="hidden" name="act" value="change_role">
              <input type="hidden" name="user_id" value="<?= $a['user_id'] ?>">
              <select name="role" onchange="this.form.submit()" class="acct-role-select"
                      style="background-color:<?= $accent ?>1a;color:<?= $accent ?>"
                      <?= $is_me ? 'disabled' : '' ?>>
                <?php foreach (['admin','hr_admin','manager','finance','supplier','employee'] as $rl): ?>
                  <option value="<?= $rl ?>" <?= $a['role'] === $rl ? 'selected' : '' ?>><?= acct_role_label($rl) ?></option>
                <?php endforeach; ?>
              </select>
            </form>

            <span class="acct-status" style="color:<?= $active ? 'var(--success)' : 'var(--danger)' ?>">
              <span class="acct-status-dot" style="background:<?= $active ? 'var(--success)' : 'var(--danger)' ?>"></span>
              <?= $active ? 'Active' : 'Deactivated' ?>
            </span>

            <span class="acct-date"><?= date('M d, Y', strtotime($a['created_at'])) ?></span>

            <div class="acct-actions">
              <button type="button" class="acct-icon-btn" title="Reset password" aria-label="Reset password" onclick="openResetModal(<?= $a['user_id'] ?>, '<?= addslashes($a['full_name']) ?>')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7.5" cy="15.5" r="5.5"/><path d="M21 2l-9.6 9.6M15.5 7.5L18 10l4-4"/></svg>
              </button>
              <form method="POST" class="toggle-active-form"
                    data-action="<?= $active ? 'Deactivate' : 'Reactivate' ?>"
                    data-name="<?= htmlspecialchars($a['full_name'], ENT_QUOTES) ?>">
                <input type="hidden" name="act" value="toggle_active">
                <input type="hidden" name="user_id" value="<?= $a['user_id'] ?>">
                <input type="hidden" name="is_active" value="<?= $active ? 0 : 1 ?>">
                <button type="submit" class="acct-icon-btn <?= $active ? 'danger' : 'success' ?>" title="<?= $active ? 'Deactivate' : 'Reactivate' ?>" aria-label="<?= $active ? 'Deactivate' : 'Reactivate' ?>" <?= $is_me ? 'disabled' : '' ?>>
                  <?php if ($active): ?>
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><line x1="5.6" y1="5.6" x2="18.4" y2="18.4"/></svg>
                  <?php else: ?>
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.5 15a9 9 0 1 0 2-9.5L1 10"/></svg>
                  <?php endif; ?>
                </button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- CREATE MODAL -->
<div class="modal-overlay-admin" id="createModal">
  <div class="modal-admin-box">
    <div class="modal-admin-header">
      <span>Create New Account</span>
      <button class="modal-close-btn" onclick="document.getElementById('createModal').classList.remove('open')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="act" value="create">
      <div class="form-group-admin">
        <label>Full Name *</label>
        <input type="text" name="full_name" required placeholder="e.g. Juan Dela Cruz">
      </div>
      <div class="form-row">
        <div class="form-group-admin">
          <label>Username *</label>
          <input type="text" name="username" required autocomplete="off">
        </div>
        <div class="form-group-admin">
          <label>Temporary Password *</label>
          <input type="text" name="password" required minlength="6" placeholder="min. 6 characters">
        </div>
      </div>
      <div class="form-group-admin">
        <label>Role *</label>
        <select name="role" required>
          <option value="employee">Employee</option>
          <option value="finance">Finance</option>
          <option value="supplier">Supplier</option>
          <option value="manager">Manager</option>
          <option value="hr_admin">HR Administrator</option>
          <option value="admin">Admin</option>
        </select>
      </div>
      <div class="modal-admin-actions">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('createModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Account</button>
      </div>
    </form>
  </div>
</div>

<!-- RESET PASSWORD MODAL -->
<div class="modal-overlay-admin" id="resetModal">
  <div class="modal-admin-box">
    <div class="modal-admin-header">
      <span id="resetModalTitle">Reset Password</span>
      <button class="modal-close-btn" onclick="document.getElementById('resetModal').classList.remove('open')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="act" value="reset_password">
      <input type="hidden" name="user_id" id="resetUserId">
      <div class="form-group-admin">
        <label>New Password *</label>
        <input type="text" name="password" required minlength="6" placeholder="min. 6 characters">
      </div>
      <div class="modal-admin-actions">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('resetModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Reset Password</button>
      </div>
    </form>
  </div>
</div>

<script>
function openResetModal(id, name) {
  document.getElementById('resetUserId').value = id;
  document.getElementById('resetModalTitle').textContent = 'Reset Password — ' + name;
  document.getElementById('resetModal').classList.add('open');
}
document.querySelectorAll('.modal-overlay-admin').forEach(function(m){
  m.addEventListener('click', function(e){ if (e.target === m) m.classList.remove('open'); });
});

// --- Role tabs + search filtering ---
(function(){
  var searchInput = document.getElementById('acctSearch');
  var tabs         = document.querySelectorAll('.role-tab');
  var sections     = document.querySelectorAll('.role-section');
  var noResults    = document.getElementById('acctNoResults');
  var activeRole   = 'all';

  function applyFilter() {
    var term = searchInput.value.trim().toLowerCase();
    var anyVisible = false;

    sections.forEach(function(section){
      var role = section.dataset.roleSection;
      var sectionMatchesRole = (activeRole === 'all' || activeRole === role);
      var rows = section.querySelectorAll('.acct-row');
      var visibleInSection = 0;

      rows.forEach(function(row){
        var matchesSearch = !term || row.dataset.search.indexOf(term) !== -1;
        var show = sectionMatchesRole && matchesSearch;
        row.style.display = show ? '' : 'none';
        if (show) visibleInSection++;
      });

      var sectionHasRows = rows.length > 0;
      section.style.display = (sectionMatchesRole && sectionHasRows && visibleInSection > 0) ? '' : 'none';
      if (visibleInSection > 0) anyVisible = true;
    });

    noResults.style.display = anyVisible ? 'none' : '';
  }

  tabs.forEach(function(tab){
    tab.addEventListener('click', function(){
      tabs.forEach(function(t){ t.classList.remove('active'); });
      tab.classList.add('active');
      activeRole = tab.dataset.role;
      applyFilter();
    });
  });

  searchInput.addEventListener('input', applyFilter);
})();

// --- SweetAlert confirmation for Deactivate / Reactivate ---
document.querySelectorAll('.toggle-active-form').forEach(function(form){
  form.addEventListener('submit', function(e){
    e.preventDefault();
    var action = form.dataset.action; // "Deactivate" or "Reactivate"
    var name   = form.dataset.name;
    var isDeactivate = action === 'Deactivate';

    Swal.fire({
      title: action + ' account?',
      html: 'Are you sure you want to <b>' + action.toLowerCase() + '</b> the account of <b>' + name + '</b>?',
      icon: isDeactivate ? 'warning' : 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, ' + action.toLowerCase() + ' it',
      cancelButtonText: 'Cancel',
      confirmButtonColor: isDeactivate ? '#d33' : '#2f6690',
      reverseButtons: true
    }).then(function(result){
      if (result.isConfirmed) form.submit();
    });
  });
});
</script>
<script src="../js/msg_banner_autodismiss.js"></script>

<script src="../js/theme-toggle.js"></script>
</body>
</html>