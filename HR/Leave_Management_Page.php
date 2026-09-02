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

// Re-sync employment_status against approved leave requests every load —
// this is what actually moves an employee onto the "On Leave" tab in
// Employee Records (and back to "Active" once their leave ends or is
// revoked), right after an approve/revoke above changes the picture.
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
</head>
<body>

<script src="../js/sidebar-toggle.js"></script>
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

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>"><?= $mm ?></div>
    <?php endif; ?>

    <?php if (!$can_approve): ?>
    <div class="kpi-grid">
      <div class="kpi-card" style="--accent-bg: rgba(59,130,192,.1)">
        <div class="kpi-val"><?= number_format((float)($bal['vacation_leave_balance'] ?? 0), 1) ?></div>
        <div class="kpi-label">Vacation Leave Balance</div>
      </div>
      <div class="kpi-card" style="--accent-bg: rgba(34,197,94,.08)">
        <div class="kpi-val"><?= number_format((float)($bal['sick_leave_balance'] ?? 0), 1) ?></div>
        <div class="kpi-label">Sick Leave Balance</div>
      </div>
    </div>

    <div class="widget">
      <div class="widget-header"><div class="widget-title">Request Leave</div></div>
      <form method="POST" id="leaveRequestForm">
        <input type="hidden" name="act" value="request">
        <div class="form-row">
          <div class="form-group-admin">
            <label>Leave Type</label>
            <select name="leave_type" id="leaveType">
              <option value="vacation">Vacation</option>
              <option value="sick">Sick</option>
              <option value="emergency">Emergency</option>
              <option value="unpaid">Unpaid</option>
              <option value="other">Other</option>
            </select>
            <div style="font-size:11.5px;color:var(--hr-text-light);margin-top:4px" id="leaveLimitNote">
              Max 3 days for regular leave. Select Emergency for longer requests.
            </div>
          </div>
          <div class="form-group-admin">
            <label>From</label>
            <input type="date" name="date_from" id="dateFrom" min="<?= htmlspecialchars($min_leave_date) ?>" required>
          </div>
          <div class="form-group-admin">
            <label>To</label>
            <input type="date" name="date_to" id="dateTo" min="<?= htmlspecialchars($min_leave_date) ?>" required>
          </div>
        </div>
        <div class="form-group-admin">
          <label>Reason</label>
          <textarea name="reason" rows="2" placeholder="Optional"></textarea>
        </div>
        <button type="button" id="leaveSubmitBtn" class="btn btn-primary">Submit Request</button>
      </form>
    </div>

    <div id="leaveConfirmModal" class="leave-confirm-overlay">
      <div class="leave-confirm-card">
        <div class="leave-confirm-icon">📋</div>
        <h2 class="leave-confirm-title">Confirm Leave Request</h2>
        <div class="leave-confirm-divider"></div>

        <div class="leave-confirm-row">
          <span>Leave Type</span>
          <strong id="cfType">—</strong>
        </div>
        <div class="leave-confirm-row">
          <span>Dates</span>
          <strong id="cfDates">—</strong>
        </div>

        <div class="leave-confirm-divider"></div>

        <div class="leave-confirm-row leave-confirm-total">
          <span>Total Duration</span>
          <strong id="cfDays">—</strong>
        </div>
        <div class="leave-confirm-row" id="cfReasonRow">
          <span>Reason</span>
          <strong id="cfReason">—</strong>
        </div>

        <div class="leave-confirm-actions">
          <button type="button" id="cfGoBack" class="btn btn-ghost">Go Back</button>
          <button type="button" id="cfConfirm" class="btn btn-primary">Confirm Request</button>
        </div>
      </div>
    </div>

    <style>
      .leave-confirm-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, .55);
        align-items: center;
        justify-content: center;
        z-index: 1000;
        padding: 16px;
      }
      .leave-confirm-card {
        background: var(--white);
        border-radius: 14px;
        width: 100%;
        max-width: 380px;
        padding: 28px 26px 24px;
        text-align: center;
        box-shadow: 0 20px 50px rgba(0,0,0,.25);
      }
      .leave-confirm-icon { font-size: 28px; margin-bottom: 6px; }
      .leave-confirm-title { margin: 0 0 14px; font-size: 20px; font-weight: 700; color: #0f172a; }
      .leave-confirm-divider { height: 1px; background: #e9e3d8; margin: 12px 0; }
      .leave-confirm-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        text-align: left;
        font-size: 14px;
        color: #334155;
        padding: 5px 0;
      }
      .leave-confirm-row strong { color: #0f172a; font-weight: 600; text-align: right; max-width: 60%; }
      .leave-confirm-total span { font-weight: 700; color: #0f172a; }
      .leave-confirm-total strong { color: #b8703f; font-size: 16px; }
      .leave-confirm-actions {
        display: flex;
        gap: 10px;
        margin-top: 20px;
      }
      .leave-confirm-actions .btn { flex: 1; }
    </style>

    <script>
      (function(){
        var typeEl    = document.getElementById('leaveType');
        var fromEl    = document.getElementById('dateFrom');
        var toEl      = document.getElementById('dateTo');
        var noteEl    = document.getElementById('leaveLimitNote');
        var formEl    = document.getElementById('leaveRequestForm');
        var submitBtn = document.getElementById('leaveSubmitBtn');
        var reasonEl  = formEl ? formEl.querySelector('textarea[name="reason"]') : null;

        var modal       = document.getElementById('leaveConfirmModal');
        var cfType       = document.getElementById('cfType');
        var cfDates      = document.getElementById('cfDates');
        var cfDays       = document.getElementById('cfDays');
        var cfReason     = document.getElementById('cfReason');
        var cfReasonRow  = document.getElementById('cfReasonRow');
        var cfGoBack     = document.getElementById('cfGoBack');
        var cfConfirm    = document.getElementById('cfConfirm');

        var required = { typeEl:typeEl, fromEl:fromEl, toEl:toEl, noteEl:noteEl, formEl:formEl,
          submitBtn:submitBtn, reasonEl:reasonEl, modal:modal, cfType:cfType, cfDates:cfDates,
          cfDays:cfDays, cfReason:cfReason, cfReasonRow:cfReasonRow, cfGoBack:cfGoBack, cfConfirm:cfConfirm };
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

        function openModal() {
          var from = parseDate(fromEl.value);
          var to   = parseDate(toEl.value);
          var days = Math.round((to - from) / 86400000) + 1;

          cfType.textContent  = typeLabels[typeEl.value] || typeEl.value;
          cfDates.textContent = formatDate(from) + ' – ' + formatDate(to);
          cfDays.textContent  = days + (days === 1 ? ' day' : ' days');

          var reasonVal = (reasonEl.value || '').trim();
          if (reasonVal) {
            cfReason.textContent = reasonVal;
            cfReasonRow.style.display = '';
          } else {
            cfReasonRow.style.display = 'none';
          }

          modal.style.display = 'flex';
        }

        function closeModal() {
          modal.style.display = 'none';
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

          openModal();
        });

        cfGoBack.addEventListener('click', closeModal);
        modal.addEventListener('click', function(e){
          if (e.target === modal) closeModal();
        });
        cfConfirm.addEventListener('click', function(){
          closeModal();
          formEl.submit();
        });
      })();
    </script>
    <?php endif; ?>

    <div class="widget">
      <div class="widget-header">
        <div class="widget-title"><?= $can_approve ? 'All Leave Requests' : 'My Leave Requests' ?></div>
      </div>
      <table>
        <thead>
          <tr>
            <?php if ($can_approve): ?><th>Employee</th><?php endif; ?>
            <th>Type</th><th>Dates</th><th>Reason</th><th>Status</th><?php if ($can_approve): ?><th>Actions</th><?php else: ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($requests)): ?>
            <tr><td colspan="6" class="empty-state">No leave requests yet.</td></tr>
          <?php else: foreach ($requests as $r): $pc = ['pending'=>'pill-pending','approved'=>'pill-approved','rejected'=>'pill-rejected','cancelled'=>'pill-cancel'][$r['status']] ?? 'pill-pending'; ?>
          <tr>
            <?php if ($can_approve): ?><td><?= htmlspecialchars($r['full_name']) ?></td><?php endif; ?>
            <td><?= ucfirst($r['leave_type']) ?></td>
            <td><?= date('M d', strtotime($r['date_from'])) ?> – <?= date('M d, Y', strtotime($r['date_to'])) ?></td>
            <td style="max-width:220px"><?= htmlspecialchars($r['reason'] ?: '—') ?></td>
            <td><span class="status-pill <?= $pc ?>"><?= ucfirst($r['status']) ?></span></td>
            <td>
              <?php if ($can_approve && $r['status'] === 'pending'): ?>
                <div style="display:flex;gap:6px">
                  <form method="POST"><input type="hidden" name="act" value="decide"><input type="hidden" name="leave_id" value="<?= $r['leave_id'] ?>"><input type="hidden" name="decision" value="approved"><button class="btn btn-sm btn-primary" type="submit">Approve</button></form>
                  <form method="POST"><input type="hidden" name="act" value="decide"><input type="hidden" name="leave_id" value="<?= $r['leave_id'] ?>"><input type="hidden" name="decision" value="rejected"><button class="btn btn-sm btn-danger" type="submit">Reject</button></form>
                </div>
              <?php elseif ($can_approve && $r['status'] === 'approved'): ?>
                <form method="POST" class="revoke-leave-form" data-name="<?= htmlspecialchars($r['full_name'], ENT_QUOTES) ?>">
                  <input type="hidden" name="act" value="revoke">
                  <input type="hidden" name="leave_id" value="<?= $r['leave_id'] ?>">
                  <button class="btn btn-sm btn-danger" type="submit">Revoke</button>
                </form>
              <?php elseif (!$can_approve && $r['status'] === 'pending'): ?>
                <form method="POST"><input type="hidden" name="act" value="cancel"><input type="hidden" name="leave_id" value="<?= $r['leave_id'] ?>"><button class="btn btn-sm btn-ghost" type="submit">Cancel</button></form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.revoke-leave-form').forEach(function(form){
  form.addEventListener('submit', function(e){
    e.preventDefault();
    var name = form.dataset.name;
    if (confirm('Revoke the approved leave for ' + name + '? This will restore their leave balance.')) {
      form.submit();
    }
  });
});
</script>

<script src="../js/msg_banner_autodismiss.js"></script>
<script src="../js/theme-toggle.js"></script>
</body>
</html>