(function() {
    const canvas = document.getElementById('hierarchyCanvas');
    const wrap = canvas?.closest('.hierarchy-canvas-wrap');
    const linesSvg = document.getElementById('hierarchyLines');
    if (!canvas || !wrap || !linesSvg) return;

    canvas.querySelectorAll('.hierarchy-dept').forEach(dept => {
        const saved = localStorage.getItem('hierarchy_pos_' + dept.dataset.deptId);
        if (saved) {
            const pos = JSON.parse(saved);
            dept.style.position = 'absolute';
            dept.style.left = pos.left + 'px';
            dept.style.top = pos.top + 'px';
            dept.style.margin = '0';
        }
    });

    let isPanning = false,
        startX, startY, scrollLeft, scrollTop;
    wrap.addEventListener('mousedown', e => {
        if (e.target.closest('.hierarchy-dept')) return;
        isPanning = true;
        startX = e.pageX - wrap.offsetLeft;
        startY = e.pageY - wrap.offsetTop;
        scrollLeft = wrap.scrollLeft;
        scrollTop = wrap.scrollTop;
    });
    wrap.addEventListener('mousemove', e => {
        if (!isPanning) return;
        e.preventDefault();
        const x = e.pageX - wrap.offsetLeft;
        const y = e.pageY - wrap.offsetTop;
        wrap.scrollLeft = scrollLeft - (x - startX);
        wrap.scrollTop = scrollTop - (y - startY);
    });
    ['mouseup', 'mouseleave'].forEach(ev => wrap.addEventListener(ev, () => {
        isPanning = false;
    }));

    let dragDept = null,
        offX, offY;
    canvas.querySelectorAll('.hierarchy-dept').forEach(dept => {
        dept.addEventListener('mousedown', e => {
            dragDept = dept;
            const rect = dept.getBoundingClientRect();
            offX = e.clientX - rect.left;
            offY = e.clientY - rect.top;
            dept.style.position = 'absolute';
            dept.style.left = (dept.offsetLeft) + 'px';
            dept.style.top = (dept.offsetTop) + 'px';
            dept.style.margin = '0';
            dept.style.transition = 'none';
            dept.style.zIndex = '10';
        });

        document.addEventListener('mousemove', e => {
            if (!dragDept) return;
            const wrapRect = wrap.getBoundingClientRect();
            const left = e.clientX - wrapRect.left - offX + wrap.scrollLeft;
            const top = e.clientY - wrapRect.top - offY + wrap.scrollTop;
            dragDept.style.left = Math.max(0, left) + 'px';
            dragDept.style.top = Math.max(0, top) + 'px';
            drawLines();
        });

        document.addEventListener('mouseup', () => {
            if (dragDept) {
                const id = dragDept.dataset.deptId;
                const left = parseInt(dragDept.style.left) || 0;
                const top = parseInt(dragDept.style.top) || 0;
                localStorage.setItem('hierarchy_pos_' + id, JSON.stringify({
                    left,
                    top
                }));
            }
            dragDept = null;
        });
    });

    function drawLines() {
        const depts = canvas.querySelectorAll('.hierarchy-dept');
        const deptMap = {};
        depts.forEach(d => {
            deptMap[d.dataset.deptId] = d;
        });

        let svgContent = '';
        const crossData = [];

        depts.forEach(d => {
            const cross = d.dataset.cross;
            if (!cross || cross === '[]') return;
            const entries = JSON.parse(cross);
            entries.forEach(entry => {
                const name = typeof entry === 'string' ? entry : entry.name;
                const type = typeof entry === 'string' ? 'member_of' : (entry.type || 'member_of');
                for (const el of Object.values(deptMap)) {
                    if (el.dataset.name === name) {
                        crossData.push({
                            from: d,
                            to: el,
                            type
                        });
                        break;
                    }
                }
            });
        });

        const wrapRect = wrap.getBoundingClientRect();

        const pairMap = {};
        crossData.forEach(item => {
            const key = [item.from.dataset.deptId, item.to.dataset.deptId].sort().join('-');
            if (!pairMap[key]) pairMap[key] = [];
            pairMap[key].push(item);
        });

        Object.values(pairMap).forEach(pairs => {
            const isBi = pairs.length === 2;
            const items = isBi ? [pairs[0]] : pairs;
            items.forEach(({
                from,
                to,
                type
            }, idx) => {
                const fromRect = from.getBoundingClientRect();
                const toRect = to.getBoundingClientRect();

                const x1 = fromRect.left - wrapRect.left + fromRect.width / 2 + wrap.scrollLeft;
                const y1 = fromRect.top - wrapRect.top + fromRect.height / 2 + wrap.scrollTop;
                const x2 = toRect.left - wrapRect.left + toRect.width / 2 + wrap.scrollLeft;
                const y2 = toRect.top - wrapRect.top + toRect.height / 2 + wrap.scrollTop;

                const dx = x2 - x1;
                const dy = y2 - y1;

                let cx1 = x1 + dx * 0.4;
                let cy1 = y1;
                let cx2 = x2 - dx * 0.4;
                let cy2 = y2;

                const pathId = 'p' + from.dataset.deptId + '_' + to.dataset.deptId + '_' + idx;
                svgContent += `<path id="${pathId}" d="M${x1},${y1} C${cx1},${cy1} ${cx2},${cy2} ${x2},${y2}" fill="none" stroke="var(--secondary-color-4)" stroke-width="2" opacity="0.7" marker-end="url(#arrow)"/>`;

                const mx = 0.125 * x1 + 0.375 * cx1 + 0.375 * cx2 + 0.125 * x2;
                const my = 0.125 * y1 + 0.375 * cy1 + 0.375 * cy2 + 0.125 * y2;
                let label;
                if (isBi) {
                    const t1 = pairs[0].type,
                        t2 = pairs[1].type;
                    if (t1 === t2) {
                        label = t1 === 'head_of' ? 'mutual Faculty Heads' : 'mutual Faculty Members';
                    } else {
                        label = 'Faculty Head / Member';
                    }
                } else {
                    label = type === 'head_of' ? '\u2192 is also Faculty Head' : '\u2192 is a Faculty Member';
                }

                const lw = Math.max(140, label.length * 7 + 20);
                const lh = 20;
                svgContent += `<rect x="${mx - lw/2}" y="${my - lh/2}" width="${lw}" height="${lh}" rx="4" fill="#f9edfa" opacity="0.92" pointer-events="none"/>`;
                svgContent += `<text x="${mx}" y="${my + 4}" text-anchor="middle" font-size="10" font-weight="600" fill="var(--secondary-color-1)" pointer-events="none">${label}</text>`;
            });
        });

        linesSvg.innerHTML = svgContent;
        linesSvg.style.width = canvas.scrollWidth + 'px';
        linesSvg.style.height = canvas.scrollHeight + 'px';
    }

    setTimeout(drawLines, 100);
    window.addEventListener('resize', drawLines);
    wrap.addEventListener('scroll', drawLines);
    const origMouseUp = document.addEventListener('mouseup', function redraw() {
        setTimeout(drawLines, 50);
    });
})();

