<?php
$page_title = 'Faculty Dashboard';

require_once '../../php/session_guard.php';
check_faculty();
require_once '../../php/db_connect.php';
require_once '../../php/includes/faculty-head.php';

/** @var $faculty_name string */
/** @var $faculty_email string */
/** @var $initials string */
/** @var $first_name string */

// Fetch recent gesture logs for this faculty's classroom
$gesture_logs = [];
$stmt = $conn->prepare("
    SELECT el.event_type, el.event_time, r.room_name
    FROM event_logs el
    JOIN rooms r ON el.classroom_id = r.id
    WHERE el.faculty_id = ?
    ORDER BY el.event_time DESC
    LIMIT 10
");
$stmt->bind_param("i", $faculty_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $gesture_logs[] = $row;
}
$stmt->close();

// Fetch recent activity logs (all types)
$logs = [];
$stmt2 = $conn->prepare("
    SELECT el.event_type, el.event_time, r.room_name
    FROM event_logs el
    JOIN rooms r ON el.classroom_id = r.id
    WHERE el.faculty_id = ?
    ORDER BY el.event_time DESC
    LIMIT 10
");
$stmt2->bind_param("i", $faculty_id);
$stmt2->execute();
$result2 = $stmt2->get_result();
while ($row = $result2->fetch_assoc()) {
    $logs[] = $row;
}
$stmt2->close();
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

    <title>Home – LumineSense</title>
</head>

<body class="contrast-bg">
    <div class="parent-container">

        <?php include '../../php/includes/faculty-topbar.php'; ?>

        <div class="child-container">
            <div class="main-container homepage gap-3">

                <!-- ── COLUMN 1 ── Time Left + Lighting Grid -->
                <div class="group-container gap-3">

                    <!-- Time Left -->
                    <div style="background-color: #f8f9fa;" class="section-container">
                        <div class="d-flex gap-1 justify-content-center align-items-center">
                            <div class="d-flex flex-column mx-2 align-items-start justify-content-center">
                                <h2 class="bold">Time Left</h2><br>
                                <h2 class="medium fs-6">until end of class</h2>
                            </div>
                            <div class="d-flex flex-column mx-1 align-items-center justify-content-center">
                                <h1 class="bold display-1">00:00:00</h1>
                            </div>
                            <div class="d-flex flex-column mx-2 align-items-end justify-content-center">
                                <button class="light">View Schedule</button>
                                <button class="light">Extend</button>
                            </div>
                        </div>
                    </div>

                    <!-- Lighting Grid -->
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
                                    <div class="all-lights-off d-flex flex-column align-items-center justify-content-center">
                                        <i class="bi bi-power" id="all-lights"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- ── COLUMN 2 ── Recent Activities + System Status -->
                <div class="group-container gap-3">

                    <!-- Recent Activities -->
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

                    <!-- System Status -->
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
                            </div>
                        </div>
                    </div>

                </div>

                <!-- ── COLUMN 3 ── Gesture Detection + Detected Gestures log -->
                <div class="group-container gap-3">

                    <!-- Gesture Detection -->
                    <div style="background-color:#f8f9fa;" class="section-container">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Gesture Detection</h2>
                            </div>
                            <div class="d-flex mx-2 align-items-end">
                                <button class="light mx-2" id="refreshBtn">Refresh</button>
                            </div>
                        </div>
                        <div class="gesture-camera d-flex flex-row align-items-center justify-content-center">
                            <button id="enableCameraBtn" class="btn btn-primary">Enable Camera</button>
                            <img src="" id="gestureStream" class="object-fit-cover" style="display:none;">
                        </div>
                        <div class="gesture-response d-flex px-2 flex-column align-items-start justify-content-start">
                            Result: <span class="bold mx-2" id="gestureResult">—</span>
                            <span>Accuracy:</span>
                            <div class="progress w-100" style="height:20px;">
                                <div class="progress-bar bg-success d-flex align-items-center justify-content-center"
                                     role="progressbar"
                                     id="accuracyBar"
                                     style="width:0%;"
                                     aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                                    0%
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Detected Gestures log -->
                    <div style="background-color:#f8f9fa;" class="section-container recents">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h4 class="bold">Detected Gestures</h4>
                            </div>
                        </div>
                        <div class="gap-2">
                            <div class="activity-list gesture-control px-2 gap-2 align-items-center max-width">
                                <?php if (empty($gesture_logs)): ?>
                                    <p class="text-muted">No gesture events yet.</p>
                                <?php else: foreach ($gesture_logs as $log): ?>
                                    <div>
                                        <h5><?= ucfirst($log['event_type']) ?> – <?= htmlspecialchars($log['room_name']) ?></h5>
                                        <p class="light mb-0">
                                            <?= date('g:i A', strtotime($log['event_time'])) ?>
                                            · <?= date('M j', strtotime($log['event_time'])) ?>
                                        </p>
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

    <!-- PHP values for gesture JS -->
    <script>
        const CLASSROOM_ID = <?= $classroom_id ?>;
        const FACULTY_ID   = <?= $faculty_id ?>;

        // Sidebar triggers
        document.getElementById('sidebarTrigger').addEventListener('click', function () {
            bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('sidebarOffcanvas')).toggle();
        });
        document.getElementById('sidebarTrigger2').addEventListener('click', function () {
            bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('profileOffcanvas')).toggle();
        });

        // Refresh reloads the page to update the gesture log list
        document.getElementById('refreshBtn').addEventListener('click', () => location.reload());

        // Log a gesture event to the DB when a gesture is confirmed
        async function logGestureEvent(gestureLabel, eventType = 'gesture') {
            const form = new FormData();
            form.append('classroom_id', CLASSROOM_ID);
            form.append('faculty_id',   FACULTY_ID);
            form.append('event_type',   eventType);
            form.append('triggered_by', 'gesture');
            await fetch('../../api/logs.php', { method: 'POST', body: form });
        }

        // Update the gesture result display (called from initialize-gesture.js)
        function updateGestureResult(label, accuracy) {
            document.getElementById('gestureResult').textContent = label;
            const pct = Math.round(accuracy * 100);
            const bar = document.getElementById('accuracyBar');
            bar.style.width = pct + '%';
            bar.textContent = pct + '%';
            bar.setAttribute('aria-valuenow', pct);
        }
    </script>

    <!-- Gesture detection script -->
    <script src="../../script/initialize-gesture.js"></script>

</body>
</html>