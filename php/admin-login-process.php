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

// ── UPDATED: now also pulls approved_by ───────────────────────────────
$stmt = $conn->prepare('
    SELECT a.id, a.first_name, a.last_name, a.password, a.is_verified, a.approved_by,
           a.admin_role, a.department_id, d.name AS department_name
    FROM admins a
    LEFT JOIN departments d ON d.id = a.department_id
    WHERE a.email = ?
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
    $_SESSION['login_error'] = 'Please verify your email before logging in.';
    header('Location: ../pages/admin-login.php');
    exit;
}

// ── NEW: block login while an ID quarantine entry is still unresolved ──
// approved_by being non-null does NOT mean the quarantine was resolved —
// faculty-approvals-handler.php's normal Approve button has no idea
// id_review_queue exists, so it can set approved_by while a mismatched/
// unreadable entry still sits there with reviewed = 0. We check the
// queue directly here, the actual source of truth, instead of trusting
// approved_by to imply it.
$qStmt = $conn->prepare("
    SELECT id FROM id_review_queue
    WHERE account_type = 'admin' AND account_id = ? AND reviewed = 0
    LIMIT 1
");
$qStmt->bind_param('i', $row['id']);
$qStmt->execute();
$pending_review = $qStmt->get_result()->fetch_assoc();
$qStmt->close();

if ($pending_review) {
    $_SESSION['login_error'] = 'Your ID verification is still under manual review. Please wait for an administrator to confirm it before logging in.';
    header('Location: ../pages/admin-login.php');
    exit;
}

// ── NEW: approval gate ──────────────────────────────────────────────
if (!$row['approved_by']) {
    $_SESSION['login_error'] = 'Your account is pending administrator approval.';
    header('Location: ../pages/admin-login.php');
    exit;
}

session_regenerate_id(true);
$_SESSION['admin_id']        = $row['id'];
$_SESSION['admin_name']      = $row['first_name'] . ' ' . $row['last_name'];
$_SESSION['admin_logged_in'] = true;
$_SESSION['role']            = 'admin';  // Keep for backward compat
$_SESSION['admin_role']      = $row['admin_role'] ?? 'principal';  // NEW
$_SESSION['department_id']   = $row['department_id'];  // NEW
$_SESSION['department_name'] = $row['department_name'];  // NEW
$_SESSION['admin_attempts']  = 0;

// Log admin login
$stmt = $conn->prepare('INSERT INTO admin_login_logs (admin_id) VALUES (?)');
$stmt->bind_param('i', $_SESSION['admin_id']);
$stmt->execute();
$stmt->close();

// ── UPDATED: Route by admin_role ──────────────────────────────────────
$redirect_url = match ($row['admin_role']) {
    'principal'    => '../pages/principal-home/principal-homepage.php',
    'head_faculty' => '../pages/head-faculty-home/head-faculty-homepage.php',
    default        => '../pages/admin-home/admin-homepage.php',  // fallback
};

header("Location: {$redirect_url}");
exit;