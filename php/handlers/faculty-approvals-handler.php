<?php
require_once __DIR__ . '/admin-handlers.php';
/**
 * Faculty Management Handler
 * Handles: approve, reject, revoke, delete, ext_approve, ext_reject
 *
 * Requires: $conn, $admin_id, $phpRoot to be defined before including this file
 *
 * @var mysqli $conn
 * @var int    $admin_id
 * @var string $phpRoot
 */

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action     = $_POST['action'];
    $faculty_id = (int)($_POST['faculty_id'] ?? 0);

    // ── Faculty actions (approve / revoke / delete) ───────────────────────
    if ($faculty_id > 0) {

        // Fetch faculty name ONCE so all branches below have it in scope
        $f_name  = 'Faculty Member';
        $f_email = '';
        $stmt = $conn->prepare('SELECT email, CONCAT(first_name, " ", last_name) FROM faculty WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $faculty_id);
            $stmt->execute();
            $stmt->bind_result($f_email, $f_name);
            $stmt->fetch();
            $stmt->close();
        }

        if ($action === 'approve') {
            // Generate Faculty ID based on table id e.g. F-001-2025
            $generated_faculty_id = 'F-' . str_pad($faculty_id, 3, '0', STR_PAD_LEFT) . '-' . date('Y');

            $stmt = $conn->prepare('UPDATE faculty SET approved_by = ?, approved_at = NOW(), faculty_id = ? WHERE id = ?');
            $stmt->bind_param('isi', $admin_id, $generated_faculty_id, $faculty_id);
            $stmt->execute();
            $stmt->close();

            // Send approval email if mailer exists
            if (!empty($f_email) && file_exists($phpRoot . '/mailer.php')) {
                require_once $phpRoot . '/mailer.php';
                sendApprovalEmail($f_email, $f_name);
            }

            $message = 'Faculty member approved successfully.';
            log_admin_action($conn, $_SESSION['admin_id'], 'faculty_approved', $f_name, 'Faculty ID: ' . $generated_faculty_id);

        } elseif ($action === 'reject' || $action === 'revoke') {
            $stmt = $conn->prepare('UPDATE faculty SET approved_by = NULL, approved_at = NULL WHERE id = ?');
            $stmt->bind_param('i', $faculty_id);
            $stmt->execute();
            $stmt->close();

            $message = 'Faculty approval revoked successfully.';
            log_admin_action($conn, $_SESSION['admin_id'], 'faculty_rejected', $f_name, 'Access revoked');

        } elseif ($action === 'delete') {
            $stmt = $conn->prepare('DELETE FROM faculty WHERE id = ?');
            $stmt->bind_param('i', $faculty_id);
            $stmt->execute();
            $stmt->close();

            $message = 'Faculty account removed successfully.';
            log_admin_action($conn, $_SESSION['admin_id'], 'faculty_rejected', $f_name, 'Record deleted');
        }
    }

    // ── Extension actions (ext_approve / ext_reject) ──────────────────────
    $ext_id = (int)($_POST['extension_id'] ?? 0);
    if ($ext_id > 0) {

        // Fetch extension details (faculty name + room name + schedule info)
        // in one query so all branches below have everything in scope
        $sched_id    = 0;
        $extend_mins = 0;
        $end_time    = '';
        $f_name      = 'Faculty Member';
        $room_name   = 'Unknown Room';

        $stmt = $conn->prepare('
            SELECT
                er.schedule_id,
                er.extend_mins,
                s.end_time,
                CONCAT(f.first_name, " ", f.last_name) AS faculty_name,
                c.room_name
            FROM extension_requests er
            JOIN schedules   s ON s.id = er.schedule_id
            JOIN faculty     f ON f.id = er.faculty_id
            JOIN classrooms  c ON c.id = s.classroom_id
            WHERE er.id = ?
        ');
        if ($stmt) {
            $stmt->bind_param('i', $ext_id);
            $stmt->execute();
            $stmt->bind_result($sched_id, $extend_mins, $end_time, $f_name, $room_name);
            $stmt->fetch();
            $stmt->close();
        }

        if ($action === 'ext_approve') {
            // Mark request approved
            $stmt = $conn->prepare("UPDATE extension_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $admin_id, $ext_id);
            $stmt->execute();
            $stmt->close();

            // Push schedule end time forward
            if ($sched_id > 0 && !empty($end_time)) {
                $new_end = date('H:i:s', strtotime($end_time) + ($extend_mins * 60));
                $stmt = $conn->prepare('UPDATE schedules SET extended_until = ? WHERE id = ?');
                $stmt->bind_param('si', $new_end, $sched_id);
                $stmt->execute();
                $stmt->close();

                // Notify ESP32 that schedule changed
                $conn->query("
                    UPDATE classrooms c
                    JOIN schedules s ON s.classroom_id = c.id
                    SET c.schedule_dirty = 1
                    WHERE s.id = $sched_id
                ");
            }

            $message = 'Extension request approved.';
            log_admin_action(
                $conn,
                $_SESSION['admin_id'],
                'extension_approved',
                $f_name . ' (' . $room_name . ')',
                $extend_mins . ' min extension'
            );

        } elseif ($action === 'ext_reject') {
            $stmt = $conn->prepare("UPDATE extension_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $admin_id, $ext_id);
            $stmt->execute();
            $stmt->close();

            $message = 'Extension request rejected.';
            log_admin_action(
                $conn,
                $_SESSION['admin_id'],
                'extension_rejected',
                $f_name . ' (' . $room_name . ')'
            );
        }
    }
}

// ── Data fetching ─────────────────────────────────────────────────────────

$total_faculty = $conn->query("SELECT COUNT(*) AS c FROM faculty")->fetch_assoc()['c'] ?? 0;
$pending_count = $conn->query("SELECT COUNT(*) AS c FROM faculty WHERE is_verified = 1 AND approved_by IS NULL")->fetch_assoc()['c'] ?? 0;

$ext_pending = 0;
if ($conn->query("SHOW TABLES LIKE 'extension_requests'")->num_rows > 0) {
    $ext_pending = $conn->query("SELECT COUNT(*) AS c FROM extension_requests WHERE status = 'pending'")->fetch_assoc()['c'] ?? 0;
}

$faculty_list = [];
$res = $conn->query("
    SELECT id, first_name, last_name, email, is_verified, approved_by, approved_at,
           faculty_id, id_image, ai_match_status, ai_extracted_name, ai_confidence_note
    FROM faculty
    ORDER BY last_name ASC
");
while ($row = $res->fetch_assoc()) {
    $row['status_label'] = match(true) {
        $row['is_verified'] == 1 && $row['approved_by'] !== null => 'approved',
        $row['is_verified'] == 1 && $row['approved_by'] === null => 'pending',
        default => 'unverified'
    };
    $faculty_list[] = $row;
}

$extensions = [];
if ($conn->query("SHOW TABLES LIKE 'extension_requests'")->num_rows > 0) {
    $res2 = $conn->query("
        SELECT
            er.id,
            er.extend_mins,
            er.status,
            er.requested_at,
            CONCAT(f.first_name, ' ', f.last_name) AS faculty_name,
            s.day_of_week,
            s.start_time,
            s.end_time,
            c.room_name
        FROM extension_requests er
        JOIN faculty     f ON f.id = er.faculty_id
        JOIN schedules   s ON s.id = er.schedule_id
        JOIN classrooms  c ON c.id = s.classroom_id
        ORDER BY er.id DESC
    ");
    while ($row = $res2->fetch_assoc()) $extensions[] = $row;
}