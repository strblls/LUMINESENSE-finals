<?php
// php/handlers/lighting-handler.php
require_once '../includes/admin-head.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['classroom_id']) || !isset($input['event_type'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$classroom_id = (int) $input['classroom_id'];
$event_type   = $input['event_type'] === 'on' ? 'on' : 'off';
$triggered_by = 'admin';

// 1. Insert into lighting_logs
$stmt = $conn->prepare("
    INSERT INTO lighting_logs (classroom_id, faculty_id, event_type, triggered_by)
    VALUES (?, NULL, ?, ?)
");
$stmt->bind_param('iss', $classroom_id, $event_type, $triggered_by);

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to insert log: ' . $stmt->error]);
    $stmt->close();
    exit;
}
$stmt->close();

// 2. Update classrooms.light_status so the card reflects it immediately on reload
$stmt2 = $conn->prepare("
    UPDATE classrooms SET light_status = ? WHERE id = ?
");
$stmt2->bind_param('si', $event_type, $classroom_id);

if (!$stmt2->execute()) {
    echo json_encode(['success' => false, 'message' => 'Log saved but classroom status not updated: ' . $stmt2->error]);
    $stmt2->close();
    exit;
}
$stmt2->close();
$conn->close();

echo json_encode(['success' => true, 'event_type' => $event_type]);