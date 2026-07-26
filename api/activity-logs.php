<?php
require_once __DIR__ . '/../php/db_connect.php';
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['faculty_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
session_write_close();

$logs = [];

// Room event logs
$res = $conn->query("
    SELECT
        'room'        AS log_type,
        id,
        event_type    AS action,
        room_name     AS target,
        triggered_by  AS actor,
        event_time    AS log_time,
        COALESCE(notes,'') AS notes
    FROM room_logs
    ORDER BY event_time DESC
    LIMIT 200
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        if (!empty($row['log_time'])) {
            $row['log_time'] = date('Y-m-d\TH:i:s', strtotime($row['log_time'])) . '+08:00';
        }
        $logs[] = $row;
    }
    $res->free();
}

// PIR occupancy events
$res_pir = $conn->query("
    SELECT
        'room'                                                      AS log_type,
        pl.id,
        CASE pl.state WHEN 1 THEN 'pir_motion' ELSE 'pir_stopped' END AS action,
        c.room_name                                                  AS target,
        'PIR'                                                        AS actor,
        pl.created_at                                                AS log_time,
        ''                                                           AS notes
    FROM pir_logs pl
    JOIN classrooms c ON c.id = pl.classroom_id
    ORDER BY pl.created_at DESC
    LIMIT 200
");
if ($res_pir) {
    while ($row = $res_pir->fetch_assoc()) {
        if (!empty($row['log_time'])) {
            $row['log_time'] = date('Y-m-d\TH:i:s', strtotime($row['log_time'])) . '+08:00';
        }
        $logs[] = $row;
    }
    $res_pir->free();
}

// Lighting schedule events (class_start / class_end)
$res_ll = $conn->query("
    SELECT
        'room'                                                      AS log_type,
        cl.id,
        cl.event_type                                               AS action,
        c.room_name                                                 AS target,
        COALESCE(cl.triggered_by, 'schedule')                       AS actor,
        cl.event_time                                               AS log_time,
        COALESCE(cl.notes, '')                                      AS notes
    FROM class_logs cl
    JOIN classrooms c ON c.id = cl.classroom_id
    ORDER BY cl.event_time DESC
    LIMIT 200
");
if ($res_ll) {
    while ($row = $res_ll->fetch_assoc()) {
        if (!empty($row['log_time'])) {
            $row['log_time'] = date('Y-m-d\TH:i:s', strtotime($row['log_time'])) . '+08:00';
        }
        $logs[] = $row;
    }
    $res_ll->free();
}

// Admin / approval logs
$res2 = $conn->query("
    SELECT
        'admin'                                                      AS log_type,
        al.id,
        al.action                                                    AS action,
        al.target_name                                               AS target,
        COALESCE(CONCAT(a.first_name,' ',a.last_name), 'System')    AS actor,
        al.created_at                                                AS log_time,
        COALESCE(al.notes, '')                                       AS notes
    FROM admin_logs al
    LEFT JOIN admins a ON a.id = al.admin_id
    WHERE al.action IN (
        'faculty_approved', 'faculty_rejected', 'faculty_pending',
        'extension_approved', 'extension_rejected'
    )
    ORDER BY al.created_at DESC
    LIMIT 200
");
if ($res2) {
    while ($row = $res2->fetch_assoc()) {
        if (!empty($row['log_time'])) {
            $row['log_time'] = date('Y-m-d\TH:i:s', strtotime($row['log_time'])) . '+08:00';
        }
        $logs[] = $row;
    }
    $res2->free();
}

usort($logs, fn($a, $b) => strtotime($b['log_time']) - strtotime($a['log_time']));

// Also return room summary counts for stat cards
$room_count = 0;
$lights_on = 0;
$issue_raised = 0;
$issue_resolved = 0;

$r3 = $conn->query("SELECT COUNT(*) AS cnt FROM classrooms");
if ($r3) { $room_count = (int)$r3->fetch_assoc()['cnt']; $r3->free(); }

$r4 = $conn->query("SELECT COUNT(*) AS cnt FROM classrooms c WHERE COALESCE((SELECT l.event_type FROM lighting_logs l WHERE l.classroom_id = c.id ORDER BY l.id DESC LIMIT 1),'off') = 'on'");
if ($r4) { $lights_on = (int)$r4->fetch_assoc()['cnt']; $r4->free(); }

// Issue counts from room_logs
$r5 = $conn->query("SELECT event_type, COUNT(*) AS cnt FROM room_logs WHERE event_type IN ('issue_raised','issue_resolved') GROUP BY event_type");
if ($r5) {
    while ($row = $r5->fetch_assoc()) {
        if ($row['event_type'] === 'issue_raised') $issue_raised = (int)$row['cnt'];
        elseif ($row['event_type'] === 'issue_resolved') $issue_resolved = (int)$row['cnt'];
    }
    $r5->free();
}

$conn->close();

echo json_encode([
    'success' => true,
    'data' => $logs,
    'stats' => [
        'total_logs' => count($logs),
        'total_rooms' => $room_count,
        'lights_on' => $lights_on,
        'issue_raised' => $issue_raised,
        'issue_resolved' => $issue_resolved,
    ]
]);
