<?php
$phpRoot = realpath(__DIR__ . '/../../php');
require_once $phpRoot . '/session_guard.php';
if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['faculty_logged_in'])) {
    header('Location: ' . ($_SESSION['role'] === 'faculty' ? '../faculty-login.php' : '../admin-login.php'));
    exit;
}
require_once $phpRoot . '/db_connect.php';
date_default_timezone_set('Asia/Manila');

$room_id = (int)($_GET['room_id'] ?? 0);

if ($room_id) {
    $row = $conn->query("
        SELECT id, room_name, light_status, row1_status, row2_status, row3_status
        FROM classrooms WHERE id = $room_id LIMIT 1
    ")->fetch_assoc();
    if ($row) {
        $room_name = $row['room_name'];
    } else {
        $room_name = 'Unknown Room';
    }
} else {
    $room_name = 'Demo Room';
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($room_name) ?> – Light View</title>
<link rel="icon" href="../../images/icon.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root {
    --primary-color: #f9edfa;
    --secondary-color-1: #2f004f;
    --secondary-color-2: #58078f;
    --secondary-color-3: #790faf;
    --secondary-color-4: #9b00e9;
    --muted: #9f9f9f;
    --muted-dark: #6c6c6c;
    --font-primary: 'Poppins', sans-serif;
}
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: var(--font-primary);
    background: var(--secondary-color-1);
    min-height: 100vh;
    display:flex; align-items:center; justify-content:center;
    padding:24px;
}
.card {
    background: var(--primary-color);
    border-radius:15px;
    padding:28px;
    max-width:420px;
    width:100%;
    box-shadow:0 6px 28px rgba(47,0,79,.16);
}
.room-name {
    font-size:20px;
    font-weight:700;
    color:var(--secondary-color-1);
    margin-bottom:2px;
}
.room-sub {
    font-size:13px;
    color:var(--muted);
    margin-bottom:20px;
}
.status-badge {
    display:inline-block;
    padding:4px 14px;
    border-radius:20px;
    font-size:11px;
    font-weight:700;
    letter-spacing:.04em;
    margin-bottom:18px;
}
.status-badge.occupied { background:#ffe4ec; color:#c0004e; }
.status-badge.vacant  { background:#d6fbe9; color:#0a7a45; }
.section {
    margin-bottom:18px;
}
.section-label {
    font-size:11px;
    font-weight:700;
    letter-spacing:.10em;
    text-transform:uppercase;
    color:var(--secondary-color-3);
    margin-bottom:8px;
}
.row-card {
    display:flex;
    align-items:center;
    gap:12px;
    padding:12px 14px;
    background:#fff;
    border-radius:10px;
    border:1px solid #f0eaf8;
}
.light-dot {
    width:12px; height:12px;
    border-radius:50%;
    flex-shrink:0;
}
.light-dot.on  { background:#27ae60; box-shadow:0 0 6px #27ae60; }
.light-dot.off { background:#ccc; }
.light-label {
    font-size:14px;
    font-weight:600;
    color:var(--secondary-color-1);
}
.light-status {
    margin-left:auto;
    font-size:12px;
    font-weight:700;
    padding:3px 10px;
    border-radius:20px;
}
.light-status.on  { background:#d6fbe9; color:#0a7a45; }
.light-status.off { background:#f0f0f0; color:#888; }
.faculty-avatar {
    width:38px; height:38px;
    border-radius:50%;
    background:var(--secondary-color-3);
    color:#fff;
    display:flex; align-items:center; justify-content:center;
    font-weight:700;
    font-size:13px;
    flex-shrink:0;
}
.faculty-name {
    font-size:14px;
    font-weight:600;
    color:var(--secondary-color-1);
}
.faculty-label {
    font-size:12px;
    color:var(--muted-dark);
}
.time-range {
    font-size:14px;
    font-weight:600;
    color:var(--secondary-color-1);
}
.time-label {
    font-size:12px;
    color:var(--muted-dark);
}
.row-card.vacant {
    padding:12px 14px;
    background:#fff;
    border-radius:10px;
    font-size:13px;
    color:var(--muted);
    text-align:center;
    border:1px solid #f0eaf8;
}
.footer {
    margin-top:20px;
    padding-top:14px;
    border-top:1px solid #f0eaf8;
    font-size:11px;
    color:var(--muted);
    text-align:center;
}
/* Admin Override Panel */
.admin-override-panel {
    background: var(--secondary-color-1);
    border-radius: 16px;
    padding: 20px;
    border: 1px solid rgba(255, 255, 255, .08);
    box-shadow: 0 0 0 1px rgba(255, 255, 255, .05), 0 6px 28px rgba(0, 0, 0, .25);
}
.override-panel-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .10em;
    text-transform: uppercase;
    color: #c9a8f5;
}
.override-live-badge {
    margin-left: auto;
    background: #16a34a;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
    letter-spacing: .08em;
    animation: pulse-green 2s infinite;
}
@keyframes pulse-green {
    0%, 100% { box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.5); }
    50% { box-shadow: 0 0 0 5px rgba(22, 163, 74, 0); }
}
.override-master-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
}
.bulb-preview-grid {
    display: grid;
    grid-template-columns: repeat(3, 34px);
    gap: 6px;
}
.bulb-img {
    width: 34px;
    height: 34px;
    object-fit: contain;
    transition: filter .2s;
}
.override-master-right {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}
.override-master-btn {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    border: 2.5px solid;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    cursor: pointer;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .04em;
    transition: transform .15s, box-shadow .15s;
}
.override-master-btn i { font-size: 20px; }
.override-master-btn.off {
    background: rgba(255, 255, 255, .08);
    border-color: rgba(255, 255, 255, .2);
    color: rgba(255, 255, 255, .45);
}
.override-master-btn.on {
    background: rgba(250, 204, 21, .12);
    border-color: #facc15;
    color: #facc15;
    box-shadow: 0 0 18px rgba(250, 204, 21, 0.25);
}
.override-master-btn:hover { transform: scale(1.08); }
.override-hint {
    font-size: 11px;
    color: rgba(255, 255, 255, .35);
}
.override-rows {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 12px 0;
    border-top: 1px solid rgba(255, 255, 255, .08);
    border-bottom: 1px solid rgba(255, 255, 255, .08);
    margin-bottom: 12px;
}
.override-row-item {
    display: flex;
    align-items: center;
    gap: 12px;
}
.override-row-label {
    font-size: 13px;
    font-weight: 600;
    color: rgba(255, 255, 255, .55);
    width: 44px;
}
.override-switch { display: none; }
.override-switch-label {
    display: inline-block;
    width: 44px;
    height: 24px;
    background: rgba(255, 255, 255, .12);
    border-radius: 24px;
    position: relative;
    cursor: pointer;
    transition: background .2s;
}
.override-switch-label::after {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: rgba(255, 255, 255, .35);
    transition: transform .2s, background .2s;
}
.override-switch:checked+.override-switch-label {
    background: rgba(167, 139, 250, .4);
}
.override-switch:checked+.override-switch-label::after {
    background: #c9a8f5;
    transform: translateX(20px);
}
.override-row-status {
    font-size: 12px;
    font-weight: 700;
    color: rgba(255, 255, 255, .4);
    width: 32px;
    transition: color .2s;
}
.override-row-status.is-on { color: #c9a8f5; }
.override-footer-note {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: rgba(255, 255, 255, .35);
}
</style>
</head>
<body>
<div class="card">
    <div class="room-name"><?= htmlspecialchars($room_name) ?></div>
    <div class="room-sub">Lighting &amp; Occupancy View</div>

    <div class="status-badge" id="statusBadge">LOADING</div>

    <div class="section">
        <div class="section-label">Lighting</div>
        <div class="row-card" id="lightRow">
            <span class="light-dot off" id="lightDot"></span>
            <span class="light-label">Room Lights</span>
            <span class="light-status off" id="lightStatus">OFF</span>
        </div>
    </div>

    <div class="section">
        <div class="section-label">Faculty</div>
        <div id="facultySection"><div class="row-card vacant">Loading&hellip;</div></div>
    </div>

    <div class="section">
        <div class="section-label">Time Slot</div>
        <div id="timeSection"><div class="row-card vacant">Loading&hellip;</div></div>
    </div>

    <!-- Admin Light Override Panel -->
    <div class="admin-override-panel mt-3">
        <div class="override-panel-header">
            <i class="bi bi-shield-lock-fill"></i>
            <span>Admin Override</span>
            <span class="override-live-badge" id="overrideLiveBadge">LIVE</span>
        </div>

        <div class="override-master-row">
            <div class="override-master-left">
                <div class="bulb-preview-grid">
                    <img src="../../images/bulb-off.png" id="bulb0" class="bulb-img">
                    <img src="../../images/bulb-off.png" id="bulb1" class="bulb-img">
                    <img src="../../images/bulb-off.png" id="bulb2" class="bulb-img">
                    <img src="../../images/bulb-off.png" id="bulb3" class="bulb-img">
                    <img src="../../images/bulb-off.png" id="bulb4" class="bulb-img">
                    <img src="../../images/bulb-off.png" id="bulb5" class="bulb-img">
                    <img src="../../images/bulb-off.png" id="bulb6" class="bulb-img">
                    <img src="../../images/bulb-off.png" id="bulb7" class="bulb-img">
                    <img src="../../images/bulb-off.png" id="bulb8" class="bulb-img">
                </div>
            </div>
            <div class="override-master-right">
                <button class="override-master-btn off" id="allLightsBtn" onclick="toggleAllLights()">
                    <i class="bi bi-power"></i>
                    <span id="allLightsLabel">OFF</span>
                </button>
                <div class="override-hint">All rows</div>
            </div>
        </div>

        <div class="override-rows">
            <div class="override-row-item">
                <span class="override-row-label">Row 1</span>
                <div class="override-row-toggle">
                    <input class="override-switch" type="checkbox" role="switch" id="row1sw" onchange="toggleRow(1, this.checked)">
                    <label class="override-switch-label" for="row1sw"></label>
                </div>
                <span class="override-row-status" id="row1status">OFF</span>
            </div>
            <div class="override-row-item">
                <span class="override-row-label">Row 2</span>
                <div class="override-row-toggle">
                    <input class="override-switch" type="checkbox" role="switch" id="row2sw" onchange="toggleRow(2, this.checked)">
                    <label class="override-switch-label" for="row2sw"></label>
                </div>
                <span class="override-row-status" id="row2status">OFF</span>
            </div>
            <div class="override-row-item">
                <span class="override-row-label">Row 3</span>
                <div class="override-row-toggle">
                    <input class="override-switch" type="checkbox" role="switch" id="row3sw" onchange="toggleRow(3, this.checked)">
                    <label class="override-switch-label" for="row3sw"></label>
                </div>
                <span class="override-row-status" id="row3status">OFF</span>
            </div>
        </div>

        <div class="override-footer-note">
            <i class="bi bi-info-circle"></i>
            Changes apply immediately and are logged.
        </div>
    </div>

    <div class="footer">LumineSense &mdash; Real-time Room Monitor</div>
</div>

<script>
const roomId = <?= $room_id ?: 0 ?>;

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

            // Sync override panel with server state
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
</script>
</body>
</html>
