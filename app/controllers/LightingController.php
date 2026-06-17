<?php
// api/controllers/LightingController.php
// Handles all light toggle endpoints, formerly:
//   lights.php → action=toggle (row or all), logs every event

declare(strict_types=1);

require_once __DIR__ . '/../../php/db_connect.php';

header('Content-Type: application/json');

// ── Auth — admin or faculty ───────────────────────────────────────────────────
if (empty($_SESSION['faculty_logged_in']) && empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ── POST only ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}

// ── Routing ───────────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? 'toggle';

match ($action) {
    'toggle' => handle_toggle($conn),
    default  => bad_request("Unknown action: {$action}"),
};


// ── Handlers ──────────────────────────────────────────────────────────────────

/**
 * POST ?action=toggle
 * Body: classroom_id, row (1|2|3|all), state (on|off), triggered_by
 *
 * Toggles a single row or all rows, re-derives global light_status,
 * then writes a lighting_log entry.
 *
 * Fix: $col is now whitelisted before interpolation into the query —
 * only 'row1_status', 'row2_status', 'row3_status' are accepted.
 *
 * Formerly: lights.php
 */
function handle_toggle(mysqli $conn): void
{
    $cid       = (int)($_POST['classroom_id'] ?? 0);
    $row       = $_POST['row']          ?? 'all';   // '1', '2', '3', or 'all'
    $state     = $_POST['state']        ?? 'off';   // 'on' or 'off'
    $triggered = $_POST['triggered_by'] ?? 'manual';
    $faculty_id = !empty($_SESSION['faculty_id']) ? (int)$_SESSION['faculty_id'] : null;

    if (!$cid || !in_array($state, ['on', 'off'])) {
        echo json_encode(['success' => false, 'message' => 'classroom_id and state (on|off) required.']);
        exit;
    }

    if ($row === 'all') {
        // ── All rows ──────────────────────────────────────────────────────────
        $stmt = $conn->prepare("
            UPDATE classrooms
            SET light_status = ?, row1_status = ?, row2_status = ?, row3_status = ?
            WHERE id = ?
        ");
        $stmt->bind_param('ssssi', $state, $state, $state, $state, $cid);
        $stmt->execute();
        $stmt->close();

    } else {
        // ── Single row — whitelist the column name ────────────────────────────
        $allowed_cols = ['1' => 'row1_status', '2' => 'row2_status', '3' => 'row3_status'];

        if (!isset($allowed_cols[$row])) {
            echo json_encode(['success' => false, 'message' => 'row must be 1, 2, 3, or all.']);
            exit;
        }

        $col  = $allowed_cols[$row];   // safe: only one of three known column names
        $stmt = $conn->prepare("UPDATE classrooms SET {$col} = ? WHERE id = ?");
        $stmt->bind_param('si', $state, $cid);
        $stmt->execute();
        $stmt->close();

        // Re-derive global light_status from all three rows
        $stmt = $conn->prepare("
            SELECT row1_status, row2_status, row3_status
            FROM classrooms WHERE id = ? LIMIT 1
        ");
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

    // ── Log the event ─────────────────────────────────────────────────────────
    $stmt = $conn->prepare("
        INSERT INTO lighting_logs (classroom_id, faculty_id, event_type, triggered_by)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param('iiss', $cid, $faculty_id, $state, $triggered);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'light_status' => $state, 'row' => $row]);
}


// ── Helpers ───────────────────────────────────────────────────────────────────

function bad_request(string $message): void
{
    http_response_code(400);
    echo json_encode(['error' => $message]);
    exit;
}