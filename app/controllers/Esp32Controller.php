<?php
// api/controllers/Esp32Controller.php
// Handles all ESP32 device endpoints, formerly:
//   esp32-schedule.php       → action=schedule
//   esp32-schedule-flag.php  → action=flag
//   esp32-status.php         → action=status
//   esp32-update-rows.php    → action=update_rows

declare(strict_types=1);

require_once __DIR__ . '/../../php/config.php';
require_once __DIR__ . '/../../php/db_connect.php';

date_default_timezone_set('Asia/Manila');

// ── Token gate — every request must pass ─────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$token  = ($method === 'POST') ? ($_POST['token'] ?? '') : ($_GET['token'] ?? '');
$action = ($method === 'POST') ? ($_POST['action'] ?? '') : ($_GET['action'] ?? '');

if ($token !== ESP32_TOKEN) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

// ── Route ─────────────────────────────────────────────────────────────────────
match ($action) {
    'schedule'    => handle_schedule($conn),
    'flag'        => handle_flag($conn),
    'status'      => handle_status($conn),
    'update_rows' => handle_update_rows($conn),
    default       => bad_request("Unknown action: {$action}"),
};


// ── Handlers ──────────────────────────────────────────────────────────────────

/**
 * GET ?action=schedule&classroom_id=1
 *
 * Returns today's schedule slots as plain-text time ranges, e.g.:
 *   08:00-09:30,10:00-11:30
 *
 * Formerly: esp32-schedule.php
 */
function handle_schedule(mysqli $conn): void
{
    header('Content-Type: text/plain');   // override; ESP32 parses raw text here

    $cid = (int)($_GET['classroom_id'] ?? 1);
    $day = date('l');   // e.g. "Tuesday" in Asia/Manila

    $stmt = $conn->prepare("
        SELECT start_time, COALESCE(extended_until, end_time) AS end_time
        FROM schedules
        WHERE classroom_id = ? AND day_of_week = ?
        ORDER BY start_time
    ");
    $stmt->bind_param('is', $cid, $day);
    $stmt->execute();
    $res = $stmt->get_result();

    $slots = [];
    while ($row = $res->fetch_assoc()) {
        $slots[] = date('H:i', strtotime($row['start_time']))
                 . '-'
                 . date('H:i', strtotime($row['end_time']));
    }
    $stmt->close();

    echo implode(',', $slots);
}

/**
 * GET ?action=flag&classroom_id=1
 *
 * Returns {"dirty":true|false}.
 * Resets the flag to 0 immediately after reading if it was set.
 * ESP32 polls this every 5 s to know when to re-fetch its schedule.
 *
 * Formerly: esp32-schedule-flag.php
 */
function handle_flag(mysqli $conn): void
{
    $classroom_id = (int)($_GET['classroom_id'] ?? 0);

    if ($classroom_id === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'classroom_id required']);
        exit;
    }

    $stmt = $conn->prepare('SELECT schedule_dirty FROM classrooms WHERE id = ?');
    $stmt->bind_param('i', $classroom_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['dirty' => false]);
        return;
    }

    $dirty = (bool) $row['schedule_dirty'];

    if ($dirty) {
        $upd = $conn->prepare('UPDATE classrooms SET schedule_dirty = 0 WHERE id = ?');
        $upd->bind_param('i', $classroom_id);
        $upd->execute();
        $upd->close();
    }

    echo json_encode(['dirty' => $dirty]);
}

/**
 * GET ?action=status&classroom_id=1
 *
 * Returns current row light states as 0/1 integers, e.g.:
 *   {"row1":1,"row2":0,"row3":1}
 *
 * Formerly: esp32-status.php
 */
function handle_status(mysqli $conn): void
{
    $cid  = (int)($_GET['classroom_id'] ?? 1);

    $stmt = $conn->prepare("
        SELECT row1_status, row2_status, row3_status
        FROM classrooms
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $stmt->bind_result($r1, $r2, $r3);
    $stmt->fetch();
    $stmt->close();

    echo json_encode([
        'row1' => $r1 === 'on' ? 1 : 0,
        'row2' => $r2 === 'on' ? 1 : 0,
        'row3' => $r3 === 'on' ? 1 : 0,
    ]);
}

/**
 * POST action=update_rows
 * Body: token, classroom_id, row1, row2, row3  (values: "on"|"off")
 *
 * Updates all three row states plus derives light_status.
 * Returns {"success":true}.
 *
 * Formerly: esp32-update-rows.php
 */
function handle_update_rows(mysqli $conn): void
{
    $cid   = (int)($_POST['classroom_id'] ?? 0);
    $row1  = $_POST['row1'] ?? 'off';
    $row2  = $_POST['row2'] ?? 'off';
    $row3  = $_POST['row3'] ?? 'off';
    $light = ($row1 === 'on' || $row2 === 'on' || $row3 === 'on') ? 'on' : 'off';

    if ($cid === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'classroom_id required']);
        exit;
    }

    $stmt = $conn->prepare("
        UPDATE classrooms
        SET row1_status = ?,
            row2_status = ?,
            row3_status = ?,
            light_status = ?
        WHERE id = ?
    ");
    $stmt->bind_param('ssssi', $row1, $row2, $row3, $light, $cid);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function bad_request(string $message): void
{
    http_response_code(400);
    echo json_encode(['error' => $message]);
    exit;
}