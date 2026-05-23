<?php
$phpRoot = realpath(__DIR__ . '/../');
require_once $phpRoot . '/session_guard.php';
check_faculty();
require_once $phpRoot . '/db_connect.php';

$faculty_name = htmlspecialchars($_SESSION['faculty_name']);
$faculty_id   = $_SESSION['faculty_id'];
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

// Today's schedule
$today = date('l');
$schedules = [];
$r = $conn->query("
    SELECT s.start_time, s.end_time, c.room_name
    FROM schedules s JOIN classrooms c ON c.id = s.classroom_id
    WHERE s.day_of_week = '$today'
    ORDER BY s.start_time
");
while ($row = $r->fetch_assoc()) $schedules[] = $row;

// Current schedule label
$current_sched = 'No class right now';
$now = date('H:i:s');
foreach ($schedules as $s) {
    if ($now >= $s['start_time'] && $now <= $s['end_time']) {
        $current_sched = $s['room_name'] . ' · '
            . date('g:i A', strtotime($s['start_time'])) . ' - '
            . date('g:i A', strtotime($s['end_time']));
        break;
    }
}

// Recent activity logs
$logs = [];
$r = $conn->query("
    SELECT l.event_type, l.triggered_by, l.event_time, c.room_name
    FROM lighting_logs l JOIN classrooms c ON c.id = l.classroom_id
    ORDER BY l.event_time DESC LIMIT 7
");
while ($row = $r->fetch_assoc()) $logs[] = $row;

// Get first classroom for gesture logging
$classroom_id = 1;
$r = $conn->query("SELECT id FROM classrooms ORDER BY id LIMIT 1");
if ($row = $r->fetch_assoc()) $classroom_id = $row['id'];

// Gesture logs — this faculty only
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