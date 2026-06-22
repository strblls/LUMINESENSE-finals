<?php
/**
 * php/handlers/faculty-login-process.php
 *
 * Handles login for ALL faculty accounts — both regular Faculty
 * and Head Faculty. There is no separate Head Faculty login or
 * signup; it's the exact same account type and the exact same
 * login form. The system auto-detects Head Faculty status here,
 * every time, based on data the Admin set — not a flag the
 * faculty member can see or touch.
 *
 * HOW HEAD FACULTY DETECTION WORKS:
 * The Admin assigns a Head Faculty by setting
 * departments.head_faculty_id = <that faculty's id> from the
 * admin dashboard. There is no separate role column on the
 * faculty table itself — being Head Faculty just means "some
 * department points to me." This query runs on every login,
 * so if the Admin reassigns the role tomorrow, the affected
 * faculty member's dashboard updates automatically on their
 * next login — no manual sync needed anywhere.
 *
 * A teacher can only log in if:
 *   1. Their email is verified (OTP confirmed)
 *   2. Their account was approved by an Admin (approved_by IS NOT NULL)
 *
 * LIGHT ACTIVATION ON LOGIN (unchanged from before):
 * If the teacher has a class scheduled RIGHT NOW, lights turn on
 * automatically in their classroom.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db_connect.php';

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

// Step 2: Has an Admin approved this account?
// approved_by = NULL means nobody has approved them yet.
// (No more "Head Teacher approval" — only Admins approve, per the new hierarchy.)
if ($row['approved_by'] === null) {
    $_SESSION['pending_name'] = $row['first_name'];
    header('Location: ../../pages/pending-approval.php');
    exit;
}

$faculty_id = (int) $row['id'];

// ── NEW: Auto-detect Head Faculty status ──────────────────────
// Being Head Faculty isn't stored on the faculty row at all — it's
// derived from whether any department currently points to this
// faculty as its head_faculty_id. This is what makes the "system
// automatically knows" behavior work: the Admin sets this once on
// the department side, and every login re-checks it fresh.
$stmt = $conn->prepare("
    SELECT id, name
    FROM departments
    WHERE head_faculty_id = ?
    LIMIT 1
");
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$headOf = $stmt->get_result()->fetch_assoc();
$stmt->close();

$is_head_faculty = $headOf !== null;

// All good — create the session
session_regenerate_id(true);

$_SESSION['faculty_logged_in']  = true;
$_SESSION['faculty_id']         = $faculty_id;
$_SESSION['faculty_name']       = $row['first_name'] . ' ' . $row['last_name'];
$_SESSION['role']               = 'faculty';
$_SESSION['department_id']      = $row['department_id'];
$_SESSION['department_name']    = $row['department_name'];
$_SESSION['faculty_attempts']   = 0;

// These two lines are the entire "automation" — the dashboard view
// just checks $_SESSION['is_head_faculty'] to decide whether to
// render the extra Head Faculty overview tab. No separate login
// page, no separate signup, no extra question asked at login time.
$_SESSION['is_head_faculty']       = $is_head_faculty;
$_SESSION['head_faculty_of_dept']  = $is_head_faculty ? $headOf['id']   : null;
$_SESSION['head_faculty_dept_name'] = $is_head_faculty ? $headOf['name'] : null;

$now_time = date('H:i:s');
$now_day  = date('l');    // e.g. "Tuesday"

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
    $cid = (int) $sched['classroom_id'];

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