<?php
// src/Cron/backfill-archive.php
// One-time backfill: rolls existing pzem_readings rows into pzem_archive
// as per-minute averages BEFORE the 7-day raw purge starts dropping them.
//
// Usage:
//   php backfill-archive.php            # all days with raw readings
//   php backfill-archive.php 2026-08-01 # only a specific date
//   php backfill-archive.php --dry-run  # report counts without writing
//
// Idempotent: uses INSERT IGNORE so re-runs never duplicate minutes.

require_once __DIR__ . '/../Config/db_connect.php';
date_default_timezone_set('Asia/Manila');

$dryRun = in_array('--dry-run', $argv ?? [], true);
$targetDate = null;
foreach (($argv ?? []) as $a) {
    if ($a !== '--dry-run' && $a !== 'backfill-archive.php' && $a !== 'php') {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $a)) $targetDate = $a;
    }
}

if ($dryRun) echo "DRY RUN - no writes\n";

// Find every distinct date that still has raw readings.
if ($targetDate) {
    $dateRows = [[ 'd' => $targetDate ]];
} else {
    $r = $conn->query("SELECT DISTINCT DATE(recorded_at) AS d FROM pzem_readings ORDER BY d");
    $dateRows = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
}

$totalInserts = 0;
$totalDates   = 0;

foreach ($dateRows as $dr) {
    $date = $dr['d'];
    if (!$date) continue;

    $totalDates++;
    // Per-minute aggregation of the 8s raw readings.
    $sql = "
        SELECT
            pr.classroom_id,
            DATE(pr.recorded_at)                                        AS archive_date,
            TIME_FORMAT(SEC_TO_TIME(FLOOR(TIME_TO_SEC(pr.recorded_at)/60)*60), '%H:%i:%s') AS minute,
            ROUND(AVG(pr.voltage), 1)                                   AS avg_voltage,
            ROUND(AVG(pr.current), 3)                                   AS avg_current,
            ROUND(AVG(pr.power), 2)                                     AS avg_power,
            ROUND(SUM(pr.power) * (3/3600), 4)                          AS energy_wh,
            COUNT(*)                                                    AS reading_count
        FROM pzem_readings pr
        WHERE DATE(pr.recorded_at) = ?
        GROUP BY pr.classroom_id, archive_date, minute
        ORDER BY minute
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $rows = $stmt->get_result();
    $stmt->close();

    $dayCount = 0;
    while ($row = $rows->fetch_assoc()) {
        if ($dryRun) {
            $dayCount++;
            continue;
        }
        $ins = $conn->prepare("
            INSERT IGNORE INTO pzem_archive
                (classroom_id, archive_date, minute, avg_voltage, avg_current, avg_power, energy_wh, reading_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->bind_param(
            'issdddii',
            $row['classroom_id'],
            $row['archive_date'],
            $row['minute'],
            $row['avg_voltage'],
            $row['avg_current'],
            $row['avg_power'],
            $row['energy_wh'],
            $row['reading_count']
        );
        $ins->execute();
        if ($ins->affected_rows > 0) $totalInserts++;
        $ins->close();
    }
    echo "[" . date('Y-m-d H:i:s') . "] $date: " . ($dryRun ? "would insert" : "inserted") . " $dayCount minute rows\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Done. $totalDates date(s) processed, $totalInserts minute row(s) inserted.\n";
$conn->close();
