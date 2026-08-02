<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../Config/db_connect.php';
require_once __DIR__ . '/../Services/mailer.php';

$email = trim($_POST['email'] ?? '');
$role  = $_POST['role'] ?? '';

if (!$email || !$role || !in_array($role, ['faculty', 'admin'])) {
    $_SESSION['reset_error'] = 'Invalid request.';
    header('Location: ../pages/forgot-password.php');
    exit;
}

// Check which table
$table = $role === 'admin' ? 'admins' : 'faculty';
$nameCol = $role === 'admin' ? "CONCAT(first_name, ' ', last_name)" : "CONCAT(first_name, ' ', last_name)";

$stmt = $conn->prepare("SELECT id, first_name, last_name, email FROM $table WHERE email = ?");
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    $_SESSION['reset_error'] = 'No account found with that email address.';
    header('Location: ../pages/forgot-password.php');
    exit;
}

// Generate OTP
$otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

$upd = $conn->prepare("UPDATE $table SET otp_code = ?, otp_expires_at = ? WHERE id = ?");
$upd->bind_param('ssi', $otp, $expires, $user['id']);
$upd->execute();
$upd->close();

$fullName = $user['first_name'] . ' ' . $user['last_name'];
$sent = sendResetOTPEmail($email, $otp, $fullName);

if ($sent) {
    $_SESSION['reset_email'] = $email;
    $_SESSION['reset_role']  = $role;
    $_SESSION['reset_user_id'] = (int)$user['id'];
    header('Location: ../pages/verify-reset-otp.php');
} else {
    $_SESSION['reset_error'] = 'Failed to send email. Please try again.';
    header('Location: ../pages/forgot-password.php');
}
$conn->close();
exit;
