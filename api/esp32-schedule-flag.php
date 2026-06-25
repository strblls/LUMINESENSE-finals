<?php
// esp32-schedule-flag.php
// ESP32 polls this every 5s — returns dirty flag and resets it

header('Content-Type: application/json');

$token        = $_GET['token']        ?? '';
$classroom_id = (int)($_GET['classroom_id'] ?? 0);

if ($token !== 'LS_ESP32_TOKEN_2025' || $classroom_id === 0) {
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'luminesense_db');
if ($conn->connect_error) {
    echo json_encode(['error' => 'db']);
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

// Reset the flag immediately after reading
if ($dirty) {
    $conn->query("UPDATE classrooms SET schedule_dirty = 0 WHERE id = $classroom_id");
}

echo json_encode(['dirty' => $dirty]);
$conn->close();
?>