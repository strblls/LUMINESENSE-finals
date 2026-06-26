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

function get_head_departments(mysqli $conn, int $head_id): array {
    $stmt = $conn->prepare("
        SELECT id, name FROM departments
        WHERE head_faculty_id = ? AND status = 'active'
        ORDER BY name
    ");
    $stmt->bind_param('i', $head_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $depts = [];
    while ($row = $res->fetch_assoc()) $depts[] = $row;
    $stmt->close();
    return $depts;
}

function member_in_any_head_department(mysqli $conn, int $head_id, int $member_id): bool {
    $stmt = $conn->prepare("
        SELECT f.id FROM faculty f
        JOIN departments d ON d.id = f.department_id
        WHERE f.id = ? 
          AND d.head_faculty_id = ? 
          AND d.status = 'active'
          AND f.is_verified = 1 
          AND f.approved_by IS NOT NULL
        LIMIT 1
    ");
    $stmt->bind_param('ii', $member_id, $head_id);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

function schedule_in_any_head_department(mysqli $conn, int $head_id, int $slot_id): ?array {
    $stmt = $conn->prepare("
        SELECT s.id, s.classroom_id, s.faculty_id, s.day_of_week, s.start_time, s.end_time, s.subject_id
        FROM schedules s
        JOIN faculty f ON f.id = s.faculty_id
        JOIN departments d ON d.id = f.department_id
        WHERE s.id = ? 
          AND d.head_faculty_id = ? 
          AND d.status = 'active'
        LIMIT 1
    ");
    $stmt->bind_param('ii', $slot_id, $head_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']); exit;
}

$depts = get_head_departments($conn, $head_id);
if (empty($depts)) {
    echo json_encode(['success' => false, 'message' => 'No active departments found for this head.']); exit;
}

$action = trim($_POST['action'] ?? '');

// ── UPDATE SUBJECT AREA ────────────────────────────────────────────────────
if ($action === 'update_subject_area') {
    $member_id           = (int)($_POST['faculty_id'] ?? 0);
    $subject_area_id     = (int)($_POST['subject_area_id'] ?? 0);
    $new_subject_area    = trim($_POST['new_subject_area'] ?? '');
    $subject_id          = (int)($_POST['subject_id'] ?? 0);

    if (!$member_id || !member_in_any_head_department($conn, $head_id, $member_id)) {
        echo json_encode(['success' => false, 'message' => 'Faculty member not found in your department.']); exit;
    }

    $target_subject_area_id = 0;

    if (!empty($new_subject_area)) {
        // Create new subject area
        // First check if there are any subjects available; if not, use first available or create a default?
        if ($subject_id <= 0) {
            // Get first available subject
            $sub_res = $conn->query('SELECT id FROM subjects LIMIT 1');
            if ($sub_res && $sub_res->num_rows > 0) {
                $subject_id = (int)$sub_res->fetch_assoc()['id'];
            } else {
                // Create a default subject
                $stmt = $conn->prepare('INSERT INTO subjects (name) VALUES (?)');
                $default_subject = 'General';
                $stmt->bind_param('s', $default_subject);
                $stmt->execute();
                $subject_id = $conn->insert_id;
                $stmt->close();
            }
        }
        // Check if this subject area already exists
        $chk = $conn->prepare('SELECT id FROM subject_area WHERE name = ? AND subject_id = ? LIMIT 1');
        $chk->bind_param('si', $new_subject_area, $subject_id);
        $chk->execute();
        $res = $chk->get_result();
        if ($res->num_rows > 0) {
            $target_subject_area_id = (int)$res->fetch_assoc()['id'];
        } else {
            // Insert new subject area
            $ins = $conn->prepare('INSERT INTO subject_area (name, subject_id) VALUES (?, ?)');
            $ins->bind_param('si', $new_subject_area, $subject_id);
            $ins->execute();
            $target_subject_area_id = $conn->insert_id;
            $ins->close();
        }
        $chk->close();
    } else if ($subject_area_id > 0) {
        $chk = $conn->prepare('SELECT id FROM subject_area WHERE id = ? LIMIT 1');
        $chk->bind_param('i', $subject_area_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            $chk->close();
            echo json_encode(['success' => false, 'message' => 'Invalid subject area.']); exit;
        }
        $chk->close();
        $target_subject_area_id = $subject_area_id;
    }

    if ($target_subject_area_id > 0) {
        $stmt = $conn->prepare('UPDATE faculty SET subject_area_id = ? WHERE id = ?');
        $stmt->bind_param('ii', $target_subject_area_id, $member_id);
    } else {
        $stmt = $conn->prepare('UPDATE faculty SET subject_area_id = NULL WHERE id = ?');
        $stmt->bind_param('i', $member_id);
    }

    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true, 'subject_area_id' => $target_subject_area_id]); exit;
    }
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error.']); exit;
}

// ── ADD SCHEDULE ────────────────────────────────────────────────────────
if ($action === 'add_schedule') {
    $member_id  = (int)($_POST['member_id'] ?? 0);
    $room_id    = (int)($_POST['room_id'] ?? 0);
    $day        = trim($_POST['day_of_week'] ?? '');
    $start      = trim($_POST['start_time'] ?? '');
    $end        = trim($_POST['end_time'] ?? '');
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $new_subject = trim($_POST['new_subject'] ?? '');

    $valid_days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

    if (!$member_id || !$room_id || !in_array($day, $valid_days) || !$start || !$end) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']); exit;
    }
    if (!member_in_any_head_department($conn, $head_id, $member_id)) {
        echo json_encode(['success' => false, 'message' => 'Faculty member not found in your department.']); exit;
    }
    if ($start >= $end) {
        echo json_encode(['success' => false, 'message' => 'End time must be after start time.']); exit;
    }

    $target_subject_id = $subject_id;

    if (!empty($new_subject)) {
        // Check if subject exists, if not create it
        $chk_subj = $conn->prepare('SELECT id FROM subjects WHERE name = ? LIMIT 1');
        $chk_subj->bind_param('s', $new_subject);
        $chk_subj->execute();
        $res_subj = $chk_subj->get_result();
        if ($res_subj->num_rows > 0) {
            $target_subject_id = (int)$res_subj->fetch_assoc()['id'];
        } else {
            $ins_subj = $conn->prepare('INSERT INTO subjects (name) VALUES (?)');
            $ins_subj->bind_param('s', $new_subject);
            $ins_subj->execute();
            $target_subject_id = $conn->insert_id;
            $ins_subj->close();
        }
        $chk_subj->close();
    }

    // Check for overlaps
    $chk_overlap = $conn->prepare("
        SELECT id FROM schedules
        WHERE classroom_id = ? AND day_of_week = ?
          AND start_time < ? AND end_time > ?
        LIMIT 1
    ");
    $chk_overlap->bind_param('isss', $room_id, $day, $end, $start);
    $chk_overlap->execute();
    if ($chk_overlap->get_result()->num_rows > 0) {
        $chk_overlap->close();
        echo json_encode(['success' => false, 'message' => 'This slot overlaps with an existing schedule.']); exit;
    }
    $chk_overlap->close();

    $stmt = $conn->prepare("
        INSERT INTO schedules (classroom_id, faculty_id, created_by, day_of_week, start_time, end_time, subject_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    if ($target_subject_id > 0) {
        $stmt->bind_param('iiisssi', $room_id, $member_id, $head_id, $day, $start, $end, $target_subject_id);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO schedules (classroom_id, faculty_id, created_by, day_of_week, start_time, end_time)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('iiisss', $room_id, $member_id, $head_id, $day, $start, $end);
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
    $new_subject = trim($_POST['new_subject'] ?? '');

    $valid_days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

    if (!$slot_id || !$room_id || !in_array($day, $valid_days) || !$start || !$end) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']); exit;
    }
    if ($start >= $end) {
        echo json_encode(['success' => false, 'message' => 'End time must be after start time.']); exit;
    }

    $slot = schedule_in_any_head_department($conn, $head_id, $slot_id);
    if (!$slot) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found in your department.']); exit;
    }

    $target_subject_id = $subject_id;
    if (!empty($new_subject)) {
        // Check if subject exists, if not create it
        $chk_subj = $conn->prepare('SELECT id FROM subjects WHERE name = ? LIMIT 1');
        $chk_subj->bind_param('s', $new_subject);
        $chk_subj->execute();
        $res_subj = $chk_subj->get_result();
        if ($res_subj->num_rows > 0) {
            $target_subject_id = (int)$res_subj->fetch_assoc()['id'];
        } else {
            $ins_subj = $conn->prepare('INSERT INTO subjects (name) VALUES (?)');
            $ins_subj->bind_param('s', $new_subject);
            $ins_subj->execute();
            $target_subject_id = $conn->insert_id;
            $ins_subj->close();
        }
        $chk_subj->close();
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

    if ($target_subject_id > 0) {
        $stmt = $conn->prepare("
            UPDATE schedules
            SET classroom_id = ?, day_of_week = ?, start_time = ?, end_time = ?, subject_id = ?
            WHERE id = ?
        ");
        $stmt->bind_param('isssii', $room_id, $day, $start, $end, $target_subject_id, $slot_id);
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

// ── DELETE SCHEDULE ────────────────────────────────────────────────────────
if ($action === 'delete_schedule') {
    $slot_id = (int)($_POST['slot_id'] ?? 0);

    if (!$slot_id) {
        echo json_encode(['success' => false, 'message' => 'Missing slot ID.']); exit;
    }

    $slot = schedule_in_any_head_department($conn, $head_id, $slot_id);
    if (!$slot) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found in your department.']); exit;
    }

    $stmt = $conn->prepare('DELETE FROM schedules WHERE id = ?');
    $stmt->bind_param('i', $slot_id);

    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true]); exit;
    }
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error.']); exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
