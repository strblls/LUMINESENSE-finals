<?php
require_once 'session_guard.php';
check_faculty();
require_once 'db_connect.php';

$faculty_id   = $_SESSION['faculty_id'];
$current_pass = $_POST['current_password'] ?? '';
$new_pass     = $_POST['new_password']     ?? '';
$confirm_pass = $_POST['confirm_password'] ?? '';

if (!$current_pass || !$new_pass || !$confirm_pass) {
    $_SESSION['pw_error'] = 'All fields are required.';
} elseif (strlen($new_pass) < 8) {
    $_SESSION['pw_error'] = 'New password must be at least 8 characters.';
} elseif ($new_pass !== $confirm_pass) {
    $_SESSION['pw_error'] = 'New passwords do not match.';
} else {
    $stmt = $conn->prepare('SELECT password FROM faculty WHERE id = ?');
    $stmt->bind_param('i', $faculty_id);
    $stmt->execute();
    $stmt->bind_result($hashed);
    $stmt->fetch();
    $stmt->close();

    if (!password_verify($current_pass, $hashed)) {
        $_SESSION['pw_error'] = 'Current password is incorrect.';
    } else {
        $new_hash = password_hash($new_pass, PASSWORD_BCRYPT);
        $stmt = $conn->prepare('UPDATE faculty SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $new_hash, $faculty_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['pw_success'] = 'Password changed successfully!';
    }
}

$conn->close();
header('Location: ../pages/faculty-home/faculty-profile-settings.php');
exit;