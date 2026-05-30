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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/admin-home.css">
</head>

<body class="contrast-bg">
    <?php include '../../php/includes/admin-topbar.php'; ?>

    <div class="parent-container">
        <div class="child-container">
            <div class="main-container admin gap-3">

                <!-- LEFT COLUMN -->
                <div class="group-container gap-3">

                    <!-- Stat cards -->
                    <div style="background-color:#f8f9fa;" class="section-container">
                        <div class="stat-row">
                            <div class="stat-card">
                                <span class="stat-icon"><i class="bi bi-door-open"
                                        style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                <div>
                                    <div class="stat-value"><?= $total_rooms ?></div>
                                    <p class="stat-label">Total<br>Rooms</p>
                                </div>
                            </div>
                            <div class="stat-card">
                                <span class="stat-icon"><i class="bi bi-lightbulb-fill"
                                        style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                <div>
                                    <div class="stat-value"><?= $lights_on ?></div>
                                    <p class="stat-label">Rooms Currently<br>Running</p>
                                </div>
                            </div>
                            <div class="stat-card">
                                <span class="stat-icon"><i class="bi bi-person-check"
                                        style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                <div>
                                    <div class="stat-value"><?= $pending ?></div>
                                    <p class="stat-label">Faculty Pending<br>Approval</p>
                                </div>
                            </div>
                            <div class="stat-card">
                                <span class="stat-icon"><i class="bi bi-clock-history"
                                        style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
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
                                <button class="light mx-2" onclick="dissolve('admin-room-manage.php')">All
                                    Rooms</button>
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
                                                <p class="mb-0"
                                                    style="font-size:10px; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:180px;">
                                                    <?= htmlspecialchars($c['description']) ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                            <?php endforeach;
                            endif; ?>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN -->
                <div class="group-container gap-3">

                    <!-- Alerts / Recent logs -->
                    <div style="background-color:#f8f9fa;" class="section-container recents">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Recent Activity</h2>
                            </div>
                            <div class="d-flex mx-2 align-items-end">
                                <button class="light mx-2"
                                    onclick="dissolve('admin-reports.php?tab=activity')">Details</button>
                            </div>
                        </div>
                        <div style="overflow:hidden; flex:1;">
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


                </div>
                <?php include '../../php/includes/admin-sidebar.php'; ?>
                <?php include '../../php/includes/profile-offcanvas.php'; ?>
                <div class="group-container gap-3">
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
                                ['label' => 'Server', 'ok' => $db_ok, 'ok_text' => 'Connected', 'fail_text' => 'Disconnected'],
                                ['label' => 'Database', 'ok' => $db_ok, 'ok_text' => 'Connected', 'fail_text' => 'Error'],
                                ['label' => 'Lighting System', 'ok' => ($lights_on > 0), 'ok_text' => $lights_on . ' room(s) active', 'fail_text' => 'No active lights'],
                                ['label' => 'Sensor Reading', 'ok' => ($lights_data > 0), 'ok_text' => 'Receiving data', 'fail_text' => 'No data today'],
                                ['label' => 'Webcam', 'ok' => false, 'ok_text' => 'Active', 'fail_text' => 'Disabled'],
                            ];
                            foreach ($statuses as $s):
                            ?>
                                <div class="d-flex justify-content-between align-items-center py-1"
                                    style="border-bottom:1px solid #eee;">
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
                                <span style="font-size:11px; color:var(--muted);"
                                    id="uptime-display">Calculating...</span>
                            </div>
                        </div>
                    </div>

                    <div></div>
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