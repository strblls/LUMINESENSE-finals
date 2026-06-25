<?php
$page_title = 'Report Management';
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
function event_icon(string $type): array
{
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

    <!--External links-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!--Relative links-->
    <link rel="icon" href="../../images/logo.png">
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">
    <link rel="stylesheet" href="../../css/admin-home-reports.css">
    <link rel="stylesheet" href="../../css/admin-common.css">
</head>

<body class="contrast-bg">
    <?php include '../../php/includes/admin-topbar.php'; ?>
    <?php include '../../php/includes/admin-sidebar.php'; ?>
    <?php include '../../php/includes/profile-offcanvas.php'; ?>

    <!-- ═══ MAIN CONTENT ═══ -->
    <div class="child-container">
        <div class="reports-layout">

            <div class="section-container">
                <div class="stat-row">
                    <div class="stat-card">
                        <span class="stat-icon"><i class="bi bi-journal-text" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                        <div>
                            <div class="stat-value"><?= count($activity_logs) ?></div>
                            <p class="stat-label">Total Log<br>Entries</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon"><i class="bi bi-door-open" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                        <div>
                            <div class="stat-value"><?= count($rooms) ?></div>
                            <p class="stat-label">Tracked<br>Rooms</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon"><i class="bi bi-lightbulb-fill" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                        <div>
                            <div class="stat-value"><?= count(array_filter($rooms, fn($r) => $r['light_status'] === 'on')) ?></div>
                            <p class="stat-label">Lights Currently<br>On</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon"><i class="bi bi-exclamation-triangle-fill" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                        <div>
                            <div class="stat-value"><?= count(array_filter($activity_logs, fn($l) => str_contains(strtolower($l['action']), 'issue'))) ?></div>
                            <p class="stat-label">Issues<br>Logged</p>
                        </div>
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
                        <h2 class="bold"><i class="bi bi-clock-history"></i> Activity Log</h2>
                        <div class="filter-bar">
                            <input type="text" id="activitySearch" placeholder="Search by room or actor…" style="width:180px;">
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
                            <button class="light" onclick="exportCSV()">
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
                        <h2><i class="bi bi-door-open"></i> Room Activity Summary</h2>
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
        document.addEventListener('DOMContentLoaded', function() {

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
            const tabParam = urlParams.get('tab');
            if (tabParam) {
                const target = document.querySelector(`.tab-btn[data-tab="${tabParam}"]`);
                if (target) target.click();
            }

            /* ── Global topbar search ── */
            const globalSearch = document.getElementById('globalSearch');
            if (globalSearch) {
                globalSearch.addEventListener('input', function() {
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
                const q = document.getElementById('activitySearch').value.toLowerCase();
                const type = document.getElementById('activityType').value;
                const date = document.getElementById('activityDate').value;
                const today = new Date().toISOString().slice(0, 10);
                const weekAgo = new Date(Date.now() - 7 * 86400000).toISOString().slice(0, 10);
                const monthAgo = new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10);

                document.querySelectorAll('#activityTimeline .timeline-item').forEach(item => {
                    const matchQ = !q || item.dataset.search.includes(q);
                    const matchType = !type || item.dataset.type === type;
                    let matchDate = true;
                    if (date === 'today') matchDate = item.dataset.date === today;
                    if (date === 'week') matchDate = item.dataset.date >= weekAgo;
                    if (date === 'month') matchDate = item.dataset.date >= monthAgo;
                    item.style.display = (matchQ && matchType && matchDate) ? '' : 'none';
                });
            }

            /* ── Room filters ── */
            function filterRooms() {
                const q = document.getElementById('roomSearch').value.toLowerCase();
                const light = document.getElementById('roomLightFilter').value;
                document.querySelectorAll('#roomTable tbody tr').forEach(row => {
                    const matchQ = !q || row.dataset.search.includes(q);
                    const matchLight = !light || row.dataset.light === light;
                    row.style.display = (matchQ && matchLight) ? '' : 'none';
                });
            }

            /* ── Attach listeners ── */
            document.getElementById('activitySearch').addEventListener('input', filterActivity);
            document.getElementById('activityType').addEventListener('change', filterActivity);
            document.getElementById('activityDate').addEventListener('change', filterActivity);
            document.getElementById('roomSearch').addEventListener('input', filterRooms);
            document.getElementById('roomLightFilter').addEventListener('change', filterRooms);

            /* ── CSV export ── */
            window.exportCSV = function() {
                const rows = [
                    ['Time', 'Action', 'Target', 'Actor', 'Type', 'Notes']
                ];
                document.querySelectorAll('#activityTimeline .timeline-item').forEach(item => {
                    if (item.style.display === 'none') return;
                    const tl_action = item.querySelector('.tl-action')?.innerText.trim() ?? '';
                    const tl_meta = [...item.querySelectorAll('.tl-meta span')].map(s => s.innerText.trim()).join(' | ');
                    const tl_notes = item.querySelector('.tl-notes')?.innerText.trim() ?? '';
                    rows.push([tl_meta, tl_action, '', '', item.dataset.type, tl_notes]);
                });
                const csv = rows.map(r => r.map(c => `"${c.replace(/"/g, '""')}"`).join(',')).join('\n');
                const blob = new Blob([csv], {
                    type: 'text/csv'
                });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = `activity-log-${new Date().toISOString().slice(0, 10)}.csv`;
                a.click();
            };

        });
    </script>
</body>

</html>