<?php
// api/lights.php
// POST  classroom_id=X & row=1|2|3|all & state=on|off
//   â†’ Persists row / all-lights toggle from the dashboard UI to the DB.
//   â†’ Also logs the event.

require_once __DIR__ . "/../src/Config/db_connect.php";
header('Content-Type: application/json');

// Auth: logged-in faculty/admin session OR a device (gesture service,
// Arduino/PIR) presenting the shared device token in `device_token`
// (mirrors api/pir.php's arduino_token check against ESP32_TOKEN).
$is_session = !empty($_SESSION['faculty_logged_in']) || !empty($_SESSION['admin_logged_in']);
$is_device  = !empty($_POST['device_token']) && $_POST['device_token'] === ESP32_TOKEN;

if (!$is_session && !$is_device) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only.']); exit;
}

$cid        = (int)($_POST['classroom_id'] ?? 0);
$row        = $_POST['row']   ?? 'all';   // '1', '2', '3', or 'all'
$state      = $_POST['state'] ?? 'off';   // 'on' or 'off'
$faculty_id = !empty($_SESSION['faculty_id']) ? (int)$_SESSION['faculty_id'] : null;
$triggered  = $_POST['triggered_by'] ?? 'manual';

if (!$cid || !in_array($state, ['on', 'off'])) {
    echo json_encode(['success' => false, 'message' => 'classroom_id and state (on|off) required.']); exit;
}

// When toggling individual rows we only update classroom light_status to 'on'
// if state='on'; when turning a row off we only set 'off' if ALL rows are off
// (we track this via a separate row_states column if needed - for now we simply
// set light_status to match the state of the 'all' action, or to 'on' for any row-on).
// A manual toggle from the UI is an explicit human command — mark it as an
// override so the cron / PIR auto-off logic does NOT revert it.
$override_mark = ", light_override = 1";

if ($row === 'all') {
    $stmt = $conn->prepare("UPDATE classrooms SET light_status = ?, row1_status = ?, row2_status = ?, row3_status = ?{$override_mark} WHERE id = ?");
    $stmt->bind_param('ssssi', $state, $state, $state, $state, $cid);
    $stmt->execute();
    $stmt->close();
} else {
    // Dynamically update the specific row status column
    $col = "row" . $row . "_status";
    $stmt = $conn->prepare("UPDATE classrooms SET $col = ?, light_override = 1 WHERE id = ?");
    $stmt->bind_param('si', $state, $cid);
    $stmt->execute();
    $stmt->close();

    // Use client-provided global status if available, otherwise re-evaluate
    $new_global = $_POST['new_global_light_status'] ?? null;
    if ($new_global !== null && in_array($new_global, ['on', 'off'], true)) {
        $stmt = $conn->prepare("UPDATE classrooms SET light_status = ? WHERE id = ?");
        $stmt->bind_param('si', $new_global, $cid);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("SELECT row1_status, row2_status, row3_status FROM classrooms WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $stmt->bind_result($r1, $r2, $r3);
        $stmt->fetch();
        $stmt->close();

        $new_global = ($r1 === 'on' || $r2 === 'on' || $r3 === 'on') ? 'on' : 'off';
        $stmt = $conn->prepare("UPDATE classrooms SET light_status = ? WHERE id = ?");
        $stmt->bind_param('si', $new_global, $cid);
        $stmt->execute();
        $stmt->close();
    }
}

// Log the event
$event_type = $state; // 'on' or 'off'
$stmt = $conn->prepare("INSERT INTO lighting_logs (classroom_id, faculty_id, event_type, triggered_by) VALUES (?,?,?,?)");
$stmt->bind_param('iiss', $cid, $faculty_id, $event_type, $triggered);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'light_status' => $state, 'row' => $row]);
