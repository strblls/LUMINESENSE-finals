/* ═══════════════════════════════════════════════════════════════════════
   js/admin/admin-overview.js — Combined "Rooms & Faculty" page
   All data comes live from the DB via the PHP page. This wiring drives
   the room cards, the overview line chart, and the per-minute scrollbar.
   ═══════════════════════════════════════════════════════════════════════ */

// ── State ────────────────────────────────────────────────────────────────
let currentRoomId = null;
let currentFacultyId = null; // null = all faculties selected, 0 = none, >0 = single (mirrors currentRoomId)
let currentPeriod = 1;
let currentMetric = 'all';
let lineChartInstance = null;
let barChartInstance = null;
let overviewLineInstance = null;
const sparkCharts = {};

// ── Chart window scroll state (Today / per-minute view) ──────────────────
const OVERVIEW_WINDOW_SIZE = 15;
var overviewScrollOffset = 0;
var overviewScrollHovered = false;
var overviewLabels = [];

const COLORS = {
    voltage: '#2f004f',
    current: '#0d9488',
    power: '#f59e0b',
    energy: '#58078f',
    lineFill: 'rgba(88, 7, 143, 0.10)',
};

const METRIC_LABELS = { voltage: 'Voltage', current: 'Current', power: 'Power' };

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

// ── Per-room VAW sparkline (Voltage / Current / Power, 3 lines) ────────
function drawVawSpark(canvasId, room) {
    const el = document.getElementById(canvasId);
    if (!el || !window.Chart) return;
    if (sparkCharts[canvasId]) sparkCharts[canvasId].destroy();
    const hasSession = room && room.isActiveSession;
    const v = hasSession ? ((room && room.sparkV) || []) : [];
    const a = hasSession ? ((room && room.sparkA) || []) : [];
    const w = hasSession ? ((room && room.sparkW) || []) : [];
    const n = Math.max(v.length, a.length, w.length, 1);
    const labels = Array.from({ length: n }, (_, i) => i);
    const grayColor = '#d0d0d0';
    sparkCharts[canvasId] = new Chart(el, {
        type: 'line',
        data: {
            labels,
            datasets: hasSession ? [
                { data: v, borderColor: COLORS.voltage, borderWidth: 1.2, pointRadius: 0, fill: false, tension: 0.35 },
                { data: a, borderColor: COLORS.current, borderWidth: 1.2, pointRadius: 0, fill: false, tension: 0.35 },
                { data: w, borderColor: COLORS.power, borderWidth: 1.2, pointRadius: 0, fill: false, tension: 0.35 },
            ] : [
                { data: [0], borderColor: grayColor, borderWidth: 1.2, pointRadius: 0, fill: false, tension: 0.35 },
            ],
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
        drawVawSpark('sparkCanvas' + room.id, room);
    });
    drawFacultySparks();
}

// Faculty cards reuse the room-card layout, so give each a sparkline that
// reflects availability: the assigned room's V/A/W when in class, else gray.
function drawFacultySparks() {
    if (!window.Chart) return;
    document.querySelectorAll('#facultyList .hroom-spark canvas').forEach(canvas => {
        const id = canvas.id;
        if (!id) return;
        if (sparkCharts[id]) sparkCharts[id].destroy();
        const card = canvas.closest('.faculty-card');
        const roomName = (card && card.getAttribute('data-room-name') || '').toLowerCase();
        const room = (ROOMS || []).find(r => (r.room_name || '').toLowerCase() === roomName);
        const hasSession = !!room && !!room.isActiveSession;
        const v = hasSession ? (room.sparkV || []) : [];
        const a = hasSession ? (room.sparkA || []) : [];
        const w = hasSession ? (room.sparkW || []) : [];
        const n = Math.max(v.length, a.length, w.length, 1);
        const labels = Array.from({ length: n }, (_, i) => i);
        const grayColor = '#d0d0d0';
        sparkCharts[id] = new Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: hasSession ? [
                    { data: v, borderColor: COLORS.voltage, borderWidth: 1.2, pointRadius: 0, fill: false, tension: 0.35 },
                    { data: a, borderColor: COLORS.current, borderWidth: 1.2, pointRadius: 0, fill: false, tension: 0.35 },
                    { data: w, borderColor: COLORS.power, borderWidth: 1.2, pointRadius: 0, fill: false, tension: 0.35 },
                ] : [
                    { data: [0], borderColor: grayColor, borderWidth: 1.2, pointRadius: 0, fill: false, tension: 0.35 },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                scales: { x: { display: false }, y: { display: false } },
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
            },
        });
    });
}

// ── Live readings ────────────────────────────────────────────────────────
function liveRooms() {
    return (ROOMS || []).filter(r => r.is_live);
}

