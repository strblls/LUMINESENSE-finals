<?php
require_once __DIR__ . "/../src/Session/session_guard.php";
require_once __DIR__ . "/../src/Config/db_connect.php";
require_once __DIR__ . "/../src/Services/mailer.php";
header('Content-Type: application/json');

$isAdmin = !empty($_SESSION['admin_logged_in']);
$isFaculty = !empty($_SESSION['faculty_logged_in']);

if (!$isAdmin && !$isFaculty) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$table = $isAdmin ? 'admins' : 'faculty';
$id    = $isAdmin ? (int)$_SESSION['admin_id'] : (int)$_SESSION['faculty_id'];
$name  = $isAdmin ? ($_SESSION['admin_name'] ?? '') : ($_SESSION['faculty_name'] ?? '');

$stmt = $conn->prepare("SELECT email, first_name, last_name FROM $table WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User not found.']);
    exit;
}

// Check cooldown (60s)
$lastSent = $_SESSION['change_otp_sent_at'] ?? 0;
if (time() - $lastSent < 60) {
    $wait = 60 - (time() - $lastSent);
    echo json_encode(['success' => false, 'message' => "Please wait {$wait}s before requesting another code."]);
    exit;
}

$otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

$upd = $conn->prepare("UPDATE $table SET otp_code = ?, otp_expires_at = ? WHERE id = ?");
$upd->bind_param('ssi', $otp, $expires, $id);
$upd->execute();
$upd->close();

$fullName = $user['first_name'] . ' ' . $user['last_name'];
$sent = sendChangeOTPEmail($user['email'], $otp, $fullName);

if ($sent) {
    $_SESSION['change_otp_sent_at'] = time();
    echo json_encode(['success' => true, 'message' => 'Verification code sent to your email.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to send email. Please try again.']);
}
$conn->close();
