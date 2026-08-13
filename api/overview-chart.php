<?php
/**
 * api/overview-chart.php
 * ----------------------
 * Returns fresh CHART_TODAY (per-minute) data for the overview page.
 * Polled by admin-overview.js to keep the line/bar charts updated.
 */
require_once __DIR__ . "/../src/Session/session_guard.php";
check_admin();
require_once __DIR__ . "/../src/Config/db_connect.php";

header('Content-Type: application/json');
header('Cache-Control: no-store');

date_default_timezone_set('Asia/Manila');

function padMinuteSeries($rows) {
    $nowTotal = (int)date('H') * 60 + (int)date('i');
    $byKey = [];
    foreach ($rows as $r) {
        $key = str_pad((int)$r['hr'], 2, '0', STR_PAD_LEFT) . ':' . str_pad((int)$r['mn'], 2, '0', STR_PAD_LEFT);
        $byKey[$key] = $r;
    }
    $out = [];
    for ($t = 0; $t <= $nowTotal; $t++) {
        $h = intdiv($t, 60);
        $m = $t % 60;
        $key = str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT);
        if (isset($byKey[$key])) {
            $out[] = $byKey[$key];
        } else {
            $out[] = [
                'hr'          => $h,
                'mn'          => $m,
                'avg_voltage' => null,
                'avg_current' => null,
                'avg_power'   => null,
                'energy_wh'   => null,
                'reading_count' => 0,
            ];
        }
    }
    return $out;
}

// Optional per-room filter (used by the room inspect + faculty view modals).
// Without it the endpoint keeps returning the all-rooms aggregate series.
$cidFilter = isset($_GET['classroom_id']) ? (int)$_GET['classroom_id'] : 0;
$where = "DATE(recorded_at) = CURDATE()";
if ($cidFilter > 0) $where .= " AND classroom_id = " . $cidFilter;

// Today per-minute records
$chartToday = [];
$chartTodayRaw = [];
$res = $conn->query("
    SELECT HOUR(recorded_at) AS hr, MINUTE(recorded_at) AS mn,
           ROUND(AVG(voltage),1) AS avg_voltage,
           ROUND(AVG(current),3) AS avg_current,
           ROUND(AVG(power),2) AS avg_power,
           ROUND(SUM(power) * (3/3600), 4) AS energy_wh,
           COUNT(*) AS reading_count
    FROM pzem_readings
    WHERE $where
    GROUP BY hr, mn
    ORDER BY hr, mn
");
while ($row = $res->fetch_assoc()) {
    $chartTodayRaw[] = $row;
}
foreach (padMinuteSeries($chartTodayRaw) as $row) {
    $hh = str_pad((int)$row['hr'], 2, '0', STR_PAD_LEFT);
    $mm = str_pad((int)$row['mn'], 2, '0', STR_PAD_LEFT);
    $chartToday[] = [
        'label'         => $hh . ':' . $mm,
        'time'          => $hh . ':' . $mm,
        'avg_voltage'   => $row['avg_voltage'] !== null ? (float)$row['avg_voltage'] : null,
        'avg_current'   => $row['avg_current'] !== null ? (float)$row['avg_current'] : null,
        'avg_power'     => $row['avg_power']   !== null ? (float)$row['avg_power']   : null,
        'energy_wh'     => $row['energy_wh']   !== null ? (float)$row['energy_wh']   : null,
        'reading_count' => (int)$row['reading_count'],
    ];
}

$conn->close();

echo json_encode([
    'ok'    => true,
    'today' => $chartToday,
]);
