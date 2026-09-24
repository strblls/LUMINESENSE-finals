<?php
// api/sessions.php
// GET ?from=YYYY-MM-DD&to=YYYY-MM-DD&room_id=X&faculty_id=Y&page=N&limit=M
// Searchable list of CLOSED power sessions (end_time IS NOT NULL), newest first.
// Feeds the "History" view inside the faculty Gantt modal on admin-overview.php.

require_once __DIR__ . "/../src/Session/session_guard.php";
check_admin();
require_once __DIR__ . "/../src/Config/db_connect.php";
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');
header('Cache-Control: no-store');

// ── Validate filters ─────────────────────────────────────────────
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');
if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    echo json_encode(['success' => false, 'message' => 'Invalid from date.']); exit;
}
if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    echo json_encode(['success' => false, 'message' => 'Invalid to date.']); exit;
}
$roomId = (int)($_GET['room_id'] ?? 0);
$facId  = (int)($_GET['faculty_id'] ?? 0);
$page   = max((int)($_GET['page'] ?? 1), 1);
$limit  = (int)($_GET['limit'] ?? 20);
if ($limit < 1 || $limit > 100) $limit = 20;
$offset = ($page - 1) * $limit;

// ── Build WHERE from bound params only ───────────────────────────
$conds  = ['ps.end_time IS NOT NULL'];
$types  = '';
$params = [];
if ($from !== '') { $conds[] = 'ps.session_date >= ?'; $types .= 's'; $params[] = $from; }
if ($to !== '')   { $conds[] = 'ps.session_date <= ?'; $types .= 's'; $params[] = $to; }
if ($roomId > 0)  { $conds[] = 'ps.classroom_id = ?';  $types .= 'i'; $params[] = $roomId; }
if ($facId > 0)   { $conds[] = 'ps.faculty_id = ?';    $types .= 'i'; $params[] = $facId; }
$where = implode(' AND ', $conds);

// ── Total count (for pagination) ─────────────────────────────────
$total = 0;
$sqlCount = "SELECT COUNT(*) AS c FROM power_sessions ps WHERE $where";
$stmt = $conn->prepare($sqlCount);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$r = $stmt->get_result();
if ($row = $r->fetch_assoc()) $total = (int)$row['c'];
$stmt->close();

// ── Page of sessions with room + faculty labels ──────────────────
$sessions = [];
$sql = "SELECT ps.id, ps.classroom_id, ps.session_date,
               DATE_FORMAT(ps.start_time, '%H:%i:%s') AS start_t,
               DATE_FORMAT(ps.end_time, '%H:%i:%s') AS end_t,
               DATE_FORMAT(ps.start_time, '%Y-%m-%d %H:%i:%s') AS start_dt,
               DATE_FORMAT(ps.end_time, '%Y-%m-%d %H:%i:%s') AS end_dt,
               ps.duration_mins, ps.trigger_source,
               ps.avg_voltage, ps.avg_current, ps.peak_power, ps.total_energy_wh,
               c.room_name,
               CONCAT(f.first_name, ' ', f.last_name) AS faculty_name
        FROM power_sessions ps
        LEFT JOIN classrooms c ON c.id = ps.classroom_id
        LEFT JOIN faculty f ON f.id = ps.faculty_id
        WHERE $where
        ORDER BY ps.session_date DESC, ps.start_time DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$allTypes = $types . 'ii';
$allParams = array_merge($params, [$limit, $offset]);
$stmt->bind_param($allTypes, ...$allParams);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) {
    $sessions[] = [
        'id'             => (int)$row['id'],
        'classroom_id'   => (int)$row['classroom_id'],
        'session_date'   => $row['session_date'],
        'start'          => $row['start_t'],
        'end'            => $row['end_t'],
        'start_dt'       => $row['start_dt'],
        'end_dt'         => $row['end_dt'],
        'duration_mins'  => (int)$row['duration_mins'],
        'trigger_source' => $row['trigger_source'],
        'avg_voltage'    => $row['avg_voltage'] !== null ? (float)$row['avg_voltage'] : null,
        'avg_current'    => $row['avg_current'] !== null ? (float)$row['avg_current'] : null,
        'peak_power'     => $row['peak_power'] !== null ? (float)$row['peak_power'] : null,
        'total_energy_wh'=> $row['total_energy_wh'] !== null ? (float)$row['total_energy_wh'] : null,
        'room_name'      => $row['room_name'] ?? '—',
        'faculty_name'   => $row['faculty_name'] !== null ? trim($row['faculty_name']) : null,
    ];
}
$stmt->close();

echo json_encode([
    'success'  => true,
    'sessions' => $sessions,
    'total'    => $total,
    'page'     => $page,
    'limit'    => $limit,
    'pages'    => (int)ceil($total / $limit),
]);
$conn->close();
