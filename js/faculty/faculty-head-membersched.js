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

// ═══════════════════════════════════════════════════════════════════════
// Two-pane Coverage Editor (Subject Areas ⇄ Subjects)
// ═══════════════════════════════════════════════════════════════════════
const deptSubjectAreas = window.lumiMemberschedDeptSAs || [];
const initialAssignedSaIds = (window.lumiMemberschedAssignedSAs || []).map(Number);
const initialAssignedSubjIds = (window.lumiMemberschedAssignedSubjects || []).map(Number);
const coverageDeptId = window.lumiMemberschedDeptId || 0;
const coverageMemberName = window.lumiMemberschedMemberName || '';

let editCoverageModal = null;
let confirmCoverageModal = null;
let coveragePane = 0;
let coverageSelectedSaId = '';
let coverageSaIds = new Set();
let coverageSubjIds = new Set();

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function coverageSaById(id) {
    return deptSubjectAreas.find(function(sa) { return sa.id == id; }) || null;
}

function coverageGotoPane(index) {
    coveragePane = index === 1 ? 1 : 0;
    document.getElementById('coveragePaneAreas').classList.toggle('d-none', coveragePane !== 0);
    document.getElementById('coveragePaneSubjects').classList.toggle('d-none', coveragePane !== 1);
    document.querySelectorAll('.coverage-step').forEach(function(el) {
        el.classList.toggle('active', parseInt(el.dataset.step) === coveragePane);
    });
    document.getElementById('coverageNavPrev').disabled = coveragePane === 0;
    document.getElementById('coverageNavNext').disabled = coveragePane === 1;
}

function coverageNav(delta) {
    coverageGotoPane(coveragePane + (delta > 0 ? 1 : -1));
}

function renderCoverageAssignedSAs() {
    const box = document.getElementById('coverageAssignedSAs');
    if (!box) return;
    box.innerHTML = '';
    const ids = Array.from(coverageSaIds);
    if (ids.length === 0) {
        box.innerHTML = '<p class="text-muted small mb-0">No subject areas assigned yet.</p>';
        return;
    }
    ids.forEach(function(id) {
        const sa = coverageSaById(id);
        if (!sa) return;
        const span = document.createElement('span');
        span.className = 'dept-subject-area bold dept-emphases align-items-center justify-content-center px-3';
        span.dataset.saId = id;
        span.title = 'Click to view its subjects';
        span.innerHTML = escapeHtml(sa.name) +
            '<button type="button" class="btn-close btn-close-white" title="Remove Subject Area"></button>';
        span.addEventListener('click', function(e) {
            if (e.target.closest('.btn-close')) {
                coverageSaIds.delete(id);
                (sa.subjects || []).forEach(function(s) { coverageSubjIds.delete(s.id); });
                if (coverageSelectedSaId == id) coverageSelectedSaId = '';
                renderCoverageAssignedSAs();
                renderCoverageAvailableSAs();
                renderCoverageSaSelect();
            } else {
                coverageSelectedSaId = id;
                renderCoverageSaSelect();
                coverageGotoPane(1);
            }
        });
        box.appendChild(span);
    });
}

function renderCoverageAvailableSAs() {
    const box = document.getElementById('coverageAvailableSAs');
    if (!box) return;
    box.innerHTML = '';
    const available = deptSubjectAreas.filter(function(sa) { return !coverageSaIds.has(sa.id); });
    if (available.length === 0) {
        box.innerHTML = '<p class="text-muted small mb-0">All subject areas are already assigned.</p>';
        return;
    }
    available.forEach(function(sa) {
        const span = document.createElement('span');
        span.className = 'dept-subject-area bold dept-emphases align-items-center justify-content-center px-3 available-sa-item';
        span.dataset.saId = sa.id;
        span.title = 'Click to add this subject area';
        span.innerHTML = escapeHtml(sa.name) +
            '<button type="button" class="p-0 ms-1 d-inline-flex flex-shrink-0 align-items-center text-white border-0 bg-transparent" title="Add Subject Area"><i class="bi bi-plus-circle"></i></button>';
        span.addEventListener('click', function() {
            coverageSaIds.add(sa.id);
            renderCoverageAssignedSAs();
            renderCoverageAvailableSAs();
            renderCoverageSaSelect();
        });
        box.appendChild(span);
    });
    applyCoverageSaSearch();
}

