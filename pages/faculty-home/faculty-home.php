<?php
$page_title = 'Faculty Dashboard';

require_once '../../php/session_guard.php';
check_faculty();
require_once '../../php/db_connect.php';
date_default_timezone_set('Asia/Manila');
require_once '../../php/includes/faculty-head.php';

/** @var string $faculty_name */
/** @var string $faculty_email */
/** @var string $initials */
/** @var string $first_name */
/** @var int    $faculty_id */
/** @var int    $classroom_id */
/** @var array  $logs */
/** @var array  $gesture_logs */
/** @var array  $schedules */

// ── Active schedule for initial page render ───────────────────────────────────
$now   = date('H:i:s');
$today = date('l');

$active_schedule = null;
$stmt = $conn->prepare("
    SELECT s.id, s.start_time, s.end_time, s.extended_until, c.room_name
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    WHERE s.faculty_id   = ?
      AND s.classroom_id = ?
      AND s.day_of_week  = ?
      AND s.start_time  <= ?
      AND (
            (s.extended_until IS NOT NULL AND s.extended_until >= ?)
         OR (s.extended_until IS NULL     AND s.end_time       >= ?)
      )
    ORDER BY s.start_time
    LIMIT 1
");
$stmt->bind_param('iissss', $faculty_id, $classroom_id, $today, $now, $now, $now);
$stmt->execute();
$active_schedule = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

// ── Classroom light state for initial render ──────────────────────────────────
$light_status = $row1_status = $row2_status = $row3_status = 'off';
$stmt = $conn->prepare("
    SELECT light_status, row1_status, row2_status, row3_status
    FROM classrooms
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param('i', $classroom_id);
$stmt->execute();
$stmt->bind_result($light_status, $row1_status, $row2_status, $row3_status);
$stmt->fetch();
$stmt->close();

$conn->close();

// ── Helpers ───────────────────────────────────────────────────────────────────
function mask_email(string $email): string
{
    [$local, $domain] = explode('@', $email, 2);
    $visible = min(2, strlen($local));
    return substr($local, 0, $visible)
         . str_repeat('*', max(1, strlen($local) - $visible))
         . '@' . $domain;
}

// Bulb images for initial render
$b1 = ($row1_status === 'on' && $active_schedule) ? '../../images/bulb-on.png' : '../../images/bulb-off.png';
$b2 = ($row2_status === 'on' && $active_schedule) ? '../../images/bulb-on.png' : '../../images/bulb-off.png';
$b3 = ($row3_status === 'on' && $active_schedule) ? '../../images/bulb-on.png' : '../../images/bulb-off.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
            crossorigin="anonymous"></script>
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">
    <link rel="stylesheet" href="../../css/faculty-home.css">
    <title>Home – LumineSense</title>
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
                <div style="background-color:#f8f9fa;" class="section-container">
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
                         style="position:relative;">
                        <button id="enableCameraBtn" class="btn btn-primary btn-sm" style="z-index:10;"
                            <?= !$active_schedule ? 'disabled title="No active schedule"' : '' ?>>
                            <i class="bi bi-camera-video me-1"></i>Enable Camera
                        </button>
                        <button id="disableCameraBtn" class="btn btn-secondary btn-sm"
                                style="display:none; position:absolute; bottom:8px; right:8px; z-index:10;">
                            <i class="bi bi-camera-video-off me-1"></i>Disable Camera
                        </button>
                        <video id="webcamVideo" autoplay playsinline
                               style="display:none; width:100%; height:100%; object-fit:cover; border-radius:8px; transform:scaleX(-1);"></video>
                        <canvas id="webcamCanvas"
                                style="display:none; position:absolute; top:0; left:0; width:100%; height:100%; object-fit:cover; border-radius:8px; pointer-events:none; transform:scaleX(-1);"></canvas>
                    </div>

                    <!-- Row pills + gesture result -->
                    <div class="gesture-response d-flex px-2 flex-column align-items-start justify-content-start gap-2">
                        <div class="gesture-row-pills w-100 d-flex justify-content-center gap-2 mt-1">
                            <span class="gesture-row-pill" id="rowPill1" data-row="1">Row 1</span>
                            <span class="gesture-row-pill" id="rowPill2" data-row="2">Row 2</span>
                            <span class="gesture-row-pill" id="rowPill3" data-row="3">Row 3</span>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <span class="text-muted" style="font-size:0.85rem;">Detected:</span>
                            <span class="bold mx-1" id="gestureResult">—</span>
                        </div>
                        <div class="w-100 d-flex justify-content-center">
                            <button class="light" data-bs-toggle="modal" data-bs-target="#gestureHelpModal">
                                <i class="bi bi-question-circle me-1"></i> View Gestures
                            </button>
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
                            <h5>PIR Sensor: <span id="statusPir" class="text-muted">Unknown</span></h5>
                        </div>
                    </div>
                </div>

            </div><!-- /col 1 -->


            <!-- ══════════════════════════════
                 COLUMN 2 – LIGHTING GRID
            ══════════════════════════════ -->
            <div class="group-container gap-3">

                <div style="background-color:#f8f9fa;" class="fit-width section-container">
                    <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                        <div class="d-flex mx-2 align-items-start">
                            <h2 class="bold">Lighting Grid</h2>
                        </div>
                    </div>

                    <!-- Lock notice — visible only when no active schedule -->
                    <div id="scheduleEndNotice"
                         class="alert alert-warning align-items-center gap-2 mx-2 mb-2 py-2"
                         style="font-size:0.82rem; display:<?= $active_schedule ? 'none' : 'flex' ?>;">
                        <i class="bi bi-lock-fill"></i>
                        Controls are locked — no active class schedule.
                    </div>

                    <div class="d-flex flex-row align-items-center justify-content-center">
                        <!-- Bulb grid -->
                        <div class="lighting-grid">
                            <?php foreach ([[$b1,1],[$b2,2],[$b3,3]] as [$src,$row]): ?>
                                <img src="<?= $src ?>" class="bulb-img" data-row="<?= $row ?>">
                                <img src="<?= $src ?>" class="bulb-img" data-row="<?= $row ?>">
                                <img src="<?= $src ?>" class="bulb-img" data-row="<?= $row ?>">
                                <hr class="w-100">
                            <?php endforeach; ?>
                        </div>

                        <!-- Switches -->
                        <div class="p-5">
                            <?php foreach ([1,2,3] as $r): ?>
                                <?php
                                $rowStatus = ${"row{$r}_status"};
                                $checked   = ($rowStatus === 'on' && $active_schedule) ? 'checked' : '';
                                $disabled  = !$active_schedule ? 'disabled' : '';
                                ?>
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <label class="form-check-label" for="row-<?= $r ?>-switch">Row <?= $r ?></label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               id="row-<?= $r ?>-switch" <?= $checked ?> <?= $disabled ?>>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <br>
                            <div class="d-flex flex-column align-items-center gap-1">
                                <h5 class="bold">All Lights</h5>
                                <h4 id="allLightsStatus"
                                    class="bold <?= ($light_status === 'on' && $active_schedule) ? 'on' : 'off' ?>">
                                    <?= ($light_status === 'on' && $active_schedule) ? 'ON' : 'OFF' ?>
                                </h4>
                                <div id="allLightsContainer"
                                     class="all-lights-<?= ($light_status === 'on' && $active_schedule) ? 'on' : 'off' ?>"
                                     style="display:flex; align-items:center; justify-content:center;
                                            <?= !$active_schedule ? 'pointer-events:none; opacity:0.4;' : '' ?>">
                                    <i class="bi bi-power" id="all-lights"
                                       style="line-height:1; display:flex; align-items:center; justify-content:center;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /col 2 -->


            <!-- ══════════════════════════════
                 COLUMN 3 – TIMER + ACTIVITIES
            ══════════════════════════════ -->
            <div class="group-container recent-activities gap-3">

                <!-- Time Left -->
                <div style="background-color:#f8f9fa;" class="section-container mb-3">
                    <div class="gap-1 align-items-center">
                        <div class="section-topbar d-flex flex-column mx-2 justify-content-between">
                            <div>
                                <h2 class="bold">Time Left</h2>
                                <h2 class="medium fs-6">until end of class</h2>
                            </div>
                            <div class="d-flex mx-2 align-items-center justify-content-end">
                                <button class="light h-50 w-auto"
                                        data-bs-toggle="modal" data-bs-target="#viewScheduleModal">
                                    View Schedule
                                </button>
                            </div>
                        </div>

                        <div class="d-flex flex-column mx-1 align-items-center justify-content-center">
                            <?php if ($active_schedule): ?>
                                <?php $end = $active_schedule['extended_until'] ?? $active_schedule['end_time']; ?>
                                <h1 class="bold display-1" id="timerDisplay"
                                    data-end="<?= htmlspecialchars($end) ?>"
                                    data-now="<?= htmlspecialchars($now) ?>">
                                    --:--:--
                                </h1>
                            <?php else: ?>
                                <h1 class="bold display-1 text-muted" id="timerDisplay">00:00:00</h1>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex flex-column mx-2 align-items-end justify-content-center">
                            <?php if ($active_schedule): ?>
                                <button class="light mt-2"
                                        data-bs-toggle="modal" data-bs-target="#extendModal">
                                    <i class="bi bi-clock-history me-1"></i> Extend
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!$active_schedule): ?>
                        <p class="text-muted text-center mt-2 mb-1">No active class schedule right now.</p>
                    <?php endif; ?>
                </div>

                <!-- Recent Activities -->
                <div style="background-color:#f8f9fa;" class="section-container recents">
                    <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                        <div class="d-flex mx-2 align-items-start">
                            <h2 class="bold">Recent Activities</h2>
                        </div>
                        <div class="d-flex mx-2 align-items-end">
                            <button class="light mx-2"
                                    data-bs-toggle="modal" data-bs-target="#activityDetailsModal">
                                Details
                            </button>
                        </div>
                    </div>
                    <div class="gap-2">
                        <div class="activity-list px-2 gap-2 align-items-center max-width" id="activityList">
                            <?php if (empty($logs)): ?>
                                <p class="text-muted">No recent activity yet.</p>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <?php
                                    $type      = $log['event_type'] ?? '';
                                    $badgeCls  = str_contains($type, 'on')      ? 'bg-success'
                                               : (str_contains($type, 'off')    ? 'bg-danger'
                                               : (str_contains($type, 'gesture')? 'bg-primary'
                                               : 'bg-secondary'));
                                    $by        = strtolower(trim($log['triggered_by'] ?? 'manual'));
                                    $byBadge   = in_array($by, ['gesture','pir'])
                                                    ? ['bg-primary',   'bi-hand-index-thumb', 'Gesture']
                                                    : ['bg-secondary', 'bi-toggle-on',        ucfirst($by)];
                                    ?>
                                    <div class="d-flex align-items-start gap-2"
                                         style="font-size:0.78rem; padding:6px 0;">
                                        <div class="flex-shrink-0">
                                            <span class="badge <?= $badgeCls ?> rounded-pill">
                                                <?= ucfirst(str_replace('_', ' ', $type)) ?>
                                            </span>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div>
                                                <strong><?= htmlspecialchars($log['room_name'] ?? '—') ?></strong>
                                                <?php if (!empty($log['row_affected'])): ?>
                                                    <span class="badge bg-info text-dark rounded-pill ms-2">
                                                        Row <?= htmlspecialchars($log['row_affected']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-muted" style="font-size:0.72rem; margin-top:4px;">
                                                <span class="badge <?= $byBadge[0] ?> rounded-pill">
                                                    <i class="bi <?= $byBadge[1] ?> me-1"></i><?= $byBadge[2] ?>
                                                </span>
                                                <span class="ms-2">
                                                    <?= date('g:i A · M j', strtotime($log['event_time'])) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <hr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div><!-- /col 3 -->

            <?php include '../../php/includes/faculty-sidebar.php'; ?>

            <!-- PROFILE MODAL -->
            <div class="profile-details-modal modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
                <div class="d-flex justify-content-center modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Profile</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="card border-0 shadow-sm rounded-4">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center gap-3 mb-4">
                                        <div class="avatar-icon d-flex align-items-center justify-content-center flex-shrink-0">
                                            <h3 class="bold mb-0"><?= $initials ?></h3>
                                        </div>
                                        <div>
                                            <h4 class="bold mb-1"><?= $faculty_name ?></h4>
                                            <p class="mb-0">Faculty Member</p>
                                        </div>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <div class="p-3 bg-light rounded-3">
                                                <small class="text-muted d-block">Email</small>
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

            <!-- ACTIVITY DETAILS MODAL -->
            <div class="profile-details-modal modal fade" id="activityDetailsModal" tabindex="-1" aria-hidden="true">
                <div class="d-flex justify-content-center modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title bold">
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
                                                <th>Row</th>
                                                <th>Triggered By</th>
                                                <th class="pe-3">Time</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($logs as $log): ?>
                                                <?php
                                                $type     = $log['event_type'] ?? '';
                                                $badgeCls = str_contains($type, 'on')       ? 'bg-success'
                                                          : (str_contains($type, 'off')      ? 'bg-danger'
                                                          : (str_contains($type, 'gesture')  ? 'bg-primary'
                                                          : 'bg-secondary'));
                                                $by       = strtolower(trim($log['triggered_by'] ?? 'manual'));
                                                $byBadge  = in_array($by, ['gesture','pir'])
                                                                ? ['bg-primary',   'bi-hand-index-thumb', 'Gesture']
                                                                : ['bg-secondary', 'bi-toggle-on',        ucfirst($by)];
                                                ?>
                                                <tr>
                                                    <td class="ps-3">
                                                        <span class="badge <?= $badgeCls ?> rounded-pill">
                                                            <?= ucfirst(str_replace('_', ' ', $type)) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= htmlspecialchars($log['room_name'] ?? '—') ?></td>
                                                    <td>
                                                        <?php if (!empty($log['row_affected'])): ?>
                                                            <span class="badge bg-info text-dark rounded-pill">
                                                                Row <?= htmlspecialchars($log['row_affected']) ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">All</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?= $byBadge[0] ?> rounded-pill">
                                                            <i class="bi <?= $byBadge[1] ?> me-1"></i>
                                                            <?= $byBadge[2] ?>
                                                        </span>
                                                    </td>
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

            <!-- EXTEND SCHEDULE MODAL -->
            <?php if ($active_schedule): ?>
                <div class="modal fade" id="extendModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title bold">
                                    <i class="bi bi-clock-history me-2"></i>Request Time Extension
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p class="text-muted small mb-3">
                                    Current class ends at
                                    <strong>
                                        <?= date('g:i A', strtotime(
                                            $active_schedule['extended_until'] ?? $active_schedule['end_time']
                                        )) ?>
                                    </strong>.
                                    How many extra minutes do you need?
                                </p>
                                <div class="d-flex gap-2 justify-content-center flex-wrap" id="extendPills">
                                    <?php foreach ([15,30,45,60] as $mins): ?>
                                        <button class="btn btn-outline-primary extend-pill"
                                                data-mins="<?= $mins ?>">
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
                    const SCHEDULE_ID = <?= (int)$active_schedule['id'] ?>;
                    let selectedMins  = 0;

                    document.querySelectorAll('.extend-pill').forEach(btn => {
                        btn.addEventListener('click', () => {
                            document.querySelectorAll('.extend-pill').forEach(b => {
                                b.classList.remove('active', 'btn-primary');
                                b.classList.add('btn-outline-primary');
                            });
                            btn.classList.add('active', 'btn-primary');
                            btn.classList.remove('btn-outline-primary');
                            selectedMins = parseInt(btn.dataset.mins);
                            document.getElementById('submitExtendBtn').disabled = false;
                            document.getElementById('extendFeedback').textContent = '';
                        });
                    });

                    document.getElementById('submitExtendBtn').addEventListener('click', async () => {
                        const btn      = document.getElementById('submitExtendBtn');
                        const feedback = document.getElementById('extendFeedback');
                        btn.disabled    = true;
                        btn.textContent = 'Sending…';

                        const form = new FormData();
                        form.append('action',      'request_extension');
                        form.append('schedule_id', SCHEDULE_ID);
                        form.append('extend_mins', selectedMins);

                        try {
                            const res  = await fetch('../../app/controllers/ScheduleController.php', {
                                method: 'POST', body: form
                            });
                            const data = await res.json();
                            feedback.textContent = data.message;
                            feedback.style.color = data.success ? 'green' : 'red';
                            btn.textContent = data.success ? 'Sent ✓' : 'Send Request';
                            if (!data.success) btn.disabled = false;
                        } catch {
                            feedback.textContent = 'Network error. Please try again.';
                            feedback.style.color = 'red';
                            btn.disabled    = false;
                            btn.textContent = 'Send Request';
                        }
                    });
                })();
                </script>
            <?php endif; ?>

            <!-- VIEW SCHEDULE MODAL -->
            <div class="modal fade" id="viewScheduleModal" tabindex="-1" aria-hidden="true">
                <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title bold">
                                <i class="bi bi-calendar-week me-2"></i>Class Schedule
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="d-flex flex-column gap-3">
                                <?php if (!empty($schedules)): ?>
                                    <?php
                                    $dayOrder = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
                                    usort($schedules, function ($a, $b) use ($dayOrder) {
                                        $da = array_search($a['day_of_week'], $dayOrder);
                                        $db = array_search($b['day_of_week'], $dayOrder);
                                        return $da !== $db
                                            ? $da - $db
                                            : strcmp($a['start_time'], $b['start_time']);
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
                                    foreach ($schedules as $sched):
                                        $isToday = ($sched['day_of_week'] === $today);
                                        $icon    = $dayIcons[$sched['day_of_week']] ?? 'bi-calendar';
                                    ?>
                                        <div class="d-flex align-items-center gap-3 p-2 rounded-3
                                            <?= $isToday
                                                ? 'bg-primary bg-opacity-10 border border-primary border-opacity-25'
                                                : 'bg-light' ?>">
                                            <i class="bi <?= $icon ?> <?= $isToday ? 'text-primary' : 'text-secondary' ?>"
                                               style="font-size:1.6rem; flex-shrink:0;"></i>
                                            <div class="flex-grow-1">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <strong><?= htmlspecialchars($sched['day_of_week']) ?></strong>
                                                    <?php if ($isToday): ?>
                                                        <span class="badge bg-primary rounded-pill"
                                                              style="font-size:0.7rem;">Today</span>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="text-muted">
                                                    <i class="bi bi-clock me-1"></i>
                                                    <?= date('g:i A', strtotime($sched['start_time'])) ?>
                                                    —
                                                    <?= date('g:i A', strtotime($sched['end_time'])) ?>
                                                </small>
                                                <small class="text-muted d-block">
                                                    <i class="bi bi-door-open me-1"></i>
                                                    <?= htmlspecialchars($sched['room_name']) ?>
                                                </small>
                                                <?php if (!empty($sched['subject_name'])): ?>
                                                    <div style="font-size:0.8rem;" class="text-secondary">
                                                        <i class="bi bi-book me-1"></i>
                                                        <?= htmlspecialchars($sched['subject_name']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="d-flex align-items-center gap-3 p-2 bg-light rounded-3 text-muted">
                                        <i class="bi bi-calendar-x" style="font-size:1.6rem;"></i>
                                        <div>No schedules found.</div>
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

            <!-- GESTURE HELP MODAL -->
            <div class="profile-details-modal gesture-help modal fade" id="gestureHelpModal"
                 tabindex="-1" aria-hidden="true">
                <div class="d-flex justify-content-center modal-dialog modal-dialog-centered modal-xl">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title bold">
                                <i class="bi bi-hand-index-thumb me-2"></i>Gesture Guide
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-3">
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0;">

                                <div class="gesture-guide-row" style="border-right:1px solid #dee2e6;">
                                    <div class="gesture-guide-img">
                                        <img src="../../images/pointing-up.png" alt="1 finger">
                                    </div>
                                    <div class="gesture-guide-text">
                                        <h4 class="bold">Turn 1st row ON/OFF</h4>
                                        <strong>Pointing Up / 1 Finger</strong>
                                        <ul><li>Point only your index finger upward.</li>
                                            <li>All other fingers curled down.</li>
                                            <li>Confirm with Thumbs Up.</li></ul>
                                    </div>
                                </div>

                                <div class="gesture-guide-row" style="border-right:1px solid #dee2e6;">
                                    <div class="gesture-guide-img">
                                        <img src="../../images/open-palm.png" alt="Open palm">
                                    </div>
                                    <div class="gesture-guide-text">
                                        <h4 class="bold">Turn all rows ON</h4>
                                        <strong>Open Palm</strong>
                                        <ul><li>Extend all five fingers wide, facing the camera.</li>
                                            <li>Confirm with Thumbs Up.</li></ul>
                                    </div>
                                </div>

                                <div class="gesture-guide-row" style="border-right:1px solid #dee2e6;">
                                    <div class="gesture-guide-img">
                                        <img src="../../images/victory.png" alt="Victory">
                                    </div>
                                    <div class="gesture-guide-text">
                                        <h4 class="bold">Turn 2nd row ON/OFF</h4>
                                        <strong>Victory / 2 Fingers</strong>
                                        <ul><li>Raise index and middle fingers in a V shape.</li>
                                            <li>Confirm with Thumbs Up.</li></ul>
                                    </div>
                                </div>

                                <div class="gesture-guide-row">
                                    <div class="gesture-guide-img">
                                        <img src="../../images/closed-fist.png" alt="Closed fist">
                                    </div>
                                    <div class="gesture-guide-text">
                                        <h4 class="bold">Turn all rows OFF</h4>
                                        <strong>Closed Fist</strong>
                                        <ul><li>Curl all fingers tightly into a fist.</li>
                                            <li>Confirm with Thumbs Up.</li></ul>
                                    </div>
                                </div>

                                <div class="gesture-guide-row" style="border-right:1px solid #dee2e6;">
                                    <div class="gesture-guide-img">
                                        <img src="../../images/ily.png" alt="ILY">
                                    </div>
                                    <div class="gesture-guide-text">
                                        <h4 class="bold">Turn 3rd row ON/OFF</h4>
                                        <strong>"I Love You" Sign</strong>
                                        <ul><li>Extend thumb, index, and pinky fingers.</li>
                                            <li>Confirm with Thumbs Up.</li></ul>
                                    </div>
                                </div>

                                <div class="gesture-guide-row">
                                    <div class="gesture-guide-img">
                                        <img src="../../images/thumbs-up.png" alt="Thumbs up">
                                    </div>
                                    <div class="gesture-guide-text">
                                        <h4 class="bold">Confirmation Gesture</h4>
                                        <strong>Thumbs Up</strong>
                                        <ul><li>Close all fingers into a fist with only the thumb pointing up.</li>
                                            <li>Use this to confirm and execute the detected gesture.</li></ul>
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

        </div>
    </div>

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
</div>

<!-- ══════════════════════════════════════════════════════════════
     MAIN DASHBOARD SCRIPT
══════════════════════════════════════════════════════════════ -->
<script>
// ── Constants (baked in by PHP on page load) ──────────────────────────────────
const CLASSROOM_ID       = <?= (int)$classroom_id ?>;
const FACULTY_ID         = <?= (int)$faculty_id ?>;
const HAS_ACTIVE_SCHEDULE = <?= $active_schedule ? 'true' : 'false' ?>;
const TODAYS_SCHEDULES   = <?= json_encode(array_values(array_filter(
    $schedules,
    fn($s) => $s['day_of_week'] === $today
))) ?>;
const BULB_ON  = '../../images/bulb-on.png';
const BULB_OFF = '../../images/bulb-off.png';

// ── Which room should the poll ask about RIGHT NOW? ───────────────────────────
// Walks through today's schedules and returns the classroom_id
// of whichever one is currently active. Falls back to page-load value.
function getCurrentClassroomId() {
    const now  = new Date();
    const pad  = n => String(n).padStart(2, '0');
    const nowStr = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;

    for (const sched of TODAYS_SCHEDULES) {
        if (nowStr >= sched.start_time && nowStr <= sched.end_time) {
            return sched.classroom_id;
        }
    }
    return CLASSROOM_ID;
}

// ── Lock / Unlock controls ────────────────────────────────────────────────────
function lockControls() {
    ['row-1-switch','row-2-switch','row-3-switch'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.disabled = true;
        el.closest('.form-check')?.classList.add('opacity-50');
    });

    const pwr = document.getElementById('allLightsContainer');
    if (pwr) { pwr.style.pointerEvents = 'none'; pwr.style.opacity = '0.4'; }

    const camBtn = document.getElementById('enableCameraBtn');
    if (camBtn) { camBtn.disabled = true; camBtn.title = 'No active schedule'; }

    const disBtn = document.getElementById('disableCameraBtn');
    if (disBtn) disBtn.disabled = true;

    const notice = document.getElementById('scheduleEndNotice');
    if (notice) notice.style.display = 'flex';
}

function unlockControls() {
    ['row-1-switch','row-2-switch','row-3-switch'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.disabled = false;
        el.closest('.form-check')?.classList.remove('opacity-50');
    });

    const pwr = document.getElementById('allLightsContainer');
    if (pwr) { pwr.style.pointerEvents = ''; pwr.style.opacity = ''; }

    const camBtn = document.getElementById('enableCameraBtn');
    if (camBtn) { camBtn.disabled = false; camBtn.title = ''; }

    const disBtn = document.getElementById('disableCameraBtn');
    if (disBtn) disBtn.disabled = false;

    const notice = document.getElementById('scheduleEndNotice');
    if (notice) notice.style.display = 'none';
}

// ── Countdown timer ───────────────────────────────────────────────────────────
window._secondsLeft = null;

(function () {
    const display = document.getElementById('timerDisplay');
    if (!display) return;

    const phpEnd = display.dataset.end;
    const phpNow = display.dataset.now;

    if (phpEnd && phpNow) {
        const toSecs = str => str.split(':').reduce((acc, v, i) => acc + Number(v) * [3600,60,1][i], 0);
        window._secondsLeft = Math.max(0, toSecs(phpEnd) - toSecs(phpNow));
    }

    const pad = n => String(n).padStart(2, '0');

    window._tickTimer = function () {
        if (!display) return;

        if (window._secondsLeft === null) {
            display.textContent = '00:00:00';
            display.classList.remove('text-danger');
            if (!HAS_ACTIVE_SCHEDULE) lockControls();
            return;
        }

        if (window._secondsLeft <= 0) {
            display.textContent = '00:00:00';
            display.classList.add('text-danger');
            lockControls();
            return;
        }

        const s = window._secondsLeft;
        display.textContent =
            `${pad(Math.floor(s / 3600))}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`;
        display.classList.remove('text-danger');
        unlockControls();
        window._secondsLeft--;
    };

    window._tickTimer();
    setInterval(window._tickTimer, 1000);
})();

// ── PIR uptime counter ────────────────────────────────────────────────────────
let _uptimeStart = null;

(function () {
    const el  = document.getElementById('statusUptime');
    const pad = n => String(n).padStart(2, '0');

    window._tickUptime = function () {
        if (!el) return;
        if (!_uptimeStart) { el.textContent = '00:00:00'; return; }
        const diff = Math.max(0, Math.floor((Date.now() - _uptimeStart) / 1000));
        el.textContent =
            `${pad(Math.floor(diff / 3600))}:${pad(Math.floor((diff % 3600) / 60))}:${pad(diff % 60)}`;
    };

    window._tickUptime();
    setInterval(window._tickUptime, 1000);
})();

// ── Live poll (every 3 s) ─────────────────────────────────────────────────────
let _lastLightStatus = '<?= $light_status ?>';

async function pollDashboard() {
    // Always ask about the room the faculty is currently supposed to be in
    const activeCid = getCurrentClassroomId();

    try {
        const res = await fetch(
            `../../app/controllers/DashboardController.php?action=faculty_snapshot&classroom_id=${activeCid}`
        );
        if (!res.ok) return;

        const data = await res.json();
        if (!data.success) return;

        // ── Update bulb images ────────────────────────────────────────────────
        const rowStatus = { 1: data.row1_status, 2: data.row2_status, 3: data.row3_status };
        [1, 2, 3].forEach(r => {
            document.querySelectorAll(`.bulb-img[data-row="${r}"]`).forEach(img => {
                img.src = rowStatus[r] === 'on' ? BULB_ON : BULB_OFF;
            });
        });

        // ── Update all-lights badge ───────────────────────────────────────────
        const overallOn = Object.values(rowStatus).some(s => s === 'on');
        if (overallOn !== _lastLightStatus) {
            _lastLightStatus = overallOn;

            const badge   = document.getElementById('allLightsStatus');
            const btnCont = document.getElementById('allLightsContainer');
            if (badge) {
                badge.textContent = overallOn ? 'ON' : 'OFF';
                badge.className   = `bold ${overallOn ? 'on' : 'off'}`;
            }
            if (btnCont) {
                btnCont.className = btnCont.className
                    .replace(/all-lights-(on|off)/, `all-lights-${overallOn ? 'on' : 'off'}`);
            }

            const sLight = document.getElementById('statusLighting');
            if (sLight) {
                sLight.textContent = overallOn ? 'ON' : 'OFF';
                sLight.className   = overallOn ? 'text-success' : 'text-danger';
            }
        }

        // ── Schedule active? update timer + lock state ────────────────────────
        if (data.schedule_active && data.schedule_end && data.server_time) {
            const toSecs = str => str.split(':').reduce((acc, v, i) => acc + Number(v) * [3600,60,1][i], 0);
            window._secondsLeft = Math.max(0, toSecs(data.schedule_end) - toSecs(data.server_time));
            unlockControls();
        } else {
            window._secondsLeft = null;
            lockControls();
        }

        // ── PIR sensor ───────────────────────────────────────────────────────
        const pirEl = document.getElementById('statusPir');
        if (data.pir_occupied && data.pir_since) {
            _uptimeStart = new Date(data.pir_since.replace(' ', 'T')).getTime();
            if (pirEl) { pirEl.textContent = 'Occupied'; pirEl.className = 'text-success'; }
        } else {
            _uptimeStart = null;
            if (pirEl) { pirEl.textContent = 'Empty'; pirEl.className = 'text-muted'; }
        }

        // ── Recent activities ─────────────────────────────────────────────────
        if (data.logs && data.logs.length > 0) {
            const list = document.getElementById('activityList');
            if (list) {
                list.innerHTML = data.logs.map(log => {
                    const type     = log.event_type || '';
                    const badgeCls = type.includes('on')      ? 'bg-success'
                                   : type.includes('off')     ? 'bg-danger'
                                   : type.includes('gesture') ? 'bg-primary'
                                   : 'bg-secondary';
                    const by       = (log.triggered_by || 'manual').toLowerCase().trim();
                    const byBadge  = ['gesture','pir'].includes(by)
                        ? ['bg-primary',   'bi-hand-index-thumb', 'Gesture']
                        : ['bg-secondary', 'bi-toggle-on',
                           by.charAt(0).toUpperCase() + by.slice(1)];
                    const timeStr  = new Date(log.event_time.replace(' ', 'T'))
                        .toLocaleString('en-US', {
                            hour: 'numeric', minute: '2-digit', hour12: true,
                            month: 'short',  day: 'numeric'
                        });
                    const rowBadge = log.row_affected
                        ? `<span class="badge bg-info text-dark rounded-pill ms-2">Row ${log.row_affected}</span>`
                        : '';

                    return `
                        <div class="d-flex align-items-start gap-2" style="font-size:0.78rem; padding:6px 0;">
                            <div class="flex-shrink-0">
                                <span class="badge ${badgeCls} rounded-pill">
                                    ${type.charAt(0).toUpperCase() + type.slice(1).replace('_',' ')}
                                </span>
                            </div>
                            <div class="flex-grow-1">
                                <div>
                                    <strong>${log.room_name || '—'}</strong>${rowBadge}
                                </div>
                                <div class="text-muted" style="font-size:0.72rem; margin-top:4px;">
                                    <span class="badge ${byBadge[0]} rounded-pill">
                                        <i class="bi ${byBadge[1]} me-1"></i>${byBadge[2]}
                                    </span>
                                    <span class="ms-2">${timeStr}</span>
                                </div>
                            </div>
                        </div>
                        <hr>
                    `;
                }).join('');
            }
        }

    } catch (e) {
        console.warn('pollDashboard error:', e);
    }
}

// ── Gesture helpers (called from initialize-gesture.js) ───────────────────────
async function logGestureEvent(gestureLabel, eventType = 'gesture') {
    const form = new FormData();
    form.append('classroom_id', getCurrentClassroomId());
    form.append('faculty_id',   FACULTY_ID);
    form.append('event_type',   eventType);
    form.append('triggered_by', 'gesture');
    await fetch('../../app/controllers/LogController.php', { method: 'POST', body: form });
}

function updateGestureResult(label) {
    const el = document.getElementById('gestureResult');
    if (el) el.textContent = label;
}

// ── Misc ──────────────────────────────────────────────────────────────────────
document.getElementById('refreshBtn').addEventListener('click', () => location.reload());

// Kick everything off
pollDashboard();
setInterval(pollDashboard, 3000);
</script>

<script type="module" src="../../script/initialize-gesture.js?v=<?= time() ?>"></script>

</body>
</html>