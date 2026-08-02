<?php
require_once __DIR__ . "/../src/Config/db_connect.php";
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['faculty_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$faculty_id = (int)$_SESSION['faculty_id'];
$today = date('l');

$today_requests = [];
$r = $conn->query("
    SELECT er.id, er.schedule_id, er.extend_mins, er.status, er.requested_at,
           s.day_of_week, s.start_time, s.end_time, s.extended_until, c.room_name, sub.name AS subject_name
    FROM extension_requests er
    JOIN schedules s ON s.id = er.schedule_id
    JOIN classrooms c ON c.id = s.classroom_id
    LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE er.faculty_id = $faculty_id AND s.day_of_week = '$today'
    ORDER BY er.requested_at DESC
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $today_requests[] = $row;
    }
}

$other_requests = [];
$r = $conn->query("
    SELECT er.id, er.schedule_id, er.extend_mins, er.status, er.requested_at,
           s.day_of_week, s.start_time, s.end_time, s.extended_until, c.room_name, sub.name AS subject_name
    FROM extension_requests er
    JOIN schedules s ON s.id = er.schedule_id
    JOIN classrooms c ON c.id = s.classroom_id
    LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE er.faculty_id = $faculty_id AND s.day_of_week != '$today'
    ORDER BY er.requested_at DESC
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $other_requests[] = $row;
    }
}

$extensions_left_today = 3;
$limit_q = $conn->prepare("
    SELECT 3 - COUNT(*) AS remaining
    FROM extension_requests er
    JOIN schedules s ON s.id = er.schedule_id
    WHERE er.faculty_id = ? AND s.day_of_week = ?
    AND er.status IN ('pending', 'approved')
");
if ($limit_q) {
    $limit_q->bind_param('is', $faculty_id, $today);
    $limit_q->execute();
    $limit_q->bind_result($extensions_left_today);
    $limit_q->fetch();
    $limit_q->close();
}

echo json_encode([
    'success' => true,
    'today' => $today_requests,
    'other' => $other_requests,
    'extensions_left_today' => max(0, $extensions_left_today)
]);
