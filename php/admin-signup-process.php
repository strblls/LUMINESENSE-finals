<?php
session_start();
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pages/admin-signup.php'); exit;
}

$last   = trim(htmlspecialchars($_POST['last_name']      ?? ''));
$first  = trim(htmlspecialchars($_POST['first_name']     ?? ''));
$mi     = trim(htmlspecialchars($_POST['middle_initial'] ?? ''));
$email  = trim(strtolower($_POST['email']               ?? ''));
$code   = trim($_POST['admin_code']                      ?? '');
$pass   = $_POST['password']         ?? '';
$pass2  = $_POST['confirm_password'] ?? '';

$errors = [];
if (!$last)                              $errors[] = 'Last name required.';
if (!$first)                             $errors[] = 'First name required.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Valid email required.';
} elseif (!preg_match('/^[^@]+@luminesense\.edu\.ph$/i', $email)) /*ay wow new knowledj*/{
    $errors[] = 'Admin email must use @luminesense.edu.ph.';
}
if ($code !== '1108')                    $errors[] = 'Invalid admin code.';
if (strlen($pass) < 8)                   $errors[] = 'Password must be at least 8 characters.';
if ($pass !== $pass2)                    $errors[] = 'Passwords do not match.';

if (!empty($errors)) {
    $_SESSION['signup_errors'] = $errors;
    $_SESSION['signup_form']   = compact('last','first','mi','email','code');
    header('Location: ../pages/admin-signup.php'); exit;
}

$stmt = $conn->prepare('SELECT id FROM admins WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $_SESSION['signup_errors'] = ['This email is already registered.'];
    header('Location: ../pages/admin-signup.php');
    $stmt->close(); exit;
}
$stmt->close();

$hash = password_hash($pass, PASSWORD_BCRYPT);
$stmt = $conn->prepare('INSERT INTO admins (last_name, first_name, middle_initial, email, password, is_verified) VALUES (?,?,?,?,?,0)');
if (!$stmt) {
    $_SESSION['signup_errors'] = ['Database error: ' . $conn->error];
    header('Location: ../pages/admin-signup.php'); exit;
}
$stmt->bind_param('sssss', $last, $first, $mi, $email, $hash);
if (!$stmt->execute()) {
    $_SESSION['signup_errors'] = ['Signup failed: ' . $stmt->error];
    $stmt->close();
    header('Location: ../pages/admin-signup.php'); exit;
}
$stmt->close();

$_SESSION['signup_success_modal'] = 'Account created successfully. Please wait for verification from the Information Systems Office before logging in.';
header('Location: ../pages/admin-login.php'); exit;
