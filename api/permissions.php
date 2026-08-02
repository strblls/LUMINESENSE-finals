<?php
header('Content-Type: application/json');

require_once __DIR__ . "/../src/Session/session_guard.php";
check_admin();
require_once __DIR__ . "/../src/Config/db_connect.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

$faculty_id = (int)($_POST['faculty_id'] ?? 0);
$permission = $_POST['permission'] ?? '';
$value      = (int)($_POST['value'] ?? 0);

if (!$faculty_id || !in_array($permission, ['lighting_control', 'gesture_control'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Ensure a row exists for this faculty
$stmt = $conn->prepare("INSERT IGNORE INTO faculty_permissions (faculty_id) VALUES (?)");
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$stmt->close();

// Update the permission column
$stmt = $conn->prepare("UPDATE faculty_permissions SET $permission = ? WHERE faculty_id = ?");
$stmt->bind_param('ii', $value, $faculty_id);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Permission updated']);
