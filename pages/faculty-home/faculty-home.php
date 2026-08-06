<?php
$page_title = 'Faculty Dashboard';

require_once __DIR__ . "/../../src/Session/session_guard.php";
check_faculty();
require_once __DIR__ . "/../../src/Config/db_connect.php";
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . "/../../src/Includes/faculty-head.php";

/** @var $faculty_name string */
/** @var $faculty_email string */
/** @var $initials string */
/** @var $first_name string */
/** @var $faculty_id int */
/** @var $classroom_id int */
/** @var $logs array */
/** @var $gesture_logs array */
/** @var $schedules array */

$active_schedule = null;
$now   = date('H:i:s');
$today = date('l');

$fid      = (int)$faculty_id;
$today_e  = $conn->real_escape_string($today);
$now_e    = $conn->real_escape_string($now);

$r = $conn->query("
    SELECT s.id, s.classroom_id, s.start_time, s.end_time, s.extended_until, c.room_name
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    WHERE s.classroom_id = $classroom_id
      AND s.day_of_week = '$today_e'
      AND s.start_time <= '$now_e'
      AND (s.end_time >= '$now_e' OR s.extended_until >= '$now_e')
    LIMIT 1
");
if ($r && $row = $r->fetch_assoc()) {
    $active_schedule = $row;
}

// Check if faculty has set a PIN
$pin_check = $conn->prepare("SELECT pin_hash FROM faculty_permissions WHERE faculty_id = ?");
$pin_check->bind_param("i", $faculty_id);
$pin_check->execute();
$pin_result = $pin_check->get_result();
$has_pin = $pin_result && $pin_result->fetch_assoc() && !empty($pin_result->fetch_assoc()['pin_hash']);
// Fix: actually check the row properly
$pin_row = $pin_result->fetch_assoc();
$has_pin = $pin_row && !empty($pin_row['pin_hash']);

$schedules = [];
$sched_res = $conn->query("
    SELECT s.id, s.day_of_week, s.start_time, s.end_time, s.extended_until,
           sub.name AS subject_name, c.room_name
    FROM schedules s
    LEFT JOIN subjects sub ON sub.id = s.subject_id
    LEFT JOIN classrooms c ON c.id = s.classroom_id
    WHERE s.classroom_id = $classroom_id
    ORDER BY FIELD(s.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), s.start_time
");
if ($sched_res) {
    while ($srow = $sched_res->fetch_assoc()) $schedules[] = $srow;
}

// Handle end early POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['end_early'])) {
    $sched_id = (int)$_POST['end_early'];

    $stmt = $conn->prepare("
        SELECT s.classroom_id, c.room_name
        FROM schedules s
        JOIN classrooms c ON c.id = s.classroom_id
        WHERE s.id = ? AND s.faculty_id = ?
    ");
    $stmt->bind_param('ii', $sched_id, $faculty_id);
    $stmt->execute();
    $stmt->bind_result($cid, $room_name);
    $has_row = $stmt->fetch();
    $stmt->close();

    if ($has_row && $cid) {
        $conn->query("UPDATE schedules SET extended_until = CURTIME() WHERE id = $sched_id");
        $conn->query("UPDATE classrooms SET light_status = 'off', row1_status = 'off', row2_status = 'off', row3_status = 'off', light_override = 0, schedule_dirty = 1 WHERE id = $cid");
        $conn->query("INSERT INTO lighting_logs (classroom_id, faculty_id, event_type, triggered_by) VALUES ($cid, $faculty_id, 'off', 'faculty_end_early')");
        $conn->query("INSERT INTO class_logs (classroom_id, event_type, triggered_by, notes) VALUES ($cid, 'class_end', 'faculty_end_early', 'Schedule ended early by faculty')");

        $_SESSION['timetable_success'] = "Class in {$room_name} ended early.";
    } else {
        $_SESSION['timetable_error'] = 'Schedule not found or access denied.';
    }

    header('Location: faculty-home.php');
    exit;
}

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

// ── Overlay hierarchy helper ──────────────────────────────────────────────────
function get_overlay_reason($has_sched, $permitted, $active) {
    if (!$has_sched) return 'no_schedule';
    if (!$permitted) return 'admin_restricted';
    if (!$active)    return 'schedule_ended';
    return null;
}
$gesture_reason   = get_overlay_reason($has_any_schedule, $permissions['gesture_control'],  $active_schedule);
$lighting_reason  = get_overlay_reason($has_any_schedule, $permissions['lighting_control'], $active_schedule);
$gesture_blocked  = $gesture_reason !== null;
$lighting_blocked = $lighting_reason !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="icon" type="image/png" href="../../images/icon.png">
    <link rel="stylesheet" href="../../css/base/global.css">
    <link rel="stylesheet" href="../../css/base/containers.css">
    <link rel="stylesheet" href="../../css/base/tooltip.css">
    <link rel="stylesheet" href="../../css/base/modals.css">
    <link rel="stylesheet" href="../../css/pages/faculty-home.css">
    <link rel="stylesheet" href="../../css/faculty/common.css">
    <title>Faculty Dashboard - LumineSense</title>
</head>
<body class="contrast-bg">
<div class="parent-container">

    <?php include __DIR__ . "/../../src/Includes/faculty-topbar.php"; ?>

    <div class="d-flex flex-row" style="width:100%;flex:1;position:relative;">
        <div class="child-container gap-3" style="flex:1;min-width:0;">

            <div class="main-container homepage gap-3">

                <!-- COLUMN 1 - GESTURE DETECTION -->
                <div class="group-container gap-3">

                    <!-- Gesture Detection -->
                    <div id="gestureSection" style="background-color: #f8f9fa;" class="section-container p-2" data-gesture-blocked="<?= $gesture_blocked ? '1' : '0' ?>">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Gesture Detection</h2>
                            </div>
                            <div class="d-flex mx-2 align-items-end gap-1">
                                <button class="light" id="gestureTestBtn" onclick="toggleGestureTestMode()" title="Test Mode: temporarily bypass the schedule lock so you can test gestures" data-bs-tooltip>
                                    <i class="bi bi-bug me-1"></i>Test
                                </button>
                                <button class="light" id="refreshBtn" title="Refresh" data-bs-toggle="tooltip">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                                <button class="light" data-bs-toggle="modal" data-bs-target="#gestureHelpModal" title="Gesture Guide" data-bs-tooltip>
                                    <i class="bi bi-question-circle"></i>
                                </button>
                                <button class="light" id="gestureMaximizeBtn" onclick="toggleGestureMaximize()" title="Maximize" data-bs-toggle="tooltip">
                                    <i class="bi bi-arrows-expand"></i>
                                </button>
                            </div>
                        </div>

                        <div id="gestureControlsWrapper" style="position: relative;">
                            <div id="gestureControlsContent"
                                <?= $gesture_blocked ? 'style="filter:blur(6px);pointer-events:none;"' : '' ?>>

                                <!-- Camera feed -->
                                <div class="gesture-camera d-flex flex-row align-items-center justify-content-center"
                                    style="position: relative;">
                                    <button id="enableCameraBtn" class="btn btn-primary btn-sm" style="z-index: 10;" <?= $gesture_blocked ? 'disabled title="No active schedule"' : '' ?>>
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
                                <div class="gesture-response d-flex px-2 flex-column align-items-start justify-content-start gap-2">

                                    <!-- Row indicator pills -->
                                    <div class="gesture-row-pills w-100 d-flex justify-content-center gap-2 mt-1">
                                        <span class="gesture-row-pill" id="rowPill1" data-row="1">Row 1</span>
                                        <span class="gesture-row-pill" id="rowPill2" data-row="2">Row 2</span>
                                        <span class="gesture-row-pill" id="rowPill3" data-row="3">Row 3</span>
                                    </div>

                                    <!-- Stacked command queue -->
                                    <div id="stackQueueWrap" class="stack-queue-wrap w-100" style="display:none;">
                                        <div class="d-flex align-items-center justify-content-between w-100 mb-1">
                                            <span class="text-muted" style="font-size:0.78rem;">Command Queue:</span>
                                            <span class="stack-queue-count" id="stackQueueCount">0/4</span>
                                        </div>
                                        <div id="pendingStackQueue" class="stack-queue w-100"></div>
                                        <span class="text-muted" style="font-size:0.7rem;">Hold <strong>👍 Thumbs Up</strong> to confirm all queued commands, or <strong>✊ Fist</strong> to clear.</span>
                                    </div>

                                    <!-- Result label -->
                                    <div class="d-flex align-items-center gap-1">
                                        <span class="text-muted" style="font-size:0.85rem;">Detected:</span>
                                        <span class="bold mx-1" id="gestureResult">&mdash;</span>
                                    </div>

                                    <!-- Gesture image (visible only when maximized) -->
                                    <div id="gestureImageContainer" class="gesture-image-container w-100" style="display:none;">
                                        <div class="gesture-list-heading" id="gestureListHeading">Available Gestures</div>
                                        <div id="gestureImageList" class="gesture-image-list">
                                            <div class="gesture-list-item" data-gesture="Pointing_Up">
                                                <img src="../../images/pointing-up.png" alt="Pointing Up">
                                                <div class="gesture-list-info">
                                                    <span class="gesture-list-name">Pointing Up</span>
                                                    <span class="gesture-list-desc">Toggle Row 1 ON/OFF</span>
                                                </div>
                                            </div>
                                            <div class="gesture-list-item" data-gesture="Victory">
                                                <img src="../../images/victory.png" alt="Victory">
                                                <div class="gesture-list-info">
                                                    <span class="gesture-list-name">Victory</span>
                                                    <span class="gesture-list-desc">Toggle Row 2 ON/OFF</span>
                                                </div>
                                            </div>
                                            <div class="gesture-list-item" data-gesture="ILoveYou">
                                                <img src="../../images/ily.png" alt="I Love You">
                                                <div class="gesture-list-info">
                                                    <span class="gesture-list-name">I Love You</span>
                                                    <span class="gesture-list-desc">Toggle Row 3 ON/OFF</span>
                                                </div>
                                            </div>
                                            <div class="gesture-list-item" data-gesture="Open_Palm">
                                                <img src="../../images/open-palm.png" alt="Open Palm">
                                                <div class="gesture-list-info">
                                                    <span class="gesture-list-name">Open Palm</span>
                                                    <span class="gesture-list-desc">Turn all lights ON</span>
                                                </div>
                                            </div>
                                            <div class="gesture-list-item" data-gesture="Closed_Fist">
                                                <img src="../../images/closed-fist.png" alt="Closed Fist">
                                                <div class="gesture-list-info">
                                                    <span class="gesture-list-name">Closed Fist</span>
                                                    <span class="gesture-list-desc">Turn all lights OFF</span>
                                                </div>
                                            </div>
                                            <div class="gesture-list-item" data-gesture="Thumb_Up">
                                                <img src="../../images/thumbs-up.png" alt="Thumbs Up">
                                                <div class="gesture-list-info">
                                                    <span class="gesture-list-name">Thumbs Up</span>
                                                    <span class="gesture-list-desc">Confirm pending action</span>
                                                </div>
                                            </div>
                                        </div>
                                        <img id="gestureImage" src="" alt="Detected gesture" style="display:none;">
                                    </div>

                                    <!-- Action buttons -->
                                    <div class="w-100 d-flex justify-content-center">
                                        <div class="gesture-toggle-group d-flex">
                                            <button class="light toggle-btn active" id="chromaKeyToggle" onclick="toggleChromaKey()" title="Highlight hand and dim the background - On by default" data-bs-toggle="tooltip" data-bs-placement="top">
                                                <i class="bi bi-brightness-high me-1"></i> Chroma
                                            </button>
                                            <button class="light toggle-btn active" id="enhanceToggle" onclick="toggleEnhance()" title="Boost contrast, brightness &amp; saturation - On by default" data-bs-toggle="tooltip" data-bs-placement="top">
                                                <i class="bi bi-sliders me-1"></i> Enhance
                                            </button>
                                        </div>
                                    </div>
                                </div>

                            </div>
                            <!-- Gesture overlay (hierarchy: no_schedule > admin_restricted > schedule_ended) -->
                            <div id="gestureScheduleOverlay" class="schedule-ended-overlay" <?= $gesture_reason ? '' : 'style="display:none;"' ?>>
                                <div class="schedule-ended-modal" id="gestNoSched" style="display:<?= $gesture_reason === 'no_schedule' ? 'block' : 'none' ?>">
                                    <i class="bi bi-calendar-x schedule-ended-icon"></i>
                                    <h5 class="schedule-ended-title">No Schedule Assigned</h5>
                                    <p class="schedule-ended-text">You don't have a schedule yet. Please contact your Faculty Head to get assigned.</p>
                                </div>
                                <div class="schedule-ended-modal" id="gestAdminRestr" style="display:<?= $gesture_reason === 'admin_restricted' ? 'block' : 'none' ?>">
                                    <i class="bi bi-shield-lock schedule-ended-icon"></i>
                                    <h5 class="schedule-ended-title">Access Restricted</h5>
                                    <p class="schedule-ended-text">Your access has been restricted by the administrator.</p>
                                </div>
                                <div class="schedule-ended-modal" id="gestSchedEnded" style="display:<?= $gesture_reason === 'schedule_ended' ? 'block' : 'none' ?>">
                                    <i class="bi bi-lock-fill schedule-ended-icon"></i>
                                    <h5 class="schedule-ended-title">Access Locked</h5>
                                    <p class="schedule-ended-text">Your schedule has ended. Controls are now locked.</p>
                                </div>
                            </div>
                            <!-- Gesture PIN overlay (when active + PIN set + not yet verified) -->
                            <div id="gesturePinOverlay" class="schedule-ended-overlay" style="display:none;">
                                <div class="schedule-ended-modal">
                                    <i class="bi bi-shield-lock schedule-ended-icon" style="color:var(--secondary-color-4);"></i>
                                    <h5 class="schedule-ended-title">PIN Required</h5>
                                    <p class="schedule-ended-text">Enter your PIN to access Gesture Control.</p>
                                    <div class="mt-2 d-flex flex-column align-items-center gap-1">
                                        <input type="password" class="form-control text-center pin-input" maxlength="4" pattern="\d*" inputmode="numeric" placeholder="****">
                                        <span class="text-danger small pin-error"></span>
                                        <button class="light pin-submit-btn">Unlock</button>
                                    </div>
                                </div>
                            </div>
                            <!-- Gesture loading overlay (shown while AI model + landmarks initialize) -->
                            <div id="gestureLoadingOverlay" class="gesture-loading-overlay" style="display:none;">
                                <div class="gesture-loading-spinner">
                                    <div class="spinner-border text-light" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <span>Preparing gesture control...</span>
                                </div>
                            </div>

                            <!-- Camera warning modal (shown when the webcam cannot be started) -->
                            <div class="notify-modal" id="cameraWarningModal">
                                <div class="modal-box">
                                    <div id="modal-header">
                                        <h5><strong>!</strong> Camera Unavailable</h5>
                                    </div>
                                    <div id="modal-body">
                                        <i class="bi bi-exclamation-triangle" id="cautionTriangle"></i>
                                        <h5>Could not start camera.</h5>
                                        <p class="small" style="max-width:360px;margin:0;">
                                            Make sure that:<br>
                                            1. You have allowed camera permission for this site.<br>
                                            2. No other application is using your webcam.
                                        </p>
                                    </div>
                                    <div id="modal-footer">
                                        <button class="medium" type="button" onclick="hideCameraWarningModal()">OK</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- System Status -->
                    <div style="background-color: #f8f9fa;" class="section-container p-2">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">System Status</h2>
                            </div>
                        </div>
                        <div class="activity-list system-status px-2 gap-2 max-width">
                            <?php
                            $statuses = [
                                ['label' => 'Server',         'id' => 'statusServer',   'ok' => true,                          'ok_text' => 'Connected',           'fail_text' => 'Disconnected'],
                                ['label' => 'Lighting System', 'id' => 'statusLighting', 'ok' => ($light_status === 'on'),      'ok_text' => 'Active',              'fail_text' => 'No active lights'],
                                ['label' => 'Webcam',         'id' => 'statusWebcam',   'ok' => false,                         'ok_text' => 'Active',              'fail_text' => 'Disabled'],
                                ['label' => 'PIR Sensor',     'id' => 'statusPIR',      'ok' => false,                         'ok_text' => 'Detecting motion',    'fail_text' => 'No motion detected'],
                            ];
                            foreach ($statuses as $s):
                                $bg_color = $s['ok'] ? '#f9edfa' : '#2f004f';
                                $text_color = $s['ok'] ? '#2f004f' : '#ffffff';
                            ?>
                                <div class="d-flex justify-content-between align-items-center py-1" style="border-bottom:1px solid #eee;">
                                    <h5 class="mb-0" style="font-size:13px;"><?= $s['label'] ?></h5>
                                    <span id="<?= $s['id'] ?>" data-ok-text="<?= $s['ok_text'] ?>" data-fail-text="<?= $s['fail_text'] ?>"
                                        style="font-size:12px; padding:2px 10px; border-radius:20px; font-weight:600;
                                        background:<?= $bg_color ?>;
                                        color:<?= $text_color ?>;">
                                        <?= $s['ok'] ? $s['ok_text'] : $s['fail_text'] ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div><!-- /col 1 -->

                <!-- COLUMN 2 - LIGHTING -->
                <div class="group-container gap-3">

                    <!-- Lighting Grid -->
                    <div style="background-color: #f8f9fa;" class="fit-width section-container p-2" data-lighting-blocked="<?= $lighting_blocked ? '1' : '0' ?>">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Lighting Grid</h2>
                            </div>
                        </div>
                        <?php
                        $b1 = ($row1_status === 'on' && $active_schedule) ? '../../images/bulb-on.png' : '../../images/bulb-off.png';
                        $b2 = ($row2_status === 'on' && $active_schedule) ? '../../images/bulb-on.png' : '../../images/bulb-off.png';
                        $b3 = ($row3_status === 'on' && $active_schedule) ? '../../images/bulb-on.png' : '../../images/bulb-off.png';
                        ?>
                        <!-- Lighting controls container -->
                        <div id="lightingControlsWrapper" style="position: relative;">
                            <div class="d-flex flex-row align-items-center justify-content-center" id="lightingControlsContent"
                                <?= $lighting_blocked ? 'style="filter:blur(6px);pointer-events:none;"' : '' ?>>
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
                            <div class="p-3">
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <label class="form-check-label" for="row-1-switch">Row 1</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="row-1-switch"
                                            <?= ($row1_status === 'on' && $active_schedule) ? 'checked' : '' ?>
                                            <?= $lighting_blocked ? 'disabled' : '' ?>>
                                    </div>
                                </div>
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <label class="form-check-label" for="row-2-switch">Row 2</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="row-2-switch"
                                            <?= ($row2_status === 'on' && $active_schedule) ? 'checked' : '' ?>
                                            <?= $lighting_blocked ? 'disabled' : '' ?>>
                                    </div>
                                </div>
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <label class="form-check-label" for="row-3-switch">Row 3</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="row-3-switch"
                                            <?= ($row3_status === 'on' && $active_schedule) ? 'checked' : '' ?>
                                            <?= $lighting_blocked ? 'disabled' : '' ?>>
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
                                        class="all-lights-<?= ($light_status === 'on' && $active_schedule) ? 'on' : 'off' ?>"
                                        style="display:flex; align-items:center; justify-content:center; <?= $lighting_blocked ? 'pointer-events:none; opacity:0.4;' : '' ?>">
                                        <i class="bi bi-power" id="all-lights" style="line-height:1; display:flex; align-items:center; justify-content:center;"></i>
                                    </div>
                                </div>
                                </div>
                            </div>
                            <!-- Lighting overlay (hierarchy: no_schedule > admin_restricted > schedule_ended) -->
                            <div id="scheduleEndOverlay" class="schedule-ended-overlay" <?= $lighting_reason ? '' : 'style="display:none;"' ?>>
                                <div class="schedule-ended-modal" id="lightNoSched" style="display:<?= $lighting_reason === 'no_schedule' ? 'block' : 'none' ?>">
                                    <i class="bi bi-calendar-x schedule-ended-icon"></i>
                                    <h5 class="schedule-ended-title">No Schedule Assigned</h5>
                                    <p class="schedule-ended-text">You don't have a schedule yet. Please contact your Faculty Head to get assigned.</p>
                                </div>
                                <div class="schedule-ended-modal" id="lightAdminRestr" style="display:<?= $lighting_reason === 'admin_restricted' ? 'block' : 'none' ?>">
                                    <i class="bi bi-shield-lock schedule-ended-icon"></i>
                                    <h5 class="schedule-ended-title">Access Restricted</h5>
                                    <p class="schedule-ended-text">Your access has been restricted by the administrator.</p>
                                </div>
                                <div class="schedule-ended-modal" id="lightSchedEnded" style="display:<?= $lighting_reason === 'schedule_ended' ? 'block' : 'none' ?>">
                                    <i class="bi bi-lock-fill schedule-ended-icon"></i>
                                    <h5 class="schedule-ended-title">Access Locked</h5>
                                    <p class="schedule-ended-text">Your schedule has ended. Controls are now locked.</p>
                                </div>
                            </div>
                            <!-- Lighting PIN overlay (when active + PIN set + not yet verified) -->
                            <div id="lightingPinOverlay" class="schedule-ended-overlay" style="display:none;">
                                <div class="schedule-ended-modal">
                                    <i class="bi bi-shield-lock schedule-ended-icon" style="color:var(--secondary-color-4);"></i>
                                    <h5 class="schedule-ended-title">PIN Required</h5>
                                    <p class="schedule-ended-text">Enter your PIN to access Lighting Control.</p>
                                    <div class="mt-2 d-flex flex-column align-items-center gap-1">
                                        <input type="password" class="form-control text-center pin-input" maxlength="4" pattern="\d*" inputmode="numeric" placeholder="****">
                                        <span class="text-danger small pin-error"></span>
                                        <button class="light pin-submit-btn">Unlock</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div><!-- /col 2 -->

                <!-- COLUMN 3 - TIME LEFT + RECENT ACTIVITIES -->
                <div class="group-container recent-activities gap-3">

                    <!-- Time Left (moved from Column 2) -->
                    <div style="background-color: #f8f9fa;" class="section-container">
                        <div class="gap-1 align-items-center">
                            <div class="section-topbar mx-2 justify-content-between">
                                <div>
                                    <h2 class="bold">Time Left</h2>
                                    <h2 class="medium fs-6">until end of class</h2>
                                </div>

                            </div>
                            <div class="subsection-container d-flex flex-column mx-1 align-items-center justify-content-center">
                                <?php if ($active_schedule): ?>
                                    <?php
                                    $end = $active_schedule['extended_until'] ?? $active_schedule['end_time'];
                                    ?>
                                    <h1 class="bold display-1 p-2" id="timerDisplay" style="color: var(--secondary-color-2);" data-end="<?= htmlspecialchars($end) ?>" >
                                        --:--:--
                                    </h1>
                                <?php else: ?>
                                    <h1 class="bold display-1 p-2" id="timerDisplay" style="color: var(--secondary-color-2);">00:00:00</h1>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex flex-row flex-nowrap mx-2 align-items-center justify-content-center gap-2">
                                <?php if ($active_schedule): ?>
                                    <button class="light text-nowrap" data-bs-toggle="modal" data-bs-target="#extendModal">
                                        <i class="bi bi-clock-history me-1"></i> Extend
                                    </button>
                                    <button class="danger text-nowrap" onclick="openEndEarlyModal(<?= $active_schedule['id'] ?>, '<?= htmlspecialchars($active_schedule['room_name']) ?>')">
                                        <i class="bi bi-stop-circle me-1"></i> End Early
                                    </button>
                                <?php endif; ?>
                                    <button class="light text-nowrap" data-bs-toggle="modal" data-bs-target="#viewScheduleModal">View Schedule</button>
                            </div>
                        </div>

                        <?php if (!$active_schedule): ?>
                            <p class="text-muted text-center mt-2 mb-1">No active class schedule right now.</p>
                        <?php endif; ?>
                    </div>

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
                            <div class="activity-list px-2 max-width" id="activityTimeline">
                                <?php if (empty($logs)): ?>
                                    <p class="text-muted">No recent activity yet.</p>
                                    <?php else:
                                    foreach ($logs as $log):
                                        $iconData = faculty_activity_icon($log);
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
                                                    <?php if (!empty($log['triggered_by'])): ?>
                                                        <span><i class="bi bi-toggle-on"></i> <?= htmlspecialchars(ucfirst($log['triggered_by'])) ?></span>
                                                    <?php endif; ?>
                                                    <span class="tl-type-badge" style="background:<?= $iconData['typeBg'] ?>; color:<?= $iconData['typeClr'] ?>;"><?= $iconData['typeLabel'] ?></span>
                                                </div>
                                                <?php if (!empty($iconData['notes'])): ?>
                                                    <span class="tl-notes"><i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($iconData['notes']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div> <?php endforeach;
                                        endif; ?>
                            </div>
                        </div>
                    </div>

                </div><!-- /col 3 -->

            </div>

    <link rel="stylesheet" href="../../css/pages/faculty-home.css">

    <script>
        const CLASSROOM_ID = <?= (int) $classroom_id ?>;
        const FACULTY_ID = <?= (int) $faculty_id ?>;
        const HAS_ACTIVE_SCHEDULE = <?= $active_schedule ? 'true' : 'false' ?>;
    </script>
    <script src="../../js/faculty/faculty-home.js?v=<?= time() ?>"></script>

    <!-- Countdown timer for Time Left widget -->
    <script>
    (function() {
        var display = document.getElementById('timerDisplay');
        if (!display) return;
        var _scheduleEnd = display.dataset.end || null;

        function pad(n) { return String(n).padStart(2, '0'); }

        function setTimerColor(diff) {
            if (diff <= 900) { display.style.color = '#dc3545'; }
            else if (diff <= 1800) { display.style.color = '#ff8c00'; }
            else { display.style.color = 'var(--secondary-color-2)'; }
        }

        window._updateScheduleEnd = function(newEnd) {
            _scheduleEnd = newEnd;
            if (display) display.dataset.end = newEnd;
            var schedData = document.getElementById('scheduleEndData');
            if (schedData) schedData.dataset.end = newEnd;
            tick();
        };

        function tick() {
            if (!_scheduleEnd) {
                display.textContent = '00:00:00';
                display.style.color = '#6c757d';
                return;
            }
            var parts = _scheduleEnd.split(':').map(Number);
            var end = new Date();
            end.setHours(parts[0], parts[1], parts[2], 0);
            var diff = Math.max(0, Math.floor((end - Date.now()) / 1000));
            display.textContent = pad(Math.floor(diff / 3600)) + ':' + pad(Math.floor((diff % 3600) / 60)) + ':' + pad(diff % 60);
            setTimerColor(diff);
        }
        tick();
        setInterval(tick, 1000);

        var pollCid = <?= $active_schedule ? (int)$active_schedule['classroom_id'] : 0 ?>;
        setInterval(function() {
            var cid = pollCid;
            if (!cid) return;
            fetch('../../api/faculty-status.php?classroom_id=' + cid)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.schedule_active && data.schedule_end) {
                        var current = _scheduleEnd || '';
                        if (data.schedule_end !== current) {
                            window._updateScheduleEnd(data.schedule_end);
                        }
                    } else {
                        _scheduleEnd = null;
                        tick();
                    }
                })
                .catch(function() {});
        }, 5000);
    })();
    </script>

    <!-- Gesture detection script -->
    <script type="module" src="../../js/faculty/initialize-gesture.js?v=<?= time() ?>"></script>

    <!--  PIN SETUP MODAL (first login)-->
    <?php if (!$has_pin): ?>
    <div id="pinSetupOverlay" class="page-timeout-overlay">
        <div class="page-timeout-modal">
            <i class="bi bi-shield-lock" style="font-size:2.5rem;color:var(--secondary-color-4);margin-bottom:0.75rem;"></i>
            <h5 class="schedule-ended-title">Set Your PIN</h5>
            <p class="schedule-ended-text">Set a 4-digit personal PIN for quick access to controls.</p>
            <div class="mt-3 d-flex flex-column align-items-center gap-2">
                <input type="password" id="pinSetupInput" maxlength="4" pattern="\d*" inputmode="numeric"
                       class="form-control text-center" style="width:140px;font-size:1.5rem;letter-spacing:4px;" placeholder="****">
                <input type="password" id="pinSetupConfirm" maxlength="4" pattern="\d*" inputmode="numeric"
                       class="form-control text-center" style="width:140px;font-size:1.5rem;letter-spacing:4px;" placeholder="Confirm">
                <div><span id="pinSetupError" class="text-danger small"></span></div>
                <button class="light" id="pinSetupSubmit">Save PIN</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!--  GESTURE HELP MODAL - 2-column grid, modal-xl, centered -->
    <div class="profile-details-modal gesture-help modal fade" id="gestureHelpModal" tabindex="-1" aria-labelledby="gestureHelpLabel" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold" id="gestureHelpLabel">
                        <i class="bi bi-hand-index-thumb me-2"></i>Gesture Guide
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="gesture-guide-banner mb-3 p-2">
                        <i class="bi bi-stack me-1"></i>
                        <strong>Stack &amp; Confirm:</strong>
                        Queue up to <strong>5 commands</strong> using either one or <strong>both hands</strong> (e.g. Pointing Up with the left hand and Victory with the right hand at the same time).
                        Commands are added in order and shown in the Command Queue.
                        Finish with a single <strong>👍 Thumbs Up</strong> to execute the entire stack at once,
                        or <strong>✊ Closed Fist</strong> to clear the queue. The queue auto-cancels after 15 seconds of inactivity.
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0;">

                        <!-- 1 Finger - Row 1 -->
                        <div class="gesture-guide-row" style="border-right: 1px solid #dee2e6;">
                            <div class="gesture-guide-img">
                                <img src="../../images/pointing-up.png" alt="Pointing up - 1 finger">
                            </div>
                            <div class="gesture-guide-text">
                                <h4 class="bold">Turn 1st row of lights ON/OFF</h4>
                                <strong>Pointing Up / 1 Finger</strong>
                                <span>To perform:
                                    <ul>
                                        <li>Point only your index finger upward.</li>
                                        <li>All other fingers curled down.</li>
                                        <li>Perform the confirmation gesture to formally execute gesture.</li>
                                        <li>Perform this gesture to turn the 1st row of lights ON or OFF.</li>
                                    </ul>
                                </span>
                            </div>
                        </div>

                        <!-- Open Palm - All ON -->
                        <div class="gesture-guide-row" style="border-bottom: none; border-right: 1px solid #dee2e6;">
                            <div class="gesture-guide-img">
                                <img src="../../images/open-palm.png" alt="Open palm">
                            </div>
                            <div class="gesture-guide-text">
                                <h4 class="bold">Turn all rows of lights ON</h4>
                                <strong>Open Palm</strong>
                                <span>To perform:
                                    <ul>
                                        <li>Extend all five fingers wide and spread them open, facing the camera.</li>
                                        <li>Perform the confirmation gesture to formally execute gesture.</li>
                                    </ul>
                                </span>
                            </div>
                        </div>

                        <!-- Victory - Row 2 -->
                        <div class="gesture-guide-row">
                            <div class="gesture-guide-img">
                                <img src="../../images/victory.png" alt="Victory - 2 fingers">
                            </div>
                            <div class="gesture-guide-text">
                                <h4 class="bold">Turn 2nd row of lights ON/OFF</h4>
                                <strong>Victory / 2 Fingers</strong>
                                <span>To perform:
                                    <ul>
                                        <li>Raise index and middle fingers in a V shape, remaining fingers curled.</li>
                                        <li>Perform the confirmation gesture to formally execute gesture.</li>
                                        <li>Perform this gesture to turn the 2nd row of lights ON or OFF.</li>
                                    </ul>
                                </span>
                            </div>
                        </div>

                        <!-- Closed Fist - All OFF -->
                        <div class="gesture-guide-row" style="border-bottom: none;">
                            <div class="gesture-guide-img">
                                <img src="../../images/closed-fist.png" alt="Closed fist">
                            </div>
                            <div class="gesture-guide-text">
                                <h4 class="bold">Turn all rows of lights OFF / Clear queue</h4>
                                <strong>Closed Fist</strong>
                                <span>To perform:
                                    <ul>
                                        <li>Curl all fingers tightly into a fist with no fingers extended.</li>
                                        <li>If commands are queued, this clears the Command Queue.</li>
                                        <li>If no commands are queued, this turns all rows of lights OFF.</li>
                                    </ul>
                                </span>
                            </div>
                        </div>

                        <!-- ILY - Row 3 -->
                        <div class="gesture-guide-row" style="border-right: 1px solid #dee2e6;">
                            <div class="gesture-guide-img">
                                <img src="../../images/ily.png" alt="ILY sign">
                            </div>
                            <div class="gesture-guide-text">
                                <h4 class="bold">Turn 3rd row of lights ON/OFF</h4>
                                <strong>"I Love You" Sign</strong>
                                <span>To perform:
                                    <ul>
                                        <li>Extend thumb, index, and pinky fingers. </li>
                                        <li>Middle and ring fingers must be curled down.</li>
                                        <li>Perform the confirmation gesture to formally execute gesture.</li>
                                        <li>Perform this gesture to turn the 3rd row of lights ON or OFF.</li>
                                    </ul>
                                </span>
                            </div>
                        </div>

                        <!-- Thumbs Up - Toggle -->
                        <div class="gesture-guide-row">
                            <div class="gesture-guide-img">
                                <img src="../../images/thumbs-up.png" alt="Thumbs up">
                            </div>
                            <div class="gesture-guide-text">
                                <h4 class="bold">Confirmation Gesture</h4>
                                <strong>Thumbs Up</strong>
                                <span>To perform:
                                    <ul>
                                        <li>Close all fingers into a fist with only the thumb pointing upward.</li>
                                        <li>Use this gesture to confirm and execute ALL queued commands at once.</li>
                                        <li>For example, queue "Row 1 ON" (pointing up) and "Row 2 ON" (victory), then give a thumbs up to turn both rows on together. You can also use both hands to queue two commands at the same time.</li>
                                    </ul>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!--  ACTIVITY DETAILS MODAL CHANGE 2: Added modal-dialog-centered -->
    <div class="profile-details-modal modal fade" id="activityDetailsModal" tabindex="-1" aria-labelledby="activityDetailsLabel"
        aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered modal-lg">
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
                                            <td><?= htmlspecialchars($log['room_name'] ?? '-') ?></td>

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
                                                <?php
                                                $by = strtolower(trim($log['triggered_by'] ?? 'manual'));
                                                $byBadge = match ($by) {
                                                    'gesture', 'pir' => ['bg-primary', 'bi-hand-index-thumb', 'Gesture'],
                                                    'manual'         => ['bg-secondary', 'bi-toggle-on',      'Manual'],
                                                    default          => ['bg-secondary', 'bi-toggle-on',      ucfirst($by)],
                                                };
                                                ?>
                                                <span class="badge <?= $byBadge[0] ?> rounded-pill">
                                                    <i class="bi <?= $byBadge[1] ?> me-1"></i>
                                                    <?= $byBadge[2] ?>
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
                    <button type="button" class="light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!--  
         EXTEND SCHEDULE MODAL
      -->
    <?php if ($active_schedule): ?>
        <div class="profile-details-modal modal fade" id="extendModal" tabindex="-1" aria-labelledby="extendModalLabel" aria-hidden="true">
            <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title bold" id="extendModalLabel">
                            <i class="bi bi-clock-history me-2"></i>Request Time Extension
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="extend-description">
                            <span class="emphasis">
                                Requesting extension for
                                <span id="extend-room"><?= htmlspecialchars($active_schedule['room_name']) ?></span>
                                from <span id="extend-start-time"><?= date('g:i A', strtotime($active_schedule['start_time'])) ?></span>
                                to <span id="extend-end-time"><?= date('g:i A', strtotime($active_schedule['end_time'])) ?></span>
                            </span>
                            <br>How many extra minutes do you need?
                        </p>
                        <div class="extend-modal-content d-flex gap-4">
                            <div class="extend-left-div">
                                <h2 class="time-elapsed-title">Time Elapsed</h2>
                                <h1 class="timer-display">
                                    <input type="text" class="timer-input" id="timer-hours" value="00" maxlength="2" />:
                                    <input type="text" class="timer-input" id="timer-minutes" value="00" maxlength="2" />:
                                    <input type="text" class="timer-input" id="timer-seconds" value="00" maxlength="2" />
                                </h1>
                                <div class="timer-labels d-flex gap-3 justify-content-center">
                                    <h6 class="timer-label">HOURS</h6>
                                    <h6 class="timer-label">MINUTES</h6>
                                    <h6 class="timer-label">SECONDS</h6>
                                </div>
                                <p class="extend-description mt-3" id="extend-description">
                                    Extending current class at <?= htmlspecialchars($active_schedule['room_name']) ?> for <span id="extend-time-range"></span>
                                </p>
                            </div>
                            <div class="extend-right-div d-flex flex-column align-items-center gap-3">
                                <h2 class="time-elapsed-title">Extend Time</h2>
                                <p class="extend-description mb-0">Add desired time:</p>
                                <div class="d-flex flex-column gap-2" id="extendPills">
                                    <?php foreach ([15, 30, 45, 60] as $mins): ?>
                                        <button class="btn btn-outline-primary extend-pill" data-mins="<?= $mins ?>">
                                            +<?= $mins ?> min
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer d-flex flex-row flex-nowrap justify-content-between gap-2">
                        <button type="button" class="light bold w-100" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="medium w-100" id="submitExtendBtn" disabled>
                            Send Request
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Confirm Extend Modal -->
        <div class="profile-details-modal modal fade" id="confirmExtendModal" tabindex="-1" aria-labelledby="confirmExtendLabel" aria-hidden="true">
            <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title bold" id="confirmExtendLabel">
                            <i class="bi bi-check-circle me-2"></i>Confirm Extension Request
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Please review the details below before submitting your extension request.</p>
                        <div class="dept-info-card p-3">
                            <div class="mb-2"><strong>Room:</strong> <span id="confirmExtendRoom"><?= htmlspecialchars($active_schedule['room_name']) ?></span></div>
                            <div class="mb-2"><strong>Time:</strong> <span id="confirmExtendTime"><?= date('g:i A', strtotime($active_schedule['start_time'])) ?> - <?= date('g:i A', strtotime($active_schedule['end_time'])) ?></span></div>
                            <div class="mb-2"><strong>Extension:</strong> <span id="confirmExtendMins"></span></div>
                            <div><strong>Action:</strong> <span id="confirmExtendAction">submit</span></div>
                        </div>
                    </div>
                    <div class="modal-footer d-flex flex-row flex-nowrap justify-content-between gap-2">
                        <button type="button" class="light bold w-100" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="medium w-100" id="confirmExtendBtn">Confirm</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
                const SCHEDULE_EXTEND_ID = <?= (int) $active_schedule['id'] ?>;
                const CLASS_START_EXTEND = '<?= date('g:i A', strtotime($active_schedule['start_time'])) ?>';
                const CLASS_END_EXTEND = '<?= date('g:i A', strtotime($active_schedule['end_time'])) ?>';
                const ROOM_NAME_EXTEND = '<?= htmlspecialchars($active_schedule['room_name'], ENT_QUOTES) ?>';
        </script>
    <?php endif; ?>

    <div class="toast-container" id="toastContainer"></div>

    <!--  
         VIEW SCHEDULE MODAL
      -->
    <div class="profile-details-modal modal fade" id="viewScheduleModal" tabindex="-1" aria-labelledby="viewScheduleLabel" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold" id="viewScheduleLabel">
                        <i class="bi bi-calendar-week me-2"></i>Class Schedule
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (!empty($schedules)): ?>
                        <?php
                        $dayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                        usort($schedules, function ($a, $b) use ($dayOrder) {
                            $da = array_search($a['day_of_week'], $dayOrder);
                            $db = array_search($b['day_of_week'], $dayOrder);
                            return $da !== $db ? $da - $db : strcmp($a['start_time'], $b['start_time']);
                        });
                        $today = date('l');
                        $byDay = [];
                        foreach ($schedules as $sched) {
                            $byDay[$sched['day_of_week']][] = $sched;
                        }
                        ?>
                        <div class="view-schedule-day-list">
                            <?php foreach ($byDay as $day => $dayScheds):
                                $isToday = ($day === $today);
                            ?>
                                <div class="day-card <?= $isToday ? 'today' : '' ?>">
                                    <div class="day-label">
                                        <div class="text-uppercase small fw-bold mb-1" style="font-size:11px;letter-spacing:0.5px;color:<?= $isToday ? '#fff' : '#6c757d' ?>;"><?= htmlspecialchars($day) ?></div>
                                        <?= htmlspecialchars($day) ?> <?= $isToday ? '· Today' : '' ?>
                                    </div>
                                    <?php foreach ($dayScheds as $sched):
                                        $start = date('g:i A', strtotime($sched['start_time']));
                                        $is_early_end = $sched['extended_until'] && $sched['extended_until'] < $sched['end_time'];
                                        $end_time = $is_early_end
                                            ? date('g:i A', strtotime($sched['extended_until']))
                                            : date('g:i A', strtotime($sched['end_time']));
                                        $scheduled_end = date('g:i A', strtotime($sched['end_time']));
                                        $start_parts = explode(' ', $start);
                                        $start_time_part = $start_parts[0];
                                        $end_parts = explode(' ', $end_time);
                                        $end_time_part = $end_parts[0];
                                        $end_ampm = $end_parts[1] ?? 'AM';
                                    ?>
                                        <div class="slot-row">
                                            <div class="slot-time">
                                                <span class="slot-time-start"><?= $start_time_part ?></span>
                                                <span class="slot-time-separator">TO</span>
                                                <span class="slot-time-end"><?= $end_time_part ?></span>
                                                <span class="slot-time-ampm"><?= $end_ampm ?></span>
                                            </div>
                                            <div class="slot-content">
                                                <div class="slot-room">
                                                    <i class="bi bi-door-open me-1"></i><?= htmlspecialchars($sched['room_name'] ?? '') ?>
                                                </div>
                                                <div class="slot-subject d-flex flex-row">
                                                    <i class="bi bi-book me-1"></i>
                                                    <h5><?= htmlspecialchars($sched['subject_name'] ?? 'No subject') ?></h5>
                                                </div>
                                            </div>
                                            <?php if ($is_early_end): ?>
                                                <div class="d-flex align-items-center gap-2 mt-1" style="font-size:12px;">
                                                    <span class="badge-early-end" title="Schedule ended early" data-bs-toggle="tooltip">
                                                        <i class="bi bi-stop-circle"></i>
                                                    </span>
                                                    <span style="color:#842029;">
                                                        Ended early: <s style="opacity:0.6;"><?= $scheduled_end ?></s> → <strong style="color:#dc3545;"><?= $end_time ?></strong>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="d-flex align-items-center gap-3 p-2 bg-light rounded-3 text-muted">
                            <i class="bi bi-calendar-x" style="font-size:1.6rem;"></i>
                            <div>No schedules found for this classroom.</div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- End Early Confirm Modal -->
    <div class="profile-details-modal modal fade" id="endEarlyModal" tabindex="-1" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background:#dc3545;">
                    <h5 class="modal-title bold"><i class="bi bi-stop-circle me-2"></i>End Class Early</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <i class="bi bi-exclamation-triangle" style="font-size:2.5rem;color:#dc3545;"></i>
                    <p class="mt-3 mb-1">End your current class in <strong id="endEarlyRoom"></strong> early?</p>
                    <p class="text-muted small">Lights in this room will be turned off and the schedule will be marked as finished.</p>
                </div>
                <div class="modal-footer d-flex flex-row flex-nowrap justify-content-between gap-2">
                    <button type="button" class="light bold w-100" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display:contents;">
                        <input type="hidden" name="end_early" id="endEarlySchedId" value="">
                        <button type="submit" class="medium w-100" style="background:#dc3545;border-color:#dc3545;">Confirm</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . "/../../src/Includes/faculty-sidebar.php"; ?>
    </div>
    </div>
</div>

    <script src="../../js/lib/animations.js"></script>
    <script src="../../js/lib/toggles.js"></script>
    <script src="../../js/lib/tooltip.js"></script>
    <script src="../../js/faculty/faculty-tutorial.js"></script>
</body>

</html>