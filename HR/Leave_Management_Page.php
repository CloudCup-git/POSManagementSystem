<?php
require_once __DIR__ . '/../admin/Permissions.php';
require_hr_login();
require_permission('apply_leave'); // baseline — everyone can at least request/view their own
require_once __DIR__ . '/../includes/DB_Connect.php';
require_once __DIR__ . '/../includes/Leave_Status_Sync.php';

$active_page = 'hr_leave';
$uid         = current_hr_user_id();
$can_approve = has_permission('manage_leave_requests');
$min_leave_date = date('Y-m-d'); // today onwards — no past dates

// Post/Redirect/Get: after a form submits, we redirect (see bottom of the
// POST block below) instead of rendering the page directly. That means a
// browser refresh just re-GETs this page instead of resubmitting the last
// action — no more repeated approvals/rejections/cancellations on refresh.
// The result banner text rides along in the session for one load.
$msg = $_SESSION['leave_flash'] ?? '';
unset($_SESSION['leave_flash']);

if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'request') {
        $type = in_array($_POST['leave_type'] ?? '', ['vacation','sick','emergency','unpaid','other'], true) ? $_POST['leave_type'] : 'vacation';
        $from = $_POST['date_from'] ?? '';
        $to   = $_POST['date_to']   ?? '';
        $reason = trim($_POST['reason'] ?? '');
        if (!$from || !$to || strtotime($to) < strtotime($from)) {
            $msg = 'error:Please provide a valid date range.';
        } elseif (strtotime($from) < strtotime($min_leave_date)) {
            $msg = 'error:Leave dates cannot be earlier than today.';
        } else {
            $days = (strtotime($to) - strtotime($from)) / 86400 + 1;
            if ($type !== 'emergency' && $days > 3) {
                $msg = 'error:Regular leave requests cannot exceed 3 days. Please select Emergency leave for longer requests.';
            } else {
                $s = mysqli_prepare($conn,
                    "INSERT INTO leave_requests (employee_id, leave_type, date_from, date_to, reason) VALUES (?,?,?,?,?)");
                mysqli_stmt_bind_param($s, 'issss', $uid, $type, $from, $to, $reason);
                mysqli_stmt_execute($s);
                $msg = 'success:Leave request submitted.';
            }
        }
    } elseif ($act === 'decide' && $can_approve) {
        $leave_id = (int)($_POST['leave_id'] ?? 0);
        $decision = ($_POST['decision'] ?? '') === 'approved' ? 'approved' : 'rejected';
        $lr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM leave_requests WHERE leave_id=$leave_id"));
        if ($lr && $lr['status'] === 'pending') {
            $s = mysqli_prepare($conn,
                "UPDATE leave_requests SET status=?, reviewed_by=?, reviewed_at=NOW() WHERE leave_id=?");
            mysqli_stmt_bind_param($s, 'sii', $decision, $uid, $leave_id);
            mysqli_stmt_execute($s);

            if ($decision === 'approved' && in_array($lr['leave_type'], ['vacation','sick'], true)) {
                $days = (strtotime($lr['date_to']) - strtotime($lr['date_from'])) / 86400 + 1;
                $col  = $lr['leave_type'] === 'vacation' ? 'vacation_leave_balance' : 'sick_leave_balance';
                mysqli_query($conn, "UPDATE employees SET $col = GREATEST(0, $col - $days) WHERE employee_id = {$lr['employee_id']}");
            }
            $msg = 'success:Leave request ' . $decision . '.';
        }
    } elseif ($act === 'cancel') {
        $leave_id = (int)($_POST['leave_id'] ?? 0);
        mysqli_query($conn, "UPDATE leave_requests SET status='cancelled' WHERE leave_id=$leave_id AND employee_id=$uid AND status='pending'");
        $msg = 'success:Request cancelled.';
    } elseif ($act === 'revoke' && $can_approve) {
        $leave_id = (int)($_POST['leave_id'] ?? 0);
        $lr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM leave_requests WHERE leave_id=$leave_id"));
        if ($lr && $lr['status'] === 'approved') {
            $s = mysqli_prepare($conn,
                "UPDATE leave_requests SET status='cancelled', reviewed_by=?, reviewed_at=NOW() WHERE leave_id=?");
            mysqli_stmt_bind_param($s, 'ii', $uid, $leave_id);
            mysqli_stmt_execute($s);

            // Give back the days that were deducted when this was approved.
            if (in_array($lr['leave_type'], ['vacation','sick'], true)) {
                $days = (strtotime($lr['date_to']) - strtotime($lr['date_from'])) / 86400 + 1;
                $col  = $lr['leave_type'] === 'vacation' ? 'vacation_leave_balance' : 'sick_leave_balance';
                mysqli_query($conn, "UPDATE employees SET $col = $col + $days WHERE employee_id = {$lr['employee_id']}");
            }
            $msg = 'success:Leave revoked and balance restored.';
        } else {
            $msg = 'error:Only approved leave requests can be revoked.';
        }
    }

    // Redirect so the browser's last "action" is a GET, not this POST —
    // refreshing the page afterward can't resubmit the form or repeat
    // the banner message.
    $_SESSION['leave_flash'] = $msg;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Auto-reject any pending request whose date range already passed,
