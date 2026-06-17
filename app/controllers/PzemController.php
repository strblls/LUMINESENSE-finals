<?php
// api/PzemController.php
// Handles all PZEM / power-session endpoints, formerly:
//   pzem_push.php       → action=push        (ESP32, X-Device-Token header)
//   pzem-update.php     → action=update       (Arduino/Mega, PZEM_TOKEN)
//   post_pzem.php       → action=post_pzem    (ESP32, DEVICE_TOKEN)
//   post_session.php    → action=post_session (ESP32, DEVICE_TOKEN)
//   live-pzem.php       → action=live         (public dashboard poll)
//   ajaz-live-pzem.php  → action=ajax_live    (admin-only dashboard poll)

declare(strict_types=1);

require_once __DIR__ . '/../../php/config.php';
require_once __DIR__ . '/../../php/db_connect.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// ── Routing ───────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';

match ($action) {
    'push'         => handle_push($conn),
    'update'       => handle_update($conn),
    'post_pzem'    => handle_post_pzem($conn),
    'post_session' => handle_post_session($conn),
    'live'         => handle_live($conn),
    'ajax_live'    => handle_ajax_live($conn),
    default        => bad_request("Unknown action: {$action}"),
};


// ── Auth helpers ──────────────────────────────────────────────────────────────

/**
 * ESP32 authenticates via X-Device-Token header.
 */
function require_device_token(): void
{
    $received = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '';
    if ($received !== DEVICE_TOKEN) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

/**
 * Arduino/Mega authenticates via POST field arduino_token.
 */
function require_pzem_token(): void
{
    $received = $_POST['arduino_token'] ?? '';
    if ($received !== PZEM_TOKEN) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

/**
 * Admin dashboard endpoints — must have a valid admin session.
 */
function require_admin_session(): void
{
    require_once __DIR__ . '/../php/session_guard.php';
    check_admin();
}

/**
 * Parse and validate JSON body. Returns decoded array or exits with 400.
 */
function parse_json_body(): array
{
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON', 'raw' => $raw]);
        exit;
    }

    return $data;
}


// ── Handlers ──────────────────────────────────────────────────────────────────

/**
 * POST ?action=push   (X-Device-Token header)
 *
 * ESP32 calls this every ~3 s with the latest PZEM + row-state JSON.
 * Upserts into pzem_live table.
 *
 * Payload (from streamPzemJson() in main.ino):
 *   { "type":"pzem", "voltage":220.5, "current":0.81, "power":178.4,
 *     "energy":12.3, "row1":true, "row2":false, "row3":true,
 *     "pir":false, "state":1, "classroom_id":3 }
 *
 * Formerly: pzem_push.php
 */
function handle_push(mysqli $conn): void
{
    require_device_token();

    $data = parse_json_body();

    $cid     = (int)($data['classroom_id'] ?? 0);
    $voltage = (float)($data['voltage']    ?? 0);
    $current = (float)($data['current']    ?? 0);
    $power   = (float)($data['power']      ?? 0);
    $energy  = (float)($data['energy']     ?? 0);
    $row1    = !empty($data['row1']) ? 1 : 0;
    $row2    = !empty($data['row2']) ? 1 : 0;
    $row3    = !empty($data['row3']) ? 1 : 0;
    $pir     = !empty($data['pir'])  ? 1 : 0;
    $state   = (int)($data['state']        ?? 0);

    if (!$cid) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing classroom_id']);
        exit;
    }

    if ($voltage <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid voltage', 'voltage' => $voltage]);
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
        http_response_code(500);
        echo json_encode(['error' => 'Prepare failed', 'db_error' => $conn->error]);
        exit;
    }

    $stmt->bind_param('iddddiiiii',
        $cid, $voltage, $current, $power, $energy,
        $row1, $row2, $row3, $pir, $state
    );

    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['error' => 'Execute failed', 'db_error' => $stmt->error]);
        $stmt->close();
        exit;
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    echo json_encode([
        'ok'            => true,
        'affected_rows' => $affected,
        'classroom_id'  => $cid,
        'voltage'       => $voltage,
    ]);
}

/**
 * POST ?action=update   (arduino_token POST field, PZEM_TOKEN)
 *
 * Arduino/Mega posts form-encoded PZEM data.
 * Updates live columns on classrooms table.
 *
 * Formerly: pzem-update.php
 */
