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

// ── Chart instances ───────────────────────────────────────────────────────────
const barChartInstance = new Chart(document.getElementById('barChart'), {
    type: 'bar',
    data: {
        labels: [],
        datasets: [
            {
                label: 'Voltage (V)',
                data: [],
                backgroundColor: 'rgba(116,47,211,0.85)',
                borderRadius: 4,
                maxBarThickness: 16,
            },
            {
                label: 'Current (A)',
                data: [],
                backgroundColor: 'rgba(245,158,11,0.85)',
                borderRadius: 4,
                maxBarThickness: 16,
            },
            {
                label: 'Power (W)',
                data: [],
                backgroundColor: 'rgba(22,163,74,0.85)',
                borderRadius: 4,
                maxBarThickness: 16,
            },
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
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
                ticks: { color: '#4d4d4d', font: { family: 'Poppins', size: 10 } },
                grid:  { color: 'rgba(47,0,79,0.07)' },
            }
        }
    }
});

const lineChartInstance = new Chart(document.getElementById('lineChart'), {
    type: 'line',
    data: {
        labels: [],
        datasets: [
            {
                label: 'Voltage (V)',
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

    const voltages = chartData.map(d => (d && d.avg_voltage != null) ? d.avg_voltage : null);
    const currents = chartData.map(d => (d && d.avg_current != null) ? d.avg_current : null);
    const powers   = chartData.map(d => (d && d.avg_power   != null) ? d.avg_power   : null);

    // Bar chart
    barChartInstance.data.labels           = labels;
    barChartInstance.data.datasets[0].data = voltages;
    barChartInstance.data.datasets[1].data = currents;
    barChartInstance.data.datasets[2].data = powers;
    barChartInstance.update();

    // Line chart
    lineChartInstance.data.labels           = labels;
    lineChartInstance.data.datasets[0].data = voltages;
    lineChartInstance.data.datasets[1].data = currents;
    lineChartInstance.data.datasets[2].data = powers;
    lineChartInstance.update();

    // ── Dynamic canvas width for horizontal scroll ──
    var count = labels.length;
    if (count > 0) {
        var barCanvas = document.getElementById('barChart');
        var lineCanvas = document.getElementById('lineChart');
        var barMinW = Math.max(count * 50, 400);
        var lineMinW = Math.max(count * 40, 400);
        if (barCanvas) barCanvas.style.minWidth = barMinW + 'px';
        if (lineCanvas) lineCanvas.style.minWidth = lineMinW + 'px';
    }
}

// ── TOGGLE CHART MAXIMIZE ─────────────────────────────────────────────────────
function toggleChartMaximize(cardId) {
    var card = document.getElementById(cardId);
    if (!card) return;
    var btn = card.querySelector('.chart-maximize-btn');
    var isMax = card.classList.toggle('chart-maximized');
    if (btn) {
        btn.innerHTML = isMax ? '<i class="bi bi-arrows-collapse"></i>' : '<i class="bi bi-arrows-expand"></i>';
        btn.title = isMax ? 'Minimize' : 'Maximize';
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

        updateCharts(chartLabels, chartData);

    } catch (err) {
        console.error('[Analytics]', err);
        showError();
        renderSummaryCards(sampleSummary);
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
        if (tfoot) tfoot.innerHTML = '<tr style="font-weight:600;border-top:2px solid #e0d6f5;">'
            + '<td>Total / Avg</td>'
            + '<td class="text-center">' + totalEnergy.toFixed(4)          + '</td>'
            + '<td class="text-center">' + (totalVoltage / n).toFixed(1)   + '</td>'
            + '<td class="text-center">' + (totalCurrent / n).toFixed(3)   + '</td>'
            + '<td class="text-center">' + (totalPower   / n).toFixed(1)   + '</td>'
            + '</tr>';
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
        var metric = this.getAttribute('data-metric');
        document.querySelectorAll('.dept-member-filter-item').forEach(function(item) {
            var onclickAttr = item.getAttribute('onclick');
            if (onclickAttr && onclickAttr.includes("'" + metric + "'")) {
                setMetric(item, metric);
            }
        });
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