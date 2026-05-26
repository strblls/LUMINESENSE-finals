<?php
require_once '../php/db_connect.php';
header('Content-Type: application/json');

$token = $_POST['arduino_token'] ?? '';
if ($token !== 'LS_PIR_TOKEN_2025') {
    http_response_code(401);
    echo json_encode(['success' => false]); exit;
}

$cid     = (int)($_POST['classroom_id'] ?? 1);
$voltage = (float)($_POST['voltage'] ?? 0);
$current = (float)($_POST['current'] ?? 0);
$power   = (float)($_POST['power']   ?? 0);
$energy  = (float)($_POST['energy']  ?? 0);

$stmt = $conn->prepare("
    UPDATE classrooms 
    SET pzem_voltage = ?, pzem_current = ?, pzem_power = ?, pzem_energy = ?
    WHERE id = ?
");
$stmt->bind_param('ddddi', $voltage, $current, $power, $energy, $cid);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true]);