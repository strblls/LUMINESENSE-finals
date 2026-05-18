<?php
$phpRoot = realpath(__DIR__ . '/../../php');
require_once $phpRoot . '/session_guard.php';
check_admin();
require_once $phpRoot . '/db_connect.php';

$admin_name = htmlspecialchars($_SESSION['admin_name']);
$name_parts = explode(' ', $admin_name);
$initials   = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));

// Fetch admin email
$admin_email = '';
$stmt = $conn->prepare('SELECT email FROM admins WHERE id = ?');
$stmt->bind_param('i', $_SESSION['admin_id']);
$stmt->execute();
$stmt->bind_result($admin_email);
$stmt->fetch();
$stmt->close();

// Summary counts
$total_rooms  = $conn->query("SELECT COUNT(*) AS c FROM classrooms")->fetch_assoc()['c'];
$lights_on    = $conn->query("SELECT COUNT(*) AS c FROM lighting_logs l WHERE l.id IN (SELECT MAX(id) FROM lighting_logs GROUP BY classroom_id) AND l.event_type='on'")->fetch_assoc()['c'];
$pending      = $conn->query("SELECT COUNT(*) AS c FROM faculty WHERE is_verified=1 AND approved_by IS NULL")->fetch_assoc()['c'];
$alerts_today = $conn->query("SELECT COUNT(*) AS c FROM lighting_logs WHERE event_type='security_alert' AND DATE(event_time)=CURDATE()")->fetch_assoc()['c'];

