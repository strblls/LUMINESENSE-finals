<?php
// api/live-pzem.php
// GET ?classroom_id=X
// Returns live PZEM readings from classrooms table

require_once '../php/db_connect.php';
header('Content-Type: application/json');

// if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['faculty_logged_in'])) {
//     http_response_code(401);
//     echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
// }

$cid = (int)($_GET['classroom_id'] ?? 0);

if ($cid) {
    $stmt = $conn->prepare("
        SELECT 
            pzem_voltage  AS voltage,
            pzem_current  AS current,
            pzem_power    AS power,
            pzem_energy   AS energy,
            updated_at
        FROM classrooms
        WHERE id = ?
    ");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    // All rooms — sum power, avg voltage
    $row = $conn->query("
        SELECT
            ROUND(AVG(pzem_voltage), 1) AS voltage,
            ROUND(SUM(pzem_current), 3) AS current,
            ROUND(SUM(pzem_power), 2)   AS power,
            ROUND(SUM(pzem_energy), 4)  AS energy,
            MAX(updated_at)             AS updated_at
        FROM classrooms
        WHERE pzem_voltage IS NOT NULL
    ")->fetch_assoc();
}

// Check if lights are on — any open session for this classroom
$lightsOn = false;
if ($cid) {
    $stmt = $conn->prepare("
        SELECT id FROM power_sessions
        WHERE classroom_id = ? AND end_time IS NULL
        LIMIT 1
    ");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $lightsOn = $stmt->get_result()->num_rows > 0;
    $stmt->close();
} else {
    $result = $conn->query("
        SELECT id FROM power_sessions
        WHERE end_time IS NULL LIMIT 1
    ");
    $lightsOn = $result->num_rows > 0;
}

echo json_encode([
    'success'  => true,
    'voltage'  => (float)($row['voltage'] ?? 0),
    'current'  => (float)($row['current'] ?? 0),
    'power'    => (float)($row['power']   ?? 0),
    'power_kw' => round((float)($row['power'] ?? 0) / 1000, 4),
    'energy'   => (float)($row['energy']  ?? 0),
    'lights_on' => $lightsOn,
    'updated_at' => $row['updated_at'] ?? null,
]);