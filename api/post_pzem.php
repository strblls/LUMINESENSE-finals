<?php
// api/post_pzem.php
// POST (JSON body) from ESP32 — saves live PZEM reading to pzem_readings
// and updates the live columns on classrooms table

require_once '../php/db_connect.php';
header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']); exit;
}

$cid     = (int)($data['classroom_id'] ?? 3);
$voltage = (float)($data['voltage']    ?? 0);
$current = (float)($data['current']    ?? 0);
$power   = (float)($data['power']      ?? 0);
$energy  = (float)($data['energy']     ?? 0);
$freq    = isset($data['frequency'])   ? (float)$data['frequency']    : null;
$pf      = isset($data['pf'])          ? (float)$data['pf']           : null;

if (!$voltage) {
    echo json_encode(['success' => false, 'message' => 'No valid reading']); exit;
}

// 1. Insert into pzem_readings (historical record)
$stmt = $conn->prepare("
    INSERT INTO pzem_readings 
        (classroom_id, voltage, current, power, energy, frequency, power_factor)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param('idddddd', $cid, $voltage, $current, $power, $energy, $freq, $pf);
$stmt->execute();
$stmt->close();

// 2. Update live columns on classrooms
$stmt = $conn->prepare("
    UPDATE classrooms 
    SET pzem_voltage = ?, pzem_current = ?, pzem_power = ?, pzem_energy = ?
    WHERE id = ?
");
$stmt->bind_param('ddddi', $voltage, $current, $power, $energy, $cid);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true]);