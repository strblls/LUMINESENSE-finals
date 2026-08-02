<?php
require_once __DIR__ . "/../../src/Session/session_guard.php";
if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['faculty_logged_in'])) {
    header('Location: ' . ($_SESSION['role'] === 'faculty' ? '../faculty-login.php' : '../admin-login.php'));
    exit;
}
require_once __DIR__ . "/../../src/Config/db_connect.php";
date_default_timezone_set('Asia/Manila');

$room_id = (int)($_GET['room_id'] ?? 0);

if ($room_id) {
    $row = $conn->query("
        SELECT id, room_name, light_status, row1_status, row2_status, row3_status
        FROM classrooms WHERE id = $room_id LIMIT 1
    ")->fetch_assoc();
    if ($row) {
        $room_name = $row['room_name'];
    } else {
        $room_name = 'Unknown Room';
    }
} else {
    $room_name = 'Demo Room';
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($room_name) ?> - Light View</title>
<link rel="icon" href="../../images/icon.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="../../css/pages/room-light-view.css">
</head>
<body>
<div class="card">
    <div class="room-name"><?= htmlspecialchars($room_name) ?></div>
    <div class="room-sub">Lighting &amp; Occupancy View</div>

    <div class="status-badge" id="statusBadge">LOADING</div>

    <div class="section">
        <div class="section-label">Lighting</div>
        <div class="row-card" id="lightRow">
            <span class="light-dot off" id="lightDot"></span>
            <span class="light-label">Room Lights</span>
            <span class="light-status off" id="lightStatus">OFF</span>
        </div>
    </div>

    <div class="section">
        <div class="section-label">Faculty</div>
        <div id="facultySection"><div class="row-card vacant">Loading&hellip;</div></div>
    </div>

    <div class="section">
        <div class="section-label">Time Slot</div>
        <div id="timeSection"><div class="row-card vacant">Loading&hellip;</div></div>
    </div>

    <!-- Admin Light Override Panel -->
    <div class="admin-override-panel mt-3">
        <div class="override-panel-header">
            <i class="bi bi-shield-lock-fill"></i>
            <span>Admin Override</span>
            <span class="override-live-badge" id="overrideLiveBadge">LIVE</span>
        </div>

        <div class="override-master-row">
            <div class="override-master-left">
                <div class="bulb-preview-grid">
                    <img src="../../images/bulb-off.png" id="bulb0" class="bulb-img">
                    <img src="../../images/bulb-off.png" id="bulb1" class="bulb-img">
                    <img src="../../images/bulb-off.png" id="bulb2" class="bulb-img">
                    <img src="../../images/bulb-off.png" id="bulb3" class="bulb-img">
                    <img src="../../images/bulb-off.png" id="bulb4" class="bulb-img">
                    <img src="../../images/bulb-off.png" id="bulb5" class="bulb-img">
                    <img src="../../images/bulb-off.png" id="bulb6" class="bulb-img">
                    <img src="../../images/bulb-off.png" id="bulb7" class="bulb-img">
                    <img src="../../images/bulb-off.png" id="bulb8" class="bulb-img">
                </div>
            </div>
            <div class="override-master-right">
                <button class="override-master-btn off" id="allLightsBtn" onclick="toggleAllLights()">
                    <i class="bi bi-power"></i>
                    <span id="allLightsLabel">OFF</span>
                </button>
                <div class="override-hint">All rows</div>
            </div>
        </div>

        <div class="override-rows">
            <div class="override-row-item">
                <span class="override-row-label">Row 1</span>
                <div class="override-row-toggle">
                    <input class="override-switch" type="checkbox" role="switch" id="row1sw" onchange="toggleRow(1, this.checked)">
                    <label class="override-switch-label" for="row1sw"></label>
                </div>
                <span class="override-row-status" id="row1status">OFF</span>
            </div>
            <div class="override-row-item">
                <span class="override-row-label">Row 2</span>
                <div class="override-row-toggle">
                    <input class="override-switch" type="checkbox" role="switch" id="row2sw" onchange="toggleRow(2, this.checked)">
                    <label class="override-switch-label" for="row2sw"></label>
                </div>
                <span class="override-row-status" id="row2status">OFF</span>
            </div>
            <div class="override-row-item">
                <span class="override-row-label">Row 3</span>
                <div class="override-row-toggle">
                    <input class="override-switch" type="checkbox" role="switch" id="row3sw" onchange="toggleRow(3, this.checked)">
                    <label class="override-switch-label" for="row3sw"></label>
                </div>
                <span class="override-row-status" id="row3status">OFF</span>
            </div>
        </div>

        <div class="override-footer-note">
            <i class="bi bi-info-circle"></i>
            Changes apply immediately and are logged.
        </div>
    </div>

    <div class="footer">LumineSense &mdash; Real-time Room Monitor</div>
</div>

<script src="../../js/admin/admin-room-light-view.js"></script>
</body>
</html>
