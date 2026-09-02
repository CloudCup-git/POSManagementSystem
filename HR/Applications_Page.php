<?php
require_once __DIR__ . '/../admin/Permissions.php';
require_hr_login();
require_permission('manage_job_postings');
require_once __DIR__ . '/../includes/DB_Connect.php';
require_once __DIR__ . '/../includes/hr_mailer.php';

$active_page = 'hr_applications';
$uid         = current_hr_user_id();
$msg         = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
$job_id      = (int)($_GET['job_id'] ?? 0);

// Which stage-list is currently showing. Kept as a GET param (like job_id
// already is) rather than a JS-only toggle, so the tab you're on survives
// a manual refresh and the 3s auto-refresh below without snapping back to
// "New" every time.
const APP_STAGES = ['new', 'initial', 'final', 'hired', 'not_qualified'];
$stage     = in_array($_GET['stage'] ?? '', APP_STAGES, true) ? $_GET['stage'] : 'new';
$job_id_qs = $job_id > 0 ? '&job_id=' . $job_id : '';

const APP_STATUSES = ['new', 'reviewed', 'interview', 'shortlisted', 'final_interview', 'rejected', 'hired'];

// Track when an interview invite was last emailed. Added on the fly (same
// pattern used elsewhere in this app) so no manual migration is needed.
// interview_email_sent_at        -> initial interview invite
// final_interview_email_sent_at  -> final interview invite
if ($conn) {
    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM job_applications LIKE 'interview_email_sent_at'");
    if ($col_check && mysqli_num_rows($col_check) === 0) {
        mysqli_query($conn, "ALTER TABLE job_applications ADD COLUMN interview_email_sent_at DATETIME NULL");
    }
    $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM job_applications LIKE 'final_interview_email_sent_at'");
    if ($col_check2 && mysqli_num_rows($col_check2) === 0) {
        mysqli_query($conn, "ALTER TABLE job_applications ADD COLUMN final_interview_email_sent_at DATETIME NULL");
    }
    // The interview's actual scheduled date/time (picked by HR in the invite
    // popup), separate from *_email_sent_at which just tracks when the
    // invite email itself went out.
    $col_check3 = mysqli_query($conn, "SHOW COLUMNS FROM job_applications LIKE 'interview_datetime'");
    if ($col_check3 && mysqli_num_rows($col_check3) === 0) {
        mysqli_query($conn, "ALTER TABLE job_applications ADD COLUMN interview_datetime DATETIME NULL");
    }
    $col_check4 = mysqli_query($conn, "SHOW COLUMNS FROM job_applications LIKE 'final_interview_datetime'");
    if ($col_check4 && mysqli_num_rows($col_check4) === 0) {
        mysqli_query($conn, "ALTER TABLE job_applications ADD COLUMN final_interview_datetime DATETIME NULL");
    }
    // Links a hired applicant's original application back to the employees
    // row it produced, so Employee Records can show everything they
    // submitted (address, availability, skills, resume, etc.) via an
    // "Employee Record" button. Set once, at hire time, below.
    $col_check5 = mysqli_query($conn, "SHOW COLUMNS FROM job_applications LIKE 'hired_employee_id'");
    if ($col_check5 && mysqli_num_rows($col_check5) === 0) {
        mysqli_query($conn, "ALTER TABLE job_applications ADD COLUMN hired_employee_id INT NULL");
    }

    // Self-heal: 'status' was originally created as an ENUM, back before
    // 'final_interview' existed as a value this app writes to it. Writing
    // an ENUM value that isn't in its defined list doesn't error in MySQL's
    // default (non-strict) mode — it just silently stores '' instead, so
    // "Approve for final interview" looks like it works but the applicant's
    // status quietly becomes blank and they vanish from every tab. Convert
    // the column to VARCHAR once so it can hold any status this app uses.
    $status_col = mysqli_query($conn, "SHOW COLUMNS FROM job_applications LIKE 'status'");
    if ($status_col) {
        $status_col_info = mysqli_fetch_assoc($status_col);
        if ($status_col_info && stripos($status_col_info['Type'], 'enum') === 0) {
            mysqli_query($conn,
                "ALTER TABLE job_applications MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'new'");
        }
    }

    // Recover any applications that already got silently truncated to ''
    // by the bug above before this fix was in place. There's no way to know
    // which stage they were actually meant to reach, so they're put back at
    // 'new' — safest default, and they'll simply need to be re-approved.
    mysqli_query($conn, "UPDATE job_applications SET status='new' WHERE status='' OR status IS NULL");

    // Self-heal: an applicant should never be sitting on 'shortlisted' (Approved)
    // while an initial interview invite has already gone out — that combination
    // means a previous status update didn't fully take (e.g. a silently failed
    // query). Bump those rows forward so the correct "Approve for final
    // interview" action reappears instead of leaving them stuck.
    mysqli_query($conn,
        "UPDATE job_applications
         SET status = 'interview'
         WHERE status = 'shortlisted' AND interview_email_sent_at IS NOT NULL");

    // Monthly salary, captured on the Hire step and carried onto the
    // employee's profile + their emailed contract. Added on the fly, same
    // pattern as the columns above.
    $col_check5 = mysqli_query($conn, "SHOW COLUMNS FROM employees LIKE 'monthly_salary'");
    if ($col_check5 && mysqli_num_rows($col_check5) === 0) {
        mysqli_query($conn, "ALTER TABLE employees ADD COLUMN monthly_salary DECIMAL(10,2) NULL");
    }

    // Same self-heal as Job_Postings_Page.php — deleting a posting there
    // now sets deleted_at instead of removing the row, so every stage
    // list here (including Hired and Not Qualified) keeps showing
    // applicants whose posting was later deleted, since the JOIN below
    // still finds a job_postings row to match against. Checked here too
    // in case this page loads before Job_Postings_Page.php ever has.
    $col_check6 = mysqli_query($conn, "SHOW COLUMNS FROM job_postings LIKE 'deleted_at'");
    if ($col_check6 && mysqli_num_rows($col_check6) === 0) {
        mysqli_query($conn, "ALTER TABLE job_postings ADD COLUMN deleted_at DATETIME NULL");
    }
}

if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'update_status') {
    $app_id = (int)($_POST['application_id'] ?? 0);
    $status = in_array($_POST['status'] ?? '', APP_STATUSES, true) ? $_POST['status'] : 'new';
    $s = mysqli_prepare($conn,
        "UPDATE job_applications SET status=?, reviewed_by=?, reviewed_at=NOW() WHERE application_id=?");
    mysqli_stmt_bind_param($s, 'sii', $status, $uid, $app_id);
    $ok = mysqli_stmt_execute($s);
    $msg = $ok ? 'success:Application updated.' : ('error:Update failed: ' . mysqli_error($conn));
}

