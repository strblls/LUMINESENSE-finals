<?php
// api/classrooms.php
// GET  (no params)          → list all classrooms
// GET  ?id=X                → single classroom
// POST action=add           room_name, room_size, description
// POST action=delete        classroom_id=X

require_once '../php/db_connect.php';
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        $id   = (int)$_GET['id'];
        $stmt = $conn->prepare('SELECT * FROM classrooms WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        echo json_encode(['success' => true, 'data' => $row]); exit;
    }

    $rows = [];
    $r = $conn->query("
        SELECT c.*, COUNT(s.id) AS schedule_count
        FROM classrooms c
        LEFT JOIN schedules s ON s.classroom_id = c.id
        GROUP BY c.id ORDER BY c.room_name
    ");
    while ($row = $r->fetch_assoc()) $rows[] = $row;
    echo json_encode(['success' => true, 'data' => $rows]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim(htmlspecialchars($_POST['room_name']   ?? ''));
        $size = $_POST['room_size']    ?? 'medium';
        $desc = trim(htmlspecialchars($_POST['description'] ?? ''));

        if (!$name) { echo json_encode(['success'=>false,'message'=>'Room name required.']); exit; }
        if (!in_array($size, ['small','medium','large'])) $size = 'medium';

        $stmt = $conn->prepare('INSERT INTO classrooms (room_name, room_size, description) VALUES (?,?,?)');
        $stmt->bind_param('sss', $name, $size, $desc);
        $stmt->execute();
        $new_id = $conn->insert_id;
        $stmt->close();
        echo json_encode(['success' => true, 'message' => "Classroom '$name' added.", 'id' => $new_id]); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['classroom_id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'message'=>'classroom_id required.']); exit; }
        $conn->query("DELETE FROM schedules     WHERE classroom_id=$id");
        $conn->query("DELETE FROM lighting_logs WHERE classroom_id=$id");
        $stmt = $conn->prepare('DELETE FROM classrooms WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Classroom deleted.']); exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']); exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
