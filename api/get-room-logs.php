<?php
require_once __DIR__ . '/../php/db_connect.php';

header('Content-Type: application/json');

$room = $_GET['room'] ?? '';
if (!$room) {
    echo json_encode(['success' => false, 'message' => 'No room specified']);
    exit;
}

$room = $conn->real_escape_string($room);

// Get classroom id for lighting_logs lookup
$classroomRow = $conn->query("SELECT id FROM classrooms WHERE room_name = '$room'")->fetch_assoc();
$classroomId  = $classroomRow ? (int)$classroomRow['id'] : 0;

$logs = [];

// From room_logs (motion, door, class events, issues, etc.)
$res = $conn->query("
    SELECT event_type, triggered_by, event_time, notes
    FROM room_logs
    WHERE room_name = '$room'
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        if (!empty($row['event_time'])) {
            $row['event_time'] = date('Y-m-d\TH:i:s', strtotime($row['event_time'])) . '+08:00';
        }
        $logs[] = $row;
    }
    $res->free();
}

// From lighting_logs (light on/off, security alerts, gesture, schedule)
if ($classroomId) {
    $res2 = $conn->query("
        SELECT
            CASE event_type
                WHEN 'on'  THEN 'light_on'
                WHEN 'off' THEN 'light_off'
                ELSE event_type
            END AS event_type,
            triggered_by,
            event_time,
            '' AS notes
        FROM lighting_logs
        WHERE classroom_id = $classroomId
    ");
    if ($res2) {
        while ($row = $res2->fetch_assoc()) {
            if (!empty($row['event_time'])) {
                $row['event_time'] = date('Y-m-d\TH:i:s', strtotime($row['event_time'])) . '+08:00';
            }
            $logs[] = $row;
        }
        $res2->free();
    }
}

// From pir_logs (dedicated PIR motion/stopped events)
if ($classroomId) {
    $res3 = $conn->query("
        SELECT
            CASE state WHEN 1 THEN 'pir_motion' ELSE 'pir_stopped' END AS event_type,
            'PIR' AS triggered_by,
            created_at AS event_time,
            '' AS notes
        FROM pir_logs
        WHERE classroom_id = $classroomId
    ");
    if ($res3) {
        while ($row = $res3->fetch_assoc()) {
            if (!empty($row['event_time'])) {
                $row['event_time'] = date('Y-m-d\TH:i:s', strtotime($row['event_time'])) . '+08:00';
            }
            $logs[] = $row;
        }
        $res3->free();
    }
}

// Sort merged logs newest-first
usort($logs, function ($a, $b) {
    return strtotime($b['event_time']) - strtotime($a['event_time']);
});

// Limit to 50
$logs = array_slice($logs, 0, 50);

echo json_encode(['success' => true, 'data' => $logs]);
