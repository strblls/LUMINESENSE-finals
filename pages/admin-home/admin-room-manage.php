<?php
$page_title = 'Room Management';
require_once '../../php/includes/admin-head.php';
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
    <link rel="icon" href="../../images/logo.png">
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">
    <link rel="stylesheet" href="../../css/tooltip.css">
    <link rel="stylesheet" href="../../css/admin-common.css">
    <link rel="stylesheet" href="../../css/admin-timetable.css">
    <link rel="stylesheet" href="../../css/faculty-timetable.css">
    <link rel="stylesheet" href="../../css/faculty-head-timetable.css">
    <link rel="stylesheet" href="../../css/admin-room-manage.css">
</head>

<body class="contrast-bg">
    <?php include '../../php/includes/admin-topbar.php'; ?>
    <?php include '../../php/includes/admin-sidebar.php'; ?>

    <div class="parent-container">

        <div class="child-container">

            <!-- ═══ PAGE CONTENT ═══ -->
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
                        <button type="button" class="timetable-btn" data-panel="panelSubjectAreaFilter" title="Filter by Subject Area">
                            <i class="bi bi-funnel"></i>
                            <span class="timetable-btn-title bold">Subject<br>Area</span>
                        </button>
                        <div id="panelSubjectAreaFilter" class="timetable-panel panel-from-right p-3 m-3">
                            <div class="section-container timetable" style="background-color:#f8f9fa;">
                                <ul class="list-unstyled mb-0" id="subjectAreaFilterMenu" style="max-height:300px;overflow-y:auto;">
                                    <li><a class="d-block px-2 py-1 filter-option active" href="#" data-value="">All Subject Areas</a></li>
                                    <?php foreach ($allSaNames as $sa): ?>
                                    <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="<?= htmlspecialchars($sa) ?>"><?= htmlspecialchars($sa) ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <button type="button" class="timetable-btn" data-panel="panelSubjectFilter2" title="Filter by Subject">
                            <i class="bi bi-funnel"></i>
                            <span class="timetable-btn-title bold">Subject</span>
                        </button>
                        <div id="panelSubjectFilter2" class="timetable-panel panel-from-right p-3 m-3">
                            <div class="section-container timetable" style="background-color:#f8f9fa;">
                                <ul class="list-unstyled mb-0" id="subjectFilterMenu2" style="max-height:300px;overflow-y:auto;">
                                    <li><a class="d-block px-2 py-1 filter-option active" href="#" data-value="">All Subjects</a></li>
                                    <?php foreach ($allSubjNames as $subj): ?>
                                    <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="<?= htmlspecialchars($subj) ?>"><?= htmlspecialchars($subj) ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="section-heading">All Rooms</div>

                <div class="main-container" style="padding: 1rem; background-color: var(--secondary-color-2);">
                <div class="rooms-grid" id="roomsGrid">
                    <?php
                    // Build faculty coverage lookup and filter options
                    $facultyCov = []; // faculty_id => [ 'sa' => [id=>name], 'subjects' => [id=>name] ]
                    $allSaNames = [];
                    $allSubjNames = [];
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
                    sort($allSaNames);
                    sort($allSubjNames);
                    foreach ($classrooms as $c):
                        $on         = ($c['light_status'] === 'on');
                        $curSched   = getCurrentSchedule($conn, $c['id']);
                        $isOccupied = !empty($curSched);
                        $fName      = $isOccupied ? $curSched['faculty_name'] : '—';
                        $fid        = $isOccupied ? (int)$curSched['faculty_id'] : 0;
                        $cov        = $isOccupied && isset($facultyCov[$fid]) ? $facultyCov[$fid] : null;
                        $covSaNames = $cov ? implode(',', array_unique(array_values($cov['sa']))) : '';
                        $covSubjNames = $cov ? implode(',', array_unique(array_values($cov['subjects']))) : '';

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
                        <div class="room-card" data-room-id="<?= $c['id'] ?>" data-room="<?= htmlspecialchars(strtolower($c['room_name'])) ?>" data-sa="<?= htmlspecialchars(strtolower($covSaNames)) ?>" data-subjects="<?= htmlspecialchars(strtolower($covSubjNames)) ?>">
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

    <?php include '../../php/includes/profile-offcanvas.php'; ?>

    <!-- ═══ ADD ROOM MODAL ═══ -->
    <div class="modal fade" id="addRoomModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Room</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="../../php/handlers/room-handler.php">
                    <input type="hidden" name="action" value="add_room">
                    <div class="modal-body d-flex flex-column gap-3">
                        <div>
                            <label class="form-label" style="font-size:.85rem;font-weight:600;">Room Name</label>
                            <input type="text" name="room_name" class="form-control" placeholder="e.g. Grade 7 – Acacia" required>
                        </div>
                        <div>
                            <label class="form-label" style="font-size:.85rem;font-weight:600;">Room Size</label>
                            <select name="room_size" class="form-select">
                                <option value="small">Small (7×7 m)</option>
                                <option value="medium" selected>Medium (7×9 m)</option>
                                <option value="large">Large (9×10 m+)</option>
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

    <!-- ═══ EDIT ROOM MODAL ═══ -->
    <div class="modal fade" id="editRoomModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Room</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="../../php/handlers/room-handler.php">
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
                                <option value="small">Small (7×7 m)</option>
                                <option value="medium">Medium (7×9 m)</option>
                                <option value="large">Large (9×10 m+)</option>
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

    <!-- ═══ DELETE ROOM MODAL ═══ -->
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
                <form method="POST" action="../../php/handlers/room-handler.php">
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


    <!-- ═══ ROOM DETAILS MODAL ═══ -->
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
                                    <em class="text-muted">Loading…</em>
                                </div>
                                <div class="collapse mt-2" id="timetableCollapse">
                                    <div id="modalTimetableBody" style="max-height:320px;overflow-y:auto;">
                                        <div class="modal-slot-empty">Loading…</div>
                                    </div>
                                </div>

                                <!-- ── Admin Light Override Panel ── -->
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
                                <div id="modalTodaySched" style="background:#fff;border-radius:8px;padding:12px;font-size:13px;">
                                    <em class="text-muted">Loading…</em>
                                </div>
                            </div>
                            <div style="background:#f8f9fa;border-radius:12px;padding:16px;">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h6 class="bold mb-0">Room Alerts</h6>
                                </div>
                                <div class="activity-list px-1" id="modalAlertsPreview" style="min-height: 40px;">
                                    <em class="text-muted" style="font-size:.82rem;">Loading…</em>
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


    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
    <script src="../../script/tooltip.js"></script>


    <script>
        function openEditModal(id, name, size, desc) {
            document.getElementById('editRoomId').value = id;
            document.getElementById('editRoomName').value = name;
            document.getElementById('editRoomDesc').value = desc;
            const sel = document.getElementById('editRoomSize');
            for (let o of sel.options) o.selected = (o.value === size);
            new bootstrap.Modal(document.getElementById('editRoomModal')).show();
        }

        function openDeleteModal(id, name) {
            document.getElementById('deleteRoomId').value = id;
            document.getElementById('deleteRoomName').textContent = name;
            new bootstrap.Modal(document.getElementById('deleteRoomModal')).show();
        }

        let currentRoomId = null;
        let roomPollInterval = null;

        function openRoomModal(id, name, size, desc) {
            currentRoomId = parseInt(id, 10);
            document.getElementById('roomModalLabel').textContent = name;
            document.getElementById('modalCurrentSched').innerHTML = '<p class="text-muted" style="font-size:.85rem;">Loading…</p>';
            document.getElementById('modalTodaySched').innerHTML = '<div class="modal-slot-empty">Loading…</div>';
            document.getElementById('modalTimetableBody').innerHTML = '<div class="modal-slot-empty">Loading…</div>';
            document.getElementById('modalAlertsPreview').innerHTML = '<div class="modal-slot-empty">Loading…</div>';

            new bootstrap.Modal(document.getElementById('roomModal')).show();

            fetchRoomData();
            clearInterval(roomPollInterval);
            roomPollInterval = setInterval(fetchRoomData, 5000);
        }

        function fetchRoomData() {
            fetch('ajax-room-data.php?room_id=' + currentRoomId)
                .then(r => r.json())
                .then(data => {
                    renderRoomModal(data);
                    updateCardLighting(currentRoomId, data.row1_status, data.row2_status, data.row3_status);
                })
                .catch(err => console.error('Room modal error:', err));
        }

        // Updates the lighting dots on the card without page refresh
        function updateCardLighting(roomId, row1, row2, row3) {
            const card = document.querySelector(`.room-card[data-room-id="${roomId}"]`);
            if (!card) return;
            const lightRow = card.querySelector('.room-info-row .bi-lightbulb-fill')?.closest('.room-info-row');
            if (lightRow) {
                const dots = lightRow.querySelectorAll('.light-dot');
                if (dots.length >= 3) {
                    dots[0].className = 'light-dot ' + (row1 === 'on' ? 'on' : 'off');
                    dots[1].className = 'light-dot ' + (row2 === 'on' ? 'on' : 'off');
                    dots[2].className = 'light-dot ' + (row3 === 'on' ? 'on' : 'off');
                }
            }
        }

        // Poll all rooms' data (lighting + schedule) every 15 seconds
        function pollAllRoomData() {
            fetch('../../api/classrooms.php')
                .then(r => r.json())
                .then(res => {
                    if (!res.success || !res.data) return;
                    res.data.forEach(room => {
                        const card = document.querySelector(`.room-card[data-room-id="${room.id}"]`);
                        if (!card) return;

                        // ── Lighting ──
                        const r1 = room.row1_status === 'on';
                        const r2 = room.row2_status === 'on';
                        const r3 = room.row3_status === 'on';
                        const lightRow = card.querySelector('.room-info-row .bi-lightbulb-fill')?.closest('.room-info-row');
                        if (lightRow) {
                            const dots = lightRow.querySelectorAll('.light-dot');
                            if (dots.length >= 3) {
                                dots[0].className = 'light-dot ' + (r1 ? 'on' : 'off');
                                dots[1].className = 'light-dot ' + (r2 ? 'on' : 'off');
                                dots[2].className = 'light-dot ' + (r3 ? 'on' : 'off');
                            }
                        }

                        // ── Schedule status ──
                        const cur = room.current_schedule;
                        const next = room.next_schedule;
                        const isOccupied = cur && cur.faculty_name;
                        let badgeClass, badgeLabel;
                        if (isOccupied) {
                            badgeClass = 'badge-occupied';  badgeLabel = 'Occupied';
                        } else if (next && next.faculty_name) {
                            badgeClass = 'badge-scheduled'; badgeLabel = 'Scheduled';
                        } else {
                            badgeClass = 'badge-vacant';    badgeLabel = 'Vacant';
                        }
                        const accent = card.querySelector('.room-card-accent');
                        if (accent) {
                            accent.className = 'room-card-accent accent-' + badgeLabel.toLowerCase();
                        }
                        const statusBadge = card.querySelector('.room-status-badge');
                        if (statusBadge) {
                            statusBadge.className = 'room-status-badge ' + badgeClass;
                            statusBadge.textContent = badgeLabel;
                        }

                        // ── Faculty name ──
                        const facultyVal = card.querySelector('.room-info-row .bi-person-fill')?.closest('.room-info-row')?.querySelector('.room-info-val');
                        if (facultyVal) facultyVal.textContent = isOccupied ? cur.faculty_name : '\u2014';

                        // ── Time / Next class ──
                        const timeLabel = card.querySelector('.room-info-row .bi-clock-fill')?.closest('.room-info-row')?.querySelector('.room-info-label');
                        const timeVal = card.querySelector('.room-info-row .bi-clock-fill')?.closest('.room-info-row')?.querySelector('.room-info-val');
                        if (timeLabel && timeVal) {
                            if (isOccupied) {
                                timeLabel.textContent = 'Current Class:';
                                const st = cur.start_time ? new Date('2000-01-01T' + cur.start_time).toLocaleTimeString([], {hour:'numeric',minute:'2-digit'}) : '';
                                const et = cur.end_time ? new Date('2000-01-01T' + cur.end_time).toLocaleTimeString([], {hour:'numeric',minute:'2-digit'}) : '';
                                timeVal.textContent = st && et ? st + ' \u2013 ' + et : '\u2014';
                            } else {
                                timeLabel.textContent = 'Next class:';
                                if (next && next.start_time) {
                                    var t = new Date('2000-01-01T' + next.start_time).toLocaleTimeString([], {hour:'numeric',minute:'2-digit'});
                                    timeVal.textContent = next.next_date ? t + ' (' + next.next_date + ')' : t;
                                } else {
                                    timeVal.textContent = 'None scheduled';
                                }
                            }
                        }
                    });
                })
                .catch(() => {});
        }

        const alertIconMap = (type) => {
            const m = {
                'on': ['bi-lightbulb-fill', '#198754', '#d1e7dd'],
                'off': ['bi-lightbulb', '#842029', '#f8d7da'],
                'light_on': ['bi-lightbulb-fill', '#198754', '#d1e7dd'],
                'light_off': ['bi-lightbulb', '#842029', '#f8d7da'],
                'motion_detect': ['bi-person-bounding-box', '#084298', '#cfe2ff'],
                'gesture': ['bi-hand-index', '#084298', '#cfe2ff'],
                'schedule': ['bi-calendar-check', '#198754', '#d1e7dd'],
                'security_alert': ['bi-exclamation-triangle-fill', '#842029', '#f8d7da'],
                'class_start': ['bi-play-circle-fill', '#198754', '#d1e7dd'],
                'class_end': ['bi-stop-circle', '#664d03', '#fff3cd'],
                'door_open': ['bi-door-open-fill', '#664d03', '#fff3cd'],
                'door_close': ['bi-door-closed-fill', '#5a3a00', '#ffe5b4'],
                'issue_raised': ['bi-exclamation-triangle-fill', '#842029', '#f8d7da'],
                'issue_resolved': ['bi-check-circle-fill', '#198754', '#d1e7dd']
            };
            return m[type] || ['bi-info-circle', '#6c757d', '#f8f9fa'];
        };

        function renderRoomModal(data) {
            const td = data.today_date || '';
            // ── Current Schedule ──
            const schedEl = document.getElementById('modalCurrentSched');
            if (data.current_schedule) {
                const s = data.current_schedule;
                const infoRows = [];
                if (s.subject_name) infoRows.push(`<div class="dept-info-card room-info-row" style="padding:0.25rem 0;margin-bottom:3px;"><p class="d-flex align-items-center gap-2 mb-0"><i class="bi bi-book" style="font-size:.85rem;"></i> <span style="font-weight:600;font-size:.82rem;">Subject:</span> <span style="font-size:.82rem;">${s.subject_name}</span></p></div>`);
                if (s.subject_area_name) infoRows.push(`<div class="dept-info-card room-info-row" style="padding:0.25rem 0;margin-bottom:3px;"><p class="d-flex align-items-center gap-2 mb-0"><i class="bi bi-diagram-3" style="font-size:.85rem;"></i> <span style="font-weight:600;font-size:.82rem;">Subject Area:</span> <span style="font-size:.82rem;">${s.subject_area_name}</span></p></div>`);
                if (s.department_name) infoRows.push(`<div class="dept-info-card room-info-row" style="padding:0.25rem 0;margin-bottom:3px;"><p class="d-flex align-items-center gap-2 mb-0"><i class="bi bi-building" style="font-size:.85rem;"></i> <span style="font-weight:600;font-size:.82rem;">Department:</span> <span style="font-size:.82rem;">${s.department_name}</span></p></div>`);
                schedEl.innerHTML = `
            <div class="d-flex align-items-start gap-3">
                <div class="avatar-icon d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:48px;height:48px;font-size:1rem;">
                    <span class="bold">${s.initials}</span>
                </div>
                <div style="flex:1;min-width:0;">
                    <p class="bold mb-0" style="font-size:.9rem;">${s.faculty_name}</p>
                    <small class="text-muted">Faculty Member</small>
                    <div style="font-size:.9rem;font-weight:600;margin-top:.15rem;">
                        ${s.start_time} – ${s.end_time}
                    </div>
                    ${infoRows.length ? '<div style="margin-top:6px;border-top:1px solid #eee;padding-top:4px;">' + infoRows.join('') + '</div>' : ''}
                </div>
            </div>`;
            } else if (data.next_schedule) {
                const ns = data.next_schedule;
                const dayInfo = ns.day_name ? '<span style="color:#a06800;font-weight:600;">' + ns.day_name + '</span>' : '';
                const infoRows = [];
                if (ns.subject_name) infoRows.push(`<div class="dept-info-card room-info-row" style="padding:0.25rem 0;margin-bottom:3px;"><p class="d-flex align-items-center gap-2 mb-0"><i class="bi bi-book" style="font-size:.85rem;"></i> <span style="font-weight:600;font-size:.82rem;">Subject:</span> <span style="font-size:.82rem;">${ns.subject_name}</span></p></div>`);
                if (ns.subject_area_name) infoRows.push(`<div class="dept-info-card room-info-row" style="padding:0.25rem 0;margin-bottom:3px;"><p class="d-flex align-items-center gap-2 mb-0"><i class="bi bi-diagram-3" style="font-size:.85rem;"></i> <span style="font-weight:600;font-size:.82rem;">Subject Area:</span> <span style="font-size:.82rem;">${ns.subject_area_name}</span></p></div>`);
                if (ns.department_name) infoRows.push(`<div class="dept-info-card room-info-row" style="padding:0.25rem 0;margin-bottom:3px;"><p class="d-flex align-items-center gap-2 mb-0"><i class="bi bi-building" style="font-size:.85rem;"></i> <span style="font-weight:600;font-size:.82rem;">Department:</span> <span style="font-size:.82rem;">${ns.department_name}</span></p></div>`);
                schedEl.innerHTML = `
            <div class="d-flex align-items-start gap-3">
                <div class="avatar-icon d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:48px;height:48px;font-size:1rem;background:#fff5d6;color:#a06800;">
                    <i class="bi bi-calendar-event" style="font-size:1.2rem;"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <span style="display:inline-block;background:#fff5d6;color:#a06800;padding:2px 8px;border-radius:10px;font-weight:700;font-size:10px;margin-bottom:6px;">
                        SCHEDULED
                    </span>
                    <p class="bold mb-0" style="font-size:.9rem;">${ns.faculty_name || '—'}</p>
                    <small class="text-muted">Next class</small>
                    <div style="font-size:.9rem;font-weight:600;margin-top:.2rem;">
                        ${ns.start_time} – ${ns.end_time}
                    </div>
                    ${dayInfo ? '<div style="font-size:.82rem;margin-top:2px;">' + dayInfo + '</div>' : ''}
                    ${infoRows.length ? '<div style="margin-top:4px;border-top:1px solid #eee;padding-top:4px;">' + infoRows.join('') + '</div>' : ''}
                </div>
            </div>`;
            } else if (data.today_schedules && data.today_schedules.length > 0) {
                schedEl.innerHTML = `
            <div style="font-size:.85rem;">
                <span style="background:#d6fbe9;color:#0a7a45;padding:2px 8px;border-radius:10px;font-weight:700;font-size:10px;">
                    VACANT
                </span>
                <p class="text-muted mt-2 mb-0">No more classes scheduled today.</p>
            </div>`;
            } else {
                schedEl.innerHTML = `
            <div>
                <span style="background:#d6fbe9;color:#0a7a45;padding:2px 8px;border-radius:10px;font-weight:700;font-size:10px;">
                    VACANT
                </span>
                <p class="text-muted mt-2 mb-0" style="font-size:.85rem;">No classes scheduled today.</p>
            </div>`;
            }

            // ── Bulb grid — only update if admin hasn't just toggled (avoid fighting the UI) ──
            const rowStatuses = {
                1: data.row1_status === 'on',
                2: data.row2_status === 'on',
                3: data.row3_status === 'on'
            };
            for (let row = 1; row <= 3; row++) {
                rowState[row] = rowStatuses[row];
                rowBulbs[row].forEach(i => setBulb(i, rowStatuses[row]));
                const sw = document.getElementById('row' + row + 'sw');
                if (sw) sw.checked = rowStatuses[row];
            }
            syncAllLightsLabel();

            // ── Today's Timetable — weekly schedule grid (horizontal scroll) ──
            const todayEl = document.getElementById('modalTodaySched');
            const todayName = new Date().toLocaleDateString('en-US', {weekday:'long'});
            const dayOrder = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            const grouped = {};
            dayOrder.forEach(d => grouped[d] = []);
            if (data.all_schedules) {
                data.all_schedules.forEach(s => {
                    if (grouped[s.day_of_week]) grouped[s.day_of_week].push(s);
                });
                Object.values(grouped).forEach(arr => arr.sort((a,b) => a.start_time.localeCompare(b.start_time)));
            }
            todayEl.innerHTML = '<div class="modal-weekly-grid">' +
                dayOrder.map(day => {
                    const isToday = day === todayName;
                    const slots = grouped[day];
                    const slotsHtml = slots && slots.length
                        ? slots.map(s => {
                            const sp = s.start_time.split(' ');
                            const ep = s.end_time.split(' ');
                            return `<div class="modal-weekly-slot">
                                <div class="modal-weekly-slot-time">
                                    <span class="mws-start">${sp[0]}</span>
                                    <span class="mws-sep">TO</span>
                                    <span class="mws-end">${ep[0]}</span>
                                    <span class="mws-ampm">${ep[1]}</span>
                                </div>
                                <div class="modal-weekly-slot-faculty">${s.faculty_name}</div>
                            </div>`;
                        }).join('')
                        : '<p class="no-sched">No classes scheduled.</p>';
                    return `<div class="modal-weekly-card${isToday ? ' today' : ''}">
                        <div class="modal-weekly-label">${day}</div>
                        ${slotsHtml}
                    </div>`;
                }).join('') + '</div>';

            // ── Full timetable — slot-row style ──
            const tBody = document.getElementById('modalTimetableBody');
            if (data.all_schedules && data.all_schedules.length > 0) {
                tBody.innerHTML = data.all_schedules.map(s => {
                    const sp = s.start_time.split(' ');
                    const ep = s.end_time.split(' ');
                    return `<div class="modal-slot-row">
                <div class="modal-slot-time">
                    <span class="modal-slot-start">${sp[0]}</span>
                    <span class="modal-slot-sep">TO</span>
                    <span class="modal-slot-end">${ep[0]}</span>
                    <span class="modal-slot-ampm">${ep[1]}</span>
                </div>
                <div class="modal-slot-content">
                    <div class="modal-slot-faculty">${s.faculty_name}</div>
                    <div class="modal-slot-day">${s.day_of_week}</div>
                </div>
            </div>`;
                }).join('');
            } else {
                tBody.innerHTML = '<div class="modal-slot-empty">No schedules yet.</div>';
            }

            // ── Alerts — timeline style (matches faculty-home.php Recent Activities) ──
            const previewEl = document.getElementById('modalAlertsPreview');
            if (data.alerts && data.alerts.length > 0) {
                const renderAlert = a => {
                    const icon = alertIconMap(a.event_type);
                    const dt = a.event_time ? new Date(a.event_time) : null;
                    const timeStr = dt ? dt.toLocaleTimeString('en-US', {timeZone:'Asia/Manila',hour:'numeric',minute:'2-digit',hour12:true}) + ', ' +
                        dt.toLocaleDateString('en-US', {timeZone:'Asia/Manila',month:'short',day:'numeric'}) : '';
                    const triggered = (a.triggered_by || '').toLowerCase().trim();
                    const label = (a.event_type || '').replace(/_/g, ' ');
                    return `<div class="modal-timeline-item">
                <div class="modal-tl-icon" style="background:${icon[2]};color:${icon[1]};">
                    <i class="bi ${icon[0]}"></i>
                </div>
                <div class="modal-tl-body">
                    <p class="modal-tl-action">${label.charAt(0).toUpperCase() + label.slice(1)}</p>
                    <div class="modal-tl-meta" style="flex-wrap:wrap;row-gap:2px;">
                        <span><i class="bi bi-clock"></i> ${timeStr}</span>
                        ${triggered ? '<span><i class="bi bi-toggle-on"></i> ' + triggered.charAt(0).toUpperCase() + triggered.slice(1) + '</span>' : ''}
                        <span class="modal-tl-badge" style="background:${icon[2]};color:${icon[1]};">${label.replace(/_/g, ' ')}</span>
                    </div>
                </div>
            </div>`;
                };
                previewEl.innerHTML = data.alerts.map(renderAlert).join('');
            } else {
                previewEl.innerHTML = '<div class="modal-slot-empty">No activity recorded for this room.</div>';
            }
        }

        // ── Light controls ──
        let rowState = {
            1: false,
            2: false,
            3: false
        };
        const rowBulbs = {
            1: [0, 1, 2],
            2: [3, 4, 5],
            3: [6, 7, 8]
        };

        function setBulb(index, on) {
            const img = document.getElementById('bulb' + index);
            if (img) img.src = on ? '../../images/bulb-on.png' : '../../images/bulb-off.png';
        }

        function toggleRow(row, on) {
            rowState[row] = on;
            rowBulbs[row].forEach(i => setBulb(i, on));
            syncAllLightsLabel();
            sendLightingUpdate(row);
        }

        function toggleAllLights() {
            const anyOff = Object.values(rowState).some(v => !v);
            const newState = anyOff;
            for (let row = 1; row <= 3; row++) {
                rowState[row] = newState;
                rowBulbs[row].forEach(i => setBulb(i, newState));
                const sw = document.getElementById('row' + row + 'sw');
                if (sw) sw.checked = newState;
            }
            syncAllLightsLabel();
            sendLightingUpdate('all');
        }

        function sendLightingUpdate(changedRow = 'all') {
            const anyOn = Object.values(rowState).some(v => v);
            const rowToSend = changedRow === 'all' ? 'all' : String(changedRow);
            const stateToSend = changedRow === 'all' ? (anyOn ? 'on' : 'off') : (rowState[changedRow] ? 'on' : 'off');

            const form = new FormData();
            form.append('classroom_id', currentRoomId);
            form.append('row', rowToSend);
            form.append('state', stateToSend);
            form.append('triggered_by', 'admin_override');
            form.append('new_global_light_status', anyOn ? 'on' : 'off');

            fetch('../../api/lights.php', {
                    method: 'POST',
                    body: form
                })
                .then(r => r.json())
                .then(d => {
                    if (d.success) updateCardLighting(currentRoomId, anyOn);
                })
                .catch(err => console.error('Lighting error:', err));
        }

        function syncAllLightsLabel() {
            const anyOn = Object.values(rowState).some(v => v);
            const label = document.getElementById('allLightsLabel');
            const btn = document.getElementById('allLightsBtn');
            if (label) label.textContent = anyOn ? 'ON' : 'OFF';
            if (btn) btn.className = 'override-master-btn ' + (anyOn ? 'on' : 'off');

            // Sync per-row status labels
            for (let row = 1; row <= 3; row++) {
                const statusEl = document.getElementById('row' + row + 'status');
                if (statusEl) {
                    statusEl.textContent = rowState[row] ? 'ON' : 'OFF';
                    statusEl.className = 'override-row-status' + (rowState[row] ? ' is-on' : '');
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Stop polling when modal closes
            document.getElementById('roomModal').addEventListener('hidden.bs.modal', function() {
                clearInterval(roomPollInterval);
                roomPollInterval = null;
            });

            // Poll all rooms' data (lighting + schedule) every 15 seconds
            pollAllRoomData();
            setInterval(pollAllRoomData, 15000);

            // ── Combined filter logic ──
            function applyFilters() {
                var saVal = (document.querySelector('#subjectAreaFilterMenu .filter-option.active') || {}).dataset?.value || '';
                var subjVal = (document.querySelector('#subjectFilterMenu2 .filter-option.active') || {}).dataset?.value || '';
                var searchVal = (document.getElementById('roomSearch') || {}).value || '';
                searchVal = searchVal.toLowerCase();
                document.querySelectorAll('.room-card').forEach(function(card) {
                    var show = true;
                    if (saVal) {
                        show = show && (card.dataset.sa || '').toLowerCase().includes(saVal.toLowerCase());
                    }
                    if (subjVal) {
                        show = show && (card.dataset.subjects || '').toLowerCase().includes(subjVal.toLowerCase());
                    }
                    if (searchVal) {
                        var roomMatch = (card.dataset.room || '').includes(searchVal);
                        var facultyEl = card.querySelector('.dept-info-card .room-info-val');
                        var facultyMatch = facultyEl ? facultyEl.textContent.toLowerCase().includes(searchVal) : false;
                        show = show && (roomMatch || facultyMatch);
                    }
                    card.style.display = show ? '' : 'none';
                });
            }

            document.querySelectorAll('#subjectAreaFilterMenu .filter-option, #subjectFilterMenu2 .filter-option').forEach(function(opt) {
                opt.addEventListener('click', function(e) {
                    e.preventDefault();
                    var parent = this.closest('ul');
                    parent.querySelectorAll('.filter-option').forEach(function(o) { o.classList.remove('active'); });
                    this.classList.add('active');
                    applyFilters();
                });
            });

            var roomSearchEl = document.getElementById('roomSearch');
            if (roomSearchEl) {
                roomSearchEl.addEventListener('input', applyFilters);
            }

            // ── Timetable-panel toggle (hover/focus open, mouseleave close with delay) ──
            (function() {
                var panels = ['panelGuideInfo', 'panelSubjectAreaFilter', 'panelSubjectFilter2'];
                var timers = {};
                panels.forEach(function(id) {
                    var btn = document.querySelector('[data-panel="' + id + '"]');
                    var panel = document.getElementById(id);
                    if (!btn || !panel) return;
                    timers[id] = null;
                    function open() {
                        if (timers[id]) { clearTimeout(timers[id]); timers[id] = null; }
                        panel.classList.add('show');
                    }
                    function close() {
                        if (timers[id]) clearTimeout(timers[id]);
                        timers[id] = setTimeout(function() { panel.classList.remove('show'); }, 150);
                    }
                    btn.addEventListener('mouseenter', open);
                    btn.addEventListener('focus', open);
                    panel.addEventListener('mouseenter', open);
                    panel.addEventListener('mouseleave', close);
                    btn.addEventListener('mouseleave', close);
                });
            })();
        });

        // ── Scroll-to-hide topbar & sidebar ──
        window.addEventListener('scroll', function() {
            var scrollThreshold = 100;
            var nearBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - scrollThreshold;
            document.querySelectorAll('.topbar-greeting, .topbar-user-info').forEach(function(el) {
                el.classList.toggle('hidden', nearBottom);
            });

        });
    </script>

    <style>
        .topbar-greeting,
        .topbar-user-info {
            transition: opacity 0.3s ease;
        }
        .topbar-greeting.hidden,
        .topbar-user-info.hidden {
            opacity: 0;
            pointer-events: none;
        }
    </style>
    <script src="../../script/faculty-tutorial.js"></script>
</body>

</html>