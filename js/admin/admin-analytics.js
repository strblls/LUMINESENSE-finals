// admin-analytics.js
// Matches updated admin-analytics.php layout:
//   - Live readings strip  (polls every 3s from pzem_live via live-pzem.php)
//   - Summary cards        (from api/analytics.php → power_sessions + pzem_readings)
//   - Line chart + Bar     (real hourly or daily avg V/A/W from pzem_readings)
//   - Daily history table  (multi-day: per-day sessions; Today: real 5-min intervals)

const API_URL      = '../../api/analytics.php';
const LIVE_API_URL = '../../api/live-pzem.php';

// ── Fallback sample data (used only when API call fails — all zeros) ──────────
const sampleDaily = [
    { label: 'Mon', date: '', energy_wh: 0, sessions: 0, minutes: 0, avg_voltage: null, avg_current: null, avg_power: null },
    { label: 'Tue', date: '', energy_wh: 0, sessions: 0, minutes: 0, avg_voltage: null, avg_current: null, avg_power: null },
    { label: 'Wed', date: '', energy_wh: 0, sessions: 0, minutes: 0, avg_voltage: null, avg_current: null, avg_power: null },
    { label: 'Thu', date: '', energy_wh: 0, sessions: 0, minutes: 0, avg_voltage: null, avg_current: null, avg_power: null },
    { label: 'Fri', date: '', energy_wh: 0, sessions: 0, minutes: 0, avg_voltage: null, avg_current: null, avg_power: null },
    { label: 'Sat', date: '', energy_wh: 0, sessions: 0, minutes: 0, avg_voltage: null, avg_current: null, avg_power: null },
    { label: 'Sun', date: '', energy_wh: 0, sessions: 0, minutes: 0, avg_voltage: null, avg_current: null, avg_power: null },
];

const sampleSummary = {
    total_energy_kwh: 0,
    total_minutes:    0,
    avg_voltage:      0,
    avg_current:      0,
    peak_power_w:     0,
    est_cost_php:     0,
};

// ── Issue markers (shared plugin state + helpers) ─────────────────────────────
let currentIssues = [];

const issueMarkerPlugin = {
    id: 'issueMarker',
    afterDraw: function(chart) {
        if (!currentIssues || !currentIssues.length) return;
        var chartArea = chart.chartArea;
        var xScale = chart.scales.x;
        if (!chartArea || !xScale) return;
        var ctx = chart.ctx;
        for (var i = 0; i < currentIssues.length; i++) {
            if (!currentIssues[i]) continue;
            var x = xScale.getPixelForValue(i);
            if (x < chartArea.left || x > chartArea.right) continue;
            drawIssueMarker(ctx, x, chartArea.top + 12);
        }
    }
};

function drawIssueMarker(ctx, x, y) {
    var r = 7;
    ctx.save();
    ctx.beginPath();
    ctx.moveTo(x, y - r);
    ctx.lineTo(x - r, y + r * 0.8);
    ctx.lineTo(x + r, y + r * 0.8);
    ctx.closePath();
    ctx.fillStyle = '#dc3545';
    ctx.shadowColor = 'rgba(220,53,69,0.45)';
    ctx.shadowBlur = 6;
    ctx.fill();
    ctx.shadowBlur = 0;
    ctx.strokeStyle = '#fff';
    ctx.lineWidth = 1.5;
    ctx.lineCap = 'round';
    ctx.beginPath();
    ctx.moveTo(x, y - r * 0.3);
    ctx.lineTo(x, y + r * 0.2);
    ctx.stroke();
    ctx.beginPath();
    ctx.arc(x, y + r * 0.45, 1.2, 0, Math.PI * 2);
    ctx.fillStyle = '#fff';
    ctx.fill();
    ctx.restore();
}

function getIssueIndexAtEvent(e, chart) {
    if (!currentIssues || !currentIssues.length) return null;
    var chartArea = chart.chartArea;
    if (!chartArea) return null;
    var pos = Chart.helpers.getRelativePosition(e, chart);
    if (pos.x < chartArea.left || pos.x > chartArea.right) return null;
    if (pos.y < chartArea.top || pos.y > chartArea.bottom) return null;
    var xScale = chart.scales.x;
    if (!xScale) return null;
    var best = null, bestDist = 16;
    currentIssues.forEach(function(issue, i) {
        if (!issue) return;
        var px = xScale.getPixelForValue(i);
        var d = Math.abs(px - pos.x);
        if (d < bestDist) { bestDist = d; best = i; }
    });
    return best;
}

function onChartClick(e, active, chart) {
    var idx = getIssueIndexAtEvent(e, chart);
    if (idx != null && currentIssues[idx]) openIssueModal(currentIssues[idx]);
}

function onChartHover(e, active, chart) {
    var idx = getIssueIndexAtEvent(e, chart);
    if (chart.canvas) chart.canvas.style.cursor = idx != null ? 'pointer' : 'default';
}

function mapIssues(issues, range, labels) {
    currentIssues = [];
    if (!issues || !issues.length || !labels || !labels.length) return;
    range = parseInt(range);
    var mapped = new Array(labels.length).fill(null);
    issues.forEach(function(issue) {
        var idx = -1;
        if (range === 1) {
            var t = (issue.event_time || '').slice(11, 16);
            idx = labels.indexOf(t);
            if (idx === -1) {
                var best = -1, bestDiff = Infinity;
                labels.forEach(function(l, i) {
                    if (!/^\d{2}:\d{2}$/.test(l)) return;
                    var diff = timeLabelDiff(t, l);
                    if (diff < bestDiff) { bestDiff = diff; best = i; }
                });
                idx = best;
            }
        } else {
            var d = formatLabelDate(issue.event_time);
            idx = labels.indexOf(d);
            if (idx === -1 && labels.length) {
                var first = parseLabelDate(labels[0]);
                var target = new Date(issue.event_time);
                var diff = Math.round((target.getTime() - first.getTime()) / 86400000);
                if (diff >= 0 && diff < labels.length) idx = diff;
            }
        }
        if (idx >= 0 && idx < mapped.length) mapped[idx] = issue;
    });
    currentIssues = mapped;
}

function timeLabelDiff(a, b) {
    var p = function(s) { var x = s.split(':'); return parseInt(x[0]) * 60 + parseInt(x[1]); };
    return Math.abs(p(a) - p(b));
}

function parseLabelDate(label) {
    var parts = String(label).split(' ');
    var months = { Jan:0, Feb:1, Mar:2, Apr:3, May:4, Jun:5, Jul:6, Aug:7, Sep:8, Oct:9, Nov:10, Dec:11 };
    return new Date(2026, months[parts[1]] || 0, parseInt(parts[2]) || 1);
}

function formatLabelDate(dt) {
    var d = new Date(dt);
    if (isNaN(d.getTime())) return '';
    var days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return days[d.getDay()] + ' ' + months[d.getMonth()] + ' ' + String(d.getDate()).padStart(2, '0');
}

const METRIC_LABELS = { voltage: 'Voltage', current: 'Current', power: 'Power' };
const CHART_TITLES   = { lineGraphCard: 'lineChartTitle', barGraphCard: 'barChartTitle' };

