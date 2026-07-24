<?php
/**
 * api/pir-log.php
 * ──────────────────
 * Dedicated PIR event logger.
 * The ESP32 calls this whenever the Mega detects a PIR state change (GPIO5).
 *
 * POST
 *   classroom_id  INT   REQUIRED
 *   state         INT   1 = motion detected, 0 = motion stopped
 *
 * Secured with X-Device-Token header (same as pzem_push.php).
 */

header('Content-Type: application/json');

// Token check
require_once __DIR__ . '/../php/config.php';
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

require_once '../php/db_connect.php';
date_default_timezone_set('Asia/Manila');

$stmt = $conn->prepare("INSERT INTO pir_logs (classroom_id, state) VALUES (?, ?)");
$stmt->bind_param('ii', $cid, $state);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'id' => $stmt->insert_id, 'classroom_id' => $cid, 'state' => $state]);
} else {
    echo json_encode(['success' => false, 'error' => $stmt->error]);
}

$stmt->close();
$conn->close();
