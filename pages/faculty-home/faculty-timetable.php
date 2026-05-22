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
$days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
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
    ORDER BY FIELD(s.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
             s.start_time
");
while ($row = $r->fetch_assoc()) {
    $schedule_by_day[$row['day_of_week']][] = $row;
    // Check current schedule
    if ($row['day_of_week'] === $today && $now >= $row['start_time'] && $now <= $row['end_time']) {
        $current_sched = $row['room_name'] . ' · '
            . date('g:i A', strtotime($row['start_time'])) . ' - '
            . date('g:i A', strtotime($row['end_time']));
    }
}

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
    <title>Class Schedule – LumineSense</title>

    <style>
        .day-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 0.8rem;
        }
        .day-label {
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #9f9f9f;
            margin-bottom: 0.5rem;
        }
        .day-label.today { color: var(--secondary-color-4, #9b00e9); }
        .slot-row {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            background: #fff;
            border-radius: 8px;
            padding: 0.7rem 1rem;
            margin-bottom: 0.4rem;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        .slot-time { font-size: 13px; color: #555; min-width: 130px; }
        .slot-room { flex: 1; font-weight: 600; font-size: 14px; }
        .badge-ext-pending  { background: #fff3cd; color: #856404; padding: 3px 10px; border-radius: 20px; font-size: 11px; }
        .badge-ext-approved { background: #d1e7dd; color: #0f5132; padding: 3px 10px; border-radius: 20px; font-size: 11px; }
        .badge-ext-rejected { background: #f8d7da; color: #842029; padding: 3px 10px; border-radius: 20px; font-size: 11px; }
        .no-sched { color: #ccc; font-size: 13px; padding: 0.3rem 0; }
    </style>
</head>
<body class="contrast-bg">
<div class="parent-container">

    <!-- TOPBAR -->
    <div class="topbar d-flex">
        <button type="button" id="sidebarTrigger"><i class="bi bi-list"></i></button>
        <div class="col d-flex flex-column px-3">
            <h1 class="bold">Class Schedule</h1>
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
            <?php foreach ($days as $day):
                $is_today = ($day === $today);
                $slots    = $schedule_by_day[$day];
            ?>
            <div class="day-card">
                <div class="day-label <?= $is_today ? 'today' : '' ?>">
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
                        <div class="slot-time">
                            <?= $start ?> – <?= $end ?>
                            <?php if ($ext): ?>
                                <br><small class="text-success">Extended to <?= $ext ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="slot-room">
                            <i class="bi bi-door-open me-1"></i><?= htmlspecialchars($slot['room_name']) ?>
                        </div>
                        <div>
                            <?php if ($ext_status === 'pending'): ?>
                                <span class="badge-ext-pending">⏳ Pending</span>
                            <?php elseif ($ext_status === 'approved'): ?>
                                <span class="badge-ext-approved">✔ Approved</span>
                            <?php elseif ($ext_status === 'rejected'): ?>
                                <span class="badge-ext-rejected">✖ Rejected</span>
                                <!-- Allow re-request if rejected -->
                                <button class="light ms-1"
                                        onclick="requestExtend(<?= $slot['id'] ?>, '<?= $slot['room_name'] ?>', '<?= $start ?>')">
                                    Re-request
                                </button>
                            <?php else: ?>
                                <button class="light"
                                        onclick="requestExtend(<?= $slot['id'] ?>, '<?= $slot['room_name'] ?>', '<?= $start ?>')">
                                    Extend
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <?php endforeach; ?>

        </div>
    </div>

    <!-- Extend Modal -->
    <div class="notify-modal" id="extend-modal" style="display:none;">
        <div class="modal-box">
            <div id="modal-header">
                <h5><strong>⏱</strong> Request Extension</h5>
            </div>
            <div id="modal-body">
                <p id="extend-label"></p>
                <label>Extend by:</label>
                <select id="extend-mins" class="form-select mt-1">
                    <option value="15">15 minutes</option>
                    <option value="30" selected>30 minutes</option>
                    <option value="45">45 minutes</option>
                    <option value="60">1 hour</option>
                </select>
            </div>
            <div id="modal-footer">
                <button class="medium" onclick="submitExtend()">CONFIRM</button>
                <button class="medium" type="button" onclick="closeExtendModal()">CANCEL</button>
            </div>
        </div>
    </div>

    <!-- Hidden form for extend submit -->
    <form id="extend-form" method="POST" action="faculty-timetable.php" style="display:none;">
        <input type="hidden" name="schedule_id" id="extend-schedule-id">
        <input type="hidden" name="extend_mins" id="extend-mins-val">
    </form>

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

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
</div>

<script>
    // Sidebar triggers
    document.getElementById('sidebarTrigger').addEventListener('click', function () {
        bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('sidebarOffcanvas')).toggle();
    });
    document.getElementById('sidebarTrigger2').addEventListener('click', function () {
        bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('profileOffcanvas')).toggle();
    });

    let currentScheduleId = null;

    function requestExtend(scheduleId, room, time) {
        currentScheduleId = scheduleId;
        document.getElementById('extend-label').textContent
            = `Request extension for ${room} at ${time}?`;
        document.getElementById('extend-modal').style.display = 'flex';
    }

    function closeExtendModal() {
        document.getElementById('extend-modal').style.display = 'none';
    }

    function submitExtend() {
        document.getElementById('extend-schedule-id').value = currentScheduleId;
        document.getElementById('extend-mins-val').value
            = document.getElementById('extend-mins').value;
        document.getElementById('extend-form').submit();
    }
</script>
</body>
</html>