// "Approve" — the applicant has been reviewed and looks worth pursuing.
// This is the gate that has to be passed before "For Initial Interview" appears.
if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'approve_application') {
    $app_id = (int)($_POST['application_id'] ?? 0);
    $s = mysqli_prepare($conn,
        "UPDATE job_applications SET status='shortlisted', reviewed_by=?, reviewed_at=NOW() WHERE application_id=? AND status IN ('new','reviewed')");
    mysqli_stmt_bind_param($s, 'ii', $uid, $app_id);
    $ok = mysqli_stmt_execute($s);
    $msg = $ok ? 'success:Applicant approved — ready for the initial interview invite.' : ('error:Update failed: ' . mysqli_error($conn));
}

// Fallback approve — for applications sitting on a status value that isn't
// one of the recognized stages (new/reviewed/shortlisted/interview/final_interview).
// Moves them straight to 'shortlisted' so the normal flow can pick back up,
// same as a regular approve, but without the status IN (...) restriction.
if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'approve_application_fallback') {
    $app_id = (int)($_POST['application_id'] ?? 0);
    $s = mysqli_prepare($conn,
        "UPDATE job_applications SET status='shortlisted', reviewed_by=?, reviewed_at=NOW() WHERE application_id=? AND status NOT IN ('rejected','hired')");
    mysqli_stmt_bind_param($s, 'ii', $uid, $app_id);
    $ok = mysqli_stmt_execute($s);
    $msg = $ok ? 'success:Applicant approved — ready for the initial interview invite.' : ('error:Update failed: ' . mysqli_error($conn));
}

