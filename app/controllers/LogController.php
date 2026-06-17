<?php
// api/controllers/LogController.php
// Handles lighting log read and write endpoints, formerly:
//   logs.php → action=index (GET), action=store (POST)

declare(strict_types=1);

require_once __DIR__ . '/../../php/db_connect.php';

header('Content-Type: application/json');

// ── Routing ───────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];

match ($method) {
    'GET'  => handle_index($conn),
    'POST' => handle_store($conn),
    default => method_not_allowed(),
};


// ── Handlers ──────────────────────────────────────────────────────────────────

/**
 * GET ?room=X&type=X&date=YYYY-MM-DD&limit=200
 *
 * Returns filtered lighting logs. Admin or faculty session required.
 *
 * Formerly: logs.php — GET branch
 */
function handle_index(mysqli $conn): void
{
    if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['faculty_logged_in'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $where  = ['1=1'];
    $params = [];
    $types  = '';

    if (!empty($_GET['room'])) {
        $where[]  = 'l.classroom_id = ?';
        $params[] = (int)$_GET['room'];
        $types   .= 'i';
    }

    $valid_types = ['on', 'off', 'gesture', 'schedule', 'security_alert'];
    if (!empty($_GET['type']) && in_array($_GET['type'], $valid_types)) {
        $where[]  = 'l.event_type = ?';
        $params[] = $_GET['type'];
        $types   .= 's';
    }

    if (!empty($_GET['date'])) {
        $where[]  = 'DATE(l.event_time) = ?';
        $params[] = $_GET['date'];
        $types   .= 's';
    }

    $limit = min((int)($_GET['limit'] ?? 200), 500);
    $sql   = "
        SELECT l.id, l.event_type, l.triggered_by, l.event_time, c.room_name
        FROM lighting_logs l
        JOIN classrooms c ON c.id = l.classroom_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY l.event_time DESC
        LIMIT {$limit}
    ";

    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $r    = $stmt->get_result();
    $rows = [];
    while ($row = $r->fetch_assoc()) $rows[] = $row;
    $stmt->close();

    echo json_encode(['success' => true, 'data' => $rows]);
}

/**
 * POST
 * Body: classroom_id, event_type, triggered_by, faculty_id (optional)
 *
 * Writes a log entry. Called by Arduino or dashboard.
 * No session required — device/internal call.
 *
 * Formerly: logs.php — POST branch
 */
function handle_store(mysqli $conn): void
{
    $cid        = (int)($_POST['classroom_id'] ?? 0);
    $type       = $_POST['event_type']   ?? '';
    $by         = $_POST['triggered_by'] ?? 'sensor';
    $faculty_id = !empty($_POST['faculty_id']) ? (int)$_POST['faculty_id'] : null;

    $valid = ['on', 'off', 'gesture', 'schedule', 'security_alert'];
    if (!$cid || !in_array($type, $valid)) {
        echo json_encode(['success' => false, 'message' => 'classroom_id and valid event_type required.']);
        exit;
    }

    $stmt = $conn->prepare("
        INSERT INTO lighting_logs (classroom_id, faculty_id, event_type, triggered_by)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param('iiss', $cid, $faculty_id, $type, $by);
    $stmt->execute();
    $new_id = $conn->insert_id;
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Log saved.', 'id' => $new_id]);
}


// ── Helpers ───────────────────────────────────────────────────────────────────

function method_not_allowed(): void
{
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}