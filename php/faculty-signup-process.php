<?php
/**
 * php/handlers/faculty-signup-process.php
 *
 * Handles new Teacher registration.
 *
 * THE FULL FLOW after this file runs:
 *   Teacher fills form
 *       -> This file validates everything
 *       -> Saves to DB with is_verified = 0
 *       -> Sends OTP to their email
 *       -> Redirects to verify-email.php
 *           -> Teacher enters OTP -> is_verified = 1
 *               -> Head Teacher sees them in "Pending Registrations"
 *                   -> Head Teacher approves -> approved_by = admin_id
 *                       -> Teacher can now log in
 *
 * PROTOTYPE NOTE on AI ID verification:
 * The AI check (Anthropic API call) is wrapped in a PROTOTYPE_MODE flag.
 * During your panel demo, set PROTOTYPE_MODE = true in config.php to
 * skip the AI call entirely. This avoids failures if there is no internet.
 * The id_image is still saved to disk — only the AI analysis is skipped.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../mailer.php';

date_default_timezone_set('Asia/Manila');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../pages/faculty-signup.php');
    exit;
}

// Collect and sanitize inputs
$last_name       = trim($_POST['last_name']        ?? '');
$first_name      = trim($_POST['first_name']       ?? '');
$middle_initial  = strtoupper(trim($_POST['middle_initial'] ?? ''));
$email           = strtolower(trim($_POST['email'] ?? ''));
$employee_id     = trim($_POST['employee_id']      ?? '');
$department_id   = (int)($_POST['department_id']   ?? 0);
$password        = $_POST['password']              ?? '';
$confirm_password = $_POST['confirm_password']     ?? '';

$errors = [];

// Basic field checks
if (empty($last_name))    $errors[] = 'Last name is required.';
if (empty($first_name))   $errors[] = 'First name is required.';
if (empty($email))        $errors[] = 'Email is required.';
if (empty($password))     $errors[] = 'Password is required.';
if (!$department_id)      $errors[] = 'Please select your department.';

// Gmail only
if (!empty($email) && !preg_match('/@gmail\.com$/i', $email)) {
    $errors[] = 'Only @gmail.com addresses are accepted.';
}

// Password rules
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

// Validate that the selected department actually exists
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

// If any errors, go back to the form
if (!empty($errors)) {
    $_SESSION['signup_errors'] = $errors;
    $_SESSION['signup_form']   = compact(
        'last_name', 'first_name', 'middle_initial', 'email', 'employee_id', 'department_id'
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
        'last_name', 'first_name', 'middle_initial', 'email', 'employee_id', 'department_id'
    );
    $stmt->close();
    header('Location: ../../pages/faculty-signup.php');
    exit;
}
$stmt->close();

// Hash password and generate OTP
$hashed_password = password_hash($password, PASSWORD_BCRYPT);
$otp_code        = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$otp_expires_at  = date('Y-m-d H:i:s', strtotime('+15 minutes'));

// Save the ID image to disk
$upload_dir = __DIR__ . '/../../uploads/faculty_ids/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$ext            = pathinfo($_FILES['id_image']['name'], PATHINFO_EXTENSION);
$image_filename = 'id_' . bin2hex(random_bytes(8)) . '.' . strtolower($ext);
$image_path     = $upload_dir . $image_filename;
$image_db_path  = 'uploads/faculty_ids/' . $image_filename;

if (!move_uploaded_file($_FILES['id_image']['tmp_name'], $image_path)) {
    $_SESSION['signup_errors'] = ['Failed to upload ID image. Please try again.'];
    header('Location: ../../pages/faculty-signup.php');
    exit;
}

// AI ID verification
// In PROTOTYPE_MODE this is skipped entirely to avoid API dependency during the demo.
$ai_match_status    = 'unreadable';
$ai_extracted_name  = null;
$ai_confidence_note = null;

if (!defined('PROTOTYPE_MODE') || !PROTOTYPE_MODE) {
    try {
        $image_data = base64_encode(file_get_contents($image_path));
        $image_mime = mime_content_type($image_path);
        $full_name  = strtolower(trim("$first_name $last_name"));

        $ai_payload = [
            'model'      => 'claude-sonnet-4-6',
            'max_tokens' => 300,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    [
                        'type'   => 'image',
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => $image_mime,
                            'data'       => $image_data,
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'This is a faculty ID image from a Philippine school. '
                                . 'Extract the full name printed on the ID. '
                                . 'Reply in JSON only, no markdown, no explanation. '
                                . 'Format: {"extracted_name":"First Last","readable":true,"note":"short note"}',
                    ],
                ],
            ]],
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS     => json_encode($ai_payload),
            CURLOPT_TIMEOUT        => 15,
        ]);

        $ai_response = curl_exec($ch);
        curl_close($ch);

        $ai_data   = json_decode($ai_response, true);
        $ai_text   = $ai_data['content'][0]['text'] ?? '{}';
        $ai_result = json_decode($ai_text, true);

        if (!empty($ai_result['readable'])) {
            $ai_extracted_name  = $ai_result['extracted_name'] ?? null;
            $ai_confidence_note = $ai_result['note']           ?? null;
            $extracted_clean    = strtolower(trim($ai_extracted_name ?? ''));

            similar_text($extracted_clean, $full_name, $pct);
            $ai_match_status = ($extracted_clean === $full_name || $pct >= 80)
                ? 'matched'
                : 'mismatched';
        } else {
            $ai_match_status    = 'unreadable';
            $ai_confidence_note = $ai_result['note'] ?? 'AI could not read the ID clearly.';
        }
    } catch (\Throwable $e) {
        $ai_match_status    = 'unreadable';
        $ai_confidence_note = 'AI processing failed. Manual review required.';
        error_log('[faculty-signup] AI error: ' . $e->getMessage());
    }
} else {
    // Prototype mode — skip AI, flag for manual review
    $ai_confidence_note = 'Prototype mode: AI verification skipped. Manual review required.';
}

// Insert the new faculty record
// is_verified = 0 until they confirm their email
// approved_by = NULL until the Head Teacher approves them
$stmt = $conn->prepare("
    INSERT INTO faculty
        (last_name, first_name, middle_initial, email, password,
         employee_id, department_id,
         is_verified, otp_code, otp_expires_at,
         id_image, ai_match_status, ai_extracted_name, ai_confidence_note)
    VALUES (?, ?, ?, ?, ?,
            ?, ?,
            0, ?, ?,
            ?, ?, ?, ?)
");
$stmt->bind_param(
    'ssssssiissss s',
    $last_name, $first_name, $middle_initial, $email, $hashed_password,
    $employee_id, $department_id,
    $otp_code, $otp_expires_at,
    $image_db_path, $ai_match_status, $ai_extracted_name, $ai_confidence_note
);

if (!$stmt->execute()) {
    error_log('[faculty-signup] DB error: ' . $stmt->error);
    $_SESSION['signup_errors'] = ['Database error. Please try again later.'];
    $stmt->close();
    header('Location: ../../pages/faculty-signup.php');
    exit;
}
$stmt->close();

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

// Pass info to the verify-email page via session
$_SESSION['pending_verification'] = [
    'email' => $email,
    'role'  => 'faculty',
    'name'  => $first_name,
];

header('Location: ../../pages/verify-email.php');
exit;