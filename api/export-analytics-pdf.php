<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . "/../src/Config/db_connect.php";
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;

header('Content-Type: application/pdf');

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || empty($data['section'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing section.']);
    exit;
}

$section = $data['section'];
$range   = (int)($data['range'] ?? 7);
$rows    = $data['data'] ?? [];
$cid     = (int)($data['classroom_id'] ?? 0);
$roomName = $cid ? '' : 'All Rooms';

if ($cid) {
    $r = $conn->query("SELECT room_name FROM classrooms WHERE id = $cid");
    if ($r && $row = $r->fetch_assoc()) $roomName = $row['room_name'];
}

$rangeLabel = match($range) {
    1  => 'Today',
    7  => 'Last 7 Days',
    14 => 'Last 14 Days',
    30 => 'Last 30 Days',
    default => $range . ' Days',
};

$sectionTitle = match($section) {
    'lineGraphCard' => 'Line Graph Data',
    'barGraphCard'  => 'Bar Graph Data',
    'historyCard'   => 'History Table',
    default         => 'Analytics Export',
};

// - Build HTML -----------------------------
$html = '<h2 style="text-align:center;margin-bottom:5px;color:#2f004f;">LumineSense - ' . $sectionTitle . '</h2>';
$html .= '<p style="text-align:center;margin-bottom:20px;color:#888;font-size:12px;">' . $rangeLabel . ' &middot; ' . htmlspecialchars($roomName) . ' &middot; Generated ' . date('M j, Y g:i A') . '</p>';

if ($section === 'historyCard') {
    $html .= '<table><thead><tr>';
    $html .= '<th>Date</th><th>Sessions</th><th>Occupied (hrs)</th><th>Energy (Wh)</th><th>Energy (kWh)</th>';
    $html .= '</tr></thead><tbody>';

    $totalSessions = 0;
    $totalMinutes  = 0;
    $totalWh       = 0;

    foreach ($rows as $row) {
        $sessions = (int)($row['sessions'] ?? 0);
        $minutes  = (float)($row['minutes'] ?? 0);
        $wh       = (float)($row['energy_wh'] ?? 0);
        $kwh      = round($wh / 1000, 4);
        $hrs      = round($minutes / 60, 1);

        $totalSessions += $sessions;
        $totalMinutes  += $minutes;
        $totalWh       += $wh;

        $html .= '<tr>';
        $html .= '<td style="font-weight:600;">' . htmlspecialchars($row['label'] ?? $row['date'] ?? '') . '</td>';
        $html .= '<td style="text-align:center;">' . $sessions . '</td>';
        $html .= '<td style="text-align:center;">' . $hrs . '</td>';
        $html .= '<td style="text-align:center;">' . number_format($wh, 2) . '</td>';
        $html .= '<td style="text-align:center;">' . number_format($kwh, 4) . '</td>';
        $html .= '</tr>';
    }

    $totalKwh = round($totalWh / 1000, 4);
    $totalHrs = round($totalMinutes / 60, 1);
    $html .= '<tr style="font-weight:700;background:#f0eaf8;">';
    $html .= '<td>Total</td>';
    $html .= '<td style="text-align:center;">' . $totalSessions . '</td>';
    $html .= '<td style="text-align:center;">' . $totalHrs . '</td>';
    $html .= '<td style="text-align:center;">' . number_format($totalWh, 2) . '</td>';
    $html .= '<td style="text-align:center;">' . number_format($totalKwh, 4) . '</td>';
    $html .= '</tr>';
    $html .= '</tbody></table>';

} else {
    $label = ($section === 'lineGraphCard') ? 'Line Graph' : 'Vertical Bar Graph';

    // Calculate summary stats
    $voltages = [];
    $currents = [];
    $powers   = [];
    foreach ($rows as $row) {
        if ($row['avg_voltage'] !== null) $voltages[] = (float)$row['avg_voltage'];
        if ($row['avg_current'] !== null) $currents[] = (float)$row['avg_current'];
        if ($row['avg_power']   !== null) $powers[]   = (float)$row['avg_power'];
    }
    $avgV = !empty($voltages) ? array_sum($voltages) / count($voltages) : 0;
    $avgA = !empty($currents) ? array_sum($currents) / count($currents) : 0;
    $avgW = !empty($powers)   ? array_sum($powers)   / count($powers)   : 0;
    $totalWh = round($avgW * (count($rows) / 12), 2);

    $html .= '<table><thead><tr>';
    $html .= '<th>Time</th><th>Voltage (V)</th><th>Current (A)</th><th>Power (W)</th>';
    $html .= '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $v = $row['avg_voltage'] ?? '';
        $a = $row['avg_current'] ?? '';
        $w = $row['avg_power'] ?? '';
        $html .= '<tr>';
        $html .= '<td style="font-weight:600;">' . htmlspecialchars($row['label'] ?? '') . '</td>';
        $html .= '<td style="text-align:center;">' . ($v !== null ? number_format($v, 1) : '-') . '</td>';
        $html .= '<td style="text-align:center;">' . ($a !== null ? number_format($a, 3) : '-') . '</td>';
        $html .= '<td style="text-align:center;">' . ($w !== null ? number_format($w, 2) : '-') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    // Summary statistics box with calculation breakdown
    $n = count($voltages);
    $html .= '<div style="margin-top:20px;padding:12px 18px;background:#f8f1ff;border:1px solid rgba(116,47,211,0.2);border-radius:8px;">';
    $html .= '<h3 style="font-size:13px;color:#2f004f;margin:0 0 8px 0;">Summary Statistics</h3>';

    $html .= '<table style="width:100%;border-collapse:collapse;margin:0;">';

    // Average Voltage
    $vSum = number_format(array_sum($voltages), 1);
    $html .= '<tr>';
    $html .= '<td style="border:none;padding:3px 10px 3px 0;font-weight:600;color:#2f004f;width:30%;">Average Voltage</td>';
    $html .= '<td style="border:none;padding:3px 0;font-family:monospace;font-size:10px;">= Sum(Voltage) / Count = ' . $vSum . ' / ' . $n . ' = <strong>' . number_format($avgV, 1) . ' V</strong></td>';
    $html .= '</tr>';

    // Average Current
    $aSum = number_format(array_sum($currents), 3);
    $html .= '<tr>';
    $html .= '<td style="border:none;padding:3px 10px 3px 0;font-weight:600;color:#2f004f;">Average Current</td>';
    $html .= '<td style="border:none;padding:3px 0;font-family:monospace;font-size:10px;">= Sum(Current) / Count = ' . $aSum . ' / ' . $n . ' = <strong>' . number_format($avgA, 3) . ' A</strong></td>';
    $html .= '</tr>';

    // Average Power
    $wSum = number_format(array_sum($powers), 2);
    $html .= '<tr>';
    $html .= '<td style="border:none;padding:3px 10px 3px 0;font-weight:600;color:#2f004f;">Average Power</td>';
    $html .= '<td style="border:none;padding:3px 0;font-family:monospace;font-size:10px;">= Sum(Power) / Count = ' . $wSum . ' / ' . $n . ' = <strong>' . number_format($avgW, 2) . ' W</strong></td>';
    $html .= '</tr>';

    // Energy (Wh)
    $hours = round($n / 12, 2);
    $html .= '<tr>';
    $html .= '<td style="border:none;padding:3px 10px 3px 0;font-weight:600;color:#2f004f;">Energy (Wh)</td>';
    $html .= '<td style="border:none;padding:3px 0;font-family:monospace;font-size:10px;">= Avg Power &times; Hours = ' . number_format($avgW, 2) . ' &times; ' . $hours . ' = <strong>' . number_format($totalWh, 4) . ' Wh</strong></td>';
    $html .= '</tr>';

    $html .= '</table>';
    $html .= '</div>';

    // Graph image - save to temp file (dompdf blocks base64 data URIs)
    $tempImage = null;
    $graphImage = $data['graph_image'] ?? null;
    if ($graphImage && preg_match('/^data:image\/(\w+);base64,/', $graphImage, $m)) {
        $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
        $tempImage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'luminesense_graph_' . md5(uniqid()) . '.' . $ext;
        file_put_contents($tempImage, base64_decode(substr($graphImage, strpos($graphImage, ',') + 1)));
    }

    if ($tempImage && file_exists($tempImage)) {
        $html .= '<div style="margin-top:20px;text-align:center;">';
        $html .= '<h3 style="font-size:13px;color:#2f004f;margin:0 0 8px 0;">' . htmlspecialchars($label) . '</h3>';
        $html .= '<img src="' . $tempImage . '" style="width:100%;max-width:900px;" />';
        $html .= '</div>';
    }
}

$html .= '<p style="text-align:center;margin-top:15px;color:#aaa;font-size:10px;">LumineSense Energy Monitoring System</p>';

// - Generate PDF ----------------------------
$dompdf = new Dompdf();
$dompdf->setPaper('A4', 'landscape');
$opts = $dompdf->getOptions();
$chroot = $opts->getChroot();
$chroot[] = sys_get_temp_dir();
$opts->setChroot($chroot);
$dompdf->setOptions($opts);

$doc = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
$doc .= '<style>
    body { font-family: Tahoma, DejaVu Sans, sans-serif; margin: 20px; font-size: 11px; color: #333; }
    h2 { font-size: 18px; margin-bottom: 5px; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; }
    th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: left; vertical-align: middle; }
    th { background: #58078f; color: #fff; font-weight: 700; font-size: 11px; }
    tr:nth-child(even) { background: #f9f6fc; }
    tr:last-child td { font-weight: 700; background: #f0eaf8; }
</style></head><body>' . $html . '</body></html>';

$dompdf->loadHtml($doc);
$dompdf->render();

$filename = 'luminesense_' . str_replace(' ', '_', strtolower($sectionTitle)) . '_' . $rangeLabel . '_' . date('Y-m-d') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $dompdf->output();

if ($tempImage && file_exists($tempImage)) @unlink($tempImage);
