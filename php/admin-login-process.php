<?php
session_start();
require_once 'db_connect.php';

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

$stmt = $conn->prepare('SELECT id, first_name, last_name, password FROM admins WHERE email = ?');
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

session_regenerate_id(true);
$_SESSION['admin_id']        = $row['id'];
$_SESSION['admin_name']      = $row['first_name'] . ' ' . $row['last_name'];
$_SESSION['admin_logged_in'] = true;
$_SESSION['role']            = 'admin';
$_SESSION['admin_attempts']  = 0;

header('Location: ../pages/admin-home/admin-homepage.php');
