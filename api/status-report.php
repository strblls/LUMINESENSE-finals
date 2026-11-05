<?php
// api/status-report.php
// GET ?preset=&from=YYYY-MM-DD&to=YYYY-MM-DD&type=&actor=&source=&search=
//     &cross={"types":[{"value":"Admin","series":""}], ...}
//     &chart=durations|types|perRoom|toggles&chartValue=&chartSeries=on|off  (legacy)
//     &page=N&limit=M
// Single source of truth for Status Reports: date range applies server-side to
// KPIs, chart aggregates, and the paged table together (no LIMIT-200 cap).
// Manila (+08:00) dates throughout.
// `cross` is the multi-select cross-filter: rows must match EVERY chart that
// has picks, but ANY pick within one chart. Whitelisted values only.
// Returns { success, kpis, charts, chartsBase, chartsOwnBase, activeCharts,
//           crossFilter, rows, total, page, totalPages, from, to }.
// `charts` has every filter applied; `chartsOwnBase[key]` is the aggregate the
// active chart `key` renders (its own picks excluded, all others applied).

require_once __DIR__ . "/../src/Session/session_guard.php";
check_admin();
require_once __DIR__ . "/../src/Config/db_connect.php";
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');
header('Cache-Control: no-store');

// ── Validate filters ─────────────────────────────────────────────
$preset = trim($_GET['preset'] ?? $_GET['date'] ?? '');
if (!in_array($preset, ['', 'today', 'week', 'month'], true)) $preset = '';
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    echo json_encode(['success' => false, 'message' => 'Invalid from date.']); exit;
}
if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    echo json_encode(['success' => false, 'message' => 'Invalid to date.']); exit;
}
$type = trim($_GET['type'] ?? '');
if (!in_array($type, ['', 'room', 'admin', 'pir', 'class', 'light', 'anomaly'], true)) $type = '';
$actorFilter = strtolower(trim($_GET['actor'] ?? ''));
$sourceFilter = strtolower(trim($_GET['source'] ?? ''));
$search = strtolower(trim($_GET['search'] ?? ''));

// Multi-select chart cross-filter:
//   ?cross={"types":[{"value":"Admin","series":""}],"toggles":[{"value":"","series":"on"}]}
// Whitelisted here and applied in PHP only — never interpolated into SQL.
$CROSS_KEYS = ['durations', 'types', 'perRoom', 'toggles'];

function status_normalize_series(string $s): string
{
    $s = strtolower(trim($s));
    if ($s === 'on' || $s === 'light_on') return 'light_on';
    if ($s === 'off' || $s === 'light_off') return 'light_off';
    return '';
}

$cross = [];
foreach ($CROSS_KEYS as $k) $cross[$k] = [];

$crossRaw = is_string($_GET['cross'] ?? null) ? trim($_GET['cross']) : '';
if ($crossRaw !== '') {
    $decoded = json_decode($crossRaw, true);
    if (is_array($decoded)) {
        foreach ($CROSS_KEYS as $k) {
            if (empty($decoded[$k]) || !is_array($decoded[$k])) continue;
            $seen = [];
            foreach ($decoded[$k] as $p) {
                $value = (is_array($p) && is_scalar($p['value'] ?? null)) ? trim((string)$p['value']) : '';
                $series = status_normalize_series((is_array($p) && is_scalar($p['series'] ?? null)) ? (string)$p['series'] : '');
                if ($value === '' && $series === '') continue;
                if (mb_strlen($value) > 120) $value = mb_substr($value, 0, 120);
                $sig = $value . "\0" . $series;
                if (isset($seen[$sig])) continue;
                $seen[$sig] = true;
                $cross[$k][] = ['value' => $value, 'series' => $series];
            }
        }
    }
}

// Legacy single-chart params (old bookmarks / PDF links) still work.
$chartKey = is_string($_GET['chart'] ?? null) ? trim($_GET['chart']) : '';
if (!in_array($chartKey, $CROSS_KEYS, true)) $chartKey = '';
$chartValue = is_string($_GET['chartValue'] ?? null) ? trim($_GET['chartValue']) : '';
$chartSeries = status_normalize_series(is_string($_GET['chartSeries'] ?? null) ? $_GET['chartSeries'] : '');
// Bar-chart legends filter by On/Off series alone, so a chart key is valid
// without a room/type value when a series is present.
if ($chartValue === '' && !(in_array($chartKey, ['durations', 'toggles'], true) && $chartSeries !== '')) $chartKey = '';
if ($chartKey !== '' && empty($cross[$chartKey])) {
    $cross[$chartKey][] = ['value' => $chartValue, 'series' => $chartSeries];
}

