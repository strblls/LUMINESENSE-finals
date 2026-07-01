<?php
// api/esp32-status.php
require_once '../php/db_connect.php';
header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
if ($token !== 'LS_ESP32_TOKEN_2025') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']); exit;
}

$cid  = (int)($_GET['classroom_id'] ?? 1);
$stmt = $conn->prepare("
    SELECT row1_status, row2_status, row3_status
    FROM classrooms WHERE id = ? LIMIT 1
");
$stmt->bind_param('i', $cid);
$stmt->execute();
$stmt->bind_result($r1, $r2, $r3);
$stmt->fetch();
$stmt->close();

echo json_encode([
    'row1' => $r1 === 'on' ? 1 : 0,
    'row2' => $r2 === 'on' ? 1 : 0,
    'row3' => $r3 === 'on' ? 1 : 0,
]);