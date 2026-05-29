<?php
// api/live-pzem.php
// GET ?classroom_id=X
// Returns live PZEM readings from pzem_live table (written by pzem_push.php)

require_once '../php/db_connect.php';
header('Content-Type: application/json');

$cid = (int)($_GET['classroom_id'] ?? 0);

$stateLabels = [
    0 => 'Outside Schedule',
    1 => 'In Schedule',
    2 => 'Cooldown',
    3 => 'Locked',
];

if ($cid) {
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
            'success'   => true,
            'stale'     => true,
            'voltage'   => 0, 'current' => 0,
            'power'     => 0, 'power_kw' => 0,
            'energy'    => 0,
            'lights_on' => false,
            'light_on'  => false,
            'state'     => 0,
            'state_label' => 'No Data',
            'updated_at'  => null,
        ]);
        $conn->close(); exit;
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
    // All rooms — aggregate
    $res = $conn->query("
        SELECT p.*, c.room_name,
               TIMESTAMPDIFF(SECOND, p.updated_at, NOW()) AS secs_ago
        FROM pzem_live p
        JOIN classrooms c ON c.id = p.classroom_id
    ");

    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;

    if (empty($rows)) {
        echo json_encode([
            'success'   => true, 'stale' => true,
            'voltage'   => 0, 'current' => 0,
            'power'     => 0, 'power_kw' => 0,
            'energy'    => 0,
            'lights_on' => false, 'light_on' => false,
            'updated_at'=> null,
        ]);
        $conn->close(); exit;
    }

    $totalPower   = array_sum(array_column($rows, 'power_w'));
    $totalCurrent = array_sum(array_column($rows, 'current_a'));
    $totalEnergy  = array_sum(array_column($rows, 'energy_wh'));
    $avgVoltage   = array_sum(array_column($rows, 'voltage_v')) / count($rows);
    $anyLightOn   = (bool)array_filter($rows, fn($r) => $r['row1'] || $r['row2'] || $r['row3']);

    echo json_encode([
        'success'   => true,
        'stale'     => false,
        'voltage'   => round($avgVoltage, 1),
        'current'   => round($totalCurrent, 3),
        'power'     => round($totalPower, 2),
        'power_kw'  => round($totalPower / 1000, 4),
        'energy'    => round($totalEnergy, 3),
        'lights_on' => $anyLightOn,
        'light_on'  => $anyLightOn,
        'updated_at'=> date('Y-m-d H:i:s'),
    ]);
}

$conn->close();