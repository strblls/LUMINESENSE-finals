<?php
$phpRoot = realpath(__DIR__ . '/../');
require_once $phpRoot . '/session_guard.php';
check_admin();
require_once $phpRoot . '/db_connect.php';

$admin_name = htmlspecialchars($_SESSION['admin_name']);
$admin_id   = $_SESSION['admin_id'];
$name_parts = explode(' ', $admin_name);
$initials   = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));

$admin_email = '';
$stmt = $conn->prepare('SELECT email FROM admins WHERE id = ?');
$stmt->bind_param('i', $admin_id);
$stmt->execute();
$stmt->bind_result($admin_email);
$stmt->fetch();
$stmt->close();

// ── Fetch all classrooms for the dropdown ─────────────────────
$rooms = [];
$res = $conn->query("SELECT id, room_name FROM classrooms ORDER BY room_name ASC");
while ($row = $res->fetch_assoc()) {
    $rooms[] = $row;
}

// Summary counts
$total_rooms = $conn->query("SELECT COUNT(*) AS c FROM classrooms")->fetch_assoc()['c'];

// Lights on = classrooms whose LATEST log entry is 'on'
$lights_on = $conn->query("
    SELECT COUNT(*) AS c FROM lighting_logs l
    WHERE l.id IN (SELECT MAX(id) FROM lighting_logs GROUP BY classroom_id)
    AND l.event_type = 'on'
")->fetch_assoc()['c'];

// Pending faculty = email verified but not yet approved by admin
$pending = $conn->query("
    SELECT COUNT(*) AS c FROM faculty
    WHERE is_verified = 1 AND approved_by IS NULL
")->fetch_assoc()['c'];

// Extension requests — table may not exist yet, so we guard it
$ext_pending = 0;
if ($conn->query("SHOW TABLES LIKE 'extension_requests'")->num_rows > 0) {
    $ext_pending = $conn->query("SELECT COUNT(*) AS c FROM extension_requests WHERE status='pending'")->fetch_assoc()['c'];
}

// System status checks
$server_ok   = true; // we're running PHP so server is up
$db_ok       = ($conn && !$conn->connect_error);
$lights_data = $conn->query("SELECT COUNT(*) AS c FROM lighting_logs WHERE DATE(event_time)=CURDATE()")->fetch_assoc()['c'];

// Recent activity logs (includes faculty approvals)
$logs = [];
$r = $conn->query("
    SELECT l.event_type, l.triggered_by, l.event_time, c.room_name
    FROM lighting_logs l
    JOIN classrooms c ON c.id = l.classroom_id
    ORDER BY l.event_time DESC
    LIMIT 6
");
while ($row = $r->fetch_assoc()) $logs[] = $row;

// Faculty approval events for recent activity
$approval_logs = [];
$r2 = $conn->query("
    SELECT CONCAT(first_name, ' ', last_name) AS faculty_name,
           approved_at
    FROM faculty
    WHERE approved_by IS NOT NULL
    ORDER BY approved_at DESC
    LIMIT 3
");
while ($row = $r2->fetch_assoc()) $approval_logs[] = $row;

// Classrooms with their description and latest light status
$classrooms = [];
$r = $conn->query("
    SELECT c.id, c.room_name, c.room_size, c.description,
           COALESCE(l.event_type, 'off') AS light_status
    FROM classrooms c
    LEFT JOIN lighting_logs l
           ON l.id = (SELECT MAX(id) FROM lighting_logs WHERE classroom_id = c.id)
    ORDER BY c.room_name
");
while ($row = $r->fetch_assoc()) $classrooms[] = $row;

function getRoomSchedules($conn, $room_id) {
    $day  = date('l');
    $stmt = $conn->prepare("
        SELECT s.start_time, s.end_time,
               CONCAT(f.first_name,' ',f.last_name) AS faculty_name
        FROM schedules s
        JOIN faculty f ON f.id = s.created_by
        WHERE s.classroom_id = ? AND s.day_of_week = ?
        ORDER BY s.start_time
    ");
    $stmt->bind_param('is', $room_id, $day);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function getCurrentSchedule($conn, $room_id) {
    $day  = date('l');
    $time = date('H:i:s');
    $stmt = $conn->prepare("
        SELECT s.start_time, s.end_time,
               CONCAT(f.first_name,' ',f.last_name) AS faculty_name,
               f.first_name, f.last_name
        FROM schedules s
        JOIN faculty f ON f.id = s.created_by
        WHERE s.classroom_id = ?
          AND s.day_of_week  = ?
          AND s.start_time  <= ?
          AND s.end_time    >= ?
        LIMIT 1
    ");
    $stmt->bind_param('isss', $room_id, $day, $time, $time);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}
?>