<?php
$page_title = 'Rooms Overview';
require_once __DIR__ . "/../../src/Includes/admin-head.php";
date_default_timezone_set('Asia/Manila');

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ═══════════════════════════════════════════════════════════════════════════
   LIVE MODE — all data below is read from the database.
   Rooms come from classrooms + pzem_live + schedules/faculty/subjects.
   Chart data comes from pzem_readings / power_sessions.
   ═══════════════════════════════════════════════════════════════════════════ */
$STATIC_MODE = false;

// ── Helpers ──────────────────────────────────────────────────────────────────
function fmtTime($t) {
    return $t ? date('g:i A', strtotime($t)) : '';
}

function fmtDow($dow) {
    $map = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6, 'Sunday' => 0];
    return $map[$dow] ?? 1;
}

// ── Base rooms with live PZEM data ───────────────────────────────────────────
$roomRows = [];
$r = $conn->query("
    SELECT c.id, c.room_name, c.room_size, c.description, c.is_prototype,
           c.row1_status, c.row2_status, c.row3_status, c.light_status, c.pir_occupied,
           p.voltage_v, p.current_a, p.power_w, p.energy_wh,
           TIMESTAMPDIFF(SECOND, p.updated_at, NOW()) AS fresh_secs
    FROM classrooms c
    LEFT JOIN pzem_live p ON p.classroom_id = c.id
    ORDER BY c.room_name
");
while ($row = $r->fetch_assoc()) $roomRows[$row['id']] = $row;

// ── All schedules with faculty names ─────────────────────────────────────────
$schedRows = [];
$rs = $conn->query("
    SELECT s.classroom_id, s.day_of_week, s.start_time, s.end_time, s.subject_id,
           CONCAT(f.first_name, ' ', f.last_name) AS faculty_name
    FROM schedules s
    LEFT JOIN faculty f ON f.id = s.faculty_id
    ORDER BY s.classroom_id, FIELD(s.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), s.start_time
");
while ($row = $rs->fetch_assoc()) $schedRows[$row['classroom_id']][] = $row;

// ── Subject / subject-area / department lookup ───────────────────────────────
$subjectMap = [];
$rs2 = $conn->query("
    SELECT sub.id AS subject_id,
           TRIM(sub.name) AS subject_name,
           TRIM(COALESCE(sa.name, '')) AS sa_name,
           TRIM(COALESCE(d.name, '')) AS dept_name
    FROM subjects sub
    LEFT JOIN subject_area sa ON sa.id = sub.subject_area_id
    LEFT JOIN departments d ON d.id = sub.department_id
");
while ($row = $rs2->fetch_assoc()) $subjectMap[$row['subject_id']] = $row;

// ── Per-room 7-day daily aggregates (energy / V / A / W) ─────────────────────
$dailyByRoom = [];
$rd = $conn->query("
    SELECT classroom_id, DATE(recorded_at) AS d,
           ROUND(SUM(power) * (3/3600), 4) AS energy_wh,
           ROUND(AVG(voltage), 1) AS avg_voltage,
           ROUND(AVG(current), 3) AS avg_current,
           ROUND(AVG(power), 2) AS avg_power
    FROM pzem_readings
    WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY classroom_id, DATE(recorded_at)
");
while ($row = $rd->fetch_assoc()) $dailyByRoom[$row['classroom_id']][$row['d']] = $row;

// ── Per-room per-minute data for today ───────────────────────────────────────
$todayByRoom = [];
$rt = $conn->query("
    SELECT classroom_id, HOUR(recorded_at) AS hr, MINUTE(recorded_at) AS mn,
           ROUND(AVG(voltage), 1) AS avg_voltage,
           ROUND(AVG(current), 3) AS avg_current,
           ROUND(AVG(power), 2) AS avg_power
    FROM pzem_readings
    WHERE DATE(recorded_at) = CURDATE()
    GROUP BY classroom_id, hr, mn
    ORDER BY hr, mn
");
while ($row = $rt->fetch_assoc()) $todayByRoom[$row['classroom_id']][] = $row;

// ── Per-room 30-day daily series (for single-room multi-day chart) ───────────
$dailySeriesByRoom = [];
$rsd = $conn->query("
    SELECT classroom_id, DATE(recorded_at) AS d,
           ROUND(AVG(voltage), 1) AS avg_voltage,
           ROUND(AVG(current), 3) AS avg_current,
           ROUND(AVG(power), 2) AS avg_power
    FROM pzem_readings
    WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY classroom_id, DATE(recorded_at)
");
while ($row = $rsd->fetch_assoc()) $dailySeriesByRoom[$row['classroom_id']][$row['d']] = $row;

// ── Alerts: lighting_logs + room_logs (last 7 days) ──────────────────────────
$alertsByRoom = [];
$ra = $conn->query("
    SELECT classroom_id, event_type, triggered_by, event_time
    FROM lighting_logs
    WHERE event_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY event_time DESC
");
while ($row = $ra->fetch_assoc()) $alertsByRoom[$row['classroom_id']][] = $row;
$rb = $conn->query("
    SELECT c.id AS classroom_id, rl.event_type, rl.triggered_by, rl.event_time
    FROM room_logs rl
    JOIN classrooms c ON c.room_name = rl.room_name
    WHERE rl.event_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY rl.event_time DESC
");
while ($row = $rb->fetch_assoc()) $alertsByRoom[$row['classroom_id']][] = $row;

// ── Assemble rooms ───────────────────────────────────────────────────────────
$nowTime = date('H:i:s');
$todayDow = (int)date('w');
$last7 = [];
for ($i = 6; $i >= 0; $i--) $last7[] = date('Y-m-d', strtotime("-$i days"));

$rooms = [];
foreach ($roomRows as $rid => $room) {
    $scheds = $schedRows[$rid] ?? [];
    $current = null;
    $nextToday = null;
    $nextAny = null;
    $nextAnyTs = null;

    foreach ($scheds as $s) {
        // Current class (today, now between start/end)
        if ($s['day_of_week'] === date('l') && $s['start_time'] <= $nowTime && $nowTime <= $s['end_time']) {
            if (!$current) $current = $s;
        }
        // Next class later today
        if ($s['day_of_week'] === date('l') && $s['start_time'] > $nowTime) {
            if (!$nextToday || $s['start_time'] < $nextToday['start_time']) $nextToday = $s;
        }
        // Next occurrence of this day+time across the week
        $ts = strtotime('+'.((fmtDow($s['day_of_week']) - $todayDow + 7) % 7).' days '.$s['start_time']);
        if ($ts !== false && $ts > time() && (!$nextAnyTs || $ts < $nextAnyTs)) {
            $nextAnyTs = $ts;
            $nextAny = $s;
        }
    }

    $faculty = '';
    $current_time = '';
    $next_time = '';
    $status = 'vacant';
    if ($current) {
        $status = 'occupied';
        $faculty = $current['faculty_name'] ?? '';
        $current_time = fmtTime($current['start_time']) . ' – ' . fmtTime($current['end_time']);
    } elseif ($nextToday) {
        $status = 'scheduled';
        $faculty = $nextToday['faculty_name'] ?? '';
        $next_time = fmtTime($nextToday['start_time']) . ' – ' . fmtTime($nextToday['end_time']);
    } elseif ($nextAny) {
        $status = 'scheduled';
        $faculty = $nextAny['faculty_name'] ?? '';
        $next_time = date('D, M j · g:i A', $nextAnyTs);
    }

    // Subject info from the active/upcoming schedule (fallback: first schedule)
    $subj = null;
    foreach (array_filter([$current, $nextToday, $nextAny]) as $s) {
        if ($s && !empty($s['subject_id']) && isset($subjectMap[$s['subject_id']])) { $subj = $subjectMap[$s['subject_id']]; break; }
    }

    $is_live = ($room['fresh_secs'] !== null && (int)$room['fresh_secs'] <= 60);

    // 7-day sparklines
    $spark = []; $sparkV = []; $sparkA = []; $sparkW = [];
    foreach ($last7 as $d) {
        $day = $dailyByRoom[$rid][$d] ?? null;
        $spark[]  = $day ? (float)$day['energy_wh']   : 0;
        $sparkV[] = $day ? (float)$day['avg_voltage'] : 0;
        $sparkA[] = $day ? (float)$day['avg_current'] : 0;
        $sparkW[] = $day ? (float)$day['avg_power']   : 0;
    }

    // Per-minute series for today (single-room chart + scrollbar)
    $todayLabels = []; $todayV = []; $todayA = []; $todayW = [];
    foreach ($todayByRoom[$rid] ?? [] as $m) {
        $hh = str_pad((int)$m['hr'], 2, '0', STR_PAD_LEFT);
        $mm = str_pad((int)$m['mn'], 2, '0', STR_PAD_LEFT);
        $todayLabels[] = $hh . ':' . $mm;
        $todayV[] = (float)$m['avg_voltage'];
        $todayA[] = (float)$m['avg_current'];
        $todayW[] = (float)$m['avg_power'];
    }

    // Daily series for the last 30 days (single-room multi-day chart)
    $dailyLabels = []; $dailyV = []; $dailyA = []; $dailyW = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $day = $dailySeriesByRoom[$rid][$d] ?? null;
        $dailyLabels[] = date('D M d', strtotime($d));
        $dailyV[] = $day && $day['avg_voltage'] !== null ? (float)$day['avg_voltage'] : null;
        $dailyA[] = $day && $day['avg_current'] !== null ? (float)$day['avg_current'] : null;
        $dailyW[] = $day && $day['avg_power']   !== null ? (float)$day['avg_power']   : null;
    }

    // Weekly timetable (formatted for the modal)
    $weekly = [];
    foreach ($scheds as $s) {
        $weekly[] = [
            'day_of_week'  => $s['day_of_week'],
            'start_time'   => fmtTime($s['start_time']),
            'end_time'     => fmtTime($s['end_time']),
            'faculty_name' => $s['faculty_name'] ?? '',
        ];
    }

    $rooms[] = [
        'id'            => $rid,
        'room_name'     => $room['room_name'],
        'room_size'     => $room['room_size'],
        'description'   => $room['description'],
        'is_prototype'  => $room['is_prototype'],
        'row1_status'   => $room['row1_status'],
        'row2_status'   => $room['row2_status'],
        'row3_status'   => $room['row3_status'],
        'light_status'  => $room['light_status'],
        'pir_occupied'  => $room['pir_occupied'],
        'voltage_v'     => $room['voltage_v'] !== null ? (float)$room['voltage_v'] : null,
        'current_a'     => $room['current_a'] !== null ? (float)$room['current_a'] : null,
        'power_w'       => $room['power_w']   !== null ? (float)$room['power_w']   : null,
        'energy_wh'     => $room['energy_wh'] !== null ? (float)$room['energy_wh'] : null,
        'fresh_secs'    => $room['fresh_secs'] !== null ? (int)$room['fresh_secs'] : null,
        'is_live'       => $is_live,
        'status'        => $status,
        'faculty_name'  => $faculty,
        'current_time'  => $current_time,
        'next_time'     => $next_time,
        'dept'          => $subj['dept_name']     ?? '',
        'subject_area'  => $subj['sa_name']       ?? '',
        'subject'       => $subj['subject_name']  ?? '',
        'spark'         => $spark,
        'sparkV'        => $sparkV,
        'sparkA'        => $sparkA,
        'sparkW'        => $sparkW,
        'todayLabels'   => $todayLabels,
        'todayV'        => $todayV,
        'todayA'        => $todayA,
        'todayW'        => $todayW,
        'dailyLabels'   => $dailyLabels,
        'dailyV'        => $dailyV,
        'dailyA'        => $dailyA,
        'dailyW'        => $dailyW,
        'schedules'     => $weekly,
        'alerts'        => array_slice($alertsByRoom[$rid] ?? [], 0, 6),
    ];
}

// Order: live → occupied → scheduled → vacant → name
usort($rooms, function ($a, $b) {
    $score = function ($r) {
        if ($r['is_live']) return 0;
        if ($r['status'] === 'occupied') return 1;
        if ($r['status'] === 'scheduled') return 2;
        return 3;
    };
    $sa = $score($a); $sb = $score($b);
    if ($sa !== $sb) return $sa <=> $sb;
    return strcmp($a['room_name'], $b['room_name']);
});

// ── Summary quick cards (30-day window) ───────────────────────────────────────
$summary = [
    'total_energy_kwh' => 0,
    'total_minutes'    => 0,
    'avg_voltage'      => 0,
    'avg_current'      => 0,
    'peak_power_w'     => 0,
    'est_cost_php'     => 0,
    'total_anomalies'  => 0,
];
$res = $conn->query("
    SELECT COALESCE(SUM(duration_mins),0) AS minutes,
           ROUND(COALESCE(SUM(total_energy_wh),0),2) AS energy_wh,
           ROUND(COALESCE(AVG(avg_voltage),0),1) AS avg_voltage,
           ROUND(COALESCE(AVG(avg_current),0),3) AS avg_current,
           ROUND(COALESCE(MAX(peak_power),0),2) AS peak_power_w
    FROM power_sessions
    WHERE session_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
      AND end_time IS NOT NULL
");
if ($row = $res->fetch_assoc()) {
    $summary['total_energy_kwh'] = round($row['energy_wh'] / 1000, 4);
    $summary['total_minutes']    = (int)$row['minutes'];
    $summary['avg_voltage']      = (float)$row['avg_voltage'];
    $summary['avg_current']      = (float)$row['avg_current'];
    $summary['peak_power_w']     = (float)$row['peak_power_w'];
    $summary['est_cost_php']     = round($row['energy_wh'] / 1000 * 11, 2);
}
$res = $conn->query("SELECT COUNT(*) AS c FROM room_logs WHERE event_type = 'issue_raised' AND event_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
if ($row = $res->fetch_assoc()) $summary['total_anomalies'] = (int)$row['c'];

// ── 7-day mini trends for the summary cards ───────────────────────────────────
$sparkSummary = ['energy' => [], 'minutes' => [], 'voltage' => [], 'current' => [], 'power' => [], 'cost' => []];
$dayAgg = [];
$res = $conn->query("
    SELECT DATE(recorded_at) AS d,
           ROUND(SUM(power) * (3/3600), 4) AS energy_wh,
           ROUND(AVG(voltage),1) AS avg_voltage,
           ROUND(AVG(current),3) AS avg_current,
           ROUND(AVG(power),2) AS avg_power
    FROM pzem_readings
    WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(recorded_at)
");
while ($row = $res->fetch_assoc()) $dayAgg[$row['d']] = $row;
$res = $conn->query("
    SELECT session_date AS d, COALESCE(SUM(duration_mins),0) AS minutes
    FROM power_sessions
    WHERE session_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND end_time IS NOT NULL
    GROUP BY session_date
");
while ($row = $res->fetch_assoc()) $dayAgg[$row['d']]['minutes'] = (int)$row['minutes'];

$costAcc = 0;
foreach ($last7 as $d) {
    $day = $dayAgg[$d] ?? [];
    $wh = (float)($day['energy_wh'] ?? 0);
    $costAcc += $wh / 1000 * 11;
    $sparkSummary['energy'][]  = $wh;
    $sparkSummary['minutes'][] = (int)($day['minutes'] ?? 0);
    $sparkSummary['voltage'][] = (float)($day['avg_voltage'] ?? 0);
    $sparkSummary['current'][] = (float)($day['avg_current'] ?? 0);
    $sparkSummary['power'][]   = (float)($day['avg_power'] ?? 0);
    $sparkSummary['cost'][]    = round($costAcc, 2);
}

// ── Chart data: multi-day aggregate (30 days, sliced client-side) ─────────────
$chartDaily = [];
$sessByDay = [];
$res = $conn->query("
    SELECT session_date AS d, COUNT(*) AS sessions, COALESCE(SUM(duration_mins),0) AS minutes,
           ROUND(COALESCE(SUM(total_energy_wh),0),2) AS energy_wh
    FROM power_sessions
    WHERE session_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND end_time IS NOT NULL
    GROUP BY session_date
");
while ($row = $res->fetch_assoc()) $sessByDay[$row['d']] = $row;
$pzByDay = [];
$res = $conn->query("
    SELECT DATE(recorded_at) AS d,
           ROUND(AVG(voltage),1) AS avg_voltage,
           ROUND(AVG(current),3) AS avg_current,
           ROUND(AVG(power),2) AS avg_power
    FROM pzem_readings
    WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY DATE(recorded_at)
");
while ($row = $res->fetch_assoc()) $pzByDay[$row['d']] = $row;
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $sess = $sessByDay[$date] ?? null;
    $pz   = $pzByDay[$date] ?? null;
    $chartDaily[] = [
        'date'        => $date,
        'label'       => date('D M d', strtotime($date)),
        'energy_wh'   => $sess ? (float)$sess['energy_wh'] : 0,
        'energy_kwh'  => $sess ? round($sess['energy_wh'] / 1000, 4) : 0,
        'sessions'    => $sess ? (int)$sess['sessions'] : 0,
        'minutes'     => $sess ? (int)$sess['minutes'] : 0,
        'avg_voltage' => $pz && $pz['avg_voltage'] !== null ? (float)$pz['avg_voltage'] : null,
        'avg_current' => $pz && $pz['avg_current'] !== null ? (float)$pz['avg_current'] : null,
        'avg_power'   => $pz && $pz['avg_power']   !== null ? (float)$pz['avg_power']   : null,
    ];
}

// ── Chart data: today (per-minute records) ────────────────────────────────────
$chartToday = [];
$res = $conn->query("
    SELECT HOUR(recorded_at) AS hr, MINUTE(recorded_at) AS mn,
           ROUND(AVG(voltage),1) AS avg_voltage,
           ROUND(AVG(current),3) AS avg_current,
           ROUND(AVG(power),2) AS avg_power,
           ROUND(SUM(power) * (3/3600), 4) AS energy_wh,
           COUNT(*) AS reading_count
    FROM pzem_readings
    WHERE DATE(recorded_at) = CURDATE()
    GROUP BY hr, mn
    ORDER BY hr, mn
");
while ($row = $res->fetch_assoc()) {
    $hh = str_pad((int)$row['hr'], 2, '0', STR_PAD_LEFT);
    $mm = str_pad((int)$row['mn'], 2, '0', STR_PAD_LEFT);
    $chartToday[] = [
        'label'         => $hh . ':' . $mm,
        'time'          => $hh . ':' . $mm,
        'avg_voltage'   => (float)$row['avg_voltage'],
        'avg_current'   => (float)$row['avg_current'],
        'avg_power'     => (float)$row['avg_power'],
        'energy_wh'     => (float)$row['energy_wh'],
        'reading_count' => (int)$row['reading_count'],
    ];
}
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
            <div>

                <!-- ═══════════ MERGED HEADER BAR ═══════════ -->
                <div class="main-container overview-heading d-flex align-items-center justify-content-between w-auto p-0">
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
                    </div>

                    <div class="d-flex flex-column align-items-center justify-content-center flex-grow-1" style="padding:6px 0;">
                        <div class="overview-title-band">
                            <h2 class="bold" id="tabHeading">Overall View</h2>
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
                                        <div class="dept-member-filter-item active" onclick="setPeriod(this, 1)">Today</div>
                                        <div class="dept-member-filter-item" onclick="setPeriod(this, 7)">Last 7 days</div>
                                        <div class="dept-member-filter-item" onclick="setPeriod(this, 14)">Last 14 days</div>
                                        <div class="dept-member-filter-item" onclick="setPeriod(this, 30)">Last 30 days</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="timetable-btn mx-2 px-2" data-panel="panelMetric" title="Filter by Metric">
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
                        <span class="static-note ms-2 live"><i class="bi bi-database-check"></i> Live data</span>
                    </div>
                </div>

                <!-- ═══════════ OVERVIEW TIER · LINE GRAPH ═══════════ -->
                <div class="section-heading mb-2">Overview</div>
                <div class="main-container" style="padding:1rem;background-color:var(--secondary-color-2);">
                    <div class="overview-pane overview-pane-chart">
                        <div class="card-white" style="height:100%;">
                            <div class="chart-card-header">
                                <h3 class="chart-card-title bold" id="overviewLineTitle">Line Graph</h3>
                                <div class="chart-header-actions">
                                    <span class="summary-label" id="overviewLineMetricLabel">All Metrics</span>
                                    <button type="button" class="light w-auto me-2" onclick="window.location.href='admin-analytics.php'" title="Open full Analytics">
                                        <i class="bi bi-graph-up-arrow"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="chart-wrapper" style="height:340px;"><canvas id="overviewLineChart"></canvas></div>
                            <div class="chart-scrollbar-wrap" id="overviewLineScrollWrap">
                                <input type="range" class="chart-scrollbar" id="overviewLineScroll" min="0" max="0" value="0" oninput="onOverviewChartScroll(this.value)">
                                <div class="chart-scroll-tip" id="overviewLineScrollTip"></div>
                                <div class="chart-scroll-pending" id="overviewLineScrollPending"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══════════ ROOM MANAGEMENT · ROOMS PANE ═══════════ -->
                <div class="main-container" style="padding:1rem;background-color:var(--secondary-color-1);">
                    <div class="overview-pane overview-pane-rooms">
                        <div class="section-heading d-flex align-items-center justify-content-between room-manage-header">
                            <div class="d-flex align-items-center gap-2">
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
                                <span>Room Management</span><span class="sub" id="roomsSelLabel"> All Rooms</span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <input type="text" id="roomSearch" class="form-control" placeholder="Search room or faculty..."
                                    style="max-width:220px;">
                                <button type="button" class="light expand-all-btn w-auto" id="expandAllRoomsBtn" title="Expand / collapse all rooms"><i class="bi bi-chevron-down"></i> Expand all</button>
                                <button type="button" class="light expand-all-btn w-auto" id="selectAllRoomsBtn" title="Select / unselect all rooms"><i class="bi bi-check2-all"></i> Select all</button>
                                <button type="button" class="medium" id="addRoomBtn" title="Add a new room" onclick="new bootstrap.Modal(document.getElementById('addRoomModal')).show()"><i class="bi bi-plus-lg"></i> Add Room</button>
                            </div>
                        </div>
                        <div class="hrooms-list" id="hroomsList">
                                <?php foreach ($rooms as $r):
                                    $live   = !empty($r['is_live']);
                                    $accent = $r['status'] === 'occupied' ? 'accent-occupied' : ($r['status'] === 'scheduled' ? 'accent-scheduled' : 'accent-vacant');
                                    $badgeLabel = $r['status'] === 'occupied' ? 'Occupied' : ($r['status'] === 'scheduled' ? 'Scheduled' : 'Vacant');
                                    $badgeClass = 'badge-' . strtolower($r['status']);
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
                                            <div class="hroom-spark"><canvas id="sparkCanvas<?= $r['id'] ?>"></canvas></div>
                                            <div class="room-expand">
                                                <div class="device-strip mb-2">
                                                    <div class="dev-left">
                                                        <span class="device-pill <?= $live ? 'live' : 'none' ?>"><?= $live ? 'LIVE' : 'NO DEVICE' ?></span>
                                                        <?php if ($live): ?>
                                                            <span class="dev-pzem">
                                                                V <b><?= $v ?></b> &middot; A <b><?= $a ?></b> &middot; W <b><?= $w ?></b>
                                                            </span>
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
                                                <div class="dept-info-card room-info-row mb-2">
                                                    <i class="bi bi-person-fill"></i>
                                                    <span class="room-info-label">Faculty:</span>
                                                    <span class="room-info-val"><?= h($fac) ?></span>
                                                </div>
                                                <div class="dept-info-card room-info-row mb-2">
                                                    <i class="bi bi-clock-fill"></i>
                                                    <span class="room-info-label"><?= $timeLabel ?></span>
                                                    <span class="room-info-val"><?= h($timeVal) ?></span>
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
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
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
                                        <span class="override-live-badge" id="overrideLiveBadge">LIVE</span>
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
                                    <div class="override-footer-note"><i class="bi bi-info-circle"></i> Override toggles persist to the room immediately.</div>
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
    </script>
    <script src="../../js/admin/admin-overview.js"></script>
    <script src="../../js/faculty/faculty-tutorial.js"></script>
</body>

</html>
<?php if (isset($conn)) $conn->close(); ?>