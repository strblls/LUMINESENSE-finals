<?php
/**
 * php/handlers/faculty-login-process.php
 *
 * Handles login for Teacher (Faculty) accounts.
 *
 * A teacher can only log in if:
 *   1. Their email is verified (they clicked the OTP link)
 *   2. Their account was approved by their Head Teacher
 *
 * If not approved yet, they are sent to the pending-approval page
 * instead of the faculty dashboard.
 *
 * LIGHT ACTIVATION ON LOGIN:
 * If the teacher has a class scheduled RIGHT NOW when they log in,
 * the system automatically turns the lights on in their classroom.
 * This is the "login trigger" for lighting.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db_connect.php';

date_default_timezone_set('Asia/Manila');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../pages/faculty-login.php');
    exit;
}

$email    = trim(strtolower($_POST['email']    ?? ''));
$password = $_POST['password'] ?? '';

if (!$email || !$password) {
    $_SESSION['login_error'] = 'Please enter your email and password.';
    header('Location: ../../pages/faculty-login.php');
    exit;
}

// Rate limiting - same 3 attempts / 15 min rule as admin login
$_SESSION['faculty_attempts']     = $_SESSION['faculty_attempts']     ?? 0;
$_SESSION['faculty_attempt_time'] = $_SESSION['faculty_attempt_time'] ?? time();

if (time() - $_SESSION['faculty_attempt_time'] > 900) {
    $_SESSION['faculty_attempts']     = 0;
    $_SESSION['faculty_attempt_time'] = time();
}

if ($_SESSION['faculty_attempts'] >= 3) {
    $wait = ceil((900 - (time() - $_SESSION['faculty_attempt_time'])) / 60);
    $_SESSION['login_error'] = "Too many failed attempts. Please wait {$wait} minute(s).";
    header('Location: ../../pages/faculty-login.php');
    exit;
}

// Fetch faculty record including department info
$stmt = $conn->prepare("
    SELECT
        f.id,
        f.first_name,
        f.last_name,
        f.password,
        f.is_verified,
        f.approved_by,
        f.department_id,
        d.name AS department_name
    FROM faculty f
    LEFT JOIN departments d ON d.id = f.department_id
    WHERE f.email = ?
    LIMIT 1
");
$stmt->bind_param('s', $email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Combine "not found" and "wrong password" into one message
// This is intentional — we don't want to tell someone
// "that email doesn't exist" because it leaks information
if (!$row || !password_verify($password, $row['password'])) {
    $_SESSION['faculty_attempts']++;
    $_SESSION['login_error'] = 'Invalid email or password.';
    header('Location: ../../pages/faculty-login.php');
    exit;
}

// Step 1: Has the teacher confirmed their email?
if (!$row['is_verified']) {
    $_SESSION['login_error'] = 'Please verify your email first. Check your inbox for the OTP code.';
    header('Location: ../../pages/faculty-login.php');
    exit;
}

// Step 2: Has the Head Teacher approved this account?
// approved_by = NULL means nobody has approved them yet
if ($row['approved_by'] === null) {
    // Don't show an error — send them to a friendly waiting page instead
    $_SESSION['pending_name'] = $row['first_name'];
    header('Location: ../../pages/pending-approval.php');
    exit;
}

// All good — create the session
session_regenerate_id(true);

$_SESSION['faculty_logged_in']  = true;
$_SESSION['faculty_id']         = (int)$row['id'];
$_SESSION['faculty_name']       = $row['first_name'] . ' ' . $row['last_name'];
$_SESSION['role']               = 'faculty';
$_SESSION['department_id']      = $row['department_id'];
$_SESSION['department_name']    = $row['department_name'];
$_SESSION['faculty_attempts']   = 0;

$faculty_id = (int)$row['id'];
$now_time   = date('H:i:s');
$now_day    = date('l');    // e.g. "Tuesday"

// Check if this teacher has a class happening RIGHT NOW
// If yes, turn on their classroom lights automatically
$stmt = $conn->prepare("
    SELECT id, classroom_id
    FROM schedules
    WHERE created_by  = ?
      AND day_of_week = ?
      AND start_time  <= ?
      AND COALESCE(extended_until, end_time) >= ?
    LIMIT 1
");
$stmt->bind_param('isss', $faculty_id, $now_day, $now_time, $now_time);
$stmt->execute();
$sched = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($sched) {
    $cid = (int)$sched['classroom_id'];

    // Turn on all rows
    $stmt = $conn->prepare("
        UPDATE classrooms
        SET light_status = 'on',
            row1_status  = 'on',
            row2_status  = 'on',
            row3_status  = 'on',
            pir_occupied = 1,
            pir_since    = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $stmt->close();

    // Log the event
    $stmt = $conn->prepare("
        INSERT INTO lighting_logs (classroom_id, faculty_id, event_type, triggered_by)
        VALUES (?, ?, 'on', 'login')
    ");
    $stmt->bind_param('ii', $cid, $faculty_id);
    $stmt->execute();
    $stmt->close();

    // Store the active classroom in session so the dashboard knows where to look
    $_SESSION['active_classroom_id'] = $cid;
}

header('Location: ../../pages/faculty-home/faculty-home.php');
exit;