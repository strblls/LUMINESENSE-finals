const roomId = window.lumiRoomId || 0;

let rowState = { 1: false, 2: false, 3: false };
const rowBulbs = { 1: [0, 1, 2], 2: [3, 4, 5], 3: [6, 7, 8] };

function setBulb(index, on) {
    const img = document.getElementById('bulb' + index);
    if (img) img.src = on ? '../../images/bulb-on.png' : '../../images/bulb-off.png';
}

function toggleRow(row, on) {
    rowState[row] = on;
    rowBulbs[row].forEach(i => setBulb(i, on));
    syncAllLightsLabel();
    sendLightingUpdate(row);
}

function toggleAllLights() {
    const anyOff = Object.values(rowState).some(v => !v);
    const newState = anyOff;
    for (let row = 1; row <= 3; row++) {
        rowState[row] = newState;
        rowBulbs[row].forEach(i => setBulb(i, newState));
        const sw = document.getElementById('row' + row + 'sw');
        if (sw) sw.checked = newState;
    }
    syncAllLightsLabel();
    sendLightingUpdate('all');
}

function sendLightingUpdate(changedRow) {
    const anyOn = Object.values(rowState).some(v => v);
    const rowToSend = changedRow === 'all' ? 'all' : String(changedRow);
    const stateToSend = changedRow === 'all' ? (anyOn ? 'on' : 'off') : (rowState[changedRow] ? 'on' : 'off');

    const form = new FormData();
    form.append('classroom_id', roomId);
    form.append('row', rowToSend);
    form.append('state', stateToSend);
    form.append('triggered_by', 'admin_override');
    form.append('new_global_light_status', anyOn ? 'on' : 'off');

    fetch('../../api/lights.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(d => { if (d.success) updateDisplay(anyOn); })
        .catch(() => {});
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

function updateDisplay(isOn) {
    const dot = document.getElementById('lightDot');
    const status = document.getElementById('lightStatus');
    dot.className = 'light-dot ' + (isOn ? 'on' : 'off');
    status.className = 'light-status ' + (isOn ? 'on' : 'off');
    status.textContent = isOn ? 'ON' : 'OFF';
}

function fetchRoomData() {
    fetch('ajax-room-data.php?room_id=' + (roomId || 1))
        .then(r => r.json())
        .then(data => {
            const isOn = data.light_on;
            const hasSched = !!data.current_schedule;

            const badge = document.getElementById('statusBadge');
            badge.className = 'status-badge ' + (hasSched ? 'occupied' : 'vacant');
            badge.textContent = hasSched ? 'OCCUPIED' : 'VACANT';

            updateDisplay(isOn);

            const facSec = document.getElementById('facultySection');
            if (hasSched) {
                const s = data.current_schedule;
                facSec.innerHTML =
                    '<div class="row-card">' +
                        '<div class="faculty-avatar">' + s.faculty_name.charAt(0).toUpperCase() + '</div>' +
                        '<div><div class="faculty-name">' + s.faculty_name + '</div><div class="faculty-label">Faculty Member</div></div>' +
                    '</div>';
            } else {
                facSec.innerHTML = '<div class="row-card vacant">No faculty currently occupying this room.</div>';
            }

            const timeSec = document.getElementById('timeSection');
            if (hasSched) {
                const s = data.current_schedule;
                timeSec.innerHTML =
                    '<div class="row-card">' +
                        '<i class="bi bi-clock" style="font-size:18px;color:var(--secondary-color-3);flex-shrink:0;"></i>' +
                        '<div><div class="time-range">' + s.start_time + ' &ndash; ' + s.end_time + '</div><div class="time-label">Current session</div></div>' +
                    '</div>';
            } else {
                timeSec.innerHTML = '<div class="row-card vacant">No active time slot.</div>';
            }

            const rowStatuses = {
                1: data.row1_status === 'on',
                2: data.row2_status === 'on',
                3: data.row3_status === 'on'
            };
            for (let row = 1; row <= 3; row++) {
                rowState[row] = rowStatuses[row];
                rowBulbs[row].forEach(i => setBulb(i, rowStatuses[row]));
                const sw = document.getElementById('row' + row + 'sw');
                if (sw) sw.checked = rowStatuses[row];
            }
            syncAllLightsLabel();
        })
        .catch(() => {});
}

fetchRoomData();
setInterval(fetchRoomData, 5000);