function toggleHierarchyMaximize() {
    const section = document.getElementById('hierarchySection');
    const btn = document.getElementById('hierarchyToggleBtn');
    if (!section || !btn) return;
    const isMax = section.classList.toggle('hierarchy-maximized');
    btn.innerHTML = isMax ? '<i class="bi bi-arrows-collapse"></i>' : '<i class="bi bi-arrows-expand"></i>';
    setTimeout(() => window.dispatchEvent(new Event('resize')), 100);
}

const SCHEDULES = window.lumiSchedules || {};
const DAYS_ENUM = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

let calDate = new Date();

function renderCalendar() {
    const year = calDate.getFullYear();
    const month = calDate.getMonth();
    const today = new Date();

    document.getElementById('cal-month-label').textContent = `${MONTHS[month]} ${year}`;

    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();

    const container = document.getElementById('cal-days');
    container.innerHTML = '';

    for (let i = 0; i < firstDay; i++) {
        const blank = document.createElement('div');
        blank.className = 'cal-day empty';
        container.appendChild(blank);
    }

    for (let d = 1; d <= daysInMonth; d++) {
        const cell = document.createElement('div');
        cell.className = 'cal-day';

        const dateObj = new Date(year, month, d);
        const dayName = DAYS_ENUM[dateObj.getDay()];
        const hasSchedule = SCHEDULES[dayName] && SCHEDULES[dayName].length > 0;

        if (hasSchedule) cell.classList.add('has-schedule');
        if (d === today.getDate() && month === today.getMonth() && year === today.getFullYear()) {
            cell.classList.add('today');
        }

        cell.textContent = d;
        cell.addEventListener('click', () => showSchedule(d, dayName, cell));
        container.appendChild(cell);
    }
}

