<?php
$page_title = 'Report Management';
require_once __DIR__ . "/../../src/Includes/admin-head.php";
require_once __DIR__ . "/../../src/Handlers/admin-handlers.php";

/* -----------------------
   FETCH: Activity Log  (room_logs + approval_logs merged)
   Expects tables:
     room_logs   (id, event_type, room_name, triggered_by, event_time, notes)
     admin_logs  (id, action, target_name, performed_by, created_at, notes)
   Adjust table/column names to match your actual schema.
----------------------- */

$activity_logs = [];

// Room event logs
$res = $conn->query("
    SELECT
        'room'        AS log_type,
        id,
        event_type    AS action,
        room_name     AS target,
        triggered_by  AS actor,
        event_time    AS log_time,
        COALESCE(notes,'') AS notes
    FROM room_logs
    ORDER BY event_time DESC
    LIMIT 200
");
if ($res) {
    while ($row = $res->fetch_assoc()) $activity_logs[] = $row;
    $res->free();
}

// Admin / approval logs (faculty + extension actions only)
$res2 = $conn->query("
    SELECT
        'admin'                                                      AS log_type,
        al.id,
        al.action                                                    AS action,
        al.target_name                                               AS target,
        COALESCE(CONCAT(a.first_name,' ',a.last_name), 'System')    AS actor,
        al.created_at                                                AS log_time,
        COALESCE(al.notes, '')                                       AS notes
    FROM admin_logs al
    LEFT JOIN admins a ON a.id = al.admin_id
    WHERE al.action IN (
        'faculty_approved', 'faculty_rejected', 'faculty_pending',
        'extension_approved', 'extension_rejected'
    )
    ORDER BY al.created_at DESC
    LIMIT 200
");
if ($res2) {
    while ($row = $res2->fetch_assoc()) $activity_logs[] = $row;
    $res2->free();
}

// PIR occupancy events
$res3 = $conn->query("
    SELECT
        'room'                                                      AS log_type,
        pl.id,
        CASE pl.state WHEN 1 THEN 'pir_motion' ELSE 'pir_stopped' END AS action,
        c.room_name                                                  AS target,
        'PIR'                                                        AS actor,
        pl.created_at                                                AS log_time,
        ''                                                           AS notes
    FROM pir_logs pl
    JOIN classrooms c ON c.id = pl.classroom_id
    ORDER BY pl.created_at DESC
    LIMIT 200
");
if ($res3) {
    while ($row = $res3->fetch_assoc()) $activity_logs[] = $row;
    $res3->free();
}

// Lighting schedule events (class_start / class_end)
$res4 = $conn->query("
    SELECT
        'room'                                                      AS log_type,
        cl.id,
        cl.event_type                                               AS action,
        c.room_name                                                 AS target,
        COALESCE(cl.triggered_by, 'schedule')                       AS actor,
        cl.event_time                                               AS log_time,
        COALESCE(cl.notes, '')                                      AS notes,
        ''                                                          AS faculty_name,
        ''                                                          AS subject_name,
        ''                                                          AS department_name
    FROM class_logs cl
    JOIN classrooms c ON c.id = cl.classroom_id
    ORDER BY cl.event_time DESC
    LIMIT 200
");
if ($res4) {
    while ($row = $res4->fetch_assoc()) $activity_logs[] = $row;
    $res4->free();
}

// Physical light state changes (on/off only) from lighting_logs
$resL = $conn->query("
    SELECT
        'room'                                                      AS log_type,
        l.id,
        CASE l.event_type WHEN 'on' THEN 'light_on' ELSE 'light_off' END AS action,
        c.room_name                                                 AS target,
        COALESCE(NULLIF(l.triggered_by, ''), 'Manual')             AS actor,
        l.event_time                                                AS log_time,
        ''                                                          AS notes
    FROM lighting_logs l
    JOIN classrooms c ON c.id = l.classroom_id
    WHERE l.event_type IN ('on', 'off')
    ORDER BY l.event_time DESC
    LIMIT 200
");
if ($resL) {
    while ($row = $resL->fetch_assoc()) $activity_logs[] = $row;
    $resL->free();
}

// Sort merged list newest-first
usort($activity_logs, fn($a, $b) => strtotime($b['log_time']) - strtotime($a['log_time']));

/* -----------------------
   FETCH: Room Activity Summary
----------------------- */
$rooms = [];
$res3 = $conn->query("
    SELECT
        c.id,
        c.room_name,
        c.room_size,
        c.description,
        COALESCE(
            (SELECT l.event_type FROM lighting_logs l
             WHERE l.classroom_id = c.id
             ORDER BY l.id DESC LIMIT 1),
            'off'
        ) AS light_status,
        (
            COALESCE((SELECT COUNT(*) FROM room_logs WHERE room_name = c.room_name), 0) +
            COALESCE((SELECT COUNT(*) FROM lighting_logs WHERE classroom_id = c.id), 0) +
            COALESCE((SELECT COUNT(*) FROM pir_logs WHERE classroom_id = c.id), 0) +
            COALESCE((SELECT COUNT(*) FROM class_logs WHERE classroom_id = c.id), 0)
        ) AS total_events,
        GREATEST(
            COALESCE((SELECT MAX(event_time) FROM room_logs WHERE room_name = c.room_name), '1970-01-01 00:00:00'),
            COALESCE((SELECT MAX(event_time) FROM lighting_logs WHERE classroom_id = c.id), '1970-01-01 00:00:00'),
            COALESCE((SELECT MAX(created_at) FROM pir_logs WHERE classroom_id = c.id), '1970-01-01 00:00:00'),
            COALESCE((SELECT MAX(event_time) FROM class_logs WHERE classroom_id = c.id), '1970-01-01 00:00:00')
        ) AS last_event
    FROM classrooms c
    ORDER BY c.room_name ASC
");
if ($res3) {
    while ($row = $res3->fetch_assoc()) {
        if ($row['last_event'] === '1970-01-01 00:00:00') {
            $row['last_event'] = null;
        }
        $rooms[] = $row;
    }
    $res3->free();
}

// - Issues Logged (from room_logs) --------------
$issues = [];
$res5 = $conn->query("
    SELECT
        id,
        event_type,
        room_name,
        triggered_by,
        event_time,
        COALESCE(notes, '') AS notes
    FROM room_logs
    WHERE event_type IN ('issue_raised', 'issue_resolved', 'tilt_alert')
    ORDER BY event_time DESC
    LIMIT 200
");
if ($res5) {
    while ($row = $res5->fetch_assoc()) $issues[] = $row;
    $res5->free();
}

// - Issue stats (tilt_alert counts as an issue raised) -
$issue_raised_count = 0;
$issue_resolved_count = 0;
foreach ($issues as $issue) {
    if ($issue['event_type'] === 'issue_raised' || $issue['event_type'] === 'tilt_alert') $issue_raised_count++;
    elseif ($issue['event_type'] === 'issue_resolved') $issue_resolved_count++;
}

/* -----------------------
   Status KPIs (from merged $activity_logs + $issues)
----------------------- */
$issue_actions = ['issue_raised', 'issue_resolved', 'tilt_alert'];
$room_actions_count = 0;
$admin_actions_count = 0;
foreach ($activity_logs as $log) {
    if (($log['log_type'] ?? '') === 'admin') $admin_actions_count++;
    else $room_actions_count++;
}
$anomalies_count = count($issues);

/* -----------------------
   Status KPI breakdowns (initial totals; JS recomputes live from filters)
----------------------- */
$lights_on_total = 0;
$lights_off_total = 0;
$q = $conn->query("SELECT event_type, COUNT(*) AS c FROM lighting_logs WHERE event_type IN ('on','off') GROUP BY event_type");
if ($q) {
    while ($r = $q->fetch_assoc()) {
        if ($r['event_type'] === 'on') $lights_on_total = (int)$r['c'];
        else $lights_off_total = (int)$r['c'];
    }
    $q->free();
}

$spike_count = 0;
$motion_count = 0;
$power_resolved_count = 0;
$q = $conn->query("SELECT event_type, triggered_by, COUNT(*) AS c FROM room_logs WHERE event_type IN ('issue_raised','issue_resolved','tilt_alert') GROUP BY event_type, triggered_by");
if ($q) {
    while ($r = $q->fetch_assoc()) {
        $actor = strtolower($r['triggered_by'] ?? '');
        if ($r['event_type'] === 'issue_raised' && $actor === 'pir') $motion_count += (int)$r['c'];
    }
    $q->free();
}
$q = $conn->query("SELECT COUNT(*) AS c FROM room_logs WHERE event_type = 'issue_raised' AND triggered_by = 'PZEM' AND notes LIKE '%spike%'");
if ($q && ($r = $q->fetch_assoc())) $spike_count = (int)$r['c'];
if ($q) $q->free();
$q = $conn->query("SELECT COUNT(*) AS c FROM room_logs WHERE event_type = 'issue_resolved' AND triggered_by = 'PZEM'");
if ($q && ($r = $q->fetch_assoc())) $power_resolved_count = (int)$r['c'];
if ($q) $q->free();
$other_anomalies_count = max(0, $anomalies_count - $spike_count - $motion_count - $power_resolved_count);

$admin_counts = ['faculty_approved' => 0, 'faculty_rejected' => 0, 'faculty_pending' => 0, 'extension_approved' => 0, 'extension_rejected' => 0];
$q = $conn->query("SELECT action, COUNT(*) AS c FROM admin_logs WHERE action IN ('faculty_approved','faculty_rejected','faculty_pending','extension_approved','extension_rejected') GROUP BY action");
if ($q) {
    while ($r = $q->fetch_assoc()) {
        if (isset($admin_counts[$r['action']])) $admin_counts[$r['action']] = (int)$r['c'];
    }
    $q->free();
}
$ext_decisions_count = $admin_counts['extension_approved'] + $admin_counts['extension_rejected'];
$pending_reviews_count = $admin_counts['faculty_pending'] + ($ext_pending_total ?? 0);

/* Distinct-day span of the merged log (frequency denominator) */
$status_day_set = [];
foreach ($activity_logs as $log) {
    if (!empty($log['log_time'])) $status_day_set[date('Y-m-d', strtotime($log['log_time']))] = true;
}
$status_span_days = max(1, count($status_day_set));
$lights_freq = round(($lights_on_total + $lights_off_total) / $status_span_days, 1);
$anom_freq = round($anomalies_count / $status_span_days, 1);

/* -----------------------
   Status chart datasets (Chart.js)
----------------------- */
$room_durations = []; // room_name => ['on' => secs_on, 'off' => secs_off]
$room_toggles = [];   // room_name => ['on' => n, 'off' => n]
$q = $conn->query("
    SELECT l.classroom_id, c.room_name, l.event_type, UNIX_TIMESTAMP(l.event_time) AS ts
    FROM lighting_logs l
    JOIN classrooms c ON c.id = l.classroom_id
    WHERE l.event_type IN ('on', 'off')
    ORDER BY l.classroom_id ASC, l.event_time ASC
    LIMIT 2000
");
if ($q) {
    $pendingOn = [];
    $firstTs = [];
    $lastTs = [];
    while ($r = $q->fetch_assoc()) {
        $rn = $r['room_name'];
        if (!isset($room_durations[$rn])) $room_durations[$rn] = ['on' => 0, 'span' => 0];
        if (!isset($room_toggles[$rn])) $room_toggles[$rn] = ['on' => 0, 'off' => 0];
        if (!isset($firstTs[$rn])) $firstTs[$rn] = (int)$r['ts'];
        $lastTs[$rn] = (int)$r['ts'];
        if ($r['event_type'] === 'on') {
            $room_toggles[$rn]['on']++;
            if (!isset($pendingOn[$rn])) $pendingOn[$rn] = (int)$r['ts'];
        } else {
            $room_toggles[$rn]['off']++;
            if (isset($pendingOn[$rn])) {
                $room_durations[$rn]['on'] += max(0, (int)$r['ts'] - $pendingOn[$rn]);
                unset($pendingOn[$rn]);
            }
        }
    }
    $q->free();
    foreach ($room_durations as $rn => $d) {
        $span = max(0, ($lastTs[$rn] ?? 0) - ($firstTs[$rn] ?? 0));
        $room_durations[$rn]['off'] = max(0, $span - $d['on']);
    }
}
uasort($room_durations, fn($a, $b) => $b['on'] <=> $a['on']);
$dur_rooms = array_slice(array_keys($room_durations), 0, 6);
$chart_durations = [
    'rooms' => array_values($dur_rooms),
    'on' => array_values(array_map(fn($rn) => round($room_durations[$rn]['on'] / 3600, 2), $dur_rooms)),
    'off' => array_values(array_map(fn($rn) => round($room_durations[$rn]['off'] / 3600, 2), $dur_rooms)),
];

/* Entries by action type (disjoint partition of the merged log) */
$type_counts = ['Admin' => 0, 'Lights' => 0, 'PIR Motion' => 0, 'Class' => 0, 'Anomalies' => 0, 'Other Room' => 0];
foreach ($activity_logs as $log) {
    $a = $log['action'] ?? '';
    if (($log['log_type'] ?? '') === 'admin') $type_counts['Admin']++;
    elseif ($a === 'light_on' || $a === 'light_off') $type_counts['Lights']++;
    elseif (in_array($a, $issue_actions, true)) $type_counts['Anomalies']++;
    elseif (str_starts_with($a, 'pir_')) $type_counts['PIR Motion']++;
    elseif (str_starts_with($a, 'class_')) $type_counts['Class']++;
    else $type_counts['Other Room']++;
}

/* Entries per room (top rooms + Other for non-room targets) */
$room_names = [];
foreach ($rooms as $rm) $room_names[$rm['room_name']] = true;
$per_room = [];
$other_targets = 0;
foreach ($activity_logs as $log) {
    $t = $log['target'] ?? '';
    if ($t !== '' && isset($room_names[$t])) $per_room[$t] = ($per_room[$t] ?? 0) + 1;
    else $other_targets++;
}
arsort($per_room);
$top_rooms = array_slice($per_room, 0, 7, true);
$chart_per_room = ['labels' => array_keys($top_rooms), 'data' => array_values($top_rooms)];
$remainder = array_sum(array_slice($per_room, 7, null, true)) + $other_targets;
if ($remainder > 0) {
    $chart_per_room['labels'][] = 'Other';
    $chart_per_room['data'][] = $remainder;
}

/* Toggles per room (top 8 by total) */
$toggle_totals = [];
foreach ($room_toggles as $rn => $t) $toggle_totals[$rn] = $t['on'] + $t['off'];
arsort($toggle_totals);
$tog_rooms = array_slice(array_keys($toggle_totals), 0, 8);
$chart_toggles = [
    'rooms' => array_values($tog_rooms),
    'on' => array_values(array_map(fn($rn) => $room_toggles[$rn]['on'], $tog_rooms)),
    'off' => array_values(array_map(fn($rn) => $room_toggles[$rn]['off'], $tog_rooms)),
];

$status_charts = [
    'durations' => $chart_durations,
    'types' => ['labels' => array_keys($type_counts), 'data' => array_values($type_counts)],
    'perRoom' => $chart_per_room,
    'toggles' => $chart_toggles,
];

/* Normalise an actor handle for display:
     "admin_override"      -> "Admin Override"
     "faculty_end_early"   -> "Faculty End Early"
     "gesture"             -> "Gesture"
   Names that already carry proper capitalisation are left untouched
   ("Alexandra Ballesteros", "LumineSense Admin", "PIR"). */
function actor_label(string $name): string
{
    $name = trim($name);
    if ($name === '') return '';

    $acronyms = ['pir' => 'PIR', 'pzem' => 'PZEM', 'esp32' => 'ESP32', 'esp' => 'ESP', 'id' => 'ID'];

    $needsFormat = strpos($name, '_') !== false || $name === strtolower($name);
    if (!$needsFormat) return $name;

    $words = preg_split('/[_\s]+/', $name);
    $out = [];
    foreach ($words as $w) {
        if ($w === '') continue;
        $lower = strtolower($w);
        $out[] = $acronyms[$lower] ?? ucfirst($lower);
    }
    return implode(' ', $out);
}

/* Distinct actors for the Status Actor filter (lowercase keys, display labels) */
$status_actors = [];
foreach ($activity_logs as $log) {
    $actorName = trim($log['actor'] ?? '');
    if ($actorName === '') continue;
    $key = strtolower($actorName);
    if (!isset($status_actors[$key])) $status_actors[$key] = actor_label($actorName);
}
ksort($status_actors);

/* Source label helper for the unified status table */
function log_source(array $log): string
{
    $actor = strtolower($log['actor'] ?? '');
    if (($log['log_type'] ?? '') === 'admin') return 'Admin';
    if ($actor === 'pir') return 'PIR';
    if ($actor === 'schedule') return 'Schedule';
    return 'Manual';
}

/* Display label for anomaly rows.
   - Issue Raised  -> Power Spike (PZEM power events) or Motion Outside Schedule (PIR)
   - Issue Resolved -> Power Normal, but ONLY when the PZEM sensor confirmed power is back */
function issue_label(array $issue): string
{
    $event = $issue['event_type'] ?? '';
    $actor = strtolower(trim($issue['actor'] ?? $issue['triggered_by'] ?? ''));
    if ($event === 'tilt_alert') return 'Tilt Alert';
    if ($event === 'issue_resolved') {
        return ($actor === 'pzem') ? 'Power Normal' : 'Issue Resolved';
    }
    if ($actor === 'pir') return 'Motion Outside Schedule';
    if ($actor === 'pzem') return 'Power Spike';
    return 'Issue Raised';
}

/* Faculty landing counts and Status Reports pending reviews */
$faculty_counts = $conn->query("
    SELECT COUNT(*) AS total, SUM(is_verified = 1 AND approved_by IS NULL) AS pending
    FROM faculty
")->fetch_assoc();
$faculty_total = (int)$faculty_counts['total'];
$faculty_pending = (int)$faculty_counts['pending'];
$faculty_schedules_total = (int)$conn->query("SELECT COUNT(*) AS total FROM schedules")->fetch_assoc()['total'];
$ext_pending_total = (int)$conn->query("SELECT COUNT(*) AS total FROM extension_requests WHERE status = 'pending'")->fetch_assoc()['total'];
$pending_reviews_count = $admin_counts['faculty_pending'] + $ext_pending_total;

$conn->close();

/* -- Icon map for event types -- */
function event_icon(string $type): array
{
    $map = [
        'light_on'       => ['bi-lightbulb-fill',      '#0f5132', '#d1e7dd'],
        'light_off'      => ['bi-lightbulb',            '#842029', '#f8d7da'],
        'motion_detect'  => ['bi-person-bounding-box',  '#084298', '#cfe2ff'],
        'pir_motion'     => ['bi-person-bounding-box',  '#084298', '#cfe2ff'],
        'pir_stopped'    => ['bi-person-bounding-box',  '#5a5a5a', '#e9ecef'],
        'door_open'      => ['bi-door-open-fill',       '#664d03', '#fff3cd'],
        'door_close'     => ['bi-door-closed-fill',     '#5a3a00', '#ffe5b4'],
        'class_start'    => ['bi-play-circle-fill',     '#0d6e3b', '#d1e7dd'],
        'class_end'      => ['bi-stop-circle',          '#6c4c00', '#fff3cd'],
        'faculty_approved' => ['bi-person-check-fill',  '#0f5132', '#d1e7dd'],
        'faculty_pending'  => ['bi-person-plus',        '#664d03', '#fff3cd'],
        'issue_raised'   => ['bi-exclamation-triangle-fill', '#842029', '#f8d7da'],
        'issue_resolved' => ['bi-check-circle-fill',   '#0f5132', '#d1e7dd'],
        'tilt_alert'     => ['bi-exclamation-octagon-fill', '#7f1d1d', '#fee2e2'],
        'admin_action'   => ['bi-shield-check',        '#084298', '#cfe2ff'],
        'archive_created'        => ['bi-archive', '#0891b2', '#cffafe'],
        'archive_deleted'        => ['bi-archive-fill', '#dc2626', '#fee2e2'],
        'reactivated'            => ['bi-arrow-clockwise', '#16a34a', '#dcfce7'],
        'system_flush'           => ['bi-trash', '#dc2626', '#fee2e2'],
        'extension_flush'        => ['bi-arrow-repeat', '#f59e0b', '#fef3c7'],
        'pzem_sync'              => ['bi-cpu', '#2563eb', '#dbeafe'],
        'pzem_archive'           => ['bi-cpu-fill', '#0891b2', '#cffafe'],
        'flush_schedule_updated' => ['bi-calendar-check', '#7c3aed', '#ede9fe'],
        'extension_event_paused' => ['bi-pause-circle', '#f59e0b', '#fef3c7'],
        'extension_event_resumed'=> ['bi-play-circle', '#16a34a', '#dcfce7'],
    ];
    $key = strtolower(str_replace(' ', '_', $type));
    return $map[$key] ?? ['bi-clock-history', '#5a5a5a', '#e9ecef'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports - LumineSense Admin</title>

    <!--External links-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!--Relative links-->
    <link rel="icon" type="image/png" sizes="32x32" href="../../images/icon.png">
    <link rel="shortcut icon" type="image/png" href="../../images/icon.png">
    <link rel="stylesheet" href="../../css/base/global.css">
    <link rel="stylesheet" href="../../css/base/containers.css">
    <link rel="stylesheet" href="../../css/base/modals.css">
    <link rel="stylesheet" href="../../css/faculty/timetable.css">
    <link rel="stylesheet" href="../../css/admin/home-reports.css?v=20261027rev20">
    <link rel="stylesheet" href="../../css/admin/common.css">
    <link rel="preload" as="image" href="../../images/admin/reports/faculty-reports-on.png">
    <link rel="preload" as="image" href="../../images/admin/reports/status-reports-on.png">
</head>

<body class="contrast-bg">
    <?php include __DIR__ . "/../../src/Includes/admin-topbar.php"; ?>
    <?php include __DIR__ . "/../../src/Includes/admin-sidebar.php"; ?>
    <?php include __DIR__ . "/../../src/Includes/profile-offcanvas.php"; ?>

    <!-- â•â•â• MAIN CONTENT â•â•â• -->
    <div class="child-container">
        <div class="reports-layout">

            <div id="reportToolbar" class="main-container faculty-timetable-heading d-flex align-items-center w-auto" style="background-color: var(--secondary-color-2);" hidden>
                <div class="d-flex align-items-center flex-grow-1" style="position:relative;">
                    <button type="button" id="reportBackBtn" class="timetable-btn ms-2" onclick="showReportLanding()" title="Back" style="display:none;">
                        <i class="bi bi-arrow-left"></i>
                        <span class="timetable-btn-title bold">Back</span>
                    </button>
                    <button type="button" class="timetable-btn ms-2" data-panel="panelGuideInfo" title="Guide">
                        <i class="bi bi-info-lg"></i>
                        <span class="timetable-btn-title bold">Guide</span>
                    </button>
                    <div id="panelGuideInfo" class="timetable-panel p-3 m-3">
                        <div class="section-container timetable" style="background-color:#f8f9fa;width:320px;">
                            <h6 class="bold mb-2"><i class="bi bi-info-circle me-1"></i>Reports Guide</h6>
                            <ol class="ps-3 mb-0" style="font-size:13px;line-height:1.7;">
                                <li>Press <strong>Faculty Reports</strong> or <strong>Status Reports</strong> to open a panel.</li>
                                <li>In <strong>Status Reports</strong>, read the KPI cards, then use the type and date filters above the table to narrow entries.</li>
                                <li>Use the search bar to find entries by room, actor, or action keyword.</li>
                                <li>In <strong>Faculty Reports</strong>, choose a date range and room, then select a faculty member to see the events behind their totals.</li>
                                <li>Use Prev / Next to page through the tables.</li>
                                <li>Click <strong>Export CSV</strong> to download the filtered comparison or status table. Status Reports also supports PDF export.</li>
                            </ol>
                        </div>
                    </div>
                    <input type="text" id="reportsSearch" class="form-control" aria-label="Search reports" placeholder="Search action, type, actor, source, room, date, notes..." style="max-width:500px;margin-left:16px;">
                </div>
                <div class="d-flex align-items-center pe-2" style="position:relative; gap:6px;">
                    <button type="button" class="timetable-btn" id="reportExportCsv" onclick="exportCSV()" title="Export CSV">
                        <i class="bi bi-filetype-csv"></i>
                        <span class="timetable-btn-title bold">Export<br>CSV</span>
                    </button>
                    <button type="button" class="timetable-btn" id="reportExportPdf" onclick="exportPDF()" title="Export PDF">
                        <i class="bi bi-filetype-pdf"></i>
                        <span class="timetable-btn-title bold">Export<br>PDF</span>
                    </button>
                </div>
            </div>

            <!-- ══ LANDING: two main panels ══ -->
            <div id="reportLanding" class="report-landing">
                <button type="button" class="report-landing-card" data-landing="faculty" onclick="showReportPanel('faculty')" aria-label="Open Faculty Reports">
                    <span class="landing-bg landing-faculty" aria-hidden="true"></span>
                    <span class="landing-caption">
                        <span class="landing-stats">
                            <span class="mini-stat-pill"><i class="bi bi-people-fill"></i><b><?= (int)$faculty_total ?></b><small>Total Faculty</small></span>
                            <span class="mini-stat-pill"><i class="bi bi-person-plus"></i><b><?= (int)$faculty_pending ?></b><small>Pending Approvals</small></span>
                            <span class="mini-stat-pill"><i class="bi bi-calendar-check"></i><b><?= (int)$faculty_schedules_total ?></b><small>Total Schedules</small></span>
                        </span>
                        <span class="landing-title">Faculty Reports</span>
                        <span class="landing-sub">Overall activities per faculty</span>
                    </span>
                    <span class="press-hint">Press to view <i class="bi bi-play-fill"></i></span>
                </button>
                <button type="button" class="report-landing-card" data-landing="status" onclick="showReportPanel('status')" aria-label="Open Status Reports">
                    <span class="landing-bg landing-status" aria-hidden="true"></span>
                    <span class="landing-caption">
                        <span class="landing-stats">
                            <span class="mini-stat-pill"><i class="bi bi-journal-text"></i><b><?= count($activity_logs) ?></b><small>Total Log Entries</small></span>
                            <span class="mini-stat-pill"><i class="bi bi-door-open"></i><b><?= count($rooms) ?></b><small>Tracked Rooms</small></span>
                            <span class="mini-stat-pill"><i class="bi bi-exclamation-triangle-fill"></i><b><?= (int)$issue_raised_count ?></b><small>Issues Raised</small></span>
                        </span>
                        <span class="landing-title">Status Reports</span>
                        <span class="landing-sub">Summary of all activities</span>
                    </span>
                    <span class="press-hint">Press to view <i class="bi bi-play-fill"></i></span>
                </button>
            </div>

            <!-- Faculty Reports -->
            <div id="panel-faculty" class="report-view" hidden>
                <div class="status-board">
                    <div class="status-kpis">
                        <span class="mini-stat-pill"><i class="bi bi-people-fill"></i><b id="facultyRosterCount">—</b><small>Faculty in Roster</small></span>
                        <span class="mini-stat-pill"><i class="bi bi-hand-index-thumb"></i><b id="facultyGestureCount">—</b><small>Gestures</small></span>
                        <span class="mini-stat-pill"><i class="bi bi-toggle-on"></i><b id="facultyToggleCount">—</b><small>UI Toggles</small></span>
                    </div>
                    <div class="status-main">
                        <div class="status-graphs faculty-graphs" id="facultyReportGraphs" hidden>
                            <?php foreach ([
                                'facultyGestures' => 'Most-Used Gestures',
                                'facultyControls' => 'Gesture vs UI Activity',
                                'facultyExtensions' => 'Classes with Extension Requests',
                            ] as $graph => $label): ?>
                                <div class="status-graph-card">
                                    <div class="graph-card-header">
                                        <span class="status-graph-label"><?= $label ?></span>
                                        <button type="button" class="graph-expand-btn" data-graph="<?= $graph ?>" title="Maximize <?= $label ?>" aria-label="Maximize <?= $label ?>">
                                            <i class="bi bi-arrows-expand" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    <div class="graph-canvas-wrap">
                                        <canvas id="<?= $graph ?>Chart" role="img" aria-label="<?= $label ?>"></canvas>
                                        <span class="faculty-graph-empty text-muted" hidden>No recorded activity for this selection.</span>
                                    </div>
                                    <div class="graph-legend-footer" data-legend="<?= $graph ?>"></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="reports-card">
                            <div class="reports-card-header">
                                <h2 class="bold"><i class="bi bi-people-fill"></i>Faculty Reports</h2>
                                <form class="filter-bar" id="facultyReportFilters">
                                    <select id="facultyReportRoom" aria-label="Filter by room">
                                        <option value="">All Rooms</option>
                                    </select>
                                    <span class="date-range-wrap">
                                        <input type="date" id="facultyReportStart" aria-label="From date" required>
                                        <span class="small">to</span>
                                        <input type="date" id="facultyReportEnd" aria-label="To date" required>
                                    </span>
                                    <button type="submit">Apply</button>
                                    <button type="reset" class="light" title="Reset to the last 30 days and all rooms">Reset</button>
                                </form>
                            </div>
                            <p id="facultyReportStatus" class="faculty-report-message" role="status"></p>
                            <div id="facultyComparison" hidden>
                                <?php $facultyMetrics = [
                                    'name' => ['Faculty', 'Current faculty member. Select a name to open their activity details.'],
                                    'active_days' => ['Active Days', 'Distinct dates with a recorded sign-in or explicit sign-out.'],
                                    'sign_ins' => ['Sign-ins', 'Number of recorded sign-ins.'],
                                    'sign_outs' => ['Explicit Sign-outs', 'Number of recorded sign-outs initiated by the faculty member.'],
                                    'gesture_count' => ['Gestures', 'Number of recorded confirmed gesture events, including older gesture stacks.'],
                                    'top_gesture' => ['Top Gesture', 'Most frequently recorded named gesture.'],
                                    'ui_toggles' => ['UI Toggles', 'Number of lights-on or lights-off actions recorded from the faculty controls.'],
                                    'ui_share' => ['UI Share (UI vs. Gestures)', 'UI toggles as a percentage of UI toggles plus recorded gesture events.'],
                                    'early_endings' => ['Early Ends', 'Number of recorded early class endings.'],
                                    'early_rate' => ['Early End Rate', 'Share of matched recorded class endings that ended early.'],
                                    'median_minutes_early' => ['Median Minutes Early', 'Median minutes between matched early endings and their scheduled end times.'],
                                    'extension_requests' => ['Extension Requests', 'Number of recorded requests to extend a class.'],
                                    'extension_rate' => ['Share of Classes with Requests', 'Share of scheduled class occurrences with at least one extension request.'],
                                ]; ?>
                                <div class="table-responsive">
                                    <table class="room-table faculty-report-table" id="facultyTable">
                                        <thead>
                                            <tr>
                                                <?php foreach ($facultyMetrics as $metric => [$label, $description]): ?>
                                                    <th scope="col"<?= $metric === 'gesture_count' ? ' aria-sort="descending"' : '' ?>>
                                                        <button type="button" class="faculty-sort-button" data-metric="<?= $metric ?>" data-description="<?= htmlspecialchars($description) ?>">
                                                            <?= $label ?> <i class="bi <?= $metric === 'gesture_count' ? 'bi-sort-down' : 'bi-arrow-down-up' ?>" aria-hidden="true"></i>
                                                        </button>
                                                    </th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                                <div class="activity-pagination">
                                    <button type="button" id="facultyReportPrev" disabled>&laquo; Prev</button>
                                    <span id="facultyReportPage">Page 1 of 1</span>
                                    <button type="button" id="facultyReportNext" disabled>Next &raquo;</button>
                                </div>
                                <p class="faculty-report-message text-muted">A dash indicates an unavailable value or a rate with no applicable events.</p>
                                <div class="legend-guide" id="facultyReportLegendGuide">
                                    <button type="button" class="legend-guide-toggle" data-bs-toggle="collapse" data-bs-target="#facultyReportLegendList" aria-expanded="false" aria-controls="facultyReportLegendList">
                                        <i class="bi bi-book" aria-hidden="true"></i> <span>Legend Guide</span> <i class="bi bi-chevron-down ms-auto" aria-hidden="true"></i>
                                    </button>
                                    <div class="collapse" id="facultyReportLegendList">
                                        <ul class="legend-guide-list">
                                            <?php foreach ($facultyMetrics as [$label, $description]): ?>
                                                <li>
                                                    <span class="legend-guide-dot" aria-hidden="true"></span>
                                                    <span class="legend-guide-label"><?= htmlspecialchars($label) ?></span>
                                                    <span class="legend-guide-desc"><?= htmlspecialchars($description) ?></span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <ul class="legend-guide-list" id="facultyReportNotices" hidden></ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <section class="reports-card" id="facultyPirReport" hidden>
                            <div class="reports-card-header">
                                <h2 class="bold"><i class="bi bi-person-bounding-box"></i>PIR Detections</h2>
                            </div>
                            <p class="faculty-report-message text-muted">Room activity grouped by hour. Counts reflect recorded PIR detections.</p>
                            <p id="facultyPirEmpty" class="faculty-report-message" hidden>No PIR detections for this period and room selection.</p>
                            <div class="table-responsive">
                                <table class="room-table" id="facultyPirTable" hidden>
                                    <thead><tr><th scope="col">Room</th><th scope="col">Hour</th><th scope="col">Scheduled</th><th scope="col">Unscheduled</th></tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </section>
                    </div>
                </div>

                    </div>
            <!-- ══ PANEL: Status Reports (existing 3 tabs) ══ -->
            <div id="panel-status" class="report-view" hidden>
                <div class="status-board">
                    <div class="status-kpis">
                        <button type="button" class="mini-stat-pill kpi-expandable" data-kpi="room" aria-expanded="false" title="Show breakdown">
                            <span class="kpi-top"><i class="bi bi-door-open"></i><b id="kpiRoomActions"><?= (int)$room_actions_count ?></b><small>Total Room Actions</small></span>
                            <ul class="kpi-breakdown">
                                <li><span>Lights On Entries</span><b id="kpiLightsOn"><?= (int)$lights_on_total ?></b></li>
                                <li><span>Lights Off Entries</span><b id="kpiLightsOff"><?= (int)$lights_off_total ?></b></li>
                                <li><span>Frequency On/Off</span><b id="kpiLightsFreq"><?= $lights_freq ?>/day</b></li>
                            </ul>
                        </button>
                        <button type="button" class="mini-stat-pill kpi-expandable" data-kpi="anomaly" aria-expanded="false" title="Show breakdown">
                            <span class="kpi-top"><i class="bi bi-exclamation-triangle-fill"></i><b id="kpiAnomalies"><?= (int)$anomalies_count ?></b><small>Total Anomalies</small></span>
                            <ul class="kpi-breakdown">
                                <li><span>Power Spiking Logs</span><b id="kpiSpike"><?= (int)$spike_count ?></b></li>
                                <li><span>Out-Class Motion Logs</span><b id="kpiMotion"><?= (int)$motion_count ?></b></li>
                                <li><span>Power Resolution</span><b id="kpiPowerResolved"><?= (int)$power_resolved_count ?></b></li>
                                <li><span>Other</span><b id="kpiOther"><?= (int)$other_anomalies_count ?></b></li>
                                <li><span>Frequency of Anomalies</span><b id="kpiAnomFreq"><?= $anom_freq ?>/day</b></li>
                            </ul>
                        </button>
                        <button type="button" class="mini-stat-pill kpi-expandable" data-kpi="admin" aria-expanded="false" title="Show breakdown">
                            <span class="kpi-top"><i class="bi bi-shield-check"></i><b id="kpiAdminActions"><?= (int)$admin_actions_count ?></b><small>Total Admin Actions</small></span>
                            <ul class="kpi-breakdown">
                                <li><span>Faculty Approvals</span><b id="kpiFacApproved"><?= (int)$admin_counts['faculty_approved'] ?></b></li>
                                <li><span>Faculty Rejections</span><b id="kpiFacRejected"><?= (int)$admin_counts['faculty_rejected'] ?></b></li>
                                <li><span>Extension Decisions</span><b id="kpiExtDecisions"><?= (int)$ext_decisions_count ?></b></li>
                                <li><span>Pending Reviews</span><b id="kpiPending" data-ext-pending="<?= (int)$ext_pending_total ?>"><?= (int)$pending_reviews_count ?></b></li>
                            </ul>
                        </button>
                    </div>
                    <div class="status-main">
                        <script id="statusChartData" type="application/json"><?= json_encode($status_charts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
                        <div class="status-graphs">
                            <div class="status-graph-card"><div class="graph-card-header"><span class="status-graph-label">On vs Off Hours by Room</span><button type="button" class="graph-expand-btn" data-graph="durations" title="Maximize"><i class="bi bi-arrows-expand"></i></button></div><div class="graph-canvas-wrap"><canvas id="statusChart1" aria-label="Clustered bar chart of lights on versus off hours by room"></canvas></div><div class="graph-legend-footer" data-legend="durations"></div></div>
                            <div class="status-graph-card"><div class="graph-card-header"><span class="status-graph-label">Entries by Action Type</span><button type="button" class="graph-expand-btn" data-graph="types" title="Maximize"><i class="bi bi-arrows-expand"></i></button></div><div class="graph-canvas-wrap"><canvas id="statusChart2" aria-label="Pie chart of log entries by action type"></canvas></div><div class="graph-legend-footer" data-legend="types"></div></div>
                            <div class="status-graph-card"><div class="graph-card-header"><span class="status-graph-label">Entries per Room</span><button type="button" class="graph-expand-btn" data-graph="perRoom" title="Maximize"><i class="bi bi-arrows-expand"></i></button></div><div class="graph-canvas-wrap"><canvas id="statusChart3" aria-label="Donut chart of log entries per room"></canvas></div><div class="graph-legend-footer" data-legend="perRoom"></div></div>
                            <div class="status-graph-card"><div class="graph-card-header"><span class="status-graph-label">Toggles per Room</span><button type="button" class="graph-expand-btn" data-graph="toggles" title="Maximize"><i class="bi bi-arrows-expand"></i></button></div><div class="graph-canvas-wrap"><canvas id="statusChart4" aria-label="Horizontal bar chart of lights on and off toggles per room"></canvas></div><div class="graph-legend-footer" data-legend="toggles"></div></div>
                        </div>
                        <div class="reports-card">
                            <div class="reports-card-header">
                                <h2 class="bold"><i class="bi bi-activity"></i>Status Reports</h2>
                                <div class="filter-bar">
                                    <span class="filter-select-wrap">
                                        <select id="statusType">
                                            <option value="">All Types</option>
                                            <option value="room">Room Events</option>
                                            <option value="admin">Admin Actions</option>
                                        <option value="pir">PIR Events</option>
                                        <option value="class">Class Events</option>
                                        <option value="light">Light Events</option>
                                        <option value="anomaly">Anomalies</option>
                                        </select>
                                        <button type="button" class="filter-clear" data-clear="statusType" title="Clear type filter" hidden>&times;</button>
                                    </span>
                                    <span class="filter-select-wrap">
                                        <select id="statusActor">
                                            <option value="">All Actors</option>
                                            <?php foreach ($status_actors as $key => $label): ?>
                                                <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="filter-clear" data-clear="statusActor" title="Clear actor filter" hidden>&times;</button>
                                    </span>
                                    <span class="filter-select-wrap">
                                        <select id="statusSource">
                                            <option value="">All Sources</option>
                                            <option value="admin">Admin</option>
                                            <option value="pir">PIR</option>
                                            <option value="schedule">Schedule</option>
                                            <option value="manual">Manual</option>
                                        </select>
                                        <button type="button" class="filter-clear" data-clear="statusSource" title="Clear source filter" hidden>&times;</button>
                                    </span>
                                    <span class="filter-select-wrap">
                                        <select id="statusDate">
                                            <option value="">All Dates</option>
                                            <option value="today">Today</option>
                                            <option value="week">This Week</option>
                                            <option value="month">This Month</option>
                                        </select>
                                        <button type="button" class="filter-clear" data-clear="statusDate" title="Clear date filter" hidden>&times;</button>
                                    </span>
                                    <span class="filter-select-wrap date-range-wrap" title="Custom date range (overrides preset)">
                                        <input type="date" id="statusFrom" aria-label="From date" style="max-width:150px;">
                                        <span style="align-self:center;color:var(--muted);font-size:0.75rem;">to</span>
                                        <input type="date" id="statusTo" aria-label="To date" style="max-width:150px;">
                                        <button type="button" class="filter-clear" data-clear="statusFrom" data-clear2="statusTo" title="Clear custom range" hidden>&times;</button>
                                    </span>
                                </div>
                                <div class="chart-filter-chips" id="chartFilterChips" hidden></div>
                            </div>
                            <?php if (empty($activity_logs)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-journal-x"></i>
                                    <p>No activity logged yet. Events will appear here as they are recorded.</p>
                                </div>
                            <?php else: ?>
                                <div style="overflow-x:auto;">
                                    <table class="room-table" id="statusTable">
                                        <thead>
                                            <tr>
                                                <th>Action</th>
                                                <th>Action Type</th>
                                                <th>Actor</th>
                                                <th>Source</th>
                                                <th>Description</th>
                                                <th>Date and Time</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($activity_logs as $log):
                                                $rawAction = $log['action'];
                                                $actorLower = strtolower($log['actor'] ?? '');
                                                if (in_array($rawAction, $issue_actions, true)) {
                                                    $plainLabel = issue_label(['event_type' => $rawAction, 'actor' => ($log['actor'] ?? '')]);
                                                    $iconKey = ($rawAction === 'issue_raised' && $actorLower === 'pir') ? 'pir_motion' : $rawAction;
                                                    [$icon, $iconColor, $iconBg] = event_icon($iconKey);
                                                    $actionLabel = htmlspecialchars($plainLabel);
                                                } else {
                                                    [$icon, $iconColor, $iconBg] = event_icon($rawAction);
                                                    $plainLabel = str_replace('Pir ', 'PIR ', ucwords(str_replace('_', ' ', $rawAction)));
                                                    $actionLabel = htmlspecialchars($plainLabel);
                                                }
                                                $isRoom  = ($log['log_type'] ?? '') === 'room';
                                                $typeBg  = $isRoom  ? '#ede6f2' : '#4a0078';
                                                $typeClr = $isRoom  ? '#4a0078' : '#ede6f2';
                                                $typeLabel = $isRoom ? 'Room' : 'Admin';
                                                $logDate = strtotime($log['log_time']);
                                                $dateStr = date('M j, Y', $logDate);
                                                $timeStr = date('g:i A', $logDate);
                                                $source = log_source($log);
                                                $target = trim($log['target'] ?? '');
                                            ?>
                                                <tr class="status-row"
                                                    data-type="<?= $log['log_type'] ?>"
                                                    data-action="<?= htmlspecialchars($log['action']) ?>"
                                                    data-actor="<?= strtolower(htmlspecialchars($log['actor'] ?? '')) ?>"
                                                    data-source="<?= strtolower($source) ?>"
                                                    data-date="<?= date('Y-m-d', $logDate) ?>"
                                                    data-search="<?= strtolower(htmlspecialchars($plainLabel . ' ' . $log['target'] . ' ' . $log['actor'] . ' ' . $log['action'] . ' ' . $source . ' ' . $log['log_type'] . ' ' . date('Y-m-d', $logDate) . ' ' . $log['notes'])) ?>">
                                                    <td>
                                                        <span class="accordion-log-icon" style="background:<?= $iconBg ?>; color:<?= $iconColor ?>;"><i class="bi <?= $icon ?>"></i></span>
                                                        <span style="font-weight:600;"><?= $actionLabel ?></span>
                                                    </td>
                                                    <td><span class="tl-type-badge" style="background:<?= $typeBg ?>; color:<?= $typeClr ?>;"><?= $typeLabel ?></span></td>
                                                    <td><?= !empty($log['actor']) ? htmlspecialchars($log['actor']) : '-' ?></td>
                                                    <td><span class="event-count-badge"><?= $source ?></span></td>
                                                    <td><?= $target !== '' ? htmlspecialchars($target) : '-' ?></td>
                                                    <td class="last-event-text" style="white-space:nowrap;"><?= $timeStr ?>, <?= $dateStr ?></td>
                                                    <td style="max-width:220px; color:var(--muted); font-size:0.75rem;"><?= !empty($log['notes']) ? htmlspecialchars($log['notes']) : '-' ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="activity-pagination" id="activityPagination">
                                    <button id="activityPrev" onclick="goActivityPage(-1)" disabled>&laquo; Prev</button>
                                    <span id="activityPageInfo">Page 1 of 1</span>
                                    <button id="activityNext" onclick="goActivityPage(1)" disabled>Next &raquo;</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div><!-- /panel-status -->

        </div><!-- /reports-layout -->


        <?php include __DIR__ . "/../../src/Includes/admin-sidebar.php"; ?>
        <?php include __DIR__ . "/../../src/Includes/profile-offcanvas.php"; ?>

    </div><!-- /child-container -->

    <!-- â•â•â• EXPORT CONFIRM MODAL â•â•â• -->
    <div class="profile-details-modal modal fade" id="exportConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold"><i class="bi bi-download me-2"></i>Confirm Export</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <i id="exportModalIcon" class="bi bi-filetype-csv" style="font-size: 3rem; color: var(--secondary-color-2);"></i>
                    <p id="exportModalMsg" class="mt-3 mb-0">Are you sure you want to export this report?</p>
                </div>
                <div class="modal-footer d-flex flex-row flex-nowrap justify-content-between gap-2">
                    <button type="button" class="light bold w-100" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="medium w-100" id="exportConfirmBtn">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ GRAPH MAXIMIZE MODAL ═══ -->
    <div class="profile-details-modal modal fade" id="facultyReportDetails" tabindex="-1" aria-labelledby="facultyReportDetailsTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold" id="facultyReportDetailsTitle">Faculty Activity</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="facultyDetailsStatus" class="text-muted" role="status"></p>
                    <div id="facultyDetailsContent" hidden>
                        <div class="row g-3">
                            <section class="col-md-6">
                                <h6 class="bold">Gesture Breakdown</h6>
                                <div class="faculty-detail-chart"><canvas id="facultyDetailsGestures" role="img" aria-label="Named gesture breakdown"></canvas></div>
                                <p id="facultyDetailsUnknown" class="small text-muted"></p>
                            </section>
                            <section class="col-md-6">
                                <h6 class="bold">Active Time</h6>
                                <div class="faculty-detail-chart"><canvas id="facultyDetailsActiveTime" role="img" aria-label="First lights-on action relative to scheduled class start"></canvas></div>
                                <p id="facultyDetailsCoverage" class="small text-muted"></p>
                            </section>
                        </div>
                        <h6 class="bold mt-3">Activity Breakdown</h6>
                        <div id="facultyDetailsBreakdown" class="row g-3"></div>
                        <div class="reports-card-header mt-3">
                            <h2><i class="bi bi-activity" aria-hidden="true"></i>Recorded Events</h2>
                            <form id="facultyDetailsEventFilters" class="filter-bar">
                                <input type="search" name="search" placeholder="Search events" aria-label="Search recorded events">
                                <select name="room_id" aria-label="Recorded events room"><option value="">All Rooms</option></select>
                                <span class="date-range-wrap">
                                    <input type="date" name="start" aria-label="Recorded events start date" required>
                                    <span class="small">to</span>
                                    <input type="date" name="end" aria-label="Recorded events end date" required>
                                </span>
                                <button type="submit">Apply</button>
                            </form>
                        </div>
                        <p id="facultyDetailsEmpty" hidden>No recorded faculty events for this period and room selection.</p>
                        <div class="table-responsive faculty-detail-events-scroll" tabindex="0" role="region" aria-label="Recorded events table">
                            <table class="room-table" id="facultyDetailsEvents" hidden>
                                <thead><tr><th scope="col">Date and Time</th><th scope="col">Room</th><th scope="col">Event</th><th scope="col">Description</th></tr></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="profile-details-modal modal fade" id="graphModal" tabindex="-1" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold" id="graphModalTitle"><i class="bi bi-arrows-expand me-2"></i>Graph</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="graph-about" id="graphModalAbout" hidden></p>
                    <div style="height:55vh;position:relative;"><canvas id="graphModalCanvas"></canvas></div>
                    <div id="graphModalLegend" class="graph-legend-footer" style="font-size:0.75rem;border-top:none;"></div>

                    <!-- Legend Guide (collapsible, like KPI breakdown) -->
                    <div class="legend-guide" id="graphModalLegendGuide" hidden>
                        <button type="button" class="legend-guide-toggle" aria-expanded="false" aria-controls="legendGuideList">
                            <i class="bi bi-book"></i> <span>Legend Guide</span> <i class="bi bi-chevron-down ms-auto"></i>
                        </button>
                        <ul class="legend-guide-list" id="legendGuideList" style="max-height:0;opacity:0;overflow:hidden;transition:max-height 0.3s ease, opacity 0.3s ease;">
                        </ul>
                    </div>

                    <div style="overflow-x:auto;margin-top:1rem;max-height:30vh;overflow-y:auto;">
                        <table class="room-table" id="graphModalTable">
                            <thead><tr id="graphModalHeadRow"></tr></thead>
                            <tbody id="graphModalBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer d-flex flex-row flex-nowrap justify-content-end gap-2">
                    <button type="button" class="light bold w-100" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="../../js/lib/animations.js"></script>
    <script src="../../js/lib/toggles.js"></script>

    <script src="../../js/admin/admin-reports.js?v=20261027rev12"></script>
    <script src="../../js/admin/status-charts.js?v=20261027rev19"></script>
    <script src="../../js/faculty/faculty-tutorial.js"></script>
</body>

</html>
