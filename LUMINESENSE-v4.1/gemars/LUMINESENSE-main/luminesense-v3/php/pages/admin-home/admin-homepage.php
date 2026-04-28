<?php
// ============================================================
//  admin-homepage.php
//  LumineSense – Admin Dashboard: Home / Overview
//
//  What this page shows:
//  - Summary cards (classrooms, lights on, pending accounts, alerts)
//  - Classroom status grid (each room's light + occupancy)
//  - Recent activity log
//  - Security alerts
//
//  SECURITY: session_guard.php blocks anyone who is not
//  logged in as an admin from seeing this page.
// ============================================================

require_once '../../php/session_guard.php';
check_admin();                        // Redirects to admin-login if not logged in
require_once '../../php/db_connect.php';

$admin_name = htmlspecialchars($_SESSION['admin_name']);

// ── Pull summary counts from the database ────────────────────

// Total classrooms
$total_rooms = 0;
$r = $conn->query("SELECT COUNT(*) AS cnt FROM classrooms");
if ($r) $total_rooms = $r->fetch_assoc()['cnt'];

// Lights currently ON (last log event for each classroom = 'on')
$lights_on = 0;
$r = $conn->query("
    SELECT COUNT(*) AS cnt FROM (
        SELECT classroom_id, event_type
        FROM lighting_logs
        WHERE id IN (
            SELECT MAX(id) FROM lighting_logs GROUP BY classroom_id
        )
        AND event_type = 'on'
    ) AS latest
");
if ($r) $lights_on = $r->fetch_assoc()['cnt'];

// Pending faculty accounts
$pending_faculty = 0;
$r = $conn->query("SELECT COUNT(*) AS cnt FROM faculty WHERE is_verified = 0");
if ($r) $pending_faculty = $r->fetch_assoc()['cnt'];

// Security alerts today (motion outside schedule hours)
$alerts_today = 0;
$r = $conn->query("
    SELECT COUNT(*) AS cnt FROM lighting_logs
    WHERE event_type = 'security_alert'
    AND DATE(event_time) = CURDATE()
");
if ($r) $alerts_today = $r->fetch_assoc()['cnt'];

// ── Pull classrooms list with latest light status ─────────────
$classrooms = [];
$r = $conn->query("
    SELECT c.id, c.room_name, c.room_size, c.description,
           COALESCE(l.event_type, 'off') AS latest_event
    FROM classrooms c
    LEFT JOIN lighting_logs l ON l.id = (
        SELECT MAX(id) FROM lighting_logs WHERE classroom_id = c.id
    )
    ORDER BY c.room_name ASC
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $classrooms[] = $row;
    }
}

// ── Recent activity log (last 8 entries) ──────────────────────
$recent_logs = [];
$r = $conn->query("
    SELECT l.event_type, l.triggered_by, l.event_time, c.room_name
    FROM lighting_logs l
    JOIN classrooms c ON c.id = l.classroom_id
    ORDER BY l.event_time DESC
    LIMIT 8
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $recent_logs[] = $row;
    }
}

$conn->close();

