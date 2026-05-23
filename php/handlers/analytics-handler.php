<?php 
require_once __DIR__ . '/admin-handlers.php';
// ── Summary counts ─────────────────────────────────────────────────────────
$total_rooms = $conn->query("SELECT COUNT(*) AS c FROM classrooms")->fetch_assoc()['c'];

$lights_on = $conn->query("
    SELECT COUNT(*) AS c FROM lighting_logs l
    WHERE l.id IN (SELECT MAX(id) FROM lighting_logs GROUP BY classroom_id)
    AND l.event_type = 'on'
")->fetch_assoc()['c'];

$pending = $conn->query("
    SELECT COUNT(*) AS c FROM faculty
    WHERE is_verified = 1 AND approved_by IS NULL
")->fetch_assoc()['c'];

$ext_pending = 0;
if ($conn->query("SHOW TABLES LIKE 'extension_requests'")->num_rows > 0) {
    $ext_pending = $conn->query("SELECT COUNT(*) AS c FROM extension_requests WHERE status='pending'")->fetch_assoc()['c'];
}

$db_ok       = ($conn && !$conn->connect_error);
$lights_data = $conn->query("SELECT COUNT(*) AS c FROM lighting_logs WHERE DATE(event_time)=CURDATE()")->fetch_assoc()['c'];

// ── Recent activity ────────────────────────────────────────────────────────
$logs = [];
$r = $conn->query("
    SELECT l.event_type, l.triggered_by, l.event_time, c.room_name
    FROM lighting_logs l
    JOIN classrooms c ON c.id = l.classroom_id
    ORDER BY l.event_time DESC LIMIT 6
");
while ($row = $r->fetch_assoc()) {
    $logs[] = $row;
}

$approval_logs = [];
$r2 = $conn->query("
    SELECT CONCAT(first_name,' ',last_name) AS faculty_name, approved_at
    FROM faculty
    WHERE approved_by IS NOT NULL
    ORDER BY approved_at DESC LIMIT 3
");
while ($row = $r2->fetch_assoc()) {
    $approval_logs[] = $row;
}

// ── Rooms list ─────────────────────────────────────────────────────────────
$rooms = [];
$r = $conn->query("
    SELECT c.id, c.room_name, c.room_size, c.description,
           COALESCE(l.event_type, 'off') AS light_status
    FROM classrooms c
    LEFT JOIN lighting_logs l
           ON l.id = (SELECT MAX(id) FROM lighting_logs WHERE classroom_id = c.id)
    ORDER BY c.room_name
");
while ($row = $r->fetch_assoc()) {
    $rooms[] = $row;
}

// ── JSON Preparation for JS Charts/Breakdowns ──────────────────────────────
// This matches the variable your JavaScript file is looking for
$roomDataFromPHP = [
    'rooms'       => $rooms,
    'lights_on'   => $lights_on,
    'total_rooms' => $total_rooms
];
?>