function handle_update(mysqli $conn): void
{
    require_pzem_token();

    $cid     = (int)($_POST['classroom_id'] ?? 1);
    $voltage = (float)($_POST['voltage']    ?? 0);
    $current = (float)($_POST['current']    ?? 0);
    $power   = (float)($_POST['power']      ?? 0);
    $energy  = (float)($_POST['energy']     ?? 0);

    $stmt = $conn->prepare("
        UPDATE classrooms
        SET pzem_voltage = ?, pzem_current = ?, pzem_power = ?, pzem_energy = ?
        WHERE id = ?
    ");
    $stmt->bind_param('ddddi', $voltage, $current, $power, $energy, $cid);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);
}

/**
 * POST ?action=post_pzem   (X-Device-Token header, DEVICE_TOKEN)
 *
 * ESP32 posts a live PZEM reading. Inserts into pzem_readings,
 * updates live columns on classrooms, and auto-manages power_sessions.
 *
 * Formerly: post_pzem.php
 */
function handle_post_pzem(mysqli $conn): void
{
    require_device_token();

    $data = parse_json_body();

    $cid     = (int)($data['classroom_id'] ?? 3);
    $voltage = (float)($data['voltage']    ?? 0);
    $current = (float)($data['current']    ?? 0);
    $power   = (float)($data['power']      ?? 0);
    $energy  = (float)($data['energy']     ?? 0);
    $freq    = isset($data['frequency'])   ? (float)$data['frequency'] : null;
    $pf      = isset($data['pf'])          ? (float)$data['pf']        : null;

    $row1  = !empty($data['row1']);
    $row2  = !empty($data['row2']);
    $row3  = !empty($data['row3']);
    $anyOn = $row1 || $row2 || $row3;

    if (!$voltage) {
        echo json_encode(['success' => false, 'message' => 'No valid reading']);
        exit;
    }

    // ── 1. Insert raw reading ─────────────────────────────────────────────
    $stmt = $conn->prepare("
        INSERT INTO pzem_readings
            (classroom_id, voltage, current, power, energy, frequency, power_factor)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('idddddd', $cid, $voltage, $current, $power, $energy, $freq, $pf);
    $stmt->execute();
    $stmt->close();

    // ── 2. Update live columns on classrooms ──────────────────────────────
    $stmt = $conn->prepare("
        UPDATE classrooms
        SET pzem_voltage = ?, pzem_current = ?, pzem_power = ?, pzem_energy = ?
        WHERE id = ?
    ");
    $stmt->bind_param('ddddi', $voltage, $current, $power, $energy, $cid);
    $stmt->execute();
    $stmt->close();

    // ── 3. Auto-manage power_sessions ─────────────────────────────────────
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
        // Open new session
        $trigger = 'manual';
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
        // Close session — compute aggregates from pzem_readings
        $sid       = $openSession['id'];
        $startTime = $openSession['start_time'];

        $stmt = $conn->prepare("
            SELECT
                ROUND(AVG(voltage), 2)                  AS avg_voltage,
                ROUND(AVG(current), 4)                  AS avg_current,
                ROUND(MAX(power), 2)                    AS peak_power,
                ROUND(MAX(energy) - MIN(energy), 4)     AS total_energy_wh
            FROM pzem_readings
            WHERE classroom_id = ?
              AND recorded_at >= ?
        ");
        $stmt->bind_param('is', $cid, $startTime);
        $stmt->execute();
        $agg = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $avgV    = (float)($agg['avg_voltage']     ?? 0);
        $avgA    = (float)($agg['avg_current']     ?? 0);
        $peakW   = (float)($agg['peak_power']      ?? 0);
        $totalWh = (float)($agg['total_energy_wh'] ?? 0);

        $stmt = $conn->prepare("
            UPDATE power_sessions
            SET
                end_time        = NOW(),
                duration_mins   = ROUND(TIMESTAMPDIFF(SECOND, start_time, NOW()) / 60),
                avg_voltage     = ?,
                avg_current     = ?,
                peak_power      = ?,
                total_energy_wh = ?
            WHERE id = ?
        ");
        $stmt->bind_param('ddddi', $avgV, $avgA, $peakW, $totalWh, $sid);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode(['success' => true]);
}

/**
 * POST ?action=post_session   (X-Device-Token header, DEVICE_TOKEN)
 *
 * ESP32 posts a completed session summary.
 * Inserts a fully-formed row into power_sessions.
 *
 * Formerly: post_session.php
 */
function handle_post_session(mysqli $conn): void
{
    require_device_token();

    $data = parse_json_body();

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
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'start_time and duration_mins required']);
        exit;
    }

    $end_time       = date('Y-m-d H:i:s', strtotime("{$session_date} {$start_time}") + ($duration * 60));
    $start_datetime = "{$session_date} {$start_time}";

    $valid_triggers = ['pir', 'schedule', 'manual'];
    if (!in_array($trigger, $valid_triggers)) $trigger = 'schedule';

    $stmt = $conn->prepare("
        INSERT INTO power_sessions
            (classroom_id, session_date, start_time, end_time, duration_mins,
             trigger_source, avg_voltage, avg_current, peak_power,
             total_energy_wh, pir_reset_used)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        'isssisddddi',
        $cid, $session_date, $start_datetime, $end_time, $duration,
        $trigger, $avg_voltage, $avg_current, $peak_power,
        $energy, $pir_reset
    );
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Session logged']);
}

/**
 * GET ?action=live&classroom_id=X
 *
 * Public dashboard poll. Returns live PZEM data for one room or all rooms.
 * classroom_id=0 (or omitted) → aggregate across all rooms.
 *
 * Formerly: live-pzem.php
 */
function handle_live(mysqli $conn): void
{
    $cid = (int)($_GET['classroom_id'] ?? 0);

    $stateLabels = [
        0 => 'Outside Schedule',
        1 => 'In Schedule',
        2 => 'Cooldown',
        3 => 'Locked',
    ];

    if ($cid) {
        // ── Single room ──────────────────────────────────────────────────
        $stmt = $conn->prepare("
            SELECT p.*, c.room_name,
                   TIMESTAMPDIFF(SECOND, p.updated_at, NOW()) AS secs_ago
            FROM pzem_live p
            JOIN classrooms c ON c.id = p.classroom_id
            WHERE p.classroom_id = ?
        ");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            echo json_encode([
                'success'     => true,
                'stale'       => true,
                'voltage'     => 0, 'current'     => 0,
                'power'       => 0, 'power_kw'    => 0,
                'energy'      => 0,
                'lights_on'   => false,
                'light_on'    => false,
                'state'       => 0,
                'state_label' => 'No Data',
                'updated_at'  => null,
            ]);
            return;
        }

        $secsAgo = (int)$row['secs_ago'];
        echo json_encode([
            'success'     => true,
            'stale'       => $secsAgo > 15,
            'room_name'   => $row['room_name'],
            'voltage'     => (float)$row['voltage_v'],
            'current'     => (float)$row['current_a'],
            'power'       => (float)$row['power_w'],
            'power_kw'    => round((float)$row['power_w'] / 1000, 4),
            'energy'      => (float)$row['energy_wh'],
            'row1'        => (bool)$row['row1'],
            'row2'        => (bool)$row['row2'],
            'row3'        => (bool)$row['row3'],
            'pir'         => (bool)$row['pir_active'],
            'state'       => (int)$row['sys_state'],
            'state_label' => $stateLabels[$row['sys_state']] ?? 'Unknown',
            'lights_on'   => ($row['row1'] || $row['row2'] || $row['row3']),
            'light_on'    => ($row['row1'] || $row['row2'] || $row['row3']),
            'secs_ago'    => $secsAgo,
            'updated_at'  => $row['updated_at'],
        ]);

    } else {
        // ── All rooms — aggregate ────────────────────────────────────────
        $res  = $conn->query("
            SELECT p.*, c.room_name,
                   TIMESTAMPDIFF(SECOND, p.updated_at, NOW()) AS secs_ago
            FROM pzem_live p
            JOIN classrooms c ON c.id = p.classroom_id
        ");

        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;

        if (empty($rows)) {
            echo json_encode([
                'success'   => true,  'stale'     => true,
                'voltage'   => 0,     'current'   => 0,
                'power'     => 0,     'power_kw'  => 0,
                'energy'    => 0,
                'lights_on' => false, 'light_on'  => false,
                'updated_at'=> null,
            ]);
            return;
        }

        $allStale = !array_filter($rows, fn($r) => (int)($r['secs_ago'] ?? 999) <= 15);

        $totalPower   = array_sum(array_column($rows, 'power_w'));
        $totalCurrent = array_sum(array_column($rows, 'current_a'));
        $totalEnergy  = array_sum(array_column($rows, 'energy_wh'));
        $avgVoltage   = array_sum(array_column($rows, 'voltage_v')) / count($rows);
        $anyLightOn   = (bool)array_filter($rows, fn($r) => $r['row1'] || $r['row2'] || $r['row3']);

        echo json_encode([
            'success'   => true,
            'stale'     => $allStale,
            'voltage'   => round($avgVoltage,   1),
            'current'   => round($totalCurrent, 3),
            'power'     => round($totalPower,   2),
            'power_kw'  => round($totalPower / 1000, 4),
            'energy'    => round($totalEnergy,  3),
            'lights_on' => $anyLightOn,
            'light_on'  => $anyLightOn,
            'updated_at'=> date('Y-m-d H:i:s'),
        ]);
    }
}

