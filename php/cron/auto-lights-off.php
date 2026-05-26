<?php
// cron/auto-lights-off.php
// Runs every minute. Turns off lights in rooms where:
//   - The schedule has ended
//   - AND no PIR activity for 10+ minutes
//   - AND lights are still on

require_once __DIR__ . '/../db_connect.php';
date_default_timezone_set('Asia/Manila');

$now_time = date('H:i:s');
$now_day  = date('l');

// Find classrooms where lights are on but schedule ended AND pir has been
// unoccupied (pir_occupied = 0) OR pir_since was 10+ mins ago
$result = $conn->query("
    SELECT c.id, c.room_name
    FROM classrooms c
    WHERE c.light_status = 'on'
      AND c.id NOT IN (
          SELECT DISTINCT classroom_id FROM schedules
          WHERE day_of_week = '$now_day'
            AND start_time <= '$now_time'
            AND COALESCE(extended_until, end_time) >= '$now_time'
      )
      AND (
          c.pir_occupied = 0
          OR c.pir_since <= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
      )
");

while ($room = $result->fetch_assoc()) {
    $cid = (int)$room['id'];

    // Turn off all lights
    $conn->query("
        UPDATE classrooms
        SET light_status = 'off',
            row1_status  = 'off',
            row2_status  = 'off',
            row3_status  = 'off',
            pir_occupied = 0,
            pir_since    = NULL
        WHERE id = $cid
    ");

    // Log it
    $stmt = $conn->prepare("
        INSERT INTO lighting_logs (classroom_id, event_type, triggered_by)
        VALUES (?, 'off', 'auto')
    ");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $stmt->close();

    echo "[" . date('Y-m-d H:i:s') . "] Auto lights-off: {$room['room_name']}\n";
}

$conn->close();