function showSchedule(day, dayName, cell) {
    const overlay = document.getElementById('calDayOverlay');
    const header = document.getElementById('calDayOverlayHeader');
    const body = document.getElementById('calDayOverlayBody');

    const schedules = SCHEDULES[dayName] || [];
    header.textContent = `${dayName} \u2014 ${MONTHS[calDate.getMonth()]} ${day}`;

    if (schedules.length === 0) {
        body.innerHTML = '<p class="cal-no-sched">No schedules for this day.</p>';
    } else {
        body.innerHTML = schedules.map(s => {
            let extBadge = '';
            if (s.ext_status) {
                const extStatus = s.ext_status;
                let badgeCls = 'ext-badge';
                let badgeIcon = '';
                if (extStatus === 'pending') {
                    badgeCls = 'badge-ext-pending';
                    badgeIcon = ' <i class="bi bi-hourglass-bottom"></i>';
                } else if (extStatus === 'approved') {
                    badgeCls = 'badge-ext-approved';
                    badgeIcon = ' <i class="bi bi-check-circle"></i>';
                } else if (extStatus === 'rejected') {
                    badgeCls = 'badge-ext-rejected';
                    badgeIcon = ' <i class="bi bi-x-circle"></i>';
                }
                extBadge = ` <span class="${badgeCls}" style="font-size:11px;padding:2px 8px;display:inline-flex;align-items:center;gap:2px;">${badgeIcon} extended</span>`;
            }
            const displayEnd = s.extended_until && s.ext_status
                ? s.extended_until.slice(0,5) + extBadge
                : s.end_time.slice(0,5);
            return `
            <div class="cal-sched-item">
                <div class="cal-sched-room"><i class="bi bi-door-open"></i> <span>${s.room_name}</span></div>
                <div class="cal-sched-time">
                    <i class="bi bi-clock"></i> Schedule: <span>${s.start_time.slice(0,5)} \u2013 ${displayEnd}</span>
                </div>
                <div class="cal-sched-faculty"><i class="bi bi-people"></i> Faculty: <span>${s.first_name ? s.first_name + ' ' + s.last_name : 'No faculty assigned'}</span></div>
            </div>
        `;
        }).join('');
    }

    const isOpen = overlay.classList.contains('open') && overlay.dataset.day === String(day);
    document.querySelectorAll('.cal-day').forEach(c => c.classList.remove('selected'));

    if (isOpen) {
        overlay.classList.remove('open');
        overlay.dataset.day = '';
        return;
    }

    const cellRect = cell.getBoundingClientRect();
    const overlayW = 220;
    let top = cellRect.top - 10;
    let left = cellRect.left + cellRect.width / 2;

    if (left + overlayW > window.innerWidth - 8) {
        left = window.innerWidth - overlayW - 8;
    }
    if (left < 8) left = 8;
    if (top < 8) top = 8;

    overlay.style.top = top + 'px';
    overlay.style.left = left + 'px';
    overlay.style.transformOrigin = 'top left';
    overlay.dataset.day = day;

    void overlay.offsetWidth;
    overlay.classList.add('open');
    cell.classList.add('selected');

    overlay.onmouseleave = () => {
        overlay.classList.remove('open');
        overlay.dataset.day = '';
        cell.classList.remove('selected');
        overlay.onmouseleave = null;
    };
}

