<?php
require_once __DIR__ . '/../session_guard.php';
check_admin();
require_once __DIR__ . '/../db_connect.php';

if (empty($_SESSION['otp_change_verified'])) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Please verify your identity first.'];
    header('Location: ../../pages/admin-home/admin-profile-settings.php');
    exit;
}

$admin_id   = $_SESSION['admin_id'];
$new_pw     = $_POST['new_password']       ?? '';
$confirm_pw = $_POST['confirm_password']   ?? '';

$redirect = '../../pages/admin-home/admin-profile-settings.php';

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
$stmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
$stmt->bind_param('si', $hash, $admin_id);
$ok = $stmt->execute();
$stmt->close();
$conn->close();

unset($_SESSION['otp_change_verified']);

if ($ok) {
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Password changed successfully.'];
} else {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Failed to update password.'];
}

header("Location: $redirect");
exit;