<?php
/**
 * LumineSense – Faculty Head Handler
 * Subject-area assignment and schedule management for department heads.
 */

header('Content-Type: application/json');

require_once realpath(__DIR__ . '/../session_guard.php');
check_faculty();
require_once realpath(__DIR__ . '/../db_connect.php');

if (empty($_SESSION['is_head'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']); exit;
}

$head_id = (int)$_SESSION['faculty_id'];

function head_department(mysqli $conn, int $head_id): ?array {
    $stmt = $conn->prepare("
        SELECT id, name FROM departments
        WHERE head_faculty_id = ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->bind_param('i', $head_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function member_in_department(mysqli $conn, int $dept_id, int $member_id): bool {
    $stmt = $conn->prepare("
        SELECT id FROM faculty
        WHERE id = ? AND department_id = ? AND is_verified = 1 AND approved_by IS NOT NULL
        LIMIT 1
    ");
    $stmt->bind_param('ii', $member_id, $dept_id);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

function schedule_in_department(mysqli $conn, int $dept_id, int $slot_id): ?array {
    $stmt = $conn->prepare("
        SELECT s.id, s.classroom_id, s.faculty_id, s.day_of_week, s.start_time, s.end_time, s.subject_id
        FROM schedules s
        JOIN faculty f ON f.id = s.faculty_id
        WHERE s.id = ? AND f.department_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $slot_id, $dept_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']); exit;
}

$dept = head_department($conn, $head_id);
if (!$dept) {
    echo json_encode(['success' => false, 'message' => 'No active department found for this head.']); exit;
}
$dept_id = (int)$dept['id'];

$action = trim($_POST['action'] ?? '');

// ── UPDATE SUBJECT AREA ────────────────────────────────────────────────────
if ($action === 'update_subject_area') {
    $member_id       = (int)($_POST['faculty_id'] ?? 0);
    $subject_area_id = (int)($_POST['subject_area_id'] ?? 0);

    if (!$member_id || !member_in_department($conn, $dept_id, $member_id)) {
        echo json_encode(['success' => false, 'message' => 'Faculty member not found in your department.']); exit;
    }

    if ($subject_area_id > 0) {
        $chk = $conn->prepare('SELECT id FROM subject_area WHERE id = ? LIMIT 1');
        $chk->bind_param('i', $subject_area_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            $chk->close();
            echo json_encode(['success' => false, 'message' => 'Invalid subject area.']); exit;
        }
        $chk->close();

        $stmt = $conn->prepare('UPDATE faculty SET subject_area_id = ? WHERE id = ?');
        $stmt->bind_param('ii', $subject_area_id, $member_id);
    } else {
        $stmt = $conn->prepare('UPDATE faculty SET subject_area_id = NULL WHERE id = ?');
        $stmt->bind_param('i', $member_id);
    }

    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true]); exit;
    }
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error.']); exit;
}

// ── UPDATE SCHEDULE ────────────────────────────────────────────────────────
if ($action === 'update_schedule') {
    $slot_id    = (int)($_POST['slot_id'] ?? 0);
    $room_id    = (int)($_POST['room_id'] ?? 0);
    $day        = trim($_POST['day_of_week'] ?? '');
    $start      = trim($_POST['start_time'] ?? '');
    $end        = trim($_POST['end_time'] ?? '');
    $subject_id = (int)($_POST['subject_id'] ?? 0);

    $valid_days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

    if (!$slot_id || !$room_id || !in_array($day, $valid_days) || !$start || !$end) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']); exit;
    }
    if ($start >= $end) {
        echo json_encode(['success' => false, 'message' => 'End time must be after start time.']); exit;
    }

    $slot = schedule_in_department($conn, $dept_id, $slot_id);
    if (!$slot) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found in your department.']); exit;
    }

    if ($subject_id > 0) {
        $chk = $conn->prepare('SELECT id FROM subjects WHERE id = ? LIMIT 1');
        $chk->bind_param('i', $subject_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            $chk->close();
            echo json_encode(['success' => false, 'message' => 'Invalid subject.']); exit;
        }
        $chk->close();
    }

    $chk = $conn->prepare("
        SELECT id FROM schedules
        WHERE classroom_id = ? AND day_of_week = ?
          AND start_time < ? AND end_time > ?
          AND id != ?
        LIMIT 1
    ");
    $chk->bind_param('isssi', $room_id, $day, $end, $start, $slot_id);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        $chk->close();
        echo json_encode(['success' => false, 'message' => 'This slot overlaps with an existing schedule.']); exit;
    }
    $chk->close();

    if ($subject_id > 0) {
        $stmt = $conn->prepare("
            UPDATE schedules
            SET classroom_id = ?, day_of_week = ?, start_time = ?, end_time = ?, subject_id = ?
            WHERE id = ?
        ");
        $stmt->bind_param('isssii', $room_id, $day, $start, $end, $subject_id, $slot_id);
    } else {
        $stmt = $conn->prepare("
            UPDATE schedules
            SET classroom_id = ?, day_of_week = ?, start_time = ?, end_time = ?, subject_id = NULL
            WHERE id = ?
        ");
        $stmt->bind_param('isssi', $room_id, $day, $start, $end, $slot_id);
    }

    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true]); exit;
    }
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error.']); exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
