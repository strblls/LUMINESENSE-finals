<?php
// src/Cron/backfill-session-faculty.php
// One-time backfill: stamps faculty_id onto existing power_sessions rows that
// are still NULL, using Option A attribution (first schedule whose window
// covers the session). Idempotent — only touches NULL rows.
//
// Usage:
//   php backfill-session-faculty.php            # fill all NULL faculty_id rows
//   php backfill-session-faculty.php --dry-run  # report counts without writing

require_once __DIR__ . '/../Config/db_connect.php';
date_default_timezone_set('Asia/Manila');

$dryRun = in_array('--dry-run', $argv ?? [], true);
if ($dryRun) echo "DRY RUN - no writes\n";

$countRes = $conn->query("
    SELECT COUNT(*) AS cnt FROM power_sessions
    WHERE faculty_id IS NULL AND end_time IS NOT NULL
");
$total = $countRes ? (int)$countRes->fetch_assoc()['cnt'] : 0;
echo "Sessions missing faculty_id: {$total}\n";

if ($total === 0) {
    echo "Nothing to backfill.\n";
    $conn->close(); exit(0);
}

$updated = 0;
$rows = $conn->query("
    SELECT id, classroom_id, session_date, start_time, end_time
    FROM power_sessions
    WHERE faculty_id IS NULL AND end_time IS NOT NULL
    ORDER BY id
");
if (!$rows) {
    echo "Error: " . $conn->error . "\n";
    $conn->close(); exit(1);
}

$af = $conn->prepare("
    SELECT s.faculty_id
    FROM schedules s
    WHERE s.classroom_id = ?
      AND s.day_of_week = DAYNAME(?)
      AND s.faculty_id IS NOT NULL
      AND s.start_time <= TIME(?)
      AND GREATEST(s.end_time, COALESCE(s.extended_until, s.end_time)) >= TIME(?)
    ORDER BY s.start_time ASC
    LIMIT 1
");
$upd = $conn->prepare("UPDATE power_sessions SET faculty_id = ? WHERE id = ?");

while ($row = $rows->fetch_assoc()) {
    $startTime = $row['start_time']; // datetime string from session
    $af->bind_param('isss', $row['classroom_id'], $row['session_date'], $startTime, $startTime);
    $af->execute();
    $fac = $af->get_result()->fetch_assoc();
    if (!$fac || !$fac['faculty_id']) continue;

    if ($dryRun) {
        echo "  #{$row['id']} -> faculty {$fac['faculty_id']}\n";
    } else {
        $upd->bind_param('ii', $fac['faculty_id'], $row['id']);
        $upd->execute();
    }
    $updated++;
}
$af->close();
$upd->close();

echo ($dryRun ? "Would update" : "Updated") . ": {$updated} session(s).\n";
$conn->close();
