<?php
// esp32-schedule-flag.php
// ESP32 polls this every 3s — returns the dirty flag READ-ONLY.
// The flag is cleared by api/esp32-schedule.php after it successfully serves
// the fresh slot list, so a consumed flag is never lost when the follow-up
// schedule fetch fails on flaky WiFi (it just retries on the next poll).

require_once __DIR__ . "/../src/Config/db_connect.php";
header('Content-Type: application/json');

$token        = $_GET['token']        ?? '';
$classroom_id = (int)($_GET['classroom_id'] ?? 0);

if ($token !== ESP32_TOKEN || $classroom_id === 0) {
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

// Read the flag
$res = $conn->query("SELECT schedule_dirty FROM classrooms WHERE id = $classroom_id");
$row = $res->fetch_assoc();

if (!$row) {
    echo json_encode(['dirty' => false]);
    $conn->close();
    exit;
}

$dirty = (bool)$row['schedule_dirty'];

echo json_encode(['dirty' => $dirty]);
$conn->close();
?>