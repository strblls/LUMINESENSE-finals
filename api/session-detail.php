<?php
// api/session-detail.php
// GET ?classroom_id=X&date=YYYY-MM-DD&start=HH:MM:SS&end=HH:MM:SS
// Returns per-session energy detail for a clicked gantt block:
// duration, total energy, est. cost, avg V/A/W, peak power, chart series, anomalies.

require_once __DIR__ . "/../src/Config/db_connect.php";
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['faculty_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
}

$cid   = (int)($_GET['classroom_id'] ?? 0);
$date  = trim($_GET['date'] ?? '');
$start = trim($_GET['start'] ?? '');
$end   = trim($_GET['end'] ?? '');

if ($cid <= 0
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
    || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $start)
    || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $end)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']); exit;
}

$winStart = "$date $start";
$winEnd   = "$date $end";

// Duration (minutes) within the day; sessions are intra-day.
$sp = explode(':', $start);
$ep = explode(':', $end);
$durMin = max(((int)$ep[0] * 60 + (int)$ep[1]) - ((int)$sp[0] * 60 + (int)$sp[1]), 0);

// - 1. Summary from pzem_readings in the window --------------------
$out = [
    'success'        => true,
    'duration_min'   => $durMin,
    'has_data'       => false,
    'avg_voltage'    => 0,
    'avg_current'    => 0,
    'avg_power'      => 0,
    'peak_power_w'   => 0,
    'total_energy_wh'=> 0,
    'est_cost_php'   => 0,
    'readings'       => 0,
    'chart'          => ['labels' => [], 'voltage' => [], 'current' => [], 'power' => []],
    'anomalies'      => [],
];

$stmt = $conn->prepare("
    SELECT
        ROUND(AVG(voltage), 1)                AS avg_voltage,
        ROUND(AVG(current), 3)                AS avg_current,
        ROUND(AVG(power), 2)                  AS avg_power,
        ROUND(MAX(power), 2)                  AS peak_power_w,
        ROUND(SUM(power) * (3/3600), 4)       AS total_energy_wh,
        COUNT(*)                              AS reading_count
    FROM pzem_readings
    WHERE classroom_id = ?
      AND recorded_at >= ?
      AND recorded_at < ?
");
$stmt->bind_param('iss', $cid, $winStart, $winEnd);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$hasReadings = (int)($row['reading_count'] ?? 0) > 0;

if ($hasReadings) {
    $out['has_data']        = true;
    $out['avg_voltage']     = (float)$row['avg_voltage'];
    $out['avg_current']     = (float)$row['avg_current'];
    $out['avg_power']       = (float)$row['avg_power'];
    $out['peak_power_w']    = (float)$row['peak_power_w'];
    $out['total_energy_wh'] = (float)$row['total_energy_wh'];
    $out['readings']        = (int)$row['reading_count'];
    $out['est_cost_php']    = round(($out['total_energy_wh'] / 1000) * 11, 2);

    // - 2. Per-minute chart series --------------------------------
    $stmt = $conn->prepare("
        SELECT HOUR(recorded_at)     AS hr,
               MINUTE(recorded_at)   AS minute,
               ROUND(AVG(voltage),1) AS avg_voltage,
               ROUND(AVG(current),3) AS avg_current,
               ROUND(AVG(power),2)   AS avg_power
        FROM pzem_readings
        WHERE classroom_id = ?
          AND recorded_at >= ?
          AND recorded_at < ?
        GROUP BY hr, minute
        ORDER BY hr, minute
    ");
    $stmt->bind_param('iss', $cid, $winStart, $winEnd);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($m = $r->fetch_assoc()) {
        $lab = str_pad($m['hr'], 2, '0', STR_PAD_LEFT) . ':' . str_pad($m['minute'], 2, '0', STR_PAD_LEFT);
        $out['chart']['labels'][]   = $lab;
        $out['chart']['voltage'][]  = $m['avg_voltage'] !== null ? (float)$m['avg_voltage'] : null;
        $out['chart']['current'][]  = $m['avg_current'] !== null ? (float)$m['avg_current'] : null;
        $out['chart']['power'][]    = $m['avg_power']   !== null ? (float)$m['avg_power']   : null;
    }
    $stmt->close();
}

// - 3. Fallback: exact power_sessions summary row -------------------
if (!$hasReadings) {
    $stmt = $conn->prepare("
        SELECT total_energy_wh, avg_voltage, avg_current, peak_power, duration_mins
        FROM power_sessions
        WHERE classroom_id = ? AND session_date = ?
        ORDER BY ABS(TIMESTAMPDIFF(SECOND, start_time, ?)) ASC
        LIMIT 1
    ");
    $stmt->bind_param('iss', $cid, $date, $winStart);
    $stmt->execute();
    $ps = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($ps) {
        $out['has_data']        = true;
        $out['avg_voltage']     = (float)($ps['avg_voltage'] ?? 0);
        $out['avg_current']     = (float)($ps['avg_current'] ?? 0);
        $out['peak_power_w']    = (float)($ps['peak_power'] ?? 0);
        $out['total_energy_wh'] = (float)($ps['total_energy_wh'] ?? 0);
        $out['duration_min']    = (int)($ps['duration_mins'] ?? $durMin);
        $hrs = $out['duration_min'] / 60;
        $out['avg_power'] = $hrs > 0 ? round($out['total_energy_wh'] / $hrs, 2) : 0;
        $out['est_cost_php'] = round(($out['total_energy_wh'] / 1000) * 11, 2);
    }
}

// - 4. Anomalies raised during the session --------------------------
$stmt = $conn->prepare("
    SELECT rl.id, rl.event_type, rl.room_name, rl.triggered_by, rl.event_time,
           COALESCE(rl.notes, '') AS notes
    FROM room_logs rl
    JOIN classrooms c ON c.room_name = rl.room_name
    WHERE rl.event_type = 'issue_raised'
      AND c.id = ?
      AND rl.event_time >= ?
      AND rl.event_time < ?
    ORDER BY rl.event_time ASC
");
$stmt->bind_param('iss', $cid, $winStart, $winEnd);
$stmt->execute();
$r = $stmt->get_result();
while ($a = $r->fetch_assoc()) {
    $out['anomalies'][] = [
        'id'           => (int)$a['id'],
        'event_type'   => $a['event_type'],
        'room_name'    => $a['room_name'],
        'triggered_by' => $a['triggered_by'],
        'event_time'   => $a['event_time'],
        'notes'        => $a['notes'],
    ];
}
$stmt->close();

echo json_encode($out);
