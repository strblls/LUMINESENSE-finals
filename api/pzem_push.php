
<?php
/**
 * api/pzem-push.php
 * ---------
 * The ESP32 calls this every ~3 s with the latest PZEM + row state JSON.
 * Matches the payload from streamPzemJson() in main.ino:
 *
 *  { "type":"pzem", "voltage":220.5, "current":0.81, "power":178.4,
 *    "energy":12.3, "row1":true, "row2":false, "row3":true,
 *    "pir":false, "state":1, "classroom_id":3 }
 *
 * classroom_id must be appended by the ESP32 firmware.
 * Secured with the same X-Device-Token header as session-end.php.
 */

header('Content-Type: application/json');

// Token check
require_once __DIR__ . "/../src/Config/config.php";
$expected = DEVICE_TOKEN;
$received = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? 'MISSING';

if ($received !== $expected) {
    echo json_encode([
        'error' => 'Unauthorized',
        'received_token' => $received,
        'expected_token' => $expected
    ]);
    exit;
}

// Get raw body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode([
        'error' => 'Invalid JSON',
        'raw' => $raw
    ]);
    exit;
}

require_once __DIR__ . "/../src/Config/db_connect.php";
date_default_timezone_set('Asia/Manila');

$cid     = (int)($data['classroom_id'] ?? 0);
$voltage = (float)($data['voltage'] ?? 0);
$current = (float)($data['current'] ?? 0);
$power   = (float)($data['power'] ?? 0);
$energy  = (float)($data['energy'] ?? 0);
$row1    = !empty($data['row1']) ? 1 : 0;
$row2    = !empty($data['row2']) ? 1 : 0;
$row3    = !empty($data['row3']) ? 1 : 0;
$pir     = !empty($data['pir'])  ? 1 : 0;
$state   = (int)($data['state'] ?? 0);

if (!$cid) {
    echo json_encode(['error' => 'Missing classroom_id', 'data' => $data]);
    exit;
}

if (!$voltage) {
    echo json_encode(['error' => 'No valid voltage', 'voltage' => $voltage, 'data' => $data]);
    exit;
}

