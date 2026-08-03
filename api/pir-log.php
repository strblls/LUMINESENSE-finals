<?php
/**
 * api/pir-log.php
 * ---------
 * Dedicated PIR event logger and classroom status updater.
 * Called by the ESP32 whenever the PIR sensor detects a state change.
 *
 * POST JSON:
 *   classroom_id  INT   REQUIRED
 *   state         INT   1 = motion detected, 0 = motion stopped
 *
 * When state=1 AND an active schedule exists â†’ turns lights/rows on.
 * When state=1 AND no active schedule        â†’ marks occupied, leaves lights off.
 * When state=0                               â†’ turns lights/rows off, clears occupancy.
 *
 * Secured with X-Device-Token header (same as pzem_push.php).
 */

header('Content-Type: application/json');

// - Token check -----------------------
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

// - 1. Log to pir_logs -------------------
$stmt = $conn->prepare("INSERT INTO pir_logs (classroom_id, state) VALUES (?, ?)");
$stmt->bind_param('ii', $cid, $state);
$stmt->execute();
$stmt->close();

// - 2. Update classrooms table ---------------
if ($state) {
    // - Motion detected -------------------
    $now_time = date('H:i:s');
    $now_day  = date('l');

    $stmt = $conn->prepare("
        SELECT id FROM schedules
        WHERE classroom_id = ?
          AND day_of_week  = ?
          AND start_time  <= ?
          AND (extended_until >= ? OR (extended_until IS NULL AND end_time >= ?))
        LIMIT 1
    ");
    $stmt->bind_param('issss', $cid, $now_day, $now_time, $now_time, $now_time);
    $stmt->execute();
    $has_schedule = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Read current occupancy - used to detect first motion of the session
    $stmt = $conn->prepare("SELECT pir_occupied FROM classrooms WHERE id = ?");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $was_empty = !(bool)$stmt->get_result()->fetch_assoc()['pir_occupied'];
    $stmt->close();

    $is_fresh_class = $has_schedule && $was_empty;

    if ($has_schedule) {
        $conn->query("
            UPDATE classrooms
            SET light_status   = 'on',
                row1_status    = 'on',
                row2_status    = 'on',
                row3_status    = 'on',
                pir_occupied   = 1,
                pir_since      = CASE WHEN pir_occupied = 0 THEN NOW() ELSE pir_since END,
                light_override = 0
            WHERE id = $cid
        ");
        $stmt = $conn->prepare("
            INSERT INTO lighting_logs (classroom_id, event_type, triggered_by)
            VALUES (?, 'on', 'PIR')
        ");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $stmt->close();
        if ($is_fresh_class) {
            $stmt = $conn->prepare("
                INSERT INTO class_logs (classroom_id, event_type, triggered_by)
                VALUES (?, 'class_start', 'PIR')
            ");
            $stmt->bind_param('i', $cid);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $conn->query("
            UPDATE classrooms
            SET pir_occupied = 1,
                pir_since    = CASE WHEN pir_occupied = 0 THEN NOW() ELSE pir_since END
            WHERE id = $cid
        ");
        $r = $conn->query("SELECT room_name FROM classrooms WHERE id = $cid");
        if ($r && ($row = $r->fetch_assoc())) {
            $notes = 'PIR motion detected outside schedule hours';
            $stmt = $conn->prepare("
                INSERT INTO room_logs (event_type, room_name, triggered_by, notes)
                VALUES ('issue_raised', ?, 'PIR', ?)
            ");
            $stmt->bind_param('ss', $row['room_name'], $notes);
            $stmt->execute();
            $stmt->close();
        }
    }
} else {
    // - Motion stopped for 5 min â†’ end schedule, clear room -
    $now_time = date('H:i:s');
    $now_day  = date('l');

    // A manual UI override (faculty/admin/gesture turned lights on) must not
    // be reverted by PIR inactivity - only auto-managed lights get auto-off.
    $override = 0;
    $stmt = $conn->prepare("SELECT light_override FROM classrooms WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $or = $stmt->get_result()->fetch_assoc();
    $override = $or ? (int)$or['light_override'] : 0;
    $stmt->close();

    if ($override) {
        // Keep the lights and schedule as-is; only clear occupancy tracking.
        $conn->query("
            UPDATE classrooms
            SET pir_occupied = 0,
                pir_since    = NULL
            WHERE id = $cid
        ");
        echo json_encode(['success' => true, 'classroom_id' => $cid, 'state' => $state, 'skipped' => 'manual_override']); exit;
    }

    // End the current active schedule (same mechanism as "End Early" button)
    $conn->query("
        UPDATE schedules
        SET extended_until = CURTIME()
        WHERE classroom_id = $cid
          AND day_of_week  = '$now_day'
          AND start_time  <= '$now_time'
          AND (extended_until >= '$now_time' OR (extended_until IS NULL AND end_time >= '$now_time'))
    ");
    $schedule_was_active = $conn->affected_rows > 0;

    $conn->query("
        UPDATE classrooms
        SET light_status   = 'off',
            row1_status    = 'off',
            row2_status    = 'off',
            row3_status    = 'off',
            pir_occupied   = 0,
            pir_since      = NULL,
            schedule_dirty = 1
        WHERE id = $cid
    ");
    $stmt = $conn->prepare("
        INSERT INTO lighting_logs (classroom_id, event_type, triggered_by)
        VALUES (?, 'off', 'PIR')
    ");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $stmt->close();

    if ($schedule_was_active) {
        $stmt = $conn->prepare("
            INSERT INTO class_logs (classroom_id, event_type, triggered_by, notes)
            VALUES (?, 'class_end', 'PIR', 'Schedule ended by PIR inactivity timeout')
        ");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $stmt->close();
    } else {
        $r = $conn->query("SELECT room_name FROM classrooms WHERE id = $cid");
        if ($r && ($row = $r->fetch_assoc())) {
            $notes = 'PIR inactivity timeout triggered with no active schedule';
            $stmt = $conn->prepare("
                INSERT INTO room_logs (event_type, room_name, triggered_by, notes)
                VALUES ('issue_raised', ?, 'PIR', ?)
            ");
            $stmt->bind_param('ss', $row['room_name'], $notes);
            $stmt->execute();
            $stmt->close();
        }
    }
}

echo json_encode(['success' => true, 'classroom_id' => $cid, 'state' => $state]);
$conn->close();
