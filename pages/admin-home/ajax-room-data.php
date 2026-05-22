<?php
$phpRoot = realpath(__DIR__ . '/../../php');
require_once $phpRoot . '/session_guard.php';
check_admin();
require_once $phpRoot . '/db_connect.php';

header('Content-Type: application/json');

$room_id = (int)($_GET['room_id'] ?? 0);
if (!$room_id) { echo json_encode(['error' => 'Invalid room']); exit; }

// ── 1. Latest lighting log ─────────────────────────────────────────────────
$row = $conn->query("
    SELECT event_type FROM lighting_logs
    WHERE classroom_id = $room_id
    ORDER BY id DESC LIMIT 1
")->fetch_assoc();
$light_on = ($row && $row['event_type'] === 'on');

// ── 2. PIR sensor status ───────────────────────────────────────────────────
// We consider PIR "active" if there was any sensor-triggered log in the last 60 seconds.
// In the prototype this comes from the lighting_logs triggered_by='sensor'.
// If your Arduino pushes to a separate table, adjust the query here.
$pirRow = $conn->query("
    SELECT id FROM lighting_logs
    WHERE classroom_id = $room_id
      AND triggered_by = 'sensor'
      AND event_time >= DATE_SUB(NOW(), INTERVAL 60 SECOND)
    LIMIT 1
")->fetch_assoc();
$pir_active = !empty($pirRow);

// ── 3. Web camera ──────────────────────────────────────────────────────────
// Currently always false for the prototype (no live feed integration yet).
$cam_active = false;

// ── 4. Current schedule (right now) ───────────────────────────────────────
$day  = date('l');
$time = date('H:i:s');
$stmt = $conn->prepare("
    SELECT s.start_time, s.end_time,
           CONCAT(f.first_name,' ',f.last_name) AS faculty_name,
           f.first_name, f.last_name
    FROM schedules s
    JOIN faculty f ON f.id = s.created_by
    WHERE s.classroom_id = ?
      AND s.day_of_week  = ?
      AND s.start_time  <= ?
      AND s.end_time    >= ?
    LIMIT 1
");
$stmt->bind_param('isss', $room_id, $day, $time, $time);
$stmt->execute();
$curSched = $stmt->get_result()->fetch_assoc();
$stmt->close();

$current_schedule = null;
if ($curSched) {
    $current_schedule = [
        'faculty_name' => $curSched['faculty_name'],
        'initials'     => strtoupper(substr($curSched['first_name'],0,1) . substr($curSched['last_name'],0,1)),
        'start_time'   => date('g:i A', strtotime($curSched['start_time'])),
        'end_time'     => date('g:i A', strtotime($curSched['end_time'])),
    ];
}

// ── 5. Today's schedules ───────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT s.start_time, s.end_time,
           CONCAT(f.first_name,' ',f.last_name) AS faculty_name
    FROM schedules s
    JOIN faculty f ON f.id = s.created_by
    WHERE s.classroom_id = ? AND s.day_of_week = ?
    ORDER BY s.start_time
");
$stmt->bind_param('is', $room_id, $day);
$stmt->execute();
$res = $stmt->get_result();
$today_schedules = [];
while ($r = $res->fetch_assoc()) {
    $today_schedules[] = [
        'start_time'   => date('g:i A', strtotime($r['start_time'])),
        'end_time'     => date('g:i A', strtotime($r['end_time'])),
        'faculty_name' => $r['faculty_name'],
    ];
}
$stmt->close();

// ── 6. Full weekly timetable ───────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT s.day_of_week, s.start_time, s.end_time,
           CONCAT(f.first_name,' ',f.last_name) AS faculty_name
    FROM schedules s
    JOIN faculty f ON f.id = s.created_by
    WHERE s.classroom_id = ?
    ORDER BY FIELD(s.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
             s.start_time
");
$stmt->bind_param('i', $room_id);
$stmt->execute();
$res = $stmt->get_result();
$all_schedules = [];
while ($r = $res->fetch_assoc()) {
    $all_schedules[] = [
        'day_of_week'  => $r['day_of_week'],
        'start_time'   => date('g:i A', strtotime($r['start_time'])),
        'end_time'     => date('g:i A', strtotime($r['end_time'])),
        'faculty_name' => $r['faculty_name'],
    ];
}
$stmt->close();

// ── 7. Today's activity / alerts ───────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT event_type, triggered_by,
           DATE_FORMAT(event_time,'%h:%i %p') AS event_time
    FROM lighting_logs
    WHERE classroom_id = ? AND DATE(event_time) = CURDATE()
    ORDER BY event_time DESC
    LIMIT 20
");
$stmt->bind_param('i', $room_id);
$stmt->execute();
$res = $stmt->get_result();
$alerts = [];
while ($r = $res->fetch_assoc()) $alerts[] = $r;
$stmt->close();

$conn->close();

echo json_encode([
    'light_on'         => $light_on,
    'pir_active'       => $pir_active,
    'cam_active'       => $cam_active,
    'current_schedule' => $current_schedule,
    'today_schedules'  => $today_schedules,
    'all_schedules'    => $all_schedules,
    'alerts'           => $alerts,
]);