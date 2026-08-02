<?php
require_once __DIR__ . '/../Session/session_guard.php';
check_faculty();
require_once __DIR__ . '/../Config/db_connect.php';

if (empty($_SESSION['otp_change_verified'])) {
    $_SESSION['pw_error'] = 'Please verify your identity first.';
    header('Location: ../pages/faculty-home/faculty-profile-settings.php');
    exit;
}

$faculty_id   = $_SESSION['faculty_id'];
$new_pass     = $_POST['new_password']     ?? '';
$confirm_pass = $_POST['confirm_password'] ?? '';

if (!$new_pass || !$confirm_pass) {
    $_SESSION['pw_error'] = 'All fields are required.';
} elseif (strlen($new_pass) < 8) {
    $_SESSION['pw_error'] = 'New password must be at least 8 characters.';
} elseif ($new_pass !== $confirm_pass) {
    $_SESSION['pw_error'] = 'New passwords do not match.';
} else {
    $new_hash = password_hash($new_pass, PASSWORD_BCRYPT);
    $stmt = $conn->prepare('UPDATE faculty SET password = ? WHERE id = ?');
    $stmt->bind_param('si', $new_hash, $faculty_id);
    $stmt->execute();
    $stmt->close();
    $_SESSION['pw_success'] = 'Password changed successfully!';
}

unset($_SESSION['otp_change_verified']);
$conn->close();
header('Location: ../pages/faculty-home/faculty-profile-settings.php');
exit;