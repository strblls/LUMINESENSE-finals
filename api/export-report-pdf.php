<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . "/../src/Config/db_connect.php";
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;

$tab    = $_GET['tab'] ?? 'activity';
$search = $_GET['search'] ?? '';
$type   = $_GET['type'] ?? '';
$date   = $_GET['date'] ?? '';
$today  = date('Y-m-d');
$weekAgo = date('Y-m-d', strtotime('-7 days'));
$monthAgo = date('Y-m-d', strtotime('-30 days'));

$html = '';

/* - Helper: event icon colors for PDF - */
function pdfEventStyle(string $action): array {
    $map = [
        'issue_raised'   => ['#842029', '#f8d7da'],
        'issue_resolved' => ['#0f5132', '#d1e7dd'],
        'tilt_alert'     => ['#7f1d1d', '#fee2e2'],
        'light_on'       => ['#0f5132', '#d1e7dd'],
        'light_off'      => ['#842029', '#f8d7da'],
        'pir_motion'     => ['#084298', '#cfe2ff'],
        'pir_stopped'    => ['#5a5a5a', '#e9ecef'],
        'class_start'    => ['#0d6e3b', '#d1e7dd'],
        'class_end'      => ['#6c4c00', '#fff3cd'],
    ];
    return $map[$action] ?? ['#5a5a5a', '#e9ecef'];
}