// Drop picks that can never match their chart type.
foreach ($cross as $k => $picks) {
    $cross[$k] = array_values(array_filter($picks, function (array $p) use ($k): bool {
        if ($k === 'types' || $k === 'perRoom') return $p['value'] !== '';
        return $p['value'] !== '' || $p['series'] !== '';
    }));
}
$chartsActive = [];
foreach ($cross as $k => $picks) if (!empty($picks)) $chartsActive[] = $k;

$page = max((int)($_GET['page'] ?? 1), 1);
$limit = (int)($_GET['limit'] ?? 10);
if ($limit < 1 || $limit > 100) $limit = 10;

// ── Resolve date range (Manila-local; explicit from/to win over preset) ──
if ($from === '' && $to === '' && $preset !== '') {
    $today = date('Y-m-d');
    if ($preset === 'today') { $from = $today; $to = $today; }
    elseif ($preset === 'week') { $from = date('Y-m-d', strtotime('-6 days')); $to = $today; }
    elseif ($preset === 'month') { $from = date('Y-m-d', strtotime('-29 days')); $to = $today; }
}
if ($from !== '' && $to !== '' && $from > $to) { $tmp = $from; $from = $to; $to = $tmp; }

$ISSUE_ACTIONS = ['issue_raised', 'issue_resolved', 'tilt_alert'];

function status_log_source(array $log): string
{
    $actor = strtolower($log['actor'] ?? '');
    if (($log['log_type'] ?? '') === 'admin') return 'Admin';
    if ($actor === 'pir') return 'PIR';
    if ($actor === 'schedule') return 'Schedule';
    return 'Manual';
}

function status_issue_label(string $event, string $actor): string
{
    $actor = strtolower(trim($actor));
    if ($event === 'tilt_alert') return 'Tilt Alert';
    if ($event === 'issue_resolved') return ($actor === 'pzem') ? 'Power Normal' : 'Issue Resolved';
    if ($actor === 'pir') return 'Motion Outside Schedule';
    if ($actor === 'pzem') return 'Power Spike';
    return 'Issue Raised';
}

function status_plain_label(array $log): string
{
    $a = $log['action'] ?? '';
    if (in_array($a, ['issue_raised', 'issue_resolved', 'tilt_alert'], true)) {
        return status_issue_label($a, $log['actor'] ?? '');
    }
    return str_replace('Pir ', 'PIR ', ucwords(str_replace('_', ' ', $a)));
}

// Which action-type bucket a row belongs to (mirrors status_aggregate).
function status_chart_bucket(array $log, array $issueActions): string
{
    $action = $log['action'] ?? '';
    if (($log['log_type'] ?? '') === 'admin') return 'Admin';
    if ($action === 'light_on' || $action === 'light_off') return 'Lights';
    if (in_array($action, $issueActions, true)) return 'Anomalies';
    if (str_starts_with($action, 'pir_')) return 'PIR Motion';
    if (str_starts_with($action, 'class_')) return 'Class';
    return 'Other Room';
}

// Does one chart pick match this row? Ends are OR-ed within a chart.
function status_pick_matches(array $log, array $pick, string $key, array $roomNames, array $topRooms, array $issueActions): bool
{
    $action = $log['action'] ?? '';
    $target = $log['target'] ?? '';
    $value = $pick['value'];
    $series = $pick['series'];

    if ($key === 'types') {
        return $value !== '' && status_chart_bucket($log, $issueActions) === $value;
    }
    if ($key === 'perRoom') {
        if ($value === '') return false;
        $isRoom = ($target !== '' && isset($roomNames[$target]));
        if ($value === 'Other') return !$isRoom || !in_array($target, $topRooms, true);
        return $target === $value;
    }
    // durations / toggles: only physical lighting rows feed these charts.
    if (empty($log['_light'])) return false;
    if ($value !== '' && $target !== $value) return false;
    if ($series !== '' && $action !== $series) return false;
    return true;
}

// AND across charts, OR within each chart's pick list.
function status_matches_charts(array $log, array $cross, array $roomNames, array $topRooms, array $issueActions, ?string $excludeKey = null): bool
{
    foreach ($cross as $key => $picks) {
        if ($excludeKey !== null && $key === $excludeKey) continue;
        if (empty($picks)) continue;
        $ok = false;
        foreach ($picks as $p) {
            if (status_pick_matches($log, $p, $key, $roomNames, $topRooms, $issueActions)) { $ok = true; break; }
        }
        if (!$ok) return false;
    }
    return true;
}

