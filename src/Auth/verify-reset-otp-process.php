<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$otp   = trim($_POST['otp'] ?? '');
$email = $_SESSION['reset_email'] ?? '';
$role  = $_SESSION['reset_role'] ?? '';
$uid   = $_SESSION['reset_user_id'] ?? 0;

if (!$otp || !$email || !$role || !$uid || !in_array($role, ['faculty', 'admin'])) {
    $_SESSION['reset_error'] = 'Session expired. Please start again.';
    header('Location: ../pages/forgot-password.php');
    exit;
}

require_once __DIR__ . '/../Config/db_connect.php';

$table = $role === 'admin' ? 'admins' : 'faculty';

$stmt = $conn->prepare("SELECT otp_code, otp_expires_at FROM $table WHERE id = ? AND email = ?");
$stmt->bind_param('is', $uid, $email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    $_SESSION['reset_error'] = 'Invalid request.';
    header('Location: ../pages/forgot-password.php');
    exit;
}

if ($row['otp_code'] !== $otp) {
    $_SESSION['reset_error'] = 'Invalid verification code.';
    header('Location: ../pages/verify-reset-otp.php');
    exit;
}

if (strtotime($row['otp_expires_at']) < time()) {
    $_SESSION['reset_error'] = 'Verification code has expired. Please request a new one.';
    header('Location: ../pages/forgot-password.php');
    exit;
}

// OTP valid - set reset allowed flag
$_SESSION['reset_allowed'] = true;
unset($_SESSION['reset_error']);
header('Location: ../pages/reset-password.php');
$conn->close();
exit;
