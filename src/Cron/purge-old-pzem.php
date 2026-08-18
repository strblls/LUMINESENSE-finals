<?php
/**
 * purge-old-pzem.php
 *
 * Removes raw pzem_readings older than 3 days.
 * Designed to run daily via Hostinger Cron Jobs.
 * Usage: php src/Cron/purge-old-pzem.php
 *
 * PZEM data is archived to pzem_archive (by per-date aggregate) before deletion.
 * This script handles raw data cleanup only; archive aggregation is done by pzem-cron.php.
 */

require_once __DIR__ . '/../Config/db_connect.php';

$days = isset($argv[1]) ? (int)$argv[1] : 3;
if ($days < 1) $days = 3;

$threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));

echo "=== PZEM Purge ===\n";
echo "Deleting readings before: {$threshold}\n";

$countResult = $conn->query("SELECT COUNT(*) as cnt FROM pzem_readings WHERE recorded_at < '{$threshold}'");
$rowCount = ($countResult && $countResult->num_rows > 0) ? (int)$countResult->fetch_assoc()['cnt'] : 0;
echo "Rows to delete: {$rowCount}\n";

if ($rowCount === 0) {
    echo "Nothing to purge.\n";
    exit(0);
}

$deleted = 0;
$chunkSize = 5000;
while ($deleted < $rowCount) {
    $result = $conn->query("DELETE FROM pzem_readings WHERE recorded_at < '{$threshold}' LIMIT {$chunkSize}");
    if (!$result) {
        echo "Error: " . $conn->error . "\n";
        break;
    }
    $deleted += $conn->affected_rows;
    echo "  Deleted {$deleted}/{$rowCount}\n";
    if ($conn->affected_rows < $chunkSize) break;
    usleep(100000);
}

echo "Purge complete. Total deleted: {$deleted}\n";
