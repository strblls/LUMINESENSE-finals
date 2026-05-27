// admin-analytics.js
// Fetches real data from api/analytics.php and renders:
//   - Summary cards
//   - Daily energy bar chart
//   - Session trigger doughnut chart
//   - Occupancy heatmap
//   - Breakdown table with CSV/PDF export

const API_URL = '../../api/analytics.php';

// Days of week for heatmap (DAYOFWEEK: 1=Sun...7=Sat)
const DOW_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const TRIGGER_COLORS = {
    pir:      '#742fd3',
    schedule: '#27ae60',
    manual:   '#e67e22',
};

// ── Chart instances ────────────────────────────────────────────────────────
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

const triggerChart = new Chart(document.getElementById('triggerChart'), {
    type: 'doughnut',
    data: {
        labels: [],
        datasets: [{
            data: [],
            backgroundColor: Object.values(TRIGGER_COLORS),
            borderWidth: 0,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        cutout: '65%',
    }
});

// Stores last fetched data for export
let lastData = null;

// ── Main fetch + render ────────────────────────────────────────────────────
async function onControlChange() {
    const range = document.getElementById('periodSelect').value;
    const cid   = document.getElementById('roomSelect').value;

    // Update room label
    const roomSelect = document.getElementById('roomSelect');
    const roomName   = roomSelect.options[roomSelect.selectedIndex].text;
    document.getElementById('roomLabel').textContent = cid == 0 ? 'All Rooms' : roomName;

    setLoading(true);

    try {
        const res  = await fetch(`${API_URL}?range=${range}&classroom_id=${cid}`);
        const data = await res.json();

        if (!data.success) throw new Error(data.message ?? 'API error');

        lastData = data;
        renderSummaryCards(data.summary);
        renderEnergyChart(data.daily);
        renderTriggerChart(data.triggers);
        renderHeatmap(data.heatmap);
        renderBreakdown(data.per_room, data.summary);

    } catch (err) {
        console.error('[Analytics]', err);
        showError();
    } finally {
        setLoading(false);
    }
}

// ── Summary cards ──────────────────────────────────────────────────────────
function renderSummaryCards(s) {
    document.getElementById('sumEnergy').textContent   = (s.total_energy_kwh ?? 0).toFixed(4) + ' kWh';
    document.getElementById('sumCost').textContent     = '₱' + (s.est_cost_php ?? 0).toFixed(2);
    document.getElementById('sumMinutes').textContent  = ((s.total_minutes ?? 0) / 60).toFixed(1) + ' hrs';
    document.getElementById('sumSessions').textContent = s.total_sessions ?? 0;
    document.getElementById('sumVoltage').textContent  = (s.avg_voltage ?? 0).toFixed(1) + ' V';
}

// ── Energy bar chart ───────────────────────────────────────────────────────
function renderEnergyChart(daily) {
    if (!daily || daily.length === 0) {
        usageChart.data.labels           = ['No data'];
        usageChart.data.datasets[0].data = [0];
        usageChart.update();
        return;
    }

    usageChart.data.labels              = daily.map(d => d.label);
    usageChart.data.datasets[0].data    = daily.map(d => d.energy_wh);
    usageChart.data.datasets[0].label   = 'Energy (Wh)';
    usageChart.update();
}

// ── Trigger doughnut ───────────────────────────────────────────────────────
function renderTriggerChart(triggers) {
    if (!triggers || triggers.length === 0) {
        triggerChart.data.labels           = ['No data'];
        triggerChart.data.datasets[0].data = [1];
        triggerChart.data.datasets[0].backgroundColor = ['#eee'];
        triggerChart.update();
        document.getElementById('triggerLegend').innerHTML = '';
        return;
    }

    triggerChart.data.labels                      = triggers.map(t => t.trigger_source);
    triggerChart.data.datasets[0].data            = triggers.map(t => t.cnt);
    triggerChart.data.datasets[0].backgroundColor = triggers.map(
        t => TRIGGER_COLORS[t.trigger_source] ?? '#999'
    );
    triggerChart.update();

    // Custom legend
    const total  = triggers.reduce((s, t) => s + parseInt(t.cnt), 0);
    const legend = document.getElementById('triggerLegend');
    legend.innerHTML = triggers.map(t => `
        <div class="trigger-legend-item">
            <span class="trigger-dot" style="background:${TRIGGER_COLORS[t.trigger_source] ?? '#999'}"></span>
            <span class="trigger-source-label">${t.trigger_source}</span>
            <span class="trigger-pct">${Math.round(t.cnt / total * 100)}%</span>
        </div>
    `).join('');
}

// ── Heatmap ────────────────────────────────────────────────────────────────
function renderHeatmap(heatmap) {
    const grid = document.getElementById('heatmapGrid');
    grid.innerHTML = '';

    // Build lookup: [dow][hour] = count
    const map = {};
    for (let d = 1; d <= 7; d++) map[d] = {};
    (heatmap ?? []).forEach(h => { map[h.day][h.hour] = h.count; });

    // Find max for colour scaling
    const maxCount = Math.max(1, ...Object.values(map).flatMap(h => Object.values(h)));

    // Hour axis header row
    const hourHeaders = [''].concat(
        Array.from({ length: 24 }, (_, i) => {
            const ampm = i < 12 ? 'AM' : 'PM';
            const h    = i === 0 ? 12 : i > 12 ? i - 12 : i;
            return `${h}${ampm}`;
        })
    );

    const headerRow = document.createElement('div');
    headerRow.className = 'heatmap-row heatmap-header-row';
    hourHeaders.forEach(label => {
        const cell = document.createElement('div');
        cell.className = 'heatmap-cell heatmap-header-cell';
        cell.textContent = label;
        headerRow.appendChild(cell);
    });
    grid.appendChild(headerRow);

    // One row per day (dow 1=Sun to 7=Sat)
    for (let d = 1; d <= 7; d++) {
        const row = document.createElement('div');
        row.className = 'heatmap-row';

        // Day label
        const dayCell = document.createElement('div');
        dayCell.className = 'heatmap-cell heatmap-day-label';
        dayCell.textContent = DOW_LABELS[d - 1];
        row.appendChild(dayCell);

        for (let h = 0; h < 24; h++) {
            const count    = map[d][h] ?? 0;
            const intensity = count / maxCount;
            const cell = document.createElement('div');
            cell.className   = 'heatmap-cell heatmap-data-cell';
            cell.style.backgroundColor = count === 0
                ? 'rgba(116,47,211,0.05)'
                : `rgba(116,47,211,${0.15 + intensity * 0.85})`;
            cell.title = `${DOW_LABELS[d-1]} ${h}:00 — ${count} event${count !== 1 ? 's' : ''}`;
            if (count > 0) cell.textContent = count;
            row.appendChild(cell);
        }
        grid.appendChild(row);
    }
}

// ── Breakdown table ────────────────────────────────────────────────────────
function renderBreakdown(perRoom, summary) {
    const tbody = document.getElementById('breakdownBody');
    tbody.innerHTML = '';

    if (!perRoom || perRoom.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No session data yet</td></tr>';
    } else {
        perRoom.forEach(r => {
            const hrs = ((r.minutes ?? 0) / 60).toFixed(1);
            const tr  = document.createElement('tr');
            tr.innerHTML = `
                <td>${r.room_name}</td>
                <td class="text-center">${r.sessions ?? 0}</td>
                <td class="text-center">${hrs} hrs</td>
                <td class="text-center">${(r.energy_wh ?? 0).toFixed(2)} Wh</td>
            `;
            tbody.appendChild(tr);
        });
    }

    const totalMins = summary?.total_minutes ?? 0;
    document.getElementById('totalHours').textContent = (totalMins / 60).toFixed(1) + ' hrs';
    document.getElementById('totalKwh').textContent   = (summary?.total_energy_kwh ?? 0).toFixed(4) + ' kWh';
    document.getElementById('totalCost').textContent  = '₱' + (summary?.est_cost_php ?? 0).toFixed(2);
}

// ── CSV Export ─────────────────────────────────────────────────────────────
function exportCSV() {
    if (!lastData) return;

    const range  = document.getElementById('periodSelect').value;
    const rows   = [['Date', 'Energy (Wh)', 'Sessions', 'Occupied (mins)']];

    (lastData.daily ?? []).forEach(d => {
        rows.push([d.date, d.energy_wh, d.sessions, d.minutes]);
    });

    const csv  = rows.map(r => r.join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `luminesense_report_${range}days.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

// ── PDF Export (print-based) ───────────────────────────────────────────────
function exportPDF() {
    window.print();
}

// ── Loading / error states ─────────────────────────────────────────────────
function setLoading(on) {
    const cards = document.querySelectorAll('.summary-val');
    if (on) cards.forEach(c => c.textContent = '...');
}

function showError() {
    document.getElementById('breakdownBody').innerHTML =
        '<tr><td colspan="4" class="text-center" style="color:#e03333">Failed to load data. Check your connection.</td></tr>';
}

// ── Init ───────────────────────────────────────────────────────────────────
onControlChange();