// "Reject"
if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'reject_application') {
    $app_id = (int)($_POST['application_id'] ?? 0);
    $s = mysqli_prepare($conn,
        "SELECT ja.applicant_name, ja.email, jp.title
         FROM job_applications ja JOIN job_postings jp ON jp.job_id = ja.job_id
         WHERE ja.application_id = ?");
    mysqli_stmt_bind_param($s, 'i', $app_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $app = $r ? mysqli_fetch_assoc($r) : null;

    $u = mysqli_prepare($conn,
        "UPDATE job_applications SET status='rejected', reviewed_by=?, reviewed_at=NOW() WHERE application_id=?");
    mysqli_stmt_bind_param($u, 'ii', $uid, $app_id);
    $ok = mysqli_stmt_execute($u);
    if (!$ok) {
        $msg = 'error:Update failed: ' . mysqli_error($conn);
    } else {
        // Best-effort — the status change is what matters; a failed email
        // shouldn't block the rejection or show as an error to the reviewer.
        if ($app) {
            send_not_qualified_email($app['email'], $app['applicant_name'], $app['title']);
        }
        $msg = 'success:Application rejected and the applicant has been notified by email.';
    }
}

// Auto-provisions the HR side of a hire: Employee Records (and the
// Employee Accounts / RBAC list) is driven entirely off the `users` +
// `employees` tables, so flipping job_applications.status to 'hired'
// alone would never make the applicant show up there. This gives them
// a login (role='employee') and an employees profile row seeded with
// the position + department from the posting they were hired into.
// Returns the new login credentials, or null username/password if an
// existing active account was reused instead of creating a new one.
function hire_provision_employee(mysqli $conn, string $name, string $phone, string $position, string $department, float $monthly_salary): array {
    // Reuse an existing active account for this person if one already
    // exists (e.g. former staff re-applying, or hired off a second
    // posting) rather than creating a duplicate login — same dedup rule
    // Employee Accounts uses when creating accounts by hand.
    $s = mysqli_prepare($conn, "SELECT user_id FROM users WHERE LOWER(full_name) = LOWER(?) AND is_active = 1 LIMIT 1");
    mysqli_stmt_bind_param($s, 's', $name);
    mysqli_stmt_execute($s);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($s));

    $username = null;
    $temp_password = null;

    if ($existing) {
        $employee_id = (int)$existing['user_id'];
    } else {
        // Build a unique username from the applicant's name, e.g.
        // "Juan Dela Cruz" -> "juan.delacruz" (then "juan.delacruz2", ...).
        $base = strtolower(trim(preg_replace('/[^a-z]+/i', '.', $name), '.'));
        $base = preg_replace('/\.+/', '.', $base);
        if ($base === '') $base = 'employee';
        $username = $base;
        $suffix = 1;
        while (true) {
            $chk = mysqli_prepare($conn, "SELECT 1 FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
            mysqli_stmt_bind_param($chk, 's', $username);
            mysqli_stmt_execute($chk);
            if (mysqli_num_rows(mysqli_stmt_get_result($chk)) === 0) break;
            $suffix++;
            $username = $base . $suffix;
        }

        $temp_password = substr(bin2hex(random_bytes(6)), 0, 10);
        $hash = password_hash($temp_password, PASSWORD_DEFAULT);
        $ins = mysqli_prepare($conn,
            "INSERT INTO users (full_name, username, password, role, is_active) VALUES (?,?,?,'employee',1)");
        mysqli_stmt_bind_param($ins, 'sss', $name, $username, $hash);
        mysqli_stmt_execute($ins);
        $employee_id = (int)mysqli_insert_id($conn);
    }

    // Seed/refresh their HR profile with the position + department for
    // the posting they were just hired into.
    $chk_emp = mysqli_prepare($conn, "SELECT employee_id FROM employees WHERE employee_id = ?");
    mysqli_stmt_bind_param($chk_emp, 'i', $employee_id);
    mysqli_stmt_execute($chk_emp);
    $has_emp_row = mysqli_num_rows(mysqli_stmt_get_result($chk_emp)) > 0;

    if ($has_emp_row) {
        $u = mysqli_prepare($conn,
            "UPDATE employees SET position=?, department=?, contact_number=?, employment_status='active', date_hired=CURDATE(), monthly_salary=? WHERE employee_id=?");
        mysqli_stmt_bind_param($u, 'sssdi', $position, $department, $phone, $monthly_salary, $employee_id);
        mysqli_stmt_execute($u);
    } else {
        $ins2 = mysqli_prepare($conn,
            "INSERT INTO employees (employee_id, position, department, contact_number, employment_status, date_hired, monthly_salary) VALUES (?,?,?,?, 'active', CURDATE(), ?)");
        mysqli_stmt_bind_param($ins2, 'isssd', $employee_id, $position, $department, $phone, $monthly_salary);
        mysqli_stmt_execute($ins2);
    }

    return ['username' => $username, 'password' => $temp_password, 'employee_id' => $employee_id];
}

// "Hired" — only reachable once the applicant has cleared the final
// interview stage (status = 'final_interview') AND the final interview
// invite has actually been sent; enforced here as well as in the UI.
if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'hire_application') {
    $app_id  = (int)($_POST['application_id'] ?? 0);
    $salary  = (float)($_POST['monthly_salary'] ?? 0);

    if ($salary <= 0) {
        $msg = 'error:Enter a valid monthly salary before marking this applicant as hired.';
    } else {
    $s = mysqli_prepare($conn,
        "SELECT ja.applicant_name, ja.email, ja.phone, jp.title, jp.department, jp.employment_type
         FROM job_applications ja JOIN job_postings jp ON jp.job_id = ja.job_id
         WHERE ja.application_id = ?");
    mysqli_stmt_bind_param($s, 'i', $app_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $app = $r ? mysqli_fetch_assoc($r) : null;

    $u = mysqli_prepare($conn,
        "UPDATE job_applications SET status='hired', reviewed_by=?, reviewed_at=NOW() WHERE application_id=? AND status='final_interview' AND final_interview_email_sent_at IS NOT NULL");
    mysqli_stmt_bind_param($u, 'ii', $uid, $app_id);
    $ok = mysqli_stmt_execute($u);
    if (!$ok) {
        $msg = 'error:Update failed: ' . mysqli_error($conn);
    } elseif (mysqli_affected_rows($conn) === 0) {
        $msg = 'error:Send the final interview invite before marking this applicant as hired.';
    } else {
        // Best-effort — the status change is what matters; a failed email
        // shouldn't block marking someone hired or show as an error.
        if ($app) {
            send_hired_email($app['email'], $app['applicant_name'], $app['title']);

            $creds = hire_provision_employee($conn, $app['applicant_name'], (string)$app['phone'], $app['title'], $app['department'], $salary);
            if ($creds['username'] !== null) {
                send_employee_account_email($app['email'], $app['applicant_name'], $creds['username'], $creds['password']);
            }

            // Remember which employee this application became, so Employee
            // Records can pull up everything they submitted.
            $link = mysqli_prepare($conn, "UPDATE job_applications SET hired_employee_id=? WHERE application_id=?");
            mysqli_stmt_bind_param($link, 'ii', $creds['employee_id'], $app_id);
            mysqli_stmt_execute($link);

            // Employee Handbook + Employment Contract PDFs, each as its own
            // email — see includes/hr_mailer.php. Both fire together the
            // moment the applicant is marked Hired, so everything they need
            // before Day 1 arrives at once.
            send_employee_handbook_email($app['email'], $app['applicant_name']);

            send_employment_contract_email($app['email'], [
                'applicant_name'  => $app['applicant_name'],
                'position'        => $app['title'],
                'department'      => $app['department'],
                'employment_type' => $app['employment_type'],
                'monthly_salary'  => $salary,
                'start_date'      => date('Y-m-d'),
            ]);
        }
        $msg = 'success:Applicant marked as hired, added to Employee Records, and welcomed by email with their handbook and contract.';
    }
    }
}

// "Approve" (post-interview) — after the interview invite has gone out
// (status = 'interview'), HR marks the applicant as cleared for a final
// interview round.
if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'approve_interview') {
    $app_id = (int)($_POST['application_id'] ?? 0);
    $s = mysqli_prepare($conn,
        "UPDATE job_applications SET status='final_interview', reviewed_by=?, reviewed_at=NOW() WHERE application_id=? AND status='interview'");
    mysqli_stmt_bind_param($s, 'ii', $uid, $app_id);
    $ok = mysqli_stmt_execute($s);
    if (!$ok) {
        $msg = 'error:Update failed: ' . mysqli_error($conn);
    } elseif (mysqli_affected_rows($conn) === 0) {
        $msg = 'error:This applicant is no longer in the Initial Interview stage — refresh and check their current status.';
    } else {
        $msg = 'success:Applicant approved for final interview.';
    }
}

// "For Interview" — only reachable once the applicant has been approved
// (status = 'shortlisted'); enforced here as well as in the UI.
if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'mark_for_interview') {
    $app_id = (int)($_POST['application_id'] ?? 0);
    $s = mysqli_prepare($conn,
        "UPDATE job_applications SET status='interview', reviewed_by=?, reviewed_at=NOW() WHERE application_id=? AND status='shortlisted'");
    mysqli_stmt_bind_param($s, 'ii', $uid, $app_id);
    $ok = mysqli_stmt_execute($s);
    $msg = $ok ? 'success:Applicant marked for interview.' : ('error:Update failed: ' . mysqli_error($conn));
}

// Reads + validates the meeting-mode fields posted by the "Google Meet or
// Personal?" popup. Returns [$mode, $detail, $datetime] or [null, null, null]
// if invalid. $datetime is a MySQL-ready 'Y-m-d H:i:00' string.
function read_meeting_choice(): array {
    $mode   = ($_POST['meeting_mode'] ?? '') === 'gmeet' ? 'gmeet' : (($_POST['meeting_mode'] ?? '') === 'personal' ? 'personal' : '');
    $detail = trim((string)($_POST['meeting_detail'] ?? ''));
    $raw_time = trim((string)($_POST['meeting_time'] ?? ''));
    $ts = $raw_time !== '' ? strtotime($raw_time) : false;
    if ($mode === '' || $detail === '' || $ts === false) {
        return [null, null, null];
    }
    return [$mode, $detail, date('Y-m-d H:i:00', $ts)];
}

// "Send Interview Email" (Initial Interview) — emails the applicant, and if
// they weren't already further along the pipeline, also flips their status
// to 'interview' (Initial Interview) so the list and the email stay in sync.
if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'send_interview_email') {
    $app_id = (int)($_POST['application_id'] ?? 0);
    [$meeting_mode, $meeting_detail, $meeting_datetime] = read_meeting_choice();
    $s = mysqli_prepare($conn,
        "SELECT ja.applicant_name, ja.email, ja.status, jp.title
         FROM job_applications ja JOIN job_postings jp ON jp.job_id = ja.job_id
         WHERE ja.application_id = ?");
    mysqli_stmt_bind_param($s, 'i', $app_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $app = $r ? mysqli_fetch_assoc($r) : null;

    if (!$app) {
        $msg = 'error:Application not found.';
    } elseif (!in_array($app['status'], ['shortlisted', 'interview'], true)) {
        $msg = 'error:Approve this applicant before sending the initial interview invite.';
    } elseif ($meeting_mode === null) {
        $msg = 'error:Pick Google Meet or Personal, fill in the link/address, and set an interview date & time before sending the invite.';
    } else {
        $sent = send_interview_invite_email($app['email'], $app['applicant_name'], $app['title'], $meeting_mode, $meeting_detail, $meeting_datetime);
        if ($sent) {
            $new_status = in_array($app['status'], ['rejected', 'hired'], true) ? $app['status'] : 'interview';
            $u = mysqli_prepare($conn,
                "UPDATE job_applications SET status=?, reviewed_by=?, reviewed_at=NOW(), interview_email_sent_at=NOW(), interview_datetime=? WHERE application_id=?");
            mysqli_stmt_bind_param($u, 'sisi', $new_status, $uid, $meeting_datetime, $app_id);
            $ok = mysqli_stmt_execute($u);
            $msg = $ok
                ? 'success:Initial interview invite emailed to ' . htmlspecialchars($app['applicant_name']) . '.'
                : 'error:Email sent, but updating the applicant status failed: ' . mysqli_error($conn) . '. Please refresh and try Approve again.';
        } else {
            $msg = 'error:Could not send the email. Check the server\'s mail/SMTP setup.';
        }
    }
}

// "Send Final Interview Email" — emails the applicant once they've been
// approved into the final interview round (status = 'final_interview').
if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'send_final_interview_email') {
    $app_id = (int)($_POST['application_id'] ?? 0);
    [$meeting_mode, $meeting_detail, $meeting_datetime] = read_meeting_choice();
    $s = mysqli_prepare($conn,
        "SELECT ja.applicant_name, ja.email, ja.status, jp.title
         FROM job_applications ja JOIN job_postings jp ON jp.job_id = ja.job_id
         WHERE ja.application_id = ?");
    mysqli_stmt_bind_param($s, 'i', $app_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $app = $r ? mysqli_fetch_assoc($r) : null;

    if (!$app) {
        $msg = 'error:Application not found.';
    } elseif ($app['status'] !== 'final_interview') {
        $msg = 'error:This applicant isn\'t in the final interview round yet.';
    } elseif ($meeting_mode === null) {
        $msg = 'error:Pick Google Meet or Personal, fill in the link/address, and set an interview date & time before sending the invite.';
    } else {
        $sent = send_final_interview_invite_email($app['email'], $app['applicant_name'], $app['title'], $meeting_mode, $meeting_detail, $meeting_datetime);
        if ($sent) {
            $u = mysqli_prepare($conn,
                "UPDATE job_applications SET reviewed_by=?, reviewed_at=NOW(), final_interview_email_sent_at=NOW(), final_interview_datetime=? WHERE application_id=?");
            mysqli_stmt_bind_param($u, 'isi', $uid, $meeting_datetime, $app_id);
            $ok = mysqli_stmt_execute($u);
            $msg = $ok
                ? 'success:Final interview invite emailed to ' . htmlspecialchars($app['applicant_name']) . '.'
                : 'error:Email sent, but recording the invite failed: ' . mysqli_error($conn);
        } else {
            $msg = 'error:Could not send the email. Check the server\'s mail/SMTP setup.';
        }
    }
}

// ── Post/Redirect/Get ──────────────────────────────────────────────────
// Every action above runs on a POST request. Without this redirect, the
// page would render its result directly as the POST response — meaning
// hitting refresh (manually, or via the auto-refresh below) would re-POST
// and re-run whatever action was last submitted (re-rejecting, re-sending
// an email, etc.), and the banner would reflect a stale action instead of
// whatever you're actually doing now. Redirecting to a plain GET after
// every action makes the result page safe to reload as many times as you
// want.
if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
    // Stay on whichever stage-tab the action was taken from (it's read from
    // the query string, which POSTs here too since the forms have no
    // explicit action=""). The applicant who was just approved/invited/etc.
    // simply drops off this list on reload — you keep working through the
    // rest of the list instead of getting bounced to wherever they landed.
    $redirect_url = 'Applications_Page.php?stage=' . $stage . $job_id_qs;
    if ($msg !== '') {
        $redirect_url .= '&msg=' . urlencode($msg);
    }
    header('Location: ' . $redirect_url);
    exit;
}

$jobs = [];
if ($conn) {
    // Deleted postings are left out of the filter dropdown (nothing new
    // should be filtered by them), but applicants tied to a deleted
    // posting still appear under "All postings" and in their stage tab —
    // see the deleted_at self-heal note above.
    $res = mysqli_query($conn, "SELECT job_id, title FROM job_postings WHERE deleted_at IS NULL ORDER BY created_at DESC");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $jobs[] = $r;
}

$applications = [];
if ($conn) {
    if ($job_id > 0) {
        $s = mysqli_prepare($conn,
            "SELECT ja.*, jp.title FROM job_applications ja JOIN job_postings jp ON jp.job_id = ja.job_id
             WHERE ja.job_id = ? ORDER BY (ja.status='new') DESC, ja.submitted_at DESC");
        mysqli_stmt_bind_param($s, 'i', $job_id);
        mysqli_stmt_execute($s);
        $res = mysqli_stmt_get_result($s);
    } else {
        $res = mysqli_query($conn,
            "SELECT ja.*, jp.title FROM job_applications ja JOIN job_postings jp ON jp.job_id = ja.job_id
             ORDER BY (ja.status='new') DESC, ja.submitted_at DESC LIMIT 100");
    }
    if ($res) while ($r = mysqli_fetch_assoc($res)) $applications[] = $r;

    // Normalize status here so a stray capital letter or trailing space
    // from a manual database edit (e.g. via phpMyAdmin) can't silently
    // desync the pipeline — this is the actual root cause behind the
    // "stuck applicant" issues earlier: a status like 'Interview' or
    // 'interview ' doesn't strictly match 'interview' in PHP, so the UI
    // falls back to a generic "Invited"/"Approve" state instead of the
    // real stage-specific one.
    foreach ($applications as &$__a) {
        $__a['status'] = strtolower(trim((string)$__a['status']));
    }
    unset($__a);
}

// Bucket every application into one of the four stage-lists. An applicant
// moves list the moment their status changes — e.g. Approve on the New
// list sets status='shortlisted', which is exactly what lands them in the
// "For Initial Interview" bucket below.
function application_stage(string $status): string {
    if (in_array($status, ['shortlisted', 'interview'], true)) return 'initial';
    if ($status === 'final_interview') return 'final';
    if ($status === 'hired') return 'hired';
    if ($status === 'rejected') return 'not_qualified';
    return 'new'; // 'new', 'reviewed', and any unrecognized/legacy value
}

$apps_by_stage = ['new' => [], 'initial' => [], 'final' => [], 'hired' => [], 'not_qualified' => []];
foreach ($applications as $__a) {
    $apps_by_stage[application_stage($__a['status'])][] = $__a;
}

$stage_labels = [
    'new'           => 'New',
    'initial'       => 'For Initial Interview',
    'final'         => 'For Final Interview',
    'hired'         => 'Hired',
    'not_qualified' => 'Not Qualified',
];
$applications_shown = $apps_by_stage[$stage];

$pill_map = [
    'new'             => 'pill-pending',
    'reviewed'        => 'pill-onleave',
    'interview'       => 'pill-draft',
    'shortlisted'     => 'pill-active',
    'final_interview' => 'pill-final',
    'rejected'        => 'pill-inactive',
    'hired'           => 'pill-active',
];

// 'shortlisted' reads as "Approved" everywhere in the UI — the underlying
// status value is kept as-is so existing data doesn't need a migration.
$status_label_map = [
    'new'             => 'New',
    'reviewed'        => 'Reviewed',
    'shortlisted'     => 'Approved',
    'interview'       => 'Initial Interview',
    'final_interview' => 'Final Interview',
    'rejected'        => 'Rejected',
    'hired'           => 'Hired',
];

/**
 * Resolves the status pill's CSS class + label for one application row.
 * Falls back to "Invited" (rather than a blank/raw status string) whenever
 * an invite email has already gone out but the stored status value doesn't
 * match one of the known stages above — e.g. legacy/unexpected data.
 */
function status_pill_info(array $a, array $pill_map, array $status_label_map): array {
    $st = $a['status'];
    if (isset($status_label_map[$st])) {
        return [$pill_map[$st] ?? 'pill-pending', $status_label_map[$st]];
    }
    if (!empty($a['final_interview_email_sent_at']) || !empty($a['interview_email_sent_at'])) {
        return ['pill-draft', 'Invited'];
    }
    return ['pill-pending', $st !== '' && $st !== null ? ucfirst($st) : 'Unknown'];
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
  <title>Applications — Cloud Cup HR</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/hr_module.css"/>
  <style>
    .app-actions{ display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
    .app-actions__form{ display:inline-flex; margin:0; }
    .app-actions .btn{ white-space:nowrap; }
    #resumeStatusMsg .btn{ margin-top:4px; }
    .app-stage-tabs{
      display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; margin-bottom:24px;
      background:var(--white); border-radius:16px; padding:14px 16px;
      border:1px solid rgba(44,92,130,0.06); box-shadow:0 2px 12px rgba(22,51,77,0.04);
    }
    .app-stage-tabs__list{ display:flex; flex-wrap:wrap; gap:6px; }
    .app-stage-tabs__filter{ display:flex; align-items:center; gap:8px; }
    .app-stage-tabs__filter label{ font-size:12.5px; font-weight:600; color:var(--hr-text-light); white-space:nowrap; }
    .app-stage-tabs__filter select{
      font-size:13px; padding:8px 10px; border-radius:8px; border:1px solid #e9e3d8;
      background: var(--white); color:var(--text); min-width:170px;
    }
    .app-stage-tab{
      display:inline-flex; align-items:center; gap:6px;
      padding:9px 14px; border-radius:9px; font-size:13.5px; font-weight:500;
      color:var(--hr-text-light); background:var(--cream-light,#f7f7f8); border:1px solid transparent;
      text-decoration:none; transition:background .15s,color .15s,border-color .15s;
    }
    .app-stage-tab:hover{ background:#efeff1; }
    .app-stage-tab.active{ background:#241f19; border-color:#241f19; color:#fff; }
    .app-stage-tab__count{
      display:inline-flex; align-items:center; justify-content:center;
      min-width:20px; height:20px; padding:0 5px; border-radius:999px;
      background:rgba(0,0,0,.08); color:inherit; font-size:11.5px; font-weight:600;
    }
    .app-stage-tab.active .app-stage-tab__count{ background:rgba(255,255,255,.2); }

    /* Interview-format SweetAlert (Google Meet / Personal choice):
       a pair of selectable buttons; picking one immediately advances
       to the next step, so there's no separate Cancel/Next to click. */
    .meeting-mode-choices{
      display:flex; justify-content:center; gap:16px; flex-wrap:wrap;
    }
    .meeting-mode-btn{
      padding:10px 20px; min-width:170px;
      border:2px solid #d8dbe2; border-radius:10px;
      font-size:15px; font-family:inherit; color:#4b5563; background: var(--white);
      cursor:pointer; transition:border-color .15s, background .15s, color .15s;
    }
    .meeting-mode-btn:hover{ border-color:#7c6bf0; color:#7c6bf0; }

    /* Detail + date/time fields shown after picking Google Meet / Personal. */
    .meeting-detail-field{ text-align:left; margin:0 auto 14px; max-width:320px; }
    .meeting-detail-field label{
      display:block; font-size:12.5px; font-weight:600; color:#6b6156; margin-bottom:5px;
    }
    .meeting-detail-field .meeting-detail-input{ margin:0; width:100%; }
  </style>
</head>
<body>

<script src="../js/sidebar-toggle.js"></script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<?php require_once 'Sidebar_HR.php'; ?>
<script src="../js/lucide-init.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle-btn" onclick="toggleSidebar()">☰</button>
      <h1>Applications<?php if ($job_id && $applications) echo ' — ' . htmlspecialchars($applications[0]['title']); ?></h1>
    </div>
    <div class="topbar-right">
      <div class="topbar-date"><?= date('F j, Y') ?></div>
    </div>
  </div>

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <script>var __appPageMsg = { type: <?= json_encode($mt) ?>, text: <?= json_encode($mm) ?> };</script>
    <?php endif; ?>

    <div class="app-stage-tabs" id="appStageTabs">
      <div class="app-stage-tabs__list">
        <?php foreach ($stage_labels as $stage_key => $stage_label): ?>
          <a class="app-stage-tab <?= $stage === $stage_key ? 'active' : '' ?>"
             href="?stage=<?= $stage_key ?><?= $job_id_qs ?>">
            <?= htmlspecialchars($stage_label) ?>
            <span class="app-stage-tab__count"><?= count($apps_by_stage[$stage_key]) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="app-stage-tabs__filter">
        <label for="jobFilterSelect">Posting:</label>
        <select id="jobFilterSelect" onchange="window.location.href = 'Applications_Page.php?stage=<?= $stage ?>' + (this.value ? ('&job_id=' + this.value) : '')">
          <option value="">All postings</option>
          <?php foreach ($jobs as $j): ?>
            <option value="<?= (int)$j['job_id'] ?>" <?= $job_id === (int)$j['job_id'] ? 'selected' : '' ?>><?= htmlspecialchars($j['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="widget" id="appListWidget">
      <div class="widget-header">
        <div class="widget-title"><?= htmlspecialchars($stage_labels[$stage]) ?> (<?= count($applications_shown) ?>)</div>
      </div>
      <table>
        <thead>
          <tr>
            <th>Applicant</th>
            <th>Position</th>
            <th>Contact</th>
            <th>Submitted</th>
            <th>Resume</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$applications_shown): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--hr-text-light)">No applicants in this list right now.</td></tr>
          <?php endif; ?>
          <?php foreach ($applications_shown as $a): ?>
          <tr>
            <td>
              <?= htmlspecialchars($a['applicant_name']) ?>
              <?php if (!empty($a['cover_message'])): ?>
                <div style="font-size:11.5px;color:var(--hr-text-light);margin-top:2px;max-width:260px"><?= htmlspecialchars(mb_strimwidth($a['cover_message'], 0, 90, '…')) ?></div>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($a['title']) ?></td>
            <td>
              <div><?= htmlspecialchars($a['email']) ?></div>
              <div style="color:var(--hr-text-light)"><?= htmlspecialchars($a['phone']) ?></div>
            </td>
            <td><?= date('M j, Y', strtotime($a['submitted_at'])) ?></td>
            <td>
              <a class="btn btn-sm btn-ghost view-resume-link"
                 href="View_Resume.php?id=<?= (int)$a['application_id'] ?>"
                 data-app-row="<?= (int)$a['application_id'] ?>"
                 data-app-status="<?= htmlspecialchars($a['status']) ?>"
                 data-name="<?= htmlspecialchars($a['applicant_name'], ENT_QUOTES) ?>">
                View
              </a>
            </td>
            <td class="status-cell" data-app-row="<?= (int)$a['application_id'] ?>">
              <?php [$pill_class, $pill_label] = status_pill_info($a, $pill_map, $status_label_map); ?>
              <span class="status-pill <?= $pill_class ?>"><?= htmlspecialchars($pill_label) ?></span>
              <?php if (!in_array($a['status'], ['rejected', 'hired'], true)): ?>
                <?php if ($a['status'] === 'final_interview' && !empty($a['final_interview_email_sent_at'])): ?>
                  <div style="font-size:11px;color:var(--hr-text-light);margin-top:3px">Invited <?= date('M j', strtotime($a['final_interview_email_sent_at'])) ?></div>
                  <?php if (!empty($a['final_interview_datetime'])): ?>
                    <div style="font-size:11px;color:var(--hr-text-light);margin-top:1px">🕑 <?= date('M j, Y g:i A', strtotime($a['final_interview_datetime'])) ?></div>
                  <?php endif; ?>
                <?php elseif (!empty($a['interview_email_sent_at'])): ?>
                  <div style="font-size:11px;color:var(--hr-text-light);margin-top:3px">Invited <?= date('M j', strtotime($a['interview_email_sent_at'])) ?></div>
                  <?php if (!empty($a['interview_datetime'])): ?>
                    <div style="font-size:11px;color:var(--hr-text-light);margin-top:1px">🕑 <?= date('M j, Y g:i A', strtotime($a['interview_datetime'])) ?></div>
                  <?php endif; ?>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <?php
              $st = $a['status'];
              $is_final = in_array($st, ['rejected', 'hired'], true);
            ?>
            <td>
              <div class="app-actions">
                <?php if (!$is_final): ?>

                  <?php
                    // Which stages already render their own primary action below.
                    $has_primary_action = in_array($st, ['new', 'reviewed', 'shortlisted', 'interview', 'final_interview'], true);
                  ?>

                  <?php if (in_array($st, ['new', 'reviewed'], true)): ?>
                    <form method="POST" class="app-actions__form approve-initial-form" data-name="<?= htmlspecialchars($a['applicant_name'], ENT_QUOTES) ?>">
                      <input type="hidden" name="act" value="approve_application">
                      <input type="hidden" name="application_id" value="<?= (int)$a['application_id'] ?>">
                      <button type="submit" class="btn btn-sm btn-primary">Approve</button>
                    </form>
                  <?php endif; ?>

                  <?php if ($st === 'shortlisted'): ?>
                    <form method="POST" class="app-actions__form send-invite-form" data-name="<?= htmlspecialchars($a['applicant_name'], ENT_QUOTES) ?>" data-round="Initial Interview">
                      <input type="hidden" name="act" value="send_interview_email">
                      <input type="hidden" name="application_id" value="<?= (int)$a['application_id'] ?>">
                      <input type="hidden" name="meeting_mode" value="">
                      <input type="hidden" name="meeting_detail" value="">
                      <input type="hidden" name="meeting_time" value="">
                      <button type="submit" class="btn btn-sm btn-ghost">
                        Send Initial Interview Email
                      </button>
                    </form>
                  <?php endif; ?>

                  <?php if ($st === 'interview'): ?>
                    <form method="POST" class="app-actions__form approve-interview-form" data-name="<?= htmlspecialchars($a['applicant_name'], ENT_QUOTES) ?>">
                      <input type="hidden" name="act" value="approve_interview">
                      <input type="hidden" name="application_id" value="<?= (int)$a['application_id'] ?>">
                      <button type="submit" class="btn btn-sm btn-primary">Approve</button>
                    </form>
                  <?php endif; ?>

                  <?php if ($st === 'final_interview'): ?>
                    <?php if (empty($a['final_interview_email_sent_at'])): ?>
                      <form method="POST" class="app-actions__form send-invite-form" data-name="<?= htmlspecialchars($a['applicant_name'], ENT_QUOTES) ?>" data-round="Final Interview">
                        <input type="hidden" name="act" value="send_final_interview_email">
                        <input type="hidden" name="application_id" value="<?= (int)$a['application_id'] ?>">
                        <input type="hidden" name="meeting_mode" value="">
                        <input type="hidden" name="meeting_detail" value="">
                        <input type="hidden" name="meeting_time" value="">
                        <button type="submit" class="btn btn-sm btn-ghost">
                          Send Final Interview Email
                        </button>
                      </form>
                    <?php else: ?>
                      <form method="POST" class="app-actions__form hire-form" data-name="<?= htmlspecialchars($a['applicant_name'], ENT_QUOTES) ?>">
                        <input type="hidden" name="act" value="hire_application">
                        <input type="hidden" name="application_id" value="<?= (int)$a['application_id'] ?>">
                        <input type="hidden" name="monthly_salary" value="">
                        <button type="submit" class="btn btn-sm btn-primary">Hired</button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>

                  <?php if (!$has_primary_action): ?>
                    <!-- Fallback: status doesn't match a known stage (e.g. legacy/unexpected
                         value) — still give the reviewer an Approve option instead of
                         leaving "Did Not Comply" as the only button. -->
                    <form method="POST" class="app-actions__form approve-initial-form" data-name="<?= htmlspecialchars($a['applicant_name'], ENT_QUOTES) ?>">
                      <input type="hidden" name="act" value="approve_application_fallback">
                      <input type="hidden" name="application_id" value="<?= (int)$a['application_id'] ?>">
                      <button type="submit" class="btn btn-sm btn-primary">Approve</button>
                    </form>
                  <?php endif; ?>

                  <?php
                    // At the final-interview stage this is the "not hired" outcome
                    // rather than an early screen-out, so it reads as "Not Qualified"
                    // there — same action (status='rejected') either way.
                    $reject_label = $st === 'final_interview' ? 'Not Qualified' : 'Did Not Comply';
                    // No reject/not-qualified button next to "Send Initial/Final
                    // Interview Email" — only once that invite has actually gone out.
                    $show_reject = $st !== 'shortlisted'
                        && !($st === 'final_interview' && empty($a['final_interview_email_sent_at']));
                  ?>
                  <?php if ($show_reject): ?>
                  <form method="POST" class="app-actions__form reject-form" data-name="<?= htmlspecialchars($a['applicant_name'], ENT_QUOTES) ?>" data-label="<?= htmlspecialchars($reject_label) ?>">
                    <input type="hidden" name="act" value="reject_application">
                    <input type="hidden" name="application_id" value="<?= (int)$a['application_id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"><?= htmlspecialchars($reject_label) ?></button>
                  </form>
                  <?php endif; ?>

                <?php else: ?>
                  <span style="color:var(--hr-text-light);font-size:12.5px">— no further action —</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
// Ask whether this interview is Google Meet or in-person, then collect the
// link/address, before an interview invite email goes out. Resolves to
// { mode, detail } or null if the reviewer backed out at any point.
function promptInterviewMeeting(name, round) {
  return Swal.fire({
    title: round + ' format',
    html:
      '<p style="margin:0 0 18px;">How will <b>' + name + '</b>\'s ' + round.toLowerCase() + ' be conducted?</p>' +
      '<div class="meeting-mode-choices">' +
        '<button type="button" class="meeting-mode-btn" data-mode="gmeet">Google Meet (online)</button>' +
        '<button type="button" class="meeting-mode-btn" data-mode="personal">Personal (in-person)</button>' +
      '</div>',
    showConfirmButton: false,
    showCancelButton: false,
    didOpen: function () {
      Swal.getPopup().querySelectorAll('.meeting-mode-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          Swal.close({ isConfirmed: true, value: btn.dataset.mode });
        });
      });
    }
  }).then(function (result) {
    if (!result || !result.isConfirmed) return null;
    var mode = result.value;
    var isGmeet = mode === 'gmeet';
    var minDateTime = new Date(Date.now() + 5 * 60000).toISOString().slice(0, 16); // 5 min from now
    return Swal.fire({
      title: isGmeet ? 'Google Meet interview' : 'In-person interview',
      html:
        '<div class="meeting-detail-field">' +
          '<label for="meetingDetailInput">' + (isGmeet ? 'Google Meet link' : 'Interview address') + '</label>' +
          '<input id="meetingDetailInput" class="swal2-input meeting-detail-input" placeholder="' +
            (isGmeet ? 'https://meet.google.com/xxx-xxxx-xxx' : 'e.g. 3rd Flr, Cloud Cup HQ, Makati') + '">' +
        '</div>' +
        '<div class="meeting-detail-field">' +
          '<label for="meetingTimeInput">Interview date &amp; time</label>' +
          '<input id="meetingTimeInput" type="datetime-local" class="swal2-input meeting-detail-input" min="' + minDateTime + '">' +
        '</div>',
      showCancelButton: true,
      confirmButtonText: 'Send Invite',
      cancelButtonText: 'Back',
      reverseButtons: true,
      focusConfirm: false,
      preConfirm: function () {
        var detail = document.getElementById('meetingDetailInput').value.trim();
        var time = document.getElementById('meetingTimeInput').value;
        if (!detail) {
          Swal.showValidationMessage(isGmeet ? 'Paste the Google Meet link first.' : 'Enter an address first.');
          return false;
        }
        if (!time) {
          Swal.showValidationMessage('Pick an interview date & time.');
          return false;
        }
        return { detail: detail, time: time };
      }
    }).then(function (r2) {
      if (!r2.isConfirmed) return null;
      var detail = r2.value.detail;
      var time = r2.value.time;
      var whenStr = new Date(time).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
      return Swal.fire({
        title: 'Send interview invite?',
        html:
          'Email <b>' + name + '</b> the ' + round.toLowerCase() + ' invite for<br>' +
          '<b>' + whenStr + '</b><br>' +
          (isGmeet ? 'via Google Meet: ' : 'at: ') + '<b>' + detail + '</b>?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, send it',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#2f6690',
        reverseButtons: true
      }).then(function (r3) {
        if (!r3.isConfirmed) return null;
        return { mode: mode, detail: detail, time: time };
      });
    });
  });
}

// Asks for the new hire's monthly salary before submitting the "Hired"
// form — needed for both their Employee Records profile and the contract
// PDF that gets emailed to them right after. Resolves to a number, or
// null if the reviewer backed out.
function promptHireSalary(name) {
  return Swal.fire({
    title: 'Hire ' + name + '?',
    html:
      '<p style="margin:0 0 14px;">Enter their monthly salary. This will be saved to their employee profile and included in the employment contract emailed to them.</p>' +
      '<div class="meeting-detail-field">' +
        '<label for="hireSalaryInput">Monthly Salary (PHP)</label>' +
        '<input id="hireSalaryInput" type="number" min="1" step="0.01" class="swal2-input meeting-detail-input" placeholder="e.g. 18000">' +
      '</div>',
    showCancelButton: true,
    confirmButtonText: 'Confirm Hire',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#2f6690',
    reverseButtons: true,
    focusConfirm: false,
    preConfirm: function () {
      var val = parseFloat(document.getElementById('hireSalaryInput').value);
      if (!val || val <= 0) {
        Swal.showValidationMessage('Enter a valid monthly salary first.');
        return false;
      }
      return val;
    }
  }).then(function (result) {
    return result.isConfirmed ? result.value : null;
  });
}

// Wires up all the interactive bits inside the stage-tabs + applicant list
// (confirmation popups, the interview-invite flow, the optimistic
// "Reviewed" pill). Called once on page load, and again after every AJAX
// list refresh below since that swaps in fresh DOM nodes with no listeners
// attached yet.
function bindListInteractions() {
  // Send interview invites (initial or final round) only after the reviewer
  // picks Google Meet / Personal and fills in the link or address.
  document.querySelectorAll('.send-invite-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var name = form.dataset.name;
      var round = form.dataset.round || 'Interview';
      promptInterviewMeeting(name, round).then(function (choice) {
        if (!choice) return;
        form.querySelector('input[name="meeting_mode"]').value = choice.mode;
        form.querySelector('input[name="meeting_detail"]').value = choice.detail;
        form.querySelector('input[name="meeting_time"]').value = choice.time;
        form.submit();
      });
    });
  });

  // Mark an applicant as Hired — asks for monthly salary first (used for
  // their employee profile and the emailed contract), then submits.
  document.querySelectorAll('.hire-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var name = form.dataset.name;
      promptHireSalary(name).then(function (salary) {
        if (!salary) return;
        form.querySelector('input[name="monthly_salary"]').value = salary;
        form.submit();
      });
    });
  });

  // Confirm before approving an applicant into the "Approved" stage
  // (unlocks sending the initial interview invite).
  document.querySelectorAll('.approve-initial-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var name = form.dataset.name;
      Swal.fire({
        title: 'Approve applicant?',
        html: 'Approve <b>' + name + '</b> and move them forward to the initial interview stage?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, approve',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#2f6690',
        reverseButtons: true
      }).then(function (result) {
        if (result.isConfirmed) form.submit();
      });
    });
  });

  // Confirm before advancing an applicant to the final interview round.
  document.querySelectorAll('.approve-interview-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var name = form.dataset.name;
      Swal.fire({
        title: 'Approve for final interview?',
        html: 'Mark <b>' + name + '</b> as approved for the final interview round?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, proceed',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#2f6690',
        reverseButtons: true
      }).then(function (result) {
        if (result.isConfirmed) form.submit();
      });
    });
  });

  // Confirm before rejecting an applicant — nothing fires until confirmed.
  // Label follows the stage: "Not Qualified" at final interview, "Did Not Comply" earlier.
  document.querySelectorAll('.reject-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var name = form.dataset.name;
      var label = form.dataset.label || 'Did Not Comply';
      Swal.fire({
        title: label + ' applicant?',
        html: 'Are you sure you want to mark <b>' + name + '</b> as "' + label + '"? This can\'t be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, ' + label.toLowerCase(),
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#d33',
        reverseButtons: true
      }).then(function (result) {
        if (result.isConfirmed) form.submit();
      });
    });
  });

  // View_Resume.php marks the application 'reviewed' server-side in the same
  // request that streams the resume back — but since that request is a file
  // download (not a page navigation), the status pill on this page doesn't
  // pick it up until the next auto-refresh (up to 3s later). Flip the pill
  // the instant "View" is clicked so it feels instant, rather than waiting
  // on the network round trip or the refresh timer. This is optimistic UI:
  // harmless even if the click is slow to register on the server, since the
  // auto-refresh below will reconcile it either way.
  document.querySelectorAll('.view-resume-link').forEach(function (link) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      var name = link.dataset.name || 'this applicant';
      Swal.fire({
        title: 'Open resume?',
        html: 'View the resume for <b>' + name + '</b>?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, view it',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#2f6690',
        reverseButtons: true
      }).then(function (result) {
        if (!result.isConfirmed) return;

        if (link.dataset.appStatus === 'new') { // only 'new' -> 'reviewed' auto-advances
          var cell = document.querySelector('.status-cell[data-app-row="' + link.dataset.appRow + '"]');
          var pill = cell && cell.querySelector('.status-pill');
          if (pill) {
            pill.className = 'status-pill pill-onleave';
            pill.textContent = 'Reviewed';
            link.dataset.appStatus = 'reviewed';
          }
        }
        window.location.href = link.href;
      });
    });
  });
}
bindListInteractions();
if (window.lucide) lucide.createIcons();

