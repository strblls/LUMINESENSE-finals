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
    WHERE event_type IN ('issue_raised', 'issue_resolved')
    ORDER BY event_time DESC
    LIMIT 200
");
if ($res5) {
    while ($row = $res5->fetch_assoc()) $issues[] = $row;
    $res5->free();
}

// - Issue stats -
$issue_raised_count = 0;
$issue_resolved_count = 0;
foreach ($issues as $issue) {
    if ($issue['event_type'] === 'issue_raised') $issue_raised_count++;
    elseif ($issue['event_type'] === 'issue_resolved') $issue_resolved_count++;
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
        'admin_action'   => ['bi-shield-check',        '#084298', '#cfe2ff'],
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

    <!--Relative links-->
    <link rel="icon" type="image/png" sizes="32x32" href="../../images/icon.png">
    <link rel="shortcut icon" type="image/png" href="../../images/icon.png">
    <link rel="stylesheet" href="../../css/base/global.css">
    <link rel="stylesheet" href="../../css/base/containers.css">
    <link rel="stylesheet" href="../../css/base/modals.css">
    <link rel="stylesheet" href="../../css/faculty/timetable.css">
    <link rel="stylesheet" href="../../css/admin/home-reports.css">
    <link rel="stylesheet" href="../../css/admin/common.css">
</head>

<body class="contrast-bg">
    <?php include __DIR__ . "/../../src/Includes/admin-topbar.php"; ?>
    <?php include __DIR__ . "/../../src/Includes/admin-sidebar.php"; ?>
    <?php include __DIR__ . "/../../src/Includes/profile-offcanvas.php"; ?>

    <!-- â•â•â• MAIN CONTENT â•â•â• -->
    <div class="child-container">
        <div class="reports-layout">

            <div class="main-container faculty-timetable-heading d-flex align-items-center w-auto" style="background-color: var(--secondary-color-2);">
                <div class="d-flex align-items-center flex-grow-1" style="position:relative;">
                    <button type="button" class="timetable-btn ms-2" data-panel="panelGuideInfo" title="Guide">
                        <i class="bi bi-info-lg"></i>
                        <span class="timetable-btn-title bold">Guide</span>
                    </button>
                    <div id="panelGuideInfo" class="timetable-panel p-3 m-3">
                        <div class="section-container timetable" style="background-color:#f8f9fa;width:320px;">
                            <h6 class="bold mb-2"><i class="bi bi-info-circle me-1"></i>Reports Guide</h6>
                            <ol class="ps-3 mb-0" style="font-size:13px;line-height:1.7;">
                                <li>Press <strong>Recent Activity</strong>, <strong>Room Activity</strong>, or <strong>Issues Logged</strong> in the heading to load a report.</li>
                                <li>Use the search bar to find entries by room, actor, or action keyword.</li>
                                <li>Use the dropdown filters inside each tab to narrow by type or date.</li>
                                <li>In <strong>Room Activity</strong>, click a room row to expand its recent event log.</li>
                                <li>Click <strong>Export CSV</strong> or <strong>Export PDF</strong> to download the currently viewed report.</li>
                            </ol>
                        </div>
                    </div>
                    <input type="text" id="reportsSearch" class="form-control" placeholder="Search room name or faculty..." style="max-width:500px;margin-left:16px;">
                </div>
                <div class="d-flex align-items-center pe-2" style="position:relative; gap:6px;">
                    <button type="button" class="timetable-btn" data-tab="activity" title="Recent Activity">
                        <i class="bi bi-clock-history"></i>
                        <span class="timetable-btn-title bold">Recent<br>Activity</span>
                    </button>
                    <button type="button" class="timetable-btn" data-tab="rooms" title="Room Activity">
                        <i class="bi bi-door-open"></i>
                        <span class="timetable-btn-title bold">Room<br>Activity</span>
                    </button>
                    <button type="button" class="timetable-btn" data-tab="issues" title="Issues Logged">
                        <i class="bi bi-exclamation-triangle"></i>
                        <span class="timetable-btn-title bold">Issues<br>Logged</span>
                    </button>
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

            <div style="background-color:#f8f9fa;" class="section-container">
                <div class="stat-row" id="statRow">
                    <div class="stat-card"
                         data-a-icon="bi-journal-text" data-a-label="Total Log Entries" data-a-val="<?= count($activity_logs) ?>"
                         data-r-icon="bi-door-open"     data-r-label="Total Rooms"        data-r-val="<?= count($rooms) ?>"
                         data-i-icon="bi-exclamation-triangle" data-i-label="Total Issues" data-i-val="<?= count($issues) ?>">
                        <span class="stat-icon"><i class="bi bi-journal-text" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                        <div>
                            <div class="stat-value"><?= count($activity_logs) ?></div>
                            <p class="stat-label">Total Log Entries</p>
                        </div>
                    </div>
                    <div class="stat-card"
                         data-a-icon="bi-door-open"          data-a-label="Tracked Rooms"        data-a-val="<?= count($rooms) ?>"
                         data-r-icon="bi-lightbulb-fill"     data-r-label="Lights On"            data-r-val="<?= count(array_filter($rooms, fn($r) => $r['light_status'] === 'on')) ?>"
                         data-i-icon="bi-exclamation-triangle-fill" data-i-label="Issue Raised" data-i-val="<?= $issue_raised_count ?>">
                        <span class="stat-icon"><i class="bi bi-door-open" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                        <div>
                            <div class="stat-value"><?= count($rooms) ?></div>
                            <p class="stat-label">Tracked Rooms</p>
                        </div>
                    </div>
                    <div class="stat-card"
                         data-a-icon="bi-lightbulb-fill"         data-a-label="Lights Currently On"  data-a-val="<?= count(array_filter($rooms, fn($r) => $r['light_status'] === 'on')) ?>"
                         data-r-icon="bi-lightbulb"             data-r-label="Lights Off"           data-r-val="<?= count(array_filter($rooms, fn($r) => $r['light_status'] === 'off')) ?>"
                         data-i-icon="bi-check-circle-fill" data-i-label="Issue Resolved" data-i-val="<?= $issue_resolved_count ?>">
                        <span class="stat-icon"><i class="bi bi-lightbulb-fill" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                        <div>
                            <div class="stat-value"><?= count(array_filter($rooms, fn($r) => $r['light_status'] === 'on')) ?></div>
                            <p class="stat-label">Lights Currently On</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- - Default state - -->
            <div class="default-state" id="defaultState">
                <i class="bi bi-arrow-up-circle"></i>
                <p>Select <strong>Recent Activity</strong>, <strong>Room Activity</strong>, or <strong>Issues Logged</strong> from the heading above to view reports.</p>
            </div>

            <!-- â•â• TAB: Activity Log â•â• -->
            <div class="tab-panel" id="tab-activity">
                <div class="reports-card">
                    <div class="reports-card-header">
                        <h2 class="bold"><i class="bi bi-clock-history"></i>Activity Logs</h2>
                        <div class="filter-bar">
                            <select id="activityType">
                                <option value="">All Types</option>
                                <option value="room">Room Events</option>
                                <option value="admin">Admin Actions</option>
                                <option value="pir">PIR Events</option>
                                <option value="class">Class Events</option>
                            </select>
                            <select id="activityDate">
                                <option value="">All Dates</option>
                                <option value="today">Today</option>
                                <option value="week">This Week</option>
                                <option value="month">This Month</option>
                            </select>
                        </div>
                    </div>

                    <div class="timeline" id="activityTimeline">
                        <?php if (empty($activity_logs)): ?>
                            <div class="empty-state">
                                <i class="bi bi-journal-x"></i>
                                <p>No activity logs found. Events will appear here as they are recorded.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($activity_logs as $i => $log):
                                [$icon, $iconColor, $iconBg] = event_icon($log['action']);
                                $isRoom  = $log['log_type'] === 'room';
                                $typeBg  = $isRoom  ? '#ede6f2' : '#4a0078';
                                $typeClr = $isRoom  ? '#4a0078' : '#ede6f2';
                                $typeLabel = $isRoom ? 'Room' : 'Admin';
                                $logDate = strtotime($log['log_time']);
                                $dateStr = date('M j, Y', $logDate);
                                $timeStr = date('g:i A', $logDate);
                            ?>
                                <div class="timeline-item"
                                    data-type="<?= $log['log_type'] ?>"
                                    data-action="<?= htmlspecialchars($log['action']) ?>"
                                    data-date="<?= date('Y-m-d', $logDate) ?>"
                                    data-search="<?= strtolower(htmlspecialchars($log['target'] . ' ' . $log['actor'] . ' ' . $log['action'])) ?>">
                                    <div class="tl-icon" style="background:<?= $iconBg ?>; color:<?= $iconColor ?>;">
                                        <i class="bi <?= $isRoom ? 'bi-door-open' : $icon ?>"></i>
                                    </div>
                                    <div class="tl-body">
                                        <p class="tl-action">
                                            <?= htmlspecialchars(str_replace('Pir ', 'PIR ', ucwords(str_replace('_', ' ', $log['action'])))) ?>
                                            <?php if (!empty($log['target'])): ?>
                                                &mdash; <span style="color:var(--secondary-color-3);"><?= htmlspecialchars($log['target']) ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <div class="tl-meta">
                                            <span><i class="bi bi-clock"></i> <?= $timeStr ?>, <?= $dateStr ?></span>
                                            <?php if (!empty($log['actor'])): ?>
                                                <span><i class="bi bi-person"></i> <?= htmlspecialchars($log['actor']) ?></span>
                                            <?php endif; ?>
                                            <span class="tl-type-badge" style="background:<?= $typeBg ?>; color:<?= $typeClr ?>;"><?= $typeLabel ?></span>
                                        </div>
                                        <?php if (!empty($log['notes'])): ?>
                                            <span class="tl-notes"><i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($log['notes']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="activity-pagination" id="activityPagination">
                        <button id="activityPrev" onclick="goActivityPage(-1)" disabled>&laquo; Prev</button>
                        <span id="activityPageInfo">Page 1 of 1</span>
                        <button id="activityNext" onclick="goActivityPage(1)" disabled>Next &raquo;</button>
                    </div>
                </div>
            </div>

            <!-- â•â• TAB: Room Activity â•â• -->
            <div class="tab-panel" id="tab-rooms">
                <div class="reports-card">
                    <div class="reports-card-header">
                        <h2><i class="bi bi-door-open"></i> Room Activity Summary</h2>
                        <div class="filter-bar">
                            <select id="roomLightFilter">
                                <option value="">All Lights</option>
                                <option value="on">Lights On</option>
                                <option value="off">Lights Off</option>
                                <option value="pir_motion">PIR Motion</option>
                            </select>
                        </div>
                    </div>

                    <?php if (empty($rooms)): ?>
                        <div class="empty-state">
                            <i class="bi bi-building-x"></i>
                            <p>No rooms found. Add classrooms to start tracking activity.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="room-table" id="roomTable">
                                <thead>
                                    <tr>
                                        <th>Room</th>
                                        <th>Light Status</th>
                                        <th>Size</th>
                                        <th>Total Events</th>
                                        <th>Last Activity</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rooms as $room):
                                        $on       = $room['light_status'] === 'on';
                                        $hasLast  = !empty($room['last_event']);
                                        $lastStr  = $hasLast ? date('M j, g:i A', strtotime($room['last_event'])) : 'No events yet';
                                        $roomName = htmlspecialchars($room['room_name']);
                                    ?>
                                        <tr class="room-main-row" data-room="<?= $roomName ?>"
                                            data-light="<?= $room['light_status'] ?>"
                                            data-search="<?= strtolower(htmlspecialchars($room['room_name'] . ' ' . $room['description'])) ?>"
                                            onclick="toggleRoomAccordion(this)">
                                            <td>
                                                <div style="font-weight:600;"><i class="bi bi-chevron-right room-chevron me-1" style="font-size:11px;transition:transform .2s;"></i><?= $roomName ?></div>
                                            </td>
                                            <td>
                                                <span class="light-pill <?= $on ? 'light-on' : 'light-off' ?>">
                                                    <span class="light-dot <?= $on ? 'dot-on' : 'dot-off' ?>"></span>
                                                    <?= $on ? 'ON' : 'OFF' ?>
                                                </span>
                                            </td>
                                            <td><?= ucfirst(htmlspecialchars($room['room_size'])) ?></td>
                                            <td><span class="event-count-badge"><?= (int)$room['total_events'] ?></span></td>
                                            <td class="last-event-text"><?= $lastStr ?></td>
                                            <td style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--muted); font-size:0.75rem;">
                                                <?= htmlspecialchars($room['description'] ?? '-') ?>
                                            </td>
                                        </tr>
                                        <tr class="room-accordion-row" style="display:none;">
                                            <td colspan="6">
                                                <div class="room-accordion-content">Loading...</div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- â•â• TAB: Issues Logged â•â• -->
            <div class="tab-panel" id="tab-issues">
                <div class="reports-card">
                    <div class="reports-card-header">
                        <h2><i class="bi bi-exclamation-triangle"></i> Issues Logged</h2>
                        <div class="filter-bar">
                            <select id="issueType">
                                <option value="">All Issues</option>
                                <option value="issue_raised">Issue Raised</option>
                                <option value="issue_resolved">Issue Resolved</option>
                            </select>
                            <select id="issueDate">
                                <option value="">All Dates</option>
                                <option value="today">Today</option>
                                <option value="week">This Week</option>
                                <option value="month">This Month</option>
                            </select>
                        </div>
                    </div>

                    <div class="timeline" id="issueTimeline">
                        <?php if (empty($issues)): ?>
                            <div class="empty-state">
                                <i class="bi bi-inbox"></i>
                                <p>No issues logged yet. Issues will appear here when PIR detects motion outside schedule or other anomalies occur.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($issues as $issue):
                                [$icon, $iconColor, $iconBg] = event_icon($issue['event_type']);
                                $logDate = strtotime($issue['event_time']);
                                $dateStr = date('M j, Y', $logDate);
                                $timeStr = date('g:i A', $logDate);
                                $isRaised = $issue['event_type'] === 'issue_raised';
                            ?>
                                <div class="timeline-item"
                                    data-type="issue"
                                    data-action="<?= $issue['event_type'] ?>"
                                    data-date="<?= date('Y-m-d', $logDate) ?>"
                                    data-search="<?= strtolower(htmlspecialchars($issue['room_name'] . ' ' . $issue['notes'])) ?>">
                                    <div class="tl-icon" style="background:<?= $iconBg ?>; color:<?= $iconColor ?>;">
                                        <i class="bi bi-exclamation-triangle-fill"></i>
                                    </div>
                                    <div class="tl-body">
                                        <p class="tl-action">
                                            <?= $isRaised ? 'Issue Raised' : 'Issue Resolved' ?>
                                            &mdash; <span style="color:var(--secondary-color-3);"><?= htmlspecialchars($issue['room_name']) ?></span>
                                        </p>
                                        <div class="tl-meta">
                                            <span><i class="bi bi-clock"></i> <?= $timeStr ?>, <?= $dateStr ?></span>
                                            <span><i class="bi bi-person"></i> <?= htmlspecialchars($issue['triggered_by']) ?></span>
                                            <span class="tl-type-badge" style="background:<?= $isRaised ? '#842029' : '#0f5132' ?>; color:#fff;">
                                                <?= $isRaised ? 'Issue Raised' : 'Issue Resolved' ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($issue['notes'])): ?>
                                            <span class="tl-notes"><i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($issue['notes']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

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

    <script src="../../js/admin/admin-reports.js"></script>
    <script src="../../js/faculty/faculty-tutorial.js"></script>
</body>

</html>