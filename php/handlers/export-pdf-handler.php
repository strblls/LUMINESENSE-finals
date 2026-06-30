<?php
/**
 * LumineSense – Export PDF Handler
 * Generates a PDF timetable from the faculty's schedule data.
 */

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../session_guard.php';
check_faculty();

require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;

$faculty_id   = (int)$_SESSION['faculty_id'];
$faculty_name = htmlspecialchars($_SESSION['faculty_name'] ?? 'Faculty');

$today = date('l');
$days  = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

$schedule_by_day = [];
foreach ($days as $day) $schedule_by_day[$day] = [];

$r = $conn->query("
    SELECT s.id, s.day_of_week, s.start_time, s.end_time,
           s.extended_until, c.room_name, sub.name AS subject_name
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE s.faculty_id = $faculty_id
    ORDER BY FIELD(s.day_of_week,'Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'),
             s.start_time
");
while ($row = $r->fetch_assoc()) {
    $schedule_by_day[$row['day_of_week']][] = $row;
}

$dow_map = ['Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6];
$today_dow_num = $dow_map[$today];
$day_date_map = [];
foreach ($days as $day) {
    $diff = $dow_map[$day] - $today_dow_num;
    $dt = new DateTime("$diff days");
    $day_date_map[$day] = strtoupper($dt->format('M j'));
}

// ── Build HTML table ────────────────────────────────────────────────────
$html = '<h2 style="text-align:center;margin-bottom:20px;">Class Timetable for ' . $faculty_name . '</h2>';
$html .= '<table>';
$html .= '<thead><tr><th>Time</th>';
foreach ($days as $day) {
    $is_today = ($day === $today);
    $style = $is_today ? ' style="background:#5a4bd1;"' : '';
    $html .= '<th' . $style . '>' . $day . '<br><small>' . $day_date_map[$day] . '</small></th>';
}
$html .= '</tr></thead><tbody>';

// Collect all unique start times across the week
$all_times = [];
foreach ($schedule_by_day as $slots) {
    foreach ($slots as $s) {
        $all_times[$s['start_time']] = true;
    }
}
ksort($all_times);

foreach ($all_times as $time => $_) {
    $time_label = date('g:i A', strtotime($time));
    $html .= '<tr><td style="font-weight:700;white-space:nowrap;background:#f0f0f0;">' . $time_label . '</td>';

    foreach ($days as $day) {
        $slot = null;
        foreach ($schedule_by_day[$day] as $s) {
            if ($s['start_time'] === $time) {
                $slot = $s;
                break;
            }
        }

        if ($slot) {
            $end_time = $slot['extended_until'] ?? $slot['end_time'];
            $end_label = date('g:i A', strtotime($end_time));
            $is_today = ($day === $today);
            $td_class = $is_today ? ' class="today"' : '';
            $html .= '<td' . $td_class . '>';
            $html .= '<strong>' . htmlspecialchars($slot['room_name']) . '</strong><br>';
            $html .= htmlspecialchars($slot['subject_name'] ?? 'No subject') . '<br>';
            $html .= '<small>' . $time_label . ' – ' . $end_label . '</small>';
            if ($slot['extended_until']) {
                $html .= '<br><small style="color:#c0004e;">Extended to ' . date('g:i A', strtotime($slot['extended_until'])) . '</small>';
            }
            $html .= '</td>';
        } else {
            $is_today = ($day === $today);
            $td_class = $is_today ? ' class="today"' : '';
            $html .= '<td' . $td_class . '><span class="no-sched">—</span></td>';
        }
    }

    $html .= '</tr>';
}

$html .= '</tbody></table>';
$html .= '<p style="text-align:center;margin-top:20px;color:#888;font-size:11px;">Generated on ' . date('F j, Y') . '</p>';

$dompdf = new Dompdf();
$dompdf->setPaper('A4', 'landscape');

$doc = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
$doc .= '<style>
    body { font-family: Tahoma, DejaVu Sans, sans-serif; margin: 20px; font-size: 11px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #ccc; padding: 6px; text-align: center; vertical-align: top; }
    th { background: #5a189a; color: #fff; font-weight: 700; font-size: 12px; }
    th small { color: #fff; }
    td.today { background: #e8e5ff; }
    .no-sched { color: #bbb; }
    small { font-size: 9px; color: #666; }
</style></head><body>' . $html . '</body></html>';

$dompdf->loadHtml($doc);
$dompdf->render();

$filename = 'timetable-' . date('Y-m-d') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $dompdf->output();