// Chart aggregates for a given row set. Called twice (pre/post chart filter) so
// the clicked chart can keep its full breakdown while the rest narrow.
// Returns ['charts' => [...4 charts...], 'topRooms' => [names kept out of "Other"]].
function status_aggregate(array $rows, array $roomNames, array $issueActions): array
{
    // Entries by action type (disjoint partition of the row set)
    $typeCounts = ['Admin' => 0, 'Lights' => 0, 'PIR Motion' => 0, 'Class' => 0, 'Anomalies' => 0, 'Other Room' => 0];
    $light = [];
    foreach ($rows as $log) {
        $a = $log['action'] ?? '';
        if (($log['log_type'] ?? '') === 'admin') $typeCounts['Admin']++;
        elseif ($a === 'light_on' || $a === 'light_off') $typeCounts['Lights']++;
        elseif (in_array($a, $issueActions, true)) $typeCounts['Anomalies']++;
        elseif (str_starts_with($a, 'pir_')) $typeCounts['PIR Motion']++;
        elseif (str_starts_with($a, 'class_')) $typeCounts['Class']++;
        else $typeCounts['Other Room']++;
        // Only physical lighting-log rows feed the duration/toggle charts.
        if (!empty($log['_light'])) $light[] = $log;
    }

    // Entries per room (top 7 + Other)
    $perRoom = [];
    $otherTargets = 0;
    foreach ($rows as $log) {
        $t = $log['target'] ?? '';
        if ($t !== '' && isset($roomNames[$t])) $perRoom[$t] = ($perRoom[$t] ?? 0) + 1;
        else $otherTargets++;
    }
    arsort($perRoom);
    $topRooms = array_slice($perRoom, 0, 7, true);
    $chartPerRoom = ['labels' => array_keys($topRooms), 'data' => array_values($topRooms)];
    $remainder = array_sum(array_slice($perRoom, 7, null, true)) + $otherTargets;
    if ($remainder > 0) { $chartPerRoom['labels'][] = 'Other'; $chartPerRoom['data'][] = $remainder; }

    // On/Off durations + toggle counts
    $roomDur = [];
    $roomTog = [];
    $asc = $light;
    usort($asc, fn($a, $b) => strtotime($a['log_time']) - strtotime($b['log_time']));
    $pendingOn = [];
    $firstTs = [];
    $lastTs = [];
    foreach ($asc as $r) {
        $rn = $r['target'];
        $ts = strtotime($r['log_time']);
        if (!isset($roomDur[$rn])) $roomDur[$rn] = ['on' => 0, 'span' => 0];
        if (!isset($roomTog[$rn])) $roomTog[$rn] = ['on' => 0, 'off' => 0];
        if (!isset($firstTs[$rn])) $firstTs[$rn] = $ts;
        $lastTs[$rn] = $ts;
        if (($r['action'] ?? '') === 'light_on') {
            $roomTog[$rn]['on']++;
            if (!isset($pendingOn[$rn])) $pendingOn[$rn] = $ts;
        } else {
            $roomTog[$rn]['off']++;
            if (isset($pendingOn[$rn])) { $roomDur[$rn]['on'] += max(0, $ts - $pendingOn[$rn]); unset($pendingOn[$rn]); }
        }
    }
    foreach ($roomDur as $rn => $d) {
        $span = max(0, ($lastTs[$rn] ?? 0) - ($firstTs[$rn] ?? 0));
        $roomDur[$rn]['off'] = max(0, $span - $d['on']);
    }
    uasort($roomDur, fn($a, $b) => $b['on'] <=> $a['on']);
    $durRooms = array_slice(array_keys($roomDur), 0, 6);
    $chartDur = [
        'rooms' => array_values($durRooms),
        'on' => array_values(array_map(fn($rn) => round($roomDur[$rn]['on'] / 3600, 2), $durRooms)),
        'off' => array_values(array_map(fn($rn) => round($roomDur[$rn]['off'] / 3600, 2), $durRooms)),
    ];
    $togTotals = [];
    foreach ($roomTog as $rn => $t) $togTotals[$rn] = $t['on'] + $t['off'];
    arsort($togTotals);
    $togRooms = array_slice(array_keys($togTotals), 0, 8);
    $chartTog = [
        'rooms' => array_values($togRooms),
        'on' => array_values(array_map(fn($rn) => $roomTog[$rn]['on'], $togRooms)),
        'off' => array_values(array_map(fn($rn) => $roomTog[$rn]['off'], $togRooms)),
    ];

    return [
        'charts' => [
            'durations' => $chartDur,
            'types' => ['labels' => array_keys($typeCounts), 'data' => array_values($typeCounts)],
            'perRoom' => $chartPerRoom,
            'toggles' => $chartTog,
        ],
        'topRooms' => array_keys($topRooms),
    ];
}

