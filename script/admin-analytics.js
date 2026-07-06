// admin-analytics.js
// Matches updated admin-analytics.php layout:
//   - Live readings strip (polls every 3s)
//   - Summary cards
//   - Daily energy bar chart
//   - Daily history table with export

const API_URL = '../../api/analytics.php';
const LIVE_API_URL = '../../api/live-pzem.php';

// ── Sample static data ──────────────────────────────────────────────────────
const sampleDaily = [
    { label: 'Mon', date: '2026-06-29', energy_wh: 2450, sessions: 4, minutes: 320 },
    { label: 'Tue', date: '2026-06-30', energy_wh: 3200, sessions: 6, minutes: 410 },
    { label: 'Wed', date: '2026-07-01', energy_wh: 2800, sessions: 5, minutes: 380 },
    { label: 'Thu', date: '2026-07-02', energy_wh: 4100, sessions: 8, minutes: 520 },
    { label: 'Fri', date: '2026-07-03', energy_wh: 1900, sessions: 3, minutes: 250 },
    { label: 'Sat', date: '2026-07-04', energy_wh: 1500, sessions: 2, minutes: 180 },
    { label: 'Sun', date: '2026-07-05', energy_wh: 2200, sessions: 3, minutes: 290 },
];

const sampleSummary = {
    total_energy_kwh: 18.15,
    total_minutes: 2350,
    total_sessions: 31,
    avg_voltage: 221.5,
    avg_current: 1.85,
    peak_power: 456.3,
    total_cost: 203.28,
};

const sampleHourly = Array.from({ length: 24 }, (_, i) => ({
    hour: i,
    voltage: 220 + Math.random() * 4,
    current: 1.2 + Math.random() * 1.6,
    power: 280 + Math.random() * 220,
}));

// ── Chart instances ─────────────────────────────────────────────────────────
const barChartInstance = new Chart(document.getElementById('barChart'), {
    type: 'bar',
    data: {
        labels: sampleDaily.map(d => d.label),
        datasets: [
            {
                label: 'Voltage (V)',
                data: sampleDaily.map(function() { return 218 + Math.random() * 6; }),
                backgroundColor: 'rgba(116,47,211,0.85)',
                borderRadius: 4,
                maxBarThickness: 16,
            },
            {
                label: 'Current (A)',
                data: sampleDaily.map(function() { return 1.0 + Math.random() * 2.0; }),
                backgroundColor: 'rgba(245,158,11,0.85)',
                borderRadius: 4,
                maxBarThickness: 16,
            },
            {
                label: 'Power (W)',
                data: sampleDaily.map(function() { return 250 + Math.random() * 280; }),
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
                    var meta = chart.getDatasetMeta(index);
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
                grid: { display: false },
            },
            y: {
                beginAtZero: true,
                ticks: { color: '#4d4d4d', font: { family: 'Poppins', size: 10 } },
                grid: { color: 'rgba(47,0,79,0.07)' },
            }
        }
    }
});

const lineChartInstance = new Chart(document.getElementById('lineChart'), {
    type: 'line',
    data: {
        labels: sampleHourly.map(d => d.hour + ':00'),
        datasets: [
            {
                label: 'Voltage (V)',
                data: sampleHourly.map(d => d.voltage),
                borderColor: '#742fd3',
                backgroundColor: 'rgba(116,47,211,0.10)',
                fill: true,
                tension: 0.3,
                pointRadius: 2,
            },
            {
                label: 'Current (A)',
                data: sampleHourly.map(d => d.current),
                borderColor: '#f59e0b',
                backgroundColor: 'rgba(245,158,11,0.10)',
                fill: true,
                tension: 0.3,
                pointRadius: 2,
                yAxisID: 'y1',
            },
            {
                label: 'Power (W)',
                data: sampleHourly.map(d => d.power),
                borderColor: '#16a34a',
                backgroundColor: 'rgba(22,163,74,0.10)',
                fill: true,
                tension: 0.3,
                pointRadius: 2,
                yAxisID: 'y2',
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
                    var meta = chart.getDatasetMeta(index);
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
                grid: { display: false },
            },
            y: {
                type: 'linear',
                display: true,
                position: 'left',
                title: { display: false },
                ticks: { color: '#742fd3', font: { family: 'Poppins', size: 10 } },
                grid: { color: 'rgba(47,0,79,0.07)' },
            },
            y1: {
                type: 'linear',
                display: true,
                position: 'left',
                title: { display: false },
                ticks: { color: '#f59e0b', font: { family: 'Poppins', size: 10 } },
                grid: { display: false },
            },
            y2: {
                type: 'linear',
                display: true,
                position: 'left',
                title: { display: false },
                ticks: { color: '#16a34a', font: { family: 'Poppins', size: 10 } },
                grid: { display: false },
            },
        }
    }
});

