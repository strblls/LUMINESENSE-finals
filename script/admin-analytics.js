// Reads `roomData` injected by admin-analytics.php via json_encode

// ── Chart init ────────────────────────────────────────────────
const usageChart = new Chart(document.getElementById('usageChart'), {
    type: 'bar',
    data: {
        labels: [],
        datasets: [{
            label: 'Estimated kWh',
            data: [],
            backgroundColor: 'rgba(116,47,211,0.95)',
            borderRadius: 14,
            maxBarThickness: 28
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: {
                ticks: { color: '#4d4d4d', font: { family: 'Poppins', size: 13 } },
                grid:  { display: false }
            },
            y: {
                beginAtZero: true,
                ticks: { color: '#4d4d4d', font: { family: 'Poppins', size: 13 }, precision: 1 },
                grid:  { color: 'rgba(47,0,79,0.08)' }
            }
        }
    }
});

// ── Main update (room or period changed) ──────────────────────
function onControlChange() {
    const room   = document.getElementById('roomSelect').value;
    const period = document.getElementById('periodSelect').value;

    // Guard: if no data exists yet for this room/period, show empty state
    if (!roomData[room] || !roomData[room][period]) {
        showEmptyState();
        return;
    }

    const d = roomData[room][period];

    // Room label
    document.getElementById('roomLabel').textContent = room;

    // Chart
    usageChart.data.labels              = d.labels.length ? d.labels : ['No data'];
    usageChart.data.datasets[0].data    = d.data.length   ? d.data   : [0];
    usageChart.update();

    // Usage card
    updateUsageCard(d.usageStats);

    // Sync breakdown period dropdown and refresh table
    document.getElementById('breakdownPeriodSelect').value = period;
    updateBreakdown();
}

// ── Usage card ────────────────────────────────────────────────
function updateUsageCard(stats) {
    const ps = stats.primary;
    const ss = stats.secondary;

    const primaryEl   = document.getElementById('usagePrimary');
    const secondaryEl = document.getElementById('usageSecondary');

    document.getElementById('usagePrimaryVal').textContent   = ps.pct;
    document.getElementById('usageSecondaryVal').textContent = ss.pct;
    document.getElementById('usagePrimaryDesc').innerHTML    = ps.desc;
    document.getElementById('usageSecondaryDesc').innerHTML  = ss.desc;

    primaryEl.className   = 'usage-number ' + ps.dir;
    primaryEl.querySelector('.usage-arrow').textContent   = ps.dir === 'up' ? '↗' : '↙';

    secondaryEl.className = 'usage-number ' + ss.dir;
    secondaryEl.querySelector('.usage-arrow').textContent = ss.dir === 'up' ? '↗' : '↙';
}

// ── Breakdown table + totals ──────────────────────────────────
function updateBreakdown() {
    const room   = document.getElementById('roomSelect').value;
    const period = document.getElementById('breakdownPeriodSelect').value;

    if (!roomData[room] || !roomData[room][period]) {
        document.getElementById('breakdownBody').innerHTML =
            '<tr><td colspan="3" style="text-align:center;color:#aaa;">No data available</td></tr>';
        document.getElementById('totalHours').textContent = '0.0 hrs';
        document.getElementById('totalKwh').textContent   = '0.00 kWh';
        return;
    }

    const rows  = roomData[room][period].breakdown;
    const tbody = document.getElementById('breakdownBody');
    tbody.innerHTML = '';

    let totalHours = 0;
    let totalKwh   = 0;

    if (!rows || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:#aaa;">No data available</td></tr>';
    } else {
        rows.forEach(r => {
            totalHours += r.hours;
            totalKwh   += r.kwh;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${r.room}</td>
                <td>${r.hours.toFixed(1)} hrs</td>
                <td>${r.kwh.toFixed(2)} kWh</td>
            `;
            tbody.appendChild(tr);
        });
    }

    document.getElementById('totalHours').textContent = totalHours.toFixed(1) + ' hrs';
    document.getElementById('totalKwh').textContent   = totalKwh.toFixed(2) + ' kWh';
}

// ── Empty state fallback ──────────────────────────────────────
function showEmptyState() {
    usageChart.data.labels           = ['No data'];
    usageChart.data.datasets[0].data = [0];
    usageChart.update();

    document.getElementById('usagePrimaryVal').textContent  = '—';
    document.getElementById('usageSecondaryVal').textContent = '—';
    document.getElementById('usagePrimaryDesc').innerHTML   = 'No data available.';
    document.getElementById('usageSecondaryDesc').innerHTML = 'No data available.';

    document.getElementById('breakdownBody').innerHTML =
        '<tr><td colspan="3" style="text-align:center;color:#aaa;">No data available</td></tr>';
    document.getElementById('totalHours').textContent = '0.0 hrs';
    document.getElementById('totalKwh').textContent   = '0.00 kWh';
}

// ── Export stubs ──────────────────────────────────────────────
function exportPDF() { alert('Export PDF is not configured yet.'); }
function exportCSV() { alert('Export CSV is not configured yet.'); }

// ── Init on page load ─────────────────────────────────────────
onControlChange();