// Fetch helper: prepared SELECT with optional DATE(col) BETWEEN filter.
function fetch_range(mysqli $conn, string $sql, string $dateCol, string $from, string $to, int $cap): array
{
    $types = '';
    $params = [];
    if ($from !== '' || $to !== '') {
        if ($from !== '' && $to !== '') { $sql = str_replace('/*DATEFILT*/', "AND DATE($dateCol) BETWEEN ? AND ?", $sql); $types .= 'ss'; $params[] = $from; $params[] = $to; }
        elseif ($from !== '') { $sql = str_replace('/*DATEFILT*/', "AND DATE($dateCol) >= ?", $sql); $types .= 's'; $params[] = $from; }
        else { $sql = str_replace('/*DATEFILT*/', "AND DATE($dateCol) <= ?", $sql); $types .= 's'; $params[] = $to; }
    } else {
        $sql = str_replace('/*DATEFILT*/', '', $sql);
    }
    $sql .= ' LIMIT ' . $cap;
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $r = $stmt->get_result();
    $rows = [];
    if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

$activity = [];

// Room event logs
foreach (fetch_range($conn, "
    SELECT 'room' AS log_type, id, event_type AS action, room_name AS target,
           triggered_by AS actor, event_time AS log_time, COALESCE(notes,'') AS notes
    FROM room_logs WHERE 1=1 /*DATEFILT*/ ORDER BY event_time DESC", 'event_time', $from, $to, 2000) as $row) {
    $activity[] = $row;
}
// Admin / approval logs
foreach (fetch_range($conn, "
    SELECT 'admin' AS log_type, al.id, al.action AS action, al.target_name AS target,
           COALESCE(CONCAT(a.first_name,' ',a.last_name),'System') AS actor,
           al.created_at AS log_time, COALESCE(al.notes,'') AS notes
    FROM admin_logs al LEFT JOIN admins a ON a.id = al.admin_id
    WHERE al.action IN ('faculty_approved','faculty_rejected','faculty_pending','extension_approved','extension_rejected')
    /*DATEFILT*/ ORDER BY al.created_at DESC", 'al.created_at', $from, $to, 2000) as $row) {
    $activity[] = $row;
}
// PIR occupancy events
foreach (fetch_range($conn, "
    SELECT 'room' AS log_type, pl.id,
           CASE pl.state WHEN 1 THEN 'pir_motion' ELSE 'pir_stopped' END AS action,
           c.room_name AS target, 'PIR' AS actor, pl.created_at AS log_time, '' AS notes
    FROM pir_logs pl JOIN classrooms c ON c.id = pl.classroom_id
    WHERE 1=1 /*DATEFILT*/ ORDER BY pl.created_at DESC", 'pl.created_at', $from, $to, 2000) as $row) {
    $activity[] = $row;
}
// Lighting schedule events
foreach (fetch_range($conn, "
    SELECT 'room' AS log_type, cl.id, cl.event_type AS action, c.room_name AS target,
           COALESCE(cl.triggered_by,'schedule') AS actor, cl.event_time AS log_time,
           COALESCE(cl.notes,'') AS notes
    FROM class_logs cl JOIN classrooms c ON c.id = cl.classroom_id
    WHERE 1=1 /*DATEFILT*/ ORDER BY cl.event_time DESC", 'cl.event_time', $from, $to, 2000) as $row) {
    $activity[] = $row;
}
// Physical light state changes
$lightRows = fetch_range($conn, "
    SELECT 'room' AS log_type, l.id,
           CASE l.event_type WHEN 'on' THEN 'light_on' ELSE 'light_off' END AS action,
           c.room_name AS target, COALESCE(NULLIF(l.triggered_by,''),'Manual') AS actor,
           l.event_time AS log_time, '' AS notes
    FROM lighting_logs l JOIN classrooms c ON c.id = l.classroom_id
    WHERE l.event_type IN ('on','off') /*DATEFILT*/ ORDER BY l.event_time DESC", 'l.event_time', $from, $to, 5000);
foreach ($lightRows as $row) { $row['_light'] = true; $activity[] = $row; }

usort($activity, fn($a, $b) => strtotime($b['log_time']) - strtotime($a['log_time']));

// ── Apply type/actor/source/search in PHP (parity with client filter) ──
$filtered = [];
foreach ($activity as $log) {
    $action = $log['action'] ?? '';
    $logType = $log['log_type'] ?? '';
    if ($type === 'anomaly') { if (!in_array($action, $ISSUE_ACTIONS, true)) continue; }
    elseif ($type === 'pir') { if (!str_starts_with($action, 'pir_')) continue; }
    elseif ($type === 'class') { if (!str_starts_with($action, 'class_')) continue; }
    elseif ($type === 'light') { if ($action !== 'light_on' && $action !== 'light_off') continue; }
    elseif ($type === 'room') { if ($logType !== 'room' || in_array($action, $ISSUE_ACTIONS, true)) continue; }
    elseif ($type === 'admin') { if ($logType !== 'admin') continue; }
    if ($actorFilter !== '' && strtolower($log['actor'] ?? '') !== $actorFilter) continue;
    $source = strtolower(status_log_source($log));
    if ($sourceFilter !== '' && $source !== $sourceFilter) continue;
    if ($search !== '') {
        $plain = status_plain_label($log);
        $hay = strtolower($plain . ' ' . ($log['target'] ?? '') . ' ' . ($log['actor'] ?? '') . ' ' . $action . ' ' . $source . ' ' . $logType . ' ' . substr($log['log_time'] ?? '', 0, 10) . ' ' . ($log['notes'] ?? ''));
        if (!str_contains($hay, $search)) continue;
    }
    $log['source'] = status_log_source($log);
    $log['date'] = substr($log['log_time'] ?? '', 0, 10);
    $filtered[] = $log;
}

// Snapshot before the chart cross-filter: the clicked chart renders from this
// so it keeps its full breakdown while the table/KPIs/other charts narrow.
$filteredBase = $filtered;

// Classroom names (real rooms vs. free-text targets; drives the "Other" bucket)
$roomNames = [];
$q = $conn->query("SELECT room_name FROM classrooms");
if ($q) { while ($rr = $q->fetch_assoc()) $roomNames[$rr['room_name']] = true; $q->free(); }

$baseAgg = status_aggregate($filteredBase, $roomNames, $ISSUE_ACTIONS);

// ── Chart cross-filters (multi-select: AND across charts, OR within) ──
// "Other" on the perRoom chart means "a real room outside that chart's top 7".
// The perRoom chart renders from its own base (its picks excluded), so its
// top 7 — not the unfiltered set's — define the Other bucket everywhere.
$topRooms = $baseAgg['topRooms'];
if (in_array('perRoom', $chartsActive, true)) {
    $perRoomBase = array_values(array_filter(
        $filteredBase,
        fn(array $log) => status_matches_charts($log, $cross, $roomNames, $topRooms, $ISSUE_ACTIONS, 'perRoom')
    ));
    $perRoomAgg = status_aggregate($perRoomBase, $roomNames, $ISSUE_ACTIONS);
    $topRooms = $perRoomAgg['topRooms'];
}

$filtered = array_values(array_filter(
    $filteredBase,
    fn(array $log) => status_matches_charts($log, $cross, $roomNames, $topRooms, $ISSUE_ACTIONS)
));
$agg = status_aggregate($filtered, $roomNames, $ISSUE_ACTIONS);

// Each active chart keeps its own full breakdown: its data is filtered by
// every OTHER chart but not by its own selection (Power BI behaviour).
$chartsOwnBase = [];
foreach ($chartsActive as $ck) {
    $rowsOwn = array_values(array_filter(
        $filteredBase,
        fn(array $log) => status_matches_charts($log, $cross, $roomNames, $topRooms, $ISSUE_ACTIONS, $ck)
    ));
    $aggOwn = status_aggregate($rowsOwn, $roomNames, $ISSUE_ACTIONS);
    $chartsOwnBase[$ck] = $aggOwn['charts'][$ck];
}

// ── KPIs from the filtered set ──
$roomRows = array_values(array_filter($filtered, fn($r) => ($r['log_type'] ?? '') !== 'admin'));
$adminRows = array_values(array_filter($filtered, fn($r) => ($r['log_type'] ?? '') === 'admin'));
$anomRows = array_values(array_filter($filtered, fn($r) => in_array($r['action'] ?? '', $ISSUE_ACTIONS, true)));
$daySet = [];
foreach ($filtered as $r) { if (!empty($r['log_time'])) $daySet[substr($r['log_time'], 0, 10)] = true; }
$spanDays = max(1, count($daySet));
$onCount = count(array_filter($filtered, fn($r) => ($r['action'] ?? '') === 'light_on'));
$offCount = count(array_filter($filtered, fn($r) => ($r['action'] ?? '') === 'light_off'));
$spike = count(array_filter($filtered, fn($r) => ($r['action'] ?? '') === 'issue_raised' && strtolower($r['actor'] ?? '') === 'pzem' && str_contains(strtolower($r['notes'] ?? ''), 'spike')));
$motion = count(array_filter($filtered, fn($r) => ($r['action'] ?? '') === 'issue_raised' && strtolower($r['actor'] ?? '') === 'pir'));
$powerRes = count(array_filter($filtered, fn($r) => ($r['action'] ?? '') === 'issue_resolved' && strtolower($r['actor'] ?? '') === 'pzem'));
$other = max(0, count($anomRows) - $spike - $motion - $powerRes);
$facApproved = count(array_filter($adminRows, fn($r) => ($r['action'] ?? '') === 'faculty_approved'));
$facRejected = count(array_filter($adminRows, fn($r) => ($r['action'] ?? '') === 'faculty_rejected'));
$extDecisions = count(array_filter($adminRows, fn($r) => in_array($r['action'] ?? '', ['extension_approved', 'extension_rejected'], true)));
$facPending = count(array_filter($adminRows, fn($r) => ($r['action'] ?? '') === 'faculty_pending'));
$extPending = 0;
try {
    $t = $conn->query("SHOW TABLES LIKE 'extension_requests'");
    if ($t && $t->num_rows > 0) {
        $q = $conn->query("SELECT COUNT(*) AS c FROM extension_requests WHERE status='pending'");
        if ($q && ($rr = $q->fetch_assoc())) $extPending = (int)($rr['c'] ?? 0);
        if ($q) $q->free();
    }
    if ($t) $t->free();
} catch (Throwable $e) { /* point-in-time only */ }

// ── Charts ──
// Aggregated above (status_aggregate) for the fully filtered set and for the
// pre-chart-filter base, so the clicked chart can keep its full breakdown.
$charts = $agg['charts'];
$chartsBase = $baseAgg['charts'];

// ── Pagination (page slice of the filtered set) ──
$total = count($filtered);
$totalPages = max(1, (int)ceil($total / $limit));
if ($page > $totalPages) $page = $totalPages;
$rows = array_slice($filtered, ($page - 1) * $limit, $limit);

echo json_encode([
    'success' => true,
    'from' => $from,
    'to' => $to,
    'kpis' => [
        'roomActions' => count($roomRows),
        'adminActions' => count($adminRows),
        'anomalies' => count($anomRows),
        'lightsOn' => $onCount,
        'lightsOff' => $offCount,
        'lightsFreq' => (round(($onCount + $offCount) / $spanDays, 1)) . '/day',
        'spike' => $spike,
        'motion' => $motion,
        'powerResolved' => $powerRes,
        'other' => $other,
        'anomFreq' => (round(count($anomRows) / $spanDays, 1)) . '/day',
        'facApproved' => $facApproved,
        'facRejected' => $facRejected,
        'extDecisions' => $extDecisions,
        'pending' => $facPending + $extPending,
        'extPending' => $extPending,
    ],
    'charts' => $charts,
    'chartsBase' => $chartsBase,
    'chartsOwnBase' => $chartsOwnBase,
    'activeCharts' => $chartsActive,
    'crossFilter' => empty($chartsActive) ? null : $cross,
    'rows' => array_map(fn($r) => [
        'log_type' => $r['log_type'] ?? '',
        'action' => $r['action'] ?? '',
        'actor' => $r['actor'] ?? '',
        'source' => $r['source'] ?? '',
        'target' => $r['target'] ?? '',
        'log_time' => $r['log_time'] ?? '',
        'date' => $r['date'] ?? '',
        'notes' => $r['notes'] ?? '',
        'label' => status_plain_label($r),
    ], $rows),
    'total' => $total,
    'page' => $page,
    'totalPages' => $totalPages,
]);
