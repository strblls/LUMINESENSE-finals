<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../php/db_connect.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;

$tab = $_GET['tab'] ?? 'activity';

$html = '';

if ($tab === 'rooms') {
    $rooms = [];
    $res = $conn->query("
        SELECT
            c.id, c.room_name, c.room_size, c.description,
            COALESCE(
                (SELECT l.event_type FROM lighting_logs l
                 WHERE l.classroom_id = c.id
                 ORDER BY l.id DESC LIMIT 1),
                'off'
            ) AS light_status,
            (SELECT COUNT(*) FROM room_logs rl WHERE rl.room_name = c.room_name) AS total_events,
            (SELECT MAX(rl2.event_time) FROM room_logs rl2 WHERE rl2.room_name = c.room_name) AS last_event
        FROM classrooms c
        ORDER BY c.room_name ASC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) $rooms[] = $row;
        $res->free();
    }

    $html = '<h2 style="text-align:center;margin-bottom:20px;">Room Activity Summary</h2>';
    $html .= '<table><thead><tr>
        <th>Room</th><th>Light Status</th><th>Size</th><th>Total Events</th><th>Last Activity</th><th>Description</th>
    </tr></thead><tbody>';
    foreach ($rooms as $room) {
        $on = $room['light_status'] === 'on';
        $statusLabel = $on ? 'ON' : 'OFF';
        $statusColor = $on ? '#0f5132' : '#842029';
        $statusBg = $on ? '#d1e7dd' : '#f8d7da';
        $lastStr = !empty($room['last_event']) ? date('M j, g:i A', strtotime($room['last_event'])) : 'No events yet';
        $html .= '<tr>';
        $html .= '<td style="font-weight:700;">' . htmlspecialchars($room['room_name']) . '</td>';
        $html .= '<td><span style="background:' . $statusBg . ';color:' . $statusColor . ';padding:2px 10px;border-radius:20px;font-weight:700;font-size:11px;">' . $statusLabel . '</span></td>';
        $html .= '<td>' . ucfirst(htmlspecialchars($room['room_size'])) . '</td>';
        $html .= '<td>' . (int)$room['total_events'] . '</td>';
        $html .= '<td>' . $lastStr . '</td>';
        $html .= '<td style="color:#888;font-size:10px;">' . htmlspecialchars($room['description'] ?? '—') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
} else {
    $activity_logs = [];

    $res = $conn->query("
        SELECT
            'room' AS log_type, id, event_type AS action,
            room_name AS target, triggered_by AS actor,
            event_time AS log_time, COALESCE(notes,'') AS notes
        FROM room_logs
        ORDER BY event_time DESC LIMIT 200
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) $activity_logs[] = $row;
        $res->free();
    }

    $res2 = $conn->query("
        SELECT
            'admin' AS log_type, al.id, al.action AS action,
            al.target_name AS target,
            COALESCE(CONCAT(a.first_name,' ',a.last_name),'System') AS actor,
            al.created_at AS log_time, COALESCE(al.notes,'') AS notes
        FROM admin_logs al
        LEFT JOIN admins a ON a.id = al.admin_id
        WHERE al.action IN (
            'faculty_approved','faculty_rejected','faculty_pending',
            'extension_approved','extension_rejected'
        )
        ORDER BY al.created_at DESC LIMIT 200
    ");
    if ($res2) {
        while ($row = $res2->fetch_assoc()) $activity_logs[] = $row;
        $res2->free();
    }

    usort($activity_logs, fn($a, $b) => strtotime($b['log_time']) - strtotime($a['log_time']));

    $html = '<h2 style="text-align:center;margin-bottom:20px;">Activity Log</h2>';
    $html .= '<table><thead><tr>
        <th>Time</th><th>Action</th><th>Target</th><th>Actor</th><th>Type</th><th>Notes</th>
    </tr></thead><tbody>';
    foreach ($activity_logs as $log) {
        $logDate = strtotime($log['log_time']);
        $timeStr = date('M j, Y g:i A', $logDate);
        $typeLabel = $log['log_type'] === 'room' ? 'Room' : 'Admin';
        $actionLabel = ucwords(str_replace('_', ' ', $log['action']));
        $html .= '<tr>';
        $html .= '<td style="white-space:nowrap;">' . $timeStr . '</td>';
        $html .= '<td style="font-weight:600;">' . htmlspecialchars($actionLabel) . '</td>';
        $html .= '<td>' . htmlspecialchars($log['target'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($log['actor'] ?? '') . '</td>';
        $html .= '<td><span style="background:' . ($log['log_type'] === 'room' ? '#ede6f2' : '#4a0078') . ';color:' . ($log['log_type'] === 'room' ? '#4a0078' : '#ede6f2') . ';padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;">' . $typeLabel . '</span></td>';
        $html .= '<td style="color:#888;font-size:10px;">' . htmlspecialchars($log['notes'] ?? '') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
}

$html .= '<p style="text-align:center;margin-top:20px;color:#888;font-size:11px;">Generated on ' . date('F j, Y, g:i A') . '</p>';

$dompdf = new Dompdf();
$dompdf->setPaper('A4', 'landscape');

$doc = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
$doc .= '<style>
    body { font-family: Tahoma, DejaVu Sans, sans-serif; margin: 20px; font-size: 11px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #ccc; padding: 6px; text-align: left; vertical-align: top; }
    th { background: #58078f; color: #fff; font-weight: 700; font-size: 11px; }
    tr:nth-child(even) { background: #f9f6fc; }
</style></head><body>' . $html . '</body></html>';

$dompdf->loadHtml($doc);
$dompdf->render();

$filename = 'report-' . $tab . '-' . date('Y-m-d') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $dompdf->output();
