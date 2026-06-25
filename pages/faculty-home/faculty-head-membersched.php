<?php
require_once '../../php/session_guard.php';
check_faculty();
require_once '../../php/db_connect.php';

if (empty($_SESSION['is_head'])) {
    header('Location: faculty-timetable.php');
    exit;
}

$faculty_name = htmlspecialchars($_SESSION['faculty_name']);
$faculty_id   = (int)$_SESSION['faculty_id'];
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

// Department this head manages
$dept_id = 0;
$stmt = $conn->prepare("SELECT id FROM departments WHERE head_faculty_id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$stmt->bind_result($dept_id);
$stmt->fetch();
$stmt->close();

$member_id = (int)($_GET['faculty_id'] ?? 0);
if (!$member_id || !$dept_id) {
    header('Location: faculty-head-timetable.php');
    exit;
}

// Verify member belongs to department
$member_name = '';
$member_area = '';
$stmt = $conn->prepare("
    SELECT CONCAT(f.first_name, ' ', f.last_name) AS full_name,
           sa.name AS subject_area_name
    FROM faculty f
    LEFT JOIN subject_area sa ON sa.id = f.subject_area_id
    WHERE f.id = ? AND f.department_id = ?
    LIMIT 1
");
$stmt->bind_param('ii', $member_id, $dept_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$member) {
    header('Location: faculty-head-timetable.php');
    exit;
}

$member_name = $member['full_name'];
$member_area = $member['subject_area_name'] ?: 'No subject area assigned';

$today = date('l');
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$schedule_by_day = [];
foreach ($days as $day) $schedule_by_day[$day] = [];

$mid = (int)$member_id;
$r = $conn->query("
    SELECT s.id, s.day_of_week, s.start_time, s.end_time, s.classroom_id,
           s.extended_until, s.subject_id,
           c.room_name,
           sub.name AS subject_name,
           (SELECT status FROM extension_requests
            WHERE schedule_id = s.id AND faculty_id = s.faculty_id
            ORDER BY requested_at DESC LIMIT 1) AS ext_status
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE s.faculty_id = $mid
    ORDER BY FIELD(s.day_of_week,'Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'),
             s.start_time
");
while ($row = $r->fetch_assoc()) {
    $schedule_by_day[$row['day_of_week']][] = $row;
}

// Rooms for edit modal
$rooms = [];
$rr = $conn->query('SELECT id, room_name FROM classrooms ORDER BY room_name');
if ($rr) {
    while ($row = $rr->fetch_assoc()) $rooms[] = $row;
}

// Subjects for edit modal
$subjects = [];
$sr = $conn->query('SELECT id, name FROM subjects ORDER BY name');
if ($sr) {
    while ($row = $sr->fetch_assoc()) $subjects[] = $row;
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
    <link rel="stylesheet" href="../../css/faculty-head-timetable.css">

    <title><?= htmlspecialchars($member_name) ?> – Schedule – LumineSense</title>
</head>

<body class="contrast-bg">
    <div class="parent-container">

        <?php include '../../php/includes/faculty-topbar.php'; ?>

        <div class="child-container mb-3">

            <div class="main-container faculty-timetable w-auto mb-3">
                <button class="light mb-3" onclick="dissolve('faculty-head-timetable.php')">
                    <i class="bi bi-arrow-left me-1"></i> Back to Department
                </button>
            </div>

            <div class="main-container faculty-timetable-heading d-flex flex-column align-items-center justify-content-center w-auto mb-3">
                <h2 class="bold"><?= htmlspecialchars($member_name) ?>'s Schedule</h2>
                <p class="text-center mb-0">
                    Subject Area: <span class="bold"><?= htmlspecialchars($member_area) ?></span> •
                    Effective A.Y. <?= date('Y') . '-' . (date('Y') + 1) ?> •
                    <span class="bold status-badge faculty-head">Faculty Head</span>
                    <span class="bold" style="color: var(--secondary-color-2);"><?= $faculty_name ?></span><br>
                    <span style="color: var(--secondary-color-2);">
                        Today is the
                        <span class="bold"><?= date('jS') ?></span> day of the month of
                        <span class="bold"><?= date('F') ?></span>, S.Y.
                        <span class="bold"><?= date('Y') ?></span>
                    </span>
                </p>
            </div>

            <div class="main-container homepage gap-3" style="flex-direction:column;">
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
                                $subject_label = !empty($slot['subject_name'])
                                    ? trim($slot['subject_name'])
                                    : 'None assigned';
                            ?>
                                <div class="slot-row">
                                    <div class="slot-header">
                                        <div class="slot-time-left">
                                            <?php
                                            $start_parts = explode(' ', $start);
                                            $start_time_part = $start_parts[0];
                                            $start_ampm = $start_parts[1] ?? 'AM';
                                            $end_parts = explode(' ', $end);
                                            $end_time_part = $end_parts[0];
                                            $end_ampm = $end_parts[1] ?? 'AM';
                                            ?>
                                            <span class="slot-time-start"><?= $start_time_part ?></span>
                                            <span class="slot-time-separator">TO</span>
                                            <span class="slot-time-end"><?= $end_time_part ?></span>
                                            <span class="slot-time-ampm"><?= $end_ampm ?></span>
                                        </div>
                                        <div class="slot-actions-right">
                                            <button class="btn-icon btn-icon-edit"
                                                title="Edit Schedule Details"
                                                onclick="openEditScheduleModal(
                                                    <?= (int)$slot['id'] ?>,
                                                    '<?= $slot['day_of_week'] ?>',
                                                    '<?= substr($slot['start_time'], 0, 5) ?>',
                                                    '<?= substr($slot['end_time'], 0, 5) ?>',
                                                    <?= (int)$slot['classroom_id'] ?>,
                                                    <?= (int)($slot['subject_id'] ?? 0) ?>
                                                )"
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="auto">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn-icon btn-icon-view"
                                                title="View Details"
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="auto"
                                                onclick="openSlotDetails(
                                                    '<?= htmlspecialchars($slot['day_of_week'], ENT_QUOTES) ?>',
                                                    '<?= $start ?>',
                                                    '<?= $end ?>',
                                                    '<?= htmlspecialchars($slot['room_name'], ENT_QUOTES) ?>',
                                                    '<?= htmlspecialchars($subject_label, ENT_QUOTES) ?>'
                                                )">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="slot-content">
                                        <div class="slot-room">
                                            <i class="bi bi-door-open me-1"></i><?= htmlspecialchars($slot['room_name']) ?>
                                        </div>
                                        <div class="slot-subject d-flex flex-row">
                                            <i class="bi bi-book me-1"></i>
                                            <h5><?= htmlspecialchars($subject_label) ?></h5>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>

        <?php include '../../php/includes/faculty-sidebar.php'; ?>

        <script src="../../script/animations.js"></script>
        <script src="../../script/toggles.js"></script>
        <script src="../../script/tooltip.js"></script>
    </div>

    <!-- Edit Schedule Modal -->
    <div class="profile-details-modal modal fade" id="editScheduleModal" tabindex="-1" aria-labelledby="editScheduleLabel" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold" id="editScheduleLabel">
                        <i class="bi bi-pencil me-2"></i>Edit Schedule Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="edit-slot-id" value="">
                    <div class="mb-3">
                        <label class="form-label bold">Day of Week</label>
                        <select class="form-select" id="edit-day">
                            <?php foreach ($days as $d): ?>
                                <option value="<?= $d ?>"><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label bold">Start Time</label>
                            <input type="time" class="form-control" id="edit-start">
                        </div>
                        <div class="col-6">
                            <label class="form-label bold">End Time</label>
                            <input type="time" class="form-control" id="edit-end">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label bold">Room</label>
                        <select class="form-select" id="edit-room">
                            <?php foreach ($rooms as $rm): ?>
                                <option value="<?= (int)$rm['id'] ?>"><?= htmlspecialchars($rm['room_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label bold">Subject</label>
                        <select class="form-select" id="edit-subject">
                            <option value="0">None assigned</option>
                            <?php foreach ($subjects as $sub): ?>
                                <option value="<?= (int)$sub['id'] ?>"><?= htmlspecialchars(trim($sub['name'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="medium" onclick="saveSchedule()">
                        <i class="bi bi-check-lg me-1"></i>Save
                    </button>
                </div>
            </div>
        </div>
    </div>

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
                        <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3">
                            <i class="bi bi-book text-primary" style="font-size:1.6rem; flex-shrink:0;"></i>
                            <div class="flex-grow-1">
                                <strong>Subject</strong>
                                <div id="slot-subject" class="text-muted"></div>
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

    <script>
        let editScheduleModal = null;
        let viewSlotModal = null;

        function openEditScheduleModal(id, day, start, end, roomId, subjectId) {
            if (!editScheduleModal) {
                editScheduleModal = new bootstrap.Modal(document.getElementById('editScheduleModal'));
            }
            document.getElementById('edit-slot-id').value = id;
            document.getElementById('edit-day').value = day;
            document.getElementById('edit-start').value = start;
            document.getElementById('edit-end').value = end;
            document.getElementById('edit-room').value = roomId;
            document.getElementById('edit-subject').value = subjectId || 0;
            editScheduleModal.show();
        }

        async function saveSchedule() {
            const slotId    = document.getElementById('edit-slot-id').value;
            const day       = document.getElementById('edit-day').value;
            const start     = document.getElementById('edit-start').value;
            const end       = document.getElementById('edit-end').value;
            const roomId    = document.getElementById('edit-room').value;
            const subjectId = document.getElementById('edit-subject').value;

            if (!day || !start || !end || !roomId) {
                alert('Please fill in all required fields.');
                return;
            }
            if (start >= end) {
                alert('End time must be after start time.');
                return;
            }

            const body = new URLSearchParams({
                action: 'update_schedule',
                slot_id: slotId,
                room_id: roomId,
                day_of_week: day,
                start_time: start,
                end_time: end,
                subject_id: subjectId
            });

            const res = await fetch('../../php/handlers/faculty-head-handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body
            });
            const data = await res.json();

            if (data.success) {
                window.location.reload();
            } else {
                alert(data.message || 'Could not save schedule.');
            }
        }

        function openSlotDetails(day, startTime, endTime, room, subject) {
            if (!viewSlotModal) {
                viewSlotModal = new bootstrap.Modal(document.getElementById('viewSlotModal'));
            }
            document.getElementById('slot-day').textContent = day;
            document.getElementById('slot-time').textContent = `${startTime} — ${endTime}`;
            document.getElementById('slot-room').textContent = room;
            document.getElementById('slot-subject').textContent = subject;
            viewSlotModal.show();
        }
    </script>

</body>

</html>
