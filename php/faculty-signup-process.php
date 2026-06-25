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

// ── 8. Check if email already exists ─────────────────────────────────────
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

// ── 8.1 Handle ID image upload ────────────────────────────────────────────
$upload_dir = __DIR__ . '/../uploads/faculty_ids/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$ext          = pathinfo($_FILES['id_image']['name'], PATHINFO_EXTENSION);
$image_filename = 'id_' . bin2hex(random_bytes(8)) . '.' . $ext;
$image_path   = $upload_dir . $image_filename;
$image_db_path = 'uploads/faculty_ids/' . $image_filename;

if (!move_uploaded_file($_FILES['id_image']['tmp_name'], $image_path)) {
    $_SESSION['signup_errors'] = ['Failed to upload ID image. Please try again.'];
    $_SESSION['signup_form']   = compact('last_name', 'first_name', 'middle_initial', 'email');
    header('Location: ../pages/faculty-signup.php');
    exit;
}

// ── 8.2 Call Anthropic AI to read the ID ─────────────────────────────────
$ai_match_status     = 'unreadable';
$ai_extracted_name   = null;
$ai_confidence_note  = null;
$full_name_typed     = strtolower(trim("$first_name $last_name"));

try {
    $image_data    = base64_encode(file_get_contents($image_path));
    $image_mime    = mime_content_type($image_path);

    $ai_payload = [
        'model'      => 'claude-sonnet-4-20250514',
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
                    ]
                ],
                [
                    'type' => 'text',
                    'text' => 'This is a faculty ID image. Extract the full name of the person on the ID. 
                               Reply in JSON only, no markdown, no explanation. 
                               Format: {"extracted_name": "First Last", "readable": true/false, "note": "short note"}'
                ]
            ]
        ]]
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($ai_payload),
    ]);

    $ai_response = curl_exec($ch);
    curl_close($ch);

    $ai_data = json_decode($ai_response, true);
    $ai_text = $ai_data['content'][0]['text'] ?? '{}';
    $ai_result = json_decode($ai_text, true);

    if (!empty($ai_result['readable']) && $ai_result['readable'] === true) {
        $ai_extracted_name  = $ai_result['extracted_name'] ?? null;
        $ai_confidence_note = $ai_result['note'] ?? null;

        // Compare extracted name vs typed name
        $extracted_clean = strtolower(trim($ai_extracted_name ?? ''));
        if (
            $extracted_clean === $full_name_typed ||
            similar_text($extracted_clean, $full_name_typed, $pct) && $pct >= 80
        ) {
            $ai_match_status = 'matched';
        } else {
            $ai_match_status = 'mismatched';
        }
    } else {
        $ai_match_status    = 'unreadable';
        $ai_confidence_note = $ai_result['note'] ?? 'AI could not read the ID clearly.';
    }

} catch (Exception $e) {
    $ai_match_status    = 'unreadable';
    $ai_confidence_note = 'AI processing failed. Manual review required.';
}

// ── 9. Insert new faculty (is_verified = 0, approved_by = NULL) ──────────
//  Flow: is_verified=0 → email confirmed → is_verified=1, approved_by=NULL
//        → admin approves → approved_by=admin_id, approved_at=now
$stmt = $conn->prepare("
    INSERT INTO faculty
        (last_name, first_name, middle_initial, email, password, is_verified, 
         otp_code, otp_expires_at, id_image, ai_match_status, ai_extracted_name, ai_confidence_note)
    VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param(
    'sssssssssss',  // 11 s's not 12
    $last_name, $first_name, $middle_initial,
    $email, $hashed_password,
    $otp_code, $otp_expires_at,
    $image_db_path, $ai_match_status, $ai_extracted_name, $ai_confidence_note
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