let lastData = null;

// ── Helpers ────────────────────────────────────────────────────────────────
function getCid() {
    return document.getElementById('roomSelect')?.value ?? 0;
}

// ── LIVE READINGS — polls every 3 seconds ──────────────────────────────────
async function fetchLive() {
    try {
        const cid = getCid();
        const res = await fetch(`${LIVE_API_URL}?classroom_id=${cid}`);
        const data = await res.json();

        const dot = document.getElementById('liveStatusDot');
        const status = document.getElementById('liveStatus');
        const badge = document.getElementById('liveBadge');

        if (!data.success || data.stale) {
            // No active Arduino connection or API error
            if (badge) {
                badge.className = 'live-badge stale';
                badge.innerHTML = '<span class="live-dot stale"></span> No Device';
            }
            if (status) status.textContent = '—';
            if (dot) {
                dot.style.background = '#ccc';
                dot.classList.remove('on');
            }
            document.getElementById('liveVoltage').textContent = '— V';
            document.getElementById('liveCurrent').textContent = '— A';
            document.getElementById('livePower').textContent = '— W';
            document.getElementById('liveEnergy').textContent = '— Wh';
            return;
        }

        // Arduino is connected and sending
        if (badge) {
            badge.className = 'live-badge';
            badge.innerHTML = '<span class="live-dot"></span> Live';
        }

        document.getElementById('liveVoltage').textContent = data.voltage.toFixed(1) + ' V';
        document.getElementById('liveCurrent').textContent = data.current.toFixed(3) + ' A';
        document.getElementById('livePower').textContent = data.power.toFixed(2) + ' W' +
            ' (' + data.power_kw.toFixed(3) + ' kW)';
        document.getElementById('liveEnergy').textContent = data.energy.toFixed(4) + ' Wh';

        if (data.lights_on) {
            if (status) status.textContent = 'ON';
            if (dot) {
                dot.style.background = '#27ae60';
                dot.classList.add('on');
            }
        } else {
            if (status) status.textContent = 'OFF';
            if (dot) {
                dot.style.background = '#ccc';
                dot.classList.remove('on');
            }
        }

    } catch (err) {
        console.warn('[Live]', err);
    }
}

// ── Polling control ─────────────────────────────────────────────────────────
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
    var activeRooms = document.querySelectorAll('.rooms-card .stat-card.active-room');
    var metricEl = document.querySelector('.dept-member-filter-item.active');
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

// ── Period / Metric filter helpers ──────────────────────────────────────────
function setPeriod(el, days) {
    el.parentElement.querySelectorAll('.dept-member-filter-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');
    const sel = document.getElementById('periodSelect');
    if (sel) { sel.value = days; sel.dispatchEvent(new Event('change')); }
    checkPolling();
}

function syncVawFromLegend() {
    var charts = [lineChartInstance, barChartInstance];
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
    var visible = chart.data.datasets.filter(function(ds, i) { return !chart.getDatasetMeta(i).hidden; }).map(function(ds) { return ds.label; });
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
    // Resize V,A,W stat cards
    var vawCards = document.querySelectorAll('#vawGroup .live-stat-card');
    vawCards.forEach(function(card) {
        card.classList.remove('metric-active', 'metric-dimmed');
        if (metric === 'all') return;
        var m = card.getAttribute('data-metric');
        if (m === metric) {
            card.classList.add('metric-active');
        } else {
            card.classList.add('metric-dimmed');
        }
    });
    // Update metric info text
    var infoEl = document.getElementById('metricInfo');
    var infoText = infoEl.querySelector('.metric-info-text');
    var formulas = {
        voltage: 'Voltage (V) = Energy (J) \u00F7 Charge (C)',
        current: 'Current (A) = Power (W) \u00F7 Voltage (V)',
        power:   'Power (W) = Voltage (V) \u00D7 Current (A)'
    };
    if (metric === 'all') {
        infoText.innerHTML = 'Voltage, Current, and Power readings are used to compute Energy (Wh) over time. <span class="metric-formula">Energy (Wh) = Power (W) \u00D7 Time (h)</span>';
    } else if (formulas[metric]) {
        infoText.innerHTML = '<span class="metric-formula">' + formulas[metric] + '</span>';
    }
    checkPolling();
}

