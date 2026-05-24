<?php
require_once '../../php/includes/admin-head.php';
include '../../php/handlers/analytics-handler.php';

/** @var int $total_rooms */
/** @var int $lights_on */
/** @var int $pending */
/** @var int $ext_pending */
/** @var bool $db_ok */
/** @var int $lights_data */
/** @var array $logs */
/** @var array $rooms */
/** @var array $roomDataFromPHP */
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Analytics</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">
    <link rel="stylesheet" href="../../css/admin-analytics.css">

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body class="contrast-bg">

    <?php include '../../php/includes/admin-topbar.php'; ?>

    <div class="parent-container">
        <?php include '../../php/includes/admin-sidebar.php'; ?>

        <div class="child-container">
            <div class="room-label bold" id="roomLabel">
                <?= htmlspecialchars($rooms[0]['room_name'] ?? 'No Rooms') ?>
            </div>

            <div class="content-area">

                <div class="top-row">

                    <div class="card-white">
                        <div class="usage-card-title bold">Usage</div>
                        <div class="usage-stat">
                            <div class="usage-number up" id="usagePrimary">
                                <span class="usage-arrow">↗</span>
                                <span id="usagePrimaryVal">—</span>
                            </div>
                            <p class="usage-desc" id="usagePrimaryDesc">Loading...</p>
                        </div>
                        <div class="usage-stat">
                            <div class="usage-number down" id="usageSecondary">
                                <span class="usage-arrow">↙</span>
                                <span id="usageSecondaryVal">—</span>
                            </div>
                            <p class="usage-desc" id="usageSecondaryDesc">Loading...</p>
                        </div>
                    </div>

                    <div class="card-white">
                        <div class="chart-card-header">
                            <span class="chart-card-title bold">Electric Usage Report</span>
                            <div class="chart-controls">
                                <div class="view-select-group">
                                    <label for="periodSelect">View</label>
                                    <select id="periodSelect" onchange="onControlChange()">
                                        <option value="daily">Daily</option>
                                        <option value="weekly" selected>Weekly</option>
                                        <option value="monthly">Monthly</option>
                                    </select>
                                </div>
                                <div class="view-select-group">
                                    <label for="roomSelect">Select Room</label>
                                    <select id="roomSelect" onchange="onControlChange()">
                                        <?php foreach ($rooms as $room): ?>
                                            <option value="<?= htmlspecialchars($room['room_name']) ?>">
                                                <?= htmlspecialchars($room['room_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="usageChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="card-white">
                    <div class="breakdown-card">
                        <div>
                            <div class="breakdown-header">
                                <span class="breakdown-title bold">Usage Breakdown</span>
                                <div class="view-select-group">
                                    <label for="breakdownPeriodSelect">View</label>
                                    <select id="breakdownPeriodSelect" onchange="updateBreakdown()">
                                        <option value="daily">Daily</option>
                                        <option value="weekly" selected>Weekly</option>
                                        <option value="monthly">Monthly</option>
                                    </select>
                                </div>
                            </div>
                            <table class="breakdown-table">
                                <thead>
                                    <tr>
                                        <th>Room</th>
                                        <th>Hours of Lighting Used</th>
                                        <th>Estimated kWh</th>
                                    </tr>
                                </thead>
                                <tbody id="breakdownBody"></tbody>
                            </table>
                        </div>
                        
                        <div class="total-usage">
                            <div class="total-usage-title bold">Total Usage</div>
                            <p>Hours of Lighting Used: <span class="val" id="totalHours">—</span></p>
                            <p>Estimated kWh: <span class="val" id="totalKwh">—</span></p>
                            <div class="export-btns">
                                <button class="btn-export-pdf" onclick="exportPDF()">Export PDF</button>
                                <button class="btn-export-csv" onclick="exportCSV()">Export CSV</button>
                            </div>
                        </div>
                    </div>
                </div> </div> </div> </div> <?php include '../../php/includes/profile-offcanvas.php'; ?>

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
    <script src="../../script/initialize-gesture.js"></script>

    <script>
        const roomData = <?= json_encode($roomDataFromPHP, JSON_HEX_TAG) ?>;
    </script>
    <script src="../../script/admin-analytics.js"></script>

</body>
</html>

<?php
// Safely close connection at the very end of processing
if (isset($conn)) {
    $conn->close();
}
?>