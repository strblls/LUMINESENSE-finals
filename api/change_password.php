<?php
/**
 * php/handlers/change-password.php
 * Handles password change for admin.
 * Place at: php/handlers/change-password.php
 */
require_once __DIR__ . '/../session_guard.php';
check_admin();
require_once __DIR__ . '/../db_connect.php';

$admin_id       = $_SESSION['admin_id'];
$current_pw     = $_POST['current_password']  ?? '';
$new_pw         = $_POST['new_password']       ?? '';
$confirm_pw     = $_POST['confirm_password']   ?? '';

$redirect = '../../admin/pages/admin-profile-settings.php';

if (!$current_pw || !$new_pw || !$confirm_pw) {
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

// Fetch stored hash
$stmt = $conn->prepare("SELECT password FROM admins WHERE id = ?");
$stmt->bind_param('i', $admin_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || !password_verify($current_pw, $row['password'])) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Current password is incorrect.'];
    header("Location: $redirect"); exit;
}

$hash = password_hash($new_pw, PASSWORD_DEFAULT);
$stmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
$stmt->bind_param('si', $hash, $admin_id);
$ok = $stmt->execute();
$stmt->close();
$conn->close();

if ($ok) {
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Password changed successfully.'];
} else {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Failed to update password.'];
}

header("Location: $redirect");
exit;