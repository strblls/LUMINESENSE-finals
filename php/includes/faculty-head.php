<?php
$phpRoot = realpath(__DIR__ . '/../');
require_once $phpRoot . '/session_guard.php';
check_faculty();
require_once $phpRoot . '/db_connect.php';
date_default_timezone_set('Asia/Manila');

$faculty_name = htmlspecialchars($_SESSION['faculty_name']);
$faculty_id   = (int)$_SESSION['faculty_id'];
$name_parts   = explode(' ', $faculty_name);
$first_name   = $name_parts[0];
$initials     = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));

// Fetch email
$faculty_email = '';
$stmt = $conn->prepare('SELECT email FROM faculty WHERE id = ?');
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$stmt->bind_result($faculty_email);
$stmt->fetch();
$stmt->close();

$today = date('l');
$now   = date('H:i:s');

// ── Classroom assigned to this faculty today ──────────────────
// FIX: was using created_by (admin ID), now uses faculty_id
$classroom_id = 0;
$stmt = $conn->prepare("
    SELECT DISTINCT s.classroom_id
    FROM schedules s
    WHERE s.faculty_id = ?
      AND s.day_of_week = ?
    ORDER BY s.start_time
    LIMIT 1
");
$stmt->bind_param('is', $faculty_id, $today);
$stmt->execute();
$stmt->bind_result($classroom_id);
$stmt->fetch();
$stmt->close();

// No schedule today = no classroom access
if (!$classroom_id) {
    $classroom_id = 0;
}

// ── Today's schedules for THIS faculty (all days for modal) ───
// FIX 1: added day_of_week to SELECT
// FIX 2: removed day filter so modal shows full week
// FIX 3: added faculty_id filter
$schedules = [];
$fid = (int)$faculty_id;
$r = $conn->query("
    SELECT s.start_time, s.end_time, s.day_of_week, c.room_name
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    WHERE s.faculty_id = $fid
    ORDER BY FIELD(s.day_of_week,'Monday','Tuesday','Wednesday',
                   'Thursday','Friday','Saturday','Sunday'), s.start_time
");
while ($row = $r->fetch_assoc()) $schedules[] = $row;

// ── Current schedule label for topbar (with extension support) ─────────────
$current_sched = 'No class right now';
$active_schedule_end = '';
$active_schedule_room = '';
$stmt = $conn->prepare("
    SELECT s.start_time, COALESCE(s.extended_until, s.end_time) AS end_time, c.room_name, sub.name AS subject_name
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE s.faculty_id = ?
      AND s.day_of_week = ?
      AND s.start_time <= ?
      AND (s.extended_until >= ? OR (s.extended_until IS NULL AND s.end_time >= ?))
    ORDER BY s.start_time
    LIMIT 1
");
$stmt->bind_param('issss', $faculty_id, $today, $now, $now, $now);
$stmt->execute();
$active = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($active) {
    $active_schedule_end = $active['end_time'];
    $active_schedule_room = $active['room_name'] ?? '';
    $subj_label = ($active['subject_name'] ?? '') ?: 'Class';
    $current_sched = $active['room_name'] . ' · ' . $subj_label
        . ' (' . date('g:i A', strtotime($active['start_time']))
        . ' - ' . date('g:i A', strtotime($active['end_time'])) . ')';
}

// ── Has any schedule at all? ──────────────────────────────────────
$has_any_schedule = false;
$stmt = $conn->prepare("SELECT 1 FROM schedules WHERE faculty_id = ? LIMIT 1");
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$stmt->bind_result($dummy);
$has_any_schedule = (bool)$stmt->fetch();
$stmt->close();

// ── Faculty permissions ───────────────────────────────────────────
$permissions = ['lighting_control' => 1, 'gesture_control' => 1];
$stmt = $conn->prepare("SELECT lighting_control, gesture_control FROM faculty_permissions WHERE faculty_id = ?");
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$stmt->bind_result($pc_lc, $pc_gc);
if ($stmt->fetch()) {
    $permissions = ['lighting_control' => $pc_lc, 'gesture_control' => $pc_gc];
}
$stmt->close();

// ── Has PIN set? ──────────────────────────────────────────────
$has_pin = false;
$stmt = $conn->prepare("SELECT pin_hash FROM faculty_permissions WHERE faculty_id = ? AND pin_hash IS NOT NULL");
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$stmt->bind_result($pin_hash_val);
$has_pin = (bool)$stmt->fetch();
$stmt->close();

$logs = [];
$r = $conn->query("
    SELECT l.event_type, l.triggered_by, l.event_time, c.room_name
    FROM lighting_logs l
    JOIN classrooms c ON c.id = l.classroom_id
    WHERE l.classroom_id = $classroom_id
    ORDER BY l.event_time DESC
    LIMIT 7
");
while ($row = $r->fetch_assoc()) $logs[] = $row;

// ── Gesture logs for this faculty only ───────────────────────
$gesture_logs = [];
$stmt = $conn->prepare("
    SELECT l.event_type, l.triggered_by, l.event_time, c.room_name
    FROM lighting_logs l
    JOIN classrooms c ON c.id = l.classroom_id
    WHERE l.faculty_id = ?
      AND l.triggered_by = 'gesture'
    ORDER BY l.event_time DESC
    LIMIT 20
");
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $gesture_logs[] = $row;
$stmt->close();


/**
 * Returns icon/color data for a faculty activity log entry.
 * Mirrors the admin activity_icon() function for consistent styling.
 *
 * Keys: icon, color, bg, label, typeBg, typeClr, typeLabel, notes
 */
function faculty_activity_icon(array $log): array
{
    // Determine the "event type" key
    $evt = $log['event_type'] ?? '';

    // ── Icon / colour maps ────────────────────────────────────────
    $iconMap = [
        // Room / lighting events
        'on'             => ['bi-lightbulb-fill',     '#198754', '#d1e7dd'],
        'off'            => ['bi-lightbulb',           '#842029', '#f8d7da'],
        'light_on'       => ['bi-lightbulb-fill',     '#198754', '#d1e7dd'],
        'light_off'      => ['bi-lightbulb',           '#842029', '#f8d7da'],
        'motion_detect'  => ['bi-person-bounding-box', '#084298', '#cfe2ff'],
        'pir_motion'     => ['bi-person-bounding-box', '#084298', '#cfe2ff'],
        'pir_stopped'    => ['bi-person-bounding-box', '#5a5a5a', '#e9ecef'],
        'gesture'        => ['bi-hand-index',          '#084298', '#cfe2ff'],
        'schedule'       => ['bi-calendar-check',     '#198754', '#d1e7dd'],
        'security_alert' => ['bi-exclamation-triangle-fill', '#842029', '#f8d7da'],
        'class_start'    => ['bi-play-circle-fill',   '#198754', '#d1e7dd'],
        'class_end'      => ['bi-stop-circle',        '#664d03', '#fff3cd'],
        'door_open'      => ['bi-door-open-fill',     '#664d03', '#fff3cd'],
        'door_close'     => ['bi-door-closed-fill',   '#5a3a00', '#ffe5b4'],

        // Misc
        'issue_raised'   => ['bi-exclamation-triangle-fill', '#842029', '#f8d7da'],
        'issue_resolved' => ['bi-check-circle-fill',   '#198754', '#d1e7dd'],
    ];

    $default = ['bi-clock-history', '#5a5a5a', '#e9ecef'];

    [$icon, $iconColor, $iconBg] = $iconMap[$evt] ?? $default;

    // ── Type badge ────────────────────────────────────────────────
    $typeMap = [
        'room'        => ['#cfe2ff', '#084298', 'Room'],
    ];
    [$typeBg, $typeClr, $typeLabel] = $typeMap['room'];

    // ── Human-readable label ──────────────────────────────────────
    $label = ucwords(str_replace('_', ' ', $evt));

    // ── Notes (optional) ─────────────────────────────────────────
    $notes = $log['notes'] ?? '';

    return [
        'icon'      => $icon,
        'color'     => $iconColor,
        'bg'        => $iconBg,
        'label'     => $label,
        'typeBg'    => $typeBg,
        'typeClr'   => $typeClr,
        'typeLabel' => $typeLabel,
        'notes'     => $notes,
    ];
}
