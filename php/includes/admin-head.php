<?php
$phpRoot = realpath(__DIR__ . '/../');
require_once $phpRoot . '/session_guard.php';
check_admin();
require_once $phpRoot . '/db_connect.php';

$admin_name = htmlspecialchars($_SESSION['admin_name']);
$admin_id   = $_SESSION['admin_id'];
$name_parts = explode(' ', $admin_name);
$initials   = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));

$admin_email = '';
$stmt = $conn->prepare('SELECT email FROM admins WHERE id = ?');
$stmt->bind_param('i', $admin_id);
$stmt->execute();
$stmt->bind_result($admin_email);
$stmt->fetch();
$stmt->close();

// ── Fetch all classrooms for the dropdown ─────────────────────
$rooms = [];
$res = $conn->query("SELECT id, room_name FROM classrooms ORDER BY room_name ASC");
while ($row = $res->fetch_assoc()) {
    $rooms[] = $row;
}

// Summary counts
$total_rooms = $conn->query("SELECT COUNT(*) AS c FROM classrooms")->fetch_assoc()['c'];

// Lights on = classrooms whose LATEST log entry is 'on'
$lights_on = $conn->query("
    SELECT COUNT(*) AS c FROM lighting_logs l
    WHERE l.id IN (SELECT MAX(id) FROM lighting_logs GROUP BY classroom_id)
    AND l.event_type = 'on'
")->fetch_assoc()['c'];

// Pending faculty = email verified but not yet approved by admin
$pending = $conn->query("
    SELECT COUNT(*) AS c FROM faculty
    WHERE is_verified = 1 AND approved_by IS NULL
")->fetch_assoc()['c'];

// Extension requests — table may not exist yet, so we guard it
$ext_pending = 0;
if ($conn->query("SHOW TABLES LIKE 'extension_requests'")->num_rows > 0) {
    $ext_pending = $conn->query("SELECT COUNT(*) AS c FROM extension_requests WHERE status='pending'")->fetch_assoc()['c'];
}

// System status checks
$server_ok   = true; // we're running PHP so server is up
$db_ok       = ($conn && !$conn->connect_error);
$lights_data = $conn->query("SELECT COUNT(*) AS c FROM lighting_logs WHERE DATE(event_time)=CURDATE()")->fetch_assoc()['c'];

// Recent activity — merge lighting_logs + admin_logs into one sorted feed
$logs = [];

// ── 1. Lighting events ────────────────────────────────────────────────────
$r = $conn->query("
    SELECT 
        ll.event_type,
        ll.triggered_by,
        ll.event_time,
        c.room_name,
        'room' AS log_type,
        NULL AS admin_name
    FROM lighting_logs ll
    JOIN classrooms c ON c.id = ll.classroom_id
    ORDER BY ll.event_time DESC
    LIMIT 20
");
if ($r) while ($row = $r->fetch_assoc()) $logs[] = $row;

// ── 2. Admin actions (approve, reject, extension, etc.) ───────────────────
$r2 = $conn->query("
    SELECT
        al.action        AS event_type,
        al.notes         AS triggered_by,
        al.created_at    AS event_time,
        al.target_name   AS room_name,
        'admin'          AS log_type,
        CONCAT(a.first_name, ' ', a.last_name) AS admin_name
    FROM admin_logs al
    JOIN admins a ON a.id = al.admin_id
    ORDER BY al.created_at DESC
    LIMIT 20
");
if ($r2) while ($row = $r2->fetch_assoc()) $logs[] = $row;

// ── 3. Admin logins — only show if a DIFFERENT admin logged in ────────────
$r3 = $conn->prepare("
    SELECT
        'admin_login'    AS event_type,
        'Logged in'      AS triggered_by,
        login_at         AS event_time,
        'System'         AS room_name,
        'admin_login'    AS log_type,
        CONCAT(a.first_name, ' ', a.last_name) AS admin_name
    FROM admin_login_logs all2
    JOIN admins a ON a.id = all2.admin_id
    WHERE all2.admin_id != ?
    ORDER BY login_at DESC
    LIMIT 5
");
if ($r3) {
    $r3->bind_param('i', $admin_id);
    $r3->execute();
    $res3 = $r3->get_result();
    while ($row = $res3->fetch_assoc()) $logs[] = $row;
    $r3->close();
}

// Sort merged list newest-first
usort($logs, fn($a, $b) => strtotime($b['event_time']) - strtotime($a['event_time']));
$logs = array_slice($logs, 0, 10); // keep top 10

$approval_logs = [];

// Classrooms with their description and latest light status
$classrooms = [];
$r = $conn->query("
    SELECT c.id, c.room_name, c.room_size, c.description,
           COALESCE(l.event_type, 'off') AS light_status
    FROM classrooms c
    LEFT JOIN lighting_logs l
           ON l.id = (SELECT MAX(id) FROM lighting_logs WHERE classroom_id = c.id)
    ORDER BY c.room_name
");
while ($row = $r->fetch_assoc()) $classrooms[] = $row;

// Mini calendar — schedules by day of week
$schedules_by_day = [];
$r = $conn->query("
    SELECT s.day_of_week, s.start_time, s.end_time, s.extended_until,
           c.room_name, f.first_name, f.last_name
    FROM schedules s
    JOIN classrooms c ON s.classroom_id = c.id
    LEFT JOIN faculty f ON s.faculty_id = f.id
    ORDER BY s.day_of_week, s.start_time
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $schedules_by_day[$row['day_of_week']][] = $row;
    }
}
$schedules_json = json_encode($schedules_by_day);

/**
 * Return icon data array for a given log row compatible with both
 * admin-homepage.php (log_type, event_type) and admin-reports.php (action).
 *
 * Keys: icon, color, bg, label, typeBg, typeClr, typeLabel, notes
 */
function activity_icon(array $log): array
{
    // Determine the "event type" key — reports uses 'action', dashboard uses 'event_type'
    $evt = $log['event_type'] ?? $log['action'] ?? '';
    $type = $log['log_type'] ?? 'room';

    // ── Icon / colour maps ────────────────────────────────────────
    $iconMap = [
        // Room / lighting events
        'on'             => ['bi-lightbulb-fill',     '#198754', '#d1e7dd'],
        'off'            => ['bi-lightbulb',           '#842029', '#f8d7da'],
        'light_on'       => ['bi-lightbulb-fill',     '#198754', '#d1e7dd'],
        'light_off'      => ['bi-lightbulb',           '#842029', '#f8d7da'],
        'motion_detect'  => ['bi-person-bounding-box', '#084298', '#cfe2ff'],
        'gesture'        => ['bi-hand-index',          '#084298', '#cfe2ff'],
        'schedule'       => ['bi-calendar-check',     '#198754', '#d1e7dd'],
        'security_alert' => ['bi-exclamation-triangle-fill', '#842029', '#f8d7da'],
        'class_start'    => ['bi-play-circle-fill',   '#198754', '#d1e7dd'],
        'class_end'      => ['bi-stop-circle',        '#664d03', '#fff3cd'],
        'door_open'      => ['bi-door-open-fill',     '#664d03', '#fff3cd'],
        'door_close'     => ['bi-door-closed-fill',   '#5a3a00', '#ffe5b4'],

        // Admin approval actions
        'faculty_approved'   => ['bi-person-check-fill',  '#198754', '#d1e7dd'],
        'faculty_rejected'   => ['bi-person-x-fill',      '#842029', '#f8d7da'],
        'faculty_pending'    => ['bi-person-plus',        '#664d03', '#fff3cd'],
        'extension_approved' => ['bi-clock-history',      '#084298', '#cfe2ff'],
        'extension_rejected' => ['bi-clock-fill',         '#842029', '#f8d7da'],

        // Admin login
        'admin_login'    => ['bi-box-arrow-in-right',  '#055160', '#cff4fc'],

        // Misc
        'issue_raised'   => ['bi-exclamation-triangle-fill', '#842029', '#f8d7da'],
        'issue_resolved' => ['bi-check-circle-fill',   '#198754', '#d1e7dd'],
        'admin_action'   => ['bi-shield-check',        '#084298', '#cfe2ff'],
    ];

    $default = ['bi-clock-history', '#5a5a5a', '#e9ecef'];

    [$icon, $iconColor, $iconBg] = $iconMap[$evt] ?? $default;

    // ── Type badge ────────────────────────────────────────────────
    $typeMap = [
        'room'        => ['#cfe2ff', '#084298', 'Room'],
        'admin'       => ['#ede6f2', '#4a0078', 'Admin'],
        'admin_login' => ['#cff4fc', '#055160', 'Login'],
    ];
    [$typeBg, $typeClr, $typeLabel] = $typeMap[$type] ?? $typeMap['room'];

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
?>