// Recent logs
$logs = [];
$r = $conn->query("
    SELECT l.event_type, l.triggered_by, l.event_time, c.room_name
    FROM lighting_logs l JOIN classrooms c ON c.id = l.classroom_id
    ORDER BY l.event_time DESC LIMIT 6
");
while ($row = $r->fetch_assoc()) $logs[] = $row;

// Classrooms
$classrooms = [];
$r = $conn->query("
    SELECT c.id, c.room_name, c.room_size,
           COALESCE(l.event_type,'off') AS light_status
    FROM classrooms c
    LEFT JOIN lighting_logs l ON l.id = (SELECT MAX(id) FROM lighting_logs WHERE classroom_id = c.id)
    ORDER BY c.room_name
");
while ($row = $r->fetch_assoc()) $classrooms[] = $row;

// Extension requests count
$ext_pending = $conn->query("SELECT COUNT(*) AS c FROM extension_requests WHERE status='pending'")->fetch_assoc()['c'];

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
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
        .stat-row { display:flex; flex-direction:row; gap:0.75rem; width:100%; }
        .stat-card {
            flex: 1 1 0; display:flex; align-items:center; gap:0.9rem;
            background:#f8f9fa; border-radius:10px; padding:1rem;
        }
        .stat-card .stat-value { font-size:2rem; font-weight:700; line-height:1; color:var(--secondary-color-1); }
        .stat-card .stat-label { font-size:0.72rem; color:var(--muted); margin:0; line-height:1.3; }
        .room-list { max-height:38vh; overflow-y:auto; padding-right:0.25rem; }
        .room-item {
            display:flex; align-items:center; gap:0.7rem;
            padding:0.5rem 0.25rem; border-bottom:1px solid #eee;
        }
        .room-item:last-child { border-bottom:none; }
        .room-icon { font-size:1.8rem; color:var(--secondary-color-2); flex-shrink:0; }
        .room-info { flex:1; min-width:0; }
        .room-info h5 { margin:0; font-size:15px; font-weight:600; }
        .room-info p  { margin:0; font-size:11px; color:var(--muted); }

        /* nav-btn sidebar style from HTML version */
        .nav-btn {
            width:52px; height:52px; border-radius:12px;
            display:flex; align-items:center; justify-content:center;
            background-color:var(--secondary-color-1); color:var(--primary-color);
            border:none; cursor:pointer;
            transition:background-color 0.2s, transform 0.15s;
        }
        .nav-btn i, .nav-btn svg { font-size:22px; }
        .nav-btn:hover { background-color:var(--secondary-color-4); transform:scale(1.06); }

        #sidebarOffcanvas { width:100px !important; background-color:var(--primary-color); }
        #sidebarOffcanvas .offcanvas-header { justify-content:center; padding:1rem 0.5rem; }
        #sidebarOffcanvas .logo { width:75px; height:75px; object-fit:contain; cursor:pointer; }
        #sidebarOffcanvas .offcanvas-body { display:flex; flex-direction:column; align-items:center; gap:8px; padding-top:0.5rem; }
        #sidebarOffcanvas .offcanvas-footer { display:flex; justify-content:center; padding:1rem; }

        #profileOffcanvas { width:240px !important; background-color:var(--primary-color); }
        .profile-btn {
            width:100%; padding:8px; margin:3px 0; border-radius:8px;
            background-color:var(--secondary-color-1); color:var(--primary-color);
            border:none; font-size:14px; cursor:pointer; font-family:var(--font-primary);
            transition:background-color 0.2s, transform 0.15s;
        }
        .profile-btn:hover { background-color:var(--secondary-color-4); transform:scale(1.02); }

        .badge-pending  { background:#fff3cd; color:#856404; padding:3px 10px; border-radius:20px; font-size:11px; }
        .badge-verified { background:#d1e7dd; color:#0f5132; padding:3px 10px; border-radius:20px; font-size:11px; }
        .ext-badge { background:#cfe2ff; color:#084298; padding:3px 10px; border-radius:20px; font-size:11px; }
    </style>
</head>

<body class="contrast-bg">
<div class="parent-container">

    <!-- TOPBAR -->
    <div class="topbar d-flex" style="background: linear-gradient(0deg, rgba(255,255,255,0) 9%, rgba(47,0,79,0.76) 40%, rgba(47,0,79,0.95) 70%, rgba(47,0,79,1) 100%);">
        <button type="button" id="sidebarTrigger" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas">
            <i class="bi bi-list"></i>
        </button>
        <div class="col d-flex flex-column px-3">
            <h1 class="bold">Welcome, <?= explode(' ', $admin_name)[0] ?>!</h1>
            <h5 class="light">Administrator</h5>
        </div>
        <div class="d-flex align-items-center justify-content-center gap-3 mx-2">
            <h4><?= explode(' ', $admin_name)[0] ?></h4>
            <div class="avatar-icon d-flex align-items-center justify-content-center"
                 id="sidebarTrigger2" data-bs-toggle="offcanvas" data-bs-target="#profileOffcanvas">
                <h3 class="bold"><?= $initials ?></h3>
            </div>
        </div>
    </div>

    <div class="child-container">
        <div class="main-container homepage gap-3">

            <!-- LEFT COLUMN -->
            <div class="group-container gap-3">

                <!-- Stat cards -->
                <div style="background-color:#f8f9fa;" class="section-container">
                    <div class="stat-row">
                        <div class="stat-card">
                            <span class="stat-icon"><img src="../../images/room.png" alt="Rooms" style="width:2rem;"></span>
                            <div>
                                <div class="stat-value"><?= $total_rooms ?></div>
                                <p class="stat-label">Rooms<br>Active</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <span class="stat-icon"><img src="../../images/bulb.png" alt="Lights" style="width:2rem;"></span>
                            <div>
                                <div class="stat-value"><?= $lights_on ?></div>
                                <p class="stat-label">Rooms Currently<br>Running</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <span class="stat-icon"><img src="../../images/alert.png" alt="Pending" style="width:2rem;"></span>
                            <div>
                                <div class="stat-value"><?= $pending ?></div>
                                <p class="stat-label">Faculty Pending<br>Approval</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <span class="stat-icon"><i class="bi bi-clock-history" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                            <div>
                                <div class="stat-value"><?= $ext_pending ?></div>
                                <p class="stat-label">Extension<br>Requests</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Rooms list -->
                <div style="background-color:#f8f9fa;" class="fit-width section-container">
                    <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                        <div class="d-flex mx-2 align-items-start"><h2 class="bold">Rooms</h2></div>
                        <div class="d-flex mx-2 align-items-end">
                            <button class="light mx-2" onclick="dissolve('admin-room-manage.php')">All Rooms</button>
                        </div>
                    </div>
                    <div class="room-list px-1 mt-1">
                        <?php if (empty($classrooms)): ?>
                            <p class="text-muted text-center mt-2">No classrooms yet.</p>
                        <?php else: foreach ($classrooms as $c):
                            $on = ($c['light_status'] === 'on'); ?>
                            <div class="room-item">
                                <i class="bi bi-building room-icon"></i>
                                <div class="room-info">
                                    <h5><?= htmlspecialchars($c['room_name']) ?></h5>
                                    <p>Size: <?= ucfirst($c['room_size']) ?> &nbsp;·&nbsp;
                                       Lighting: <strong><?= $on ? 'ON' : 'Off' ?></strong>
                                    </p>
                                </div>
                                <button class="light" onclick="dissolve('admin-room-manage.php')">View</button>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

            </div><!-- /LEFT COLUMN -->

            <!-- RIGHT COLUMN -->
            <div class="group-container gap-3">

                <!-- Alerts / Recent logs -->
                <div style="background-color:#f8f9fa;" class="section-container recents">
                    <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                        <div class="d-flex mx-2 align-items-start"><h2 class="bold">Recent Activity</h2></div>
                        <div class="d-flex mx-2 align-items-end">
                            <button class="light mx-2" onclick="dissolve('admin-reports.php')">Details</button>
                        </div>
                    </div>
                    <div class="gap-2">
                        <div class="activity-list px-2 gap-2 align-items-center max-width">
                            <?php if (empty($logs)): ?>
                                <p class="text-muted">No recent activity.</p>
                            <?php else: foreach ($logs as $log): ?>
                                <div>
                                    <h5><?= ucfirst($log['event_type']) ?> – <?= htmlspecialchars($log['room_name']) ?></h5>
                                    <p class="light mb-0"><?= date('g:i A', strtotime($log['event_time'])) ?> · <?= date('M j', strtotime($log['event_time'])) ?></p>
                                </div>
                                <hr>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>

                <!-- System Status -->
                <div style="background-color:#f8f9fa;" class="section-container">
                    <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                        <div class="d-flex mx-2 align-items-start"><h2 class="bold">System Status</h2></div>
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

            </div><!-- /RIGHT COLUMN -->

            <!-- SIDEBAR LEFT -->
            <div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas">
                <div class="offcanvas-header justify-content-center">
                    <img src="../../images/logo.png" class="logo" alt="Logo"
                         onclick="dissolve('admin-homepage.php')">
                </div>
                <div class="offcanvas-body align-items-center d-flex flex-column gap-2">
                    <button class="nav-btn" title="Home" onclick="dissolve('admin-homepage.php')">
                        <i class="bi bi-house-door"></i>
                    </button>
                    <button class="nav-btn" title="Room Management" onclick="dissolve('admin-room-manage.php')">
                        <i class="fa-solid fa-person-shelter"></i>
                    </button>
                    <button class="nav-btn" title="Analytics" onclick="dissolve('admin-analytics.php')">
                        <i class="bi bi-clipboard2-data"></i>
                    </button>
                    <button class="nav-btn" title="Reports" onclick="dissolve('admin-reports.php')">
                        <i class="bi bi-exclamation-triangle"></i>
                    </button>
                    <button class="nav-btn" title="Faculty" onclick="dissolve('admin-faculty-management.php')">
                        <i class="bi bi-people"></i>
                    </button>
                    <button class="nav-btn" title="Profile Settings" onclick="dissolve('admin-profile-settings.php')">
                        <i class="bi bi-gear"></i>
                    </button>
                </div>
                <div class="offcanvas-footer">
                    <img src="../../images/team-logo.png" alt="Team Logo" style="width:4rem;">
                </div>
            </div>

            <!-- PROFILE OFFCANVAS -->
            <div class="offcanvas offcanvas-end" tabindex="-1" id="profileOffcanvas">
                <div class="offcanvas-body align-items-center d-flex flex-column pt-4 gap-2">
                    <div class="avatar-icon d-flex align-items-center justify-content-center">
                        <h3 class="bold"><?= $initials ?></h3>
                    </div>
                    <h4 class="bold mt-2" style="color:var(--secondary-color-1);"><?= $admin_name ?></h4>
                    <h6 class="light" style="word-break:break-all;text-align:center;"><?= htmlspecialchars($admin_email) ?></h6>
                    <div class="d-flex flex-column align-items-center justify-content-center w-100 mt-2 gap-1">
                        <button class="profile-btn" onclick="dissolve('admin-profile-settings.php')">Profile Settings</button>
                        <button class="profile-btn">Classroom Details</button>
                        <button class="profile-btn" onclick="dissolve('../../php/logout.php')">Logout</button>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="../../script/animations.js"></script>
<script src="../../script/toggles.js"></script>
</body>
</html>