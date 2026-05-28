<?php
// api/analytics.php
// GET ?classroom_id=X&range=7|14|30
// Returns energy summary, daily chart, heatmap, trigger breakdown, per-session detail

require_once '../php/db_connect.php';
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['faculty_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
}

$range = $_GET['range'] ?? '30';
$cid   = (int)($_GET['classroom_id'] ?? 0);

$days = match($range) {
    'week'  => 7,
    'month' => 30,
    default => (int)$range
};
if (!in_array($days, [7, 14, 30])) $days = 7;

$cid_filter  = $cid ? "AND ps.classroom_id = $cid" : "";
$cid_filter2 = $cid ? "AND ll.classroom_id = $cid" : "";
$cid_filter3 = $cid ? "AND pr.classroom_id = $cid" : "";

// ── 1. Summary cards — from power_sessions ────────────────────────────────
$stmt = $conn->prepare("
    SELECT
        COUNT(*)                            AS total_sessions,
        SUM(duration_mins)                  AS total_minutes,
        ROUND(SUM(total_energy_wh), 2)      AS total_energy_wh,
        ROUND(AVG(avg_voltage), 1)          AS avg_voltage,
        ROUND(AVG(avg_current), 3)          AS avg_current,
        ROUND(MAX(peak_power), 2)           AS peak_power_w
    FROM power_sessions ps
    WHERE ps.session_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
      AND ps.end_time IS NOT NULL
    $cid_filter
");
$stmt->bind_param('i', $days);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fallback: if no sessions yet, pull live averages from pzem_readings
if (!$summary['total_sessions']) {
    $stmt = $conn->prepare("
        SELECT
            ROUND(AVG(voltage), 1)  AS avg_voltage,
            ROUND(AVG(current), 3)  AS avg_current,
            ROUND(MAX(power), 2)    AS peak_power_w,
            ROUND(SUM(power) * (3/3600), 4) AS total_energy_wh
        FROM pzem_readings pr
        WHERE pr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        $cid_filter3
    ");
    $stmt->bind_param('i', $days);
    $stmt->execute();
    $live = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $summary['avg_voltage']   = $live['avg_voltage']   ?? 0;
    $summary['avg_current']   = $live['avg_current']   ?? 0;
    $summary['peak_power_w']  = $live['peak_power_w']  ?? 0;
    $summary['total_energy_wh'] = $live['total_energy_wh'] ?? 0;
}

$summary['total_energy_kwh'] = round(($summary['total_energy_wh'] ?? 0) / 1000, 4);
$summary['est_cost_php']     = round($summary['total_energy_kwh'] * 11, 2);
$summary['peak_power_kw']    = round(($summary['peak_power_w'] ?? 0) / 1000, 4);

// ── 2. Daily energy chart ─────────────────────────────────────────────────
$daily = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));

    // Try power_sessions first
    $stmt = $conn->prepare("
        SELECT
            ROUND(SUM(total_energy_wh), 2)  AS energy_wh,
            COUNT(*)                         AS sessions,
            SUM(duration_mins)               AS minutes
        FROM power_sessions ps
        WHERE ps.session_date = ?
          AND ps.end_time IS NOT NULL
        $cid_filter
    ");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Fallback to pzem_readings if no session data
    if (!$row['sessions']) {
        $stmt = $conn->prepare("
            SELECT ROUND(SUM(power) * (3/3600), 4) AS energy_wh
            FROM pzem_readings pr
            WHERE DATE(pr.recorded_at) = ?
            $cid_filter3
        ");
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $liveRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $row['energy_wh'] = $liveRow['energy_wh'] ?? 0;
    }

    $daily[] = [
        'date'      => $date,
        'label'     => date('D M d', strtotime($date)),
        'energy_wh' => (float)($row['energy_wh'] ?? 0),
        'energy_kw' => round((float)($row['energy_wh'] ?? 0) / 1000, 4),
        'sessions'  => (int)($row['sessions']    ?? 0),
        'minutes'   => (int)($row['minutes']     ?? 0),
    ];
}

// ── 3. Heatmap ────────────────────────────────────────────────────────────
$heatmap = [];
$r = $conn->query("
    SELECT
        DAYOFWEEK(event_time) AS dow,
        HOUR(event_time)      AS hr,
        COUNT(*)              AS cnt
    FROM lighting_logs ll
    WHERE event_type = 'on'
      AND event_time >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
    $cid_filter2
    GROUP BY dow, hr
    ORDER BY dow, hr
");
while ($row = $r->fetch_assoc()) {
    $heatmap[] = [
        'day'   => (int)$row['dow'],
        'hour'  => (int)$row['hr'],
        'count' => (int)$row['cnt'],
    ];
}

// ── 4. Trigger breakdown ──────────────────────────────────────────────────
$triggers = [];
$stmt = $conn->prepare("
    SELECT trigger_source, COUNT(*) AS cnt
    FROM power_sessions ps
    WHERE ps.session_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
      AND ps.end_time IS NOT NULL
    $cid_filter
    GROUP BY trigger_source
");
$stmt->bind_param('i', $days);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $triggers[] = $row;
$stmt->close();

// ── 5. Per-room breakdown ─────────────────────────────────────────────────
$per_room = [];
if (!$cid) {
    $stmt = $conn->prepare("
        SELECT
            c.room_name,
            COUNT(ps.id)                        AS sessions,
            ROUND(SUM(ps.total_energy_wh), 2)   AS energy_wh,
            ROUND(SUM(ps.total_energy_wh)/1000, 4) AS energy_kwh,
            SUM(ps.duration_mins)               AS minutes,
            ROUND(AVG(ps.avg_voltage), 1)       AS avg_voltage,
            ROUND(MAX(ps.peak_power), 2)        AS peak_power_w
        FROM classrooms c
        LEFT JOIN power_sessions ps ON ps.classroom_id = c.id
            AND ps.session_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            AND ps.end_time IS NOT NULL
        GROUP BY c.id
        ORDER BY c.room_name
    ");
    $stmt->bind_param('i', $days);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $per_room[] = $row;
    $stmt->close();
}

// ── 6. Per-session detail (NEW) ───────────────────────────────────────────
$sessions = [];
$stmt = $conn->prepare("
    SELECT
        ps.id,
        c.room_name,
        ps.session_date,
        ps.start_time,
        ps.end_time,
        ps.duration_mins,
        ps.trigger_source,
        ROUND(ps.avg_voltage, 1)            AS avg_voltage,
        ROUND(ps.avg_current, 3)            AS avg_current,
        ROUND(ps.peak_power, 2)             AS peak_power_w,
        ROUND(ps.peak_power / 1000, 4)      AS peak_power_kw,
        ROUND(ps.total_energy_wh, 2)        AS total_energy_wh,
        ROUND(ps.total_energy_wh / 1000, 4) AS total_energy_kwh,
        ROUND((ps.total_energy_wh / 1000) * 11, 2) AS est_cost_php
    FROM power_sessions ps
    JOIN classrooms c ON c.id = ps.classroom_id
    WHERE ps.session_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
      AND ps.end_time IS NOT NULL
    $cid_filter
    ORDER BY ps.start_time DESC
    LIMIT 100
");
$stmt->bind_param('i', $days);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $sessions[] = $row;
$stmt->close();

echo json_encode([
    'success'  => true,
    'range'    => $days,
    'summary'  => $summary,
    'daily'    => $daily,
    'heatmap'  => $heatmap,
    'triggers' => $triggers,
    'per_room' => $per_room,
    'sessions' => $sessions,   // NEW
]);