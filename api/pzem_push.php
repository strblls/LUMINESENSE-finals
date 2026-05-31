
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
<?php
header('Content-Type: application/json');

// Token check
require_once __DIR__ . '/../php/config.php';
$expected = DEVICE_TOKEN;
$received = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? 'MISSING';

if ($received !== $expected) {
    echo json_encode([
        'error' => 'Unauthorized',
        'received_token' => $received,
        'expected_token' => $expected
    ]);
    exit;
}

// Get raw body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode([
        'error' => 'Invalid JSON',
        'raw' => $raw
    ]);
    exit;
}

require_once '../php/db_connect.php';

$cid     = (int)($data['classroom_id'] ?? 0);
$voltage = (float)($data['voltage'] ?? 0);
$current = (float)($data['current'] ?? 0);
$power   = (float)($data['power'] ?? 0);
$energy  = (float)($data['energy'] ?? 0);
$row1    = !empty($data['row1']) ? 1 : 0;
$row2    = !empty($data['row2']) ? 1 : 0;
$row3    = !empty($data['row3']) ? 1 : 0;
$pir     = !empty($data['pir'])  ? 1 : 0;
$state   = (int)($data['state'] ?? 0);

if (!$cid) {
    echo json_encode(['error' => 'Missing classroom_id', 'data' => $data]);
    exit;
}

if (!$voltage) {
    echo json_encode(['error' => 'No valid voltage', 'voltage' => $voltage, 'data' => $data]);
    exit;
}

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

if (!$stmt) {
    echo json_encode(['error' => 'Prepare failed', 'db_error' => $conn->error]);
    exit;
}

$stmt->bind_param('iddddiiiii',
    $cid, $voltage, $current, $power, $energy,
    $row1, $row2, $row3, $pir, $state);

$ok = $stmt->execute();

if (!$ok) {
    echo json_encode(['error' => 'Execute failed', 'db_error' => $stmt->error]);
    $stmt->close();
    $conn->close();
    exit;
}

$affected = $stmt->affected_rows;
$stmt->close();
$conn->close();

echo json_encode([
    'ok' => true,
    'affected_rows' => $affected,
    'classroom_id' => $cid,
    'voltage' => $voltage
]);