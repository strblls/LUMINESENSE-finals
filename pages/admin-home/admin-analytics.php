<?php
$page_title = 'Analytics';
require_once __DIR__ . "/../../src/Includes/admin-head.php";
include __DIR__ . "/../../src/Handlers/analytics-handler.php";
/** @var mysqli $conn */
/** @var array $rooms */
/** @var array $roomDataFromPHP */
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LumineSense - Admin Analytics</title>

    <!--External links-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!--Relative links-->
    <link rel="icon" type="image/png" sizes="32x32" href="../../images/icon.png">
    <link rel="shortcut icon" type="image/png" href="../../images/icon.png">
    <link rel="stylesheet" href="../../css/base/global.css">
    <link rel="stylesheet" href="../../css/base/containers.css">
    <link rel="stylesheet" href="../../css/base/modals.css">
    <link rel="stylesheet" href="../../css/admin/analytics.css?v=<?= time() ?>">
    <link rel="stylesheet" href="../../css/admin/common.css">
    <link rel="stylesheet" href="../../css/faculty/timetable.css">
    <link rel="stylesheet" href="../../css/admin/faculty-management.css">
    <link rel="stylesheet" href="../../css/base/tooltip.css">
    <link rel="stylesheet" href="../../css/admin/room-manage.css">

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body class="contrast-bg">

    <?php include __DIR__ . "/../../src/Includes/admin-topbar.php"; ?>
    <?php include __DIR__ . "/../../src/Includes/admin-sidebar.php"; ?>

    <div class="parent-container">

        <div class="child-container">

            <!-- Page header -->
            <div class="main-container faculty-timetable-heading d-flex flex-column align-items-center justify-content-center w-auto" 
                style="position:relative;background-color:var(--secondary-color-2);margin-bottom: 1rem !important;">
                <div class="d-flex gap-2" style="position:absolute;left:8px;top:50%;transform:translateY(-50%);">
                    <button type="button" class="timetable-btn ms-2" data-panel="panelGuideInfo" title="Guide">
                        <i class="bi bi-info-lg"></i>
                        <span class="timetable-btn-title bold">Guide</span>
                    </button>
                    <div id="panelGuideInfo" class="timetable-panel p-3 m-3">
                        <div class="section-container timetable" style="background-color:#f8f9fa;width:393px;">
                            <h6 class="bold mb-2"><i class="bi bi-info-circle me-1"></i>Analytics Guide</h6>
                            <div class="ps-3 mb-0" style="font-size:10px;line-height:1.5;">
                                <p class="mb-1"><strong>Live Readings</strong> - Real-time Voltage (V), Current (A), and Power (W) from connected hardware, plus session Energy (Wh) and Light Status.</p>
                                <p class="mb-1"><strong>Rooms Sidebar</strong> - Click a room to filter all analytics to that room only. Hover to see description, dimension, faculty, schedule, and lighting.</p>
                                <p class="mb-1"><strong>Charts</strong> - Line graph and bar graph display Voltage, Current, and Power. Click legend items to toggle datasets on/off; hidden datasets also hide their y-axis on the line graph.</p>
                                <p class="mb-1"><strong>Filter by Period</strong> - Switch between Today (24 hourly data points), Last 7, 14, or 30 days. Today shows 5-minute interval history rows.</p>
                                <p class="mb-1"><strong>Filter by Metrics</strong> - Focus on Voltage, Current, or Power across both charts and the live reading cards. Selecting a metric enlarges its card and shows the relevant formula.</p>
                                <p class="mb-1"><strong>Formula Bar</strong> - Displays the relationship between V, A, W, and Energy. Updates dynamically based on the selected metric filter.</p>
                                <p class="mb-0"><strong>Polling</strong> - Live data refreshes every 3s, charts every 30s. Polling pauses when filters are active, and resumes when all filters are cleared.</p>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="timetable-btn" data-panel="panelFilterInfo" title="Filter">
                        <i class="bi bi-funnel"></i>
                        <span class="timetable-btn-title bold">Filter</span>
                    </button>
                    <div id="panelFilterInfo" class="timetable-panel p-3 m-3">
                        <div class="section-container timetable" style="background-color:#f8f9fa;">
                            <div class="dept-member-filter">
                                <div class="dept-member-filter-header">Filter by Period</div>
                                <div class="dept-member-filter-list">
                                    <div class="dept-member-filter-item active" onclick="setPeriod(this, 1)">Today</div>
                                    <div class="dept-member-filter-item" onclick="setPeriod(this, 7)">Last 7 days</div>
                                    <div class="dept-member-filter-item" onclick="setPeriod(this, 14)">Last 14 days</div>
                                    <div class="dept-member-filter-item" onclick="setPeriod(this, 30)">Last 30 days</div>
                                </div>
                            </div>
                            <div class="dept-member-filter">
                                <div class="dept-member-filter-header">Filter by Metrics</div>
                                <div class="dept-member-filter-list">
                                    <div class="dept-member-filter-item active" onclick="setMetric(this, 'all')">All Metrics</div>
                                    <div class="dept-member-filter-item" onclick="setMetric(this, 'voltage')">Voltage</div>
                                    <div class="dept-member-filter-item" onclick="setMetric(this, 'current')">Current</div>
                                    <div class="dept-member-filter-item" onclick="setMetric(this, 'power')">Power</div>
                                </div>
                            </div>
                        </div>
                        <select id="periodSelect" onchange="onControlChange()" hidden>
                            <option value="1" selected>Today</option>
                            <option value="7">Last 7 days</option>
                            <option value="14">Last 14 days</option>
                            <option value="30">Last 30 days</option>
                        </select>
                        <?php if (count($rooms) > 1): ?>
                            <select id="roomSelect" onchange="onControlChange()" hidden>
                                <option value="0">All Rooms</option>
                                <?php foreach ($rooms as $room): ?>
                                    <option value="<?= $room['id'] ?>">
                                        <?= htmlspecialchars($room['room_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="hidden" id="roomSelect" value="<?= $rooms[0]['id'] ?? 0 ?>">
                        <?php endif; ?>
                    </div>
                </div>
                <div class="d-flex gap-2" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);">
                    <button type="button" class="timetable-btn" onclick="exportCSV()" title="Export CSV">
                        <i class="bi bi-filetype-csv"></i>
                        <span class="timetable-btn-title bold">Export<br>CSV</span>
                    </button>
                    <button type="button" class="timetable-btn" onclick="exportPDF()" title="Export PDF">
                        <i class="bi bi-filetype-pdf"></i>
                        <span class="timetable-btn-title bold">Export<br>PDF</span>
                    </button>
                </div>
                <div class="p-2" style="color:#fff;background-color:var(--secondary-color-1);border-radius:5px;overflow:hidden;position:relative;">
                    <div class="tab-text-slide" id="tabTextSlide">
                        <h2 class="text-center bold" id="tabHeading">Energy Statistics</h2>
                        <p class="text-uppercase text-center mb-0" style="font-size:14px;color:var(--accent-yellow);" id="tabSubheading">
                            All Rooms Selected
                        </p>
                    </div>
                            </div>
                        </div>

            <div class="content-area">
                <div class="analytics-grid">
                    <aside class="analytics-filters" style="display:none;">
                    </aside>

                    <div class="analytics-sidebar">

                        <!-- - Energy saved widget - -->
                        <div class="card-white savings-card" id="savingsCard">
                            <div class="chart-card-header">
                                <h3 class="chart-card-title bold"><i class="bi bi-flower1 me-1" style="color:var(--secondary-color-2);"></i>Energy Saved</h3>
                                <div class="chart-header-actions">
                                    <span class="summary-label" id="savingsPeriodLabel">&mdash;</span>
                                    <button class="light" onclick="openSavingsModal()" title="View calculation">
                                        <i class="bi bi-arrows-expand"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="savings-value-row">
                                <div class="savings-value" id="savingsValue">&mdash;</div>
                                <span class="savings-badge neutral" id="savingsBadge">
                                    <i class="bi bi-dash-lg"></i> No data
                                </span>
                            </div>
                            <p class="savings-sub">vs. the previous equal-length period</p>
                            <button type="button" class="savings-details-btn" onclick="toggleSavingsExpand()">
                                <span><i class="bi bi-calculator me-1"></i> View calculation</span>
                                <i class="bi bi-chevron-down savings-chevron" id="savingsChevron"></i>
                            </button>
                            <div class="savings-expand" id="savingsExpand">
                                <div class="savings-expand-row">
                                    <span class="summary-info-label">Formula:</span>
                                    <span class="summary-info-val">Saved % = (prev &minus; current) &divide; prev &times; 100</span>
                                </div>
                                <div class="savings-expand-row">
                                    <span class="summary-info-label">Current:</span>
                                    <span class="summary-info-val" id="savingsCurrent">&mdash;</span>
                                </div>
                                <div class="savings-expand-row">
                                    <span class="summary-info-label">Previous:</span>
                                    <span class="summary-info-val" id="savingsPrev">&mdash;</span>
                                </div>
                                <div class="savings-expand-row">
                                    <span class="summary-info-label">Change:</span>
                                    <span class="summary-info-val" id="savingsDelta">&mdash;</span>
                                </div>
                                <div class="savings-expand-desc" id="savingsDesc">
                                    Compares the current period's energy against the immediately preceding window of equal length, using the selected room and range.
                                </div>
                            </div>
                        </div>

                        <div class="card-white rooms-card">
                            <h3 class="rooms-title">Rooms <span class="rooms-deselect" onclick="deselectRoom()" title="Deselect room" data-bs-toggle="tooltip" data-bs-placement="auto">&times;</span></h3>
                            <?php foreach ($rooms as $room): ?>
                            <div class="stat-card" data-room-id="<?= $room['id'] ?>">
                                <div class="stat-card-top">
                                    <span class="stat-icon">
                                        <i class="bi bi-door-open" style="font-size:1.5rem;color:var(--secondary-color-2);"></i>
                                    </span>
                                    <div>
                                        <div class="stat-value"><?= htmlspecialchars($room['room_name']) ?><?php if (!empty($room['is_prototype'])): ?><span class="prototype-badge">Device</span><?php endif; ?></div>
                                        <p class="stat-label">Room</p>
                                    </div>
                                </div>
                                <div class="room-expand">
                                    <?php if (!empty($room['description'])): ?>
                                    <div class="room-expand-row">
                                        <i class="bi bi-info-circle"></i>
                                        <span class="room-info-label">Description:</span>
                                        <span class="room-info-val"><?= htmlspecialchars($room['description']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="room-expand-row">
                                        <i class="bi bi-aspect-ratio"></i>
                                        <span class="room-info-label">Dimension:</span>
                                        <span class="room-info-val" style="text-transform:capitalize;"><?= htmlspecialchars($room['room_size'] ?? 'medium') ?></span>
                                    </div>
                                    <div class="room-expand-row">
                                        <i class="bi bi-person-fill"></i>
                                        <span class="room-info-label">Faculty:</span>
                                        <span class="room-info-val"><?= htmlspecialchars($room['faculty_name']) ?></span>
                                    </div>
                                    <div class="room-expand-row">
                                        <i class="bi bi-clock-fill"></i>
                                        <span class="room-info-label"><?php
                                            if ($room['is_occupied']):
                                                echo 'Current class:';
                                            elseif ($room['next_start_time']):
                                                echo 'Next class:';
                                            else:
                                                echo 'Next class:';
                                            endif;
                                        ?></span>
                                        <span class="room-info-val">
                                            <?php if ($room['is_occupied']): ?>
                                                <?= date('g:i A', strtotime($room['start_time'])) ?> &ndash; <?= date('g:i A', strtotime($room['end_time'])) ?>
                                            <?php elseif ($room['next_start_time']): ?>
                                                <?= date('g:i A', strtotime($room['next_start_time'])) ?> &ndash; <?= date('g:i A', strtotime($room['next_end_time'])) ?>
                                            <?php else: ?>
                                                No classes scheduled
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="room-expand-row">
                                        <i class="bi bi-lightbulb-fill"></i>
                                        <span class="room-info-label">Lighting:</span>
                                        <span><span class="light-dot <?= $room['light_status'] === 'on' ? 'on' : 'off' ?>"></span><span class="room-info-val"><?= $room['light_status'] === 'on' ? 'ON' : 'OFF' ?></span></span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- - Summary live-card - -->
                        <div class="live-card">
                            <div class="live-card-header" style="margin-bottom:10px;">
                                <span class="chart-card-title bold">Summary</span>
                                <span class="summary-label"><?= date('F j, Y') ?></span>
                            </div>
                            <div class="summary-column">
                                <div class="live-stat-card">
                                    <div class="summary-row">
                                        <div class="live-stat-val" id="sumEnergy">-</div>
                                        <div class="live-stat-label">Total Energy (kWh)</div>
                                    </div>
                                    <div class="summary-expand">
                                        <i class="bi bi-calculator"></i>
                                        <span class="summary-info-label">Formula:</span>
                                        <span class="summary-info-val">kWh = &Sigma;(energy_wh) &divide; 1000</span>
                                        <span class="summary-info-desc">Total watt-hours from all completed sessions divided by 1000.</span>
                                    </div>
                                </div>
                                <div class="live-stat-card">
                                    <div class="summary-row">
                                        <div class="live-stat-val" id="sumMinutes">-</div>
                                        <div class="live-stat-label">Total Occupied (hrs)</div>
                                    </div>
                                    <div class="summary-expand">
                                        <i class="bi bi-calculator"></i>
                                        <span class="summary-info-label">Formula:</span>
                                        <span class="summary-info-val">hrs = &Sigma;(duration_mins) &divide; 60</span>
                                        <span class="summary-info-desc">Total minutes lights were on across all sessions divided by 60.</span>
                                    </div>
                                </div>
                                <div class="live-stat-card">
                                    <div class="summary-row">
                                        <div class="live-stat-val" id="sumVoltage">-</div>
                                        <div class="live-stat-label">Avg Voltage (V)</div>
                                    </div>
                                    <div class="summary-expand">
                                        <i class="bi bi-calculator"></i>
                                        <span class="summary-info-label">Formula:</span>
                                        <span class="summary-info-val">V<sub>avg</sub> = AVG(avg_voltage)</span>
                                        <span class="summary-info-desc">Average voltage recorded across all completed power sessions.</span>
                                    </div>
                                </div>
                                <div class="live-stat-card">
                                    <div class="summary-row">
                                        <div class="live-stat-val" id="sumCurrent">-</div>
                                        <div class="live-stat-label">Avg Current (A)</div>
                                    </div>
                                    <div class="summary-expand">
                                        <i class="bi bi-calculator"></i>
                                        <span class="summary-info-label">Formula:</span>
                                        <span class="summary-info-val">A<sub>avg</sub> = AVG(avg_current)</span>
                                        <span class="summary-info-desc">Average current draw recorded across all completed power sessions.</span>
                                    </div>
                                </div>
                                <div class="live-stat-card">
                                    <div class="summary-row">
                                        <div class="live-stat-val" id="sumPower">-</div>
                                        <div class="live-stat-label">Peak Power (W)</div>
                                    </div>
                                    <div class="summary-expand">
                                        <i class="bi bi-calculator"></i>
                                        <span class="summary-info-label">Formula:</span>
                                        <span class="summary-info-val">W<sub>peak</sub> = MAX(peak_power)</span>
                                        <span class="summary-info-desc">Highest instantaneous power draw recorded across all completed sessions.</span>
                                    </div>
                                </div>
                                <div class="live-stat-card">
                                    <div class="summary-row">
                                        <div class="live-stat-val" id="sumCost">-</div>
                                        <div class="live-stat-label">Est. Cost (PHP)</div>
                                    </div>
                                    <div class="summary-expand">
                                        <i class="bi bi-calculator"></i>
                                        <span class="summary-info-label">Formula:</span>
                                        <span class="summary-info-val">Cost = Total kWh &times; &dollar;11.00/kWh</span>
                                        <span class="summary-info-desc">Estimated cost using the national average rate of &#x20B1;11.00 per kWh.</span>
                                    </div>
                                </div>
                                <div class="live-stat-card">
                                    <div class="summary-row">
                                        <div class="live-stat-val" id="sumAnomalies">-</div>
                                        <div class="live-stat-label">Anomalies</div>
                                    </div>
                                    <div class="summary-expand">
                                        <i class="bi bi-exclamation-triangle-fill"></i>
                                        <span class="summary-info-label">Source:</span>
                                        <span class="summary-info-val">room_logs (issue_raised)</span>
                                        <span class="summary-info-desc">Total energy dropouts (lights ON, power ~0W) and power spikes detected during the selected period.</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <main class="analytics-main">

                <!-- - Live readings - -->
                <div class="live-card">
                    <div class="live-card-header">
                        <span class="chart-card-title bold">Live Readings</span>
                        <span class="live-badge" id="liveBadge">
                            <span class="live-dot"></span> Live
                        </span>
                    </div>
                    <div class="live-readings-row">
                        <div class="live-readings-group" id="vawGroup">
                            <div class="live-stat-card" data-metric="voltage">
                                <div class="live-stat-val" id="liveVoltage">- V</div>
                                <div class="live-stat-label">Voltage</div>
                            </div>
                            <div class="live-stat-card" data-metric="current">
                                <div class="live-stat-val" id="liveCurrent">- A</div>
                                <div class="live-stat-label">Current</div>
                            </div>
                            <div class="live-stat-card" data-metric="power">
                                <div class="live-stat-val" id="livePower">- W</div>
                                <div class="live-stat-label">Power</div>
                            </div>
                        </div>
                        <div class="live-readings-group vaw-group">
                            <div class="live-stat-card">
                                <div class="live-stat-val" id="liveEnergy">- Wh</div>
                                <div class="live-stat-label">Energy (session)</div>
                            </div>
                            <div class="live-stat-card">
                                <div class="live-stat-row">
                                    <span class="live-status-dot" id="liveStatusDot"></span>
                                    <span class="live-stat-val" id="liveStatus">-</span>
                                </div>
                                <div class="live-stat-label">Light Status</div>
                            </div>
                        </div>
                    </div>
                    <div class="metric-info" id="metricInfo">
                        <span class="metric-info-text">Voltage, Current, and Power readings are used to compute Energy (Wh) over time. <span class="metric-formula">Energy (Wh) = Power (W) &times; Time (h)</span></span>
                    </div>
                </div>

                <div class="chart-grid">
                    <div class="card-white" id="lineGraphCard">
                        <div class="chart-card-header">
                            <h3 class="chart-card-title bold" id="lineChartTitle">Line Graph</h3>
                            <div class="chart-header-actions">
                                <span class="summary-label" id="lineMetricLabel">All Metrics</span>
                                <button class="light" onclick="toggleChartMaximize('lineGraphCard')" title="Maximize">
                                    <i class="bi bi-arrows-expand"></i>
                                </button>
                            </div>
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="lineChart"></canvas>
                        </div>
                        <div class="chart-scrollbar-wrap" id="lineChartScrollWrap">
                            <input type="range" class="chart-scrollbar" id="lineChartScroll" min="0" max="0" value="0" oninput="onChartScroll('lineChart', this.value)">
                            <div class="chart-scroll-tip" id="lineChartScrollTip"></div>
                            <div class="chart-scroll-pending" id="lineChartScrollPending"></div>
                        </div>
                    </div>
                    <div class="card-white" id="barGraphCard">
                        <div class="chart-card-header">
                            <h3 class="chart-card-title bold" id="barChartTitle">Vertical Bar Graph</h3>
                            <div class="chart-header-actions">
                                <span class="summary-label" id="barMetricLabel">All Metrics</span>
                                <button class="light" onclick="toggleChartMaximize('barGraphCard')" title="Maximize">
                                    <i class="bi bi-arrows-expand"></i>
                                </button>
                            </div>
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="barChart"></canvas>
                        </div>
                        <div class="chart-scrollbar-wrap" id="barChartScrollWrap">
                            <input type="range" class="chart-scrollbar" id="barChartScroll" min="0" max="0" value="0" oninput="onChartScroll('barChart', this.value)">
                            <div class="chart-scroll-tip" id="barChartScrollTip"></div>
                            <div class="chart-scroll-pending" id="barChartScrollPending"></div>
                        </div>
                    </div>
                </div>

                <!-- - Daily history table - -->
                <div class="card-white" id="historyCard">

                    <div class="breakdown-header" style="margin-top:18px;margin-bottom:14px;">
                        <div class="breakdown-title-row">
                            <span class="breakdown-title bold" id="historyTitle">7-Day History</span>
                        </div>
                        <div class="history-table-wrapper">
                            <table class="breakdown-table">
                                <thead id="historyHead">
                                    <tr>
                                        <th style="text-align:left;">Date</th>
                                        <th>Sessions</th>
                                        <th>Occupied Time</th>
                                        <th>Energy (Wh)</th>
                                        <th>Energy (kWh)</th>
                                    </tr>
                                </thead>
                                <tbody id="historyBody">
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">Loading...</td>
                                    </tr>
                                </tbody>
                                <tfoot id="historyFoot"></tfoot>
                            </table>
                        </div>
                    </div>

                </div>
                </main><!-- /analytics-main -->
                </div><!-- /analytics-grid -->
            </div><!-- /content-area -->
        </div><!-- /child-container -->
        </div><!-- /parent-container -->

        <!-- Export selection modal -->
        <div class="modal fade" id="exportModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="border-radius:12px;overflow:hidden;">
                    <div class="modal-header" style="background:var(--secondary-color-1);color:#fff;padding:12px 20px;">
                        <h6 class="modal-title bold" id="exportModalTitle">Export</h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-3">
                        <p class="mb-2" style="font-size:13px;color:#666;">Select a section to export:</p>
                        <div class="d-flex flex-column gap-2">
                            <button type="button" class="export-option-btn" onclick="exportSection('lineGraphCard')">
                                <i class="bi bi-graph-up"></i>
                                <span>Line Graph</span>
                            </button>
                            <button type="button" class="export-option-btn" onclick="exportSection('barGraphCard')">
                                <i class="bi bi-bar-chart"></i>
                                <span>Vertical Bar Graph</span>
                            </button>
                            <button type="button" class="export-option-btn" onclick="exportSection('historyCard')">
                                <i class="bi bi-table"></i>
                                <span>History Table</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Energy saved calculation modal -->
        <div class="modal fade" id="savingsModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="border-radius:12px;overflow:hidden;">
                    <div class="modal-header" style="background:var(--secondary-color-1);color:#fff;padding:12px 20px;">
                        <h6 class="modal-title bold"><i class="bi bi-flower1 me-1"></i>Energy Saved Calculation</h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-3">
                        <div class="savings-modal-hero">
                            <div class="savings-modal-value" id="savingsModalValue">&mdash;</div>
                            <span class="savings-modal-badge neutral" id="savingsModalBadge"><i class="bi bi-dash-lg"></i> No data</span>
                        </div>
                        <div class="savings-modal-compare">
                            <div class="savings-modal-col">
                                <div class="savings-modal-col-label">Current period</div>
                                <div class="savings-modal-col-value" id="savingsModalCurrent">&mdash;</div>
                                <div class="savings-modal-bar-wrap"><div class="savings-modal-bar" id="savingsModalCurrentBar"></div></div>
                            </div>
                            <div class="savings-modal-col">
                                <div class="savings-modal-col-label">Previous period</div>
                                <div class="savings-modal-col-value" id="savingsModalPrev">&mdash;</div>
                                <div class="savings-modal-bar-wrap"><div class="savings-modal-bar prev" id="savingsModalPrevBar"></div></div>
                            </div>
                        </div>
                        <div class="savings-modal-formula">
                            <span class="summary-info-label">Formula:</span>
                            <span class="summary-info-val">Saved % = (prev &minus; current) &divide; prev &times; 100</span>
                        </div>
                        <p class="savings-modal-note mb-0" id="savingsModalNote">&mdash;</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Issue detail modal -->
        <div class="modal fade" id="issueDetailModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="border-radius:12px;overflow:hidden;">
                    <div class="modal-header" style="background:#842029;color:#fff;padding:12px 20px;">
                        <h6 class="modal-title bold"><i class="bi bi-exclamation-triangle-fill me-1"></i>Issue Details</h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-3">
                        <div class="issue-detail-row">
                            <span class="issue-detail-label">Status:</span>
                            <span class="issue-detail-status" id="issueStatus">Issue Raised</span>
                        </div>
                        <div class="issue-detail-row">
                            <span class="issue-detail-label">Room:</span>
                            <span class="issue-detail-val" id="issueRoom">&mdash;</span>
                        </div>
                        <div class="issue-detail-row">
                            <span class="issue-detail-label">Source:</span>
                            <span class="issue-detail-val" id="issueSource">&mdash;</span>
                        </div>
                        <div class="issue-detail-row">
                            <span class="issue-detail-label">Detected:</span>
                            <span class="issue-detail-val" id="issueTime">&mdash;</span>
                        </div>
                        <div class="issue-detail-row issue-detail-notes">
                            <span class="issue-detail-label">Notes:</span>
                            <span class="issue-detail-val" id="issueNotes">&mdash;</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php include __DIR__ . "/../../src/Includes/profile-offcanvas.php"; ?>

        <script src="../../js/lib/animations.js"></script>
        <script src="../../js/lib/toggles.js"></script>
        <script src="../../js/lib/tooltip.js"></script>
        <link rel="stylesheet" href="../../css/pages/admin-analytics.css">

        <script>
            const roomData = <?= json_encode($roomDataFromPHP, JSON_HEX_TAG) ?>;
            const defaultCid = <?= (int)($rooms[0]['id'] ?? 3) ?>;
        </script>


        <script src="../../js/admin/admin-analytics.js?v=<?= time() ?>"></script>
        <script src="../../js/faculty/faculty-tutorial.js"></script>

</body>

</html>
<?php if (isset($conn)) $conn->close(); ?>