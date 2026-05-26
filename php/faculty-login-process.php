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
    $_SESSION['login_error'] = 'Invalid email or password.';
    header('Location: ../pages/faculty-login.php'); exit;
}

if (!$row['is_verified']) {
    $_SESSION['login_error'] = 'Please verify your email first. Check your inbox for the verification code.';
    header('Location: ../pages/faculty-login.php'); exit;
}

if ($row['approved_by'] === null) {
    $_SESSION['login_error'] = 'Your account is pending approval from an Administrator.';
    header('Location: ../pages/faculty-login.php'); exit;
}

session_regenerate_id(true);
$_SESSION['faculty_id']        = $row['id'];
$_SESSION['faculty_name']      = $row['first_name'] . ' ' . $row['last_name'];
$_SESSION['faculty_logged_in'] = true;
$_SESSION['role']              = 'faculty';
$_SESSION['faculty_attempts']  = 0;

$faculty_id = (int)$row['id'];
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
            row2_status  = 'on',
            row3_status  = 'on',
            pir_occupied = 1,
            pir_since    = NOW()
        WHERE id = $cid
    ");
    $stmt = $conn->prepare("
        INSERT INTO lighting_logs (classroom_id, faculty_id, event_type, triggered_by)
        VALUES (?, ?, 'on', 'login')
    ");
    $stmt->bind_param('ii', $cid, $faculty_id);
    $stmt->execute();
    $stmt->close();
}

header('Location: ../pages/faculty-home/faculty-home.php'); exit;