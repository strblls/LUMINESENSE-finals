<?php
date_default_timezone_set('Asia/Manila');
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

$member_id    = (int)($_GET['faculty_id'] ?? 0);
$department_id = (int)($_GET['department_id'] ?? 0);
if (!$member_id || !$department_id) {
    header('Location: faculty-head-timetable.php');
    exit;
}

// Verify member belongs to any of the departments the head manages
$member_name = '';
$member_area = '';
$stmt = $conn->prepare("
    SELECT CONCAT(f.first_name, ' ', f.last_name) AS full_name,
           GROUP_CONCAT(DISTINCT sa.name ORDER BY sa.name SEPARATOR ', ') AS subject_area_name
    FROM faculty f
    JOIN junction_faculty_department jfd ON f.id = jfd.faculty_id
    LEFT JOIN junction_faculty_subjectarea jfsa ON f.id = jfsa.faculty_id
    LEFT JOIN subject_area sa ON sa.id = jfsa.subject_area_id AND sa.department_id = jfd.department_id
    JOIN departments d ON d.id = jfd.department_id
    WHERE f.id = ? 
      AND d.id = ?
      AND d.head_faculty_id = ? 
      AND d.status = 'active'
    GROUP BY f.id
    LIMIT 1
");
$stmt->bind_param('iii', $member_id, $department_id, $faculty_id);
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
           s.extended_until, s.subject_id, s.created_by,
           c.room_name,
           sub.name AS subject_name,
           cr.first_name AS creator_first, cr.last_name AS creator_last,
           (SELECT status FROM extension_requests
            WHERE schedule_id = s.id AND faculty_id = s.faculty_id
            ORDER BY requested_at DESC LIMIT 1) AS ext_status
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    LEFT JOIN subjects sub ON sub.id = s.subject_id
    LEFT JOIN faculty cr ON cr.id = s.created_by
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

// Subjects for edit modal (only those directly assigned to this member)
$subjects = [];
$sr = $conn->prepare("
    SELECT DISTINCT s.id, s.name
    FROM subjects s
    JOIN junction_faculty_subject jfs ON s.id = jfs.subject_id
    WHERE jfs.faculty_id = ?
      AND s.subject_area_id IN (
          SELECT id FROM subject_area WHERE department_id = ?
      )
    ORDER BY s.name
");
$sr->bind_param('ii', $member_id, $department_id);
$sr->execute();
$res_sr = $sr->get_result();
if ($res_sr) {
    while ($row = $res_sr->fetch_assoc()) $subjects[] = $row;
}
$sr->close();

// ── Member coverage: subject areas and their subjects (within this department) ──
$coverage = [];
$cov_stmt = $conn->prepare("
    SELECT sa.id AS sa_id, sa.name AS sa_name, s.id AS subj_id, s.name AS subj_name
    FROM junction_faculty_subjectarea jfsa
    JOIN subject_area sa ON sa.id = jfsa.subject_area_id
    LEFT JOIN junction_faculty_subject jfs ON jfs.faculty_id = jfsa.faculty_id
    LEFT JOIN subjects s ON s.id = jfs.subject_id AND s.subject_area_id = sa.id
    WHERE jfsa.faculty_id = ?
      AND sa.department_id = ?
    ORDER BY sa.name, s.name
");
$cov_stmt->bind_param('ii', $member_id, $department_id);
$cov_stmt->execute();
$cov_res = $cov_stmt->get_result();
while ($row = $cov_res->fetch_assoc()) {
    $sa_id = $row['sa_id'];
    if (!isset($coverage[$sa_id])) {
        $coverage[$sa_id] = ['name' => $row['sa_name'], 'subjects' => []];
    }
    if ($row['subj_id']) {
        $coverage[$sa_id]['subjects'][$row['subj_id']] = $row['subj_name'];
    }
}
$cov_stmt->close();

// Determine if any subject is actually assigned to the member
$has_any_subject = false;
foreach ($coverage as $sa) {
    if (!empty($sa['subjects'])) {
        $has_any_subject = true;
        break;
    }
}

// ── Head's full name ──
$head_name = '';
$hstmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM faculty WHERE id = ?");
$hstmt->bind_param('i', $faculty_id);
$hstmt->execute();
$hstmt->bind_result($head_name);
$hstmt->fetch();
$hstmt->close();

// ── Department name ──
$dept_name = '';
$dstmt = $conn->prepare("SELECT name FROM departments WHERE id = ?");
$dstmt->bind_param('i', $department_id);
$dstmt->execute();
$dstmt->bind_result($dept_name);
$dstmt->fetch();
$dstmt->close();

// ── Last edited timestamp and who edited it ──
$last_edited = null;
$edited_by_name = '';
$lestmt = $conn->prepare("
    SELECT COALESCE(s.updated_at, s.created_at) AS last_time,
           CONCAT(f.first_name, ' ', f.last_name) AS editor_name
    FROM schedules s
    LEFT JOIN faculty f ON f.id = COALESCE(s.updated_by, s.created_by)
    WHERE s.faculty_id = ?
    ORDER BY COALESCE(s.updated_at, s.created_at) DESC
    LIMIT 1
");
$lestmt->bind_param('i', $member_id);
$lestmt->execute();
$le_res = $lestmt->get_result();
if ($le_row = $le_res->fetch_assoc()) {
    $last_edited = $le_row['last_time'];
    $edited_by_name = ' by ' . ($le_row['editor_name'] ?? '');
}
$lestmt->close();

// ── Ordinal helper ──
function ordinal($number)
{
    $ends = ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'];
    if (($number % 100) >= 11 && ($number % 100) <= 13) return $number . 'th';
    return $number . $ends[$number % 10];
}

// ── Date map for each day of the week ──
$dow_map = ['Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6];
$today_dow_num = $dow_map[$today];
$day_date_map = [];
$day_date_full = [];
foreach ($days as $day) {
    $diff = $dow_map[$day] - $today_dow_num;
    $dt = new DateTime("$diff days");
    $day_date_map[$day] = strtoupper($dt->format('M j'));
    $day_date_full[$day] = $dt->format('F j');
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

            <div class="main-container faculty-timetable-heading d-flex flex-column align-items-center justify-content-center w-auto mb-2" style="position:relative; background-color: var(--secondary-color-2);">
                <div class="d-flex gap-2" style="position:absolute;left:8px;top:50%;transform:translateY(-50%);">
                    <button type="button" class="timetable-btn" onclick="dissolve('faculty-head-timetable.php')" title="Back to Department">
                        <i class="bi bi-arrow-left"></i>
                        <span class="timetable-btn-title bold">Back</span>
                    </button>
                    <button type="button" class="timetable-btn" data-bs-toggle="modal" data-bs-target="#editScheduleModal" onclick="openAddScheduleModal()" title="Add Schedule Slot">
                        <i class="bi bi-plus-lg"></i>
                        <span class="timetable-btn-title bold">Add Slot</span>
                    </button>
                </div>
                <div class="d-flex gap-2" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);">
                    <button type="button" class="timetable-btn" data-panel="panelCoverage" title="Coverage Details">
                        <span class="timetable-btn-title bold">Coverage</span>
                        <i class="bi bi-layout-three-columns"></i>
                    </button>
                    <button type="button" class="timetable-btn" data-panel="panelInfo" title="Schedule Info">
                        <span class="timetable-btn-title bold">Info</span>
                        <i class="bi bi-info-circle"></i>
                    </button>
                </div>
                <div class="p-2" style="color: #fff; background-color: var(--secondary-color-1); border-radius: 5px;">
                    <h2 class="bold"><?= htmlspecialchars($member_name) ?>'s Schedule </h2>
                    <p class="text-uppercase text-center mb-0 " style="font-size: 14px; color: var(--muted-white); ">
                        Effective A.Y. <strong><?= date('Y') ?>-<?= date('Y', strtotime('+1 year')) ?></strong>
                    </p>
                </div>

                <!-- Coverage panel -->
                <div id="panelCoverage" class="timetable-panel panel-from-right p-3 m-3">
                    <div style="background-color: #f8f9fa;" class="section-container timetable mb-3">
                        <div class="section-topbar mx-2 justify-content-between">
                            <div>
                                <h2 class="bold"><i class="bi bi-layout-three-columns me-1"></i>Assigned Coverage</h2>
                            </div>
                        </div>
                        <div class="d-flex flex-column p-2 gap-2" style="max-height:25vh;overflow-y:auto;">
                            <?php if (!empty($coverage)): ?>
                                <?php foreach ($coverage as $sa): ?>
                                    <div class="dept-info-card mb-0 p-2">
                                        <div class="mt-1">
                                            <i class="bi bi-briefcase"></i>
                                            <span class="dept-subject-area bold dept-emphases"><?= htmlspecialchars($sa['name']) ?></span>
                                        </div>
                                        <?php if (!empty($sa['subjects'])): ?>
                                            <div class="d-flex flex-wrap gap-1 mt-1">
                                                <i class="bi bi-book"></i>
                                                <?php foreach ($sa['subjects'] as $subj_name): ?>
                                                    <span class="subarea-subject bold dept-emphases"><?= htmlspecialchars($subj_name) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="small text-muted mt-1">No subjects under this area.</div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-muted">No subject areas assigned.</span>
                            <?php endif; ?>
                            <?php if (!$has_any_subject): ?>
                                <p style="text-align:justify;" class="small mb-2"><br>
                                    <strong>Attention:</strong> Currently, <?= htmlspecialchars($member_name) ?> <strong>doesn't have any subjects assigned for teaching</strong>.
                                    Please assign subjects to this faculty member <strong>in the previous page</strong> to ensure they have the necessary teaching responsibilities.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Info panel -->
                <div id="panelInfo" class="timetable-panel panel-from-right p-3 m-3">
                    <div style="background-color: #f8f9fa;" class="section-container timetable mb-3">
                        <div class="section-topbar mx-2 justify-content-between">
                            <div>
                                <h2 class="bold"><i class="bi bi-info-circle me-1"></i>Schedule Info</h2>
                            </div>
                        </div>
                        <div class="d-flex flex-column p-3 gap-2">
                            <div class="dept-info-card mb-0 p-2">
                                <div class="small text-muted">Prepared by</div>
                                <div><span class="status-badge faculty-head bold">Faculty Head</span> <strong><?= htmlspecialchars($head_name) ?></strong></div>
                            </div>
                            <div class="dept-info-card mb-0 p-2">
                                <div class="small text-muted">Last Edited</div>
                                <div><strong><?= $last_edited ? date('F j, Y (g:i A)', strtotime($last_edited)) : 'No schedules yet' ?></strong><strong> <?= htmlspecialchars($edited_by_name) ?></strong></div>
                            </div>
                            <div class="dept-info-card mb-0 p-2">
                                <div class="small text-muted">Current Department</div>
                                <div><strong><?= htmlspecialchars($dept_name) ?></strong></div>
                            </div>
                            <div class="dept-info-card mb-0 p-2">
                                <p style="text-align:justify;" class="small mb-0"><br>
                                    <strong>Note:</strong> As a <span class="status-badge faculty-head bold">Faculty Head</span> of this department, you can manage the schedule of this faculty member.
                                    You can add, edit, or delete schedule slots as needed while abiding by the department's policies and guidelines.
                                    Please ensure that any changes made are communicated to the faculty member and relevant parties.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Weekly Calendar Type -->
            <div class="main-container homepage gap-3" style="flex-direction:column;">
                <div class="weekly-schedule-grid">
                    <?php foreach ($days as $day):
                        $is_today = ($day === $today);
                        $slots    = $schedule_by_day[$day];
                    ?>
                        <div class="day-card <?= $is_today ? 'today' : '' ?>">
                            <div class="day-label">
                                <div class="text-uppercase small fw-bold mb-1" style="font-size:11px;letter-spacing:0.5px;color:<?= $is_today ? '#fff' : '#6c757d' ?>;"><?= $day_date_map[$day] ?? '' ?></div>
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
                                    $is_owner = (int)$slot['created_by'] === $faculty_id;
                                    $creator_name = trim(($slot['creator_first'] ?? '') . ' ' . ($slot['creator_last'] ?? ''));
                                ?>
                                    <div class="slot-row">
                                        <div class="slot-time">
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
                                        <div class="slot-content">
                                            <div class="slot-room">
                                                <i class="bi bi-door-open me-1"></i><?= htmlspecialchars($slot['room_name']) ?>
                                            </div>
                                            <div class="slot-subject d-flex flex-row">
                                                <i class="bi bi-book me-1"></i>
                                                <h5><?= htmlspecialchars($subject_label) ?></h5>
                                            </div>
                                        </div>
                                        <div class="slot-actions">
                                            <button class="btn-icon <?= $is_owner ? 'btn-icon-edit' : 'btn-icon-disabled' ?>"
                                                title="<?= $is_owner ? 'Edit Schedule Details' : 'Restricted - assigned by another faculty head' ?>"
                                                onclick="<?= $is_owner
                                                                ? "openEditScheduleModal(" . (int)$slot['id'] . ",'" . $slot['day_of_week'] . "','" . substr($slot['start_time'], 0, 5) . "','" . substr($slot['end_time'], 0, 5) . "'," . (int)$slot['classroom_id'] . "," . (int)($slot['subject_id'] ?? 0) . ",'" . htmlspecialchars($subject_label, ENT_QUOTES) . "')"
                                                                : "showRestrictedModal('" . htmlspecialchars($creator_name, ENT_QUOTES) . "')"
                                                            ?>"
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
                                            <button class="btn-icon <?= $is_owner ? 'btn-icon-del' : 'btn-icon-disabled' ?>"
                                                title="<?= $is_owner ? 'Delete Schedule' : 'Restricted - assigned by another faculty head' ?>"
                                                onclick="<?= $is_owner
                                                                ? "confirmDeleteSchedule(" . (int)$slot['id'] . ")"
                                                                : "showRestrictedModal('" . htmlspecialchars($creator_name, ENT_QUOTES) . "')"
                                                            ?>"
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="auto">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                            <?php endforeach;
                            endif; ?>
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
                    <input type="hidden" id="edit-is-add" value="">
                    <div class="mb-3">
                        <label class="form-label bold">Day of Week</label>
                        <select class="form-select" id="edit-day">
                            <?php foreach ($days as $d):
                                $day_date_label = $day_date_full[$d] ?? '';
                            ?>
                                <option value="<?= $d ?>" <?= $d === $today ? 'selected' : '' ?>><?= $d ?> (<?= $day_date_label ?>)</option>
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
                        <input type="text" class="form-control mb-2" id="edit-subject-search" placeholder="Search subjects..." autocomplete="off">
                        <div class="mb-1" style="font-size:13px;color:var(--text-muted);">Selected: <span id="edit-selected-subject-name" class="text-muted" style="font-style:italic;">None</span></div>
                        <div class="d-flex flex-wrap gap-1" id="edit-available-subjects-container">
                            <?php foreach ($subjects as $sub): ?>
                                <span class="subarea-subject bold dept-emphases align-items-center justify-content-center px-3 edit-subject-item"
                                    data-subject-id="<?= (int)$sub['id'] ?>"
                                    data-subject-name="<?= htmlspecialchars(trim($sub['name']), ENT_QUOTES) ?>"
                                    title="Click to select this subject"
                                    style="cursor:pointer;">
                                    <?= htmlspecialchars(trim($sub['name'])) ?>
                                    <button type="button" class="p-0 ms-1 d-inline-flex flex-shrink-0 align-items-center text-white border-0 bg-transparent" title="Select Subject">
                                        <i class="bi bi-plus-circle"></i>
                                    </button>
                                </span>
                            <?php endforeach; ?>
                            <?php if (empty($subjects)): ?>
                                <p class="text-muted small mb-0">No subjects assigned.</p>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" id="edit-subject-id" value="0">
                        <input type="hidden" id="edit-subject-name" value="">
                    </div>
                </div>
                <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                    <button type="button" class="light w-100 px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="medium w-100 px-3" onclick="saveSchedule()">
                        <i class="bi bi-check-lg me-1"></i>Save
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirm Modal -->
    <div class="modal fade" id="deleteScheduleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header" style="background:linear-gradient(135deg,#c0004e,#e05580);color:#fff;">
                    <h5 class="modal-title" style="font-weight:700;">Delete Schedule Slot</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="bi bi-trash" style="font-size:2.5rem;color:#c0004e;"></i>
                    <p class="mt-3 mb-0" style="font-size:15px;">
                        Are you sure you want to delete this schedule slot?
                    </p>
                </div>
                <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                    <button class="light" data-bs-dismiss="modal">Cancel</button>
                    <button class="medium" style="background:#c0004e;" onclick="executeDeleteSchedule()">
                        <i class="bi bi-trash me-1"></i> Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Save Schedule Modal (stacked on top of edit modal) -->
    <div class="modal fade" id="confirmSaveScheduleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header" style="background:linear-gradient(135deg,#2a7a3e,#5cb85c);color:#fff;">
                    <h5 class="modal-title" style="font-weight:700;">Confirm Schedule</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="bi bi-question-circle" style="font-size:2.5rem;color:#2a7a3e;"></i>
                    <p class="mt-3 mb-0" style="font-size:15px;" id="confirm-save-message">
                        Are you sure you want to save this schedule slot?
                    </p>
                </div>
                <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                    <button class="light" data-bs-dismiss="modal">Cancel</button>
                    <button class="medium" style="background:#2a7a3e;" id="confirm-save-btn">
                        <i class="bi bi-check-lg me-1"></i> Confirm
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Overlap Warning Modal -->
    <div class="modal fade" id="overlapWarningModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header" style="background:linear-gradient(135deg,#c0392b,#e74c3c);color:#fff;">
                    <h5 class="modal-title" style="font-weight:700;">Schedule Overlap</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="bi bi-exclamation-octagon" style="font-size:2.5rem;color:#c0392b;"></i>
                    <p class="mt-3 mb-2" style="font-size:15px;font-weight:600;">This slot overlaps with an existing schedule:</p>
                    <div id="overlap-details" class="text-start d-inline-block" style="font-size:14px;"></div>
                </div>
                <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                    <button type="button" class="medium w-100 px-3" style="background:#c0392b;" data-bs-dismiss="modal">Understood</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Time Validation Caution Modal -->
    <div class="modal fade" id="timeValidationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header" style="background:linear-gradient(135deg,#e67e22,#f39c12);color:#fff;">
                    <h5 class="modal-title" style="font-weight:700;">Caution</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="bi bi-exclamation-triangle" style="font-size:2.5rem;color:#e67e22;"></i>
                    <p class="mt-3 mb-0" style="font-size:15px;">
                        End time must be after start time. Please check your schedule entry — a time like 9PM to 10AM is not valid because the end falls before the start on the same day.
                    </p>
                </div>
                <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                    <button type="button" class="medium w-100 px-3" data-bs-dismiss="modal">Understood</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Restricted Action Modal -->
    <div class="modal fade" id="restrictedActionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header" style="background:linear-gradient(135deg,#8e44ad,#bb6bd9);color:#fff;">
                    <h5 class="modal-title" style="font-weight:700;">Action Restricted</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="bi bi-shield-exclamation" style="font-size:2.5rem;color:#8e44ad;"></i>
                    <p class="mt-3 mb-0" style="font-size:15px;">
                        This schedule slot was assigned by<br>
                        <strong id="restricted-creator-name">another Faculty Head</strong>.<br><br>
                        You cannot edit or delete a slot created by a different Faculty Head.
                    </p>
                </div>
                <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                    <button type="button" class="medium w-100 px-3" style="background:#8e44ad;" data-bs-dismiss="modal">Understood</button>
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
                            <i class="bi bi-calendar-week" style="font-size:1.6rem; flex-shrink:0; color:var(--secondary-color-4);"></i>
                            <div class="flex-grow-1">
                                <div class="text-muted">Day</div>
                                <strong id="slot-day"></strong>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3">
                            <i class="bi bi-clock" style="font-size:1.6rem; flex-shrink:0; color:var(--secondary-color-4);"></i>
                            <div class="flex-grow-1">
                                <div class="text-muted">Time</div>
                                <strong id="slot-time"></strong>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3">
                            <i class="bi bi-door-open" style="font-size:1.6rem; flex-shrink:0; color:var(--secondary-color-4);"></i>
                            <div class="flex-grow-1">
                                <div class="text-muted">Room</div>
                                <strong id="slot-room"></strong>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3">
                            <i class="bi bi-book" style="font-size:1.6rem; flex-shrink:0; color:var(--secondary-color-4);"></i>
                            <div class="flex-grow-1">
                                <div class="text-muted">Subject</div>
                                <strong id="slot-subject"></strong>
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
        let deleteScheduleModal = null;
        let confirmSaveModal = null;
        let restrictedModal = null;
        let overlapWarningModal = null;
        let timeValidationModal = null;
        let deleteSlotId = null;
        const subjects = <?php echo json_encode($subjects); ?>;
        const rooms = <?php echo json_encode($rooms); ?>;
        const memberId = <?= (int)$member_id ?>;
        const todayDayName = '<?= $today ?>';

        function cleanupModalBackdrop() {
            document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }

        document.addEventListener('hidden.bs.modal', cleanupModalBackdrop);

        // ── Timetable panel toggle (hover) ──
        (function() {
            const panels = ['panelCoverage', 'panelInfo'];
            const timers = {};
            panels.forEach(id => {
                const btn = document.querySelector(`[data-panel="${id}"]`);
                const panel = document.getElementById(id);
                if (!btn || !panel) return;
                timers[id] = null;
                const open = () => {
                    if (timers[id]) { clearTimeout(timers[id]); timers[id] = null; }
                    panel.classList.add('show');
                    btn.classList.remove('has-update');
                };
                const close = () => {
                    if (timers[id]) clearTimeout(timers[id]);
                    timers[id] = setTimeout(() => panel.classList.remove('show'), 150);
                };
                btn.addEventListener('mouseenter', open);
                btn.addEventListener('focus', open);
                panel.addEventListener('mouseenter', open);
                panel.addEventListener('mouseleave', close);
                btn.addEventListener('mouseleave', close);
            });
        })();

        // ── Overlap warning modal ──
        function showOverlapModal(conflict) {
            if (!overlapWarningModal) {
                overlapWarningModal = new bootstrap.Modal(document.getElementById('overlapWarningModal'));
            }
            document.getElementById('overlap-details').innerHTML =
                '<div class="mb-1"><strong>Day:</strong> ' + conflict.day + '</div>' +
                '<div class="mb-1"><strong>Time:</strong> ' + conflict.start + ' \u2014 ' + conflict.end + '</div>' +
                '<div class="mb-1"><strong>Room:</strong> ' + conflict.room + '</div>' +
                '<div class="mb-1"><strong>Subject:</strong> ' + conflict.subject + '</div>' +
                '<div class="mb-1"><strong>Teacher:</strong> ' + conflict.teacher + '</div>';
            overlapWarningModal.show();
        }

        // ── Subject search filtering ──
        document.addEventListener('input', function(e) {
            if (e.target.id === 'edit-subject-search') {
                const filter = e.target.value.toLowerCase();
                const container = document.getElementById('edit-available-subjects-container');
                const items = container.querySelectorAll('.edit-subject-item');
                let anyVisible = false;
                items.forEach(function(item) {
                    const name = item.dataset.subjectName.toLowerCase();
                    const show = name.includes(filter);
                    item.style.display = show ? '' : 'none';
                    if (show) anyVisible = true;
                });
                let emptyMsg = container.querySelector('.no-match-msg');
                if (!anyVisible) {
                    if (!emptyMsg) {
                        emptyMsg = document.createElement('p');
                        emptyMsg.className = 'text-muted small mb-0 no-match-msg';
                        emptyMsg.textContent = 'No matching subjects.';
                        container.appendChild(emptyMsg);
                    }
                } else if (emptyMsg) {
                    emptyMsg.remove();
                }
            }
        });

        // ── Subject chip click to select ──
        document.addEventListener('click', function(e) {
            const item = e.target.closest('.edit-subject-item');
            if (!item) return;

            const subjectId = item.dataset.subjectId;
            const subjectName = item.dataset.subjectName;

            document.getElementById('edit-subject-id').value = subjectId;
            document.getElementById('edit-subject-name').value = subjectName;
            document.getElementById('edit-selected-subject-name').textContent = subjectName;
            document.getElementById('edit-selected-subject-name').style.fontStyle = 'normal';
            document.getElementById('edit-selected-subject-name').style.color = 'var(--text-color, #212529)';

            // Highlight selected
            document.querySelectorAll('.edit-subject-item').forEach(function(el) {
                el.style.border = '2px solid transparent';
            });
            item.style.border = '2px solid #2a7a3e';

            // Clear search
            document.getElementById('edit-subject-search').value = '';
            // Reset filter
            document.querySelectorAll('.edit-subject-item').forEach(function(el) {
                el.style.display = '';
            });
            const emptyMsg = document.getElementById('edit-available-subjects-container').querySelector('.no-match-msg');
            if (emptyMsg) emptyMsg.remove();
        });

        // ── Modal open functions ──
        function openAddScheduleModal() {
            if (!editScheduleModal) {
                editScheduleModal = new bootstrap.Modal(document.getElementById('editScheduleModal'));
            }
            document.getElementById('editScheduleLabel').innerHTML = '<i class="bi bi-plus-lg me-2"></i>Add Schedule Slot';
            document.getElementById('edit-slot-id').value = '';
            document.getElementById('edit-is-add').value = '1';
            document.getElementById('edit-day').value = todayDayName;
            document.getElementById('edit-start').value = '09:00';
            document.getElementById('edit-end').value = '10:00';
            document.getElementById('edit-room').value = rooms.length > 0 ? rooms[0].id : '';
            resetSubjectSelection();
            editScheduleModal.show();
        }

        function openEditScheduleModal(id, day, start, end, roomId, subjectId, subjectName) {
            if (!editScheduleModal) {
                editScheduleModal = new bootstrap.Modal(document.getElementById('editScheduleModal'));
            }
            document.getElementById('editScheduleLabel').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Schedule Details';
            document.getElementById('edit-slot-id').value = id;
            document.getElementById('edit-is-add').value = '0';
            document.getElementById('edit-day').value = day;
            document.getElementById('edit-start').value = start;
            document.getElementById('edit-end').value = end;
            document.getElementById('edit-room').value = roomId;
            resetSubjectSelection();

            if (subjectId && subjectId > 0) {
                document.getElementById('edit-subject-id').value = subjectId;
                document.getElementById('edit-subject-name').value = subjectName || '';
                document.getElementById('edit-selected-subject-name').textContent = subjectName || '';
                document.getElementById('edit-selected-subject-name').style.fontStyle = 'normal';
                document.getElementById('edit-selected-subject-name').style.color = 'var(--text-color, #212529)';
                document.querySelectorAll('.edit-subject-item').forEach(function(el) {
                    if (parseInt(el.dataset.subjectId) === subjectId) {
                        el.style.border = '2px solid #2a7a3e';
                    }
                });
            }
            editScheduleModal.show();
        }

        function resetSubjectSelection() {
            document.getElementById('edit-subject-id').value = '0';
            document.getElementById('edit-subject-name').value = '';
            document.getElementById('edit-selected-subject-name').textContent = 'None';
            document.getElementById('edit-selected-subject-name').style.fontStyle = 'italic';
            document.getElementById('edit-selected-subject-name').style.color = 'var(--text-muted, #6c757d)';
            document.getElementById('edit-subject-search').value = '';
            document.querySelectorAll('.edit-subject-item').forEach(function(el) {
                el.style.display = '';
                el.style.border = '2px solid transparent';
            });
            const emptyMsg = document.getElementById('edit-available-subjects-container').querySelector('.no-match-msg');
            if (emptyMsg) emptyMsg.remove();
        }

        // ── Confirm-then-save flow ──
        function saveSchedule() {
            const day = document.getElementById('edit-day').value;
            const start = document.getElementById('edit-start').value;
            const end = document.getElementById('edit-end').value;
            const roomId = document.getElementById('edit-room').value;

            if (!day || !start || !end || !roomId) {
                alert('Please fill in all required fields.');
                return;
            }
            if (start >= end) {
                if (!timeValidationModal) {
                    timeValidationModal = new bootstrap.Modal(document.getElementById('timeValidationModal'));
                }
                timeValidationModal.show();
                return;
            }

            // Build preview message
            const subjectName = document.getElementById('edit-subject-name').value || 'None assigned';
            const roomName = document.getElementById('edit-room').selectedOptions[0]?.text || 'Unknown';
            const isAdd = document.getElementById('edit-is-add').value === '1';
            const actionLabel = isAdd ? 'Add' : 'Update';
            document.getElementById('confirm-save-message').innerHTML =
                '<strong>' + actionLabel + ' schedule slot:</strong><br>' +
                day + ' | ' + start + ' – ' + end + '<br>' +
                'Room: ' + roomName + '<br>' +
                'Subject: ' + subjectName;

            if (!confirmSaveModal) {
                confirmSaveModal = new bootstrap.Modal(document.getElementById('confirmSaveScheduleModal'));
            }
            confirmSaveModal.show();
        }

        document.getElementById('confirm-save-btn').addEventListener('click', executeSaveSchedule);

        async function executeSaveSchedule() {
            const isAdd = document.getElementById('edit-is-add').value === '1';
            const slotId = document.getElementById('edit-slot-id').value;
            const day = document.getElementById('edit-day').value;
            const start = document.getElementById('edit-start').value;
            const end = document.getElementById('edit-end').value;
            const roomId = document.getElementById('edit-room').value;
            const subjectId = parseInt(document.getElementById('edit-subject-id').value) || 0;
            const subjectName = document.getElementById('edit-subject-name').value.trim();

            let newSubject = '';
            if (subjectName && subjectId === 0) {
                const found = subjects.find(function(s) {
                    return s.name.toLowerCase() === subjectName.toLowerCase();
                });
                if (found) {
                    // It's actually an existing subject - should not happen with chip UI, but handle gracefully
                } else {
                    newSubject = subjectName;
                }
            }

            const body = new URLSearchParams({
                action: isAdd ? 'add_schedule' : 'update_schedule',
                member_id: memberId,
                slot_id: slotId,
                room_id: roomId,
                day_of_week: day,
                start_time: start,
                end_time: end,
                subject_id: subjectId,
                new_subject: newSubject
            });

            if (confirmSaveModal) confirmSaveModal.hide();
            if (editScheduleModal) editScheduleModal.hide();

            const res = await fetch('../../php/handlers/faculty-head-handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body
            });
            const data = await res.json();

            if (data.success) {
                window.location.reload();
            } else if (data.message === 'not_your_slot') {
                showRestrictedModal('another Faculty Head');
            } else if (data.conflict) {
                showOverlapModal(data.conflict);
            } else {
                alert(data.message || 'Could not save schedule.');
            }
        }

        function confirmDeleteSchedule(slotId) {
            if (!deleteScheduleModal) {
                deleteScheduleModal = new bootstrap.Modal(document.getElementById('deleteScheduleModal'));
            }
            deleteSlotId = slotId;
            deleteScheduleModal.show();
        }

        async function executeDeleteSchedule() {
            if (!deleteSlotId) return;

            const body = new URLSearchParams({
                action: 'delete_schedule',
                slot_id: deleteSlotId
            });

            const res = await fetch('../../php/handlers/faculty-head-handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body
            });
            const data = await res.json();

            if (data.success) {
                window.location.reload();
            } else if (data.message === 'not_your_slot') {
                showRestrictedModal('another Faculty Head');
            } else {
                alert(data.message || 'Could not delete schedule.');
            }
        }

        function showRestrictedModal(creatorName) {
            if (!restrictedModal) {
                restrictedModal = new bootstrap.Modal(document.getElementById('restrictedActionModal'));
            }
            document.getElementById('restricted-creator-name').textContent = creatorName;
            restrictedModal.show();
        }

        function openSlotDetails(day, startTime, endTime, room, subject) {
            if (!viewSlotModal) {
                viewSlotModal = new bootstrap.Modal(document.getElementById('viewSlotModal'));
            }
            document.getElementById('slot-day').textContent = day;
            document.getElementById('slot-time').textContent = startTime + ' \u2014 ' + endTime;
            document.getElementById('slot-room').textContent = room;
            document.getElementById('slot-subject').textContent = subject;
            viewSlotModal.show();
        }
    </script>

</body>

</html>