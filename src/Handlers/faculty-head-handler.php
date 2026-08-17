<?php
/**
 * LumineSense - Faculty Head Handler
 * Subject-area assignment and schedule management for department heads.
 */

header('Content-Type: application/json');

require_once realpath(__DIR__ . '/../Session/session_guard.php');
check_faculty();
require_once realpath(__DIR__ . '/../Config/db_connect.php');

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
        JOIN junction_faculty_department jfd ON f.id = jfd.faculty_id
        JOIN departments d ON d.id = jfd.department_id
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
        SELECT s.id, s.classroom_id, s.faculty_id, s.day_of_week, s.start_time, s.end_time, s.subject_id, s.created_by
        FROM schedules s
        JOIN faculty f ON f.id = s.faculty_id
        JOIN junction_faculty_department jfd ON f.id = jfd.faculty_id
        JOIN departments d ON d.id = jfd.department_id
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

// Global catch for unexpected errors
try {

$depts = get_head_departments($conn, $head_id);
if (empty($depts)) {
    echo json_encode(['success' => false, 'message' => 'No active departments found for this head.']); exit;
}

$action = trim($_POST['action'] ?? '');

// - SAVE FACULTY COVERAGE (subject areas + subjects, like department edit) -
if ($action === 'save_faculty_coverage') {
    $member_id    = (int)($_POST['faculty_id'] ?? 0);
    $department_id = (int)($_POST['department_id'] ?? 0);

    if (!$member_id || !member_in_any_head_department($conn, $head_id, $member_id)) {
        echo json_encode(['success' => false, 'message' => 'Faculty member not found in your department.']); exit;
    }
    if (!$department_id) {
        echo json_encode(['success' => false, 'message' => 'Department ID is required.']); exit;
    }

    $keep_sa_ids     = json_decode($_POST['keep_sa_ids'] ?? '[]', true) ?: [];
    $remove_sa_ids   = json_decode($_POST['remove_sa_ids'] ?? '[]', true) ?: [];
    $remove_subject_ids = json_decode($_POST['remove_subject_ids'] ?? '[]', true) ?: [];
    $add_sa_ids      = json_decode($_POST['add_sa_ids'] ?? '[]', true) ?: [];
    $add_subject_ids = json_decode($_POST['add_subject_ids'] ?? '[]', true) ?: [];

    $conn->begin_transaction();
    try {
        // Remove subject areas from faculty (unlink)
        if (!empty($remove_sa_ids)) {
            $placeholders = implode(',', array_fill(0, count($remove_sa_ids), '?'));
            $stmt = $conn->prepare("
                DELETE jfsa FROM junction_faculty_subjectarea jfsa
                JOIN subject_area sa ON sa.id = jfsa.subject_area_id
                WHERE jfsa.faculty_id = ? AND sa.department_id = ?
                  AND jfsa.subject_area_id IN ($placeholders)
            ");
            $params = array_merge([$member_id, $department_id], $remove_sa_ids);
            $types = 'ii' . str_repeat('i', count($remove_sa_ids));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        }

        // Remove subjects from faculty (unlink)
        if (!empty($remove_subject_ids)) {
            $placeholders = implode(',', array_fill(0, count($remove_subject_ids), '?'));
            $stmt = $conn->prepare("
                DELETE jfs FROM junction_faculty_subject jfs
                JOIN subjects s ON s.id = jfs.subject_id
                JOIN subject_area sa ON sa.id = s.subject_area_id
                WHERE jfs.faculty_id = ? AND sa.department_id = ?
                  AND jfs.subject_id IN ($placeholders)
            ");
            $params = array_merge([$member_id, $department_id], $remove_subject_ids);
            $types = 'ii' . str_repeat('i', count($remove_subject_ids));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        }

        // Add subject areas selected from available list
        if (!empty($add_sa_ids)) {
            $stmt = $conn->prepare('INSERT IGNORE INTO junction_faculty_subjectarea (faculty_id, subject_area_id) VALUES (?, ?)');
            foreach ($add_sa_ids as $said) {
                $said = (int)$said;
                if ($said > 0) {
                    $stmt->bind_param('ii', $member_id, $said);
                    $stmt->execute();
                }
            }
            $stmt->close();
        }

        // Add subjects selected from available list
        if (!empty($add_subject_ids)) {
            // Update subjects.department_id first
            $upd = $conn->prepare('UPDATE subjects SET department_id = ? WHERE id = ?');
            $ins = $conn->prepare('INSERT IGNORE INTO junction_faculty_subject (faculty_id, subject_id) VALUES (?, ?)');
            foreach ($add_subject_ids as $subid) {
                $subid = (int)$subid;
                if ($subid > 0) {
                    $upd->bind_param('ii', $department_id, $subid);
                    $upd->execute();
                    $ins->bind_param('ii', $member_id, $subid);
                    $ins->execute();
                }
            }
            $upd->close();
            $ins->close();
        }

        $conn->commit();
        echo json_encode(['success' => true]); exit;
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]); exit;
    }
}

// - ADD SCHEDULE ----------------------------
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
        SELECT s.id, s.day_of_week, s.start_time, s.end_time, c.room_name,
               sub.name AS subject_name,
               CONCAT(f.first_name, ' ', f.last_name) AS teacher_name
        FROM schedules s
        JOIN classrooms c ON c.id = s.classroom_id
        LEFT JOIN subjects sub ON sub.id = s.subject_id
        JOIN faculty f ON f.id = s.faculty_id
        WHERE s.classroom_id = ? AND s.day_of_week = ?
          AND s.start_time < ? AND s.end_time > ?
        LIMIT 1
    ");
    $chk_overlap->bind_param('isss', $room_id, $day, $end, $start);
    $chk_overlap->execute();
    $overlap_row = $chk_overlap->get_result()->fetch_assoc();
    if ($overlap_row) {
        $chk_overlap->close();
        echo json_encode([
            'success' => false,
            'message' => 'overlap',
            'conflict' => [
                'day'       => $overlap_row['day_of_week'],
                'start'     => date('g:i A', strtotime($overlap_row['start_time'])),
                'end'       => date('g:i A', strtotime($overlap_row['end_time'])),
                'room'      => $overlap_row['room_name'],
                'subject'   => $overlap_row['subject_name'] ?? 'None',
                'teacher'   => $overlap_row['teacher_name']
            ]
        ]); exit;
    }
    $chk_overlap->close();

    // A faculty member cannot be in two rooms at the same time. Check the
    // member's own schedule for a time overlap on the same day, regardless of
    // which room the other class is in.
    $chk_teacher = $conn->prepare("
        SELECT s.id, s.day_of_week, s.start_time, s.end_time, c.room_name,
               sub.name AS subject_name,
               CONCAT(f.first_name, ' ', f.last_name) AS teacher_name
        FROM schedules s
        JOIN classrooms c ON c.id = s.classroom_id
        LEFT JOIN subjects sub ON sub.id = s.subject_id
        JOIN faculty f ON f.id = s.faculty_id
        WHERE s.faculty_id = ? AND s.day_of_week = ?
          AND s.start_time < ? AND s.end_time > ?
        LIMIT 1
    ");
    $chk_teacher->bind_param('isss', $member_id, $day, $end, $start);
    $chk_teacher->execute();
    $teacher_overlap = $chk_teacher->get_result()->fetch_assoc();
    if ($teacher_overlap) {
        $chk_teacher->close();
        echo json_encode([
            'success' => false,
            'message' => 'overlap',
            'conflict' => [
                'day'       => $teacher_overlap['day_of_week'],
                'start'     => date('g:i A', strtotime($teacher_overlap['start_time'])),
                'end'       => date('g:i A', strtotime($teacher_overlap['end_time'])),
                'room'      => $teacher_overlap['room_name'],
                'subject'   => $teacher_overlap['subject_name'] ?? 'None',
                'teacher'   => $teacher_overlap['teacher_name']
            ]
        ]); exit;
    }
    $chk_teacher->close();

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
    $err = $stmt->error;
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $err]); exit;
}

