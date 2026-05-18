<?php
require_once '../../php/session_guard.php';
check_faculty();
require_once '../../php/db_connect.php';

$faculty_name    = htmlspecialchars($_SESSION['faculty_name']);
$faculty_id      = $_SESSION['faculty_id'];
$name_parts      = explode(' ', $faculty_name);
$first_name      = $name_parts[0];
$initials        = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));

// Today's schedule
$today = date('l');
$schedules = [];
$r = $conn->query("
    SELECT s.start_time, s.end_time, c.room_name
    FROM schedules s JOIN classrooms c ON c.id = s.classroom_id
    WHERE s.day_of_week = '$today'
    ORDER BY s.start_time
");
while ($row = $r->fetch_assoc()) $schedules[] = $row;

// Current schedule label
$current_sched = 'No class right now';
$now = date('H:i:s');
foreach ($schedules as $s) {
    if ($now >= $s['start_time'] && $now <= $s['end_time']) {
        $current_sched = $s['room_name'] . ' · ' . date('g:i A', strtotime($s['start_time'])) . ' - ' . date('g:i A', strtotime($s['end_time']));
        break;
    }
}

// Recent activity logs
$logs = [];
$r = $conn->query("
    SELECT l.event_type, l.triggered_by, l.event_time, c.room_name
    FROM lighting_logs l JOIN classrooms c ON c.id = l.classroom_id
    ORDER BY l.event_time DESC LIMIT 7
");
while ($row = $r->fetch_assoc()) $logs[] = $row;

// Fetch email from DB
$faculty_email = '';
$stmt = $conn->prepare('SELECT email FROM faculty WHERE id = ?');
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$stmt->bind_result($faculty_email);
$stmt->fetch();
$stmt->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!--Bootstrap and JS CDN-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>

    <!--CSS files-->
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
</head>

<body class="contrast-bg">
    <div class="parent-container">
        <div class="topbar d-flex">
            <button type="button" id="sidebarTrigger">
                <i class="bi bi-list"></i>
            </button>
            <div class="col d-flex flex-column px-3">
                <h1 class="bold">Welcome, <?= $first_name ?>!</h1>
                <h5 class="light">Current Schedule: <?= $current_sched ?></h5> <!--ALERT: PHP | DISPLAY-->
            </div>
            <div class="d-flex align-items-center justify-content-center gap-2 mx-2">
                <h4><?= $faculty_name ?></h4> <!--ALERT: PHP | DISPLAY-->
                <div class="avatar-icon d-flex align-items-center justify-content-center" id="sidebarTrigger2">
                    <h3 class="bold"><?= $initials ?></h3> <!--ALERT: PHP | DISPLAY (like profile picture or initials)-->
                </div>
            </div>
        </div>
        <div class="child-container">
            <div class="main-container homepage gap-3">
                <div class="group-container gap-3">
                    <div style="background-color: #f8f9fa;" class="section-container">
                        <div class="d-flex gap-1 justify-content-center align-items-center">
                            <div class="d-flex flex-column mx-2 align-items-start justify-content-center">
                                <h2 class="bold">Time Left</h2><br>
                                <h2 class="medium fs-6">until end of class</h2>
                            </div>
                            <div class="d-flex flex-column mx-1 align-items-center justify-content-center">
                                <h1 class="bold display-1">00:00:00</h1>
                                <!-- ALERT: PHP | DISPLAY (like conditional formatting for time left) -->
                            </div>
                            <div class="d-flex flex-column mx-2 align-items-end justify-content-center">
                                <button class="light">View Schedule</button>
                                <button class="light">Extend</button>
                            </div>
                        </div>
                        <!--ALERT: PHP | DISPLAY (like no schedule yet, limit exceeded)-->
                    </div>
                    <div style="background-color: #f8f9fa;" class="fit-width section-container">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Lighting Grid</h2>
                            </div>
                            <div class="d-flex mx-2 align-items-end">
                                <button class="light mx-2">More</button>
                            </div>
                        </div>
                        <div class="d-flex flex-row align-items-center justify-content-center">
                            <div class="lighting-grid">
                                <img src="../../images/bulb-off.png">
                                <img src="../../images/bulb-off.png">
                                <img src="../../images/bulb-off.png">
                                <hr class="w-100">
                                <img src="../../images/bulb-off.png">
                                <img src="../../images/bulb-off.png">
                                <img src="../../images/bulb-off.png">
                                <hr class="w-100">
                                <img src="../../images/bulb-off.png">
                                <img src="../../images/bulb-off.png">
                                <img src="../../images/bulb-off.png">
                                <hr class="w-100">
                            </div>
                            <div class="p-5">
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <label class="form-check-label" for="row-1-switch">Row 1</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="row-1-switch">
                                    </div>
                                </div>
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <label class="form-check-label" for="row-2-switch">Row 2</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="row-2-switch">
                                    </div>
                                </div>
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <label class="form-check-label" for="row-3-switch">Row 3</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="row-3-switch">
                                    </div>
                                </div>
                                <br>
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <h5 class="bold">All Lights</h5>
                                    <h4 class="bold off">OFF</h4>
                                    <div
                                        class="all-lights-off d-flex flex-column align-items-center justify-content-center">
                                        <i class="bi bi-power" id="all-lights"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="group-container gap-3">
                    <div style="background-color: #f8f9fa;" class="section-container recents">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Recent Activities</h2>
                            </div>
                            <div class="d-flex mx-2 align-items-end">
                                <button class="light mx-2">Details</button>
                            </div>
                        </div>
                        <div class="gap-2">
                            <div class="activity-list px-2 gap-2 align-items-center max-width">
                                <?php if (empty($logs)): ?>
                                    <p class="text-muted">No recent activity yet.</p>
                                <?php else: foreach ($logs as $log): ?>
                                    <div>
                                        <h5><?= ucfirst($log['event_type']) ?> – <?= htmlspecialchars($log['room_name']) ?></h5>
                                        <p class="light mb-0"><?= date('g:i A', strtotime($log['event_time'])) ?></p>
                                    </div>
                                    <hr>
                                <?php endforeach; endif; ?>
                            </div>
                        </div>
                    </div>
                    <div style="background-color: #f8f9fa;" class="section-container">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">System Status</h2>
                            </div>
                        </div>
                        <div class="gap-2">
                            <div class="activity-list px-2 gap-2 align-items-center max-width">
                                <h5>Lighting: Disconnected</h5>
                                <h5>Server: Connected</h5>
                                <h5>Webcam: Disabled</h5>
                                <h5>Sensor Reading: Disconnected</h5>
                                <h5>System Uptime: 00:00:00</h5>
                                <!--ALERT: PHP | DISPLAY (dynamic depending sa status)-->
                            </div>
                        </div>
                    </div>
                </div>

                <div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas"
                    aria-labelledby="sidebarOffcanvasLabel">
                    <div class="offcanvas-header justify-content-center">
                        <img src="../../images/logo.png" class="logo" onclick="dissolve('faculty-homepage.php')">
                    </div>
                    <div class="offcanvas-body align-items-center d-flex flex-column">
                        <button class="wb-2" onclick="dissolve('faculty-lighting.php')"><i
                                class="bi bi-lightbulb"></i></button>
                        <button class="wb-2" onclick="dissolve('faculty-readings.php')"><i
                                class="bi bi-broadcast"></i></button>
                        <button class="wb-2" onclick="dissolve('faculty-gesture.php')"><i
                                class="bi bi-hand-thumbs-up"></i></button>
                        <button class="wb-2" onclick="dissolve('faculty-timetable.php')"><i
                                class="bi bi-calendar-event"></i></button>
                        <button class="wb-2" onclick="dissolve('faculty-profile-settings.html')"><i
                                class="bi bi-gear"></i></button>
                    </div>
                    <div class="offcanvas-footer">
                        <img src="../../images/team-logo.png" class="logo">
                    </div>
                </div>
                <div class="offcanvas offcanvas-end" tabindex="-1" id="profileOffcanvas"
                    aria-labelledby="sidebarOffcanvasLabel">
                    <div class="offcanvas-body align-items-center d-flex flex-column">
                        <div class="avatar-icon d-flex align-items-center justify-content-center" id="sidebarTrigger2">
                            <h3 class="bold"><?= $initials ?></h3> 
                        </div>
                        <h4 class="bold"><?= $faculty_name ?></h4>
                        <h6 class="light email-limit"><?= htmlspecialchars($faculty_email) ?></h6>
                        <div class="d-flex flex-column align-items-center justify-content-center">
                            <button>Profile Settings</button>
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
</body>

</html>