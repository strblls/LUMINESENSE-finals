<?php
// api/faculty-status.php
// GET ?classroom_id=X  →  live dashboard snapshot for faculty home
// Called every 3 s by the JavaScript poll loop.

require_once '../php/db_connect.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['faculty_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
}

$cid = (int)($_GET['classroom_id'] ?? 0);
if (!$cid) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'classroom_id required.']); exit;
}

$now_time = date('H:i:s');
$now_day  = date('l');

// ── Classroom row ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT light_status, pir_occupied, pir_since FROM classrooms WHERE id=? LIMIT 1");
$stmt->bind_param('i', $cid);
$stmt->execute();
$stmt->bind_result($light_status, $pir_occupied, $pir_since);
$stmt->fetch();
$stmt->close();

// ── Active schedule ───────────────────────────────────────────────────────────
$active_schedule = null;
$stmt = $conn->prepare("
    SELECT id, start_time, end_time, extended_until
    FROM schedules
    WHERE classroom_id = ?
      AND day_of_week  = ?
      AND start_time  <= ?
      AND (extended_until >= ? OR (extended_until IS NULL AND end_time >= ?))
    ORDER BY start_time
    LIMIT 1
");
$stmt->bind_param('issss', $cid, $now_day, $now_time, $now_time, $now_time);
$stmt->execute();
$r = $stmt->get_result();
if ($row = $r->fetch_assoc()) $active_schedule = $row;
$stmt->close();

// ── Recent activity logs (last 7) ─────────────────────────────────────────────
$logs = [];
$stmt = $conn->prepare("
    SELECT l.event_type, l.triggered_by, l.event_time, c.room_name
    FROM lighting_logs l
    JOIN classrooms c ON c.id = l.classroom_id
    WHERE l.classroom_id = ?
    ORDER BY l.event_time DESC
    LIMIT 7
");
$stmt->bind_param('i', $cid);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $logs[] = $row;
$stmt->close();

// ── Gesture logs (this faculty only, last 20) ─────────────────────────────────
$faculty_id = (int)$_SESSION['faculty_id'];
$gesture_logs = [];
$stmt = $conn->prepare("
    SELECT l.event_type, l.triggered_by, l.event_time, c.room_name
    FROM lighting_logs l
    JOIN classrooms c ON c.id = l.classroom_id
    WHERE l.faculty_id = ?
      AND l.classroom_id = ?
      AND l.triggered_by = 'gesture'
    ORDER BY l.event_time DESC
    LIMIT 20
");
$stmt->bind_param('ii', $faculty_id, $cid);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $gesture_logs[] = $row;
$stmt->close();

echo json_encode([
    'success'         => true,
    'server_time'     => $now_time,
    'light_status'    => $light_status ?? 'off',
    'pir_occupied'    => (bool)$pir_occupied,
    'pir_since'       => $pir_since,
    'schedule_active' => $active_schedule !== null,
    'schedule_end'    => $active_schedule
                            ? ($active_schedule['extended_until'] ?? $active_schedule['end_time'])
                            : null,
    'logs'            => $logs,
    'gesture_logs'    => $gesture_logs,
]);
