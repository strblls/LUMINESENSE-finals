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
/** @var $faculty_id int */
/** @var $classroom_id int */
/** @var $logs array */
/** @var $gesture_logs array */
/** @var $schedules array */

// ── Active schedule for timer ─────────────────────────────────────────────────
$active_schedule = null;
$now = date('H:i:s');
$today = date('l');
$stmt = $conn->prepare("
    SELECT s.id, s.start_time, s.end_time, s.extended_until, c.room_name
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    WHERE s.classroom_id = ?
      AND s.day_of_week = ?
      AND s.start_time <= ?
      AND (s.extended_until >= ? OR (s.extended_until IS NULL AND s.end_time >= ?))
    ORDER BY s.start_time
    LIMIT 1
");
$stmt->bind_param('issss', $classroom_id, $today, $now, $now, $now);
$stmt->execute();
$r = $stmt->get_result();
if ($row = $r->fetch_assoc()) $active_schedule = $row;
$stmt->close();

// ── Classroom light_status ────────────────────────────────────────────────────
$light_status = 'off';
$stmt = $conn->prepare("SELECT light_status FROM classrooms WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $classroom_id);
$stmt->execute();
$stmt->bind_result($light_status);
$stmt->fetch();
$stmt->close();

// ── Masked email helper ───────────────────────────────────────────────────────
function mask_email(string $email): string
{
    [$local, $domain] = explode('@', $email, 2);
    $visible = min(2, strlen($local));
    return substr($local, 0, $visible) . str_repeat('*', max(1, strlen($local) - $visible)) . '@' . $domain;
}

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
    <link rel="stylesheet" href="../../css/modals.css">

    <title>Home – LumineSense</title>

    <style>
        /* Override homepage grid to 3 columns: gesture | center | activities */
        .main-container.homepage {
            grid-template-columns: 1fr 1.2fr 1fr !important;
        }

        /* Gesture camera area */
        .gesture-camera {
            background: #212529;
            border-radius: 8px;
            min-height: 190px;
            margin: 0 10px 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .gesture-camera img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 8px;
        }

        .gesture-response {
            padding: 4px 10px 12px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 0.88rem;
        }

        @media (max-width: 992px) {
            .main-container.homepage {
                grid-template-columns: 1fr 1fr !important;
            }
        }

        @media (max-width: 640px) {
            .main-container.homepage {
                grid-template-columns: 1fr !important;
            }
        }
    </style>
</head>

<body class="contrast-bg">
    <div class="parent-container">

        <?php include '../../php/includes/faculty-topbar.php'; ?>

        <div class="child-container">
            <div class="main-container homepage gap-3">

                <!-- ══════════════════════════════
                     COLUMN 1 – GESTURE DETECTION
                ══════════════════════════════ -->
                <div class="group-container gap-3">

                    <!-- Gesture Detection -->
                    <div style="background-color: #f8f9fa;" class="section-container">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Gesture Detection</h2>
                            </div>
                            <div class="d-flex mx-2 align-items-end">
                                <button class="light mx-2" id="refreshBtn">Refresh</button>
                            </div>
                        </div>

                        <!-- Camera feed -->
                        <div class="gesture-camera d-flex flex-row align-items-center justify-content-center">
                            <button id="enableCameraBtn" class="btn btn-primary btn-sm">
                                <i class="bi bi-camera-video me-1"></i>Enable Camera
                            </button>
                            <img src="" id="gestureStream" class="object-fit-cover" style="display:none;">
                        </div>

                        <!-- Result + accuracy -->
                        <div class="gesture-response d-flex px-2 flex-column align-items-start justify-content-start">
                            Result: <span class="bold mx-2" id="gestureResult">—</span>
                            <span>Accuracy:</span>
                            <div class="progress w-100" style="height: 20px;">
                                <div class="progress-bar bg-success d-flex align-items-center justify-content-center"
                                    role="progressbar"
                                    id="accuracyBar"
                                    style="width: 0%;"
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
                                <?php endforeach;
                                endif; ?>
                            </div>
                        </div>
                    </div>

                </div><!-- /col 1 -->


                <!-- ══════════════════════════════
                     COLUMN 2 – TIMER + LIGHTING
                ══════════════════════════════ -->
                <div class="group-container gap-3">

                    <!-- Time Left -->
                    <div style="background-color: #f8f9fa;" class="section-container">
                        <div class="d-flex gap-1 justify-content-center align-items-center">
                            <div class="d-flex flex-column mx-2 align-items-start justify-content-center">
                                <h2 class="bold">Time Left</h2><br>
                                <h2 class="medium fs-6">until end of class</h2>
                            </div>
                            <div class="d-flex flex-column mx-1 align-items-center justify-content-center">
                                <?php if ($active_schedule): ?>
                                    <?php
                                    // Use extended_until if set, otherwise end_time
                                    $end = $active_schedule['extended_until'] ?? $active_schedule['end_time'];
                                    ?>
                                    <h1 class="bold display-1" id="timerDisplay"
                                        data-end="<?= htmlspecialchars($end) ?>">
                                        --:--:--
                                    </h1>
                                <?php else: ?>
                                    <h1 class="bold display-1 text-muted" id="timerDisplay">00:00:00</h1>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex flex-column mx-2 align-items-end justify-content-center">
                                <button class="light" onclick="dissolve('faculty-timetable.html')">View Schedule</button>
                                <button class="light">Extend</button>
                            </div>
                        </div>

                        <?php if (!$active_schedule): ?>
                            <p class="text-muted text-center mt-2 mb-1">No active class schedule right now.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Lighting Grid -->
                    <div style="background-color: #f8f9fa;" class="fit-width section-container">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Lighting Grid</h2>
                            </div>
                            <div class="d-flex mx-2 align-items-end">
                                <button class="light mx-2" onclick="dissolve('faculty-lighting.html')">More</button>
                            </div>
                        </div>
                        <?php
                        $bulb_img = ($light_status === 'on' && $active_schedule)
                            ? '../../images/bulb-on.png'
                            : '../../images/bulb-off.png';
                        ?>
                        <div class="d-flex flex-row align-items-center justify-content-center">
                            <div class="lighting-grid">
                                <img src="<?= $bulb_img ?>">
                                <img src="<?= $bulb_img ?>">
                                <img src="<?= $bulb_img ?>">
                                <hr class="w-100">
                                <img src="<?= $bulb_img ?>">
                                <img src="<?= $bulb_img ?>">
                                <img src="<?= $bulb_img ?>">
                                <hr class="w-100">
                                <img src="<?= $bulb_img ?>">
                                <img src="<?= $bulb_img ?>">
                                <img src="<?= $bulb_img ?>">
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
                                    <h4 class="bold <?= ($light_status === 'on' && $active_schedule) ? 'on' : 'off' ?>">
                                        <?= ($light_status === 'on' && $active_schedule) ? 'ON' : 'OFF' ?>
                                    </h4>
                                    <div class="all-lights-<?= ($light_status === 'on' && $active_schedule) ? 'on' : 'off' ?> ...">
                                        <i class="bi bi-power" id="all-lights"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div><!-- /col 2 -->


                <!-- ══════════════════════════════
                     COLUMN 3 – RECENT ACTIVITIES + SYSTEM STATUS
                ══════════════════════════════ -->
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
                                        <div style="font-size:0.78rem; padding: 4px 0;">
                                            <span class="bold"><?= ucfirst(str_replace('_', ' ', $log['event_type'])) ?></span>
                                            <span class="text-muted"> · <?= htmlspecialchars($log['room_name']) ?></span>
                                            <div class="text-muted" style="font-size:0.72rem;"><?= date('g:i A · M j', strtotime($log['event_time'])) ?></div>
                                        </div>
                                        <hr>
                                <?php endforeach;
                                endif; ?>
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
                                <h5>Lighting:
                                    <?php if ($light_status === 'on'): ?>
                                        <span class="text-success">ON</span>
                                    <?php else: ?>
                                        <span class="text-danger">OFF</span>
                                    <?php endif; ?>
                                </h5>
                                <h5>Server: <span class="text-success">Connected</span></h5>
                                <h5>Webcam: <span class="text-muted">Disabled</span></h5>
                                <h5>Sensor Reading: <span class="text-danger">Disconnected</span></h5>
                                <h5>System Uptime: 00:00:00</h5>
                            </div>
                        </div>
                    </div>

                </div><!-- /col 3 -->

                <?php include '../../php/includes/faculty-sidebar.php'; ?>
                <?php include '../../php/includes/f-profile-offcanvas.php'; ?>

                <!-- Profile Modal -->
                <div class="profile-details-modal modal fade" id="profileModal" tabindex="-1"
                    aria-labelledby="profileModalLabel" aria-hidden="true">
                    <div class="d-flex justify-content-center modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <div>
                                    <h5 class="modal-title" id="profileModalLabel">Profile</h5>
                                </div>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="card border-0 shadow-sm rounded-4">
                                    <div class="card-body p-4">
                                        <div class="d-flex flex-between align-items-center gap-3 mb-4">
                                            <div class="avatar-icon d-flex align-items-center justify-content-center flex-shrink-0">
                                                <h3 class="bold mb-0"><?= $initials ?></h3>
                                            </div>
                                            <div>
                                                <h4 class="bold mb-1"><?= $faculty_name ?></h4>
                                                <p class="mb-0">Faculty Member</p>
                                            </div>
                                            <!-- Edit profile: opens this same modal, already here -->
                                            <button type="button"
                                                class="edit-button btn btn-sm btn-light border rounded-circle ms-auto"
                                                data-bs-toggle="modal" data-bs-target="#profileModal"
                                                aria-label="Edit profile details">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        </div>
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <div class="p-3 bg-light rounded-3">
                                                    <small class="text-muted d-block">Email</small>
                                                    <!-- Masked for privacy, e.g. ca***@gmail.com -->
                                                    <p class="mb-0"><?= htmlspecialchars(mask_email($faculty_email)) ?></p>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="p-3 bg-light rounded-3">
                                                    <small class="text-muted d-block">Address</small>
                                                    <p class="mb-0">N/A</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Classroom Details Modal -->
                <div class="profile-details-modal modal fade" id="classroomModal" tabindex="-1"
                    aria-labelledby="classroomModalLabel" aria-hidden="true">
                    <div class="d-flex justify-content-center modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <div>
                                    <h5 class="modal-title" id="classroomModalLabel">Classroom Details</h5>
                                </div>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="card border-0 shadow-sm rounded-4">
                                    <div class="card-body p-4">
                                        <div class="d-flex flex-between align-items-center gap-3 mb-4">
                                            <div class="flex-grow-1 min-w-0">
                                                <h4 class="bold mb-1">Grade 6 Narra</h4>
                                                <p class="mb-0">Classroom Overview</p>
                                            </div>
                                        </div>
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <div class="p-3 bg-light rounded-3">
                                                    <small class="text-muted d-block">Faculty In-Charge</small>
                                                    <p class="mb-0">John Doe</p>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="p-3 bg-light rounded-3">
                                                    <small class="text-muted d-block">Room Status</small>
                                                    <p class="mb-0">Occupied</p>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="p-3 bg-light rounded-3">
                                                    <small class="text-muted d-block">Current Schedule</small>
                                                    <p class="mb-0">4:30 PM - 5:30 PM</p>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="p-3 bg-light rounded-3">
                                                    <small class="text-muted d-block">Lighting Status</small>
                                                    <p class="mb-0"><?= strtoupper($light_status) ?></p>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="p-3 bg-light rounded-3">
                                                    <small class="text-muted d-block">Specifications (in compliance with
                                                        DepEd Order No.64 s. 2017)</small>
                                                    <p class="mb-0">
                                                        Dimensions (meters): 7 m x 9 m x 2.1 m<br>
                                                        Floor Space (sq. meters): 63 sq. m.<br>
                                                        Target Brightness (lux): 250 lux or lumen per sq. m.<br>
                                                        Luminous Efficacy (lumens per watt): 104 lm/W minimum<br>
                                                        Calculated Wattage: 81W - 108W
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <script src="../../script/animations.js"></script>
        <script src="../../script/toggles.js"></script>
    </div>

    <!-- PHP values for gesture JS -->
    <script>
        const CLASSROOM_ID = <?= (int)$classroom_id ?>;
        const FACULTY_ID = <?= (int)$faculty_id ?>;

        // Sidebar triggers
        document.getElementById('sidebarTrigger').addEventListener('click', function() {
            bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('sidebarOffcanvas')).toggle();
        });
        document.getElementById('sidebarTrigger2').addEventListener('click', function() {
            bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('profileOffcanvas')).toggle();
        });

        // Refresh reloads the page to update gesture log
        document.getElementById('refreshBtn').addEventListener('click', () => location.reload());

        // Log a gesture event to the DB
        async function logGestureEvent(gestureLabel, eventType = 'gesture') {
            const form = new FormData();
            form.append('classroom_id', CLASSROOM_ID);
            form.append('faculty_id', FACULTY_ID);
            form.append('event_type', eventType);
            form.append('triggered_by', 'gesture');
            await fetch('../../api/logs.php', {
                method: 'POST',
                body: form
            });
        }

        // Update gesture result display (called from initialize-gesture.js)
        function updateGestureResult(label, accuracy) {
            document.getElementById('gestureResult').textContent = label;
            const pct = Math.round(accuracy * 100);
            const bar = document.getElementById('accuracyBar');
            bar.style.width = pct + '%';
            bar.textContent = pct + '%';
            bar.setAttribute('aria-valuenow', pct);
        }

        // ── Countdown timer ───────────────────────────────────────────────────
        (function() {
            const display = document.getElementById('timerDisplay');
            const endAttr = display ? display.dataset.end : null;
            if (!endAttr) return; // no active schedule

            function tick() {
                const now = new Date();
                const [h, m, s] = endAttr.split(':').map(Number);
                const end = new Date(now);
                end.setHours(h, m, s, 0);

                let diff = Math.floor((end - now) / 1000);
                if (diff < 0) diff = 0;

                const hh = String(Math.floor(diff / 3600)).padStart(2, '0');
                const mm = String(Math.floor((diff % 3600) / 60)).padStart(2, '0');
                const ss = String(diff % 60).padStart(2, '0');
                display.textContent = `${hh}:${mm}:${ss}`;

                if (diff === 0) display.classList.add('text-danger');
            }

            tick();
            setInterval(tick, 1000);
        })();
    </script>

    <!-- Gesture detection script -->
    <script src="../../script/initialize-gesture.js"></script>

</body>

</html>