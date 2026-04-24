<?php
require_once dirname(__DIR__, 2) . '/session_guard.php';
check_admin();
require_once dirname(__DIR__, 2) . '/db_connect.php';

$admin_name = htmlspecialchars($_SESSION['admin_name']);

// Summary counts
$total_rooms   = $conn->query("SELECT COUNT(*) AS c FROM classrooms")->fetch_assoc()['c'];
$lights_on     = $conn->query("SELECT COUNT(*) AS c FROM lighting_logs l WHERE l.id IN (SELECT MAX(id) FROM lighting_logs GROUP BY classroom_id) AND l.event_type='on'")->fetch_assoc()['c'];
$pending       = $conn->query("SELECT COUNT(*) AS c FROM faculty WHERE is_verified=0")->fetch_assoc()['c'];
$alerts_today  = $conn->query("SELECT COUNT(*) AS c FROM lighting_logs WHERE event_type='security_alert' AND DATE(event_time)=CURDATE()")->fetch_assoc()['c'];

// Recent 6 logs
$logs = [];
$r = $conn->query("SELECT l.event_type, l.triggered_by, l.event_time, c.room_name FROM lighting_logs l JOIN classrooms c ON c.id=l.classroom_id ORDER BY l.event_time DESC LIMIT 6");
while ($row = $r->fetch_assoc()) $logs[] = $row;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard – LumineSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark px-4">
    <span class="navbar-brand fw-bold">LumineSense Admin</span>
    <div class="d-flex gap-2 align-items-center">
        <span class="text-white small">👤 <?= $admin_name ?></span>
        <a onclick="dissolve('php/logout.php')" class="btn btn-sm btn-outline-light">Logout</a>
    </div>
</nav>

<!-- Nav tabs -->
<div class="container-fluid px-4 pt-3">
    <ul class="nav nav-tabs" id="adminTabs">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#overview">Overview</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#accounts">
            Faculty Accounts <?= $pending > 0 ? "<span class='badge bg-warning text-dark'>$pending</span>" : '' ?>
        </a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#classrooms">Classrooms</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#schedule">Timetable</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#logs">Logs</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#analytics">Analytics</a></li>
    </ul>
</div>

<div class="container-fluid px-4 py-3">
<div class="tab-content">

