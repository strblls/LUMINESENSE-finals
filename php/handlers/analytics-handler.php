<?php
require_once __DIR__ . '/admin-handlers.php';

// ── Summary counts (kept for sidebar/topbar badges) ───────────────────────
$total_rooms = $conn->query("SELECT COUNT(*) AS c FROM classrooms")->fetch_assoc()['c'];

$pending = $conn->query("
    SELECT COUNT(*) AS c FROM faculty
    WHERE is_verified = 1 AND approved_by IS NULL
")->fetch_assoc()['c'];

$ext_pending = 0;
if ($conn->query("SHOW TABLES LIKE 'extension_requests'")->num_rows > 0) {
    $ext_pending = $conn->query(
        "SELECT COUNT(*) AS c FROM extension_requests WHERE status='pending'"
    )->fetch_assoc()['c'];
}

$db_ok       = ($conn && !$conn->connect_error);
$lights_data = $conn->query(
    "SELECT COUNT(*) AS c FROM lighting_logs WHERE DATE(event_time)=CURDATE()"
)->fetch_assoc()['c'];

// ── Rooms list (passed to JS for dropdowns) ────────────────────────────────
$rooms = [];
$r = $conn->query("SELECT id, room_name FROM classrooms ORDER BY room_name");
while ($row = $r->fetch_assoc()) {
    $rooms[] = $row;
}

// ── Passed to JS ───────────────────────────────────────────────────────────
// roomDataFromPHP is now just the rooms list — actual chart data is
// fetched live from api/analytics.php by admin-analytics.js
$roomDataFromPHP = $rooms;