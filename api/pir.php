<?php
// api/pir.php
// POST  classroom_id=X & occupied=1|0
//   â†’ Arduino/PIR sensor webhook.
//   â†’ Also accepts a manual "simulate" call from the dashboard (with session auth).
//
// When occupied=1 AND a schedule is active  â†’ set light_status='on', log event
// When occupied=0                           â†’ set light_status='off', clear pir flag, log event

require_once __DIR__ . "/../src/Config/db_connect.php";
session_start();
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

// Allow Arduino (no session) OR logged-in faculty/admin
$is_arduino  = !empty($_POST['arduino_token']) && $_POST['arduino_token'] === ESP32_TOKEN;
$is_session  = !empty($_SESSION['faculty_logged_in']) || !empty($_SESSION['admin_logged_in']);

if (!$is_arduino && !$is_session) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only.']); exit;
}

$cid      = (int)($_POST['classroom_id'] ?? 0);
$occupied = (int)($_POST['occupied']     ?? 0); // 1 = person detected, 0 = room empty

if (!$cid) {
    echo json_encode(['success' => false, 'message' => 'classroom_id required.']); exit;
}

$now_time = date('H:i:s');
$now_day  = date('l');

// - Check if there is an active schedule right now --------------
$active_schedule = null;
$stmt = $conn->prepare("
    SELECT id, start_time, end_time, extended_until
    FROM schedules
    WHERE classroom_id = ?
      AND day_of_week  = ?
      AND start_time  <= ?
      AND (extended_until >= ? OR (extended_until IS NULL AND end_time >= ?))
    LIMIT 1
");
$stmt->bind_param('issss', $cid, $now_day, $now_time, $now_time, $now_time);
$stmt->execute();
$r = $stmt->get_result();
if ($row = $r->fetch_assoc()) $active_schedule = $row;
$stmt->close();

if ($occupied) {
    // - Person detected ----------------------------
    if ($active_schedule) {
        // Turn on lights and mark occupancy start
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
        // Log the event
        $stmt = $conn->prepare("
            INSERT INTO lighting_logs (classroom_id, event_type, triggered_by)
            VALUES (?, 'on', 'PIR')
        ");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $stmt->close();
        // Log to dedicated pir_logs
        $stmt = $conn->prepare("INSERT INTO pir_logs (classroom_id, state) VALUES (?, 1)");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'action' => 'lights_on', 'schedule' => true]); exit;
    } else {
        // Person present but outside schedule - just flag, don't turn on lights
        $conn->query("
            UPDATE classrooms
            SET pir_occupied = 1,
                pir_since    = CASE WHEN pir_occupied = 0 THEN NOW() ELSE pir_since END
            WHERE id = $cid
        ");
        // Log to dedicated pir_logs
        $stmt = $conn->prepare("INSERT INTO pir_logs (classroom_id, state) VALUES (?, 1)");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'action' => 'occupied_no_schedule']); exit;
    }
} else {
    // - Room cleared → end schedule ---------------------
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
        $conn->query("
            UPDATE classrooms
            SET pir_occupied = 0,
                pir_since    = NULL
            WHERE id = $cid
        ");
        $stmt = $conn->prepare("INSERT INTO pir_logs (classroom_id, state) VALUES (?, 0)");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'action' => 'lights_kept_on', 'override' => true]); exit;
    }

    $conn->query("
        UPDATE schedules
        SET extended_until = CURTIME()
        WHERE classroom_id = $cid
          AND day_of_week  = '$now_day'
          AND start_time  <= '$now_time'
          AND (extended_until >= '$now_time' OR (extended_until IS NULL AND end_time >= '$now_time'))
    ");
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
    $stmt = $conn->prepare("
        INSERT INTO class_logs (classroom_id, event_type, triggered_by, notes)
        VALUES (?, 'class_end', 'PIR', 'Schedule ended by PIR inactivity timeout')
    ");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $stmt->close();
    // Log to dedicated pir_logs
    $stmt = $conn->prepare("INSERT INTO pir_logs (classroom_id, state) VALUES (?, 0)");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true, 'action' => 'lights_off']); exit;
}
