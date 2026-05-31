<?php
require_once '../../php/session_guard.php';
check_faculty();
require_once '../../php/db_connect.php';

$faculty_name = htmlspecialchars($_SESSION['faculty_name']);
$faculty_id = $_SESSION['faculty_id'];
$name_parts = explode(' ', $faculty_name);
$first_name = $name_parts[0];
$initials = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));

// Fetch full info
$faculty_email = '';
$faculty_last = '';
$faculty_first = '';
$stmt = $conn->prepare('SELECT first_name, last_name, email FROM faculty WHERE id = ?');
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$stmt->bind_result($faculty_first, $faculty_last, $faculty_email);
$stmt->fetch();
$stmt->close();

// Today's schedule
$today = date('l');
$schedules = [];
$r = $conn->query("
    SELECT s.start_time, s.end_time, c.room_name
    FROM schedules s JOIN classrooms c ON c.id = s.classroom_id
    WHERE s.day_of_week = '$today'
    ORDER BY s.start_time
");
while ($row = $r->fetch_assoc())
    $schedules[] = $row;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <title>Profile Settings – LumineSense</title>
</head>

<body class="contrast-bg">
    <div class="parent-container">

        <!-- TOPBAR -->
        <div class="topbar d-flex align-items-center justify-content-between">
            <div class="page-title">
                <button type="button" id="sidebarTrigger">
                    <i class="bi bi-list"></i>
                </button>
                <h1 class="bold">Profile Settings</h1>
            </div>
            <button class="light info-action-btn logout-btn ms-auto"
                onclick="dissolve('../../php/logout.php')">Logout</button>
        </div>

        <div class="child-container homepage-modal">
            <div class="profile-wrapper">
                <div class="profile-main-card">

                    <!-- Profile Header -->
                    <div class="profile-header">
                        <div class="profile-avatar avatar-icon"><?= $initials ?></div>
                        <div class="profile-user">
                            <h2 class="bold mb-1"><?= $faculty_name ?></h2>
                            <p class="light mb-0">Faculty Member</p>
                        </div>
                    </div>

                    <div class="profile-content row gx-4 gy-4">

                        <!-- Contact Info -->
                        <div class="col-xl-5 col-lg-6">
                            <div class="info-card">
                                <div class="info-card-header d-flex align-items-start justify-content-between">
                                    <h3 class="bold mb-0">Contact Information</h3>
                                </div>
                                <div class="info-field">
                                    <span class="label">Email</span>
                                    <div class="field-value"><?= htmlspecialchars($faculty_email) ?></div>
                                </div>
                            </div>

                            <!-- Change Password -->
                            <div class="info-card mt-3">
                                <div class="info-card-header">
                                    <h3 class="bold mb-3">Change Password</h3>
                                </div>
                                <?php if (!empty($_SESSION['pw_success'])): ?>
                                    <div class="alert alert-success">✅ <?= htmlspecialchars($_SESSION['pw_success']) ?>
                                    </div>
                                    <?php unset($_SESSION['pw_success']); ?>
                                <?php endif; ?>
                                <?php if (!empty($_SESSION['pw_error'])): ?>
                                    <div class="alert alert-danger">⚠️ <?= htmlspecialchars($_SESSION['pw_error']) ?></div>
                                    <?php unset($_SESSION['pw_error']); ?>
                                <?php endif; ?>
                                <form method="POST" action="../../php/change-password.php">
                                    <div class="mb-2">
                                        <label class="form-label">Current Password</label>
                                        <input type="password" class="form-control" name="current_password"
                                            placeholder="Current password" required>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label">New Password</label>
                                        <input type="password" class="form-control" name="new_password"
                                            placeholder="Min 8 characters" minlength="8" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Confirm New Password</label>
                                        <input type="password" class="form-control" name="confirm_password"
                                            placeholder="Repeat new password" required>
                                    </div>
                                    <button type="submit" class="light info-action-btn w-100">
                                        Save Password
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Schedule -->
                        <div class="col-xl-7 col-lg-6">
                            <div class="info-card schedule-card">
                                <div class="info-card-header d-flex align-items-start justify-content-between">
                                    <h3 class="bold mb-0">Today's Schedule</h3>
                                    <button class="light info-action-btn" onclick="dissolve('faculty-timetable.php')">
                                        See All
                                    </button>
                                </div>
                                <div class="schedule-list mt-4">
                                    <?php if (empty($schedules)): ?>
                                        <p class="text-muted">No classes today.</p>
                                    <?php else:
                                        foreach ($schedules as $s): ?>
                                            <div class="schedule-item">
                                                <div>
                                                    <p class="subject mb-1">
                                                        <?= htmlspecialchars($s['room_name']) ?>
                                                    </p>
                                                    <p class="light mb-0">
                                                        <?= date('g:i A', strtotime($s['start_time'])) ?>
                                                        – <?= date('g:i A', strtotime($s['end_time'])) ?>
                                                    </p>
                                                </div>
                                            </div>
                                        <?php endforeach; endif; ?>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <?php include '../../php/includes/faculty-sidebar.php'; ?>

        </div>

        <script src="../../script/animations.js"></script>
        <script src="../../script/toggles.js"></script>
    </div>

    <script>
        document.getElementById('sidebarTrigger').addEventListener('click', function () {
            bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('sidebarOffcanvas')).toggle();
        });
    </script>
</body>

</html>