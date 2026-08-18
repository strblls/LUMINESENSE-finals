<?php
// api/post_session.php
// POST (JSON body) from ESP32 - saves completed session summary to power_sessions

require_once __DIR__ . "/../src/Config/db_connect.php";
header('Content-Type: application/json');

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']); exit;
}

$cid          = (int)($data['classroom_id']      ?? 3);
$session_date = $data['session_date']            ?? date('Y-m-d');
$start_time   = $data['start_time']              ?? null;
$duration     = (int)($data['duration_mins']     ?? 0);
$trigger      = $data['trigger_source']          ?? 'schedule';
$avg_voltage  = (float)($data['avg_voltage']     ?? 0);
$avg_current  = (float)($data['avg_current']     ?? 0);
$peak_power   = (float)($data['peak_power']      ?? 0);
$energy       = (float)($data['total_energy_wh'] ?? 0);
$pir_reset    = (int)($data['pir_reset_used']    ?? 0);

if (!$start_time || !$duration) {
    echo json_encode(['success' => false, 'message' => 'start_time and duration_mins required']); exit;
}

// Derive end_time from start + duration
$end_time       = date('Y-m-d H:i:s', strtotime("$session_date $start_time") + ($duration * 60));
$start_datetime = "$session_date $start_time";

$valid_triggers = ['pir', 'schedule', 'manual', 'reconcile'];
if (!in_array($trigger, $valid_triggers)) $trigger = 'schedule';

if ($trigger === 'reconcile') {
    // Close orphaned session from blackout - use server now() as end_time
    $upd = $conn->prepare("
        UPDATE power_sessions
        SET end_time = NOW(),
            duration_mins = TIMESTAMPDIFF(MINUTE, start_time, NOW()),
            avg_voltage = ?,
            avg_current = ?,
            total_energy_wh = ?,
            trigger_source = 'reconcile'
        WHERE classroom_id = ?
          AND end_time IS NULL
        LIMIT 1
    ");
    $upd->bind_param('dddi', $avg_voltage, $avg_current, $energy, $cid);
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();
    $msg = $affected > 0 ? 'Orphaned session closed' : 'No open session found';
    echo json_encode(['success' => true, 'message' => $msg]);
    exit;
}

// Option A attribution: first schedule whose window covers this session.
$faculty_id = null;
$af = $conn->prepare("
    SELECT s.faculty_id
    FROM schedules s
    WHERE s.classroom_id = ?
      AND s.day_of_week = DAYNAME(?)
      AND s.faculty_id IS NOT NULL
      AND s.start_time <= TIME(?)
      AND GREATEST(s.end_time, COALESCE(s.extended_until, s.end_time)) >= TIME(?)
    ORDER BY s.start_time ASC
    LIMIT 1
");
$af->bind_param('isss', $cid, $session_date, $start_time, $start_time);
$af->execute();
$afRow = $af->get_result()->fetch_assoc();
if ($afRow) $faculty_id = (int)$afRow['faculty_id'];
$af->close();

$stmt = $conn->prepare("
    INSERT INTO power_sessions
        (classroom_id, session_date, start_time, end_time, duration_mins,
         trigger_source, avg_voltage, avg_current, peak_power,
         total_energy_wh, pir_reset_used, faculty_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param(
    'isssisddddii',
    $cid, $session_date, $start_datetime, $end_time, $duration,
    $trigger, $avg_voltage, $avg_current, $peak_power,
    $energy, $pir_reset, $faculty_id
);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Session logged']);