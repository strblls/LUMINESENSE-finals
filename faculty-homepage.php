<?php
require_once dirname(__DIR__, 2) . '/session_guard.php';
check_faculty();
require_once dirname(__DIR__, 2) . '/db_connect.php';

$faculty_name = htmlspecialchars($_SESSION['faculty_name']);

// Faculty sees: classrooms + their current schedule + light status
$classrooms = [];
$r = $conn->query("
    SELECT c.id, c.room_name, c.room_size,
           COALESCE(l.event_type,'off') AS light_status
    FROM classrooms c
    LEFT JOIN lighting_logs l ON l.id=(SELECT MAX(id) FROM lighting_logs WHERE classroom_id=c.id)
    ORDER BY c.room_name
");
while ($row = $r->fetch_assoc()) $classrooms[] = $row;

// Today's schedule
$today = date('l');
$schedules = [];
$r = $conn->query("
    SELECT s.start_time, s.end_time, c.room_name
    FROM schedules s JOIN classrooms c ON c.id=s.classroom_id
    WHERE s.day_of_week='$today'
    ORDER BY s.start_time
");
while ($row = $r->fetch_assoc()) $schedules[] = $row;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Faculty Dashboard – LumineSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark px-4">
    <span class="navbar-brand fw-bold">LumineSense</span>
    <div class="d-flex align-items-center gap-3">
        <span class="text-white small">👤 <?= $faculty_name ?></span>
        <a href="../../php/logout.php" class="btn btn-sm btn-outline-light">Logout</a>
    </div>
</nav>

<div class="container py-4">
    <h5 class="fw-bold mb-1">Good day, <?= $faculty_name ?>!</h5>
    <p class="text-muted small mb-4">Today is <?= date('l, F j, Y') ?></p>

    <!-- Classroom Status -->
    <h6 class="fw-bold mb-2">Classroom Lights</h6>
    <div class="row g-3 mb-4">
        <?php if (empty($classrooms)): ?>
        <p class="text-muted">No classrooms configured yet.</p>
        <?php else: foreach ($classrooms as $c):
            $on = ($c['light_status'] === 'on'); ?>
        <div class="col-sm-6 col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-bold"><?= htmlspecialchars($c['room_name']) ?></div>
                        <small class="text-muted"><?= ucfirst($c['room_size']) ?></small>
                    </div>
                    <span class="badge <?= $on ? 'bg-success' : 'bg-secondary' ?> fs-6">
                        <i class="bi bi-lightbulb<?= $on ? '-fill' : '' ?>"></i>
                        <?= $on ? 'ON' : 'OFF' ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- Today's Schedule -->
    <h6 class="fw-bold mb-2">Today's Schedule (<?= $today ?>)</h6>
    <?php if (empty($schedules)): ?>
    <p class="text-muted">No classes scheduled today.</p>
    <?php else: ?>
    <table class="table table-bordered table-sm bg-white shadow-sm">
        <thead class="table-dark">
            <tr><th>Room</th><th>Start</th><th>End</th></tr>
        </thead>
        <tbody>
            <?php foreach ($schedules as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['room_name']) ?></td>
                <td><?= date('g:i A', strtotime($s['start_time'])) ?></td>
                <td><?= date('g:i A', strtotime($s['end_time'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Gesture Control note -->
    <div class="alert alert-info mt-3">
        <i class="bi bi-hand-index-thumb"></i>
        <strong>Gesture Control</strong> — Use hand gestures in front of the classroom webcam to control lights.
        The webcam feed and gesture recognition runs on the physical prototype device.
    </div>
</div>

<script>
setTimeout(() => location.reload(), 30000);
</script>
</body>
</html>
