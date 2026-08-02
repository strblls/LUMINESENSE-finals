/* ═══════════════════════════════════════════════════════════════════════
   js/admin/admin-overview.js — Combined "Rooms & Analytics" page
   Drives the static preview. When STATIC_MODE is removed (after "Banana"),
   ROOMS/SUMMARY/CHART_* will come from live queries but this wiring stays.
   ═══════════════════════════════════════════════════════════════════════ */

// ── State ────────────────────────────────────────────────────────────────
let currentRoomId = null;
let currentPeriod = 7;
let currentMetric = 'all';
let lineChartInstance = null;
let barChartInstance = null;
const sparkCharts = {};

const COLORS = {
    voltage: '#2f004f',
    current: '#0d9488',
    power: '#f59e0b',
    energy: '#58078f',
    lineFill: 'rgba(88, 7, 143, 0.10)',
};

// ── Sparkline helper ────────────────────────────────────────────────────
function drawSpark(canvasId, data, color) {
    const el = document.getElementById(canvasId);
    if (!el || !window.Chart) return;
    if (sparkCharts[canvasId]) sparkCharts[canvasId].destroy();
    const flat = (data || []).map(Number);
    sparkCharts[canvasId] = new Chart(el, {
        type: 'line',
        data: {
            labels: flat.map((_, i) => i),
            datasets: [{
                data: flat,
                borderColor: color || COLORS.energy,
                backgroundColor: COLORS.lineFill,
                borderWidth: 1.5,
                pointRadius: 0,
                fill: true,
                tension: 0.35,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            scales: { x: { display: false }, y: { display: false } },
            plugins: { legend: { display: false }, tooltip: { enabled: false } },
        },
    });
}

// ── Draw all summary + per-room sparklines ──────────────────────────────
function drawAllSparks() {
    const s = SPARK_SUMMARY || {};
    const mapping = {
        sumEnergy: { key: 'energy', color: COLORS.energy },
        sumMinutes: { key: 'minutes', color: '#0d9488' },
        sumVoltage: { key: 'voltage', color: COLORS.voltage },
        sumCurrent: { key: 'current', color: '#0d9488' },
        sumPower: { key: 'power', color: '#f59e0b' },
        sumCost: { key: 'cost', color: '#c0004e' },
    };
    Object.keys(mapping).forEach(id => {
        drawSpark('sumSpark_' + id, s[mapping[id].key], mapping[id].color);
    });
    (ROOMS || []).forEach(room => {
        drawSpark('sparkCanvas' + room.id, room.spark, room.is_live ? '#16a34a' : '#9f9f9f');
    });
}

// ── Live readings ────────────────────────────────────────────────────────
function liveRooms() {
    return (ROOMS || []).filter(r => r.is_live);
}

function renderLiveReadings() {
    let src = null;
    if (currentRoomId) {
        src = (ROOMS || []).find(r => r.id == currentRoomId) || null;
    }
    let v = null, a = null, w = null, e = null, on = false;
    if (src) {
        v = src.voltage_v; a = src.current_a; w = src.power_w; e = src.energy_wh;
        on = src.light_status === 'on' || src.row1_status === 'on' || src.row2_status === 'on' || src.row3_status === 'on';
    } else {
        const live = liveRooms();
        const l = live.length;
        if (l) {
            v = live.reduce((s, r) => s + (r.voltage_v || 0), 0) / l;
            a = live.reduce((s, r) => s + (r.current_a || 0), 0) / l;
            w = live.reduce((s, r) => s + (r.power_w || 0), 0);
            e = live.reduce((s, r) => s + (r.energy_wh || 0), 0);
            on = live.some(r => r.light_status === 'on' || r.row1_status === 'on' || r.row2_status === 'on' || r.row3_status === 'on');
        }
    }
    const set = (id, txt) => { const el = document.getElementById(id); if (el) el.textContent = txt; };
    set('liveVoltage', v !== null ? v.toFixed(1) + ' V' : '\u2014 V');
    set('liveCurrent', a !== null ? a.toFixed(3) + ' A' : '\u2014 A');
    set('livePower', w !== null ? w.toFixed(1) + ' W' : '\u2014 W');
    set('liveEnergy', e !== null ? e.toFixed(3) + ' Wh' : '\u2014 Wh');
    set('liveStatus', on ? 'ON' : 'OFF');
    const dot = document.getElementById('liveStatusDot');
    if (dot) { dot.className = 'live-status-dot ' + (on ? 'on' : 'off'); }
}

// ── Main charts ──────────────────────────────────────────────────────────
function buildLineChart(labels, rows) {
    const ctx = document.getElementById('lineChart');
    if (!ctx || !window.Chart) return;
    if (lineChartInstance) lineChartInstance.destroy();
    const datasets = [];
    if (currentMetric === 'all' || currentMetric === 'voltage') {
        datasets.push({ label: 'Voltage (V)', data: rows.map(r => r.avg_voltage), borderColor: COLORS.voltage, backgroundColor: 'rgba(47,0,79,.06)', tension: 0.35, pointRadius: 2 });
    }
    if (currentMetric === 'all' || currentMetric === 'current') {
        datasets.push({ label: 'Current (A)', data: rows.map(r => r.avg_current), borderColor: COLORS.current, backgroundColor: 'rgba(13,148,136,.06)', tension: 0.35, pointRadius: 2 });
    }
    if (currentMetric === 'all' || currentMetric === 'power') {
        datasets.push({ label: 'Power (W)', data: rows.map(r => r.avg_power), borderColor: COLORS.power, backgroundColor: 'rgba(245,158,11,.06)', tension: 0.35, pointRadius: 2 });
    }
    lineChartInstance = new Chart(ctx, {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            scales: { x: { grid: { display: false } }, y: { beginAtZero: false } },
            plugins: { legend: { position: 'top', labels: { boxWidth: 12, font: { size: 10 } } } },
        },
    });
}

function buildBarChart(labels, rows) {
    const ctx = document.getElementById('barChart');
    if (!ctx || !window.Chart) return;
    if (barChartInstance) barChartInstance.destroy();
    barChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Energy (Wh)',
                data: rows.map(r => r.energy_wh),
                backgroundColor: 'rgba(88, 7, 143, 0.65)',
                borderRadius: 4,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            scales: { x: { grid: { display: false } }, y: { beginAtZero: true } },
            plugins: { legend: { display: false } },
        },
    });
}

