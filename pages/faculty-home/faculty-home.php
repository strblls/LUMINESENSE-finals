<?php
$page_title = 'Faculty Dashboard';

require_once '../../php/session_guard.php';
check_faculty();
require_once '../../php/db_connect.php';
date_default_timezone_set('Asia/Manila');
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
if ($row = $r->fetch_assoc())
    $active_schedule = $row;
$stmt->close();

// ── Classroom light_status ────────────────────────────────────────────────────
$light_status = 'off';
$row1_status = 'off';
$row2_status = 'off';
$row3_status = 'off';
$stmt = $conn->prepare("SELECT light_status, row1_status, row2_status, row3_status FROM classrooms WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $classroom_id);
$stmt->execute();
$stmt->bind_result($light_status, $row1_status, $row2_status, $row3_status);
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
        .main-container.homepage {
            display: grid !important;
            grid-template-columns: 1fr 1.2fr 1fr !important;
            align-items: start !important;
        }

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

        /* Gesture row selector pills */
        .gesture-row-pills {
            display: flex;
            gap: 6px;
        }

        .gesture-row-pill {
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 600;
            background: #e9ecef;
            color: #6c757d;
            border: 2px solid transparent;
            transition: all 0.2s ease;
            cursor: default;
            user-select: none;
        }

        .gesture-row-pill.active {
            background: #0d6efd;
            color: #fff;
            border-color: #0a58ca;
            box-shadow: 0 0 8px rgba(13, 110, 253, 0.45);
        }

        .gesture-row-pill.pending {
            background: #ffc107;
            color: #212529;
            border-color: #ff9800;
            box-shadow: 0 0 10px rgba(255, 193, 7, 0.6);
            animation: pillPulse 1s infinite alternate ease-in-out;
        }

        .gesture-row-pill.confirmed {
            background: #198754;
            color: #fff;
            border-color: #146c43;
            animation: pillPop 0.35s ease;
        }

        @keyframes pillPulse {
            0% {
                transform: scale(1);
            }

            100% {
                transform: scale(1.08);
            }
        }

        @keyframes pillPop {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.18);
            }

            100% {
                transform: scale(1);
            }
        }

        #simulatePirBtn {
            font-size: 0.78rem;
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
                        <div class="gesture-camera d-flex flex-row align-items-center justify-content-center"
                            style="position: relative;">
                            <button id="enableCameraBtn" class="btn btn-primary btn-sm" style="z-index: 10;" <?= !$active_schedule ? 'disabled title="No active schedule"' : '' ?>>
                                <i class="bi bi-camera-video me-1"></i>Enable Camera
                            </button>
                            <button id="disableCameraBtn" class="btn btn-secondary btn-sm"
                                style="display:none; position: absolute; bottom: 8px; right: 8px; z-index: 10;">
                                <i class="bi bi-camera-video-off me-1"></i>Disable Camera
                            </button>
                            <video id="webcamVideo" autoplay playsinline
                                style="display:none; width:100%; height:100%; object-fit:cover; border-radius:8px; transform: scaleX(-1);"></video>
                            <canvas id="webcamCanvas"
                                style="display:none; position:absolute; top:0; left:0; width:100%; height:100%; object-fit:cover; border-radius:8px; pointer-events:none; transform: scaleX(-1);"></canvas>
                        </div>

                        <!-- Row selector pills + result + accuracy -->
                        <div
                            class="gesture-response d-flex px-2 flex-column align-items-start justify-content-start gap-2">

                            <!-- Row indicator pills -->
                            <div class="gesture-row-pills w-100 d-flex justify-content-center gap-2 mt-1">
                                <span class="gesture-row-pill" id="rowPill1" data-row="1">Row 1</span>
                                <span class="gesture-row-pill" id="rowPill2" data-row="2">Row 2</span>
                                <span class="gesture-row-pill" id="rowPill3" data-row="3">Row 3</span>
                            </div>

                            <!-- Result label -->
                            <div class="d-flex align-items-center gap-1">
                                <span class="text-muted" style="font-size:0.85rem;">Detected:</span>
                                <span class="bold mx-1" id="gestureResult">—</span>
                            </div>

                            <div>
                                <button class="btn btn-sm btn-outline-secondary ms-auto" data-bs-toggle="modal"
                                    data-bs-target="#gestureHelpModal"
                                    style="font-size:0.75rem; padding: 2px 8px; border-radius: 20px;">
                                    <i class="bi bi-question-circle"></i> View Gestures
                                </button>
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
                                    <span id="statusLighting"
                                        class="<?= $light_status === 'on' ? 'text-success' : 'text-danger' ?>">
                                        <?= strtoupper($light_status) ?>
                                    </span>
                                </h5>
                                <h5>Server: <span class="text-success">Connected</span></h5>
                                <h5>Webcam: <span id="statusWebcam" class="text-muted">Disabled</span></h5>
                                <h5>PIR Sensor:
                                    <span id="statusPir" class="text-muted">Unknown</span>
                                </h5>
                                <!-- PIR simulate button (testing without Arduino) -->
                                <button id="simulatePirBtn" class="btn btn-sm btn-success mt-1 w-100">
                                    🟢 Simulate Occupancy
                                </button>
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
                                    $end = $active_schedule['extended_until'] ?? $active_schedule['end_time'];
                                    ?>
                                    <h1 class="bold display-1" id="timerDisplay" data-end="<?= htmlspecialchars($end) ?>">
                                        --:--:--
                                    </h1>
                                <?php else: ?>
                                    <h1 class="bold display-1 text-muted" id="timerDisplay">00:00:00</h1>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex flex-column mx-2 align-items-end justify-content-center">
                                <button class="light" data-bs-toggle="modal" data-bs-target="#viewScheduleModal">View Schedule</button>
                                <?php if ($active_schedule): ?>
                                    <button class="light mt-2" data-bs-toggle="modal" data-bs-target="#extendModal">
                                        <i class="bi bi-clock-history me-1"></i> Extend
                                    </button>
                                <?php endif; ?>
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
                        </div>
                        <!-- Schedule ended notice -->
                        <div id="scheduleEndNotice" class="alert alert-warning d-flex align-items-center gap-2 mx-2 mb-2 py-2"
                            style="font-size:0.82rem; <?= !$active_schedule ? '' : 'display:none;' ?>">
                            <i class="bi bi-lock-fill"></i>
                            Controls are locked — no active class schedule.
                        </div>

                        <?php
                        $b1 = ($row1_status === 'on' && $active_schedule) ? '../../images/bulb-on.png' : '../../images/bulb-off.png';
                        $b2 = ($row2_status === 'on' && $active_schedule) ? '../../images/bulb-on.png' : '../../images/bulb-off.png';
                        $b3 = ($row3_status === 'on' && $active_schedule) ? '../../images/bulb-on.png' : '../../images/bulb-off.png';
                        ?>
                        <div class="d-flex flex-row align-items-center justify-content-center">
                            <div class="lighting-grid">
                                <img src="<?= $b1 ?>" class="bulb-img" data-row="1">
                                <img src="<?= $b1 ?>" class="bulb-img" data-row="1">
                                <img src="<?= $b1 ?>" class="bulb-img" data-row="1">
                                <hr class="w-100">
                                <img src="<?= $b2 ?>" class="bulb-img" data-row="2">
                                <img src="<?= $b2 ?>" class="bulb-img" data-row="2">
                                <img src="<?= $b2 ?>" class="bulb-img" data-row="2">
                                <hr class="w-100">
                                <img src="<?= $b3 ?>" class="bulb-img" data-row="3">
                                <img src="<?= $b3 ?>" class="bulb-img" data-row="3">
                                <img src="<?= $b3 ?>" class="bulb-img" data-row="3">
                                <hr class="w-100">
                            </div>
                            <div class="p-5">
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <label class="form-check-label" for="row-1-switch">Row 1</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="row-1-switch"
                                            <?= ($row1_status === 'on' && $active_schedule) ? 'checked' : '' ?>
                                            <?= !$active_schedule ? 'disabled' : '' ?>>
                                    </div>
                                </div>
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <label class="form-check-label" for="row-2-switch">Row 2</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="row-2-switch"
                                            <?= ($row2_status === 'on' && $active_schedule) ? 'checked' : '' ?>
                                            <?= !$active_schedule ? 'disabled' : '' ?>>
                                    </div>
                                </div>
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <label class="form-check-label" for="row-3-switch">Row 3</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="row-3-switch"
                                            <?= ($row3_status === 'on' && $active_schedule) ? 'checked' : '' ?>
                                            <?= !$active_schedule ? 'disabled' : '' ?>>
                                    </div>
                                </div>
                                <br>
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <h5 class="bold">All Lights</h5>
                                    <h4 id="allLightsStatus"
                                        class="bold <?= ($light_status === 'on' && $active_schedule) ? 'on' : 'off' ?>">
                                        <?= ($light_status === 'on' && $active_schedule) ? 'ON' : 'OFF' ?>
                                    </h4>
                                    <div id="allLightsContainer"
                                        class="all-lights-<?= ($light_status === 'on' && $active_schedule) ? 'on' : 'off' ?> ..."
                                        style="display:flex; align-items:center; justify-content:center; <?= !$active_schedule ? 'pointer-events:none; opacity:0.4;' : '' ?>">
                                        <i class="bi bi-power" id="all-lights" style="line-height:1; display:flex; align-items:center; justify-content:center;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div><!-- /col 2 -->


                <!-- ══════════════════════════════
                     COLUMN 3 – RECENT ACTIVITIES
                ══════════════════════════════ -->
                <div class="group-container gap-3">

                    <!-- Recent Activities -->
                    <div style="background-color: #f8f9fa;" class="section-container recents" style="min-height: 420px;">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Recent Activities</h2>
                            </div>
                            <div class="d-flex mx-2 align-items-end">
                                <button class="light mx-2" data-bs-toggle="modal"
                                    data-bs-target="#activityDetailsModal">Details</button>
                            </div>
                        </div>
                        <div class="gap-2">
                            <div class="activity-list px-2 gap-2 align-items-center max-width">
                                <?php if (empty($logs)): ?>
                                    <p class="text-muted">No recent activity yet.</p>
                                <?php else:
                                    foreach ($logs as $log): ?>
                                        <div style="font-size:0.78rem; padding: 4px 0;">
                                            <span class="bold"><?= ucfirst(str_replace('_', ' ', $log['event_type'])) ?></span>
                                            <span class="text-muted"> · <?= htmlspecialchars($log['room_name']) ?></span>
                                            <div class="text-muted" style="font-size:0.72rem;">
                                                <?= date('g:i A · M j', strtotime($log['event_time'])) ?>
                                            </div>
                                        </div>
                                        <hr>
                                    <?php endforeach;
                                endif; ?>
                            </div>
                        </div>
                    </div>

                </div><!-- /col 3 -->

                <?php include '../../php/includes/faculty-sidebar.php'; ?>

                <!-- ══════════════════════════════
                     PROFILE MODAL
                ══════════════════════════════ -->
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
                                            <div
                                                class="avatar-icon d-flex align-items-center justify-content-center flex-shrink-0">
                                                <h3 class="bold mb-0"><?= $initials ?></h3>
                                            </div>
                                            <div>
                                                <h4 class="bold mb-1"><?= $faculty_name ?></h4>
                                                <p class="mb-0">Faculty Member</p>
                                            </div>
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
                                                    <p class="mb-0"><?= htmlspecialchars(mask_email($faculty_email)) ?>
                                                    </p>
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

                <!-- ══════════════════════════════
                     CLASSROOM DETAILS MODAL
                ══════════════════════════════ -->
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
        const CLASSROOM_ID = <?= (int) $classroom_id ?>;
        const FACULTY_ID = <?= (int) $faculty_id ?>;

        // Sidebar trigger
        document.getElementById('sidebarTrigger').addEventListener('click', function () {
            bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('sidebarOffcanvas')).toggle();
        });

        // Refresh
        document.getElementById('refreshBtn').addEventListener('click', () => location.reload());

        // Log a gesture event to the DB
        async function logGestureEvent(gestureLabel, eventType = 'gesture') {
            const form = new FormData();
            form.append('classroom_id', CLASSROOM_ID);
            form.append('faculty_id', FACULTY_ID);
            form.append('event_type', eventType);
            form.append('triggered_by', 'gesture');
            await fetch('../../api/logs.php', { method: 'POST', body: form });
        }

        // Update gesture result display (called from initialize-gesture.js)
        function updateGestureResult(label, accuracy) {
            document.getElementById('gestureResult').textContent = label;
        }

        // ── Lock / Unlock controls ────────────────────────────────────────────
        function lockControls() {
            ['row-1-switch','row-2-switch','row-3-switch'].forEach(id => {
                const el = document.getElementById(id);
                if (el) { el.disabled = true; el.closest('.form-check')?.classList.add('opacity-50'); }
            });
            const pwr = document.getElementById('allLightsContainer');
            if (pwr) { pwr.style.pointerEvents = 'none'; pwr.style.opacity = '0.4'; }
            const camBtn = document.getElementById('enableCameraBtn');
            if (camBtn) { camBtn.disabled = true; camBtn.title = 'No active schedule'; }
            const disBtn = document.getElementById('disableCameraBtn');
            if (disBtn) { disBtn.disabled = true; }
            const notice = document.getElementById('scheduleEndNotice');
            if (notice) notice.style.display = 'block';
        }

        function unlockControls() {
            ['row-1-switch','row-2-switch','row-3-switch'].forEach(id => {
                const el = document.getElementById(id);
                if (el) { el.disabled = false; el.closest('.form-check')?.classList.remove('opacity-50'); }
            });
            const pwr = document.getElementById('allLightsContainer');
            if (pwr) { pwr.style.pointerEvents = ''; pwr.style.opacity = ''; }
            const camBtn = document.getElementById('enableCameraBtn');
            if (camBtn) { camBtn.disabled = false; camBtn.title = ''; }
            const disBtn = document.getElementById('disableCameraBtn');
            if (disBtn) { disBtn.disabled = false; }
            const notice = document.getElementById('scheduleEndNotice');
            if (notice) notice.style.display = 'none';
        }

        // ── Countdown timer ───────────────────────────────────────────────────
        let _scheduleEnd = null;
        (function () {
            const display = document.getElementById('timerDisplay');
            const phpEnd = display ? display.dataset.end : null;
            if (phpEnd) _scheduleEnd = phpEnd;

            function pad(n) { return String(n).padStart(2, '0'); }

            window._tickTimer = function () {
                if (!display) return;
                if (!_scheduleEnd) {
                    display.textContent = '00:00:00';
                    display.classList.remove('text-danger');
                    lockControls();
                    return;
                }
                const now = new Date();
                const [h, m, s] = _scheduleEnd.split(':').map(Number);
                const end = new Date(now);
                end.setHours(h, m, s, 0);
                let diff = Math.max(0, Math.floor((end - now) / 1000));
                display.textContent = `${pad(Math.floor(diff / 3600))}:${pad(Math.floor((diff % 3600) / 60))}:${pad(diff % 60)}`;
                if (diff === 0) {
                    display.classList.add('text-danger');
                    lockControls();
                } else {
                    display.classList.remove('text-danger');
                    unlockControls();
                }
            };
            window._tickTimer();
            setInterval(window._tickTimer, 1000);
        })();

        // ── System Uptime ─────────────────────────────────────────────────────
        let _uptimeStart = null;
        (function () {
            const el = document.getElementById('statusUptime');
            function pad(n) { return String(n).padStart(2, '0'); }
            window._tickUptime = function () {
                if (!el) return;
                if (!_uptimeStart) { el.textContent = '00:00:00'; return; }
                let diff = Math.max(0, Math.floor((Date.now() - _uptimeStart) / 1000));
                el.textContent = `${pad(Math.floor(diff / 3600))}:${pad(Math.floor((diff % 3600) / 60))}:${pad(diff % 60)}`;
            };
            window._tickUptime();
            setInterval(window._tickUptime, 1000);
        })();

        // ── Live dashboard poll (every 3 s) ───────────────────────────────────
        const BULB_ON = '../../images/bulb-on.png';
        const BULB_OFF = '../../images/bulb-off.png';
        let _lastLightStatus = '<?= $light_status ?>';

        async function pollDashboard() {
            try {
                const res = await fetch(`../../api/faculty-status.php?classroom_id=${CLASSROOM_ID}`);
                if (!res.ok) return;
                const data = await res.json();
                if (!data.success) return;

                const r1 = data.row1_status || 'off';
                const r2 = data.row2_status || 'off';
                const r3 = data.row3_status || 'off';

                document.querySelectorAll('.bulb-img[data-row="1"]').forEach(img => img.src = r1 === 'on' ? BULB_ON : BULB_OFF);
                document.querySelectorAll('.bulb-img[data-row="2"]').forEach(img => img.src = r2 === 'on' ? BULB_ON : BULB_OFF);
                document.querySelectorAll('.bulb-img[data-row="3"]').forEach(img => img.src = r3 === 'on' ? BULB_ON : BULB_OFF);

                const sw1 = document.getElementById('row-1-switch'); if (sw1) sw1.checked = (r1 === 'on');
                const sw2 = document.getElementById('row-2-switch'); if (sw2) sw2.checked = (r2 === 'on');
                const sw3 = document.getElementById('row-3-switch'); if (sw3) sw3.checked = (r3 === 'on');

                const overallBadgeOn = (r1 === 'on' || r2 === 'on' || r3 === 'on');

                if (overallBadgeOn !== _lastLightStatus) {
                    _lastLightStatus = overallBadgeOn;

                    const badge = document.getElementById('allLightsStatus');
                    const btnCont = document.getElementById('allLightsContainer');
                    if (badge) {
                        badge.textContent = overallBadgeOn ? 'ON' : 'OFF';
                        badge.className = `bold ${overallBadgeOn ? 'on' : 'off'}`;
                    }
                    if (btnCont) {
                        btnCont.className = btnCont.className
                            .replace(/all-lights-(on|off)/, `all-lights-${overallBadgeOn ? 'on' : 'off'}`);
                    }

                    const sLight = document.getElementById('statusLighting');
                    if (sLight) {
                        sLight.textContent = overallBadgeOn ? 'ON' : 'OFF';
                        sLight.className = overallBadgeOn ? 'text-success' : 'text-danger';
                    }
                }

                _scheduleEnd = data.schedule_end || null;
                if (!_scheduleEnd) lockControls(); else unlockControls();

                const pirEl = document.getElementById('statusPir');
                if (data.pir_occupied && data.pir_since) {
                    _uptimeStart = new Date(data.pir_since.replace(' ', 'T')).getTime();
                    if (pirEl) { pirEl.textContent = 'Occupied'; pirEl.className = 'text-success'; }
                } else {
                    _uptimeStart = null;
                    if (pirEl) { pirEl.textContent = 'Empty'; pirEl.className = 'text-muted'; }
                }

            } catch (e) {
                console.warn('pollDashboard error:', e);
            }
        }

        pollDashboard();
        setInterval(pollDashboard, 3000);

        // ── PIR Simulate button ───────────────────────────────────────────────
        const simBtn = document.getElementById('simulatePirBtn');
        if (simBtn) {
            let _pirState = false;
            simBtn.addEventListener('click', async () => {
                _pirState = !_pirState;
                simBtn.textContent = _pirState ? '🔴 Simulate Empty Room' : '🟢 Simulate Occupancy';
                simBtn.className = `btn btn-sm mt-1 w-100 ${_pirState ? 'btn-danger' : 'btn-success'}`;
                const form = new FormData();
                form.append('classroom_id', CLASSROOM_ID);
                form.append('occupied', _pirState ? '1' : '0');
                await fetch('../../api/pir.php', { method: 'POST', body: form });
                await pollDashboard();
            });
        }
    </script>

    <!-- Gesture detection script -->
    <script type="module" src="../../script/initialize-gesture.js?v=<?= time() ?>"></script>

    <!-- ══════════════════════════════
         GESTURE HELP MODAL
    ══════════════════════════════ -->
    <div class="modal fade" id="gestureHelpModal" tabindex="-1" aria-labelledby="gestureHelpLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold" id="gestureHelpLabel">
                        <i class="bi bi-hand-index-thumb me-2"></i>Gesture Guide
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex align-items-center gap-3 p-2 bg-light rounded-3">
                            <span style="font-size:1.6rem;">☝️</span>
                            <div><strong>1 finger</strong> — Select Row 1</div>
                        </div>
                        <div class="d-flex align-items-center gap-3 p-2 bg-light rounded-3">
                            <span style="font-size:1.6rem;">✌️</span>
                            <div><strong>2 fingers</strong> — Select Row 2</div>
                        </div>
                        <div class="d-flex align-items-center gap-3 p-2 bg-light rounded-3">
                            <span style="font-size:1.6rem;">🤟</span>
                            <div><strong>ILY sign</strong> — Select Row 3</div>
                        </div>
                        <div class="d-flex align-items-center gap-3 p-2 bg-light rounded-3">
                            <span style="font-size:1.6rem;">👍</span>
                            <div><strong>Thumbs up</strong> — Toggle selected row</div>
                        </div>
                        <div class="d-flex align-items-center gap-3 p-2 bg-light rounded-3">
                            <span style="font-size:1.6rem;">✋</span>
                            <div><strong>Open palm</strong> — Turn all lights ON</div>
                        </div>
                        <div class="d-flex align-items-center gap-3 p-2 bg-light rounded-3">
                            <span style="font-size:1.6rem;">✊</span>
                            <div><strong>Fist</strong> — Turn all lights OFF</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════
         ACTIVITY DETAILS MODAL
    ══════════════════════════════ -->
    <div class="modal fade" id="activityDetailsModal" tabindex="-1" aria-labelledby="activityDetailsLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold" id="activityDetailsLabel">
                        <i class="bi bi-clock-history me-2"></i>Recent Activity Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <?php if (empty($logs)): ?>
                        <p class="text-muted text-center py-4">No recent activity yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle" style="font-size:0.85rem;">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-3">Event</th>
                                        <th>Room</th>
                                        <th>Row Affected</th>
                                        <th>Triggered By</th>
                                        <th class="pe-3">Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <!-- Event type badge -->
                                            <td class="ps-3">
                                                <?php
                                                $type = $log['event_type'] ?? '';
                                                $badgeClass = match (true) {
                                                    str_contains($type, 'on')      => 'bg-success',
                                                    str_contains($type, 'off')     => 'bg-danger',
                                                    str_contains($type, 'gesture') => 'bg-primary',
                                                    default                        => 'bg-secondary'
                                                };
                                                ?>
                                                <span class="badge <?= $badgeClass ?> rounded-pill">
                                                    <?= ucfirst(str_replace('_', ' ', $type)) ?>
                                                </span>
                                            </td>

                                            <!-- Room -->
                                            <td><?= htmlspecialchars($log['room_name'] ?? '—') ?></td>

                                            <!-- Row affected -->
                                            <td>
                                                <?php $rowAffected = $log['row_affected'] ?? null; ?>
                                                <?php if ($rowAffected): ?>
                                                    <span class="badge bg-info text-dark rounded-pill">Row
                                                        <?= htmlspecialchars($rowAffected) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">All rows</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Triggered by -->
                                            <td>
                                                <?php $by = $log['triggered_by'] ?? 'manual'; ?>
                                                <span
                                                    class="badge <?= $by === 'gesture' ? 'bg-primary' : 'bg-secondary' ?> rounded-pill">
                                                    <i
                                                        class="bi <?= $by === 'gesture' ? 'bi-hand-index-thumb' : 'bi-toggle-on' ?> me-1"></i>
                                                    <?= ucfirst(htmlspecialchars($by)) ?>
                                                </span>
                                            </td>

                                            <!-- Time -->
                                            <td class="pe-3 text-muted" style="white-space:nowrap;">
                                                <?= date('g:i A', strtotime($log['event_time'])) ?>
                                                <div style="font-size:0.72rem;">
                                                    <?= date('M j, Y', strtotime($log['event_time'])) ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════
         EXTEND SCHEDULE MODAL
    ══════════════════════════════ -->
    <?php if ($active_schedule): ?>
        <div class="modal fade" id="extendModal" tabindex="-1" aria-labelledby="extendModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title bold" id="extendModalLabel">
                            <i class="bi bi-clock-history me-2"></i>Request Time Extension
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-3">
                            Current class ends at
                            <strong><?= date('g:i A', strtotime($active_schedule['extended_until'] ?? $active_schedule['end_time'])) ?></strong>.
                            How many extra minutes do you need?
                        </p>
                        <div class="d-flex gap-2 justify-content-center flex-wrap" id="extendPills">
                            <?php foreach ([15, 30, 45, 60] as $mins): ?>
                                <button class="btn btn-outline-primary extend-pill" data-mins="<?= $mins ?>">
                                    +<?= $mins ?> min
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-center text-muted small mt-3 mb-0" id="extendFeedback"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="submitExtendBtn" disabled>
                            Send Request
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            (function () {
                const SCHEDULE_ID = <?= (int) $active_schedule['id'] ?>;
                let selectedMins = 0;

                document.querySelectorAll('.extend-pill').forEach(btn => {
                    btn.addEventListener('click', () => {
                        document.querySelectorAll('.extend-pill').forEach(b => b.classList.remove('active', 'btn-primary'));
                        btn.classList.add('active', 'btn-primary');
                        btn.classList.remove('btn-outline-primary');
                        selectedMins = parseInt(btn.dataset.mins);
                        document.getElementById('submitExtendBtn').disabled = false;
                        document.getElementById('extendFeedback').textContent = '';
                    });
                });

                document.getElementById('submitExtendBtn').addEventListener('click', async () => {
                    const btn = document.getElementById('submitExtendBtn');
                    const feedback = document.getElementById('extendFeedback');
                    btn.disabled = true;
                    btn.textContent = 'Sending…';

                    const form = new FormData();
                    form.append('schedule_id', SCHEDULE_ID);
                    form.append('extend_mins', selectedMins);

                    try {
                        const res = await fetch('../../api/request-extension.php', { method: 'POST', body: form });
                        const data = await res.json();
                        feedback.textContent = data.message;
                        feedback.style.color = data.success ? 'green' : 'red';
                        if (data.success) {
                            btn.textContent = 'Sent ✓';
                        } else {
                            btn.disabled = false;
                            btn.textContent = 'Send Request';
                        }
                    } catch {
                        feedback.textContent = 'Network error. Please try again.';
                        feedback.style.color = 'red';
                        btn.disabled = false;
                        btn.textContent = 'Send Request';
                    }
                });
            })();
        </script>
    <?php endif; ?>

    <!-- ══════════════════════════════
         VIEW SCHEDULE MODAL
    ══════════════════════════════ -->
    <div class="modal fade" id="viewScheduleModal" tabindex="-1"
        aria-labelledby="viewScheduleLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold" id="viewScheduleLabel">
                        <i class="bi bi-calendar-week me-2"></i>Class Schedule
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-column gap-3">
                        <?php if (!empty($schedules)): ?>
                            <?php
                            $dayOrder = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
                            usort($schedules, function($a, $b) use ($dayOrder) {
                                $da = array_search($a['day_of_week'], $dayOrder);
                                $db = array_search($b['day_of_week'], $dayOrder);
                                return $da !== $db ? $da - $db : strcmp($a['start_time'], $b['start_time']);
                            });
                            $dayIcons = [
                                'Monday'    => 'bi-1-square-fill',
                                'Tuesday'   => 'bi-2-square-fill',
                                'Wednesday' => 'bi-3-square-fill',
                                'Thursday'  => 'bi-4-square-fill',
                                'Friday'    => 'bi-5-square-fill',
                                'Saturday'  => 'bi-6-square-fill',
                                'Sunday'    => 'bi-7-square-fill',
                            ];
                            $today = date('l');
                            foreach ($schedules as $sched):
                                $isToday  = ($sched['day_of_week'] === $today);
                                $icon     = $dayIcons[$sched['day_of_week']] ?? 'bi-calendar';
                                $start    = date('g:i A', strtotime($sched['start_time']));
                                $end      = date('g:i A', strtotime($sched['end_time']));
                            ?>
                            <div class="d-flex align-items-center gap-3 p-2 rounded-3
                                <?= $isToday ? 'bg-primary bg-opacity-10 border border-primary border-opacity-25' : 'bg-light' ?>">
                                <i class="bi <?= $icon ?> <?= $isToday ? 'text-primary' : 'text-secondary' ?>"
                                    style="font-size:1.6rem; flex-shrink:0;"></i>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <strong><?= htmlspecialchars($sched['day_of_week']) ?></strong>
                                        <?php if ($isToday): ?>
                                            <span class="badge bg-primary rounded-pill" style="font-size:0.7rem;">Today</span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted">
                                        <i class="bi bi-clock me-1"></i><?= $start ?> — <?= $end ?>
                                    </small>
                                    <?php if (!empty($sched['subject_name'])): ?>
                                        <div style="font-size:0.8rem;" class="text-secondary">
                                            <i class="bi bi-book me-1"></i><?= htmlspecialchars($sched['subject_name']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="d-flex align-items-center gap-3 p-2 bg-light rounded-3 text-muted">
                                <i class="bi bi-calendar-x" style="font-size:1.6rem;"></i>
                                <div>No schedules found for this classroom.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

</body>

</html>