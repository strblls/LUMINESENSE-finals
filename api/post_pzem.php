<?php
// api/post_pzem.php
// POST (JSON body) from ESP32 — saves live PZEM reading to pzem_readings,
// updates live columns on classrooms, and auto-manages power_sessions.

require_once '../php/db_connect.php';
header('Content-Type: application/json');

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']); exit;
}

$cid     = (int)($data['classroom_id'] ?? 3);
$voltage = (float)($data['voltage']    ?? 0);
$current = (float)($data['current']    ?? 0);
$power   = (float)($data['power']      ?? 0);
$energy  = (float)($data['energy']     ?? 0);
$freq    = isset($data['frequency'])   ? (float)$data['frequency'] : null;
$pf      = isset($data['pf'])          ? (float)$data['pf']        : null;

// Row states from Mega JSON
$row1  = !empty($data['row1']);
$row2  = !empty($data['row2']);
$row3  = !empty($data['row3']);
$anyOn = $row1 || $row2 || $row3;

if (!$voltage) {
    echo json_encode(['success' => false, 'message' => 'No valid reading']); exit;
}

// ── 1. Insert raw reading ─────────────────────────────────────────────────
$stmt = $conn->prepare("
    INSERT INTO pzem_readings
        (classroom_id, voltage, current, power, energy, frequency, power_factor)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param('idddddd', $cid, $voltage, $current, $power, $energy, $freq, $pf);
$stmt->execute();
$stmt->close();

// ── 2. Update live columns on classrooms ──────────────────────────────────
$stmt = $conn->prepare("
    UPDATE classrooms
    SET pzem_voltage = ?, pzem_current = ?, pzem_power = ?, pzem_energy = ?
    WHERE id = ?
");
$stmt->bind_param('ddddi', $voltage, $current, $power, $energy, $cid);
$stmt->execute();
$stmt->close();

// ── 3. Auto-manage power_sessions ─────────────────────────────────────────
// Strategy:
//   - Lights ON  → open a session if none is open
//   - Lights OFF → close the open session if one exists
//   - Session considered "open" = row with no end_time for this classroom

// Check for open session
$stmt = $conn->prepare("
    SELECT id, start_time FROM power_sessions
    WHERE classroom_id = ? AND end_time IS NULL
    ORDER BY id DESC LIMIT 1
");
$stmt->bind_param('i', $cid);
$stmt->execute();
$openSession = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($anyOn && !$openSession) {
    // ── Open new session ──
    $trigger = 'manual'; // default; Mega can pass trigger_source later
    if (!empty($data['trigger_source'])) {
        $trigger = $data['trigger_source'];
    } elseif (!empty($data['pir'])) {
        $trigger = 'pir';
    } elseif (!empty($data['state']) && (int)$data['state'] === 1) {
        $trigger = 'schedule';
    }

    $stmt = $conn->prepare("
        INSERT INTO power_sessions
            (classroom_id, session_date, start_time, trigger_source)
        VALUES (?, CURDATE(), NOW(), ?)
    ");
    $stmt->bind_param('is', $cid, $trigger);
    $stmt->execute();
    $stmt->close();

} elseif (!$anyOn && $openSession) {
    // ── Close session — compute aggregates from pzem_readings ──
    $sid       = $openSession['id'];
    $startTime = $openSession['start_time'];

    $stmt = $conn->prepare("
        SELECT
            ROUND(AVG(voltage), 2)          AS avg_voltage,
            ROUND(AVG(current), 4)          AS avg_current,
            ROUND(MAX(power), 2)            AS peak_power,
            ROUND(MAX(energy) - MIN(energy), 4) AS total_energy_wh
        FROM pzem_readings
        WHERE classroom_id = ?
          AND recorded_at >= ?
    ");
    $stmt->bind_param('is', $cid, $startTime);
    $stmt->execute();
    $agg = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $avgV    = (float)($agg['avg_voltage']    ?? 0);
    $avgA    = (float)($agg['avg_current']    ?? 0);
    $peakW   = (float)($agg['peak_power']     ?? 0);
    $totalWh = (float)($agg['total_energy_wh'] ?? 0);

    $stmt = $conn->prepare("
        UPDATE power_sessions
        SET
            end_time       = NOW(),
            duration_mins  = ROUND(TIMESTAMPDIFF(SECOND, start_time, NOW()) / 60),
            avg_voltage    = ?,
            avg_current    = ?,
            peak_power     = ?,
            total_energy_wh = ?
        WHERE id = ?
    ");
    $stmt->bind_param('ddddi', $avgV, $avgA, $peakW, $totalWh, $sid);
    $stmt->execute();
    $stmt->close();
}

echo json_encode(['success' => true]);