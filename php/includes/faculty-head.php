<?php
/**
 * faculty-head.php
 * Runs on every faculty page load.
 * Sets up: faculty info, active classroom, today's schedules, logs.
 */

$phpRoot = realpath(__DIR__ . '/../');
require_once $phpRoot . '/session_guard.php';
check_faculty();
require_once $phpRoot . '/db_connect.php';
date_default_timezone_set('Asia/Manila');

// ── Who is this faculty? ──────────────────────────────────────────────────────
$faculty_id   = (int)$_SESSION['faculty_id'];
$faculty_name = htmlspecialchars($_SESSION['faculty_name']);
$name_parts   = explode(' ', $faculty_name);
$first_name   = $name_parts[0];
$initials     = strtoupper(
    substr($name_parts[0], 0, 1) .
    substr(end($name_parts), 0, 1)
);

// Grab their email from DB
$faculty_email = '';
$stmt = $conn->prepare('SELECT email FROM faculty WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$stmt->bind_result($faculty_email);
$stmt->fetch();
$stmt->close();

// ── What time/day is it right now? ────────────────────────────────────────────
$today = date('l');   // e.g. "Tuesday"
$now   = date('H:i:s');

// ── Which classroom is active RIGHT NOW for this faculty? ─────────────────────
// "Find me a schedule where:
//   - it belongs to this faculty
//   - it's for today
//   - class has already started (start_time <= now)
//   - class hasn't ended yet — check extended_until first, fall back to end_time"
$classroom_id = 0;
$stmt = $conn->prepare("
    SELECT classroom_id
    FROM schedules
    WHERE faculty_id  = ?
      AND day_of_week = ?
      AND start_time  <= ?
      AND (
            (extended_until IS NOT NULL AND extended_until >= ?)
         OR (extended_until IS NULL     AND end_time       >= ?)
      )
    ORDER BY start_time
    LIMIT 1
");
$stmt->bind_param('issss', $faculty_id, $today, $now, $now, $now);
$stmt->execute();
$stmt->bind_result($classroom_id);
$stmt->fetch();
$stmt->close();

// No active class right now? Fall back to first scheduled room today.
// "At least give them something to look at."
if (!$classroom_id) {
    $stmt = $conn->prepare("
        SELECT classroom_id
        FROM schedules
        WHERE faculty_id  = ?
          AND day_of_week = ?
        ORDER BY start_time
        LIMIT 1
    ");
    $stmt->bind_param('is', $faculty_id, $today);
    $stmt->execute();
    $stmt->bind_result($classroom_id);
    $stmt->fetch();
    $stmt->close();
}

// ── Full weekly schedule (for the modal + JS room-switcher) ───────────────────
// "Give me ALL schedules for this faculty across the whole week,
//  with the room name attached. JS needs classroom_id too."
$schedules = [];
$stmt = $conn->prepare("
    SELECT s.classroom_id, s.start_time, s.end_time, s.day_of_week,
           c.room_name
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    WHERE s.faculty_id = ?
    ORDER BY
        FIELD(s.day_of_week, 'Monday','Tuesday','Wednesday',
              'Thursday','Friday','Saturday','Sunday'),
        s.start_time
");
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $schedules[] = $row;
$stmt->close();

// ── Recent lighting logs for this classroom ───────────────────────────────────
$logs = [];
if ($classroom_id) {
    $stmt = $conn->prepare("
        SELECT l.event_type, l.triggered_by, l.event_time,
               c.room_name, l.row_affected
        FROM lighting_logs l
        JOIN classrooms c ON c.id = l.classroom_id
        WHERE l.classroom_id = ?
        ORDER BY l.event_time DESC
        LIMIT 7
    ");
    $stmt->bind_param('i', $classroom_id);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $logs[] = $row;
    $stmt->close();
}

// ── Gesture logs for this faculty ────────────────────────────────────────────
$gesture_logs = [];
$stmt = $conn->prepare("
    SELECT l.event_type, l.triggered_by, l.event_time,
           c.room_name
    FROM lighting_logs l
    JOIN classrooms c ON c.id = l.classroom_id
    WHERE l.faculty_id   = ?
      AND l.triggered_by = 'gesture'
    ORDER BY l.event_time DESC
    LIMIT 20
");
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $gesture_logs[] = $row;
$stmt->close();

// ── Current or next schedule for the topbar ───────────────────────────────────
$current_sched = 'No scheduled classes';
$stmt = $conn->prepare("
    SELECT s.start_time, s.end_time, c.room_name
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    WHERE s.faculty_id = ?
      AND s.day_of_week = ?
      AND s.start_time <= ?
      AND (
            (s.extended_until IS NOT NULL AND s.extended_until >= ?)
         OR (s.extended_until IS NULL     AND s.end_time       >= ?)
      )
    ORDER BY s.start_time
    LIMIT 1
");
$stmt->bind_param('issss', $faculty_id, $today, $now, $now, $now);
$stmt->execute();
$stmt->bind_result($sched_start, $sched_end, $sched_room);
if ($stmt->fetch()) {
    $current_sched = date('g:i A', strtotime($sched_start)) . ' – ' . 
                     date('g:i A', strtotime($sched_end)) . 
                     ' (' . htmlspecialchars($sched_room) . ')';
}
$stmt->close();