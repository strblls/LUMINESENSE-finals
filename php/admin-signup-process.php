<?php
/**
 * php/handlers/admin-login-process.php
 *
 * Admins self-register via the signup form. New admin accounts
 * land with is_verified = 0 and need approval from an EXISTING
 * admin before they can log in (same pattern as faculty approval,
 * just admin-approving-admin instead of admin-approving-faculty).
 *
 * No principal / head_teacher tiers — removed per DepEd memo:
 * teachers cannot hold administrative duties. All admins have
 * equal access once approved.
 *
 * All admins can:
 *   - View every department
 *   - Approve/reject/revoke faculty accounts
 *   - Approve/reject other pending admin accounts
 *   - Review quarantined ID images (mismatched/unreadable OCR)
 *   - Designate a Head Faculty per department
 */

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

// No more admin_role / department_id lookups — every admin is equal
// and can see every department.
$stmt = $conn->prepare('
    SELECT id, first_name, last_name, password, is_verified
    FROM admins
    WHERE email = ?
');
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
    $_SESSION['login_error'] = 'Your account is pending approval from an existing Administrator.';
    header('Location: ../pages/admin-login.php');
    exit;
}

session_regenerate_id(true);
$_SESSION['admin_id']        = $row['id'];
$_SESSION['admin_name']      = $row['first_name'] . ' ' . $row['last_name'];
$_SESSION['admin_logged_in'] = true;
$_SESSION['role']            = 'admin';
$_SESSION['admin_attempts']  = 0;

// Log admin login
$stmt = $conn->prepare('INSERT INTO admin_login_logs (admin_id) VALUES (?)');
$stmt->bind_param('i', $_SESSION['admin_id']);
$stmt->execute();
$stmt->close();

// One role, one homepage — no more match() routing by tier.
header('Location: ../pages/admin-home/admin-homepage.php');
exit;