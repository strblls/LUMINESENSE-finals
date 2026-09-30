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

/* Distinct actors for the Status Actor filter (lowercase keys, display labels) */
$status_actors = [];
foreach ($activity_logs as $log) {
    $actorName = trim($log['actor'] ?? '');
    if ($actorName === '') continue;
    $key = strtolower($actorName);
    if (!isset($status_actors[$key])) $status_actors[$key] = $actorName;
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

/* -----------------------
   FETCH: Faculty Reports (per-faculty activity aggregation)
   Provisional stat pills — final labels/numbers can be swapped later.
----------------------- */
$faculty_reports = [];
$faculty_total = 0;
$faculty_pending = 0;
$faculty_approved_count = 0;
$faculty_schedules_total = 0;
$ext_pending_total = 0;

$hasFacultyIdSched = false;
$hasCreatedBySched = false;
$hasFacultyIdLight = false;
$hasExtTable = false;
$hasFacultyIdExt = false;
try {
    $c = $conn->query("SHOW COLUMNS FROM schedules LIKE 'faculty_id'");
    $hasFacultyIdSched = ($c && $c->num_rows > 0);
    if ($c) $c->free();
    $c = $conn->query("SHOW COLUMNS FROM schedules LIKE 'created_by'");
    $hasCreatedBySched = ($c && $c->num_rows > 0);
    if ($c) $c->free();
    $c = $conn->query("SHOW COLUMNS FROM lighting_logs LIKE 'faculty_id'");
    $hasFacultyIdLight = ($c && $c->num_rows > 0);
    if ($c) $c->free();
    $t = $conn->query("SHOW TABLES LIKE 'extension_requests'");
    $hasExtTable = ($t && $t->num_rows > 0);
    if ($t) $t->free();
    if ($hasExtTable) {
        $c = $conn->query("SHOW COLUMNS FROM extension_requests LIKE 'faculty_id'");
        $hasFacultyIdExt = ($c && $c->num_rows > 0);
        if ($c) $c->free();
    }
} catch (Throwable $e) { /* keep defaults, faculty panel shows directory only */ }

$resF = $conn->query("SELECT id, first_name, last_name, email, is_verified, approved_by, created_at FROM faculty ORDER BY last_name ASC, first_name ASC");
if ($resF) {
    while ($frow = $resF->fetch_assoc()) {
        $fid = (int)$frow['id'];
        $isApproved = ((int)$frow['is_verified'] === 1 && $frow['approved_by'] !== null);
        $isPending = ((int)$frow['is_verified'] === 1 && $frow['approved_by'] === null);
        $schedCount = 0;
        $extCount = 0;
        $extApproved = 0;
        $lightCount = 0;
        $lastActivity = null;
        if ($hasFacultyIdSched || $hasCreatedBySched) {
            $conds = [];
            if ($hasFacultyIdSched) $conds[] = "faculty_id = $fid";
            if ($hasCreatedBySched) $conds[] = "created_by = $fid";
            $q = $conn->query("SELECT COUNT(*) AS c, MAX(created_at) AS m FROM schedules WHERE " . implode(' OR ', $conds));
            if ($q && ($r = $q->fetch_assoc())) {
                $schedCount = (int)($r['c'] ?? 0);
                if (!empty($r['m'])) $lastActivity = $r['m'];
            }
            if ($q) $q->free();
        }
        if ($hasExtTable && $hasFacultyIdExt) {
            $q = $conn->query("SELECT COUNT(*) AS c, SUM(status='approved') AS a, MAX(requested_at) AS m FROM extension_requests WHERE faculty_id = $fid");
            if ($q && ($r = $q->fetch_assoc())) {
                $extCount = (int)($r['c'] ?? 0);
                $extApproved = (int)($r['a'] ?? 0);
                if (!empty($r['m']) && ($lastActivity === null || $r['m'] > $lastActivity)) $lastActivity = $r['m'];
            }
            if ($q) $q->free();
        }
        if ($hasFacultyIdLight) {
            $q = $conn->query("SELECT COUNT(*) AS c, MAX(event_time) AS m FROM lighting_logs WHERE faculty_id = $fid");
            if ($q && ($r = $q->fetch_assoc())) {
                $lightCount = (int)($r['c'] ?? 0);
                if (!empty($r['m']) && ($lastActivity === null || $r['m'] > $lastActivity)) $lastActivity = $r['m'];
            }
            if ($q) $q->free();
        }
        $faculty_reports[] = [
            'id' => $fid,
            'name' => trim(($frow['first_name'] ?? '') . ' ' . ($frow['last_name'] ?? '')),
            'email' => $frow['email'] ?? '',
            'is_approved' => $isApproved,
            'is_pending' => $isPending,
            'schedules' => $schedCount,
            'extensions' => $extCount,
            'extensions_approved' => $extApproved,
            'lighting_events' => $lightCount,
            'last_activity' => $lastActivity,
        ];
        $faculty_schedules_total += $schedCount;
    }
    $resF->free();
}
$faculty_total = count($faculty_reports);
foreach ($faculty_reports as $fr) {
    if ($fr['is_pending']) $faculty_pending++;
    if ($fr['is_approved']) $faculty_approved_count++;
}
if ($hasExtTable) {
    $q = $conn->query("SELECT COUNT(*) AS c FROM extension_requests WHERE status='pending'");
    if ($q && ($r = $q->fetch_assoc())) $ext_pending_total = (int)($r['c'] ?? 0);
    if ($q) $q->free();
}

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
    <link rel="stylesheet" href="../../css/admin/home-reports.css?v=20260928rev5">
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
                                <li>Use Prev / Next to page through the status table.</li>
                                <li>Click <strong>Export CSV</strong> or <strong>Export PDF</strong> to download the currently viewed report.</li>
                            </ol>
                        </div>
                    </div>
                    <input type="text" id="reportsSearch" class="form-control" placeholder="Search room name or faculty..." style="max-width:500px;margin-left:16px;">
                </div>
                <div class="d-flex align-items-center pe-2" style="position:relative; gap:6px;">
                    <button type="button" class="timetable-btn" onclick="exportCSV()" title="Export CSV">
                        <i class="bi bi-filetype-csv"></i>
                        <span class="timetable-btn-title bold">Export<br>CSV</span>
                    </button>
                    <button type="button" class="timetable-btn" onclick="exportPDF()" title="Export PDF">
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

            <!-- ══ PANEL: Faculty Reports ══ -->
            <div id="panel-faculty" class="report-view" hidden>
                <div style="background-color:#f8f9fa;" class="section-container">
                    <div class="stat-row">
                        <div class="stat-card">
                            <span class="stat-icon"><i class="bi bi-people-fill" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                            <div>
                                <div class="stat-value"><?= (int)$faculty_total ?></div>
                                <p class="stat-label">Total Faculty</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <span class="stat-icon"><i class="bi bi-person-plus" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                            <div>
                                <div class="stat-value"><?= (int)$faculty_pending ?></div>
                                <p class="stat-label">Pending Approvals</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <span class="stat-icon"><i class="bi bi-calendar-check" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                            <div>
                                <div class="stat-value"><?= (int)$faculty_schedules_total ?></div>
                                <p class="stat-label">Total Schedules</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="reports-card">
                    <div class="reports-card-header">
                        <h2 class="bold"><i class="bi bi-people-fill"></i>Faculty Reports</h2>
                        <div class="filter-bar">
                            <select id="facultyStatusFilter">
                                <option value="">All Faculty</option>
                                <option value="approved">Approved</option>
                                <option value="pending">Pending</option>
                                <option value="other">Unverified / Other</option>
                            </select>
                        </div>
                    </div>
                    <?php if (empty($faculty_reports)): ?>
                        <div class="empty-state">
                            <i class="bi bi-people"></i>
                            <p>No faculty records found.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="room-table" id="facultyTable">
                                <thead>
                                    <tr>
                                        <th>Faculty</th>
                                        <th>Status</th>
                                        <th>Schedules</th>
                                        <th>Extensions</th>
                                        <th>Lighting Events</th>
                                        <th>Last Activity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($faculty_reports as $fr):
                                        $statusKey = $fr['is_approved'] ? 'approved' : ($fr['is_pending'] ? 'pending' : 'other');
                                        $statusLabel = $fr['is_approved'] ? 'Approved' : ($fr['is_pending'] ? 'Pending' : 'Unverified');
                                        $statusBg = $fr['is_approved'] ? '#0f5132' : ($fr['is_pending'] ? '#664d03' : '#5a5a5a');
                                        $lastStr = !empty($fr['last_activity']) ? date('M j, g:i A', strtotime($fr['last_activity'])) : 'No activity yet';
                                    ?>
                                        <tr class="faculty-main-row"
                                            data-status="<?= $statusKey ?>"
                                            data-search="<?= strtolower(htmlspecialchars($fr['name'] . ' ' . $fr['email'])) ?>">
                                            <td>
                                                <div style="font-weight:600;"><?= htmlspecialchars($fr['name'] !== '' ? $fr['name'] : 'Unnamed Faculty') ?></div>
                                                <div style="font-size:0.72rem;color:var(--muted);"><?= htmlspecialchars($fr['email']) ?></div>
                                            </td>
                                            <td><span class="tl-type-badge" style="background:<?= $statusBg ?>; color:#fff;"><?= $statusLabel ?></span></td>
                                            <td><span class="event-count-badge"><?= (int)$fr['schedules'] ?></span></td>
                                            <td><span class="event-count-badge"><?= (int)$fr['extensions'] ?><?= ((int)$fr['extensions_approved'] > 0 ? ' (' . (int)$fr['extensions_approved'] . ' approved)' : '') ?></span></td>
                                            <td><span class="event-count-badge"><?= (int)$fr['lighting_events'] ?></span></td>
                                            <td class="last-event-text"><?= $lastStr ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ══ PANEL: Status Reports (existing 3 tabs) ══ -->
            <div id="panel-status" class="report-view" hidden>
                <div class="status-board">
                    <div class="status-kpis">
                        <span class="mini-stat-pill"><i class="bi bi-door-open"></i><b id="kpiRoomActions"><?= (int)$room_actions_count ?></b><small>Total Room Actions</small></span>
                        <span class="mini-stat-pill"><i class="bi bi-exclamation-triangle-fill"></i><b id="kpiAnomalies"><?= (int)$anomalies_count ?></b><small>Total Anomalies</small></span>
                        <span class="mini-stat-pill"><i class="bi bi-shield-check"></i><b id="kpiAdminActions"><?= (int)$admin_actions_count ?></b><small>Total Admin Actions</small></span>
                    </div>
                    <div class="status-main">
                        <div class="status-graphs">
                            <div class="status-graph-card"><span class="status-graph-label">Chart 1 &mdash; coming soon</span><canvas id="statusChart1"></canvas></div>
                            <div class="status-graph-card"><span class="status-graph-label">Chart 2 &mdash; coming soon</span><canvas id="statusChart2"></canvas></div>
                            <div class="status-graph-card"><span class="status-graph-label">Chart 3 &mdash; coming soon</span><canvas id="statusChart3"></canvas></div>
                            <div class="status-graph-card"><span class="status-graph-label">Chart 4 &mdash; coming soon</span><canvas id="statusChart4"></canvas></div>
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
                                </div>
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
                                                [$icon, $iconColor, $iconBg] = event_icon($log['action']);
                                                $isRoom  = ($log['log_type'] ?? '') === 'room';
                                                $typeBg  = $isRoom  ? '#ede6f2' : '#4a0078';
                                                $typeClr = $isRoom  ? '#4a0078' : '#ede6f2';
                                                $typeLabel = $isRoom ? 'Room' : 'Admin';
                                                $logDate = strtotime($log['log_time']);
                                                $dateStr = date('M j, Y', $logDate);
                                                $timeStr = date('g:i A', $logDate);
                                                $actionLabel = htmlspecialchars(str_replace('Pir ', 'PIR ', ucwords(str_replace('_', ' ', $log['action']))));
                                                $source = log_source($log);
                                                $target = trim($log['target'] ?? '');
                                            ?>
                                                <tr class="status-row"
                                                    data-type="<?= $log['log_type'] ?>"
                                                    data-action="<?= htmlspecialchars($log['action']) ?>"
                                                    data-actor="<?= strtolower(htmlspecialchars($log['actor'] ?? '')) ?>"
                                                    data-source="<?= strtolower($source) ?>"
                                                    data-date="<?= date('Y-m-d', $logDate) ?>"
                                                    data-search="<?= strtolower(htmlspecialchars($log['target'] . ' ' . $log['actor'] . ' ' . $log['action'] . ' ' . $source . ' ' . $log['log_type'] . ' ' . date('Y-m-d', $logDate) . ' ' . $log['notes'])) ?>">
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

    <script src="../../js/lib/animations.js"></script>
    <script src="../../js/lib/toggles.js"></script>

    <script src="../../js/admin/admin-reports.js?v=20260928rev5"></script>
    <script src="../../js/faculty/faculty-tutorial.js"></script>
</body>

</html>