// ── MAIN FETCH + RENDER ────────────────────────────────────────────────────
function updateLineData(labels) {
    if (!labels || labels.length === 0) labels = ['No data'];
    var newData = labels.map(function() {
        return {
            voltage: 218 + Math.random() * 6,
            current: 1.0 + Math.random() * 2.0,
            power: 250 + Math.random() * 280,
        };
    });
    lineChartInstance.data.labels = labels;
    lineChartInstance.data.datasets[0].data = newData.map(function(d) { return d.voltage; });
    lineChartInstance.data.datasets[1].data = newData.map(function(d) { return d.current; });
    lineChartInstance.data.datasets[2].data = newData.map(function(d) { return d.power; });
    lineChartInstance.update();
}

async function onControlChange() {
    const range = parseInt(document.getElementById('periodSelect').value);
    const cid = getCid();
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
            labelEl.innerHTML = from.toLocaleDateString('en-US', opts) + ' \u2013 ' + now.toLocaleDateString('en-US', opts) + ', ' + now.getFullYear();
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
        const res = await fetch(`${API_URL}?range=${range}&classroom_id=${cid}`);
        const data = await res.json();
        if (!data.success) throw new Error(data.message ?? 'API error');

        lastData = data;
        renderSummaryCards(data.summary);
        renderHistoryTable(data.daily, data.summary, range);
        var chartLabels;
        if (range === 1) {
            chartLabels = Array.from({ length: 24 }, function(_, i) {
                var h = i.toString().padStart(2, '0');
                return h + ':00';
            });
        } else {
            chartLabels = (data.daily || []).map(function(d) { return d.label; });
        }
        renderEnergyChart(chartLabels);
        updateLineData(chartLabels);
        console.log('Daily data:', data.daily);

    } catch (err) {
        console.error('[Analytics]', err);
        showError();
        renderSummaryCards(sampleSummary);
        var fallbackLabels;
        if (range === 1) {
            fallbackLabels = Array.from({ length: 24 }, function(_, i) {
                var h = i.toString().padStart(2, '0');
                return h + ':00';
            });
        } else {
            fallbackLabels = sampleDaily.map(function(d) { return d.label; });
        }
        renderEnergyChart(fallbackLabels);
        renderHistoryTable(sampleDaily, sampleSummary, range);
        updateLineData(fallbackLabels);
    } finally {
        setLoading(false);
    }
}

// ── SUMMARY CARDS ──────────────────────────────────────────────────────────
function renderSummaryCards(s) {
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    set('sumEnergy', (s.total_energy_kwh ?? 0).toFixed(4) + ' kWh');
    set('sumMinutes', ((s.total_minutes ?? 0) / 60).toFixed(1) + ' hrs');
    set('sumVoltage', (s.avg_voltage ?? 0).toFixed(1) + ' V');
    set('sumCurrent', (s.avg_current ?? 0).toFixed(3) + ' A');
    set('sumPower', (s.peak_power ?? 0).toFixed(1) + ' W');
    set('sumCost', '₱' + (s.total_cost ?? 0).toFixed(2));
}

// ── ENERGY CHART ───────────────────────────────────────────────────────────
function renderEnergyChart(labels) {
    if (!labels || labels.length === 0) {
        barChartInstance.data.labels = ['No data'];
        barChartInstance.data.datasets.forEach(function(ds) { ds.data = [0]; });
        barChartInstance.update();
        return;
    }
    barChartInstance.data.labels = labels;
    barChartInstance.data.datasets[0].data = labels.map(function() { return 218 + Math.random() * 6; });
    barChartInstance.data.datasets[1].data = labels.map(function() { return 1.0 + Math.random() * 2.0; });
    barChartInstance.data.datasets[2].data = labels.map(function() { return 250 + Math.random() * 280; });
    barChartInstance.update();
}

