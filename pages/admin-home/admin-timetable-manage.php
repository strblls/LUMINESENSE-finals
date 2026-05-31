<?php
$page_title = 'Schedule Management';
require_once '../../php/includes/admin-head.php';
require_once '../../php/handlers/admin-handlers.php';

/** @var string $initials */
/** @var string $admin_name */
/** @var string $admin_email */
/** @var int $admin_id */

// Fetch rooms
$rooms = [];
$r = $conn->query('SELECT id, room_name FROM classrooms ORDER BY room_name');
if ($r) { while ($row = $r->fetch_assoc()) $rooms[] = $row; }

// Fetch approved faculty for dropdowns
$faculty_list = [];
$f = $conn->query("
    SELECT id, CONCAT(first_name,' ',last_name) AS full_name
    FROM faculty
    WHERE is_verified = 1 AND approved_by IS NOT NULL
    ORDER BY last_name, first_name
");
if ($f) { while ($row = $f->fetch_assoc()) $faculty_list[] = $row; }

// Selected room
$selected_room_id   = 0;
$selected_room_name = '';

if (!empty($_GET['room_id'])) {
    $selected_room_id = (int)$_GET['room_id'];
} elseif (!empty($_GET['room'])) {
    $rn = $_GET['room'];
    foreach ($rooms as $rm) {
        if (strtolower($rm['room_name']) === strtolower($rn)) {
            $selected_room_id = $rm['id']; break;
        }
    }
}
if (!$selected_room_id && !empty($rooms)) {
    $selected_room_id = $rooms[0]['id'];
}
foreach ($rooms as $rm) {
    if ($rm['id'] == $selected_room_id) {
        $selected_room_name = $rm['room_name']; break;
    }
}

// Fetch schedule rows for selected room
$schedule_rows = [];
if ($selected_room_id) {
    $sq = $conn->prepare("
        SELECT s.id,
               CONCAT(f.first_name,' ',f.last_name) AS faculty_name,
               s.day_of_week, s.start_time, s.end_time
        FROM   schedules s
        JOIN   faculty f ON f.id = s.created_by
        WHERE  s.classroom_id = ?
        ORDER  BY FIELD(s.day_of_week,'Monday','Tuesday','Wednesday',
                        'Thursday','Friday','Saturday','Sunday'),
                  s.start_time
    ");
    if ($sq) {
        $sq->bind_param('i', $selected_room_id);
        $sq->execute();
        $res = $sq->get_result();
        while ($row = $res->fetch_assoc()) $schedule_rows[] = $row;
        $sq->close();
    }
}

$days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schedule Management – LumineSense</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
            crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">
    <link rel="stylesheet" href="../../css/admin-timetable.css">                        
</head>

<body class="contrast-bg">
    <?php include '../../php/includes/admin-topbar.php'; ?>

    <div class="px-4 pt-3">
        <button class="light info-action-btn" 
                onclick="dissolve('admin-room-manage.php')"
                style="padding:6px 14px; border-radius:8px; font-size:13px;">
            <i class="bi bi-arrow-left me-1"></i> Back to Room Management
        </button>
    </div>
    
    <!-- ═══ PAGE CONTENT ═══ -->
    <div class="page-content">
        <div class="section-heading">Room Schedule</div>

        <div class="schedule-card">

            <!-- Card header -->
            <div class="schedule-card-header">
                <h4><i class="bi bi-calendar3 me-2"></i>Schedule Management</h4>
                <div class="room-pill" id="headerRoomPill">
                    <i class="bi bi-door-open"></i>
                    <span id="headerRoomName"><?= htmlspecialchars($selected_room_name ?: 'Select a Room') ?></span>
                </div>
            </div>

            <!-- Room selector + Add button -->
            <div class="room-selector-row">
                <span class="room-selector-label">Room</span>
                <select class="room-select-dropdown" id="roomDropdown" onchange="changeRoom(this.value)">
                    <?php foreach ($rooms as $rm): ?>
                        <option value="<?= $rm['id'] ?>"
                            <?= $rm['id'] == $selected_room_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($rm['room_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn-add-slot" data-bs-toggle="modal" data-bs-target="#schedModal"
                        onclick="openAddModal()">
                    <i class="bi bi-plus-lg"></i> Add Schedule Slot
                </button>
            </div>

            <!-- Schedule table -->
            <div class="schedule-table-wrap">
                <table class="schedule-table" id="schedTable">
                    <thead>
                        <tr>
                            <th style="width:160px;">Time</th>
                            <th>Faculty</th>
                            <th style="width:90px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="schedTableBody">
                        <?php
                        $by_day = [];
                        foreach ($schedule_rows as $row) {
                            $by_day[$row['day_of_week']][] = $row;
                        }
                        if (empty($by_day)):
                        ?>
                        <tr>
                            <td colspan="3">
                                <div class="empty-state">
                                    <i class="bi bi-calendar-x"></i>
                                    <p>No schedule slots for this room yet.<br>
                                       Click <strong>Add Schedule Slot</strong> to get started.</p>
                                </div>
                            </td>
                        </tr>
                        <?php else:
                            foreach ($days as $day):
                                if (!isset($by_day[$day])) continue;
                        ?>
                        <tr class="day-header">
                            <td colspan="3"><?= $day ?></td>
                        </tr>
                        <?php foreach ($by_day[$day] as $s): ?>
                        <tr class="sched-row" data-id="<?= $s['id'] ?>">
                            <td class="sched-time">
                                <?= date('g:i A', strtotime($s['start_time'])) ?>
                                &ndash;
                                <?= date('g:i A', strtotime($s['end_time'])) ?>
                            </td>
                            <td>
                                <div class="sched-faculty"><?= htmlspecialchars($s['faculty_name']) ?></div>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <button class="btn-icon btn-icon-edit"
                                        onclick="openEditModal(
                                            <?= $s['id'] ?>,
                                            '<?= addslashes($s['faculty_name']) ?>',
                                            '<?= $s['day_of_week'] ?>',
                                            '<?= $s['start_time'] ?>',
                                            '<?= $s['end_time'] ?>'
                                        )" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn-icon btn-icon-del"
                                        onclick="confirmDelete(<?= $s['id'] ?>)"
                                        title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

        </div><!-- /schedule-card -->
    </div><!-- /page-content -->

    <!-- ═══ CONFIRM / DISCARD BAR ═══ -->
    <div class="confirm-bar" id="confirmBar">
        <p><i class="bi bi-info-circle me-2"></i>Schedule updated successfully.</p>
        <button class="btn-discard" onclick="discardChanges()">Dismiss</button>
        <button class="btn-confirm" onclick="saveChanges()">
            <i class="bi bi-check-lg me-1"></i> Done
        </button>
    </div>

    <!-- ═══ ADD / EDIT MODAL ═══ -->
    <div class="sched-modal modal fade" id="schedModal" tabindex="-1"
         aria-labelledby="schedModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:16px;overflow:hidden;border:none;">
                <div class="modal-header">
                    <h5 class="modal-title" id="schedModalLabel">Add Schedule Slot</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="editSlotId" value="">

                    <!-- Day -->
                    <div class="form-group mb-3">
                        <label class="form-label-sm">Day of Week</label>
                        <select class="form-ctrl" id="slotDay">
                            <?php foreach ($days as $d): ?>
                                <option value="<?= $d ?>"><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Time -->
                    <div class="form-row mb-3">
                        <div class="form-group">
                            <label class="form-label-sm">Time Start</label>
                            <input type="time" class="form-ctrl" id="slotStart">
                        </div>
                        <div class="form-group">
                            <label class="form-label-sm">Time End</label>
                            <input type="time" class="form-ctrl" id="slotEnd">
                        </div>
                    </div>

                    <!-- Faculty -->
                    <div class="form-group mb-3">
                        <label class="form-label-sm">Faculty</label>
                        <select class="form-ctrl" id="slotFaculty">
                            <option value="">— Select Faculty —</option>
                            <?php foreach ($faculty_list as $f): ?>
                                <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <button class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn-modal-save" onclick="saveSlot()">
                            <i class="bi bi-check-lg me-1"></i> Save Slot
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ DELETE CONFIRM MODAL ═══ -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content" style="border-radius:16px;overflow:hidden;border:none;">
                <div class="modal-header" style="background:linear-gradient(135deg,#c0004e,#e05580);color:#fff;">
                    <h5 class="modal-title" style="font-weight:700;">Delete Slot?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="bi bi-trash" style="font-size:2.5rem;color:#c0004e;"></i>
                    <p class="mt-3 mb-0" style="font-size:15px;">
                        This schedule slot will be permanently removed. Are you sure?
                    </p>
                </div>
                <div class="modal-footer border-0 justify-content-center gap-2">
                    <button class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn-modal-save" style="background:#c0004e;" onclick="executeDelete()">
                        <i class="bi bi-trash me-1"></i> Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php include '../../php/includes/admin-sidebar.php'; ?>
    <?php include '../../php/includes/profile-offcanvas.php'; ?>

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
    <script>
    function changeRoom(roomId) {
        const opt   = document.getElementById('roomDropdown').options;
        const label = opt[opt.selectedIndex].text;
        document.getElementById('headerRoomName').textContent = label;
        window.location.href = 'admin-timetable-manage.php?room_id=' + encodeURIComponent(roomId);
    }

    function openAddModal() {
        document.getElementById('schedModalLabel').textContent = 'Add Schedule Slot';
        document.getElementById('editSlotId').value  = '';
        document.getElementById('slotDay').value     = 'Monday';
        document.getElementById('slotStart').value   = '';
        document.getElementById('slotEnd').value     = '';
        document.getElementById('slotFaculty').value = '';
    }

    function openEditModal(id, faculty, day, start, end) {
        document.getElementById('schedModalLabel').textContent = 'Edit Schedule Slot';
        document.getElementById('editSlotId').value = id;
        document.getElementById('slotDay').value    = day;
        document.getElementById('slotStart').value  = start.substring(0, 5);
        document.getElementById('slotEnd').value    = end.substring(0, 5);
        // match faculty by name text
        const fSel = document.getElementById('slotFaculty');
        for (let o of fSel.options) {
            if (o.text === faculty) { fSel.value = o.value; break; }
        }
        new bootstrap.Modal(document.getElementById('schedModal')).show();
    }

    function saveSlot() {
        const id      = document.getElementById('editSlotId').value;
        const day     = document.getElementById('slotDay').value;
        const start   = document.getElementById('slotStart').value;
        const end     = document.getElementById('slotEnd').value;
        const faculty = document.getElementById('slotFaculty').value;
        const roomId  = document.getElementById('roomDropdown').value;

        if (!day || !start || !end || !faculty) {
            alert('Please fill in all fields.'); return;
        }
        if (start >= end) {
            alert('End time must be after start time.'); return;
        }

        const body = new URLSearchParams({
            action:      id ? 'update' : 'create',
            slot_id:     id,
            room_id:     roomId,
            faculty_id:  faculty,
            day_of_week: day,
            start_time:  start,
            end_time:    end
        });

        fetch('../../php/handlers/schedule-handler.php', { method: 'POST', body })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('schedModal'))?.hide();
                    showConfirmBar();
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(() => alert('Could not reach the server. Please try again.'));
    }

    let _pendingDeleteId = null;
    function confirmDelete(id) {
        _pendingDeleteId = id;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }

    function executeDelete() {
        if (!_pendingDeleteId) return;
        const body = new URLSearchParams({ action: 'delete', slot_id: _pendingDeleteId });

        fetch('../../php/handlers/schedule-handler.php', { method: 'POST', body })
            .then(r => r.json())
            .then(data => {
                bootstrap.Modal.getInstance(document.getElementById('deleteModal'))?.hide();
                if (data.success) {
                    const row = document.querySelector(`.sched-row[data-id="${_pendingDeleteId}"]`);
                    if (row) row.remove();
                    showConfirmBar();
                } else {
                    alert('Error: ' + (data.message || 'Could not delete'));
                }
                _pendingDeleteId = null;
            })
            .catch(() => alert('Could not reach the server. Please try again.'));
    }

    function showConfirmBar() {
        document.getElementById('confirmBar').classList.add('visible');
    }
    function saveChanges() {
        document.getElementById('confirmBar').classList.remove('visible');
        location.reload();
    }
    function discardChanges() {
        document.getElementById('confirmBar').classList.remove('visible');
    }
    </script>

</body>
</html>