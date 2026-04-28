<?php
// ============================================================
//  admin-login.php
//  LumineSense – Administrator Login
//
//  Same logic as faculty-login.php but:
//  - Looks in "admins" table
//  - Sets role = 'admin' in the session
//  - Redirects to admin dashboard
// ============================================================

session_start();
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pages/admin-login.php');
    exit;
}

// ── 1. Collect inputs ─────────────────────────────────────────
$email    = trim(strtolower($_POST['email']    ?? ''));
$password = $_POST['password'] ?? '';

// ── 2. Basic validation ───────────────────────────────────────
if (empty($email) || empty($password)) {
    $_SESSION['login_error'] = "Please enter your email and password.";
    header('Location: ../pages/admin-login.php');
    exit;
}

// ── 3. Rate limiting – 3 attempts in 15 minutes ───────────────
if (!isset($_SESSION['admin_attempt_count'])) {
    $_SESSION['admin_attempt_count'] = 0;
    $_SESSION['admin_attempt_time']  = time();
}

$fifteen_minutes = 15 * 60;
if (time() - $_SESSION['admin_attempt_time'] > $fifteen_minutes) {
    $_SESSION['admin_attempt_count'] = 0;
    $_SESSION['admin_attempt_time']  = time();
}

if ($_SESSION['admin_attempt_count'] >= 3) {
    $wait_seconds = $fifteen_minutes - (time() - $_SESSION['admin_attempt_time']);
    $wait_minutes = ceil($wait_seconds / 60);
    $_SESSION['login_error'] = "Too many failed attempts. Please try again in {$wait_minutes} minute(s).";
    header('Location: ../pages/admin-login.php');
    exit;
}

// ── 4. Look up the admin ──────────────────────────────────────
$stmt = $conn->prepare("SELECT id, last_name, first_name, password, is_verified FROM admins WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['admin_attempt_count']++;
    $_SESSION['login_error'] = "Invalid email or password.";
    header('Location: ../pages/admin-login.php');
    $stmt->close();
    exit;
}

$admin = $result->fetch_assoc();
$stmt->close();

// ── 5. Check the password ─────────────────────────────────────
if (!password_verify($password, $admin['password'])) {
    $_SESSION['admin_attempt_count']++;
    $_SESSION['login_error'] = "Invalid email or password.";
    header('Location: ../pages/admin-login.php');
    exit;
}

// ── 6. Check if verified by Information Systems Office ────────
if ($admin['is_verified'] == 0) {
    $_SESSION['login_error'] = "Your administrator account is pending verification from the Information Systems Office.";
    header('Location: ../pages/admin-login.php');
    exit;
}

// ── 7. Start session ──────────────────────────────────────────
session_regenerate_id(true);

$_SESSION['admin_id']        = $admin['id'];
$_SESSION['admin_name']      = $admin['first_name'] . ' ' . $admin['last_name'];
$_SESSION['admin_logged_in'] = true;
$_SESSION['role']            = 'admin';

$_SESSION['admin_attempt_count'] = 0;

header('Location: ../pages/admin-home/admin-homepage.html');
exit;
?>