// ── DAILY HISTORY TABLE ────────────────────────────────────────────────────
function renderHistoryTable(daily, summary, range) {
    range = parseInt(range);
    const tbody = document.getElementById('historyBody');
    const tfoot = document.getElementById('historyFoot');
    const thead = document.getElementById('historyHead');
    tbody.innerHTML = '';
    if (tfoot) tfoot.innerHTML = '';

    if (range === 1) {
        if (thead) thead.querySelector('tr').innerHTML = `
            <th style="text-align:left;">Time</th>
            <th>Energy (Wh)</th>
            <th>Voltage (V)</th>
            <th>Current (A)</th>
            <th>Power (W)</th>
        `;
        var intervals = 288;
        var totalEnergy = 0, totalVoltage = 0, totalCurrent = 0, totalPower = 0;
        for (var i = 0; i < intervals; i++) {
            var h = Math.floor(i / 12);
            var m = (i % 12) * 5;
            var timeLabel = h.toString().padStart(2, '0') + ':' + m.toString().padStart(2, '0');
            var energy = (0.5 + Math.random() * 4).toFixed(2);
            var volt = (218 + Math.random() * 6).toFixed(1);
            var curr = (1.0 + Math.random() * 2.0).toFixed(3);
            var pow = (250 + Math.random() * 280).toFixed(1);
            totalEnergy += parseFloat(energy);
            totalVoltage += parseFloat(volt);
            totalCurrent += parseFloat(curr);
            totalPower += parseFloat(pow);
            var tr = document.createElement('tr');
            tr.innerHTML = '<td>' + timeLabel + '</td><td class="text-center">' + energy + '</td><td class="text-center">' + volt + '</td><td class="text-center">' + curr + '</td><td class="text-center">' + pow + '</td>';
            tbody.appendChild(tr);
        }
        if (tfoot) tfoot.innerHTML = '<tr style="font-weight:600;border-top:2px solid #e0d6f5;"><td>Total</td><td class="text-center">' + totalEnergy.toFixed(2) + '</td><td class="text-center">' + (totalVoltage / intervals).toFixed(1) + '</td><td class="text-center">' + (totalCurrent / intervals).toFixed(3) + '</td><td class="text-center">' + (totalPower / intervals).toFixed(1) + '</td></tr>';
        return;
    }

    if (thead) thead.querySelector('tr').innerHTML = `
        <th style="text-align:left;">Date</th>
        <th>Sessions</th>
        <th>Occupied Time</th>
        <th>Energy (Wh)</th>
        <th>Energy (kWh)</th>
    `;

    if (!daily || daily.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No data</td></tr>';
        return;
    }

    daily.forEach(d => {
        const kwh = (d.energy_wh / 1000).toFixed(4);
        const hrs = ((d.minutes ?? 0) / 60).toFixed(1);
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${d.label}</td>
            <td class="text-center">${d.sessions}</td>
            <td class="text-center">${hrs} hrs</td>
            <td class="text-center">${d.energy_wh.toFixed(2)} Wh</td>
            <td class="text-center">${kwh} kWh</td>
        `;
        tbody.appendChild(tr);
    });

    const totalWh = daily.reduce((s, d) => s + d.energy_wh, 0);
    const totalKwh = (totalWh / 1000).toFixed(4);
    const totalMins = daily.reduce((s, d) => s + (d.minutes ?? 0), 0);
    const totalHrs = (totalMins / 60).toFixed(1);
    const totalSess = daily.reduce((s, d) => s + d.sessions, 0);

    tfoot.innerHTML = `
        <tr style="font-weight:600; border-top:2px solid #e0d6f5;">
            <td>Total</td>
            <td class="text-center">${totalSess}</td>
            <td class="text-center">${totalHrs} hrs</td>
            <td class="text-center">${totalWh.toFixed(2)} Wh</td>
            <td class="text-center">${totalKwh} kWh</td>
        </tr>
    `;
}

function exportCSV() {
    if (!lastData) return;
    const range = document.getElementById('periodSelect').value;
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
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `luminesense_report_${range}days.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

// ── PDF EXPORT ─────────────────────────────────────────────────────────────
function exportPDF() {
    window.print();
}

// ── LOADING / ERROR ────────────────────────────────────────────────────────
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

// ── INIT ───────────────────────────────────────────────────────────────────
var initLabels = sampleDaily.map(function(d) { return d.label; });
renderSummaryCards(sampleSummary);
renderEnergyChart(initLabels);
renderHistoryTable(sampleDaily, sampleSummary, document.getElementById('periodSelect').value);
updateLineData(initLabels);
// Set initial summary label date range
var initLabel = document.querySelector('.summary-label');
if (initLabel) initLabel.textContent = new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
onControlChange();

// ── Silent background refresh every 30s ───────────────────────────────────
dataInterval = setInterval(() => {
    onControlChange();
}, 30000);