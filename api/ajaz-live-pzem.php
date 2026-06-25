<?php
/**
 * api/ajax-live-pzem.php   (was: ajaz-live-pzem.php — note corrected filename)
 * ─────────────────────────
 * Polled by admin-analytics.js every 3 s.
 * Returns the latest PZEM row for the requested classroom_id (or all rooms).
 *
 * GET ?room_id=3   → single room
 * GET ?room_id=0   → all rooms (returns array keyed by classroom_id)
 */
require_once __DIR__ . '/../php/session_guard.php';
check_admin();
require_once __DIR__ . '/../php/db_connect.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$roomId = (int)($_GET['room_id'] ?? 0);

$stateLabels = [
    0 => 'Outside Schedule',
    1 => 'In Schedule',
    2 => 'Cooldown',
    3 => 'Locked',
];

if ($roomId > 0) {
    // ── Single room ──────────────────────────────────────────────────────────
    $stmt = $conn->prepare("
        SELECT p.*, c.room_name,
               TIMESTAMPDIFF(SECOND, p.updated_at, NOW()) AS secs_ago
        FROM pzem_live p
        JOIN classrooms c ON c.id = p.classroom_id
        WHERE p.classroom_id = ?
    ");
    $stmt->bind_param('i', $roomId);   // ← was missing, causing crash
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode([
            'ok'          => true,
            'stale'       => true,
            'voltage'     => 0, 'current'  => 0,
            'power'       => 0, 'energy'   => 0,
            'row1'        => false, 'row2' => false, 'row3' => false,
            'pir'         => false,
            'state'       => 0,
            'state_label' => 'No Data',
            'light_on'    => false,
            'secs_ago'    => null,
        ]);
        $conn->close(); exit;
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
    // ── All rooms ────────────────────────────────────────────────────────────
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

$conn->close();