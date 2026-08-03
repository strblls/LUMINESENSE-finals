<?php
/**
 * api/overview-live.php
 * ----------------------
 * Polled by admin-overview.js to keep the room cards' device-strips and
 * 7-day sparklines fresh while the ESP32 + Arduino (SEL 1) is streaming.
 *
 * Returns per-room live PZEM values + row/light status + 7-day spark series.
 */
require_once __DIR__ . "/../src/Session/session_guard.php";
check_admin();
require_once __DIR__ . "/../src/Config/db_connect.php";

header('Content-Type: application/json');
header('Cache-Control: no-store');

date_default_timezone_set('Asia/Manila');

$last7 = [];
for ($i = 6; $i >= 0; $i--) $last7[] = date('Y-m-d', strtotime("-$i days"));

$roomRows = [];
$r = $conn->query("
    SELECT c.id, c.room_name, c.row1_status, c.row2_status, c.row3_status, c.light_status,
           p.voltage_v, p.current_a, p.power_w, p.energy_wh,
           TIMESTAMPDIFF(SECOND, p.updated_at, NOW()) AS fresh_secs
    FROM classrooms c
    LEFT JOIN pzem_live p ON p.classroom_id = c.id
    ORDER BY c.room_name
");
while ($row = $r->fetch_assoc()) $roomRows[$row['id']] = $row;

$dailyByRoom = [];
$rd = $conn->query("
    SELECT classroom_id, DATE(recorded_at) AS d,
           ROUND(SUM(power) * (3/3600), 4) AS energy_wh,
           ROUND(AVG(voltage), 1) AS avg_voltage,
           ROUND(AVG(current), 3) AS avg_current,
           ROUND(AVG(power), 2) AS avg_power
    FROM pzem_readings
    WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY classroom_id, DATE(recorded_at)
");
while ($row = $rd->fetch_assoc()) $dailyByRoom[$row['classroom_id']][$row['d']] = $row;

$rooms = [];
foreach ($roomRows as $rid => $room) {
    $spark = []; $sparkV = []; $sparkA = []; $sparkW = [];
    foreach ($last7 as $d) {
        $day = $dailyByRoom[$rid][$d] ?? null;
        $spark[]  = $day ? (float)$day['energy_wh']   : 0;
        $sparkV[] = $day ? (float)$day['avg_voltage'] : 0;
        $sparkA[] = $day ? (float)$day['avg_current'] : 0;
        $sparkW[] = $day ? (float)$day['avg_power']   : 0;
    }
    $rooms[] = [
        'id'          => (int)$rid,
        'row1_status' => $room['row1_status'] ?? 'off',
        'row2_status' => $room['row2_status'] ?? 'off',
        'row3_status' => $room['row3_status'] ?? 'off',
        'light_status'=> $room['light_status'] ?? 'off',
        'voltage_v'   => $room['voltage_v'] !== null ? (float)$room['voltage_v'] : null,
        'current_a'   => $room['current_a'] !== null ? (float)$room['current_a'] : null,
        'power_w'     => $room['power_w']   !== null ? (float)$room['power_w']   : null,
        'energy_wh'   => $room['energy_wh'] !== null ? (float)$room['energy_wh'] : null,
        'fresh_secs'  => $room['fresh_secs'] !== null ? (int)$room['fresh_secs'] : null,
        'is_live'     => $room['fresh_secs'] !== null && (int)$room['fresh_secs'] <= 60,
        'spark'       => $spark,
        'sparkV'      => $sparkV,
        'sparkA'      => $sparkA,
        'sparkW'      => $sparkW,
    ];
}

echo json_encode([
    'ok'    => true,
    'rooms' => $rooms,
]);

$conn->close();