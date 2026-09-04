<?php
require_once __DIR__ . '/../admin/Permissions.php';
require_hr_login();
require_permission('manage_job_postings');
require_once __DIR__ . '/../includes/DB_Connect.php';
require_once __DIR__ . '/../includes/HR_Reference_Data.php';

$active_page = 'hr_job_postings';
$uid         = current_hr_user_id();
$msg         = '';

const JOB_EMPLOYMENT_TYPES = ['full-time', 'part-time', 'contract'];

// Self-heal: 'deleted_at' lets "Delete" on a posting be a soft delete
// instead of a hard DELETE. Applicant lists (Applications_Page.php) join
// job_applications to job_postings on job_id — hard-deleting the posting
// row would silently drop every applicant tied to it from every stage
// tab, including Hired and Not Qualified, since the join would no longer
// match. Keeping the row (just hidden from the active postings list)
// preserves that applicant history regardless of whether the posting
// itself was deleted.
if ($conn) {
    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM job_postings LIKE 'deleted_at'");
    if ($col_check && mysqli_num_rows($col_check) === 0) {
        mysqli_query($conn, "ALTER TABLE job_postings ADD COLUMN deleted_at DATETIME NULL");
    }
}

// Self-heal: 'benefits' is a separate field from 'description' so it has
// its own input in the form below, instead of being baked into the
// description text (which is what was making the description box overflow
// its visible rows and cut text off on the "Post a New Opening" form).
if ($conn) {
    $col_check_b = mysqli_query($conn, "SHOW COLUMNS FROM job_postings LIKE 'benefits'");
    if ($col_check_b && mysqli_num_rows($col_check_b) === 0) {
        mysqli_query($conn, "ALTER TABLE job_postings ADD COLUMN benefits TEXT NULL");
    }
}

if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'save_job') {
        $job_id      = (int)($_POST['job_id'] ?? 0);
        $title       = in_array($_POST['title'] ?? '', HR_POSITIONS, true) ? $_POST['title'] : '';
        $department  = in_array($_POST['department'] ?? '', HR_DEPARTMENTS, true) ? $_POST['department'] : '';
        $emp_type    = in_array($_POST['employment_type'] ?? '', JOB_EMPLOYMENT_TYPES, true) ? $_POST['employment_type'] : 'full-time';
        $location    = trim($_POST['location'] ?? '') ?: 'Cloud Cup — Main Branch';
        $description = trim($_POST['description'] ?? '');
        $requirements = trim($_POST['requirements'] ?? '');
        $benefits    = trim($_POST['benefits'] ?? '');

        if ($title === '' || $department === '' || $description === '') {
            $msg = 'error:Please select a position and department, and enter a description.';
        } elseif ($job_id > 0) {
            $s = mysqli_prepare($conn,
                "UPDATE job_postings SET title=?, department=?, employment_type=?, location=?, description=?, requirements=?, benefits=? WHERE job_id=?");
            mysqli_stmt_bind_param($s, 'sssssssi', $title, $department, $emp_type, $location, $description, $requirements, $benefits, $job_id);
            mysqli_stmt_execute($s);
            $msg = 'success:Job posting updated.';
        } else {
            $s = mysqli_prepare($conn,
                "INSERT INTO job_postings (title, department, employment_type, location, description, requirements, benefits, posted_by) VALUES (?,?,?,?,?,?,?,?)");
            mysqli_stmt_bind_param($s, 'sssssssi', $title, $department, $emp_type, $location, $description, $requirements, $benefits, $uid);
            mysqli_stmt_execute($s);
            $msg = 'success:Job posting created.';
        }
    } elseif ($act === 'toggle_status') {
        $job_id = (int)($_POST['job_id'] ?? 0);
        $new_status = ($_POST['new_status'] ?? '') === 'open' ? 'open' : 'closed';
        $s = mysqli_prepare($conn, "UPDATE job_postings SET status=? WHERE job_id=?");
        mysqli_stmt_bind_param($s, 'si', $new_status, $job_id);
        mysqli_stmt_execute($s);
        $msg = 'success:Posting marked as ' . $new_status . '.';
    } elseif ($act === 'delete_job') {
        // Soft delete — see the deleted_at self-heal note above. The row
        // (and every application tied to its job_id) stays intact; it's
        // just excluded from the postings list and dropdown below.
        $job_id = (int)($_POST['job_id'] ?? 0);
        $s = mysqli_prepare($conn, "UPDATE job_postings SET deleted_at=NOW() WHERE job_id=?");
        mysqli_stmt_bind_param($s, 'i', $job_id);
        mysqli_stmt_execute($s);
        $msg = 'success:Job posting deleted.';
    }

    // Post/Redirect/Get: stash the result message in the session and redirect
    // to this same page via GET. Without this, refreshing the page after an
    // action re-sends the same POST body, which re-runs the action (e.g.
    // re-deleting/re-toggling) and re-shows the same banner every time.
    $_SESSION['job_postings_msg'] = $msg;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if (isset($_SESSION['job_postings_msg'])) {
    $msg = $_SESSION['job_postings_msg'];
    unset($_SESSION['job_postings_msg']);
}

