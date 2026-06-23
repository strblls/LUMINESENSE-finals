<?php
// api/controllers/ScheduleController.php
// Handles all schedule and extension-request endpoints, formerly:
//   schedules.php          → action=list, action=add, action=delete  (admin)
//   request-extension.php  → action=request_extension                (faculty)

declare(strict_types=1);

require_once __DIR__ . '/../../php/db_connect.php';

header('Content-Type: application/json');

// ── Routing ───────────────────────────────────────────────────────────────────
// schedules.php used REQUEST_METHOD for routing; here everything goes through
// ?action= so the caller controls intent explicitly.

$action = $_GET['action'] ?? $_POST['action'] ?? '';

match ($action) {
    'list'               => handle_list($conn),
    'add'                => handle_add($conn),
    'delete'             => handle_delete($conn),
    'request_extension'  => handle_request_extension($conn),
    default              => bad_request("Unknown action: {$action}"),
};


// ── Auth helpers ──────────────────────────────────────────────────────────────

function require_admin(): void
{
    if (empty($_SESSION['admin_logged_in']) || $_SESSION['role'] !== 'admin') {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

function require_faculty(): void
{
    if (empty($_SESSION['faculty_logged_in'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}


// ── Handlers ──────────────────────────────────────────────────────────────────

/**
 * GET ?action=list[&classroom_id=X]
 *
 * Returns all schedules, or filtered by classroom_id.
 * Admin only.
 *
 * Formerly: schedules.php — GET branch
 */
function handle_list(mysqli $conn): void
{
    require_admin();

    $rows = [];

    if (!empty($_GET['classroom_id'])) {
        $cid  = (int)$_GET['classroom_id'];
        $stmt = $conn->prepare("
            SELECT s.id, s.day_of_week, s.start_time, s.end_time,
                   s.classroom_id, c.room_name
            FROM schedules s
            JOIN classrooms c ON c.id = s.classroom_id
            WHERE s.classroom_id = ?
            ORDER BY FIELD(s.day_of_week,
                'Monday','Tuesday','Wednesday','Thursday',
                'Friday','Saturday','Sunday'),
                s.start_time
        ");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $r = $stmt->get_result();
        $stmt->close();
    } else {
        $r = $conn->query("
            SELECT s.id, s.day_of_week, s.start_time, s.end_time,
                   s.classroom_id, c.room_name
            FROM schedules s
            JOIN classrooms c ON c.id = s.classroom_id
            ORDER BY FIELD(s.day_of_week,
                'Monday','Tuesday','Wednesday','Thursday',
                'Friday','Saturday','Sunday'),
                s.start_time
        ");
    }

    while ($row = $r->fetch_assoc()) $rows[] = $row;

    echo json_encode(['success' => true, 'data' => $rows]);
}

/**
 * POST ?action=add
 * Body: classroom_id, day_of_week, start_time, end_time
 *
 * Validates, checks for overlaps, inserts, then marks schedule_dirty.
 * Admin only.
 *
 * Formerly: schedules.php — POST action=add
 */
function handle_add(mysqli $conn): void
{
    require_admin();

    $admin_id  = (int)$_SESSION['admin_id'];
    $valid_days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

    $cid   = (int)($_POST['classroom_id'] ?? 0);
    $day   = $_POST['day_of_week'] ?? '';
    $start = $_POST['start_time']  ?? '';
    $end   = $_POST['end_time']    ?? '';

    $errors = [];
    if (!$cid)                        $errors[] = 'classroom_id required.';
    if (!in_array($day, $valid_days)) $errors[] = 'Invalid day.';
    if (!$start || !$end)             $errors[] = 'start_time and end_time required.';
    if ($start >= $end)               $errors[] = 'start_time must be before end_time.';

    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        exit;
    }

    // Overlap check
    $stmt = $conn->prepare("
        SELECT id FROM schedules
        WHERE classroom_id = ? AND day_of_week = ?
          AND NOT (end_time <= ? OR start_time >= ?)
    ");
    $stmt->bind_param('isss', $cid, $day, $start, $end);
    $stmt->execute();
    $stmt->store_result();
    $overlaps = $stmt->num_rows > 0;
    $stmt->close();

    if ($overlaps) {
        echo json_encode(['success' => false, 'message' => 'Time slot overlaps existing schedule.']);
        exit;
    }

    // Insert schedule
    $stmt = $conn->prepare("
        INSERT INTO schedules (classroom_id, day_of_week, start_time, end_time, created_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('isssi', $cid, $day, $start, $end, $admin_id);
    $stmt->execute();
    $new_id = $conn->insert_id;
    $stmt->close();

    // Mark dirty — prepared, no raw interpolation
    $upd = $conn->prepare('UPDATE classrooms SET schedule_dirty = 1 WHERE id = ?');
    $upd->bind_param('i', $cid);
    $upd->execute();
    $upd->close();

    echo json_encode(['success' => true, 'message' => 'Schedule added.', 'id' => $new_id]);
}

/**
 * POST ?action=delete
 * Body: schedule_id
 *
 * Fetches classroom_id first (prepared), deletes, then marks schedule_dirty.
 * Admin only.
 *
 * Formerly: schedules.php — POST action=delete
 * Fix: replaced both raw query interpolations with prepared statements.
 */
function handle_delete(mysqli $conn): void
{
    require_admin();

    $id = (int)($_POST['schedule_id'] ?? 0);

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'schedule_id required.']);
        exit;
    }

    // Fetch classroom_id before deleting — prepared
    $stmt = $conn->prepare('SELECT classroom_id FROM schedules WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $schedRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Delete — prepared
    $stmt = $conn->prepare('DELETE FROM schedules WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    // Mark dirty — prepared
    if ($schedRow) {
        $cid = (int)$schedRow['classroom_id'];
        $upd = $conn->prepare('UPDATE classrooms SET schedule_dirty = 1 WHERE id = ?');
        $upd->bind_param('i', $cid);
        $upd->execute();
        $upd->close();
    }

    echo json_encode(['success' => true, 'message' => 'Schedule removed.']);
}

/**
 * POST ?action=request_extension
 * Body: schedule_id, extend_mins
 *
 * Faculty submits an extension request for an active schedule.
 * Blocks duplicate pending requests for the same schedule.
 * Faculty session required.
 *
 * Formerly: request-extension.php
 */
function handle_request_extension(mysqli $conn): void
{
    require_faculty();

    $faculty_id  = (int)$_SESSION['faculty_id'];
    $schedule_id = (int)($_POST['schedule_id'] ?? 0);
    $extend_mins = (int)($_POST['extend_mins']  ?? 30);

    if (!$schedule_id || $extend_mins <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid data.']);
        exit;
    }

    // Verify that the schedule belongs to the logged-in faculty member
    $stmt = $conn->prepare("
        SELECT id FROM schedules
        WHERE id = ? AND faculty_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $schedule_id, $faculty_id);
    $stmt->execute();
    $stmt->store_result();
    $belongs = $stmt->num_rows > 0;
    $stmt->close();

    if (!$belongs) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden: You do not own this schedule.']);
        exit;
    }

    // Block duplicate pending request for this schedule
    $stmt = $conn->prepare("
        SELECT id FROM extension_requests
        WHERE schedule_id = ? AND status = 'pending'
        LIMIT 1
    ");
    $stmt->bind_param('i', $schedule_id);
    $stmt->execute();
    $stmt->store_result();
    $already = $stmt->num_rows > 0;
    $stmt->close();

    if ($already) {
        echo json_encode([
            'success' => false,
            'message' => 'You already have a pending extension request for this class.',
        ]);
        exit;
    }

    $stmt = $conn->prepare("
        INSERT INTO extension_requests (faculty_id, schedule_id, extend_mins)
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param('iii', $faculty_id, $schedule_id, $extend_mins);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Extension request submitted. Waiting for admin approval.',
    ]);
}


// ── Helpers ───────────────────────────────────────────────────────────────────

function bad_request(string $message): void
{
    http_response_code(400);
    echo json_encode(['error' => $message]);
    exit;
}