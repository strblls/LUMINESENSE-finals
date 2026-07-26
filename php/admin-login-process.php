<?php
session_start();
require_once __DIR__ . '/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pages/admin-login.php');
    exit;
}

$email    = trim(strtolower($_POST['email'] ?? ''));
$password = $_POST['password'] ?? '';

if (!$email || !$password) {
    $_SESSION['login_error'] = 'Please enter your email and password.';
    header('Location: ../pages/admin-login.php');
    exit;
}

// Rate limiting
$_SESSION['admin_attempts'] = $_SESSION['admin_attempts'] ?? 0;
$_SESSION['admin_attempt_time'] = $_SESSION['admin_attempt_time'] ?? time();
if (time() - $_SESSION['admin_attempt_time'] > 900) {
    $_SESSION['admin_attempts'] = 0;
    $_SESSION['admin_attempt_time'] = time();
}
if ($_SESSION['admin_attempts'] >= 3) {
    $wait = ceil((900 - (time() - $_SESSION['admin_attempt_time'])) / 60);
    $_SESSION['login_error'] = "Too many attempts. Wait {$wait} minute(s).";
    header('Location: ../pages/admin-login.php');
    exit;
}

$stmt = @$conn->prepare('SELECT id, first_name, last_name, password, is_verified, approved_by, must_change_password FROM admins WHERE email = ?');
if (!$stmt) {
    $_SESSION['login_error'] = 'Database error. Please try again.';
    header('Location: ../pages/admin-login.php');
    exit;
}
$stmt->bind_param('s', $email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    $_SESSION['admin_attempts']++;
    $_SESSION['login_error'] = 'No account found with this email address.';
    header('Location: ../pages/admin-login.php');
    exit;
}

if (!password_verify($password, $row['password'])) {
    $_SESSION['admin_attempts']++;
    $_SESSION['login_error'] = 'Incorrect password.';
    header('Location: ../pages/admin-login.php');
    exit;
}

if (!$row['is_verified']) {
    $_SESSION['login_error'] = 'Please verify your email before logging in.';
    header('Location: ../pages/admin-login.php');
    exit;
}

if (!$row['approved_by']) {
    $_SESSION['login_error'] = 'Your account is pending approval from an existing administrator. Please wait for approval.';
    header('Location: ../pages/admin-login.php');
    exit;
}

session_regenerate_id(true);
$_SESSION['admin_id']        = $row['id'];
$_SESSION['admin_name']      = $row['first_name'] . ' ' . $row['last_name'];
$_SESSION['admin_logged_in'] = true;
$_SESSION['role']            = 'admin';
$_SESSION['admin_attempts']  = 0;

// If the seeded admin must change password, set a flash and let admin-head.php handle redirect
if (!empty($row['must_change_password']) && $row['must_change_password'] === '1') {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'You must change your password before continuing.'];
}

// Log admin login (gracefully skip if table doesn't exist)
$stmt = @$conn->prepare('INSERT INTO admin_login_logs (admin_id) VALUES (?)');
if ($stmt) {
    $stmt->bind_param('i', $_SESSION['admin_id']);
    $stmt->execute();
    $stmt->close();
}

header('Location: ../pages/admin-home/admin-homepage.php');
exit;