function updateMainCharts() {
    const isToday = currentPeriod === 1;
    const rows = isToday ? (CHART_TODAY || []) : (CHART_DAILY || []);
    const labels = rows.map(r => r.label);
    buildLineChart(labels, rows);
    buildBarChart(labels, rows);
    const lm = document.getElementById('lineMetricLabel');
    if (lm) lm.textContent = currentMetric === 'all' ? 'All Metrics' : currentMetric.charAt(0).toUpperCase() + currentMetric.slice(1);
    const bm = document.getElementById('barMetricLabel');
    if (bm) bm.textContent = currentMetric === 'all' ? 'All Metrics' : currentMetric.charAt(0).toUpperCase() + currentMetric.slice(1);
}

// ── History table ────────────────────────────────────────────────────────
function renderHistoryTable() {
    const thead = document.getElementById('historyHead');
    const tbody = document.getElementById('historyBody');
    const tfoot = document.getElementById('historyFoot');
    const title = document.getElementById('historyTitle');
    if (!thead || !tbody) return;

    const isToday = currentPeriod === 1;
    if (title) title.textContent = isToday ? "Today's History" : currentPeriod + '-Day History';

    if (isToday) {
        thead.innerHTML = '<tr><th style="text-align:left;">Time</th><th>Energy (Wh)</th><th>Voltage (V)</th><th>Current (A)</th><th>Power (W)</th></tr>';
        const rows = CHART_TODAY || [];
        tbody.innerHTML = rows.length
            ? rows.map(r => '<tr><td>' + r.time + '</td><td>' + r.energy_wh.toFixed(4) + '</td><td>' + r.avg_voltage.toFixed(1) + '</td><td>' + r.avg_current.toFixed(3) + '</td><td>' + r.avg_power.toFixed(1) + '</td></tr>').join('')
            : '<tr><td colspan="5" class="text-center text-muted">No readings recorded today.</td></tr>';
        tfoot.innerHTML = '';
    } else {
        thead.innerHTML = '<tr><th style="text-align:left;">Date</th><th>Sessions</th><th>Occupied Time</th><th>Energy (Wh)</th><th>Energy (kWh)</th></tr>';
        const rows = CHART_DAILY || [];
        tbody.innerHTML = rows.map(r => {
            const hrs = r.minutes ? (r.minutes / 60).toFixed(1) : '0.0';
            return '<tr><td>' + r.label + '</td><td>' + r.sessions + '</td><td>' + hrs + ' hrs</td><td>' + r.energy_wh.toFixed(2) + '</td><td>' + r.energy_kwh.toFixed(4) + '</td></tr>';
        }).join('');
        const totalWh = rows.reduce((s, r) => s + r.energy_wh, 0);
        tfoot.innerHTML = '<tr><td>Total</td><td colspan="2">' + (SUMMARY ? SUMMARY.total_minutes / 60 : 0).toFixed(1) + ' hrs</td><td>' + totalWh.toFixed(2) + '</td><td>' + (totalWh / 1000).toFixed(4) + '</td></tr>';
    }
}

