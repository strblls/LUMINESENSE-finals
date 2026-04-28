<?php
// ============================================================
//  admin-logs.php
//  LumineSense – Full Activity Log
//
//  Shows all lighting events with filtering by:
//  - Classroom
//  - Event type
//  - Date range
// ============================================================

require_once '../../php/session_guard.php';
check_admin();
require_once '../../php/db_connect.php';

// ── Filters from GET params ────────────────────────────────────
$filter_room  = (int)($_GET['room']  ?? 0);
$filter_type  = $_GET['type']  ?? '';
$filter_date  = $_GET['date']  ?? '';

// ── Build WHERE clause dynamically ────────────────────────────
$where_parts = ["1=1"];
$params      = [];
$types       = "";

if ($filter_room > 0) {
    $where_parts[] = "l.classroom_id = ?";
    $params[] = $filter_room;
    $types   .= "i";
}

$valid_types = ['on','off','gesture','schedule','security_alert'];
if ($filter_type && in_array($filter_type, $valid_types)) {
    $where_parts[] = "l.event_type = ?";
    $params[] = $filter_type;
    $types   .= "s";
}

if ($filter_date) {
    $where_parts[] = "DATE(l.event_time) = ?";
    $params[] = $filter_date;
    $types   .= "s";
}

$where = implode(" AND ", $where_parts);

// ── Fetch logs ─────────────────────────────────────────────────
$logs = [];
$sql  = "
    SELECT l.id, l.event_type, l.triggered_by, l.event_time, c.room_name
    FROM lighting_logs l
    JOIN classrooms c ON c.id = l.classroom_id
    WHERE $where
    ORDER BY l.event_time DESC
    LIMIT 200
";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $logs[] = $row;
$stmt->close();

// ── Fetch classroom list for filter dropdown ───────────────────
$classrooms = [];
$r = $conn->query("SELECT id, room_name FROM classrooms ORDER BY room_name");
if ($r) while ($row = $r->fetch_assoc()) $classrooms[] = $row;

$conn->close();

// Event type labels + badge map
$type_map = [
    'on'             => ['Light ON',        'badge-verified'],
    'off'            => ['Light OFF',        'badge-info'],
    'gesture'        => ['Gesture Control',  'badge-info'],
    'schedule'       => ['Schedule Trigger', 'badge-pending'],
    'security_alert' => ['Security Alert',   'badge-alert'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Activity Logs – LumineSense Admin</title>

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
            <h1 class="topbar-title">Activity Logs</h1>
            <div class="topbar-right">
                <span style="color:#888; font-size:0.82rem;">Showing up to 200 records</span>
            </div>
        </div>

        <div class="dashboard-content">

            <!-- ── Filters ───────────────────────────────── -->
            <div class="panel" style="margin-bottom:20px;">
                <div class="panel-body">
                    <form method="GET" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">

                        <div style="flex:1; min-width:150px;">
                            <label style="font-size:0.78rem; font-weight:600; color:#555; display:block; margin-bottom:4px;">Classroom</label>
                            <select name="room" class="form-control form-control-sm">
                                <option value="">All Rooms</option>
                                <?php foreach ($classrooms as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $filter_room == $c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['room_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="flex:1; min-width:150px;">
                            <label style="font-size:0.78rem; font-weight:600; color:#555; display:block; margin-bottom:4px;">Event Type</label>
                            <select name="type" class="form-control form-control-sm">
                                <option value="">All Types</option>
                                <?php foreach ($type_map as $val => [$label, $badge]): ?>
                                <option value="<?= $val ?>" <?= $filter_type === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="flex:1; min-width:150px;">
                            <label style="font-size:0.78rem; font-weight:600; color:#555; display:block; margin-bottom:4px;">Date</label>
                            <input type="date" name="date" class="form-control form-control-sm"
                                   value="<?= htmlspecialchars($filter_date) ?>">
                        </div>

                        <div style="display:flex; gap:8px;">
                            <button type="submit" class="btn-approve" style="padding:6px 16px;">
                                <i class="bi bi-funnel"></i> Filter
                            </button>
                            <a href="admin-logs.php" class="btn-reject" style="padding:6px 16px; text-decoration:none;">
                                <i class="bi bi-x"></i> Clear
                            </a>
                        </div>

                    </form>
                </div>
            </div>

            <!-- ── Log Table ─────────────────────────────── -->
            <div class="panel">
                <div class="panel-header">
                    <h6><i class="bi bi-clock-history"></i> Log Entries (<?= count($logs) ?>)</h6>
                </div>
                <div class="panel-body" style="padding:0;">
                    <?php if (empty($logs)): ?>
                    <p style="color:#aaa; font-size:0.85rem; text-align:center; padding:28px 0;">
                        No log entries found for the selected filters.
                    </p>
                    <?php else: ?>
                    <table class="ls-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Room</th>
                                <th>Event</th>
                                <th>Triggered By</th>
                                <th>Date & Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $i => $log):
                                [$label, $badge] = $type_map[$log['event_type']] ?? [$log['event_type'], 'badge-info'];
                            ?>
                            <tr>
                                <td style="color:#ccc;"><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($log['room_name']) ?></td>
                                <td><span class="<?= $badge ?>"><?= $label ?></span></td>
                                <td><?= htmlspecialchars($log['triggered_by']) ?></td>
                                <td style="color:#888;">
                                    <?= date('M d, Y – h:i:s A', strtotime($log['event_time'])) ?>
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
</body>
</html>
