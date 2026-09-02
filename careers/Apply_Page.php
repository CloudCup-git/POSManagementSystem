<?php
require_once __DIR__ . '/../includes/DB_Connect.php';

$job_id = (int)($_GET['id'] ?? $_POST['job_id'] ?? 0);
$job = null;
if ($conn && $job_id > 0) {
    $s = mysqli_prepare($conn, "SELECT * FROM job_postings WHERE job_id = ? AND status = 'open' AND deleted_at IS NULL");
    mysqli_stmt_bind_param($s, 'i', $job_id);
    mysqli_stmt_execute($s);
    $job = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
}

$errors  = [];
$success = false;

const RESUME_MAX_BYTES = 5 * 1024 * 1024; // 5MB
const RESUME_ALLOWED_EXT = ['pdf', 'doc', 'docx'];
const RESUME_ALLOWED_MIME = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];

// ── Reference lists used by both the form markup and server-side validation ──
const SHIFT_OPTIONS  = ['Morning Rush', 'Lunch Peak', 'Closing'];
const DAY_OPTIONS    = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const STATUS_OPTIONS = ['student' => 'Student', 'freelancer' => 'Freelancer', 'unemployed' => 'Unemployed'];
const SKILL_OPTIONS  = ['Espresso Machine Operation', 'POS / Cash Handling', 'Customer Service', 'Latte Art'];
const GOV_DOC_OPTIONS = ['Barangay Clearance', 'Valid Government ID', "Food Handler's Medical Certificate"];

// ── Self-heal: add the extra application columns this form needs if the ──
// ── job_applications table predates this version of the form. Mirrors  ──
// ── the same on-the-fly ALTER pattern used in HR/Applications_Page.php. ──
if ($conn) {
    $needed_columns = [
        'address'            => "TEXT NULL",
        'shift_preference'   => "VARCHAR(255) NULL",
        'availability_days'  => "VARCHAR(255) NULL",
        'applicant_status'   => "VARCHAR(30) NULL",
        'school_name'        => "VARCHAR(150) NULL",
        'course'             => "VARCHAR(150) NULL",
        'barista_experience' => "VARCHAR(5) NULL",
        'skills'             => "VARCHAR(255) NULL",
        'gov_clearances'     => "VARCHAR(255) NULL",
    ];
    foreach ($needed_columns as $col => $def) {
        $chk = mysqli_query($conn, "SHOW COLUMNS FROM job_applications LIKE '" . $col . "'");
        if ($chk && mysqli_num_rows($chk) === 0) {
            mysqli_query($conn, "ALTER TABLE job_applications ADD COLUMN `$col` $def");
        }
    }
}

