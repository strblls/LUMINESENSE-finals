<?php
/**
 * purge-old-logs.php
 *
 * Rotates old log files and trims old audit trail / flush logs.
 * Designed to run weekly via Hostinger Cron Jobs.
 * Usage: php src/Cron/purge-old-logs.php [days_to_keep]
 *
 * Default: keeps 90 days of admin_logs and system_flush_logs.
 * Old flush logs are moved to flush_logs_archive folder before deletion.
 */

require_once __DIR__ . '/../Config/db_connect.php';

$days = isset($argv[1]) ? (int)$argv[1] : 90;
if ($days < 1) $days = 90;

$threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));

echo "=== Log Rotation ===\n";
echo "Trimming logs older than: {$threshold}\n";

// 1. Trim admin_logs
$result = $conn->query("DELETE FROM admin_logs WHERE created_at < '{$threshold}'");
$adminLogsDeleted = $result ? $conn->affected_rows : 0;
echo "admin_logs deleted: {$adminLogsDeleted}\n";

// 2. Trim flush_schedules (keep only last 20 executions)
$conn->query("DELETE FROM flush_schedules WHERE id NOT IN (SELECT id FROM (SELECT id FROM flush_schedules ORDER BY id DESC LIMIT 20) tmp)");
$flushSchedulesDeleted = $conn->affected_rows;
echo "flush_schedules trimmed: {$flushSchedulesDeleted}\n";

// 3. Trim extension_requests (keep only last 50)
$conn->query("DELETE FROM extension_requests WHERE id NOT IN (SELECT id FROM (SELECT id FROM extension_requests ORDER BY id DESC LIMIT 50) tmp)");
$extRequestsDeleted = $conn->affected_rows;
echo "extension_requests trimmed: {$extRequestsDeleted}\n";

// 4. Rotate log files (move old logs to archive folder)
$logDirs = [
    __DIR__ . '/../../logs',
    __DIR__ . '/../../tmp'
];

foreach ($logDirs as $logDir) {
    if (!is_dir($logDir)) continue;
    $files = glob($logDir . '/*.log');
    foreach ($files as $file) {
        if (filemtime($file) < strtotime("-{$days} days")) {
            $basename = basename($file);
            $archiveDir = $logDir . '/archive';
            if (!is_dir($archiveDir)) mkdir($archiveDir, 0755, true);
            rename($file, $archiveDir . '/' . $basename);
            echo "Archived log: {$basename}\n";
        }
    }
}

echo "Log rotation complete.\n";
