<?php
// api/analytics.php
// GET ?classroom_id=X&range=7|14|30
// Returns energy summary, daily chart, heatmap, trigger breakdown, per-session detail

require_once __DIR__ . "/../src/Config/db_connect.php";
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

// Archive mode: archive=YYYY-MM-DD treats that date as the RANGE END.
// Live mode (no param) uses today as the range end.
$archiveParam = trim($_GET['archive'] ?? '');
$isArchive    = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $archiveParam);
$anchor       = $isArchive ? $archiveParam : date('Y-m-d');

$cid_filter  = $cid ? "AND ps.classroom_id = $cid" : "";
$cid_filter2 = $cid ? "AND ll.classroom_id = $cid" : "";
$cid_filter3 = $cid ? "AND pr.classroom_id = $cid" : "";

// - Non-prototype room early return --------------------
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
                'peak_power_kw' => 0, 'total_anomalies' => 0,
            ],
            'daily' => [], 'hourly' => [], 'intervals' => [],
            'heatmap' => [], 'triggers' => [], 'per_room' => [],
            'sessions' => [], 'active_session' => null,
            'savings' => ['current_kwh' => 0, 'prev_kwh' => 0, 'pct' => null, 'direction' => 'saved'],
            'issues' => [],
        ]);
        $conn->close(); exit;
    }
}

