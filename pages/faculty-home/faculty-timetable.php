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

// Handle extend request POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_id'])) {
    $schedule_id = (int)$_POST['schedule_id'];
    $extend_mins = (int)($_POST['extend_mins'] ?? 30);

    // Check if there's already a pending request for this slot
    $stmt = $conn->prepare("
        SELECT id FROM extension_requests
        WHERE schedule_id = ? AND faculty_id = ? AND status = 'pending'
    ");
    $stmt->bind_param('ii', $schedule_id, $faculty_id);
    $stmt->execute();
    $stmt->store_result();
    $already_requested = $stmt->num_rows > 0;
    $stmt->close();

    if (!$already_requested) {
        $stmt = $conn->prepare("
            INSERT INTO extension_requests (schedule_id, faculty_id, extend_mins)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param('iii', $schedule_id, $faculty_id, $extend_mins);
        $stmt->execute();
        $stmt->close();
        $_SESSION['timetable_success'] = 'Extension request submitted!';
    } else {
        $_SESSION['timetable_error'] = 'You already have a pending request for this slot.';
    }

    header('Location: faculty-timetable.php');
    exit;
}

// Current schedule label
$today = date('l');
$current_sched = 'No class right now';
$now = date('H:i:s');

// Full weekly schedule
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$schedule_by_day = [];
foreach ($days as $day) $schedule_by_day[$day] = [];

$r = $conn->query("
    SELECT s.id, s.day_of_week, s.start_time, s.end_time,
           s.extended_until, c.room_name,
           (SELECT status FROM extension_requests
            WHERE schedule_id = s.id AND faculty_id = $faculty_id
            ORDER BY requested_at DESC LIMIT 1) AS ext_status
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    WHERE s.created_by = $faculty_id
    ORDER BY FIELD(s.day_of_week,'Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'),
             s.start_time
");
while ($row = $r->fetch_assoc()) {
    $schedule_by_day[$row['day_of_week']][] = $row;
}

// ── Active schedule for timer (for Time Left widget) ─────────────────────────
$fid      = (int)$faculty_id;
$today_e  = $conn->real_escape_string($today);
$now_e    = $conn->real_escape_string($now);

$r = $conn->query("
    SELECT s.id, s.start_time, s.end_time, s.extended_until, c.room_name
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    WHERE s.faculty_id = $fid
      AND s.day_of_week = '$today_e'
      AND s.start_time <= '$now_e'
      AND (s.extended_until >= '$now_e' OR (s.extended_until IS NULL AND s.end_time >= '$now_e'))
    ORDER BY s.start_time
    LIMIT 1
");
$active_schedule = ($r && $r->num_rows > 0) ? $r->fetch_assoc() : null;

// Build schedules array for View Schedule modal
$schedules = [];
$r2 = $conn->query("
    SELECT s.id, s.day_of_week, s.start_time, s.end_time, c.room_name
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    WHERE s.created_by = $faculty_id
    ORDER BY FIELD(s.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
             s.start_time
");
while ($row = $r2->fetch_assoc()) {
    $schedules[] = $row;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!--External links-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

    <!--Relative links-->
    <link type="icon" href="../../logo.png">
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/tooltip.css">
    <link rel="stylesheet" href="../../css/modals.css">
    <link rel="stylesheet" href="../../css/faculty-timetable.css">
    <link rel="stylesheet" href="../../css/faculty-common.css">
    <link rel="stylesheet" href="../../css/faculty-settings.css">

    <title>Class Schedule – LumineSense</title>
</head>

<body class="contrast-bg">
    <div class="parent-container">

        <?php include '../../php/includes/faculty-topbar.php'; ?>

        <div class="child-container mb-3">


            <div class="main-container faculty-timetable w-auto">
                <!-- Time Left -->
                <div style="background-color: #f8f9fa;" class="section-container timetable mb-3">
                    <div class="section-topbar mx-2 justify-content-between">
                        <div>
                            <h2 class="bold"><i class="bi bi-clock me-1"></i>Time Left <span class="medium text-muted fs-6">until end of class</span></h2>
                            <!-- <h2 class="medium text-muted fs-6" style="font-size: 14px;"><i class="bi bi-info-circle me-1"></i>Today is <span class="bold">Monday, October 2nd, 2023</span></h2> -->
                        </div>
                        <div class="d-flex mx-2 align-items-center justify-content-end">
                            <!-- <button class="light h-50 w-auto" data-bs-toggle="modal" data-bs-target="#viewScheduleModal">View Schedule</button> -->
                        </div>
                    </div>
                    <div class="gap-1 align-items-center  d-flex flex-column">

                        <div class="subsection-container d-flex flex-column mx-1 align-items-center justify-content-center">
                            <?php if ($active_schedule): ?>
                                <?php
                                $end = $active_schedule['extended_until'] ?? $active_schedule['end_time'];
                                ?>
                                <h1 class="bold display-1 p-2" style="color: var(--muted-white);" id="timerDisplay" data-end="<?= htmlspecialchars($end) ?>">
                                    --:--:--
                                </h1>
                            <?php else: ?>
                                <h1 class="bold display-1 p-2" style="font-size: 5rem; color: var(--muted-white);" id="timerDisplay">00:00:00</h1>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex flex-column mx-2 align-items-end justify-content-center">
                            <?php if ($active_schedule): ?>
                                <?php
                                $end = $active_schedule['extended_until'] ?? $active_schedule['end_time'];
                                $start_12h = date('g:i A', strtotime($active_schedule['start_time']));
                                $end_12h = date('g:i A', strtotime($end));
                                ?>
                                <button class="light mt-2" onclick="requestExtend(<?= $active_schedule['id'] ?>, '<?= htmlspecialchars($active_schedule['room_name']) ?>', '<?= $start_12h ?>', '<?= $end_12h ?>')">
                                    <i class="bi bi-clock-history me-1"></i> Extend
                                </button>
                            <?php endif; ?>

                            <?php if (!$active_schedule): ?>
                                <p class="text-muted text-center mt-2 mb-1">No active class schedule right now.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Current and Next Class | Static -->
                <div style="background-color: #f8f9fa;" class="section-container timetable ">
                    <div class="section-topbar d-flex flex-column mx-2 justify-content-between">
                        <div>
                            <h2 class="bold"><i class="bi bi-info-circle me-1"></i>Class Details</h2>
                            <!-- <h2 class="medium text-muted" style="font-size: 14px;"><i class="bi bi-person me-1"></i>assigned by Faculty Head<span class="bold"> Charlie Mondragon</span></h2> -->
                        </div>
                        <div class="d-flex mx-2 align-items-center justify-content-end">
                            <!-- <button class="light h-50 w-auto" data-bs-toggle="modal" data-bs-target="#viewScheduleModal">View Schedule</button> -->
                        </div>
                    </div>
                    <div class="d-flex flex-row mx-1 gap-3 align-items-center justify-content-center mb-3">
                        <div class="subsection-container p-3">
                            <h2 class="bold text-uppercase" style="color: #fff;">Current</h2>
                            <h2 class="medium fs-6" style="color: #fff;"><i class="bi bi-door-open me-1"></i>Room: <?= htmlspecialchars($active_schedule['room_name'] ?? 'N/A') ?></h2>
                            <h2 class="medium fs-6" style="color: #fff;"><i class="bi bi-book me-1"></i>Subject: <?= htmlspecialchars($active_schedule['subject'] ?? 'N/A') ?></h2>
                        </div>
                        <div>
                            <h2 class="bold text-uppercase" style="font-size: 14px;">Next</h2>
                            <h2 class="medium fs-6" style="font-size: 14px;"><i class="bi bi-door-open me-1"></i>Room: <?= htmlspecialchars($active_schedule['room_name'] ?? 'N/A') ?></h2>
                            <h2 class="medium fs-6" style="font-size: 14px;"><i class="bi bi-book me-1"></i>Subject: <?= htmlspecialchars($active_schedule['subject'] ?? 'N/A') ?></h2>
                        </div>
                    </div>
                </div>


                <!-- Sent Requests | Static -->
                <div style="background-color: #f8f9fa;" class="section-container timetable d-flex flex-row mx-1 gap-3 align-items-center justify-content-center mb-3">

                </div>

            </div>

        </div>



        <div class="child-container">
            <!-- intro heading -->
            <div class="main-container faculty-timetable-heading d-flex flex-column align-items-center justify-content-center w-auto mb-3">
                <h2 class="bold">Class Timetable for <?= $faculty_name ?></h2>
                <p class="text-center">Effective A.Y. <?= date('Y') . '-' . (date('Y') + 1) ?> • Prepared by:
                    <span class="bold status-badge faculty-head">Faculty Head</span>
                    <span class="bold" style="color: var(--secondary-color-2);">Charlie Ampatuan</span> •
                    <span style="color: var(--secondary-color-2);">
                        Today is the
                        <span class="bold"><?= date('jS') ?></span> day of the month of
                        <span class="bold"><?= date('F') ?></span>, S.Y.
                        <span class="bold"><?= date('Y') ?></span>
                    </span><br>
                    You as the <span class="bold status-badge faculty-member">Faculty Member</span> can view all schedules of the set to you by the <span class="bold status-badge faculty-head">Faculty Head</span>, as well as request for an extension of your class schedule and end schedule prematurely if needed.
                </p>
                <!--Note: Faculty Head is static-->
            </div>

            <div class="main-container homepage gap-3" style="flex-direction:column;">
                <!-- Flash messages -->
                <?php if (!empty($_SESSION['timetable_success'])): ?>
                    <div class="alert alert-success">
                        ✅ <?= htmlspecialchars($_SESSION['timetable_success']) ?>
                    </div>
                    <?php unset($_SESSION['timetable_success']); ?>
                <?php endif; ?>
                <?php if (!empty($_SESSION['timetable_error'])): ?>
                    <div class="alert alert-warning">
                        ⚠️ <?= htmlspecialchars($_SESSION['timetable_error']) ?>
                    </div>
                    <?php unset($_SESSION['timetable_error']); ?>
                <?php endif; ?>

                <!-- Weekly schedule -->
                <div class="weekly-schedule-grid">
                    <?php foreach ($days as $day):
                        $is_today = ($day === $today);
                        $slots    = $schedule_by_day[$day];
                    ?>
                        <div class="day-card <?= $is_today ? 'today' : '' ?>">
                            <div class="day-label">
                                <?= $day ?> <?= $is_today ? '· Today' : '' ?>
                            </div>

                            <?php if (empty($slots)): ?>
                                <p class="no-sched">No classes scheduled.</p>
                                <?php else: foreach ($slots as $slot):
                                    $start    = date('g:i A', strtotime($slot['start_time']));
                                    $end      = date('g:i A', strtotime($slot['end_time']));
                                    $ext      = $slot['extended_until']
                                        ? date('g:i A', strtotime($slot['extended_until']))
                                        : null;
                                    $ext_status = $slot['ext_status'];
                                ?>
                                    <div class="slot-row">
                                        <div class="slot-header">
                                            <div class="slot-time-left">
                                                <?php
                                                // Start time
                                                $start_parts = explode(' ', $start);
                                                $start_time_part = $start_parts[0];
                                                $start_ampm = isset($start_parts[1]) ? $start_parts[1] : 'AM';

                                                // End time
                                                $end_parts = explode(' ', $end);
                                                $end_time_part = $end_parts[0];
                                                $end_ampm = isset($end_parts[1]) ? $end_parts[1] : 'AM';
                                                ?>
                                                <span class="slot-time-start"><?= $start_time_part ?></span>
                                                <span class="slot-time-separator">TO</span>
                                                <span class="slot-time-end"><?= $end_time_part ?></span>
                                                <span class="slot-time-ampm"><?= $end_ampm ?></span>
                                            </div>
                                            <div class="slot-actions-right">
                                                <?php if ($ext_status === 'pending'): ?>
                                                    <span class="badge-ext-pending"
                                                        title="Extension request pending"
                                                        data-bs-toggle="tooltip">
                                                        <i class="bi bi-hourglass-bottom"></i>
                                                    </span>
                                                    <button class="btn-icon btn-icon-view"
                                                        title="View Details"
                                                        data-bs-toggle="tooltip"
                                                        data-bs-placement="auto"
                                                        onclick="openSlotDetails(<?= $slot['id'] ?>, '<?= htmlspecialchars($slot['day_of_week']) ?>', '<?= $start ?>', '<?= $end ?>', '<?= htmlspecialchars($slot['room_name']) ?>', '<?= htmlspecialchars($ext ?? '') ?>')">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                <?php elseif ($ext_status === 'approved'): ?>
                                                    <span class="badge-ext-approved"
                                                        title="Extension approved"
                                                        data-bs-toggle="tooltip">
                                                        <i class="bi bi-check-circle"></i>
                                                    </span>
                                                    <button class="btn-icon btn-icon-view"
                                                        title="View Details"
                                                        data-bs-toggle="tooltip"
                                                        data-bs-placement="auto"
                                                        onclick="openSlotDetails(<?= $slot['id'] ?>, '<?= htmlspecialchars($slot['day_of_week']) ?>', '<?= $start ?>', '<?= $end ?>', '<?= htmlspecialchars($slot['room_name']) ?>', '<?= htmlspecialchars($ext ?? '') ?>')">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                <?php elseif ($ext_status === 'rejected'): ?>
                                                    <span class="badge-ext-rejected"
                                                        title="Extension rejected"
                                                        data-bs-toggle="tooltip">
                                                        <i class="bi bi-x-circle"></i>
                                                    </span>
                                                    <button class="extend-icon-btn"
                                                        onclick="requestExtend(<?= $slot['id'] ?>, '<?= $slot['room_name'] ?>', '<?= $start ?>', '<?= $end ?>')"
                                                        title="Re-request Extension"
                                                        data-bs-toggle="tooltip"
                                                        data-bs-placement="auto">
                                                        <i class="bi bi-clock-history"></i>
                                                    </button>
                                                    <button class="btn-icon btn-icon-view"
                                                        title="View Details"
                                                        data-bs-toggle="tooltip"
                                                        data-bs-placement="auto"
                                                        onclick="openSlotDetails(<?= $slot['id'] ?>, '<?= htmlspecialchars($slot['day_of_week']) ?>', '<?= $start ?>', '<?= $end ?>', '<?= htmlspecialchars($slot['room_name']) ?>', '<?= htmlspecialchars($ext ?? '') ?>')">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button class="extend-icon-btn"
                                                        onclick="requestExtend(<?= $slot['id'] ?>, '<?= $slot['room_name'] ?>', '<?= $start ?>', '<?= $end ?>')"
                                                        title="Request Extension"
                                                        data-bs-toggle="tooltip"
                                                        data-bs-placement="auto">
                                                        <i class="bi bi-clock-history"></i>
                                                    </button>
                                                    <button class="btn-icon btn-icon-view"
                                                        title="View Details"
                                                        data-bs-toggle="tooltip"
                                                        data-bs-placement="auto"
                                                        onclick="openSlotDetails(<?= $slot['id'] ?>, '<?= htmlspecialchars($slot['day_of_week']) ?>', '<?= $start ?>', '<?= $end ?>', '<?= htmlspecialchars($slot['room_name']) ?>', '<?= htmlspecialchars($ext ?? '') ?>')">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="slot-content">
                                            <div class="slot-room">
                                                <i class="bi bi-door-open me-1"></i><?= htmlspecialchars($slot['room_name']) ?>
                                            </div>
                                            <div class="slot-subject d-flex flex-row">
                                                <i class="bi bi-book me-1"></i>
                                                <h5>Math</h5>
                                            </div>
                                        </div>
                                    </div>
                            <?php endforeach;
                            endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

            </div>
        </div>

        <!-- Request Extension Modal -->
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
                                <span id="extend-room"></span>
                                from <span id="extend-start-time"></span>
                                to <span id="extend-end-time"></span>
                            </span>
                            <br>How many extra minutes do you need?
                        </p>
                        <div class="extend-modal-content d-flex gap-4">
                            <!-- LEFT DIV: Timer -->
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
                                    Extending time for Math discussion at <span id="extend-room"></span> for <span id="extend-time-range"></span>
                                </p>
                            </div>

                            <!-- RIGHT DIV: Extend Buttons -->
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

        <!-- Hidden form for extend submit -->
        <form id="extend-form" method="POST" action="faculty-timetable.php" style="display:none;">
            <input type="hidden" name="schedule_id" id="extend-schedule-id">
            <input type="hidden" name="extend_mins" id="extend-mins-val">
        </form>

        <?php include '../../php/includes/faculty-sidebar.php'; ?>


        <script src="../../script/animations.js"></script>
        <script src="../../script/toggles.js"></script>
        <script src="../../script/tooltip.js"></script>
    </div>

    <script>
        // Initialize Bootstrap modal for extend request
        const extendModalEl = document.getElementById('extendModal');
        const extendModal = new bootstrap.Modal(extendModalEl);

        let currentScheduleId = null;
        let currentRoom = '';
        let currentStartTime = '';
        let currentEndTime = '';
        let totalExtensionMinutes = 0;

        // Parse time string (e.g., "1:00 PM") to Date object for today
        function parseTime(timeStr) {
            const now = new Date();
            const [time, ampm] = timeStr.trim().split(' ');
            let [hours, minutes] = time.split(':').map(Number);
            if (ampm === 'PM' && hours !== 12) hours += 12;
            if (ampm === 'AM' && hours === 12) hours = 0;
            now.setHours(hours, minutes, 0, 0);
            return now;
        }

        // Format time to 12-hour format (e.g., "1:00 PM")
        function formatTime(date) {
            let hours = date.getHours();
            const minutes = date.getMinutes();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            if (hours === 0) hours = 12;
            const minStr = minutes.toString().padStart(2, '0');
            return `${hours}:${minStr} ${ampm}`;
        }

        // Calculate elapsed time between start and end
        function calculateElapsedMinutes(startTime, endTime) {
            const start = parseTime(startTime);
            const end = parseTime(endTime);
            const diffMs = end - start;
            return Math.floor(diffMs / 60000);
        }

        // Update timer display from total seconds
        function updateTimerDisplay(totalSeconds) {
            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const seconds = totalSeconds % 60;
            document.getElementById('timer-hours').value = hours.toString().padStart(2, '0');
            document.getElementById('timer-minutes').value = minutes.toString().padStart(2, '0');
            document.getElementById('timer-seconds').value = seconds.toString().padStart(2, '0');
        }

        // Get total seconds from timer inputs
        function getTotalSecondsFromInputs() {
            const hours = parseInt(document.getElementById('timer-hours').value) || 0;
            const minutes = parseInt(document.getElementById('timer-minutes').value) || 0;
            const seconds = parseInt(document.getElementById('timer-seconds').value) || 0;
            return hours * 3600 + minutes * 60 + seconds;
        }

        // Update the description text with extended time
        function updateDescription() {
            const totalSeconds = getTotalSecondsFromInputs();
            const elapsedMinutes = calculateElapsedMinutes(currentStartTime, currentEndTime);
            const extraMinutes = Math.max(0, Math.floor(totalSeconds / 60) - elapsedMinutes);

            document.getElementById('extend-room').textContent = currentRoom;
            document.getElementById('extend-start-time').textContent = currentStartTime;

            if (currentEndTime) {
                const endDateTime = parseTime(currentEndTime);
                endDateTime.setMinutes(endDateTime.getMinutes() + extraMinutes);
                const newEndTime = formatTime(endDateTime);
                document.getElementById('extend-end-time').textContent = newEndTime;
                document.getElementById('extend-time-range').textContent = `${currentStartTime} - ${newEndTime}`;
            }

            // Disable send button if timer is 00:00:00
            document.getElementById('submitExtendBtn').disabled = totalSeconds === 0;
        }

        // Reset timer to elapsed time based on slot
        function resetTimerToElapsed() {
            if (currentStartTime && currentEndTime) {
                const elapsedMinutes = calculateElapsedMinutes(currentStartTime, currentEndTime);
                totalExtensionMinutes = 0;
                updateTimerDisplay(elapsedMinutes * 60);
                updateDescription();
            } else {
                totalExtensionMinutes = 0;
                updateTimerDisplay(0);
                updateDescription();
            }
        }

        function requestExtend(scheduleId, room, startTime, endTime) {
            currentScheduleId = scheduleId;
            currentRoom = room;
            currentStartTime = startTime;
            currentEndTime = endTime;

            document.getElementById('submitExtendBtn').disabled = true;

            // Reset pills
            document.querySelectorAll('.extend-pill').forEach(btn => {
                btn.classList.remove('active', 'btn-primary');
                btn.classList.add('btn-outline-primary');
            });

            // Reset timer to elapsed time
            resetTimerToElapsed();

            extendModal.show();
        }

        // Handle pill selection - adds minutes to timer
        document.querySelectorAll('.extend-pill').forEach(btn => {
            btn.addEventListener('click', () => {
                const minsToAdd = parseInt(btn.dataset.mins);

                // Read current values directly from the inputs
                let currentHours = parseInt(document.getElementById('timer-hours').value) || 0;
                let currentMinutes = parseInt(document.getElementById('timer-minutes').value) || 0;
                let currentSeconds = parseInt(document.getElementById('timer-seconds').value) || 0;

                // Add to minutes
                currentMinutes += minsToAdd;

                // Cascade overflow upward
                if (currentMinutes >= 60) {
                    currentHours += Math.floor(currentMinutes / 60);
                    currentMinutes = currentMinutes % 60;
                }
                if (currentHours > 99) currentHours = 99;

                // Write back
                document.getElementById('timer-hours').value = currentHours.toString().padStart(2, '0');
                document.getElementById('timer-minutes').value = currentMinutes.toString().padStart(2, '0');
                document.getElementById('timer-seconds').value = currentSeconds.toString().padStart(2, '0');

                // Visual state
                document.querySelectorAll('.extend-pill').forEach(b => {
                    b.classList.remove('active', 'btn-primary');
                    b.classList.add('btn-outline-primary');
                });
                // Visual state - flash active then revert (push button behavior)
                btn.classList.add('active', 'btn-primary');
                btn.classList.remove('btn-outline-primary');

                setTimeout(() => {
                    btn.classList.remove('active', 'btn-primary');
                    btn.classList.add('btn-outline-primary');
                }, 150);

                updateDescription();
                document.getElementById('submitExtendBtn').disabled = false;
            });
        });

        // Handle timer input changes
        document.querySelectorAll('.timer-input').forEach(input => {
            input.addEventListener('focus', (e) => {
                e.target.select();
            });

            input.addEventListener('blur', (e) => {
                let val = parseInt(e.target.value) || 0;

                if (e.target.id === 'timer-hours') {
                    if (val > 99) val = 99;
                    e.target.value = val.toString().padStart(2, '0');
                } else if (e.target.id === 'timer-minutes') {
                    if (val >= 60) {
                        const carryHours = Math.floor(val / 60);
                        const remMinutes = val % 60;
                        const hoursInput = document.getElementById('timer-hours');
                        let currentHours = parseInt(hoursInput.value) || 0;
                        currentHours = Math.min(99, currentHours + carryHours);
                        hoursInput.value = currentHours.toString().padStart(2, '0');
                        val = remMinutes;
                    }
                    e.target.value = val.toString().padStart(2, '0');
                } else if (e.target.id === 'timer-seconds') {
                    if (val >= 60) {
                        const carryMinutes = Math.floor(val / 60);
                        const remSeconds = val % 60;
                        const minutesInput = document.getElementById('timer-minutes');
                        let currentMinutes = parseInt(minutesInput.value) || 0;
                        currentMinutes += carryMinutes;
                        // Seconds carry may itself push minutes over 60, cascade up
                        if (currentMinutes >= 60) {
                            const carryHours = Math.floor(currentMinutes / 60);
                            currentMinutes = currentMinutes % 60;
                            const hoursInput = document.getElementById('timer-hours');
                            let currentHours = parseInt(hoursInput.value) || 0;
                            currentHours = Math.min(99, currentHours + carryHours);
                            hoursInput.value = currentHours.toString().padStart(2, '0');
                        }
                        minutesInput.value = currentMinutes.toString().padStart(2, '0');
                        val = remSeconds;
                    }
                    e.target.value = val.toString().padStart(2, '0');
                }

                updateDescription();
            });

            input.addEventListener('input', (e) => {
                e.target.value = e.target.value.replace(/[^0-9]/g, '');
            });

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') e.target.blur();
            });
        });

        // Handle submit button click
        document.getElementById('submitExtendBtn').addEventListener('click', () => {
            const totalSeconds = getTotalSecondsFromInputs();
            const elapsedMinutes = calculateElapsedMinutes(currentStartTime, currentEndTime);
            const extensionMinutes = Math.floor(totalSeconds / 60) - elapsedMinutes;

            if (extensionMinutes > 0) {
                document.getElementById('extend-schedule-id').value = currentScheduleId;
                document.getElementById('extend-mins-val').value = extensionMinutes;
                document.getElementById('extend-form').submit();
            }
        });

        // Close modal on hide
        extendModalEl.addEventListener('hidden.bs.modal', () => {
            currentScheduleId = null;
            currentRoom = '';
            currentStartTime = '';
            currentEndTime = '';
            totalExtensionMinutes = 0;
        });

        // ── Countdown timer for Time Left widget ───────────────────────────────
        let _scheduleEnd = null;
        (function() {
            const display = document.getElementById('timerDisplay');
            const phpEnd = display ? display.dataset.end : null;
            if (phpEnd) _scheduleEnd = phpEnd;

            function pad(n) {
                return String(n).padStart(2, '0');
            }

            window._tickTimer = function() {
                if (!display) return;
                if (!_scheduleEnd) {
                    display.textContent = '00:00:00';
                    display.classList.remove('text-danger');
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
                } else {
                    display.classList.remove('text-danger');
                }
            };
            window._tickTimer();
            setInterval(window._tickTimer, 1000);
        })();

        // ── View Slot Details Modal ───────────────────────────────
        let viewSlotModal = null;

        function openSlotDetails(id, day, startTime, endTime, room, extension) {
            if (!viewSlotModal) {
                viewSlotModal = new bootstrap.Modal(document.getElementById('viewSlotModal'));
            }

            document.getElementById('slot-day').textContent = day;
            document.getElementById('slot-time').textContent = `${startTime} — ${endTime}`;
            document.getElementById('slot-room').textContent = room;

            viewSlotModal.show();
        }
    </script>

    <!-- View Slot Details Modal -->
    <div class="profile-details-modal modal fade" id="viewSlotModal" tabindex="-1" aria-labelledby="viewSlotLabel" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold" id="viewSlotLabel">
                        <i class="bi bi-calendar-event me-2"></i>Schedule Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3">
                            <i class="bi bi-calendar-week text-primary" style="font-size:1.6rem; flex-shrink:0;"></i>
                            <div class="flex-grow-1">
                                <strong>Day</strong>
                                <div id="slot-day" class="text-muted"></div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3">
                            <i class="bi bi-clock text-primary" style="font-size:1.6rem; flex-shrink:0;"></i>
                            <div class="flex-grow-1">
                                <strong>Time</strong>
                                <div id="slot-time" class="text-muted"></div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3">
                            <i class="bi bi-door-open text-primary" style="font-size:1.6rem; flex-shrink:0;"></i>
                            <div class="flex-grow-1">
                                <strong>Room</strong>
                                <div id="slot-room" class="text-muted"></div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3" id="slot-extension-row">
                            <i class="bi bi-hourglass-split text-primary" style="font-size:1.6rem; flex-shrink:0;"></i>
                            <div class="flex-grow-1">
                                <strong>Subject</strong>
                                <div id="slot-extension" class="text-muted">Math</div><!---- Placeholder subject, replace with actual subject if available -->
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

</body>

</html>