$jobs = [];
if ($conn) {
    $res = mysqli_query($conn,
        "SELECT jp.*, (SELECT COUNT(*) FROM job_applications ja WHERE ja.job_id = jp.job_id) AS applicant_count
         FROM job_postings jp WHERE jp.deleted_at IS NULL ORDER BY (jp.status='open') DESC, jp.created_at DESC");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $jobs[] = $r;
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
  <title>Job Postings — Cloud Cup HR</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/hr_module.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    /* Bullet-list editor for Requirements / Benefits — replaces a raw
       multi-line textarea (which has no way to render bullets and clips
       awkwardly once content outgrows its visible rows) with real rows,
       each with its own bullet marker and remove button. */
    .bullet-list-editor{
      border:1px solid rgba(44,92,130,.15);border-radius:8px;padding:6px;background: var(--white);
    }
    .bullet-list-editor .bullet-row{
      display:flex;align-items:center;gap:8px;padding:5px 6px;
    }
    .bullet-list-editor .bullet-row .bullet-dot{
      flex:none;width:6px;height:6px;border-radius:50%;background:var(--caramel);
    }
    .bullet-list-editor .bullet-row input[type="text"]{
      flex:1;border:none;outline:none;font-size:13.5px;font-family:inherit;color:var(--text);
      padding:2px 0;background:transparent;
    }
    .bullet-list-editor .bullet-row .bullet-remove{
      flex:none;border:none;background:none;cursor:pointer;color:var(--text-light);
      font-size:16px;line-height:1;padding:2px 4px;border-radius:5px;
    }
    .bullet-list-editor .bullet-row .bullet-remove:hover{ background:rgba(239,68,68,.08);color:var(--danger); }
    .bullet-list-editor .bullet-add-row{
      display:flex;align-items:center;gap:8px;padding:5px 6px;border-top:1px dashed rgba(44,92,130,.15);margin-top:2px;
    }
    .bullet-list-editor .bullet-add-row input[type="text"]{
      flex:1;border:none;outline:none;font-size:13.5px;font-family:inherit;color:var(--text-light);
      padding:2px 0;background:transparent;
    }
    .bullet-list-editor .bullet-add-row .bullet-add-btn{
      flex:none;border:none;background:none;cursor:pointer;color:var(--caramel);font-weight:700;font-size:13px;
    }

    /* Job Title / Department / Employment Type dropdowns — restyles the
       native <select> (closed box AND the open option list) so it matches
       the app's rounded, soft-bordered look instead of the browser's stock
       list (sharp corners, plain white, default blue highlight). This is
       progressive enhancement via appearance:base-select — browsers that
       don't support it yet just keep today's default look, no regression. */
    .form-group-admin select{
      appearance: base-select;
      width:100%;
      padding:10px 12px;
      border:1px solid rgba(44,92,130,.15);
      border-radius:8px;
      background:var(--white);
      color:var(--text);
      font-size:14px;
      font-family:inherit;
      cursor:pointer;
      transition:border-color .15s ease, box-shadow .15s ease;
    }
    .form-group-admin select:hover{
      border-color:var(--caramel);
    }
    .form-group-admin select:focus,
    .form-group-admin select:open{
      outline:none;
      border-color:var(--caramel);
      box-shadow:0 0 0 3px rgba(184,112,63,.15);
    }
    .form-group-admin select::picker-icon{
      color:var(--text-light);
      transition:rotate .15s ease;
    }
    .form-group-admin select:open::picker-icon{
      rotate:180deg;
    }
    .form-group-admin select::picker(select){
      appearance: base-select;
      margin-top:6px;
      padding:6px;
      border:1px solid rgba(44,92,130,.15);
      border-radius:10px;
      background:var(--white);
      box-shadow:0 12px 28px rgba(20,30,40,.14);
    }
    .form-group-admin select option{
      padding:8px 10px;
      border-radius:6px;
      font-size:14px;
      color:var(--text);
    }
    .form-group-admin select option:hover,
    .form-group-admin select option:focus{
      background:rgba(184,112,63,.12);
    }
    .form-group-admin select option:checked{
      background:var(--caramel);
      color:var(--white);
    }
    .form-group-admin select option::checkmark{
      display:none;
    }
  </style>
</head>
<body>

<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<?php require_once 'Sidebar_HR.php'; ?>
<script src="../js/lucide-init.js"></script>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <h1>Job Postings</h1>
    </div>
    <div class="topbar-right">
      <div class="topbar-date"><?= date('F j, Y') ?></div>
    </div>
  </div>

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>"><?= htmlspecialchars($mm) ?></div>
    <?php endif; ?>

    <div class="widget">
      <div class="widget-header">
        <div class="widget-title">Post a New Opening</div>
      </div>
      <form method="POST" id="jobForm">
        <input type="hidden" name="act" value="save_job">
        <input type="hidden" name="job_id" id="jobIdField" value="0">
        <div class="form-row">
          <div class="form-group-admin">
            <label>Job Title (Position)</label>
            <select name="title" id="jobTitle" required>
              <option value="">— Select position —</option>
              <?php foreach (HR_POSITIONS as $pos): ?>
                <option value="<?= htmlspecialchars($pos) ?>"><?= htmlspecialchars($pos) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group-admin">
            <label>Department</label>
            <select name="department" id="jobDepartment" required>
              <option value="">— Select department —</option>
              <?php foreach (HR_DEPARTMENTS as $dept): ?>
                <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group-admin">
            <label>Employment Type</label>
            <select name="employment_type" id="jobEmpType">
              <option value="full-time">Full-time</option>
              <option value="part-time">Part-time</option>
              <option value="contract">Contract</option>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group-admin" style="flex:1">
            <label>Location</label>
            <input type="text" name="location" id="jobLocation" placeholder="Cloud Cup — Main Branch">
          </div>
        </div>
        <div class="form-group-admin">
          <label>Description</label>
          <textarea name="description" id="jobDescription" rows="4" placeholder="What the role involves..." required></textarea>
        </div>
        <div class="form-group-admin">
          <label>Requirements</label>
          <div class="bullet-list-editor" id="jobRequirementsEditor"></div>
          <textarea name="requirements" id="jobRequirements" style="display:none"></textarea>
        </div>
        <div class="form-group-admin">
          <label>Benefits</label>
          <div class="bullet-list-editor" id="jobBenefitsEditor"></div>
          <textarea name="benefits" id="jobBenefits" style="display:none"></textarea>
        </div>
        <button type="submit" class="btn btn-primary" id="jobSubmitBtn">Post Job</button>
        <button type="button" class="btn" id="jobCancelEditBtn" style="display:none">Cancel Edit</button>
      </form>
    </div>
    <br>

    <div class="widget">
      <div class="widget-header">
        <div class="widget-title">All Postings</div>
      </div>
      <table>
        <thead>
          <tr>
            <th>Title</th>
            <th>Department</th>
            <th>Type</th>
            <th>Status</th>
            <th>Applicants</th>
            <th>Posted</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$jobs): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--hr-text-light)">No job postings yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($jobs as $j): ?>
          <tr>
            <td><?= htmlspecialchars($j['title']) ?></td>
            <td><?= htmlspecialchars($j['department']) ?></td>
            <td><?= htmlspecialchars(ucfirst(str_replace('-', ' ', $j['employment_type']))) ?></td>
            <td><span class="status-pill <?= $j['status'] === 'open' ? 'pill-active' : 'pill-inactive' ?>"><?= htmlspecialchars(ucfirst($j['status'])) ?></span></td>
            <td style="text-align:center"><?= (int)$j['applicant_count'] ?></td>
            <td><?= date('M j, Y', strtotime($j['created_at'])) ?></td>
            <td style="display:flex;gap:6px;flex-wrap:wrap">
              <button type="button" class="btn btn-sm edit-job-btn"
                data-id="<?= (int)$j['job_id'] ?>"
                data-title="<?= htmlspecialchars($j['title'], ENT_QUOTES) ?>"
                data-department="<?= htmlspecialchars($j['department'], ENT_QUOTES) ?>"
                data-type="<?= htmlspecialchars($j['employment_type'], ENT_QUOTES) ?>"
                data-location="<?= htmlspecialchars($j['location'], ENT_QUOTES) ?>"
                data-description="<?= htmlspecialchars($j['description'], ENT_QUOTES) ?>"
                data-requirements="<?= htmlspecialchars($j['requirements'] ?? '', ENT_QUOTES) ?>"
                data-benefits="<?= htmlspecialchars($j['benefits'] ?? '', ENT_QUOTES) ?>">Edit</button>
              <form method="POST" style="display:inline" class="toggle-status-form"
                data-title="<?= htmlspecialchars($j['title'], ENT_QUOTES) ?>"
                data-action="<?= $j['status'] === 'open' ? 'close' : 'reopen' ?>">
                <input type="hidden" name="act" value="toggle_status">
                <input type="hidden" name="job_id" value="<?= (int)$j['job_id'] ?>">
                <input type="hidden" name="new_status" value="<?= $j['status'] === 'open' ? 'closed' : 'open' ?>">
                <button type="submit" class="btn btn-sm"><?= $j['status'] === 'open' ? 'Close' : 'Reopen' ?></button>
              </form>
              <form method="POST" style="display:inline" class="delete-job-form"
                data-title="<?= htmlspecialchars($j['title'], ENT_QUOTES) ?>">
                <input type="hidden" name="act" value="delete_job">
                <input type="hidden" name="job_id" value="<?= (int)$j['job_id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
