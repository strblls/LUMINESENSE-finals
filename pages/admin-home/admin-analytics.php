<?php
$page_title = 'Analytics';
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

            <!-- Page header -->
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
                    <?php if (count($rooms) > 1): ?>
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
                    <?php else: ?>
                        <!-- Single room — no dropdown needed, pass ID silently -->
                        <input type="hidden" id="roomSelect" value="<?= $rooms[0]['id'] ?? 0 ?>">
                    <?php endif; ?>
                </div>
            </div>

            <div class="content-area">

                <!-- ── Live readings ── -->
                <div class="card-white live-card">
                    <div class="live-card-header">
                        <span class="chart-card-title bold">Live Readings</span>
                        <span class="live-badge" id="liveBadge">
                            <span class="live-dot"></span> Live
                        </span>
                    </div>
                    <div class="live-readings-row">
                        <div class="live-reading-item">
                            <div class="live-reading-val" id="liveVoltage">— V</div>
                            <div class="live-reading-label">Voltage</div>
                        </div>
                        <div class="live-divider"></div>
                        <div class="live-reading-item">
                            <div class="live-reading-val" id="liveCurrent">— A</div>
                            <div class="live-reading-label">Current</div>
                        </div>
                        <div class="live-divider"></div>
                        <div class="live-reading-item">
                            <div class="live-reading-val" id="livePower">— W</div>
                            <div class="live-reading-label">Power</div>
                        </div>
                        <div class="live-divider"></div>
                        <div class="live-reading-item">
                            <div class="live-reading-val" id="liveEnergy">— Wh</div>
                            <div class="live-reading-label">Energy (session)</div>
                        </div>
                        <div class="live-divider"></div>
                        <div class="live-reading-item">
                            <div class="live-status-row">
                                <span class="live-status-dot" id="liveStatusDot"></span>
                                <span class="live-reading-val" id="liveStatus">—</span>
                            </div>
                            <div class="live-reading-label">Light Status</div>
                        </div>
                    </div>
                </div>

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
                        <div class="summary-icon"><i class="bi bi-cash-coin"></i></div>
                        <div class="summary-info">
                            <div class="summary-val" id="sumCost">—</div>
                            <div class="summary-label">Est. Cost (₱)</div>
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

                <!-- ── Daily energy chart ── -->
                <div class="card-white">
                    <div class="chart-card-header">
                        <span class="chart-card-title bold">Daily Energy Consumption</span>
                        <span class="summary-label">Wh per day</span>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="usageChart"></canvas>
                    </div>
                </div>

                <!-- ── Daily history table ── -->
                <div class="card-white">
                    <div class="breakdown-header" style="margin-bottom:14px;">
                        <span class="breakdown-title bold">Daily History</span>
                        <div class="export-btns">
                            <button class="btn-export-csv" onclick="exportCSV()">Export CSV</button>
                            <button class="btn-export-pdf" onclick="exportPDF()">Export PDF</button>
                        </div>
                    </div>
                    <table class="breakdown-table">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Date</th>
                                <th>Sessions</th>
                                <th>Occupied Time</th>
                                <th>Energy (Wh)</th>
                                <th>Energy (kWh)</th>
                                <th>Est. Cost (₱)</th>
                            </tr>
                        </thead>
                        <tbody id="historyBody">
                            <tr><td colspan="6" class="text-center text-muted">Loading...</td></tr>
                        </tbody>
                        <tfoot id="historyFoot"></tfoot>
                    </table>
                </div>

            </div><!-- /content-area -->
        </div><!-- /child-container -->
    </div><!-- /parent-container -->

    <?php include '../../php/includes/profile-offcanvas.php'; ?>

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>

    <script>
        const roomData   = <?= json_encode($roomDataFromPHP, JSON_HEX_TAG) ?>;
        const defaultCid = <?= (int)($rooms[0]['id'] ?? 3) ?>;
    </script>
    <script src="../../script/admin-analytics.js"></script>

</body>
</html>
<?php if (isset($conn)) $conn->close(); ?>