<?php
// ── SEND EMAIL VERIFICATION CODE ──────────────────────────────────────────
// Called by Apply_Page.php (AJAX) when the applicant clicks "Send Code"
// next to the Email field. Generates a 6-digit code, stores it in
// email_verifications (keyed by email, one active code per email), and
// emails it out via the same mail wrapper used for interview invites.
//
// Applicants must enter this code correctly (see Verify_Code.php) before
// Apply_Page.php will accept their application submission.
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/DB_Connect.php';
require_once __DIR__ . '/../includes/hr_mailer.php';

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
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(['ok' => false, 'error' => 'Please enter a valid email address first.']);
}

// Table is created on the fly the same way other on-the-fly columns/tables
// are handled elsewhere in this app, so no manual migration is needed.
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS email_verifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        code VARCHAR(6) NOT NULL,
        verified TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        verified_at DATETIME NULL,
        UNIQUE KEY email_unique (email)
    )
");

// Basic cooldown so the same email can't be spammed with codes.
$chk = mysqli_prepare($conn, "SELECT created_at FROM email_verifications WHERE email = ?");
mysqli_stmt_bind_param($chk, 's', $email);
mysqli_stmt_execute($chk);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
if ($row && strtotime($row['created_at']) > time() - 30) {
    json_out(['ok' => false, 'error' => 'Please wait a few seconds before requesting another code.']);
}

$code       = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$created_at = date('Y-m-d H:i:s');
$expires_at = date('Y-m-d H:i:s', time() + 600); // 10 minutes

$s = mysqli_prepare($conn, "
    INSERT INTO email_verifications (email, code, verified, created_at, expires_at, verified_at)
    VALUES (?, ?, 0, ?, ?, NULL)
    ON DUPLICATE KEY UPDATE code = VALUES(code), verified = 0, created_at = VALUES(created_at),
                            expires_at = VALUES(expires_at), verified_at = NULL
");
mysqli_stmt_bind_param($s, 'ssss', $email, $code, $created_at, $expires_at);
mysqli_stmt_execute($s);

$sent = send_verification_code_email($email, $code);
if (!$sent) {
    json_out(['ok' => false, 'error' => "Could not send the code. Check the address is correct, or the server's mail/SMTP setup."]);
}

json_out(['ok' => true]);
