<?php
/**
 * LumineSense – Schedule Handler
 * --------------------------------
 * Handles create / update / delete for schedule slots.
 * Called via fetch() from admin-timetable-manage.php
 */

header('Content-Type: application/json');

require_once realpath(__DIR__ . '/../includes/admin-head.php');
require_once __DIR__ . '/admin-handlers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']); exit;
}

$action     = trim($_POST['action']      ?? '');
$slot_id    = (int)($_POST['slot_id']    ?? 0);
$room_id    = (int)($_POST['room_id']    ?? 0);
$faculty_id = (int)($_POST['faculty_id'] ?? 0);
$day        = trim($_POST['day_of_week'] ?? '');
$start      = trim($_POST['start_time']  ?? '');
$end        = trim($_POST['end_time']    ?? '');

$valid_days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

// ── CREATE ─────────────────────────────────────────────────────────────────
if ($action === 'create') {
    if (!$room_id || !$faculty_id || !in_array($day, $valid_days) || !$start || !$end) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']); exit;
    }
    if ($start >= $end) {
        echo json_encode(['success' => false, 'message' => 'End time must be after start time.']); exit;
    }

    // Check for overlapping slot in the same room on the same day
    $chk = $conn->prepare("
        SELECT id FROM schedules
        WHERE classroom_id = ? AND day_of_week = ?
          AND start_time < ? AND end_time > ?
        LIMIT 1
    ");
    $chk->bind_param('isss', $room_id, $day, $end, $start);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) {
        $chk->close();
        echo json_encode(['success' => false, 'message' => 'This slot overlaps with an existing schedule.']); exit;
    }
    $chk->close();

    $stmt = $conn->prepare("
        INSERT INTO schedules (classroom_id, day_of_week, start_time, end_time, created_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('isssi', $room_id, $day, $start, $end, $faculty_id);
    if ($stmt->execute()) {
        $new_id = $conn->insert_id;
        $stmt->close();

        // ✅ Fetch room name and faculty name for the log
        $room_name  = $conn->query("SELECT room_name FROM classrooms WHERE id = $room_id")->fetch_assoc()['room_name'] ?? 'Unknown Room';
        $fac_name   = $conn->query("SELECT CONCAT(first_name,' ',last_name) FROM faculty WHERE id = $faculty_id")->fetch_assoc()["CONCAT(first_name,' ',last_name)"] ?? 'Unknown Faculty';
        log_admin_action($conn, $_SESSION['admin_id'], 'schedule_created', $room_name . ' – ' . $day, $fac_name . ' ' . $start . '–' . $end);

        echo json_encode(['success' => true, 'slot_id' => $new_id]); exit;
    }
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error.']); exit;
}

// ── UPDATE ─────────────────────────────────────────────────────────────────
if ($action === 'update') {
    if (!$slot_id || !$faculty_id || !in_array($day, $valid_days) || !$start || !$end) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']); exit;
    }
    if ($start >= $end) {
        echo json_encode(['success' => false, 'message' => 'End time must be after start time.']); exit;
    }

    // Get the room_id for this slot (needed for overlap check and log)
    $gr = $conn->prepare("SELECT classroom_id FROM schedules WHERE id = ?");
    $gr->bind_param('i', $slot_id);
    $gr->execute();
    $gr->bind_result($existing_room);
    $gr->fetch();
    $gr->close();

    // Overlap check (exclude the slot being edited)
    $chk = $conn->prepare("
        SELECT id FROM schedules
        WHERE classroom_id = ? AND day_of_week = ?
          AND start_time < ? AND end_time > ?
          AND id != ?
        LIMIT 1
    ");
    $chk->bind_param('isssi', $existing_room, $day, $end, $start, $slot_id);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) {
        $chk->close();
        echo json_encode(['success' => false, 'message' => 'This slot overlaps with an existing schedule.']); exit;
    }
    $chk->close();

    $stmt = $conn->prepare("
        UPDATE schedules
        SET day_of_week = ?, start_time = ?, end_time = ?, created_by = ?
        WHERE id = ?
    ");
    $stmt->bind_param('sssii', $day, $start, $end, $faculty_id, $slot_id);
    if ($stmt->execute()) {
        $stmt->close();

        // ✅ Fetch room name for the log
        $room_name = $conn->query("SELECT room_name FROM classrooms WHERE id = $existing_room")->fetch_assoc()['room_name'] ?? 'Unknown Room';
        log_admin_action($conn, $_SESSION['admin_id'], 'schedule_updated', $room_name . ' – ' . $day, $start . '–' . $end);

        echo json_encode(['success' => true]); exit;
    }
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error.']); exit;
}

// ── DELETE ─────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    if (!$slot_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid slot ID.']); exit;
    }

    // ✅ Fetch details BEFORE deleting so we still have them for the log
    $info = $conn->query("
        SELECT c.room_name, s.day_of_week, s.start_time, s.end_time
        FROM schedules s
        JOIN classrooms c ON c.id = s.classroom_id
        WHERE s.id = $slot_id
    ")->fetch_assoc();
    $log_target = ($info['room_name'] ?? 'Unknown') . ' – ' . ($info['day_of_week'] ?? '');
    $log_notes  = ($info['start_time'] ?? '') . '–' . ($info['end_time'] ?? '');

    $stmt = $conn->prepare("DELETE FROM schedules WHERE id = ?");
    $stmt->bind_param('i', $slot_id);
    if ($stmt->execute()) {
        $stmt->close();

        // ✅ Log after successful delete
        log_admin_action($conn, $_SESSION['admin_id'], 'schedule_deleted', $log_target, $log_notes);

        echo json_encode(['success' => true]); exit;
    }
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error.']); exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);