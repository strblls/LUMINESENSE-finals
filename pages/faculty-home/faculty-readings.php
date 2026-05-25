<?php
require_once '../../php/session_guard.php';
check_faculty();
require_once '../../php/db_connect.php';
require_once '../../php/includes/faculty-head.php';

// ── Occupancy logs (PIR sensor — faculty_id is NULL, triggered_by = sensor) ──
// Only from classrooms in THIS faculty's schedule
$occupancy_logs = [];
$stmt = $conn->prepare("
    SELECT l.event_type, l.triggered_by, l.event_time, c.room_name
    FROM lighting_logs l
    JOIN classrooms c ON c.id = l.classroom_id
    WHERE l.faculty_id IS NULL
      AND l.triggered_by = 'sensor'
      AND l.classroom_id IN (
          SELECT DISTINCT classroom_id FROM schedules
      )
    ORDER BY l.event_time DESC
    LIMIT 20
");
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $occupancy_logs[] = $row;
$stmt->close();

// ── Lighting logs (manual — this faculty only) ──
$lighting_logs = [];
$stmt = $conn->prepare("
    SELECT l.event_type, l.triggered_by, l.event_time, c.room_name
    FROM lighting_logs l
    JOIN classrooms c ON c.id = l.classroom_id
    WHERE l.faculty_id = ?
      AND l.triggered_by = 'manual'
    ORDER BY l.event_time DESC
    LIMIT 20
");
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $lighting_logs[] = $row;
$stmt->close();

// ── Gesture logs (this faculty only) ──
$gesture_logs = [];
$stmt = $conn->prepare("
    SELECT l.event_type, l.triggered_by, l.event_time, c.room_name
    FROM lighting_logs l
    JOIN classrooms c ON c.id = l.classroom_id
    WHERE l.faculty_id = ?
      AND l.triggered_by = 'gesture'
    ORDER BY l.event_time DESC
    LIMIT 20
");
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $gesture_logs[] = $row;
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <title>Sensor Readings – LumineSense</title>
</head>
<body class="contrast-bg">
<div class="parent-container">

    <?php include '../../php/includes/faculty-topbar.php'; ?>
    
    <div class="child-container">
        <div class="main-container" style="display:flex; flex-direction:row; gap:1.5rem; padding:2rem; width:100%;">

            <!-- OCCUPANCY -->
            <div style="flex:1; min-width:0;">
                <div style="background-color:#f8f9fa;" class="section-container recents">
                    <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                        <div class="d-flex mx-2 align-items-start">
                            <h2 class="bold">Occupancy</h2>
                        </div>
                    </div>
                    <div class="gap-2">
                        <div class="activity-list sensor-readings px-2 gap-2 align-items-center max-width">
                            <?php if (empty($occupancy_logs)): ?>
                                <p class="text-muted">No occupancy events yet.</p>
                            <?php else: foreach ($occupancy_logs as $log): ?>
                                <div>
                                    <h5><?= ucfirst($log['event_type']) ?> – <?= htmlspecialchars($log['room_name']) ?></h5>
                                    <p class="light mb-0"><?= date('g:i A', strtotime($log['event_time'])) ?> · <?= date('M j', strtotime($log['event_time'])) ?></p>
                                </div>
                                <hr>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- LIGHTING -->
            <div style="flex:1; min-width:0;">
                <div style="background-color:#f8f9fa;" class="section-container recents">
                    <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                        <div class="d-flex mx-2 align-items-start">
                            <h2 class="bold">Lighting</h2>
                        </div>
                    </div>
                    <div class="gap-2">
                        <div class="activity-list sensor-readings px-2 gap-2 align-items-center max-width">
                            <?php if (empty($lighting_logs)): ?>
                                <p class="text-muted">No manual lighting actions yet.</p>
                            <?php else: foreach ($lighting_logs as $log): ?>
                                <div>
                                    <h5><?= ucfirst($log['event_type']) ?> – <?= htmlspecialchars($log['room_name']) ?></h5>
                                    <p class="light mb-0"><?= date('g:i A', strtotime($log['event_time'])) ?> · <?= date('M j', strtotime($log['event_time'])) ?></p>
                                </div>
                                <hr>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- GESTURES -->
            <div style="flex:1; min-width:0;">
                <div style="background-color:#f8f9fa;" class="section-container recents">
                    <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                        <div class="d-flex mx-2 align-items-start">
                            <h2 class="bold">Gestures</h2>
                        </div>
                    </div>
                    <div class="gap-2">
                        <div class="activity-list sensor-readings px-2 gap-2 align-items-center max-width">
                            <?php if (empty($gesture_logs)): ?>
                                <p class="text-muted">No gesture events yet.</p>
                            <?php else: foreach ($gesture_logs as $log): ?>
                                <div>
                                    <h5><?= ucfirst($log['event_type']) ?> – <?= htmlspecialchars($log['room_name']) ?></h5>
                                    <p class="light mb-0"><?= date('g:i A', strtotime($log['event_time'])) ?> · <?= date('M j', strtotime($log['event_time'])) ?></p>
                                </div>
                                <hr>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php include '../../php/includes/faculty-sidebar.php'; ?>
            <?php include '../../php/includes/f-profile-offcanvas.php'; ?>

        </div>
    </div>

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
</div>

<script>
    document.getElementById('sidebarTrigger').addEventListener('click', function () {
        bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('sidebarOffcanvas')).toggle();
    });
    document.getElementById('sidebarTrigger2').addEventListener('click', function () {
        bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('profileOffcanvas')).toggle();
    });
</script>
</body>
</html>