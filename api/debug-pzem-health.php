<?php
/**
 * api/debug-pzem-health.php
 * ------------------------
 * Admin-only health check for the PZEM push pipeline.
 * Shows whether the ESP32's pzem_push payloads are landing in the DB.
 *
 * GET  ?test=1  → also runs a live self-test that posts a fake PZEM reading
 *                 (with the correct X-Device-Token) exactly like the ESP32 does.
 *
 * Requires admin session (same guard as the analytics pages).
 */
require_once __DIR__ . "/../src/Session/session_guard.php";
check_admin();

require_once __DIR__ . "/../src/Config/load-env.php";
loadEnv();
require_once __DIR__ . "/../src/Config/db_connect.php";
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json');

$out = [
    'ok'        => true,
    'now'       => date('Y-m-d H:i:s'),
    'timezone'  => 'Asia/Manila',
    'db'        => [
        'host'      => DB_HOST,
        'name'      => DB_NAME,
        'connected' => ($conn && !$conn->connect_error),
        'time_zone' => null,
    ],
    'config'    => [
        'env_loaded'        => DEVICE_TOKEN !== '',
        'device_token_set'  => DEVICE_TOKEN !== '',
        'esp32_token_set'   => ESP32_TOKEN !== '',
        'expected_push_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . dirname($_SERVER['SCRIPT_NAME']) . '/pzem_push.php',
    ],
    'rooms'            => [],
    'pzem_live'        => [],
    'readings_today'   => [],
    'readings_recent'  => [],
    'open_sessions'    => [],
    'sessions_today'   => [],
    'self_test'        => null,
];

// Server timezone actually used by MySQL
$tz = $conn->query("SELECT @@session.time_zone AS tz, NOW() AS now")->fetch_assoc();
if ($tz) {
    $out['db']['time_zone'] = $tz['tz'];
    $out['db']['mysql_now'] = $tz['now'];
}

