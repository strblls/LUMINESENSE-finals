<?php
require_once __DIR__ . '/admin-handlers.php';

// ── Summary counts (kept for sidebar/topbar badges) ───────────────────────
$total_rooms = $conn->query("SELECT COUNT(*) AS c FROM classrooms")->fetch_assoc()['c'];

$pending = $conn->query("
    SELECT COUNT(*) AS c FROM faculty
    WHERE is_verified = 1 AND approved_by IS NULL
")->fetch_assoc()['c'];

$ext_pending = 0;
if ($conn->query("SHOW TABLES LIKE 'extension_requests'")->num_rows > 0) {
    $ext_pending = $conn->query(
        "SELECT COUNT(*) AS c FROM extension_requests WHERE status='pending'"
    )->fetch_assoc()['c'];
}

$db_ok       = ($conn && !$conn->connect_error);
$lights_data = $conn->query(
    "SELECT COUNT(*) AS c FROM lighting_logs WHERE DATE(event_time)=CURDATE()"
)->fetch_assoc()['c'];

// ── Rooms list with schedule info ──────────────────────────────────────────
$rooms = [];
$r = $conn->query("
    SELECT c.id, c.room_name, c.room_size, c.description,
           COALESCE(l.event_type, 'off') AS light_status
    FROM classrooms c
    LEFT JOIN lighting_logs l
        ON l.id = (SELECT MAX(id) FROM lighting_logs WHERE classroom_id = c.id)
    ORDER BY c.room_name
");
while ($row = $r->fetch_assoc()) {
    $rooms[] = $row;
}

// Build a lookup: room_id => current schedule info
$day  = date('l');
$time = $conn->query("SELECT TIME(NOW()) as t")->fetch_assoc()['t'];
$schedSt = $conn->query("
    SELECT s.classroom_id,
           CONCAT(f.first_name,' ',f.last_name) AS faculty_name,
           s.start_time, s.end_time
    FROM schedules s
    JOIN faculty f ON f.id = s.faculty_id
    WHERE s.day_of_week = '$day'
      AND '$time' BETWEEN s.start_time AND s.end_time
");
$roomSchedule = [];
while ($s = $schedSt->fetch_assoc()) {
    $roomSchedule[(int)$s['classroom_id']] = $s;
}

// Build a lookup: room_id => next upcoming schedule
$nextSchedSt = $conn->query("
    SELECT s.classroom_id,
           CONCAT(f.first_name,' ',f.last_name) AS next_faculty_name,
           s.start_time AS next_start, s.end_time AS next_end
    FROM schedules s
    JOIN faculty f ON f.id = s.faculty_id
    JOIN (
        SELECT classroom_id, MIN(start_time) AS min_start
        FROM schedules
        WHERE day_of_week = '$day' AND start_time > '$time'
        GROUP BY classroom_id
    ) n ON n.classroom_id = s.classroom_id AND n.min_start = s.start_time
");
$roomNextSchedule = [];
while ($s = $nextSchedSt->fetch_assoc()) {
    $roomNextSchedule[(int)$s['classroom_id']] = $s;
}

// Attach schedule info to each room
foreach ($rooms as &$room) {
    $rid = (int)$room['id'];
    if (isset($roomSchedule[$rid])) {
        $room['faculty_name'] = $roomSchedule[$rid]['faculty_name'];
        $room['start_time']   = $roomSchedule[$rid]['start_time'];
        $room['end_time']     = $roomSchedule[$rid]['end_time'];
        $room['is_occupied']  = true;
        $room['next_faculty_name'] = '';
        $room['next_start_time']   = null;
        $room['next_end_time']     = null;
    } else {
        $room['faculty_name'] = isset($roomNextSchedule[$rid]) ? $roomNextSchedule[$rid]['next_faculty_name'] : '—';
        $room['start_time']   = null;
        $room['end_time']     = null;
        $room['is_occupied']  = false;
        $room['next_faculty_name'] = isset($roomNextSchedule[$rid]) ? $roomNextSchedule[$rid]['next_faculty_name'] : '';
        $room['next_start_time']   = isset($roomNextSchedule[$rid]) ? $roomNextSchedule[$rid]['next_start'] : null;
        $room['next_end_time']     = isset($roomNextSchedule[$rid]) ? $roomNextSchedule[$rid]['next_end'] : null;
    }
}
unset($room);

// ── Passed to JS ───────────────────────────────────────────────────────────
// roomDataFromPHP is now just the rooms list — actual chart data is
// fetched live from api/analytics.php by admin-analytics.js
$roomDataFromPHP = $rooms;