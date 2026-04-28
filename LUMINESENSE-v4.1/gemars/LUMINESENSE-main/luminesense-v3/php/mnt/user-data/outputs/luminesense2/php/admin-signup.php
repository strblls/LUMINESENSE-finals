<?php
// ============================================================
//  admin-signup.php
//  LumineSense – Administrator Registration
//
//  Almost identical to faculty-signup.php BUT:
//  - Saves to the "admins" table instead of "faculty"
//  - The verification notice says "Information Systems Office"
//    instead of "Administrator" (per the capstone document)
// ============================================================

session_start();
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pages/admin-signup.php');
    exit;
}

// ── 1. Collect and sanitize inputs ───────────────────────────
$last_name      = trim(htmlspecialchars($_POST['last_name']      ?? ''));
$first_name     = trim(htmlspecialchars($_POST['first_name']     ?? ''));
$middle_initial = trim(htmlspecialchars($_POST['middle_initial'] ?? ''));
$email          = trim(strtolower($_POST['email']               ?? ''));
$password       = $_POST['password']         ?? '';
$confirm_pass   = $_POST['confirm_password'] ?? '';

// ── 2. Validation ─────────────────────────────────────────────
$errors = [];

if (empty($last_name))  $errors[] = "Last name is required.";
if (empty($first_name)) $errors[] = "First name is required.";
if (empty($email))      $errors[] = "Email is required.";
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Please enter a valid email address.";
}
if (strlen($password) < 8) {
    $errors[] = "Password must be at least 8 characters long.";
}
if ($password !== $confirm_pass) {
    $errors[] = "Passwords do not match.";
}

if (!empty($errors)) {
    $_SESSION['signup_errors'] = $errors;
    $_SESSION['signup_form']   = compact('last_name', 'first_name', 'middle_initial', 'email');
    header('Location: ../pages/admin-signup.php');
    exit;
}

// ── 3. Check if email already exists ──────────────────────────
$stmt = $conn->prepare("SELECT id FROM admins WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $_SESSION['signup_errors'] = ["This email is already registered as an Administrator."];
    header('Location: ../pages/admin-signup.php');
    $stmt->close();
    exit;
}
$stmt->close();

// ── 4. Hash the password ──────────────────────────────────────
$hashed_password = password_hash($password, PASSWORD_BCRYPT);

// ── 5. Insert into admins table ───────────────────────────────
$stmt = $conn->prepare(
    "INSERT INTO admins (last_name, first_name, middle_initial, email, password, is_verified)
     VALUES (?, ?, ?, ?, ?, 0)"
);
$stmt->bind_param("sssss", $last_name, $first_name, $middle_initial, $email, $hashed_password);

if ($stmt->execute()) {
    $_SESSION['signup_success'] = "Admin account created! Please coordinate with the Information Systems Office for account verification before logging in.";
    header('Location: ../pages/admin-login.php');
} else {
    $_SESSION['signup_errors'] = ["Something went wrong. Please try again. Error: " . $conn->error];
    header('Location: ../pages/admin-signup.php');
}

$stmt->close();
$conn->close();
exit;
?>