// ── Bullet-list editor ───────────────────────────────────────────────
// Powers the Requirements and Benefits fields: real rows with a bullet
// marker and a remove button, instead of a plain multi-line textarea.
// The hidden textarea (kept for form submission — no PHP/DB change
// needed, still saved as a newline-per-item string) is resynced from
// the visible rows on every edit and right before the form submits.
function BulletListEditor(editorEl, hiddenEl, placeholder) {
  this.editorEl = editorEl;
  this.hiddenEl = hiddenEl;
  this.placeholder = placeholder;
  this.rowsWrap = document.createElement('div');
  this.editorEl.appendChild(this.rowsWrap);
  this.buildAddRow();
  this.setItems(hiddenEl.value ? hiddenEl.value.split('\n').filter(Boolean) : []);
}
BulletListEditor.prototype.buildAddRow = function () {
  const addRow = document.createElement('div');
  addRow.className = 'bullet-add-row';
  const input = document.createElement('input');
  input.type = 'text';
  input.placeholder = this.placeholder;
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'bullet-add-btn';
  btn.textContent = '+ Add';
  const self = this;
  function addFromInput() {
    const val = input.value.trim();
    if (!val) return;
    self.addRow(val);
    input.value = '';
    self.sync();
    input.focus();
  }
  btn.addEventListener('click', addFromInput);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); addFromInput(); }
  });
  addRow.appendChild(input);
  addRow.appendChild(btn);
  this.editorEl.appendChild(addRow);
};
BulletListEditor.prototype.addRow = function (text) {
  const row = document.createElement('div');
  row.className = 'bullet-row';
  const dot = document.createElement('span');
  dot.className = 'bullet-dot';
  const input = document.createElement('input');
  input.type = 'text';
  input.value = text;
  const self = this;
  input.addEventListener('input', function () { self.sync(); });
  const remove = document.createElement('button');
  remove.type = 'button';
  remove.className = 'bullet-remove';
  remove.textContent = '×';
  remove.addEventListener('click', function () {
    row.remove();
    self.sync();
  });
  row.appendChild(dot);
  row.appendChild(input);
  row.appendChild(remove);
  this.rowsWrap.appendChild(row);
};
BulletListEditor.prototype.setItems = function (items) {
  this.rowsWrap.innerHTML = '';
  items.forEach((it) => this.addRow(it));
  this.sync();
};
BulletListEditor.prototype.sync = function () {
  const values = Array.from(this.rowsWrap.querySelectorAll('input[type="text"]'))
    .map((i) => i.value.trim())
    .filter(Boolean);
  this.hiddenEl.value = values.join('\n');
};

