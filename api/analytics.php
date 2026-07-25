<?php
// api/analytics.php
// GET ?classroom_id=X&range=7|14|30
// Returns energy summary, daily chart, heatmap, trigger breakdown, per-session detail

require_once '../php/db_connect.php';
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['faculty_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
}

$range = $_GET['range'] ?? '30';
$cid   = (int)($_GET['classroom_id'] ?? 0);

$days = match($range) {
    'week'  => 7,
    'month' => 30,
    default => (int)$range
};
if (!in_array($days, [1, 7, 14, 30])) $days = 7;

$cid_filter  = $cid ? "AND ps.classroom_id = $cid" : "";
$cid_filter2 = $cid ? "AND ll.classroom_id = $cid" : "";
$cid_filter3 = $cid ? "AND pr.classroom_id = $cid" : "";

// ── Non-prototype room early return ───────────────────────────────────────
if ($cid > 0) {
    $stmt = $conn->prepare("SELECT is_prototype FROM classrooms WHERE id = ?");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || empty($row['is_prototype'])) {
        echo json_encode([
            'success' => true,
            'summary' => [
                'total_energy_kwh' => 0, 'total_minutes' => 0,
                'avg_voltage' => 0, 'avg_current' => 0,
                'peak_power_w' => 0, 'est_cost_php' => 0,
                'total_sessions' => 0, 'total_energy_wh' => 0,
                'peak_power_kw' => 0,
            ],
            'daily' => [], 'hourly' => [], 'intervals' => [],
            'heatmap' => [], 'triggers' => [], 'per_room' => [],
            'sessions' => [], 'active_session' => null,
        ]);
        $conn->close(); exit;
    }
}