function renderLiveReadings() {
    let src = null;
    if (currentRoomId === 0) {
        src = null;
    } else if (currentRoomId) {
        src = (ROOMS || []).find(r => r.id == currentRoomId) || null;
    }
    let v = null, a = null, w = null, e = null, on = false;
    if (src) {
        v = src.voltage_v; a = src.current_a; w = src.power_w; e = src.energy_wh;
        on = src.light_status === 'on' || src.row1_status === 'on' || src.row2_status === 'on' || src.row3_status === 'on';
    } else if (currentRoomId !== 0) {
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

// ── Overview tier line graph (all V/A/W, like admin-analytics.php) ──────
// Respects the room selection: all rooms → aggregate CHART_DAILY,
// single room → that room's VAW series, none → empty.
function buildOverviewLineChart() {
    const ctx = document.getElementById('overviewLineChart');
    if (!ctx || !window.Chart) return;
    if (overviewLineInstance) overviewLineInstance.destroy();

    const rows = currentPeriod === 1 ? (CHART_TODAY || []) : (CHART_DAILY || []);
    const labels = rows.map(r => r.label);
    const datasets = [];
    const selected = currentRoomId;
    const room = selected > 0 ? (ROOMS || []).find(r => r.id == selected) : null;

    const showMetric = (m) => currentMetric === 'all' || currentMetric === m;

    if (selected === 0) {
        // No rooms selected → empty chart
    } else if (room) {
        // Single room: use that room's own series (per-minute today, or daily)
        const v = currentPeriod === 1 ? (room.todayV || []) : (room.dailyV || []).slice(-currentPeriod);
        const a = currentPeriod === 1 ? (room.todayA || []) : (room.dailyA || []).slice(-currentPeriod);
        const w = currentPeriod === 1 ? (room.todayW || []) : (room.dailyW || []).slice(-currentPeriod);
        const roomLabels = currentPeriod === 1 ? (room.todayLabels || []) : (room.dailyLabels || []).slice(-currentPeriod);
        const n = Math.max(v.length, a.length, w.length);
        const pad = (arr) => { const x = (arr || []).slice(); while (x.length < n) x.push(null); return x; };
        if (showMetric('voltage')) datasets.push({ label: room.room_name + ' · Voltage (V)', metric: 'voltage', data: pad(v), borderColor: '#742fd3', backgroundColor: 'rgba(116,47,211,0.10)', fill: true, tension: 0.3, pointRadius: 2, spanGaps: false });
        if (showMetric('current')) datasets.push({ label: room.room_name + ' · Current (A)', metric: 'current', data: pad(a), borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.10)', fill: true, tension: 0.3, pointRadius: 2, yAxisID: 'y1', spanGaps: false });
        if (showMetric('power'))   datasets.push({ label: room.room_name + ' · Power (W)', metric: 'power', data: pad(w), borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,0.10)', fill: true, tension: 0.3, pointRadius: 2, yAxisID: 'y2', spanGaps: false });
        if (roomLabels.length) labels.splice(0, labels.length, ...roomLabels);
    } else {
        if (showMetric('voltage')) datasets.push({ label: 'Voltage (V)', metric: 'voltage', data: rows.map(r => r.avg_voltage), borderColor: '#742fd3', backgroundColor: 'rgba(116,47,211,0.10)', fill: true, tension: 0.3, pointRadius: 2, spanGaps: false });
        if (showMetric('current')) datasets.push({ label: 'Current (A)', metric: 'current', data: rows.map(r => r.avg_current), borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.10)', fill: true, tension: 0.3, pointRadius: 2, yAxisID: 'y1', spanGaps: false });
        if (showMetric('power'))   datasets.push({ label: 'Power (W)', metric: 'power', data: rows.map(r => r.avg_power), borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,0.10)', fill: true, tension: 0.3, pointRadius: 2, yAxisID: 'y2', spanGaps: false });
    }

    overviewLineInstance = new Chart(ctx, {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: { font: { family: 'Poppins', size: 10 }, boxWidth: 12, padding: 12 },
                    onClick: function (e, legendItem, legend) {
                        const meta = legend.chart.getDatasetMeta(legendItem.datasetIndex);
                        meta.hidden = meta.hidden === null ? !legend.chart.data.datasets[legendItem.datasetIndex].hidden : null;
                        const axisId = legend.chart.data.datasets[legendItem.datasetIndex].yAxisID;
                        if (axisId && legend.chart.options.scales[axisId]) {
                            legend.chart.options.scales[axisId].display = !meta.hidden;
                        }
                        legend.chart.update();
                        updateOverviewLineTitle();
                        const labelEl = document.getElementById('overviewLineMetricLabel');
                        if (labelEl) {
                            const visible = legend.chart.data.datasets.filter(function (ds, i) { return !legend.chart.getDatasetMeta(i).hidden; }).map(function (ds) { return ds.label; });
                            labelEl.textContent = visible.length === legend.chart.data.datasets.length ? 'All Metrics' : visible.join(', ');
                        }
                    },
                },
            },
            scales: {
                x: { ticks: { color: '#4d4d4d', font: { family: 'Poppins', size: 10 } }, grid: { display: false } },
                y: { type: 'linear', display: true, position: 'left', title: { display: false }, ticks: { color: '#742fd3', font: { family: 'Poppins', size: 10 } }, grid: { color: 'rgba(47,0,79,0.07)' } },
                y1: { type: 'linear', display: true, position: 'left', title: { display: false }, ticks: { color: '#f59e0b', font: { family: 'Poppins', size: 10 } }, grid: { display: false } },
                y2: { type: 'linear', display: true, position: 'right', title: { display: false }, ticks: { color: '#16a34a', font: { family: 'Poppins', size: 10 } }, grid: { display: false } },
            },
        },
    });
    updateOverviewLineTitle();
    const metricLabelEl = document.getElementById('overviewLineMetricLabel');
    if (metricLabelEl) metricLabelEl.textContent = currentMetric === 'all' ? 'All Metrics' : (METRIC_LABELS[currentMetric] || currentMetric);
    updateOverviewScrollbar();
}

// ── Overview chart horizontal scrollbar (Today / per-minute only) ────────
function updateOverviewScrollbar() {
    const wrap = document.getElementById('overviewLineScrollWrap');
    const slider = document.getElementById('overviewLineScroll');
    const tipEl = document.getElementById('overviewLineScrollTip');
    const pendingEl = document.getElementById('overviewLineScrollPending');
    if (!wrap || !slider || !overviewLineInstance) return;

    const chart = overviewLineInstance;
    const n = chart.data.labels.length;
    const isToday = currentPeriod === 1;

    if (!isToday || n <= OVERVIEW_WINDOW_SIZE) {
        wrap.classList.remove('visible');
        overviewScrollOffset = 0;
        if (chart.options.scales.x) {
            chart.options.scales.x.min = undefined;
            chart.options.scales.x.max = undefined;
            chart.update();
        }
        return;
    }

    wrap.classList.add('visible');
    overviewLabels = chart.data.labels;
    const maxVal = n - OVERVIEW_WINDOW_SIZE;
    slider.max = maxVal;

    if (overviewScrollHovered) {
        const currentVal = parseInt(slider.value);
        if (currentVal < maxVal && pendingEl) pendingEl.classList.add('show');
    } else {
        slider.value = maxVal;
        overviewScrollOffset = maxVal;
        chart.options.scales.x.min = maxVal;
        chart.options.scales.x.max = maxVal + OVERVIEW_WINDOW_SIZE;
        chart.update();
        if (pendingEl) pendingEl.classList.remove('show');
    }
}

function updateOverviewScrollTip() {
    const tipEl = document.getElementById('overviewLineScrollTip');
    const slider = document.getElementById('overviewLineScroll');
    if (!tipEl || !slider) return;
    const offset = parseInt(slider.value);
    const label = overviewLabels[offset] || '';
    tipEl.textContent = label;
    tipEl.classList.add('show');
    const pct = slider.max > 0 ? (offset / slider.max) * 100 : 0;
    tipEl.style.left = 'calc(' + pct + '% + ' + (4 - pct * 0.08) + 'px)';
    tipEl.style.transform = 'translateX(-50%)';
}

function onOverviewChartScroll(value) {
    if (!overviewLineInstance || !overviewLineInstance.data || !overviewLineInstance.data.labels) return;
    const offset = parseInt(value);
    overviewScrollOffset = offset;
    overviewLineInstance.options.scales.x.min = offset;
    overviewLineInstance.options.scales.x.max = offset + OVERVIEW_WINDOW_SIZE;
    overviewLineInstance.update();
    updateOverviewScrollTip();
    const pendingEl = document.getElementById('overviewLineScrollPending');
    if (pendingEl) {
        const slider = document.getElementById('overviewLineScroll');
        if (slider && parseInt(slider.value) >= parseInt(slider.max)) pendingEl.classList.remove('show');
    }
}

// Dynamic line-graph title based on the shown metrics + selected rooms.
// e.g. "Readings of All Rooms", "Voltage and Power Readings of SEL 1",
//      "Power Readings of SEL 3".
function updateOverviewLineTitle() {
    const titleEl = document.getElementById('overviewLineTitle');
    if (!titleEl) return;

    const chart = overviewLineInstance;
    let metricNames = [];
    if (chart) {
        metricNames = chart.data.datasets
            .filter((ds, i) => !chart.getDatasetMeta(i).hidden)
            .map(ds => METRIC_LABELS[ds.metric] || ds.metric);
    }

    let roomsLabel = 'All Rooms';
    if (currentRoomId === 0) {
        roomsLabel = 'No Rooms';
    } else if (currentRoomId > 0) {
        const r = (ROOMS || []).find(x => x.id == currentRoomId);
        roomsLabel = r ? r.room_name : 'Room';
    }

    if (!metricNames.length) {
        titleEl.textContent = 'Readings of ' + roomsLabel;
    } else if (metricNames.length === 3) {
        titleEl.textContent = 'Readings of ' + roomsLabel;
    } else {
        titleEl.textContent = metricNames.join(' and ') + ' Readings of ' + roomsLabel;
    }
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
    const rows = isToday ? (CHART_TODAY || []) : (CHART_DAILY || []).slice(-currentPeriod);
    const labels = rows.map(r => r.label);
    buildLineChart(labels, rows);
    buildBarChart(labels, rows);
    const lm = document.getElementById('lineMetricLabel');
    if (lm) lm.textContent = currentMetric === 'all' ? 'All Metrics' : currentMetric.charAt(0).toUpperCase() + currentMetric.slice(1);
    const bm = document.getElementById('barMetricLabel');
    if (bm) bm.textContent = currentMetric === 'all' ? 'All Metrics' : currentMetric.charAt(0).toUpperCase() + currentMetric.slice(1);
}

// ── Period / Metric ──────────────────────────────────────────────────────
function setPeriod(el, days) {
    document.querySelectorAll('#panelPeriod .dept-member-filter-item').forEach(i => i.classList.remove('active'));
    if (el) el.classList.add('active');
    currentPeriod = parseInt(days, 10);
    updateMainCharts();
    buildOverviewLineChart();
    closePanel('panelPeriod');
}

function setMetric(el, metric) {
    document.querySelectorAll('#panelMetric .dept-member-filter-item').forEach(i => i.classList.remove('active'));
    if (el) el.classList.add('active');
    currentMetric = metric;
    updateMainCharts();
    buildOverviewLineChart();
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
// currentRoomId: null = all rooms selected (default), 0 = none, >0 = single.
function updateSelectionUI() {
    const allSel = currentRoomId === null;
    const noneSel = currentRoomId === 0;
    const room = !noneSel && !allSel ? (ROOMS || []).find(r => r.id == currentRoomId) : null;

    document.querySelectorAll('.spark-card, .room-card:not(.faculty-card), .hroom-row:not(.faculty-card)').forEach(c => {
        const rid = c.getAttribute('data-room-id');
        c.classList.toggle('active-room', !noneSel && (allSel || rid == currentRoomId));
    });

    const sub = document.getElementById('tabSubheading');
    const label = document.getElementById('roomsSelLabel');
    if (noneSel) {
        if (sub) sub.textContent = 'No Rooms Selected';
        if (label) label.textContent = ' None';
    } else if (room) {
        if (sub) sub.textContent = room.room_name + ' Selected';
        if (label) label.textContent = ' ' + room.room_name;
    } else {
        if (sub) sub.textContent = 'All Rooms Selected';
        if (label) label.textContent = ' All Rooms';
    }

    const selBtn = document.getElementById('selectAllRoomsBtn');
    if (selBtn) {
        selBtn.innerHTML = allSel
            ? '<i class="bi bi-x-lg"></i> Unselect all'
            : '<i class="bi bi-check2-all"></i> Select all';
        selBtn.classList.toggle('expanded', noneSel);
    }

    renderLiveReadings();
    buildOverviewLineChart();
    updateOverviewLineTitle();
}

function selectRoom(id) {
    const rid = parseInt(id, 10);
    if (currentRoomId === rid) {
        deselectRoom();
        setFacultyOnly(null); // back to “all faculties” together with “all rooms”
        return;
    }
    setRoomSelection(rid);
}

function deselectRoom() {
    currentRoomId = null;
    updateSelectionUI();
}

function toggleSelectAll() {
    const all = currentRoomId !== null;
    setRoomOnly(all ? null : 0);
    setFacultyOnly(all ? null : 0);
}

// ── Room / faculty cross-selection (matched on their classroom/session) ──
function setRoomOnly(id) {
    currentRoomId = (id === null || id === undefined) ? null : parseInt(id, 10);
    updateSelectionUI();
}

function setFacultyOnly(id) {
    currentFacultyId = (id === null || id === undefined) ? null : parseInt(id, 10);
    updateFacultySelectionUI();
}

function setRoomSelection(id) {
    setRoomOnly(id);
    syncFacultyFromRoom(id);
}

function setFacultySelection(id) {
    setFacultyOnly(id);
    if (id === null) { setRoomOnly(null); return; }
    if (id === 0) { setRoomOnly(0); return; }
    syncRoomFromFaculty(id);
}

function findRoomCardById(id) {
    const key = String(id);
    let out = null;
    document.querySelectorAll('.hroom-row:not(.faculty-card)').forEach(function (rc) {
        if (!out && rc.getAttribute('data-room-id') === key) out = rc;
    });
    return out;
}

function findRoomCardByRoomName(name) {
    const key = (name || '').toLowerCase();
    if (!key) return null;
    let out = null;
    document.querySelectorAll('.hroom-row:not(.faculty-card)').forEach(function (rc) {
        if (!out && (rc.getAttribute('data-room') || '').toLowerCase() === key) out = rc;
    });
    return out;
}

function findFacultyCardById(id) {
    const key = String(id);
    let out = null;
    document.querySelectorAll('#facultyList .faculty-card').forEach(function (fc) {
        if (!out && fc.getAttribute('data-faculty-id') === key) out = fc;
    });
    return out;
}

function findFacultyIdByRoomName(name) {
    const key = (name || '').toLowerCase();
    if (!key) return null;
    let out = null;
    document.querySelectorAll('#facultyList .faculty-card').forEach(function (fc) {
        if (!out && (fc.getAttribute('data-room-name') || '').toLowerCase() === key) {
            out = parseInt(fc.getAttribute('data-faculty-id'), 10);
        }
    });
    return out;
}

// Selecting a room also selects whichever faculty holds a session in it.
function syncFacultyFromRoom(roomId) {
    if (roomId === null) { setFacultyOnly(null); return; }
    const rc = findRoomCardById(roomId);
    if (!rc) { setFacultyOnly(0); return; }
    const fid = findFacultyIdByRoomName(rc.getAttribute('data-room'));
    setFacultyOnly(fid !== null ? fid : 0);
}

// Selecting a faculty also selects the room where their session is held.
function syncRoomFromFaculty(facId) {
    const fc = findFacultyCardById(facId);
    if (!fc) { setRoomOnly(0); return; }
    const roomName = fc.getAttribute('data-room-name');
    const rc = findRoomCardByRoomName(roomName);
    setRoomOnly(rc ? parseInt(rc.getAttribute('data-room-id'), 10) : 0);
}

function updateFacultySelectionUI() {
    const allSel = currentFacultyId === null;
    const noneSel = currentFacultyId === 0;

    document.querySelectorAll('#facultyList .faculty-card').forEach(function (c) {
        const fid = parseInt(c.getAttribute('data-faculty-id'), 10);
        c.classList.toggle('selected', !noneSel && (allSel || fid === currentFacultyId));
    });

    const label = document.getElementById('facultySelLabel');
    if (label) {
        if (noneSel) {
            label.textContent = ' None';
        } else if (!allSel) {
            const fc = findFacultyCardById(currentFacultyId);
            const name = fc ? (fc.querySelector('.room-card-name') || {}).textContent : '';
            label.textContent = ' ' + (name || '');
        } else {
            label.textContent = ' All Faculty';
        }
    }

    const selBtn = document.getElementById('selectAllFacultyBtn');
    if (selBtn) {
        selBtn.innerHTML = allSel
            ? '<i class="bi bi-x-lg"></i> Deselect all'
            : '<i class="bi bi-check2-all"></i> Select all';
    }
}

// ── Filters (status / dept / subject / search) ──────────────────────────
function applyFilters() {
    const statusVal = (document.querySelector('#statusFilterMenu .filter-option.active') || {}).dataset?.value || '';
    const deptVal = (document.querySelector('#departmentFilterMenu .filter-option.active') || {}).dataset?.value || '';
    const subjVal = (document.querySelector('#subjectFilterMenu .filter-option.active') || {}).dataset?.value || '';
    const searchVal = ((document.getElementById('roomSearch') || {}).value || '').toLowerCase();

    document.querySelectorAll('.spark-card, .room-card:not(.faculty-card), .hroom-row:not(.faculty-card)').forEach(card => {
        let show = true;
        if (statusVal) show = show && (card.dataset.status || '') === statusVal.toLowerCase();
        if (deptVal) show = show && (card.dataset.departments || '').toLowerCase().includes(deptVal.toLowerCase());
        if (subjVal) show = show && (card.dataset.subjects || '').toLowerCase().includes(subjVal.toLowerCase());
        if (searchVal) {
            const roomMatch = (card.dataset.room || '').includes(searchVal);
            const facEl = card.querySelector('.spark-card-faculty, .room-info-val, .hroom-faculty');
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
    const dailyRows = currentPeriod > 1 ? (CHART_DAILY || []).slice(-currentPeriod) : (CHART_DAILY || []);
    const rows = currentPeriod === 1 ? (CHART_TODAY || []) : dailyRows;
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
    setRoomSelection(parseInt(id, 10));
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

// ── Lighting override (persisted via api/lights.php) ─────────────────────
let rowState = { 1: false, 2: false, 3: false };
const rowBulbs = { 1: [0, 1, 2], 2: [3, 4, 5], 3: [6, 7, 8] };

function setBulb(index, on) {
    const img = document.getElementById('bulb' + index);
    if (img) img.src = on ? '../../images/bulb-on.png' : '../../images/bulb-off.png';
}

function rowGlobalState() {
    return (rowState[1] || rowState[2] || rowState[3]) ? 'on' : 'off';
}

function toggleRow(row, on) {
    rowState[row] = on;
    rowBulbs[row].forEach(i => setBulb(i, on));
    syncAllLightsLabel();
    sendLightingUpdate(row, on ? 'on' : 'off');
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
    sendLightingUpdate('all', anyOff ? 'on' : 'off');
}

function sendLightingUpdate(row, state) {
    const cid = currentRoomId;
    if (!cid) return;
    const body = new URLSearchParams();
    body.append('classroom_id', cid);
    body.append('row', row);
    body.append('state', state);
    body.append('triggered_by', 'admin');
    if (row !== 'all') body.append('new_global_light_status', rowGlobalState());
    fetch('../../api/lights.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
    }).catch(function () {});
    updateCardLighting(cid, rowState[1], rowState[2], rowState[3]);
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

// ── Live auto-refresh (device-strip + sparklines) ─────────────────────────
const OVERVIEW_POLL_MS = 5000;
const CHART_POLL_MS = 30000;
let overviewPollTimer = null;
let chartPollTimer = null;

function applyLiveRoom(room) {
    const card = document.querySelector('.room-card[data-room-id="' + room.id + '"]');
    if (!card) return;

    // Sparklines (7-day energy / VAW) from fresh data
    const prior = (ROOMS || []).find(r => r.id == room.id);
    if (prior && prior.spark) {
        prior.spark = room.spark; prior.sparkV = room.sparkV;
        prior.sparkA = room.sparkA; prior.sparkW = room.sparkW;
        prior.voltage_v = room.voltage_v; prior.current_a = room.current_a;
        prior.power_w = room.power_w; prior.energy_wh = room.energy_wh;
        prior.is_live = room.is_live;
        drawVawSpark('sparkCanvas' + room.id, room);
    }

    // Device strip: LIVE / NO DEVICE pill
    const pill = card.querySelector('.device-pill');
    if (pill) {
        pill.textContent = room.is_live ? 'LIVE' : 'NO DEVICE';
        pill.classList.toggle('live', room.is_live);
        pill.classList.toggle('none', !room.is_live);
    }

    // Device strip: V / A / W values
    const devLeft = card.querySelector('.dev-left');
    let pzem = card.querySelector('.dev-pzem');
    if (room.is_live) {
        if (!pzem && devLeft) {
            pzem = document.createElement('span');
            pzem.className = 'dev-pzem';
            const pillEl = card.querySelector('.device-pill');
            pillEl ? pillEl.insertAdjacentElement('afterend', pzem) : devLeft.appendChild(pzem);
        }
        if (pzem) {
            pzem.innerHTML = ' V <b>' + (room.voltage_v != null ? room.voltage_v.toFixed(1) : '\u2014') +
                '</b> &middot; A <b>' + (room.current_a != null ? room.current_a.toFixed(3) : '\u2014') +
                '</b> &middot; W <b>' + (room.power_w != null ? room.power_w.toFixed(1) : '\u2014') + '</b>';
        }
    } else if (pzem) {
        pzem.remove();
    }

    // Row light bars
    const bars = card.querySelectorAll('.row-bar');
    bars.forEach(function (bar) {
        const item = bar.closest('.row-bar-item');
        const idx = item ? parseInt(item.querySelector('.row-bar-label').textContent.replace(/\D/g, ''), 10) : 0;
        if (idx >= 1 && idx <= 3) bar.classList.toggle('on', room['row' + idx + '_status'] === 'on');
    });
}

async function pollOverviewLive() {
    try {
        const res = await fetch('../../api/overview-live.php');
        const data = await res.json();
        if (!data || !data.ok) return;
        (data.rooms || []).forEach(applyLiveRoom);
        renderLiveReadings();
    } catch (err) {
        console.warn('[Overview Live]', err);
    }
}

async function pollOverviewChart() {
    if (currentPeriod !== 1) return;
    try {
        const res = await fetch('../../api/overview-chart.php');
        const data = await res.json();
        if (!data || !data.ok) return;
        CHART_TODAY = data.today || [];
        updateMainCharts();
        buildOverviewLineChart();
    } catch (err) {
        console.warn('[Overview Chart]', err);
    }
}

// ── Init ─────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    drawAllSparks();
    renderLiveReadings();
    buildOverviewLineChart();
    updateMainCharts();
    updateSelectionUI();

    // Keep device-strips + sparklines fresh while ESP32/Arduino streams
    overviewPollTimer = setInterval(pollOverviewLive, OVERVIEW_POLL_MS);
    chartPollTimer = setInterval(pollOverviewChart, CHART_POLL_MS);

    // Room selection
    document.querySelectorAll('.spark-card, .room-card:not(.faculty-card), .hroom-row:not(.faculty-card)').forEach(card => {
        card.addEventListener('click', (e) => {
            if (e.target.closest('.room-icons') || e.target.closest('.light') || e.target.closest('.room-card-actions')) return;
            selectRoom(card.getAttribute('data-room-id'));
        });
    });
    const selectAllBtn = document.getElementById('selectAllRoomsBtn');
    if (selectAllBtn) selectAllBtn.addEventListener('click', toggleSelectAll);

    // Metric cards in live readings
    document.querySelectorAll('#vawGroup .live-stat-card[data-metric]').forEach(card => {
        card.addEventListener('click', function () {
            const isActive = this.classList.contains('metric-active');
            if (isActive) { setMetric(null, 'all'); return; }
            setMetric(this, this.getAttribute('data-metric'));
        });
    });

    // Filters
    ['statusFilterMenu'].forEach(bindFilterMenu);
    const searchEl = document.getElementById('roomSearch');
    if (searchEl) searchEl.addEventListener('input', applyFilters);

    // Overview chart scrollbar hover
    const ovWrap = document.getElementById('overviewLineScrollWrap');
    const ovSlider = document.getElementById('overviewLineScroll');
    if (ovWrap && ovSlider) {
        ovWrap.addEventListener('mouseenter', function () {
            overviewScrollHovered = true;
            const tipEl = document.getElementById('overviewLineScrollTip');
            if (tipEl) tipEl.classList.add('show');
            updateOverviewScrollTip();
        });
        ovWrap.addEventListener('mouseleave', function () {
            overviewScrollHovered = false;
            const tipEl = document.getElementById('overviewLineScrollTip');
            if (tipEl) tipEl.classList.remove('show');
            const p = document.getElementById('overviewLineScrollPending');
            if (p) p.classList.remove('show');
        });
    }

    // Expand / collapse all room details
    const expandBtn = document.getElementById('expandAllRoomsBtn');
    const hroomsList = document.getElementById('hroomsList');
    if (expandBtn && hroomsList) {
        expandBtn.addEventListener('click', function () {
            const expanded = hroomsList.classList.toggle('expanded');
            expandBtn.classList.toggle('expanded', expanded);
            expandBtn.innerHTML = expanded
                ? '<i class="bi bi-chevron-up"></i> Collapse all'
                : '<i class="bi bi-chevron-down"></i> Expand all';
        });
    }

    // Expand / collapse all faculty details
    const expandFacBtn = document.getElementById('expandAllFacultyBtn');
    const facultyListEl = document.getElementById('facultyList');
    if (expandFacBtn && facultyListEl) {
        expandFacBtn.addEventListener('click', function () {
            const expanded = facultyListEl.classList.toggle('expanded');
            expandFacBtn.classList.toggle('expanded', expanded);
            expandFacBtn.innerHTML = expanded
                ? '<i class="bi bi-chevron-up"></i> Collapse all'
                : '<i class="bi bi-chevron-down"></i> Expand all';
        });
    }

    // Panel hover + click expand
    const panels = ['panelGuide', 'panelStatus', 'panelPeriod', 'panelMetric'];
    const timers = {};
    panels.forEach(function (id) {
        const btn = document.querySelector('[data-panel="' + id + '"]');
        const panel = document.getElementById(id);
        if (!btn || !panel) return;
        const heading = panel.closest('.overview-heading, .room-manage-header');
        timers[id] = null;
        function open() {
            if (timers[id]) { clearTimeout(timers[id]); timers[id] = null; }
            panel.classList.add('show');
            if (heading) heading.style.zIndex = '1050';
            btn.setAttribute('aria-expanded', 'true');
        }
        function closeNow() {
            if (timers[id]) { clearTimeout(timers[id]); timers[id] = null; }
            panel.classList.remove('show');
            if (heading) heading.style.zIndex = '';
            btn.setAttribute('aria-expanded', 'false');
        }
        function close() {
            if (timers[id]) clearTimeout(timers[id]);
            timers[id] = setTimeout(closeNow, 150);
        }
        btn.addEventListener('mouseenter', open);
        btn.addEventListener('focus', open);
        btn.addEventListener('mouseleave', close);
        btn.addEventListener('click', function () {
            if (panel.classList.contains('show')) closeNow();
            else open();
        });
        panel.addEventListener('mouseenter', open);
        panel.addEventListener('mouseleave', close);
        panel.addEventListener('click', function () { closeNow(); });
    });

    // Modal cleanup
    const roomModalEl = document.getElementById('roomModal');
    if (roomModalEl) {
roomModalEl.addEventListener('hidden.bs.modal', function () {
            setRoomOnly(null);
            setFacultyOnly(null);
        });
    }

    initFacultyManagement();

    // Maximize button → fullscreen schedule Gantt
    const maximizeBtn = document.getElementById('maximizeFacultyBtn');
    if (maximizeBtn) maximizeBtn.addEventListener('click', openFacultyGantt);
});

/* ── Faculty Management Pane ─────────────────────────────────────────────── */
async function initFacultyManagement() {
    // Faculty list is rendered server-side. We just attach behavior.
    var facultyListEl = document.getElementById('facultyList');
    if (!facultyListEl) return;

    var searchEl = document.getElementById('facultySearch');
    if (searchEl) {
        searchEl.addEventListener('input', function () {
            filterFacultyCards();
        });
    }

    var selectAllBtn = document.getElementById('selectAllFacultyBtn');
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function () {
            if (currentFacultyId === null) { setFacultyOnly(0); setRoomOnly(0); }
            else { setFacultyOnly(null); setRoomOnly(null); }
        });
    }
}

function filterFacultyCards() {
    var search = (document.getElementById('facultySearch')?.value || '').toLowerCase();
    var cards = document.querySelectorAll('#facultyList .faculty-card');
    cards.forEach(function(card) {
        var name = card.querySelector('.room-card-name')?.textContent?.toLowerCase() || '';
        var matchSearch = !search || name.includes(search);
        card.style.display = matchSearch ? '' : 'none';
    });
}

function selectFaculty(id) {
    const fid = parseInt(id, 10);
    if (currentFacultyId === fid) {
        setFacultyOnly(null);
        setRoomOnly(null); // back to “all rooms” together with “all faculties”
        return;
    }
    setFacultySelection(fid);
}

/* ── Faculty Schedule Gantt (maximize pane) ───────────────────────────────── */
const GANTT_HOUR_START = 0;   // 12:00 AM (full day, so labels depict 1 AM through 11:59 PM)
const GANTT_HOUR_END   = 24;  // 12:00 AM (end of day)
const GANTT_DAY_ORDER  = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const GANTT_DAY_SHORT  = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const GANTT_VISIBLE_HOURS = 4; // hours shown at once in the pane (slider shifts this window)

let ganttDayIdx = 0; // current pane day, Monday = 0 … Sunday = 6 (defaults to today on open)
let ganttSearch = ''; // Gantt faculty search filter
let ganttScrollOffset = 0; // minute offset of the visible window from rangeStart (mirrors overviewScrollOffset)
let ganttAutoPosition = true; // when true, the next render snaps the window onto the first class of the day

function ganttTimeToMin(t) {
    if (!t) return GANTT_HOUR_START * 60;
    const parts = String(t).split(':');
    return parseInt(parts[0], 10) * 60 + parseInt(parts[1] || '0', 10);
}

function renderFacultyGantt() {
    const container = document.getElementById('facultyGantt');
    if (!container) return;

    const wrap = document.getElementById('facultyGanttWrap');
    const paneW = (wrap && wrap.clientWidth > 0) ? wrap.clientWidth : 1000;
    const labelW = 150;
    const dayW = Math.max(paneW - labelW, 300);
    const rowH = 52;
    const headerH = 38;

    const nowMin = ganttTimeToMin(new Date().toTimeString().slice(0, 8));
    const todayDow = new Date().getDay(); // 0 Sun … 6 Sat
    const todayIdx = todayDow === 0 ? 6 : todayDow - 1; // Monday = 0

    // Build rows (search-narrowed)
    const members = (FACULTY || []).filter(f => {
        if (!ganttSearch) return true;
        const hay = (f.first_name + ' ' + f.last_name + ' ' + (f.department_name || '')).toLowerCase();
        return hay.includes(ganttSearch);
    });
    const day = GANTT_DAY_ORDER[ganttDayIdx];

    // Active day tab (respects the < / > navigation state)
    updateGanttDayTabs();

    if (!members.length) {
        container.innerHTML = '<p class="text-muted small p-3">' +
            (ganttSearch ? 'No faculty members match that search.' : 'No active faculty members.') + '</p>';
        return;
    }

    // Gather this day's schedules first so the time axis is dynamic (no overlaps / inaccuracy).
    const daySchedList = [];
    members.forEach(f => {
        const scheds = (FACULTY_SCHEDULES || {})[f.id] || [];
        scheds.filter(s => s.day_of_week === day).forEach(s => daySchedList.push({ f: f, s: s }));
    });

    // Axis always spans the full day (12 AM → 12 AM) so hour labels accurately
    // depict every hour from 1 AM through 11:59 PM; the slider scrolls a fixed
    // GANTT_VISIBLE_HOURS window across it.
    const rangeStart = GANTT_HOUR_START * 60;
    const rangeEnd = GANTT_HOUR_END * 60;
    const spanMin = Math.max(rangeEnd - rangeStart, 60);
    // The pane always shows a fixed GANTT_VISIBLE_HOURS window; the slider shifts it.
    const visWindowMin = GANTT_VISIBLE_HOURS * 60;
    const maxOffset = Math.max(spanMin - visWindowMin, 0);

    // Auto-position the window after navigation: center today's current time, otherwise the first class.
    if (ganttAutoPosition) {
        ganttAutoPosition = false;
        if (ganttDayIdx === todayIdx && nowMin >= rangeStart && nowMin < rangeEnd) {
            ganttScrollOffset = Math.max(nowMin - visWindowMin / 2, 0);
        } else {
            let firstMin = Infinity;
            daySchedList.forEach(function (item) { ganttTimeToMin(item.s.start_time) < firstMin && (firstMin = ganttTimeToMin(item.s.start_time)); });
            ganttScrollOffset = (firstMin !== Infinity)
                ? Math.max(Math.floor(firstMin / 60) * 60, 0)
                : Math.min(6 * 60, maxOffset); // no classes → start the pane at 6:00 AM
        }
    }
    if (ganttScrollOffset > maxOffset) ganttScrollOffset = maxOffset;
    if (ganttScrollOffset < 0) ganttScrollOffset = 0;
    const visStart = rangeStart + ganttScrollOffset;
    const visEnd = visStart + visWindowMin;
    const hourPx = dayW / GANTT_VISIBLE_HOURS;

    // Keep the scrollbar + tooltip in sync with the visible window (mirrors the overview chart).
    const scrollbarWrap = document.getElementById('ganttScrollWrap');
    const sliderEl = document.getElementById('ganttScroll');
    if (scrollbarWrap) scrollbarWrap.classList.toggle('visible', maxOffset > 0);
    if (sliderEl) { sliderEl.max = maxOffset; sliderEl.value = ganttScrollOffset; }
    const tipEl = document.getElementById('ganttScrollTip');
    if (tipEl) {
        if (maxOffset > 0) {
            tipEl.textContent = fmtGanttTime(visStart) + ' – ' + fmtGanttTime(visEnd);
            const pct = (ganttScrollOffset / maxOffset) * 100;
            tipEl.style.left = 'calc(' + pct + '% + ' + (4 - pct * 0.08) + 'px)';
            tipEl.style.transform = 'translateX(-50%)';
        } else {
            tipEl.classList.remove('show');
        }
    }

    // Grid: pinned labels column + one scrollable day region (all faculty rows scroll together).
    let html = '<div class="gantt-grid" style="width:100%;">';

    // — Pinned label column (header + each faculty) —
    html += '<div class="gantt-labels-col">';
    html += '<div class="gantt-label-cell gantt-label-head" style="width:' + labelW + 'px;height:' + headerH + 'px;">Faculty</div>';
    members.forEach(f => {
        const isSel = parseInt(f.id, 10) === currentFacultyId;
        html += '<div class="gantt-label-cell' + (isSel ? ' gantt-fac-selected' : '') + '" style="width:' + labelW + 'px;height:' + rowH + 'px;">' +
            '<div class="gantt-fac-name">' + escapeHtml(f.first_name + ' ' + f.last_name) + '</div>' +
            '<div class="gantt-fac-dept">' + escapeHtml(f.department_name || '') + '</div></div>';
    });
    html += '</div>';

    // — Day region (header ticks + all faculty schedules), showing only the visible window —
    html += '<div class="gantt-days-scroll">';
    html += '<div class="gantt-day-col" style="width:' + dayW + 'px;height:' + headerH + 'px;">' +
        '<div class="gantt-hour-ticks">';
    // Ticks align to real hour boundaries inside the visible window; every cell gets a label.
    const firstTick = Math.ceil(visStart / 60) * 60;
    for (let m = firstTick; m < visEnd; m += 60) {
        const h = Math.floor(m / 60);
        const l = (h % 12 === 0 ? 12 : h % 12) + (h >= 12 ? ' PM' : ' AM');
        const left = ((m - visStart) / 60) * hourPx;
        html += '<span style="width:' + hourPx + 'px;left:' + left + 'px;"><em class="gantt-tick-line"></em>' + l + '</span>';
    }
    html += '</div></div>';

    // Current-time marker: an accent line through the whole day grid on today, with a
    // "Current" label revealed on hover. Rendered only while the clock falls in the window.
    if (ganttDayIdx === todayIdx && nowMin >= visStart && nowMin <= visEnd) {
        const nowX = (nowMin - visStart) * (hourPx / 60);
        html += '<div class="gantt-now-line" style="left:' + nowX + 'px;"><i class="gantt-now-tag">Current</i></div>';
    }

    const daySchedMap = {};
    daySchedList.forEach(({ f, s }) => { (daySchedMap[f.id] = daySchedMap[f.id] || []).push(s); });

    members.forEach((f, fi) => {
        const dayScheds = daySchedMap[f.id] || [];
        const isSel = parseInt(f.id, 10) === currentFacultyId;
        html += '<div class="gantt-day-area-inner' + (fi % 2 ? ' gantt-zebra' : '') + (isSel ? ' gantt-fac-selected' : '') + '" style="width:' + dayW + 'px;height:' + rowH + 'px;">';
        if (!dayScheds.length) {
            html += '<span class="gantt-empty">—</span>';
        }
        dayScheds.forEach(s => {
            const startMin = ganttTimeToMin(s.start_time);
            const baseEnd = ganttTimeToMin(s.end_time);
            const extEnd = ganttTimeToMin(s.extended_until);
            const endMin = Math.max(extEnd, baseEnd);
            const isToday = ganttDayIdx === todayIdx;
            const isNow = isToday && startMin <= nowMin && nowMin <= endMin;
            const isPast = isToday && endMin < nowMin;
            const isExtended = extEnd > baseEnd;
            const cls = isExtended ? 'gantt-block-extended'
                : (isNow ? 'gantt-block-now'
                : (isPast ? 'gantt-block-past' : 'gantt-block-upcoming'));
            // Clip the block to the visible window so out-of-view parts never show.
            const clipStart = Math.max(startMin, visStart);
            const clipEnd = Math.min(endMin, visEnd);
            if (clipEnd <= clipStart) return;
            const left = (clipStart - visStart) * (hourPx / 60);
            const width = Math.max((clipEnd - clipStart) * (hourPx / 60), 6);
            const startTxt = fmtGanttTime(startMin);
            const endTxt = fmtGanttTime(endMin);
            const subjName = s.subject_name || 'Class';
            const roomName = s.room_name || '—';
            html += '<div class="gantt-block ' + cls + '" style="left:' + left + 'px;width:' + width + 'px;" ' +
                'data-subject="' + escapeHtml(subjName) + '" ' +
                'data-room="' + escapeHtml(roomName) + '" ' +
                'data-time="' + escapeHtml(startTxt + ' – ' + endTxt) + '">' +
                '<span class="gantt-block-subject">' + escapeHtml(subjName) + '</span>' +
                '<span class="gantt-block-room">' + escapeHtml(roomName) + '</span>' +
                '</div>';
        });
        html += '</div>';
    });
    html += '</div>'; // days-scroll
    html += '</div>'; // grid

    // Legend sits apart from the chart, on its own full-width bar (not scrollable).
    html += '<div class="gantt-legend-bar">' +
        '<span class="gantt-legend gantt-legend-past"></span><span class="small">Past</span>' +
        '<span class="gantt-legend gantt-legend-now"></span><span class="small">Now</span>' +
        '<span class="gantt-legend gantt-legend-upcoming"></span><span class="small">Upcoming</span>' +
        '<span class="gantt-legend gantt-legend-extended"></span><span class="small">Extended</span>' +
        '</div>';

    container.innerHTML = html;
}

function updateGanttDayTabs() {
    document.querySelectorAll('.gantt-day-tab').forEach(function (btn) {
        const idx = parseInt(btn.getAttribute('data-day'), 10);
        btn.classList.toggle('active', idx === ganttDayIdx);
    });
}

function fmtGanttTime(min) {
    const h = Math.floor(min / 60) % 24; // 24:00 → 00:00 (12:00 AM)
    const m = min % 60;
    const ampm = h >= 12 ? 'PM' : 'AM';
    const hh = h % 12 === 0 ? 12 : h % 12;
    return hh + ':' + String(m).padStart(2, '0') + ' ' + ampm;
}

function openFacultyGantt() {
    const todayDow = new Date().getDay(); // 0 Sun … 6 Sat
    ganttDayIdx = todayDow === 0 ? 6 : todayDow - 1; // default to current day
    ganttScrollOffset = 0;
    ganttAutoPosition = true;
    // A faculty selected on the overview becomes the focus of the maximized view:
    // pre-fill the search (narrows the grid to that member) and highlight their row.
    const selFaculty = (currentFacultyId > 0)
        ? (FACULTY || []).find(function (f) { return parseInt(f.id, 10) === currentFacultyId; })
        : null;
    ganttSearch = selFaculty ? (selFaculty.first_name + ' ' + selFaculty.last_name).toLowerCase() : '';
    const searchEl = document.getElementById('ganttFacultySearch');
    if (searchEl) searchEl.value = selFaculty ? (selFaculty.first_name + ' ' + selFaculty.last_name) : '';
    const modalEl = document.getElementById('facultyGanttModal');
    if (modalEl && window.bootstrap) {
        const modal = new bootstrap.Modal(modalEl);
        modalEl.addEventListener('shown.bs.modal', function () { renderFacultyGantt(); }, { once: true });
        modal.show();
    } else {
        renderFacultyGantt();
    }
}

window.addEventListener('resize', function () {
    const wrap = document.getElementById('facultyGanttWrap');
    if (wrap && wrap.offsetParent !== null) renderFacultyGantt();
});

/* ── Gantt day-pane navigation (prev / next) ──────────────────────────────── */
function ganttStep(offset) {
    ganttDayIdx = (ganttDayIdx + offset + 7) % 7;
    ganttScrollOffset = 0; // each day starts at its earliest visible hour
    ganttAutoPosition = true;
    hideFacultyGanttOverlay();
    renderFacultyGantt();
}

const ganttPrevBtn = document.getElementById('ganttPrevBtn');
if (ganttPrevBtn) ganttPrevBtn.addEventListener('click', function () { ganttStep(-1); });

const ganttNextBtn = document.getElementById('ganttNextBtn');
if (ganttNextBtn) ganttNextBtn.addEventListener('click', function () { ganttStep(1); });

/* Direct day-tab navigation (clicking a day tab jumps to that day) */
document.querySelectorAll('.gantt-day-tab').forEach(function (btn) {
    btn.addEventListener('click', function () {
        ganttDayIdx = parseInt(btn.getAttribute('data-day'), 10);
        ganttScrollOffset = 0; // each day starts at its earliest visible hour
        ganttAutoPosition = true;
        hideFacultyGanttOverlay();
        renderFacultyGantt();
    });
});

/* Time-window scrollbar for the Gantt pane (mirrors the overview chart scrollbar) */
function onGanttScroll(value) {
    ganttScrollOffset = parseInt(value, 10) || 0;
    hideFacultyGanttOverlay();
    renderFacultyGantt();
}

const ganttScrollWrapEl = document.getElementById('ganttScrollWrap');
if (ganttScrollWrapEl) {
    ganttScrollWrapEl.addEventListener('mouseenter', function () {
        const t = document.getElementById('ganttScrollTip');
        if (t && t.textContent) t.classList.add('show');
    });
    ganttScrollWrapEl.addEventListener('mouseleave', function () {
        const t = document.getElementById('ganttScrollTip');
        if (t) t.classList.remove('show');
    });
}

/* Gantt faculty search — re-renders the pane so only matching members appear */
const ganttSearchEl = document.getElementById('ganttFacultySearch');
if (ganttSearchEl) {
    ganttSearchEl.addEventListener('input', function () {
        ganttSearch = this.value.trim().toLowerCase();
        hideFacultyGanttOverlay();
        renderFacultyGantt();
    });
}

/* ── Gantt block detail overlay (anchored, scales in like the homepage day overlay) ── */
let ganttOverlayEl = null;
let ganttOverlayHost = null;

function ensureGanttOverlay() {
    if (ganttOverlayEl) return ganttOverlayEl;
    ganttOverlayEl = document.getElementById('facultyGanttOverlay');
    return ganttOverlayEl;
}

function showGanttOverlay(block) {
    const overlay = ensureGanttOverlay();
    if (!overlay) return;
    const header = document.getElementById('facultyGanttOverlayHeader');
    const body = document.getElementById('facultyGanttOverlayBody');
    if (header) header.textContent = block.getAttribute('data-subject') || 'Class';
    if (body) {
        body.innerHTML =
            '<div class="gantt-overlay-item"><span class="gantt-overlay-label">Room: </span><span class="gantt-overlay-value">' + (block.getAttribute('data-room') || '—') + '</span></div>' +
            '<div class="gantt-overlay-item"><span class="gantt-overlay-label">Time: </span><span class="gantt-overlay-value">' + (block.getAttribute('data-time') || '—') + '</span></div>';
    }

    const rect = block.getBoundingClientRect();
    const overlayW = 220;
    let top = rect.top - 10;
    let left = rect.left + rect.width / 2;
    if (left + overlayW > window.innerWidth - 8) left = window.innerWidth - overlayW - 8;
    if (left < 8) left = 8;
    if (top < 8) top = 8;

    overlay.style.top = top + 'px';
    overlay.style.left = left + 'px';
    overlay.style.transformOrigin = 'top left';
    ganttOverlayHost = block;
    void overlay.offsetWidth;
    overlay.classList.add('open');
}

function hideFacultyGanttOverlay() {
    const overlay = ensureGanttOverlay();
    if (overlay) overlay.classList.remove('open');
    ganttOverlayHost = null;
}

document.addEventListener('mouseover', function (e) {
    const block = e.target.closest('.gantt-block');
    if (!block || block === ganttOverlayHost) return;
    showGanttOverlay(block);
});

document.addEventListener('mouseout', function (e) {
    const t = e.target;
    if (!t || !t.closest) return;
    const fromBlock = t.closest('.gantt-block');
    const fromOverlay = t.closest('#facultyGanttOverlay');
    if (!fromBlock && !fromOverlay) return;
    const toEl = e.relatedTarget;
    if (toEl && toEl.closest && toEl.closest('.gantt-block, #facultyGanttOverlay')) return;
    hideFacultyGanttOverlay();
});
