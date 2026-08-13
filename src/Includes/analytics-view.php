<?php
// Shared Analytics view body — ported from admin-analytics.php and embedded into the
// admin-overview Live dashboard. Expected variables:
//   $rooms        (array) each with id, room_name, room_size, description, is_prototype,
//                 light_status, faculty_name, and either analytics keys
//                 (is_occupied / start_time / end_time / next_start_time / next_end_time)
//                 or overview keys (status / current_time / next_time).
//   $faculty_list (array) each with id, first_name, last_name, classroom_name,
//                 department_name (+ optional subject_name).
if (!isset($rooms)) $rooms = [];
if (!isset($faculty_list)) $faculty_list = [];

// Room name (lower) → faculty id for cross-selection.
$facIdByRoomName = [];
$facNameById = [];
foreach ($faculty_list as $_f) {
    $fid = (int)($_f['id'] ?? 0);
    if (!$fid) continue;
    $facIdByRoomName[strtolower((string)($_f['classroom_name'] ?? ''))] = $fid;
    $facNameById[$fid] = trim(($_f['first_name'] ?? '') . ' ' . ($_f['last_name'] ?? ''));
}
?>
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

            <!-- - Rooms slicer (multi-select) - -->
            <div class="card-white rooms-card">
                <h3 class="rooms-title">Rooms <span class="rooms-deselect" onclick="deselectRoom()" title="Deselect rooms" data-bs-toggle="tooltip" data-bs-placement="auto">&times;</span></h3>
                <?php foreach ($rooms as $room):
                    $rid = (int)$room['id'];
                    $fid = $facIdByRoomName[strtolower((string)$room['room_name'])] ?? 0;
                    $classLabel = 'Next class:';
                    $classRange = 'No classes scheduled';
                    if (!empty($room['is_occupied']) && !empty($room['start_time'])) {
                        $classLabel = 'Current class:';
                        $classRange = date('g:i A', strtotime($room['start_time'])) . ' &ndash; ' . date('g:i A', strtotime($room['end_time']));
                    } elseif (!empty($room['next_start_time'])) {
                        $classLabel = 'Next class:';
                        $classRange = date('g:i A', strtotime($room['next_start_time'])) . ' &ndash; ' . date('g:i A', strtotime($room['next_end_time']));
                    } elseif (($room['status'] ?? '') === 'occupied' && !empty($room['current_time'])) {
                        $classLabel = 'Current class:';
                        $classRange = $room['current_time'];
                    } elseif (!empty($room['next_time'])) {
                        $classLabel = 'Next class:';
                        $classRange = $room['next_time'];
                    }
                ?>
                <div class="stat-card" data-room-id="<?= $rid ?>"
                    data-room-name="<?= h(strtolower((string)$room['room_name'])) ?>"
                    <?php if ($fid): ?>data-faculty-id="<?= $fid ?>"<?php endif; ?>>
                    <div class="stat-card-top">
                        <span class="stat-icon">
                            <i class="bi bi-door-open" style="font-size:1.5rem;color:var(--secondary-color-2);"></i>
                        </span>
                        <div>
                            <div class="stat-value"><?= h($room['room_name']) ?><?php if (!empty($room['is_prototype'])): ?><span class="prototype-badge">Device</span><?php endif; ?></div>
                            <p class="stat-label">Room</p>
                        </div>
                    </div>
                    <div class="room-expand">
                        <?php if (!empty($room['description'])): ?>
                        <div class="room-expand-row">
                            <i class="bi bi-info-circle"></i>
                            <span class="room-info-label">Description:</span>
                            <span class="room-info-val"><?= h($room['description']) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="room-expand-row">
                            <i class="bi bi-aspect-ratio"></i>
                            <span class="room-info-label">Dimension:</span>
                            <span class="room-info-val" style="text-transform:capitalize;"><?= h($room['room_size'] ?? 'medium') ?></span>
                        </div>
                        <div class="room-expand-row">
                            <i class="bi bi-person-fill"></i>
                            <span class="room-info-label">Faculty:</span>
                            <span class="room-info-val"><?= h($room['faculty_name'] ?? '—') ?></span>
                        </div>
                        <div class="room-expand-row">
                            <i class="bi bi-clock-fill"></i>
                            <span class="room-info-label"><?= $classLabel ?></span>
                            <span class="room-info-val"><?= $classRange ?></span>
                        </div>
                        <div class="room-expand-row">
                            <i class="bi bi-lightbulb-fill"></i>
                            <span class="room-info-label">Lighting:</span>
                            <span><span class="light-dot <?= (($room['light_status'] ?? 'off') === 'on') ? 'on' : 'off' ?>"></span><span class="room-info-val"><?= (($room['light_status'] ?? 'off') === 'on') ? 'ON' : 'OFF' ?></span></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- - Faculty slicer (multi-select, cross-synced with rooms) - -->
            <div class="card-white rooms-card faculty-slice-card">
                <h3 class="rooms-title">Faculty <?php if (!empty($faculty_list)): ?><span class="rooms-deselect" id="facultyDeselect" onclick="deselectAllFaculties()" title="Deselect faculty" data-bs-toggle="tooltip" data-bs-placement="auto">&times;</span><?php endif; ?></h3>
                <?php foreach ($faculty_list as $fac):
                    $fid = (int)$fac['id'];
                    if (!$fid) continue;
                    $facName = trim(($fac['first_name'] ?? '') . ' ' . ($fac['last_name'] ?? ''));
                    $facRoom = (string)($fac['classroom_name'] ?? '');
                    $facSub  = trim((string)($fac['subject_name'] ?? ''));
                    $facDept = trim((string)($fac['department_name'] ?? ''));
                    $facMeta = $facRoom !== '' ? $facRoom : ($facSub !== '' ? $facSub : ($facDept !== '' ? $facDept : 'Faculty'));
                    $roomIdByFac = 0;
                    if ($facRoom !== '') {
                        foreach ($rooms as $_r) {
                            if (strcasecmp((string)$_r['room_name'], $facRoom) === 0) { $roomIdByFac = (int)$_r['id']; break; }
                        }
                    }
                ?>
                <div class="stat-card faculty-stat-card" data-faculty-id="<?= $fid ?>"
                    data-room-name="<?= h(strtolower($facRoom)) ?>"
                    <?php if ($roomIdByFac): ?>data-room-id="<?= $roomIdByFac ?>"<?php endif; ?>>
                    <div class="stat-card-top">
                        <span class="stat-icon">
                            <i class="bi bi-person-badge" style="font-size:1.5rem;color:var(--secondary-color-2);"></i>
                        </span>
                        <div>
                            <div class="stat-value"><?= h($facName) ?></div>
                            <p class="stat-label"><?= h($facMeta) ?></p>
                        </div>
                    </div>
                    <div class="room-expand">
                        <div class="room-expand-row">
                            <i class="bi bi-mortarboard"></i>
                            <span class="room-info-label">Subject:</span>
                            <span class="room-info-val"><?= h($facSub !== '' ? $facSub : '—') ?></span>
                        </div>
                        <?php if (!empty($fac['current_class'])): ?>
                        <div class="room-expand-row">
                            <i class="bi bi-play-circle-fill"></i>
                            <span class="room-info-label">In class now:</span>
                            <span class="room-info-val"><?= h($fac['current_class']) ?></span>
                        </div>
                        <?php elseif (!empty($fac['next_class'])): ?>
                        <div class="room-expand-row">
                            <i class="bi bi-clock-fill"></i>
                            <span class="room-info-label">Next class:</span>
                            <span class="room-info-val"><?= h($fac['next_class']) ?></span>
                        </div>
                        <?php else: ?>
                        <div class="room-expand-row">
                            <i class="bi bi-calendar-x"></i>
                            <span class="room-info-label">Next class:</span>
                            <span class="room-info-val">None today</span>
                        </div>
                        <?php endif; ?>
                        <?php if ($facDept !== ''): ?>
                        <div class="room-expand-row">
                            <i class="bi bi-diagram-3"></i>
                            <span class="room-info-label">Department:</span>
                            <span class="room-info-val"><?= h($facDept) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="room-expand-row">
                            <i class="bi bi-patch-check"></i>
                            <span class="room-info-label">Approved on:</span>
                            <span class="room-info-val"><?= h(!empty($fac['approved_on']) ? $fac['approved_on'] : '—') ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($faculty_list)): ?><p class="text-muted small mb-0">No faculty members yet.</p><?php endif; ?>
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

            <div class="chart-carousel" id="chartCarousel">
                <button type="button" class="chart-nav chart-nav-left" id="chartNavLeft" title="Show line graph" onclick="chartCarouselStep(-1)" aria-label="Show line graph">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <div class="chart-track" id="chartTrack">
                <div class="card-white" id="lineGraphCard">
                    <div class="chart-card-header">
                        <h3 class="chart-card-title bold" id="lineChartTitle">Readings of All Rooms</h3>
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
                        <h3 class="chart-card-title bold" id="barChartTitle">Readings of All Rooms</h3>
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
                <button type="button" class="chart-nav chart-nav-right" id="chartNavRight" title="Show bar graph" onclick="chartCarouselStep(1)" aria-label="Show bar graph">
                    <i class="bi bi-chevron-right"></i>
                </button>
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

            <!-- - Summary report of findings - -->
            <div class="card-white findings-card" id="findingsCard" style="display:none;">
                <div class="breakdown-header">
                    <div class="breakdown-title-row">
                        <span class="breakdown-title bold" id="findingsTitle">Summary Report</span>
                        <span class="summary-label" id="findingsSub"></span>
                    </div>
                    <div class="findings-chips" id="findingsChips">
                        <div class="findings-chip">
                            <span class="findings-chip-icon"><i class="bi bi-lightning-charge-fill"></i></span>
                            <div class="findings-chip-body">
                                <span class="findings-chip-value" id="findingsEnergy">—</span>
                                <span class="findings-chip-label">Total Energy</span>
                            </div>
                            <canvas class="findings-chip-canvas" id="findingsSparkEnergy"></canvas>
                        </div>
                        <div class="findings-chip">
                            <span class="findings-chip-icon"><i class="bi bi-cash-coin"></i></span>
                            <div class="findings-chip-body">
                                <span class="findings-chip-value" id="findingsCost">—</span>
                                <span class="findings-chip-label">Est. Cost</span>
                            </div>
                            <canvas class="findings-chip-canvas" id="findingsSparkCost"></canvas>
                        </div>
                        <div class="findings-chip">
                            <span class="findings-chip-icon"><i class="bi bi-clock-fill"></i></span>
                            <div class="findings-chip-body">
                                <span class="findings-chip-value" id="findingsOccupied">—</span>
                                <span class="findings-chip-label">Occupied Time</span>
                            </div>
                        </div>
                        <div class="findings-chip">
                            <span class="findings-chip-icon"><i class="bi bi-play-circle-fill"></i></span>
                            <div class="findings-chip-body">
                                <span class="findings-chip-value" id="findingsSessions">—</span>
                                <span class="findings-chip-label">Sessions</span>
                            </div>
                        </div>
                        <div class="findings-chip">
                            <span class="findings-chip-icon"><i class="bi bi-exclamation-triangle-fill"></i></span>
                            <div class="findings-chip-body">
                                <span class="findings-chip-value" id="findingsAnomalyCount">—</span>
                                <span class="findings-chip-label">Anomalies</span>
                            </div>
                        </div>
                    </div>
                    <div class="findings-mini-chart">
                        <div class="chart-card-header">
                            <span class="chart-card-title bold" id="findingsChartLabel">Energy Profile</span>
                        </div>
                        <div class="chart-wrapper" style="height:150px;">
                            <canvas id="findingsMiniChart"></canvas>
                        </div>
                        <div class="chart-scrollbar-wrap" id="findingsScrollWrap">
                            <input type="range" class="chart-scrollbar" id="findingsScroll" min="0" max="0" value="0" oninput="onFindingsScroll(this.value)">
                            <div class="chart-scroll-tip" id="findingsScrollTip"></div>
                        </div>
                    </div>
                    <div class="findings-list" id="findingsList"></div>
                    <div class="findings-anomalies" id="findingsAnomalies" style="display:none;"></div>
                </div>
            </div>
        </main><!-- /analytics-main -->
    </div><!-- /analytics-grid -->
</div><!-- /content-area -->

