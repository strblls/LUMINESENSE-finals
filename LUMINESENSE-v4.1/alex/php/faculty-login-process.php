<?php
session_start();
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pages/faculty-login-page.php'); exit;
}

$email    = trim(strtolower($_POST['email'] ?? ''));
$password = $_POST['password'] ?? '';

if (!$email || !$password) {
    $_SESSION['login_error'] = 'Please enter your email and password.';
    header('Location: pages/faculty-login-page.php'); exit;
}

$_SESSION['faculty_attempts']     = $_SESSION['faculty_attempts']     ?? 0;
$_SESSION['faculty_attempt_time'] = $_SESSION['faculty_attempt_time'] ?? time();
if (time() - $_SESSION['faculty_attempt_time'] > 900) {
    $_SESSION['faculty_attempts']     = 0;
    $_SESSION['faculty_attempt_time'] = time();
}
if ($_SESSION['faculty_attempts'] >= 3) {
    $wait = ceil((900 - (time() - $_SESSION['faculty_attempt_time'])) / 60);
    $_SESSION['login_error'] = "Too many attempts. Wait {$wait} minute(s).";
    header('Location: pages/faculty-login-page.php'); exit;
}

$stmt = $conn->prepare('SELECT id, first_name, last_name, password, is_verified FROM faculty WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || !password_verify($password, $row['password'])) {
    $_SESSION['faculty_attempts']++;
    $_SESSION['login_error'] = 'Invalid email or password.';
    header('Location: pages/faculty-login-page.php'); exit;
}

if (!$row['is_verified']) {
    $_SESSION['login_error'] = 'Your account is pending approval from an Administrator.';
    header('Location: pages/faculty-login-page.php'); exit;
}

session_regenerate_id(true);
$_SESSION['faculty_id']        = $row['id'];
$_SESSION['faculty_name']      = $row['first_name'] . ' ' . $row['last_name'];
$_SESSION['faculty_logged_in'] = true;
$_SESSION['role']              = 'faculty';
$_SESSION['faculty_attempts']  = 0;

header('Location: pages/faculty-home/faculty-homepage.php'); exit;