function renderCoverageSaSelect() {
    const sel = document.getElementById('coverageSaSelect');
    if (!sel) return;
    const keep = coverageSelectedSaId;
    sel.innerHTML = '<option value="">Select a subject area...</option>';
    deptSubjectAreas.forEach(function(sa) {
        const opt = document.createElement('option');
        opt.value = sa.id;
        opt.textContent = sa.name;
        sel.appendChild(opt);
    });
    if (keep) {
        sel.value = keep;
        renderCoverageSubjects(keep);
    } else {
        sel.value = '';
        renderCoverageSubjectsEmpty();
    }
}

function renderCoverageSubjectsEmpty() {
    document.getElementById('coverageAvailableSubjects').innerHTML = '<p class="text-muted small mb-0">Select a subject area to view available subjects.</p>';
    document.getElementById('coverageAssignedSubjects').innerHTML = '<p class="text-muted small mb-0">Select a subject area to view its subjects.</p>';
    const search = document.getElementById('coverageSubjectSearch');
    if (search) { search.value = ''; search.disabled = true; search.placeholder = 'Select a subject area first'; }
}

function renderCoverageSubjects(saId) {
    coverageSelectedSaId = saId;
    const sa = coverageSaById(saId);
    const availBox = document.getElementById('coverageAvailableSubjects');
    const assignedBox = document.getElementById('coverageAssignedSubjects');
    const search = document.getElementById('coverageSubjectSearch');
    if (!sa || !availBox || !assignedBox) { renderCoverageSubjectsEmpty(); return; }

    search.disabled = false;
    search.placeholder = 'Search available subjects for ' + sa.name;

    const subjects = sa.subjects || [];
    const available = subjects.filter(function(s) { return !coverageSubjIds.has(s.id); });
    const assigned = subjects.filter(function(s) { return coverageSubjIds.has(s.id); });

    availBox.innerHTML = '';
    if (available.length === 0) {
        availBox.innerHTML = '<p class="text-muted small mb-0">All subjects under this area are already assigned.</p>';
    } else {
        available.forEach(function(s) {
            const span = document.createElement('span');
            span.className = 'subarea-subject bold dept-emphases align-items-center justify-content-center px-3 available-subject-item';
            span.dataset.subjectId = s.id;
            span.title = 'Click to add this subject';
            span.innerHTML = escapeHtml(s.name) +
                '<button type="button" class="p-0 ms-1 d-inline-flex flex-shrink-0 align-items-center text-white border-0 bg-transparent" title="Add Subject"><i class="bi bi-plus-circle"></i></button>';
            span.addEventListener('click', function() {
                coverageSubjIds.add(s.id);
                coverageSaIds.add(saId); // keep the parent area assigned too
                renderCoverageSubjects(saId);
                renderCoverageAssignedSAs();
                renderCoverageAvailableSAs();
                renderCoverageSaSelect();
            });
            availBox.appendChild(span);
        });
    }

    assignedBox.innerHTML = '';
    if (assigned.length === 0) {
        assignedBox.innerHTML = '<p class="text-muted small mb-0">No subjects assigned under this area.</p>';
    } else {
        assigned.forEach(function(s) {
            const span = document.createElement('span');
            span.className = 'subarea-subject bold dept-emphases align-items-center justify-content-center px-3';
            span.dataset.subjectId = s.id;
            span.title = 'Click to remove this subject';
            span.innerHTML = escapeHtml(s.name) +
                '<button type="button" class="btn-close btn-close-white" title="Remove Subject"></button>';
            span.addEventListener('click', function() {
                coverageSubjIds.delete(s.id);
                renderCoverageSubjects(saId);
            });
            assignedBox.appendChild(span);
        });
    }
    applyCoverageSubjectSearch();
}

