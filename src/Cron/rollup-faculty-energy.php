<?php
// src/Cron/rollup-faculty-energy.php
// Builds / refreshes the compact faculty_energy_daily rollup from
// power_sessions (attributed sessions only — NULL faculty_id is skipped and
// stays in room totals only). Idempotent upsert, safe to run daily.
//
// Usage:
//   php rollup-faculty-energy.php            # rebuild today + keep history
//   php rollup-faculty-energy.php --dry-run  # report counts without writing

require_once __DIR__ . '/../Config/db_connect.php';
date_default_timezone_set('Asia/Manila');

$dryRun = in_array('--dry-run', $argv ?? [], true);
if ($dryRun) echo "DRY RUN - no writes\n";

$sql = "
    SELECT
        s.faculty_id,
        DATE(s.start_time)                          AS day,
        ROUND(SUM(COALESCE(s.total_energy_wh, 0)), 3) AS energy_wh,
        SUM(s.duration_mins)                        AS minutes,
        COUNT(*)                                    AS sessions,
        ROUND(AVG(s.avg_voltage), 1)                AS avg_voltage,
        ROUND(AVG(s.avg_current), 3)                AS avg_current,
        ROUND(MAX(s.peak_power), 2)                 AS peak_power
    FROM power_sessions s
    WHERE s.faculty_id IS NOT NULL
      AND s.end_time IS NOT NULL
    GROUP BY s.faculty_id, DATE(s.start_time)
";

$res = $conn->query($sql);
if (!$res) {
    echo "Error: " . $conn->error . "\n";
    $conn->close(); exit(1);
}

$upsert = $conn->prepare("
    INSERT INTO faculty_energy_daily
        (faculty_id, day, energy_wh, minutes, sessions,
         avg_voltage, avg_current, peak_power)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        energy_wh   = VALUES(energy_wh),
        minutes     = VALUES(minutes),
        sessions    = VALUES(sessions),
        avg_voltage = VALUES(avg_voltage),
        avg_current = VALUES(avg_current),
        peak_power  = VALUES(peak_power)
");

$processed = 0;
while ($row = $res->fetch_assoc()) {
    if ($dryRun) {
        $processed++;
        continue;
    }
    $upsert->bind_param(
        'isdiiidd',
        $row['faculty_id'],
        $row['day'],
        $row['energy_wh'],
        $row['minutes'],
        $row['sessions'],
        $row['avg_voltage'],
        $row['avg_current'],
        $row['peak_power']
    );
    $upsert->execute();
    $processed++;
}
$upsert->close();

echo ($dryRun ? "Would upsert" : "Upserted") . ": {$processed} faculty-day row(s).\n";
$conn->close();