// then re-sync employment_status against approved leave requests —
// this is what actually moves an employee onto the "On Leave" tab in
// Employee Records (and back to "Active" once their leave ends or is
// revoked), right after an approve/revoke above changes the picture.
expire_stale_leave_requests($conn);
sync_employee_leave_statuses($conn);

if ($can_approve) {
    $requests = [];
    $res = mysqli_query($conn,
        "SELECT lr.*, u.full_name FROM leave_requests lr JOIN users u ON u.user_id = lr.employee_id
         ORDER BY (lr.status='pending') DESC, lr.created_at DESC LIMIT 50");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $requests[] = $r;
} else {
    $requests = [];
    $res = mysqli_query($conn, "SELECT * FROM leave_requests WHERE employee_id=$uid ORDER BY created_at DESC");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $requests[] = $r;
    $bal = mysqli_fetch_assoc(mysqli_query($conn, "SELECT vacation_leave_balance, sick_leave_balance FROM employees WHERE employee_id=$uid"));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script>(function(){try{var t=localStorage.getItem('cloudcup-theme')||'light';document.documentElement.classList.toggle('dark-mode',t==='dark');document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="stylesheet" href="../css/theme.css"/>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Leave Management — Cloud Cup HR</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/hr_module.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<?php if (current_role() === 'employee') { require_once '../staff/Sidebar_Employee.php'; } else { require_once 'Sidebar_HR.php'; } ?>
<script src="../js/lucide-init.js"></script>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      
      <h1>Leave Management</h1>
    </div>
    <div class="topbar-right">
      <div class="topbar-date"><?= date('F j, Y') ?></div>
    </div>
  </div>

  <?php // tabs removed — navigation now lives in the sidebar ?>

  <div class="content leave-ui">
    <style>
      /* ================= Leave management — professional redesign ================= */
      .leave-ui{
        --lv-paper:#FFFFFF; --lv-ink:#241811; --lv-ink-soft:#7A6C5A;
        --lv-sage:#5C8374; --lv-sage-dark:#3E5A4F; --lv-sage-tint:#E4EEE9;
        --lv-clay:#AD6A34; --lv-clay-tint:#F3E3CE;
        --lv-rust:#9A4B3B; --lv-rust-tint:#F2DDD4;
        --lv-neutral-ink:#8A7B67; --lv-neutral-tint:#EFE8D8;
        --lv-line:#E1D5BC; --lv-line-strong:#CBBA95; --lv-kraft:#EDE6D6;
      }

      /* -- Request-leave / requests-list panels: hairline + accent bar, no card-kit shadow -- */
      .leave-ui .widget{
        background:var(--lv-paper); border:1px solid var(--lv-line); border-radius:8px;
        box-shadow:none;
      }
      .leave-ui .widget-header{
        border-bottom:1px solid var(--lv-line); padding:18px 26px; margin:0;
      }
      .leave-ui .widget-title{
        font-family:'Fraunces',serif; font-weight:600; font-size:16.5px; color:var(--lv-ink);
      }
      .leave-ui form#leaveRequestForm{ padding:22px 26px 24px; }
      .leave-ui .form-row{ display:grid; grid-template-columns:1fr 1fr 1fr; gap:18px; margin-bottom:16px; }
      .leave-ui .form-group-admin label{
        display:block; font-family:'Inter',sans-serif; font-size:12.5px; font-weight:600;
        color:var(--lv-ink-soft); margin-bottom:7px;
      }
      .leave-ui textarea{
        width:100%; border:1px solid var(--lv-line); background:var(--lv-paper); border-radius:7px;
        padding:9px 11px; font-family:'Inter',sans-serif; font-size:13.5px; color:var(--lv-ink);
        box-sizing:border-box; resize:vertical; transition:border-color .12s ease, box-shadow .12s ease;
      }
      .leave-ui textarea:hover{ border-color:var(--lv-line-strong); }
      .leave-ui textarea:focus{ outline:none; border-color:var(--lv-sage); box-shadow:0 0 0 3px rgba(92,131,116,.16); }
      .leave-ui #leaveLimitNote{ font-style:italic; }

      /* -- Requests table: ledger typography instead of the default grid -- */
      .leave-ui table{ width:100%; border-collapse:collapse; }
      .leave-ui th{
        text-align:left; font-family:'Inter',sans-serif; font-size:12.5px; font-weight:600;
        color:var(--lv-ink-soft); padding:14px 26px; border-bottom:2px solid var(--lv-ink);
        text-transform:none;
      }
      .leave-ui td{ padding:14px 26px; font-size:13.5px; color:var(--lv-ink); border-top:1px solid var(--lv-line); }
      .leave-ui tr:first-child td{ border-top:none; }
      .leave-ui tbody tr:hover td{ background:var(--lv-kraft); }
      .leave-ui td.lv-dates{ font-family:'IBM Plex Mono',monospace; font-size:12.5px; letter-spacing:-.01em; }
      .leave-ui .empty-state{ padding:32px 26px; text-align:center; color:var(--lv-ink-soft); font-style:italic; }

      /* -- Status pills: full set, mapped to the ledger palette -- */
      .leave-ui .status-pill{
        display:inline-block; font-family:'Inter',sans-serif; font-size:11.5px; font-weight:600;
        padding:4px 11px; border-radius:20px; letter-spacing:.01em;
      }
      .leave-ui .pill-pending{ color:var(--lv-clay); background:var(--lv-clay-tint); }
      .leave-ui .pill-approved{ color:var(--lv-sage-dark); background:var(--lv-sage-tint); }
      .leave-ui .pill-rejected{ color:var(--lv-rust); background:var(--lv-rust-tint); }
      .leave-ui .pill-cancel{ color:var(--lv-neutral-ink); background:var(--lv-neutral-tint); }

      @media (max-width: 760px){
        .leave-ui .form-row{ grid-template-columns:1fr; }
      }

      /* ---------- Styled leave-type dropdown + date pickers ---------- */
      .leave-ui .lv-control{ position:relative; }
      .leave-ui .lv-trigger{
        width:100%; display:flex; align-items:center; justify-content:space-between; gap:8px;
        border:1px solid var(--lv-line); background:var(--lv-paper); border-radius:7px;
        padding:9px 11px; font-family:'Inter',sans-serif; font-size:13.5px; color:var(--lv-ink);
        cursor:pointer; transition:border-color .12s ease, box-shadow .12s ease; text-align:left;
      }
      .leave-ui .lv-trigger:hover{ border-color:var(--lv-line-strong); }
      .leave-ui .lv-trigger:focus-visible{ outline:none; border-color:var(--lv-sage); box-shadow:0 0 0 3px rgba(92,131,116,.16); }
      .leave-ui .lv-trigger .lv-chev{ flex-shrink:0; color:var(--lv-ink-soft); transition:transform .15s ease; }
      .leave-ui .lv-trigger .lv-cal-ic{ flex-shrink:0; color:var(--lv-ink-soft); }
      .leave-ui .lv-control.open .lv-trigger{ border-color:var(--lv-sage); box-shadow:0 0 0 3px rgba(92,131,116,.16); }
      .leave-ui .lv-control.open .lv-trigger .lv-chev{ transform:rotate(180deg); }
      .leave-ui .lv-trigger .placeholder{ color:#B0A28C; }

      .leave-ui .lv-panel{
        position:absolute; top:calc(100% + 6px); left:0; z-index:20;
        background:var(--lv-paper); border:1px solid var(--lv-line); border-radius:10px;
        box-shadow:0 12px 28px rgba(43,27,20,.14);
        opacity:0; transform:translateY(-4px) scale(.98); pointer-events:none;
        transition:opacity .12s ease, transform .12s ease; transform-origin:top left;
      }
      .leave-ui .lv-control.open .lv-panel{ opacity:1; transform:translateY(0) scale(1); pointer-events:auto; }

      .leave-ui .lv-select-list{ list-style:none; margin:0; padding:6px; min-width:100%; }
      .leave-ui .lv-select-option{
        display:flex; align-items:center; justify-content:space-between; gap:10px;
        padding:8px 10px; border-radius:6px; font-size:13.5px; color:var(--lv-ink); cursor:pointer;
        transition:background-color .1s ease; white-space:nowrap;
      }
      .leave-ui .lv-select-option:hover{ background:var(--lv-kraft); }
      .leave-ui .lv-select-option.selected{ background:var(--lv-sage-tint); color:var(--lv-sage-dark); font-weight:600; }
      .leave-ui .lv-select-option .lv-check{ opacity:0; color:var(--lv-sage-dark); flex-shrink:0; }
      .leave-ui .lv-select-option.selected .lv-check{ opacity:1; }

      .leave-ui .lv-date-field{ width:260px; padding:14px; }
      .leave-ui .lv-cal-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; padding:0 2px; }
      .leave-ui .lv-cal-month{ font-family:'Fraunces',serif; font-size:14px; font-weight:600; color:var(--lv-ink); }
      .leave-ui .lv-cal-nav{
        width:26px; height:26px; border-radius:6px; border:1px solid var(--lv-line); background:var(--lv-paper);
        display:flex; align-items:center; justify-content:center; cursor:pointer; color:var(--lv-ink-soft);
        transition:background-color .12s ease, border-color .12s ease, color .12s ease;
      }
      .leave-ui .lv-cal-nav:hover{ background:var(--lv-kraft); border-color:var(--lv-line-strong); color:var(--lv-ink); }
      .leave-ui .lv-cal-weekdays{ display:grid; grid-template-columns:repeat(7,1fr); margin-bottom:2px; }
      .leave-ui .lv-cal-weekdays span{ font-size:10.5px; font-weight:600; color:var(--lv-ink-soft); text-align:center; padding:4px 0; }
      .leave-ui .lv-cal-grid{ display:grid; grid-template-columns:repeat(7,1fr); row-gap:2px; }
      .leave-ui .lv-cal-day{
        width:100%; aspect-ratio:1; border:none; background:transparent; border-radius:7px;
        font-family:'Inter',sans-serif; font-size:12.5px; color:var(--lv-ink); cursor:pointer;
        display:flex; align-items:center; justify-content:center; transition:background-color .1s ease, color .1s ease;
      }
      .leave-ui .lv-cal-day:hover:not(:disabled){ background:var(--lv-kraft); }
      .leave-ui .lv-cal-day.outside{ color:#C9BB9E; }
      .leave-ui .lv-cal-day.today{ font-weight:700; color:var(--lv-sage-dark); }
      .leave-ui .lv-cal-day.selected{ background:var(--lv-sage); color:#fff; font-weight:600; }
      .leave-ui .lv-cal-day.selected:hover{ background:var(--lv-sage-dark); }
      .leave-ui .lv-cal-day:disabled{ color:#DAD0BC; cursor:not-allowed; }
      .leave-ui .lv-cal-foot{ display:flex; justify-content:flex-end; margin-top:8px; padding-top:10px; border-top:1px solid var(--lv-line); }
      .leave-ui .lv-cal-today-btn{ font-size:12px; font-weight:600; color:var(--lv-sage-dark); background:none; border:none; cursor:pointer; padding:4px 6px; border-radius:5px; }
      .leave-ui .lv-cal-today-btn:hover{ background:var(--lv-sage-tint); }

      /* -- Leave-balance cards (employee view) -- */
      .leave-ui .lv-balance-row{ display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-bottom:20px; }
      .leave-ui .lv-balance-card{
        border:1px solid var(--lv-line); border-radius:8px; padding:16px 18px; background:var(--lv-paper);
        display:flex; align-items:center; justify-content:space-between;
      }
      .leave-ui .lv-balance-card .lv-balance-label{ font-size:12px; font-weight:600; color:var(--lv-ink-soft); text-transform:uppercase; letter-spacing:.03em; }
      .leave-ui .lv-balance-card .lv-balance-value{ font-family:'Fraunces',serif; font-size:28px; font-weight:600; color:var(--lv-sage-dark); }
      .leave-ui .lv-balance-card.vacation{ border-left:4px solid var(--lv-sage); }
      .leave-ui .lv-balance-card.sick{ border-left:4px solid var(--lv-clay); }

      /* -- Manager view: at-a-glance stats -- */
      .leave-ui .lv-stats-row{ display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:14px; margin-bottom:22px; }
      .leave-ui .lv-stat-card{ border:1px solid var(--lv-line); border-radius:8px; padding:14px 16px; background:var(--lv-paper); }
      .leave-ui .lv-stat-card .lv-stat-num{ font-family:'Fraunces',serif; font-size:26px; font-weight:600; color:var(--lv-ink); line-height:1.1; }
      .leave-ui .lv-stat-card .lv-stat-label{ font-size:11.5px; font-weight:600; color:var(--lv-ink-soft); text-transform:uppercase; letter-spacing:.03em; margin-top:4px; }
      .leave-ui .lv-stat-card.pending{ border-left:4px solid var(--lv-clay); }
      .leave-ui .lv-stat-card.approved{ border-left:4px solid var(--lv-sage); }
      .leave-ui .lv-stat-card.rejected{ border-left:4px solid var(--lv-rust); }
      .leave-ui .lv-stat-card.cancelled{ border-left:4px solid var(--lv-neutral-ink); }

      /* -- Manager view: pending requests surfaced as actionable cards, -- */
      /* -- separate from the resolved-requests ledger table below      -- */
      .leave-ui .lv-pending-section{ margin-bottom:26px; }
      .leave-ui .lv-section-label{
        font-family:'Inter',sans-serif; font-size:12.5px; font-weight:700; color:var(--lv-ink-soft);
        text-transform:uppercase; letter-spacing:.04em; margin-bottom:12px;
      }
      .leave-ui .lv-pending-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
      .leave-ui .lv-pending-card{
        border:1px solid var(--lv-line); border-left:4px solid var(--lv-clay); border-radius:8px;
        padding:16px 18px; background:var(--lv-paper);
      }
      .leave-ui .lv-pending-card .lv-pc-top{ display:flex; align-items:baseline; justify-content:space-between; gap:8px; margin-bottom:4px; }
      .leave-ui .lv-pending-card .lv-pc-name{ font-family:'Fraunces',serif; font-weight:600; font-size:15px; color:var(--lv-ink); }
      .leave-ui .lv-pending-card .lv-pc-type{ font-size:11.5px; font-weight:600; color:var(--lv-clay); text-transform:uppercase; letter-spacing:.02em; }
      .leave-ui .lv-pending-card .lv-pc-dates{ font-family:'IBM Plex Mono',monospace; font-size:12.5px; color:var(--lv-ink-soft); margin-bottom:8px; }
      .leave-ui .lv-pending-card .lv-pc-reason{ font-size:13px; color:var(--lv-ink); margin-bottom:14px; line-height:1.4; }
      .leave-ui .lv-pending-card .lv-pc-actions{ display:flex; gap:8px; }
      .leave-ui .lv-empty-note{ font-size:13px; color:var(--lv-ink-soft); font-style:italic; padding:4px 0 4px; }
    </style>

    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <script>
        document.addEventListener('DOMContentLoaded', function(){
          Swal.fire({
            icon: <?= json_encode($mt === 'success' ? 'success' : 'error') ?>,
            title: <?= json_encode($mt === 'success' ? 'Done' : 'Something went wrong') ?>,
            text: <?= json_encode($mm) ?>,
            confirmButtonColor: '#5C8374'
          });
        });
      </script>
    <?php endif; ?>

    <?php if (!$can_approve): ?>

    <div class="lv-balance-row">
      <div class="lv-balance-card vacation">
        <div><div class="lv-balance-label">Vacation Leave</div></div>
        <div class="lv-balance-value"><?= isset($bal['vacation_leave_balance']) ? number_format((float)$bal['vacation_leave_balance'], 0) : '—' ?></div>
      </div>
      <div class="lv-balance-card sick">
        <div><div class="lv-balance-label">Sick Leave</div></div>
        <div class="lv-balance-value"><?= isset($bal['sick_leave_balance']) ? number_format((float)$bal['sick_leave_balance'], 0) : '—' ?></div>
      </div>
    </div>

    <div class="widget">
      <div class="widget-header"><div class="widget-title">Request Leave</div></div>
      <form method="POST" id="leaveRequestForm">
        <input type="hidden" name="act" value="request">
        <div class="form-row">
          <div class="form-group-admin">
            <label>Leave Type</label>
            <div class="lv-control" id="leaveTypeControl">
              <button type="button" class="lv-trigger" id="leaveTypeTrigger">
                <span id="leaveTypeValue">Vacation</span>
                <svg class="lv-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m6 9 6 6 6-6"/></svg>
              </button>
              <div class="lv-panel">
                <ul class="lv-select-list" role="listbox">
                  <li class="lv-select-option selected" role="option" data-value="vacation">
                    <span>Vacation</span>
                    <svg class="lv-check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 13l4 4L19 7"/></svg>
                  </li>
                  <li class="lv-select-option" role="option" data-value="sick">
                    <span>Sick</span>
                    <svg class="lv-check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 13l4 4L19 7"/></svg>
                  </li>
                  <li class="lv-select-option" role="option" data-value="emergency">
                    <span>Emergency</span>
                    <svg class="lv-check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 13l4 4L19 7"/></svg>
                  </li>
                  <li class="lv-select-option" role="option" data-value="unpaid">
                    <span>Unpaid</span>
                    <svg class="lv-check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 13l4 4L19 7"/></svg>
                  </li>
                  <li class="lv-select-option" role="option" data-value="other">
                    <span>Other</span>
                    <svg class="lv-check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 13l4 4L19 7"/></svg>
                  </li>
                </ul>
              </div>
            </div>
            <input type="hidden" name="leave_type" id="leaveType" value="vacation">
            <div style="font-size:11.5px;color:var(--hr-text-light);margin-top:4px" id="leaveLimitNote">
              Max 3 days for regular leave. Select Emergency for longer requests.
            </div>
          </div>
          <div class="form-group-admin">
            <label>From</label>
            <div class="lv-control lv-datepicker" id="dateFromControl">
              <button type="button" class="lv-trigger">
                <svg class="lv-cal-ic" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
                <span class="lv-date-value placeholder">Select date</span>
              </button>
              <div class="lv-panel lv-date-field">
                <div class="lv-cal-head">
                  <button type="button" class="lv-cal-nav prev">‹</button>
                  <span class="lv-cal-month">—</span>
                  <button type="button" class="lv-cal-nav next">›</button>
                </div>
                <div class="lv-cal-weekdays"><span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span></div>
                <div class="lv-cal-grid"></div>
                <div class="lv-cal-foot"><button type="button" class="lv-cal-today-btn">Today</button></div>
              </div>
            </div>
            <input type="hidden" name="date_from" id="dateFrom">
          </div>
          <div class="form-group-admin">
            <label>To</label>
            <div class="lv-control lv-datepicker" id="dateToControl">
              <button type="button" class="lv-trigger">
                <svg class="lv-cal-ic" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
                <span class="lv-date-value placeholder">Select date</span>
              </button>
              <div class="lv-panel lv-date-field">
                <div class="lv-cal-head">
                  <button type="button" class="lv-cal-nav prev">‹</button>
                  <span class="lv-cal-month">—</span>
                  <button type="button" class="lv-cal-nav next">›</button>
                </div>
                <div class="lv-cal-weekdays"><span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span></div>
                <div class="lv-cal-grid"></div>
                <div class="lv-cal-foot"><button type="button" class="lv-cal-today-btn">Today</button></div>
              </div>
            </div>
            <input type="hidden" name="date_to" id="dateTo">
          </div>
        </div>
        <div class="form-group-admin">
          <label>Reason</label>
          <textarea name="reason" rows="2" placeholder="Optional"></textarea>
        </div>
        <button type="button" id="leaveSubmitBtn" class="btn btn-primary">Submit Request</button>
      </form>
    </div>

    <script>
      (function(){
        var typeEl    = document.getElementById('leaveType');
        var fromEl    = document.getElementById('dateFrom');
        var toEl      = document.getElementById('dateTo');
        var noteEl    = document.getElementById('leaveLimitNote');
        var formEl    = document.getElementById('leaveRequestForm');
        var submitBtn = document.getElementById('leaveSubmitBtn');
        var reasonEl  = formEl ? formEl.querySelector('textarea[name="reason"]') : null;

        var required = { typeEl:typeEl, fromEl:fromEl, toEl:toEl, noteEl:noteEl, formEl:formEl,
          submitBtn:submitBtn, reasonEl:reasonEl };
        for (var key in required) {
          if (!required[key]) {
            console.error('Leave request form: missing element "' + key + '" — wiring skipped.');
            return;
          }
        }

        var typeLabels = {
          vacation: 'Vacation', sick: 'Sick', emergency: 'Emergency',
          unpaid: 'Unpaid', other: 'Other'
        };

        // Today at local midnight, so "no past dates" means literally today onward.
        function todayMidnight() {
          var t = new Date();
          t.setHours(0,0,0,0);
          return t;
        }

        function parseDate(v) {
          // value is 'YYYY-MM-DD' from <input type=date>; parse as local, not UTC.
          var parts = v.split('-');
          return new Date(parseInt(parts[0],10), parseInt(parts[1],10) - 1, parseInt(parts[2],10));
        }

        function formatDate(d) {
          return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function setNote(text, isError) {
          noteEl.style.color = isError ? '#d33' : '';
          noteEl.textContent = text;
        }

        var defaultNote = 'Max 3 days for regular leave. Select Emergency for longer requests.';

        function checkLimit() {
          if (!fromEl.value || !toEl.value) { setNote(defaultNote, false); return true; }
          var from = parseDate(fromEl.value);
          var to   = parseDate(toEl.value);
          var days = Math.round((to - from) / 86400000) + 1;
          if (typeEl.value !== 'emergency' && days > 3) {
            setNote('Regular leave is limited to 3 days. Select Emergency for longer requests.', true);
            return false;
          }
          setNote(defaultNote, false);
          return true;
        }

        function escapeHtml(s) {
          return s.replace(/[&<>"']/g, function(c){
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
          });
        }

        function confirmAndSubmit() {
          var from = parseDate(fromEl.value);
          var to   = parseDate(toEl.value);
          var days = Math.round((to - from) / 86400000) + 1;
          var reasonVal = (reasonEl.value || '').trim();

          var rows = ''
            + '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #eee;"><span>Leave type</span><strong>' + escapeHtml(typeLabels[typeEl.value] || typeEl.value) + '</strong></div>'
            + '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #eee;"><span>Dates</span><strong>' + escapeHtml(formatDate(from) + ' – ' + formatDate(to)) + '</strong></div>'
            + '<div style="display:flex;justify-content:space-between;padding:6px 0;' + (reasonVal ? 'border-bottom:1px solid #eee;' : '') + '"><span>Total duration</span><strong>' + (days + (days === 1 ? ' day' : ' days')) + '</strong></div>'
            + (reasonVal ? '<div style="display:flex;justify-content:space-between;padding:6px 0;gap:12px;"><span>Reason</span><strong style="text-align:right;">' + escapeHtml(reasonVal) + '</strong></div>' : '');

          Swal.fire({
            title: 'Confirm leave request',
            html: '<div style="text-align:left;font-size:14px;color:#334155;">' + rows + '</div>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Confirm request',
            cancelButtonText: 'Go back',
            confirmButtonColor: '#5C8374',
            cancelButtonColor: '#8A7B67',
            reverseButtons: true
          }).then(function(result){
            if (result.isConfirmed) formEl.submit();
          });
        }

        [typeEl, fromEl, toEl].forEach(function(el){ el.addEventListener('change', checkLimit); });

        submitBtn.addEventListener('click', function(){
          if (!fromEl.value || !toEl.value) {
            setNote('Please choose both a From and a To date.', true);
            (fromEl.value ? toEl : fromEl).focus();
            return;
          }

          var from = parseDate(fromEl.value);
          var to   = parseDate(toEl.value);
          var today = todayMidnight();

          if (from < today) {
            setNote('The From date cannot be earlier than today.', true);
            fromEl.focus();
            return;
          }
          if (to < from) {
            setNote('The To date cannot be earlier than the From date.', true);
            toEl.focus();
            return;
          }
          if (!checkLimit()) return;

          confirmAndSubmit();
        });
      })();
    </script>

    <script>
      (function(){
        // ---------- shared open/close handling for the styled controls ----------
        function allControls(){ return document.querySelectorAll('.lv-control'); }
        function closeAllExcept(except){
          allControls().forEach(function(c){ if (c !== except) c.classList.remove('open'); });
        }
        document.addEventListener('click', function(e){
          allControls().forEach(function(c){ if (!c.contains(e.target)) c.classList.remove('open'); });
        });
        document.addEventListener('keydown', function(e){
          if (e.key === 'Escape') allControls().forEach(function(c){ c.classList.remove('open'); });
        });

        // ---------- Leave type dropdown ----------
        var typeControl = document.getElementById('leaveTypeControl');
        if (typeControl) {
          var typeTrigger = document.getElementById('leaveTypeTrigger');
          var typeValueEl = document.getElementById('leaveTypeValue');
          var typeHidden  = document.getElementById('leaveType');
          var typeOptions = typeControl.querySelectorAll('.lv-select-option');

          typeTrigger.addEventListener('click', function(e){
            e.stopPropagation();
            var willOpen = !typeControl.classList.contains('open');
            closeAllExcept(typeControl);
            typeControl.classList.toggle('open', willOpen);
          });

          typeOptions.forEach(function(opt){
            opt.addEventListener('click', function(){
              typeOptions.forEach(function(o){ o.classList.remove('selected'); });
              opt.classList.add('selected');
              typeValueEl.textContent = opt.querySelector('span').textContent;
              typeHidden.value = opt.dataset.value;
              typeHidden.dispatchEvent(new Event('change', { bubbles: true }));
              typeControl.classList.remove('open');
            });
          });
        }

        // ---------- Date pickers ----------
        var MONTH_NAMES = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        var lvToday = new Date();
        lvToday.setHours(0, 0, 0, 0);

        function fmtISO(d){
          var m = String(d.getMonth() + 1).padStart(2, '0');
          var day = String(d.getDate()).padStart(2, '0');
          return d.getFullYear() + '-' + m + '-' + day;
        }
        function fmtDisplay(d){
          return MONTH_NAMES[d.getMonth()].slice(0, 3) + ' ' + d.getDate() + ', ' + d.getFullYear();
        }
        function sameDay(a, b){
          return a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
        }

        function LvDatePicker(root, hiddenInput){
          this.root = root;
          this.hiddenInput = hiddenInput;
          this.trigger = root.querySelector('.lv-trigger');
          this.valueEl = root.querySelector('.lv-date-value');
          this.monthLabel = root.querySelector('.lv-cal-month');
          this.grid = root.querySelector('.lv-cal-grid');
          this.prevBtn = root.querySelector('.lv-cal-nav.prev');
          this.nextBtn = root.querySelector('.lv-cal-nav.next');
          this.todayBtn = root.querySelector('.lv-cal-today-btn');

          this.selected = null;
          this.viewYear = lvToday.getFullYear();
          this.viewMonth = lvToday.getMonth();

          var self = this;
          this.trigger.addEventListener('click', function(e){
            e.stopPropagation();
            var willOpen = !self.root.classList.contains('open');
            closeAllExcept(self.root);
            self.root.classList.toggle('open', willOpen);
            if (willOpen) self.render();
          });
          this.prevBtn.addEventListener('click', function(e){ e.stopPropagation(); self.shiftMonth(-1); });
          this.nextBtn.addEventListener('click', function(e){ e.stopPropagation(); self.shiftMonth(1); });
          this.todayBtn.addEventListener('click', function(e){
            e.stopPropagation();
            self.selected = new Date(lvToday);
            self.viewYear = lvToday.getFullYear();
            self.viewMonth = lvToday.getMonth();
            self.commit();
          });

          this.render();
        }

        LvDatePicker.prototype.shiftMonth = function(delta){
          this.viewMonth += delta;
          if (this.viewMonth < 0){ this.viewMonth = 11; this.viewYear--; }
          if (this.viewMonth > 11){ this.viewMonth = 0; this.viewYear++; }
          this.render();
        };

        LvDatePicker.prototype.commit = function(){
          this.valueEl.textContent = fmtDisplay(this.selected);
          this.valueEl.classList.remove('placeholder');
          this.hiddenInput.value = fmtISO(this.selected);
          this.hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
          this.root.classList.remove('open');
          this.render();
        };

        LvDatePicker.prototype.render = function(){
          this.monthLabel.textContent = MONTH_NAMES[this.viewMonth] + ' ' + this.viewYear;
          this.grid.innerHTML = '';

          var firstOfMonth = new Date(this.viewYear, this.viewMonth, 1);
          var startOffset = firstOfMonth.getDay();
          var daysInMonth = new Date(this.viewYear, this.viewMonth + 1, 0).getDate();
          var daysInPrevMonth = new Date(this.viewYear, this.viewMonth, 0).getDate();

          var cells = [];
          for (var i = startOffset - 1; i >= 0; i--){
            cells.push({ day: daysInPrevMonth - i, outside: true, date: new Date(this.viewYear, this.viewMonth - 1, daysInPrevMonth - i) });
          }
          for (var d = 1; d <= daysInMonth; d++){
            cells.push({ day: d, outside: false, date: new Date(this.viewYear, this.viewMonth, d) });
          }
          var nextDay = 1;
          while (cells.length % 7 !== 0){
            cells.push({ day: nextDay, outside: true, date: new Date(this.viewYear, this.viewMonth + 1, nextDay) });
            nextDay++;
          }

          var self = this;
          cells.forEach(function(cell){
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'lv-cal-day';
            btn.textContent = cell.day;
            if (cell.outside) btn.classList.add('outside');
            if (sameDay(cell.date, lvToday)) btn.classList.add('today');
            if (sameDay(cell.date, self.selected)) btn.classList.add('selected');
            if (cell.date < lvToday) btn.disabled = true;
            btn.addEventListener('click', function(e){
              e.stopPropagation();
              self.selected = cell.date;
              if (cell.outside){ self.viewYear = cell.date.getFullYear(); self.viewMonth = cell.date.getMonth(); }
              self.commit();
            });
            self.grid.appendChild(btn);
          });
        };

        var fromControl = document.getElementById('dateFromControl');
        var toControl   = document.getElementById('dateToControl');
        if (fromControl) new LvDatePicker(fromControl, document.getElementById('dateFrom'));
        if (toControl)   new LvDatePicker(toControl, document.getElementById('dateTo'));
      })();
    </script>
    <?php endif; ?>

    <?php if ($can_approve):
      $status_counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0];
      foreach ($requests as $r) { if (isset($status_counts[$r['status']])) $status_counts[$r['status']]++; }
      $pending_requests  = array_values(array_filter($requests, fn($r) => $r['status'] === 'pending'));
      $resolved_requests = array_values(array_filter($requests, fn($r) => $r['status'] !== 'pending'));
    ?>

    <div class="lv-stats-row">
      <div class="lv-stat-card pending"><div class="lv-stat-num"><?= $status_counts['pending'] ?></div><div class="lv-stat-label">Pending</div></div>
      <div class="lv-stat-card approved"><div class="lv-stat-num"><?= $status_counts['approved'] ?></div><div class="lv-stat-label">Approved</div></div>
      <div class="lv-stat-card rejected"><div class="lv-stat-num"><?= $status_counts['rejected'] ?></div><div class="lv-stat-label">Rejected</div></div>
      <div class="lv-stat-card cancelled"><div class="lv-stat-num"><?= $status_counts['cancelled'] ?></div><div class="lv-stat-label">Cancelled</div></div>
    </div>

    <div class="lv-pending-section">
      <div class="lv-section-label">Needs Your Review</div>
      <?php if (empty($pending_requests)): ?>
        <div class="lv-empty-note">No pending requests right now.</div>
      <?php else: ?>
        <div class="lv-pending-grid">
          <?php foreach ($pending_requests as $r): ?>
            <div class="lv-pending-card">
              <div class="lv-pc-top">
                <span class="lv-pc-name"><?= htmlspecialchars($r['full_name']) ?></span>
                <span class="lv-pc-type"><?= ucfirst($r['leave_type']) ?></span>
              </div>
              <div class="lv-pc-dates"><?= date('M d', strtotime($r['date_from'])) ?> – <?= date('M d, Y', strtotime($r['date_to'])) ?></div>
              <div class="lv-pc-reason"><?= htmlspecialchars($r['reason'] ?: 'No reason given.') ?></div>
              <div class="lv-pc-actions">
                <form method="POST"><input type="hidden" name="act" value="decide"><input type="hidden" name="leave_id" value="<?= $r['leave_id'] ?>"><input type="hidden" name="decision" value="approved"><button class="btn btn-sm btn-primary" type="submit">Approve</button></form>
                <form method="POST"><input type="hidden" name="act" value="decide"><input type="hidden" name="leave_id" value="<?= $r['leave_id'] ?>"><input type="hidden" name="decision" value="rejected"><button class="btn btn-sm btn-danger" type="submit">Reject</button></form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="widget">
      <div class="widget-header"><div class="widget-title">Request History</div></div>
      <table>
        <thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if (empty($resolved_requests)): ?>
            <tr><td colspan="6" class="empty-state">No resolved requests yet.</td></tr>
          <?php else: foreach ($resolved_requests as $r): $pc = ['approved'=>'pill-approved','rejected'=>'pill-rejected','cancelled'=>'pill-cancel'][$r['status']] ?? 'pill-cancel'; ?>
          <tr>
            <td><?= htmlspecialchars($r['full_name']) ?></td>
            <td><?= ucfirst($r['leave_type']) ?></td>
            <td class="lv-dates"><?= date('M d', strtotime($r['date_from'])) ?> – <?= date('M d, Y', strtotime($r['date_to'])) ?></td>
            <td style="max-width:220px"><?= htmlspecialchars($r['reason'] ?: '—') ?></td>
            <td><span class="status-pill <?= $pc ?>"><?= ucfirst($r['status']) ?></span></td>
            <td>
              <?php if ($r['status'] === 'approved'): ?>
                <form method="POST" class="revoke-leave-form" data-name="<?= htmlspecialchars($r['full_name'], ENT_QUOTES) ?>">
                  <input type="hidden" name="act" value="revoke">
                  <input type="hidden" name="leave_id" value="<?= $r['leave_id'] ?>">
                  <button class="btn btn-sm btn-danger" type="submit">Revoke</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php else: ?>

    <div class="widget">
      <div class="widget-header"><div class="widget-title">My Leave Requests</div></div>
      <table>
        <thead><tr><th>Type</th><th>Dates</th><th>Reason</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($requests)): ?>
            <tr><td colspan="5" class="empty-state">No leave requests yet.</td></tr>
          <?php else: foreach ($requests as $r): $pc = ['pending'=>'pill-pending','approved'=>'pill-approved','rejected'=>'pill-rejected','cancelled'=>'pill-cancel'][$r['status']] ?? 'pill-pending'; ?>
          <tr>
            <td><?= ucfirst($r['leave_type']) ?></td>
            <td class="lv-dates"><?= date('M d', strtotime($r['date_from'])) ?> – <?= date('M d, Y', strtotime($r['date_to'])) ?></td>
            <td style="max-width:220px"><?= htmlspecialchars($r['reason'] ?: '—') ?></td>
            <td><span class="status-pill <?= $pc ?>"><?= ucfirst($r['status']) ?></span></td>
            <td>
              <?php if ($r['status'] === 'pending'): ?>
                <form method="POST" class="cancel-leave-form">
                  <input type="hidden" name="act" value="cancel">
                  <input type="hidden" name="leave_id" value="<?= $r['leave_id'] ?>">
                  <button class="btn btn-sm btn-ghost" type="submit">Cancel</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php endif; ?>
  </div>
</div>

<script>
document.querySelectorAll('.cancel-leave-form').forEach(function(form){
  form.addEventListener('submit', function(e){
    e.preventDefault();
    Swal.fire({
      title: 'Cancel this request?',
      text: 'This leave request will be withdrawn.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, cancel it',
      cancelButtonText: 'Keep it',
      confirmButtonColor: '#9A4B3B',
      cancelButtonColor: '#8A7B67',
      reverseButtons: true
    }).then(function(result){
      if (result.isConfirmed) form.submit();
    });
  });
});

document.querySelectorAll('.revoke-leave-form').forEach(function(form){
  form.addEventListener('submit', function(e){
    e.preventDefault();
    var name = form.dataset.name;
    Swal.fire({
      title: 'Revoke this leave?',
      text: 'Revoke the approved leave for ' + name + '? This will restore their leave balance.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, revoke it',
      cancelButtonText: 'Go back',
      confirmButtonColor: '#9A4B3B',
      cancelButtonColor: '#8A7B67',
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