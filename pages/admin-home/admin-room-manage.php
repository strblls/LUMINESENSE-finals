<?php
$page_title = 'Room Management';
require_once '../../php/includes/admin-head.php';
date_default_timezone_set('Asia/Manila');

function getRoomSchedules($conn, $room_id)
{
    $day  = date('l');
    $time = $conn->query("SELECT TIME(NOW()) as t")->fetch_assoc()['t'];
    $stmt = $conn->prepare("
        SELECT s.start_time, s.end_time,
               CONCAT(f.first_name,' ',f.last_name) AS faculty_name
        FROM schedules s
        JOIN faculty f ON f.id = s.created_by
        WHERE s.classroom_id = ? 
          AND s.day_of_week = ?
          AND s.end_time >= ?
        ORDER BY s.start_time
    ");
    $stmt->bind_param('iss', $room_id, $day, $time);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
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
    $time = $conn->query("SELECT TIME(NOW()) as t")->fetch_assoc()['t'];
    $stmt = $conn->prepare("
        SELECT s.start_time, s.end_time,
               CONCAT(f.first_name,' ',f.last_name) AS faculty_name,
               f.first_name, f.last_name
        FROM schedules s
        JOIN faculty f ON f.id = s.created_by
        WHERE s.classroom_id = ?
          AND s.day_of_week  = ?
          AND s.start_time  <= ?
          AND s.end_time    >= ?
        LIMIT 1
    ");
    $stmt->bind_param('isss', $room_id, $day, $time, $time);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
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
    <title>Room Management</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <!-- Shared stylesheets -->
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">
    <link rel="stylesheet" href="../../css/admin-room-manage.css">

    <style>
        
    </style>
</head>

<body class="contrast-bg">

    <?php include '../../php/includes/admin-topbar.php'; ?>

    <!-- ═══ PAGE CONTENT ═══ -->
    <div class="page-content">
        <div class="section-heading">All Rooms</div>

        <div class="rooms-grid" id="roomsGrid">
            <?php foreach ($classrooms as $c):
                $on         = ($c['light_status'] === 'on');
                $curSched   = getCurrentSchedule($conn, $c['id']);
                $isOccupied = !empty($curSched);
                $fName      = $isOccupied ? $curSched['faculty_name'] : '—';

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
                    $time = $conn->query("SELECT TIME(NOW()) as t")->fetch_assoc()['t'];
                    $st = $conn->prepare("
                    SELECT start_time FROM schedules 
                    WHERE classroom_id = ? 
                    AND day_of_week = ? 
                    AND start_time > ?
                    ORDER BY start_time 
                    LIMIT 1
                ");
                    $st->bind_param('iss', $c['id'], $day, $time);
                    $st->execute();
                    $result = $st->get_result();
                    $next = $result->fetch_assoc();
                    $st->close();
                    if ($next) $nextSched = date('g:i A', strtotime($next['start_time']));
                }
            ?>
                <div class="room-card" data-room="<?= htmlspecialchars(strtolower($c['room_name'])) ?>">
                    <div class="room-card-accent <?= $accentClass ?>"></div>
                    <div class="room-card-body">
                        <div class="room-card-header">
                            <div>
                                <div class="room-card-name"><?= htmlspecialchars($c['room_name']) ?></div>
                                <div class="room-card-section">
                                    <?= ucfirst($c['room_size']) ?> room
                                    <?php if (!empty($c['description'])): ?>
                                        &middot; <?= htmlspecialchars($c['description']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-1">
                                <button style="background:none;border:none;cursor:pointer;color:#aaa;font-size:14px;padding:2px 5px;"
                                    title="Edit"
                                    onclick="openEditModal(<?= $c['id'] ?>, '<?= addslashes($c['room_name']) ?>', '<?= $c['room_size'] ?>', '<?= addslashes($c['description']) ?>')">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button style="background:none;border:none;cursor:pointer;color:#aaa;font-size:14px;padding:2px 5px;"
                                    title="Delete"
                                    onclick="openDeleteModal(<?= $c['id'] ?>, '<?= addslashes($c['room_name']) ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <span class="room-status-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                            </div>
                        </div>
                        <hr class="room-card-divider">
                        <div class="room-info-row">
                            <i class="bi bi-person-fill"></i>
                            <span class="room-info-label">Faculty:&nbsp;</span>
                            <span class="room-info-val"><?= htmlspecialchars($fName) ?></span>
                        </div>
                        <div class="room-info-row">
                            <i class="bi bi-clock-fill"></i>
                            <span class="room-info-label">
                                <?= $isOccupied ? 'Time:' : 'Next class:' ?>&nbsp;
                            </span>
                            <span class="room-info-val">
                                <?php if ($isOccupied): ?>
                                    <?= date('g:i A', strtotime($curSched['start_time'])) ?> &ndash; <?= date('g:i A', strtotime($curSched['end_time'])) ?>
                                <?php else: ?>
                                    <?= $nextSched ?? 'None today' ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="room-info-row">
                            <i class="bi bi-lightbulb-fill"></i>
                            <span class="room-info-label">Lighting:&nbsp;</span>
                            <span>
                                <span class="light-dot <?= $on ? 'on' : 'off' ?>"></span>
                                <span class="room-info-val"><?= $on ? 'ON' : 'OFF' ?></span>
                            </span>
                        </div>
                    </div>

                    <div class="room-card-actions">
                        <button class="btn-room-view"
                            onclick="openRoomModal(<?= $c['id'] ?>, '<?= addslashes($c['room_name']) ?>', '<?= $c['room_size'] ?>', '<?= addslashes($c['description']) ?>')">
                            View
                        </button>
                        <button class="btn-room-timetable"
                            onclick="dissolve('admin-timetable-manage.php?room=<?= urlencode($c['room_name']) ?>')">
                            Timetable
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Add Room card -->
            <div class="room-card" style="border:2px dashed #bbb;background:#fafafa;box-shadow:none;cursor:pointer;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;color:#aaa;min-height:200px;"
                onclick="new bootstrap.Modal(document.getElementById('addRoomModal')).show()">
                <i class="bi bi-plus-circle" style="font-size:2rem;"></i>
                <span style="font-size:.85rem;font-weight:600;">Add Room</span>
            </div>

        </div><!-- /rooms-grid -->
    </div><!-- /page-content -->
    <?php $conn->close(); ?>

    <!-- ═══ ADD ROOM MODAL ═══ -->
    <div class="modal fade" id="addRoomModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
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
                    <div class="modal-footer">
                        <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="medium">Add Room</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ═══ EDIT ROOM MODAL ═══ -->
    <div class="modal fade" id="editRoomModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
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
                    <div class="modal-footer">
                        <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="medium">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ═══ DELETE ROOM MODAL ═══ -->
    <div class="modal fade" id="deleteRoomModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Room</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="min-height:420px;">
                    Are you sure you want to delete <strong id="deleteRoomName"></strong>?
                    This will also remove all schedules and logs for this room.
                </div>
                <form method="POST" action="../../php/handlers/room-handler.php">
                    <input type="hidden" name="action" value="delete_room">
                    <input type="hidden" name="room_id" id="deleteRoomId">
                    <div class="modal-footer">
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
                            <div style="background:#fff;border-radius:12px;padding:20px;border:1px solid #eee;">
                                <h6 class="bold mb-3">Current Schedule</h6>
                                <div id="modalCurrentSched" style="background:#fff;border-radius:8px;padding:12px;font-size:13px; min-height:60px;">
                                    <em class="text-muted">Loading…</em>
                                </div>
                                <div class="collapse mt-2" id="timetableCollapse">
                                    <table class="table table-sm mt-1" style="font-size:.82rem;">
                                        <thead>
                                            <tr>
                                                <th style="background:var(--secondary-color-1);color:#fff;">Day</th>
                                                <th style="background:var(--secondary-color-1);color:#fff;">Time</th>
                                                <th style="background:var(--secondary-color-1);color:#fff;">Faculty</th>
                                            </tr>
                                        </thead>
                                        <tbody id="modalTimetableBody">
                                            <tr>
                                                <td colspan="3" class="text-muted text-center">Loading…</td>
                                            </tr>
                                        </tbody>
                                    </table>
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
    <?php include '../../php/includes/admin-sidebar.php'; ?>
    <?php include '../../php/includes/profile-offcanvas.php'; ?>

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>

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
    document.getElementById('modalTodaySched').innerHTML = '<em class="text-muted">Loading…</em>';
    document.getElementById('modalTimetableBody').innerHTML = '<tr><td colspan="3" class="text-muted text-center">Loading…</td></tr>';
    document.getElementById('modalAlertsPreview').innerHTML = '<em class="text-muted">Loading…</em>';

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
            updateCardLighting(currentRoomId, data.light_on);
        })
        .catch(err => console.error('Room modal error:', err));
}

// Updates the lighting dot + text on the card without page refresh
function updateCardLighting(roomId, isOn) {
    // Find the card — match by the onclick attribute containing the room id
    const cards = document.querySelectorAll('.room-card');
    cards.forEach(card => {
        const btn = card.querySelector('.btn-room-view');
        if (!btn) return;
        const match = btn.getAttribute('onclick').match(/openRoomModal\((\d+)/);
        if (!match || parseInt(match[1]) !== roomId) return;

        const dot = card.querySelector('.light-dot');
        const label = card.querySelector('.room-info-row .room-info-val:last-child');

        if (dot) {
            dot.className = 'light-dot ' + (isOn ? 'on' : 'off');
        }
        // Find the lighting value span specifically
        card.querySelectorAll('.room-info-row').forEach(row => {
            if (row.querySelector('.bi-lightbulb-fill')) {
                const val = row.querySelector('.room-info-val');
                if (val) val.textContent = isOn ? 'ON' : 'OFF';
            }
        });
    });
}

function renderRoomModal(data) {
    // ── Current Schedule ──
    const schedEl = document.getElementById('modalCurrentSched');
    if (data.current_schedule) {
        const s = data.current_schedule;
        schedEl.innerHTML = `
            <div class="d-flex align-items-center gap-3">
                <div class="avatar-icon d-flex align-items-center justify-content-center"
                     style="width:48px;height:48px;font-size:1rem;">
                    <span class="bold">${s.initials}</span>
                </div>
                <div>
                    <p class="bold mb-0" style="font-size:.9rem;">${s.faculty_name}</p>
                    <small class="text-muted">Faculty Member</small>
                    <div style="font-size:.82rem;margin-top:.3rem;">
                        <span style="background:#ffe4ec;color:#c0004e;padding:2px 8px;border-radius:10px;font-weight:700;font-size:10px;">
                            OCCUPIED
                        </span>
                        &nbsp;${s.start_time} – ${s.end_time}
                    </div>
                </div>
            </div>`;
    } else if (data.next_schedule) {
        schedEl.innerHTML = `
            <div style="font-size:.85rem;">
                <span style="background:#fff5d6;color:#a06800;padding:2px 8px;border-radius:10px;font-weight:700;font-size:10px;">
                    SCHEDULED
                </span>
                <p class="text-muted mt-2 mb-0">No active class right now. Next class today:</p>
                <p class="bold mb-0 mt-1">${data.next_schedule.start_time} – ${data.next_schedule.end_time}</p>
                <small class="text-muted">${data.next_schedule.faculty_name}</small>
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
    const rowStatuses = { 1: data.row1_status === 'on', 2: data.row2_status === 'on', 3: data.row3_status === 'on' };
    for (let row = 1; row <= 3; row++) {
        rowState[row] = rowStatuses[row];
        rowBulbs[row].forEach(i => setBulb(i, rowStatuses[row]));
        const sw = document.getElementById('row' + row + 'sw');
        if (sw) sw.checked = rowStatuses[row];
    }
    syncAllLightsLabel();

    // ── Today's Timetable — use today_schedules directly ──
    const todayEl = document.getElementById('modalTodaySched');
    if (data.today_schedules && data.today_schedules.length > 0) {
        todayEl.innerHTML = data.today_schedules.map(s =>
            `<div class="sched-block">
                <div style="font-weight:600;">${s.start_time} – ${s.end_time}</div>
                <small>${s.faculty_name}</small>
            </div>`
        ).join('');
    } else {
        todayEl.innerHTML = '<p class="text-muted mb-0" style="font-size:.82rem;">No classes scheduled today.</p>';
    }

    // ── Full timetable ──
    const tBody = document.getElementById('modalTimetableBody');
    if (data.all_schedules && data.all_schedules.length > 0) {
        tBody.innerHTML = data.all_schedules.map(s =>
            `<tr>
                <td>${s.day_of_week}</td>
                <td>${s.start_time} – ${s.end_time}</td>
                <td>${s.faculty_name}</td>
            </tr>`
        ).join('');
    } else {
        tBody.innerHTML = '<tr><td colspan="3" class="text-muted text-center">No schedules yet.</td></tr>';
    }

    // ── Alerts — scrollable, no slice ──
    const previewEl = document.getElementById('modalAlertsPreview');
    if (data.alerts && data.alerts.length > 0) {
        const renderAlert = a => `
            <div class="alert-log-item">
                <span class="status-pill ${a.event_type === 'security_alert' ? 'pill-warn' : 'pill-ok'}"
                      style="margin-right:.4rem;">
                    ${a.event_type.replace('_', ' ')}
                </span>
                ${a.triggered_by ? '<span style="color:#555;">' + a.triggered_by + '</span>' : ''}
                <span class="text-muted ms-1">${a.event_time}</span>
            </div>`;
        previewEl.innerHTML = data.alerts.map(renderAlert).join('');
    } else {
        previewEl.innerHTML = '<p class="text-muted mb-0" style="font-size:.82rem;">No activity today.</p>';
    }
}

// ── Light controls ──
let rowState = { 1: false, 2: false, 3: false };
const rowBulbs = { 1: [0,1,2], 2: [3,4,5], 3: [6,7,8] };

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
    const rowToSend   = changedRow === 'all' ? 'all' : String(changedRow);
    const stateToSend = changedRow === 'all' ? (anyOn ? 'on' : 'off') : (rowState[changedRow] ? 'on' : 'off');

    const form = new FormData();
    form.append('classroom_id', currentRoomId);
    form.append('row',          rowToSend);
    form.append('state',        stateToSend);
    form.append('triggered_by', 'admin_override');

    fetch('../../api/lights.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(d => { if (d.success) updateCardLighting(currentRoomId, anyOn); })
        .catch(err => console.error('Lighting error:', err));
}

function syncAllLightsLabel() {
    const anyOn = Object.values(rowState).some(v => v);
    const label = document.getElementById('allLightsLabel');
    const btn   = document.getElementById('allLightsBtn');
    if (label) label.textContent = anyOn ? 'ON' : 'OFF';
    if (btn)   btn.className = 'override-master-btn ' + (anyOn ? 'on' : 'off');

    // Sync per-row status labels
    for (let row = 1; row <= 3; row++) {
        const statusEl = document.getElementById('row' + row + 'status');
        if (statusEl) {
            statusEl.textContent = rowState[row] ? 'ON' : 'OFF';
            statusEl.className = 'override-row-status' + (rowState[row] ? ' is-on' : '');
        }
    }
}

document.addEventListener('DOMContentLoaded', function () {
    // Stop polling when modal closes
    document.getElementById('roomModal').addEventListener('hidden.bs.modal', function () {
        clearInterval(roomPollInterval);
        roomPollInterval = null;
    });

    // Search filter
    const roomSearchEl = document.getElementById('roomSearch');
    if (roomSearchEl) {
        roomSearchEl.addEventListener('input', function () {
            const q = this.value.toLowerCase();
            document.querySelectorAll('.room-card').forEach(card => {
                card.style.display = card.dataset.room.includes(q) ? '' : 'none';
            });
        });
    }
});
</script>
</body>

</html>