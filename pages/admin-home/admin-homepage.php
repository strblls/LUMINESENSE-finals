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

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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

                </div><!-- /LEFT COLUMN -->

                <!-- ─── CENTER COLUMN ────────────────────────────────── -->
                <div class="group-container gap-3">

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
                            <div class="activity-list admin px-2 gap-2 align-items-center max-width">
                                <?php if (empty($logs)): ?>
                                    <p class="text-muted">No recent activity.</p>
                                <?php else: ?>
                                    <?php foreach ($logs as $log): ?>
                                        <div class="d-flex justify-content-between align-items-start py-1">
                                            <div>
                                                <h5 class="mb-0" style="font-size:13px;">
                                                    <?php if ($log['log_type'] === 'admin'): ?>
                                                        <?php $icon = match($log['event_type']) {
                                                            'faculty_approved'   => '<i class="bi bi-person-check text-success me-1"></i>',
                                                            'faculty_rejected'   => '<i class="bi bi-person-x text-danger me-1"></i>',
                                                            'extension_approved' => '<i class="bi bi-clock-history text-primary me-1"></i>',
                                                            'extension_rejected' => '<i class="bi bi-clock text-danger me-1"></i>',
                                                            default              => '<i class="bi bi-shield text-secondary me-1"></i>'
                                                        }; echo $icon; ?>
                                                        <?= ucfirst(str_replace('_', ' ', $log['event_type'])) ?>
                                                        – <?= htmlspecialchars($log['room_name']) ?>
                                                        <?php if (!empty($log['admin_name'])): ?>
                                                            <span class="text-muted" style="font-size:11px;">by <?= htmlspecialchars($log['admin_name']) ?></span>
                                                        <?php endif; ?>
                                                    <?php elseif ($log['log_type'] === 'admin_login'): ?>
                                                        <i class="bi bi-box-arrow-in-right text-info me-1"></i>
                                                        Admin Login – <?= htmlspecialchars($log['admin_name']) ?>
                                                    <?php else: ?>
                                                        <?php $icon = match($log['event_type']) {
                                                            'on'             => '<i class="bi bi-lightbulb-fill text-warning me-1"></i>',
                                                            'off'            => '<i class="bi bi-lightbulb text-secondary me-1"></i>',
                                                            'gesture'        => '<i class="bi bi-hand-index text-primary me-1"></i>',
                                                            'schedule'       => '<i class="bi bi-calendar-check text-success me-1"></i>',
                                                            'security_alert' => '<i class="bi bi-exclamation-triangle text-danger me-1"></i>',
                                                            default          => '<i class="bi bi-activity me-1"></i>'
                                                        }; echo $icon; ?>
                                                        <?= ucfirst(str_replace('_', ' ', $log['event_type'])) ?>
                                                        – <?= htmlspecialchars($log['room_name']) ?>
                                                    <?php endif; ?>
                                                </h5>
                                                <p class="mb-0" style="font-size:11px; color:var(--muted);">
                                                    <?= date('g:i A', strtotime($log['event_time'])) ?> ·
                                                    <?= date('M j', strtotime($log['event_time'])) ?>
                                                    <?php if (!empty($log['triggered_by'])): ?>
                                                        · <?= htmlspecialchars($log['triggered_by']) ?>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                        <hr class="my-1">
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
                        <div class="activity-list px-2 gap-2 max-width">
                            <?php
                            $statuses = [
                                ['label' => 'Server',         'ok' => $db_ok,            'ok_text' => 'Connected',           'fail_text' => 'Disconnected'],
                                ['label' => 'Database',       'ok' => $db_ok,            'ok_text' => 'Connected',           'fail_text' => 'Error'],
                                ['label' => 'Lighting System','ok' => ($lights_on > 0),  'ok_text' => $lights_on.' room(s) active', 'fail_text' => 'No active lights'],
                                ['label' => 'Sensor Reading', 'ok' => ($lights_data > 0),'ok_text' => 'Receiving data',      'fail_text' => 'No data today'],
                                ['label' => 'Webcam',         'ok' => false,             'ok_text' => 'Active',              'fail_text' => 'Disabled'],
                            ];
                            foreach ($statuses as $s): ?>
                                <div class="d-flex justify-content-between align-items-center py-1" style="border-bottom:1px solid #eee;">
                                    <h5 class="mb-0" style="font-size:13px;"><?= $s['label'] ?></h5>
                                    <span style="font-size:11px; padding:2px 10px; border-radius:20px; font-weight:600;
                                        background:<?= $s['ok'] ? '#d1e7dd' : '#f8d7da' ?>;
                                        color:<?= $s['ok'] ? '#0f5132' : '#842029' ?>;">
                                        <?= $s['ok'] ? $s['ok_text'] : $s['fail_text'] ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                            <div class="d-flex justify-content-between align-items-center py-1">
                                <h5 class="mb-0" style="font-size:13px;">System Uptime</h5>
                                <span style="font-size:11px; color:var(--muted);" id="uptime-display">Calculating...</span>
                            </div>
                        </div>
                    </div>

                </div><!-- /CENTER COLUMN -->

                <!-- ─── RIGHT COLUMN ─────────────────────────────────── -->
                <div class="group-container gap-3">

                    <!-- Mini Calendar -->
                    <div style="background-color:#f8f9fa;" class="section-container">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Schedule</h2>
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
        const DAYS_ENUM = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];

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
            const header  = document.getElementById('cal-popover-header');
            const body    = document.getElementById('cal-popover-body');

            const schedules = SCHEDULES[dayName] || [];
            header.textContent = `${dayName} — ${MONTHS[calDate.getMonth()]} ${day}`;

            if (schedules.length === 0) {
                body.innerHTML = '<p class="cal-no-sched">No schedules for this day.</p>';
            } else {
                body.innerHTML = schedules.map(s => `
                    <div class="cal-sched-item">
                        <div class="cal-sched-room">${s.room_name}</div>
                        <div class="cal-sched-time">
                            ${s.start_time.slice(0,5)} – ${s.extended_until
                                ? s.extended_until.slice(0,5) + ' <span class="ext-badge">extended</span>'
                                : s.end_time.slice(0,5)}
                        </div>
                        <div class="cal-sched-faculty">${s.first_name ? s.first_name + ' ' + s.last_name : 'No faculty assigned'}</div>
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

        // ── Admin Dashboard Auto-refresh (every 5s) ───────────────────────────────
async function pollAdminDashboard() {
    try {
        const res = await fetch('../../api/admin-status.php');
        if (!res.ok) return;
        const data = await res.json();
        if (!data.success) return;

        // ── Update stat cards ──────────────────────────────────────────
        const pendingEl = document.querySelector('.stat-card:nth-child(3) .stat-value');
        const extEl     = document.querySelector('.stat-card:nth-child(4) .stat-value');
        if (pendingEl) pendingEl.textContent = data.pending;
        if (extEl)     extEl.textContent     = data.ext_pending;

        // ── Update rooms list ──────────────────────────────────────────
        const roomList = document.querySelector('.room-list');
        if (roomList && data.classrooms) {
            roomList.innerHTML = data.classrooms.map(c => {
                const on = c.light_status === 'on';
                return `
                    <div class="room-item">
                        <i class="bi bi-building room-icon"></i>
                        <div class="room-info">
                            <div class="d-flex align-items-center gap-2">
                                <h5 class="mb-0">${c.room_name}</h5>
                                <span style="font-size:10px; padding:2px 8px; border-radius:20px; font-weight:600;
                                    background:${on ? '#d1e7dd' : '#f8d7da'};
                                    color:${on ? '#0f5132' : '#842029'};">
                                    ${on ? 'ON' : 'OFF'}
                                </span>
                            </div>
                            <p class="mb-0" style="font-size:11px; color:var(--muted);">
                                ${c.room_size.charAt(0).toUpperCase() + c.room_size.slice(1)} room
                            </p>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // ── Update recent activity ─────────────────────────────────────
        const activityList = document.querySelector('.activity-list.admin');
        if (activityList && data.logs) {
            activityList.innerHTML = data.logs.map(log => {
                let icon = '';
                let label = '';

                if (log.log_type === 'admin') {
                    icon = log.event_type === 'faculty_approved'   ? '<i class="bi bi-person-check text-success me-1"></i>' :
                           log.event_type === 'faculty_rejected'   ? '<i class="bi bi-person-x text-danger me-1"></i>' :
                           log.event_type === 'extension_approved' ? '<i class="bi bi-clock-history text-primary me-1"></i>' :
                           log.event_type === 'extension_rejected' ? '<i class="bi bi-clock text-danger me-1"></i>' :
                                                                     '<i class="bi bi-shield text-secondary me-1"></i>';
                    label = log.event_type.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
                    label += ` – ${log.room_name}`;
                    if (log.admin_name) label += ` <span class="text-muted" style="font-size:11px;">by ${log.admin_name}</span>`;

                } else if (log.log_type === 'admin_login') {
                    icon  = '<i class="bi bi-box-arrow-in-right text-info me-1"></i>';
                    label = `Admin Login – ${log.admin_name}`;

                } else {
                    icon = log.event_type === 'on'             ? '<i class="bi bi-lightbulb-fill text-warning me-1"></i>' :
                           log.event_type === 'off'            ? '<i class="bi bi-lightbulb text-secondary me-1"></i>' :
                           log.event_type === 'gesture'        ? '<i class="bi bi-hand-index text-primary me-1"></i>' :
                           log.event_type === 'schedule'       ? '<i class="bi bi-calendar-check text-success me-1"></i>' :
                           log.event_type === 'security_alert' ? '<i class="bi bi-exclamation-triangle text-danger me-1"></i>' :
                                                                 '<i class="bi bi-activity me-1"></i>';
                    label = log.event_type.charAt(0).toUpperCase() + log.event_type.slice(1);
                    label += ` – ${log.room_name}`;
                }

                const time = new Date(log.event_time.replace(' ', 'T'));
                const timeStr = time.toLocaleString('en-US', {
                    hour: 'numeric', minute: '2-digit', hour12: true,
                    month: 'short', day: 'numeric'
                });

                return `
                    <div class="d-flex justify-content-between align-items-start py-1">
                        <div>
                            <h5 class="mb-0" style="font-size:13px;">${icon}${label}</h5>
                            <p class="mb-0" style="font-size:11px; color:var(--muted);">
                                ${timeStr}
                                ${log.triggered_by ? '· ' + log.triggered_by : ''}
                            </p>
                        </div>
                    </div>
                    <hr class="my-1">
                `;
            }).join('');
        }

    } catch(e) {
        console.warn('pollAdminDashboard error:', e);
    }
}

pollAdminDashboard();
setInterval(pollAdminDashboard, 5000);
    </script>
</body>

</html>