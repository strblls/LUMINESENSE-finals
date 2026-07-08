<?php
// api/request-extension.php
require_once '../php/db_connect.php';
header('Content-Type: application/json');

if (empty($_SESSION['faculty_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only.']); exit;
}

$faculty_id  = (int)$_SESSION['faculty_id'];
$schedule_id = (int)($_POST['schedule_id'] ?? 0);
$extend_mins = (int)($_POST['extend_mins'] ?? 30);
$edit_ext_request = (int)($_POST['edit_ext_request'] ?? 0);

if (!$schedule_id || $extend_mins <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid data.']); exit;
}

// If editing, first remove the old pending request
if ($edit_ext_request > 0) {
    $stmt = $conn->prepare("DELETE FROM extension_requests WHERE id = ? AND faculty_id = ? AND status = 'pending'");
    $stmt->bind_param('ii', $edit_ext_request, $faculty_id);
    $stmt->execute();
    $stmt->close();
}

// Check if there's a succeeding schedule in the same room
$stmt = $conn->prepare("
    SELECT s2.id, s2.start_time, s2.end_time, c.room_name
    FROM schedules s1
    JOIN schedules s2 ON s2.classroom_id = s1.classroom_id
                     AND s2.day_of_week = s1.day_of_week
                     AND s2.start_time >= COALESCE(s1.extended_until, s1.end_time)
                     AND s2.id != s1.id
    JOIN classrooms c ON c.id = s2.classroom_id
    WHERE s1.id = ?
    ORDER BY s2.start_time
    LIMIT 1
");
$stmt->bind_param('i', $schedule_id);
$stmt->execute();
$successor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($successor) {
    echo json_encode(['success' => false, 'message' => 'Cannot request extension: There is a succeeding schedule in ' . $successor['room_name'] . ' at ' . date('g:i A', strtotime($successor['start_time'])) . '.']);
    exit;
}

// Make sure no pending request already exists for this schedule
$stmt = $conn->prepare("SELECT id FROM extension_requests WHERE schedule_id = ? AND status = 'pending' LIMIT 1");
$stmt->bind_param('i', $schedule_id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'You already have a pending extension request for this class.']); exit;
}
$stmt->close();

// Insert the request
$stmt = $conn->prepare("INSERT INTO extension_requests (faculty_id, schedule_id, extend_mins) VALUES (?, ?, ?)");
$stmt->bind_param('iii', $faculty_id, $schedule_id, $extend_mins);
$stmt->execute();
$inserted_id = $stmt->insert_id;
$stmt->close();

// ── Auto-approve if grace period is enabled ────────────────────
$auto_approved = false;
$r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'grace_minutes'");
$grace_minutes = $r && $row = $r->fetch_assoc() ? (int)$row['setting_value'] : 0;

if ($grace_minutes > 0) {
    $today = date('l');

    $stmt = $conn->prepare("
        SELECT COALESCE(extended_until, end_time) AS current_end, classroom_id
        FROM schedules
        WHERE id = ? AND day_of_week = ?
    ");
    $stmt->bind_param('is', $schedule_id, $today);
    $stmt->execute();
    $stmt->bind_result($current_end, $classroom_id);
    $found = $stmt->fetch();
    $stmt->close();

    if ($found) {
        $new_end = date('H:i:s', strtotime($current_end) + ($extend_mins * 60));

        $upd = $conn->prepare("UPDATE extension_requests SET status = 'approved', reviewed_at = NOW() WHERE id = ?");
        $upd->bind_param('i', $inserted_id);
        $upd->execute();
        $upd->close();

        $upd = $conn->prepare("UPDATE schedules SET extended_until = ? WHERE id = ?");
        $upd->bind_param('si', $new_end, $schedule_id);
        $upd->execute();
        $upd->close();

        $checkCol = $conn->query("SHOW COLUMNS FROM classrooms LIKE 'schedule_dirty'");
        if ($checkCol && $checkCol->num_rows > 0) {
            $conn->query("UPDATE classrooms SET schedule_dirty = 1 WHERE id = {$classroom_id}");
        }

        $auto_approved = true;
    }
}

// Fetch schedule details for frontend update
$new_extended_until = null;
$schedule_end_time = null;
$stmt = $conn->prepare("SELECT end_time, extended_until FROM schedules WHERE id = ?");
$stmt->bind_param('i', $schedule_id);
$stmt->execute();
$stmt->bind_result($schedule_end_time, $new_extended_until);
$stmt->fetch();
$stmt->close();

echo json_encode([
    'success' => true,
    'message' => $auto_approved
        ? 'Extension request auto-approved.'
        : 'Extension request submitted. Waiting for admin approval.',
    'auto_approved' => $auto_approved,
    'extended_until' => $new_extended_until,
    'end_time' => $schedule_end_time,
    'end_time_formatted' => $schedule_end_time ? date('g:i A', strtotime($schedule_end_time)) : null,
    'extended_until_formatted' => $new_extended_until ? date('g:i A', strtotime($new_extended_until)) : null
]);