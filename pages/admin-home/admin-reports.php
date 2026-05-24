<?php
require_once '../../php/includes/admin-head.php';
require_once '../../php/handlers/admin-handlers.php';

/* ─────────────────────────────────────────────
   FETCH: Activity Log  (room_logs + approval_logs merged)
   Expects tables:
     room_logs   (id, event_type, room_name, triggered_by, event_time, notes)
     admin_logs  (id, action, target_name, performed_by, created_at, notes)
   Adjust table/column names to match your actual schema.
───────────────────────────────────────────── */

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

// Sort merged list newest-first
usort($activity_logs, fn($a, $b) => strtotime($b['log_time']) - strtotime($a['log_time']));

/* ─────────────────────────────────────────────
   FETCH: Room Activity Summary
───────────────────────────────────────────── */
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
        (SELECT COUNT(*) FROM room_logs rl WHERE rl.room_name = c.room_name) AS total_events,
        (SELECT MAX(rl2.event_time) FROM room_logs rl2 WHERE rl2.room_name = c.room_name) AS last_event
    FROM classrooms c
    ORDER BY c.room_name ASC
");
if ($res3) {
    while ($row = $res3->fetch_assoc()) $rooms[] = $row;
    $res3->free();
}

$conn->close();

/* ─── Icon map for event types ─── */
function event_icon(string $type): array {
    $map = [
        'light_on'       => ['bi-lightbulb-fill',      '#0f5132', '#d1e7dd'],
        'light_off'      => ['bi-lightbulb',            '#842029', '#f8d7da'],
        'motion_detect'  => ['bi-person-bounding-box',  '#084298', '#cfe2ff'],
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
    <title>Reports – LumineSense Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">

    <style>
        :root {
            --primary-color:      #f9edfa;
            --secondary-color-1:  #2f004f;
            --secondary-color-2:  #58078f;
            --secondary-color-3:  #790faf;
            --secondary-color-4:  #9b00e9;
            --muted:              #9f9f9f;
            --font-primary:       'Poppins', sans-serif;
            --card-bg:            #fff;
            --border:             #ece3f0;
        }

        /* ── Layout ── */
        .reports-layout {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            padding: 1.25rem 1rem 2rem;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
        }

        /* ── Tab Nav ── */
        .tab-nav {
            display: flex;
            gap: 0.5rem;
            background: #ede6f2;
            border-radius: 12px;
            padding: 5px;
            width: fit-content;
        }
        .tab-btn {
            padding: 0.45rem 1.2rem;
            border-radius: 9px;
            border: none;
            background: transparent;
            font-family: var(--font-primary);
            font-size: 0.82rem;
            font-weight: 500;
            color: var(--secondary-color-2);
            cursor: pointer;
            transition: background 0.18s, color 0.18s;
        }
        .tab-btn.active {
            background: var(--secondary-color-1);
            color: #fff;
        }
        .tab-btn:not(.active):hover {
            background: #d8c9e8;
        }

        /* ── Section card ── */
        .reports-card {
            background: var(--card-bg);
            border-radius: 14px;
            border: 1px solid var(--border);
            overflow: hidden;
        }
        .reports-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.25rem 0.75rem;
            border-bottom: 1px solid var(--border);
            gap: 1rem;
            flex-wrap: wrap;
        }
        .reports-card-header h2 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--secondary-color-1);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .reports-card-header h2 i { font-size: 1.1rem; color: var(--secondary-color-3); }

        /* ── Filters ── */
        .filter-bar {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .filter-bar input,
        .filter-bar select {
            font-family: var(--font-primary);
            font-size: 0.78rem;
            padding: 0.35rem 0.75rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            outline: none;
            color: var(--secondary-color-1);
        }
        .filter-bar input:focus,
        .filter-bar select:focus { border-color: var(--secondary-color-3); }

        /* ── Export btn ── */
        .export-btn {
            padding: 0.35rem 0.9rem;
            border-radius: 8px;
            border: 1.5px solid var(--secondary-color-1);
            background: transparent;
            font-family: var(--font-primary);
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--secondary-color-1);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            transition: background 0.15s, color 0.15s;
        }
        .export-btn:hover { background: var(--secondary-color-1); color: #fff; }

        /* ── Timeline (Activity Log) ── */
        .timeline {
            padding: 1rem 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0;
            max-height: 60vh;
            overflow-y: auto;
        }
        .timeline-item {
            display: flex;
            gap: 1rem;
            padding: 0.7rem 0;
            border-bottom: 1px solid #f3edf7;
            animation: fadeSlide 0.3s ease both;
        }
        .timeline-item:last-child { border-bottom: none; }
        @keyframes fadeSlide {
            from { opacity: 0; transform: translateY(6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .tl-icon {
            width: 34px; height: 34px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            font-size: 0.9rem;
        }
        .tl-body { flex: 1; min-width: 0; }
        .tl-action {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--secondary-color-1);
            margin: 0 0 2px;
        }
        .tl-meta {
            font-size: 0.72rem;
            color: var(--muted);
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .tl-meta span { display: flex; align-items: center; gap: 3px; }
        .tl-notes {
            font-size: 0.72rem;
            color: #666;
            background: #f9f3fc;
            border-radius: 6px;
            padding: 3px 8px;
            margin-top: 4px;
            display: inline-block;
        }
        .tl-type-badge {
            font-size: 0.65rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 20px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        /* ── Room Activity Table ── */
        .room-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }
        .room-table thead tr {
            background: #f6f0fb;
        }
        .room-table th {
            padding: 0.65rem 1rem;
            font-weight: 600;
            color: var(--secondary-color-2);
            text-align: left;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .room-table td {
            padding: 0.7rem 1rem;
            border-bottom: 1px solid #f3edf7;
            vertical-align: middle;
            color: var(--secondary-color-1);
        }
        .room-table tbody tr:last-child td { border-bottom: none; }
        .room-table tbody tr:hover { background: #faf5ff; }

        .light-pill {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 0.72rem; font-weight: 700;
            padding: 3px 10px; border-radius: 20px;
        }
        .light-on  { background: #d1e7dd; color: #0f5132; }
        .light-off { background: #f8d7da; color: #842029; }
        .light-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }
        .dot-on  { background: #198754; }
        .dot-off { background: #dc3545; }

        .event-count-badge {
            background: #ede6f2;
            color: var(--secondary-color-2);
            font-size: 0.72rem;
            font-weight: 700;
            padding: 2px 9px;
            border-radius: 20px;
        }

        .last-event-text {
            font-size: 0.72rem;
            color: var(--muted);
        }

        /* ── Empty state ── */
        .empty-state {
            padding: 2.5rem 1rem;
            text-align: center;
            color: var(--muted);
        }
        .empty-state i { font-size: 2rem; margin-bottom: 0.5rem; display: block; }
        .empty-state p { font-size: 0.82rem; margin: 0; }

        /* ── Summary pills (top) ── */
        .summary-row {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .summary-pill {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: #f6f0fb;
            border-radius: 10px;
            padding: 0.55rem 0.9rem;
            min-width: 130px;
        }
        .summary-pill .pill-val {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--secondary-color-1);
            line-height: 1;
        }
        .summary-pill .pill-label {
            font-size: 0.68rem;
            color: var(--muted);
            line-height: 1.3;
        }
        .summary-pill i { font-size: 1.3rem; color: var(--secondary-color-3); }

        /* ── Topbar adaptations ── */
        .topbar h1 { font-size: 1.2rem !important; }

        /* ── Tab panels ── */
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        /* ── Sidebar/offcanvas (same as other admin pages) ── */
        .nav-btn {
            width: 52px; height: 52px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            background-color: var(--secondary-color-1); color: var(--primary-color);
            border: none; cursor: pointer;
            transition: background-color 0.2s, transform 0.15s;
        }
        .nav-btn i { font-size: 22px; }
        .nav-btn:hover { background-color: var(--secondary-color-4); transform: scale(1.06); }
        #sidebarOffcanvas { width: 100px !important; background-color: var(--primary-color); }
        #sidebarOffcanvas .offcanvas-header { justify-content: center; padding: 1rem 0.5rem; }
        #sidebarOffcanvas .logo { width: 75px; height: 75px; object-fit: contain; cursor: pointer; }
        #sidebarOffcanvas .offcanvas-body { display: flex; flex-direction: column; align-items: center; gap: 8px; padding-top: 0.5rem; }
        #sidebarOffcanvas .offcanvas-footer { display: flex; justify-content: center; padding: 1rem; }
        #profileOffcanvas { width: 240px !important; background-color: var(--primary-color); }
        .profile-btn {
            width: 100%; padding: 8px; margin: 3px 0; border-radius: 8px;
            background-color: var(--secondary-color-1); color: var(--primary-color);
            border: none; font-size: 14px; cursor: pointer;
            font-family: var(--font-primary);
            transition: background-color 0.2s, transform 0.15s;
        }
        .profile-btn:hover { background-color: var(--secondary-color-4); transform: scale(1.02); }
        .avatar-icon {
            width: 44px; height: 44px; border-radius: 50%;
            background: var(--secondary-color-1); color: #fff;
        }

        @media (max-width: 600px) {
            .summary-row { gap: 0.5rem; }
            .summary-pill { min-width: 100px; }
            .room-table th, .room-table td { padding: 0.5rem 0.6rem; }
            .timeline { max-height: 55vh; }
        }
    </style>
</head>

<body class="contrast-bg">
    <?php include '../../php/includes/admin-topbar.php'; ?>

    <!-- ═══ MAIN CONTENT ═══ -->
    <div class="child-container">
        <div class="reports-layout">

            <!-- Summary pills -->
            <div class="summary-row">
                <div class="summary-pill">
                    <i class="bi bi-journal-text"></i>
                    <div>
                        <div class="pill-val"><?= count($activity_logs) ?></div>
                        <div class="pill-label">Total<br>Log Entries</div>
                    </div>
                </div>
                <div class="summary-pill">
                    <i class="bi bi-building"></i>
                    <div>
                        <div class="pill-val"><?= count($rooms) ?></div>
                        <div class="pill-label">Tracked<br>Rooms</div>
                    </div>
                </div>
                <div class="summary-pill">
                    <i class="bi bi-lightbulb-fill" style="color:#198754;"></i>
                    <div>
                        <div class="pill-val"><?= count(array_filter($rooms, fn($r) => $r['light_status'] === 'on')) ?></div>
                        <div class="pill-label">Lights<br>Currently On</div>
                    </div>
                </div>
                <div class="summary-pill">
                    <i class="bi bi-exclamation-triangle-fill" style="color:#dc3545;"></i>
                    <div>
                        <div class="pill-val"><?= count(array_filter($activity_logs, fn($l) => str_contains(strtolower($l['action']), 'issue'))) ?></div>
                        <div class="pill-label">Issues<br>Logged</div>
                    </div>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="tab-nav" id="tabNav">
                <button class="tab-btn active" data-tab="activity">
                    <i class="bi bi-clock-history me-1"></i> Recent Activity
                </button>
                <button class="tab-btn" data-tab="rooms">
                    <i class="bi bi-building me-1"></i> Room Activity
                </button>
            </div>

            <!-- ══ TAB: Activity Log ══ -->
            <div class="tab-panel active" id="tab-activity">
                <div class="reports-card">
                    <div class="reports-card-header">
                        <h2><i class="bi bi-clock-history"></i> Activity Log</h2>
                        <div class="filter-bar">
                            <input type="text" id="activitySearch" placeholder="Filter by room or actor…" style="width:180px;">
                            <select id="activityType">
                                <option value="">All Types</option>
                                <option value="room">Room Events</option>
                                <option value="admin">Admin Actions</option>
                            </select>
                            <select id="activityDate">
                                <option value="">All Dates</option>
                                <option value="today">Today</option>
                                <option value="week">This Week</option>
                                <option value="month">This Month</option>
                            </select>
                            <button class="export-btn" onclick="exportCSV()">
                                <i class="bi bi-download"></i> Export CSV
                            </button>
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
                                    $typeBg  = $isRoom  ? '#cfe2ff' : '#ede6f2';
                                    $typeClr = $isRoom  ? '#084298' : '#4a0078';
                                    $typeLabel = $isRoom ? 'Room' : 'Admin';
                                    $logDate = strtotime($log['log_time']);
                                    $dateStr = date('M j, Y', $logDate);
                                    $timeStr = date('g:i A', $logDate);
                                ?>
                                <div class="timeline-item"
                                    data-type="<?= $log['log_type'] ?>"
                                    data-date="<?= date('Y-m-d', $logDate) ?>"
                                    data-search="<?= strtolower(htmlspecialchars($log['target'] . ' ' . $log['actor'] . ' ' . $log['action'])) ?>">
                                    <div class="tl-icon" style="background:<?= $iconBg ?>; color:<?= $iconColor ?>;">
                                        <i class="bi <?= $icon ?>"></i>
                                    </div>
                                    <div class="tl-body">
                                        <p class="tl-action">
                                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $log['action']))) ?>
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
                </div>
            </div>

            <!-- ══ TAB: Room Activity ══ -->
            <div class="tab-panel" id="tab-rooms">
                <div class="reports-card">
                    <div class="reports-card-header">
                        <h2><i class="bi bi-building"></i> Room Activity Summary</h2>
                        <div class="filter-bar">
                            <input type="text" id="roomSearch" placeholder="Search rooms…" style="width:180px;">
                            <select id="roomLightFilter">
                                <option value="">All Lights</option>
                                <option value="on">Lights On</option>
                                <option value="off">Lights Off</option>
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
                                ?>
                                <tr data-light="<?= $room['light_status'] ?>"
                                    data-search="<?= strtolower(htmlspecialchars($room['room_name'] . ' ' . $room['description'])) ?>">
                                    <td>
                                        <div style="font-weight:600;"><?= htmlspecialchars($room['room_name']) ?></div>
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
                                        <?= htmlspecialchars($room['description'] ?? '—') ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /reports-layout -->


        <?php include '../../php/includes/admin-sidebar.php'; ?>
        <?php include '../../php/includes/profile-offcanvas.php'; ?>

    </div><!-- /child-container -->

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>

   <script>
document.addEventListener('DOMContentLoaded', function () {

    /* ── Tab switching ── */
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
        });
    });

    /* ── Deep-link: ?tab=activity or ?tab=rooms ── */
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam  = urlParams.get('tab');
    if (tabParam) {
        const target = document.querySelector(`.tab-btn[data-tab="${tabParam}"]`);
        if (target) target.click();
    }

    /* ── Global topbar search ── */
    const globalSearch = document.getElementById('globalSearch');
    if (globalSearch) {
        globalSearch.addEventListener('input', function () {
            const active = document.querySelector('.tab-btn.active').dataset.tab;
            if (active === 'activity') {
                document.getElementById('activitySearch').value = this.value;
                filterActivity();
            } else {
                document.getElementById('roomSearch').value = this.value;
                filterRooms();
            }
        });
    }

    /* ── Activity Log filters ── */
    function filterActivity() {
        const q        = document.getElementById('activitySearch').value.toLowerCase();
        const type     = document.getElementById('activityType').value;
        const date     = document.getElementById('activityDate').value;
        const today    = new Date().toISOString().slice(0, 10);
        const weekAgo  = new Date(Date.now() - 7  * 86400000).toISOString().slice(0, 10);
        const monthAgo = new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10);

        document.querySelectorAll('#activityTimeline .timeline-item').forEach(item => {
            const matchQ    = !q    || item.dataset.search.includes(q);
            const matchType = !type || item.dataset.type === type;
            let   matchDate = true;
            if (date === 'today') matchDate = item.dataset.date === today;
            if (date === 'week')  matchDate = item.dataset.date >= weekAgo;
            if (date === 'month') matchDate = item.dataset.date >= monthAgo;
            item.style.display = (matchQ && matchType && matchDate) ? '' : 'none';
        });
    }

    /* ── Room filters ── */
    function filterRooms() {
        const q     = document.getElementById('roomSearch').value.toLowerCase();
        const light = document.getElementById('roomLightFilter').value;
        document.querySelectorAll('#roomTable tbody tr').forEach(row => {
            const matchQ     = !q     || row.dataset.search.includes(q);
            const matchLight = !light || row.dataset.light === light;
            row.style.display = (matchQ && matchLight) ? '' : 'none';
        });
    }

    /* ── Attach listeners ── */
    document.getElementById('activitySearch').addEventListener('input',  filterActivity);
    document.getElementById('activityType').addEventListener('change',   filterActivity);
    document.getElementById('activityDate').addEventListener('change',   filterActivity);
    document.getElementById('roomSearch').addEventListener('input',      filterRooms);
    document.getElementById('roomLightFilter').addEventListener('change',filterRooms);

    /* ── CSV export ── */
    window.exportCSV = function () {
        const rows = [['Time', 'Action', 'Target', 'Actor', 'Type', 'Notes']];
        document.querySelectorAll('#activityTimeline .timeline-item').forEach(item => {
            if (item.style.display === 'none') return;
            const tl_action = item.querySelector('.tl-action')?.innerText.trim() ?? '';
            const tl_meta   = [...item.querySelectorAll('.tl-meta span')].map(s => s.innerText.trim()).join(' | ');
            const tl_notes  = item.querySelector('.tl-notes')?.innerText.trim() ?? '';
            rows.push([tl_meta, tl_action, '', '', item.dataset.type, tl_notes]);
        });
        const csv  = rows.map(r => r.map(c => `"${c.replace(/"/g, '""')}"`).join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
        const a    = document.createElement('a');
        a.href     = URL.createObjectURL(blob);
        a.download = `activity-log-${new Date().toISOString().slice(0, 10)}.csv`;
        a.click();
    };

});
</script>
</body>
</html>