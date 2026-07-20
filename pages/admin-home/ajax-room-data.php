<?php
$phpRoot = realpath(__DIR__ . '/../../php');
require_once $phpRoot . '/session_guard.php';
if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['faculty_logged_in'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
require_once $phpRoot . '/db_connect.php';
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json');

$room_id = (int)($_GET['room_id'] ?? 0);
if (!$room_id) {
    echo json_encode(['error' => 'Invalid room']);
    exit;
}

// ── 1. Latest lighting log ─────────────────────────────────────────────────
$row = $conn->query("
    SELECT light_status, row1_status, row2_status, row3_status
    FROM classrooms WHERE id = $room_id LIMIT 1
")->fetch_assoc();
$light_on    = ($row && $row['light_status'] === 'on');
$row1_status = $row['row1_status'] ?? 'off';
$row2_status = $row['row2_status'] ?? 'off';
$row3_status = $row['row3_status'] ?? 'off';

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
$time = $conn->query("SELECT TIME(NOW()) as t")->fetch_assoc()['t'];
$stmt = $conn->prepare("
    SELECT s.start_time, s.end_time,
           CONCAT(f.first_name,' ',f.last_name) AS faculty_name,
           f.first_name, f.last_name
    FROM schedules s
    JOIN faculty f ON f.id = s.faculty_id
    WHERE s.classroom_id = ?
      AND s.day_of_week  = ?
      AND TIME(s.start_time) <= TIME(?)
      AND TIME(s.end_time) >= TIME(?)
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
        'initials'     => strtoupper(substr($curSched['first_name'], 0, 1) . substr($curSched['last_name'], 0, 1)),
        'start_time'   => date('g:i A', strtotime($curSched['start_time'])),
        'end_time'     => date('g:i A', strtotime($curSched['end_time'])),
        'status'       => 'Occupied',
    ];
}

// ── 5. Today's schedules ───────────────────────────────────
$stmt = $conn->prepare("
    SELECT s.start_time, s.end_time,
           CONCAT(f.first_name,' ',f.last_name) AS faculty_name
    FROM schedules s
    JOIN faculty f ON f.id = s.faculty_id
    WHERE s.classroom_id = ?
      AND s.day_of_week = ?
    ORDER BY TIME(s.start_time) ASC
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

// ── 6. Full weekly timetable ── (no time filter — shows everything)
$stmt = $conn->prepare("
    SELECT s.day_of_week, s.start_time, s.end_time,
           CONCAT(f.first_name,' ',f.last_name) AS faculty_name
    FROM schedules s
    JOIN faculty f ON f.id = s.faculty_id
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

// ── 7. Room activity log / alerts ──────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT event_type, triggered_by, row_affected,
           DATE_FORMAT(event_time,'%Y-%m-%d %H:%i:%s') AS event_time
    FROM lighting_logs
    WHERE classroom_id = ?
    ORDER BY event_time DESC
    LIMIT 50
");
$stmt->bind_param('i', $room_id);
$stmt->execute();
$res = $stmt->get_result();
$alerts = [];
while ($r = $res->fetch_assoc()) $alerts[] = $r;
$stmt->close();

//8. ── Next schedule today or future day ───────────────────────
$stmt = $conn->prepare("
    SELECT s.start_time, s.end_time,
           CONCAT(f.first_name,' ',f.last_name) AS faculty_name
    FROM schedules s
    JOIN faculty f ON f.id = s.faculty_id
    WHERE s.classroom_id = ?
      AND s.day_of_week = ?
      AND TIME(s.start_time) > TIME(?)
    ORDER BY TIME(s.start_time) ASC
    LIMIT 1
");

$stmt->bind_param('iss', $room_id, $day, $time);
$stmt->execute();

$next_schedule = null;

if ($row = $stmt->get_result()->fetch_assoc()) {
    $next_schedule = [
        'start_time' => date('g:i A', strtotime($row['start_time'])),
        'end_time' => date('g:i A', strtotime($row['end_time'])),
        'faculty_name' => $row['faculty_name'],
        'day_name' => null
    ];
} else {
    $dayOrder = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $currentDayIndex = array_search($day, $dayOrder);
    for ($i = 1; $i <= 7; $i++) {
        $checkDay = $dayOrder[($currentDayIndex + $i) % 7];
        $st2 = $conn->prepare("
            SELECT s.start_time, s.end_time,
                   CONCAT(f.first_name,' ',f.last_name) AS faculty_name
            FROM schedules s
            JOIN faculty f ON f.id = s.faculty_id
            WHERE s.classroom_id = ?
              AND s.day_of_week = ?
            ORDER BY TIME(s.start_time) ASC
            LIMIT 1
        ");
        $st2->bind_param('is', $room_id, $checkDay);
        $st2->execute();
        if ($row = $st2->get_result()->fetch_assoc()) {
            $next_schedule = [
                'start_time' => date('g:i A', strtotime($row['start_time'])),
                'end_time' => date('g:i A', strtotime($row['end_time'])),
                'faculty_name' => $row['faculty_name'],
                'day_name' => $checkDay
            ];
            $st2->close();
            break;
        }
        $st2->close();
    }
}

$stmt->close();

$conn->close();

echo json_encode([
    'today_date'       => date('F j, l'),
    'next_schedule'   => $next_schedule,
    'light_on'         => $light_on,
    'row1_status'      => $row1_status, 
    'row2_status'      => $row2_status,
    'row3_status'      => $row3_status,
    'pir_active'       => $pir_active,
    'cam_active'       => $cam_active,
    'current_schedule' => $current_schedule,
    'today_schedules'  => $today_schedules,
    'all_schedules'    => $all_schedules,
    'alerts'           => $alerts,
]);