// ── 1. Summary cards ─────────────────────────────────────────────────────
if ($days === 1) {
    // Today: pull live averages directly from pzem_readings
    if ($cid) {
        $stmt = $conn->prepare("
            SELECT
                ROUND(AVG(voltage), 1)                      AS avg_voltage,
                ROUND(AVG(current), 3)                      AS avg_current,
                ROUND(MAX(power), 2)                        AS peak_power_w,
                ROUND(SUM(power) * (3/3600), 4)             AS total_energy_wh
            FROM pzem_readings pr
            WHERE DATE(pr.recorded_at) = CURDATE()
              AND pr.classroom_id = ?
        ");
        $stmt->bind_param('i', $cid);
    } else {
        $stmt = $conn->prepare("
            SELECT
                ROUND(AVG(voltage), 1)                      AS avg_voltage,
                ROUND(AVG(current), 3)                      AS avg_current,
                ROUND(MAX(power), 2)                        AS peak_power_w,
                ROUND(SUM(power) * (3/3600), 4)             AS total_energy_wh
            FROM pzem_readings pr
            WHERE DATE(pr.recorded_at) = CURDATE()
        ");
    }
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Today occupied minutes: completed sessions + active session elapsed
    if ($cid) {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(duration_mins), 0) AS completed_mins,
                   COUNT(*)                        AS sessions
            FROM power_sessions
            WHERE session_date = CURDATE()
              AND end_time IS NOT NULL
              AND classroom_id = ?
        ");
        $stmt->bind_param('i', $cid);
    } else {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(duration_mins), 0) AS completed_mins,
                   COUNT(*)                        AS sessions
            FROM power_sessions
            WHERE session_date = CURDATE()
              AND end_time IS NOT NULL
        ");
    }
    $stmt->execute();
    $todaySession = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $activeMins = 0;
    if ($cid) {
        $stmt = $conn->prepare("
            SELECT TIMESTAMPDIFF(MINUTE, start_time, NOW()) AS active_mins
            FROM power_sessions
            WHERE session_date = CURDATE()
              AND end_time IS NULL
              AND classroom_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $cid);
    } else {
        $stmt = $conn->prepare("
            SELECT TIMESTAMPDIFF(MINUTE, start_time, NOW()) AS active_mins
            FROM power_sessions
            WHERE session_date = CURDATE()
              AND end_time IS NULL
            LIMIT 1
        ");
    }
    $stmt->execute();
    $activeRow = $stmt->get_result()->fetch_assoc();
    if ($activeRow) $activeMins = (int)$activeRow['active_mins'];
    $stmt->close();

    $summary['total_sessions'] = ($todaySession['sessions'] ?? 0) + ($activeRow ? 1 : 0);
    $summary['total_minutes']  = ($todaySession['completed_mins'] ?? 0) + $activeMins;

} else {
    // Multi-day: aggregate from power_sessions within the selected range
    if ($cid) {
        $stmt = $conn->prepare("
            SELECT
                COUNT(*)                            AS total_sessions,
                SUM(duration_mins)                  AS total_minutes,
                ROUND(SUM(total_energy_wh), 2)      AS total_energy_wh,
                ROUND(AVG(avg_voltage), 1)          AS avg_voltage,
                ROUND(AVG(avg_current), 3)          AS avg_current,
                ROUND(MAX(peak_power), 2)           AS peak_power_w
            FROM power_sessions ps
            WHERE ps.session_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              AND ps.end_time IS NOT NULL
              AND ps.classroom_id = ?
        ");
        $stmt->bind_param('ii', $days, $cid);
    } else {
        $stmt = $conn->prepare("
            SELECT
                COUNT(*)                            AS total_sessions,
                SUM(duration_mins)                  AS total_minutes,
                ROUND(SUM(total_energy_wh), 2)      AS total_energy_wh,
                ROUND(AVG(avg_voltage), 1)          AS avg_voltage,
                ROUND(AVG(avg_current), 3)          AS avg_current,
                ROUND(MAX(peak_power), 2)           AS peak_power_w
            FROM power_sessions ps
            WHERE ps.session_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              AND ps.end_time IS NOT NULL
        ");
        $stmt->bind_param('i', $days);
    }
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$summary['total_energy_kwh'] = round(($summary['total_energy_wh'] ?? 0) / 1000, 4);
$summary['est_cost_php']     = round($summary['total_energy_kwh'] * 11, 2);
$summary['peak_power_kw']    = round(($summary['peak_power_w'] ?? 0) / 1000, 4);

// ── 2. Daily energy chart ─────────────────────────────────────────────────
$daily = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));

    // Try power_sessions first
    $stmt = $conn->prepare("
        SELECT
            ROUND(SUM(total_energy_wh), 2)  AS energy_wh,
            COUNT(*)                         AS sessions,
            SUM(duration_mins)               AS minutes
        FROM power_sessions ps
        WHERE ps.session_date = ?
          AND ps.end_time IS NOT NULL
        $cid_filter
    ");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Fallback to pzem_readings if no session data
    if (!$row['sessions']) {
        $stmt = $conn->prepare("
            SELECT ROUND(SUM(power) * (3/3600), 4) AS energy_wh
            FROM pzem_readings pr
            WHERE DATE(pr.recorded_at) = ?
            $cid_filter3
        ");
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $liveRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $row['energy_wh'] = $liveRow['energy_wh'] ?? 0;
    }

    // Avg V/A/W from pzem_readings for chart datasets
    if ($cid) {
        $pq = $conn->prepare("
            SELECT ROUND(AVG(voltage),1) AS avg_voltage,
                   ROUND(AVG(current),3) AS avg_current,
                   ROUND(AVG(power),2)   AS avg_power
            FROM pzem_readings
            WHERE DATE(recorded_at) = ? AND classroom_id = ?
        ");
        $pq->bind_param('si', $date, $cid);
    } else {
        $pq = $conn->prepare("
            SELECT ROUND(AVG(voltage),1) AS avg_voltage,
                   ROUND(AVG(current),3) AS avg_current,
                   ROUND(AVG(power),2)   AS avg_power
            FROM pzem_readings
            WHERE DATE(recorded_at) = ?
        ");
        $pq->bind_param('s', $date);
    }
    $pq->execute();
    $pzRow = $pq->get_result()->fetch_assoc();
    $pq->close();

    $daily[] = [
        'date'        => $date,
        'label'       => date('D M d', strtotime($date)),
        'energy_wh'   => (float)($row['energy_wh'] ?? 0),
        'energy_kw'   => round((float)($row['energy_wh'] ?? 0) / 1000, 4),
        'sessions'    => (int)($row['sessions']    ?? 0),
        'minutes'     => (int)($row['minutes']     ?? 0),
        'avg_voltage' => $pzRow['avg_voltage'] !== null ? (float)$pzRow['avg_voltage'] : null,
        'avg_current' => $pzRow['avg_current'] !== null ? (float)$pzRow['avg_current'] : null,
        'avg_power'   => $pzRow['avg_power']   !== null ? (float)$pzRow['avg_power']   : null,
    ];
}

// ── 3. Heatmap ────────────────────────────────────────────────────────────
$heatmap = [];
$r = $conn->query("
    SELECT
        DAYOFWEEK(event_time) AS dow,
        HOUR(event_time)      AS hr,
        COUNT(*)              AS cnt
    FROM lighting_logs ll
    WHERE event_type = 'on'
      AND event_time >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
    $cid_filter2
    GROUP BY dow, hr
    ORDER BY dow, hr
");
while ($row = $r->fetch_assoc()) {
    $heatmap[] = [
        'day'   => (int)$row['dow'],
        'hour'  => (int)$row['hr'],
        'count' => (int)$row['cnt'],
    ];
}

// ── 4. Trigger breakdown ──────────────────────────────────────────────────
$triggers = [];
$stmt = $conn->prepare("
    SELECT trigger_source, COUNT(*) AS cnt
    FROM power_sessions ps
    WHERE ps.session_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
      AND ps.end_time IS NOT NULL
    $cid_filter
    GROUP BY trigger_source
");
$stmt->bind_param('i', $days);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $triggers[] = $row;
$stmt->close();

// ── 5. Per-room breakdown ─────────────────────────────────────────────────
$per_room = [];
if (!$cid) {
    $stmt = $conn->prepare("
        SELECT
            c.room_name,
            COUNT(ps.id)                        AS sessions,
            ROUND(SUM(ps.total_energy_wh), 2)   AS energy_wh,
            ROUND(SUM(ps.total_energy_wh)/1000, 4) AS energy_kwh,
            SUM(ps.duration_mins)               AS minutes,
            ROUND(AVG(ps.avg_voltage), 1)       AS avg_voltage,
            ROUND(MAX(ps.peak_power), 2)        AS peak_power_w
        FROM classrooms c
        LEFT JOIN power_sessions ps ON ps.classroom_id = c.id
            AND ps.session_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            AND ps.end_time IS NOT NULL
        GROUP BY c.id
        ORDER BY c.room_name
    ");
    $stmt->bind_param('i', $days);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $per_room[] = $row;
    $stmt->close();
}

// ── 6. Per-session detail (NEW) ───────────────────────────────────────────
$sessions = [];
$stmt = $conn->prepare("
    SELECT
        ps.id,
        c.room_name,
        ps.session_date,
        ps.start_time,
        ps.end_time,
        ps.duration_mins,
        ps.trigger_source,
        ROUND(ps.avg_voltage, 1)            AS avg_voltage,
        ROUND(ps.avg_current, 3)            AS avg_current,
        ROUND(ps.peak_power, 2)             AS peak_power_w,
        ROUND(ps.peak_power / 1000, 4)      AS peak_power_kw,
        ROUND(ps.total_energy_wh, 2)        AS total_energy_wh,
        ROUND(ps.total_energy_wh / 1000, 4) AS total_energy_kwh,
        ROUND((ps.total_energy_wh / 1000) * 11, 2) AS est_cost_php
    FROM power_sessions ps
    JOIN classrooms c ON c.id = ps.classroom_id
    WHERE ps.session_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
      AND ps.end_time IS NOT NULL
    $cid_filter
    ORDER BY ps.start_time DESC
    LIMIT 100
");
$stmt->bind_param('i', $days);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $sessions[] = $row;
$stmt->close();

// ── 7. Active (ongoing) session for today (if any) ───────────────────────
$active_session = null;
if ($cid) {
    $stmt = $conn->prepare("SELECT ps.id, ps.classroom_id, ps.start_time
        FROM power_sessions ps
        WHERE ps.session_date = CURDATE()
          AND ps.end_time IS NULL
          AND ps.classroom_id = ?
        LIMIT 1");
    $stmt->bind_param('i', $cid);
} else {
    $stmt = $conn->prepare("SELECT ps.id, ps.classroom_id, ps.start_time
        FROM power_sessions ps
        WHERE ps.session_date = CURDATE()
          AND ps.end_time IS NULL
        LIMIT 1");
}
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $sid = $row['id'];
    $start = $row['start_time'];
    $roomid = (int)$row['classroom_id'];
    $stmt->close();

    if ($cid) {
        $q = $conn->prepare("SELECT ROUND(SUM(power) * (3/3600), 4) AS energy_wh,
            ROUND(AVG(voltage), 1) AS avg_voltage,
            ROUND(AVG(current), 3) AS avg_current
            FROM pzem_readings pr
            WHERE pr.recorded_at >= ?
            AND pr.classroom_id = ?");
        $q->bind_param('si', $start, $cid);
    } else {
        $q = $conn->prepare("SELECT ROUND(SUM(power) * (3/3600), 4) AS energy_wh,
            ROUND(AVG(voltage), 1) AS avg_voltage,
            ROUND(AVG(current), 3) AS avg_current
            FROM pzem_readings pr
            WHERE pr.recorded_at >= ?");
        $q->bind_param('s', $start);
    }
    $q->execute();
    $liveRow = $q->get_result()->fetch_assoc();
    $q->close();

    $duration_mins = (int)floor((time() - strtotime($start)) / 60);

    $active_session = [
        'id' => (int)$sid,
        'classroom_id' => $roomid,
        'start_time' => $start,
        'duration_mins' => $duration_mins,
        'energy_wh' => (float)($liveRow['energy_wh'] ?? 0),
        'energy_kwh' => round((($liveRow['energy_wh'] ?? 0) / 1000), 4),
        'avg_voltage' => (float)($liveRow['avg_voltage'] ?? 0),
        'avg_current' => (float)($liveRow['avg_current'] ?? 0),
    ];
} else {
    $stmt->close();
}

// If no active power_sessions row found, try inferring from pzem_live (device still on)
if (!$active_session) {
    if ($cid) {
        $q = $conn->prepare("SELECT *, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS secs_ago FROM pzem_live WHERE classroom_id = ? LIMIT 1");
        $q->bind_param('i', $cid);
    } else {
        $q = $conn->prepare("SELECT *, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS secs_ago FROM pzem_live ORDER BY updated_at DESC LIMIT 1");
    }
    $q->execute();
    $live = $q->get_result()->fetch_assoc();
    $q->close();

    if ($live) {
        $lightsOn = (!empty($live['row1']) || !empty($live['row2']) || !empty($live['row3']));
        $fresh = ((int)($live['secs_ago'] ?? 999)) <= 60;
        if ($lightsOn && $fresh) {
            $updated = $live['updated_at'];
            $duration_mins = (int)floor((time() - strtotime($updated)) / 60);
            $energy_wh = (float)($live['energy_wh'] ?? ($live['energy'] ?? 0));
            $active_session = [
                'id' => null,
                'classroom_id' => $live['classroom_id'] ?? $cid,
                'start_time' => $updated,
                'duration_mins' => $duration_mins,
                'energy_wh' => $energy_wh,
                'energy_kwh' => round($energy_wh / 1000, 4),
                'avg_voltage' => (float)($live['voltage_v'] ?? 0),
                'avg_current' => (float)($live['current_a'] ?? 0),
            ];
        }
    }
}

// ── 8. Per-minute chart data for today ──────────────────────────────────
$hourly = [];
if ($days === 1) {
    if ($cid) {
        $stmt = $conn->prepare("
            SELECT HOUR(recorded_at)     AS hr,
                   MINUTE(recorded_at)   AS minute,
                   ROUND(AVG(voltage),1) AS avg_voltage,
                   ROUND(AVG(current),3) AS avg_current,
                   ROUND(AVG(power),2)   AS avg_power,
                   COUNT(*)              AS reading_count
            FROM pzem_readings
            WHERE DATE(recorded_at) = CURDATE() AND classroom_id = ?
            GROUP BY hr, minute
            ORDER BY hr, minute
        ");
        $stmt->bind_param('i', $cid);
    } else {
        $stmt = $conn->prepare("
            SELECT HOUR(recorded_at)     AS hr,
                   MINUTE(recorded_at)   AS minute,
                   ROUND(AVG(voltage),1) AS avg_voltage,
                   ROUND(AVG(current),3) AS avg_current,
                   ROUND(AVG(power),2)   AS avg_power,
                   COUNT(*)              AS reading_count
            FROM pzem_readings
            WHERE DATE(recorded_at) = CURDATE()
            GROUP BY hr, minute
            ORDER BY hr, minute
        ");
    }
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $h = (int)$row['hr'];
        $m = (int)$row['minute'];
        $hourly[] = [
            'label'         => str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT),
            'avg_voltage'   => (float)$row['avg_voltage'],
            'avg_current'   => (float)$row['avg_current'],
            'avg_power'     => (float)$row['avg_power'],
            'reading_count' => (int)$row['reading_count'],
        ];
    }
    $stmt->close();
}

// ── 9. Per-minute interval rows for today's history table ────────────────
$intervals = [];
if ($days === 1) {
    if ($cid) {
        $stmt = $conn->prepare("
            SELECT HOUR(recorded_at)     AS hr,
                   MINUTE(recorded_at)   AS minute,
                   ROUND(AVG(voltage),1) AS avg_voltage,
                   ROUND(AVG(current),3) AS avg_current,
                   ROUND(AVG(power),2)   AS avg_power,
                   ROUND(SUM(power)*(3/3600),4)   AS energy_wh,
                   COUNT(*)              AS reading_count
            FROM pzem_readings
            WHERE DATE(recorded_at) = CURDATE() AND classroom_id = ?
            GROUP BY hr, minute
            ORDER BY hr, minute
        ");
        $stmt->bind_param('i', $cid);
    } else {
        $stmt = $conn->prepare("
            SELECT HOUR(recorded_at)     AS hr,
                   MINUTE(recorded_at)   AS minute,
                   ROUND(AVG(voltage),1) AS avg_voltage,
                   ROUND(AVG(current),3) AS avg_current,
                   ROUND(AVG(power),2)   AS avg_power,
                   ROUND(SUM(power)*(3/3600),4)   AS energy_wh,
                   COUNT(*)              AS reading_count
            FROM pzem_readings
            WHERE DATE(recorded_at) = CURDATE()
            GROUP BY hr, minute
            ORDER BY hr, minute
        ");
    }
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $h = (int)$row['hr'];
        $m = (int)$row['minute'];
        $intervals[] = [
            'time'          => str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT),
            'avg_voltage'   => (float)$row['avg_voltage'],
            'avg_current'   => (float)$row['avg_current'],
            'avg_power'     => (float)$row['avg_power'],
            'energy_wh'     => (float)$row['energy_wh'],
            'reading_count' => (int)$row['reading_count'],
        ];
    }
    $stmt->close();
}

echo json_encode([
    'success'        => true,
    'range'          => $days,
    'summary'        => $summary,
    'daily'          => $daily,
    'hourly'         => $hourly,
    'intervals'      => $intervals,
    'heatmap'        => $heatmap,
    'triggers'       => $triggers,
    'per_room'       => $per_room,
    'sessions'       => $sessions,
    'active_session' => $active_session,
]);