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
    <link rel="stylesheet" href="../../css/containers.css">

    <style>
        :root {
            --primary-color: #f9edfa;
            --secondary-color-1: #2f004f;
            --secondary-color-2: #58078f;
            --secondary-color-3: #790faf;
            --secondary-color-4: #9b00e9;
            --muted: #9f9f9f;
            --font-primary: 'Poppins', sans-serif;
        }

        .stat-row {
            display: flex;
            flex-direction: row;
            gap: 0.75rem;
            width: 100%;
        }

        .stat-card {
            flex: 1 1 0;
            display: flex;
            align-items: center;
            gap: 0.9rem;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
        }

        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
            color: var(--secondary-color-1);
        }

        .stat-card .stat-label {
            font-size: 0.72rem;
            color: var(--muted);
            margin: 0;
            line-height: 1.3;
        }

        .room-list {
            max-height: 38vh;
            overflow-y: auto;
            padding-right: 0.25rem;
        }

        .room-item {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.5rem 0.25rem;
            border-bottom: 1px solid #eee;
        }

        .room-item:last-child {
            border-bottom: none;
        }

        .room-icon {
            font-size: 1.8rem;
            color: var(--secondary-color-2);
            flex-shrink: 0;
        }

        .room-info {
            flex: 1;
            min-width: 0;
        }

        .room-info h5 {
            margin: 0;
            font-size: 15px;
            font-weight: 600;
        }

        .room-info p {
            margin: 0;
            font-size: 11px;
            color: var(--muted);
        }

        /* nav-btn sidebar style from HTML version */
        .nav-btn {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--secondary-color-1);
            color: var(--primary-color);
            border: none;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.15s;
        }

        .nav-btn i,
        .nav-btn svg {
            font-size: 22px;
        }

        .nav-btn:hover {
            background-color: var(--secondary-color-4);
            transform: scale(1.06);
        }

        #sidebarOffcanvas {
            width: 100px !important;
            background-color: var(--primary-color);
        }

        #sidebarOffcanvas .offcanvas-header {
            justify-content: center;
            padding: 1rem 0.5rem;
        }

        #sidebarOffcanvas .logo {
            width: 75px;
            height: 75px;
            object-fit: contain;
            cursor: pointer;
        }

        #sidebarOffcanvas .offcanvas-body {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding-top: 0.5rem;
        }

        #sidebarOffcanvas .offcanvas-footer {
            display: flex;
            justify-content: center;
            padding: 1rem;
        }

        #profileOffcanvas {
            width: 240px !important;
            background-color: var(--primary-color);
        }

        .profile-btn {
            width: 100%;
            padding: 8px;
            margin: 3px 0;
            border-radius: 8px;
            background-color: var(--secondary-color-1);
            color: var(--primary-color);
            border: none;
            font-size: 14px;
            cursor: pointer;
            font-family: var(--font-primary);
            transition: background-color 0.2s, transform 0.15s;
        }

        .profile-btn:hover {
            background-color: var(--secondary-color-4);
            transform: scale(1.02);
        }

        .badge-pending {
            background: #fff3cd;
            color: #856404;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
        }

        .badge-verified {
            background: #d1e7dd;
            color: #0f5132;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
        }

        .ext-badge {
            background: #cfe2ff;
            color: #084298;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
        }
    </style>
</head>

<body class="contrast-bg">
    <?php include '../../php/includes/admin-topbar.php'; ?>

    <div class="parent-container">
        <div class="child-container">
            <div class="main-container homepage gap-3">

                <!-- LEFT COLUMN -->
                <div class="group-container gap-3">

                    <!-- Stat cards -->
                    <div style="background-color:#f8f9fa;" class="section-container">
                        <div class="stat-row">
                            <div class="stat-card">
                                <span class="stat-icon"><img src="../../images/room.png" alt="Rooms" style="width:2rem;"></span>
                                <div>
                                    <div class="stat-value"><?= $total_rooms ?></div>
                                    <p class="stat-label">Total<br>Rooms</p>
                                </div>
                            </div>
                            <div class="stat-card">
                                <span class="stat-icon"><img src="../../images/bulb.png" alt="Lights" style="width:2rem;"></span>
                                <div>
                                    <div class="stat-value"><?= $lights_on ?></div>
                                    <p class="stat-label">Rooms Currently<br>Running</p>
                                </div>
                            </div>
                            <div class="stat-card">
                                <span class="stat-icon"><img src="../../images/alert.png" alt="Pending" style="width:2rem;"></span>
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
                                <?php else: foreach ($classrooms as $c):
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

                <!-- RIGHT COLUMN -->
                <div class="group-container gap-3">

                    <!-- Alerts / Recent logs -->
                    <div style="background-color:#f8f9fa;" class="section-container recents">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Recent Activity</h2>
                            </div>
                            <div class="d-flex mx-2 align-items-end">
                                <button class="light mx-2" onclick="dissolve('admin-reports.php?tab=activity')">Details</button>
                            </div>
                        </div>
                        <div class="gap-2">
                            <div class="activity-list px-2 gap-2 align-items-center max-width">
                                <?php if (empty($logs)): ?>
                                    <p class="text-muted">No recent activity.</p>
                                <?php else: ?>
                                    <?php foreach ($logs as $log): ?>
                                        <div class="d-flex justify-content-between align-items-start py-1">
                                            <div>
                                                <h5 class="mb-0" style="font-size:13px;">
                                                    <?php if ($log['log_type'] === 'faculty'): ?>
                                                        <i class="bi bi-person-check text-success me-1"></i>
                                                        Faculty Approved – <?= htmlspecialchars($log['room_name']) ?>
                                                    <?php else: ?>
                                                        <?= ucfirst(str_replace('_', ' ', $log['event_type'])) ?>
                                                        – <?= htmlspecialchars($log['room_name']) ?>
                                                    <?php endif; ?>
                                                </h5>
                                                <p class="mb-0" style="font-size:11px; color:var(--muted);">
                                                    <?= date('g:i A', strtotime($log['event_time'])) ?> · <?= date('M j', strtotime($log['event_time'])) ?>
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
                    <div style="background-color:#f8f9fa;" class="section-container">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">System Status</h2>
                            </div>
                        </div>
                        <div class="activity-list px-2 gap-2 max-width">
                            <?php
                            $statuses = [
                                ['label' => 'Server',         'ok' => $db_ok,       'ok_text' => 'Connected',    'fail_text' => 'Disconnected'],
                                ['label' => 'Database',        'ok' => $db_ok,       'ok_text' => 'Connected',    'fail_text' => 'Error'],
                                ['label' => 'Lighting System', 'ok' => ($lights_on > 0), 'ok_text' => $lights_on . ' room(s) active', 'fail_text' => 'No active lights'],
                                ['label' => 'Sensor Reading',  'ok' => ($lights_data > 0), 'ok_text' => 'Receiving data', 'fail_text' => 'No data today'],
                                ['label' => 'Webcam',          'ok' => false,        'ok_text' => 'Active',       'fail_text' => 'Disabled'],
                            ];
                            foreach ($statuses as $s):
                            ?>
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

                </div><!-- /RIGHT COLUMN -->
                <?php include '../../php/includes/admin-sidebar.php'; ?>
                <?php include '../../php/includes/profile-offcanvas.php'; ?>

            </div>
        </div>
    </div>

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
    <script>
        // Live uptime counter (counts from page load — resets on refresh)
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
    </script>
</body>

</html>