// Helper: format event type into readable label + badge class
function event_label($type) {
    $map = [
        'on'             => ['Light ON',        'badge-verified'],
        'off'            => ['Light OFF',        'badge-info'],
        'gesture'        => ['Gesture Control',  'badge-info'],
        'schedule'       => ['Schedule Trigger', 'badge-pending'],
        'security_alert' => ['Security Alert',   'badge-alert'],
    ];
    return $map[$type] ?? [$type, 'badge-info'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard – LumineSense Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/dashboard.css">
</head>
<body>
<div class="dashboard-wrapper">

    <!-- ── SIDEBAR ──────────────────────────────────────────── -->
    <?php include 'admin-sidebar.php'; ?>

    <!-- ── MAIN ─────────────────────────────────────────────── -->
    <div class="dashboard-main">

        <!-- Topbar -->
        <div class="dashboard-topbar">
            <h1 class="topbar-title">Overview</h1>
            <div class="topbar-right">
                <span><i class="bi bi-calendar3"></i> <span id="topbar-date"></span></span>
                <span class="topbar-time" id="topbar-time"></span>
            </div>
        </div>

        <div class="dashboard-content">

            <?php if ($alerts_today > 0): ?>
            <!-- Security alert banner if there are alerts today -->
            <div class="alert-banner danger">
                <i class="bi bi-shield-exclamation"></i>
                <span><strong><?= $alerts_today ?> security alert(s)</strong> detected today — motion outside scheduled hours.</span>
                <a href="admin-alerts.php" style="margin-left:auto; font-weight:700; color:inherit;">View →</a>
            </div>
            <?php endif; ?>

            <?php if ($pending_faculty > 0): ?>
            <!-- Pending accounts banner -->
            <div class="alert-banner">
                <i class="bi bi-person-exclamation"></i>
                <span><strong><?= $pending_faculty ?> faculty account(s)</strong> are waiting for your approval.</span>
                <a href="admin-accounts.php" style="margin-left:auto; font-weight:700; color:inherit;">Review →</a>
            </div>
            <?php endif; ?>

            <!-- ── Summary Cards ──────────────────────────── -->
            <div class="summary-cards">
                <div class="summary-card">
                    <div class="summary-icon blue"><i class="bi bi-door-open"></i></div>
                    <div class="summary-info">
                        <div class="summary-value"><?= $total_rooms ?></div>
                        <div class="summary-label">Total Classrooms</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon yellow"><i class="bi bi-lightbulb-fill"></i></div>
                    <div class="summary-info">
                        <div class="summary-value"><?= $lights_on ?></div>
                        <div class="summary-label">Lights Currently ON</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon orange"><i class="bi bi-person-fill-add"></i></div>
                    <div class="summary-info">
                        <div class="summary-value"><?= $pending_faculty ?></div>
                        <div class="summary-label">Pending Accounts</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon red"><i class="bi bi-shield-fill-exclamation"></i></div>
                    <div class="summary-info">
                        <div class="summary-value"><?= $alerts_today ?></div>
                        <div class="summary-label">Alerts Today</div>
                    </div>
                </div>
            </div>

            <!-- ── Classroom Status Grid ──────────────────── -->
            <div class="panel">
                <div class="panel-header">
                    <h6><i class="bi bi-grid-3x3-gap-fill"></i> Classroom Status</h6>
                    <a href="admin-classrooms.php" class="btn-icon" title="Manage classrooms">
                        <i class="bi bi-gear"></i>
                    </a>
                </div>
                <div class="panel-body">
                    <?php if (empty($classrooms)): ?>
                        <p style="color:#aaa; font-size:0.85rem; text-align:center; padding:20px 0;">
                            No classrooms configured yet.
                            <a href="admin-classrooms.php">Add a classroom →</a>
                        </p>
                    <?php else: ?>
                    <div class="classroom-grid">
                        <?php foreach ($classrooms as $room):
                            $is_on = ($room['latest_event'] === 'on');
                        ?>
                        <div class="classroom-card">
                            <div class="classroom-card-header">
                                <span class="classroom-name"><?= htmlspecialchars($room['room_name']) ?></span>
                                <span class="light-status <?= $is_on ? 'on' : 'off' ?>">
                                    <i class="bi bi-lightbulb<?= $is_on ? '-fill' : '' ?>"></i>
                                    <?= $is_on ? 'ON' : 'OFF' ?>
                                </span>
                            </div>
                            <div class="classroom-meta">
                                <span><i class="bi bi-aspect-ratio"></i> <?= ucfirst($room['room_size']) ?> room</span>
                                <span><i class="bi bi-info-circle"></i>
                                    <?= $room['description'] ? htmlspecialchars($room['description']) : 'No description' ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Recent Activity Log ────────────────────── -->
            <div class="panel">
                <div class="panel-header">
                    <h6><i class="bi bi-clock-history"></i> Recent Activity</h6>
                    <a href="admin-logs.php" class="btn-icon" title="View full log">
                        <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
                <div class="panel-body" style="padding:0;">
                    <?php if (empty($recent_logs)): ?>
                        <p style="color:#aaa; font-size:0.85rem; text-align:center; padding:20px 0;">
                            No activity recorded yet.
                        </p>
                    <?php else: ?>
                    <table class="ls-table">
                        <thead>
                            <tr>
                                <th>Room</th>
                                <th>Event</th>
                                <th>Triggered By</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_logs as $log):
                                [$label, $badge] = event_label($log['event_type']);
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($log['room_name']) ?></td>
                                <td><span class="<?= $badge ?>"><?= $label ?></span></td>
                                <td><?= htmlspecialchars($log['triggered_by']) ?></td>
                                <td style="color:#888;"><?= date('M d, h:i A', strtotime($log['event_time'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /dashboard-content -->
    </div><!-- /dashboard-main -->
</div><!-- /dashboard-wrapper -->

<script>
    // Live clock in topbar
    function updateClock() {
        const now = new Date();
        document.getElementById('topbar-time').textContent =
            now.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        document.getElementById('topbar-date').textContent =
            now.toLocaleDateString('en-PH', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
    }
    updateClock();
    setInterval(updateClock, 1000);
</script>
</body>
</html>
