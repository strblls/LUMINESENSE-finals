<?php
/**
 * api/archive-sync.php
 * --------------------
 * ESP32 calls this to upload one finished day of per-minute archive rows.
 * Body (JSON):
 *   {
 *     "classroom_id": 3,
 *     "archive_date": "2026-08-02",
 *     "rows": [
 *        { "minute": "07:30:00", "avg_voltage": 220.5, "avg_current": 0.12,
 *          "avg_power": 26.4, "energy_wh": 0.44, "reading_count": 8 }
 *     ]
 *   }
 * Secured with the same X-Device-Token header as api/pzem_push.php.
 * Idempotent: uses INSERT ... ON DUPLICATE KEY UPDATE so re-syncs never duplicate.
 */

header('Content-Type: application/json');

require_once __DIR__ . "/../src/Config/load-env.php";
loadEnv();
require_once __DIR__ . "/../src/Config/config.php";
$expected = DEVICE_TOKEN;
$received = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? 'MISSING';

if ($received !== $expected) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$cid   = (int)($data['classroom_id'] ?? 0);
$date  = (string)($data['archive_date'] ?? '');
$rows  = $data['rows'] ?? [];

if (!$cid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['error' => 'Missing or invalid classroom_id / archive_date']);
    exit;
}
if (!is_array($rows) || count($rows) === 0) {
    echo json_encode(['error' => 'No rows supplied']);
    exit;
}

// Cap the batch to protect the server from malformed firmware.
if (count($rows) > 1500) {
    $rows = array_slice($rows, 0, 1500);
}

require_once __DIR__ . "/../src/Config/db_connect.php";
date_default_timezone_set('Asia/Manila');

// Validate that the classroom exists & is a prototype.
$chk = $conn->prepare("SELECT is_prototype FROM classrooms WHERE id = ?");
$chk->bind_param('i', $cid);
$chk->execute();
$proto = $chk->get_result()->fetch_assoc();
$chk->close();
if (!$proto || empty($proto['is_prototype'])) {
    echo json_encode(['error' => 'Unknown classroom_id']);
    $conn->close(); exit;
}

$stmt = $conn->prepare("
    INSERT INTO pzem_archive
        (classroom_id, archive_date, minute, avg_voltage, avg_current, avg_power, energy_wh, reading_count)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        avg_voltage   = VALUES(avg_voltage),
        avg_current   = VALUES(avg_current),
        avg_power     = VALUES(avg_power),
        energy_wh     = VALUES(energy_wh),
        reading_count = VALUES(reading_count)
");
if (!$stmt) {
    echo json_encode(['error' => 'Prepare failed', 'db_error' => $conn->error]);
    $conn->close(); exit;
}

$inserted = 0;
$updated  = 0;
foreach ($rows as $row) {
    $minute = (string)($row['minute'] ?? '');
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $minute)) continue;

    $v = isset($row['avg_voltage']) ? (float)$row['avg_voltage'] : null;
    $a = isset($row['avg_current']) ? (float)$row['avg_current'] : null;
    $w = isset($row['avg_power'])   ? (float)$row['avg_power']   : null;
    $e = isset($row['energy_wh'])   ? (float)$row['energy_wh']   : 0;
    $n = isset($row['reading_count']) ? (int)$row['reading_count'] : 0;

    $stmt->bind_param('issddddi', $cid, $date, $minute, $v, $a, $w, $e, $n);
    $ok = $stmt->execute();
    if (!$ok) continue;
    if ($stmt->affected_rows === 1) $inserted++; else $updated++;
}
$stmt->close();

echo json_encode([
    'ok'          => true,
    'classroom_id' => $cid,
    'archive_date' => $date,
    'rows_received' => count($rows),
    'inserted'     => $inserted,
    'updated'      => $updated,
]);
$conn->close();
