<?php
// api/request-extension.php
require_once '../php/db_connect.php';
header('Content-Type: application/json');

if (empty($_SESSION['faculty_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only.']); exit;
}

$faculty_id  = (int)$_SESSION['faculty_id'];
$schedule_id = (int)($_POST['schedule_id'] ?? 0);
$extend_mins = (int)($_POST['extend_mins'] ?? 30);

if (!$schedule_id || $extend_mins <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid data.']); exit;
}

// Make sure no pending request already exists for this schedule
$stmt = $conn->prepare("SELECT id FROM extension_requests WHERE schedule_id = ? AND status = 'pending' LIMIT 1");
$stmt->bind_param('i', $schedule_id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'You already have a pending extension request for this class.']); exit;
}
$stmt->close();

// Insert the request
$stmt = $conn->prepare("INSERT INTO extension_requests (faculty_id, schedule_id, extend_mins) VALUES (?, ?, ?)");
$stmt->bind_param('iii', $faculty_id, $schedule_id, $extend_mins);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Extension request submitted. Waiting for admin approval.']);