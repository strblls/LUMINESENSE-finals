<?php
/**
 * api/archive-list.php
 * --------------------
 * Returns the list of archive dates available for the analytics Archives
 * picker. Session-guarded (admin or faculty).
 *
 * GET ?classroom_id=3
 *   → dates that have rows in pzem_archive for that classroom.
 * GET (no classroom_id) or classroom_id=0
 *   → dates that have rows for ANY classroom.
 *
 * Response:
 *   { "success": true, "dates": [
 *       { "date": "2026-08-02", "label": "Aug 2, 2026", "minutes": 120, "energy_wh": 32.4 },
 *       ...
 *   ]}
 */

require_once __DIR__ . "/../src/Config/db_connect.php";
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['faculty_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    $conn->close(); exit;
}

$cid = (int)($_GET['classroom_id'] ?? 0);

if ($cid > 0) {
    $stmt = $conn->prepare("
        SELECT archive_date,
               COUNT(*)                         AS minutes,
               ROUND(SUM(COALESCE(energy_wh,0)), 2) AS energy_wh
        FROM pzem_archive
        WHERE classroom_id = ?
        GROUP BY archive_date
        ORDER BY archive_date DESC
    ");
    $stmt->bind_param('i', $cid);
} else {
    $stmt = $conn->prepare("
        SELECT archive_date,
               COUNT(*)                         AS minutes,
               ROUND(SUM(COALESCE(energy_wh,0)), 2) AS energy_wh
        FROM pzem_archive
        GROUP BY archive_date
        ORDER BY archive_date DESC
    ");
}
$stmt->execute();
$r = $stmt->get_result();
$stmt->close();

$dates = [];
while ($row = $r->fetch_assoc()) {
    $d = $row['archive_date'];
    $dates[] = [
        'date'     => $d,
        'label'    => date('M j, Y', strtotime($d)),
        'minutes'  => (int)$row['minutes'],
        'energy_wh'=> (float)$row['energy_wh'],
    ];
}

echo json_encode(['success' => true, 'dates' => $dates]);
$conn->close();
