<?php
// ============================================================
//  admin-alerts.php
//  LumineSense – Security Alerts
//
//  Shows all security_alert events — motion detected outside
//  scheduled hours. These need admin review.
// ============================================================

require_once '../../php/session_guard.php';
check_admin();
require_once '../../php/db_connect.php';

// ── Fetch security alerts ──────────────────────────────────────
$alerts = [];
$r = $conn->query("
    SELECT l.id, l.triggered_by, l.event_time, c.room_name
    FROM lighting_logs l
    JOIN classrooms c ON c.id = l.classroom_id
    WHERE l.event_type = 'security_alert'
    ORDER BY l.event_time DESC
    LIMIT 100
");
if ($r) while ($row = $r->fetch_assoc()) $alerts[] = $row;

// Count alerts by room
$by_room = [];
foreach ($alerts as $a) {
    $by_room[$a['room_name']] = ($by_room[$a['room_name']] ?? 0) + 1;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Security Alerts – LumineSense Admin</title>

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
            <h1 class="topbar-title">Security Alerts</h1>
        </div>

        <div class="dashboard-content">

            <?php if (empty($alerts)): ?>
            <div class="alert-banner" style="background:#e8f5e9; border-color:#a5d6a7; color:#1b5e20;">
                <i class="bi bi-shield-check" style="color:#2e7d32;"></i>
                <span>No security alerts recorded. All clear!</span>
            </div>
            <?php else: ?>

            <!-- Alert summary cards -->
            <div class="summary-cards" style="margin-bottom:24px;">
                <?php foreach ($by_room as $room => $count): ?>
                <div class="summary-card">
                    <div class="summary-icon red"><i class="bi bi-shield-fill-exclamation"></i></div>
                    <div class="summary-info">
                        <div class="summary-value"><?= $count ?></div>
                        <div class="summary-label"><?= htmlspecialchars($room) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Alerts explanation banner -->
            <div class="alert-banner danger" style="margin-bottom:20px;">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span>These are motion detections that occurred <strong>outside scheduled class hours</strong>. Review and coordinate with security personnel if needed.</span>
            </div>

            <!-- Alerts table -->
            <div class="panel">
                <div class="panel-header">
                    <h6><i class="bi bi-shield-exclamation"></i> Alert Log (<?= count($alerts) ?>)</h6>
                </div>
                <div class="panel-body" style="padding:0;">
                    <table class="ls-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Room</th>
                                <th>Triggered By</th>
                                <th>Date & Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alerts as $i => $a): ?>
                            <tr>
                                <td style="color:#ccc;"><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($a['room_name']) ?></td>
                                <td><?= htmlspecialchars($a['triggered_by']) ?></td>
                                <td style="color:#888;">
                                    <?= date('M d, Y – h:i:s A', strtotime($a['event_time'])) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
