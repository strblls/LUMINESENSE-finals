<?php
$phpRoot = realpath(__DIR__ . '/../../php');
if ($phpRoot === false) {
    die('Unable to resolve PHP root path.');
}
require_once $phpRoot . '/session_guard.php';
check_admin();
require_once $phpRoot . '/db_connect.php';

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

// Faculty list
$faculty_list = [];
$r = $conn->query("
    SELECT f.id, f.last_name, f.first_name, f.middle_initial,
           f.email, f.is_verified, f.approved_by, f.created_at,
           CONCAT(a.first_name,' ',a.last_name) AS approved_by_name,
           f.approved_at
    FROM faculty f
    LEFT JOIN admins a ON a.id = f.approved_by
    ORDER BY f.is_verified ASC, f.created_at ASC
");
while ($row = $r->fetch_assoc()) $faculty_list[] = $row;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard – LumineSense</title>

    <!-- Bootstrap and icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">

    <!-- Poppins font -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">

    <!-- Project CSS -->
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">

    <style>
        :root {
            --primary-color: #f9edfa;
            --secondary-color-1: #2f004f;
            --secondary-color-2: #58078f;
            --secondary-color-3: #790faf;
            --secondary-color-4: #9b00e9;
            --muted: #9f9f9f;
            --font-primary: 'Poppins', sans-serif;
        }

        .stat-row {
            display: flex;
            flex-direction: row;
            gap: 0.75rem;
            width: 100%;
        }
        .stat-card {
            flex: 1 1 0;
            display: flex;
            align-items: center;
            gap: 0.9rem;
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 1rem 1rem;
        }
        .stat-card .stat-icon { font-size: 2rem; line-height: 1; flex-shrink: 0; }
        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
            color: var(--secondary-color-1);
        }
        .stat-card .stat-label {
            font-size: 0.72rem;
            color: var(--muted);
            margin: 0;
            line-height: 1.3;
            font-family: var(--font-primary);
        }

        .room-list {
            max-height: 38vh;
            overflow-y: auto;
            padding-right: 0.25rem;
        }
        .room-item {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.5rem 0.25rem;
            border-bottom: 1px solid var(--secondary-color-1);
        }
        .room-item:last-child { border-bottom: none; }
        .room-icon { font-size: 1.8rem; color: var(--secondary-color-2); flex-shrink: 0; }
        .room-info { flex: 1; min-width: 0; }
        .room-info h5 { margin: 0; font-size: 15px; font-weight: 600; }
        .room-info p  { margin: 0; font-size: 11px; font-weight: 450; color: var(--muted); }
    </style>
</head>

