<?php
/**
 * api/review-id.php
 *
 * Admin-only endpoint for viewing a quarantined ID image
 * (status: mismatched or unreadable) — works for BOTH faculty
 * and admin signups, since id_review_queue is generic
 * (account_type + account_id instead of a faculty-only FK).
 *
 * GET  ?queue_id=5             -> decrypts and streams the image
 * POST queue_id, decision      -> approve/reject the underlying
 *                                  account, marks queue row reviewed
 *
 * SELF-REVIEW BLOCK: if account_type = 'admin' and account_id
 * equals the logged-in admin's own id, this endpoint refuses —
 * an admin can never review or decide on their own quarantined ID.
 * A different existing approved admin must handle it.
 *
 * DOUBLE-REVIEW BLOCK: if a queue row has already been reviewed
 * by someone else, further decisions are refused. Prevents a race
 * where two admins act on the same row at nearly the same time.
 *
 * Every successful image view is written to id_review_access_log.
 * The decrypted image is NEVER written to disk — streamed straight
 * from memory to the browser response.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../php/db_connect.php';
require_once __DIR__ . '/../php/session_guard.php';
require_once __DIR__ . '/../php/id-quarantine.php';

check_admin(); // any verified, approved admin — flat role, no tiers

$admin_id = $_SESSION['admin_id'];

// ── VIEW MODE ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['queue_id'])) {
    $queue_id = (int) $_GET['queue_id'];

    $stmt = $conn->prepare('
        SELECT encrypted_blob, expires_at, account_type, account_id
        FROM id_review_queue
        WHERE id = ?
    ');
    $stmt->bind_param('i', $queue_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Not found or already purged.']);
        exit;
    }

    // Self-review block — an admin can never view their own quarantined ID.
    if ($row['account_type'] === 'admin' && (int) $row['account_id'] === (int) $admin_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You cannot review your own ID. Ask another administrator.']);
        exit;
    }

    if (strtotime($row['expires_at']) < time()) {
        http_response_code(410);
        echo json_encode(['success' => false, 'message' => 'This item has expired and is pending cleanup.']);
        exit;
    }

    try {
        $imageBytes = IdQuarantine::decrypt($row['encrypted_blob']);
    } catch (\Throwable $e) {
        error_log('[review-id] Decrypt failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not decrypt image.']);
        exit;
    }

    // Log the access — every single view, no exceptions.
    $logStmt = $conn->prepare('INSERT INTO id_review_access_log (queue_id, viewed_by, ip_address) VALUES (?, ?, ?)');
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $logStmt->bind_param('iis', $queue_id, $admin_id, $ip);
    $logStmt->execute();
    $logStmt->close();

    // Stream the image directly — never saved to disk on this side either.
    header('Content-Type: image/jpeg');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Content-Length: ' . strlen($imageBytes));
    echo $imageBytes;
    exit;
}

// ── REVIEW DECISION MODE ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $queue_id = (int) ($_POST['queue_id'] ?? 0);
    $decision = $_POST['decision'] ?? ''; // 'approve' | 'reject'

    if (!$queue_id || !in_array($decision, ['approve', 'reject'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    $stmt = $conn->prepare('SELECT account_type, account_id, reviewed FROM id_review_queue WHERE id = ?');
    $stmt->bind_param('i', $queue_id);  
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Not found.']);
        exit;
    }

    // Double-review block — someone already made a call on this one.
    if (!empty($row['reviewed'])) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This item has already been reviewed.']);
        exit;
    }

    // Self-review block applies to decisions too, not just viewing.
    if ($row['account_type'] === 'admin' && (int) $row['account_id'] === (int) $admin_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You cannot decide on your own ID. Ask another administrator.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // Mark the queue row reviewed — encrypted_blob can be cleared now,
        // no need to wait for the 24h purge once a human has decided.
        $upd = $conn->prepare('UPDATE id_review_queue SET reviewed = 1, reviewed_by = ?, reviewed_at = NOW(), encrypted_blob = NULL WHERE id = ?');
        $upd->bind_param('ii', $admin_id, $queue_id);
        $upd->execute();
        $upd->close();

        // Route the update to the right table based on account_type —
        // this is what makes it generic instead of faculty-only.
        $targetTable = $row['account_type'] === 'admin' ? 'admins' : 'faculty';

        if ($decision === 'approve') {
            // Manual override: a different admin confirms identity
            // despite the OCR mismatch/unreadable result.
            $upd2 = $conn->prepare("UPDATE {$targetTable} SET is_verified = 1, approved_by = ? WHERE id = ?");
            $upd2->bind_param('ii', $admin_id, $row['account_id']);
        } else {
            $upd2 = $conn->prepare("UPDATE {$targetTable} SET is_verified = 0 WHERE id = ?");
            $upd2->bind_param('i', $row['account_id']);
        }
        $upd2->execute();
        $upd2->close();

        $conn->commit();
        echo json_encode(['success' => true, 'decision' => $decision, 'account_type' => $row['account_type']]);
    } catch (\Throwable $e) {
        $conn->rollback();
        error_log('[review-id] Decision failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not save decision.']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);