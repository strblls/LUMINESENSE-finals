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
    document.getElementById('modalCurrentSched').innerHTML = '<p class="text-muted" style="font-size:.85rem;">Loading\u2026</p>';
    document.getElementById('modalTodaySched').innerHTML = '<div class="modal-slot-empty">Loading\u2026</div>';
    document.getElementById('modalTimetableBody').innerHTML = '<div class="modal-slot-empty">Loading\u2026</div>';
    document.getElementById('modalAlertsPreview').innerHTML = '<div class="modal-slot-empty">Loading\u2026</div>';

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

function pollAllRoomData() {
    fetch('../../api/classrooms.php')
        .then(r => r.json())
        .then(res => {
            if (!res.success || !res.data) return;
            res.data.forEach(room => {
                const card = document.querySelector(`.room-card[data-room-id="${room.id}"]`);
                if (!card) return;

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

                const facultyVal = card.querySelector('.room-info-row .bi-person-fill')?.closest('.room-info-row')?.querySelector('.room-info-val');
                if (facultyVal) facultyVal.textContent = isOccupied ? cur.faculty_name : '\u2014';

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
        'pir_motion': ['bi-person-bounding-box', '#084298', '#cfe2ff'],
        'pir_stopped': ['bi-person-bounding-box', '#5a5a5a', '#e9ecef'],
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
                ${s.start_time} \u2013 ${s.end_time}
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
            <p class="bold mb-0" style="font-size:.9rem;">${ns.faculty_name || '\u2014'}</p>
            <small class="text-muted">Next class</small>
            <div style="font-size:.9rem;font-weight:600;margin-top:.2rem;">
                ${ns.start_time} \u2013 ${ns.end_time}
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

    const todayEl = document.getElementById('modalTodaySched');
    const todayName = new Date().toLocaleDateString('en-US', {weekday:'long'});
    const dayOrder = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const todayIndex = dayOrder.indexOf(todayName);
    const grouped = {};
    dayOrder.forEach(d => grouped[d] = []);
    if (data.all_schedules) {
        data.all_schedules.forEach(s => {
            if (grouped[s.day_of_week]) grouped[s.day_of_week].push(s);
        });
        Object.values(grouped).forEach(arr => arr.sort((a,b) => a.start_time.localeCompare(b.start_time)));
    }
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const now = new Date();
    const dateLabels = {};
    dayOrder.forEach((day, i) => {
        const d = new Date(now);
        d.setDate(d.getDate() + (i - todayIndex));
        dateLabels[day] = months[d.getMonth()] + ' ' + d.getDate();
    });
    todayEl.innerHTML = '<div class="weekly-schedule-grid" style="min-width:max-content;">' +
        dayOrder.map(day => {
            const isToday = day === todayName;
            const slots = grouped[day];
            const slotsHtml = slots && slots.length
                ? slots.map(s => {
                    const sp = s.start_time.split(' ');
                    const ep = s.end_time.split(' ');
                    return `<div class="slot-row">
                        <div class="slot-time">
                            <span class="slot-time-start">${sp[0]}</span>
                            <span class="slot-time-separator">TO</span>
                            <span class="slot-time-end">${ep[0]}</span>
                            <span class="slot-time-ampm">${ep[1]}</span>
                        </div>
                        <div class="slot-content">
                            <div class="slot-room"><i class="bi bi-person me-1"></i>${s.faculty_name}</div>
                        </div>
                    </div>`;
                }).join('')
                : '<p class="no-sched">No classes scheduled.</p>';
            return `<div class="day-card${isToday ? ' today' : ''}">
                <div class="day-label">
                    <div class="text-uppercase small fw-bold mb-1" style="font-size:11px;letter-spacing:0.5px;color:${isToday ? '#fff' : '#6c757d'};">${dateLabels[day]}</div>
                    ${day}${isToday ? ' \u00b7 Today' : ''}
                </div>
                ${slotsHtml}
            </div>`;
        }).join('') + '</div>';

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

    for (let row = 1; row <= 3; row++) {
        const statusEl = document.getElementById('row' + row + 'status');
        if (statusEl) {
            statusEl.textContent = rowState[row] ? 'ON' : 'OFF';
            statusEl.className = 'override-row-status' + (rowState[row] ? ' is-on' : '');
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('roomModal').addEventListener('hidden.bs.modal', function() {
        clearInterval(roomPollInterval);
        roomPollInterval = null;
    });

    pollAllRoomData();
    setInterval(pollAllRoomData, 15000);

    function applyFilters() {
        var statusVal = (document.querySelector('#statusFilterMenu .filter-option.active') || {}).dataset?.value || '';
        var deptVal = (document.querySelector('#departmentFilterMenu .filter-option.active') || {}).dataset?.value || '';
        var saVal = (document.querySelector('#subjectAreaFilterMenu .filter-option.active') || {}).dataset?.value || '';
        var subjVal = (document.querySelector('#subjectFilterMenu2 .filter-option.active') || {}).dataset?.value || '';
        var searchVal = (document.getElementById('roomSearch') || {}).value || '';
        searchVal = searchVal.toLowerCase();
        document.querySelectorAll('.room-card').forEach(function(card) {
            var show = true;
            if (statusVal) {
                show = show && (card.dataset.status || '') === statusVal.toLowerCase();
            }
            if (deptVal) {
                show = show && (card.dataset.departments || '').toLowerCase().includes(deptVal.toLowerCase());
            }
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

    document.querySelectorAll('#statusFilterMenu .filter-option, #departmentFilterMenu .filter-option, #subjectAreaFilterMenu .filter-option, #subjectFilterMenu2 .filter-option').forEach(function(opt) {
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

    (function() {
        var panels = ['panelGuideInfo', 'panelStatusFilter', 'panelScheduleFilter'];
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
