<?php
require_once '../../php/includes/admin-head.php';
include '../../php/handlers/analytics-handler.php';
/** @var mysqli $conn */
/** @var array $rooms */
/** @var array $roomDataFromPHP */

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Analytics</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">
    <link rel="stylesheet" href="../../css/admin-analytics.css">

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body class="contrast-bg">

    <?php include '../../php/includes/admin-topbar.php'; ?>

    <div class="parent-container">
        <?php include '../../php/includes/admin-sidebar.php'; ?>

        <div class="child-container">

            <!-- Page header row -->
            <div class="analytics-header">
                <div class="room-label bold" id="roomLabel">
                    <?= htmlspecialchars($rooms[0]['room_name'] ?? 'No Rooms') ?>
                </div>
                <div class="analytics-controls">
                    <div class="view-select-group">
                        <label for="periodSelect">Period</label>
                        <select id="periodSelect" onchange="onControlChange()">
                            <option value="7">Last 7 days</option>
                            <option value="14">Last 14 days</option>
                            <option value="30" selected>Last 30 days</option>
                        </select>
                    </div>
                    <div class="view-select-group">
                        <label for="roomSelect">Room</label>
                        <select id="roomSelect" onchange="onControlChange()">
                            <option value="0">All Rooms</option>
                            <?php foreach ($rooms as $room): ?>
                                <option value="<?= $room['id'] ?>">
                                    <?= htmlspecialchars($room['room_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="content-area">

                <!-- ── Summary cards ── -->
                <div class="summary-cards-row">
                    <div class="summary-card">
                        <div class="summary-icon"><i class="bi bi-lightning-charge-fill"></i></div>
                        <div class="summary-info">
                            <div class="summary-val" id="sumEnergy">—</div>
                            <div class="summary-label">Total Energy (kWh)</div>
                        </div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-icon"><i class="bi bi-activity"></i></div>
                        <div class="summary-info">
                            <div class="summary-val" id="sumPeakKw">—</div>
                            <div class="summary-label">Peak Power (kW)</div>
                        </div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-icon"><i class="bi bi-clock-history"></i></div>
                        <div class="summary-info">
                            <div class="summary-val" id="sumMinutes">—</div>
                            <div class="summary-label">Total Occupied (hrs)</div>
                        </div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-icon"><i class="bi bi-calendar-check"></i></div>
                        <div class="summary-info">
                            <div class="summary-val" id="sumSessions">—</div>
                            <div class="summary-label">Total Sessions</div>
                        </div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-icon"><i class="bi bi-plug-fill"></i></div>
                        <div class="summary-info">
                            <div class="summary-val" id="sumVoltage">—</div>
                            <div class="summary-label">Avg Voltage (V)</div>
                        </div>
                    </div>
                </div>

                <!-- ── Energy chart + Trigger breakdown ── -->
                <div class="top-row">
                    <div class="card-white">
                        <div class="chart-card-header">
                            <span class="chart-card-title bold">Daily Energy Consumption</span>
                            <span class="chart-unit-label" id="chartUnitLabel">Wh per day</span>
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="usageChart"></canvas>
                        </div>
                    </div>

                    <div class="card-white trigger-card">
                        <div class="chart-card-title bold" style="margin-bottom:16px;">Session Triggers</div>
                        <div class="chart-wrapper-sm">
                            <canvas id="triggerChart"></canvas>
                        </div>
                        <div id="triggerLegend" class="trigger-legend"></div>
                    </div>
                </div>

                <!-- ── Heatmap ── -->
                <div class="card-white">
                    <div class="chart-card-header">
                        <span class="chart-card-title bold">Occupancy Heatmap</span>
                        <span class="summary-label">Lighting ON events by hour &amp; day of week</span>
                    </div>
                    <div class="heatmap-scroll">
                        <div id="heatmapGrid" class="heatmap-grid"></div>
                    </div>
                </div>

                <!-- ── Breakdown table ── -->
                <div class="card-white">
                    <div class="breakdown-card">
                        <div>
                            <div class="breakdown-header">
                                <span class="breakdown-title bold">Usage Breakdown by Room</span>
                            </div>
                            <table class="breakdown-table">
                                <thead>
                                    <tr>
                                        <th>Room</th>
                                        <th>Sessions</th>
                                        <th>Occupied Time</th>
                                        <th>Energy (Wh)</th>
                                        <th>Energy (kWh)</th>
                                        <th>Avg Voltage</th>
                                    </tr>
                                </thead>
                                <tbody id="breakdownBody">
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">Loading...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="total-usage">
                            <div class="total-usage-title bold">Period Total</div>
                            <p>Occupied: <span class="val" id="totalHours">—</span></p>
                            <p>Energy: <span class="val" id="totalKwh">—</span></p>
                            <p>Est. Cost: <span class="val" id="totalCost">—</span></p>
                            <div class="export-btns">
                                <!-- FIX: classes were swapped — btn-export-csv now calls exportCSV(),
                                     btn-export-pdf now calls exportPDF() -->
                                <button class="btn-export-csv" onclick="exportCSV()">Export CSV</button>
                                <button class="btn-export-pdf" onclick="exportPDF()">Export PDF</button>
                            </div>
                        </div>

                        <div class="card-white">
                            <div class="chart-card-header">
                                <span class="chart-card-title bold">Session Detail</span>
                                <span class="summary-label">Per-session breakdown — Volts, kW, kWh, Cost</span>
                            </div>
                            <div id="sessionsTableContainer">Loading...</div>
                        </div>
                    </div>
                </div>

            </div><!-- /content-area -->
        </div><!-- /child-container -->
    </div><!-- /parent-container -->

    <?php include '../../php/includes/profile-offcanvas.php'; ?>

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>

    <script>
        const roomData = <?= json_encode($roomDataFromPHP, JSON_HEX_TAG) ?>;
    </script>
    <script src="../../script/admin-analytics.js"></script>

</body>

</html>
<?php if (isset($conn)) $conn->close(); ?>