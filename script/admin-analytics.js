// admin-analytics.js
// Fetches real data from api/analytics.php and renders:
//   - Summary cards (with kW)
//   - Daily energy bar chart
//   - Session trigger doughnut chart
//   - Occupancy heatmap
//   - Breakdown table
//   - Per-session detail table (NEW)
//   - CSV / PDF export

const API_URL = '../../api/analytics.php';

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

let lastData = null;

// ── Main fetch + render ────────────────────────────────────────────────────
async function onControlChange() {
    const range = document.getElementById('periodSelect').value;
    const cid   = document.getElementById('roomSelect').value;

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
        renderSessionsTable(data.sessions ?? []);

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
    document.getElementById('sumMinutes').textContent  = ((s.total_minutes ?? 0) / 60).toFixed(1) + ' hrs';
    document.getElementById('sumSessions').textContent = s.total_sessions ?? 0;
    document.getElementById('sumVoltage').textContent  = (s.avg_voltage ?? 0).toFixed(1) + ' V';

    // kW card if it exists
    const kwEl = document.getElementById('sumPeakKw');
    if (kwEl) kwEl.textContent = (s.peak_power_kw ?? 0).toFixed(3) + ' kW';
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

    const map = {};
    for (let d = 1; d <= 7; d++) map[d] = {};
    (heatmap ?? []).forEach(h => { map[h.day][h.hour] = h.count; });

    const maxCount = Math.max(1, ...Object.values(map).flatMap(h => Object.values(h)));

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

    for (let d = 1; d <= 7; d++) {
        const row = document.createElement('div');
        row.className = 'heatmap-row';

        const dayCell = document.createElement('div');
        dayCell.className = 'heatmap-cell heatmap-day-label';
        dayCell.textContent = DOW_LABELS[d - 1];
        row.appendChild(dayCell);

        for (let h = 0; h < 24; h++) {
            const count     = map[d][h] ?? 0;
            const intensity = count / maxCount;
            const cell = document.createElement('div');
            cell.className = 'heatmap-cell heatmap-data-cell';
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
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No session data yet</td></tr>';
    } else {
        perRoom.forEach(r => {
            const hrs = ((r.minutes ?? 0) / 60).toFixed(1);
            const kwh = (r.energy_kwh ?? ((r.energy_wh ?? 0) / 1000)).toFixed(4);
            const tr  = document.createElement('tr');
            tr.innerHTML = `
                <td>${r.room_name}</td>
                <td class="text-center">${r.sessions ?? 0}</td>
                <td class="text-center">${hrs} hrs</td>
                <td class="text-center">${(r.energy_wh ?? 0).toFixed(2)} Wh</td>
                <td class="text-center">${kwh} kWh</td>
                <td class="text-center">${(r.avg_voltage ?? 0).toFixed(1)} V</td>
            `;
            tbody.appendChild(tr);
        });
    }

    const totalMins = summary?.total_minutes ?? 0;
    document.getElementById('totalHours').textContent = (totalMins / 60).toFixed(1) + ' hrs';
    document.getElementById('totalKwh').textContent   = (summary?.total_energy_kwh ?? 0).toFixed(4) + ' kWh';
    document.getElementById('totalCost').textContent  = '₱' + (summary?.est_cost_php ?? 0).toFixed(2);
}

// ── Per-session detail table (NEW) ─────────────────────────────────────────
function renderSessionsTable(sessions) {
    const container = document.getElementById('sessionsTableContainer');
    if (!container) return;

    if (!sessions || sessions.length === 0) {
        container.innerHTML = '<p class="text-center text-muted" style="padding:24px;">No completed sessions in this period.</p>';
        return;
    }

    const rows = sessions.map(s => {
        const triggerBadge = {
            pir:      '<span class="badge-trigger badge-pir">PIR</span>',
            schedule: '<span class="badge-trigger badge-schedule">Schedule</span>',
            manual:   '<span class="badge-trigger badge-manual">Manual</span>',
        }[s.trigger_source] ?? `<span class="badge-trigger">${s.trigger_source}</span>`;

        const startFmt = s.start_time ? s.start_time.slice(11, 16) : '—';
        const endFmt   = s.end_time   ? s.end_time.slice(11, 16)   : '—';

        return `
        <tr>
            <td>${s.session_date ?? '—'}</td>
            <td>${s.room_name ?? '—'}</td>
            <td>${startFmt} – ${endFmt}</td>
            <td class="text-center">${s.duration_mins ?? 0} min</td>
            <td class="text-center">${triggerBadge}</td>
            <td class="text-center">${(s.avg_voltage ?? 0).toFixed(1)} V</td>
            <td class="text-center">${(s.avg_current ?? 0).toFixed(3)} A</td>
            <td class="text-center">${(s.peak_power_kw ?? 0).toFixed(3)} kW</td>
            <td class="text-center">${(s.total_energy_kwh ?? 0).toFixed(4)} kWh</td>
        </tr>`;
    }).join('');

    container.innerHTML = `
        <div style="overflow-x:auto;">
        <table class="breakdown-table sessions-detail-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Room</th>
                    <th>Time</th>
                    <th>Duration</th>
                    <th>Trigger</th>
                    <th>Avg Voltage</th>
                    <th>Avg Current</th>
                    <th>Peak kW</th>
                    <th>Energy (kWh)</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
        </div>`;
}

// ── CSV Export ─────────────────────────────────────────────────────────────
function exportCSV() {
    if (!lastData) return;

    const range = document.getElementById('periodSelect').value;

    // Sessions detail export
    const headers = ['Date','Room','Start','End','Duration (min)','Trigger',
                     'Avg Voltage (V)','Avg Current (A)','Peak Power (kW)',
                     'Energy (kWh)','Est Cost (PHP)'];

    const rows = (lastData.sessions ?? []).map(s => [
        s.session_date,
        s.room_name,
        s.start_time ? s.start_time.slice(0,19) : '',
        s.end_time   ? s.end_time.slice(0,19)   : '',
        s.duration_mins ?? 0,
        s.trigger_source ?? '',
        (s.avg_voltage   ?? 0).toFixed(1),
        (s.avg_current   ?? 0).toFixed(3),
        (s.peak_power_kw ?? 0).toFixed(3),
        (s.total_energy_kwh ?? 0).toFixed(4),
        (s.est_cost_php  ?? 0).toFixed(2),
    ]);

    const csv  = [headers, ...rows].map(r => r.join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `luminesense_sessions_${range}days.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

// ── PDF Export ─────────────────────────────────────────────────────────────
function exportPDF() {
    window.print();
}

// ── Loading / error ────────────────────────────────────────────────────────
function setLoading(on) {
    document.querySelectorAll('.summary-val').forEach(c => {
        if (on) c.textContent = '...';
    });
}

function showError() {
    document.getElementById('breakdownBody').innerHTML =
        '<tr><td colspan="6" class="text-center" style="color:#e03333">Failed to load data. Check your connection.</td></tr>';
    const sc = document.getElementById('sessionsTableContainer');
    if (sc) sc.innerHTML = '<p class="text-center" style="color:#e03333">Failed to load sessions.</p>';
}

// ── Init ───────────────────────────────────────────────────────────────────
onControlChange();