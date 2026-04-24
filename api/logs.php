<?php
// api/logs.php
// GET  ?room=X&type=X&date=YYYY-MM-DD&limit=200   → filtered log
// POST (from Arduino or dashboard)
//      classroom_id, event_type, triggered_by

require_once '../php/db_connect.php';
header('Content-Type: application/json');

// GET: admin or faculty can read
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['faculty_logged_in'])) {
        http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit;
    }

    $where  = ['1=1'];
    $params = [];
    $types  = '';

    if (!empty($_GET['room'])) {
        $where[]  = 'l.classroom_id = ?';
        $params[] = (int)$_GET['room'];
        $types   .= 'i';
    }
    $valid_types = ['on','off','gesture','schedule','security_alert'];
    if (!empty($_GET['type']) && in_array($_GET['type'], $valid_types)) {
        $where[]  = 'l.event_type = ?';
        $params[] = $_GET['type'];
        $types   .= 's';
    }
    if (!empty($_GET['date'])) {
        $where[]  = 'DATE(l.event_time) = ?';
        $params[] = $_GET['date'];
        $types   .= 's';
    }

    $limit = min((int)($_GET['limit'] ?? 200), 500);
    $sql   = 'SELECT l.id, l.event_type, l.triggered_by, l.event_time, c.room_name
              FROM lighting_logs l
              JOIN classrooms c ON c.id = l.classroom_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY l.event_time DESC LIMIT ' . $limit;

    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $r = $stmt->get_result();
    $rows = [];
    while ($row = $r->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    echo json_encode(['success'=>true,'data'=>$rows]); exit;
}

// POST: Arduino or dashboard writes a log entry
// No session check here — Arduino has no session.
// In production add an API key check. For prototype this is fine.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cid  = (int)($_POST['classroom_id'] ?? 0);
    $type = $_POST['event_type']   ?? '';
    $by   = $_POST['triggered_by'] ?? 'sensor';

    $valid = ['on','off','gesture','schedule','security_alert'];
    if (!$cid || !in_array($type, $valid)) {
        echo json_encode(['success'=>false,'message'=>'classroom_id and valid event_type required.']); exit;
    }

    $stmt = $conn->prepare('INSERT INTO lighting_logs (classroom_id, event_type, triggered_by) VALUES (?,?,?)');
    $stmt->bind_param('iss', $cid, $type, $by);
    $stmt->execute();
    $new_id = $conn->insert_id;
    $stmt->close();
    echo json_encode(['success'=>true,'message'=>'Log saved.','id'=>$new_id]); exit;
}

http_response_code(405);
echo json_encode(['success'=>false,'message'=>'Method not allowed.']);