// - UPDATE SCHEDULE ----------------------------
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
    if ((int)$slot['created_by'] !== $head_id) {
        echo json_encode(['success' => false, 'message' => 'not_your_slot']); exit;
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
        SELECT s.id, s.day_of_week, s.start_time, s.end_time, c.room_name,
               sub.name AS subject_name,
               CONCAT(f.first_name, ' ', f.last_name) AS teacher_name
        FROM schedules s
        JOIN classrooms c ON c.id = s.classroom_id
        LEFT JOIN subjects sub ON sub.id = s.subject_id
        JOIN faculty f ON f.id = s.faculty_id
        WHERE s.classroom_id = ? AND s.day_of_week = ?
          AND s.start_time < ? AND s.end_time > ?
          AND s.id != ?
        LIMIT 1
    ");
    $chk->bind_param('isssi', $room_id, $day, $end, $start, $slot_id);
    $chk->execute();
    $overlap_row = $chk->get_result()->fetch_assoc();
    if ($overlap_row) {
        $chk->close();
        echo json_encode([
            'success' => false,
            'message' => 'overlap',
            'conflict' => [
                'day'       => $overlap_row['day_of_week'],
                'start'     => date('g:i A', strtotime($overlap_row['start_time'])),
                'end'       => date('g:i A', strtotime($overlap_row['end_time'])),
                'room'      => $overlap_row['room_name'],
                'subject'   => $overlap_row['subject_name'] ?? 'None',
                'teacher'   => $overlap_row['teacher_name']
            ]
        ]); exit;
    }
    $chk->close();

    // A faculty member cannot be in two rooms at the same time. Check the
    // member's own schedule for a time overlap on the same day, regardless of
    // which room the other class is in (exclude the slot being edited).
    $chk_teacher = $conn->prepare("
        SELECT s.id, s.day_of_week, s.start_time, s.end_time, c.room_name,
               sub.name AS subject_name,
               CONCAT(f.first_name, ' ', f.last_name) AS teacher_name
        FROM schedules s
        JOIN classrooms c ON c.id = s.classroom_id
        LEFT JOIN subjects sub ON sub.id = s.subject_id
        JOIN faculty f ON f.id = s.faculty_id
        WHERE s.faculty_id = ? AND s.day_of_week = ?
          AND s.start_time < ? AND s.end_time > ?
          AND s.id != ?
        LIMIT 1
    ");
    $chk_teacher->bind_param('isssi', $slot['faculty_id'], $day, $end, $start, $slot_id);
    $chk_teacher->execute();
    $teacher_overlap = $chk_teacher->get_result()->fetch_assoc();
    if ($teacher_overlap) {
        $chk_teacher->close();
        echo json_encode([
            'success' => false,
            'message' => 'overlap',
            'conflict' => [
                'day'       => $teacher_overlap['day_of_week'],
                'start'     => date('g:i A', strtotime($teacher_overlap['start_time'])),
                'end'       => date('g:i A', strtotime($teacher_overlap['end_time'])),
                'room'      => $teacher_overlap['room_name'],
                'subject'   => $teacher_overlap['subject_name'] ?? 'None',
                'teacher'   => $teacher_overlap['teacher_name']
            ]
        ]); exit;
    }
    $chk_teacher->close();

    if ($target_subject_id > 0) {
        $stmt = $conn->prepare("
            UPDATE schedules
            SET classroom_id = ?, day_of_week = ?, start_time = ?, end_time = ?, subject_id = ?,
                updated_at = NOW(), updated_by = ?, extended_until = NULL
            WHERE id = ?
        ");
        $stmt->bind_param('isssiii', $room_id, $day, $start, $end, $target_subject_id, $head_id, $slot_id);
    } else {
        $stmt = $conn->prepare("
            UPDATE schedules
            SET classroom_id = ?, day_of_week = ?, start_time = ?, end_time = ?, subject_id = NULL,
                updated_at = NOW(), updated_by = ?, extended_until = NULL
            WHERE id = ?
        ");
        $stmt->bind_param('isssiii', $room_id, $day, $start, $end, $head_id, $slot_id);
    }

    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true]); exit;
    }
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error.']); exit;
}