if ($tab === 'rooms') {
    $rooms = [];
    $where = [];
    if ($search) {
        $s = $conn->real_escape_string($search);
        $where[] = "(c.room_name LIKE '%$s%' OR c.description LIKE '%$s%')";
    }

    $lightFilter = $type; // roomLightFilter value
    if ($lightFilter === 'on' || $lightFilter === 'off') {
        $lf = $conn->real_escape_string($lightFilter);
        $where[] = "COALESCE((SELECT l.event_type FROM lighting_logs l WHERE l.classroom_id = c.id ORDER BY l.id DESC LIMIT 1),'off') = '$lf'";
    } elseif ($lightFilter === 'pir_motion') {
        $where[] = "c.pir_occupied = 1";
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $res = $conn->query("
        SELECT
            c.id, c.room_name, c.room_size, c.description,
            COALESCE(
                (SELECT l.event_type FROM lighting_logs l
                 WHERE l.classroom_id = c.id
                 ORDER BY l.id DESC LIMIT 1),
                'off'
            ) AS light_status,
            (
                COALESCE((SELECT COUNT(*) FROM room_logs WHERE room_name = c.room_name), 0) +
                COALESCE((SELECT COUNT(*) FROM lighting_logs WHERE classroom_id = c.id), 0) +
                COALESCE((SELECT COUNT(*) FROM pir_logs WHERE classroom_id = c.id), 0) +
                COALESCE((SELECT COUNT(*) FROM class_logs WHERE classroom_id = c.id), 0)
            ) AS total_events,
            (
                GREATEST(
                    COALESCE((SELECT MAX(event_time) FROM room_logs WHERE room_name = c.room_name), '1970-01-01 00:00:00'),
                    COALESCE((SELECT MAX(event_time) FROM lighting_logs WHERE classroom_id = c.id), '1970-01-01 00:00:00'),
                    COALESCE((SELECT MAX(created_at) FROM pir_logs WHERE classroom_id = c.id), '1970-01-01 00:00:00'),
                    COALESCE((SELECT MAX(event_time) FROM class_logs WHERE classroom_id = c.id), '1970-01-01 00:00:00')
                )
            ) AS last_event
        FROM classrooms c
        $whereClause
        ORDER BY c.room_name ASC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if ($row['last_event'] === '1970-01-01 00:00:00') {
                $row['last_event'] = null;
            }
            $rooms[] = $row;
        }
        $res->free();
    }

    $html = '<h2 style="text-align:center;margin-bottom:20px;">Room Activity Summary</h2>';
    if ($search) $html .= '<p style="text-align:center;color:#888;font-size:10px;">Filtered by: "' . htmlspecialchars($search) . '"</p>';
    $html .= '<table><thead><tr>
        <th>Room</th><th>Light Status</th><th>Size</th><th>Total Events</th><th>Last Activity</th><th>Description</th>
    </tr></thead><tbody>';
    if (empty($rooms)) {
        $html .= '<tr><td colspan="6" style="text-align:center;color:#999;">No rooms match the current filters.</td></tr>';
    } else {
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
            $html .= '<td style="color:#888;font-size:10px;">' . htmlspecialchars($room['description'] ?? '-') . '</td>';
            $html .= '</tr>';
        }
    }
    $html .= '</tbody></table>';
} elseif ($tab === 'issues') {
    $where = ["event_type IN ('issue_raised','issue_resolved','tilt_alert')"];
    if ($search) {
        $s = $conn->real_escape_string($search);
        $where[] = "(room_name LIKE '%$s%' OR notes LIKE '%$s%' OR triggered_by LIKE '%$s%')";
    }
    if ($type) {
        $t = $conn->real_escape_string($type);
        $where[] = "event_type = '$t'";
    }
    if ($date === 'today') {
        $where[] = "DATE(event_time) = '$today'";
    } elseif ($date === 'week') {
        $where[] = "DATE(event_time) >= '$weekAgo'";
    } elseif ($date === 'month') {
        $where[] = "DATE(event_time) >= '$monthAgo'";
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);
    $issues = [];
    $res = $conn->query("
        SELECT id, event_type, room_name, triggered_by, event_time, COALESCE(notes,'') AS notes
        FROM room_logs
        $whereClause
        ORDER BY event_time DESC
        LIMIT 200
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) $issues[] = $row;
        $res->free();
    }

    $html = '<h2 style="text-align:center;margin-bottom:20px;">Issues Logged</h2>';
    if ($search) $html .= '<p style="text-align:center;color:#888;font-size:10px;">Filtered by: "' . htmlspecialchars($search) . '"</p>';
    $html .= '<table><thead><tr>
        <th>Time</th><th>Issue</th><th>Room</th><th>Triggered By</th><th>Notes</th>
    </tr></thead><tbody>';
    if (empty($issues)) {
        $html .= '<tr><td colspan="5" style="text-align:center;color:#999;">No issues match the current filters.</td></tr>';
    } else {
        foreach ($issues as $issue) {
            [$fg, $bg] = pdfEventStyle($issue['event_type']);
            $isTilt   = $issue['event_type'] === 'tilt_alert';
            $isRaised = $issue['event_type'] === 'issue_raised';
            $label = $isTilt ? 'Tilt Alert' : ($isRaised ? 'Issue Raised' : 'Issue Resolved');
            $timeStr = date('M j, Y g:i A', strtotime($issue['event_time']));
            $html .= '<tr>';
            $html .= '<td style="white-space:nowrap;">' . $timeStr . '</td>';
            $html .= '<td><span style="background:' . $bg . ';color:' . $fg . ';padding:2px 10px;border-radius:20px;font-weight:700;font-size:10px;">' . $label . '</span></td>';
            $html .= '<td style="font-weight:600;">' . htmlspecialchars($issue['room_name']) . '</td>';
            $html .= '<td>' . htmlspecialchars($issue['triggered_by']) . '</td>';
            $html .= '<td style="color:#888;font-size:10px;">' . htmlspecialchars($issue['notes']) . '</td>';
            $html .= '</tr>';
        }
    }
    $html .= '</tbody></table>';
    } elseif ($tab === 'flush_archive') {
        $registryId = (int)($_GET['id'] ?? 0);
        if ($registryId <= 0) {
            $title = 'Flush Archive';
            $subtitle = 'No archive ID specified';
            $html = '<p>No archive ID provided.</p>';
        } else {
            $arch = $conn->query("SELECT * FROM archive_registry WHERE id = {$registryId}")->fetch_assoc();
            if (!$arch) {
                $title = 'Flush Archive';
                $subtitle = 'Archive not found';
                $html = '<p>Archive not found.</p>';
            } else {
                $title = 'Flush Archive Detail';
                $subtitle = $arch['semester'] . ' ' . $arch['academic_year'] . ' | ' . date('M j, Y g:i A', strtotime($arch['flush_at']));
                $schedQ = $conn->query("SELECT COUNT(*) as cnt FROM archived_schedules WHERE registry_id = {$registryId}");
                $deptQ = $conn->query("SELECT COUNT(*) as cnt FROM archived_departments WHERE registry_id = {$registryId}");
                $subjQ = $conn->query("SELECT COUNT(*) as cnt FROM archived_subjects WHERE registry_id = {$registryId}");
                $extQ = $conn->query("SELECT COUNT(*) as cnt FROM archived_extension_requests WHERE registry_id = {$registryId}");
                $schedCnt = $schedQ ? $schedQ->fetch_assoc()['cnt'] : 0;
                $deptCnt = $deptQ ? $deptQ->fetch_assoc()['cnt'] : 0;
                $subjCnt = $subjQ ? $subjQ->fetch_assoc()['cnt'] : 0;
                $extCnt = $extQ ? $extQ->fetch_assoc()['cnt'] : 0;
                $html = '<h2>Flush Archive</h2>';
                $html .= '<p><strong>Semester:</strong> ' . htmlspecialchars($arch['semester'] . ' ' . $arch['academic_year']) . '</p>';
                $html .= '<p><strong>Flushed:</strong> ' . date('M j, Y g:i A', strtotime($arch['flush_at'])) . '</p>';
                $html .= '<p><strong>Archived:</strong> ' . number_format($arch['total_archived']) . ' records | <strong>Deleted:</strong> ' . number_format($arch['total_deleted']) . ' records</p>';
                $html .= '<table border="1" cellpadding="4" cellspacing="0" width="100%">';
                $html .= '<tr><th>Category</th><th>Records</th></tr>';
                $html .= '<tr><td>Schedules</td><td>' . number_format($schedCnt) . '</td></tr>';
                $html .= '<tr><td>Departments</td><td>' . number_format($deptCnt) . '</td></tr>';
                $html .= '<tr><td>Subjects</td><td>' . number_format($subjCnt) . '</td></tr>';
                $html .= '<tr><td>Extension Requests</td><td>' . number_format($extCnt) . '</td></tr>';
                $html .= '</table>';
            }
        }
    } elseif ($tab === 'pzem_archive') {
        $title = 'PZEM Archive';
        $subtitle = 'Daily energy data aggregation history';
        $result = $conn->query("SELECT archive_date, total_readings, records_archived, records_purged FROM pzem_archive ORDER BY archive_date DESC LIMIT 50");
        $html = '<h2>PZEM Archive</h2>';
        $html .= '<table border="1" cellpadding="4" cellspacing="0" width="100%">';
        $html .= '<tr><th>Date</th><th>Total Readings</th><th>Archived</th><th>Purged</th></tr>';
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $html .= '<tr>';
                $html .= '<td>' . date('M j, Y', strtotime($row['archive_date'])) . '</td>';
                $html .= '<td>' . number_format($row['total_readings']) . '</td>';
                $html .= '<td>' . number_format($row['records_archived']) . '</td>';
                $html .= '<td>' . number_format($row['records_purged']) . '</td>';
                $html .= '</tr>';
            }
        }
        $html .= '</table>';
    } else {
    // - Activity tab -
    $activity_logs = [];

    $roomWhere = [];
    if ($search) {
        $s = $conn->real_escape_string($search);
        $roomWhere[] = "(room_name LIKE '%$s%' OR triggered_by LIKE '%$s%' OR event_type LIKE '%$s%' OR notes LIKE '%$s%')";
    }
    if ($date === 'today') {
        $roomWhere[] = "DATE(event_time) = '$today'";
    } elseif ($date === 'week') {
        $roomWhere[] = "DATE(event_time) >= '$weekAgo'";
    } elseif ($date === 'month') {
        $roomWhere[] = "DATE(event_time) >= '$monthAgo'";
    }
    $roomWhereClause = $roomWhere ? 'WHERE ' . implode(' AND ', $roomWhere) : '';

    // room_logs
    $res = $conn->query("
        SELECT 'room' AS log_type, id, event_type AS action,
               room_name AS target, triggered_by AS actor,
               event_time AS log_time, COALESCE(notes,'') AS notes
        FROM room_logs
        $roomWhereClause
        ORDER BY event_time DESC LIMIT 200
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) $activity_logs[] = $row;
        $res->free();
    }

    // pir_logs
    $pirWhere = [];
    if ($search) {
        $s = $conn->real_escape_string($search);
        $pirWhere[] = "(c.room_name LIKE '%$s%' OR 'PIR' LIKE '%$s%')";
    }
    if ($date === 'today') {
        $pirWhere[] = "DATE(pl.created_at) = '$today'";
    } elseif ($date === 'week') {
        $pirWhere[] = "DATE(pl.created_at) >= '$weekAgo'";
    } elseif ($date === 'month') {
        $pirWhere[] = "DATE(pl.created_at) >= '$monthAgo'";
    }
    $pirWhereClause = $pirWhere ? 'AND ' . implode(' AND ', $pirWhere) : '';

    $res3 = $conn->query("
        SELECT 'room' AS log_type, pl.id,
               CASE pl.state WHEN 1 THEN 'pir_motion' ELSE 'pir_stopped' END AS action,
               c.room_name AS target, 'PIR' AS actor,
               pl.created_at AS log_time, '' AS notes
        FROM pir_logs pl
        JOIN classrooms c ON c.id = pl.classroom_id
        $pirWhereClause
        ORDER BY pl.created_at DESC LIMIT 200
    ");
    if ($res3) {
        while ($row = $res3->fetch_assoc()) $activity_logs[] = $row;
        $res3->free();
    }

    // class_logs
    $classWhere = [];
    if ($search) {
        $s = $conn->real_escape_string($search);
        $classWhere[] = "(c.room_name LIKE '%$s%' OR cl.triggered_by LIKE '%$s%' OR cl.event_type LIKE '%$s%')";
    }
    if ($date === 'today') {
        $classWhere[] = "DATE(cl.event_time) = '$today'";
    } elseif ($date === 'week') {
        $classWhere[] = "DATE(cl.event_time) >= '$weekAgo'";
    } elseif ($date === 'month') {
        $classWhere[] = "DATE(cl.event_time) >= '$monthAgo'";
    }
    $classWhereClause = $classWhere ? 'AND ' . implode(' AND ', $classWhere) : '';

    $res4 = $conn->query("
        SELECT 'room' AS log_type, cl.id, cl.event_type AS action,
               c.room_name AS target, COALESCE(cl.triggered_by,'schedule') AS actor,
               cl.event_time AS log_time, COALESCE(cl.notes,'') AS notes
        FROM class_logs cl
        JOIN classrooms c ON c.id = cl.classroom_id
        $classWhereClause
        ORDER BY cl.event_time DESC LIMIT 200
    ");
    if ($res4) {
        while ($row = $res4->fetch_assoc()) $activity_logs[] = $row;
        $res4->free();
    }

    // admin_logs - only when no type filter excludes 'admin'
    if (!$type || $type === 'admin') {
        $adminWhere = ["al.action IN ('faculty_approved','faculty_rejected','faculty_pending','extension_approved','extension_rejected')"];
        if ($search) {
            $s = $conn->real_escape_string($search);
            $adminWhere[] = "(al.target_name LIKE '%$s%' OR CONCAT(a.first_name,' ',a.last_name) LIKE '%$s%' OR al.action LIKE '%$s%')";
        }
        if ($date === 'today') {
            $adminWhere[] = "DATE(al.created_at) = '$today'";
        } elseif ($date === 'week') {
            $adminWhere[] = "DATE(al.created_at) >= '$weekAgo'";
        } elseif ($date === 'month') {
            $adminWhere[] = "DATE(al.created_at) >= '$monthAgo'";
        }
        $adminWhereClause = 'WHERE ' . implode(' AND ', $adminWhere);

        $res2 = $conn->query("
            SELECT 'admin' AS log_type, al.id, al.action AS action,
                   al.target_name AS target,
                   COALESCE(CONCAT(a.first_name,' ',a.last_name),'System') AS actor,
                   al.created_at AS log_time, COALESCE(al.notes,'') AS notes
            FROM admin_logs al
            LEFT JOIN admins a ON a.id = al.admin_id
            $adminWhereClause
            ORDER BY al.created_at DESC LIMIT 200
        ");
        if ($res2) {
            while ($row = $res2->fetch_assoc()) $activity_logs[] = $row;
            $res2->free();
        }
    }

    usort($activity_logs, fn($a, $b) => strtotime($b['log_time']) - strtotime($a['log_time']));

    // Apply type filter in PHP (log_type: room/admin, or action prefix: pir_*/class_*)
    if ($type) {
        $activity_logs = array_filter($activity_logs, function($log) use ($type) {
            if ($type === 'pir') return str_starts_with($log['action'], 'pir_');
            if ($type === 'class') return str_starts_with($log['action'], 'class_');
            return $log['log_type'] === $type;
        });
    }

    $html = '<h2 style="text-align:center;margin-bottom:20px;">Activity Log</h2>';
    if ($search) $html .= '<p style="text-align:center;color:#888;font-size:10px;">Filtered by: "' . htmlspecialchars($search) . '"</p>';
    $html .= '<table><thead><tr>
        <th>Time</th><th>Action</th><th>Target</th><th>Actor</th><th>Type</th><th>Notes</th>
    </tr></thead><tbody>';
    if (empty($activity_logs)) {
        $html .= '<tr><td colspan="6" style="text-align:center;color:#999;">No activity logs match the current filters.</td></tr>';
    } else {
        foreach ($activity_logs as $log) {
            $logDate = strtotime($log['log_time']);
            $timeStr = date('M j, Y g:i A', $logDate);
            $isRoom = $log['log_type'] === 'room';
            $typeLabel = $isRoom ? 'Room' : 'Admin';
            $typeBg = $isRoom ? '#ede6f2' : '#4a0078';
            $typeClr = $isRoom ? '#4a0078' : '#ede6f2';
            $actionLabel = str_replace('Pir ', 'PIR ', ucwords(str_replace('_', ' ', $log['action'])));
            $html .= '<tr>';
            $html .= '<td style="white-space:nowrap;">' . $timeStr . '</td>';
            $html .= '<td style="font-weight:600;">' . htmlspecialchars($actionLabel) . '</td>';
            $html .= '<td>' . htmlspecialchars($log['target'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($log['actor'] ?? '') . '</td>';
            $html .= '<td><span style="background:' . $typeBg . ';color:' . $typeClr . ';padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;">' . $typeLabel . '</span></td>';
            $html .= '<td style="color:#888;font-size:10px;">' . htmlspecialchars($log['notes'] ?? '') . '</td>';
            $html .= '</tr>';
        }
    }
    $html .= '</tbody></table>';
}

$html .= '<p style="text-align:center;margin-top:20px;color:#888;font-size:11px;">Generated on ' . date('F j, Y, g:i A') . '</p>';

// Mode=view: return HTML preview instead of PDF
if (isset($_GET['mode']) && $_GET['mode'] === 'view') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title ?? 'Report') . '</title>';
    echo '<style>body{font-family:Arial,sans-serif;padding:20px;font-size:13px;}table{width:100%;border-collapse:collapse;margin-top:10px;}th,td{border:1px solid #ccc;padding:6px 10px;text-align:left;}th{background:#f5f5f5;font-weight:600;}h2{margin-bottom:2px;}h3{color:#666;font-weight:normal;margin-top:0;}</style>';
    echo '</head><body>';
    echo '<h2>' . htmlspecialchars($title ?? 'Report') . '</h2>';
    if (!empty($subtitle)) echo '<h3>' . htmlspecialchars($subtitle) . '</h3>';
    echo $html ?? '<p>No data.</p>';
    echo '</body></html>';
    exit(0);
}

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

$filename = $title . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $dompdf->output();
