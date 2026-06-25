<?php
session_start();
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pages/faculty-login.php'); exit;
}

$email    = trim(strtolower($_POST['email'] ?? ''));
$password = $_POST['password'] ?? '';

if (!$email || !$password) {
    $_SESSION['login_error'] = 'Please enter your email and password.';
    header('Location: ../pages/faculty-login.php'); exit;
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
    header('Location: ../pages/faculty-login.php'); exit;
}

$stmt = $conn->prepare('SELECT id, first_name, last_name, password, is_verified, approved_by FROM faculty WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || !password_verify($password, $row['password'])) {
    $_SESSION['faculty_attempts']++;
    $_SESSION['faculty_attempt_time'] = time();
    $_SESSION['login_error'] = 'Invalid email or password.';
    header('Location: ../pages/faculty-login.php'); exit;
}

if ((int)$row['is_verified'] !== 1 || $row['approved_by'] === null) {
    $_SESSION['login_error'] = 'Your account is pending approval from an Administrator.';
    header('Location: ../pages/faculty-login.php'); exit;
}

session_regenerate_id(true);
$_SESSION['faculty_id']        = $row['id'];
$_SESSION['faculty_name']      = $row['first_name'] . ' ' . $row['last_name'];
$_SESSION['faculty_logged_in'] = true;
$_SESSION['role']              = 'faculty';
$_SESSION['faculty_attempts']  = 0;

// ── NEW AUTHENTICATION QUERY: Check if user is Department Head ───
$faculty_id = (int)$row['id'];
$check_head_query = "SELECT EXISTS(SELECT 1 FROM departments WHERE head_faculty_id = ?) AS is_head";
$stmt_head = $conn->prepare($check_head_query);
$stmt_head->bind_param("i", $faculty_id);
$stmt_head->execute();
$head_result = $stmt_head->get_result()->fetch_assoc();
$stmt_head->close();

// Save the true/false flag in the session state
$_SESSION['is_head'] = (bool)$head_result['is_head'];
// ─────────────────────────────────────────────────────────────────

$now_time   = date('H:i:s');
$now_day    = date('l');

$stmt = $conn->prepare("
    SELECT id, classroom_id FROM schedules
    WHERE created_by = ?
      AND day_of_week = ?
      AND start_time <= ?
      AND COALESCE(extended_until, end_time) >= ?
    LIMIT 1
");
$stmt->bind_param('isss', $faculty_id, $now_day, $now_time, $now_time);
$stmt->execute();
$sched = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($sched) {
    $cid = (int)$sched['classroom_id'];
    $conn->query("
        UPDATE classrooms
        SET light_status = 'on',
            row1_status  = 'on',
            row2_status  = 'on'
        WHERE id = $cid
    ");
}

header('Location: ../pages/faculty-home/faculty-home.php'); exit;