<?php
// api/analytics.php
// GET ?range=7|30|month     → energy summary per classroom + daily chart data
// Both admin and faculty can read.

require_once '../php/db_connect.php';
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['faculty_logged_in'])) {
    http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit;
}

$range = (int)($_GET['range'] ?? 7);
if (!in_array($range, [7, 14, 30])) $range = 7;

// kWh formula: 9 bulbs × 3W = 27W per ON event, assume 1hr per event
const WATTS = 27;

// Per-classroom summary
$stats = [];
$r = $conn->query("
    SELECT c.id, c.room_name, c.room_size,
           SUM(CASE WHEN l.event_type='on' THEN 1 ELSE 0 END) AS on_count,
           SUM(CASE WHEN l.event_type='security_alert' THEN 1 ELSE 0 END) AS alert_count
    FROM classrooms c
    LEFT JOIN lighting_logs l ON l.classroom_id=c.id
        AND l.event_time >= DATE_SUB(NOW(), INTERVAL {$range} DAY)
    GROUP BY c.id ORDER BY c.room_name
");
while ($row = $r->fetch_assoc()) {
    $row['est_kwh'] = round((WATTS * $row['on_count']) / 1000, 3);
    $stats[] = $row;
}

// Daily ON counts for chart
$daily = [];
for ($i = $range - 1; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM lighting_logs WHERE event_type='on' AND DATE(event_time)=?");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
    $daily[] = ['date' => $date, 'label' => date('D M d', strtotime($date)), 'count' => (int)$cnt];
}

echo json_encode(['success'=>true,'range'=>$range,'classrooms'=>$stats,'daily'=>$daily]);
