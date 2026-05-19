<?php
$phpRoot = realpath(__DIR__ . '/../../php');
require_once $phpRoot . '/session_guard.php';
check_admin();
require_once $phpRoot . '/db_connect.php';

$admin_name  = htmlspecialchars($_SESSION['admin_name']);
$name_parts  = explode(' ', $admin_name);
$initials    = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));

$admin_email = '';
$stmt = $conn->prepare('SELECT email FROM admins WHERE id = ?');
$stmt->bind_param('i', $_SESSION['admin_id']);
$stmt->execute();
$stmt->bind_result($admin_email);
$stmt->fetch();
$stmt->close();

/* ── Fetch rooms ── */
$rooms = [];
$r = $conn->query('SELECT id, room_name FROM rooms ORDER BY room_name');
if ($r) { while ($row = $r->fetch_assoc()) $rooms[] = $row; }

/* ── Fetch faculty for dropdowns ── */
$faculty_list = [];
$f = $conn->query('SELECT id, name FROM faculty ORDER BY name');
if ($f) { while ($row = $f->fetch_assoc()) $faculty_list[] = $row; }

/* ── Fetch subjects ── */
$subjects = [];
$s = $conn->query('SELECT id, subject_name FROM subjects ORDER BY subject_name');
if ($s) { while ($row = $s->fetch_assoc()) $subjects[] = $row; }

/* ── Selected room from query param or default ── */
$selected_room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : ($rooms[0]['id'] ?? 0);
$selected_room_name = '';
foreach ($rooms as $rm) {
    if ($rm['id'] == $selected_room_id) { $selected_room_name = $rm['room_name']; break; }
}
/* Fallback: match by name param for backwards-compat with ?room= links */
if (!$selected_room_name && isset($_GET['room'])) {
    $rn = $_GET['room'];
    foreach ($rooms as $rm) {
        if (strtolower($rm['room_name']) === strtolower($rn)) {
            $selected_room_id   = $rm['id'];
            $selected_room_name = $rm['room_name'];
            break;
        }
    }
}

