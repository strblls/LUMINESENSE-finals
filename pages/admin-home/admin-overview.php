<?php
$page_title = 'Rooms & Analytics';
require_once __DIR__ . "/../../src/Includes/admin-head.php";
date_default_timezone_set('Asia/Manila');

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ═══════════════════════════════════════════════════════════════════════════
   STATIC MODE — mock/preview data.
   Everything between [STATIC_BEGIN] and [STATIC_END] is hardcoded so the
   combined overview design can be reviewed before live wiring.
   ▸ Send "Banana" to replace this block with live DB queries.
   ═══════════════════════════════════════════════════════════════════════════ */
// [STATIC_BEGIN]
$STATIC_MODE = true;

// ── Rooms — ordered functioning-first (live → occupied → scheduled → vacant → name)
$rooms = [
    [
        'id' => 3,
        'room_name' => 'SEL 1',
        'room_size' => 'medium',
        'description' => 'Lecture',
        'is_prototype' => 1,
        'row1_status' => 'on',
        'row2_status' => 'on',
        'row3_status' => 'off',
        'light_status' => 'on',
        'pir_occupied' => 1,
        'voltage_v' => 221.9,
        'current_a' => 0.054,
        'power_w' => 7.6,
        'energy_wh' => 0.044,
        'fresh_secs' => 12,
        'is_live' => true,
        'status' => 'occupied',
        'faculty_name' => 'Jaz Entapa',
        'current_time' => '10:30 AM – 12:00 PM',
        'next_time' => '',
        'dept' => 'Science',
        'subject_area' => 'Sciences',
        'subject' => 'Physics',
        'spark' => [0.0, 0.1, 0.3, 0.6, 1.1, 1.9, 2.6],
        'schedules' => [
            ['day_of_week' => 'Monday',    'start_time' => '10:30 AM', 'end_time' => '12:00 PM', 'faculty_name' => 'Jaz Entapa'],
            ['day_of_week' => 'Wednesday', 'start_time' => '01:00 PM', 'end_time' => '02:30 PM', 'faculty_name' => 'Jaz Entapa'],
            ['day_of_week' => 'Friday',    'start_time' => '08:00 AM', 'end_time' => '09:30 AM', 'faculty_name' => 'Jimar Intapa'],
        ],
        'alerts' => [
            ['event_type' => 'light_on',     'triggered_by' => 'admin',   'event_time' => '2026-08-02 10:30:00'],
            ['event_type' => 'class_start',  'triggered_by' => 'schedule', 'event_time' => '2026-08-02 10:30:00'],
            ['event_type' => 'pir_motion',   'triggered_by' => 'PIR',     'event_time' => '2026-08-02 10:28:41'],
        ],
    ],
    [
        'id' => 9,
        'room_name' => 'SEL 2',
        'room_size' => 'large',
        'description' => 'Laboratories',
        'is_prototype' => 1,
        'row1_status' => 'on',
        'row2_status' => 'on',
        'row3_status' => 'off',
        'light_status' => 'on',
        'pir_occupied' => 0,
        'voltage_v' => 230.1,
        'current_a' => 0.031,
        'power_w' => 4.2,
        'energy_wh' => 0.010,
        'fresh_secs' => 8,
        'is_live' => true,
        'status' => 'vacant',
        'faculty_name' => '',
        'current_time' => '',
        'next_time' => '01:00 PM – 03:00 PM',
        'dept' => 'TLE',
        'subject_area' => 'Vocational',
        'subject' => 'Electronics',
        'spark' => [0.4, 0.2, 0.1, 0.0, 0.3, 0.1, 0.0],
        'schedules' => [
            ['day_of_week' => 'Tuesday',   'start_time' => '01:00 PM', 'end_time' => '03:00 PM', 'faculty_name' => 'August Uno'],
            ['day_of_week' => 'Thursday',  'start_time' => '01:00 PM', 'end_time' => '03:00 PM', 'faculty_name' => 'August Uno'],
        ],
        'alerts' => [
            ['event_type' => 'light_on',   'triggered_by' => 'admin',   'event_time' => '2026-08-02 09:12:00'],
            ['event_type' => 'door_open',  'triggered_by' => 'sensor',  'event_time' => '2026-08-02 09:10:22'],
        ],
    ],
    [
        'id' => 15,
        'room_name' => 'SEL 4',
        'room_size' => 'small',
        'description' => 'Discussion',
        'is_prototype' => 0,
        'row1_status' => 'off',
        'row2_status' => 'off',
        'row3_status' => 'off',
        'light_status' => 'off',
        'pir_occupied' => 0,
        'voltage_v' => null,
        'current_a' => null,
        'power_w' => null,
        'energy_wh' => null,
        'fresh_secs' => null,
        'is_live' => false,
        'status' => 'scheduled',
        'faculty_name' => '',
        'current_time' => '',
        'next_time' => 'Tue, Aug 4 · 08:00 AM',
        'dept' => 'Mathematics',
        'subject_area' => 'Mathematics',
        'subject' => 'Algebra',
        'spark' => [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
        'schedules' => [
            ['day_of_week' => 'Tuesday',  'start_time' => '08:00 AM', 'end_time' => '09:30 AM', 'faculty_name' => 'Jimar Intapa'],
            ['day_of_week' => 'Thursday', 'start_time' => '10:00 AM', 'end_time' => '11:30 AM', 'faculty_name' => 'Jimar Intapa'],
        ],
        'alerts' => [],
    ],
    [
        'id' => 12,
        'room_name' => 'SEL 3',
        'room_size' => 'medium',
        'description' => 'Lecture',
        'is_prototype' => 0,
        'row1_status' => 'off',
        'row2_status' => 'off',
        'row3_status' => 'off',
        'light_status' => 'off',
        'pir_occupied' => 0,
        'voltage_v' => null,
        'current_a' => null,
        'power_w' => null,
        'energy_wh' => null,
        'fresh_secs' => null,
        'is_live' => false,
        'status' => 'vacant',
        'faculty_name' => '',
        'current_time' => '',
        'next_time' => '',
        'dept' => 'English',
        'subject_area' => 'Languages',
        'subject' => 'Literature',
        'spark' => [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
        'schedules' => [
            ['day_of_week' => 'Monday',   'start_time' => '07:30 AM', 'end_time' => '09:00 AM', 'faculty_name' => 'Sofia Santos'],
            ['day_of_week' => 'Friday',   'start_time' => '02:00 PM', 'end_time' => '03:30 PM', 'faculty_name' => 'Sofia Santos'],
        ],
        'alerts' => [
            ['event_type' => 'issue_raised', 'triggered_by' => 'system', 'event_time' => '2026-08-01 14:00:00'],
        ],
    ],
];

// ── Summary quick cards ──
$summary = [
    'total_energy_kwh' => 1.284,
    'total_minutes'    => 452,
    'avg_voltage'      => 222.5,
    'avg_current'      => 0.052,
    'peak_power_w'     => 13.8,
    'est_cost_php'     => 14.12,
    'total_anomalies'  => 2,
];

// 7-day mini trends for the summary cards
$sparkSummary = [
    'energy'  => [0.0, 0.1, 0.4, 0.9, 1.4, 2.1, 3.2],
    'minutes' => [20, 35, 60, 90, 120, 150, 180],
    'voltage' => [220.1, 221.3, 222.0, 221.5, 222.2, 223.0, 222.5],
    'current' => [0.040, 0.045, 0.050, 0.048, 0.052, 0.055, 0.052],
    'power'   => [5.2, 6.1, 7.4, 8.0, 10.2, 11.5, 12.1],
    'cost'    => [0.0, 1.1, 2.4, 4.9, 7.7, 11.5, 14.1],
];

// ── Chart data: multi-day aggregate ──
$chartDaily = [];
$d_energy = [0.0, 0.1, 0.4, 0.9, 1.4, 2.1, 3.2];
$d_volt   = [220.1, 221.3, 222.0, 221.5, 222.2, 223.0, 222.5];
$d_curr   = [0.040, 0.045, 0.050, 0.048, 0.052, 0.055, 0.052];
$d_power  = [5.2, 6.1, 7.4, 8.0, 10.2, 11.5, 12.1];
$d_sess   = [0, 1, 2, 3, 4, 5, 6];
$d_mins   = [0, 18, 42, 61, 92, 118, 146];
for ($i = 6; $i >= 0; $i--) {
    $t = strtotime("-$i days");
    $chartDaily[] = [
        'date'        => date('Y-m-d', $t),
        'label'       => date('D M d', $t),
        'energy_wh'   => $d_energy[6 - $i],
        'energy_kwh'  => round($d_energy[6 - $i] / 1000, 4),
        'sessions'    => $d_sess[6 - $i],
        'minutes'     => $d_mins[6 - $i],
        'avg_voltage' => $d_volt[6 - $i],
        'avg_current' => $d_curr[6 - $i],
        'avg_power'   => $d_power[6 - $i],
    ];
}

// ── Chart data: today (5-min interval slots) ──
$chartToday = [];
$t0 = strtotime('today 08:00');
for ($i = 0; $i < 10; $i++) {
    $chartToday[] = [
        'label'         => date('H:i', $t0 + $i * 600),
        'time'          => date('H:i', $t0 + $i * 600),
        'avg_voltage'   => round(221.9 + ($i % 3) * 0.4, 1),
        'avg_current'   => 0.05,
        'avg_power'     => round(6.0 + $i * 0.7, 1),
        'energy_wh'     => round(0.02 * ($i + 1), 4),
        'reading_count' => 3,
    ];
}
// [STATIC_END]
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LumineSense - Rooms &amp; Analytics</title>

    <!--External links-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!--Relative links-->
    <link rel="icon" type="image/png" sizes="32x32" href="../../images/icon.png">
    <link rel="shortcut icon" type="image/png" href="../../images/icon.png">
    <link rel="stylesheet" href="../../css/base/global.css">
    <link rel="stylesheet" href="../../css/base/containers.css">
    <link rel="stylesheet" href="../../css/base/modals.css">
    <link rel="stylesheet" href="../../css/base/tooltip.css">
    <link rel="stylesheet" href="../../css/admin/common.css">
    <link rel="stylesheet" href="../../css/admin/timetable.css">
    <link rel="stylesheet" href="../../css/faculty/timetable.css">
    <link rel="stylesheet" href="../../css/faculty/head-timetable.css">
    <link rel="stylesheet" href="../../css/admin/room-manage.css">
    <link rel="stylesheet" href="../../css/admin/analytics.css">
    <link rel="stylesheet" href="../../css/admin/overview.css">
</head>

<body class="contrast-bg">
    <?php include __DIR__ . "/../../src/Includes/admin-topbar.php"; ?>
    <?php include __DIR__ . "/../../src/Includes/admin-sidebar.php"; ?>

    <div class="parent-container">
        <div class="child-container">
            <div class="page-content">

                <!-- ═══════════ MERGED HEADER BAR ═══════════ -->
                <div class="main-container overview-heading d-flex align-items-center justify-content-between w-auto">
                    <div class="d-flex align-items-center gap-2" style="position:absolute;left:8px;top:50%;transform:translateY(-50%);">
                        <button type="button" class="timetable-btn ms-2" data-panel="panelGuide" title="Guide">
                            <i class="bi bi-info-lg"></i><span class="timetable-btn-title bold">Guide</span>
                        </button>
                        <div id="panelGuide" class="timetable-panel p-3 m-3">
                            <div class="section-container timetable" style="background-color:#f8f9fa;width:340px;">
                                <h6 class="bold mb-2"><i class="bi bi-info-circle me-1"></i>Rooms &amp; Analytics Guide</h6>
                                <ol class="ps-3 mb-0" style="font-size:12px;line-height:1.7;">
                                    <li><strong>Room Management</strong> — the rooms at a glance are combined here with the management grid.</li>
                                    <li>Functioning (LIVE) rooms are shown first, then by status: Occupied → Scheduled → Vacant.</li>
                                    <li>Each room card shows all three light rows (R1/R2/R3), current faculty, live V/A/W, and a 7-day energy sparkline.</li>
                                    <li>Click a room card here to select it and filter the analytics section below to that room.</li>
                                    <li>Use <strong>Inspect</strong> for the room detail modal (timetable, lighting override, alerts).</li>
                                    <li>Period / Metric filters drive the charts and history table.</li>
                                </ol>
                            </div>
                        </div>
                        <button type="button" class="timetable-btn" data-panel="panelStatus" title="Filter by Status">
                            <i class="bi bi-funnel"></i><span class="timetable-btn-title bold">Status</span>
                        </button>
                        <div id="panelStatus" class="timetable-panel p-3 m-3">
                            <div class="section-container timetable" style="background-color:#f8f9fa;">
                                <ul class="list-unstyled mb-0" id="statusFilterMenu" style="max-height:300px;overflow-y:auto;">
                                    <li><a class="d-block px-2 py-1 filter-option active" href="#" data-value="">All Statuses</a></li>
                                    <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="live">Live / Functioning</a></li>
                                    <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="occupied">Occupied</a></li>
                                    <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="scheduled">Scheduled</a></li>
                                    <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="vacant">Vacant</a></li>
                                </ul>
                            </div>
                        </div>
                        <button type="button" class="timetable-btn" data-panel="panelSchedule" title="Filter by Schedule Info">
                            <i class="bi bi-funnel"></i><span class="timetable-btn-title bold">Schedule<br>Info</span>
                        </button>
                        <div id="panelSchedule" class="timetable-panel panel-from-right p-3 m-3">
                            <div class="section-container timetable" style="background-color:#f8f9fa;min-width:200px;">
                                <div class="mb-2">
                                    <div class="small fw-bold text-muted mb-1 px-2">Department</div>
                                    <ul class="list-unstyled mb-0" id="departmentFilterMenu" style="max-height:130px;overflow-y:auto;">
                                        <li><a class="d-block px-2 py-1 filter-option active" href="#" data-value="">All Departments</a></li>
                                        <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="Science">Science</a></li>
                                        <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="TLE">TLE</a></li>
                                        <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="Mathematics">Mathematics</a></li>
                                        <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="English">English</a></li>
                                    </ul>
                                </div>
                                <hr class="my-1">
                                <div>
                                    <div class="small fw-bold text-muted mb-1 px-2">Subject</div>
                                    <ul class="list-unstyled mb-0" id="subjectFilterMenu" style="max-height:130px;overflow-y:auto;">
                                        <li><a class="d-block px-2 py-1 filter-option active" href="#" data-value="">All Subjects</a></li>
                                        <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="Physics">Physics</a></li>
                                        <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="Electronics">Electronics</a></li>
                                        <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="Algebra">Algebra</a></li>
                                        <li><a class="d-block px-2 py-1 filter-option" href="#" data-value="Literature">Literature</a></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <input type="text" id="roomSearch" class="form-control" placeholder="Search room or faculty..."
                            style="max-width:300px;">
                    </div>

                    <div class="d-flex flex-column align-items-center justify-content-center flex-grow-1" style="padding:6px 0;">
                        <div class="overview-title-band">
                            <h2 class="bold" id="tabHeading">Overall Status</h2>
                            <p id="tabSubheading">All Rooms Selected</p>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2 pe-2" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);">
                        <button type="button" class="timetable-btn" data-panel="panelPeriod" title="Filter by Period">
                            <i class="bi bi-calendar-range"></i><span class="timetable-btn-title bold">Period</span>
                        </button>
                        <div id="panelPeriod" class="timetable-panel panel-from-right p-3 m-3">
                            <div class="section-container timetable" style="background-color:#f8f9fa;">
                                <div class="dept-member-filter">
                                    <div class="dept-member-filter-header">Filter by Period</div>
                                    <div class="dept-member-filter-list">
                                        <div class="dept-member-filter-item active" onclick="setPeriod(this, 7)">Last 7 days</div>
                                        <div class="dept-member-filter-item" onclick="setPeriod(this, 1)">Today</div>
                                        <div class="dept-member-filter-item" onclick="setPeriod(this, 14)">Last 14 days</div>
                                        <div class="dept-member-filter-item" onclick="setPeriod(this, 30)">Last 30 days</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="timetable-btn" data-panel="panelMetric" title="Filter by Metric">
                            <i class="bi bi-graph-up"></i><span class="timetable-btn-title bold">Metric</span>
                        </button>
                        <div id="panelMetric" class="timetable-panel panel-from-right p-3 m-3">
                            <div class="section-container timetable" style="background-color:#f8f9fa;">
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
                        </div>
                        <button type="button" class="timetable-btn" onclick="exportCSV()" title="Export CSV">
                            <i class="bi bi-filetype-csv"></i><span class="timetable-btn-title bold">CSV</span>
                        </button>
                        <button type="button" class="timetable-btn" onclick="exportPDF()" title="Export PDF">
                            <i class="bi bi-filetype-pdf"></i><span class="timetable-btn-title bold">PDF</span>
                        </button>
                        <?php if (!empty($STATIC_MODE)): ?>
                            <span class="static-note ms-2"><i class="bi bi-database-exclamation"></i> Static preview</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══════════ NEW OVERVIEW TIER · LINE GRAPH + ROOMS ═══════════ -->
                <div class="section-heading">Overview <span class="sub">— V/A/W trend &amp; rooms at a glance</span></div>
                <div class="main-container" style="padding:1rem;background-color:var(--secondary-color-2);">
                    <div class="overview-split">
                        <div class="overview-pane overview-pane-chart">
                            <div class="card-white" style="height:100%;">
                                <div class="chart-card-header">
                                    <h3 class="chart-card-title bold">Line Graph</h3>
                                    <div class="chart-header-actions"><span class="summary-label" id="overviewLineMetricLabel">All Metrics</span></div>
                                </div>
                                <div class="chart-wrapper" style="height:340px;"><canvas id="overviewLineChart"></canvas></div>
                            </div>
                        </div>
                        <div class="overview-pane overview-pane-rooms">
                            <div class="section-heading">Room Management<span class="sub"> All Rooms</span></div>
                            <div class="hrooms-list" id="hroomsList">
                                <?php foreach ($rooms as $r):
                                    $live   = !empty($r['is_live']);
                                    $accent = $r['status'] === 'occupied' ? 'accent-occupied' : ($r['status'] === 'scheduled' ? 'accent-scheduled' : 'accent-vacant');
                                    $badgeLabel = $live ? 'Live' : ($r['status'] === 'occupied' ? 'Occupied' : ($r['status'] === 'scheduled' ? 'Scheduled' : 'Vacant'));
                                    $badgeClass = 'badge-' . strtolower($badgeLabel);
                                    $fac    = $r['faculty_name'] !== '' ? $r['faculty_name'] : '-';
                                    $v = $r['voltage_v'] !== null ? number_format($r['voltage_v'], 1) : '—';
                                    $a = $r['current_a'] !== null ? number_format($r['current_a'], 3) : '—';
                                    $w = $r['power_w']   !== null ? number_format($r['power_w'], 1)   : '—';
                                    $timeLabel = $r['status'] === 'occupied' ? 'Current Class:' : 'Next class:';
                                    $timeVal   = $r['status'] === 'occupied' ? $r['current_time'] : ($r['next_time'] !== '' ? $r['next_time'] : 'None scheduled');
                                ?>
                                    <div class="hroom-row room-card" data-room-id="<?= $r['id'] ?>"
                                        data-room="<?= h(strtolower($r['room_name'])) ?>"
                                        data-status="<?= h($live ? 'live' : $r['status']) ?>"
                                        data-departments="<?= h(strtolower($r['dept'])) ?>"
                                        data-sa="<?= h(strtolower($r['subject_area'])) ?>"
                                        data-subjects="<?= h(strtolower($r['subject'])) ?>">
                                        <div class="room-card-accent <?= $accent ?>"></div>
                                        <div class="room-card-body">
                                            <div class="room-card-header">
                                                <div>
                                                    <h2 class="room-card-name"><?= h($r['room_name']) ?><?php if (!empty($r['is_prototype'])): ?><span class="prototype-badge">Device</span><?php endif; ?></h2>
                                                    <div class="room-card-section">
                                                        <?= ucfirst(h($r['room_size'])) ?> room
                                                        <?php if (!empty($r['description'])): ?> &middot; <?= h($r['description']) ?><?php endif; ?>
                                                    </div>
                                                </div>
                                                <span class="room-status-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                                            </div>
                                            <div class="room-expand">
                                                <div class="device-strip">
                                                    <div class="dev-left">
                                                        <span class="device-pill <?= $live ? 'live' : 'none' ?>"><?= $live ? 'LIVE' : 'NO DEVICE' ?></span>
                                                        <?php if ($live): ?>
                                                            <span class="dev-pzem">
                                                                V <b><?= $v ?></b> &middot; A <b><?= $a ?></b> &middot; W <b><?= $w ?></b>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($r['pir_occupied'])): ?>
                                                            <span class="dev-occ"><i class="bi bi-person-fill"></i> Occupied</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="row-bars">
                                                        <?php for ($row = 1; $row <= 3; $row++):
                                                            $st = $r['row' . $row . '_status']; ?>
                                                            <div class="row-bar-item">
                                                                <span class="row-bar-label">R<?= $row ?></span>
                                                                <span class="row-bar <?= $st === 'on' ? 'on' : '' ?>"></span>
                                                            </div>
                                                        <?php endfor; ?>
                                                    </div>
                                                </div>
                                                <div class="room-expand-row">
                                                    <i class="bi bi-person-fill"></i>
                                                    <span class="room-info-label">Faculty:</span>
                                                    <span class="room-info-val"><?= h($fac) ?></span>
                                                </div>
                                                <div class="room-expand-row">
                                                    <i class="bi bi-clock-fill"></i>
                                                    <span class="room-info-label"><?= $timeLabel ?></span>
                                                    <span class="room-info-val"><?= h($timeVal) ?></span>
                                                </div>
                                            </div>
                                            <div class="hroom-spark"><canvas id="sparkCanvas<?= $r['id'] ?>"></canvas></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php /* ═══════════ SECTION 1 · OVERVIEW TIER (OLD) — COMMENTED OUT FOR NOW ═══════════

                <div class="main-container" style="padding:1rem;background-color:var(--secondary-color-2);">
                    <!-- Summary quick cards with spark trends -->
                    <div class="summary-sparks-grid mb-3" id="summarySparks">
                        <?php
                        $cards = [
                            ['id' => 'sumEnergy',  'label' => 'Total Energy',  'unit' => 'kWh', 'key' => 'energy',  'spark' => 'sparkSummary.energy'],
                            ['id' => 'sumMinutes', 'label' => 'Occupied',      'unit' => 'hrs', 'key' => 'minutes', 'spark' => 'sparkSummary.minutes'],
                            ['id' => 'sumVoltage', 'label' => 'Avg Voltage',   'unit' => 'V',   'key' => 'voltage', 'spark' => 'sparkSummary.voltage'],
                            ['id' => 'sumCurrent', 'label' => 'Avg Current',   'unit' => 'A',   'key' => 'current', 'spark' => 'sparkSummary.current'],
                            ['id' => 'sumPower',   'label' => 'Peak Power',    'unit' => 'W',   'key' => 'power',   'spark' => 'sparkSummary.power'],
                            ['id' => 'sumCost',    'label' => 'Est. Cost',     'unit' => 'PHP', 'key' => 'cost',    'spark' => 'sparkSummary.cost'],
                        ];
                        foreach ($cards as $c):
                            $val = match ($c['key']) {
                                'energy'  => $summary['total_energy_kwh'] . ' kWh',
                                'minutes' => round($summary['total_minutes'] / 60, 1) . ' hrs',
                                'voltage' => $summary['avg_voltage'] . ' V',
                                'current' => $summary['avg_current'] . ' A',
                                'power'   => $summary['peak_power_w'] . ' W',
                                'cost'    => '&#x20B1;' . $summary['est_cost_php'],
                            };
                        ?>
                        <div class="summary-spark-card">
                            <span class="spark-label"><?= h($c['label']) ?></span>
                            <span class="spark-value"><?= $val ?></span>
                            <div class="spark-canvas-wrap"><canvas id="sumSpark_<?= h($c['id']) ?>"></canvas></div>
                            <span class="spark-sub">7-day trend</span>
                        </div>
                        <?php endforeach; ?>
                        <div class="summary-spark-card" style="border-top-color:#c0004e;">
                            <span class="spark-label">Anomalies</span>
                            <span class="spark-value"><?= $summary['total_anomalies'] ?></span>
                            <div style="flex:1;display:flex;align-items:center;justify-content:center;min-height:34px;">
                                <span style="font-size:10px;color:#c0004e;font-weight:600;">issues in selected period</span>
                            </div>
                        </div>
                    </div>

                </div>

                */ ?>
                <?php /* ═══════════ SECTION 2 · ROOM MANAGEMENT — COMMENTED OUT FOR NOW ═══════════
                <div class="main-container" style="padding:1rem;background-color:var(--secondary-color-2);">
                    <!-- Rooms at a glance (functioning first) — combined with management -->
                    <div class="section-heading" style="margin-top:0;">Rooms <span class="sub">— click a room to select it for analytics</span></div>
                    <div class="rooms-strip" id="roomsStrip">
                        <?php foreach ($rooms as $r):
                            $live   = !empty($r['is_live']);
                            $accent = $live ? 'accent-live' : ($r['status'] === 'occupied' ? 'accent-occupied' : ($r['status'] === 'scheduled' ? 'accent-scheduled' : 'accent-vacant'));
                            $fac    = $r['faculty_name'] !== '' ? $r['faculty_name'] : '—';
                            $v = $r['voltage_v'] !== null ? number_format($r['voltage_v'], 1) : '—';
                            $a = $r['current_a'] !== null ? number_format($r['current_a'], 3) : '—';
                            $w = $r['power_w']   !== null ? number_format($r['power_w'], 1)   : '—';
                            $t = $r['status'] === 'occupied' ? $r['current_time'] : ($r['next_time'] !== '' ? 'next: ' . $r['next_time'] : 'No classes scheduled');
                        ?>
                        <div class="spark-card" data-room-id="<?= $r['id'] ?>"
                            data-room="<?= h(strtolower($r['room_name'])) ?>"
                            data-status="<?= h($live ? 'live' : $r['status']) ?>"
                            data-departments="<?= h(strtolower($r['dept'])) ?>"
                            data-sa="<?= h(strtolower($r['subject_area'])) ?>"
                            data-subjects="<?= h(strtolower($r['subject'])) ?>">
                            <div class="spark-card-accent <?= $accent ?>"></div>
                            <div class="spark-card-top">
                                <div>
                                    <div class="spark-card-name"><?= h($r['room_name']) ?><?php if (!empty($r['is_prototype'])): ?><span class="prototype-badge">Device</span><?php endif; ?></div>
                                    <div class="spark-card-size"><?= h($r['room_size']) ?> room</div>
                                </div>
                                <span class="device-pill <?= $live ? 'live' : 'none' ?>"><?= $live ? 'LIVE' : 'NO DEVICE' ?></span>
                            </div>
                            <div class="spark-card-faculty"><i class="bi bi-person-fill"></i><?= h($fac) ?></div>
                            <div class="spark-card-meta">
                                <span class="spark-card-live">V <b><?= $v ?></b> &middot; A <b><?= $a ?></b> &middot; W <b><?= $w ?></b></span>
                                <span class="spark-time"><?= h($t) ?></span>
                            </div>
                            <div class="row-bars">
                                <?php for ($row = 1; $row <= 3; $row++):
                                    $st = $r['row' . $row . '_status']; ?>
                                <div class="row-bar-item">
                                    <span class="row-bar-label">R<?= $row ?></span>
                                    <span class="row-bar <?= $st === 'on' ? 'on' : '' ?>"></span>
                                    <span class="row-bar-state <?= $st === 'on' ? 'on' : '' ?>"><?= strtoupper($st) ?></span>
                                </div>
                                <?php endfor; ?>
                            </div>
                            <div class="spark-canvas-wrap"><canvas id="sparkCanvas<?= $r['id'] ?>"></canvas></div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="rooms-grid" id="roomsGrid">
                        <?php foreach ($rooms as $r):
                            $live   = !empty($r['is_live']);
                            $curSched = null;
                            $fName  = $r['faculty_name'] !== '' ? $r['faculty_name'] : '-';
                            $badgeLabel = $live ? 'Live' : ($r['status'] === 'occupied' ? 'Occupied' : ($r['status'] === 'scheduled' ? 'Scheduled' : 'Vacant'));
                            $badgeClass = 'badge-' . strtolower($badgeLabel);
                            $accentClass = 'accent-' . ($live ? 'live' : $r['status']);
                            $timeLabel = $r['status'] === 'occupied' ? 'Current Class:' : 'Next class:';
                            $timeVal   = $r['status'] === 'occupied' ? $r['current_time'] : ($r['next_time'] !== '' ? $r['next_time'] : 'None scheduled');
                        ?>
                        <div class="room-card" data-room-id="<?= $r['id'] ?>"
                            data-room="<?= h(strtolower($r['room_name'])) ?>"
                            data-status="<?= h($live ? 'live' : $r['status']) ?>"
                            data-departments="<?= h(strtolower($r['dept'])) ?>"
                            data-sa="<?= h(strtolower($r['subject_area'])) ?>"
                            data-subjects="<?= h(strtolower($r['subject'])) ?>">
                            <div class="room-card-accent <?= $accentClass ?>"></div>
                            <div class="room-card-body">
                                <div class="room-card-header">
                                    <div>
                                        <h2 class="room-card-name"><?= h($r['room_name']) ?></h2>
                                        <div class="room-card-section">
                                            <?= ucfirst(h($r['room_size'])) ?> room
                                            <?php if (!empty($r['description'])): ?> &middot; <?= h($r['description']) ?><?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="room-status-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                                </div>

                                <div class="device-strip">
                                    <div class="dev-left">
                                        <span class="device-pill <?= $live ? 'live' : 'none' ?>"><?= $live ? 'LIVE' : 'NO DEVICE' ?></span>
                                        <?php if ($live): ?>
                                        <span class="dev-pzem">
                                            V <b><?= number_format($r['voltage_v'], 1) ?></b>
                                            A <b><?= number_format($r['current_a'], 3) ?></b>
                                            W <b><?= number_format($r['power_w'], 1) ?></b>
                                        </span>
                                        <?php endif; ?>
                                        <?php if (!empty($r['pir_occupied'])): ?>
                                        <span class="dev-occ"><i class="bi bi-person-fill"></i> Occupied</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="row-bars">
                                        <?php for ($row = 1; $row <= 3; $row++):
                                            $st = $r['row' . $row . '_status']; ?>
                                        <div class="row-bar-item">
                                            <span class="row-bar-label">R<?= $row ?></span>
                                            <span class="row-bar <?= $st === 'on' ? 'on' : '' ?>"></span>
                                        </div>
                                        <?php endfor; ?>
                                    </div>
                                </div>

                                <div class="dept-info-card room-info-row" style="padding:0.5rem;">
                                    <p class="d-flex align-items-center gap-2"><i class="bi bi-person-fill"></i> <span class="room-info-label">Current Faculty:</span> <span class="room-info-val"><?= h($fName) ?></span></p>
                                </div>
                                <div class="dept-info-card room-info-row" style="padding:0.5rem;">
                                    <p class="d-flex align-items-center gap-2"><i class="bi bi-clock-fill"></i> <span class="room-info-label"><?= $timeLabel ?></span> <span class="room-info-val"><?= h($timeVal) ?></span></p>
                                </div>
                            </div>
                            <div class="room-card-actions">
                                <div class="d-flex align-items-center room-icons gap-1">
                                    <button class="btn-icon btn-icon-edit" title="Edit"
                                        onclick="openEditModal(<?= $r['id'] ?>, '<?= h(addslashes($r['room_name'])) ?>', '<?= h($r['room_size']) ?>', '<?= h(addslashes($r['description'])) ?>')">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn-icon btn-icon-del" title="Delete"
                                        onclick="openDeleteModal(<?= $r['id'] ?>, '<?= h(addslashes($r['room_name'])) ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                                <button class="light" onclick="openRoomModal(<?= $r['id'] ?>)">Inspect</button>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <div class="room-card" style="border:2px dashed #bbb;background:transparent;box-shadow:none;cursor:pointer;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;color:#aaa;min-height:200px;"
                            onclick="new bootstrap.Modal(document.getElementById('addRoomModal')).show()">
                            <i class="bi bi-plus-circle" style="font-size:2rem;"></i>
                            <span style="font-size:1rem;font-weight:600;">Add Room</span>
                        </div>
                    </div>
                </div>

                */ ?>
                <?php /* end old Section 2 */ ?>

                <!-- ═══════════ SECTION 3 · ANALYTICS ═══════════ -->
                <div class="section-heading">Analytics</div>
                <div class="main-container" style="padding:1rem;background-color:var(--secondary-color-2);">
                    <div class="overview-analytics-grid">
                        <aside class="analytics-sidebar">
                            <div class="live-card">
                                <div class="live-card-header">
                                    <span class="chart-card-title bold">Live Readings</span>
                                    <span class="live-badge" id="liveBadge"><span class="live-dot"></span> Live</span>
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
                                            <div class="live-stat-row"><span class="live-status-dot" id="liveStatusDot"></span><span class="live-stat-val" id="liveStatus">-</span></div>
                                            <div class="live-stat-label">Light Status</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="metric-info"><span class="metric-info-text">Voltage, Current, and Power readings drive Energy (Wh). <span class="metric-formula">Energy (Wh) = Power (W) &times; Time (h)</span></span></div>
                            </div>
                        </aside>
                        <main class="analytics-main">
                            <div class="chart-grid" style="height:340px;">
                                <div class="card-white" id="lineGraphCard">
                                    <div class="chart-card-header">
                                        <h3 class="chart-card-title bold">Line Graph</h3>
                                        <div class="chart-header-actions"><span class="summary-label" id="lineMetricLabel">All Metrics</span></div>
                                    </div>
                                    <div class="chart-wrapper"><canvas id="lineChart"></canvas></div>
                                </div>
                                <div class="card-white" id="barGraphCard">
                                    <div class="chart-card-header">
                                        <h3 class="chart-card-title bold">Vertical Bar Graph</h3>
                                        <div class="chart-header-actions"><span class="summary-label" id="barMetricLabel">All Metrics</span></div>
                                    </div>
                                    <div class="chart-wrapper"><canvas id="barChart"></canvas></div>
                                </div>
                            </div>
                            <div class="card-white" id="historyCard">
                                <div class="breakdown-header" style="margin-top:18px;margin-bottom:14px;">
                                    <div class="breakdown-title-row"><span class="breakdown-title bold" id="historyTitle">7-Day History</span></div>
                                    <div class="history-table-wrapper">
                                        <table class="breakdown-table">
                                            <thead id="historyHead"></thead>
                                            <tbody id="historyBody"></tbody>
                                            <tfoot id="historyFoot"></tfoot>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </main>
                    </div>
                </div>

            </div><!-- /page-content -->
        </div><!-- /child-container -->
    </div><!-- /parent-container -->

    <?php include __DIR__ . "/../../src/Includes/profile-offcanvas.php"; ?>

    <!-- ── ADD ROOM MODAL ── -->
    <div class="modal fade" id="addRoomModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Room</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="../../handlers/room-handler.php">
                    <input type="hidden" name="action" value="add_room">
                    <div class="modal-body d-flex flex-column gap-3">
                        <div>
                            <label class="form-label" style="font-size:.85rem;font-weight:600;">Room Name</label>
                            <input type="text" name="room_name" class="form-control" placeholder="e.g. Grade 7 - Acacia" required>
                        </div>
                        <div>
                            <label class="form-label" style="font-size:.85rem;font-weight:600;">Room Size</label>
                            <select name="room_size" class="form-select">
                                <option value="small">Small (7×7 m)</option>
                                <option value="medium" selected>Medium (7×9 m)</option>
                                <option value="large">Large (9×10 m+)</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" style="font-size:.85rem;font-weight:600;">Description <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" name="description" class="form-control" placeholder="e.g. Near library, 2nd floor">
                        </div>
                    </div>
                    <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                        <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="medium">Add Room</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── EDIT ROOM MODAL ── -->
    <div class="modal fade" id="editRoomModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Room</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="../../handlers/room-handler.php">
                    <input type="hidden" name="action" value="edit_room">
                    <input type="hidden" name="room_id" id="editRoomId">
                    <div class="modal-body d-flex flex-column gap-3">
                        <div>
                            <label class="form-label" style="font-size:.85rem;font-weight:600;">Room Name</label>
                            <input type="text" name="room_name" id="editRoomName" class="form-control" required>
                        </div>
                        <div>
                            <label class="form-label" style="font-size:.85rem;font-weight:600;">Room Size</label>
                            <select name="room_size" id="editRoomSize" class="form-select">
                                <option value="small">Small (7×7 m)</option>
                                <option value="medium">Medium (7×9 m)</option>
                                <option value="large">Large (9×10 m+)</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" style="font-size:.85rem;font-weight:600;">Description</label>
                            <input type="text" name="description" id="editRoomDesc" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                        <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="medium">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── DELETE ROOM MODAL ── -->
    <div class="modal fade" id="deleteRoomModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header modal-header-warning">
                    <h5 class="modal-title">Delete Room</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="bi bi-trash" style="font-size:2.5rem;color:#c0004e;"></i>
                    <p class="mt-3 mb-0" style="font-size:15px;">Are you sure you want to delete <strong id="deleteRoomName"></strong>? This will also remove all schedules and logs for this room.</p>
                </div>
                <form method="POST" action="../../handlers/room-handler.php">
                    <input type="hidden" name="action" value="delete_room">
                    <input type="hidden" name="room_id" id="deleteRoomId">
                    <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                        <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="medium" style="background:#c0392b;">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── ROOM DETAILS MODAL ── -->
    <div class="room-details-modal modal fade" id="roomModal" tabindex="-1" aria-labelledby="roomModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="roomModalLabel">Room Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-row gap-3 align-items-start flex-wrap">
                        <div class="d-flex flex-column gap-3" style="flex:0 0 340px; min-width:280px; max-width:380px;">
                            <div style="background:var(--accent-yellow);border-radius:12px;padding:20px;border:1px solid #eee;">
                                <h6 class="bold mb-3">Current Schedule</h6>
                                <div id="modalCurrentSched" style="background:#fff;border-radius:8px;padding:12px;font-size:13px; min-height:60px;"><em class="text-muted">Loading…</em></div>
                                <div class="collapse mt-2" id="timetableCollapse">
                                    <div id="modalTimetableBody" style="max-height:320px;overflow-y:auto;">
                                        <div class="modal-slot-empty">Loading…</div>
                                    </div>
                                </div>
                                <div class="admin-override-panel mt-3">
                                    <div class="override-panel-header">
                                        <i class="bi bi-shield-lock-fill"></i>
                                        <span>Admin Override</span>
                                        <span class="override-live-badge" id="overrideLiveBadge">STATIC</span>
                                    </div>
                                    <div class="override-master-row">
                                        <div class="override-master-left">
                                            <div class="bulb-preview-grid">
                                                <?php for ($i = 0; $i < 9; $i++): ?>
                                                    <img src="../../images/bulb-off.png" id="bulb<?= $i ?>" class="bulb-img">
                                                <?php endfor; ?>
                                            </div>
                                        </div>
                                        <div class="override-master-right">
                                            <button class="override-master-btn off" id="allLightsBtn" onclick="toggleAllLights()"><i class="bi bi-power"></i><span id="allLightsLabel">OFF</span></button>
                                            <div class="override-hint">All rows</div>
                                        </div>
                                    </div>
                                    <div class="override-rows">
                                        <?php foreach ([1, 2, 3] as $row): ?>
                                            <div class="override-row-item">
                                                <span class="override-row-label">Row <?= $row ?></span>
                                                <div class="override-row-toggle">
                                                    <input class="override-switch" type="checkbox" role="switch" id="row<?= $row ?>sw" onchange="toggleRow(<?= $row ?>, this.checked)">
                                                    <label class="override-switch-label" for="row<?= $row ?>sw"></label>
                                                </div>
                                                <span class="override-row-status" id="row<?= $row ?>status">OFF</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="override-footer-note"><i class="bi bi-info-circle"></i> Static preview — toggles update locally only.</div>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex flex-column gap-3" style="flex:1;min-width:220px;">
                            <div style="background:#f8f9fa;border-radius:12px;padding:16px;">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h6 class="bold mb-0">Weekly Timetable</h6>
                                </div>
                                <div id="modalTodaySched"><em class="text-muted">Loading…</em></div>
                            </div>
                            <div style="background:#f8f9fa;border-radius:12px;padding:16px;">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h6 class="bold mb-0">Room Alerts</h6>
                                </div>
                                <div class="activity-list px-1" id="modalAlertsPreview" style="min-height:40px;"><em class="text-muted" style="font-size:.82rem;">Loading…</em></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- PART2_MODALS -->

    <script src="../../js/lib/animations.js"></script>
    <script src="../../js/lib/toggles.js"></script>
    <script src="../../js/lib/tooltip.js"></script>

    <script>
        const ROOMS = <?= json_encode($rooms, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const SUMMARY = <?= json_encode($summary, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const SPARK_SUMMARY = <?= json_encode($sparkSummary, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const CHART_DAILY = <?= json_encode($chartDaily, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const CHART_TODAY = <?= json_encode($chartToday, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const STATIC_MODE = <?= !empty($STATIC_MODE) ? 'true' : 'false' ?>;
    </script>
    <script src="../../js/admin/admin-overview.js"></script>
    <script src="../../js/faculty/faculty-tutorial.js"></script>
</body>

</html>
<?php if (isset($conn)) $conn->close(); ?>