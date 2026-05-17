<?php
/**
 * LumineSense – Faculty Sign-Up Process
 * --------------------------------------
 * 1. Validates that the email ends in @gmail.com
 * 2. Saves new faculty (is_verified = 0) to the DB
 * 3. Sends a 6-digit OTP to the provided Gmail
 * 4. Redirects to verify-email.php
 *
 * After email is verified → is_verified = 1, approved_by = NULL (waiting for admin)
 * After Admin approves   → approved_by = admin id, approved_at = now
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'db_connect.php';
require_once 'mailer.php';

// ── 1. Only accept POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pages/faculty-signup.php');
    exit;
}

// ── 2. Collect & sanitize inputs ──────────────────────────────────────────
$last_name       = trim($_POST['last_name']       ?? '');
$first_name      = trim($_POST['first_name']      ?? '');
$middle_initial  = strtoupper(trim($_POST['middle_initial'] ?? ''));
$email           = strtolower(trim($_POST['email'] ?? ''));
$password        = $_POST['password']         ?? '';
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

// ── 6. If there are errors, go back ───────────────────────────────────────
if (!empty($errors)) {
    $_SESSION['signup_errors'] = $errors;
    // Remember form values so they're not wiped on redirect
    $_SESSION['signup_form'] = [
        'last_name'      => $last_name,
        'first_name'     => $first_name,
        'middle_initial' => $middle_initial,
        'email'          => $email,
    ];
    header('Location: ../pages/faculty-signup.php');
    exit;
}

// ── 7. Check if email already exists ─────────────────────────────────────
$stmt = $conn->prepare("SELECT id FROM faculty WHERE email = ?");
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $_SESSION['signup_errors'] = ['This email is already registered.'];
    $_SESSION['signup_form']   = compact('last_name', 'first_name', 'middle_initial', 'email');
    $stmt->close();
    header('Location: ../pages/faculty-signup.php');
    exit;
}
$stmt->close();

// ── 8. Hash password & generate OTP ──────────────────────────────────────
$hashed_password = password_hash($password, PASSWORD_BCRYPT);
$otp_code        = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$otp_expires_at  = date('Y-m-d H:i:s', strtotime('+15 minutes'));

// ── 9. Insert new faculty (is_verified = 0, approved_by = NULL) ──────────
//  Flow: is_verified=0 → email confirmed → is_verified=1, approved_by=NULL
//        → admin approves → approved_by=admin_id, approved_at=now
$stmt = $conn->prepare("
    INSERT INTO faculty
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
    $_SESSION['signup_form']   = compact('last_name', 'first_name', 'middle_initial', 'email');
    $stmt->close();
    header('Location: ../pages/faculty-signup.php');
    exit;
}
$stmt->close();

// ── 10. Send OTP email ────────────────────────────────────────────────────
$mail_sent = sendVerificationEmail($email, $otp_code, $first_name);

if (!$mail_sent) {
    $_SESSION['email_warning'] = 'We could not send the verification email. Please use the Resend button.';
}

// ── 11. Pass data to verify page via session ──────────────────────────────
$_SESSION['pending_verification'] = [
    'email' => $email,
    'role'  => 'faculty',
    'name'  => $first_name,
];

header('Location: ../pages/verify-email.php');
exit;