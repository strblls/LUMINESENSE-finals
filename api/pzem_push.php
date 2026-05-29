<?php
/**
 * api/pzem-push.php
 * ──────────────────
 * The ESP32 calls this every ~3 s with the latest PZEM + row state JSON.
 * Matches the payload from streamPzemJson() in main.ino:
 *
 *  { "type":"pzem", "voltage":220.5, "current":0.81, "power":178.4,
 *    "energy":12.3, "row1":true, "row2":false, "row3":true,
 *    "pir":false, "state":1, "classroom_id":3 }
 *
 * classroom_id must be appended by the ESP32 firmware.
 * Secured with the same X-Device-Token header as session-end.php.
 */
header('Content-Type: application/json');

$expected = getenv('LUMINESENSE_DEVICE_TOKEN') ?: 'luminesense-secret-token';
if (($_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '') !== $expected) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$b = json_decode(file_get_contents('php://input'), true);

$cid     = (int)   ($b['classroom_id'] ?? 0);
$voltage = (float) ($b['voltage']      ?? 0);
$current = (float) ($b['current']      ?? 0);
$power   = (float) ($b['power']        ?? 0);
$energy  = (float) ($b['energy']       ?? 0);
$row1    = !empty($b['row1']) ? 1 : 0;
$row2    = !empty($b['row2']) ? 1 : 0;
$row3    = !empty($b['row3']) ? 1 : 0;
$pir     = !empty($b['pir'])  ? 1 : 0;
$state   = (int)   ($b['state']        ?? 0);

if (!$cid) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing classroom_id']);
    exit;
}

require_once __DIR__ . '/../php/db_connect.php';

$stmt = $conn->prepare("
    INSERT INTO pzem_live
        (classroom_id, voltage_v, current_a, power_w, energy_wh,
         row1, row2, row3, pir_active, sys_state)
    VALUES (?, ?, ?, ?, ?,  ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        voltage_v  = VALUES(voltage_v),
        current_a  = VALUES(current_a),
        power_w    = VALUES(power_w),
        energy_wh  = VALUES(energy_wh),
        row1       = VALUES(row1),
        row2       = VALUES(row2),
        row3       = VALUES(row3),
        pir_active = VALUES(pir_active),
        sys_state  = VALUES(sys_state),
        updated_at = CURRENT_TIMESTAMP
");
$stmt->bind_param('iddddiiiii',
    $cid, $voltage, $current, $power, $energy,
    $row1, $row2, $row3, $pir, $state);

$ok = $stmt->execute();
$stmt->close();
$conn->close();

echo json_encode(['ok' => $ok]);