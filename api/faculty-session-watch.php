<?php
// api/faculty-session-watch.php
// Faculty-scoped "is my class still running" poll. Unlike faculty-status.php,
// it needs no classroom_id — it resolves the faculty's own active schedule from
// the session, so it works on every faculty page that shares the topbar.
//
// The auto-logout module uses this to detect the moment the current class
// session ends (active: true -> false) and run the logout sequence.

require_once __DIR__ . "/../src/Config/db_connect.php";
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['faculty_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
}
session_write_close(); // read-only

$fid      = (int)$_SESSION['faculty_id'];
$now_time = date('H:i:s');
$now_day  = date('l');

$stmt = $conn->prepare("
    SELECT id, start_time, end_time, extended_until
    FROM schedules
    WHERE faculty_id = ?
      AND day_of_week  = ?
      AND start_time  <= ?
      AND (extended_until >= ? OR (extended_until IS NULL AND end_time >= ?))
    ORDER BY start_time
    LIMIT 1
");
$stmt->bind_param('issss', $fid, $now_day, $now_time, $now_time, $now_time);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo json_encode([
    'success' => true,
    'active'  => $row !== null,
    'end'     => $row ? ($row['extended_until'] ?? $row['end_time']) : null,
    'server_time' => $now_time,
]);