<?php
// ============================================================
//  faculty-login.php
//  LumineSense – Faculty Member Login
//
//  What this file does:
//  1. Receives email + password from the login form.
//  2. Looks up the email in the "faculty" table.
//  3. Checks the password matches the stored hash.
//  4. Checks is_verified = 1 (Admin has approved this account).
//  5. Blocks the account after 3 wrong attempts in 15 minutes
//     (as specified in the capstone document).
//  6. If everything passes, starts a session and goes to the
//     faculty dashboard.
// ============================================================

session_start();
require_once 'db_connect.php';

// ── Only accept POST requests ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pages/faculty-login.php');
    exit;
}

// ── 1. Collect inputs ─────────────────────────────────────────
$email    = trim(strtolower($_POST['email']    ?? ''));
$password = $_POST['password'] ?? '';

// ── 2. Basic validation ───────────────────────────────────────
if (empty($email) || empty($password)) {
    $_SESSION['login_error'] = "Please enter your email and password.";
    header('Location: ../pages/faculty-login.php');
    exit;
}

// ── 3. Rate limiting – block after 3 failed attempts ──────────
//  We store failed attempts in the PHP session.
//  "attempt_count" counts how many times they failed.
//  "attempt_time"  records when the first failure happened.
if (!isset($_SESSION['faculty_attempt_count'])) {
    $_SESSION['faculty_attempt_count'] = 0;
    $_SESSION['faculty_attempt_time']  = time();
}

// Reset counter if 15 minutes have passed since first failure
$fifteen_minutes = 15 * 60; // 900 seconds
if (time() - $_SESSION['faculty_attempt_time'] > $fifteen_minutes) {
    $_SESSION['faculty_attempt_count'] = 0;
    $_SESSION['faculty_attempt_time']  = time();
}

// If they already failed 3 times, lock them out
if ($_SESSION['faculty_attempt_count'] >= 3) {
    $wait_seconds = $fifteen_minutes - (time() - $_SESSION['faculty_attempt_time']);
    $wait_minutes = ceil($wait_seconds / 60);
    $_SESSION['login_error'] = "Too many failed attempts. Please try again in {$wait_minutes} minute(s).";
    header('Location: ../pages/faculty-login.php');
    exit;
}

// ── 4. Look up the user in the database ───────────────────────
$stmt = $conn->prepare("SELECT id, last_name, first_name, password, is_verified FROM faculty WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Email not found — but we say "invalid credentials" NOT "email not found"
    // This is a security best practice (don't leak which emails are registered).
    $_SESSION['faculty_attempt_count']++;
    $_SESSION['login_error'] = "Invalid email or password.";
    header('Location: ../pages/faculty-login.php');
    $stmt->close();
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

// ── 5. Check the password ─────────────────────────────────────
//  password_verify() compares the typed password against the hash.
if (!password_verify($password, $user['password'])) {
    $_SESSION['faculty_attempt_count']++;
    $_SESSION['login_error'] = "Invalid email or password.";
    header('Location: ../pages/faculty-login.php');
    exit;
}

// ── 6. Check if the account is verified ───────────────────────
if ($user['is_verified'] == 0) {
    $_SESSION['login_error'] = "Your account is pending approval from an Administrator. Please wait.";
    header('Location: ../pages/faculty-login.php');
    exit;
}

// ── 7. All checks passed! Start the session ───────────────────
//  We store who is logged in so all other pages know.
session_regenerate_id(true); // Security: give them a fresh session ID

$_SESSION['faculty_id']        = $user['id'];
$_SESSION['faculty_name']      = $user['first_name'] . ' ' . $user['last_name'];
$_SESSION['faculty_logged_in'] = true;
$_SESSION['role']              = 'faculty';

// Reset failed attempt counter on successful login
$_SESSION['faculty_attempt_count'] = 0;

// Go to the faculty dashboard
header('Location: ../pages/faculty-home/faculty-homepage.html');
exit;
?>
