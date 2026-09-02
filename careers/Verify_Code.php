<?php
// ── VERIFY EMAIL CODE ──────────────────────────────────────────────────────
// Called by Apply_Page.php (AJAX) when the applicant clicks "Verify" after
// entering the code that was emailed to them. On success, marks that email
// as verified — Apply_Page.php's server-side submit handler checks this
// same table before accepting the application, so this can't be bypassed
// just by disabling the front-end JS.
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/DB_Connect.php';

function json_out(array $data): void {
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    json_out(['ok' => false, 'error' => 'Method not allowed.']);
}

if (!$conn) {
    json_out(['ok' => false, 'error' => 'Server is temporarily unavailable. Please try again shortly.']);
}

$email = trim($_POST['email'] ?? '');
$code  = trim($_POST['code'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $code === '') {
    json_out(['ok' => false, 'error' => 'Please enter the code sent to your email.']);
}

$s = mysqli_prepare($conn, "SELECT code, expires_at FROM email_verifications WHERE email = ?");
mysqli_stmt_bind_param($s, 's', $email);
mysqli_stmt_execute($s);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));

if (!$row) {
    json_out(['ok' => false, 'error' => 'No code was sent to this email. Click "Send Code" first.']);
}
if (strtotime($row['expires_at']) < time()) {
    json_out(['ok' => false, 'error' => 'This code has expired. Please request a new one.']);
}
if (!hash_equals($row['code'], $code)) {
    json_out(['ok' => false, 'error' => 'That code is incorrect. Please check and try again.']);
}

$u = mysqli_prepare($conn, "UPDATE email_verifications SET verified = 1, verified_at = NOW() WHERE email = ?");
mysqli_stmt_bind_param($u, 's', $email);
mysqli_stmt_execute($u);

json_out(['ok' => true]);
