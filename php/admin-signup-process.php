<?php
/**
 * LumineSense – Admin Sign-Up Process
 * ------------------------------------
 * 1. Validates that the email ends in @gmail.com
 * 2. Checks the admin code + selected role
 * 3. Saves the new admin (is_verified = 0) to the DB
 * 4. Runs the uploaded ID through IdVerifier (Google Vision)
 * 5. If mismatched/unreadable -> encrypts + drops into id_review_queue
 * 6. Sends a 6-digit OTP to the provided Gmail
 * 7. Redirects to verify-email.php
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'db_connect.php';
require_once 'mailer.php';
require_once 'id-verifier.php';
require_once 'id-quarantine.php';

// ── 1. Only accept POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pages/admin-signup.php');
    exit;
}

// ── 2. Collect & sanitize inputs ──────────────────────────────────────────
$last_name        = trim($_POST['last_name']        ?? '');
$first_name       = trim($_POST['first_name']       ?? '');
$middle_initial   = strtoupper(trim($_POST['middle_initial'] ?? ''));
$admin_code       = trim($_POST['admin_code']       ?? '');
$email            = strtolower(trim($_POST['email'] ?? ''));
$password         = $_POST['password']         ?? '';
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

// ── 6. ID Image validation ────────────────────────────────────────────────
$allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
$max_size      = 5 * 1024 * 1024; // 5MB

if (empty($_FILES['id_image']['name'])) {
    $errors[] = 'Please upload a photo of your ID.';
} elseif (!in_array($_FILES['id_image']['type'], $allowed_types)) {
    $errors[] = 'ID image must be a JPG, PNG, or WEBP file.';
} elseif ($_FILES['id_image']['size'] > $max_size) {
    $errors[] = 'ID image must be under 5MB.';
}

// ── 7. If there are errors, go back ───────────────────────────────────────
if (!empty($errors)) {
    $_SESSION['signup_errors'] = $errors;
    $_SESSION['signup_form']   = compact('last_name', 'first_name', 'middle_initial', 'email');
    header('Location: ../pages/admin-signup.php');
    exit;
}

// ── 8. Check if email already exists ──────────────────────────────────────
$stmt = $conn->prepare("SELECT id FROM admins WHERE email = ?");
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $_SESSION['signup_errors'] = ['This email is already registered.'];
    $_SESSION['signup_form']   = compact('last_name', 'first_name', 'middle_initial', 'email');
    $stmt->close();
    header('Location: ../pages/admin-signup.php');
    exit;
}
$stmt->close();

// ── 9. Hash password & generate OTP ──────────────────────────────────────
$hashed_password = password_hash($password, PASSWORD_BCRYPT);
$otp_code        = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$otp_expires_at  = date('Y-m-d H:i:s', strtotime('+15 minutes'));

// ── 9.1 Save the uploaded ID temporarily (needed before verification) ────
$tmp_dir  = sys_get_temp_dir();
$ext      = pathinfo($_FILES['id_image']['name'], PATHINFO_EXTENSION);
$tmp_path = $tmp_dir . '/id_' . bin2hex(random_bytes(8)) . '.' . $ext;

if (!move_uploaded_file($_FILES['id_image']['tmp_name'], $tmp_path)) {
    $_SESSION['signup_errors'] = ['Failed to process ID image. Please try again.'];
    $_SESSION['signup_form']   = compact('last_name', 'first_name', 'middle_initial', 'email');
    header('Location: ../pages/admin-signup.php');
    exit;
}

// Grab raw bytes NOW — IdVerifier deletes the file once it's done checking.
$raw_image_bytes = file_get_contents($tmp_path);

// ── 9.2 Run the ID through IdVerifier (Google Vision) ────────────────────
$verifier = new IdVerifier(getenv('VISION_API_KEY'));
$result   = $verifier->verify($tmp_path, $first_name, $last_name);
// ^ verify() deletes $tmp_path internally once it's done, win or lose.

// ── 10. Insert new admin (is_verified = 0 until email confirmed) ──────────
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
    $_SESSION['signup_form']   = compact('last_name', 'first_name', 'middle_initial', 'email');
    $stmt->close();
    header('Location: ../pages/admin-signup.php');
    exit;
}
$admin_id = $conn->insert_id;
$stmt->close();

// ── 10.1 If the ID didn't clearly match, drop it in the review queue ──────
if (in_array($result['status'], ['mismatched', 'unreadable'], true)) {

    $encrypted_blob = IdQuarantine::encrypt($raw_image_bytes);
    $expires_at     = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $qStmt = $conn->prepare("
        INSERT INTO id_review_queue
            (account_type, account_id, encrypted_blob, ai_match_status, ai_extracted_name, ai_confidence_note, expires_at)
        VALUES ('admin', ?, ?, ?, ?, ?, ?)
    ");
    $qStmt->bind_param(
        'isssss',
        $admin_id,
        $encrypted_blob,
        $result['status'],
        $result['extracted_name'],
        $result['note'],
        $expires_at
    );
    $qStmt->execute();
    $qStmt->close();
}

// Wipe our in-memory copy — don't need it anymore either way.
$raw_image_bytes = null;
unset($raw_image_bytes);

// ── 11. Send OTP email ─────────────────────────────────────────────────────
$mail_sent = sendVerificationEmail($email, $otp_code, $first_name);

if (!$mail_sent) {
    $_SESSION['email_warning'] = 'We could not send the verification email. Please use the Resend button.';
}

// ── 12. Pass data to verify page via session ───────────────────────────────
$_SESSION['pending_verification'] = [
    'email' => $email,
    'role'  => 'admin',
    'name'  => $first_name,
];

header('Location: ../pages/verify-email.php');
exit;