// ── Period / Metric ──────────────────────────────────────────────────────
function setPeriod(el, days) {
    document.querySelectorAll('#panelPeriod .dept-member-filter-item').forEach(i => i.classList.remove('active'));
    if (el) el.classList.add('active');
    currentPeriod = parseInt(days, 10);
    updateMainCharts();
    renderHistoryTable();
    closePanel('panelPeriod');
}

function setMetric(el, metric) {
    document.querySelectorAll('#panelMetric .dept-member-filter-item').forEach(i => i.classList.remove('active'));
    if (el) el.classList.add('active');
    currentMetric = metric;
    updateMainCharts();
    // Live card emphasis
    document.querySelectorAll('#vawGroup .live-stat-card').forEach(c => {
        c.classList.remove('metric-active', 'metric-dimmed');
        if (metric !== 'all') {
            if (c.getAttribute('data-metric') === metric) c.classList.add('metric-active');
            else c.classList.add('metric-dimmed');
        }
    });
    closePanel('panelMetric');
}

// ── Room selection ───────────────────────────────────────────────────────
function selectRoom(id, silent) {
    const rid = parseInt(id, 10);
    if (currentRoomId === rid) {
        deselectRoom();
        return;
    }
    currentRoomId = rid;
    document.querySelectorAll('.spark-card, .room-card').forEach(c => {
        c.classList.toggle('active-room', c.getAttribute('data-room-id') == rid);
    });
    const room = (ROOMS || []).find(r => r.id == rid);
    const sub = document.getElementById('tabSubheading');
    if (sub) sub.textContent = (room ? room.room_name : 'Room') + ' Selected';
    renderLiveReadings();
}

function deselectRoom() {
    currentRoomId = null;
    document.querySelectorAll('.spark-card, .room-card').forEach(c => c.classList.remove('active-room'));
    const sub = document.getElementById('tabSubheading');
    if (sub) sub.textContent = 'All Rooms Selected';
    renderLiveReadings();
}

// ── Filters (status / dept / subject / search) ──────────────────────────
function applyFilters() {
    const statusVal = (document.querySelector('#statusFilterMenu .filter-option.active') || {}).dataset?.value || '';
    const deptVal = (document.querySelector('#departmentFilterMenu .filter-option.active') || {}).dataset?.value || '';
    const subjVal = (document.querySelector('#subjectFilterMenu .filter-option.active') || {}).dataset?.value || '';
    const searchVal = ((document.getElementById('roomSearch') || {}).value || '').toLowerCase();

    document.querySelectorAll('.spark-card, .room-card').forEach(card => {
        let show = true;
        if (statusVal) show = show && (card.dataset.status || '') === statusVal.toLowerCase();
        if (deptVal) show = show && (card.dataset.departments || '').toLowerCase().includes(deptVal.toLowerCase());
        if (subjVal) show = show && (card.dataset.subjects || '').toLowerCase().includes(subjVal.toLowerCase());
        if (searchVal) {
            const roomMatch = (card.dataset.room || '').includes(searchVal);
            const facEl = card.querySelector('.spark-card-faculty, .room-info-val');
            const facMatch = facEl ? facEl.textContent.toLowerCase().includes(searchVal) : false;
            show = show && (roomMatch || facMatch);
        }
        card.style.display = show ? '' : 'none';
    });
}

