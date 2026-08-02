<?php
$page_title = 'Room Management';
require_once __DIR__ . "/../../src/Includes/admin-head.php";
date_default_timezone_set('Asia/Manila');

function getRoomSchedules($conn, $room_id)
{
    $day  = date('l');
    $time = date('H:i:s');
    $stmt = $conn->prepare("
        SELECT s.start_time, s.end_time,
               CONCAT(f.first_name,' ',f.last_name) AS faculty_name
        FROM schedules s
        JOIN faculty f ON f.id = s.faculty_id
        WHERE s.classroom_id = ? 
          AND s.day_of_week = ?
          AND s.end_time >= ?
        ORDER BY s.start_time
    ");
    $stmt->bind_param('iss', $room_id, $day, $time);
    @$stmt->execute();
    $result = @$stmt->get_result();
    $rows = [];
    if ($result) while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

// function getCurrentSchedule($conn, $room_id) {
//     $day  = date('l');
//     $time = date('H:i:s');
//     $stmt = $conn->prepare("
//         SELECT s.start_time, s.end_time,
//                CONCAT(f.first_name,' ',f.last_name) AS faculty_name
//         FROM schedules s
//         JOIN faculty f ON f.id = s.created_by
//         WHERE s.classroom_id = ?
//           AND s.day_of_week  = ?
//           AND s.start_time  <= ?
//           AND s.end_time    >= ?
//         LIMIT 1
//     ");
//     $stmt->bind_param('isss', $room_id, $day, $time, $time);
//     $stmt->execute();
//     $row = $stmt->get_result()->fetch_assoc();
//     $stmt->close();
//     return $row;
// }

function getCurrentSchedule($conn, $room_id)
{
    $day  = date('l');
    $time = date('H:i:s');
    $stmt = $conn->prepare("
        SELECT s.start_time, s.end_time,
               s.faculty_id,
               CONCAT(f.first_name,' ',f.last_name) AS faculty_name,
               f.first_name, f.last_name
        FROM schedules s
        JOIN faculty f ON f.id = s.faculty_id
        WHERE s.classroom_id = ?
          AND s.day_of_week  = ?
          AND s.start_time  <= ?
          AND s.end_time    >= ?
        LIMIT 1
    ");
    $stmt->bind_param('isss', $room_id, $day, $time, $time);
    @$stmt->execute();
    $result = @$stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row;
}

$classrooms = [];
$r = $conn->query("
    SELECT id, room_name, room_size, description,
           light_status, row1_status, row2_status, row3_status
    FROM classrooms
    ORDER BY room_name
");
while ($row = $r->fetch_assoc()) $classrooms[] = $row;

// - Build faculty coverage lookups (ALL faculty) -
$facultyCov   = []; // faculty_id => [ 'sa' => [...], 'subjects' => [...] ]
$facultyDepts = []; // faculty_id => [ dept_name, ... ]
$allSaNames   = [];
$allSubjNames = [];
$allDeptNames = [];

$covSt = @$conn->prepare("
    SELECT jfsa.faculty_id, sa.id AS sa_id, sa.name AS sa_name,
           jfs.subject_id, sub.name AS subj_name
    FROM junction_faculty_subjectarea jfsa
    JOIN subject_area sa ON sa.id = jfsa.subject_area_id
    LEFT JOIN junction_faculty_subject jfs ON jfs.faculty_id = jfsa.faculty_id
    LEFT JOIN subjects sub ON sub.id = jfs.subject_id
    ORDER BY sa.name, sub.name
");
if ($covSt) {
    @$covSt->execute();
    $covRes = @$covSt->get_result();
    if ($covRes) {
        while ($row = $covRes->fetch_assoc()) {
            $fid = (int)$row['faculty_id'];
            if (!isset($facultyCov[$fid])) {
                $facultyCov[$fid] = ['sa' => [], 'subjects' => []];
            }
            $facultyCov[$fid]['sa'][(int)$row['sa_id']] = $row['sa_name'];
            $allSaNames[$row['sa_name']] = $row['sa_name'];
            if (!empty($row['subject_id'])) {
                $facultyCov[$fid]['subjects'][(int)$row['subject_id']] = $row['subj_name'];
                $allSubjNames[$row['subj_name']] = $row['subj_name'];
            }
        }
    }
    $covSt->close();
}

$deptSt = @$conn->prepare("
    SELECT jfd.faculty_id, d.id AS dept_id, d.name AS dept_name
    FROM junction_faculty_department jfd
    JOIN departments d ON d.id = jfd.department_id
    WHERE d.status = 'active'
    ORDER BY d.name
");
if ($deptSt) {
    @$deptSt->execute();
    $deptRes = @$deptSt->get_result();
    if ($deptRes) {
        while ($row = $deptRes->fetch_assoc()) {
            $fid = (int)$row['faculty_id'];
            if (!isset($facultyDepts[$fid])) $facultyDepts[$fid] = [];
            $facultyDepts[$fid][(int)$row['dept_id']] = $row['dept_name'];
            $allDeptNames[$row['dept_name']] = $row['dept_name'];
        }
    }
    $deptSt->close();
}
sort($allSaNames);
sort($allSubjNames);
sort($allDeptNames);

// - Build active-only filter lists by checking each room's current schedule -
$activeSaNames     = [];
$activeSubjNames   = [];
$activeDeptNames   = [];
foreach ($classrooms as $c) {
    $sched = getCurrentSchedule($conn, $c['id']);
    if (!empty($sched)) {
        $fid = (int)$sched['faculty_id'];
        if (isset($facultyCov[$fid])) {
            foreach ($facultyCov[$fid]['sa'] as $n) $activeSaNames[$n] = $n;
            foreach ($facultyCov[$fid]['subjects'] as $n) $activeSubjNames[$n] = $n;
        }
        if (isset($facultyDepts[$fid])) {
            foreach ($facultyDepts[$fid] as $n) $activeDeptNames[$n] = $n;
        }
    }
}
ksort($activeSaNames);
ksort($activeSubjNames);
ksort($activeDeptNames);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LumineSense - Room Management</title>

    <!--External links-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <!--Relative links -->
    <link rel="icon" type="image/png" sizes="32x32" href="../../images/icon.png">
    <link rel="shortcut icon" type="image/png" href="../../images/icon.png">
    <link rel="stylesheet" href="../../css/base/global.css">
    <link rel="stylesheet" href="../../css/base/containers.css">
    <link rel="stylesheet" href="../../css/base/modals.css">
    <link rel="stylesheet" href="../../css/base/tooltip.css">
    <link rel="stylesheet" href="../../css/admin/common.css">
    <link rel="stylesheet" href="../../css/admin/timetable.css">
    <link rel="stylesheet" href="../../css/faculty/timetable.css">
    <link rel="stylesheet" href="../../css/faculty/head-timetable.css">
    <link rel="stylesheet" href="../../css/admin/room-manage.css">
</head>

<body class="contrast-bg">
    <?php include __DIR__ . "/../../src/Includes/admin-topbar.php"; ?>
    <?php include __DIR__ . "/../../src/Includes/admin-sidebar.php"; ?>

    <div class="parent-container">

        <div class="child-container">

            <!-- â•â•â• PAGE CONTENT â•â•â• -->
            <div class="page-content">
                <div class="main-container faculty-timetable-heading d-flex align-items-center w-auto" style="background-color: var(--secondary-color-2);">
                    <div class="d-flex align-items-center flex-grow-1" style="position:relative;">
                        <button type="button" class="timetable-btn ms-2" data-panel="panelGuideInfo" title="Guide">
                            <i class="bi bi-info-lg"></i>
                            <span class="timetable-btn-title bold">Guide</span>
                        </button>
                        <div id="panelGuideInfo" class="timetable-panel p-3 m-3">
                            <div class="section-container timetable" style="background-color:#f8f9fa;width:320px;">
                                <h6 class="bold mb-2"><i class="bi bi-info-circle me-1"></i>Room Management Guide</h6>
                                <ol class="ps-3 mb-0" style="font-size:13px;line-height:1.7;">
                                    <li>Use the search bar to find rooms by name or current faculty member.</li>
                                    <li>Click the icons on each room card to edit, delete, or open the room's light view.</li>
                                    <li>Click <strong>Inspect</strong> to view detailed room information, timetable, and alerts.</li>
                                    <li>Use the <strong>Admin Override</strong> panel inside a room to manually control lighting per row or all rows at once.</li>
                                    <li>All override actions are logged for audit purposes.</li>
                                </ol>
                            </div>
                        </div>
                        <input type="text" id="roomSearch" class="form-control" placeholder="Search room name or faculty..." style="max-width:500px;margin-left:16px;">
                    </div>
                    <div class="d-flex align-items-center pe-2" style="position:relative;">
                        <button type="button" class="timetable-btn" data-panel="panelStatusFilter" title="Filter by Status">
                            <i class="bi bi-funnel"></i>
                            <span class="timetable-btn-title bold">Status</span>
                        </button>
                        <div id="panelStatusFilter" class="timetable-panel panel-from-right p-3 m-3">
                            <div class="section-container timetable" style="background-color:#f8f9fa;">
                                <ul class="list-unstyled mb-0" id="statusFilterMenu" style="max-height:300px;overflow-y:auto;">
                                    <li><a class="d-block px-2 py-1 filter-option active" href="#" data-value="">All Statuses</a></li>
                                    <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="occupied">Occupied</a></li>
                                    <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="scheduled">Scheduled</a></li>
                                    <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="vacant">Vacant</a></li>
                                </ul>
                            </div>
                        </div>
                        <button type="button" class="timetable-btn" data-panel="panelScheduleFilter" title="Filter by Schedule Info">
                            <i class="bi bi-funnel"></i>
                            <span class="timetable-btn-title bold">Schedule<br>Info</span>
                        </button>
                        <div id="panelScheduleFilter" class="timetable-panel panel-from-right p-3 m-3">
                            <div class="section-container timetable" style="background-color:#f8f9fa;min-width:200px;">
                                <?php if (!empty($activeDeptNames) || !empty($activeSaNames) || !empty($activeSubjNames)): ?>
                                <div class="mb-2">
                                    <div class="small fw-bold text-muted mb-1 px-2">Department</div>
                                    <ul class="list-unstyled mb-0" id="departmentFilterMenu" style="max-height:130px;overflow-y:auto;">
                                        <li><a class="d-block px-2 py-1 filter-option active" href="#" data-value="">All Departments</a></li>
                                        <?php foreach ($activeDeptNames as $dept): ?>
                                        <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <hr class="my-1">
                                <div class="mb-2">
                                    <div class="small fw-bold text-muted mb-1 px-2">Subject Area</div>
                                    <ul class="list-unstyled mb-0" id="subjectAreaFilterMenu" style="max-height:130px;overflow-y:auto;">
                                        <li><a class="d-block px-2 py-1 filter-option active" href="#" data-value="">All Subject Areas</a></li>
                                        <?php foreach ($activeSaNames as $sa): ?>
                                        <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="<?= htmlspecialchars($sa) ?>"><?= htmlspecialchars($sa) ?></a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <hr class="my-1">
                                <div>
                                    <div class="small fw-bold text-muted mb-1 px-2">Subject</div>
                                    <ul class="list-unstyled mb-0" id="subjectFilterMenu2" style="max-height:130px;overflow-y:auto;">
                                        <li><a class="d-block px-2 py-1 filter-option active" href="#" data-value="">All Subjects</a></li>
                                        <?php foreach ($activeSubjNames as $subj): ?>
                                        <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="<?= htmlspecialchars($subj) ?>"><?= htmlspecialchars($subj) ?></a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <?php else: ?>
                                <p class="text-muted text-center small my-3">No current schedules.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="section-heading">All Rooms</div>

                <div class="main-container" style="padding: 1rem; background-color: var(--secondary-color-2);">
                <div class="rooms-grid" id="roomsGrid">
                    <?php foreach ($classrooms as $c):
                        $on         = ($c['light_status'] === 'on');
                        $curSched   = getCurrentSchedule($conn, $c['id']);
                        $isOccupied = !empty($curSched);
                        $fName      = $isOccupied ? $curSched['faculty_name'] : '-';
                        $fid        = $isOccupied ? (int)$curSched['faculty_id'] : 0;
                        $cov        = $isOccupied && isset($facultyCov[$fid]) ? $facultyCov[$fid] : null;
                        $covSaNames = $cov ? implode(',', array_unique(array_values($cov['sa']))) : '';
                        $covSubjNames = $cov ? implode(',', array_unique(array_values($cov['subjects']))) : '';
                        $covDeptNames = $isOccupied && isset($facultyDepts[$fid]) ? implode(',', array_unique(array_values($facultyDepts[$fid]))) : '';

                        if ($isOccupied) {
                            $accentClass = 'accent-occupied';
                            $badgeClass  = 'badge-occupied';
                            $badgeLabel  = 'Occupied';
                        } elseif (!empty(getRoomSchedules($conn, $c['id']))) {
                            $accentClass = 'accent-scheduled';
                            $badgeClass  = 'badge-scheduled';
                            $badgeLabel  = 'Scheduled';
                        } else {
                            $accentClass = 'accent-vacant';
                            $badgeClass  = 'badge-vacant';
                            $badgeLabel  = 'Vacant';
                        }

                        $nextSched = null;
                        if (!$isOccupied) {
                            $day  = date('l');
                            $time = date('H:i:s');
                            $st = $conn->prepare("
                                SELECT start_time FROM schedules 
                                WHERE classroom_id = ? 
                                AND day_of_week = ? 
                                AND start_time > ?
                                ORDER BY start_time 
                                LIMIT 1
                            ");
                            $st->bind_param('iss', $c['id'], $day, $time);
                            @$st->execute();
                            $next = ($res = @$st->get_result()) ? $res->fetch_assoc() : null;
                            $st->close();
                            if ($next) {
                                $nextSched = date('g:i A', strtotime($next['start_time']));
                            } else {
                                $dayOrder = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                                $currentDayIndex = array_search($day, $dayOrder);
                                for ($i = 1; $i <= 7; $i++) {
                                    $checkDay = $dayOrder[($currentDayIndex + $i) % 7];
                                    $st = $conn->prepare("
                                        SELECT start_time FROM schedules 
                                        WHERE classroom_id = ? 
                                        AND day_of_week = ?
                                        ORDER BY start_time 
                                        LIMIT 1
                                    ");
                                    $st->bind_param('is', $c['id'], $checkDay);
                                    @$st->execute();
                                    $next = ($res = @$st->get_result()) ? $res->fetch_assoc() : null;
                                    $st->close();
                                    if ($next) {
                                        $nextDate = date('F j', strtotime("next $checkDay"));
                        $nextSched = date('g:i A', strtotime($next['start_time'])) . ' (' . $checkDay . ', ' . $nextDate . ')';
                                        break;
                                    }
                                }
                            }
                        }
                    ?>
                        <div class="room-card" data-room-id="<?= $c['id'] ?>" data-room="<?= htmlspecialchars(strtolower($c['room_name'])) ?>" data-sa="<?= htmlspecialchars(strtolower($covSaNames)) ?>" data-subjects="<?= htmlspecialchars(strtolower($covSubjNames)) ?>" data-departments="<?= htmlspecialchars(strtolower($covDeptNames)) ?>" data-status="<?= strtolower($badgeLabel) ?>">
                            <div class="room-card-accent <?= $accentClass ?>"></div>
                            <div class="room-card-body">
                                <div class="room-card-header">
                                    <div>
                                        <h2 class="room-card-name"><?= htmlspecialchars($c['room_name']) ?></h2>
                                        <div class="room-card-section">
                                            <?= ucfirst($c['room_size']) ?> room
                                            <?php if (!empty($c['description'])): ?>
                                                &middot; <?= htmlspecialchars($c['description']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="room-status-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                                </div>
                                <hr class="room-card-divider">
                                <div class="dept-info-card room-info-row" style="padding: 0.5rem;">
                                    <p class="d-flex align-items-center gap-2"><i class="bi bi-person-fill"></i> <span class="room-info-label">Current Faculty:</span> <span class="room-info-val"><?= htmlspecialchars($fName) ?></span></p>
                                </div>
                                <div class="dept-info-card room-info-row" style="padding: 0.5rem;">
                                    <p class="d-flex align-items-center gap-2"><i class="bi bi-clock-fill"></i> <span class="room-info-label"><?= $isOccupied ? 'Current Class:' : 'Next class:' ?></span> <span class="room-info-val"><?php if ($isOccupied): ?><?= date('g:i A', strtotime($curSched['start_time'])) ?> &ndash; <?= date('g:i A', strtotime($curSched['end_time'])) ?><?php else: ?><?= $nextSched ?? 'None scheduled' ?><?php endif; ?></span></p>
                                </div>
                                <div class="dept-info-card room-info-row" style="padding: 0.5rem;">
                                    <p class="d-flex align-items-center gap-2"><i class="bi bi-lightbulb-fill"></i> <span class="room-info-label">Lighting:</span> <span class="room-info-val" style="display:inline-flex;gap:6px;align-items:center;"><span><span class="light-dot <?= $c['row1_status'] === 'on' ? 'on' : 'off' ?>" style="width:7px;height:7px;"></span> R1</span><span><span class="light-dot <?= $c['row2_status'] === 'on' ? 'on' : 'off' ?>" style="width:7px;height:7px;"></span> R2</span><span><span class="light-dot <?= $c['row3_status'] === 'on' ? 'on' : 'off' ?>" style="width:7px;height:7px;"></span> R3</span></span></p>
                                </div>
                            </div>

                            <div class="room-card-actions">
                                <div class="d-flex align-items-center room-icons gap-1">
                                    <button class="btn-icon btn-icon-edit"
                                        title="Edit"
                                        onclick="openEditModal(<?= $c['id'] ?>, '<?= addslashes($c['room_name']) ?>', '<?= $c['room_size'] ?>', '<?= addslashes($c['description']) ?>')"
                                        data-bs-toggle="tooltip" 
                                        data-bs-placement="auto">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn-icon btn-icon-del"
                                        title="Delete"
                                        onclick="openDeleteModal(<?= $c['id'] ?>, '<?= addslashes($c['room_name']) ?>')"
                                        data-bs-toggle="tooltip" 
                                        data-bs-placement="auto">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <!-- PURPOSEFULLY HIDDEN: Open Dummy Room button for testing 
                                    <button class="btn-icon btn-icon-view"
                                        title="Open Dummy Room"
                                        onclick="window.open('room-light-view.php?room_id=<?= $c['id'] ?>','lightView_<?= $c['id'] ?>_'+Date.now(),'width=500,height=600,scrollbars=0')"
                                        data-bs-toggle="tooltip" 
                                        data-bs-placement="auto">
                                        <i class="bi bi-box-arrow-up-right"></i>
                                    </button> -->
                                </div>
                                <button class="light"
                                    onclick="openRoomModal(<?= $c['id'] ?>, '<?= addslashes($c['room_name']) ?>', '<?= $c['room_size'] ?>', '<?= addslashes($c['description']) ?>')">
                                    Inspect
                                </button>
                                <!-- <button class="light"
                                    onclick="dissolve('admin-timetable-manage.php?room=<?= urlencode($c['room_name']) ?>')">
                                    Timetable
                                </button> -->
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Add Room card -->
                    <div class="room-card" style="border:2px dashed #bbb;background:transparent;box-shadow:none;cursor:pointer;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;color:#aaa;min-height:200px;"
                        onclick="new bootstrap.Modal(document.getElementById('addRoomModal')).show()">
                        <i class="bi bi-plus-circle" style="font-size:2rem;"></i>
                        <span style="font-size:1rem;font-weight:600;">Add Room</span>
                    </div>

                </div><!-- /rooms-grid -->
            </div><!-- /main-container -->
            </div><!-- /page-content -->
            <?php $conn->close(); ?>

        </div><!-- /child-container -->
    </div><!-- /parent-container -->

    <?php include __DIR__ . "/../../src/Includes/profile-offcanvas.php"; ?>

    <!-- â•â•â• ADD ROOM MODAL â•â•â• -->
    <div class="modal fade" id="addRoomModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Room</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="../../handlers/room-handler.php">
                    <input type="hidden" name="action" value="add_room">
                    <div class="modal-body d-flex flex-column gap-3">
                        <div>
                            <label class="form-label" style="font-size:.85rem;font-weight:600;">Room Name</label>
                            <input type="text" name="room_name" class="form-control" placeholder="e.g. Grade 7 - Acacia" required>
                        </div>
                        <div>
                            <label class="form-label" style="font-size:.85rem;font-weight:600;">Room Size</label>
                            <select name="room_size" class="form-select">
                                <option value="small">Small (7Ã—7 m)</option>
                                <option value="medium" selected>Medium (7Ã—9 m)</option>
                                <option value="large">Large (9Ã—10 m+)</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" style="font-size:.85rem;font-weight:600;">Description <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" name="description" class="form-control" placeholder="e.g. Near library, 2nd floor">
                        </div>
                    </div>
                    <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                        <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="medium">Add Room</button>
                    </div>
            </div>
            </form>
        </div>
    </div>
    </div>

    <!-- â•â•â• EDIT ROOM MODAL â•â•â• -->
    <div class="modal fade" id="editRoomModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Room</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="../../handlers/room-handler.php">
                    <input type="hidden" name="action" value="edit_room">
                    <input type="hidden" name="room_id" id="editRoomId">
                    <div class="modal-body d-flex flex-column gap-3">
                        <div>
                            <label class="form-label" style="font-size:.85rem;font-weight:600;">Room Name</label>
                            <input type="text" name="room_name" id="editRoomName" class="form-control" required>
                        </div>
                        <div>
                            <label class="form-label" style="font-size:.85rem;font-weight:600;">Room Size</label>
                            <select name="room_size" id="editRoomSize" class="form-select">
                                <option value="small">Small (7Ã—7 m)</option>
                                <option value="medium">Medium (7Ã—9 m)</option>
                                <option value="large">Large (9Ã—10 m+)</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" style="font-size:.85rem;font-weight:600;">Description</label>
                            <input type="text" name="description" id="editRoomDesc" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                        <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="medium">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- â•â•â• DELETE ROOM MODAL â•â•â• -->
    <div class="modal fade" id="deleteRoomModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header modal-header-warning">
                    <h5 class="modal-title">Delete Room</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="bi bi-trash" style="font-size:2.5rem;color:#c0004e;"></i>
                    <p class="mt-3 mb-0" style="font-size:15px;">
                        Are you sure you want to delete <strong id="deleteRoomName"></strong>?
                        This will also remove all schedules and logs for this room.
                    </p>
                </div>
                <form method="POST" action="../../handlers/room-handler.php">
                    <input type="hidden" name="action" value="delete_room">
                    <input type="hidden" name="room_id" id="deleteRoomId">
                    <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                        <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="medium" style="background:#c0392b;">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- â•â•â• ROOM DETAILS MODAL â•â•â• -->
    <div class="room-details-modal modal fade" id="roomModal" tabindex="-1" aria-labelledby="roomModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="roomModalLabel">Room Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-row gap-3 align-items-start flex-wrap">

                        <!-- Left: Schedule + lighting -->
                        <div class="d-flex flex-column gap-3" style="flex:0 0 340px; min-width:280px; max-width:380px;">
                            <div style="background:var(--accent-yellow);border-radius:12px;padding:20px;border:1px solid #eee;">
                                <h6 class="bold mb-3">Current Schedule</h6>
                                <div id="modalCurrentSched" style="background:#fff;border-radius:8px;padding:12px;font-size:13px; min-height:60px;">
                                    <em class="text-muted">Loadingâ€¦</em>
                                </div>
                                <div class="collapse mt-2" id="timetableCollapse">
                                    <div id="modalTimetableBody" style="max-height:320px;overflow-y:auto;">
                                        <div class="modal-slot-empty">Loadingâ€¦</div>
                                    </div>
                                </div>

                                <!-- - Admin Light Override Panel - -->
                                <div class="admin-override-panel mt-3">
                                    <div class="override-panel-header">
                                        <i class="bi bi-shield-lock-fill"></i>
                                        <span>Admin Override</span>
                                        <span class="override-live-badge" id="overrideLiveBadge">LIVE</span>
                                    </div>

                                    <!-- Master toggle -->
                                    <div class="override-master-row">
                                        <div class="override-master-left">
                                            <div class="bulb-preview-grid">
                                                <?php for ($i = 0; $i < 9; $i++): ?>
                                                    <img src="../../images/bulb-off.png" id="bulb<?= $i ?>"
                                                        class="bulb-img">
                                                <?php endfor; ?>
                                            </div>
                                        </div>
                                        <div class="override-master-right">
                                            <button class="override-master-btn off" id="allLightsBtn" onclick="toggleAllLights()">
                                                <i class="bi bi-power"></i>
                                                <span id="allLightsLabel">OFF</span>
                                            </button>
                                            <div class="override-hint">All rows</div>
                                        </div>
                                    </div>

                                    <!-- Per-row toggles -->
                                    <div class="override-rows">
                                        <?php foreach ([1, 2, 3] as $row): ?>
                                            <div class="override-row-item">
                                                <span class="override-row-label">Row <?= $row ?></span>
                                                <div class="override-row-toggle">
                                                    <input class="override-switch" type="checkbox" role="switch"
                                                        id="row<?= $row ?>sw"
                                                        onchange="toggleRow(<?= $row ?>, this.checked)">
                                                    <label class="override-switch-label" for="row<?= $row ?>sw"></label>
                                                </div>
                                                <span class="override-row-status" id="row<?= $row ?>status">OFF</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="override-footer-note">
                                        <i class="bi bi-info-circle"></i>
                                        Changes apply immediately and are logged.
                                    </div>
                                </div>

                            </div>
                        </div>

                        <!-- Right: Timetable + Alerts -->
                        <div class="d-flex flex-column gap-3" style="flex:1;min-width:220px;">
                            <div style="background:#f8f9fa;border-radius:12px;padding:16px;">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h6 class="bold mb-0">Timetable</h6>
                                </div>
                                <div id="modalTodaySched">
                                    <em class="text-muted">Loadingâ€¦</em>
                                </div>
                            </div>
                            <div style="background:#f8f9fa;border-radius:12px;padding:16px;">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h6 class="bold mb-0">Room Alerts</h6>
                                </div>
                                <div class="activity-list px-1" id="modalAlertsPreview" style="min-height: 40px;">
                                    <em class="text-muted" style="font-size:.82rem;">Loadingâ€¦</em>
                                </div>
                                <div class="collapse mt-2" id="alertsCollapse">
                                    <div id="modalAlertsFull" style="max-height:200px;overflow-y:auto;" class="activity-list px-1"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <script src="../../js/lib/animations.js"></script>
    <script src="../../js/lib/toggles.js"></script>
    <script src="../../js/lib/tooltip.js"></script>


    <script src="../../js/admin/admin-room-manage.js"></script>

    <link rel="stylesheet" href="../../css/pages/admin-room-manage.css">
    <script src="../../js/faculty/faculty-tutorial.js"></script>
</body>

</html>