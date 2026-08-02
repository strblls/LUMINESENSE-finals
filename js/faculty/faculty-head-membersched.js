let editScheduleModal = null;
let viewSlotModal = null;
let deleteScheduleModal = null;
let confirmSaveModal = null;
let restrictedModal = null;
let runningScheduleModal = null;
let overlapWarningModal = null;
let timeValidationModal = null;
let deleteSlotId = null;
const subjects = window.lumiMemberschedSubjects || [];
const rooms = window.lumiMemberschedRooms || [];
const memberId = window.lumiMemberschedMemberId || 0;
const todayDayName = window.lumiMemberschedToday || '';

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
        '<div class="mb-1"><strong>Time:</strong> ' + conflict.start + ' — ' + conflict.end + '</div>' +
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

    const res = await fetch('../../handlers/faculty-head-handler.php', {
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

    const res = await fetch('../../handlers/faculty-head-handler.php', {
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

function showRunningWarningModal() {
    if (!runningScheduleModal) {
        runningScheduleModal = new bootstrap.Modal(document.getElementById('runningScheduleModal'));
    }
    runningScheduleModal.show();
}

function openSlotDetails(day, startTime, endTime, room, subject) {
    if (!viewSlotModal) {
        viewSlotModal = new bootstrap.Modal(document.getElementById('viewSlotModal'));
    }
    document.getElementById('slot-day').textContent = day;
    document.getElementById('slot-time').textContent = startTime + ' — ' + endTime;
    document.getElementById('slot-room').textContent = room;
    document.getElementById('slot-subject').textContent = subject;
    viewSlotModal.show();
}