// - DELETE SCHEDULE ----------------------------
if ($action === 'delete_schedule') {
    $slot_id = (int)($_POST['slot_id'] ?? 0);

    if (!$slot_id) {
        echo json_encode(['success' => false, 'message' => 'Missing slot ID.']); exit;
    }

    $slot = schedule_in_any_head_department($conn, $head_id, $slot_id);
    if (!$slot) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found in your department.']); exit;
    }
    if ((int)$slot['created_by'] !== $head_id) {
        echo json_encode(['success' => false, 'message' => 'not_your_slot']); exit;
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

// - SAVE DEPARTMENT SUBJECT AREAS ----------------------
if ($action === 'save_department_subject_areas') {
    $department_id = (int)($_POST['department_id'] ?? 0);
    if (!$department_id) {
        echo json_encode(['success' => false, 'message' => 'Missing department ID.']); exit;
    }

    // Verify this head manages this department
    $chk_dept = $conn->prepare("SELECT id FROM departments WHERE id = ? AND head_faculty_id = ? AND status = 'active' LIMIT 1");
    $chk_dept->bind_param('ii', $department_id, $head_id);
    $chk_dept->execute();
    if ($chk_dept->get_result()->num_rows === 0) {
        $chk_dept->close();
        echo json_encode(['success' => false, 'message' => 'Department not found or not under your management.']); exit;
    }
    $chk_dept->close();

    $keep_sa_ids = json_decode($_POST['keep_sa_ids'] ?? '[]', true) ?: [];
    $remove_sa_ids = json_decode($_POST['remove_sa_ids'] ?? '[]', true) ?: [];
    $remove_subject_ids = json_decode($_POST['remove_subject_ids'] ?? '[]', true) ?: [];
    $new_sa_names = json_decode($_POST['new_sa_names'] ?? '[]', true) ?: [];
    $selected_sa_id = (int)($_POST['selected_sa_id'] ?? 0);
    $new_subject_names = json_decode($_POST['new_subject_names'] ?? '[]', true) ?: [];

    $conn->begin_transaction();
    try {
        // Remove subject areas that were marked for deletion
        if (!empty($remove_sa_ids)) {
            // First, remove subjects under these subject areas
            $placeholders = implode(',', array_fill(0, count($remove_sa_ids), '?'));
            $types = str_repeat('i', count($remove_sa_ids));

            $stmt = $conn->prepare("DELETE FROM subjects WHERE subject_area_id IN ($placeholders)");
            $stmt->bind_param($types, ...$remove_sa_ids);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM subject_area WHERE id IN ($placeholders) AND department_id = ?");
            $all_ids = array_merge($remove_sa_ids, [$department_id]);
            $types_all = str_repeat('i', count($remove_sa_ids)) . 'i';
            $stmt->bind_param($types_all, ...$all_ids);
            $stmt->execute();
            $stmt->close();
        }

        // Remove subjects that were marked for deletion
        if (!empty($remove_subject_ids)) {
            $placeholders = implode(',', array_fill(0, count($remove_subject_ids), '?'));
            $types = str_repeat('i', count($remove_subject_ids));
            $stmt = $conn->prepare("DELETE FROM subjects WHERE id IN ($placeholders) AND subject_area_id IS NOT NULL");
            $stmt->bind_param($types, ...$remove_subject_ids);
            $stmt->execute();
            $stmt->close();
        }

        // Add new subject areas
        foreach ($new_sa_names as $sa_name) {
            $name = trim($sa_name);
            if (empty($name)) continue;

            $stmt = $conn->prepare("INSERT INTO subject_area (name, department_id) VALUES (?, ?)");
            $stmt->bind_param('si', $name, $department_id);
            $stmt->execute();
            $stmt->close();
        }

        // Add new subjects under selected subject area
        if ($selected_sa_id > 0 && !empty($new_subject_names)) {
            // Verify the subject area belongs to this department
            $chk_sa = $conn->prepare("SELECT id FROM subject_area WHERE id = ? AND department_id = ? LIMIT 1");
            $chk_sa->bind_param('ii', $selected_sa_id, $department_id);
            $chk_sa->execute();
            if ($chk_sa->get_result()->num_rows > 0) {
                $chk_sa->close();
                foreach ($new_subject_names as $subj_name) {
                    $name = trim($subj_name);
                    if (empty($name)) continue;
                    $stmt = $conn->prepare("INSERT INTO subjects (name, subject_area_id) VALUES (?, ?)");
                    $stmt->bind_param('si', $name, $selected_sa_id);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $chk_sa->close();
            }
        }

        $conn->commit();
        echo json_encode(['success' => true]); exit;
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]); exit;
    }
}

} catch (Throwable $e) {
    $log = date('Y-m-d H:i:s') . ' ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
    @file_put_contents(__DIR__ . '/handler_error.log', $log, FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
