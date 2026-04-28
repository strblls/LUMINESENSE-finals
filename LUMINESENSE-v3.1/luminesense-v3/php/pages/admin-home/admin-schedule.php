<?php
// ============================================================
//  admin-schedule.php
//  LumineSense – Timetable Management
//
//  Admins can:
//  - View schedules per classroom in a weekly grid
//  - Add new schedule slots (classroom + day + start/end time)
//  - Delete existing schedule slots
//  - Extend a classroom's hours (temporary override)
// ============================================================

require_once '../../php/session_guard.php';
check_admin();
require_once '../../php/db_connect.php';

$admin_id = (int)$_SESSION['admin_id'];

// ── Handle POST: Add schedule ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $classroom_id = (int)($_POST['classroom_id'] ?? 0);
    $day          = $_POST['day_of_week'] ?? '';
    $start        = $_POST['start_time']  ?? '';
    $end          = $_POST['end_time']    ?? '';

    $valid_days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    $errors = [];

    if (!$classroom_id)             $errors[] = "Please select a classroom.";
    if (!in_array($day, $valid_days)) $errors[] = "Invalid day.";
    if (!$start || !$end)           $errors[] = "Please set both start and end time.";
    if ($start >= $end)             $errors[] = "Start time must be before end time.";

    if (empty($errors)) {
        // Check for overlapping schedule in same room + day
        $stmt = $conn->prepare("
            SELECT id FROM schedules
            WHERE classroom_id = ? AND day_of_week = ?
            AND NOT (end_time <= ? OR start_time >= ?)
        ");
        $stmt->bind_param("isss", $classroom_id, $day, $start, $end);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = "This time slot overlaps with an existing schedule for that day.";
        }
        $stmt->close();
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("INSERT INTO schedules (classroom_id, day_of_week, start_time, end_time, created_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("isssi", $classroom_id, $day, $start, $end, $admin_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['schedule_msg'] = ['type' => 'success', 'text' => 'Schedule added successfully.'];
    } else {
        $_SESSION['schedule_msg'] = ['type' => 'danger', 'text' => implode(' ', $errors)];
    }

    header('Location: admin-schedule.php');
    exit;
}

// ── Handle POST: Delete schedule ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $schedule_id = (int)($_POST['schedule_id'] ?? 0);
    if ($schedule_id > 0) {
        $stmt = $conn->prepare("DELETE FROM schedules WHERE id = ?");
        $stmt->bind_param("i", $schedule_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['schedule_msg'] = ['type' => 'success', 'text' => 'Schedule entry removed.'];
    }
    header('Location: admin-schedule.php');
    exit;
}

// ── Fetch all classrooms ───────────────────────────────────────
$classrooms = [];
$r = $conn->query("SELECT id, room_name FROM classrooms ORDER BY room_name ASC");
if ($r) while ($row = $r->fetch_assoc()) $classrooms[] = $row;