// Classrooms with prototype flag + live PZEM columns
$r = $conn->query("
    SELECT c.id, c.room_name, c.is_prototype,
           c.pzem_voltage, c.pzem_current, c.pzem_power, c.pzem_energy,
           c.light_status
    FROM classrooms c
    ORDER BY c.id
");
while ($row = $r->fetch_assoc()) {
    $out['rooms'][] = [
        'id'           => (int)$row['id'],
        'room_name'    => $row['room_name'],
        'is_prototype' => (int)$row['is_prototype'],
        'pzem_voltage' => $row['pzem_voltage'] !== null ? (float)$row['pzem_voltage'] : null,
        'pzem_current' => $row['pzem_current'] !== null ? (float)$row['pzem_current'] : null,
        'pzem_power'   => $row['pzem_power']   !== null ? (float)$row['pzem_power']   : null,
        'pzem_energy'  => $row['pzem_energy']  !== null ? (float)$row['pzem_energy']  : null,
        'light_status' => $row['light_status'],
    ];
}

// Latest pzem_live row per room
$r = $conn->query("
    SELECT p.*, c.room_name,
           TIMESTAMPDIFF(SECOND, p.updated_at, NOW()) AS secs_ago
    FROM pzem_live p
    JOIN classrooms c ON c.id = p.classroom_id
    ORDER BY c.id
");
while ($row = $r->fetch_assoc()) {
    $out['pzem_live'][] = [
        'classroom_id' => (int)$row['classroom_id'],
        'room_name'    => $row['room_name'],
        'voltage_v'    => (float)$row['voltage_v'],
        'current_a'    => (float)$row['current_a'],
        'power_w'      => (float)$row['power_w'],
        'energy_wh'    => (float)$row['energy_wh'],
        'sys_state'    => (int)$row['sys_state'],
        'updated_at'   => $row['updated_at'],
        'secs_ago'     => (int)$row['secs_ago'],
        'stale'        => ((int)$row['secs_ago']) > 15,
    ];
}

// pzem_readings today
$r = $conn->query("
    SELECT COUNT(*) AS cnt, MIN(recorded_at) AS first_at, MAX(recorded_at) AS last_at
    FROM pzem_readings
    WHERE DATE(recorded_at) = CURDATE()
");
if ($r && $row = $r->fetch_assoc()) {
    $out['readings_today'] = [
        'count'    => (int)$row['cnt'],
        'first_at' => $row['first_at'],
        'last_at'  => $row['last_at'],
    ];
}

// Most recent 10 readings
$r = $conn->query("
    SELECT pr.*, c.room_name
    FROM pzem_readings pr
    JOIN classrooms c ON c.id = pr.classroom_id
    ORDER BY pr.id DESC
    LIMIT 10
");
while ($row = $r->fetch_assoc()) {
    $out['readings_recent'][] = [
        'classroom_id' => (int)$row['classroom_id'],
        'room_name'    => $row['room_name'],
        'voltage'      => (float)$row['voltage'],
        'current'      => (float)$row['current'],
        'power'        => (float)$row['power'],
        'energy'       => (float)$row['energy'],
        'recorded_at'  => $row['recorded_at'],
    ];
}

// Open sessions
$r = $conn->query("
    SELECT ps.id, ps.classroom_id, c.room_name, ps.start_time,
           TIMESTAMPDIFF(MINUTE, ps.start_time, NOW()) AS mins_open
    FROM power_sessions ps
    JOIN classrooms c ON c.id = ps.classroom_id
    WHERE ps.end_time IS NULL
    ORDER BY ps.id DESC
");
while ($row = $r->fetch_assoc()) {
    $out['open_sessions'][] = [
        'id'           => (int)$row['id'],
        'classroom_id' => (int)$row['classroom_id'],
        'room_name'    => $row['room_name'],
        'start_time'   => $row['start_time'],
        'mins_open'    => (int)$row['mins_open'],
    ];
}

// Sessions created today
$r = $conn->query("
    SELECT COUNT(*) AS cnt FROM power_sessions
    WHERE session_date = CURDATE()
");
$out['sessions_today'] = ['count' => (int)($r->fetch_assoc()['cnt'] ?? 0)];

// ── Optional self-test: simulate an ESP32 push exactly like esp.ino ──
if (isset($_GET['test'])) {
    $testPayload = json_encode([
        'type'         => 'pzem',
        'voltage'      => 220.0,
        'current'      => 0.42,
        'power'        => 92.4,
        'energy'       => 15.25,
        'row1'         => true,
        'row2'         => false,
        'row3'         => false,
        'pir'          => false,
        'state'        => 1,
        'classroom_id' => 3,
    ]);

    $ch = curl_init();
    $pushUrl = $out['config']['expected_push_url'];
    curl_setopt_array($ch, [
        CURLOPT_URL            => $pushUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $testPayload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Device-Token: ' . DEVICE_TOKEN,
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body    = curl_exec($ch);
    $status  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $cerr    = curl_error($ch);
    curl_close($ch);

    $out['self_test'] = [
        'posted_to' => $pushUrl,
        'http_code' => $status,
        'response'  => $body,
        'curl_error'=> $cerr ?: null,
    ];

    // Re-check latest live row to confirm it moved
    $r = $conn->query("
        SELECT p.*, c.room_name,
               TIMESTAMPDIFF(SECOND, p.updated_at, NOW()) AS secs_ago
        FROM pzem_live p
        JOIN classrooms c ON c.id = p.classroom_id
        WHERE p.classroom_id = 3
    ");
    if ($row = $r->fetch_assoc()) {
        $out['self_test']['after_live'] = [
            'voltage_v' => (float)$row['voltage_v'],
            'power_w'   => (float)$row['power_w'],
            'updated_at'=> $row['updated_at'],
            'secs_ago'  => (int)$row['secs_ago'],
        ];
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$conn->close();
