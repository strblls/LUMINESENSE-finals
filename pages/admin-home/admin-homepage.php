<?php
$page_title = 'Dashboard';
require_once '../../php/includes/admin-head.php';

/** @var int $total_rooms */
/** @var int $lights_on */
/** @var int $pending */
/** @var int $ext_pending */
/** @var bool $db_ok */
/** @var int $lights_data */
/** @var array $logs */
/** @var array $approval_logs */
/** @var array $classrooms */
/** @var string $schedules_json */
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard – LumineSense</title>

    <!--External links-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!--Relative links-->
    <link rel="icon" href="../../images/logo.png">
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css?v=<?= time() ?>">
    <link rel="stylesheet" href="../../css/admin-home.css?v=<?= time() ?>">
</head>

<body class="contrast-bg">
    <?php include '../../php/includes/admin-topbar.php'; ?>
    <?php include '../../php/includes/admin-sidebar.php'; ?>
    <?php include '../../php/includes/profile-offcanvas.php'; ?>

    <div class="parent-container">
        <div class="child-container">
            <div class="main-container admin gap-3">

                <!-- ─── LEFT COLUMN ─────────────────────────────────── -->
                <div class="group-container gap-3">

                    <!-- Stat cards -->
                    <div style="background-color:#f8f9fa;" class="section-container">
                        <div class="stat-row">
                            <div class="stat-card">
                                <span class="stat-icon"><i class="bi bi-door-open" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                <div>
                                    <div class="stat-value"><?= $total_rooms ?></div>
                                    <p class="stat-label">Total<br>Rooms</p>
                                </div>
                            </div>
                            <div class="stat-card">
                                <span class="stat-icon"><i class="bi bi-lightbulb-fill" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                <div>
                                    <div class="stat-value"><?= $lights_on ?></div>
                                    <p class="stat-label">Rooms Currently<br>Running</p>
                                </div>
                            </div>
                            <div class="stat-card">
                                <span class="stat-icon"><i class="bi bi-person-check" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                <div>
                                    <div class="stat-value"><?= $pending ?></div>
                                    <p class="stat-label">Faculty Pending<br>Approval</p>
                                </div>
                            </div>
                            <div class="stat-card">
                                <span class="stat-icon"><i class="bi bi-clock-history" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                <div>
                                    <div class="stat-value"><?= $ext_pending ?></div>
                                    <p class="stat-label">Extension<br>Requests</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div style="background-color:#f8f9fa;" class="section-container recents">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Recent Activity</h2>
                            </div>
                            <div class="d-flex mx-2 align-items-end">
                                <button class="light mx-2" onclick="dissolve('admin-reports.php?tab=activity')">Details</button>
                            </div>
                        </div>
                        <div style="overflow:visible; flex:1;">
                            <div class="activity-list admin px-2 max-width" id="activityTimeline">
                                <?php if (empty($logs)): ?>
                                    <p class="text-muted">No recent activity.</p>
                                <?php else: ?>
                                    <?php foreach ($logs as $log):
                                        $iconData = activity_icon($log);
                                    ?>
                                    <div class="timeline-item">
                                        <div class="tl-icon" style="background:<?= $iconData['bg'] ?>; color:<?= $iconData['color'] ?>;">
                                            <i class="bi <?= $iconData['icon'] ?>"></i>
                                        </div>
                                        <div class="tl-body">
                                            <p class="tl-action">
                                                <?= htmlspecialchars($iconData['label']) ?>
                                                <?php if (!empty($log['room_name'])): ?>
                                                    &mdash; <span style="color:var(--secondary-color-3);"><?= htmlspecialchars($log['room_name']) ?></span>
                                                <?php endif; ?>
                                            </p>
                                            <div class="tl-meta" style="flex-wrap: wrap; row-gap: 2px;">
                                                <span><i class="bi bi-clock"></i> <?= date('g:i A', strtotime($log['event_time'])) ?>, <?= date('M j', strtotime($log['event_time'])) ?></span>
                                                <?php if (!empty($log['admin_name'])): ?>
                                                    <span><i class="bi bi-person"></i> <?= htmlspecialchars($log['admin_name']) ?></span>
                                                <?php elseif (!empty($log['triggered_by'])): ?>
                                                    <span><i class="bi bi-person"></i> <?= htmlspecialchars($log['triggered_by']) ?></span>
                                                <?php endif; ?>
                                                <span class="tl-type-badge" style="background:<?= $iconData['typeBg'] ?>; color:<?= $iconData['typeClr'] ?>;"><?= $iconData['typeLabel'] ?></span>
                                            </div>
                                            <?php if (!empty($iconData['notes'])): ?>
                                                <span class="tl-notes"><i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($iconData['notes']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- System Status -->
                    <div style="background-color:#f8f9fa;" class="section-container system-status">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">System Status</h2>
                            </div>
                        </div>
                        <div class="activity-list system-status px-2 gap-2 max-width">
                            <?php
                            $statuses = [
                                ['label' => 'Server',         'ok' => $db_ok,            'ok_text' => 'Connected',           'fail_text' => 'Disconnected'],
                                ['label' => 'Database',       'ok' => $db_ok,            'ok_text' => 'Connected',           'fail_text' => 'Error'],
                                ['label' => 'Lighting System', 'ok' => ($lights_on > 0),  'ok_text' => $lights_on . ' room(s) active', 'fail_text' => 'No active lights'],
                                ['label' => 'Sensor Reading', 'ok' => ($lights_data > 0), 'ok_text' => 'Receiving data',      'fail_text' => 'No data today'],
                                ['label' => 'Webcam',         'ok' => false,             'ok_text' => 'Active',              'fail_text' => 'Disabled'],
                            ];
                            foreach ($statuses as $s): ?>
                                <div class="d-flex justify-content-between align-items-center py-1" style="border-bottom:1px solid #eee;">
                                    <h5 class="mb-0" style="font-size:13px;"><?= $s['label'] ?></h5>
                                    <span style="font-size:12px; padding:2px 10px; border-radius:20px; font-weight:600;
                                        background:<?= $s['ok'] ? '#d1e7dd' : '#f8d7da' ?>;
                                        color:<?= $s['ok'] ? '#0f5132' : '#842029' ?>;">
                                        <?= $s['ok'] ? $s['ok_text'] : $s['fail_text'] ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                            <div class="d-flex justify-content-between align-items-center py-1">
                                <h5 class="mb-0" style="font-size:13px;">System Uptime</h5>
                                <span style="font-size:13px; color:var(--muted-dark);" id="uptime-display">Calculating...</span>
                            </div>
                        </div>
                    </div>
                </div><!-- /LEFT COLUMN -->

                <!-- ─── CENTER COLUMN ────────────────────────────────── -->
                <div class="group-container gap-3">

                    <!-- Faculty Hierarchy -->
                    <div style="background-color:#f8f9fa;" class="section-container recents">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Faculty Hierarchy</h2>
                            </div>
                            <div class="d-flex mx-2 align-items-end">
                                <button class="light mx-2" onclick="dissolve('admin-reports.php?tab=activity')">Details</button>
                            </div>
                        </div>
                        <div style="overflow:visible; flex:1;">
                            <!--Insert Faculty Hierarchy Tree API-->
                        </div>
                    </div>



                </div><!-- /CENTER COLUMN -->

                <!-- ─── RIGHT COLUMN ─────────────────────────────────── -->
                <div class="group-container gap-3">
                    <!-- Rooms list -->
                    <div style="background-color:#f8f9fa;" class="fit-width section-container">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Rooms</h2>
                            </div>
                            <div class="d-flex mx-2 align-items-end">
                                <button class="light mx-2" onclick="dissolve('admin-room-manage.php')">All Rooms</button>
                            </div>
                        </div>
                        <div class="room-list px-1 mt-1">
                            <?php if (empty($classrooms)): ?>
                                <p class="text-muted text-center mt-2">No classrooms yet.</p>
                                <?php else:
                                foreach ($classrooms as $c):
                                    $on = ($c['light_status'] === 'on'); ?>
                                    <div class="room-item">
                                        <i class="bi bi-building room-icon"></i>
                                        <div class="room-info">
                                            <div class="d-flex align-items-center gap-2">
                                                <h5 class="mb-0"><?= htmlspecialchars($c['room_name']) ?></h5>
                                                <span style="font-size:10px; padding:2px 8px; border-radius:20px; font-weight:600;
                                                    background:<?= $on ? '#d1e7dd' : '#f8d7da' ?>;
                                                    color:<?= $on ? '#0f5132' : '#842029' ?>;">
                                                    <?= $on ? 'ON' : 'OFF' ?>
                                                </span>
                                            </div>
                                            <p class="mb-0" style="font-size:11px; color:var(--muted);">
                                                <?= ucfirst($c['room_size']) ?> room
                                            </p>
                                            <?php if (!empty($c['description'])): ?>
                                                <p class="mb-0" style="font-size:10px; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:180px;">
                                                    <?= htmlspecialchars($c['description']) ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                            <?php endforeach;
                            endif; ?>
                        </div>
                    </div>
                    <!-- Mini Calendar -->
                    <div style="background-color:#f8f9fa;" class="section-container">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Calendar</h2>
                            </div>
                        </div>

                        <div class="mini-calendar">
                            <div class="cal-nav">
                                <button class="cal-nav-btn" id="cal-prev">&#8249;</button>
                                <span class="cal-month-label" id="cal-month-label"></span>
                                <button class="cal-nav-btn" id="cal-next">&#8250;</button>
                            </div>
                            <div class="cal-grid">
                                <div class="cal-dow">Sun</div>
                                <div class="cal-dow">Mon</div>
                                <div class="cal-dow">Tue</div>
                                <div class="cal-dow">Wed</div>
                                <div class="cal-dow">Thu</div>
                                <div class="cal-dow">Fri</div>
                                <div class="cal-dow">Sat</div>
                            </div>
                            <div class="cal-days" id="cal-days"></div>
                        </div>

                        <!-- Schedule popover -->
                        <div class="cal-popover" id="cal-popover">
                            <div class="cal-popover-header" id="cal-popover-header"></div>
                            <div class="cal-popover-body" id="cal-popover-body"></div>
                        </div>
                    </div>

                </div><!-- /RIGHT COLUMN -->

            </div>
        </div>
    </div>

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
    <script>
        // Uptime counter
        const start = Date.now();

        function updateUptime() {
            const s = Math.floor((Date.now() - start) / 1000);
            const h = String(Math.floor(s / 3600)).padStart(2, '0');
            const m = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
            const sec = String(s % 60).padStart(2, '0');
            const el = document.getElementById('uptime-display');
            if (el) el.textContent = `${h}:${m}:${sec}`;
        }
        setInterval(updateUptime, 1000);
        updateUptime();

        // Mini Calendar
        const SCHEDULES = <?= $schedules_json ?>;
        const DAYS_ENUM = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

        let calDate = new Date();

        function renderCalendar() {
            const year = calDate.getFullYear();
            const month = calDate.getMonth();
            const today = new Date();

            document.getElementById('cal-month-label').textContent = `${MONTHS[month]} ${year}`;

            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();

            const container = document.getElementById('cal-days');
            container.innerHTML = '';

            for (let i = 0; i < firstDay; i++) {
                const blank = document.createElement('div');
                blank.className = 'cal-day empty';
                container.appendChild(blank);
            }

            for (let d = 1; d <= daysInMonth; d++) {
                const cell = document.createElement('div');
                cell.className = 'cal-day';

                const dateObj = new Date(year, month, d);
                const dayName = DAYS_ENUM[dateObj.getDay()];
                const hasSchedule = SCHEDULES[dayName] && SCHEDULES[dayName].length > 0;

                if (hasSchedule) cell.classList.add('has-schedule');
                if (d === today.getDate() && month === today.getMonth() && year === today.getFullYear()) {
                    cell.classList.add('today');
                }

                cell.textContent = d;
                cell.addEventListener('click', () => showSchedule(d, dayName, cell));
                container.appendChild(cell);
            }
        }

        function showSchedule(day, dayName, cell) {
            const popover = document.getElementById('cal-popover');
            const header = document.getElementById('cal-popover-header');
            const body = document.getElementById('cal-popover-body');

            const schedules = SCHEDULES[dayName] || [];
            header.textContent = `${dayName} — ${MONTHS[calDate.getMonth()]} ${day}`;

            if (schedules.length === 0) {
                body.innerHTML = '<p class="cal-no-sched">No schedules for this day.</p>';
            } else {
                body.innerHTML = schedules.map(s => `
                    <div class="cal-sched-item">
                        <div class="cal-sched-room"><i class="bi bi-door-open"></i> <span>${s.room_name}</span></div>
                        <div class="cal-sched-time">
                            <i class="bi bi-clock"></i> Schedule: <span>${s.start_time.slice(0,5)} – ${s.extended_until
                                ? s.extended_until.slice(0,5) + ' <span class="ext-badge">extended</span>'
                                : s.end_time.slice(0,5)}</span>
                        </div>
                        <div class="cal-sched-faculty"><i class="bi bi-people"></i> Faculty: <span>${s.first_name ? s.first_name + ' ' + s.last_name : 'No faculty assigned'}</span></div>
                    </div>
                `).join('');
            }

            const isOpen = popover.classList.contains('open') && popover.dataset.day === String(day);
            document.querySelectorAll('.cal-day').forEach(c => c.classList.remove('selected'));
            if (isOpen) {
                popover.classList.remove('open');
                popover.dataset.day = '';
            } else {
                popover.classList.add('open');
                popover.dataset.day = day;
                cell.classList.add('selected');
            }
        }

        document.getElementById('cal-prev').addEventListener('click', () => {
            calDate.setMonth(calDate.getMonth() - 1);
            renderCalendar();
            document.getElementById('cal-popover').classList.remove('open');
        });
        document.getElementById('cal-next').addEventListener('click', () => {
            calDate.setMonth(calDate.getMonth() + 1);
            renderCalendar();
            document.getElementById('cal-popover').classList.remove('open');
        });

        renderCalendar();

        // ── JS mirror of PHP activity_icon() for live updates ────────────────────
        function getActivityIcon(log) {
            const evt = log.event_type || log.action || '';
            const type = log.log_type || 'room';

            const iconMap = {
                'on':             ['bi-lightbulb-fill',     '#198754', '#d1e7dd'],
                'off':            ['bi-lightbulb',           '#842029', '#f8d7da'],
                'light_on':       ['bi-lightbulb-fill',     '#198754', '#d1e7dd'],
                'light_off':      ['bi-lightbulb',           '#842029', '#f8d7da'],
                'motion_detect':  ['bi-person-bounding-box', '#084298', '#cfe2ff'],
                'gesture':        ['bi-hand-index',          '#084298', '#cfe2ff'],
                'schedule':       ['bi-calendar-check',     '#198754', '#d1e7dd'],
                'security_alert': ['bi-exclamation-triangle-fill', '#842029', '#f8d7da'],
                'class_start':    ['bi-play-circle-fill',   '#198754', '#d1e7dd'],
                'class_end':      ['bi-stop-circle',        '#664d03', '#fff3cd'],
                'door_open':      ['bi-door-open-fill',     '#664d03', '#fff3cd'],
                'door_close':     ['bi-door-closed-fill',   '#5a3a00', '#ffe5b4'],
                'faculty_approved':   ['bi-person-check-fill',  '#198754', '#d1e7dd'],
                'faculty_rejected':   ['bi-person-x-fill',      '#842029', '#f8d7da'],
                'faculty_pending':    ['bi-person-plus',        '#664d03', '#fff3cd'],
                'extension_approved': ['bi-clock-history',      '#084298', '#cfe2ff'],
                'extension_rejected': ['bi-clock-fill',         '#842029', '#f8d7da'],
                'admin_login':    ['bi-box-arrow-in-right',  '#055160', '#cff4fc'],
                'issue_raised':   ['bi-exclamation-triangle-fill', '#842029', '#f8d7da'],
                'issue_resolved': ['bi-check-circle-fill',   '#198754', '#d1e7dd'],
                'admin_action':   ['bi-shield-check',        '#084298', '#cfe2ff'],
            };
            const def = ['bi-clock-history', '#5a5a5a', '#e9ecef'];
            const [icon, color, bg] = iconMap[evt] || def;

            const typeMap = {
                'room':        ['#cfe2ff', '#084298', 'Room'],
                'admin':       ['#ede6f2', '#4a0078', 'Admin'],
                'admin_login': ['#cff4fc', '#055160', 'Login'],
            };
            const [typeBg, typeClr, typeLabel] = typeMap[type] || typeMap['room'];

            const label = evt.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

            return { icon, color, bg, label, typeBg, typeClr, typeLabel, notes: log.notes || '' };
        }

        function formatTime(timeStr) {
            const d = new Date(timeStr.replace(' ', 'T'));
            const hours = d.getHours(), mins = d.getMinutes();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            const h12 = hours % 12 || 12;
            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            return h12 + ':' + String(mins).padStart(2,'0') + ' ' + ampm + ', ' + months[d.getMonth()] + ' ' + d.getDate();
        }

        // ── Admin Dashboard Auto-refresh (every 5s) ───────────────────────────────
        async function pollAdminDashboard() {
            try {
                const res = await fetch('../../api/admin-status.php');
                if (!res.ok) return;
                const data = await res.json();
                if (!data.success) return;

                // ── Update stat cards ──────────────────────────────────────────
                const pendingEl = document.querySelector('.stat-card:nth-child(3) .stat-value');
                const extEl = document.querySelector('.stat-card:nth-child(4) .stat-value');
                if (pendingEl) pendingEl.textContent = data.pending;
                if (extEl) extEl.textContent = data.ext_pending;

                // ── Update rooms list ──────────────────────────────────────────
                const roomList = document.querySelector('.room-list');
                if (roomList && data.classrooms) {
                    roomList.innerHTML = data.classrooms.map(c => {
                        const on = c.light_status === 'on';
                        return `
                    <div class="room-item">
                        <i class="bi bi-door-open room-icon"></i>
                        <div class="room-info">
                            <div class="d-flex align-items-center gap-2">
                                <h5 class="mb-0" style="font-size: 14.5px;">${c.room_name}</h5>
                                <span style="font-size:10px; padding:2px 8px; border-radius:20px; font-weight:600;
                                    background:${on ? '#d1e7dd' : '#f8d7da'};
                                    color:${on ? '#0f5132' : '#842029'};">
                                    ${on ? 'ON' : 'OFF'}
                                </span>
                            </div>
                            <p class="room-size mb-0" style="font-size:13.5px; color:var(--muted-dark);">
                            Room size:
                                <span>${c.room_size.charAt(0).toUpperCase() + c.room_size.slice(1)}</span> room
                            </p>
                        </div>
                    </div>
                `;
                    }).join('');
                }

                // ── Update recent activity ─────────────────────────────────────
                const activityList = document.getElementById('activityTimeline');
                if (activityList && data.logs) {
                    activityList.innerHTML = data.logs.map(log => {
                        const iconData = getActivityIcon(log);
                        return `
                        <div class="timeline-item">
                            <div class="tl-icon" style="background:${iconData.bg}; color:${iconData.color};">
                                <i class="bi ${iconData.icon}"></i>
                            </div>
                            <div class="tl-body">
                                <p class="tl-action" style="font-size:14px; font-weight: 600;">
                                    ${iconData.label}
                                    ${log.room_name ? '&mdash; <span style="color:var(--secondary-color-3);">' + log.room_name + '</span>' : ''}
                                    <span class="tl-type-badge" style="background:${iconData.typeBg}; color:${iconData.typeClr};">${iconData.typeLabel}</span>
                                </p>
                                <div class="tl-meta" style="display: flex; flex-wrap: wrap; row-gap: 4px; width: 100%;">
                                    <span style="width: 100%;"><i class="bi bi-clock"></i> ${formatTime(log.event_time)}</span>
                                    ${log.admin_name ? '<span style="width: 100%; margin-top: 2px;"><i class="bi bi-person"></i> ' + log.admin_name + '</span>' : ''}
                                    ${(!log.admin_name && log.triggered_by) ? '<span style="width: 100%; margin-top: 2px;"><i class="bi bi-person"></i> ' + log.triggered_by + '</span>' : ''}
                                    
                                </div>
                                ${iconData.notes ? '<span class="tl-notes"><i class="bi bi-chat-left-text me-1"></i>' + iconData.notes + '</span>' : ''}
                            </div>
                        </div>`;
                    }).join('');
                }

            } catch (e) {
                console.warn('pollAdminDashboard error:', e);
            }
        }

        pollAdminDashboard();
        setInterval(pollAdminDashboard, 5000);
    </script>
</body>

</html>