<body class="contrast-bg">
    <div class="parent-container">

        <!-- ══ TOPBAR — identical structure to faculty ══ -->
        <div class="topbar d-flex">
            <button type="button" id="sidebarTrigger">
                <i class="bi bi-list"></i>
            </button>
            <div class="col d-flex flex-column px-3">
                <h1 class="bold">Welcome, <?= $admin_name ?>!</h1>
                <h5 class="light">Administrator</h5>
            </div>
            <div class="d-flex align-items-center justify-content-center gap-2 mx-2">
                <h4><?= explode(' ', $admin_name)[0] ?></h4>
                <div class="avatar-icon d-flex align-items-center justify-content-center" id="sidebarTrigger2">
                    <h3 class="bold"><?= strtoupper(substr($admin_name, 0, 1)) ?></h3>
                </div>
            </div>
        </div>

        <div class="child-container">
            <div class="main-container homepage gap-3">

                <!-- ══ LEFT COLUMN ══ -->
                <div class="group-container gap-3">

                    <!-- Stat summary -->
                    <div style="background-color:#f8f9fa;" class="section-container">
                        <div class="stat-row">
                            <div class="stat-card">
                                <span class="stat-icon"><img src="../../images/room.png" alt="Rooms"></span>
                                <div>
                                    <div class="stat-value"><?= $total_rooms ?></div>
                                    <p class="stat-label">Rooms<br>Active</p>
                                </div>
                            </div>
                            <div class="stat-card">
                                <span class="stat-icon"><img src="../../images/bulb.png" alt="Lights"></span>
                                <div>
                                    <div class="stat-value"><?= $lights_on ?></div>
                                    <p class="stat-label">Rooms Currently<br>Running</p>
                                </div>
                            </div>
                            <div class="stat-card">
                                <span class="stat-icon"><img src="../../images/alert.png" alt="Alerts"></span>
                                <div>
                                    <div class="stat-value"><?= $pending ?></div>
                                    <p class="stat-label">Actions to<br>be Resolved</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Rooms list -->
                    <div style="background-color:#f8f9fa;" class="fit-width section-container">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Rooms</h2>
                            </div>
                            <div class="d-flex mx-2 align-items-end">
                                <button class="light mx-2">All Rooms</button>
                            </div>
                        </div>
                        <div class="room-list px-1 mt-1">
                            <div class="room-item">
                                <i class="bi bi-building room-icon"></i>
                                <div class="room-info">
                                    <h5>Room 3A-B Grade 12 Newton</h5>
                                    <p>Room Status: <strong>Occupied</strong> &nbsp;·&nbsp; Lighting: Off &nbsp;·&nbsp; Mode: Default</p>
                                </div>
                                <button class="light">View Room</button>
                            </div>
                            <div class="room-item">
                                <i class="bi bi-building room-icon"></i>
                                <div class="room-info">
                                    <h5>Room 1B-B Grade 11 Torvalds</h5>
                                    <p>Room Status: <strong>Vacant</strong> &nbsp;·&nbsp; Lighting: Off &nbsp;·&nbsp; Mode: Default</p>
                                </div>
                                <button class="light">View Room</button>
                            </div>
                            <div class="room-item">
                                <i class="bi bi-building room-icon"></i>
                                <div class="room-info">
                                    <h5>Room 3A-C Grade 10 Newton</h5>
                                    <p>Room Status: <strong>Vacant</strong> &nbsp;·&nbsp; Lighting: Off &nbsp;·&nbsp; Mode: Scheduled</p>
                                </div>
                                <button class="light">View Room</button>
                            </div>
                            <div class="room-item">
                                <i class="bi bi-building room-icon"></i>
                                <div class="room-info">
                                    <h5>Materials Lab</h5>
                                    <p>Room Status: <strong>Vacant</strong> &nbsp;·&nbsp; Lighting: Off &nbsp;·&nbsp; Mode: Scheduled</p>
                                </div>
                                <button class="light">View Room</button>
                            </div>
                        </div>
                    </div>

                    <!-- FACULTY TAB -->
        <div id="tab-faculty" class="tab-section">
            <div style="background-color:#f8f9fa;" class="section-container">
                <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                    <div class="d-flex mx-2 align-items-start"><h2 class="bold">Faculty Accounts</h2></div>
                    <div class="d-flex mx-2 gap-2">
                        <button class="light" onclick="filterFaculty('all')">All</button>
                        <button class="light" onclick="filterFaculty('pending')">Pending</button>
                        <button class="light" onclick="filterFaculty('verified')">Approved</button>
                    </div>
                </div>

                <div id="faculty-list" class="mt-2" style="display:flex;flex-direction:column;gap:0.6rem;">
                    <?php foreach ($faculty_list as $f): ?>
                        <?php
                            $fullname  = htmlspecialchars($f['first_name'] . ' ' . $f['last_name']);
                            $initial   = strtoupper(substr($f['first_name'], 0, 1));
                            $email     = htmlspecialchars($f['email']);
                            $approved  = $f['approved_by'] !== null;
                            $status    = $approved ? 'verified' : 'pending';
                            $badge     = $approved
                                ? '<span class="badge-verified">✔ Approved</span>'
                                : '<span class="badge-pending">⏳ Pending</span>';
                            $approver  = $approved
                                ? 'Approved by: ' . htmlspecialchars($f['approved_by_name'])
                                : 'Waiting for approval';
                        ?>
                        <div class="faculty-card" data-status="<?= $status ?>" data-id="<?= $f['id'] ?>">
                            <div class="faculty-avatar"><?= $initial ?></div>
                            <div class="faculty-info">
                                <h5><?= $fullname ?> <?= $badge ?></h5>
                                <p><?= $email ?> &nbsp;·&nbsp; <?= $approver ?></p>
                            </div>
                            <div class="d-flex gap-2">
                                <?php if (!$approved): ?>
                                    <button class="light" onclick="handleFaculty(<?= $f['id'] ?>, 'approve', this)">Approve</button>
                                    <button class="light" onclick="handleFaculty(<?= $f['id'] ?>, 'reject', this)">Reject</button>
                                <?php else: ?>
                                    <button class="light" onclick="handleFaculty(<?= $f['id'] ?>, 'revoke', this)">Revoke</button>
                                <?php endif; ?>
                                <button class="light" onclick="handleFaculty(<?= $f['id'] ?>, 'delete', this)">Delete</button>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (empty($faculty_list)): ?>
                        <p class="text-center text-muted mt-3">No faculty accounts yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div><!-- /tab-faculty -->

                </div><!-- /LEFT COLUMN -->

                <!-- ══ RIGHT COLUMN ══ -->
                <div class="group-container gap-3">

                    <div style="background-color:#f8f9fa;" class="section-container recents">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Alerts</h2>
                            </div>
                            <div class="d-flex mx-2 align-items-end">
                                <button class="light mx-2">Details</button>
                            </div>
                        </div>
                        <div class="gap-2">
                            <div class="activity-list px-2 gap-2 align-items-center max-width">
                                <div>
                                    <h5>Extension – Faculty Request</h5>
                                    <p class="light mb-0">Room 2B-C Grade 9 Lovelace</p>
                                </div>
                                <hr>
                                <div>
                                    <h5>Classes Started</h5>
                                    <p class="light mb-0">Room 2B – Grade 9 Lovelace</p>
                                </div>
                                <hr>
                                <div>
                                    <h5>Classes Ended</h5>
                                    <p class="light mb-0">Room 1A-C Grade 10 Fleming</p>
                                </div>
                                <hr>
                                <div>
                                    <h5>Classes Ended</h5>
                                    <p class="light mb-0">Room 1A-C Grade 10 Fleming</p>
                                </div>
                                <hr>
                                <div>
                                    <h5>Extension – Faculty Request</h5>
                                    <p class="light mb-0">Room 3A-B Grade 12 Newton</p>
                                </div>
                                <hr>
                            </div>
                        </div>
                    </div>

                    <div style="background-color:#f8f9fa;" class="section-container">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">System Status</h2>
                            </div>
                        </div>
                        <div class="gap-2">
                            <div class="activity-list px-2 gap-2 align-items-center max-width">
                                <h5>Lighting: Disconnected</h5>
                                <h5>Server: Connected</h5>
                                <h5>Webcam: Disabled</h5>
                                <h5>Sensor Reading: Disconnected</h5>
                                <h5>System Uptime: 00:00:00</h5>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- ══ SIDEBAR OFFCANVAS (left) ══ -->
                <div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas" aria-labelledby="sidebarOffcanvasLabel">
                    <div class="offcanvas-header justify-content-center">
                        <img src="../../images/logo.png" class="logo" onclick="dissolve('admin-homepage.php')">
                    </div>
                    <div class="offcanvas-body align-items-center d-flex flex-column">
                        <button class="wb-2" onclick="showTab('home')">
                            <i class="bi bi-building"></i>
                        </button>
                        <button class="wb-2" onclick="showTab('faculty')">
                            <i class="bi bi-people"></i>
                        </button>
                        <button class="wb-2" onclick="dissolve('admin-homepage.php')">
                            <i class="bi bi-bar-chart-line"></i>
                        </button>
                        <button class="wb-2" onclick="dissolve('admin-homepage.php')">
                            <i class="bi bi-file-earmark-text"></i>
                        </button>
                        <button class="wb-2" onclick="dissolve('admin-homepage.php')">
                            <i class="bi bi-gear"></i>
                        </button>
                    </div>
                    <div class="offcanvas-footer">
                        <img src="../../images/team-logo.png" class="logo">
                    </div>
                </div>

                <!-- ══ PROFILE OFFCANVAS (right) ══ -->
                <div class="offcanvas offcanvas-end" tabindex="-1" id="profileOffcanvas" aria-labelledby="profileOffcanvasLabel">
                    <div class="offcanvas-body align-items-center d-flex flex-column">
                        <div class="avatar-icon d-flex align-items-center justify-content-center">
                            <h3 class="bold"><?= strtoupper(substr($admin_name, 0, 1)) ?></h3>
                        </div>
                        <h4 class="bold"><?= $admin_name ?></h4>
                        <h6 class="light email-limit"><?= $_SESSION['admin_email'] ?? 'Administrator' ?></h6>
                        <div class="d-flex flex-column align-items-center justify-content-center">
                            <button class="light" onclick="dissolve('../../php/logout.php')">Logout</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>
    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>

    <script>
        document.getElementById('sidebarTrigger').addEventListener('click', function () {
            bootstrap.Offcanvas.getOrCreateInstance(
                document.getElementById('sidebarOffcanvas')
            ).toggle();
        });
        document.getElementById('sidebarTrigger2').addEventListener('click', function () {
            bootstrap.Offcanvas.getOrCreateInstance(
                document.getElementById('profileOffcanvas')
            ).toggle();
        });

                // Tab switching
        function showTab(name) {
            document.querySelectorAll('.tab-section').forEach(t => t.classList.remove('active'));
            const tab = document.getElementById('tab-' + name);
            if (tab) tab.classList.add('active');
            bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('sidebarOffcanvas')).hide();
        }

        // Filter faculty by status
        function filterFaculty(status) {
            document.querySelectorAll('#faculty-list .faculty-card').forEach(card => {
                card.style.display = (status === 'all' || card.dataset.status === status) ? 'flex' : 'none';
            });
        }

        // Approve / reject / revoke / delete
        function handleFaculty(id, action, btn) {
            const labels = { approve: 'Approve', reject: 'Reject', revoke: 'Revoke', delete: 'Delete' };
            if (!confirm(`${labels[action]} this faculty account?`)) return;

            const form = new FormData();
            form.append('action', action);
            form.append('faculty_id', id);

            fetch('../../api/accounts.php', { method: 'POST', body: form })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        // Reload just the faculty card area
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                });
        }
    </script>

</body>

</html>
