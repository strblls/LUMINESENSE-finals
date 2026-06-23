<?php
/**
 * php/handlers/admin-signup-process.php
 *
 * Open self-registration for Admin accounts.
 * Mirrors faculty-signup-process.php exactly — same ID upload,
 * same OCR pipeline, same quarantine branching. The only
 * structural differences from faculty signup are:
 *   - No department_id field (admins see all departments)
 *   - account_type = 'admin' when writing to id_review_queue
 *   - Inserts into `admins` table, not `faculty`
 *
 * FLOW:
 *   Admin fills form + uploads school ID
 *       -> OCR runs, image deleted regardless of outcome
 *       -> If matched: account created, OTP sent
 *       -> If mismatched/unreadable: account STILL created (so
 *          the person gets their OTP and knows signup worked),
 *          but encrypted image goes to id_review_queue for a
 *          DIFFERENT admin to review within 24 hours
 *       -> Admin confirms OTP -> is_verified = 1
 *           -> Existing Admin sees them in "Pending Accounts"
 *               -> Existing Admin approves -> approved_by = their id
 *                   -> New admin can now log in
 *                   (login checks is_verified AND approved_by AND
 *                    no unresolved quarantine entry)
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../IdVerifier.php';
require_once __DIR__ . '/../IdQuarantine.php';

date_default_timezone_set('Asia/Manila');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../pages/admin-signup.php');
    exit;
}

// Collect and sanitize inputs
$last_name        = trim($_POST['last_name']        ?? '');
$first_name       = trim($_POST['first_name']       ?? '');
$middle_initial   = strtoupper(trim($_POST['middle_initial'] ?? ''));
$email            = strtolower(trim($_POST['email'] ?? ''));
$password         = $_POST['password']              ?? '';
$confirm_password = $_POST['confirm_password']      ?? '';

$errors = [];

if (empty($last_name))   $errors[] = 'Last name is required.';
if (empty($first_name))  $errors[] = 'First name is required.';
if (empty($email))       $errors[] = 'Email is required.';
if (empty($password))    $errors[] = 'Password is required.';

if (!empty($email) && !preg_match('/@gmail\.com$/i', $email)) {
    $errors[] = 'Only @gmail.com addresses are accepted.';
}

if (strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters.';
}
if ($password !== $confirm_password) {
    $errors[] = 'Passwords do not match.';
}

// ID image validation
$allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
$max_size      = 5 * 1024 * 1024; // 5MB

$has_image = !empty($_FILES['id_image']['name']) && $_FILES['id_image']['error'] === UPLOAD_ERR_OK;

if (!$has_image) {
    $errors[] = 'Please upload a photo of your school ID.';
} elseif (!in_array($_FILES['id_image']['type'], $allowed_types)) {
    $errors[] = 'ID image must be a JPG, PNG, or WEBP file.';
} elseif ($_FILES['id_image']['size'] > $max_size) {
    $errors[] = 'ID image must be under 5MB.';
}

if (!empty($errors)) {
    $_SESSION['signup_errors'] = $errors;
    $_SESSION['signup_form']   = compact('last_name', 'first_name', 'middle_initial', 'email');
    header('Location: ../../pages/admin-signup.php');
    exit;
}

// Check for duplicate email
$stmt = $conn->prepare('SELECT id FROM admins WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $_SESSION['signup_errors'] = ['This email is already registered.'];
    $_SESSION['signup_form']   = compact('last_name', 'first_name', 'middle_initial', 'email');
    $stmt->close();
    header('Location: ../../pages/admin-signup.php');
    exit;
}
$stmt->close();

$hashed_password = password_hash($password, PASSWORD_BCRYPT);
$otp_code        = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$otp_expires_at  = date('Y-m-d H:i:s', strtotime('+15 minutes'));

// ── ID image: written to a TEMP path only, never to /uploads ──
$tmp_dir  = sys_get_temp_dir();
$ext      = strtolower(pathinfo($_FILES['id_image']['name'], PATHINFO_EXTENSION));
$tmp_path = $tmp_dir . '/idcheck_' . bin2hex(random_bytes(8)) . '.' . $ext;

if (!move_uploaded_file($_FILES['id_image']['tmp_name'], $tmp_path)) {
    $_SESSION['signup_errors'] = ['Failed to process ID image. Please try again.'];
    header('Location: ../../pages/admin-signup.php');
    exit;
}

// ── OCR-based ID verification ──────────────────────────────
$ai_match_status    = 'unreadable';
$ai_extracted_name  = null;
$ai_confidence_note = null;
$quarantine_blob    = null;

if (!defined('PROTOTYPE_MODE') || !PROTOTYPE_MODE) {
    $verifier = new IdVerifier(getenv('VISION_API_KEY'));

    // Read bytes BEFORE verify() runs — verify() deletes the temp
    // file internally. Only held in memory long enough to encrypt
    // if needed, never written back to disk unencrypted.
    $imageBytesForQuarantine = file_get_contents($tmp_path);

    $result = $verifier->verify($tmp_path, $first_name, $last_name);

    $ai_match_status    = $result['status'];
    $ai_extracted_name  = $result['extracted_name'];
    $ai_confidence_note = $result['note'];

    if ($ai_match_status !== 'matched') {
        $quarantine_blob = IdQuarantine::encrypt($imageBytesForQuarantine);
    }
    unset($imageBytesForQuarantine);
} else {
    // Prototype mode — skip OCR, delete the temp file, flag for manual review.
    if (is_file($tmp_path)) {
        @file_put_contents($tmp_path, random_bytes(1024));
        @unlink($tmp_path);
    }
    $ai_confidence_note = 'Prototype mode: AI verification skipped. Manual review required.';
}

// Insert the new admin record.
// is_verified = 0 until OTP confirmed.
// approved_by = NULL until an existing admin approves.
// No id_image column — image was never saved.
$stmt = $conn->prepare("
    INSERT INTO admins
        (last_name, first_name, middle_initial, email, password,
         is_verified, otp_code, otp_expires_at,
         ai_match_status, ai_extracted_name, ai_confidence_note)
    VALUES (?, ?, ?, ?, ?,
            0, ?, ?,
            ?, ?, ?)
");
// 11 placeholders, 11 type chars — no int fields, all strings
$stmt->bind_param(
    'sssssssssss',
    $last_name, $first_name, $middle_initial, $email, $hashed_password,
    $otp_code, $otp_expires_at,
    $ai_match_status, $ai_extracted_name, $ai_confidence_note
);

if (!$stmt->execute()) {
    error_log('[admin-signup] DB error: ' . $stmt->error);
    $_SESSION['signup_errors'] = ['Database error. Please try again later.'];
    $stmt->close();
    header('Location: ../../pages/admin-signup.php');
    exit;
}
$new_admin_id = $stmt->insert_id;
$stmt->close();

// ── Quarantine insert (only runs for mismatched/unreadable) ──
if ($quarantine_blob !== null) {
    $expires_at   = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $account_type = 'admin';

    $qStmt = $conn->prepare("
        INSERT INTO id_review_queue
            (account_type, account_id, encrypted_blob, ai_match_status, ai_extracted_name, ai_confidence_note, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $qStmt->bind_param(
        'sisssss',
        $account_type, $new_admin_id, $quarantine_blob, $ai_match_status, $ai_extracted_name, $ai_confidence_note, $expires_at
    );
    if (!$qStmt->execute()) {
        error_log('[admin-signup] Quarantine insert failed: ' . $qStmt->error);
    }
    $qStmt->close();
}

// Send OTP email
try {
    $mail_sent = sendVerificationEmail($email, $otp_code, $first_name);
    if (!$mail_sent) {
        $_SESSION['email_warning'] = 'We could not send your verification email. Use the Resend button on the next page.';
    }
} catch (\Throwable $e) {
    $_SESSION['email_warning'] = 'Email sending failed. Use the Resend button on the next page.';
    error_log('[admin-signup] Mailer error: ' . $e->getMessage());
}

$_SESSION['pending_verification'] = [
    'email' => $email,
    'role'  => 'admin',
    'name'  => $first_name,
];

header('Location: ../../pages/verify-email.php');
exit;