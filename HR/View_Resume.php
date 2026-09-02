<?php
// ── DOWNLOAD RESUME ───────────────────────────────────────────────────────
// Streams an applicant's resume from uploads/resumes back to the browser
// with Content-Disposition: attachment so it downloads immediately instead
// of opening inline. Loading this file is also treated as "the reviewer
// opened the resume" — the very first time it's opened for a given
// application we auto-advance status 'new' -> 'reviewed' (we never
// downgrade a status that's already past 'new', e.g.
// interview/shortlisted/hired/rejected).
require_once __DIR__ . '/../admin/Permissions.php';
require_hr_login();
require_permission('manage_job_postings');
require_once __DIR__ . '/../includes/DB_Connect.php';

$uid   = current_hr_user_id();
$app_id = (int)($_GET['id'] ?? 0);

if (!$conn || $app_id <= 0) {
    http_response_code(404);
    exit('Not found.');
}

$stmt = mysqli_prepare($conn, "SELECT applicant_name, resume_path, status FROM job_applications WHERE application_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $app_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = $res ? mysqli_fetch_assoc($res) : null;

if (!$row || empty($row['resume_path'])) {
    http_response_code(404);
    exit('Resume not found.');
}

// Resolve to an absolute path inside uploads/resumes and make sure it
// didn't escape that folder (defense in depth against a bad resume_path).
$resumes_dir = realpath(__DIR__ . '/../uploads/resumes');
$abs_path    = realpath(__DIR__ . '/../' . $row['resume_path']);

if (!$abs_path || !$resumes_dir || strpos($abs_path, $resumes_dir) !== 0 || !is_file($abs_path)) {
    http_response_code(404);
    exit('Resume file is missing.');
}

// Auto-mark as reviewed the first time someone opens it — don't touch
// statuses that are already further along in the pipeline.
if ($row['status'] === 'new') {
    $upd = mysqli_prepare($conn,
        "UPDATE job_applications SET status='reviewed', reviewed_by=?, reviewed_at=NOW() WHERE application_id=? AND status='new'");
    mysqli_stmt_bind_param($upd, 'ii', $uid, $app_id);
    mysqli_stmt_execute($upd);
}

$ext = strtolower(pathinfo($abs_path, PATHINFO_EXTENSION));
$mime_map = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
$mime = $mime_map[$ext] ?? 'application/octet-stream';

// Build a friendly download filename from the applicant's name instead of
// the stored hashed filename, e.g. "Jane Dela Cruz - Resume.pdf".
$safe_name = preg_replace('/[^A-Za-z0-9 _\-]/', '', $row['applicant_name']);
$safe_name = trim($safe_name) !== '' ? trim($safe_name) : 'Resume';
$download_name = $safe_name . ' - Resume.' . $ext;

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $download_name . '"');
header('Content-Length: ' . filesize($abs_path));
header('X-Content-Type-Options: nosniff');
readfile($abs_path);
exit;
