<?php
// api/esp32-time.php
// Local time source for the ESP32 when NTP (pool.ntp.org) is unreachable
// (e.g. the laptop hotspot has no internet). Returns the server's current
// epoch time in UTC; the firmware applies its +08:00 offset for local time.
// Token-authenticated, mirrors the other esp32-* endpoints.

require_once __DIR__ . "/../src/Config/db_connect.php";
header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
if ($token !== ESP32_TOKEN) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

echo json_encode([
    'success' => true,
    'epoch'   => (int)time(),
]);