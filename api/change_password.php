<?php
require_once __DIR__ . '/../session_guard.php';
check_admin();
require_once __DIR__ . '/../db_connect.php';

$force = ($_GET['force'] ?? '') === '1';

// Forced changes skip OTP; normal changes require OTP verification
if (!$force && empty($_SESSION['otp_change_verified'])) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Please verify your identity first.'];
    header('Location: ../../pages/admin-home/admin-profile-settings.php');
    exit;
}

// For forced changes, verify that the admin actually needs to change password
if ($force) {
    $chk = $conn->query("SELECT must_change_password FROM admins WHERE id = " . (int)$_SESSION['admin_id']);
    if (!$chk || !($r2 = $chk->fetch_assoc()) || $r2['must_change_password'] !== '1') {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Password change not required.'];
        header('Location: ../../pages/admin-home/admin-profile-settings.php');
        exit;
    }
}

$admin_id   = $_SESSION['admin_id'];
$new_pw     = $_POST['new_password']       ?? '';
$confirm_pw = $_POST['confirm_password']   ?? '';

$redirect = $force
    ? '../../pages/admin-home/admin-homepage.php'
    : '../../pages/admin-home/admin-profile-settings.php';

if (!$new_pw || !$confirm_pw) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'All password fields are required.'];
    header("Location: $redirect"); exit;
}

if ($new_pw !== $confirm_pw) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'New passwords do not match.'];
    header("Location: $redirect"); exit;
}

if (strlen($new_pw) < 8) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Password must be at least 8 characters.'];
    header("Location: $redirect"); exit;
}

$hash = password_hash($new_pw, PASSWORD_DEFAULT);
$stmt = $conn->prepare("UPDATE admins SET password = ?, must_change_password = '0' WHERE id = ?");
$stmt->bind_param('si', $hash, $admin_id);
$ok = $stmt->execute();
$stmt->close();
$conn->close();

unset($_SESSION['otp_change_verified']);

if ($ok) {
    $_SESSION['flash'] = ['type' => 'success', 'msg' => $force ? 'Password set successfully. Welcome to LumineSense!' : 'Password changed successfully.'];
} else {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Failed to update password.'];
}

header("Location: $redirect");
exit;