<?php
// ============================================================
//  faculty-signup.php
//  LumineSense – Faculty Member Registration
//
//  What this file does (like a story):
//  1. The faculty member fills the sign-up form and clicks SIGN UP.
//  2. This file receives all the typed information.
//  3. It checks everything is correct (not empty, valid email, passwords match).
//  4. It checks the email is not already used.
//  5. It locks the password (hashes it) so no one can read it in the database.
//  6. It saves everything to the "faculty" table with is_verified = 0
//     (meaning: waiting for Admin approval — they cannot log in yet).
//  7. It sends the user back to the login page with a message.
// ============================================================

session_start();
require_once 'db_connect.php';

// ── Only accept POST requests (form submissions) ─────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pages/faculty-signup.php');
    exit;
}

// ── 1. Collect and sanitize inputs ───────────────────────────
//  htmlspecialchars() prevents someone from typing HTML/JS into
//  the form fields (called XSS protection).
$last_name      = trim(htmlspecialchars($_POST['last_name']      ?? ''));
$first_name     = trim(htmlspecialchars($_POST['first_name']     ?? ''));
$middle_initial = trim(htmlspecialchars($_POST['middle_initial'] ?? ''));
$email          = trim(strtolower($_POST['email']               ?? ''));
$password       = $_POST['password']         ?? '';
$confirm_pass   = $_POST['confirm_password'] ?? '';

// ── 2. Validation ─────────────────────────────────────────────
$errors = [];

if (empty($last_name))   $errors[] = "Last name is required.";
if (empty($first_name))  $errors[] = "First name is required.";
if (empty($email))       $errors[] = "Email is required.";
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Please enter a valid email address.";
}
if (strlen($password) < 8) {
    $errors[] = "Password must be at least 8 characters long.";
}
if ($password !== $confirm_pass) {
    $errors[] = "Passwords do not match.";
}

// ── 3. If there are errors, go back with error messages ───────
if (!empty($errors)) {
    $_SESSION['signup_errors'] = $errors;
    $_SESSION['signup_form']   = compact('last_name', 'first_name', 'middle_initial', 'email');
    header('Location: ../pages/faculty-signup.php');
    exit;
}

// ── 4. Check if email already exists in the database ──────────
//  We use a "prepared statement" — this is the safe way to talk
//  to the database. It prevents SQL Injection attacks.
$stmt = $conn->prepare("SELECT id FROM faculty WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $_SESSION['signup_errors'] = ["This email is already registered. Please log in instead."];
    header('Location: ../pages/faculty-signup.php');
    $stmt->close();
    exit;
}
$stmt->close();

// ── 5. Hash (lock) the password ───────────────────────────────
//  password_hash() turns "mypassword123" into a scrambled string
//  like "$2y$10$abc...xyz". Even if someone steals the database,
//  they cannot read the real password.
$hashed_password = password_hash($password, PASSWORD_BCRYPT);

// ── 6. Insert into the database ───────────────────────────────
$stmt = $conn->prepare(
    "INSERT INTO faculty (last_name, first_name, middle_initial, email, password, is_verified)
     VALUES (?, ?, ?, ?, ?, 0)"
);
$stmt->bind_param("sssss", $last_name, $first_name, $middle_initial, $email, $hashed_password);

if ($stmt->execute()) {
    // Success! But they still need Admin approval (is_verified = 0).
    $_SESSION['signup_success'] = "Account created! Please wait for an Administrator to verify your account before logging in.";
    header('Location: ../pages/faculty-login.php');
} else {
    $_SESSION['signup_errors'] = ["Something went wrong. Please try again. Error: " . $conn->error];
    header('Location: ../pages/faculty-signup.php');
}

$stmt->close();
$conn->close();
exit;
?>