/* ── Fetch schedule rows for selected room ── */
/*  ALERT: PHP | DB — adjust table/column names to match your schema  */
$schedule_rows = [];
if ($selected_room_id) {
    $sq = $conn->prepare(
        'SELECT s.id, f.name AS faculty_name, sub.subject_name,
                s.day_of_week, s.time_start, s.time_end
         FROM   schedules s
         JOIN   faculty  f   ON f.id   = s.faculty_id
         JOIN   subjects sub ON sub.id = s.subject_id
         WHERE  s.room_id = ?
         ORDER  BY FIELD(s.day_of_week,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"),
                   s.time_start'
    );
    if ($sq) {
        $sq->bind_param('i', $selected_room_id);
        $sq->execute();
        $res = $sq->get_result();
        while ($row = $res->fetch_assoc()) $schedule_rows[] = $row;
        $sq->close();
    }
}

$conn->close();

$days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schedule Management</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
            crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">

    <style>
        /* ══════════════════════════════════════
           PAGE SHELL
        ══════════════════════════════════════ */
        body.contrast-bg { background: #2f004f; min-height: 100vh; }

        .page-content { padding: 0 24px 40px; }

        /* ── Topbar ── */
        .topbar {
            background: linear-gradient(0deg,rgba(255,255,255,0) 9%,rgba(47,0,79,.76) 40%,rgba(47,0,79,.95) 70%,rgba(47,0,79,1) 100%);
            position: sticky; top: 0; z-index: 100;
            display: flex; align-items: center;
            padding: 16px 24px; gap: 12px;
        }
        .topbar-menu-btn {
            background-color: var(--primary-color); color: var(--secondary-color-1);
            border: none; border-radius: 10px; height: 50px; width: 50px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; cursor: pointer; transition: background-color .2s;
        }
        .topbar-menu-btn i { font-size: 24px; }
        .topbar-title { flex:1; color:var(--primary-color); font-size:28px; font-weight:700; margin:0; }
        .topbar-right { display:flex; align-items:center; gap:14px; }
        .topbar-admin { color:var(--primary-color); font-size:16px; white-space:nowrap; }

        .search-container { position:relative; }
        .search-input {
            height:50px; border-radius:30px; padding-left:40px; border:none;
            font-family:var(--font-primary); font-size:15px; width:240px;
            background:#fff; color:var(--black); outline:none;
            box-shadow:0 0 10px rgba(0,0,0,.1);
        }
        .search-icon { position:absolute; top:50%; left:15px; transform:translateY(-50%); color:#888; font-size:16px; }

        .avatar-icon {
            width:50px; height:50px; border-radius:50%;
            background-color:#8e8b8b; color:#3f3f3f;
            display:flex; align-items:center; justify-content:center;
            cursor:pointer; flex-shrink:0;
        }

        /* ── Section heading ── */
        .section-heading {
            color:var(--primary-color); font-size:13px; font-weight:600;
            letter-spacing:.10em; text-transform:uppercase;
            margin:24px 0 14px; opacity:.75;
        }

        /* ══════════════════════════════════════
           SCHEDULE CARD
        ══════════════════════════════════════ */
        .schedule-card {
            background:#fff; border-radius:20px;
            box-shadow:0 8px 40px rgba(47,0,79,.18);
            overflow:hidden;
        }

        /* gradient header strip */
        .schedule-card-header {
            background:linear-gradient(135deg,#2d0d5f 0%,#4a1d8f 100%);
            padding:22px 28px;
            display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;
        }
        .schedule-card-header h4 { color:#fff; margin:0; font-weight:700; font-size:1.25rem; }
        .room-pill {
            display:inline-flex; align-items:center; gap:8px;
            background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.3);
            border-radius:999px; padding:6px 16px;
            color:#fff; font-size:14px; font-weight:600;
        }
        .room-pill i { font-size:15px; }

        /* room selector row */
        .room-selector-row {
            padding:20px 28px 0;
            display:flex; align-items:center; gap:12px; flex-wrap:wrap;
        }
        .room-selector-label { font-size:13px; font-weight:600; color:#888; text-transform:uppercase; letter-spacing:.06em; }
        .room-select-dropdown {
            height:44px; border-radius:10px; border:1.5px solid #ddd;
            padding:0 14px; font-family:var(--font-primary); font-size:15px;
            color:var(--secondary-color-1); background:#f8f9fa;
            cursor:pointer; outline:none; transition:border-color .2s;
            min-width:220px;
        }
        .room-select-dropdown:focus { border-color:var(--secondary-color-2); }

        /* add-slot button */
        .btn-add-slot {
            margin-left:auto; display:inline-flex; align-items:center; gap:7px;
            padding:10px 20px; border-radius:10px; border:none;
            background:var(--secondary-color-1); color:var(--primary-color);
            font-family:var(--font-primary); font-size:13px; font-weight:600;
            cursor:pointer; transition:background-color .2s, transform .15s;
            width:auto;
        }
        .btn-add-slot:hover { background:var(--secondary-color-4); transform:scale(1.02); color:var(--primary-color); }

        /* ── Table area ── */
        .schedule-table-wrap { padding:20px 28px 28px; }

        .schedule-table {
            width:100%; border-collapse:separate; border-spacing:0 8px;
        }

        .schedule-table thead th {
            font-size:11px; font-weight:700; text-transform:uppercase;
            letter-spacing:.08em; color:#999; padding:0 12px 4px;
            border:none;
        }

        /* day group header */
        .day-header td {
            padding:12px 12px 4px;
            font-size:12px; font-weight:700; text-transform:uppercase;
            letter-spacing:.08em; color:var(--secondary-color-2);
            border-bottom:2px solid #ede8f5;
        }

        /* schedule row */
        .sched-row td {
            background:#f8f5fc; padding:14px 12px;
            border:none; font-size:14px; vertical-align:middle;
        }
        .sched-row td:first-child { border-radius:12px 0 0 12px; }
        .sched-row td:last-child  { border-radius:0 12px 12px 0; }
        .sched-row:hover td { background:#ede8f5; }

        .sched-time {
            font-size:13px; font-weight:700; color:var(--secondary-color-1);
            white-space:nowrap;
        }

        .sched-faculty { font-weight:600; color:var(--secondary-color-1); }
        .sched-subject { font-size:12px; color:#888; margin-top:2px; }

        /* action icon buttons */
        .btn-icon {
            width:32px; height:32px; border-radius:8px; border:none;
            display:inline-flex; align-items:center; justify-content:center;
            cursor:pointer; font-size:14px; transition:background-color .2s, transform .15s;
        }
        .btn-icon-edit  { background:#e9d5ff; color:var(--secondary-color-1); }
        .btn-icon-edit:hover  { background:var(--secondary-color-1); color:#fff; transform:scale(1.08); }
        .btn-icon-del   { background:#ffe4ec; color:#c0004e; }
        .btn-icon-del:hover   { background:#c0004e; color:#fff; transform:scale(1.08); }

        /* empty state */
        .empty-state {
            text-align:center; padding:48px 24px; color:#bbb;
        }
        .empty-state i { font-size:3rem; margin-bottom:12px; display:block; }
        .empty-state p { font-size:15px; margin:0; }

        /* ══════════════════════════════════════
           CONFIRM PANEL (sticky bottom)
        ══════════════════════════════════════ */
        .confirm-bar {
            position:sticky; bottom:0; left:0; right:0;
            background:rgba(47,0,79,.96);
            backdrop-filter:blur(10px);
            padding:14px 28px;
            display:none; align-items:center; gap:14px; flex-wrap:wrap;
            border-top:1px solid rgba(255,255,255,.12);
            z-index:200;
        }
        .confirm-bar.visible { display:flex; }
        .confirm-bar p { color:#fff; margin:0; font-size:14px; flex:1; }
        .btn-confirm {
            padding:10px 28px; border-radius:10px; border:none;
            background:#27ae60; color:#fff;
            font-family:var(--font-primary); font-size:14px; font-weight:700;
            cursor:pointer; transition:background-color .2s, transform .15s;
            width:auto;
        }
        .btn-confirm:hover { background:#1e8449; transform:scale(1.02); color:#fff; }
        .btn-discard {
            padding:10px 20px; border-radius:10px;
            border:1.5px solid rgba(255,255,255,.3);
            background:transparent; color:#fff;
            font-family:var(--font-primary); font-size:14px; font-weight:600;
            cursor:pointer; transition:background-color .2s; width:auto;
        }
        .btn-discard:hover { background:rgba(255,255,255,.12); color:#fff; }

        /* ══════════════════════════════════════
           ADD / EDIT MODAL
        ══════════════════════════════════════ */
        .sched-modal .modal-header {
            background:linear-gradient(135deg,#2d0d5f 0%,#4a1d8f 100%);
            color:#fff;
        }
        .sched-modal .modal-title { font-weight:700; font-size:1.2rem; }
        .sched-modal .modal-body { padding:24px; }

        .form-label-sm {
            font-size:12px; font-weight:600; color:#888;
            text-transform:uppercase; letter-spacing:.06em;
            margin-bottom:6px; display:block;
        }
        .form-ctrl {
            width:100%; height:44px; border-radius:10px;
            border:1.5px solid #ddd; padding:0 14px;
            font-family:var(--font-primary); font-size:15px;
            color:var(--secondary-color-1); background:#fff;
            outline:none; transition:border-color .2s;
            box-sizing:border-box;
        }
        .form-ctrl:focus { border-color:var(--secondary-color-2); }
        .form-row { display:flex; gap:14px; flex-wrap:wrap; }
        .form-row .form-group { flex:1; min-width:160px; }

        .btn-modal-save {
            padding:11px 28px; border-radius:10px; border:none;
            background:var(--secondary-color-1); color:var(--primary-color);
            font-family:var(--font-primary); font-size:14px; font-weight:700;
            cursor:pointer; transition:background-color .2s, transform .15s;
            width:auto;
        }
        .btn-modal-save:hover { background:var(--secondary-color-4); transform:scale(1.02); color:var(--primary-color); }
        .btn-modal-cancel {
            padding:11px 20px; border-radius:10px;
            border:1.5px solid #ddd; background:#fff;
            color:var(--secondary-color-1); font-family:var(--font-primary);
            font-size:14px; font-weight:600; cursor:pointer;
            transition:background-color .2s; width:auto;
        }
        .btn-modal-cancel:hover { background:#f0eaf8; }

        /* ══════════════════════════════════════
           SIDEBAR / PROFILE OFFCANVAS
        ══════════════════════════════════════ */
        .nav-btn {
            width:52px; height:52px; border-radius:12px;
            display:flex; align-items:center; justify-content:center;
            background-color:var(--secondary-color-1); color:var(--primary-color);
            border:none; cursor:pointer; transition:background-color .2s, transform .15s;
        }
        .nav-btn i, .nav-btn svg { font-size:22px; }
        .nav-btn:hover { background-color:var(--secondary-color-4); transform:scale(1.06); }

        #sidebarOffcanvas { width:100px !important; background-color:var(--primary-color); }
        #sidebarOffcanvas .offcanvas-header { justify-content:center; padding:1rem .5rem; }
        #sidebarOffcanvas .logo { width:75px; height:75px; object-fit:contain; cursor:pointer; }
        #sidebarOffcanvas .offcanvas-body { display:flex; flex-direction:column; align-items:center; gap:8px; padding-top:.5rem; }
        #sidebarOffcanvas .offcanvas-footer { display:flex; justify-content:center; padding:1rem; }
        #sidebarOffcanvas .offcanvas-footer img { width:4rem; }

        #profileOffcanvas { width:240px !important; background-color:var(--primary-color); }
        #profileOffcanvas .avatar-icon { width:80px; height:80px; border-radius:50%; background:#d9d6d6; color:var(--secondary-color-1); }

        .profile-btn {
            width:100%; padding:8px; margin:3px 0; border-radius:8px;
            background-color:var(--secondary-color-1); color:var(--primary-color);
            border:none; font-size:14px; cursor:pointer;
            font-family:var(--font-primary); transition:background-color .2s, transform .15s;
        }
        .profile-btn:hover { background-color:var(--secondary-color-4); transform:scale(1.02); }

        @media(max-width:600px){
            .search-input{width:140px;}
            .topbar-admin{display:none;}
            .schedule-card-header,.room-selector-row,.schedule-table-wrap{padding-left:16px;padding-right:16px;}
        }
    </style>
</head>
<body class="contrast-bg">

    <!-- ═══ TOPBAR ═══ -->
    <div class="topbar">
        <button type="button" class="topbar-menu-btn" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas">
            <i class="bi bi-list"></i>
        </button>
        <h1 class="topbar-title bold">Schedule Management</h1>
        <div class="topbar-right">
            <div class="search-container">
                <input type="text" class="search-input" placeholder="Search…">
                <i class="bi bi-search search-icon"></i>
            </div>
            <span class="topbar-admin"><?= $admin_name ?></span>
            <div class="avatar-icon" data-bs-toggle="offcanvas" data-bs-target="#profileOffcanvas">
                <h3 class="bold mb-0"><?= $initials ?></h3>
            </div>
        </div>
    </div>

    <!-- ═══ PAGE CONTENT ═══ -->
    <div class="page-content">
        <div class="section-heading">Room Schedule</div>

        <div class="schedule-card">

            <!-- Card header with current room pill -->
            <div class="schedule-card-header">
                <h4><i class="bi bi-calendar3 me-2"></i>Schedule Management</h4>
                <div class="room-pill" id="headerRoomPill">
                    <i class="bi bi-door-open"></i>
                    <span id="headerRoomName"><?= htmlspecialchars($selected_room_name ?: 'Select a Room') ?></span>
                </div>
            </div>

            <!-- Room selector + Add button -->
            <div class="room-selector-row">
                <span class="room-selector-label">Room</span>
                <select class="room-select-dropdown" id="roomDropdown" onchange="changeRoom(this.value)">
                    <?php foreach ($rooms as $rm): ?>
                        <option value="<?= $rm['id'] ?>"
                            <?= $rm['id'] == $selected_room_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($rm['room_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button class="btn-add-slot" data-bs-toggle="modal" data-bs-target="#schedModal"
                        onclick="openAddModal()">
                    <i class="bi bi-plus-lg"></i> Add Schedule Slot
                </button>
            </div>

            <!-- Schedule table -->
            <div class="schedule-table-wrap">
                <table class="schedule-table" id="schedTable">
                    <thead>
                        <tr>
                            <th style="width:140px;">Time</th>
                            <th>Faculty</th>
                            <th>Subject</th>
                            <th style="width:90px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="schedTableBody">
                        <?php
                        // Group by day
                        $by_day = [];
                        foreach ($schedule_rows as $row) {
                            $by_day[$row['day_of_week']][] = $row;
                        }

                        if (empty($by_day)):
                        ?>
                        <tr>
                            <td colspan="4">
                                <div class="empty-state">
                                    <i class="bi bi-calendar-x"></i>
                                    <p>No schedule slots for this room yet.<br>Click <strong>Add Schedule Slot</strong> to get started.</p>
                                </div>
                            </td>
                        </tr>
                        <?php else:
                            foreach ($days as $day):
                                if (!isset($by_day[$day])) continue;
                        ?>
                        <tr class="day-header">
                            <td colspan="4"><?= $day ?></td>
                        </tr>
                        <?php foreach ($by_day[$day] as $s): ?>
                        <tr class="sched-row" data-id="<?= $s['id'] ?>">
                            <td class="sched-time">
                                <?= date('g:i A', strtotime($s['time_start'])) ?>
                                &ndash;
                                <?= date('g:i A', strtotime($s['time_end'])) ?>
                            </td>
                            <td>
                                <div class="sched-faculty"><?= htmlspecialchars($s['faculty_name']) ?></div>
                            </td>
                            <td>
                                <div class="sched-subject"><?= htmlspecialchars($s['subject_name']) ?></div>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <button class="btn-icon btn-icon-edit"
                                            onclick="openEditModal(<?= $s['id'] ?>, '<?= addslashes($s['faculty_name']) ?>', '<?= addslashes($s['subject_name']) ?>', '<?= $s['day_of_week'] ?>', '<?= $s['time_start'] ?>', '<?= $s['time_end'] ?>')"
                                            title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn-icon btn-icon-del"
                                            onclick="confirmDelete(<?= $s['id'] ?>)"
                                            title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div><!-- /schedule-card -->
    </div><!-- /page-content -->

    <!-- ═══ CONFIRM / DISCARD BAR ═══ -->
    <div class="confirm-bar" id="confirmBar">
        <p><i class="bi bi-info-circle me-2"></i>You have unsaved changes to the schedule.</p>
        <button class="btn-discard" onclick="discardChanges()">Discard</button>
        <button class="btn-confirm" onclick="saveChanges()">
            <i class="bi bi-check-lg me-1"></i> Confirm &amp; Save
        </button>
    </div>


    <!-- ═══ ADD / EDIT MODAL ═══ -->
    <div class="sched-modal modal fade" id="schedModal" tabindex="-1" aria-labelledby="schedModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:16px;overflow:hidden;border:none;">
                <div class="modal-header">
                    <h5 class="modal-title" id="schedModalLabel">Add Schedule Slot</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="editSlotId" value="">

                    <!-- Day -->
                    <div class="form-group mb-3">
                        <label class="form-label-sm">Day of Week</label>
                        <select class="form-ctrl" id="slotDay">
                            <?php foreach ($days as $d): ?>
                                <option value="<?= $d ?>"><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Time -->
                    <div class="form-row mb-3">
                        <div class="form-group">
                            <label class="form-label-sm">Time Start</label>
                            <input type="time" class="form-ctrl" id="slotStart">
                        </div>
                        <div class="form-group">
                            <label class="form-label-sm">Time End</label>
                            <input type="time" class="form-ctrl" id="slotEnd">
                        </div>
                    </div>

                    <!-- Faculty -->
                    <div class="form-group mb-3">
                        <label class="form-label-sm">Faculty Name</label>
                        <select class="form-ctrl" id="slotFaculty">
                            <option value="">— Select Faculty —</option>
                            <?php foreach ($faculty_list as $f): ?>
                                <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Subject -->
                    <div class="form-group mb-3">
                        <label class="form-label-sm">Subject Name</label>
                        <select class="form-ctrl" id="slotSubject">
                            <option value="">— Select Subject —</option>
                            <?php foreach ($subjects as $sub): ?>
                                <option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['subject_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <button class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn-modal-save" onclick="saveSlot()">
                            <i class="bi bi-check-lg me-1"></i> Save Slot
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ DELETE CONFIRM MODAL ═══ -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content" style="border-radius:16px;overflow:hidden;border:none;">
                <div class="modal-header" style="background:linear-gradient(135deg,#c0004e,#e05580);color:#fff;">
                    <h5 class="modal-title" style="font-weight:700;">Delete Slot?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="bi bi-trash" style="font-size:2.5rem;color:#c0004e;"></i>
                    <p class="mt-3 mb-0" style="font-size:15px;">This schedule slot will be permanently removed. Are you sure?</p>
                </div>
                <div class="modal-footer border-0 justify-content-center gap-2">
                    <button class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn-modal-save" style="background:#c0004e;" onclick="executeDelete()">
                        <i class="bi bi-trash me-1"></i> Delete
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- ═══ SIDEBAR OFFCANVAS ═══ -->
    <div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas">
        <div class="offcanvas-header justify-content-center">
            <img src="../../images/logo.png" class="logo" alt="Logo">
        </div>
        <div class="offcanvas-body align-items-center d-flex flex-column gap-2">
            <button class="nav-btn" title="Home"            onclick="dissolve('admin-homepage.php')"><i class="bi bi-house-door"></i></button>
            <button class="nav-btn" title="Room Management" onclick="dissolve('admin-room-manage.php')"><i class="fa-solid fa-person-shelter"></i></button>
            <button class="nav-btn" title="Analytics"       onclick="dissolve('admin-analytics.php')"><i class="bi bi-clipboard2-data"></i></button>
            <button class="nav-btn" title="Reports"         onclick="dissolve('admin-reports.php')"><i class="bi bi-exclamation-triangle"></i></button>
            <button class="nav-btn" title="Faculty"         onclick="dissolve('admin-faculty-management.php')"><i class="bi bi-people"></i></button>
            <button class="nav-btn" title="Profile Settings" onclick="dissolve('admin-profile-settings.php')"><i class="bi bi-gear"></i></button>
        </div>
        <div class="offcanvas-footer">
            <img src="../../images/team-logo.png" alt="Team Logo" style="width:4rem;">
        </div>
    </div>

    <!-- ═══ PROFILE OFFCANVAS ═══ -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="profileOffcanvas">
        <div class="offcanvas-body align-items-center d-flex flex-column pt-4 gap-2">
            <div class="avatar-icon d-flex align-items-center justify-content-center">
                <h3 class="bold"><?= $initials ?></h3>
            </div>
            <h4 class="bold mt-2" style="color:var(--secondary-color-1);"><?= $admin_name ?></h4>
            <h6 class="light" style="word-break:break-all;text-align:center;"><?= $admin_email ?></h6>
            <div class="d-flex flex-column align-items-center justify-content-center w-100 mt-2 gap-1">
                <button class="profile-btn" onclick="dissolve('admin-profile-settings.php')">Profile Settings</button>
                <button class="profile-btn">Classroom Details</button>
                <button class="profile-btn" onclick="window.location.href='../../index.php'">Logout</button>
            </div>
        </div>
    </div>

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>

    <script>
    /* ══════════════════════════════════════════
       ROOM DROPDOWN — reload page with room_id
    ══════════════════════════════════════════ */
    function changeRoom(roomId) {
        // Update the header pill label instantly for UX
        const opt = document.getElementById('roomDropdown').options;
        const label = opt[opt.selectedIndex].text;
        document.getElementById('headerRoomName').textContent = label;
        // Navigate to same page with new room
        window.location.href = 'admin-timetable-manage.php?room_id=' + encodeURIComponent(roomId);
    }

    /* ══════════════════════════════════════════
       MODAL — ADD vs EDIT
    ══════════════════════════════════════════ */
    function openAddModal() {
        document.getElementById('schedModalLabel').textContent = 'Add Schedule Slot';
        document.getElementById('editSlotId').value = '';
        document.getElementById('slotDay').value     = 'Monday';
        document.getElementById('slotStart').value   = '';
        document.getElementById('slotEnd').value     = '';
        document.getElementById('slotFaculty').value = '';
        document.getElementById('slotSubject').value = '';
    }

    function openEditModal(id, faculty, subject, day, start, end) {
        document.getElementById('schedModalLabel').textContent = 'Edit Schedule Slot';
        document.getElementById('editSlotId').value = id;
        document.getElementById('slotDay').value    = day;
        document.getElementById('slotStart').value  = start.substring(0,5);
        document.getElementById('slotEnd').value    = end.substring(0,5);

        // Match faculty & subject by text (since we have names from DB join)
        const fSel = document.getElementById('slotFaculty');
        for (let o of fSel.options) { if (o.text === faculty) { fSel.value = o.value; break; } }
        const sSel = document.getElementById('slotSubject');
        for (let o of sSel.options) { if (o.text === subject) { sSel.value = o.value; break; } }

        new bootstrap.Modal(document.getElementById('schedModal')).show();
    }

    /* ══════════════════════════════════════════
       SAVE SLOT (POST to PHP handler)
    ══════════════════════════════════════════ */
    function saveSlot() {
        const id       = document.getElementById('editSlotId').value;
        const day      = document.getElementById('slotDay').value;
        const start    = document.getElementById('slotStart').value;
        const end      = document.getElementById('slotEnd').value;
        const faculty  = document.getElementById('slotFaculty').value;
        const subject  = document.getElementById('slotSubject').value;
        const roomId   = document.getElementById('roomDropdown').value;

        if (!day || !start || !end || !faculty || !subject) {
            alert('Please fill in all fields.');
            return;
        }
        if (start >= end) {
            alert('End time must be after start time.');
            return;
        }

        /* ALERT: PHP | Back-end — POST to your save-schedule handler */
        const body = new URLSearchParams({
            action:     id ? 'update' : 'create',
            slot_id:    id,
            room_id:    roomId,
            faculty_id: faculty,
            subject_id: subject,
            day_of_week: day,
            time_start:  start,
            time_end:    end
        });

        fetch('../../php/schedule_handler.php', { method:'POST', body })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('schedModal'))?.hide();
                    showConfirmBar();
                    /* Optionally reload table via AJAX; for now reload page */
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(() => {
                /* Dev/demo fallback: just show confirm bar */
                bootstrap.Modal.getInstance(document.getElementById('schedModal'))?.hide();
                showConfirmBar();
            });
    }

    /* ══════════════════════════════════════════
       DELETE
    ══════════════════════════════════════════ */
    let _pendingDeleteId = null;

    function confirmDelete(id) {
        _pendingDeleteId = id;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }

    function executeDelete() {
        if (!_pendingDeleteId) return;
        const body = new URLSearchParams({ action:'delete', slot_id:_pendingDeleteId });

        fetch('../../php/schedule_handler.php', { method:'POST', body })
            .then(r => r.json())
            .then(data => {
                bootstrap.Modal.getInstance(document.getElementById('deleteModal'))?.hide();
                if (data.success) {
                    const row = document.querySelector(`.sched-row[data-id="${_pendingDeleteId}"]`);
                    if (row) row.remove();
                    showConfirmBar();
                } else {
                    alert('Error: ' + (data.message || 'Could not delete'));
                }
                _pendingDeleteId = null;
            })
            .catch(() => {
                bootstrap.Modal.getInstance(document.getElementById('deleteModal'))?.hide();
                const row = document.querySelector(`.sched-row[data-id="${_pendingDeleteId}"]`);
                if (row) row.remove();
                showConfirmBar();
                _pendingDeleteId = null;
            });
    }

    /* ══════════════════════════════════════════
       CONFIRM BAR
    ══════════════════════════════════════════ */
    function showConfirmBar() {
        document.getElementById('confirmBar').classList.add('visible');
    }

    function saveChanges() {
        /* Changes were already sent to the server per-action.
           This bar just gives visual confirmation; reload to refresh state. */
        document.getElementById('confirmBar').classList.remove('visible');
        location.reload();
    }

    function discardChanges() {
        document.getElementById('confirmBar').classList.remove('visible');
        location.reload();
    }
    </script>

</body>
</html>