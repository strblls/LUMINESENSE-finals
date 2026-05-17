<?php
/**
 * LumineSense – Admin Sign-Up Process
 * ------------------------------------
 * 1. Validates that the email ends in @gmail.com
 * 2. Checks the admin code
 * 3. Saves the new admin (is_verified = 0) to the DB
 * 4. Sends a 6-digit OTP to the provided Gmail
 * 5. Redirects to verify-email.php
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'db_connect.php';
require_once 'mailer.php';

// ── 1. Only accept POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pages/admin-signup.php');
    exit;
}

// ── 2. Collect & sanitize inputs ──────────────────────────────────────────
$last_name       = trim($_POST['last_name']       ?? '');
$first_name      = trim($_POST['first_name']      ?? '');
$middle_initial  = strtoupper(trim($_POST['middle_initial'] ?? ''));
$admin_code      = trim($_POST['admin_code']      ?? '');
$email           = strtolower(trim($_POST['email'] ?? ''));
$password        = $_POST['password']        ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

$errors = [];

// ── 3. Basic field checks ─────────────────────────────────────────────────
if (empty($last_name))   $errors[] = 'Last name is required.';
if (empty($first_name))  $errors[] = 'First name is required.';
if (empty($email))       $errors[] = 'Email is required.';
if (empty($password))    $errors[] = 'Password is required.';

// ── 4. Gmail-only rule ────────────────────────────────────────────────────
if (!empty($email) && !preg_match('/@gmail\.com$/i', $email)) {
    $errors[] = 'Only @gmail.com addresses are accepted.';
}

// ── 5. Password rules ─────────────────────────────────────────────────────
if (strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters.';
}
if ($password !== $confirm_password) {
    $errors[] = 'Passwords do not match.';
}

// ── 6. Admin code validation ──────────────────────────────────────────────
// Change LUMINESENSE_ADMIN_2025 to whatever secret code you want.
define('VALID_ADMIN_CODE', 'LUMINESENSE_ADMIN_2025');
if ($admin_code !== VALID_ADMIN_CODE) {
    $errors[] = 'Invalid admin code.';
}

// ── 7. If there are errors, go back ───────────────────────────────────────
if (!empty($errors)) {
    $_SESSION['signup_errors'] = $errors;
    header('Location: ../pages/admin-signup.php');
    exit;
}

// ── 8. Check if email already exists ─────────────────────────────────────
$stmt = $conn->prepare("SELECT id FROM admins WHERE email = ?");
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $_SESSION['signup_errors'] = ['This email is already registered.'];
    $stmt->close();
    header('Location: ../pages/admin-signup.php');
    exit;
}
$stmt->close();

// ── 9. Hash password & generate OTP ──────────────────────────────────────
$hashed_password = password_hash($password, PASSWORD_BCRYPT);
$otp_code        = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$otp_expires_at  = date('Y-m-d H:i:s', strtotime('+15 minutes'));

// ── 10. Insert new admin (is_verified = 0 until email confirmed) ──────────
//  is_verified: 0 = email not yet confirmed, 1 = active
$stmt = $conn->prepare("
    INSERT INTO admins
        (last_name, first_name, middle_initial, email, password, is_verified, otp_code, otp_expires_at)
    VALUES (?, ?, ?, ?, ?, 0, ?, ?)
");
$stmt->bind_param(
    'sssssss',
    $last_name, $first_name, $middle_initial,
    $email, $hashed_password,
    $otp_code, $otp_expires_at
);

if (!$stmt->execute()) {
    $_SESSION['signup_errors'] = ['Database error. Please try again later.'];
    $stmt->close();
    header('Location: ../pages/admin-signup.php');
    exit;
}
$stmt->close();

// ── 11. Send OTP email ────────────────────────────────────────────────────
$mail_sent = sendVerificationEmail($email, $otp_code, $first_name);

if (!$mail_sent) {
    // Not fatal – user can request resend on the verify page
    $_SESSION['email_warning'] = 'We could not send the verification email. Please use the Resend button.';
}

// ── 12. Pass data to verify page via session ──────────────────────────────
$_SESSION['pending_verification'] = [
    'email'    => $email,
    'role'     => 'admin',
    'name'     => $first_name,
];

header('Location: ../pages/verify-email.php');
exit;