function bindFilterMenu(menuId) {
    document.querySelectorAll('#' + menuId + ' .filter-option').forEach(opt => {
        opt.addEventListener('click', function (e) {
            e.preventDefault();
            const ul = this.closest('ul');
            ul.querySelectorAll('.filter-option').forEach(o => o.classList.remove('active'));
            this.classList.add('active');
            applyFilters();
        });
    });
}

// ── Export ───────────────────────────────────────────────────────────────
function exportCSV() {
    const rows = currentPeriod === 1 ? (CHART_TODAY || []) : (CHART_DAILY || []);
    if (!rows.length) { alert('No data to export.'); return; }
    let csv = currentPeriod === 1
        ? 'Time,Energy (Wh),Voltage (V),Current (A),Power (W)\n'
        : 'Date,Sessions,Occupied (hrs),Energy (Wh),Energy (kWh)\n';
    rows.forEach(r => {
        csv += currentPeriod === 1
            ? [r.time, r.energy_wh, r.avg_voltage, r.avg_current, r.avg_power].join(',') + '\n'
            : [r.label, r.sessions, (r.minutes / 60).toFixed(1), r.energy_wh, r.energy_kwh].join(',') + '\n';
    });
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'luminesense_overview.csv';
    a.click();
    URL.revokeObjectURL(url);
}

function exportPDF() {
    alert('PDF export is stubbed in the static preview. It will be wired to export-analytics-pdf.php after live data is connected.');
}

// ── Panel hover helpers ──────────────────────────────────────────────────
function closePanel(id) {
    const p = document.getElementById(id);
    if (p) p.classList.remove('show');
}

// ── Room modals ──────────────────────────────────────────────────────────
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

// ── Room details modal (static data) ─────────────────────────────────────
const alertIconMap = (type) => {
    const m = {
        'on': ['bi-lightbulb-fill', '#198754', '#d1e7dd'],
        'off': ['bi-lightbulb', '#842029', '#f8d7da'],
        'light_on': ['bi-lightbulb-fill', '#198754', '#d1e7dd'],
        'light_off': ['bi-lightbulb', '#842029', '#f8d7da'],
        'pir_motion': ['bi-person-bounding-box', '#084298', '#cfe2ff'],
        'pir_stopped': ['bi-person-bounding-box', '#5a5a5a', '#e9ecef'],
        'schedule': ['bi-calendar-check', '#198754', '#d1e7dd'],
        'class_start': ['bi-play-circle-fill', '#198754', '#d1e7dd'],
        'class_end': ['bi-stop-circle', '#664d03', '#fff3cd'],
        'door_open': ['bi-door-open-fill', '#664d03', '#fff3cd'],
        'door_close': ['bi-door-closed-fill', '#5a3a00', '#ffe5b4'],
        'issue_raised': ['bi-exclamation-triangle-fill', '#842029', '#f8d7da'],
        'issue_resolved': ['bi-check-circle-fill', '#198754', '#d1e7dd'],
    };
    return m[type] || ['bi-info-circle', '#6c757d', '#f8f9fa'];
};

function openRoomModal(id) {
    currentRoomId = parseInt(id, 10);
    const room = (ROOMS || []).find(r => r.id == id) || {};
    document.getElementById('roomModalLabel').textContent = room.room_name || 'Room Details';
    new bootstrap.Modal(document.getElementById('roomModal')).show();
    renderRoomModalFrom(room);
}

