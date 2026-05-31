<?php
$phpRoot = realpath(__DIR__ . '/../');
require_once $phpRoot . '/session_guard.php';
check_faculty();
require_once $phpRoot . '/db_connect.php';
date_default_timezone_set('Asia/Manila');

$faculty_name = htmlspecialchars($_SESSION['faculty_name']);
$faculty_id   = (int)$_SESSION['faculty_id'];
$name_parts   = explode(' ', $faculty_name);
$first_name   = $name_parts[0];
$initials     = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));

// Fetch email
$faculty_email = '';
$stmt = $conn->prepare('SELECT email FROM faculty WHERE id = ?');
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$stmt->bind_result($faculty_email);
$stmt->fetch();
$stmt->close();

$today = date('l');
$now   = date('H:i:s');

// ── Classroom assigned to this faculty today ──────────────────
// FIX: was using created_by (admin ID), now uses faculty_id
$classroom_id = 0;
$stmt = $conn->prepare("
    SELECT DISTINCT s.classroom_id
    FROM schedules s
    WHERE s.faculty_id = ?
      AND s.day_of_week = ?
    ORDER BY s.start_time
    LIMIT 1
");
$stmt->bind_param('is', $faculty_id, $today);
$stmt->execute();
$stmt->bind_result($classroom_id);
$stmt->fetch();
$stmt->close();

// No schedule today = no classroom access
if (!$classroom_id) {
    $classroom_id = 0;
}

// ── Today's schedules for THIS faculty (all days for modal) ───
// FIX 1: added day_of_week to SELECT
// FIX 2: removed day filter so modal shows full week
// FIX 3: added faculty_id filter
$schedules = [];
$fid = (int)$faculty_id;
$r = $conn->query("
    SELECT s.start_time, s.end_time, s.day_of_week, c.room_name
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    WHERE s.faculty_id = $fid
    ORDER BY FIELD(s.day_of_week,'Monday','Tuesday','Wednesday',
                   'Thursday','Friday','Saturday','Sunday'), s.start_time
");
while ($row = $r->fetch_assoc()) $schedules[] = $row;

// Current schedule label for topbar
$current_sched = 'No class right now';
foreach ($schedules as $s) {
    if ($s['day_of_week'] === $today && $now >= $s['start_time'] && $now <= $s['end_time']) {
        $current_sched = $s['room_name'] . ' · '
            . date('g:i A', strtotime($s['start_time'])) . ' - '
            . date('g:i A', strtotime($s['end_time']));
        break;
    }
}

// ── Recent logs for this faculty's classroom only ─────────────
// FIX: was showing all rooms, now filters by classroom_id
$logs = [];
$r = $conn->query("
    SELECT l.event_type, l.triggered_by, l.event_time, c.room_name
    FROM lighting_logs l
    JOIN classrooms c ON c.id = l.classroom_id
    WHERE l.classroom_id = $classroom_id
    ORDER BY l.event_time DESC
    LIMIT 7
");
while ($row = $r->fetch_assoc()) $logs[] = $row;

// ── Gesture logs for this faculty only ───────────────────────
$gesture_logs = [];
$stmt = $conn->prepare("
    SELECT l.event_type, l.triggered_by, l.event_time, c.room_name
    FROM lighting_logs l
    JOIN classrooms c ON c.id = l.classroom_id
    WHERE l.faculty_id = ?
      AND l.triggered_by = 'gesture'
    ORDER BY l.event_time DESC
    LIMIT 20
");
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $gesture_logs[] = $row;
$stmt->close();
?>