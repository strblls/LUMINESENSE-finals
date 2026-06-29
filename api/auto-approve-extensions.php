<?php
/**
 * Auto-approves pending extension requests when the class is currently
 * in session and has ≤ grace_minutes remaining.
 *
 * This endpoint runs independently of admin login so auto-accept works
 * even when the admin is logged out.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../php/db_connect.php';

// Read grace period from system_settings
$r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'grace_minutes'");
$grace_minutes = 0;
if ($r && $row = $r->fetch_assoc()) {
    $grace_minutes = (int)$row['setting_value'];
}

if ($grace_minutes <= 0) {
    echo json_encode(['success' => true, 'approved' => 0, 'message' => 'Grace period disabled.']);
    exit;
}

// Check extension_requests table exists
$check = $conn->query("SHOW TABLES LIKE 'extension_requests'");
if (!$check || $check->num_rows === 0) {
    echo json_encode(['success' => true, 'approved' => 0, 'message' => 'No extension_requests table.']);
    exit;
}

$now_time = date('H:i:s');
$today = date('l');

$stmt = $conn->prepare("
    SELECT er.id, er.extend_mins, er.schedule_id,
           COALESCE(s.extended_until, s.end_time) AS end_time,
           s.classroom_id
    FROM extension_requests er
    JOIN schedules s ON s.id = er.schedule_id
    WHERE er.status = 'pending'
      AND s.day_of_week = ?
      AND s.start_time <= ?
      AND COALESCE(s.extended_until, s.end_time) >= ?
      AND TIME_TO_SEC(TIMEDIFF(COALESCE(s.extended_until, s.end_time), ?)) / 60 <= ?
");
$stmt->bind_param('ssssi', $today, $now_time, $now_time, $now_time, $grace_minutes);
$stmt->execute();
$result = $stmt->get_result();

$approved = 0;
while ($row = $result->fetch_assoc()) {
    $new_end = date('H:i:s', strtotime($row['end_time']) + ($row['extend_mins'] * 60));

    // reviewed_by stays NULL (auto-approved without admin)
    $upd = $conn->prepare("UPDATE extension_requests SET status = 'approved', reviewed_at = NOW() WHERE id = ?");
    $upd->bind_param('i', $row['id']);
    $upd->execute();
    $upd->close();

    $upd = $conn->prepare('UPDATE schedules SET extended_until = ? WHERE id = ?');
    $upd->bind_param('si', $new_end, $row['schedule_id']);
    $upd->execute();
    $upd->close();

    $colCheck = $conn->query("SHOW COLUMNS FROM classrooms LIKE 'schedule_dirty'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $conn->query("UPDATE classrooms c JOIN schedules s ON s.classroom_id = c.id SET c.schedule_dirty = 1 WHERE s.id = {$row['schedule_id']}");
    }

    $approved++;
}
$stmt->close();

echo json_encode([
    'success'  => true,
    'approved' => $approved,
    'message'  => $approved > 0 ? "Auto-approved $approved extension(s)." : 'No pending requests to auto-approve.'
]);
