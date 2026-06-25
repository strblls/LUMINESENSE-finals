// admin-analytics.js
// Matches updated admin-analytics.php layout:
//   - Live readings strip (polls every 3s)
//   - Summary cards
//   - Daily energy bar chart
//   - Daily history table with export

const API_URL = '../../api/analytics.php';
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
                grid: { display: false },
            },
            y: {
                beginAtZero: true,
                ticks: { color: '#4d4d4d', font: { family: 'Poppins', size: 12 } },
                grid: { color: 'rgba(47,0,79,0.07)' },
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

fetchLive();
setInterval(fetchLive, 3000);

// ── MAIN FETCH + RENDER ────────────────────────────────────────────────────
async function onControlChange() {
    const range = document.getElementById('periodSelect').value;
    const cid = getCid();
    const roomSelect = document.getElementById('roomSelect');

    if (roomSelect && roomSelect.tagName === 'SELECT') {
        const roomName = roomSelect.options[roomSelect.selectedIndex].text;
        document.getElementById('roomLabel').textContent = cid == 0 ? 'All Rooms' : roomName;
    }

    setLoading(true);

    try {
        const res = await fetch(`${API_URL}?range=${range}&classroom_id=${cid}`);
        const data = await res.json();
        if (!data.success) throw new Error(data.message ?? 'API error');

        lastData = data;
        renderSummaryCards(data.summary);
        renderEnergyChart(data.daily);
        renderHistoryTable(data.daily, data.summary);
        console.log('Daily data:', data.daily);

    } catch (err) {
        console.error('[Analytics]', err);
        showError();
    } finally {
        setLoading(false);
    }
}

// ── SUMMARY CARDS ──────────────────────────────────────────────────────────
function renderSummaryCards(s) {
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    set('sumEnergy', (s.total_energy_kwh ?? 0).toFixed(4) + ' kWh');
    set('sumMinutes', ((s.total_minutes ?? 0) / 60).toFixed(1) + ' hrs');
    set('sumSessions', s.total_sessions ?? 0);
    set('sumVoltage', (s.avg_voltage ?? 0).toFixed(1) + ' V');
}

// ── ENERGY CHART ───────────────────────────────────────────────────────────
function renderEnergyChart(daily) {
    if (!daily || daily.length === 0) {
        usageChart.data.labels = ['No data'];
        usageChart.data.datasets[0].data = [0];
        usageChart.update();
        return;
    }
    usageChart.data.labels = daily.map(d => d.label);
    usageChart.data.datasets[0].data = daily.map(d => d.energy_wh);
    usageChart.update();
}

// ── DAILY HISTORY TABLE ────────────────────────────────────────────────────
function renderHistoryTable(daily, summary) {
    const tbody = document.getElementById('historyBody');
    const tfoot = document.getElementById('historyFoot');
    tbody.innerHTML = '';
    if (tfoot) tfoot.innerHTML = '';

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
    document.querySelectorAll('.summary-val').forEach(c => {
        if (on) c.textContent = '...';
    });
}

function showError() {
    const tbody = document.getElementById('historyBody');
    if (tbody) tbody.innerHTML =
        '<tr><td colspan="5" class="text-center" style="color:#e03333">Failed to load data. Check your connection.</td></tr>';
}

// ── INIT ───────────────────────────────────────────────────────────────────
onControlChange();

// ── Silent background refresh every 30s ───────────────────────────────────
setInterval(() => {
    onControlChange();
}, 30000);