// Success/error feedback from the last action (approve, invite sent, hired,
// rejected, etc.) — shown as a SweetAlert toast instead of the old static
// banner, so it matches the confirm/collect dialogs used everywhere else
// on this page. Auto-dismisses; doesn't block the auto-refresh below.
if (typeof __appPageMsg !== 'undefined' && typeof Swal !== 'undefined') {
  Swal.fire({
    toast: true,
    position: 'top-end',
    icon: __appPageMsg.type === 'success' ? 'success' : 'error',
    title: __appPageMsg.text,
    showConfirmButton: false,
    timer: 3500,
    timerProgressBar: true
  });
}

// The success/error banner above came from a ?msg= query param left over
// from the redirect after your last action. Strip it from the address bar
// right away (without reloading) so a manual refresh, reopening the tab,
// or the auto-refresh below doesn't keep re-showing that same old message
// forever.
if (window.location.search.indexOf('msg=') !== -1) {
  var cleanUrl = window.location.pathname +
    window.location.search.replace(/([?&])msg=[^&]*(&|$)/, function (m, p1, p2) {
      return p2 === '&' ? p1 : '';
    }).replace(/[?&]$/, '');
  window.history.replaceState({}, '', cleanUrl || window.location.pathname);
}

// Auto-refresh every 3 seconds so status/count changes made from another
// tab or by another HR user show up without a manual reload — but only the
// stage tabs + applicant list are swapped in, not the whole page, so the
// sidebar, scroll position, and everything else stay exactly as they are.
// Skipped while a SweetAlert confirmation/input modal is open so it never
// interrupts someone mid-approval or mid-typing a meeting link/address.
function refreshAppList() {
  if (typeof Swal !== 'undefined' && Swal.isVisible()) return;
  fetch(window.location.pathname + window.location.search, { credentials: 'same-origin' })
    .then(function (res) { return res.text(); })
    .then(function (html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var newTabs = doc.getElementById('appStageTabs');
      var newWidget = doc.getElementById('appListWidget');
      var curTabs = document.getElementById('appStageTabs');
      var curWidget = document.getElementById('appListWidget');
      if (newTabs && curTabs) curTabs.innerHTML = newTabs.innerHTML;
      if (newWidget && curWidget) curWidget.innerHTML = newWidget.innerHTML;
      bindListInteractions();
    })
    .catch(function () { /* stay on the current list if the fetch fails */ });
}
setInterval(refreshAppList, 3000);
</script>
<script src="../js/theme-toggle.js"></script>
</body>
</html>