function renderRoomModalFrom(room) {
    const schedEl = document.getElementById('modalCurrentSched');
    if (room.status === 'occupied' && room.faculty_name) {
        schedEl.innerHTML = `<div class="d-flex align-items-start gap-3">
            <div class="avatar-icon d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;font-size:1rem;"><span class="bold">${initialsOf(room.faculty_name)}</span></div>
            <div style="flex:1;min-width:0;">
                <p class="bold mb-0" style="font-size:.9rem;">${escapeHtml(room.faculty_name)}</p>
                <small class="text-muted">Faculty Member</small>
                <div style="font-size:.9rem;font-weight:600;margin-top:.15rem;">${room.current_time || ''}</div>
                <div style="font-size:.82rem;margin-top:2px;"><span class="badge-occupied" style="padding:2px 8px;border-radius:10px;font-weight:700;font-size:10px;">OCCUPIED</span></div>
            </div>
        </div>`;
    } else if (room.status === 'scheduled' && room.next_time) {
        schedEl.innerHTML = `<div class="d-flex align-items-start gap-3">
            <div class="avatar-icon d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;font-size:1rem;background:#fff5d6;color:#a06800;"><i class="bi bi-calendar-event" style="font-size:1.2rem;"></i></div>
            <div style="flex:1;min-width:0;">
                <span style="display:inline-block;background:#fff5d6;color:#a06800;padding:2px 8px;border-radius:10px;font-weight:700;font-size:10px;margin-bottom:6px;">SCHEDULED</span>
                <div style="font-size:.9rem;font-weight:600;">${escapeHtml(room.next_time)}</div>
            </div>
        </div>`;
    } else {
        schedEl.innerHTML = `<div>
            <span style="background:#d6fbe9;color:#0a7a45;padding:2px 8px;border-radius:10px;font-weight:700;font-size:10px;">VACANT</span>
            <p class="text-muted mt-2 mb-0" style="font-size:.85rem;">No classes scheduled.</p>
        </div>`;
    }

    // Weekly timetable
    const todayEl = document.getElementById('modalTodaySched');
    const dayOrder = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const grouped = {};
    dayOrder.forEach(d => grouped[d] = []);
    (room.schedules || []).forEach(s => {
        if (grouped[s.day_of_week]) grouped[s.day_of_week].push(s);
    });
    const todayName = new Date().toLocaleDateString('en-US', { weekday: 'long' });
    todayEl.innerHTML = '<div class="weekly-schedule-grid" style="min-width:max-content;">' + dayOrder.map(day => {
        const slots = grouped[day] || [];
        const slotsHtml = slots.length
            ? slots.map(s => `<div class="slot-row">
                <div class="slot-time"><span class="slot-time-start">${escapeHtml(s.start_time)}</span><span class="slot-time-separator">TO</span><span class="slot-time-end">${escapeHtml(s.end_time)}</span></div>
                <div class="slot-content"><div class="slot-room"><i class="bi bi-person me-1"></i>${escapeHtml(s.faculty_name)}</div></div>
            </div>`).join('')
            : '<p class="no-sched">No classes scheduled.</p>';
        return `<div class="day-card${day === todayName ? ' today' : ''}"><div class="day-label">${day}${day === todayName ? ' · Today' : ''}</div>${slotsHtml}</div>`;
    }).join('') + '</div>';

    // Alerts
    const alerts = room.alerts || [];
    const previewEl = document.getElementById('modalAlertsPreview');
    if (alerts.length) {
        previewEl.innerHTML = alerts.map(a => {
            const icon = alertIconMap(a.event_type);
            const label = (a.event_type || '').replace(/_/g, ' ');
            return `<div class="modal-timeline-item">
                <div class="modal-tl-icon" style="background:${icon[2]};color:${icon[1]};"><i class="bi ${icon[0]}"></i></div>
                <div class="modal-tl-body">
                    <p class="modal-tl-action">${label.charAt(0).toUpperCase() + label.slice(1)}</p>
                    <div class="modal-tl-meta"><span><i class="bi bi-clock"></i> ${escapeHtml(a.event_time || '')}</span><span class="modal-tl-badge" style="background:${icon[2]};color:${icon[1]};">${escapeHtml(a.triggered_by || 'system')}</span></div>
                </div>
            </div>`;
        }).join('');
    } else {
        previewEl.innerHTML = '<div class="modal-slot-empty">No activity recorded for this room.</div>';
    }

    // Override toggles (local)
    rowState = {
        1: room.row1_status === 'on',
        2: room.row2_status === 'on',
        3: room.row3_status === 'on'
    };
    rowBulbs[1].forEach(i => setBulb(i, rowState[1]));
    rowBulbs[2].forEach(i => setBulb(i, rowState[2]));
    rowBulbs[3].forEach(i => setBulb(i, rowState[3]));
    ['1', '2', '3'].forEach(r => {
        const sw = document.getElementById('row' + r + 'sw');
        if (sw) sw.checked = rowState[r];
    });
    syncAllLightsLabel();
}

function initialsOf(name) {
    return (name || '').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase();
}

function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// ── Lighting override (local, static mode) ───────────────────────────────
let rowState = { 1: false, 2: false, 3: false };
const rowBulbs = { 1: [0, 1, 2], 2: [3, 4, 5], 3: [6, 7, 8] };

