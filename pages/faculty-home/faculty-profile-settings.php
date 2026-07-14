<?php
require_once '../../php/session_guard.php';
check_faculty();
require_once '../../php/db_connect.php';

$faculty_name = htmlspecialchars($_SESSION['faculty_name']);
$faculty_id = $_SESSION['faculty_id'];
$is_head = $_SESSION['is_head'] ?? false;
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

// Fetch departments
$departments = [];
$r = $conn->query("
    SELECT d.name FROM departments d
    JOIN junction_faculty_department jfd ON jfd.department_id = d.id
    WHERE jfd.faculty_id = $faculty_id
");
while ($row = $r->fetch_assoc())
    $departments[] = $row['name'];

// Fetch subjects
$subjects = [];
$r = $conn->query("
    SELECT s.name FROM subjects s
    JOIN junction_faculty_subject jfs ON jfs.subject_id = s.id
    WHERE jfs.faculty_id = $faculty_id
");
while ($row = $r->fetch_assoc())
    $subjects[] = $row['name'];

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

// — PIN status —
$has_pin = false;
$stmt = $conn->prepare("SELECT 1 FROM faculty_permissions WHERE faculty_id = ? AND pin_hash IS NOT NULL");
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$stmt->bind_result($pin_exists);
$has_pin = (bool)$stmt->fetch();
$stmt->close();

// — Active schedule end time (with extension) for audio notifications —
$now = date('H:i:s');
$active_schedule_end = '';
$stmt = $conn->prepare("
    SELECT COALESCE(s.extended_until, s.end_time) AS end_time
    FROM schedules s
    WHERE s.faculty_id = ?
      AND s.day_of_week = ?
      AND s.start_time <= ?
      AND (s.extended_until >= ? OR (s.extended_until IS NULL AND s.end_time >= ?))
    ORDER BY s.start_time
    LIMIT 1
");
$stmt->bind_param('issss', $faculty_id, $today, $now, $now, $now);
$stmt->execute();
$stmt->bind_result($active_schedule_end);
$stmt->fetch();
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <!--External links-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>

    <!--Relative links-->
    <link rel="icon" href="../../images/logo.png">   
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">
    <link rel="stylesheet" href="../../css/faculty-common.css">
    <link rel="stylesheet" href="../../css/faculty-settings.css">

    <title>Profile Settings â€“ LumineSense</title>
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
                    <div class="profile-layout">
                        <!-- Sidebar -->
                        <div class="profile-sidebar">
                            <div class="sidebar-item active" data-section="contact">
                                <i class="bi bi-person-lines-fill"></i> Contact Information
                            </div>
                            <div class="sidebar-item" data-section="teaching">
                                <i class="bi bi-book"></i> Teaching Coverage
                            </div>
                            <div class="sidebar-item" data-section="credentials">
                                <i class="bi bi-shield-lock"></i> Change Credentials
                            </div>
                            <div class="sidebar-item" data-section="about">
                                <i class="bi bi-info-circle"></i> About System
                            </div>
                        </div>

                        <!-- Content Area -->
                        <div class="profile-content-area">

                            <!-- Contact Section (default) -->
                            <div id="section-contact" class="section-content active">
                                <div class="profile-header">
                                    <div class="profile-avatar avatar-icon"><?= $initials ?></div>
                                    <div class="profile-user">
                                        <h2 class="bold mb-1"><?= $faculty_name ?></h2>
                                        <?php if ($is_head): ?>
                                            <span class="bold status-badge faculty-head">Faculty Head</span>
                                        <?php else: ?>
                                            <span class="bold status-badge faculty-member">Faculty Member</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <hr>
                                <div class="info-card">
                                    <div class="info-card-header d-flex align-items-start justify-content-between">
                                        <h3 class="bold mb-0">Contact Information</h3>
                                    </div>
                                    <div class="info-field">
                                        <span class="label">Email</span>
                                        <div class="field-value"><?= htmlspecialchars($faculty_email) ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Teaching Coverage Section -->
                            <div id="section-teaching" class="section-content">
                                <div class="info-card">
                                    <div class="info-card-header">
                                        <h3 class="bold mb-0">Department &amp; Subjects</h3>
                                    </div>
                                    <div class="info-field">
                                        <span class="label">Department</span>
                                        <div class="field-value">
                                            <?php if (!empty($departments)): ?>
                                                <?= htmlspecialchars(implode(', ', $departments)) ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not assigned</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="info-field">
                                        <span class="label">Subjects Handled</span>
                                        <div class="field-value">
                                            <?php if (!empty($subjects)): ?>
                                                <?= htmlspecialchars(implode(', ', $subjects)) ?>
                                            <?php else: ?>
                                                <span class="text-muted">None</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="info-card mt-3">
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
                                                            &ndash; <?= date('g:i A', strtotime($s['end_time'])) ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            <?php endforeach; endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Change Credentials Section -->
                            <div id="section-credentials" class="section-content">
                                <div class="info-card">
                                    <div class="info-card-header">
                                        <h3 class="bold mb-3">Change Password</h3>
                                    </div>
                                    <?php if (!empty($_SESSION['pw_success'])): ?>
                                        <div class="alert alert-success">&#9989; <?= htmlspecialchars($_SESSION['pw_success']) ?>
                                        </div>
                                        <?php unset($_SESSION['pw_success']); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($_SESSION['pw_error'])): ?>
                                        <div class="alert alert-danger">&#9888;&#65039; <?= htmlspecialchars($_SESSION['pw_error']) ?></div>
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

                                <div class="info-card mt-3">
                                    <div class="info-card-header">
                                        <h3 class="bold mb-3">PIN Settings</h3>
                                    </div>
                                    <div id="pinFeedback" class="d-none"></div>
                                    <div id="pinForm">
                                        <div class="mb-2">
                                            <?php if ($has_pin): ?>
                                                <label class="form-label">Current PIN</label>
                                                <input type="password" class="form-control" id="oldPinInput"
                                                    maxlength="4" pattern="\d*" inputmode="numeric" placeholder="Current 4-digit PIN">
                                            <?php endif; ?>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label"><?= $has_pin ? 'New' : 'Set' ?> PIN</label>
                                            <input type="password" class="form-control" id="newPinInput"
                                                maxlength="4" pattern="\d*" inputmode="numeric" placeholder="4-digit PIN">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Confirm PIN</label>
                                            <input type="password" class="form-control" id="confirmPinInput"
                                                maxlength="4" pattern="\d*" inputmode="numeric" placeholder="Repeat PIN">
                                        </div>
                                        <button type="button" class="light info-action-btn w-100" id="savePinBtn">
                                            <?= $has_pin ? 'Change PIN' : 'Set PIN' ?>
                                        </button>
                                    </div>
                                </div>
                                <script>
                                (function() {
                                    var feedback = document.getElementById('pinFeedback');
                                    var form = document.getElementById('pinForm');
                                    var newPin = document.getElementById('newPinInput');
                                    var confirmPin = document.getElementById('confirmPinInput');
                                    var btn = document.getElementById('savePinBtn');
                                    var hasPin = <?= json_encode($has_pin) ?>;
                                    var oldPin = hasPin ? document.getElementById('oldPinInput') : null;

                                    function showMsg(msg, isError) {
                                        feedback.className = isError ? 'alert alert-danger' : 'alert alert-success';
                                        feedback.textContent = msg;
                                    }

                                    btn.addEventListener('click', function() {
                                        var pin = newPin.value;
                                        if (!/^\d{4}$/.test(pin)) {
                                            showMsg('PIN must be exactly 4 digits.', true);
                                            return;
                                        }
                                        if (pin !== confirmPin.value) {
                                            showMsg('PINs do not match.', true);
                                            return;
                                        }
                                        btn.disabled = true;
                                        btn.textContent = 'Saving\u2026';
                                        var body = hasPin
                                            ? JSON.stringify({action: 'change', pin: pin, old_pin: oldPin.value})
                                            : JSON.stringify({action: 'save', pin: pin});
                                        fetch('../../api/pin.php', {
                                            method: 'POST',
                                            headers: {'Content-Type': 'application/json'},
                                            body: body
                                        }).then(function(r){ return r.json(); }).then(function(d){
                                            if (d.success) {
                                                showMsg(d.message, false);
                                                if (!hasPin) {
                                                    btn.textContent = 'Change PIN';
                                                    hasPin = true;
                                                    var lbl = document.querySelector('#pinForm .mb-2');
                                                    if (lbl && !oldPin) {
                                                        var html = '<label class="form-label">Current PIN</label>' +
                                                            '<input type="password" class="form-control" id="oldPinInput" ' +
                                                            'maxlength="4" pattern="\\d*" inputmode="numeric" placeholder="Current 4-digit PIN">';
                                                        var div = document.createElement('div');
                                                        div.className = 'mb-2';
                                                        div.innerHTML = html;
                                                        lbl.parentNode.insertBefore(div, lbl);
                                                        oldPin = document.getElementById('oldPinInput');
                                                    }
                                                }
                                                btn.disabled = false;
                                                btn.textContent = 'Change PIN';
                                                newPin.value = '';
                                                confirmPin.value = '';
                                                if (oldPin) oldPin.value = '';
                                            } else {
                                                showMsg(d.message, true);
                                                btn.disabled = false;
                                                btn.textContent = hasPin ? 'Change PIN' : 'Set PIN';
                                            }
                                        }).catch(function(){
                                            showMsg('Network error.', true);
                                            btn.disabled = false;
                                            btn.textContent = hasPin ? 'Change PIN' : 'Set PIN';
                                        });
                                    });
                                })();
                                </script>
                            </div>

                            <!-- About System Section -->
                            <div id="section-about" class="section-content">
                                <div class="info-card d-flex flex-column align-items-center text-center py-5">
                                    <img src="../../images/logo.png" alt="LumineSense Logo" style="max-width:200px;margin-bottom:1.5rem;">
                                    <h3 class="bold mb-1">LumineSense</h3>
                                    <p class="text-muted mb-4" style="font-size:14px;">Smart Classroom Management System</p>
                                    <img src="../../images/team-logo.png" alt="Team Logo" style="max-width:120px;margin-bottom:0.75rem;">
                                    <p class="text-muted mb-0" style="font-size:12px;">All Rights Reserved &copy; <?= date('Y') ?></p>
                                </div>
                                <div class="info-card mt-3">
                                    <div class="info-card-header">
                                        <h3 class="bold mb-0"><i class="bi bi-sliders me-2"></i>Preferences</h3>
                                    </div>
                                    <div class="info-field d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="label mb-0">Help Icon (Page Tutorials)</span>
                                            <p class="text-muted small mb-0">Show the help icon on faculty pages to access step-by-step tutorials.</p>
                                        </div>
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox" role="switch" id="tutorialToggle" checked>
                                        </div>
                                    </div>
                                </div>
                                <script>
                                (function() {
                                    var toggle = document.getElementById('tutorialToggle');
                                    var key = 'lum_tutorial_disabled';
                                    toggle.checked = localStorage.getItem(key) !== '1';
                                    toggle.addEventListener('change', function() {
                                        if (this.checked) {
                                            localStorage.removeItem(key);
                                        } else {
                                            localStorage.setItem(key, '1');
                                        }
                                    });
                                })();
                                </script>
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
    document.addEventListener('DOMContentLoaded', function() {
        var sidebarItems = document.querySelectorAll('.profile-sidebar .sidebar-item');
        var sections = {
            contact: document.getElementById('section-contact'),
            teaching: document.getElementById('section-teaching'),
            credentials: document.getElementById('section-credentials'),
            about: document.getElementById('section-about')
        };

        sidebarItems.forEach(function(item) {
            item.addEventListener('click', function() {
                var section = this.getAttribute('data-section');
                if (!section || !sections[section]) return;

                sidebarItems.forEach(function(si) { si.classList.remove('active'); });
                this.classList.add('active');

                Object.keys(sections).forEach(function(key) {
                    sections[key].classList.remove('active');
                });
                sections[section].classList.add('active');
            });
        });
    });
    </script>

</body>

</html>