function applyCoverageSaSearch() {
    const box = document.getElementById('coverageAvailableSAs');
    const input = document.getElementById('coverageSaSearch');
    if (!box || !input) return;
    const filter = (input.value || '').toLowerCase();
    let any = false;
    box.querySelectorAll('.available-sa-item').forEach(function(item) {
        const show = item.textContent.toLowerCase().includes(filter);
        item.style.display = show ? '' : 'none';
        if (show) any = true;
    });
    let empty = box.querySelector('.no-match-msg');
    if (!any) {
        if (!empty) {
            empty = document.createElement('p');
            empty.className = 'text-muted small mb-0 no-match-msg';
            empty.textContent = 'No matching subject areas.';
            box.appendChild(empty);
        }
    } else if (empty) {
        empty.remove();
    }
}

function applyCoverageSubjectSearch() {
    const box = document.getElementById('coverageAvailableSubjects');
    const input = document.getElementById('coverageSubjectSearch');
    if (!box || !input) return;
    const filter = (input.value || '').toLowerCase();
    let any = false;
    box.querySelectorAll('.available-subject-item').forEach(function(item) {
        const show = item.textContent.toLowerCase().includes(filter);
        item.style.display = show ? '' : 'none';
        if (show) any = true;
    });
    let empty = box.querySelector('.no-match-msg');
    if (!any) {
        if (!empty) {
            empty = document.createElement('p');
            empty.className = 'text-muted small mb-0 no-match-msg';
            empty.textContent = 'No matching subjects.';
            box.appendChild(empty);
        }
    } else if (empty) {
        empty.remove();
    }
}

function openEditCoverageModal() {
    if (!editCoverageModal) {
        editCoverageModal = new bootstrap.Modal(document.getElementById('editCoverageModal'));
    }
    coverageSaIds = new Set(initialAssignedSaIds);
    coverageSubjIds = new Set(initialAssignedSubjIds);
    coverageSelectedSaId = '';
    document.getElementById('editCoverageMemberName').textContent = coverageMemberName;
    document.getElementById('coverageSaSearch').value = '';
    document.getElementById('coverageSubjectSearch').value = '';
    renderCoverageAssignedSAs();
    renderCoverageAvailableSAs();
    renderCoverageSaSelect();
    coverageGotoPane(0);
    editCoverageModal.show();
}

function confirmCoverageSave() {
    if (!confirmCoverageModal) {
        confirmCoverageModal = new bootstrap.Modal(document.getElementById('confirmCoverageModal'));
    }
    document.getElementById('confirmCoverageName').textContent = coverageMemberName;
    confirmCoverageModal.show();
}

async function executeCoverageSave() {
    const currentSaIds = Array.from(coverageSaIds);
    const currentSubjIds = Array.from(coverageSubjIds);

    const removeSaIds = initialAssignedSaIds.filter(function(id) { return !coverageSaIds.has(id); });
    const addSaIds = currentSaIds.filter(function(id) { return initialAssignedSaIds.indexOf(id) === -1; });
    const removeSubjIds = initialAssignedSubjIds.filter(function(id) { return !coverageSubjIds.has(id); });
    const addSubjIds = currentSubjIds.filter(function(id) { return initialAssignedSubjIds.indexOf(id) === -1; });

    const body = new URLSearchParams({
        action: 'save_faculty_coverage',
        faculty_id: memberId,
        department_id: coverageDeptId,
        keep_sa_ids: JSON.stringify(currentSaIds),
        remove_sa_ids: JSON.stringify(removeSaIds),
        remove_subject_ids: JSON.stringify(removeSubjIds),
        add_sa_ids: JSON.stringify(addSaIds),
        add_subject_ids: JSON.stringify(addSubjIds)
    });

    if (confirmCoverageModal) confirmCoverageModal.hide();
    if (editCoverageModal) editCoverageModal.hide();

    try {
        const res = await fetch('../../handlers/faculty-head-handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        });
        const data = await res.json();
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Failed to save changes.');
        }
    } catch (err) {
        alert('An error occurred while saving.');
    }
}

document.addEventListener('input', function(e) {
    if (e.target.id === 'coverageSaSearch') applyCoverageSaSearch();
    if (e.target.id === 'coverageSubjectSearch') applyCoverageSubjectSearch();
});

document.addEventListener('change', function(e) {
    if (e.target.id === 'coverageSaSelect') {
        if (e.target.value) {
            renderCoverageSubjects(e.target.value);
        } else {
            renderCoverageSubjectsEmpty();
        }
    }
});
