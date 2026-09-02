<?php
// ── HR APPLICANT EMAIL HELPER ─────────────────────────────────────────────
// Lives in the shared /includes folder (alongside DB_Connect.php) since,
// like that file, it's app-wide plumbing rather than an HR-only page —
// but today it's used by both the HR module (interview/hired/not-qualified
// emails) and the public careers form (verification codes).
//
// Sends real email via SMTP using PHPMailer (bundled in
// includes/PHPMailer/, no Composer required). Fill in the SMTP_* constants
// below with your real mail account details — see the setup guide that was
// provided alongside this file for step-by-step instructions.

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
require_once __DIR__ . '/SimplePdf.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// ── SMTP CONFIGURATION — fill these in with your real mail account ────────
const SMTP_HOST       = 'smtp.gmail.com';        // e.g. smtp.gmail.com, smtp-mail.outlook.com, smtp.yourhost.com
const SMTP_PORT       = 587;                     // 587 for STARTTLS, 465 for SMTPS
const SMTP_ENCRYPTION = PHPMailer::ENCRYPTION_STARTTLS; // use PHPMailer::ENCRYPTION_SMTPS for port 465
const SMTP_USERNAME   = 'cloudcupdasma@gmail.com';    // ← the mailbox you're sending from
const SMTP_PASSWORD   = 'dslm egpg aoaz sstr'; // ← an App Password, NOT your normal login password

const HR_MAIL_FROM_NAME  = 'Cloud Cup Careers';
const HR_MAIL_FROM_EMAIL = SMTP_USERNAME; // most SMTP providers require the From address to match the login

// ── COMPANY INFO — used on the printed employment contract, fill in yours ─
const COMPANY_LEGAL_NAME = 'Cloud Cup';
const COMPANY_ADDRESS    = '123 Aguinaldo Highway, Dasmariñas, Cavite 4114';
const COMPANY_SIGNATORY_NAME  = 'Mrs. Kenneth Jasmine R. Basilio';
const COMPANY_SIGNATORY_TITLE = 'HR Manager';

// ── BRAND COLORS — matches the HR module's own palette (admin_page.css) ───
const HR_BRAND_NAVY        = '#161009'; // --brown-dark
const HR_BRAND_BLUE        = '#b8703f'; // --caramel (the app's actual accent blue)
const HR_BRAND_BLUE_SOFT   = '#c98a5c'; // --gold
const HR_BRAND_CREAM       = '#faf8f4'; // --cream-light
const HR_BRAND_TEXT_MID    = '#335270'; // --text-mid
const HR_BRAND_TEXT_LIGHT  = '#2f6690'; // --text-light
const HR_BRAND_SUCCESS     = '#2f6f4e';
const HR_BRAND_DANGER_SOFT = '#B45050'; // muted, not alarm-red — this is a "not moving forward" email, not an error

/**
 * Sends an HTML email (with a plain-text fallback) to an applicant via SMTP.
 * Returns true if the mail server accepted it, false otherwise.
 *
 * @param array $attachments Optional list of ['data' => raw bytes, 'name' => filename, 'type' => mime type]
 *                            to attach (e.g. a generated contract PDF).
 */