// ── Chart instances ───────────────────────────────────────────────────────────
const barChartInstance = new Chart(document.getElementById('barChart'), {
    type: 'bar',
    plugins: [issueMarkerPlugin],
    data: {
        labels: [],
        datasets: [
            {
                label: 'Voltage (V)',
                metric: 'voltage',
                data: [],
                backgroundColor: 'rgba(116,47,211,0.85)',
                borderRadius: 4,
                maxBarThickness: 16,
                yAxisID: 'y',
            },
            {
                label: 'Current (A)',
                metric: 'current',
                data: [],
                backgroundColor: 'rgba(245,158,11,0.85)',
                borderRadius: 4,
                maxBarThickness: 16,
                yAxisID: 'y1',
            },
            {
                label: 'Power (W)',
                metric: 'power',
                data: [],
                backgroundColor: 'rgba(22,163,74,0.85)',
                borderRadius: 4,
                maxBarThickness: 16,
                yAxisID: 'y2',
            },
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        onClick: onChartClick,
        onHover: onChartHover,
        plugins: {
            legend: {
                position: 'top',
                labels: { font: { family: 'Poppins', size: 10 }, boxWidth: 12, padding: 12 },
                onClick: function(e, legendItem, legend) {
                    var index = legendItem.datasetIndex;
                    var chart = legend.chart;
                    var meta  = chart.getDatasetMeta(index);
                    meta.hidden = meta.hidden === null ? !chart.data.datasets[index].hidden : null;
                    chart.update();
                    var labelEl = document.getElementById('barMetricLabel');
                    if (labelEl) {
                        var visible = chart.data.datasets.filter(function(ds, i) { return !chart.getDatasetMeta(i).hidden; }).map(function(ds) { return ds.label; });
                        labelEl.textContent = visible.length === chart.data.datasets.length ? 'All Metrics' : visible.join(', ');
                    }
                    syncVawFromLegend();
                    updateChartTitles();
                }
            },
        },
        scales: {
            x: {
                ticks: { color: '#4d4d4d', font: { family: 'Poppins', size: 10 } },
                grid:  { display: false },
            },
            y: {
                beginAtZero: true,
                position: 'left',
                title: { display: false },
                ticks: { color: '#742fd3', font: { family: 'Poppins', size: 10 } },
                grid:  { color: 'rgba(47,0,79,0.07)' },
            },
            y1: {
                type: 'linear',
                beginAtZero: true,
                display: true,
                position: 'left',
                title: { display: false },
                ticks: { color: '#f59e0b', font: { family: 'Poppins', size: 10 } },
                grid:  { display: false },
            },
            y2: {
                type: 'linear',
                beginAtZero: true,
                display: true,
                position: 'right',
                title: { display: false },
                ticks: { color: '#16a34a', font: { family: 'Poppins', size: 10 } },
                grid:  { display: false },
            },
        }
    }
});

const lineChartInstance = new Chart(document.getElementById('lineChart'), {
    type: 'line',
    plugins: [issueMarkerPlugin],
    data: {
        labels: [],
        datasets: [
            {
                label: 'Voltage (V)',
                metric: 'voltage',
                data: [],
                borderColor: '#742fd3',
                backgroundColor: 'rgba(116,47,211,0.10)',
                fill: true,
                tension: 0.3,
                pointRadius: 2,
                spanGaps: false,
            },
            {
                label: 'Current (A)',
                metric: 'current',
                data: [],
                borderColor: '#f59e0b',
                backgroundColor: 'rgba(245,158,11,0.10)',
                fill: true,
                tension: 0.3,
                pointRadius: 2,
                yAxisID: 'y1',
                spanGaps: false,
            },
            {
                label: 'Power (W)',
                metric: 'power',
                data: [],
                borderColor: '#16a34a',
                backgroundColor: 'rgba(22,163,74,0.10)',
                fill: true,
                tension: 0.3,
                pointRadius: 2,
                yAxisID: 'y2',
                spanGaps: false,
            },
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        onClick: onChartClick,
        onHover: onChartHover,
        plugins: {
            legend: {
                position: 'top',
                labels: { font: { family: 'Poppins', size: 10 }, boxWidth: 12, padding: 12 },
                onClick: function(e, legendItem, legend) {
                    var index = legendItem.datasetIndex;
                    var chart = legend.chart;
                    var meta  = chart.getDatasetMeta(index);
                    meta.hidden = meta.hidden === null ? !chart.data.datasets[index].hidden : null;
                    var axisId = chart.data.datasets[index].yAxisID;
                    if (axisId && chart.options.scales[axisId]) {
                        chart.options.scales[axisId].display = !meta.hidden;
                    }
                    chart.update();
                    var labelEl = document.getElementById('lineMetricLabel');
                    if (labelEl) {
                        var visible = chart.data.datasets.filter(function(ds, i) { return !chart.getDatasetMeta(i).hidden; }).map(function(ds) { return ds.label; });
                        labelEl.textContent = visible.length === chart.data.datasets.length ? 'All Metrics' : visible.join(', ');
                    }
                    syncVawFromLegend();
                    updateChartTitles();
                }
            },
        },
        scales: {
            x: {
                ticks: { color: '#4d4d4d', font: { family: 'Poppins', size: 10 } },
                grid:  { display: false },
            },
            y: {
                type: 'linear',
                display: true,
                position: 'left',
                title: { display: false },
                ticks: { color: '#742fd3', font: { family: 'Poppins', size: 10 } },
                grid:  { color: 'rgba(47,0,79,0.07)' },
            },
            y1: {
                type: 'linear',
                display: true,
                position: 'left',
                title: { display: false },
                ticks: { color: '#f59e0b', font: { family: 'Poppins', size: 10 } },
                grid:  { display: false },
            },
            y2: {
                type: 'linear',
                display: true,
                position: 'left',
                title: { display: false },
                ticks: { color: '#16a34a', font: { family: 'Poppins', size: 10 } },
                grid:  { display: false },
            },
        }
    }
});

let lastData = null;

// ── Helpers ───────────────────────────────────────────────────────────────────
function getCid() {
    return document.getElementById('roomSelect')?.value ?? 0;
}

// ── Chart window scroll state ──
const WINDOW_SIZE = 15;
var chartScrollOffset = { lineChart: 0, barChart: 0 };
var scrollbarHovered = { lineChart: false, barChart: false };
var currentLabels = [];

// ── LIVE READINGS — polls every 3 seconds ─────────────────────────────────────
async function fetchLive() {
    try {
        const cid  = getCid();
        const res  = await fetch(`${LIVE_API_URL}?classroom_id=${cid}`);
        const data = await res.json();

        const dot    = document.getElementById('liveStatusDot');
        const status = document.getElementById('liveStatus');
        const badge  = document.getElementById('liveBadge');

        if (!data.success || data.stale) {
            if (badge) {
                badge.className = 'live-badge stale';
                badge.innerHTML = '<span class="live-dot stale"></span> No Device';
            }
            if (status) status.textContent = '—';
            if (dot) { dot.style.background = '#ccc'; dot.classList.remove('on'); }
            document.getElementById('liveVoltage').textContent = '— V';
            document.getElementById('liveCurrent').textContent = '— A';
            document.getElementById('livePower').textContent   = '— W';
            document.getElementById('liveEnergy').textContent  = '— Wh';
            return;
        }

        if (badge) {
            badge.className = 'live-badge';
            badge.innerHTML = '<span class="live-dot"></span> Live';
        }

        document.getElementById('liveVoltage').textContent = data.voltage.toFixed(1) + ' V';
        document.getElementById('liveCurrent').textContent = data.current.toFixed(3) + ' A';
        document.getElementById('livePower').innerHTML   =
            data.power.toFixed(2) + ' W<br><span style="font-size:14px;">(' + data.power_kw.toFixed(3) + ' kW)</span>';
        document.getElementById('liveEnergy').textContent  = data.energy.toFixed(4) + ' Wh';

        if (data.lights_on) {
            if (status) status.textContent = 'ON';
            if (dot) { dot.style.background = '#27ae60'; dot.classList.add('on'); }
        } else {
            if (status) status.textContent = 'OFF';
            if (dot) { dot.style.background = '#ccc'; dot.classList.remove('on'); }
        }

    } catch (err) {
        console.warn('[Live]', err);
    }
}

// ── Polling control ───────────────────────────────────────────────────────────
var liveInterval, dataInterval;

function pausePolling() {
    if (liveInterval) { clearInterval(liveInterval); liveInterval = null; }
    if (dataInterval) { clearInterval(dataInterval); dataInterval = null; }
}

function resumePolling() {
    if (!liveInterval) { fetchLive(); liveInterval = setInterval(fetchLive, 3000); }
    if (!dataInterval) { dataInterval = setInterval(function() { onControlChange(); }, 30000); }
}

function checkPolling() {
    var activeRooms  = document.querySelectorAll('.rooms-card .stat-card.active-room');
    var metricEl     = document.querySelector('.dept-member-filter-item.active');
    var metricActive = metricEl && metricEl.textContent.trim() !== 'All Metrics';
    var periodSelect = document.getElementById('periodSelect');
    var periodActive = periodSelect && parseInt(periodSelect.value) !== 1;
    var legendActive = false;
    [lineChartInstance, barChartInstance].forEach(function(ch) {
        if (ch) ch.data.datasets.forEach(function(_, i) { if (ch.getDatasetMeta(i).hidden) legendActive = true; });
    });
    // Exactly 1 room, no other filters → continue polling for that room
    if (activeRooms.length === 1 && !metricActive && !periodActive && !legendActive) {
        resumePolling(); return;
    }
    if (metricActive || periodActive || legendActive || activeRooms.length > 1) {
        pausePolling();
    } else {
        resumePolling();
    }
}

fetchLive();
liveInterval = setInterval(fetchLive, 3000);

// ── Period / Metric filter helpers ────────────────────────────────────────────
function setPeriod(el, days) {
    el.parentElement.querySelectorAll('.dept-member-filter-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');
    const sel = document.getElementById('periodSelect');
    if (sel) { sel.value = days; sel.dispatchEvent(new Event('change')); }
    checkPolling();
}

function syncVawFromLegend() {
    var charts    = [lineChartInstance, barChartInstance];
    var allHidden = true;
    charts.forEach(function(ch) {
        ch.data.datasets.forEach(function(_, i) {
            if (!ch.getDatasetMeta(i).hidden) allHidden = false;
        });
    });
    var vawCards = document.querySelectorAll('#vawGroup .live-stat-card');
    vawCards.forEach(function(card) {
        card.classList.remove('metric-active', 'metric-dimmed');
        if (allHidden) card.classList.add('metric-active');
    });
    if (allHidden) {
        var filters = document.querySelectorAll('.dept-member-filter-item');
        filters.forEach(function(f) { f.classList.remove('active'); });
    }
    checkPolling();
}

function syncMetricLabel(chart, id) {
    var labelEl = document.getElementById(id);
    if (!labelEl) return;
    var visible = chart.data.datasets
        .filter(function(ds, i) { return !chart.getDatasetMeta(i).hidden; })
        .map(function(ds) { return ds.label; });
    labelEl.textContent = visible.length === chart.data.datasets.length ? 'All Metrics' : visible.join(', ');
}

// Dynamic chart titles, mirroring admin-overview's updateOverviewLineTitle().
// e.g. "Readings of All Rooms", "Voltage and Power Readings of SEL 1".
function updateChartTitles() {
    var charts = [lineChartInstance, barChartInstance];
    charts.forEach(function(chart) {
        if (!chart || !chart.data) return;
        var titleId = chart === lineChartInstance ? 'lineChartTitle' : 'barChartTitle';
        var titleEl = document.getElementById(titleId);
        if (!titleEl) return;

        var metricNames = chart.data.datasets
            .filter(function(ds, i) { return !chart.getDatasetMeta(i).hidden; })
            .map(function(ds) { return METRIC_LABELS[ds.metric] || ds.label; });

        var cid = getCid();
        var roomsLabel = 'All Rooms';
        if (cid > 0) {
            var r = roomData.find(function(x) { return x.id == cid; });
            roomsLabel = r ? r.room_name : 'Room';
        }

        if (!metricNames.length || metricNames.length === 3) {
            titleEl.textContent = 'Readings of ' + roomsLabel;
        } else {
            titleEl.textContent = metricNames.join(' and ') + ' Readings of ' + roomsLabel;
        }
    });
}

function setMetric(el, metric) {
    el.parentElement.querySelectorAll('.dept-member-filter-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');
    var charts = [lineChartInstance, barChartInstance];
    charts.forEach(function(chart) {
        var map = { voltage: 0, current: 1, power: 2 };
        chart.data.datasets.forEach(function(ds, i) {
            var meta = chart.getDatasetMeta(i);
            if (metric === 'all') {
                meta.hidden = false;
            } else {
                meta.hidden = (map[metric] !== undefined) ? (i !== map[metric]) : true;
            }
            var axisId = chart.data.datasets[i] && chart.data.datasets[i].yAxisID;
            if (axisId && chart.options.scales && chart.options.scales[axisId]) {
                chart.options.scales[axisId].display = !meta.hidden;
            }
        });
        chart.update();
        syncMetricLabel(chart, chart === lineChartInstance ? 'lineMetricLabel' : 'barMetricLabel');
    });
    var vawCards = document.querySelectorAll('#vawGroup .live-stat-card');
    vawCards.forEach(function(card) {
        card.classList.remove('metric-active', 'metric-dimmed');
        if (metric === 'all') return;
        var m = card.getAttribute('data-metric');
        if (m === metric) { card.classList.add('metric-active'); }
        else              { card.classList.add('metric-dimmed'); }
    });
    var infoEl   = document.getElementById('metricInfo');
    var infoText = infoEl.querySelector('.metric-info-text');
    var formulas = {
        voltage: 'Voltage (V) = Energy (J) \u00F7 Charge (C)',
        current: 'Current (A) = Power (W) \u00F7 Voltage (V)',
        power:   'Power (W) = Voltage (V) \u00D7 Current (A)'
    };
    if (metric === 'all') {
        infoText.innerHTML = 'Voltage, Current, and Power readings are used to compute Energy (Wh) over time. '
            + '<span class="metric-formula">Energy (Wh) = Power (W) \u00D7 Time (h)</span>';
    } else if (formulas[metric]) {
        infoText.innerHTML = '<span class="metric-formula">' + formulas[metric] + '</span>';
    }
    updateChartTitles();
    checkPolling();
}

// ── CHART RENDERER — uses real PZEM data ──────────────────────────────────────
/**
 * Push real avg V/A/W readings into both charts.
 * @param {string[]} labels    x-axis labels
 * @param {Array}    chartData array of objects with avg_voltage / avg_current / avg_power
 *                             (null values leave a gap — Chart.js spanGaps: false)
 */
function updateCharts(labels, chartData) {
    if (!labels || labels.length === 0) labels = ['No data'];

    var count = labels.length;

    const voltages = chartData.map(d => (d && d.avg_voltage != null) ? d.avg_voltage : null);
    const currents = chartData.map(d => (d && d.avg_current != null) ? d.avg_current : null);
    const powers   = chartData.map(d => (d && d.avg_power   != null) ? d.avg_power   : null);

    // Global y-axis max across all data
    var maxV = 0, maxA = 0, maxW = 0;
    chartData.forEach(function(d) {
        if (d && d.avg_voltage != null && d.avg_voltage > maxV) maxV = d.avg_voltage;
        if (d && d.avg_current != null && d.avg_current > maxA) maxA = d.avg_current;
        if (d && d.avg_power   != null && d.avg_power   > maxW) maxW = d.avg_power;
    });

    // Bar chart (separate y-axes: y=voltage, y1=current, y2=power)
    barChartInstance.data.labels           = labels;
    barChartInstance.data.datasets[0].data = voltages;
    barChartInstance.data.datasets[1].data = currents;
    barChartInstance.data.datasets[2].data = powers;
    barChartInstance.options.scales.y.max  = maxV > 0 ? maxV * 1.1 : undefined;
    barChartInstance.options.scales.y1.max = maxA > 0 ? maxA * 1.1 : undefined;
    barChartInstance.options.scales.y2.max = maxW > 0 ? maxW * 1.1 : undefined;
    barChartInstance.update();

    // Line chart (separate y-axes: y=voltage, y1=current, y2=power)
    lineChartInstance.data.labels           = labels;
    lineChartInstance.data.datasets[0].data = voltages;
    lineChartInstance.data.datasets[1].data = currents;
    lineChartInstance.data.datasets[2].data = powers;
    lineChartInstance.options.scales.y.max  = maxV > 0 ? maxV * 1.1 : undefined;
    lineChartInstance.options.scales.y1.max = maxA > 0 ? maxA * 1.1 : undefined;
    lineChartInstance.options.scales.y2.max = maxW > 0 ? maxW * 1.1 : undefined;
    lineChartInstance.update();

    // Store labels for tooltip use
    currentLabels = labels;

    // Update scrollbars, auto-scroll to rightmost on new data
    ['lineChart', 'barChart'].forEach(function(key) {
        var chart = key === 'lineChart' ? lineChartInstance : barChartInstance;
        var wrap = document.getElementById(key + 'ScrollWrap');
        var slider = document.getElementById(key + 'Scroll');
        var tipEl = document.getElementById(key + 'ScrollTip');
        var pendingEl = document.getElementById(key + 'ScrollPending');
        if (!wrap || !slider) return;
        var n = chart.data.labels.length;
        if (n <= WINDOW_SIZE) {
            wrap.classList.remove('visible');
            chartScrollOffset[key] = 0;
            if (chart.options.scales.x) {
                chart.options.scales.x.min = undefined;
                chart.options.scales.x.max = undefined;
                chart.update();
            }
            return;
        }
        wrap.classList.add('visible');
        var maxVal = n - WINDOW_SIZE;
        slider.max = maxVal;
        if (scrollbarHovered[key]) {
            // User is hovering — don't auto-scroll, show pending indicator
            var currentVal = parseInt(slider.value);
            if (currentVal < maxVal && pendingEl) {
                pendingEl.classList.add('show');
            }
        } else {
            // Auto-scroll to rightmost (latest data)
            slider.value = maxVal;
            chartScrollOffset[key] = maxVal;
            chart.options.scales.x.min = maxVal;
            chart.options.scales.x.max = maxVal + WINDOW_SIZE;
            chart.update();
            if (pendingEl) pendingEl.classList.remove('show');
        }
    });
}

function updateScrollTip(chartId) {
    var tipEl = document.getElementById(chartId + 'ScrollTip');
    var slider = document.getElementById(chartId + 'Scroll');
    if (!tipEl || !slider) return;
    var offset = parseInt(slider.value);
    var label = currentLabels[offset] || '';
    tipEl.textContent = label;
    tipEl.classList.add('show');
    // Position the tooltip above the thumb
    var pct = slider.max > 0 ? (offset / slider.max) * 100 : 0;
    tipEl.style.left = 'calc(' + pct + '% + ' + (4 - pct * 0.08) + 'px)';
    tipEl.style.transform = 'translateX(-50%)';
}

function onChartScroll(chartId, value) {
    var chart = chartId === 'lineChart' ? lineChartInstance : barChartInstance;
    if (!chart || !chart.data || !chart.data.labels) return;
    var offset = parseInt(value);
    chartScrollOffset[chartId] = offset;
    chart.options.scales.x.min = offset;
    chart.options.scales.x.max = offset + WINDOW_SIZE;
    chart.update();
    updateScrollTip(chartId);
    // Hide pending dot if scrolled to rightmost
    var pendingEl = document.getElementById(chartId + 'ScrollPending');
    if (pendingEl) {
        var slider = document.getElementById(chartId + 'Scroll');
        if (slider && parseInt(slider.value) >= parseInt(slider.max)) {
            pendingEl.classList.remove('show');
        }
    }
}

// ── TOGGLE CHART MAXIMIZE ─────────────────────────────────────────────────────
function toggleChartMaximize(cardId) {
    var card = document.getElementById(cardId);
    if (!card) return;
    var btn = card.querySelector('.chart-card-header .light');
    var wrapper = card.querySelector('.chart-wrapper');
    var isMax = card.classList.toggle('chart-maximized');
    if (btn) {
        btn.innerHTML = isMax ? '<i class="bi bi-arrows-collapse"></i>' : '<i class="bi bi-arrows-expand"></i>';
        btn.title = isMax ? 'Minimize' : 'Maximize';
    }
    if (!isMax && wrapper) {
        // Reset wrapper height to default on minimize
        wrapper.style.height = '';
    }
    setTimeout(function() {
        [lineChartInstance, barChartInstance].forEach(function(ch) { if (ch) ch.resize(); });
    }, 100);
}

// ── MAIN FETCH + RENDER ───────────────────────────────────────────────────────
async function onControlChange() {
    const range = parseInt(document.getElementById('periodSelect').value);
    const cid   = getCid();

    const titleEl = document.getElementById('historyTitle');
    if (titleEl) titleEl.textContent = range === 1 ? "Today's History" : range + '-Day History';

    // Update summary label date range
    var labelEl = document.querySelector('.summary-label');
    if (labelEl) {
        var now = new Date();
        if (range === 1) {
            labelEl.textContent = now.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
        } else {
            var from = new Date(now);
            from.setDate(from.getDate() - (range - 1));
            var opts = { month: 'short', day: 'numeric' };
            labelEl.innerHTML = from.toLocaleDateString('en-US', opts)
                + ' \u2013 ' + now.toLocaleDateString('en-US', opts) + ', ' + now.getFullYear();
        }
    }

    var sub = document.getElementById('tabSubheading');
    if (sub) {
        var sel = document.getElementById('roomSelect');
        if (sel && sel.value != 0) {
            var opt = sel.options[sel.selectedIndex];
            sub.textContent = opt ? opt.text + ' Selected' : 'Room Selected';
        } else {
            sub.textContent = 'All Rooms Selected';
        }
    }

    setLoading(true);

    try {
        const res  = await fetch(`${API_URL}?range=${range}&classroom_id=${cid}`);
        const data = await res.json();
        if (!data.success) throw new Error(data.message ?? 'API error');

        lastData = data;
        renderSummaryCards(data.summary);
        renderFindingsReport(data, range);

        var chartLabels, chartData;
        if (range === 1) {
            // Today: use 24-slot hourly data for the charts
            chartLabels = (data.hourly || []).map(h => h.label);
            chartData   = data.hourly || [];
            // Today's table: real 5-minute intervals from pzem_readings
            renderHistoryTable(data.intervals || [], data.summary, range);
        } else {
            // Multi-day: use daily data (with avg V/A/W per day)
            chartLabels = (data.daily || []).map(d => d.label);
            chartData   = data.daily || [];
            renderHistoryTable(data.daily, data.summary, range);
        }

        renderSavings(data.savings, range);
        mapIssues(data.issues, range, chartLabels);
        updateChartTitles();

        updateCharts(chartLabels, chartData);

    } catch (err) {
        console.error('[Analytics]', err);
        showError();
        renderSummaryCards(sampleSummary);
        currentIssues = [];
        renderSavings(null, range);
        renderFindingsReport(null, range);
        updateCharts(sampleDaily.map(d => d.label), sampleDaily);
        renderHistoryTable(sampleDaily, sampleSummary, range);
    } finally {
        setLoading(false);
    }
}

// ── SUMMARY CARDS ─────────────────────────────────────────────────────────────
function renderSummaryCards(s) {
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    set('sumEnergy',  (s.total_energy_kwh ?? 0).toFixed(4) + ' kWh');
    set('sumMinutes', ((s.total_minutes   ?? 0) / 60).toFixed(1) + ' hrs');
    set('sumVoltage', (s.avg_voltage      ?? 0).toFixed(1) + ' V');
    set('sumCurrent', (s.avg_current      ?? 0).toFixed(3) + ' A');
    set('sumPower',   (s.peak_power_w     ?? 0).toFixed(1) + ' W');  // fixed: was s.peak_power
    set('sumCost',    '\u20B1' + (s.est_cost_php ?? 0).toFixed(2));  // fixed: was s.total_cost
    set('sumAnomalies', (s.total_anomalies ?? 0) + (s.total_anomalies === 1 ? ' issue' : ' issues'));
}

// ── ENERGY SAVED WIDGET ───────────────────────────────────────────────────────
function renderSavings(s, range) {
    s = s || {};
    var cur  = (typeof s.current_kwh === 'number') ? s.current_kwh : 0;
    var prev = (typeof s.prev_kwh === 'number') ? s.prev_kwh : 0;

    var periodEl = document.getElementById('savingsPeriodLabel');
    if (periodEl) periodEl.textContent = (range == 1) ? 'Today' : 'Last ' + range + ' days';

    var valueEl = document.getElementById('savingsValue');
    var badgeEl = document.getElementById('savingsBadge');
    var descEl  = document.getElementById('savingsDesc');

    var set = function(id, txt) { var el = document.getElementById(id); if (el) el.textContent = txt; };
    set('savingsCurrent', cur.toFixed(2) + ' kWh');
    set('savingsPrev',    prev.toFixed(2) + ' kWh');
    set('savingsDelta',   ((cur - prev >= 0) ? '+' : '') + (cur - prev).toFixed(2) + ' kWh');

    if (s.pct == null || prev <= 0) {
        if (valueEl) { valueEl.textContent = '\u2014'; valueEl.classList.remove('savings-green', 'savings-red'); }
        if (badgeEl) { badgeEl.className = 'savings-badge neutral'; badgeEl.innerHTML = '<i class="bi bi-dash-lg"></i> No baseline'; }
        if (descEl)  descEl.textContent  = 'Not enough historical data for the previous period to compute a comparison.';
        fillSavingsModal(null, range);
        return;
    }

    var saved = s.direction === 'saved';
    var pct   = s.pct;
    if (valueEl) {
        valueEl.textContent = (pct > 0 ? '+' : '') + pct.toFixed(1) + '%';
        valueEl.classList.toggle('savings-green', saved);
        valueEl.classList.toggle('savings-red', !saved);
    }
    if (badgeEl) {
        badgeEl.className = 'savings-badge ' + (saved ? 'saved' : 'increase');
        badgeEl.innerHTML = saved
            ? '<i class="bi bi-arrow-down-right"></i> Energy saved'
            : '<i class="bi bi-arrow-up-right"></i> Energy increased';
    }
    if (descEl) {
        descEl.textContent = saved
            ? 'Used ' + Math.abs(pct).toFixed(1) + '% less energy than the previous equal-length period.'
            : 'Used ' + Math.abs(pct).toFixed(1) + '% more energy than the previous equal-length period.';
    }
    fillSavingsModal(s, range);
}

function fillSavingsModal(s, range) {
    var mVal   = document.getElementById('savingsModalValue');
    var mBadge = document.getElementById('savingsModalBadge');
    var mCur   = document.getElementById('savingsModalCurrent');
    var mPrev  = document.getElementById('savingsModalPrev');
    var mCurBar  = document.getElementById('savingsModalCurrentBar');
    var mPrevBar = document.getElementById('savingsModalPrevBar');
    var mNote  = document.getElementById('savingsModalNote');

    if (s && s.pct != null && (s.prev_kwh > 0)) {
        var saved = s.direction === 'saved';
        var cur   = s.current_kwh || 0;
        var prev  = s.prev_kwh || 0;
        var maxV  = Math.max(cur, prev, 0.001);
        if (mVal) { mVal.textContent = (s.pct > 0 ? '+' : '') + s.pct.toFixed(1) + '%'; mVal.classList.add(saved ? 'savings-green' : 'savings-red'); mVal.classList.remove(saved ? 'savings-red' : 'savings-green'); }
        if (mBadge) {
            mBadge.className = 'savings-modal-badge ' + (saved ? 'saved' : 'increase');
            mBadge.innerHTML = saved
                ? '<i class="bi bi-arrow-down-right"></i> Energy saved'
                : '<i class="bi bi-arrow-up-right"></i> Energy increased';
        }
        if (mCur) mCur.textContent = cur.toFixed(2) + ' kWh';
        if (mPrev) mPrev.textContent = prev.toFixed(2) + ' kWh';
        if (mCurBar) mCurBar.style.width = (cur / maxV * 100) + '%';
        if (mPrevBar) mPrevBar.style.width = (prev / maxV * 100) + '%';
        if (mNote) {
            mNote.innerHTML = '<strong>' + ((range == 1) ? 'Today' : 'Last ' + range + ' days') + '</strong> compared against the previous '
                + ((range == 1) ? 'day (same hour)' : 'equal-length window') + '. Current <strong>' + cur.toFixed(2) + ' kWh</strong> vs previous <strong>'
                + prev.toFixed(2) + ' kWh</strong> \u2014 a ' + Math.abs(s.pct).toFixed(1) + '% '
                + (saved ? 'decrease' : 'increase') + '.';
        }
    } else {
        if (mVal) { mVal.textContent = '\u2014'; mVal.classList.remove('savings-green', 'savings-red'); }
        if (mBadge) { mBadge.className = 'savings-modal-badge neutral'; mBadge.innerHTML = '<i class="bi bi-dash-lg"></i> No baseline'; }
        if (mCur) mCur.textContent = '\u2014';
        if (mPrev) mPrev.textContent = '\u2014';
        if (mCurBar) mCurBar.style.width = '0%';
        if (mPrevBar) mPrevBar.style.width = '0%';
        if (mNote) mNote.textContent = 'Not enough historical data for the previous period to compute a comparison.';
    }
}

function openSavingsModal() {
    var modal = new bootstrap.Modal(document.getElementById('savingsModal'));
    modal.show();
}

function toggleSavingsExpand() {
    var exp  = document.getElementById('savingsExpand');
    var chev = document.getElementById('savingsChevron');
    if (!exp) return;
    exp.classList.toggle('open');
    if (chev) chev.style.transform = exp.classList.contains('open') ? 'rotate(180deg)' : '';
}

// ── ISSUE DETAIL MODAL ────────────────────────────────────────────────────────
function openIssueModal(issue) {
    if (!issue) return;
    var set = function(id, val) { var el = document.getElementById(id); if (el) el.textContent = val; };
    set('issueRoom',   issue.room_name || '\u2014');
    set('issueSource', issue.triggered_by || '\u2014');
    set('issueTime',   issue.event_time ? new Date(issue.event_time).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '\u2014');
    set('issueNotes',  issue.notes || 'No additional notes.');
    var status = document.getElementById('issueStatus');
    if (status) {
        var resolved = issue.event_type === 'issue_resolved';
        status.textContent = resolved ? 'Resolved' : 'Issue Raised';
        status.className = 'issue-detail-status ' + (resolved ? 'resolved' : 'raised');
    }
    var modal = new bootstrap.Modal(document.getElementById('issueDetailModal'));
    modal.show();
}

// ── SUMMARY REPORT OF FINDINGS ────────────────────────────────────────────────
var findingsSparkCharts = {};
var findingsMiniChart   = null;

function renderFindingsReport(data, range) {
    var card = document.getElementById('findingsCard');
    if (!card) return;
    destroyFindingsCharts();

    if (!data || !data.success) {
        card.style.display = 'none';
        return;
    }
    card.style.display = 'block';

    range = parseInt(range);
    var s = data.summary || {};
    var isToday = range === 1;

    var titleEl = document.getElementById('findingsTitle');
    var subEl   = document.getElementById('findingsSub');
    if (titleEl) titleEl.textContent = isToday ? "Today's Summary Report" : range + '-Day Summary Report';
    if (subEl)  subEl.textContent  = isToday ? new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
                                            : 'Last ' + range + ' days';

    // Series for sparklines + mini chart
    var series;
    var seriesLabels;
    if (isToday) {
        series       = (data.intervals || []).map(function(r) { return r.energy_wh; });
        seriesLabels = (data.intervals || []).map(function(r) { return r.time; });
    } else {
        series       = (data.daily || []).map(function(d) { return d.energy_wh; });
        seriesLabels = (data.daily || []).map(function(d) { return d.label; });
    }

    // Chips
    setFindingsText('findingsEnergy',  ((s.total_energy_kwh ?? 0)).toFixed(2) + ' kWh');
    setFindingsText('findingsCost',    '\u20B1' + (s.est_cost_php ?? 0).toFixed(2));
    setFindingsText('findingsOccupied', ((s.total_minutes ?? 0) / 60).toFixed(1) + ' hrs');
    setFindingsText('findingsSessions', (s.total_sessions ?? 0) + '');
    var anom = s.total_anomalies ?? 0;
    setFindingsText('findingsAnomalyCount', anom + (anom === 1 ? ' issue' : ' issues'));
    var anomChip = document.getElementById('findingsAnomalyCount');
    if (anomChip && anomChip.closest) {
        var tile = anomChip.closest('.findings-chip');
        if (tile) {
            tile.classList.remove('chip-alert', 'chip-ok');
            tile.classList.add(anom > 0 ? 'chip-alert' : 'chip-ok');
        }
    }

    // Sparklines (energy + cost derive from the same series)
    var seriesFlat = series.filter(function(v) { return typeof v === 'number' && !isNaN(v); });
    drawFindingsSpark('findingsSparkEnergy', seriesFlat, '#58078f');
    drawFindingsSpark('findingsSparkCost', seriesFlat.map(function(v) { return v * 11; }), '#c0004e');

    // Mini chart (energy profile) with peak highlight + issue markers
    renderFindingsMiniChart(seriesLabels, series, data.issues, range);

    // Text narrative
    renderFindingsList(data, series, seriesLabels, isToday);

    // Clickable anomalies
    renderFindingsAnomalies(data.issues || []);
}

function setFindingsText(id, txt) {
    var el = document.getElementById(id);
    if (el) el.textContent = txt;
}

function destroyFindingsCharts() {
    Object.keys(findingsSparkCharts).forEach(function(id) {
        if (findingsSparkCharts[id]) { findingsSparkCharts[id].destroy(); delete findingsSparkCharts[id]; }
    });
    if (findingsMiniChart) { findingsMiniChart.destroy(); findingsMiniChart = null; }
}

function drawFindingsSpark(canvasId, dataArr, color) {
    var el = document.getElementById(canvasId);
    if (!el || !window.Chart) return;
    if (findingsSparkCharts[canvasId]) findingsSparkCharts[canvasId].destroy();
    var flat = (dataArr || []).map(Number);
    if (!flat.length) flat = [0];
    findingsSparkCharts[canvasId] = new Chart(el, {
        type: 'line',
        data: {
            labels: flat.map(function(_, i) { return i; }),
            datasets: [{
                data: flat,
                borderColor: color || '#58078f',
                backgroundColor: 'rgba(88,7,143,0.10)',
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

function renderFindingsMiniChart(labels, series, issues, range) {
    var canvas = document.getElementById('findingsMiniChart');
    var hint   = document.getElementById('findingsChartHint');
    var label  = document.getElementById('findingsChartLabel');
    if (!canvas || !window.Chart) return;
    if (!labels || !labels.length) {
        if (label) label.textContent = 'Energy Profile';
        if (hint)  hint.textContent  = 'No data';
        return;
    }

    // Map issues to chart indices
    var issueByIndex = {};
    (issues || []).forEach(function(issue) {
        var idx = -1;
        if (parseInt(range) === 1) {
            var t = (issue.event_time || '').slice(11, 16);
            idx = labels.indexOf(t);
            if (idx === -1) {
                var best = -1, bestDiff = Infinity;
                labels.forEach(function(l, i) {
                    if (!/^\d{2}:\d{2}$/.test(l)) return;
                    var d = timeLabelDiff(t, l);
                    if (d < bestDiff) { bestDiff = d; best = i; }
                });
                idx = best;
            }
        } else {
            var d = formatLabelDate(issue.event_time);
            idx = labels.indexOf(d);
            if (idx === -1 && labels.length) {
                var first = parseLabelDate(labels[0]);
                var target = new Date(issue.event_time);
                var diff = Math.round((target.getTime() - first.getTime()) / 86400000);
                if (diff >= 0 && diff < labels.length) idx = diff;
            }
        }
        if (idx >= 0) issueByIndex[idx] = issue;
    });

    var maxIdx = 0, maxVal = -Infinity;
    series.forEach(function(v, i) {
        if (typeof v === 'number' && v > maxVal) { maxVal = v; maxIdx = i; }
    });

    var bg = series.map(function(v, i) {
        if (issueByIndex[i]) return 'rgba(220,53,69,0.85)';
        return i === maxIdx ? 'rgba(245,158,11,0.9)' : 'rgba(116,47,211,0.55)';
    });

    findingsMiniChart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Energy (Wh)',
                data: series,
                backgroundColor: bg,
                borderRadius: 3,
                maxBarThickness: 14,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            onClick: function(e, active) {
                if (!active || !active.length) return;
                var idx = active[0].index;
                if (issueByIndex[idx]) openIssueModal(issueByIndex[idx]);
            },
            onHover: function(e, active) {
                var idx = active && active.length ? active[0].index : -1;
                canvas.style.cursor = (idx >= 0 && issueByIndex[idx]) ? 'pointer' : 'default';
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        afterLabel: function(ctx) {
                            var issue = issueByIndex[ctx.dataIndex];
                            return issue ? '\u26A0 ' + issue.room_name + ' \u00B7 ' + issue.triggered_by : '';
                        }
                    }
                }
            },
            scales: {
                x: { ticks: { color: '#4d4d4d', font: { family: 'Poppins', size: 9 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 8 }, grid: { display: false } },
                y: { beginAtZero: true, ticks: { color: '#4d4d4d', font: { family: 'Poppins', size: 9 } }, grid: { color: 'rgba(47,0,79,0.07)' } },
            },
        },
    });

    if (label) label.textContent = (parseInt(range) === 1) ? 'Today Energy Profile (Wh per interval)' : 'Daily Energy Profile (Wh)';
    if (hint)  hint.textContent  = 'Amber = peak \u00B7 Red = issue';
}

function renderFindingsList(data, series, seriesLabels, isToday) {
    var listEl = document.getElementById('findingsList');
    if (!listEl) return;
    listEl.innerHTML = '';
    if (!series || !series.length) {
        listEl.appendChild(findingsItem('bi-inbox', '#888', isToday ? 'No readings recorded today.' : 'No data for the selected period.'));
        return;
    }

    var s = data.summary || {};
    var findings = [];

    // Peak power + time
    if (s.peak_power_w) {
        var peakTime = null;
        var peakAvg  = -Infinity;
        (data.intervals || []).forEach(function(r) {
            if (r.avg_power != null && r.avg_power > peakAvg) { peakAvg = r.avg_power; peakTime = r.time; }
        });
        var phrase = 'Peak power draw of <strong>' + s.peak_power_w.toFixed(1) + ' W</strong>';
        if (peakTime) phrase += ' with the busiest interval around <strong>' + peakTime + '</strong>';
        findings.push(findingsItem('bi-graph-up-arrow', '#f59e0b', phrase + '.'));
    }

    // Highest-energy point
    var maxIdx = 0, maxVal = -Infinity;
    series.forEach(function(v, i) { if (typeof v === 'number' && v > maxVal) { maxVal = v; maxIdx = i; } });
    if (typeof maxVal === 'number' && seriesLabels[maxIdx]) {
        findings.push(findingsItem('bi-fire', '#dc3545',
            'Highest energy consumption on <strong>' + seriesLabels[maxIdx] + '</strong> at <strong>' + maxVal.toFixed(2) + ' Wh</strong>.'));
    }

    // Energy + cost
    findings.push(findingsItem('bi-lightning-charge-fill', '#58078f',
        'Total energy used: <strong>' + (s.total_energy_kwh ?? 0).toFixed(2) + ' kWh</strong> (' + (s.total_energy_wh ?? 0).toFixed(0) + ' Wh), estimated cost <strong>\u20B1' + (s.est_cost_php ?? 0).toFixed(2) + '</strong>.'));

    // Occupancy + sessions
    var occHrs = ((s.total_minutes ?? 0) / 60).toFixed(1);
    var sess   = s.total_sessions ?? 0;
    if (sess || parseFloat(occHrs) > 0) {
        findings.push(findingsItem('bi-clock-fill', '#0d9488',
            'Lights occupied for <strong>' + occHrs + ' hrs</strong> across <strong>' + sess + ' session' + (sess === 1 ? '' : 's') + '</strong>.'));
    }

    // Savings comparison
    if (data.savings && data.savings.pct != null && data.savings.prev_kwh > 0) {
        var saved = data.savings.direction === 'saved';
        findings.push(findingsItem(saved ? 'bi-arrow-down-right' : 'bi-arrow-up-right', saved ? '#16a34a' : '#dc3545',
            'Energy ' + (saved ? '<strong>saved</strong> by ' : '<strong>increased</strong> by ') + Math.abs(data.savings.pct).toFixed(1) + '% vs the previous period.'));
    }

    // Anomalies
    var issues = data.issues || [];
    if (!issues.length) {
        findings.push(findingsItem('bi-check-circle-fill', '#16a34a', 'No anomalies detected in this period.'));
    } else {
        var pir = issues.filter(function(i) { return (i.triggered_by || '').toUpperCase() === 'PIR'; }).length;
        var pzm = issues.filter(function(i) { return (i.triggered_by || '').toUpperCase() === 'PZEM'; }).length;
        var parts = [];
        if (pir) parts.push(pir + ' PIR');
        if (pzm) parts.push(pzm + ' PZEM');
        findings.push(findingsItem('bi-exclamation-triangle-fill', '#dc3545',
            '<strong>' + issues.length + ' anomaly' + (issues.length === 1 ? '' : 'ies') + '</strong> detected' + (parts.length ? ' (' + parts.join(', ') + ')' : '') + ' \u2014 click the markers above for details.'));
    }

    findings.forEach(function(item) { listEl.appendChild(item); });
}

function findingsItem(icon, color, html) {
    var div = document.createElement('div');
    div.className = 'findings-item';
    div.innerHTML = '<i class="bi ' + icon + '"></i><span>' + html + '</span>';
    return div;
}

var findingsIssuesCache = [];

function renderFindingsAnomalies(issues) {
    var wrap = document.getElementById('findingsAnomalies');
    if (!wrap) return;
    findingsIssuesCache = issues || [];
    wrap.innerHTML = '';
    if (!findingsIssuesCache.length) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'flex';

    var header = document.createElement('div');
    header.className = 'findings-anomalies-header';

    var label = document.createElement('span');
    label.className = 'findings-anomalies-label';
    label.innerHTML = '<i class="bi bi-bell-fill me-1"></i>Open issues';

    var searchWrap = document.createElement('div');
    searchWrap.className = 'findings-anomalies-search';
    var searchIcon = document.createElement('i');
    searchIcon.className = 'bi bi-search';
    var searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.placeholder = 'Search room or issue...';
    searchInput.setAttribute('aria-label', 'Search open issues');
    searchInput.addEventListener('input', function() { renderFindingsAnomalyChips(searchInput.value); });
    searchWrap.appendChild(searchIcon);
    searchWrap.appendChild(searchInput);

    header.appendChild(label);
    header.appendChild(searchWrap);
    wrap.appendChild(header);

    var body = document.createElement('div');
    body.className = 'findings-anomalies-body';
    body.id = 'findingsAnomaliesBody';
    wrap.appendChild(body);
    renderFindingsAnomalyChips('');
}

function renderFindingsAnomalyChips(query) {
    var body = document.getElementById('findingsAnomaliesBody');
    if (!body) return;
    body.innerHTML = '';
    var q = (query || '').toLowerCase().trim();
    findingsIssuesCache.forEach(function(issue) {
        var haystack = ((issue.room_name || '') + ' ' + (issue.triggered_by || '')
            + ' ' + (issue.event_time || '')).toLowerCase();
        if (q && haystack.indexOf(q) === -1) return;
        var chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'findings-anomaly-chip';
        chip.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>'
            + (issue.room_name || 'Room') + ' \u00B7 ' + (issue.triggered_by || '')
            + ' \u00B7 ' + (issue.event_time ? issue.event_time.slice(0, 16).replace('T', ' ') : '');
        chip.onclick = function() { openIssueModal(issue); };
        body.appendChild(chip);
    });
}

// ── HISTORY TABLE ─────────────────────────────────────────────────────────────
function renderHistoryTable(rows, summary, range) {
    range = parseInt(range);
    const tbody = document.getElementById('historyBody');
    const tfoot = document.getElementById('historyFoot');
    const thead = document.getElementById('historyHead');
    tbody.innerHTML = '';
    if (tfoot) tfoot.innerHTML = '';

    if (range === 1) {
        // ── Today: 5-minute interval rows from real pzem_readings ──
        if (thead) thead.querySelector('tr').innerHTML = `
            <th style="text-align:left;">Time</th>
            <th>Energy (Wh)</th>
            <th>Voltage (V)</th>
            <th>Current (A)</th>
            <th>Power (W)</th>
        `;

        if (!rows || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No readings recorded today.</td></tr>';
            return;
        }

        var totalEnergy = 0, totalVoltage = 0, totalCurrent = 0, totalPower = 0;
        rows.forEach(function(r) {
            totalEnergy  += r.energy_wh;
            totalVoltage += r.avg_voltage;
            totalCurrent += r.avg_current;
            totalPower   += r.avg_power;
            var tr = document.createElement('tr');
            tr.innerHTML = '<td>' + r.time + '</td>'
                + '<td class="text-center">' + r.energy_wh.toFixed(4)   + '</td>'
                + '<td class="text-center">' + r.avg_voltage.toFixed(1) + '</td>'
                + '<td class="text-center">' + r.avg_current.toFixed(3) + '</td>'
                + '<td class="text-center">' + r.avg_power.toFixed(1)   + '</td>';
            tbody.appendChild(tr);
        });

        var n = rows.length;
        var occHrs = summary && summary.total_minutes
            ? (summary.total_minutes / 60).toFixed(1)
            : '0.0';
        if (tfoot) tfoot.innerHTML = '<tr style="font-weight:600;border-top:2px solid #e0d6f5;">'
            + '<td>Total / Avg</td>'
            + '<td class="text-center">' + totalEnergy.toFixed(4)          + '</td>'
            + '<td class="text-center">' + (totalVoltage / n).toFixed(1)   + '</td>'
            + '<td class="text-center">' + (totalCurrent / n).toFixed(3)   + '</td>'
            + '<td class="text-center">' + (totalPower   / n).toFixed(1)   + '</td>'
            + '</tr>'
            + '<tr style="font-weight:600;"><td colspan="5" class="text-center" style="padding:4px 0;color:#f59e0b;">Occupied Time: ' + occHrs + ' hrs</td></tr>';
        return;
    }

    // ── Multi-day: sessions + energy per day ──
    if (thead) thead.querySelector('tr').innerHTML = `
        <th style="text-align:left;">Date</th>
        <th>Sessions</th>
        <th>Occupied Time</th>
        <th>Energy (Wh)</th>
        <th>Energy (kWh)</th>
    `;

    if (!rows || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No data</td></tr>';
        return;
    }

    rows.forEach(d => {
        const kwh = (d.energy_wh / 1000).toFixed(4);
        const hrs = ((d.minutes  ?? 0) / 60).toFixed(1);
        const tr  = document.createElement('tr');
        tr.innerHTML = `
            <td>${d.label}</td>
            <td class="text-center">${d.sessions}</td>
            <td class="text-center">${hrs} hrs</td>
            <td class="text-center">${d.energy_wh.toFixed(2)} Wh</td>
            <td class="text-center">${kwh} kWh</td>
        `;
        tbody.appendChild(tr);
    });

    const totalWh   = rows.reduce((s, d) => s + d.energy_wh, 0);
    const totalKwh  = (totalWh / 1000).toFixed(4);
    const totalMins = rows.reduce((s, d) => s + (d.minutes ?? 0), 0);
    const totalHrs  = (totalMins / 60).toFixed(1);
    const totalSess = rows.reduce((s, d) => s + d.sessions, 0);

    if (tfoot) tfoot.innerHTML = `
        <tr style="font-weight:600; border-top:2px solid #e0d6f5;">
            <td>Total</td>
            <td class="text-center">${totalSess}</td>
            <td class="text-center">${totalHrs} hrs</td>
            <td class="text-center">${totalWh.toFixed(2)} Wh</td>
            <td class="text-center">${totalKwh} kWh</td>
        </tr>
    `;
}

// ── CSV EXPORT ────────────────────────────────────────────────────────────────
var exportMode = 'csv';

function exportCSV() {
    exportMode = 'csv';
    document.getElementById('exportModalTitle').textContent = 'Export CSV';
    var modal = new bootstrap.Modal(document.getElementById('exportModal'));
    modal.show();
}

// ── PDF EXPORT ────────────────────────────────────────────────────────────────
function exportPDF() {
    exportMode = 'pdf';
    document.getElementById('exportModalTitle').textContent = 'Export PDF';
    var modal = new bootstrap.Modal(document.getElementById('exportModal'));
    modal.show();
}

// ── Export selected section ────────────────────────────────────────────────────
function exportSection(sectionId) {
    var modalEl = document.getElementById('exportModal');
    var modal = bootstrap.Modal.getInstance(modalEl);
    if (modal) modal.hide();

    if (exportMode === 'csv') {
        exportSectionCSV(sectionId);
    } else {
        exportSectionPDF(sectionId);
    }
}

function exportSectionCSV(sectionId) {
    if (!lastData) return;
    const range = document.getElementById('periodSelect').value;

    if (sectionId === 'historyCard') {
        const headers = ['Date', 'Sessions', 'Occupied (hrs)', 'Energy (Wh)', 'Energy (kWh)'];
        const rows = (lastData.daily ?? [])
            .map(d => [
                d.date,
                d.sessions,
                ((d.minutes ?? 0) / 60).toFixed(1),
                d.energy_wh.toFixed(2),
                (d.energy_wh / 1000).toFixed(4),
            ]);
        const csv = [headers, ...rows].map(r => r.join(',')).join('\n');
        downloadCSV(csv, `luminesense_history_${range}days.csv`);
    } else {
        const sourceData = (range === '1') ? (lastData.hourly ?? []) : (lastData.daily ?? []);
        if (sourceData.length === 0) return;
        const headers = ['Time', 'Voltage (V)', 'Current (A)', 'Power (W)'];
        const rows = sourceData.map(d => [
            d.label,
            d.avg_voltage != null ? d.avg_voltage : '',
            d.avg_current != null ? d.avg_current : '',
            d.avg_power != null ? d.avg_power : '',
        ]);
        const csv = [headers, ...rows].map(r => r.join(',')).join('\n');
        const name = sectionId === 'lineGraphCard' ? 'line_graph' : 'bar_graph';
        downloadCSV(csv, `luminesense_${name}_${range}days.csv`);
    }
}

function downloadCSV(csv, filename) {
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

function exportSectionPDF(sectionId) {
    if (!lastData) return;
    var range = parseInt(document.getElementById('periodSelect').value);
    var cid   = document.getElementById('roomSelect')?.value ?? 0;

    var chartData;
    if (range === 1) {
        chartData = lastData.hourly ?? [];
    } else {
        chartData = lastData.daily ?? [];
    }

    var canvasId = (sectionId === 'lineGraphCard') ? 'lineChart' : 'barChart';
    var canvas = document.getElementById(canvasId);
    var graphImage = canvas ? canvas.toDataURL('image/png', 1.0) : null;

    var payload = {
        section: sectionId,
        range: range,
        classroom_id: cid,
        data: chartData,
        graph_image: graphImage
    };

    fetch('../../api/export-analytics-pdf.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function(res) {
        if (!res.ok) throw new Error('PDF generation failed');
        return res.blob();
    })
    .then(function(blob) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'luminesense_export.pdf';
        a.click();
        URL.revokeObjectURL(url);
    })
    .catch(function(err) {
        console.error('[PDF Export]', err);
        alert('Failed to generate PDF. Please try again.');
    });
}

// ── LOADING / ERROR ───────────────────────────────────────────────────────────
function setLoading(on) {
    document.querySelectorAll('.summary-column .live-stat-val').forEach(c => {
        if (on) c.textContent = '...';
    });
}

function showError() {
    const tbody = document.getElementById('historyBody');
    if (tbody) tbody.innerHTML =
        '<tr><td colspan="5" class="text-center" style="color:#e03333">Failed to load data. Check your connection.</td></tr>';
}

// ── Click vawGroup metric cards to focus metric (same as Filter by Metrics) ──
document.querySelectorAll('#vawGroup .live-stat-card[data-metric]').forEach(function(card) {
    card.addEventListener('click', function() {
        var isActive = this.classList.contains('metric-active');
        if (isActive) {
            // Deselect: switch to All Metrics
            document.querySelectorAll('.dept-member-filter-item').forEach(function(item) {
                var onclickAttr = item.getAttribute('onclick');
                if (onclickAttr && onclickAttr.includes("'all'")) {
                    setMetric(item, 'all');
                }
            });
            return;
        }
        var metric = this.getAttribute('data-metric');
        document.querySelectorAll('.dept-member-filter-item').forEach(function(item) {
            var onclickAttr = item.getAttribute('onclick');
            if (onclickAttr && onclickAttr.includes("'" + metric + "'")) {
                setMetric(item, metric);
            }
        });
    });
});

// ── Scrollbar hover handlers ───────────────────────────────────────────────────
['lineChart', 'barChart'].forEach(function(key) {
    var wrap = document.getElementById(key + 'ScrollWrap');
    var slider = document.getElementById(key + 'Scroll');
    if (!wrap || !slider) return;
    wrap.addEventListener('mouseenter', function() {
        scrollbarHovered[key] = true;
        var tipEl = document.getElementById(key + 'ScrollTip');
        if (tipEl) tipEl.classList.add('show');
        updateScrollTip(key);
    });
    wrap.addEventListener('mouseleave', function() {
        scrollbarHovered[key] = false;
        var tipEl = document.getElementById(key + 'ScrollTip');
        if (tipEl) tipEl.classList.remove('show');
        var p = document.getElementById(key + 'ScrollPending');
        if (p) p.classList.remove('show');
    });
});

// ── INIT ──────────────────────────────────────────────────────────────────────
// Set summary label to today's date
var initLabel = document.querySelector('.summary-label');
if (initLabel) initLabel.textContent = new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });

// Fetch real data immediately (no dummy pre-render)
onControlChange();

// Silent background refresh every 30 s
dataInterval = setInterval(() => {
    onControlChange();
}, 30000);

(function() {
    var panels = ['panelGuideInfo', 'panelFilterInfo'];
    var timers = {};
    var heading = document.querySelector('.main-container.faculty-timetable-heading');
    panels.forEach(function(id) {
        var btn = document.querySelector('[data-panel="' + id + '"]');
        var panel = document.getElementById(id);
        if (!btn || !panel) return;
        timers[id] = null;
        function open() {
            if (timers[id]) { clearTimeout(timers[id]); timers[id] = null; }
            panel.classList.add('show');
            if (heading) heading.style.zIndex = '1050';
        }
        function close() {
            if (timers[id]) clearTimeout(timers[id]);
            timers[id] = setTimeout(function() {
                panel.classList.remove('show');
                if (heading) heading.style.zIndex = '';
            }, 150);
        }
        btn.addEventListener('mouseenter', open);
        btn.addEventListener('mouseleave', close);
        panel.addEventListener('mouseenter', open);
        panel.addEventListener('mouseleave', close);
    });
})();

function syncRoomSelect(val) {
    var sel = document.getElementById('roomSelect');
    if (sel) sel.value = val;
}
function isRoomPrototype(rid) {
    var r = roomData.find(function(d) { return d.id == rid; });
    return r && r.is_prototype == 1;
}
function showNoDeviceState() {
    var badge = document.getElementById('liveBadge');
    if (badge) { badge.className = 'live-badge stale'; badge.innerHTML = '<span class="live-dot stale"></span> No Device'; }
    var ids = ['liveVoltage','liveCurrent','livePower','liveEnergy','liveStatus'];
    ids.forEach(function(id) { var el = document.getElementById(id); if (el) el.textContent = id === 'liveStatus' ? '\u2014' : (id === 'liveEnergy' ? '\u2014 Wh' : '\u2014 ' + (id === 'liveVoltage' ? 'V' : id === 'liveCurrent' ? 'A' : id === 'livePower' ? 'W' : '')); });
    var dot = document.getElementById('liveStatusDot');
    if (dot) { dot.style.background = '#ccc'; dot.classList.remove('on'); }
    var sumIds = ['sumEnergy','sumMinutes','sumVoltage','sumCurrent','sumPower','sumCost'];
    sumIds.forEach(function(id) { var el = document.getElementById(id); if (el) el.textContent = '\u2014'; });
    var tbody = document.getElementById('historyBody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No data \u2014 no device connected</td></tr>';
    var tfoot = document.getElementById('historyFoot');
    if (tfoot) tfoot.innerHTML = '';
    currentIssues = [];
    var sr = document.getElementById('periodSelect');
    renderSavings(null, sr ? parseInt(sr.value) : 7);
    var fCard = document.getElementById('findingsCard');
    if (fCard) fCard.style.display = 'none';
    destroyFindingsCharts();
    if (typeof lineChartInstance !== 'undefined') {
        lineChartInstance.data.labels = ['No data'];
        lineChartInstance.data.datasets.forEach(function(ds) { ds.data = []; });
        lineChartInstance.update();
    }
    if (typeof barChartInstance !== 'undefined') {
        barChartInstance.data.labels = ['No data'];
        barChartInstance.data.datasets.forEach(function(ds) { ds.data = []; });
        barChartInstance.update();
    }
    updateChartTitles();
}
function deselectRoom() {
    document.querySelectorAll('.rooms-card .stat-card.active-room').forEach(function(c) {
        c.classList.remove('active-room');
    });
    var sub = document.getElementById('tabSubheading');
    if (sub) sub.textContent = 'All Rooms Selected';
    syncRoomSelect(0);
    if (typeof onControlChange === 'function') onControlChange();
    if (typeof fetchLive === 'function') fetchLive();
    if (typeof checkPolling === 'function') checkPolling();
}
document.querySelectorAll('.rooms-card .stat-card').forEach(function(card) {
    card.addEventListener('click', function(e) {
        var active = document.querySelector('.rooms-card .stat-card.active-room');
        if (active && active !== this) active.classList.remove('active-room');
        var wasActive = this.classList.contains('active-room');
        this.classList.toggle('active-room');
        var sub = document.getElementById('tabSubheading');
        var rid = this.getAttribute('data-room-id');
        if (sub) {
            if (wasActive) {
                sub.textContent = 'All Rooms Selected';
                syncRoomSelect(0);
            } else {
                var nameEl = this.querySelector('.stat-value');
                sub.textContent = nameEl ? nameEl.textContent + ' Selected' : 'Room Selected';
                syncRoomSelect(rid);
            }
        }
        if (!wasActive && !isRoomPrototype(rid)) {
            showNoDeviceState();
            pausePolling();
        } else {
            if (typeof onControlChange === 'function') onControlChange();
            if (typeof fetchLive === 'function') fetchLive();
        }
        if (typeof checkPolling === 'function') checkPolling();
    });
});