/**
 * GET ?action=ajax_live&room_id=X   (admin session required)
 *
 * Polled by admin-analytics.js every 3 s.
 * room_id=0 → all rooms (returns array keyed by classroom_id + summary).
 * room_id>0 → single room detail.
 *
 * Formerly: ajaz-live-pzem.php
 */
function handle_ajax_live(mysqli $conn): void
{
    require_admin_session();

    $roomId = (int)($_GET['room_id'] ?? 0);

    $stateLabels = [
        0 => 'Outside Schedule',
        1 => 'In Schedule',
        2 => 'Cooldown',
        3 => 'Locked',
    ];

    if ($roomId > 0) {
        // ── Single room ──────────────────────────────────────────────────
        $stmt = $conn->prepare("
            SELECT p.*, c.room_name,
                   TIMESTAMPDIFF(SECOND, p.updated_at, NOW()) AS secs_ago
            FROM pzem_live p
            JOIN classrooms c ON c.id = p.classroom_id
            WHERE p.classroom_id = ?
        ");
        $stmt->bind_param('i', $roomId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            echo json_encode([
                'ok'          => true,
                'stale'       => true,
                'voltage'     => 0,     'current'     => 0,
                'power'       => 0,     'energy'      => 0,
                'row1'        => false, 'row2'        => false, 'row3' => false,
                'pir'         => false,
                'state'       => 0,
                'state_label' => 'No Data',
                'light_on'    => false,
                'secs_ago'    => null,
            ]);
            return;
        }

        $secsAgo = (int)$row['secs_ago'];
        echo json_encode([
            'ok'          => true,
            'stale'       => $secsAgo > 15,
            'room_name'   => $row['room_name'],
            'voltage'     => (float)$row['voltage_v'],
            'current'     => (float)$row['current_a'],
            'power'       => (float)$row['power_w'],
            'energy'      => (float)$row['energy_wh'],
            'row1'        => (bool)$row['row1'],
            'row2'        => (bool)$row['row2'],
            'row3'        => (bool)$row['row3'],
            'pir'         => (bool)$row['pir_active'],
            'state'       => (int)$row['sys_state'],
            'state_label' => $stateLabels[$row['sys_state']] ?? 'Unknown',
            'light_on'    => ($row['row1'] || $row['row2'] || $row['row3']),
            'secs_ago'    => $secsAgo,
        ]);

    } else {
        // ── All rooms ────────────────────────────────────────────────────
        $res = $conn->query("
            SELECT p.*, c.room_name,
                   TIMESTAMPDIFF(SECOND, p.updated_at, NOW()) AS secs_ago
            FROM pzem_live p
            JOIN classrooms c ON c.id = p.classroom_id
            ORDER BY c.room_name
        ");

        $out = [];
        while ($row = $res->fetch_assoc()) {
            $secsAgo = (int)$row['secs_ago'];
            $out[$row['classroom_id']] = [
                'room_name'   => $row['room_name'],
                'stale'       => $secsAgo > 15,
                'voltage'     => (float)$row['voltage_v'],
                'current'     => (float)$row['current_a'],
                'power'       => (float)$row['power_w'],
                'energy'      => (float)$row['energy_wh'],
                'row1'        => (bool)$row['row1'],
                'row2'        => (bool)$row['row2'],
                'row3'        => (bool)$row['row3'],
                'pir'         => (bool)$row['pir_active'],
                'state'       => (int)$row['sys_state'],
                'state_label' => $stateLabels[$row['sys_state']] ?? 'Unknown',
                'light_on'    => ($row['row1'] || $row['row2'] || $row['row3']),
                'secs_ago'    => $secsAgo,
            ];
        }

        $totalPower  = array_sum(array_column($out, 'power'));
        $totalEnergy = array_sum(array_column($out, 'energy'));
        $avgVoltage  = count($out) > 0
            ? array_sum(array_column($out, 'voltage')) / count($out)
            : 0;

        echo json_encode([
            'ok'      => true,
            'rooms'   => $out,
            'summary' => [
                'total_power_w'   => round($totalPower,  2),
                'total_energy_wh' => round($totalEnergy, 3),
                'avg_voltage_v'   => round($avgVoltage,  2),
            ],
        ]);
    }
}


// ── Helpers ───────────────────────────────────────────────────────────────────

function bad_request(string $message): void
{
    http_response_code(400);
    echo json_encode(['error' => $message]);
    exit;
}