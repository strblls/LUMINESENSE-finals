<?php
// api/pending-extensions.php
// Polled by admin-faculty-management.js so the Pending Approvals extension
// list reflects new/auto-approved requests without a page refresh.
require_once __DIR__ . "/../src/Session/session_guard.php";
check_admin();
require_once __DIR__ . "/../src/Config/db_connect.php";
header('Content-Type: application/json');
header('Cache-Control: no-store');

$pending = [];
if ($conn->query("SHOW TABLES LIKE 'extension_requests'")->num_rows > 0) {
    $r = $conn->query("
        SELECT er.id, er.extend_mins, er.requested_at,
               CONCAT(f.first_name, ' ', f.last_name) AS faculty_name,
               s.day_of_week, s.start_time, s.end_time,
               c.room_name, sub.name AS subject_name
        FROM extension_requests er
        JOIN faculty     f ON f.id = er.faculty_id
        JOIN schedules   s ON s.id = er.schedule_id
        JOIN classrooms  c ON c.id = s.classroom_id
        LEFT JOIN subjects sub ON sub.id = s.subject_id
        WHERE er.status = 'pending'
        ORDER BY er.requested_at DESC
    ");
    if ($r) {
        while ($row = $r->fetch_assoc()) $pending[] = $row;
    }
}

echo json_encode(['success' => true, 'pending' => $pending]);
$conn->close();
