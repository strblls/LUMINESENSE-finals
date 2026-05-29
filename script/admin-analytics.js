// admin-analytics.js
// Matches updated admin-analytics.php layout:
//   - Live readings strip (polls every 3s)
//   - Summary cards
//   - Daily energy bar chart
//   - Daily history table with export

const API_URL      = '../../api/analytics.php';
const LIVE_API_URL = '../../api/live-pzem.php';

// ── Chart instance ─────────────────────────────────────────────────────────
const usageChart = new Chart(document.getElementById('usageChart'), {
    type: 'bar',
    data: {
        labels: [],
        datasets: [{
            label: 'Energy (Wh)',
            data: [],
            backgroundColor: 'rgba(116,47,211,0.90)',
            borderRadius: 10,
            maxBarThickness: 32,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: {
                ticks: { color: '#4d4d4d', font: { family: 'Poppins', size: 12 } },
                grid:  { display: false },
            },
            y: {
                beginAtZero: true,
                ticks: { color: '#4d4d4d', font: { family: 'Poppins', size: 12 } },
                grid:  { color: 'rgba(47,0,79,0.07)' },
            }
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
        const res  = await fetch(`${LIVE_API_URL}?classroom_id=${cid}`);
        const data = await res.json();
        if (!data.success) return;

        document.getElementById('liveVoltage').textContent = data.voltage.toFixed(1)  + ' V';
        document.getElementById('liveCurrent').textContent = data.current.toFixed(3)  + ' A';
        document.getElementById('livePower').textContent   = data.power.toFixed(2)    + ' W' +
            ' (' + data.power_kw.toFixed(3) + ' kW)';
        document.getElementById('liveEnergy').textContent  = data.energy.toFixed(4)   + ' Wh';

        const dot    = document.getElementById('liveStatusDot');
        const status = document.getElementById('liveStatus');
        if (data.lights_on) {
            status.textContent   = 'ON';
            dot.style.background = '#27ae60';
        } else {
            status.textContent   = 'OFF';
            dot.style.background = '#ccc';
        }

    } catch (err) {
        console.warn('[Live]', err);
    }
}

fetchLive();
setInterval(fetchLive, 3000);

// ── MAIN FETCH + RENDER ────────────────────────────────────────────────────
async function onControlChange() {
    const range      = document.getElementById('periodSelect').value;
    const cid        = getCid();
    const roomSelect = document.getElementById('roomSelect');

    if (roomSelect && roomSelect.tagName === 'SELECT') {
        const roomName = roomSelect.options[roomSelect.selectedIndex].text;
        document.getElementById('roomLabel').textContent = cid == 0 ? 'All Rooms' : roomName;
    }

    setLoading(true);

    try {
        const res  = await fetch(`${API_URL}?range=${range}&classroom_id=${cid}`);
        const data = await res.json();
        if (!data.success) throw new Error(data.message ?? 'API error');

        lastData = data;
        renderSummaryCards(data.summary);
        renderEnergyChart(data.daily);
        renderHistoryTable(data.daily, data.summary);

    } catch (err) {
        console.error('[Analytics]', err);
        showError();
    } finally {
        setLoading(false);
    }
}

// ── SUMMARY CARDS ──────────────────────────────────────────────────────────
function renderSummaryCards(s) {
    document.getElementById('sumEnergy').textContent   = (s.total_energy_kwh ?? 0).toFixed(4) + ' kWh';
    document.getElementById('sumCost').textContent     = '₱' + (s.est_cost_php ?? 0).toFixed(2);
    document.getElementById('sumMinutes').textContent  = ((s.total_minutes ?? 0) / 60).toFixed(1) + ' hrs';
    document.getElementById('sumSessions').textContent = s.total_sessions ?? 0;
    document.getElementById('sumVoltage').textContent  = (s.avg_voltage ?? 0).toFixed(1) + ' V';
}

// ── ENERGY CHART ───────────────────────────────────────────────────────────
function renderEnergyChart(daily) {
    if (!daily || daily.length === 0) {
        usageChart.data.labels           = ['No data'];
        usageChart.data.datasets[0].data = [0];
        usageChart.update();
        return;
    }
    usageChart.data.labels           = daily.map(d => d.label);
    usageChart.data.datasets[0].data = daily.map(d => d.energy_wh);
    usageChart.update();
}

// ── DAILY HISTORY TABLE ────────────────────────────────────────────────────
function renderHistoryTable(daily, summary) {
    const tbody = document.getElementById('historyBody');
    const tfoot = document.getElementById('historyFoot');
    tbody.innerHTML = '';

    if (!daily || daily.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No data</td></tr>';
        return;
    }

    const active = daily.filter(d => d.sessions > 0 || d.energy_wh > 0);

    if (active.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No sessions in this period yet</td></tr>';
    } else {
        active.forEach(d => {
            const kwh  = (d.energy_wh / 1000).toFixed(4);
            const cost = ((d.energy_wh / 1000) * 11).toFixed(2);
            const hrs  = ((d.minutes ?? 0) / 60).toFixed(1);
            const tr   = document.createElement('tr');
            tr.innerHTML = `
                <td>${d.label}</td>
                <td class="text-center">${d.sessions}</td>
                <td class="text-center">${hrs} hrs</td>
                <td class="text-center">${(d.energy_wh).toFixed(2)} Wh</td>
                <td class="text-center">${kwh} kWh</td>
                <td class="text-center">₱${cost}</td>
            `;
            tbody.appendChild(tr);
        });
    }

    const totalWh   = daily.reduce((s, d) => s + d.energy_wh, 0);
    const totalKwh  = (totalWh / 1000).toFixed(4);
    const totalCost = ((totalWh / 1000) * 11).toFixed(2);
    const totalMins = daily.reduce((s, d) => s + (d.minutes ?? 0), 0);
    const totalHrs  = (totalMins / 60).toFixed(1);
    const totalSess = daily.reduce((s, d) => s + d.sessions, 0);

    tfoot.innerHTML = `
        <tr style="font-weight:600; border-top:2px solid #e0d6f5;">
            <td>Total</td>
            <td class="text-center">${totalSess}</td>
            <td class="text-center">${totalHrs} hrs</td>
            <td class="text-center">${totalWh.toFixed(2)} Wh</td>
            <td class="text-center">${totalKwh} kWh</td>
            <td class="text-center">₱${totalCost}</td>
        </tr>
    `;
}

// ── CSV EXPORT ─────────────────────────────────────────────────────────────
function exportCSV() {
    if (!lastData) return;
    const range   = document.getElementById('periodSelect').value;
    const headers = ['Date', 'Sessions', 'Occupied (hrs)', 'Energy (Wh)', 'Energy (kWh)', 'Est Cost (PHP)'];
    const rows    = (lastData.daily ?? [])
        .filter(d => d.sessions > 0 || d.energy_wh > 0)
        .map(d => [
            d.date,
            d.sessions,
            ((d.minutes ?? 0) / 60).toFixed(1),
            d.energy_wh.toFixed(2),
            (d.energy_wh / 1000).toFixed(4),
            ((d.energy_wh / 1000) * 11).toFixed(2),
        ]);

    const csv  = [headers, ...rows].map(r => r.join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
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
    document.querySelectorAll('.summary-val').forEach(c => {
        if (on) c.textContent = '...';
    });
}

function showError() {
    const tbody = document.getElementById('historyBody');
    if (tbody) tbody.innerHTML =
        '<tr><td colspan="6" class="text-center" style="color:#e03333">Failed to load data. Check your connection.</td></tr>';
}

// ── INIT ───────────────────────────────────────────────────────────────────
onControlChange();