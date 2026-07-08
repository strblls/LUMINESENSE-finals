<?php
/**
 * script/purge-expired-quarantine.php
 *
 * Run this on a schedule (cron / Hostinger cron job / Task Scheduler
 * locally) — every hour is plenty for a 24h expiry window.
 *
 * Deletes any id_review_queue row past its expires_at, whether or
 * not a Head Teacher reviewed it. Reviewed rows already have
 * encrypted_blob cleared by review-id.php, so this is mainly
 * cleaning up rows nobody got to in time.
 *
 * Also cleans up orphaned admin ID image files from uploads/admin_ids/
 * for accounts that were never approved/rejected within the 24h window.
 *
 * Suggested cron line (Hostinger / Linux):
 *   0 * * * * php /home/USERNAME/luminesense/script/purge-expired-quarantine.php
 */

require_once __DIR__ . '/../php/db_connect.php';

$result = $conn->query('SELECT id, account_type, account_id FROM id_review_queue WHERE expires_at < NOW()');

$purged = 0;
while ($row = $result->fetch_assoc()) {
    // Delete admin ID image file from disk if it exists
    if ($row['account_type'] === 'admin') {
        // Search for any image file matching the admin ID
        $pattern = __DIR__ . '/../uploads/admin_ids/' . (int)$row['account_id'] . '.*';
        foreach (glob($pattern) as $img_file) {
            unlink($img_file);
        }
    }

    // Clear the blob explicitly before deleting the row (defense in depth —
    // in case some future change soft-deletes instead of hard-deletes).
    $upd = $conn->prepare('UPDATE id_review_queue SET encrypted_blob = NULL WHERE id = ?');
    $upd->bind_param('i', $row['id']);
    $upd->execute();
    $upd->close();

    $del = $conn->prepare('DELETE FROM id_review_queue WHERE id = ?');
    $del->bind_param('i', $row['id']);
    $del->execute();
    $del->close();

    $purged++;
}

echo "[" . date('Y-m-d H:i:s') . "] Purged {$purged} expired quarantine row(s).\n";