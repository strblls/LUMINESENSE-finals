<?php
require_once '../php/db_connect.php';
header('Content-Type: application/json');

if (empty($_SESSION['faculty_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$schedule_id = (int)($_GET['schedule_id'] ?? 0);
if (!$schedule_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid schedule ID.']);
    exit;
}

$stmt = $conn->prepare("
    SELECT s2.id, s2.start_time, s2.end_time, s2.extended_until,
           c.room_name, sub.name AS subject_name
    FROM schedules s1
    JOIN schedules s2 ON s2.classroom_id = s1.classroom_id
                     AND s2.day_of_week = s1.day_of_week
                     AND s2.start_time >= COALESCE(s1.extended_until, s1.end_time)
                     AND s2.start_time < ADDTIME(COALESCE(s1.extended_until, s1.end_time), '01:00:00')
                     AND s2.id != s1.id
    JOIN classrooms c ON c.id = s2.classroom_id
    LEFT JOIN subjects sub ON sub.id = s2.subject_id
    WHERE s1.id = ?
    ORDER BY s2.start_time
    LIMIT 1
");
$stmt->bind_param('i', $schedule_id);
$stmt->execute();
$result = $stmt->get_result();
$next = $result->fetch_assoc();
$stmt->close();
$conn->close();

if ($next) {
    echo json_encode([
        'success' => true,
        'has_successor' => true,
        'next' => [
            'start_time' => date('g:i A', strtotime($next['start_time'])),
            'end_time' => date('g:i A', strtotime($next['extended_until'] ?? $next['end_time'])),
            'room_name' => $next['room_name'],
            'subject_name' => $next['subject_name'] ?? 'N/A'
        ]
    ]);
} else {
    echo json_encode(['success' => true, 'has_successor' => false]);
}
