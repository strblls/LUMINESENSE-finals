<?php
/**
 * api/esp32-config.php
 * Returns system settings consumed by the ESP32 firmware.
 * Token-authenticated (same as other esp32-* endpoints).
 */
require_once __DIR__ . "/../src/Config/db_connect.php";
header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
if ($token !== ESP32_TOKEN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$result = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key = 'pir_inactivity_timeout'");
$timeout = 5; // default
if ($result && $row = $result->fetch_assoc()) {
    $timeout = (int)$row['setting_value'];
    if ($timeout < 1) $timeout = 5;
}

echo json_encode([
    'pir_inactivity_timeout' => $timeout
]);