const requirementsEditor = new BulletListEditor(
  document.getElementById('jobRequirementsEditor'),
  document.getElementById('jobRequirements'),
  'Add a requirement…'
);
const benefitsEditor = new BulletListEditor(
  document.getElementById('jobBenefitsEditor'),
  document.getElementById('jobBenefits'),
  'Add a benefit…'
);
document.getElementById('jobForm').addEventListener('submit', function (e) {
  requirementsEditor.sync();
  benefitsEditor.sync();

  // Confirm before the posting actually goes out (or an edit is saved) —
  // once submitted it's immediately live and visible to applicants.
  if (!this.dataset.confirmed) {
    e.preventDefault();
    var isNew = document.getElementById('jobIdField').value === '0';
    var form = this;
    Swal.fire({
      icon: 'question',
      title: isNew ? 'Post this job opening?' : 'Save changes to this posting?',
      text: isNew
        ? 'This will publish the listing and make it visible to applicants right away.'
        : 'This will update the live posting with your changes.',
      showCancelButton: true,
      confirmButtonText: isNew ? 'Yes, post it' : 'Yes, save changes',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#b8703f'
    }).then(function (result) {
      if (result.isConfirmed) {
        form.dataset.confirmed = '1';
        form.submit();
      }
    });
  }
});