document.getElementById('cal-prev').addEventListener('click', () => {
    calDate.setMonth(calDate.getMonth() - 1);
    renderCalendar();
    document.getElementById('calDayOverlay').classList.remove('open');
});
document.getElementById('cal-next').addEventListener('click', () => {
    calDate.setMonth(calDate.getMonth() + 1);
    renderCalendar();
    document.getElementById('calDayOverlay').classList.remove('open');
});

renderCalendar();

function showHierarchyOverlay(element) {
    const overlay = document.getElementById('hierarchyOverlay');
    const header = document.getElementById('hierarchyOverlayHeader');
    const body = document.getElementById('hierarchyOverlayBody');

    if (overlay.classList.contains('open')) {
        overlay.classList.remove('open');
        return;
    }

    const data = JSON.parse(element.dataset.overlay);
    const type = element.dataset.type;

    if (type === 'dept') {
        header.textContent = data.name;
        let html = '';
        if (data.created_at) html += `<div class="h-info"><span class="h-info-label">Created:</span> ${data.created_at}</div>`;
        if (data.subjects && data.subjects.length) html += `<div class="h-info"><span class="h-info-label">Subjects:</span> ${data.subjects.join(', ')}</div>`;
        if (data.subject_areas && data.subject_areas.length) html += `<div class="h-info"><span class="h-info-label">Subject Areas:</span> ${data.subject_areas.join(', ')}</div>`;
        body.innerHTML = html || '<p class="cal-no-sched">No details available.</p>';
    } else if (type === 'head') {
        header.textContent = data.name;
        let html = '';
        if (data.approved_at) html += `<div class="h-info"><span class="h-info-label">Approved:</span> ${data.approved_at}</div>`;
        if (data.cross_depts && data.cross_depts.length) {
            const deptNames = data.cross_depts.map(d => d.name || d).join(', ');
            html += `<div class="h-info"><span class="h-info-label">Also in:</span> ${deptNames}</div>`;
        }
        if (data.subject_areas && data.subject_areas.length) html += `<div class="h-info"><span class="h-info-label">Coverage (Subject Areas):</span> ${data.subject_areas.join(', ')}</div>`;
        body.innerHTML = html || '<p class="cal-no-sched">No details available.</p>';
    } else if (type === 'member') {
        header.textContent = data.name;
        let html = '';
        if (data.approved_at) html += `<div class="h-info"><span class="h-info-label">Approved:</span> ${data.approved_at}</div>`;
        if (data.cross_depts && data.cross_depts.length) html += `<div class="h-info"><span class="h-info-label">Also in:</span> ${data.cross_depts.join(', ')}</div>`;
        if (data.subjects && data.subjects.length) html += `<div class="h-info"><span class="h-info-label">Subjects:</span> ${data.subjects.join(', ')}</div>`;
        if (data.subject_areas && data.subject_areas.length) html += `<div class="h-info"><span class="h-info-label">Subject Areas:</span> ${data.subject_areas.join(', ')}</div>`;
        body.innerHTML = html || '<p class="cal-no-sched">No details available.</p>';
    }

    const rect = element.getBoundingClientRect();
    const overlayW = 220;
    let top = rect.top - 10;
    let left = rect.left + rect.width / 2;
    if (left + overlayW > window.innerWidth - 8) left = window.innerWidth - overlayW - 8;
    if (left < 8) left = 8;
    if (top < 8) top = 8;

    overlay.style.top = top + 'px';
    overlay.style.left = left + 'px';
    overlay.style.transformOrigin = 'top left';
    void overlay.offsetWidth;
    overlay.classList.add('open');

    overlay.onmouseleave = () => {
        overlay.classList.remove('open');
        overlay.onmouseleave = null;
    };
}

