<?php
// api/admin-status.php
// GET → returns live counts and recent activity for admin dashboard
require_once '../php/db_connect.php';
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false]); exit;
}

$admin_id = (int)$_SESSION['admin_id'];

// ── Counts ────────────────────────────────────────────────────────────────
$pending = $conn->query("
    SELECT COUNT(*) AS c FROM faculty
    WHERE is_verified = 1 AND approved_by IS NULL
")->fetch_assoc()['c'];

$ext_pending = $conn->query("
    SELECT COUNT(*) AS c FROM extension_requests WHERE status='pending'
")->fetch_assoc()['c'];

$lights_on = $conn->query("
    SELECT COUNT(*) AS c FROM classrooms WHERE light_status = 'on'
")->fetch_assoc()['c'];

$total_rooms = $conn->query("
    SELECT COUNT(*) AS c FROM classrooms
")->fetch_assoc()['c'];

// ── Classrooms ────────────────────────────────────────────────────────────
$classrooms = [];
$r = $conn->query("
    SELECT id, room_name, room_size, light_status
    FROM classrooms ORDER BY room_name
");
while ($row = $r->fetch_assoc()) $classrooms[] = $row;

// ── Recent activity ───────────────────────────────────────────────────────
$logs = [];

// Lighting logs
$r = $conn->query("
    SELECT ll.event_type, ll.triggered_by, ll.event_time,
           c.room_name, 'room' AS log_type, NULL AS admin_name
    FROM lighting_logs ll
    JOIN classrooms c ON c.id = ll.classroom_id
    ORDER BY ll.event_time DESC
    LIMIT 20
");
if ($r) while ($row = $r->fetch_assoc()) $logs[] = $row;

// Admin logs
$r2 = $conn->query("
    SELECT al.action AS event_type, al.notes AS triggered_by,
           al.created_at AS event_time, al.target_name AS room_name,
           'admin' AS log_type,
           CONCAT(a.first_name, ' ', a.last_name) AS admin_name
    FROM admin_logs al
    JOIN admins a ON a.id = al.admin_id
    ORDER BY al.created_at DESC
    LIMIT 20
");
if ($r2) while ($row = $r2->fetch_assoc()) $logs[] = $row;

// Admin logins (other admins only)
$stmt = $conn->prepare("
    SELECT 'admin_login' AS event_type, 'Logged in' AS triggered_by,
           login_at AS event_time, 'System' AS room_name,
           'admin_login' AS log_type,
           CONCAT(a.first_name, ' ', a.last_name) AS admin_name
    FROM admin_login_logs all2
    JOIN admins a ON a.id = all2.admin_id
    WHERE all2.admin_id != ?
    ORDER BY login_at DESC
    LIMIT 5
");
$stmt->bind_param('i', $admin_id);
$stmt->execute();
$r3 = $stmt->get_result();
while ($row = $r3->fetch_assoc()) $logs[] = $row;
$stmt->close();

// Sort merged list newest-first
usort($logs, fn($a, $b) => strtotime($b['event_time']) - strtotime($a['event_time']));
$logs = array_slice($logs, 0, 10);

echo json_encode([
    'success'     => true,
    'pending'     => (int)$pending,
    'ext_pending' => (int)$ext_pending,
    'lights_on'   => (int)$lights_on,
    'total_rooms' => (int)$total_rooms,
    'classrooms'  => $classrooms,
    'logs'        => $logs,
]);