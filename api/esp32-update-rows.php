<?php
require_once __DIR__ . "/../src/Config/db_connect.php";
header('Content-Type: application/json');

$token = $_POST['token'] ?? '';
if ($token !== ESP32_TOKEN) {
    http_response_code(401); exit;
}

$cid  = (int)($_POST['classroom_id'] ?? 3);
$row1 = $_POST['row1'] ?? 'off';
$row2 = $_POST['row2'] ?? 'off';
$row3 = $_POST['row3'] ?? 'off';
$light = ($row1 === 'on' || $row2 === 'on' || $row3 === 'on') ? 'on' : 'off';

$stmt = $conn->prepare("UPDATE classrooms SET row1_status=?, row2_status=?, row3_status=?, light_status=?, light_override=0 WHERE id=?");
$stmt->bind_param('ssssi', $row1, $row2, $row3, $light, $cid);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true]);