document.querySelectorAll('.hierarchy-dept-node, .hierarchy-head-node, .hierarchy-member-node').forEach(el => {
    el.addEventListener('click', e => {
        e.stopPropagation();
        closeAllOverlaysExcept('hierarchyOverlay');
        showHierarchyOverlay(el);
    });
});

function closeAllOverlaysExcept(keepId) {
    document.querySelectorAll('.cal-day-overlay.open').forEach(o => {
        if (o.id !== keepId) o.classList.remove('open');
    });
}

function getActivityIcon(log) {
    const evt = log.event_type || log.action || '';
    const type = log.log_type || 'room';

    const iconMap = {
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
        'faculty_approved': ['bi-person-check-fill', '#198754', '#d1e7dd'],
        'faculty_rejected': ['bi-person-x-fill', '#842029', '#f8d7da'],
        'faculty_pending': ['bi-person-plus', '#664d03', '#fff3cd'],
        'extension_approved': ['bi-clock-history', '#084298', '#cfe2ff'],
        'extension_rejected': ['bi-clock-fill', '#842029', '#f8d7da'],
        'admin_login': ['bi-box-arrow-in-right', '#055160', '#cff4fc'],
        'issue_raised': ['bi-exclamation-triangle-fill', '#842029', '#f8d7da'],
        'issue_resolved': ['bi-check-circle-fill', '#198754', '#d1e7dd'],
        'admin_action': ['bi-shield-check', '#084298', '#cfe2ff'],
    };
    const def = ['bi-clock-history', '#5a5a5a', '#e9ecef'];
    const [icon, color, bg] = iconMap[evt] || def;

    const typeMap = {
        'room': ['#f9edfa', '#2f004f', 'Room'],
        'admin': ['#2f004f', '#f9edfa', 'Admin'],
        'admin_login': ['#cff4fc', '#055160', 'Login'],
    };
    const [typeBg, typeClr, typeLabel] = typeMap[type] || typeMap['room'];

    const label = evt.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

    return {
        icon,
        color,
        bg,
        label,
        typeBg,
        typeClr,
        typeLabel,
        notes: log.notes || ''
    };
}

function formatTime(timeStr) {
    const d = new Date(timeStr.replace(' ', 'T') + '+08:00');
    const hours = d.getHours(),
        mins = d.getMinutes();
    const ampm = hours >= 12 ? 'PM' : 'AM';
    const h12 = hours % 12 || 12;
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return h12 + ':' + String(mins).padStart(2, '0') + ' ' + ampm + ', ' + months[d.getMonth()] + ' ' + d.getDate();
}

