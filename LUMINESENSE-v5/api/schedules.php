<?php
// api/schedules.php
// GET  ?classroom_id=X      → schedules for one room
// GET  (no params)          → all schedules
// POST action=add           classroom_id, day_of_week, start_time, end_time
// POST action=delete        schedule_id=X

require_once '../php/db_connect.php';
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
}

$admin_id = (int)$_SESSION['admin_id'];
$valid_days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $where = '';
    if (!empty($_GET['classroom_id'])) {
        $cid   = (int)$_GET['classroom_id'];
        $where = "WHERE s.classroom_id = $cid";
    }
    $rows = [];
    $r = $conn->query("
        SELECT s.id, s.day_of_week, s.start_time, s.end_time,
               s.classroom_id, c.room_name
        FROM schedules s
        JOIN classrooms c ON c.id = s.classroom_id
        $where
        ORDER BY FIELD(s.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), s.start_time
    ");
    while ($row = $r->fetch_assoc()) $rows[] = $row;
    echo json_encode(['success' => true, 'data' => $rows]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $cid   = (int)($_POST['classroom_id'] ?? 0);
        $day   = $_POST['day_of_week'] ?? '';
        $start = $_POST['start_time']  ?? '';
        $end   = $_POST['end_time']    ?? '';

        $errors = [];
        if (!$cid)                         $errors[] = 'classroom_id required.';
        if (!in_array($day, $valid_days))  $errors[] = 'Invalid day.';
        if (!$start || !$end)              $errors[] = 'start_time and end_time required.';
        if ($start >= $end)                $errors[] = 'start_time must be before end_time.';

        if (!empty($errors)) {
            echo json_encode(['success'=>false,'message'=>implode(' ',$errors)]); exit;
        }

        // Overlap check
        $stmt = $conn->prepare("
            SELECT id FROM schedules
            WHERE classroom_id=? AND day_of_week=?
            AND NOT (end_time <= ? OR start_time >= ?)
        ");
        $stmt->bind_param('isss', $cid, $day, $start, $end);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            echo json_encode(['success'=>false,'message'=>'Time slot overlaps existing schedule.']); exit;
        }
        $stmt->close();

        $stmt = $conn->prepare('INSERT INTO schedules (classroom_id, day_of_week, start_time, end_time, created_by) VALUES (?,?,?,?,?)');
        $stmt->bind_param('isssi', $cid, $day, $start, $end, $admin_id);
        $stmt->execute();
        $new_id = $conn->insert_id;
        $stmt->close();
        echo json_encode(['success'=>true,'message'=>'Schedule added.','id'=>$new_id]); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['schedule_id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'message'=>'schedule_id required.']); exit; }
        $stmt = $conn->prepare('DELETE FROM schedules WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success'=>true,'message'=>'Schedule removed.']); exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action.']); exit;
}

http_response_code(405);
echo json_encode(['success'=>false,'message'=>'Method not allowed.']);
