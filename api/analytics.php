<?php
// api/analytics.php
// GET ?classroom_id=X&range=7|14|30|month|week
// Returns energy summary, daily chart, heatmap, and trigger breakdown

require_once '../php/db_connect.php';
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['faculty_logged_in'])) {
    http_response_code(401); 
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
}

$range = $_GET['range'] ?? '7';
$cid   = (int)($_GET['classroom_id'] ?? 0);

// Convert range to days
$days = match($range) {
    'week'  => 7,
    'month' => 30,
    default => (int)$range
};
if (!in_array($days, [7, 14, 30])) $days = 7;

$cid_filter  = $cid ? "AND ps.classroom_id = $cid" : "";
$cid_filter2 = $cid ? "AND ll.classroom_id = $cid" : "";

// ── 1. Summary cards ──────────────────────────────────────
$stmt = $conn->prepare("
    SELECT 
        COUNT(*)                        AS total_sessions,
        SUM(duration_mins)              AS total_minutes,
        ROUND(SUM(total_energy_wh), 2)  AS total_energy_wh,
        ROUND(AVG(avg_voltage), 1)      AS avg_voltage,
        ROUND(AVG(avg_current), 3)      AS avg_current
    FROM power_sessions ps
    WHERE ps.session_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    $cid_filter
");
$stmt->bind_param('i', $days);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Add kWh and estimated cost (Meralco ~₱11/kWh)
$summary['total_energy_kwh'] = round(($summary['total_energy_wh'] ?? 0) / 1000, 4);
$summary['est_cost_php']     = round($summary['total_energy_kwh'] * 11, 2);

// ── 2. Daily energy chart ─────────────────────────────────
$daily = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $stmt = $conn->prepare("
        SELECT 
            ROUND(SUM(total_energy_wh), 2) AS energy_wh,
            COUNT(*)                        AS sessions,
            SUM(duration_mins)              AS minutes
        FROM power_sessions ps
        WHERE ps.session_date = ?
        $cid_filter
    ");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $daily[] = [
        'date'      => $date,
        'label'     => date('D M d', strtotime($date)),
        'energy_wh' => (float)($row['energy_wh'] ?? 0),
        'sessions'  => (int)($row['sessions']    ?? 0),
        'minutes'   => (int)($row['minutes']     ?? 0),
    ];
}

// ── 3. Heatmap — hour × day of week ──────────────────────
// Count ON events per hour per day of week from lighting_logs
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
        'day'   => (int)$row['dow'],  // 1=Sun, 2=Mon ... 7=Sat
        'hour'  => (int)$row['hr'],
        'count' => (int)$row['cnt'],
    ];
}

// ── 4. Trigger source breakdown ───────────────────────────
$triggers = [];
$stmt = $conn->prepare("
    SELECT trigger_source, COUNT(*) AS cnt
    FROM power_sessions ps
    WHERE ps.session_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    $cid_filter
    GROUP BY trigger_source
");
$stmt->bind_param('i', $days);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $triggers[] = $row;
$stmt->close();

// ── 5. Per-classroom breakdown (if no specific room filtered)
$per_room = [];
if (!$cid) {
    $stmt = $conn->prepare("
        SELECT 
            c.room_name,
            COUNT(ps.id)                        AS sessions,
            ROUND(SUM(ps.total_energy_wh), 2)   AS energy_wh,
            SUM(ps.duration_mins)               AS minutes
        FROM classrooms c
        LEFT JOIN power_sessions ps ON ps.classroom_id = c.id
            AND ps.session_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY c.id
        ORDER BY c.room_name
    ");
    $stmt->bind_param('i', $days);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $per_room[] = $row;
    $stmt->close();
}

echo json_encode([
    'success'  => true,
    'range'    => $days,
    'summary'  => $summary,
    'daily'    => $daily,
    'heatmap'  => $heatmap,
    'triggers' => $triggers,
    'per_room' => $per_room,
]);