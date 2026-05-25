<?php
// api/status.php
// GET → current light status + active schedule for all classrooms
// Used by both dashboards for real-time display.

require_once '../php/db_connect.php';
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['faculty_logged_in'])) {
    http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit;
}

$now_time = date('H:i:s');
$now_day  = date('l'); // e.g. "Monday"

$rows = [];
$r = $conn->query("
    SELECT c.id, c.room_name, c.room_size, c.description,
           c.light_status, c.row1_status, c.row2_status, c.row3_status,
           c.pir_occupied, c.pir_since
    FROM classrooms c
    ORDER BY c.room_name
");

while ($room = $r->fetch_assoc()) {
    // Check if there is a schedule active RIGHT NOW
    $cid  = (int)$room['id'];
    $stmt = $conn->prepare("
        SELECT id, start_time, end_time FROM schedules
        WHERE classroom_id=? AND day_of_week=?
        AND start_time <= ? AND end_time >= ?
        LIMIT 1
    ");
    $stmt->bind_param('isss', $cid, $now_day, $now_time, $now_time);
    $stmt->execute();
    $sched = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $room['row1_status'] = $room['row1_status'];
    ['row2_status'] = $room['row2_status'];
    $room['row3_status'] = $room['row3_status'];
    $rows[] = $room;
}

echo json_encode(['success'=>true,'time'=>date('H:i:s'),'day'=>$now_day,'data'=>$rows]);
