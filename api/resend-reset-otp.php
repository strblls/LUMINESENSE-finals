<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

$email = $_SESSION['reset_email'] ?? '';
$role  = $_SESSION['reset_role'] ?? '';
$uid   = $_SESSION['reset_user_id'] ?? 0;

if (!$email || !$role || !$uid || !in_array($role, ['faculty', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired.']);
    exit;
}

// Cooldown
$lastSent = $_SESSION['reset_otp_sent_at'] ?? 0;
if (time() - $lastSent < 60) {
    $wait = 60 - (time() - $lastSent);
    echo json_encode(['success' => false, 'message' => "Please wait {$wait}s."]);
    exit;
}

require_once __DIR__ . "/../src/Config/db_connect.php";
require_once __DIR__ . "/../src/Services/mailer.php";

$table = $role === 'admin' ? 'admins' : 'faculty';

$stmt = $conn->prepare("SELECT first_name, last_name, email FROM $table WHERE id = ?");
$stmt->bind_param('i', $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User not found.']);
    exit;
}

$otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

$upd = $conn->prepare("UPDATE $table SET otp_code = ?, otp_expires_at = ? WHERE id = ?");
$upd->bind_param('ssi', $otp, $expires, $uid);
$upd->execute();
$upd->close();

$fullName = $user['first_name'] . ' ' . $user['last_name'];
$sent = sendResetOTPEmail($email, $otp, $fullName);

if ($sent) {
    $_SESSION['reset_otp_sent_at'] = time();
    echo json_encode(['success' => true, 'message' => 'Code resent.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to send email.']);
}
$conn->close();