if ($conn && $job && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['applicant_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $message = trim($_POST['cover_message'] ?? '');

    $shifts     = array_values(array_intersect((array)($_POST['shifts'] ?? []), SHIFT_OPTIONS));
    $avail_days = array_values(array_intersect((array)($_POST['avail_days'] ?? []), DAY_OPTIONS));

    $applicant_status = $_POST['applicant_status'] ?? '';
    $school = trim($_POST['school_name'] ?? '');
    $course = trim($_POST['course'] ?? '');

    $barista_experience = $_POST['barista_experience'] ?? '';
    $skills   = array_values(array_intersect((array)($_POST['skills'] ?? []), SKILL_OPTIONS));
    $gov_docs = array_values(array_intersect((array)($_POST['gov_docs'] ?? []), GOV_DOC_OPTIONS));

    if ($name === '' || mb_strlen($name) > 150) {
        $errors[] = 'Please enter your full name.';
    }
    if ($address === '' || mb_strlen($address) > 255) {
        $errors[] = 'Please enter your complete address.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($phone === '' || !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
        $errors[] = 'Please enter a valid phone number.';
    }
    if (!$shifts) {
        $errors[] = 'Please select at least one shift you can work.';
    }
    if (!$avail_days) {
        $errors[] = 'Please select at least one day you are available to work.';
    }
    if (!array_key_exists($applicant_status, STATUS_OPTIONS)) {
        $errors[] = 'Please tell us your current status (Student, Freelancer, or Unemployed).';
    }
    if ($applicant_status === 'student' && ($school === '' || $course === '')) {
        $errors[] = 'Please provide your school name and course.';
    }
    if (!in_array($barista_experience, ['yes', 'no'], true)) {
        $errors[] = 'Please let us know if you have prior barista experience.';
    }
    if (count($gov_docs) < count(GOV_DOC_OPTIONS)) {
        $errors[] = 'Please confirm you possess or can obtain each of the listed government clearances and certificates.';
    }

    // Email must have been confirmed via the "Send Code" / "Verify" step
    // below before we accept the application. Checked here server-side too
    // so it can't be bypassed by disabling the page's JS.
    $email_verified = false;
    if ($conn && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $vchk = mysqli_prepare($conn, "SELECT verified FROM email_verifications WHERE email = ? AND verified = 1 AND verified_at >= (NOW() - INTERVAL 30 MINUTE)");
        mysqli_stmt_bind_param($vchk, 's', $email);
        mysqli_stmt_execute($vchk);
        $vrow = mysqli_fetch_assoc(mysqli_stmt_get_result($vchk));
        $email_verified = (bool)$vrow;
    }
    if (!$email_verified) {
        $errors[] = 'Please verify your email address (click "Send Code" next to the Email field) before submitting.';
    }

    $resume_rel_path = null;
    if (!isset($_FILES['resume']) || $_FILES['resume']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Please attach your resume (PDF or Word document).';
    } elseif ($_FILES['resume']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'There was a problem uploading your resume. Please try again.';
    } else {
        $file = $_FILES['resume'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : '';

        if ($file['size'] > RESUME_MAX_BYTES) {
            $errors[] = 'Resume file must be under 5MB.';
        } elseif (!in_array($ext, RESUME_ALLOWED_EXT, true) || ($mime && !in_array($mime, RESUME_ALLOWED_MIME, true))) {
            $errors[] = 'Resume must be a PDF or Word document (.pdf, .doc, .docx).';
        } else {
            $upload_dir = __DIR__ . '/../uploads/resumes/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $safe_name = 'resume_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $upload_dir . $safe_name)) {
                $resume_rel_path = 'uploads/resumes/' . $safe_name;
            } else {
                $errors[] = 'Could not save your resume. Please try again.';
            }
        }
    }

    if (!$errors) {
        $shift_str      = implode(', ', $shifts);
        $avail_days_str = implode(', ', $avail_days);
        $skills_str     = implode(', ', $skills);
        $gov_docs_str   = implode(', ', $gov_docs);
        $school_val     = $school !== '' ? $school : null;
        $course_val     = $course !== '' ? $course : null;

        $stmt = mysqli_prepare($conn,
            "INSERT INTO job_applications
                (job_id, applicant_name, email, phone, address, cover_message, resume_path,
                 shift_preference, availability_days, applicant_status, school_name, course,
                 barista_experience, skills, gov_clearances, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'new')");
        $types = 'i' . str_repeat('s', 14);
        mysqli_stmt_bind_param(
            $stmt,
            $types,
            $job_id, $name, $email, $phone, $address, $message, $resume_rel_path,
            $shift_str, $avail_days_str, $applicant_status, $school_val, $course_val,
            $barista_experience, $skills_str, $gov_docs_str
        );
        if (mysqli_stmt_execute($stmt)) {
            $success = true;
        } else {
            // The real cause was previously discarded (mysqli_report is set to
            // MYSQLI_REPORT_OFF in DB_Connect.php), so applicants only ever saw
            // a generic message with no way for us to diagnose it. Log the
            // actual DB error instead of showing it publicly.
            error_log('Job application insert failed: ' . mysqli_stmt_error($stmt));
            $errors[] = 'Something went wrong submitting your application. Please try again.';
        }
    }
}

// Re-populate multi-value/radio fields after a failed submit so the
// applicant doesn't have to redo their selections.
$posted_shifts   = (array)($_POST['shifts'] ?? []);
$posted_days     = (array)($_POST['avail_days'] ?? []);
$posted_status   = $_POST['applicant_status'] ?? '';
$posted_skills   = (array)($_POST['skills'] ?? []);
$posted_gov_docs = (array)($_POST['gov_docs'] ?? []);
$posted_barista  = $_POST['barista_experience'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script>(function(){try{var t=localStorage.getItem('cloudcup-theme')||'light';document.documentElement.classList.toggle('dark-mode',t==='dark');document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="stylesheet" href="../css/theme.css"/>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Apply — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,500&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="css/careers.css"/>
</head>
<body>

<nav>
  <div class="nav-logo"><a href="../auth/Landing_Page.php" class="logo-link"><span class="cloud">☁️</span> CLOUD CUP</a></div>
  <a href="../auth/Landing_Page.php#careers" class="nav-cta">← Back to Job Offers</a>
</nav>

<?php if (!$job): ?>
  <section class="careers-hero">
    <h1>Position not found</h1>
    <p>This posting may have closed. <a href="../auth/Landing_Page.php#careers">See all current openings</a>.</p>
  </section>

<?php elseif ($success): ?>
  <section class="apply-confirm">
    <div class="apply-confirm-icon">✅</div>
    <h1>Application Submitted</h1>
    <p>Thanks for applying to <strong><?= htmlspecialchars($job['title']) ?></strong> at Cloud Cup. Our HR team will review your application and reach out if you're a good fit.</p>
    <a href="../auth/Landing_Page.php#careers" class="nav-cta">View Other Openings</a>
  </section>

<?php else: ?>
  <section class="apply-form-section">
    <div class="apply-card">
      <div class="apply-card-head">
        <h1>Apply for <?= htmlspecialchars($job['title']) ?></h1>
        <p class="apply-sub"><?= htmlspecialchars($job['department']) ?> • <?= htmlspecialchars(ucfirst(str_replace('-', ' ', $job['employment_type']))) ?> • <?= htmlspecialchars($job['location']) ?></p>
      </div>

      <?php if ($errors): ?>
        <div class="form-errors">
          <strong>We couldn't submit your application:</strong>
          <ul>
            <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data" action="Apply_Page.php?id=<?= (int)$job_id ?>" id="applyForm" novalidate>
        <input type="hidden" name="job_id" value="<?= (int)$job_id ?>">

        <!-- 1. Basic & Contact Information -->
        <fieldset class="form-fieldset">
          <legend><span class="step-num">1</span> Basic &amp; Contact Information</legend>

          <div class="form-group">
            <label>Full Name <span class="required" aria-hidden="true">*</span></label>
            <input type="text" name="applicant_name" value="<?= htmlspecialchars($_POST['applicant_name'] ?? '') ?>" data-required="true" maxlength="150">
            <div class="field-error" data-error-for="applicant_name">Please enter your full name.</div>
          </div>

          <div class="form-group">
            <label>Complete Address <span class="required" aria-hidden="true">*</span></label>
            <input type="text" name="address" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" data-required="true" maxlength="255" placeholder="House/Unit No., Street, Barangay, City">
            <div class="field-error" data-error-for="address">Please enter your complete address.</div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label>Email Address <span class="required" aria-hidden="true">*</span></label>
              <div class="email-verify-row">
                <input type="email" name="email" id="emailInput" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" data-required="true">
                <button type="button" id="sendCodeBtn" class="btn-verify">Send Code</button>
              </div>
              
              <div class="field-error" data-error-for="email">Please enter a valid email address.</div>

              <div id="codeVerifyBlock" class="code-verify-block">
                <label>Verification Code</label>
                <div class="code-verify-row">
                  <input type="text" id="codeInput" inputmode="numeric" maxlength="6" placeholder="6-digit code">
                  <button type="button" id="verifyCodeBtn" class="btn-verify btn-verify-solid">Verify</button>
                </div>
                <p id="codeStatusMsg" class="code-status-msg"></p>
              </div>
              <input type="hidden" name="email_verified" id="emailVerifiedFlag" value="0">
            </div>

            <div class="form-group">
              <label>Mobile Number <span class="required" aria-hidden="true">*</span></label>
              <input type="text" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" data-required="true" placeholder="e.g. 0917 123 4567">
              <div class="field-error" data-error-for="phone">Please enter a valid phone number.</div>
            </div>
          </div>
        </fieldset>

        <!-- 2. Schedule Availability -->
        <fieldset class="form-fieldset">
          <legend><span class="step-num">2</span> Schedule Availability</legend>

          <div class="form-group">
            <label>Shifts You Can Work <span class="required" aria-hidden="true">*</span></label>
            <div class="chip-grid" data-chip-group="shifts">
              <?php foreach (SHIFT_OPTIONS as $shift): ?>
                <label class="chip">
                  <input type="checkbox" class="chip-input" name="shifts[]" value="<?= htmlspecialchars($shift) ?>" <?= in_array($shift, $posted_shifts, true) ? 'checked' : '' ?>>
                  <span class="chip-face"><?= htmlspecialchars($shift) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <div class="field-error" data-error-for="shifts">Please select at least one shift you can work.</div>
          </div>

          <div class="form-group">
            <label>Weekly Availability <span class="required" aria-hidden="true">*</span></label>
            <div class="day-grid" data-chip-group="avail_days">
              <?php foreach (DAY_OPTIONS as $day): ?>
                <label class="chip day-chip">
                  <input type="checkbox" class="chip-input" name="avail_days[]" value="<?= htmlspecialchars($day) ?>" <?= in_array($day, $posted_days, true) ? 'checked' : '' ?>>
                  <span class="chip-face"><?= htmlspecialchars(substr($day, 0, 3)) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <div class="field-error" data-error-for="avail_days">Please select at least one day you're available.</div>
          </div>
        </fieldset>

        <!-- 3. Applicant Status & Education -->
        <fieldset class="form-fieldset">
          <legend><span class="step-num">3</span> Applicant Status &amp; Education</legend>

          <div class="form-group">
            <label>Current Status <span class="required" aria-hidden="true">*</span></label>
            <div class="radio-card-row" data-radio-group="applicant_status">
              <?php foreach (STATUS_OPTIONS as $val => $lbl): ?>
                <label class="radio-card">
                  <input type="radio" name="applicant_status" value="<?= htmlspecialchars($val) ?>" <?= $posted_status === $val ? 'checked' : '' ?>>
                  <span class="radio-card-face"><?= htmlspecialchars($lbl) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <div class="field-error" data-error-for="applicant_status">Please select your current status.</div>
          </div>

          <div class="form-row-2 conditional-block" id="studentFields" style="<?= $posted_status === 'student' ? '' : 'display:none' ?>">
            <div class="form-group">
              <label>School Name</label>
              <input type="text" name="school_name" value="<?= htmlspecialchars($_POST['school_name'] ?? '') ?>" maxlength="150" placeholder="e.g. General Trias National High School">
              <div class="field-error" data-error-for="school_name">Please enter your school name.</div>
            </div>
            <div class="form-group">
              <label>Course / Strand</label>
              <input type="text" name="course" value="<?= htmlspecialchars($_POST['course'] ?? '') ?>" maxlength="150" placeholder="e.g. BS Hospitality Management">
              <div class="field-error" data-error-for="course">Please enter your course.</div>
            </div>
          </div>
          <p class="field-hint conditional-block" id="studentHint" style="<?= $posted_status === 'student' ? '' : 'display:none' ?>">Letting us know your school schedule helps us plan part-time shifts that don't overlap with your classes.</p>
        </fieldset>

        <!-- 4. Cafe Experience & Core Skills -->
        <fieldset class="form-fieldset">
          <legend><span class="step-num">4</span> Cafe Experience &amp; Core Skills</legend>

          <div class="form-group">
            <label>Do you have prior barista experience? <span class="required" aria-hidden="true">*</span></label>
            <div class="radio-card-row" data-radio-group="barista_experience">
              <label class="radio-card">
                <input type="radio" name="barista_experience" value="yes" <?= $posted_barista === 'yes' ? 'checked' : '' ?>>
                <span class="radio-card-face">Yes</span>
              </label>
              <label class="radio-card">
                <input type="radio" name="barista_experience" value="no" <?= $posted_barista === 'no' ? 'checked' : '' ?>>
                <span class="radio-card-face">No</span>
              </label>
            </div>
            <div class="field-error" data-error-for="barista_experience">Please let us know if you have prior barista experience.</div>
          </div>

          <div class="form-group">
            <label>Skills You Already Have <span class="optional">(optional)</span></label>
            <div class="chip-grid">
              <?php foreach (SKILL_OPTIONS as $skill): ?>
                <label class="chip">
                  <input type="checkbox" class="chip-input" name="skills[]" value="<?= htmlspecialchars($skill) ?>" <?= in_array($skill, $posted_skills, true) ? 'checked' : '' ?>>
                  <span class="chip-face"><?= htmlspecialchars($skill) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="form-group">
            <label>Cover Message <span class="optional">(optional)</span></label>
            <textarea name="cover_message" rows="4" placeholder="Tell us why you'd be a great fit..."><?= htmlspecialchars($_POST['cover_message'] ?? '') ?></textarea>
          </div>
        </fieldset>

        <!-- 5. Legal & Pre-Employment Documents -->
        <fieldset class="form-fieldset">
          <legend><span class="step-num">5</span> Legal &amp; Pre-Employment Documents</legend>

          <div class="form-group">
            <label>Resume <span class="required" aria-hidden="true">*</span> <span class="optional">(PDF or Word, max 5MB)</span></label>
            <label class="file-upload" for="resumeInput">
              <span class="file-upload-icon">📄</span>
              <span class="file-upload-text">
                <span class="file-upload-title">Click to upload your resume</span>
                <span class="file-upload-name" data-file-name>No file selected</span>
              </span>
            </label>
            <input type="file" name="resume" id="resumeInput" accept=".pdf,.doc,.docx" data-required-file="true" hidden>
            <div class="field-error" data-error-for="resume">Please attach your resume (PDF or Word document, max 5MB).</div>
          </div>

          <div class="form-group">
            <label>Government Clearances &amp; Fitness to Work <span class="required" aria-hidden="true">*</span></label>
            <p class="field-hint">Please confirm you already have, or can obtain before your start date, each of the following:</p>
            <div class="checklist">
              <?php foreach (GOV_DOC_OPTIONS as $doc): ?>
                <label class="checklist-item">
                  <input type="checkbox" name="gov_docs[]" value="<?= htmlspecialchars($doc) ?>" <?= in_array($doc, $posted_gov_docs, true) ? 'checked' : '' ?> data-required="true">
                  <span>I possess or can obtain a <strong><?= htmlspecialchars($doc) ?></strong>.</span>
                </label>
              <?php endforeach; ?>
            </div>
            <div class="field-error" data-error-for="gov_docs">Please confirm each government clearance and certificate above.</div>
          </div>
        </fieldset>

        <button type="submit" class="nav-cta apply-submit-btn">Submit Application</button>
      </form>
    </div>
  </section>
<?php endif; ?>

<script>
(function () {
  var form = document.getElementById('applyForm');
  if (!form) return;

  var resumeInput = document.getElementById('resumeInput');
  var fileNameEl  = form.querySelector('[data-file-name]');
  var MAX_BYTES   = <?= RESUME_MAX_BYTES ?>;
  var ALLOWED_EXT = <?= json_encode(RESUME_ALLOWED_EXT) ?>;

  // ── Email verification (Send Code / Verify) ──────────────────────────
  var emailInput     = document.getElementById('emailInput');
  var sendCodeBtn    = document.getElementById('sendCodeBtn');
  var codeBlock      = document.getElementById('codeVerifyBlock');
  var codeInput      = document.getElementById('codeInput');
  var verifyCodeBtn  = document.getElementById('verifyCodeBtn');
  var codeStatusMsg  = document.getElementById('codeStatusMsg');
  var verifiedFlag   = document.getElementById('emailVerifiedFlag');
  var sendCooldown   = 0;
  var sendCooldownTimer = null;

  function setCodeStatus(text, isError) {
    codeStatusMsg.textContent = text;
    codeStatusMsg.style.color = isError ? '#8a3428' : '#15803d';
  }

  function markEmailUnverified() {
    verifiedFlag.value = '0';
    sendCodeBtn.textContent = sendCooldown > 0 ? ('Resend in ' + sendCooldown + 's') : 'Send Code';
  }

  // If the applicant edits the email after verifying, they need to re-verify.
  emailInput.addEventListener('input', function () {
    if (verifiedFlag.value === '1') {
      verifiedFlag.value = '0';
      setCodeStatus('Email changed — please verify again.', true);
    }
  });

  sendCodeBtn.addEventListener('click', function () {
    var email = emailInput.value.trim();
    var emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    if (!emailOk) {
      showError('email');
      emailInput.focus();
      return;
    }
    clearError('email');
    sendCodeBtn.disabled = true;
    sendCodeBtn.textContent = 'Sending…';

    fetch('Send_Verification_Code.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'email=' + encodeURIComponent(email)
    }).then(function (r) { return r.json(); }).then(function (data) {
      sendCodeBtn.disabled = false;
      if (data.ok) {
        codeBlock.style.display = 'block';
        setCodeStatus('Code sent — check your inbox (it expires in 10 minutes).', false);
        codeInput.focus();
        sendCooldown = 30;
        clearInterval(sendCooldownTimer);
        sendCooldownTimer = setInterval(function () {
          sendCooldown--;
          if (sendCooldown <= 0) {
            clearInterval(sendCooldownTimer);
            sendCodeBtn.textContent = 'Resend Code';
          } else {
            sendCodeBtn.textContent = 'Resend in ' + sendCooldown + 's';
          }
        }, 1000);
      } else {
        sendCodeBtn.textContent = 'Send Code';
        setCodeStatus(data.error || 'Could not send the code. Please try again.', true);
      }
    }).catch(function () {
      sendCodeBtn.disabled = false;
      sendCodeBtn.textContent = 'Send Code';
      setCodeStatus('Could not reach the server. Please try again.', true);
    });
  });

  verifyCodeBtn.addEventListener('click', function () {
    var email = emailInput.value.trim();
    var code  = codeInput.value.trim();
    if (!code) {
      setCodeStatus('Enter the code we sent you.', true);
      codeInput.focus();
      return;
    }
    verifyCodeBtn.disabled = true;
    verifyCodeBtn.textContent = 'Verifying…';

    fetch('Verify_Code.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'email=' + encodeURIComponent(email) + '&code=' + encodeURIComponent(code)
    }).then(function (r) { return r.json(); }).then(function (data) {
      verifyCodeBtn.disabled = false;
      verifyCodeBtn.textContent = 'Verify';
      if (data.ok) {
        verifiedFlag.value = '1';
        setCodeStatus('✓ Email verified.', false);
      } else {
        verifiedFlag.value = '0';
        setCodeStatus(data.error || 'That code is incorrect.', true);
      }
    }).catch(function () {
      verifyCodeBtn.disabled = false;
      verifyCodeBtn.textContent = 'Verify';
      setCodeStatus('Could not reach the server. Please try again.', true);
    });
  });

  resumeInput.addEventListener('change', function () {
    if (resumeInput.files && resumeInput.files.length) {
      fileNameEl.textContent = resumeInput.files[0].name;
      fileNameEl.classList.add('has-file');
    } else {
      fileNameEl.textContent = 'No file selected';
      fileNameEl.classList.remove('has-file');
    }
    clearError('resume');
  });

  // ── Student status reveals School / Course fields ─────────────────────
  var studentFields = document.getElementById('studentFields');
  var studentHint   = document.getElementById('studentHint');
  form.querySelectorAll('input[name="applicant_status"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      if (radio.checked && radio.value === 'student') {
        studentFields.style.display = '';
        studentHint.style.display = '';
      } else if (radio.checked) {
        studentFields.style.display = 'none';
        studentHint.style.display = 'none';
      }
      clearError('applicant_status');
    });
  });

  // ── Chip-style checkbox groups (shifts / days) clear their group error ──
  form.querySelectorAll('.chip-input').forEach(function (cb) {
    cb.addEventListener('change', function () {
      var group = cb.closest('[data-chip-group]');
      if (group) clearError(group.getAttribute('data-chip-group'));
    });
  });

  // ── Government clearance checklist clears its error once all are checked ──
  form.querySelectorAll('input[name="gov_docs[]"]').forEach(function (cb) {
    cb.addEventListener('change', function () {
      var boxes = form.querySelectorAll('input[name="gov_docs[]"]');
      var all = true;
      boxes.forEach(function (b) { if (!b.checked) all = false; });
      if (all) clearError('gov_docs');
    });
  });

  form.querySelectorAll('input[name="barista_experience"]').forEach(function (radio) {
    radio.addEventListener('change', function () { clearError('barista_experience'); });
  });

  function showError(name) {
    var err = form.querySelector('[data-error-for="' + name + '"]');
    if (err) err.classList.add('show');
    var field = form.querySelector('[name="' + name + '"]');
    if (field) field.classList.add('input-error');
    if (name === 'resume') {
      var wrap = form.querySelector('.file-upload');
      if (wrap) wrap.classList.add('input-error');
    }
  }

  function clearError(name) {
    var err = form.querySelector('[data-error-for="' + name + '"]');
    if (err) err.classList.remove('show');
    var field = form.querySelector('[name="' + name + '"]');
    if (field) field.classList.remove('input-error');
    if (name === 'resume') {
      var wrap = form.querySelector('.file-upload');
      if (wrap) wrap.classList.remove('input-error');
    }
  }

  form.querySelectorAll('[data-required]').forEach(function (field) {
    var evt = (field.type === 'checkbox' || field.type === 'radio') ? 'change' : 'input';
    field.addEventListener(evt, function () {
      if (field.type === 'checkbox' ? field.checked : field.value.trim() !== '') {
        clearError(field.name);
      }
    });
  });

  form.addEventListener('submit', function (e) {
    var firstInvalid = null;
    var name = form.querySelector('[name="applicant_name"]');
    var address = form.querySelector('[name="address"]');
    var email = form.querySelector('[name="email"]');
    var phone = form.querySelector('[name="phone"]');

    ['applicant_name', 'address', 'email', 'phone', 'resume', 'shifts', 'avail_days', 'applicant_status', 'barista_experience', 'gov_docs'].forEach(clearError);

    if (name.value.trim() === '') { showError('applicant_name'); firstInvalid = firstInvalid || name; }
    if (address.value.trim() === '') { showError('address'); firstInvalid = firstInvalid || address; }

    var emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim());
    if (!emailOk) { showError('email'); firstInvalid = firstInvalid || email; }

    var phoneOk = /^[0-9+\-\s()]{7,20}$/.test(phone.value.trim());
    if (!phoneOk) { showError('phone'); firstInvalid = firstInvalid || phone; }

    var file = resumeInput.files && resumeInput.files[0];
    var resumeOk = !!file;
    if (resumeOk) {
      var ext = file.name.split('.').pop().toLowerCase();
      if (ALLOWED_EXT.indexOf(ext) === -1 || file.size > MAX_BYTES) resumeOk = false;
    }
    if (!resumeOk) { showError('resume'); firstInvalid = firstInvalid || resumeInput; }

    var shiftsOk = form.querySelectorAll('input[name="shifts[]"]:checked').length > 0;
    if (!shiftsOk) { showError('shifts'); firstInvalid = firstInvalid || form.querySelector('input[name="shifts[]"]'); }

    var daysOk = form.querySelectorAll('input[name="avail_days[]"]:checked').length > 0;
    if (!daysOk) { showError('avail_days'); firstInvalid = firstInvalid || form.querySelector('input[name="avail_days[]"]'); }

    var statusRadio = form.querySelector('input[name="applicant_status"]:checked');
    var statusOk = !!statusRadio;
    if (!statusOk) { showError('applicant_status'); firstInvalid = firstInvalid || form.querySelector('input[name="applicant_status"]'); }

    var baristaRadio = form.querySelector('input[name="barista_experience"]:checked');
    var baristaOk = !!baristaRadio;
    if (!baristaOk) { showError('barista_experience'); firstInvalid = firstInvalid || form.querySelector('input[name="barista_experience"]'); }

    var govBoxes = form.querySelectorAll('input[name="gov_docs[]"]');
    var govOk = true;
    govBoxes.forEach(function (b) { if (!b.checked) govOk = false; });
    if (!govOk) { showError('gov_docs'); firstInvalid = firstInvalid || govBoxes[0]; }

    var emailVerified = verifiedFlag.value === '1';
    if (emailOk && !emailVerified) {
      setCodeStatus('Please verify your email address before submitting.', true);
      codeBlock.style.display = 'block';
      firstInvalid = firstInvalid || emailInput;
    }

    if (!name.value.trim() || !address.value.trim() || !emailOk || !phoneOk || !resumeOk ||
        !shiftsOk || !daysOk || !statusOk || !baristaOk || !govOk || !emailVerified) {
      e.preventDefault();
      if (firstInvalid) firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });
})();
</script>



<script src="../js/theme-toggle.js"></script>
</body>
</html>
