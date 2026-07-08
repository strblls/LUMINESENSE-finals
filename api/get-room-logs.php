<?php
require_once __DIR__ . '/../php/db_connect.php';

header('Content-Type: application/json');

$room = $_GET['room'] ?? '';
if (!$room) {
    echo json_encode(['success' => false, 'message' => 'No room specified']);
    exit;
}

$room = $conn->real_escape_string($room);

$res = $conn->query("
    SELECT id, event_type, triggered_by, event_time, notes
    FROM room_logs
    WHERE room_name = '$room'
    ORDER BY event_time DESC
    LIMIT 50
");

$logs = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $logs[] = $row;
    }
    $res->free();
}

echo json_encode(['success' => true, 'data' => $logs]);