function setBulb(index, on) {
    const img = document.getElementById('bulb' + index);
    if (img) img.src = on ? '../../images/bulb-on.png' : '../../images/bulb-off.png';
}

function toggleRow(row, on) {
    rowState[row] = on;
    rowBulbs[row].forEach(i => setBulb(i, on));
    syncAllLightsLabel();
    sendLightingUpdate();
}

function toggleAllLights() {
    const anyOff = Object.values(rowState).some(v => !v);
    for (let row = 1; row <= 3; row++) {
        rowState[row] = anyOff;
        rowBulbs[row].forEach(i => setBulb(i, anyOff));
        const sw = document.getElementById('row' + row + 'sw');
        if (sw) sw.checked = anyOff;
    }
    syncAllLightsLabel();
    sendLightingUpdate();
}

function sendLightingUpdate() {
    // Static mode — mirror to the room cards only.
    updateCardLighting(currentRoomId, rowState[1], rowState[2], rowState[3]);
    renderLiveReadings();
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

function updateCardLighting(roomId, r1, r2, r3) {
    const map = { 1: r1, 2: r2, 3: r3 };
    document.querySelectorAll('.room-card[data-room-id="' + roomId + '"] .device-strip .row-bar, .spark-card[data-room-id="' + roomId + '"] .row-bar').forEach(bar => {
        const item = bar.closest('.row-bar-item');
        const idx = item ? parseInt(item.querySelector('.row-bar-label').textContent.replace(/\D/g, ''), 10) : 0;
        const on = !!map[idx];
        bar.classList.toggle('on', on);
        const st = item ? item.querySelector('.row-bar-state') : null;
        if (st) { st.classList.toggle('on', on); st.textContent = on ? 'ON' : 'OFF'; }
    });
}

// ── Init ─────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    drawAllSparks();
    renderLiveReadings();
    updateMainCharts();
    renderHistoryTable();

    // Room selection
    document.querySelectorAll('.spark-card').forEach(card => {
        card.addEventListener('click', () => selectRoom(card.getAttribute('data-room-id')));
    });
    document.querySelectorAll('.room-card').forEach(card => {
        card.addEventListener('click', (e) => {
            if (e.target.closest('.room-icons') || e.target.closest('.light')) return;
            selectRoom(card.getAttribute('data-room-id'));
        });
    });

    // Metric cards in live readings
    document.querySelectorAll('#vawGroup .live-stat-card[data-metric]').forEach(card => {
        card.addEventListener('click', function () {
            const isActive = this.classList.contains('metric-active');
            if (isActive) { setMetric(null, 'all'); return; }
            setMetric(this, this.getAttribute('data-metric'));
        });
    });

    // Filters
    ['statusFilterMenu', 'departmentFilterMenu', 'subjectFilterMenu'].forEach(bindFilterMenu);
    const searchEl = document.getElementById('roomSearch');
    if (searchEl) searchEl.addEventListener('input', applyFilters);

    // Panel hover
    const panels = ['panelGuide', 'panelStatus', 'panelSchedule', 'panelPeriod', 'panelMetric'];
    const timers = {};
    panels.forEach(function (id) {
        const btn = document.querySelector('[data-panel="' + id + '"]');
        const panel = document.getElementById(id);
        if (!btn || !panel) return;
        timers[id] = null;
        function open() { if (timers[id]) { clearTimeout(timers[id]); timers[id] = null; } panel.classList.add('show'); }
        function close() { if (timers[id]) clearTimeout(timers[id]); timers[id] = setTimeout(function () { panel.classList.remove('show'); }, 150); }
        btn.addEventListener('mouseenter', open);
        btn.addEventListener('focus', open);
        panel.addEventListener('mouseenter', open);
        panel.addEventListener('mouseleave', close);
        btn.addEventListener('mouseleave', close);
    });

    // Modal cleanup
    const roomModalEl = document.getElementById('roomModal');
    if (roomModalEl) {
        roomModalEl.addEventListener('hidden.bs.modal', function () {
            currentRoomId = null;
        });
    }

    // Topbar hide on scroll
    window.addEventListener('scroll', function () {
        const nearBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 100;
        document.querySelectorAll('.topbar-greeting, .topbar-user-info').forEach(function (el) {
            el.classList.toggle('hidden', nearBottom);
        });
    });
});
