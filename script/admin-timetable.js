function changeRoom(roomId) {
    const opt = document.getElementById('roomDropdown').options;
    const label = opt[opt.selectedIndex].text;
    document.getElementById('headerRoomName').textContent = label;
    window.location.href = 'admin-timetable-manage.php?room_id=' + encodeURIComponent(roomId);
}

function openAddModal() {
    document.getElementById('schedModalLabel').textContent = 'Add Schedule Slot';
    document.getElementById('editSlotId').value = '';
    document.getElementById('slotDay').value = 'Monday';
    document.getElementById('slotStart').value = '';
    document.getElementById('slotEnd').value = '';
    document.getElementById('slotFaculty').value = '';
}

function openEditModal(id, faculty, day, start, end) {
    document.getElementById('schedModalLabel').textContent = 'Edit Schedule Slot';
    document.getElementById('editSlotId').value = id;
    document.getElementById('slotDay').value = day;
    document.getElementById('slotStart').value = start.substring(0, 5);
    document.getElementById('slotEnd').value = end.substring(0, 5);
    // match faculty by name text
    const fSel = document.getElementById('slotFaculty');
    for (let o of fSel.options) {
        if (o.text === faculty) { fSel.value = o.value; break; }
    }
    new bootstrap.Modal(document.getElementById('schedModal')).show();
}

function saveSlot() {
    const id = document.getElementById('editSlotId').value;
    const day = document.getElementById('slotDay').value;
    const start = document.getElementById('slotStart').value;
    const end = document.getElementById('slotEnd').value;
    const faculty = document.getElementById('slotFaculty').value;
    const roomId = document.getElementById('roomDropdown').value;

    if (!day || !start || !end || !faculty) {
        alert('Please fill in all fields.'); return;
    }
    if (start >= end) {
        alert('End time must be after start time.'); return;
    }

    const body = new URLSearchParams({
        action: id ? 'update' : 'create',
        slot_id: id,
        room_id: roomId,
        faculty_id: faculty,
        day_of_week: day,
        start_time: start,
        end_time: end
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