// - 1. Summary cards ---------------------------
if ($days === 1) {
    if ($isArchive) {
        // Archived day: pull per-minute averages directly from pzem_archive
        $archSql = $cid ? " AND classroom_id = ?" : "";
        $stmt = $conn->prepare("
            SELECT
                ROUND(AVG(avg_voltage), 1)             AS avg_voltage,
                ROUND(AVG(avg_current), 3)             AS avg_current,
                ROUND(MAX(avg_power), 2)               AS peak_power_w,
                ROUND(SUM(COALESCE(energy_wh,0)), 4)   AS total_energy_wh
            FROM pzem_archive
            WHERE archive_date = ?$archSql
        ");
        if ($cid) $stmt->bind_param('si', $anchor, $cid);
        else      $stmt->bind_param('s', $anchor);
    } elseif ($cid) {
        // Today: pull live averages directly from pzem_readings
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
    if ($isArchive) {
        // No active session for a past day — count completed sessions only
        $archSql = $cid ? " AND classroom_id = ?" : "";
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(duration_mins), 0) AS completed_mins,
                   COUNT(*)                        AS sessions
            FROM power_sessions
            WHERE session_date = ?
              AND end_time IS NOT NULL
              $archSql
        ");
        if ($cid) $stmt->bind_param('si', $anchor, $cid);
        else      $stmt->bind_param('s', $anchor);
        $stmt->execute();
        $todaySession = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $activeMins = 0;
        $activeRow  = null;
    } elseif ($cid) {
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
    if (!$isArchive) {
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
    }

    $summary['total_sessions'] = ($todaySession['sessions'] ?? 0) + ($activeRow ? 1 : 0);
    $summary['total_minutes']  = ($todaySession['completed_mins'] ?? 0) + $activeMins;

} else {
    // Multi-day: aggregate from power_sessions within the selected range
    // (archive mode anchors the window to the selected date as range end)
    $winStartSql = $isArchive ? "ps.session_date >= DATE_SUB('$anchor', INTERVAL " . ($days - 1) . " DAY) AND ps.session_date <= '$anchor'"
                              : "ps.session_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
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
            WHERE $winStartSql
              AND ps.end_time IS NOT NULL
              AND ps.classroom_id = ?
        ");
        if ($isArchive) $stmt->bind_param('i', $cid);
        else            $stmt->bind_param('ii', $days, $cid);
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
            WHERE $winStartSql
              AND ps.end_time IS NOT NULL
        ");
        if (!$isArchive) $stmt->bind_param('i', $days);
    }
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$summary['total_energy_kwh'] = round(($summary['total_energy_wh'] ?? 0) / 1000, 4);
$summary['est_cost_php']     = round($summary['total_energy_kwh'] * 11, 2);
$summary['peak_power_kw']    = round(($summary['peak_power_w'] ?? 0) / 1000, 4);

// - Anomaly count -----------------------------
$anomWinSql = $isArchive
    ? "rl.event_time >= DATE_SUB('$anchor', INTERVAL " . ($days - 1) . " DAY) AND rl.event_time <= '$anchor 23:59:59'"
    : "rl.event_time >= DATE_SUB(NOW(), INTERVAL ? DAY)";
$anomWinSql2 = $isArchive
    ? "event_time >= DATE_SUB('$anchor', INTERVAL " . ($days - 1) . " DAY) AND event_time <= '$anchor 23:59:59'"
    : "event_time >= DATE_SUB(NOW(), INTERVAL ? DAY)";
if ($cid) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt FROM room_logs rl
        JOIN classrooms c ON c.room_name = rl.room_name
        WHERE rl.event_type = 'issue_raised'
          AND c.id = ?
          AND $anomWinSql
    ");
    if ($isArchive) $stmt->bind_param('i', $cid);
    else            $stmt->bind_param('ii', $cid, $days);
} else {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt FROM room_logs
        WHERE event_type = 'issue_raised'
          AND $anomWinSql2
    ");
    if (!$isArchive) $stmt->bind_param('i', $days);
}
$stmt->execute();
$summary['total_anomalies'] = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// - 1b. Energy saved: current window vs previous equal-length window --------
$savings = ['current_kwh' => 0, 'prev_kwh' => 0, 'pct' => null, 'direction' => 'saved'];

if ($isArchive) {
    // Archive: energy always comes from pzem_archive per-minute sums.
    // days=1 → anchor day vs the previous day (full days).
    // days>1 → [anchor-(days-1) .. anchor] vs the previous equal-length window.
    if ($days === 1) {
        $curStart  = "'$anchor'";
        $curEnd    = "'$anchor'";
        $prevStart = "DATE_SUB('$anchor', INTERVAL 1 DAY)";
        $prevEnd   = "DATE_SUB('$anchor', INTERVAL 1 DAY)";
    } else {
        $curStart  = "DATE_SUB('$anchor', INTERVAL " . ($days - 1) . " DAY)";
        $curEnd    = "'$anchor'";
        $prevStart = "DATE_SUB('$anchor', INTERVAL " . (2 * $days - 1) . " DAY)";
        $prevEnd   = "DATE_SUB('$anchor', INTERVAL $days DAY)";
    }

    if ($cid) {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(COALESCE(energy_wh,0)), 0) AS energy_wh
            FROM pzem_archive
            WHERE archive_date >= $curStart
              AND archive_date <= $curEnd
              AND classroom_id = ?
        ");
        $stmt->bind_param('i', $cid);
    } else {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(COALESCE(energy_wh,0)), 0) AS energy_wh
            FROM pzem_archive
            WHERE archive_date >= $curStart
              AND archive_date <= $curEnd
        ");
    }
    $stmt->execute();
    $curWh = (float)$stmt->get_result()->fetch_assoc()['energy_wh'];
    $stmt->close();

    if ($cid) {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(COALESCE(energy_wh,0)), 0) AS energy_wh
            FROM pzem_archive
            WHERE archive_date >= $prevStart
              AND archive_date <= $prevEnd
              AND classroom_id = ?
        ");
        $stmt->bind_param('i', $cid);
    } else {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(COALESCE(energy_wh,0)), 0) AS energy_wh
            FROM pzem_archive
            WHERE archive_date >= $prevStart
              AND archive_date <= $prevEnd
        ");
    }
    $stmt->execute();
    $prevWh = (float)$stmt->get_result()->fetch_assoc()['energy_wh'];
    $stmt->close();
} elseif ($days === 1) {
    // Today (up to the current hour) vs yesterday (up to the same hour)
    if ($cid) {
        $stmt = $conn->prepare("
            SELECT COALESCE(ROUND(SUM(power) * (3/3600), 4), 0) AS energy_wh
            FROM pzem_readings
            WHERE recorded_at >= CURDATE()
              AND recorded_at < CURDATE() + INTERVAL 1 HOUR
              AND classroom_id = ?
        ");
        $stmt->bind_param('i', $cid);
    } else {
        $stmt = $conn->prepare("
            SELECT COALESCE(ROUND(SUM(power) * (3/3600), 4), 0) AS energy_wh
            FROM pzem_readings
            WHERE recorded_at >= CURDATE()
              AND recorded_at < CURDATE() + INTERVAL 1 HOUR
        ");
    }
    $stmt->execute();
    $curWh = (float)$stmt->get_result()->fetch_assoc()['energy_wh'];
    $stmt->close();

    if ($cid) {
        $stmt = $conn->prepare("
            SELECT COALESCE(ROUND(SUM(power) * (3/3600), 4), 0) AS energy_wh
            FROM pzem_readings
            WHERE recorded_at >= CURDATE() - INTERVAL 1 DAY
              AND recorded_at < CURDATE() - INTERVAL 1 DAY + INTERVAL 1 HOUR
              AND classroom_id = ?
        ");
        $stmt->bind_param('i', $cid);
    } else {
        $stmt = $conn->prepare("
            SELECT COALESCE(ROUND(SUM(power) * (3/3600), 4), 0) AS energy_wh
            FROM pzem_readings
            WHERE recorded_at >= CURDATE() - INTERVAL 1 DAY
              AND recorded_at < CURDATE() - INTERVAL 1 DAY + INTERVAL 1 HOUR
        ");
    }
    $stmt->execute();
    $prevWh = (float)$stmt->get_result()->fetch_assoc()['energy_wh'];
    $stmt->close();
} else {
    // Current window matches the summary aggregation bounds
    $curStart  = "DATE_SUB(CURDATE(), INTERVAL {$days} DAY)";
    $prevStart = "DATE_SUB(CURDATE(), INTERVAL " . (2 * $days + 1) . " DAY)";

    if ($cid) {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(total_energy_wh), 0) AS energy_wh
            FROM power_sessions
            WHERE session_date >= $curStart
              AND end_time IS NOT NULL
              AND classroom_id = ?
        ");
        $stmt->bind_param('i', $cid);
    } else {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(total_energy_wh), 0) AS energy_wh
            FROM power_sessions
            WHERE session_date >= $curStart
              AND end_time IS NOT NULL
        ");
    }
    $stmt->execute();
    $curWh = (float)$stmt->get_result()->fetch_assoc()['energy_wh'];
    $stmt->close();

    if ($cid) {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(total_energy_wh), 0) AS energy_wh
            FROM power_sessions
            WHERE session_date >= $prevStart
              AND session_date < $curStart
              AND end_time IS NOT NULL
              AND classroom_id = ?
        ");
        $stmt->bind_param('i', $cid);
    } else {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(total_energy_wh), 0) AS energy_wh
            FROM power_sessions
            WHERE session_date >= $prevStart
              AND session_date < $curStart
              AND end_time IS NOT NULL
        ");
    }
    $stmt->execute();
    $prevWh = (float)$stmt->get_result()->fetch_assoc()['energy_wh'];
    $stmt->close();
}

