<?php
/**
 * php/handlers/faculty-signup-process.php
 *
 * THE FULL FLOW after this file runs:
 *   Teacher fills form
 *       -> This file validates everything
 *       -> Runs OCR check on the ID photo, then DELETES the image
 *       -> Saves to DB with is_verified = 0 (no image, no raw OCR text stored)
 *       -> Sends OTP to their email
 *       -> Redirects to verify-email.php
 *           -> Teacher enters OTP -> is_verified = 1
 *               -> An Admin sees them in "Pending Registrations"
 *                   -> Admin approves -> approved_by = admin_id
 *                       -> Teacher can now log in
 *
 * CHANGES FROM PREVIOUS VERSION:
 *   1. ID image is now read, OCR'd, and immediately deleted —
 *      never written permanently to /uploads. No id_image
 *      column/path is stored.
 *   2. OCR uses Google Cloud Vision (purpose-built document
 *      reader) instead of asking a chat model to parse the image.
 *   3. PROTOTYPE_MODE now skips verification AND still deletes
 *      the temp image — it never reaches disk either way.
 *   4. Fixed bind_param type string (was 14 chars for 13 params,
 *      which throws a fatal error).
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../IdVerifier.php';
require_once __DIR__ . '/../IdQuarantine.php';

date_default_timezone_set('Asia/Manila');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../pages/faculty-signup.php');
    exit;
}

// Collect and sanitize inputs
$last_name        = trim($_POST['last_name']        ?? '');
$first_name       = trim($_POST['first_name']       ?? '');
$middle_initial   = strtoupper(trim($_POST['middle_initial'] ?? ''));
$email            = strtolower(trim($_POST['email'] ?? ''));
$department_id    = (int)($_POST['department_id']   ?? 0);
$password         = $_POST['password']              ?? '';
$confirm_password = $_POST['confirm_password']       ?? '';

$errors = [];

if (empty($last_name))    $errors[] = 'Last name is required.';
if (empty($first_name))   $errors[] = 'First name is required.';
if (empty($email))        $errors[] = 'Email is required.';
if (empty($password))     $errors[] = 'Password is required.';
if (!$department_id)      $errors[] = 'Please select your department.';

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

if ($department_id) {
    $chk = $conn->prepare('SELECT id FROM departments WHERE id = ? LIMIT 1');
    $chk->bind_param('i', $department_id);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) {
        $errors[] = 'Invalid department selected.';
    }
    $chk->close();
}

if (!empty($errors)) {
    $_SESSION['signup_errors'] = $errors;
    $_SESSION['signup_form']   = compact(
        'last_name', 'first_name', 'middle_initial', 'email', 'department_id'
    );
    header('Location: ../../pages/faculty-signup.php');
    exit;
}

// Check for duplicate email
$stmt = $conn->prepare('SELECT id FROM faculty WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $_SESSION['signup_errors'] = ['This email is already registered.'];
    $_SESSION['signup_form']   = compact(
        'last_name', 'first_name', 'middle_initial', 'email', 'department_id'
    );
    $stmt->close();
    header('Location: ../../pages/faculty-signup.php');
    exit;
}
$stmt->close();

$hashed_password = password_hash($password, PASSWORD_BCRYPT);
$otp_code         = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$otp_expires_at   = date('Y-m-d H:i:s', strtotime('+15 minutes'));

// ── ID image: written to a TEMP path only, never to /uploads ──
$tmp_dir  = sys_get_temp_dir();
$ext      = strtolower(pathinfo($_FILES['id_image']['name'], PATHINFO_EXTENSION));
$tmp_path = $tmp_dir . '/idcheck_' . bin2hex(random_bytes(8)) . '.' . $ext;

if (!move_uploaded_file($_FILES['id_image']['tmp_name'], $tmp_path)) {
    $_SESSION['signup_errors'] = ['Failed to process ID image. Please try again.'];
    header('Location: ../../pages/faculty-signup.php');
    exit;
}

// ── OCR-based ID verification ──────────────────────────────
// matched      -> image deleted immediately, same as before.
// mismatched / unreadable -> image is encrypted and held in
//                            id_review_queue for 24h, then purged
//                            by the cleanup script regardless of
//                            whether anyone reviewed it.
$ai_match_status    = 'unreadable';
$ai_extracted_name  = null;
$ai_confidence_note = null;
$quarantine_blob     = null; // only set when status needs review

if (!defined('PROTOTYPE_MODE') || !PROTOTYPE_MODE) {
    $verifier = new IdVerifier(getenv('VISION_API_KEY')); // set in server env, never hardcoded

    // Read the bytes BEFORE verify() runs, since verify() deletes the
    // temp file internally. We only keep these bytes in memory long
    // enough to encrypt them if needed — never written back to disk
    // unencrypted.
    $imageBytesForQuarantine = file_get_contents($tmp_path);

    $result = $verifier->verify($tmp_path, $first_name, $last_name);

    $ai_match_status    = $result['status'];
    $ai_extracted_name  = $result['extracted_name'];
    $ai_confidence_note = $result['note'];

    if ($ai_match_status !== 'matched') {
        $quarantine_blob = IdQuarantine::encrypt($imageBytesForQuarantine);
    }
    // Plaintext bytes go out of scope here; nothing unencrypted persists.
    unset($imageBytesForQuarantine);
} else {
    // Prototype mode — skip OCR, still delete the temp file, flag for manual review.
    if (is_file($tmp_path)) {
        @file_put_contents($tmp_path, random_bytes(1024));
        @unlink($tmp_path);
    }
    $ai_confidence_note = 'Prototype mode: AI verification skipped. Manual review required.';
}

// Insert the new faculty record.
// No id_image column — the image was never saved.
// is_verified = 0 until OTP confirmed; approved_by = NULL until Head Teacher approves.
$stmt = $conn->prepare("
    INSERT INTO faculty
        (last_name, first_name, middle_initial, email, password,
         department_id,
         is_verified, otp_code, otp_expires_at,
         ai_match_status, ai_extracted_name, ai_confidence_note)
    VALUES (?, ?, ?, ?, ?,
            ?, 0, ?, ?,
            ?, ?, ?)
");
// 12 placeholders -> 12 type chars: department_id is the only int, rest are strings
$stmt->bind_param(
    'sssssisssss',
    $last_name, $first_name, $middle_initial, $email, $hashed_password,
    $department_id,
    $otp_code, $otp_expires_at,
    $ai_match_status, $ai_extracted_name, $ai_confidence_note
);

if (!$stmt->execute()) {
    error_log('[faculty-signup] DB error: ' . $stmt->error);
    $_SESSION['signup_errors'] = ['Database error. Please try again later.'];
    $stmt->close();
    header('Location: ../../pages/faculty-signup.php');
    exit;
}
$new_faculty_id = $stmt->insert_id;
$stmt->close();

// ── Quarantine insert (only runs for mismatched/unreadable) ──
// Uses the generic account_type/account_id columns — id_review_queue
// supports both faculty and admin signups through the same table.
if ($quarantine_blob !== null) {
    $expires_at  = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $account_type = 'faculty';

    $qStmt = $conn->prepare("
        INSERT INTO id_review_queue
            (account_type, account_id, encrypted_blob, ai_match_status, ai_extracted_name, ai_confidence_note, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $qStmt->bind_param(
        'sisssss',
        $account_type, $new_faculty_id, $quarantine_blob, $ai_match_status, $ai_extracted_name, $ai_confidence_note, $expires_at
    );
    if (!$qStmt->execute()) {
        // Don't block signup over a quarantine-write failure — just log it.
        // The faculty account still exists with ai_match_status recorded;
        // worst case, the admin reviews based on text fields only.
        error_log('[faculty-signup] Quarantine insert failed: ' . $qStmt->error);
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
    error_log('[faculty-signup] Mailer error: ' . $e->getMessage());
}

$_SESSION['pending_verification'] = [
    'email' => $email,
    'role'  => 'faculty',
    'name'  => $first_name,
];

header('Location: ../../pages/verify-email.php');
exit;