// Qualification/description/salary templates per position, sourced from
// HR_POSITION_TEMPLATES in includes/HR_Reference_Data.php. Auto-fills the
// "Post a New Opening" form when HR picks a position, so every posting
// starts from a consistent, researched baseline (editable before posting).
const JOB_TEMPLATES = <?= json_encode(
    array_map(function ($t) {
        $benefits = array_merge(
            HR_STANDARD_BENEFITS['statutory'],
            HR_STANDARD_BENEFITS['supplemental']
        );
        return [
            'department'   => $t['department'],
            'description'  => sprintf(
                "Compensation: ₱%s – ₱%s / month\n\n%s",
                number_format($t['salary_min']),
                number_format($t['salary_max']),
                $t['description']
            ),
            'requirements' => $t['requirements'],
            'benefits'     => $benefits,
        ];
    }, HR_POSITION_TEMPLATES),
    JSON_UNESCAPED_UNICODE
) ?>;

document.getElementById('jobTitle').addEventListener('change', function () {
  // Only auto-fill for a brand-new posting — don't clobber an in-progress edit.
  if (document.getElementById('jobIdField').value !== '0') return;
  const tpl = JOB_TEMPLATES[this.value];
  if (!tpl) return;
  document.getElementById('jobDepartment').value = tpl.department;
  document.getElementById('jobDescription').value = tpl.description;
  requirementsEditor.setItems(tpl.requirements);
  benefitsEditor.setItems(tpl.benefits);
});

document.querySelectorAll('.edit-job-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.getElementById('jobIdField').value = btn.dataset.id;
    document.getElementById('jobTitle').value = btn.dataset.title;
    document.getElementById('jobDepartment').value = btn.dataset.department;
    document.getElementById('jobEmpType').value = btn.dataset.type;
    document.getElementById('jobLocation').value = btn.dataset.location;
    document.getElementById('jobDescription').value = btn.dataset.description;
    requirementsEditor.setItems(btn.dataset.requirements ? btn.dataset.requirements.split('\n').filter(Boolean) : []);
    benefitsEditor.setItems(btn.dataset.benefits ? btn.dataset.benefits.split('\n').filter(Boolean) : []);
    document.getElementById('jobSubmitBtn').textContent = 'Save Changes';
    document.getElementById('jobCancelEditBtn').style.display = 'inline-block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
});
document.getElementById('jobCancelEditBtn').addEventListener('click', function () {
  document.getElementById('jobForm').reset();
  document.getElementById('jobIdField').value = '0';
  document.getElementById('jobSubmitBtn').textContent = 'Post Job';
  this.style.display = 'none';
});

// ── Close / Reopen confirmation ──────────────────────────────────────
document.querySelectorAll('.toggle-status-form').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    if (form.dataset.confirmed) return;
    e.preventDefault();
    var isClosing = form.dataset.action === 'close';
    Swal.fire({
      icon: 'question',
      title: isClosing ? 'Close this posting?' : 'Reopen this posting?',
      text: isClosing
        ? '"' + form.dataset.title + '" will stop accepting new applicants until it\'s reopened.'
        : '"' + form.dataset.title + '" will be visible and open to applicants again.',
      showCancelButton: true,
      confirmButtonText: isClosing ? 'Yes, close it' : 'Yes, reopen it',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#b8703f'
    }).then(function (result) {
      if (result.isConfirmed) {
        form.dataset.confirmed = '1';
        form.submit();
      }
    });
  });
});

// ── Delete confirmation ───────────────────────────────────────────────
document.querySelectorAll('.delete-job-form').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    if (form.dataset.confirmed) return;
    e.preventDefault();
    Swal.fire({
      icon: 'warning',
      title: 'Delete this posting?',
      text: '"' + form.dataset.title + '" and its applicant history will be removed from the active list. This can\'t be undone from here.',
      showCancelButton: true,
      confirmButtonText: 'Yes, delete it',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#b8453a'
    }).then(function (result) {
      if (result.isConfirmed) {
        form.dataset.confirmed = '1';
        form.submit();
      }
    });
  });
});
</script>
<script src="../js/msg_banner_autodismiss.js"></script>
<script src="../js/theme-toggle.js"></script>
</body>
</html>