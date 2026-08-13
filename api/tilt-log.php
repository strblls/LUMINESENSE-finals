<?php
/**
 * api/tilt-log.php
 * ---------
 * Tilt / manhandling alert logger for the prototype hardware.
 * Called by the ESP32 when the Mega's tilt (ball) sensor detects the
 * prototype being tilted/shaken and the piezo buzzer alarm goes off.
 *
 * POST JSON:
 *   classroom_id  INT   REQUIRED
 *   state         INT   1 = tilt detected (alarm raised), 0 = sensor settled
 *
 * state=1 inserts a raw row into tilt_logs AND raises a 'tilt_alert'
 * issue in room_logs so it shows up under "Issues Logged" on
 * admin-reports.php (with a 30s dedup window to survive retries).
 *
 * Secured with X-Device-Token header (same as pir-log.php / pzem_push.php).
 */

header('Content-Type: application/json');

// - Token check -----------------------
require_once __DIR__ . "/../src/Config/load-env.php";
loadEnv();
require_once __DIR__ . "/../src/Config/config.php";
$expected = DEVICE_TOKEN;
$received = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? 'MISSING';

if ($received !== $expected) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['error' => 'Invalid JSON', 'raw' => $raw]);
    exit;
}

$cid   = (int)($data['classroom_id'] ?? 0);
$state = (int)($data['state'] ?? 0);

if (!$cid) {
    echo json_encode(['error' => 'Missing classroom_id']);
    exit;
}

require_once __DIR__ . "/../src/Config/db_connect.php";
date_default_timezone_set('Asia/Manila');

// - 1. Log to tilt_logs -------------------
$stmt = $conn->prepare("INSERT INTO tilt_logs (classroom_id, state) VALUES (?, ?)");
$stmt->bind_param('ii', $cid, $state);
$stmt->execute();
$stmt->close();

// - 2. Raise an issue when tilt is detected ----------
if ($state) {
    $r = $conn->query("SELECT room_name FROM classrooms WHERE id = $cid");
    $room_name = ($r && ($row = $r->fetch_assoc())) ? $row['room_name'] : 'Unknown';

    // Dedup: skip if a tilt_alert was already raised for this room recently
    // (the Mega cooldown + network retries could otherwise double-log).
    $dup = $conn->prepare("
        SELECT id FROM room_logs
        WHERE event_type = 'tilt_alert'
          AND room_name  = ?
          AND event_time > NOW() - INTERVAL 30 SECOND
        LIMIT 1
    ");
    $dup->bind_param('s', $room_name);
    $dup->execute();
    $already = (bool)$dup->get_result()->fetch_assoc();
    $dup->close();

    if (!$already) {
        $notes = 'Tilt/impact detected — possible manhandling of the prototype';
        $stmt = $conn->prepare("
            INSERT INTO room_logs (event_type, room_name, triggered_by, notes)
            VALUES ('tilt_alert', ?, 'Tilt Sensor', ?)
        ");
        $stmt->bind_param('ss', $room_name, $notes);
        $stmt->execute();
        $stmt->close();
    }
}

echo json_encode(['success' => true, 'classroom_id' => $cid, 'state' => $state, 'deduped' => $state ? ($already ?? false) : false]);
$conn->close();
