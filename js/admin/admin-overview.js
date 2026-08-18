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
let liveMode = false; // Live dashboard (shared analytics runtime) on/off
let overviewLineInstance = null;
let liveChartToday = null; // refreshed CHART_TODAY from the poll (CHART_TODAY is a const)
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

const OV_METRIC_LABELS = { voltage: 'Voltage', current: 'Current', power: 'Power' };

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

// Per-faculty 7-day energy series from FACULTY_DAILY (rollup). Returns an array
// of {label, energy_wh, minutes} for the last `days` days (0-padded).
function facultyEnergySeries(facId, days) {
    const daily = (FACULTY_DAILY || {})[facId] || {};
    const out = [];
    const today = new Date();
    for (let i = days - 1; i >= 0; i--) {
        const d = new Date(today.getFullYear(), today.getMonth(), today.getDate() - i);
        const key = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        const row = daily[key];
        out.push({
            label: key,
            energy_wh: row ? parseFloat(row.energy_wh) : 0,
            minutes: row ? parseInt(row.minutes, 10) || 0 : 0,
        });
    }
    return out;
}

// Faculty cards show the faculty's own 7-day energy sparkline (from the daily
// rollup). Gray when the faculty has no attributed energy history.
function drawFacultySparks() {
    if (!window.Chart) return;
    document.querySelectorAll('#facultyList .hroom-spark canvas').forEach(canvas => {
        const id = canvas.id;
        if (!id) return;
        if (sparkCharts[id]) sparkCharts[id].destroy();
        const card = canvas.closest('.faculty-card');
        const facId = card ? parseInt(card.getAttribute('data-faculty-id'), 10) : 0;
        const series = facultyEnergySeries(facId, 7);
        const hasData = series.some(s => s.energy_wh > 0);
        const data = hasData ? series.map(s => s.energy_wh) : [0];
        const labels = series.map(s => s.label);
        const grayColor = '#d0d0d0';
        sparkCharts[id] = new Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: hasData ? [
                    { data, borderColor: COLORS.energy, borderWidth: 1.2, pointRadius: 0, fill: false, tension: 0.35 },
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

// ── Overview tier line graph (all V/A/W, like admin-analytics.php) ──────
// Respects the room selection: all rooms → aggregate CHART_DAILY,
// single room → that room's VAW series, none → empty.
function buildOverviewLineChart() {
    const ctx = document.getElementById('overviewLineChart');
    if (!ctx || !window.Chart) return;
    if (overviewLineInstance) overviewLineInstance.destroy();

    const todayRows = (liveChartToday || CHART_TODAY || []);
    const rows = currentPeriod === 1 ? todayRows : (CHART_DAILY || []);
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

    mapOverviewIssues(labels);
    overviewLineInstance = new Chart(ctx, {
        type: 'line',
        data: { labels, datasets },
        plugins: [overviewIssuePlugin],
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            onClick: onOverviewChartClick,
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
    if (metricLabelEl) metricLabelEl.textContent = currentMetric === 'all' ? 'All Metrics' : (OV_METRIC_LABELS[currentMetric] || currentMetric);
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
    overviewLabels = chart.data.labels;

    // Scrollbar control stays visible on every chart so it is always
    // discoverable; it is disabled while data fits within one window.
    wrap.classList.add('visible');
    if (n <= OVERVIEW_WINDOW_SIZE) {
        slider.disabled = true;
        slider.max = 0;
        slider.value = 0;
        overviewScrollOffset = 0;
        if (tipEl) tipEl.classList.remove('show');
        if (pendingEl) pendingEl.classList.remove('show');
        if (chart.options.scales.x) {
            chart.options.scales.x.min = undefined;
            chart.options.scales.x.max = undefined;
            chart.update();
        }
        renderOverviewMiniGantt();
        return;
    }

    slider.disabled = false;
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
    renderOverviewMiniGantt();
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
    renderOverviewMiniGantt();
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
            .map(ds => OV_METRIC_LABELS[ds.metric] || ds.metric);
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

// ── Period / Metric ─────────────────────────────────────────────────────────
function ovSetPeriod(el, days) {
    document.querySelectorAll('#panelPeriod .dept-member-filter-item').forEach(i => i.classList.remove('active'));
    if (el) el.classList.add('active');
    currentPeriod = parseInt(days, 10);
    // Keep the hidden analytics-runtime select in sync so CSV/PDF exports
    // (which read periodSelect.value) honour the period picked here.
    const ps = document.getElementById('periodSelect');
    if (ps) ps.value = currentPeriod;
    renderLiveReadings();
    buildOverviewLineChart();
    if (currentModalRoom) renderRoomModalChart(extendRoomTodayToNow(currentModalRoom));
    closePanel('panelPeriod');
}

function ovSetMetric(el, metric) {
    document.querySelectorAll('#panelMetric .dept-member-filter-item').forEach(i => i.classList.remove('active'));
    if (el) el.classList.add('active');
    currentMetric = metric;
    buildOverviewLineChart();
    if (currentModalRoom) renderRoomModalChart(extendRoomTodayToNow(currentModalRoom));
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

// ── Live / analytics routing ─────────────────────────────────────────────
// The heading Period & Metric panels drive the normal overview charts while
// Live is off, and the shared admin-analytics.js runtime while Live is on.
function handleHeadingPeriod(el, days) {
    if (liveMode && typeof window.setPeriod === 'function') {
        window.setPeriod(el, days);
        return;
    }
    ovSetPeriod(el, days);
}

function handleHeadingMetric(el, metric) {
    if (liveMode && typeof window.setMetric === 'function') {
        window.setMetric(el, metric);
        return;
    }
    ovSetMetric(el, metric);
}

function setLiveMode(on) {
    document.body.classList.toggle('live-mode', on);
    liveMode = on;
    const btn = document.getElementById('liveToggleBtn');
    if (btn) btn.classList.toggle('active', on);
    if (on) {
        // Charts are created lazily by admin-analytics.js once the live
        // dashboard is visible (avoids Chart.js "Canvas exceeds max size").
        if (typeof window.createAnalyticsCharts === 'function') window.createAnalyticsCharts();
        if (typeof window.resumePolling === 'function') window.resumePolling();
        if (typeof window.onControlChange === 'function') window.onControlChange();
    } else {
        if (typeof window.pausePolling === 'function') window.pausePolling();
        // Release chart instances while the dashboard is hidden so the next
        // toggle recreates them in a visible container.
        if (typeof window.destroyAnalyticsCharts === 'function') window.destroyAnalyticsCharts();
        if (typeof window.destroyFindingsCharts === 'function') window.destroyFindingsCharts();
        updateSelectionUI();
    }
}

function toggleLiveMode() {
    setLiveMode(!liveMode);
}

// ── Export (CSV / PDF) ────────────────────────────────────────────────────────
// The export modal and its handlers live in admin-analytics.js (shared runtime,
// loaded on this page too). lastData is only populated once the analytics API
// has been fetched, so ensure it is loaded before opening the modal — otherwise
// the export silently does nothing when Live mode has never been toggled on.
function openExport(mode) {
    const open = function () {
        if (mode === 'pdf' && typeof window.exportPDF === 'function') {
            window.exportPDF();
        } else if (mode === 'csv' && typeof window.exportCSV === 'function') {
            window.exportCSV();
        }
    };
    if (typeof lastData !== 'undefined' && lastData) { open(); return; }
    if (typeof window.onControlChange !== 'function') { open(); return; }
    window.onControlChange();
    let tries = 0;
    const iv = setInterval(function () {
        tries++;
        if ((typeof lastData !== 'undefined' && lastData) || tries >= 12) {
            clearInterval(iv);
            open();
        }
    }, 150);
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
        ovDeselectRoom();
        setFacultyOnly(null); // back to “all faculties” together with “all rooms”
        return;
    }
    setRoomSelection(rid);
}

function ovDeselectRoom() {
    currentRoomId = null;
    updateSelectionUI();
}

// Deselect all faculties (and rooms, keeping the pair in sync).
function deselectAllFaculties() {
    setFacultyOnly(null);
    setRoomOnly(null);
    updateFacultySelectionUI();
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

// ── Faculty slicer (Live dashboard shared view) ──────────────────────────
function findRoomStatCard(rid) {
    const key = String(rid);
    let out = null;
    document.querySelectorAll('.rooms-card .stat-card:not(.faculty-stat-card)').forEach(function (c) {
        if (!out && c.getAttribute('data-room-id') === key) out = c;
    });
    return out;
}

function findFacultyStatCard(fid) {
    const key = String(fid);
    let out = null;
    document.querySelectorAll('.rooms-card.faculty-slice-card .stat-card').forEach(function (c) {
        if (!out && c.getAttribute('data-faculty-id') === key) out = c;
    });
    return out;
}

// Clicking a Faculty row single-selects it and cross-selects their room in
// the Room slicer + graphs (the shared analytics runtime drives the data).
function selectFacultyFromSlicer(card) {
    if (!card) return;
    const wasActive = card.classList.contains('active-room');
    const rid = parseInt(card.getAttribute('data-room-id') || '0', 10) || 0;

    document.querySelectorAll('.rooms-card .stat-card.active-room').forEach(function (c) {
        c.classList.remove('active-room');
    });

    const sub = document.getElementById('tabSubheading');
    if (wasActive) {
        if (typeof syncRoomSelect === 'function') syncRoomSelect(0);
        if (typeof onControlChange === 'function') onControlChange();
        if (sub) sub.textContent = 'All Rooms Selected';
        return;
    }

    card.classList.add('active-room');
    if (rid > 0) {
        const rc = findRoomStatCard(rid);
        if (rc) rc.classList.add('active-room');
    }
    if (typeof syncRoomSelect === 'function') syncRoomSelect(rid);
    if (typeof onControlChange === 'function') onControlChange();
    if (sub) {
        const nameEl = card.querySelector('.stat-value');
        sub.textContent = (nameEl ? nameEl.textContent : 'Faculty') + ' Selected';
    }
}

// ── Chart carousel (line <-> bar switcher) ───────────────────────────────
let chartCarouselIndex = 0;

function initChartCarousel() {
    const track = document.getElementById('chartTrack');
    if (!track) return;
    chartCarouselIndex = 0;
    track.classList.remove('at-bar');
    const left = document.getElementById('chartNavLeft');
    const right = document.getElementById('chartNavRight');
    if (left) left.classList.add('disabled');
    if (right) right.classList.remove('disabled');
    resizeChartInstances();
}

function resizeChartInstances() {
    if (typeof lineChartInstance !== 'undefined' && typeof lineChartInstance.resize === 'function') lineChartInstance.resize();
    if (typeof barChartInstance !== 'undefined' && typeof barChartInstance.resize === 'function') barChartInstance.resize();
}

function chartCarouselStep(dir) {
    const track = document.getElementById('chartTrack');
    if (!track) return;
    const total = track.children.length;
    const next = Math.max(0, Math.min(total - 1, chartCarouselIndex + dir));
    if (next === chartCarouselIndex) return;
    chartCarouselIndex = next;
    track.classList.toggle('at-bar', chartCarouselIndex > 0);
    const left = document.getElementById('chartNavLeft');
    const right = document.getElementById('chartNavRight');
    if (left) left.classList.toggle('disabled', chartCarouselIndex === 0);
    if (right) right.classList.toggle('disabled', chartCarouselIndex === total - 1);
    setTimeout(function () {
        let inst = null;
        if (chartCarouselIndex === 1 && typeof barChartInstance !== 'undefined') inst = barChartInstance;
        else if (chartCarouselIndex === 0 && typeof lineChartInstance !== 'undefined') inst = lineChartInstance;
        if (inst && typeof inst.resize === 'function') inst.resize();
    }, 480);
}

// ── Room Inspect modal — overview chart ───────────────────────────────────
let roomModalChartInstance = null;
let currentModalRoom = null;
let roomModalTimer = null; // 30s live re-sync while the room modal is open
let roomModalChartReq = 0; // guards against out-of-order fetch responses

function renderRoomModalChart(room) {
    const canvas = document.getElementById('roomModalChart');
    if (!canvas || !window.Chart) return;
    if (roomModalChartInstance) { roomModalChartInstance.destroy(); roomModalChartInstance = null; }

    const today = !!(room && room.todayLabels && room.todayLabels.length);
    const labelsArr = today ? (room.todayLabels || []) : (room.dailyLabels || []).slice(-7);
    let v = (today ? (room.todayV || []) : (room.dailyV || []).slice(-7)).slice();
    let a = (today ? (room.todayA || []) : (room.dailyA || []).slice(-7)).slice();
    let w = (today ? (room.todayW || []) : (room.dailyW || []).slice(-7)).slice();
    let labels = labelsArr.map(String);

    // Keep long per-minute "today" series legible inside the modal pane.
    const MAX_PTS = 120;
    if (labels.length > MAX_PTS) {
        const step = labels.length / MAX_PTS;
        const idxs = [];
        for (let i = 0; i < MAX_PTS; i++) idxs.push(Math.floor(i * step));
        const pick = (arr) => idxs.map(function (i) { return arr[i] == null ? null : arr[i]; });
        labels = idxs.map(function (i) { return labelsArr[i]; }).map(String);
        v = pick(v); a = pick(a); w = pick(w);
    }

    const n = Math.max(labels.length, v.length, a.length, w.length);
    const pad = (arr) => { const x = (arr || []).slice(); while (x.length < n) x.push(null); return x; };
    const name = (room && room.room_name) || 'Room';

    // Respect the metric currently filtered on the overview (All / V / A / W).
    const showMetric = (m) => currentMetric === 'all' || currentMetric === m;
    const hasData = n > 0;
    const datasets = [];
    if (hasData) {
        if (showMetric('voltage')) datasets.push({ label: name + ' \u00B7 Voltage (V)', data: pad(v), borderColor: '#742fd3', backgroundColor: 'rgba(116,47,211,0.10)', fill: true, tension: 0.3, pointRadius: 2 });
        if (showMetric('current')) datasets.push({ label: name + ' \u00B7 Current (A)', data: pad(a), borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.10)', fill: true, tension: 0.3, pointRadius: 2, yAxisID: 'y1' });
        if (showMetric('power'))   datasets.push({ label: name + ' \u00B7 Power (W)', data: pad(w), borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,0.10)', fill: true, tension: 0.3, pointRadius: 2, yAxisID: 'y2' });
    }
    if (!datasets.length) datasets.push({ label: 'No data', data: [], borderColor: '#cccccc', pointRadius: 0 });

    // Flagged issues for this room, aligned to the (possibly down-sampled) labels.
    const roomIssues = (OVERVIEW_ISSUES || []).filter(function (iss) { return iss.room_id != null && String(iss.room_id) === String(room && room.id); });
    roomModalIssuesMapped = mapIssuesToLabels(roomIssues, labels);

    const labelEl = document.getElementById('roomModalChartLabel');
    if (labelEl) labelEl.textContent = currentMetric === 'all' ? 'All Metrics' : (OV_METRIC_LABELS[currentMetric] || currentMetric);

    roomModalChartInstance = new Chart(canvas, {
        type: 'line',
        data: { labels: hasData ? labels : ['No data'], datasets },
        plugins: [roomModalIssuePlugin],
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            onClick: onRoomModalChartClick,
            plugins: {
                legend: { position: 'top', labels: { font: { family: 'Poppins', size: 10 }, boxWidth: 12, padding: 12 } },
            },
            scales: {
                x: { ticks: { color: '#4d4d4d', font: { family: 'Poppins', size: 10 } }, grid: { display: false } },
                y: { type: 'linear', display: true, position: 'left', title: { display: false }, ticks: { color: '#742fd3', font: { family: 'Poppins', size: 10 } }, grid: { color: 'rgba(47,0,79,0.07)' } },
                y1: { type: 'linear', display: true, position: 'left', title: { display: false }, ticks: { color: '#f59e0b', font: { family: 'Poppins', size: 10 } }, grid: { display: false } },
                y2: { type: 'linear', display: true, position: 'right', title: { display: false }, ticks: { color: '#16a34a', font: { family: 'Poppins', size: 10 } }, grid: { display: false } },
            },
        },
    });
}

// ── Flagged issue annotations on the overview + inspect charts ───────────────
let overviewIssuesMapped = [];
let roomModalIssuesMapped = [];

// Align issues to chart labels by matching their "HH:MM" time to the nearest
// per-minute label index (works for both full and down-sampled series).
function mapIssuesToLabels(issues, labels) {
    const mapped = new Array(labels.length).fill(null);
    if (!issues || !issues.length || !labels || !labels.length) return mapped;
    const minLabels = labels.map(l => (/^\d{2}:\d{2}$/.test(l) ? ganttTimeToMin(l) : -1));
    issues.forEach(issue => {
        const tMin = ganttTimeToMin(String(issue.event_time || '').slice(11, 16));
        if (tMin < 0) return;
        let best = -1, bestDiff = Infinity;
        minLabels.forEach((ml, i) => {
            if (ml < 0) return;
            const d = Math.abs(ml - tMin);
            if (d < bestDiff) { bestDiff = d; best = i; }
        });
        if (best >= 0) mapped[best] = issue;
    });
    return mapped;
}

// Issues shown on the overview chart honor the current room selection.
function mapOverviewIssues(labels) {
    overviewIssuesMapped = [];
    if (!OVERVIEW_ISSUES || !OVERVIEW_ISSUES.length) return;
    let issues = OVERVIEW_ISSUES;
    if (currentRoomId === 0) issues = [];
    else if (currentRoomId > 0) issues = issues.filter(iss => String(iss.room_id) === String(currentRoomId));
    overviewIssuesMapped = mapIssuesToLabels(issues, labels);
}

function getMappedIssueIndex(e, chart, mapped) {
    if (!mapped || !mapped.length) return null;
    const chartArea = chart.chartArea;
    if (!chartArea) return null;
    const pos = Chart.helpers.getRelativePosition(e, chart);
    if (pos.x < chartArea.left || pos.x > chartArea.right) return null;
    if (pos.y < chartArea.top || pos.y > chartArea.bottom) return null;
    const xScale = chart.scales.x;
    if (!xScale) return null;
    let best = null, bestDist = 16;
    mapped.forEach(function (issue, i) {
        if (!issue) return;
        const px = xScale.getPixelForValue(i);
        const d = Math.abs(px - pos.x);
        if (d < bestDist) { bestDist = d; best = i; }
    });
    return best;
}

const overviewIssuePlugin = {
    id: 'overviewIssueMarker',
    afterDraw: function (chart) {
        if (!overviewIssuesMapped || !overviewIssuesMapped.length) return;
        const chartArea = chart.chartArea;
        const xScale = chart.scales.x;
        if (!chartArea || !xScale) return;
        const ctx = chart.ctx;
        for (let i = 0; i < overviewIssuesMapped.length; i++) {
            if (!overviewIssuesMapped[i]) continue;
            const x = xScale.getPixelForValue(i);
            if (x < chartArea.left || x > chartArea.right) continue;
            drawIssueMarker(ctx, x, chartArea.top + 12);
        }
    }
};

const roomModalIssuePlugin = {
    id: 'roomModalIssueMarker',
    afterDraw: function (chart) {
        if (!roomModalIssuesMapped || !roomModalIssuesMapped.length) return;
        const chartArea = chart.chartArea;
        const xScale = chart.scales.x;
        if (!chartArea || !xScale) return;
        const ctx = chart.ctx;
        for (let i = 0; i < roomModalIssuesMapped.length; i++) {
            if (!roomModalIssuesMapped[i]) continue;
            const x = xScale.getPixelForValue(i);
            if (x < chartArea.left || x > chartArea.right) continue;
            drawIssueMarker(ctx, x, chartArea.top + 12);
        }
    }
};

function onOverviewChartClick(e, active, chart) {
    const idx = getMappedIssueIndex(e, chart, overviewIssuesMapped);
    if (idx != null && overviewIssuesMapped[idx]) openIssueModal(overviewIssuesMapped[idx]);
}

function onRoomModalChartClick(e, active, chart) {
    const idx = getMappedIssueIndex(e, chart, roomModalIssuesMapped);
    if (idx != null && roomModalIssuesMapped[idx]) openIssueModal(roomModalIssuesMapped[idx]);
}

// ── Mini Gantt under the overview chart pane (untoggled / Today view) ────────
// The time axis mirrors the overview chart's per-minute labels and follows the
// chart's scrollbar window, so the session rows line up with the plotted data.
function renderOverviewMiniGantt() {
    const container = document.getElementById('overviewMiniGantt');
    const wrap = document.getElementById('overviewMiniGanttWrap');
    const axisEl = document.getElementById('overviewMiniGanttAxis');
    if (!container || !wrap) return;

    const labels = (overviewLabels && overviewLabels.length)
        ? overviewLabels
        : (CHART_TODAY || []).map(r => r.label);
    const isMinute = labels.length && /^\d{2}:\d{2}$/.test(labels[labels.length - 1] || '');

    if (currentPeriod !== 1 || !isMinute || !labels.length) {
        wrap.style.display = 'none';
        return;
    }

    const startIdx = Math.max(0, Math.min(overviewScrollOffset || 0, labels.length - 1));
    const endIdx = Math.min(startIdx + OVERVIEW_WINDOW_SIZE, labels.length);
    const visStartMin = ganttTimeToMin(labels[startIdx]);
    const visEndMin = endIdx < labels.length ? ganttTimeToMin(labels[endIdx]) : visStartMin + OVERVIEW_WINDOW_SIZE;
    const spanMin = Math.max(visEndMin - visStartMin, 1);

    const todayName = new Date().toLocaleDateString('en-US', { weekday: 'long' });
    const nowMin = ganttTimeToMin(new Date().toTimeString().slice(0, 8));

    const rows = [];
    (FACULTY || []).forEach(f => {
        const todayScheds = ((FACULTY_SCHEDULES || {})[f.id] || []).filter(s => s.day_of_week === todayName);
        if (todayScheds.length) rows.push({ f: f, scheds: todayScheds });
    });

    if (!rows.length) {
        wrap.style.display = 'none';
        return;
    }
    wrap.style.display = '';

    const labelW = 150;
    const paneW = container.clientWidth || 600;
    const dayW = Math.max(paneW - labelW, 260);
    const rowH = 40;
    const headerH = 26;
    const pxPerMin = dayW / spanMin;

    if (axisEl) axisEl.textContent = fmtGanttTime(visStartMin) + ' – ' + fmtGanttTime(visEndMin) + ' \u00B7 synced to the chart window';

    let html = '<div class="mini-gantt-grid">';
    html += '<div class="mini-gantt-labels">';
    html += '<div class="mini-gantt-cell mini-gantt-head" style="width:' + labelW + 'px;height:' + headerH + 'px;">Faculty</div>';
    rows.forEach(r => {
        html += '<div class="mini-gantt-cell" style="width:' + labelW + 'px;height:' + rowH + 'px;">' +
            '<div class="gantt-fac-name">' + escapeHtml(r.f.first_name + ' ' + r.f.last_name) + '</div>' +
            '<div class="gantt-fac-dept">' + escapeHtml(r.f.department_name || '') + '</div></div>';
    });
    html += '</div>';

    html += '<div class="mini-gantt-timeline">';
    html += '<div class="mini-gantt-ticks" style="height:' + headerH + 'px;">';
    for (let m = visStartMin; m <= visEndMin; m++) {
        const left = (m - visStartMin) * pxPerMin;
        const isLabel = (m % 5 === 0) || m === visStartMin || m === visEndMin;
        html += '<span class="mini-gantt-tick' + (isLabel ? ' mini-gantt-tick-labeled' : '') + '" style="left:' + left + 'px;">' +
            (isLabel ? fmtGanttTime(m).replace(' ', '') : '') + '</span>';
    }
    html += '</div>';

    if (nowMin >= visStartMin && nowMin <= visEndMin) {
        const nowX = (nowMin - visStartMin) * pxPerMin;
        html += '<div class="gantt-now-line" style="left:' + nowX + 'px;"><i class="gantt-now-tag">Current</i></div>';
    }

    rows.forEach((r, fi) => {
        html += '<div class="mini-gantt-row' + (fi % 2 ? ' mini-gantt-zebra' : '') + '" style="height:' + rowH + 'px;">';
        r.scheds.forEach(s => {
            const startMin = ganttTimeToMin(s.start_time);
            const baseEnd = ganttTimeToMin(s.end_time);
            const extEnd = ganttTimeToMin(s.extended_until);
            const endMin = Math.max(extEnd, baseEnd);
            const clipStart = Math.max(startMin, visStartMin);
            const clipEnd = Math.min(endMin, visEndMin);
            if (clipEnd <= clipStart) return;
            const left = (clipStart - visStartMin) * pxPerMin;
            const width = Math.max((clipEnd - clipStart) * pxPerMin, 4);
            const isNow = startMin <= nowMin && nowMin <= endMin;
            const isPast = endMin < nowMin;
            const isExtended = extEnd > baseEnd;
            const cls = isExtended ? 'gantt-block-extended' : (isNow ? 'gantt-block-now' : (isPast ? 'gantt-block-past' : 'gantt-block-upcoming'));
            html += '<div class="gantt-block mini-gantt-block ' + cls + '" style="left:' + left + 'px;width:' + width + 'px;" ' +
                'data-subject="' + escapeHtml(s.subject_name || 'Class') + '" ' +
                'data-room="' + escapeHtml(s.room_name || '\u2014') + '" ' +
                'data-time="' + escapeHtml(fmtGanttTime(startMin) + ' \u2013 ' + fmtGanttTime(endMin)) + '">' +
                '<span class="gantt-block-subject">' + escapeHtml(s.subject_name || 'Class') + '</span></div>';
        });
        html += '</div>';
    });
    html += '</div>'; // timeline
    html += '</div>'; // grid

    container.innerHTML = html;
}

// ── Faculty info + access control modal ─────────────────────────────────────
function openFacultyInfoModal(id) {
    const fac = (FACULTY || []).find(f => parseInt(f.id, 10) === parseInt(id, 10));
    if (!fac) return;

    const initials = ((fac.first_name || '') + ' ' + (fac.last_name || '')).split(/\s+/).filter(Boolean).slice(0, 2).map(w => (w[0] || '')).join('').toUpperCase();
    const avatar = document.getElementById('facInfoAvatar');
    if (avatar) avatar.textContent = initials || '?';

    const nameEl = document.getElementById('facInfoName');
    if (nameEl) nameEl.textContent = (fac.first_name || '') + ' ' + (fac.last_name || '');

    const roleEl = document.getElementById('facInfoRole');
    if (roleEl) {
        roleEl.textContent = fac.is_archived ? 'Archived' : 'Active';
        roleEl.className = 'bold status-badge ' + (fac.is_archived ? 'faculty-archived' : 'faculty-member');
    }

    const covEl = document.getElementById('facInfoCoverage');
    if (covEl) {
        const parts = [];
        if (fac.department_name) parts.push('<i class="bi bi-briefcase me-1"></i>' + escapeHtml(fac.department_name));
        if (fac.email) parts.push('<i class="bi bi-envelope me-1"></i>' + escapeHtml(fac.email));
        covEl.innerHTML = parts.join(' &nbsp;&middot;&nbsp; ') || 'No coverage details.';
    }

    const roomEl = document.getElementById('facInfoRoom');
    if (roomEl) {
        roomEl.innerHTML = fac.classroom_name
            ? '<i class="bi bi-door-open me-1"></i>' + escapeHtml(fac.classroom_name) + (fac.subject_name ? ' &middot; ' + escapeHtml(fac.subject_name) : '')
            : (fac.is_archived ? 'No Class' : 'No Active Class');
    }

    const schedEl = document.getElementById('facInfoSchedule');
    if (schedEl) {
        const fmt24 = (t) => { if (!t) return ''; const p = String(t).split(':'); return p.slice(0, 2).join(':'); };
        const classEnd = fac.extended_until ? fmt24(fac.extended_until) : (fac.end_time ? fmt24(fac.end_time) : '');
        const lines = [];
        if (fac.current_class) lines.push('<div><span class="text-muted">Now:</span> ' + escapeHtml(fac.current_class) + '</div>');
        if (fac.next_class) lines.push('<div><span class="text-muted">Next:</span> ' + escapeHtml(fac.next_class) + '</div>');
        if (fac.start_time) lines.push('<div><span class="text-muted">Class:</span> ' + escapeHtml(fmt24(fac.start_time)) + ' \u2013 ' + escapeHtml(classEnd) + '</div>');
        if (fac.approved_on) lines.push('<div><span class="text-muted">Approved:</span> ' + escapeHtml(fac.approved_on) + '</div>');
        schedEl.innerHTML = lines.join('') || '<em class="text-muted">No schedule today.</em>';
    }

    window.lumiFacultyId = parseInt(id, 10);
    const perms = FACULTY_PERMS[id] || {};
    const lc = document.getElementById('facInfoSwitchLighting');
    const gc = document.getElementById('facInfoSwitchGesture');
    if (lc) lc.checked = perms.lighting_control !== false;
    if (gc) gc.checked = perms.gesture_control !== false;

    const modal = new bootstrap.Modal(document.getElementById('facultyInfoModal'));
    modal.show();
}

function saveFacultyPermission(permission, value) {
    const form = new FormData();
    form.append('faculty_id', window.lumiFacultyId || 0);
    form.append('permission', permission);
    form.append('value', value ? 1 : 0);
    fetch('../../api/permissions.php', {
        method: 'POST',
        body: form
    })
        .then(r => r.json())
        .then(data => {
            if (!data.success) revertFacultyPermissionSwitch(permission, value);
        })
        .catch(function () {
            revertFacultyPermissionSwitch(permission, value);
        });
}

// Revert every permission toggle (info + view modals) when a save fails.
function revertFacultyPermissionSwitch(permission, value) {
    const id = permission === 'lighting_control' ? 'Lighting' : 'Gesture';
    ['facInfoSwitch' + id, 'facViewSwitch' + id].forEach(function (pid) {
        const el = document.getElementById(pid);
        if (el) el.checked = !value;
    });
}

// ── Faculty view modal (schedule overview + access + activity) ───────────────
let facultyViewChartInstance = null;
let currentFacultyView = null;
let facultyViewTimer = null; // 30s live re-sync while the modal is open
let facultyViewChartReq = 0; // guards against out-of-order fetch responses

function openFacultyViewModal(id) {
    const fac = (FACULTY || []).find(f => parseInt(f.id, 10) === parseInt(id, 10));
    if (!fac) return;
    currentFacultyView = fac;
    window.lumiFacultyId = parseInt(id, 10);

    const label = document.getElementById('facultyViewModalLabel');
    if (label) label.textContent = (fac.first_name || '') + ' ' + (fac.last_name || '') + ' · Faculty Schedule';

    const modalEl = document.getElementById('facultyViewModal');
    if (modalEl && !modalEl.dataset.facViewTimerBound) {
        modalEl.dataset.facViewTimerBound = '1';
        modalEl.addEventListener('hidden.bs.modal', function () {
            if (facultyViewTimer) { clearInterval(facultyViewTimer); facultyViewTimer = null; }
        });
    }
    if (facultyViewTimer) { clearInterval(facultyViewTimer); facultyViewTimer = null; }
    facultyViewTimer = setInterval(refreshFacultyViewLive, 30000);

    new bootstrap.Modal(modalEl).show();
    renderFacultyView(fac);
    refreshFacultyViewLive(); // paint real readings for the extended minutes immediately
}

// Lightweight live refresh while the modal is open: fetch fresh per-minute
// readings for the faculty's room and re-render the chart + mini-gantt so a
// running session keeps tracking. Access/timetable/activities are untouched.
// Falls back to the local extend-to-now behavior when the fetch fails.
function refreshFacultyViewLive() {
    const fac = currentFacultyView;
    if (!fac) return;
    const room = (ROOMS || []).find(r => r.room_name === fac.classroom_name) || null;
    const req = ++facultyViewChartReq;
    fetchRoomTodaySeries(room ? room.id : 0).then(function (today) {
        if (req !== facultyViewChartReq) return; // stale response
        const roomNow = today && room
            ? extendRoomTodayToNow(roomWithFreshToday(room, today))
            : extendRoomTodayToNow(room);
        renderFacultyViewChart(fac, roomNow);
        renderFacultyViewMiniGantt(fac, roomNow);
    });
    // Fresh activities for the recent-activities card (keep current on failure).
    fetchFacultyActivity(fac.id).then(function (logs) {
        if (req !== facultyViewChartReq || !logs) return;
        renderFacultyViewActivities(fac, logs);
    });
}

// Extend the room's per-minute "today" series up to the current minute
// (labels padded, values null) so sessions that started after page load still
// appear in the modal chart and its synced mini-gantt. Returns a fresh object
// and never mutates the shared ROOMS entry.
function extendRoomTodayToNow(room) {
    if (!room || !room.todayLabels || !room.todayLabels.length) return room;
    const nowMin = ganttTimeToMin(new Date().toTimeString().slice(0, 8));
    const lastMin = ganttTimeToMin(room.todayLabels[room.todayLabels.length - 1]);
    if (nowMin <= lastMin) return room; // series already reaches the current minute
    const labels = room.todayLabels.slice();
    const v = room.todayV ? room.todayV.slice() : [];
    const a = room.todayA ? room.todayA.slice() : [];
    const w = room.todayW ? room.todayW.slice() : [];
    for (let m = lastMin + 1; m <= nowMin; m++) {
        labels.push(String(Math.floor(m / 60) % 24).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0'));
        v.push(null);
        a.push(null);
        w.push(null);
    }
    return Object.assign({}, room, { todayLabels: labels, todayV: v, todayA: a, todayW: w });
}

// Fetch today's per-minute series for one room (padded to the current minute
// server-side). Returns null on failure so callers can fall back to the
// local extend-to-now behavior.
function fetchRoomTodaySeries(cid) {
    return fetch('../../api/overview-chart.php?classroom_id=' + encodeURIComponent(cid), {
        headers: { 'Accept': 'application/json' }
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            return (data && data.ok && Array.isArray(data.today)) ? data.today : null;
        })
        .catch(function () { return null; });
}

// Fetch the merged log feed for one room (get-room-logs.php). Null on failure.
function fetchRoomAlerts(roomName) {
    return fetch('../../api/get-room-logs.php?room=' + encodeURIComponent(roomName), {
        headers: { 'Accept': 'application/json' }
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            return (data && data.success && Array.isArray(data.data)) ? data.data : null;
        })
        .catch(function () { return null; });
}

// Fetch the latest lighting-log activities for one faculty member
// (activity-logs.php?faculty_id=). Null on failure.
function fetchFacultyActivity(fid) {
    return fetch('../../api/activity-logs.php?faculty_id=' + encodeURIComponent(fid), {
        headers: { 'Accept': 'application/json' }
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            return (data && data.success && Array.isArray(data.faculty)) ? data.faculty : null;
        })
        .catch(function () { return null; });
}

// Replace a room's today series with freshly-fetched per-minute rows.
function roomWithFreshToday(room, todayRows) {
    if (!room || !todayRows || !todayRows.length) return room;
    const labels = [];
    const v = [];
    const a = [];
    const w = [];
    todayRows.forEach(function (r) {
        labels.push(r.label);
        v.push(r.avg_voltage != null ? r.avg_voltage : null);
        a.push(r.avg_current != null ? r.avg_current : null);
        w.push(r.avg_power != null ? r.avg_power : null);
    });
    return Object.assign({}, room, { todayLabels: labels, todayV: v, todayA: a, todayW: w });
}

function renderFacultyView(fac) {
    const room = (ROOMS || []).find(r => r.room_name === fac.classroom_name) || null;
    const roomNow = extendRoomTodayToNow(room);
    renderFacultyViewChart(fac, roomNow);
    renderFacultyViewMiniGantt(fac, roomNow);
    renderFacultyViewAccess(fac);
    renderFacultyViewTimetable(fac);
    renderFacultyViewActivities(fac);
}

// Chart pane for the faculty view modal — the faculty's own daily energy series
// from the faculty_energy_daily rollup (session-attributed). Metrics map to
// daily aggregates: energy (Wh), avg_voltage, avg_current, peak_power.
function renderFacultyViewChart(fac, room) {
    const canvas = document.getElementById('facultyViewChart');
    if (!canvas || !window.Chart) return;
    if (facultyViewChartInstance) { facultyViewChartInstance.destroy(); facultyViewChartInstance = null; }

    const series = facultyEnergySeries(fac.id, 7);
    const daily = (FACULTY_DAILY || {})[fac.id] || {};
    const labels = series.map(s => s.label);
    const e = series.map(s => s.energy_wh);
    const v = labels.map(k => daily[k] && daily[k].avg_voltage != null ? parseFloat(daily[k].avg_voltage) : null);
    const a = labels.map(k => daily[k] && daily[k].avg_current != null ? parseFloat(daily[k].avg_current) : null);
    const w = labels.map(k => daily[k] && daily[k].peak_power != null ? parseFloat(daily[k].peak_power) : null);

    const name = (fac.first_name || '') + ' ' + (fac.last_name || '');

    const showMetric = (m) => currentMetric === 'all' || currentMetric === m;
    const datasets = [];
    if (showMetric('all') || currentMetric === 'voltage') datasets.push({ label: name + ' · Energy (Wh)', data: e, borderColor: '#742fd3', backgroundColor: 'rgba(116,47,211,0.10)', fill: true, tension: 0.3, pointRadius: 2 });
    if (showMetric('voltage')) datasets.push({ label: name + ' · Avg Voltage (V)', data: v, borderColor: '#742fd3', backgroundColor: 'rgba(116,47,211,0.10)', fill: true, tension: 0.3, pointRadius: 2 });
    if (showMetric('current')) datasets.push({ label: name + ' · Avg Current (A)', data: a, borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.10)', fill: true, tension: 0.3, pointRadius: 2, yAxisID: 'y1' });
    if (showMetric('power'))   datasets.push({ label: name + ' · Peak Power (W)', data: w, borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,0.10)', fill: true, tension: 0.3, pointRadius: 2, yAxisID: 'y2' });
    if (!datasets.length) datasets.push({ label: 'No data', data: [], borderColor: '#cccccc', pointRadius: 0 });

    const labelEl = document.getElementById('facultyViewChartLabel');
    if (labelEl) labelEl.textContent = currentMetric === 'all' ? 'Energy (Wh) · Daily' : (OV_METRIC_LABELS[currentMetric] || currentMetric);

    const hasData = e.some(x => x > 0) || v.some(x => x != null) || a.some(x => x != null) || w.some(x => x != null);
    facultyViewChartInstance = new Chart(canvas, {
        type: 'line',
        data: { labels: hasData ? labels : ['No data'], datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: {
                legend: { position: 'top', labels: { font: { family: 'Poppins', size: 10 }, boxWidth: 12, padding: 12 } },
            },
            scales: {
                x: { ticks: { color: '#4d4d4d', font: { family: 'Poppins', size: 10 } }, grid: { display: false } },
                y: { type: 'linear', display: true, position: 'left', title: { display: false }, ticks: { color: '#742fd3', font: { family: 'Poppins', size: 10 } }, grid: { color: 'rgba(47,0,79,0.07)' } },
                y1: { type: 'linear', display: true, position: 'left', title: { display: false }, ticks: { color: '#f59e0b', font: { family: 'Poppins', size: 10 } }, grid: { display: false } },
                y2: { type: 'linear', display: true, position: 'right', title: { display: false }, ticks: { color: '#16a34a', font: { family: 'Poppins', size: 10 } }, grid: { display: false } },
            },
        },
    });
}

// Mini Gantt inside the faculty view modal — a single row for the selected
// faculty, aligned to the same x-axis span as the modal chart above it.
function renderFacultyViewMiniGantt(fac, room) {
    const container = document.getElementById('facultyViewMiniGantt');
    const wrap = document.getElementById('facultyViewMiniGanttWrap');
    const axisEl = document.getElementById('facultyViewMiniGanttAxis');
    if (!container || !wrap) return;

    const labels = (room && room.todayLabels) || [];
    const isMinute = labels.length && /^\d{2}:\d{2}$/.test(labels[labels.length - 1] || '');
    if (!isMinute || !labels.length) {
        wrap.style.display = 'none';
        return;
    }

    const visStartMin = ganttTimeToMin(labels[0]);
    const visEndMin = ganttTimeToMin(labels[labels.length - 1]) + 1;
    const spanMin = Math.max(visEndMin - visStartMin, 1);

    const todayName = new Date().toLocaleDateString('en-US', { weekday: 'long' });
    const nowMin = ganttTimeToMin(new Date().toTimeString().slice(0, 8));
    const scheds = ((FACULTY_SCHEDULES || {})[fac.id] || []).filter(s => s.day_of_week === todayName);

    if (!scheds.length) {
        wrap.style.display = 'none';
        return;
    }
    wrap.style.display = '';

    const labelW = 150;
    const paneW = container.clientWidth || 600;
    const dayW = Math.max(paneW - labelW, 260);
    const rowH = 40;
    const headerH = 26;
    const pxPerMin = dayW / spanMin;

    if (axisEl) axisEl.textContent = fmtGanttTime(visStartMin) + ' – ' + fmtGanttTime(visEndMin) + ' · synced to the chart';

    let html = '<div class="mini-gantt-grid">';
    html += '<div class="mini-gantt-labels">';
    html += '<div class="mini-gantt-cell mini-gantt-head" style="width:' + labelW + 'px;height:' + headerH + 'px;">Faculty</div>';
    html += '<div class="mini-gantt-cell" style="width:' + labelW + 'px;height:' + rowH + 'px;">' +
        '<div class="gantt-fac-name">' + escapeHtml(fac.first_name + ' ' + fac.last_name) + '</div>' +
        '<div class="gantt-fac-dept">' + escapeHtml(fac.department_name || '') + '</div></div>';
    html += '</div>';

    html += '<div class="mini-gantt-timeline">';
    html += '<div class="mini-gantt-ticks" style="height:' + headerH + 'px;">';
    for (let m = visStartMin; m <= visEndMin; m++) {
        const left = (m - visStartMin) * pxPerMin;
        const isLabel = (m % 5 === 0) || m === visStartMin || m === visEndMin;
        html += '<span class="mini-gantt-tick' + (isLabel ? ' mini-gantt-tick-labeled' : '') + '" style="left:' + left + 'px;">' +
            (isLabel ? fmtGanttTime(m).replace(' ', '') : '') + '</span>';
    }
    html += '</div>';

    if (nowMin >= visStartMin && nowMin <= visEndMin) {
        const nowX = (nowMin - visStartMin) * pxPerMin;
        html += '<div class="gantt-now-line" style="left:' + nowX + 'px;"><i class="gantt-now-tag">Current</i></div>';
    }

    html += '<div class="mini-gantt-row" style="height:' + rowH + 'px;">';
    scheds.forEach(s => {
        const startMin = ganttTimeToMin(s.start_time);
        const baseEnd = ganttTimeToMin(s.end_time);
        const extEnd = ganttTimeToMin(s.extended_until);
        const endMin = Math.max(extEnd, baseEnd);
        const clipStart = Math.max(startMin, visStartMin);
        const clipEnd = Math.min(endMin, visEndMin);
        if (clipEnd <= clipStart) return;
        const left = (clipStart - visStartMin) * pxPerMin;
        const width = Math.max((clipEnd - clipStart) * pxPerMin, 4);
        const isNow = startMin <= nowMin && nowMin <= endMin;
        const isPast = endMin < nowMin;
        const isExtended = extEnd > baseEnd;
        const cls = isExtended ? 'gantt-block-extended' : (isNow ? 'gantt-block-now' : (isPast ? 'gantt-block-past' : 'gantt-block-upcoming'));
        html += '<div class="gantt-block mini-gantt-block ' + cls + '" style="left:' + left + 'px;width:' + width + 'px;" ' +
            'data-subject="' + escapeHtml(s.subject_name || 'Class') + '" ' +
            'data-room="' + escapeHtml(s.room_name || '—') + '" ' +
            'data-time="' + escapeHtml(fmtGanttTime(startMin) + ' – ' + fmtGanttTime(endMin)) + '">' +
            '<span class="gantt-block-subject">' + escapeHtml(s.subject_name || 'Class') + '</span></div>';
    });
    html += '</div>';
    html += '</div>'; // timeline
    html += '</div>'; // grid

    container.innerHTML = html;
}

// Access-control toggles (same permissions as the faculty info modal).
function renderFacultyViewAccess(fac) {
    const perms = FACULTY_PERMS[fac.id] || {};
    const lc = document.getElementById('facViewSwitchLighting');
    const gc = document.getElementById('facViewSwitchGesture');
    if (lc) lc.checked = perms.lighting_control !== false;
    if (gc) gc.checked = perms.gesture_control !== false;
}

// Weekly timetable for this faculty member only.
function renderFacultyViewTimetable(fac) {
    const el = document.getElementById('facultyViewTimetable');
    if (!el) return;
    const dayOrder = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const grouped = {};
    dayOrder.forEach(d => grouped[d] = []);
    ((FACULTY_SCHEDULES || {})[fac.id] || []).forEach(s => {
        if (grouped[s.day_of_week]) grouped[s.day_of_week].push(s);
    });
    const hasAny = dayOrder.some(d => grouped[d].length);
    if (!hasAny) {
        el.innerHTML = '<div class="modal-slot-empty">No classes scheduled for this faculty member.</div>';
        return;
    }
    const todayName = new Date().toLocaleDateString('en-US', { weekday: 'long' });
    const fmt12 = (t) => { const m = ganttTimeToMin(t); return fmtGanttTime(m); };
    el.innerHTML = '<div class="weekly-schedule-grid" style="min-width:max-content;">' + dayOrder.map(day => {
        const slots = grouped[day] || [];
        const slotsHtml = slots.length
            ? slots.map(s => `<div class="slot-row">
                <div class="slot-time"><span class="slot-time-start">${escapeHtml(fmt12(s.start_time))}</span><span class="slot-time-separator">TO</span><span class="slot-time-end">${escapeHtml(fmt12(s.end_time))}</span></div>
                <div class="slot-content"><div class="slot-room"><i class="bi bi-door-open me-1"></i>${escapeHtml(s.room_name || '—')}${s.subject_name ? ' · ' + escapeHtml(s.subject_name) : ''}</div></div>
            </div>`).join('')
            : '<p class="no-sched">No classes.</p>';
        return `<div class="day-card${day === todayName ? ' today' : ''}"><div class="day-label">${day}${day === todayName ? ' · Today' : ''}</div>${slotsHtml}</div>`;
    }).join('') + '</div>';
}

// Recent activities for this faculty member (lighting_logs, latest 10).
// Accepts freshly-fetched logs; falls back to the page-load FACULTY_ACTIVITY.
function renderFacultyViewActivities(fac, freshLogs) {
    const el = document.getElementById('facultyViewActivities');
    if (!el) return;
    const logs = freshLogs || (FACULTY_ACTIVITY || {})[fac.id] || [];
    if (logs.length) {
        el.innerHTML = logs.map(a => {
            const icon = alertIconMap(a.event_type);
            const label = (a.event_type || '').replace(/_/g, ' ');
            const rowTxt = a.row_affected ? ' · Row ' + escapeHtml(a.row_affected) : '';
            return `<div class="modal-timeline-item">
                <div class="modal-tl-icon" style="background:${icon[2]};color:${icon[1]};"><i class="bi ${icon[0]}"></i></div>
                <div class="modal-tl-body">
                    <p class="modal-tl-action">${label.charAt(0).toUpperCase() + label.slice(1)}${rowTxt}</p>
                    <div class="modal-tl-meta"><span><i class="bi bi-clock"></i> ${escapeHtml(displayLogTime(a.event_time))}</span><span class="modal-tl-badge" style="background:${icon[2]};color:${icon[1]};">${escapeHtml(a.room_name || 'system')}</span></div>
                </div>
            </div>`;
        }).join('');
    } else {
        el.innerHTML = '<div class="modal-slot-empty">No activity recorded for this faculty member.</div>';
    }
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
// CSV/PDF export of the analytics charts lives in admin-analytics.js
// (shared runtime); the overview heading exposes its own actions instead.

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
    currentModalRoom = room;
    document.getElementById('roomModalLabel').textContent = room.room_name || 'Room Details';

    const modalEl = document.getElementById('roomModal');
    if (modalEl && !modalEl.dataset.roomModalTimerBound) {
        modalEl.dataset.roomModalTimerBound = '1';
        modalEl.addEventListener('hidden.bs.modal', function () {
            if (roomModalTimer) { clearInterval(roomModalTimer); roomModalTimer = null; }
        });
    }
    if (roomModalTimer) { clearInterval(roomModalTimer); roomModalTimer = null; }
    roomModalTimer = setInterval(refreshRoomModalLive, 30000);

    new bootstrap.Modal(modalEl).show();
    renderRoomModalFrom(room);
    refreshRoomModalLive(); // paint real readings for the extended minutes immediately
}

function renderRoomModalFrom(room) {
    renderRoomModalChart(extendRoomTodayToNow(room));
    renderModalCurrentSched(room);

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

    // Alerts (fresh data replaces this on the 30s live tick)
    renderRoomAlerts(document.getElementById('modalAlertsPreview'), room.alerts || []);

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

const DAY_IDX = { Sunday: 0, Monday: 1, Tuesday: 2, Wednesday: 3, Thursday: 4, Friday: 5, Saturday: 6 };

// Parse a "g:i A" formatted time (PHP fmtTime) into minutes since midnight.
function parseFmtTime(t) {
    const m = String(t || '').match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i);
    if (!m) return -1;
    let h = parseInt(m[1], 10) % 12;
    if (/pm/i.test(m[3])) h += 12;
    return h * 60 + parseInt(m[2], 10);
}

// Live "Current Schedule" card for the room modal — recomputed from the room's
// weekly schedules + the current time so it can't go stale mid-day.
function renderModalCurrentSched(room) {
    const schedEl = document.getElementById('modalCurrentSched');
    if (!schedEl) return;
    const now = new Date();
    const nowMin = now.getHours() * 60 + now.getMinutes();
    const todayIdx = now.getDay();
    const todayName = now.toLocaleDateString('en-US', { weekday: 'long' });
    const scheds = (room.schedules || []);

    let current = null;
    let nextToday = null;
    let nextAny = null;
    scheds.forEach(s => {
        const start = parseFmtTime(s.start_time);
        const end = parseFmtTime(s.end_time);
        if (s.day_of_week === todayName) {
            if (start >= 0 && end >= 0 && start <= nowMin && nowMin <= end && !current) current = s;
            if (start >= 0 && start > nowMin && (!nextToday || start < parseFmtTime(nextToday.start_time))) nextToday = s;
        }
        const idx = DAY_IDX[s.day_of_week];
        if (idx !== undefined && start >= 0) {
            let offset = idx - todayIdx;
            if (offset < 0) offset += 7;
            if (offset === 0 && start <= nowMin) offset = 7; // today's slot already passed
            if (!nextAny || offset < nextAny.offset || (offset === nextAny.offset && start < nextAny.startMin)) {
                nextAny = { s: s, offset: offset, startMin: start };
            }
        }
    });

    if (current) {
        const fac = current.faculty_name || '';
        schedEl.innerHTML = `<div class="d-flex align-items-start gap-3">
            <div class="avatar-icon d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;font-size:1rem;"><span class="bold">${initialsOf(fac)}</span></div>
            <div style="flex:1;min-width:0;">
                <p class="bold mb-0" style="font-size:.9rem;">${escapeHtml(fac)}</p>
                <small class="text-muted">Faculty Member</small>
                <div style="font-size:.9rem;font-weight:600;margin-top:.15rem;">${escapeHtml(current.start_time)} – ${escapeHtml(current.end_time)}</div>
                <div style="font-size:.82rem;margin-top:2px;"><span class="badge-occupied" style="padding:2px 8px;border-radius:10px;font-weight:700;font-size:10px;">OCCUPIED</span></div>
            </div>
        </div>`;
        return;
    }

    let nextLabel = '';
    if (nextToday) {
        nextLabel = escapeHtml(nextToday.start_time) + ' – ' + escapeHtml(nextToday.end_time);
    } else if (nextAny) {
        const d = new Date();
        d.setDate(d.getDate() + nextAny.offset);
        nextLabel = escapeHtml(d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) + ' · ' + nextAny.s.start_time);
    }

    if (nextLabel) {
        schedEl.innerHTML = `<div class="d-flex align-items-start gap-3">
            <div class="avatar-icon d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;font-size:1rem;background:#fff5d6;color:#a06800;"><i class="bi bi-calendar-event" style="font-size:1.2rem;"></i></div>
            <div style="flex:1;min-width:0;">
                <span style="display:inline-block;background:#fff5d6;color:#a06800;padding:2px 8px;border-radius:10px;font-weight:700;font-size:10px;margin-bottom:6px;">SCHEDULED</span>
                <div style="font-size:.9rem;font-weight:600;">${nextLabel}</div>
            </div>
        </div>`;
        return;
    }

    schedEl.innerHTML = `<div>
        <span style="background:#d6fbe9;color:#0a7a45;padding:2px 8px;border-radius:10px;font-weight:700;font-size:10px;">VACANT</span>
        <p class="text-muted mt-2 mb-0" style="font-size:.85rem;">No classes scheduled.</p>
    </div>`;
}

// Normalize a log timestamp (ISO from the APIs, or raw from the page globals)
// into the "Y-m-d H:i:s" display used by the modals.
function displayLogTime(t) {
    return String(t == null ? '' : t).replace('T', ' ').replace('+08:00', '');
}

// Alerts/activity timeline items for the room modal (same markup as before).
function renderRoomAlerts(previewEl, alerts) {
    if (!previewEl) return;
    if (alerts && alerts.length) {
        previewEl.innerHTML = alerts.map(a => {
            const icon = alertIconMap(a.event_type);
            const label = (a.event_type || '').replace(/_/g, ' ');
            return `<div class="modal-timeline-item">
                <div class="modal-tl-icon" style="background:${icon[2]};color:${icon[1]};"><i class="bi ${icon[0]}"></i></div>
                <div class="modal-tl-body">
                    <p class="modal-tl-action">${label.charAt(0).toUpperCase() + label.slice(1)}</p>
                    <div class="modal-tl-meta"><span><i class="bi bi-clock"></i> ${escapeHtml(displayLogTime(a.event_time))}</span><span class="modal-tl-badge" style="background:${icon[2]};color:${icon[1]};">${escapeHtml(a.triggered_by || 'system')}</span></div>
                </div>
            </div>`;
        }).join('');
    } else {
        previewEl.innerHTML = '<div class="modal-slot-empty">No activity recorded for this room.</div>';
    }
}

// Lightweight live refresh while the room modal is open (mirrors the faculty
// view modal): fetch fresh per-minute readings + room logs and re-render the
// chart, current-schedule card and alerts. Timetable/override toggles are
// untouched. Falls back to the local extend-to-now behavior on fetch failure.
function refreshRoomModalLive() {
    const room = currentModalRoom;
    if (!room || !room.id) return;
    const req = ++roomModalChartReq;
    fetchRoomTodaySeries(room.id).then(function (today) {
        if (req !== roomModalChartReq) return; // stale response
        const roomNow = today
            ? extendRoomTodayToNow(roomWithFreshToday(room, today))
            : extendRoomTodayToNow(room);
        renderRoomModalChart(roomNow);
        renderModalCurrentSched(room);
    });
    // Fresh alerts for the alerts card (keep the current list on failure).
    fetchRoomAlerts(room.room_name).then(function (logs) {
        if (req !== roomModalChartReq || !logs) return;
        renderRoomAlerts(document.getElementById('modalAlertsPreview'), logs);
    });
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
        if (!liveMode) renderLiveReadings();
    } catch (err) {
        console.warn('[Overview Live]', err);
    }
}

async function pollOverviewChart() {
    if (liveMode || currentPeriod !== 1) return;
    try {
        const res = await fetch('../../api/overview-chart.php');
        const data = await res.json();
        if (!data || !data.ok) return;
        liveChartToday = data.today || [];
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
    updateSelectionUI();

    // Keep device-strips + sparklines fresh while ESP32/Arduino streams
    overviewPollTimer = setInterval(pollOverviewLive, OVERVIEW_POLL_MS);
    chartPollTimer = setInterval(pollOverviewChart, CHART_POLL_MS);

    // Live dashboard toggle
    const liveBtn = document.getElementById('liveToggleBtn');
    if (liveBtn) liveBtn.addEventListener('click', toggleLiveMode);

    // Room selection
    document.querySelectorAll('.spark-card, .room-card:not(.faculty-card), .hroom-row:not(.faculty-card)').forEach(card => {
        card.addEventListener('click', (e) => {
            if (e.target.closest('.room-icons') || e.target.closest('.light') || e.target.closest('.room-card-actions')) return;
            selectRoom(card.getAttribute('data-room-id'));
        });
    });
    const selectAllBtn = document.getElementById('selectAllRoomsBtn');
    if (selectAllBtn) selectAllBtn.addEventListener('click', toggleSelectAll);

    // Live dashboard shared view: Faculty slicer selection + cross-sync.
    document.querySelectorAll('.rooms-card.faculty-slice-card .stat-card').forEach(card => {
        card.addEventListener('click', function () {
            selectFacultyFromSlicer(this);
        });
    });
    // Selecting a Room row clears the (single) Faculty selection so the
    // slicers always agree (room click handler in admin-analytics.js drives data).
    document.querySelectorAll('.rooms-card .stat-card:not(.faculty-stat-card)').forEach(function (c) {
        c.addEventListener('click', function () {
            document.querySelectorAll('.rooms-card.faculty-slice-card .stat-card.active-room').forEach(function (f) {
                f.classList.remove('active-room');
            });
        });
    });

    // Metric cards in live readings (bound by admin-analytics.js itself)

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
            if (roomModalChartInstance) { roomModalChartInstance.destroy(); roomModalChartInstance = null; }
            currentModalRoom = null;
            setRoomOnly(null);
            setFacultyOnly(null);
        });
    }

    initFacultyManagement();

    // Maximize button → fullscreen schedule Gantt
    const maximizeBtn = document.getElementById('maximizeFacultyBtn');
    if (maximizeBtn) maximizeBtn.addEventListener('click', openFacultyGantt);

    // Live dashboard starts ON by default (per product request).
    if (liveBtn) {
        setLiveMode(true);
        initChartCarousel();
    }
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
                'data-time="' + escapeHtml(startTxt + ' – ' + endTxt) + '" ' +
                'data-faculty-id="' + f.id + '" ' +
                'data-classroom-id="' + (s.classroom_id || 0) + '" ' +
                'data-sched-id="' + (s.sched_id || 0) + '" ' +
                'data-start="' + (s.start_time || '') + '" ' +
                'data-end="' + (s.extended_until || s.end_time || '') + '">' +
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
    closeGanttSessionPanel();
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
        closeGanttSessionPanel();
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
        closeGanttSessionPanel();
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

/* ── Gantt session-detail panel (appears below the chart on block click) ── */
let ganttSdChartInstance = null;

function destroyGanttSdChart() {
    if (ganttSdChartInstance) { ganttSdChartInstance.destroy(); ganttSdChartInstance = null; }
}

// Concrete date for the selected (weekly) gantt day: today if it's that weekday,
// otherwise the next future occurrence of that weekday.
function ganttSessionDate(dayIdx) {
    const today = new Date();
    const todayDow = today.getDay();            // 0 Sun … 6 Sat
    const selDow = (dayIdx + 1) % 7;            // Mon(0) → 1 … Sun(6) → 0
    const d = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    if (selDow !== todayDow) {
        d.setDate(d.getDate() + ((selDow - todayDow + 7) % 7));
    }
    return d.getFullYear() + '-' +
        String(d.getMonth() + 1).padStart(2, '0') + '-' +
        String(d.getDate()).padStart(2, '0');
}

function fmtDurationMin(min) {
    const m = Math.max(parseInt(min, 10) || 0, 0);
    const h = Math.floor(m / 60);
    const r = m % 60;
    return h > 0 ? h + ' hr ' + r + ' min' : r + ' min';
}

function ganttSdStat(label, value) {
    return '<div class="gantt-sd-stat"><div class="gantt-sd-stat-label">' + label + '</div>' +
        '<div class="gantt-sd-stat-value">' + value + '</div></div>';
}

function renderGanttSdStats(data) {
    const el = document.getElementById('ganttSdStats');
    if (!el) return;
    const has = !!data.has_data;
    const dash = '<span class="text-muted">—</span>';
    const energyWh = parseFloat(data.total_energy_wh) || 0;
    const energyTxt = has
        ? (energyWh >= 1000 ? (energyWh / 1000).toFixed(3) + ' kWh' : energyWh.toFixed(2) + ' Wh')
        : 'No data';
    el.innerHTML =
        '<div class="gantt-sd-empty px-1 pb-2" ' + (has ? 'style="display:none;"' : '') + '>No energy readings recorded for this session.</div>' +
        '<div class="gantt-sd-stat-grid">' +
        ganttSdStat('Time consumed', fmtDurationMin(data.duration_min)) +
        ganttSdStat('Total energy', energyTxt) +
        ganttSdStat('Est. cost', has ? '₱' + parseFloat(data.est_cost_php || 0).toFixed(2) : dash) +
        ganttSdStat('Avg Voltage', has ? (data.avg_voltage || 0) + ' V' : dash) +
        ganttSdStat('Avg Current', has ? (data.avg_current || 0) + ' A' : dash) +
        ganttSdStat('Avg Power', has ? (data.avg_power || 0) + ' W' : dash) +
        ganttSdStat('Peak Power', has ? (data.peak_power_w || 0) + ' W' : dash) +
        '</div>';
}

function renderGanttSdAnomalies(anomalies, hasData) {
    const el = document.getElementById('ganttSdAnomalies');
    if (!el) return;
    const list = anomalies || [];
    if (!list.length) {
        el.innerHTML = '<div class="gantt-sd-anomaly-none">' +
            (hasData ? 'No anomalies detected in this session.' : 'No anomalies recorded.') +
            '</div>';
        return;
    }
    el.innerHTML = list.map(function (a) {
        return '<div class="gantt-sd-anomaly">' +
            '<span class="gantt-sd-anomaly-icon"><i class="bi bi-exclamation-triangle-fill"></i></span>' +
            '<div class="gantt-sd-anomaly-body">' +
            '<div class="gantt-sd-anomaly-title">' + escapeHtml((a.event_type || 'issue_raised').replace(/_/g, ' ')) +
            ' <span class="badge">' + escapeHtml(a.triggered_by || 'system') + '</span></div>' +
            '<div class="gantt-sd-anomaly-meta"><i class="bi bi-clock me-1"></i>' + escapeHtml(a.event_time || '') + '</div>' +
            (a.notes ? '<div class="gantt-sd-anomaly-notes">' + escapeHtml(a.notes) + '</div>' : '') +
            '</div></div>';
    }).join('');
}

function renderGanttSdChart(data) {
    const canvas = document.getElementById('ganttSdChart');
    if (!canvas || !window.Chart) return;
    destroyGanttSdChart();
    const has = !!data.has_data && (data.chart && data.chart.labels.length);
    if (!has) {
        canvas.style.display = 'none';
        return;
    }
    canvas.style.display = 'block';
    ganttSdChartInstance = new Chart(canvas, {
        type: 'line',
        data: {
            labels: data.chart.labels,
            datasets: [
                { label: 'Voltage (V)', data: data.chart.voltage, borderColor: '#742fd3', backgroundColor: 'rgba(116,47,211,0.08)', fill: true, tension: 0.3, pointRadius: 1 },
                { label: 'Current (A)', data: data.chart.current, borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.08)', fill: true, tension: 0.3, pointRadius: 1, yAxisID: 'y1' },
                { label: 'Power (W)', data: data.chart.power, borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,0.08)', fill: true, tension: 0.3, pointRadius: 1, yAxisID: 'y1' },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: {
                legend: { position: 'top', labels: { font: { family: 'Poppins', size: 10 }, boxWidth: 12, padding: 12 } },
            },
            scales: {
                x: { ticks: { color: '#4d4d4d', font: { family: 'Poppins', size: 10 } }, grid: { display: false } },
                y: { type: 'linear', display: true, position: 'left', ticks: { color: '#742fd3', font: { family: 'Poppins', size: 10 } }, grid: { color: 'rgba(47,0,79,0.07)' } },
                y1: { type: 'linear', display: true, position: 'right', ticks: { color: '#f59e0b', font: { family: 'Poppins', size: 10 } }, grid: { display: false } },
            },
        },
    });
}

function showGanttSessionPanel(block) {
    const panel = document.getElementById('ganttSessionDetail');
    if (!panel) return;
    const cid = parseInt(block.getAttribute('data-classroom-id'), 10);
    const fid = parseInt(block.getAttribute('data-faculty-id'), 10);
    const subject = block.getAttribute('data-subject') || 'Class';
    const room = block.getAttribute('data-room') || '—';
    const timeTxt = block.getAttribute('data-time') || '';
    const start = block.getAttribute('data-start') || '';
    const end = block.getAttribute('data-end') || '';

    const fac = (FACULTY || []).find(function (f) { return parseInt(f.id, 10) === fid; });
    const facName = fac ? (fac.first_name + ' ' + fac.last_name) : 'Faculty';
    const date = ganttSessionDate(ganttDayIdx);

    document.getElementById('ganttSdTitle').textContent = subject;
    const subEl = document.getElementById('ganttSdSubtitle');
    if (subEl) subEl.textContent = room + ' · ' + facName + ' · ' + date + ' · ' + timeTxt;

    panel.classList.remove('d-none');
    document.getElementById('ganttSdStats').innerHTML = '<div class="gantt-sd-empty text-muted py-2">Loading session data…</div>';
    document.getElementById('ganttSdAnomalies').innerHTML = '';
    destroyGanttSdChart();
    const canvas = document.getElementById('ganttSdChart');
    if (canvas) canvas.style.display = 'none';

    if (cid <= 0 || !start || !end) {
        renderGanttSdStats({ duration_min: 0, has_data: false });
        renderGanttSdAnomalies([], false);
        return;
    }

    fetch('../../api/session-detail.php?classroom_id=' + cid +
        '&date=' + encodeURIComponent(date) +
        '&start=' + encodeURIComponent(start) +
        '&end=' + encodeURIComponent(end))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || data.success === false) throw new Error('bad');
            renderGanttSdStats(data);
            renderGanttSdAnomalies(data.anomalies, data.has_data);
            renderGanttSdChart(data);
        })
        .catch(function () {
            document.getElementById('ganttSdStats').innerHTML = '<div class="gantt-sd-empty text-muted py-2">Could not load session data.</div>';
        });
}

function closeGanttSessionPanel() {
    const panel = document.getElementById('ganttSessionDetail');
    if (panel) panel.classList.add('d-none');
    destroyGanttSdChart();
}

// Clicking a gantt block shows the session-detail panel below the chart.
document.addEventListener('click', function (e) {
    const block = e.target.closest('.gantt-block');
    if (!block) return;
    hideFacultyGanttOverlay();
    showGanttSessionPanel(block);
});

// Clean up the panel/chart when the maximized modal closes.
const ganttModalEl = document.getElementById('facultyGanttModal');
if (ganttModalEl) {
    ganttModalEl.addEventListener('hidden.bs.modal', function () {
        closeGanttSessionPanel();
    });
}
