<?php
/**
 * scripts/purge-expired-quarantine.php
 *
 * Run this on a schedule (cron / Hostinger cron job / Task Scheduler
 * locally) — every hour is plenty for a 24h expiry window.
 *
 * Deletes any id_review_queue row past its expires_at, whether or
 * not a Head Teacher reviewed it. Reviewed rows already have
 * encrypted_blob cleared by review-id.php, so this is mainly
 * cleaning up rows nobody got to in time.
 *
 * Suggested cron line (Hostinger / Linux):
 *   0 * * * * php /home/USERNAME/luminesense/scripts/purge-expired-quarantine.php
 */

require_once __DIR__ . '/../php/db_connect.php';

$result = $conn->query('SELECT id, faculty_id FROM id_review_queue WHERE expires_at < NOW()');

$purged = 0;
while ($row = $result->fetch_assoc()) {
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