$savings['current_kwh'] = round($curWh / 1000, 4);
$savings['prev_kwh']    = round($prevWh / 1000, 4);
if ($prevWh > 0) {
    $pct = ($prevWh - $curWh) / $prevWh * 100;
    $savings['pct']       = round($pct, 1);
    $savings['direction'] = $pct >= 0 ? 'saved' : 'increase';
}

// - 2. Daily energy chart -------------------------
$daily = [];
for ($i = $days - 1; $i >= 0; $i--) {
    // Live mode: anchor at today. Archive mode: anchor at the selected date.
    $date = $isArchive
        ? date('Y-m-d', strtotime("$anchor - $i days"))
        : date('Y-m-d', strtotime("-{$i} days"));

    if ($isArchive) {
        // Archive: energy + V/A/W from pzem_archive; sessions/minutes from power_sessions
        if ($cid) {
            $stmt = $conn->prepare("
                SELECT ROUND(SUM(COALESCE(energy_wh,0)), 4) AS energy_wh,
                       ROUND(AVG(avg_voltage),1)            AS avg_voltage,
                       ROUND(AVG(avg_current),3)            AS avg_current,
                       ROUND(AVG(avg_power),2)              AS avg_power
                FROM pzem_archive
                WHERE archive_date = ? AND classroom_id = ?
            ");
            $stmt->bind_param('si', $date, $cid);
        } else {
            $stmt = $conn->prepare("
                SELECT ROUND(SUM(COALESCE(energy_wh,0)), 4) AS energy_wh,
                       ROUND(AVG(avg_voltage),1)            AS avg_voltage,
                       ROUND(AVG(avg_current),3)            AS avg_current,
                       ROUND(AVG(avg_power),2)              AS avg_power
                FROM pzem_archive
                WHERE archive_date = ?
            ");
            $stmt->bind_param('s', $date);
        }
        $stmt->execute();
        $archRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($cid) {
            $ss = $conn->prepare("
                SELECT COUNT(*) AS sessions, COALESCE(SUM(duration_mins),0) AS minutes
                FROM power_sessions
                WHERE session_date = ? AND end_time IS NOT NULL AND classroom_id = ?
            ");
            $ss->bind_param('si', $date, $cid);
        } else {
            $ss = $conn->prepare("
                SELECT COUNT(*) AS sessions, COALESCE(SUM(duration_mins),0) AS minutes
                FROM power_sessions
                WHERE session_date = ? AND end_time IS NOT NULL
            ");
            $ss->bind_param('s', $date);
        }
        $ss->execute();
        $sRow = $ss->get_result()->fetch_assoc();
        $ss->close();

        $daily[] = [
            'date'        => $date,
            'label'       => date('D M d', strtotime($date)),
            'energy_wh'   => (float)($archRow['energy_wh'] ?? 0),
            'energy_kw'   => round((float)($archRow['energy_wh'] ?? 0) / 1000, 4),
            'sessions'    => (int)($sRow['sessions'] ?? 0),
            'minutes'     => (int)($sRow['minutes']  ?? 0),
            'avg_voltage' => $archRow['avg_voltage'] !== null ? (float)$archRow['avg_voltage'] : null,
            'avg_current' => $archRow['avg_current'] !== null ? (float)$archRow['avg_current'] : null,
            'avg_power'   => $archRow['avg_power']   !== null ? (float)$archRow['avg_power']   : null,
        ];
        continue;
    }

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

// - 3. Heatmap ------------------------------
$heatmap = [];
$heatWinSql = $isArchive
    ? "event_time >= DATE_SUB('$anchor', INTERVAL " . ($days - 1) . " DAY) AND event_time <= '$anchor 23:59:59'"
    : "event_time >= DATE_SUB(NOW(), INTERVAL {$days} DAY)";
$r = $conn->query("
    SELECT
        DAYOFWEEK(event_time) AS dow,
        HOUR(event_time)      AS hr,
        COUNT(*)              AS cnt
    FROM lighting_logs ll
    WHERE event_type = 'on'
      AND $heatWinSql
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

// Shared window fragment for power_sessions-based queries
$psWinSql = $isArchive
    ? "ps.session_date >= DATE_SUB('$anchor', INTERVAL " . ($days - 1) . " DAY) AND ps.session_date <= '$anchor'"
    : "ps.session_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";

// - 4. Trigger breakdown -------------------------
$triggers = [];
$stmt = $conn->prepare("
    SELECT trigger_source, COUNT(*) AS cnt
    FROM power_sessions ps
    WHERE $psWinSql
      AND ps.end_time IS NOT NULL
    $cid_filter
    GROUP BY trigger_source
");
if (!$isArchive) $stmt->bind_param('i', $days);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $triggers[] = $row;
$stmt->close();

// - 5. Per-room breakdown -------------------------
$per_room = [];
if (!$cid) {
    if ($isArchive) {
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
                AND ps.session_date >= DATE_SUB('$anchor', INTERVAL " . ($days - 1) . " DAY)
                AND ps.session_date <= '$anchor'
                AND ps.end_time IS NOT NULL
            GROUP BY c.id
            ORDER BY c.room_name
        ");
    } else {
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
    }
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $per_room[] = $row;
    $stmt->close();
}

// - 6. Per-session detail (NEW) ----------------------
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
    WHERE $psWinSql
      AND ps.end_time IS NOT NULL
    $cid_filter
    ORDER BY ps.start_time DESC
    LIMIT 100
");
if (!$isArchive) $stmt->bind_param('i', $days);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $sessions[] = $row;
$stmt->close();

// - 7. Active (ongoing) session for today (if any) ------------
$active_session = null;
if (!$isArchive) {
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
} // end if (!$isArchive) — active session is always null in archive mode

// - 8. Per-minute chart data for today -----------------
function padMinuteChart($rows, $maxTotal = null) {
    if ($maxTotal === null) $maxTotal = (int)date('H') * 60 + (int)date('i');
    $byKey = [];
    foreach ($rows as $r) {
        $key = str_pad((int)$r['hr'], 2, '0', STR_PAD_LEFT) . ':' . str_pad((int)$r['mn'], 2, '0', STR_PAD_LEFT);
        $byKey[$key] = $r;
    }
    $out = [];
    for ($t = 0; $t <= $maxTotal; $t++) {
        $h = intdiv($t, 60);
        $m = $t % 60;
        $key = str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT);
        if (isset($byKey[$key])) {
            $out[] = $byKey[$key];
        } else {
            $out[] = [
                'hr'            => $h,
                'mn'            => $m,
                'avg_voltage'   => null,
                'avg_current'   => null,
                'avg_power'     => null,
                'reading_count' => 0,
            ];
        }
    }
    return $out;
}

$hourly = [];
if ($days === 1) {
    if ($isArchive) {
        // Archived day: per-minute rows already stored in pzem_archive
        $archSql = $cid ? " AND classroom_id = ?" : "";
        $stmt = $conn->prepare("
            SELECT HOUR(minute)       AS hr,
                   MINUTE(minute)     AS minute,
                   ROUND(avg_voltage,1) AS avg_voltage,
                   ROUND(avg_current,3) AS avg_current,
                   ROUND(avg_power,2)   AS avg_power,
                   reading_count
            FROM pzem_archive
            WHERE archive_date = ?$archSql
            ORDER BY minute
        ");
        if ($cid) $stmt->bind_param('si', $anchor, $cid);
        else      $stmt->bind_param('s', $anchor);
        $stmt->execute();
        $r = $stmt->get_result();
        $rawHourly = [];
        while ($row = $r->fetch_assoc()) {
            $rawHourly[] = [
                'hr'            => (int)$row['hr'],
                'mn'            => (int)$row['minute'],
                'avg_voltage'   => (float)$row['avg_voltage'],
                'avg_current'   => (float)$row['avg_current'],
                'avg_power'     => (float)$row['avg_power'],
                'reading_count' => (int)$row['reading_count'],
            ];
        }
        $stmt->close();
        // Full day: pad 00:00–23:59 so gaps are visible
        foreach (padMinuteChart($rawHourly, 1439) as $row) {
            $h = (int)$row['hr'];
            $m = (int)$row['mn'];
            $hourly[] = [
                'label'         => str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT),
                'avg_voltage'   => $row['avg_voltage'] !== null ? (float)$row['avg_voltage'] : null,
                'avg_current'   => $row['avg_current'] !== null ? (float)$row['avg_current'] : null,
                'avg_power'     => $row['avg_power']   !== null ? (float)$row['avg_power']   : null,
                'reading_count' => (int)$row['reading_count'],
            ];
        }
    } elseif ($cid) {
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
    if (!$isArchive) {
        $stmt->execute();
        $r = $stmt->get_result();
        $rawHourly = [];
        while ($row = $r->fetch_assoc()) {
            $rawHourly[] = [
                'hr'            => (int)$row['hr'],
                'mn'            => (int)$row['minute'],
                'avg_voltage'   => (float)$row['avg_voltage'],
                'avg_current'   => (float)$row['avg_current'],
                'avg_power'     => (float)$row['avg_power'],
                'reading_count' => (int)$row['reading_count'],
            ];
        }
        $stmt->close();
        foreach (padMinuteChart($rawHourly) as $row) {
            $h = (int)$row['hr'];
            $m = (int)$row['mn'];
            $hourly[] = [
                'label'         => str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT),
                'avg_voltage'   => $row['avg_voltage'] !== null ? (float)$row['avg_voltage'] : null,
                'avg_current'   => $row['avg_current'] !== null ? (float)$row['avg_current'] : null,
                'avg_power'     => $row['avg_power']   !== null ? (float)$row['avg_power']   : null,
                'reading_count' => (int)$row['reading_count'],
            ];
        }
    }
}

// - 9. Per-minute interval rows for today's history table --------
$intervals = [];
if ($days === 1) {
    if ($isArchive) {
        // Archived day: per-minute rows from pzem_archive
        $archSql = $cid ? " AND classroom_id = ?" : "";
        $stmt = $conn->prepare("
            SELECT HOUR(minute)       AS hr,
                   MINUTE(minute)     AS minute,
                   ROUND(avg_voltage,1) AS avg_voltage,
                   ROUND(avg_current,3) AS avg_current,
                   ROUND(avg_power,2)   AS avg_power,
                   ROUND(COALESCE(energy_wh,0),4) AS energy_wh,
                   reading_count
            FROM pzem_archive
            WHERE archive_date = ?$archSql
            ORDER BY minute
        ");
        if ($cid) $stmt->bind_param('si', $anchor, $cid);
        else      $stmt->bind_param('s', $anchor);
    } elseif ($cid) {
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

// - 10. Issues raised within the selected window -------------------
$issues = [];
if ($cid) {
    $stmt = $conn->prepare("
        SELECT rl.id, rl.event_type, rl.room_name, rl.triggered_by, rl.event_time,
               COALESCE(rl.notes, '') AS notes
        FROM room_logs rl
        JOIN classrooms c ON c.room_name = rl.room_name
        WHERE rl.event_type = 'issue_raised'
          AND c.id = ?
          AND $anomWinSql
        ORDER BY rl.event_time ASC
    ");
    if ($isArchive) $stmt->bind_param('i', $cid);
    else            $stmt->bind_param('ii', $cid, $days);
} else {
    $stmt = $conn->prepare("
        SELECT id, event_type, room_name, triggered_by, event_time,
               COALESCE(notes, '') AS notes
        FROM room_logs
        WHERE event_type = 'issue_raised'
          AND $anomWinSql2
        ORDER BY event_time ASC
    ");
    if (!$isArchive) $stmt->bind_param('i', $days);
}
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) {
    $issues[] = [
        'id'           => (int)$row['id'],
        'event_type'   => $row['event_type'],
        'room_name'    => $row['room_name'],
        'triggered_by' => $row['triggered_by'],
        'event_time'   => $row['event_time'],
        'notes'        => $row['notes'],
    ];
}
$stmt->close();

echo json_encode([
    'success'        => true,
    'range'          => $days,
    'archive'        => $isArchive ? $anchor : null,
    'summary'        => $summary,
    'savings'        => $savings,
    'daily'          => $daily,
    'hourly'         => $hourly,
    'intervals'      => $intervals,
    'heatmap'        => $heatmap,
    'triggers'       => $triggers,
    'per_room'       => $per_room,
    'sessions'       => $sessions,
    'active_session' => $active_session,
    'issues'         => $issues,
]);