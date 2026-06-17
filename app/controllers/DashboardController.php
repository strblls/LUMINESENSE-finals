<?php
/**
 * app/Controllers/DashboardController.php
 * ────────────────────────────────────────
 * Handles all dashboard status endpoints.
 *
 * Routes:
 *   GET ?action=status           → live status all rooms (faculty + admin)
 *   GET ?action=admin_status     → admin overview with counts + merged activity
 *   GET ?action=faculty_snapshot &classroom_id=X  → faculty single-room snapshot
 *
 * Merged from:
 *   - api/status.php
 *   - api/admin-status.php
 *   - api/faculty-status.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../php/db_connect.php';

date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');
header('Cache-Control: no-store');

// ── Routing ───────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';

match ($action) {
    'status'              => handle_status($conn),
    'admin_status'        => handle_admin_status($conn),
    'faculty_snapshot'    => handle_faculty_snapshot($conn),
    default               => bad_request("Unknown action: {$action}"),
};


// ════════════════════════════════════════════════════════════════════════════
// GET ?action=status
// ════════════════════════════════════════════════════════════════════════════
/**
 * Returns current light status + active schedule for all classrooms.
 * Accessible by both admin and faculty sessions.
 *
 * Formerly: api/status.php
 */
function handle_status(mysqli $conn): void
{
    if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['faculty_logged_in'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $now_time = date('H:i:s');
    $now_day  = date('l');   // e.g. "Monday"

    $r    = $conn->query("
        SELECT c.id, c.room_name, c.room_size, c.description,
               c.light_status, c.row1_status, c.row2_status, c.row3_status,
               c.pir_occupied, c.pir_since
        FROM classrooms c
        ORDER BY c.room_name
    ");

    $rows = [];
    while ($room = $r->fetch_assoc()) {
        $cid = (int)$room['id'];

        $stmt = $conn->prepare("
            SELECT id, start_time, end_time FROM schedules
            WHERE classroom_id = ? AND day_of_week = ?
              AND start_time <= ? AND end_time >= ?
            LIMIT 1
        ");
        $stmt->bind_param('isss', $cid, $now_day, $now_time, $now_time);
        $stmt->execute();
        $sched = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $room['active_schedule'] = $sched ?: null;
        $rows[] = $room;
    }

    echo json_encode([
        'success' => true,
        'time'    => date('H:i:s'),
        'day'     => $now_day,
        'data'    => $rows,
    ]);
}


// ════════════════════════════════════════════════════════════════════════════
// GET ?action=admin_status
// ════════════════════════════════════════════════════════════════════════════
/**
 * Returns live counts, classroom list, and merged recent activity logs.
 * Admin session required.
 *
 * Formerly: api/admin-status.php
 */
function handle_admin_status(mysqli $conn): void
{
    if (empty($_SESSION['admin_logged_in'])) {
        http_response_code(401);
        echo json_encode(['success' => false]);
        exit;
    }

    $admin_id = (int)$_SESSION['admin_id'];

    // ── Counts ────────────────────────────────────────────────────────
    $pending = (int)$conn->query("
        SELECT COUNT(*) AS c FROM faculty
        WHERE is_verified = 1 AND approved_by IS NULL
    ")->fetch_assoc()['c'];

    $ext_pending = (int)$conn->query("
        SELECT COUNT(*) AS c FROM extension_requests WHERE status = 'pending'
    ")->fetch_assoc()['c'];

    $lights_on = (int)$conn->query("
        SELECT COUNT(*) AS c FROM classrooms WHERE light_status = 'on'
    ")->fetch_assoc()['c'];

    $total_rooms = (int)$conn->query("
        SELECT COUNT(*) AS c FROM classrooms
    ")->fetch_assoc()['c'];

    // ── Classrooms ────────────────────────────────────────────────────────
    $classrooms = [];
    $r = $conn->query("
        SELECT id, room_name, room_size, light_status
        FROM classrooms ORDER BY room_name
    ");
    while ($row = $r->fetch_assoc()) $classrooms[] = $row;

    // ── Recent activity — merge three log sources ─────────────────────────
    $logs = [];

    // Lighting logs
    $r = $conn->query("
        SELECT ll.event_type, ll.triggered_by, ll.event_time,
               c.room_name, 'room' AS log_type, NULL AS admin_name
        FROM lighting_logs ll
        JOIN classrooms c ON c.id = ll.classroom_id
        ORDER BY ll.event_time DESC
        LIMIT 20
    ");
    if ($r) while ($row = $r->fetch_assoc()) $logs[] = $row;

    // Admin action logs
    $r2 = $conn->query("
        SELECT al.action AS event_type, al.notes AS triggered_by,
               al.created_at AS event_time, al.target_name AS room_name,
               'admin' AS log_type,
               CONCAT(a.first_name, ' ', a.last_name) AS admin_name
        FROM admin_logs al
        JOIN admins a ON a.id = al.admin_id
        ORDER BY al.created_at DESC
        LIMIT 20
    ");
    if ($r2) while ($row = $r2->fetch_assoc()) $logs[] = $row;

    // Admin login logs (other admins only)
    $stmt = $conn->prepare("
        SELECT 'admin_login' AS event_type, 'Logged in' AS triggered_by,
               login_at AS event_time, 'System' AS room_name,
               'admin_login' AS log_type,
               CONCAT(a.first_name, ' ', a.last_name) AS admin_name
        FROM admin_login_logs all2
        JOIN admins a ON a.id = all2.admin_id
        WHERE all2.admin_id != ?
        ORDER BY login_at DESC
        LIMIT 5
    ");
    $stmt->bind_param('i', $admin_id);
    $stmt->execute();
    $r3 = $stmt->get_result();
    while ($row = $r3->fetch_assoc()) $logs[] = $row;
    $stmt->close();

    // Sort merged list newest-first, keep top 10
    usort($logs, fn($a, $b) => strtotime($b['event_time']) - strtotime($a['event_time']));
    $logs = array_slice($logs, 0, 10);

    echo json_encode([
        'success'     => true,
        'pending'     => $pending,
        'ext_pending' => $ext_pending,
        'lights_on'   => $lights_on,
        'total_rooms' => $total_rooms,
        'classrooms'  => $classrooms,
        'logs'        => $logs,
    ]);
}


// ════════════════════════════════════════════════════════════════════════════
// GET ?action=faculty_snapshot&classroom_id=X
// ════════════════════════════════════════════════════════════════════════════
/**
 * Live dashboard snapshot for faculty home.
 * Returns light status, row states, PIR info, active schedule, and activity logs
 * for a specific classroom.
 *
 * Called every 3s by the JavaScript poll loop on the faculty dashboard.
 *
 * Formerly: api/faculty-status.php
 */
function handle_faculty_snapshot(mysqli $conn): void
{
    if (empty($_SESSION['faculty_logged_in'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }

    $cid = (int)($_GET['classroom_id'] ?? 0);
    if (!$cid) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'classroom_id required.']);
        exit;
    }

    $now_time = date('H:i:s');
    $now_day  = date('l');

    // ── Classroom row ─────────────────────────────────────────────────────────
    $stmt = $conn->prepare("
        SELECT light_status, row1_status, row2_status, row3_status, pir_occupied, pir_since
        FROM classrooms WHERE id = ? LIMIT 1
    ");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $stmt->bind_result($light_status, $row1_status, $row2_status, $row3_status, $pir_occupied, $pir_since);
    $stmt->fetch();
    $stmt->close();

    // ── Active schedule ───────────────────────────────────────────────────────
    $active_schedule = null;
    $stmt = $conn->prepare("
        SELECT id, start_time, end_time, extended_until
        FROM schedules
        WHERE classroom_id = ?
          AND day_of_week  = ?
          AND start_time  <= ?
          AND (extended_until >= ? OR (extended_until IS NULL AND end_time >= ?))
        ORDER BY start_time
        LIMIT 1
    ");
    $stmt->bind_param('issss', $cid, $now_day, $now_time, $now_time, $now_time);
    $stmt->execute();
    $r = $stmt->get_result();
    if ($row = $r->fetch_assoc()) $active_schedule = $row;
    $stmt->close();

    // ── Recent activity logs (last 7) ─────────────────────────────────────────
    $logs = [];
    $stmt = $conn->prepare("
        SELECT l.event_type, l.triggered_by, l.event_time, c.room_name
        FROM lighting_logs l
        JOIN classrooms c ON c.id = l.classroom_id
        WHERE l.classroom_id = ?
        ORDER BY l.event_time DESC
        LIMIT 7
    ");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $logs[] = $row;
    $stmt->close();

    // ── Gesture logs (this faculty only, last 20) ─────────────────────────────
    $faculty_id = (int)$_SESSION['faculty_id'];
    $gesture_logs = [];
    $stmt = $conn->prepare("
        SELECT l.event_type, l.triggered_by, l.event_time, c.room_name
        FROM lighting_logs l
        JOIN classrooms c ON c.id = l.classroom_id
        WHERE l.faculty_id = ?
          AND l.classroom_id = ?
          AND l.triggered_by = 'gesture'
        ORDER BY l.event_time DESC
        LIMIT 20
    ");
    $stmt->bind_param('ii', $faculty_id, $cid);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $gesture_logs[] = $row;
    $stmt->close();

    echo json_encode([
        'success'         => true,
        'server_time'     => $now_time,
        'light_status'    => $light_status ?? 'off',
        'row1_status'     => $row1_status ?? 'off',
        'row2_status'     => $row2_status ?? 'off',
        'row3_status'     => $row3_status ?? 'off',
        'pir_occupied'    => (bool)$pir_occupied,
        'pir_since'       => $pir_since,
        'schedule_active' => $active_schedule !== null,
        'schedule_end'    => $active_schedule
                                ? ($active_schedule['extended_until'] ?? $active_schedule['end_time'])
                                : null,
        'logs'            => $logs,
        'gesture_logs'    => $gesture_logs,
    ]);
}


// ── Helpers ───────────────────────────────────────────────────────────────────

function bad_request(string $message): void
{
    http_response_code(400);
    echo json_encode(['error' => $message]);
    exit;
}