<!-- ══ OVERVIEW ════════════════════════════════════════════════ -->
<div class="tab-pane fade show active" id="overview">
    <div class="row g-3 mb-4 mt-1">
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-2 fw-bold text-primary"><?= $total_rooms ?></div><div class="small text-muted">Classrooms</div>
        </div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-2 fw-bold text-warning"><?= $lights_on ?></div><div class="small text-muted">Lights ON</div>
        </div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-2 fw-bold text-orange" style="color:#e65100"><?= $pending ?></div><div class="small text-muted">Pending Accounts</div>
        </div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-2 fw-bold text-danger"><?= $alerts_today ?></div><div class="small text-muted">Alerts Today</div>
        </div></div>
    </div>
    <h6 class="fw-bold">Recent Activity</h6>
    <table class="table table-sm table-bordered bg-white shadow-sm">
        <thead class="table-dark"><tr><th>Room</th><th>Event</th><th>By</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
        <tr>
            <td><?= htmlspecialchars($l['room_name']) ?></td>
            <td><span class="badge bg-secondary"><?= $l['event_type'] ?></span></td>
            <td><?= htmlspecialchars($l['triggered_by']) ?></td>
            <td class="text-muted small"><?= date('M d h:i A', strtotime($l['event_time'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?><tr><td colspan="4" class="text-muted text-center">No activity yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ══ ACCOUNTS ════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="accounts">
    <div class="d-flex justify-content-between align-items-center my-3">
        <h6 class="fw-bold mb-0">Faculty Accounts</h6>
        <div class="btn-group btn-group-sm">
            <button class="btn btn-outline-secondary active" onclick="loadAccounts('all',this)">All</button>
            <button class="btn btn-outline-warning" onclick="loadAccounts('pending',this)">Pending</button>
            <button class="btn btn-outline-success" onclick="loadAccounts('verified',this)">Verified</button>
        </div>
    </div>
    <div id="accounts-table">Loading...</div>
</div>

<!-- ══ CLASSROOMS ═══════════════════════════════════════════════ -->
<div class="tab-pane fade" id="classrooms">
    <div class="d-flex justify-content-between align-items-center my-3">
        <h6 class="fw-bold mb-0">Classrooms</h6>
        <button class="btn btn-sm btn-dark" onclick="document.getElementById('add-room-form').classList.toggle('d-none')">+ Add Classroom</button>
    </div>
    <div id="add-room-form" class="card p-3 mb-3 d-none">
        <div class="row g-2">
            <div class="col-md-4"><input class="form-control form-control-sm" id="new-room-name" placeholder="Room name (e.g. Room 101)" required></div>
            <div class="col-md-3">
                <select class="form-select form-select-sm" id="new-room-size">
                    <option value="small">Small (7m×7m)</option>
                    <option value="medium" selected>Medium (7m×9m)</option>
                    <option value="large">Large (9m×10m+)</option>
                </select>
            </div>
            <div class="col-md-4"><input class="form-control form-control-sm" id="new-room-desc" placeholder="Description (optional)"></div>
            <div class="col-md-1"><button class="btn btn-sm btn-success w-100" onclick="addClassroom()">Add</button></div>
        </div>
    </div>
    <div id="classrooms-table">Loading...</div>
</div>

<!-- ══ SCHEDULE ═════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="schedule">
    <div class="d-flex justify-content-between align-items-center my-3">
        <h6 class="fw-bold mb-0">Timetable</h6>
        <button class="btn btn-sm btn-dark" onclick="document.getElementById('add-sched-form').classList.toggle('d-none')">+ Add Slot</button>
    </div>
    <div id="add-sched-form" class="card p-3 mb-3 d-none">
        <div class="row g-2">
            <div class="col-md-3"><select class="form-select form-select-sm" id="sched-room"><option value="">Select room...</option></select></div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" id="sched-day">
                    <?php foreach(['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $d): ?>
                    <option><?= $d ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><input type="time" class="form-control form-control-sm" id="sched-start"></div>
            <div class="col-md-2"><input type="time" class="form-control form-control-sm" id="sched-end"></div>
            <div class="col-md-1"><button class="btn btn-sm btn-success w-100" onclick="addSchedule()">Add</button></div>
        </div>
    </div>
    <div id="schedule-table">Loading...</div>
</div>

<!-- ══ LOGS ═════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="logs">
    <div class="d-flex gap-2 align-items-center my-3 flex-wrap">
        <h6 class="fw-bold mb-0 me-2">Activity Logs</h6>
        <select class="form-select form-select-sm" style="width:160px" id="log-filter-room"><option value="">All Rooms</option></select>
        <select class="form-select form-select-sm" style="width:160px" id="log-filter-type">
            <option value="">All Types</option>
            <option>on</option><option>off</option><option>gesture</option><option>schedule</option><option>security_alert</option>
        </select>
        <input type="date" class="form-control form-control-sm" style="width:160px" id="log-filter-date">
        <button class="btn btn-sm btn-dark" onclick="loadLogs()">Filter</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="clearLogFilters()">Clear</button>
    </div>
    <div id="logs-table">Loading...</div>
</div>

<!-- ══ ANALYTICS ════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="analytics">
    <div class="d-flex gap-2 align-items-center my-3">
        <h6 class="fw-bold mb-0 me-2">Energy Analytics</h6>
        <select class="form-select form-select-sm" style="width:140px" id="analytics-range" onchange="loadAnalytics()">
            <option value="7" selected>Last 7 days</option>
            <option value="14">Last 14 days</option>
            <option value="30">Last 30 days</option>
        </select>
    </div>
    <div id="analytics-table">Loading...</div>
</div>

</div><!-- /tab-content -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script>
const API = '../../api/';

// ── helpers ────────────────────────────────────────────────────
function post(url, data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k,v));
    return fetch(url, {method:'POST', body:fd}).then(r=>r.json());
}
function get(url) { return fetch(url).then(r=>r.json()); }
function msg(text, ok=true) {
    const el = document.createElement('div');
    el.className = `alert alert-${ok?'success':'danger'} alert-dismissible fade show`;
    el.innerHTML = `${text}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
    document.querySelector('.container-fluid.px-4.py-3').prepend(el);
    setTimeout(()=>el.remove(), 4000);
}

// ── ACCOUNTS ──────────────────────────────────────────────────
let cachedRooms = [];

function loadAccounts(filter='all', btn=null) {
    if(btn){ document.querySelectorAll('#accounts .btn-group .btn').forEach(b=>b.classList.remove('active')); btn.classList.add('active'); }
    document.getElementById('accounts-table').innerHTML = 'Loading...';
    get(API+'accounts.php?filter='+filter).then(res => {
        if (!res.success) { document.getElementById('accounts-table').innerHTML = '<p class="text-danger">'+res.message+'</p>'; return; }
        if (!res.data.length) { document.getElementById('accounts-table').innerHTML = '<p class="text-muted">No accounts found.</p>'; return; }
        let html = `<table class="table table-sm table-bordered bg-white shadow-sm">
            <thead class="table-dark"><tr><th>Name</th><th>Email</th><th>Registered</th><th>Status</th><th>Approved By</th><th>Actions</th></tr></thead><tbody>`;
        res.data.forEach(f => {
            const status = f.is_verified=='1'
                ? '<span class="badge bg-success">Verified</span>'
                : '<span class="badge bg-warning text-dark">Pending</span>';
            const approvedBy = f.approved_by_name ? f.approved_by_name : '—';
            const actions = f.is_verified=='0'
                ? `<button class="btn btn-xs btn-success btn-sm me-1" onclick="accountAction('approve',${f.id})">Approve</button>
                   <button class="btn btn-xs btn-danger btn-sm" onclick="accountAction('reject',${f.id})">Reject</button>`
                : `<button class="btn btn-xs btn-warning btn-sm me-1" onclick="accountAction('revoke',${f.id})">Revoke</button>
                   <button class="btn btn-xs btn-danger btn-sm" onclick="accountAction('delete',${f.id})">Delete</button>`;
            html += `<tr>
                <td><strong>${f.last_name}</strong>, ${f.first_name} ${f.middle_initial}</td>
                <td>${f.email}</td>
                <td class="small text-muted">${f.created_at.substring(0,10)}</td>
                <td>${status}</td>
                <td class="small">${approvedBy}</td>
                <td>${actions}</td></tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('accounts-table').innerHTML = html;
    });
}

function accountAction(action, id) {
    const labels = {approve:'Approve',reject:'Reject',revoke:'Revoke',delete:'Delete'};
    if (!confirm(`${labels[action]} this faculty account?`)) return;
    post(API+'accounts.php', {action, faculty_id:id}).then(res => {
        msg(res.message, res.success);
        loadAccounts();
    });
}

// ── CLASSROOMS ────────────────────────────────────────────────
function loadClassrooms() {
    document.getElementById('classrooms-table').innerHTML = 'Loading...';
    get(API+'classrooms.php').then(res => {
        cachedRooms = res.data || [];
        // Populate schedule room dropdown
        const sel = document.getElementById('sched-room');
        sel.innerHTML = '<option value="">Select room...</option>';
        cachedRooms.forEach(c => sel.innerHTML += `<option value="${c.id}">${c.room_name}</option>`);
        // Populate log filter
        const lsel = document.getElementById('log-filter-room');
        lsel.innerHTML = '<option value="">All Rooms</option>';
        cachedRooms.forEach(c => lsel.innerHTML += `<option value="${c.id}">${c.room_name}</option>`);

        if (!res.data.length) { document.getElementById('classrooms-table').innerHTML = '<p class="text-muted">No classrooms yet.</p>'; return; }
        const sizes = {small:'Small (7m×7m)',medium:'Medium (7m×9m)',large:'Large (9m×10m+)'};
        let html = `<table class="table table-sm table-bordered bg-white shadow-sm">
            <thead class="table-dark"><tr><th>Room</th><th>Size</th><th>Description</th><th>Schedules</th><th>Action</th></tr></thead><tbody>`;
        res.data.forEach(c => {
            html += `<tr>
                <td><strong>${c.room_name}</strong></td>
                <td><span class="badge bg-info text-dark">${sizes[c.room_size]||c.room_size}</span></td>
                <td class="small text-muted">${c.description||'—'}</td>
                <td>${c.schedule_count}</td>
                <td><button class="btn btn-danger btn-sm" onclick="deleteClassroom(${c.id},'${c.room_name}')"><i class="bi bi-trash"></i></button></td></tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('classrooms-table').innerHTML = html;
    });
}

function addClassroom() {
    const name = document.getElementById('new-room-name').value.trim();
    const size = document.getElementById('new-room-size').value;
    const desc = document.getElementById('new-room-desc').value.trim();
    if (!name) { alert('Room name required.'); return; }
    post(API+'classrooms.php', {action:'add', room_name:name, room_size:size, description:desc}).then(res => {
        msg(res.message, res.success);
        if (res.success) { document.getElementById('new-room-name').value=''; loadClassrooms(); }
    });
}

function deleteClassroom(id, name) {
    if (!confirm(`Delete "${name}"? This also removes its schedules and logs.`)) return;
    post(API+'classrooms.php', {action:'delete', classroom_id:id}).then(res => {
        msg(res.message, res.success);
        if (res.success) loadClassrooms();
    });
}

// ── SCHEDULE ──────────────────────────────────────────────────
function loadSchedule() {
    document.getElementById('schedule-table').innerHTML = 'Loading...';
    get(API+'schedules.php').then(res => {
        if (!res.data.length) { document.getElementById('schedule-table').innerHTML = '<p class="text-muted">No schedules yet.</p>'; return; }
        let html = `<table class="table table-sm table-bordered bg-white shadow-sm">
            <thead class="table-dark"><tr><th>Room</th><th>Day</th><th>Start</th><th>End</th><th>Action</th></tr></thead><tbody>`;
        res.data.forEach(s => {
            html += `<tr><td>${s.room_name}</td><td>${s.day_of_week}</td>
                <td>${fmtTime(s.start_time)}</td><td>${fmtTime(s.end_time)}</td>
                <td><button class="btn btn-danger btn-sm" onclick="deleteSchedule(${s.id})"><i class="bi bi-trash"></i></button></td></tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('schedule-table').innerHTML = html;
    });
}

function addSchedule() {
    const cid   = document.getElementById('sched-room').value;
    const day   = document.getElementById('sched-day').value;
    const start = document.getElementById('sched-start').value;
    const end   = document.getElementById('sched-end').value;
    if (!cid || !start || !end) { alert('Fill in all fields.'); return; }
    post(API+'schedules.php', {action:'add', classroom_id:cid, day_of_week:day, start_time:start, end_time:end}).then(res => {
        msg(res.message, res.success);
        if (res.success) loadSchedule();
    });
}

function deleteSchedule(id) {
    if (!confirm('Remove this schedule slot?')) return;
    post(API+'schedules.php', {action:'delete', schedule_id:id}).then(res => {
        msg(res.message, res.success);
        if (res.success) loadSchedule();
    });
}

// ── LOGS ──────────────────────────────────────────────────────
function loadLogs() {
    const room = document.getElementById('log-filter-room').value;
    const type = document.getElementById('log-filter-type').value;
    const date = document.getElementById('log-filter-date').value;
    let url = API+'logs.php?limit=200';
    if (room) url += '&room='+room;
    if (type) url += '&type='+type;
    if (date) url += '&date='+date;
    document.getElementById('logs-table').innerHTML = 'Loading...';
    get(url).then(res => {
        if (!res.data.length) { document.getElementById('logs-table').innerHTML = '<p class="text-muted">No logs found.</p>'; return; }
        const badges = {on:'success',off:'secondary',gesture:'info',schedule:'primary',security_alert:'danger'};
        let html = `<table class="table table-sm table-bordered bg-white shadow-sm">
            <thead class="table-dark"><tr><th>#</th><th>Room</th><th>Event</th><th>Triggered By</th><th>Time</th></tr></thead><tbody>`;
        res.data.forEach((l,i) => {
            html += `<tr><td class="text-muted">${i+1}</td><td>${l.room_name}</td>
                <td><span class="badge bg-${badges[l.event_type]||'secondary'}">${l.event_type}</span></td>
                <td>${l.triggered_by}</td>
                <td class="small text-muted">${l.event_time}</td></tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('logs-table').innerHTML = html;
    });
}

function clearLogFilters() {
    document.getElementById('log-filter-room').value='';
    document.getElementById('log-filter-type').value='';
    document.getElementById('log-filter-date').value='';
    loadLogs();
}

// ── ANALYTICS ─────────────────────────────────────────────────
function loadAnalytics() {
    const range = document.getElementById('analytics-range').value;
    get(API+'analytics.php?range='+range).then(res => {
        if (!res.success) { document.getElementById('analytics-table').innerHTML='<p class="text-danger">Error loading analytics.</p>'; return; }
        let html = `<div class="alert alert-info small mb-3">
            <strong>Formula:</strong> kWh = (27W × ON events) ÷ 1000 &nbsp;|&nbsp; Prototype: 9 bulbs × 3W = 27W
        </div>
        <table class="table table-sm table-bordered bg-white shadow-sm mb-4">
            <thead class="table-dark"><tr><th>Room</th><th>Size</th><th>ON Events</th><th>Est. kWh</th><th>Alerts</th></tr></thead><tbody>`;
        res.classrooms.forEach(c => {
            html += `<tr><td><strong>${c.room_name}</strong></td>
                <td>${c.room_size}</td>
                <td>${c.on_count}</td>
                <td><strong>${c.est_kwh} kWh</strong></td>
                <td>${c.alert_count > 0 ? '<span class="badge bg-danger">'+c.alert_count+'</span>' : '—'}</td></tr>`;
        });
        html += '</tbody></table>';
        html += `<h6 class="fw-bold">Daily Activations</h6>
        <table class="table table-sm table-bordered bg-white shadow-sm">
            <thead class="table-dark"><tr><th>Date</th><th>Light ON Events</th></tr></thead><tbody>`;
        res.daily.forEach(d => {
            html += `<tr><td>${d.label}</td><td>${d.count > 0 ? '<span class="badge bg-warning text-dark">'+d.count+'</span>' : '<span class="text-muted">0</span>'}</td></tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('analytics-table').innerHTML = html;
    });
}

// ── helpers ────────────────────────────────────────────────────
function fmtTime(t) {
    if (!t) return '';
    const [h,m] = t.split(':');
    const hr = parseInt(h);
    return `${hr%12||12}:${m} ${hr<12?'AM':'PM'}`;
}

// ── Init: load all tabs ────────────────────────────────────────
loadAccounts();
loadClassrooms();
loadSchedule();
loadLogs();
loadAnalytics();

// Re-load on tab click
document.querySelectorAll('[data-bs-toggle="tab"]').forEach(tab => {
    tab.addEventListener('shown.bs.tab', e => {
        const target = e.target.getAttribute('href');
        if (target==='#accounts')   loadAccounts();
        if (target==='#classrooms') loadClassrooms();
        if (target==='#schedule')   loadSchedule();
        if (target==='#logs')       loadLogs();
        if (target==='#analytics')  loadAnalytics();
    });
});
</script>
<script src="../../script/animations.js"></script>
</body>
</html>
