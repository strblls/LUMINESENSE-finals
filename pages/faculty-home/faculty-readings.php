<?php
require_once '../../php/session_guard.php';
check_faculty();
require_once '../../php/db_connect.php';

$faculty_name = htmlspecialchars($_SESSION['faculty_name']);
$faculty_id   = $_SESSION['faculty_id'];
$name_parts   = explode(' ', $faculty_name);
$first_name   = $name_parts[0];
$initials     = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));

// Fetch email
$faculty_email = '';
$stmt = $conn->prepare('SELECT email FROM faculty WHERE id = ?');
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$stmt->bind_result($faculty_email);
$stmt->fetch();
$stmt->close();

// Current schedule label
$today = date('l');
$current_sched = 'No class right now';
$now = date('H:i:s');
$r = $conn->query("
    SELECT s.start_time, s.end_time, c.room_name
    FROM schedules s JOIN classrooms c ON c.id = s.classroom_id
    WHERE s.day_of_week = '$today'
    ORDER BY s.start_time
");
while ($row = $r->fetch_assoc()) {
    if ($now >= $row['start_time'] && $now <= $row['end_time']) {
        $current_sched = $row['room_name'] . ' · '
            . date('g:i A', strtotime($row['start_time'])) . ' - '
            . date('g:i A', strtotime($row['end_time']));
        break;
    }
}

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

    <!-- TOPBAR -->
    <div class="topbar d-flex">
        <button type="button" id="sidebarTrigger"><i class="bi bi-list"></i></button>
        <div class="col d-flex flex-column px-3">
            <h1 class="bold">Sensor Readings</h1>
            <h5 class="light">Current Schedule: <?= $current_sched ?></h5>
        </div>
        <div class="d-flex align-items-center justify-content-center gap-2 mx-2">
            <h4><?= $faculty_name ?></h4>
            <div class="avatar-icon d-flex align-items-center justify-content-center" id="sidebarTrigger2">
                <h3 class="bold"><?= $initials ?></h3>
            </div>
        </div>
    </div>

    <div class="child-container">
        <div class="main-container homepage gap-3">

            <!-- OCCUPANCY -->
            <div class="group-container gap-3">
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
            <div class="group-container gap-3">
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
            <div class="group-container gap-3">
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

            <!-- SIDEBAR LEFT -->
            <div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas">
                <div class="offcanvas-header justify-content-center">
                    <img src="../../images/logo.png" class="logo" onclick="dissolve('faculty-homepage.php')">
                </div>
                <div class="offcanvas-body align-items-center d-flex flex-column">
                    <button class="wb-2" onclick="dissolve('faculty-lighting.php')"><i class="bi bi-lightbulb"></i></button>
                    <button class="wb-2" onclick="dissolve('faculty-readings.php')"><i class="bi bi-broadcast"></i></button>
                    <button class="wb-2" onclick="dissolve('faculty-gesture.php')"><i class="bi bi-hand-thumbs-up"></i></button>
                    <button class="wb-2" onclick="dissolve('faculty-timetable.php')"><i class="bi bi-calendar-event"></i></button>
                    <button class="wb-2" onclick="dissolve('faculty-profile-settings.php')"><i class="bi bi-gear"></i></button>
                </div>
                <div class="offcanvas-footer">
                    <img src="../../images/team-logo.png" class="logo">
                </div>
            </div>

            <!-- SIDEBAR RIGHT -->
            <div class="offcanvas offcanvas-end" tabindex="-1" id="profileOffcanvas">
                <div class="offcanvas-body align-items-center d-flex flex-column">
                    <div class="avatar-icon d-flex align-items-center justify-content-center">
                        <h3 class="bold"><?= $initials ?></h3>
                    </div>
                    <h4 class="bold"><?= $faculty_name ?></h4>
                    <h6 class="light email-limit"><?= htmlspecialchars($faculty_email) ?></h6>
                    <div class="d-flex flex-column align-items-center justify-content-center">
                        <button onclick="dissolve('faculty-profile-settings.php')">Profile Settings</button>
                        <button>Classroom Details</button>
                        <button onclick="dissolve('../../php/logout.php')">Logout</button>
                    </div>
                </div>
            </div>

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