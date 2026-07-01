<?php
require_once '../php/db_connect.php';
header('Content-Type: text/plain');

$token = $_GET['token'] ?? '';
if ($token !== 'LS_ESP32_TOKEN_2025') {
    http_response_code(401); exit;
}

date_default_timezone_set('Asia/Manila');   // ← ADD THIS LINE

$cid = (int)($_GET['classroom_id'] ?? 1);
$day = date('l');   // now correctly returns e.g. "Tuesday" in PST

$stmt = $conn->prepare("
    SELECT start_time, COALESCE(extended_until, end_time) AS end_time
    FROM schedules
    WHERE classroom_id = ? AND day_of_week = ?
    ORDER BY start_time
");
$stmt->bind_param('is', $cid, $day);
$stmt->execute();
$res = $stmt->get_result();

$slots = [];
while ($row = $res->fetch_assoc()) {
    $slots[] = date('H:i', strtotime($row['start_time'])) 
             . '-' 
             . date('H:i', strtotime($row['end_time']));
}
$stmt->close();

echo implode(',', $slots);