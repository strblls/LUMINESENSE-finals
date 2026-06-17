<?php
// api/controllers/ClassroomController.php
// Handles all classroom CRUD endpoints, formerly:
//   classrooms.php → action=index, action=show, action=add, action=delete

declare(strict_types=1);

require_once __DIR__ . '/../../php/db_connect.php';

header('Content-Type: application/json');

// ── Auth — admin only ─────────────────────────────────────────────────────────
if (empty($_SESSION['admin_logged_in']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ── Routing ───────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = ($method === 'POST') ? ($_POST['action'] ?? '') : ($_GET['action'] ?? '');

match (true) {
    $method === 'GET' && isset($_GET['id']) => handle_show($conn),
    $method === 'GET'                       => handle_index($conn),
    $action === 'add'                       => handle_add($conn),
    $action === 'delete'                    => handle_delete($conn),
    default                                 => bad_request("Unknown action: {$action}"),
};


// ── Handlers ──────────────────────────────────────────────────────────────────

/**
 * GET ?action=index   (or just GET with no id)
 *
 * Returns all classrooms with their schedule counts.
 *
 * Formerly: classrooms.php — GET, no ?id
 */
function handle_index(mysqli $conn): void
{
    $rows = [];
    $r    = $conn->query("
        SELECT c.*, COUNT(s.id) AS schedule_count
        FROM classrooms c
        LEFT JOIN schedules s ON s.classroom_id = c.id
        GROUP BY c.id
        ORDER BY c.room_name
    ");
    while ($row = $r->fetch_assoc()) $rows[] = $row;

    echo json_encode(['success' => true, 'data' => $rows]);
}

/**
 * GET ?id=X
 *
 * Returns a single classroom by id.
 *
 * Formerly: classrooms.php — GET ?id=X
 */
function handle_show(mysqli $conn): void
{
    $id   = (int)$_GET['id'];
    $stmt = $conn->prepare('SELECT * FROM classrooms WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    echo json_encode(['success' => true, 'data' => $row]);
}

/**
 * POST ?action=add
 * Body: room_name, room_size, description
 *
 * Formerly: classrooms.php — POST action=add
 */
function handle_add(mysqli $conn): void
{
    $name = trim(htmlspecialchars($_POST['room_name']   ?? ''));
    $size = $_POST['room_size']    ?? 'medium';
    $desc = trim(htmlspecialchars($_POST['description'] ?? ''));

    if (!$name) {
        echo json_encode(['success' => false, 'message' => 'Room name required.']);
        exit;
    }
    if (!in_array($size, ['small', 'medium', 'large'])) $size = 'medium';

    $stmt = $conn->prepare('INSERT INTO classrooms (room_name, room_size, description) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $name, $size, $desc);
    $stmt->execute();
    $new_id = $conn->insert_id;
    $stmt->close();

    echo json_encode(['success' => true, 'message' => "Classroom '{$name}' added.", 'id' => $new_id]);
}

/**
 * POST ?action=delete
 * Body: classroom_id
 *
 * Cascades: deletes schedules and lighting_logs for the room first.
 * All three deletes use prepared statements.
 *
 * Formerly: classrooms.php — POST action=delete
 */
function handle_delete(mysqli $conn): void
{
    $id = (int)($_POST['classroom_id'] ?? 0);

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'classroom_id required.']);
        exit;
    }

    $del_sched = $conn->prepare('DELETE FROM schedules WHERE classroom_id = ?');
    $del_sched->bind_param('i', $id);
    $del_sched->execute();
    $del_sched->close();

    $del_logs = $conn->prepare('DELETE FROM lighting_logs WHERE classroom_id = ?');
    $del_logs->bind_param('i', $id);
    $del_logs->execute();
    $del_logs->close();

    $del_room = $conn->prepare('DELETE FROM classrooms WHERE id = ?');
    $del_room->bind_param('i', $id);
    $del_room->execute();
    $del_room->close();

    echo json_encode(['success' => true, 'message' => 'Classroom deleted.']);
}


// ── Helpers ───────────────────────────────────────────────────────────────────

function bad_request(string $message): void
{
    http_response_code(400);
    echo json_encode(['error' => $message]);
    exit;
}