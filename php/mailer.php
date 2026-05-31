<?php
/**
 * LumineSense – Mailer Helper
 * ----------------------------
 * Sends a 6-digit OTP verification email using Gmail SMTP via PHPMailer.
 *
 * SETUP (do this once):
 *   1. Run in your project root:  composer require phpmailer/phpmailer
 *   2. Fill in GMAIL_USER and GMAIL_APP_PASSWORD below.
 *      - Go to your Google Account → Security → 2-Step Verification → App Passwords
 *      - Generate a password for "Mail" / "Other (LumineSense)"
 *      - Paste that 16-character password as GMAIL_APP_PASSWORD
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

// ── Hostinger SMTP credentials ──────────────────────────────────────────
require_once __DIR__ . '/config.php';

/**
 * Sends a verification OTP to the given email address.
 *
 * @param  string $to        Recipient Gmail address
 * @param  string $otp_code  6-digit OTP string
 * @param  string $name      Recipient's first name (used in greeting)
 * @return bool              true if sent successfully, false otherwise
 */
function sendVerificationEmail(string $to, string $otp_code, string $name = 'User'): bool
{
    $mail = new PHPMailer(true);

    try {
       $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($to, $name);

        // ── Content ──────────────────────────────────────────────────────
        $mail->isHTML(true);
        $mail->Subject = 'LumineSense – Your Verification Code';
        $mail->Body    = buildEmailBody($name, $otp_code);
        $mail->AltBody = "Hi $name,\n\nYour LumineSense verification code is: $otp_code\n\nThis code expires in 15 minutes.\n\nIf you did not sign up, please ignore this email.";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('LumineSense Mailer Error: ' . $mail->ErrorInfo);
        return false;
    }
}

function sendApprovalEmail(string $to, string $name): bool
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($to, $name);

        $mail->isHTML(true);
        $mail->Subject = 'LumineSense – Your Account Has Been Approved!';
        $mail->Body    = "
            <div style='font-family:Arial,sans-serif;max-width:480px;margin:40px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);'>
                <div style='background:#1a1a2e;padding:28px 32px;text-align:center;'>
                    <h1 style='color:#f5c518;font-size:20px;margin:0;letter-spacing:2px;'>💡 LUMINESENSE</h1>
                </div>
                <div style='padding:32px;color:#333;'>
                    <p>Hi <strong>{$name}</strong>,</p>
                    <p>Great news! Your LumineSense faculty account has been <strong style='color:#28a745;'>approved</strong> by an Administrator.</p>
                    <p>You can now log in and access your dashboard.</p>
                    <div style='text-align:center;margin:28px 0;'>
                        <a href='http://localhost/LUMINESENSE_VERSIONS/LUMINESENSE-finals/pages/faculty-login.php'
                           style='background:#4a6cf7;color:#fff;padding:12px 32px;border-radius:8px;text-decoration:none;font-weight:700;'>
                            LOG IN NOW
                        </a>
                    </div>
                    <p style='font-size:13px;color:#888;'>If you did not create a LumineSense account, please ignore this email.</p>
                </div>
                <div style='background:#f9f9f9;text-align:center;padding:16px;font-size:12px;color:#aaa;border-top:1px solid #eee;'>
                    © 2025 LumineSense · University of Negros Occidental – Recoletos
                </div>
            </div>
        ";
        $mail->AltBody = "Hi {$name},\n\nYour LumineSense faculty account has been approved! You can now log in.\n\nIf you did not create an account, ignore this email.";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('LumineSense Approval Mailer Error: ' . $mail->ErrorInfo);
        return false;
    }
}



/**
 * Builds the HTML email body.
 */
function buildEmailBody(string $name, string $otp_code): string
{
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <style>
    body        { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 0; }
    .wrapper    { max-width: 480px; margin: 40px auto; background: #ffffff;
                  border-radius: 12px; overflow: hidden;
                  box-shadow: 0 4px 20px rgba(0,0,0,.08); }
    .header     { background: #1a1a2e; padding: 28px 32px; text-align: center; }
    .header img { height: 48px; }
    .header h1  { color: #f5c518; font-size: 20px; margin: 10px 0 0; letter-spacing: 2px; }
    .body       { padding: 32px; color: #333; }
    .body p     { margin: 0 0 16px; line-height: 1.6; }
    .otp-box    { background: #f0f4ff; border: 2px dashed #4a6cf7;
                  border-radius: 10px; text-align: center; padding: 20px;
                  margin: 24px 0; }
    .otp-code   { font-size: 40px; font-weight: 700; letter-spacing: 12px;
                  color: #1a1a2e; }
    .note       { font-size: 13px; color: #888; }
    .footer     { background: #f9f9f9; text-align: center;
                  padding: 16px; font-size: 12px; color: #aaa;
                  border-top: 1px solid #eee; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>💡 LUMINESENSE</h1>
    </div>
    <div class="body">
      <p>Hi <strong>{$name}</strong>,</p>
      <p>Thank you for signing up! Here is your one-time verification code:</p>
      <div class="otp-box">
        <div class="otp-code">{$otp_code}</div>
      </div>
      <p>Enter this code on the verification page to confirm your email address.</p>
      <p class="note">⏰ This code expires in <strong>15 minutes</strong>.<br>
         If you did not create a LumineSense account, you can safely ignore this email.</p>
    </div>
    <div class="footer">
      © 2025 LumineSense · University of Negros Occidental – Recoletos
    </div>
  </div>
</body>
</html>
HTML;
}