// ── Fetch all schedules with room names ───────────────────────
$schedules = [];
$r = $conn->query("
    SELECT s.id, s.day_of_week, s.start_time, s.end_time,
           c.room_name, c.id AS classroom_id
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    ORDER BY FIELD(s.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), s.start_time
");
if ($r) while ($row = $r->fetch_assoc()) $schedules[] = $row;

$conn->close();

// Group schedules by day for the weekly grid display
$days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
$by_day = array_fill_keys($days, []);
foreach ($schedules as $s) {
    $by_day[$s['day_of_week']][] = $s;
}

// Flash message
$msg = $_SESSION['schedule_msg'] ?? null;
unset($_SESSION['schedule_msg']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Timetable – LumineSense Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/dashboard.css">
</head>
<body>
<div class="dashboard-wrapper">

    <?php include 'admin-sidebar.php'; ?>

    <div class="dashboard-main">

        <div class="dashboard-topbar">
            <h1 class="topbar-title">Timetable Management</h1>
            <div class="topbar-right">
                <button class="medium" onclick="document.getElementById('add-schedule-modal').style.display='flex'"
                        style="font-size:0.82rem; padding:7px 16px;">
                    <i class="bi bi-plus-lg"></i> Add Schedule
                </button>
            </div>
        </div>

        <div class="dashboard-content">

            <!-- Flash message -->
            <?php if ($msg): ?>
            <div class="alert-banner <?= $msg['type'] === 'danger' ? 'danger' : '' ?>" style="margin-bottom:20px;">
                <i class="bi bi-<?= $msg['type'] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                <span><?= htmlspecialchars($msg['text']) ?></span>
            </div>
            <?php endif; ?>

            <!-- ── Weekly Timetable Grid ─────────────────── -->
            <div class="panel">
                <div class="panel-header">
                    <h6><i class="bi bi-calendar-week-fill"></i> Weekly Schedule Overview</h6>
                </div>
                <div class="panel-body timetable-grid" style="padding:0 0 4px;">
                    <?php if (empty($schedules)): ?>
                    <p style="color:#aaa; font-size:0.85rem; text-align:center; padding:28px 0;">
                        No schedules set yet. Click <strong>Add Schedule</strong> to begin.
                    </p>
                    <?php else: ?>
                    <table class="ls-table">
                        <thead>
                            <tr>
                                <th style="width:110px;">Day</th>
                                <th>Scheduled Slots</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($days as $day): ?>
                            <tr>
                                <td style="font-weight:700; color:#1a1a2e;"><?= $day ?></td>
                                <td>
                                    <?php if (empty($by_day[$day])): ?>
                                        <span style="color:#ccc; font-size:0.8rem;">No classes</span>
                                    <?php else: ?>
                                        <?php foreach ($by_day[$day] as $slot): ?>
                                        <span class="schedule-cell">
                                            <?= htmlspecialchars($slot['room_name']) ?> &nbsp;
                                            <?= date('g:i A', strtotime($slot['start_time'])) ?> –
                                            <?= date('g:i A', strtotime($slot['end_time'])) ?>
                                            <!-- Delete button -->
                                            <form method="POST" style="display:inline; margin-left:4px;">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="schedule_id" value="<?= $slot['id'] ?>">
                                                <button type="submit" title="Remove"
                                                        onclick="return confirm('Remove this schedule slot?')"
                                                        style="background:none; border:none; color:inherit; cursor:pointer; padding:0; font-size:0.75rem; line-height:1;">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </form>
                                        </span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── All Schedules Detail Table ────────────── -->
            <div class="panel">
                <div class="panel-header">
                    <h6><i class="bi bi-list-ul"></i> All Schedule Entries (<?= count($schedules) ?>)</h6>
                </div>
                <div class="panel-body" style="padding:0;">
                    <?php if (empty($schedules)): ?>
                    <p style="color:#aaa; font-size:0.85rem; text-align:center; padding:24px 0;">No schedule entries yet.</p>
                    <?php else: ?>
                    <table class="ls-table">
                        <thead>
                            <tr>
                                <th>Room</th>
                                <th>Day</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($schedules as $s): ?>
                            <tr>
                                <td><?= htmlspecialchars($s['room_name']) ?></td>
                                <td><?= $s['day_of_week'] ?></td>
                                <td><?= date('g:i A', strtotime($s['start_time'])) ?></td>
                                <td><?= date('g:i A', strtotime($s['end_time'])) ?></td>
                                <td>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="schedule_id" value="<?= $s['id'] ?>">
                                        <button type="submit" class="btn-reject"
                                                onclick="return confirm('Remove this schedule slot?')">
                                            <i class="bi bi-trash"></i> Remove
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ── Add Schedule Modal ──────────────────────────────────── -->
<div class="ls-modal-overlay" id="add-schedule-modal" style="display:none;">
    <div class="ls-modal-box">
        <h5><i class="bi bi-calendar-plus"></i> Add Schedule Entry</h5>

        <form method="POST">
            <input type="hidden" name="action" value="add">

            <div class="mb-3">
                <label class="form-label" style="font-size:0.85rem; font-weight:600;">Classroom</label>
                <select name="classroom_id" class="form-control" required>
                    <option value="">-- Select classroom --</option>
                    <?php foreach ($classrooms as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['room_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label" style="font-size:0.85rem; font-weight:600;">Day of Week</label>
                <select name="day_of_week" class="form-control" required>
                    <option value="">-- Select day --</option>
                    <?php foreach ($days as $d): ?>
                    <option value="<?= $d ?>"><?= $d ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3" style="display:flex; gap:12px;">
                <div style="flex:1;">
                    <label class="form-label" style="font-size:0.85rem; font-weight:600;">Start Time</label>
                    <input type="time" name="start_time" class="form-control" required>
                </div>
                <div style="flex:1;">
                    <label class="form-label" style="font-size:0.85rem; font-weight:600;">End Time</label>
                    <input type="time" name="end_time" class="form-control" required>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-reject"
                        onclick="document.getElementById('add-schedule-modal').style.display='none'">
                    Cancel
                </button>
                <button type="submit" class="btn-approve">
                    <i class="bi bi-plus-lg"></i> Add Schedule
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Close modal when clicking outside the box
document.getElementById('add-schedule-modal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>
</body>
</html>