async function pollAdminDashboard() {
    try {
        const res = await fetch('../../api/admin-status.php');
        if (!res.ok) return;
        const data = await res.json();
        if (!data.success) return;

        const lightsEl = document.querySelector('.stat-card:nth-child(2) .stat-value');
        const pendingEl = document.querySelector('.stat-card:nth-child(3) .stat-value');
        const extEl = document.querySelector('.stat-card:nth-child(4) .stat-value');
        if (lightsEl) lightsEl.textContent = data.lights_on;
        if (pendingEl) pendingEl.textContent = data.pending;
        if (extEl) extEl.textContent = data.ext_pending;

        const roomList = document.getElementById('rooms-list');
        if (roomList && data.classrooms) {
            roomList.innerHTML = data.classrooms.map(c => {
                const on = c.light_status === 'on';
                const detail = JSON.stringify({
                    room_name: c.room_name,
                    room_size: c.room_size || 'N/A',
                    description: c.description || '',
                    light_status: c.light_status
                }).replace(/'/g, '&#39;');
                return `
            <div class="room-item" data-type="room" data-detail='${detail}'>
                <i class="bi bi-door-open room-icon"></i>
                <div class="room-info">
                    <div class="d-flex align-items-center gap-2">
                        <h5 class="mb-0" style="font-size: 14.5px;">${c.room_name}</h5>
                        <span style="font-size:10px; padding:2px 8px; border-radius:20px; font-weight:600;
                            background:${on ? '#d1e7dd' : '#f8d7da'};
                            color:${on ? '#0f5132' : '#842029'};">
                            ${on ? 'ON' : 'OFF'}
                        </span>
                    </div>
                    <p class="room-size mb-0" style="font-size:13.5px; color:var(--muted-dark);">
                        Room size: <span>${c.room_size.charAt(0).toUpperCase() + c.room_size.slice(1)}</span> room
                    </p>
                </div>
            </div>
        `;
            }).join('');
        }

        const deptList = document.getElementById('depts-list');
        if (deptList && data.departments) {
            deptList.innerHTML = data.departments.map(d => {
                const detail = JSON.stringify({
                    name: d.name,
                    head_name: d.head_name || 'Unassigned',
                    status: d.status || 'active',
                    subject_areas: d.subject_areas || [],
                    subjects: d.subjects || [],
                    member_count: d.member_count || 0
                }).replace(/'/g, '&#39;');
                return `
            <div class="room-item" data-type="dept-list" data-detail='${detail}'>
                <i class="bi bi-diagram-3 room-icon"></i>
                <div class="room-info">
                    <h5 class="mb-0" style="font-size: 14.5px;">${d.name}</h5>
                    <p class="room-size mb-0" style="font-size:13.5px; color:var(--muted-dark);">
                        Head: <span>${d.head_name || 'Unassigned'}</span>
                    </p>
                </div>
            </div>
        `;
            }).join('');
        }

        const facultyList = document.getElementById('faculty-list');
        if (facultyList && data.faculty_members) {
            facultyList.innerHTML = data.faculty_members.map(f => {
                const d = new Date(f.date_shown + 'T00:00:00');
                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                const dateStr = months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
                const detail = JSON.stringify({
                    name: f.first_name + ' ' + f.last_name,
                    date_shown: f.date_shown || ''
                }).replace(/'/g, '&#39;');
                return `
            <div class="room-item" data-type="faculty" data-detail='${detail}'>
                <i class="bi bi-person-badge room-icon"></i>
                <div class="room-info">
                    <h5 class="mb-0" style="font-size: 14.5px;">${f.first_name} ${f.last_name}</h5>
                    <p class="room-size mb-0" style="font-size:13.5px; color:var(--muted-dark);">
                        Approved: <span>${dateStr}</span>
                    </p>
                </div>
            </div>
        `;
            }).join('');
        }

        const activityList = document.getElementById('activityTimeline');
        if (activityList && data.logs) {
            activityList.innerHTML = data.logs.map(log => {
                const iconData = getActivityIcon(log);
                return `
                <div class="timeline-item">
                    <div class="tl-icon" style="background:${iconData.bg}; color:${iconData.color};">
                        <i class="bi ${iconData.icon}"></i>
                    </div>
                    <div class="tl-body">
                        <p class="tl-action" style="font-size:14px; font-weight: 600;">
                            ${iconData.label}
                            ${log.room_name ? '&mdash; <span style="color:var(--secondary-color-3);">' + log.room_name + '</span>' : ''}
                            <span class="tl-type-badge" style="background:${iconData.typeBg}; color:${iconData.typeClr};">${iconData.typeLabel}</span>
                        </p>
                        <div class="tl-meta" style="display: flex; flex-wrap: wrap; row-gap: 4px; width: 100%;">
                            <span style="width: 100%;"><i class="bi bi-clock"></i> ${formatTime(log.event_time)}</span>
                            ${log.admin_name ? '<span style="width: 100%; margin-top: 2px;"><i class="bi bi-person"></i> ' + log.admin_name + '</span>' : ''}
                            ${(!log.admin_name && log.triggered_by) ? '<span style="width: 100%; margin-top: 2px;"><i class="bi bi-person"></i> ' + log.triggered_by + '</span>' : ''}
                            
                        </div>
                        ${iconData.notes ? '<span class="tl-notes"><i class="bi bi-chat-left-text me-1"></i>' + iconData.notes + '</span>' : ''}
                    </div>
                </div>`;
            }).join('');
        }

    } catch (e) {
        console.warn('pollAdminDashboard error:', e);
    }
}

pollAdminDashboard();
setInterval(pollAdminDashboard, 5000);

function openDetailModal(roomItem) {
    try {
        var data = JSON.parse(roomItem.dataset.detail);
    } catch(e) { return; }
    var type = roomItem.dataset.type;
    var modal = document.getElementById('detailModal');
    var titleMap = { 'room': 'Room Detail', 'dept-list': 'Department Detail', 'faculty': 'Faculty Detail' };
    document.getElementById('detailModalLabel').textContent = titleMap[type] || 'Detail';
    var body = modal.querySelector('.modal-body');
    if (type === 'room') {
        body.innerHTML =
            '<div class="detail-row"><span class="detail-label">Room Name</span><span>' + (data.room_name || 'N/A') + '</span></div>' +
            '<div class="detail-row"><span class="detail-label">Room Size</span><span>' + (data.room_size || 'N/A') + '</span></div>' +
            '<div class="detail-row"><span class="detail-label">Description</span><span>' + (data.description || 'N/A') + '</span></div>' +
            '<div class="detail-row"><span class="detail-label">Light Status</span><span style="color:' + (data.light_status === 'on' ? '#198754' : '#842029') + ';font-weight:600;">' + (data.light_status || '').toUpperCase() + '</span></div>';
    } else if (type === 'dept-list') {
        body.innerHTML =
            '<div class="detail-row"><span class="detail-label">Department</span><span>' + (data.name || 'N/A') + '</span></div>' +
            '<div class="detail-row"><span class="detail-label">Head</span><span>' + (data.head_name || 'N/A') + '</span></div>' +
            '<div class="detail-row"><span class="detail-label">Status</span><span>' + (data.status || 'N/A') + '</span></div>' +
            '<div class="detail-row"><span class="detail-label">Subject Areas</span><span>' + (data.subject_areas && data.subject_areas.length ? data.subject_areas.join(', ') : 'None') + '</span></div>' +
            '<div class="detail-row"><span class="detail-label">Subjects</span><span>' + (data.subjects && data.subjects.length ? data.subjects.join(', ') : 'None') + '</span></div>' +
            '<div class="detail-row"><span class="detail-label">Faculty Members</span><span>' + (data.member_count != null ? data.member_count : '0') + '</span></div>';
    } else if (type === 'faculty') {
        body.innerHTML =
            '<div class="detail-row"><span class="detail-label">Name</span><span>' + (data.name || 'N/A') + '</span></div>' +
            '<div class="detail-row"><span class="detail-label">Approved</span><span>' + (data.date_shown || 'N/A') + '</span></div>';
    }
    new bootstrap.Modal(modal).show();
}

document.getElementById('rooms-list').addEventListener('click', function(e) {
    var item = e.target.closest('.room-item');
    if (item) openDetailModal(item);
});
document.getElementById('depts-list').addEventListener('click', function(e) {
    var item = e.target.closest('.room-item');
    if (item) openDetailModal(item);
});
document.getElementById('faculty-list').addEventListener('click', function(e) {
    var item = e.target.closest('.room-item');
    if (item) openDetailModal(item);
});