$stmt = $conn->prepare("
    INSERT INTO pzem_live
        (classroom_id, voltage_v, current_a, power_w, energy_wh,
         row1, row2, row3, pir_active, sys_state)
    VALUES (?, ?, ?, ?, ?,  ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        voltage_v  = VALUES(voltage_v),
        current_a  = VALUES(current_a),
        power_w    = VALUES(power_w),
        energy_wh  = VALUES(energy_wh),
        row1       = VALUES(row1),
        row2       = VALUES(row2),
        row3       = VALUES(row3),
        pir_active = VALUES(pir_active),
        sys_state  = VALUES(sys_state),
        updated_at = CURRENT_TIMESTAMP
");

if (!$stmt) {
    echo json_encode(['error' => 'Prepare failed', 'db_error' => $conn->error]);
    exit;
}

$stmt->bind_param('iddddiiiii',
    $cid, $voltage, $current, $power, $energy,
    $row1, $row2, $row3, $pir, $state);

$ok = $stmt->execute();

if (!$ok) {
    echo json_encode(['error' => 'Execute failed', 'db_error' => $stmt->error]);
    $stmt->close();
    $conn->close();
    exit;
}

$affected = $stmt->affected_rows;
$stmt->close();

// - Also log to pzem_readings for historical charts -
$now = date('Y-m-d H:i:s');
$stmt2 = $conn->prepare("
    INSERT INTO pzem_readings
        (classroom_id, voltage, current, power, energy, recorded_at)
    VALUES (?, ?, ?, ?, ?, ?)
");
$stmt2->bind_param('idddds', $cid, $voltage, $current, $power, $energy, $now);
$ok2 = $stmt2->execute();
if (!$ok2) {
    echo json_encode(['error' => 'pzem_readings insert failed', 'db_error' => $stmt2->error]);
    $stmt2->close();
    $conn->close();
    exit;
}
$stmt2->close();

// - Anomaly detection: dropout & spike -----------
$any_row_on = (bool)($row1 || $row2 || $row3);
$r = $conn->query("SELECT room_name FROM classrooms WHERE id = $cid");
$room_name = $r && ($row = $r->fetch_assoc()) ? $row['room_name'] : '';

if ($room_name) {

    // Helper: most recent event_type for a given note pattern (within 1 hour)
    $getLastEventType = function($conn, $room_name, $keyword) {
        $stmt = $conn->prepare("
            SELECT event_type FROM room_logs
            WHERE room_name = ? AND notes LIKE ?
              AND event_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ORDER BY id DESC LIMIT 1
        ");
        $like = "%$keyword%";
        $stmt->bind_param('ss', $room_name, $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? $row['event_type'] : null;
    };

    // -- Dropout: lights ON but power near zero --
    if ($any_row_on && $power < DROPOUT_POWER_THRESHOLD && $voltage > 0) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS cnt FROM (
                SELECT power FROM pzem_readings
                WHERE classroom_id = ? ORDER BY id DESC LIMIT " . DROPOUT_CONFIRM_COUNT . "
            ) r WHERE power < " . DROPOUT_POWER_THRESHOLD . "
        ");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $confirmed = (int)$stmt->get_result()->fetch_assoc()['cnt'] >= DROPOUT_CONFIRM_COUNT;
        $stmt->close();

        if ($confirmed) {
            $last = $getLastEventType($conn, $room_name, 'dropout');
            if (!$last || $last === 'issue_resolved') {
                $notes = "Energy dropout detected - lights ON but power near zero ({$power}W)";
                $stmt = $conn->prepare("
                    INSERT INTO room_logs (event_type, room_name, triggered_by, notes)
                    VALUES ('issue_raised', ?, 'PZEM', ?)
                ");
                $stmt->bind_param('ss', $room_name, $notes);
                $stmt->execute();
                $stmt->close();
            }
        }
    } elseif (!$any_row_on || $power >= DROPOUT_POWER_THRESHOLD) {
        $last = $getLastEventType($conn, $room_name, 'dropout');
        if ($last === 'issue_raised') {
            $notes = "Energy dropout resolved - power at {$power}W" . ($any_row_on ? '' : ', lights OFF');
            $stmt = $conn->prepare("
                INSERT INTO room_logs (event_type, room_name, triggered_by, notes)
                VALUES ('issue_resolved', ?, 'PZEM', ?)
            ");
            $stmt->bind_param('ss', $room_name, $notes);
            $stmt->execute();
            $stmt->close();
        }
    }

    // -- Spike: power exceeds NÃ— recent average --
    if ($power > 0 && $any_row_on) {
        $stmt = $conn->prepare("
            SELECT ROUND(AVG(power), 2) AS avg_pwr FROM (
                SELECT power FROM pzem_readings
                WHERE classroom_id = ? AND power > " . DROPOUT_POWER_THRESHOLD . "
                ORDER BY id DESC LIMIT 10
            ) r
        ");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $avg = (float)$stmt->get_result()->fetch_assoc()['avg_pwr'];
        $stmt->close();

        if ($avg > SPIKE_MIN_AVG_POWER && $power > $avg * SPIKE_RAISE_RATIO) {
            $last = $getLastEventType($conn, $room_name, 'spike');
            if (!$last || $last === 'issue_resolved') {
                $notes = "Power spike detected - {$power}W vs typical ~{$avg}W";
                $stmt = $conn->prepare("
                    INSERT INTO room_logs (event_type, room_name, triggered_by, notes)
                    VALUES ('issue_raised', ?, 'PZEM', ?)
                ");
                $stmt->bind_param('ss', $room_name, $notes);
                $stmt->execute();
                $stmt->close();
            }
        } elseif ($avg > SPIKE_MIN_AVG_POWER && $power < $avg * SPIKE_RESOLVE_RATIO) {
            $last = $getLastEventType($conn, $room_name, 'spike');
            if ($last === 'issue_raised') {
                $notes = "Power spike resolved - power returned to {$power}W (normal)";
                $stmt = $conn->prepare("
                    INSERT INTO room_logs (event_type, room_name, triggered_by, notes)
                    VALUES ('issue_resolved', ?, 'PZEM', ?)
                ");
                $stmt->bind_param('ss', $room_name, $notes);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

$conn->close();

echo json_encode([
    'ok' => true,
    'affected_rows' => $affected,
    'classroom_id' => $cid,
    'voltage' => $voltage
]);