function send_hr_email(string $to_email, string $to_name, string $subject, string $html_body, string $plain_body = '', array $attachments = []): bool {
    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) return false;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(HR_MAIL_FROM_EMAIL, HR_MAIL_FROM_NAME);
        $mail->addAddress($to_email, $to_name);
        $mail->addReplyTo(HR_MAIL_FROM_EMAIL, HR_MAIL_FROM_NAME);

        foreach ($attachments as $att) {
            if (!empty($att['data']) && !empty($att['name'])) {
                $mail->addStringAttachment($att['data'], $att['name'], 'base64', $att['type'] ?? 'application/octet-stream');
            }
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_body;
        $mail->AltBody = $plain_body !== '' ? $plain_body : trim(html_entity_decode(strip_tags(
            preg_replace('/<br\s*\/?>|<\/p>|<\/div>|<\/tr>/i', "\n", $html_body)
        )));

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        // Log the real reason somewhere you can see it while testing.
        error_log('send_hr_email failed: ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Wraps a block of email content in the shared Cloud Cup HTML shell: navy
 * header with the brand name, a colored status badge, a heading, the
 * content itself, and a plain footer. Table-based + inline styles
 * throughout since that's what actually renders consistently across
 * Gmail/Outlook/Apple Mail.
 *
 * @param string $badge_label Short status word shown as a pill (e.g. "INTERVIEW INVITE").
 * @param string $badge_bg    Badge background color (hex).
 * @param string $heading     Main heading text.
 * @param string $body_html   Inner content — plain <p>/<div> markup, no need to wrap in <html>/<body>.
 */
function build_hr_email_shell(string $badge_label, string $badge_bg, string $heading, string $body_html): string {
    $navy      = HR_BRAND_NAVY;
    $blue      = HR_BRAND_BLUE;
    $blueSoft  = HR_BRAND_BLUE_SOFT;
    $textMid   = HR_BRAND_TEXT_MID;
    $textLight = HR_BRAND_TEXT_LIGHT;
    $year      = date('Y');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$heading}</title>
</head>
<body style="margin:0;padding:0;background:#EDF3F8;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EDF3F8;padding:32px 16px;">
  <tr>
    <td align="center">
      <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 2px 14px rgba(11,30,51,0.08);">

        <!-- Header -->
        <tr>
          <td style="background:{$navy};padding:28px 32px;text-align:center;">
            <div style="font-family:Georgia,'Times New Roman',serif;font-size:22px;font-weight:700;color:#FFFFFF;letter-spacing:0.5px;">&#9749; Cloud Cup</div>
            <div style="font-size:11.5px;font-weight:600;letter-spacing:2px;color:{$blueSoft};text-transform:uppercase;margin-top:4px;">Careers Team</div>
          </td>
        </tr>

        <!-- Accent bar -->
        <tr><td style="height:4px;background:{$blue};line-height:4px;font-size:0;">&nbsp;</td></tr>

        <!-- Body -->
        <tr>
          <td style="padding:36px 36px 28px;">
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:18px;">
              <tr>
                <td style="background:{$badge_bg};color:#FFFFFF;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;padding:6px 14px;border-radius:999px;">{$badge_label}</td>
              </tr>
            </table>
            <h1 style="margin:0 0 18px;font-size:21px;line-height:1.35;color:{$navy};font-family:Arial,Helvetica,sans-serif;">{$heading}</h1>
            <div style="font-size:14.5px;line-height:1.7;color:{$textMid};">
              {$body_html}
            </div>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="padding:20px 36px 28px;border-top:1px solid #EEF3F8;">
            <div style="font-size:12px;line-height:1.6;color:{$textLight};">
              This message was sent by the Cloud Cup HR team regarding your job application.
              If you have questions, just reply directly to this email.
            </div>
            <div style="font-size:11px;color:{$textLight};margin-top:10px;">&copy; {$year} Cloud Cup. All rights reserved.</div>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;
}

/**
 * Builds the "how this interview will happen" card shared by both the
 * initial and final interview invite emails — a light info box with the
 * date/time and either a Join button (Google Meet) or the address.
 *
 * @param string $mode     'gmeet' or 'personal'
 * @param string $detail   Google Meet link (for 'gmeet') or the physical address (for 'personal')
 * @param string $datetime MySQL-format 'Y-m-d H:i:s' scheduled interview time
 */
function build_interview_meeting_card(string $mode, string $detail, string $datetime = ''): string {
    $cream     = HR_BRAND_CREAM;
    $navy      = HR_BRAND_NAVY;
    $blue      = HR_BRAND_BLUE;
    $textLight = HR_BRAND_TEXT_LIGHT;

    $when_html = '';
    if ($datetime !== '') {
        $ts = strtotime($datetime);
        if ($ts !== false) {
            $when_html = '<div style="font-size:15px;font-weight:700;color:' . $navy . ';margin-bottom:4px;">' .
                htmlspecialchars(date('l, F j, Y', $ts)) . '</div>' .
                '<div style="font-size:13.5px;color:' . $textLight . ';margin-bottom:16px;">' .
                htmlspecialchars(date('g:i A', $ts)) . '</div>';
        }
    }

    $detail_esc = htmlspecialchars($detail);

    if ($mode === 'gmeet') {
        $link_href = filter_var($detail, FILTER_VALIDATE_URL) ? $detail_esc : ('https://' . ltrim($detail_esc, '/'));
        $meeting_html =
            '<div style="font-size:12px;font-weight:700;letter-spacing:0.5px;color:' . $textLight . ';text-transform:uppercase;margin-bottom:6px;">Google Meet</div>' .
            '<a href="' . $link_href . '" style="display:inline-block;background:' . $blue . ';color:#FFFFFF;font-size:13.5px;font-weight:700;text-decoration:none;padding:11px 22px;border-radius:8px;margin-top:2px;">Join the Meeting</a>' .
            '<div style="font-size:12px;color:' . $textLight . ';margin-top:10px;word-break:break-all;">' . $link_href . '</div>';
    } else {
        $meeting_html =
            '<div style="font-size:12px;font-weight:700;letter-spacing:0.5px;color:' . $textLight . ';text-transform:uppercase;margin-bottom:6px;">Location</div>' .
            '<div style="font-size:14.5px;color:' . $navy . ';font-weight:600;">' . $detail_esc . '</div>';
    }

    return
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $cream . ';border-radius:10px;margin:8px 0 20px;">' .
        '<tr><td style="padding:20px 22px;">' . $when_html . $meeting_html . '</td></tr>' .
        '</table>';
}

/**
 * Builds + sends the "confirm your email" verification code for a job
 * applicant, before their application is accepted.
 */
function send_verification_code_email(string $to_email, string $code): bool {
    $subject = "Your Cloud Cup verification code: {$code}";
    $code_esc = htmlspecialchars($code);
    $body_html =
        '<p style="margin:0 0 16px;">Use this code to confirm your email address on your Cloud Cup job application:</p>' .
        '<div style="font-size:28px;font-weight:700;letter-spacing:6px;color:' . HR_BRAND_NAVY . ';background:' . HR_BRAND_CREAM . ';border-radius:10px;padding:16px;text-align:center;margin:0 0 16px;">' . $code_esc . '</div>' .
        '<p style="margin:0;font-size:13px;color:' . HR_BRAND_TEXT_LIGHT . ';">This code expires in 10 minutes. If you didn\'t request this, you can safely ignore this email.</p>';
    $html = build_hr_email_shell('VERIFICATION', HR_BRAND_BLUE, 'Confirm your email address', $body_html);
    $plain = "Use this code to confirm your email address on your Cloud Cup job application:\n\n    {$code}\n\nThis code expires in 10 minutes. If you didn't request this, you can safely ignore this email.\n\n— Cloud Cup Careers Team";

    return send_hr_email($to_email, '', $subject, $html, $plain);
}

/**
 * Builds + sends the "you're up for an initial interview" email for one applicant.
 *
 * @param string $meeting_mode     'gmeet' or 'personal'
 * @param string $meeting_detail   Google Meet link or physical address
 * @param string $meeting_datetime MySQL-format 'Y-m-d H:i:s' scheduled interview time
 */
function send_interview_invite_email(string $to_email, string $applicant_name, string $job_title, string $meeting_mode, string $meeting_detail, string $meeting_datetime = ''): bool {
    $subject = "You're up for an interview with Cloud Cup — {$job_title}";
    $name_esc  = htmlspecialchars($applicant_name);
    $title_esc = htmlspecialchars($job_title);
    $meeting_card = build_interview_meeting_card($meeting_mode, $meeting_detail, $meeting_datetime);

    $body_html =
        '<p style="margin:0 0 16px;">Hi ' . $name_esc . ',</p>' .
        '<p style="margin:0 0 16px;">Thank you for applying for the <strong>' . $title_esc . '</strong> position at Cloud Cup. We reviewed your application and would like to invite you to the next step: an initial interview.</p>' .
        $meeting_card .
        '<p style="margin:0;">If you have any questions or need to reschedule, just reply to this email. We look forward to speaking with you!</p>';

    $html = build_hr_email_shell('INTERVIEW INVITE', HR_BRAND_BLUE, "You're invited to an interview", $body_html);

    $ts = $meeting_datetime !== '' ? strtotime($meeting_datetime) : false;
    $when_plain = $ts ? ('Scheduled for ' . date('l, F j, Y \a\t g:i A', $ts) . ".\n\n") : '';
    $meeting_plain = $meeting_mode === 'gmeet'
        ? "This interview will be held online via Google Meet:\n\n    {$meeting_detail}\n"
        : "This interview will be held in person at:\n\n    {$meeting_detail}\n";
    $plain =
        "Hi {$applicant_name},\n\n" .
        "Thank you for applying for the {$job_title} position at Cloud Cup. We reviewed your application and would like to invite you to the next step: an initial interview.\n\n" .
        $when_plain . $meeting_plain . "\n" .
        "If you have any questions or need to reschedule, just reply to this email.\n\n— Cloud Cup Careers Team";

    return send_hr_email($to_email, $applicant_name, $subject, $html, $plain);
}

/**
 * Builds + sends the "you've made it to the final interview" email for one applicant.
 *
 * @param string $meeting_mode     'gmeet' or 'personal'
 * @param string $meeting_detail   Google Meet link or physical address
 * @param string $meeting_datetime MySQL-format 'Y-m-d H:i:s' scheduled interview time
 */
function send_final_interview_invite_email(string $to_email, string $applicant_name, string $job_title, string $meeting_mode, string $meeting_detail, string $meeting_datetime = ''): bool {
    $subject = "Final interview round with Cloud Cup — {$job_title}";
    $name_esc  = htmlspecialchars($applicant_name);
    $title_esc = htmlspecialchars($job_title);
    $meeting_card = build_interview_meeting_card($meeting_mode, $meeting_detail, $meeting_datetime);

    $body_html =
        '<p style="margin:0 0 16px;">Hi ' . $name_esc . ',</p>' .
        '<p style="margin:0 0 16px;">Great news — you\'ve cleared the initial interview for the <strong>' . $title_esc . '</strong> position at Cloud Cup, and we\'d like to invite you to a final interview round.</p>' .
        $meeting_card .
        '<p style="margin:0;">If you have any questions or need to reschedule, just reply to this email. We look forward to speaking with you!</p>';

    $html = build_hr_email_shell('FINAL INTERVIEW', HR_BRAND_BLUE, "You've reached the final round", $body_html);

    $ts = $meeting_datetime !== '' ? strtotime($meeting_datetime) : false;
    $when_plain = $ts ? ('Scheduled for ' . date('l, F j, Y \a\t g:i A', $ts) . ".\n\n") : '';
    $meeting_plain = $meeting_mode === 'gmeet'
        ? "This interview will be held online via Google Meet:\n\n    {$meeting_detail}\n"
        : "This interview will be held in person at:\n\n    {$meeting_detail}\n";
    $plain =
        "Hi {$applicant_name},\n\n" .
        "Great news — you've cleared the initial interview for the {$job_title} position at Cloud Cup, and we'd like to invite you to a final interview round.\n\n" .
        $when_plain . $meeting_plain . "\n" .
        "If you have any questions or need to reschedule, just reply to this email.\n\n— Cloud Cup Careers Team";

    return send_hr_email($to_email, $applicant_name, $subject, $html, $plain);
}

/**
 * Builds + sends the "you're hired" email once an applicant clears the
 * final interview and is marked Hired.
 */
function send_hired_email(string $to_email, string $applicant_name, string $job_title): bool {
    $subject = "Welcome to Cloud Cup, {$applicant_name}!";
    $name_esc  = htmlspecialchars($applicant_name);
    $title_esc = htmlspecialchars($job_title);

    $body_html =
        '<p style="margin:0 0 16px;">Hi ' . $name_esc . ',</p>' .
        '<p style="margin:0 0 16px;">Congratulations — we\'re delighted to offer you the <strong>' . $title_esc . '</strong> position at Cloud Cup! Your interviews stood out, and we\'re excited to have you join the team.</p>' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . HR_BRAND_CREAM . ';border-radius:10px;margin:8px 0 20px;">' .
        '<tr><td style="padding:20px 22px;">' .
        '<div style="font-size:12px;font-weight:700;letter-spacing:0.5px;color:' . HR_BRAND_TEXT_LIGHT . ';text-transform:uppercase;margin-bottom:6px;">Position</div>' .
        '<div style="font-size:15px;font-weight:700;color:' . HR_BRAND_NAVY . ';">' . $title_esc . '</div>' .
        '</td></tr></table>' .
        '<p style="margin:0 0 16px;">Our HR team will reach out shortly with your onboarding details — start date, paperwork, and everything you\'ll need for your first day.</p>' .
        '<p style="margin:0;">Welcome aboard, and congratulations again!</p>';

    $html = build_hr_email_shell('HIRED', HR_BRAND_SUCCESS, "You're hired!", $body_html);

    $plain =
        "Hi {$applicant_name},\n\n" .
        "Congratulations — we're delighted to offer you the {$job_title} position at Cloud Cup! Your interviews stood out, and we're excited to have you join the team.\n\n" .
        "Our HR team will reach out shortly with your onboarding details — start date, paperwork, and everything you'll need for your first day.\n\n" .
        "Welcome aboard, and congratulations again!\n\n— Cloud Cup Careers Team";

    return send_hr_email($to_email, $applicant_name, $subject, $html, $plain);
}

/**
 * Builds + sends the login-credentials email for the Cloud Cup staff
 * account that gets auto-created when an applicant is marked Hired
 * (see hire_provision_employee() in HR/Applications_Page.php). Only
 * sent when a brand-new account was created — not when an existing
 * one was reused.
 */
function send_employee_account_email(string $to_email, string $applicant_name, string $username, string $temp_password): bool {
    $subject = "Your Cloud Cup staff account";
    $name_esc = htmlspecialchars($applicant_name);
    $user_esc = htmlspecialchars($username);
    $pass_esc = htmlspecialchars($temp_password);

    $body_html =
        '<p style="margin:0 0 16px;">Hi ' . $name_esc . ',</p>' .
        '<p style="margin:0 0 16px;">Here are your login details for the Cloud Cup staff system, used for clocking in sales and viewing your schedule.</p>' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . HR_BRAND_CREAM . ';border-radius:10px;margin:8px 0 20px;">' .
        '<tr><td style="padding:20px 22px;">' .
        '<div style="font-size:12px;font-weight:700;letter-spacing:0.5px;color:' . HR_BRAND_TEXT_LIGHT . ';text-transform:uppercase;margin-bottom:6px;">Username</div>' .
        '<div style="font-size:15px;font-weight:700;color:' . HR_BRAND_NAVY . ';margin-bottom:14px;">' . $user_esc . '</div>' .
        '<div style="font-size:12px;font-weight:700;letter-spacing:0.5px;color:' . HR_BRAND_TEXT_LIGHT . ';text-transform:uppercase;margin-bottom:6px;">Temporary password</div>' .
        '<div style="font-size:15px;font-weight:700;color:' . HR_BRAND_NAVY . ';">' . $pass_esc . '</div>' .
        '</td></tr></table>' .
        '<p style="margin:0;">Please log in and change this password as soon as you can. See you soon!</p>';

    $html = build_hr_email_shell('ACCOUNT', HR_BRAND_BLUE, 'Your staff login', $body_html);

    $plain =
        "Hi {$applicant_name},\n\n" .
        "Here are your login details for the Cloud Cup staff system, used for clocking in sales and viewing your schedule.\n\n" .
        "Username: {$username}\n" .
        "Temporary password: {$temp_password}\n\n" .
        "Please log in and change this password as soon as you can. See you soon!\n\n— Cloud Cup Careers Team";

    return send_hr_email($to_email, $applicant_name, $subject, $html, $plain);
}

/**
 * Builds + sends the "not moving forward" email when an applicant is
 * rejected or marked Not Qualified at any stage.
 */
function send_not_qualified_email(string $to_email, string $applicant_name, string $job_title): bool {
    $subject = "Update on your Cloud Cup application — {$job_title}";
    $name_esc  = htmlspecialchars($applicant_name);
    $title_esc = htmlspecialchars($job_title);

    $body_html =
        '<p style="margin:0 0 16px;">Hi ' . $name_esc . ',</p>' .
        '<p style="margin:0 0 16px;">Thank you for taking the time to apply for the <strong>' . $title_esc . '</strong> position at Cloud Cup, and for the effort you put into the process.</p>' .
        '<p style="margin:0 0 16px;">After careful consideration, we\'ve decided to move forward with other candidates for this role. This isn\'t a reflection of your abilities — we simply had a limited number of openings and a strong pool of applicants.</p>' .
        '<p style="margin:0;">We\'ll keep your application on file and encourage you to apply again for future openings that match your skills. We wish you all the best in your job search.</p>';

    $html = build_hr_email_shell('APPLICATION UPDATE', HR_BRAND_DANGER_SOFT, 'An update on your application', $body_html);

    $plain =
        "Hi {$applicant_name},\n\n" .
        "Thank you for taking the time to apply for the {$job_title} position at Cloud Cup, and for the effort you put into the process.\n\n" .
        "After careful consideration, we've decided to move forward with other candidates for this role. This isn't a reflection of your abilities — we simply had a limited number of openings and a strong pool of applicants.\n\n" .
        "We'll keep your application on file and encourage you to apply again for future openings that match your skills. We wish you all the best in your job search.\n\n— Cloud Cup Careers Team";

    return send_hr_email($to_email, $applicant_name, $subject, $html, $plain);
}

/**
 * Builds a basic employment contract as a PDF (via the dependency-free
 * SimplePdf writer — no Composer, no external service). This is a
 * generic, editable starting template, NOT a legal document reviewed by
 * counsel — have a lawyer review the clauses below before relying on it.
 *
 * @param array $c Expects: applicant_name, position, department,
 *                  employment_type (e.g. 'full-time'), monthly_salary (float),
 *                  start_date ('Y-m-d').
 * @return string Raw PDF bytes.
 */
function build_employment_contract_pdf(array $c): string {
    $name       = $c['applicant_name'];
    $position   = $c['position'];
    $department = $c['department'] ?: 'N/A';
    $empType    = ucwords(str_replace('-', ' ', $c['employment_type'] ?: 'full-time'));
    // The base-14 PDF fonts this writer uses don't include the ₱ glyph
    // (outside Windows-1252), so spell it out instead of risking a dropped/
    // mangled character on the printed contract.
    $salaryFmt  = 'PHP ' . number_format((float)$c['monthly_salary'], 2);
    $startFmt   = date('F j, Y', strtotime($c['start_date']));
    $todayFmt   = date('F j, Y');

    $pdf = new SimplePdf();
    $pdf->addPage();

    $pdf->setFont('B', 18);
    $pdf->writeLine('EMPLOYMENT CONTRACT');
    $pdf->setFont('', 10);
    $pdf->writeLine(COMPANY_LEGAL_NAME);
    $pdf->writeLine(COMPANY_ADDRESS);
    $pdf->spacer(10);

    $pdf->setFont('', 11);
    $pdf->writeParagraph("This Employment Contract (\"Agreement\") is made and entered into on {$todayFmt}, by and between " . COMPANY_LEGAL_NAME . " (\"the Company\") and {$name} (\"the Employee\").");

    $pdf->setFont('B', 12);
    $pdf->writeLine('1. Position and Duties');
    $pdf->setFont('', 11);
    $pdf->writeParagraph("The Employee is hired for the position of {$position} in the {$department} department, on a {$empType} basis. The Employee agrees to perform the duties associated with this role, and any other reasonable duties assigned by the Company from time to time, to the best of their ability.");

    $pdf->setFont('B', 12);
    $pdf->writeLine('2. Start Date');
    $pdf->setFont('', 11);
    $pdf->writeParagraph("Employment under this Agreement begins on {$startFmt}.");

    $pdf->setFont('B', 12);
    $pdf->writeLine('3. Compensation');
    $pdf->setFont('', 11);
    $pdf->writeParagraph("The Employee will be paid a gross monthly salary of {$salaryFmt}, payable in accordance with the Company's regular payroll schedule, less applicable statutory deductions (e.g. SSS, PhilHealth, Pag-IBIG, withholding tax).");

    $pdf->setFont('B', 12);
    $pdf->writeLine('4. Probationary Period');
    $pdf->setFont('', 11);
    $pdf->writeParagraph("Unless stated otherwise in writing, the Employee will serve an initial probationary period of six (6) months from the start date, during which performance will be evaluated against standards communicated by the Company. Upon satisfactory completion, the Employee may be considered for regular employment status.");

    $pdf->setFont('B', 12);
    $pdf->writeLine('5. Work Schedule');
    $pdf->setFont('', 11);
    $pdf->writeParagraph("The Employee's work schedule will be assigned by the Company and may vary based on business needs, consistent with the employment type stated above and applicable labor law.");

    $pdf->setFont('B', 12);
    $pdf->writeLine('6. Confidentiality');
    $pdf->setFont('', 11);
    $pdf->writeParagraph("The Employee agrees not to disclose any confidential or proprietary information belonging to the Company, whether during or after employment, except as required by law or with the Company's prior written consent.");

    $pdf->setFont('B', 12);
    $pdf->writeLine('7. Termination');
    $pdf->setFont('', 11);
    $pdf->writeParagraph("Either party may terminate this Agreement in accordance with the Company's policies and applicable labor law, including for just or authorized causes, or by mutual written agreement.");

    $pdf->setFont('B', 12);
    $pdf->writeLine('8. Governing Law');
    $pdf->setFont('', 11);
    $pdf->writeParagraph("This Agreement shall be governed by and construed in accordance with the applicable labor laws of the Republic of the Philippines.");

    $pdf->spacer(18);
    $pdf->setFont('', 11);
    $pdf->writeParagraph("By signing below, both parties acknowledge that they have read, understood, and agree to the terms of this Agreement.", 24);

    $pdf->writeField('Company Representative:', COMPANY_SIGNATORY_NAME . ' — ' . COMPANY_SIGNATORY_TITLE);
    $pdf->spacer(28);
    $pdf->writeLine('Signature: _______________________________        Date: ______________');
    $pdf->spacer(22);
    $pdf->writeField('Employee:', $name);
    $pdf->spacer(28);
    $pdf->writeLine('Signature: _______________________________        Date: ______________');

    return $pdf->output();
}

/**
 * Builds + sends the employment contract PDF to a newly hired applicant,
 * as its own dedicated email (separate from the "You're hired!" welcome
 * email so the contract doesn't get lost among the other congratulatory
 * copy, and so it's easy to find again later by subject line).
 */
function send_employment_contract_email(string $to_email, array $contractData): bool {
    $name = $contractData['applicant_name'];
    $title_esc = htmlspecialchars($contractData['position']);
    $subject = "Your Cloud Cup employment contract — {$contractData['position']}";
    $name_esc = htmlspecialchars($name);

    $body_html =
        '<p style="margin:0 0 16px;">Hi ' . $name_esc . ',</p>' .
        '<p style="margin:0 0 16px;">Attached is your employment contract for the <strong>' . $title_esc . '</strong> position at Cloud Cup. Please review it carefully.</p>' .
        '<p style="margin:0 0 16px;">Print, sign, and return a copy (scanned or in person) to our HR team at your earliest convenience. If anything is unclear or needs adjusting, just reply to this email.</p>' .
        '<p style="margin:0;">Welcome to the team!</p>';

    $html = build_hr_email_shell('CONTRACT', HR_BRAND_BLUE, 'Your employment contract', $body_html);

    $plain =
        "Hi {$name},\n\n" .
        "Attached is your employment contract for the {$contractData['position']} position at Cloud Cup. Please review it carefully.\n\n" .
        "Print, sign, and return a copy (scanned or in person) to our HR team at your earliest convenience. If anything is unclear or needs adjusting, just reply to this email.\n\n" .
        "Welcome to the team!\n\n— Cloud Cup Careers Team";

    $pdfBytes = build_employment_contract_pdf($contractData);
    $filename = 'Employment_Contract_' . preg_replace('/[^A-Za-z0-9]+/', '_', $name) . '.pdf';

    return send_hr_email($to_email, $name, $subject, $html, $plain, [
        ['data' => $pdfBytes, 'name' => $filename, 'type' => 'application/pdf'],
    ]);
}