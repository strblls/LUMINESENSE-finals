<?php
session_start();
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pages/faculty-signup.php'); exit;
}

$last  = trim(htmlspecialchars($_POST['last_name']      ?? ''));
$first = trim(htmlspecialchars($_POST['first_name']     ?? ''));
$mi    = trim(htmlspecialchars($_POST['middle_initial'] ?? ''));
$email = trim(strtolower($_POST['email']               ?? ''));
$pass  = $_POST['password']         ?? '';
$pass2 = $_POST['confirm_password'] ?? '';

$errors = [];
if (!$last)                              $errors[] = 'Last name required.';
if (!$first)                             $errors[] = 'First name required.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';
if (strlen($pass) < 8)                   $errors[] = 'Password must be at least 8 characters.';
if ($pass !== $pass2)                    $errors[] = 'Passwords do not match.';

if (!empty($errors)) {
    $_SESSION['signup_errors'] = $errors;
    $_SESSION['signup_form']   = compact('last','first','mi','email');
    header('Location: ../pages/faculty-signup.php'); exit;
}

$stmt = $conn->prepare('SELECT id FROM faculty WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $_SESSION['signup_errors'] = ['This email is already registered.'];
    header('Location: ../pages/faculty-signup.php');
    $stmt->close(); exit;
}
$stmt->close();

$hash = password_hash($pass, PASSWORD_BCRYPT);
$stmt = $conn->prepare('INSERT INTO faculty (last_name, first_name, middle_initial, email, password, is_verified) VALUES (?,?,?,?,?,0)');
$stmt->bind_param('sssss', $last, $first, $mi, $email, $hash);
$stmt->execute();
$stmt->close();

$_SESSION['signup_success'] = 'Account created! Wait for an Administrator to approve it before logging in.';
header('Location: ../pages/faculty-login.php'); exit;
