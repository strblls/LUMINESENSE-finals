<?php
/**
 * Auto-approves pending extension requests for today's classes
 * when the grace period is enabled.
 *
 * This endpoint runs independently of admin login so auto-accept works
 * even when the admin is logged out.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . "/../src/Config/db_connect.php";

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

$today = date('l');

$stmt = $conn->prepare("
    SELECT er.id, er.extend_mins, er.schedule_id,
           COALESCE(s.extended_until, s.end_time) AS current_end,
           s.classroom_id
    FROM extension_requests er
    JOIN schedules s ON s.id = er.schedule_id
    WHERE er.status = 'pending'
      AND s.day_of_week = ?
");
$stmt->bind_param('s', $today);
$stmt->execute();
$stmt->bind_result($ext_id, $ext_mins, $sched_id, $current_end, $classroom_id);

// Buffer all SELECT results before running UPDATE queries to avoid
// MySQLi "Commands out of sync" error (one active query per connection).
$pending = [];
while ($stmt->fetch()) {
    $pending[] = [$ext_id, $ext_mins, $sched_id, $current_end, $classroom_id];
}
$stmt->close();

$approved = 0;
foreach ($pending as $p) {
    [$ext_id, $ext_mins, $sched_id, $current_end, $classroom_id] = $p;

    $new_end = date('H:i:s', strtotime($current_end) + ($ext_mins * 60));

    $upd = $conn->prepare("UPDATE extension_requests SET status = 'approved', reviewed_at = NOW() WHERE id = ?");
    $upd->bind_param('i', $ext_id);
    $upd->execute();
    $upd->close();

    $upd = $conn->prepare('UPDATE schedules SET extended_until = ? WHERE id = ?');
    $upd->bind_param('si', $new_end, $sched_id);
    $upd->execute();
    $upd->close();

    $colCheck = $conn->query("SHOW COLUMNS FROM classrooms LIKE 'schedule_dirty'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $conn->query("UPDATE classrooms SET schedule_dirty = 1 WHERE id = {$classroom_id}");
    }

    $approved++;
}

echo json_encode([
    'success'  => true,
    'approved' => $approved,
    'message'  => $approved > 0 ? "Auto-approved $approved extension(s)." : 'No pending requests to auto-approve.'
]);
