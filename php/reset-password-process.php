<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['reset_allowed']) || empty($_SESSION['reset_email']) || empty($_SESSION['reset_role']) || empty($_SESSION['reset_user_id'])) {
    $_SESSION['reset_error'] = 'Session expired. Please start again.';
    header('Location: ../pages/forgot-password.php');
    exit;
}

$newPass     = $_POST['new_password'] ?? '';
$confirmPass = $_POST['confirm_password'] ?? '';
$role        = $_SESSION['reset_role'];
$uid         = (int)$_SESSION['reset_user_id'];

if (strlen($newPass) < 8) {
    $_SESSION['reset_error'] = 'Password must be at least 8 characters.';
    header('Location: ../pages/reset-password.php');
    exit;
}

if ($newPass !== $confirmPass) {
    $_SESSION['reset_error'] = 'Passwords do not match.';
    header('Location: ../pages/reset-password.php');
    exit;
}

require_once __DIR__ . '/db_connect.php';

$table = $role === 'admin' ? 'admins' : 'faculty';
$hash  = password_hash($newPass, PASSWORD_BCRYPT);

$stmt = $conn->prepare("UPDATE $table SET password = ?, otp_code = NULL, otp_expires_at = NULL WHERE id = ?");
$stmt->bind_param('si', $hash, $uid);
$stmt->execute();
$stmt->close();
$conn->close();

// Clear all reset session vars
unset($_SESSION['reset_allowed'], $_SESSION['reset_email'], $_SESSION['reset_role'], $_SESSION['reset_user_id'], $_SESSION['reset_error']);

$loginPage = $role === 'admin' ? 'admin-login.php' : 'faculty-login.php';
$_SESSION['login_success'] = 'Password reset successfully! You can now log in.';
header("Location: ../pages/$loginPage");
exit;
