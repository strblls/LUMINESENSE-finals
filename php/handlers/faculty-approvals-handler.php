<?php
/**
 * Faculty Management Handler
 * Handles: approve, reject, revoke, delete, ext_approve, ext_reject
 * 
 * Requires: $conn, $admin_id, $phpRoot to be defined before including this file
 * 
 * @var mysqli $conn
 * @var int $admin_id
 * @var string $phpRoot
 * @var int $sched_id 
 * @var int $extend_mins 
 * @var string $end_time **/

$stmt = $conn->prepare('
    SELECT er.schedule_id, er.extend_mins, s.end_time
    FROM extension_requests er
    JOIN schedules s ON s.id = er.schedule_id
    WHERE er.id = ?
');
if ($stmt) {
    $stmt->bind_param('i', $ext_id);
    $stmt->execute();
    $stmt->bind_result($sched_id, $extend_mins, $end_time);
    $stmt->fetch();
    $stmt->close();

    $new_end = date('H:i:s', strtotime($end_time) + ($extend_mins * 60));
    $stmt = $conn->prepare('UPDATE schedules SET extended_until=? WHERE id=?');
    if ($stmt) {
        $stmt->bind_param('si', $new_end, $sched_id);
        $stmt->execute();
        $stmt->close();
    }
}
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action     = $_POST['action'];
    $faculty_id = (int)($_POST['faculty_id'] ?? 0);

    if ($faculty_id > 0) {
        if ($action === 'approve') {
            $stmt = $conn->prepare('UPDATE faculty SET approved_by=?, approved_at=NOW() WHERE id=?');
            $stmt->bind_param('ii', $admin_id, $faculty_id);
            $stmt->execute();
            $stmt->close();

            $f_email = '';
            $f_name  = 'Faculty Member';
            $stmt = $conn->prepare('SELECT email, first_name FROM faculty WHERE id=?');
            if ($stmt) {
                $stmt->bind_param('i', $faculty_id);
                $stmt->execute();
                $stmt->bind_result($fetched_email, $fetched_name);
                if ($stmt->fetch()) {
                    if (!empty($fetched_email)) $f_email = $fetched_email;
                    if (!empty($fetched_name))  $f_name  = $fetched_name;
                }
                $stmt->close();
            }

            if (!empty($f_email) && file_exists($phpRoot . '/mailer.php')) {
                require_once $phpRoot . '/mailer.php';
                sendApprovalEmail($f_email, $f_name);
            }

            $message = 'Faculty member approved successfully.';

        } elseif ($action === 'reject' || $action === 'revoke') {
            $stmt = $conn->prepare('UPDATE faculty SET approved_by=NULL, approved_at=NULL WHERE id=?');
            $stmt->bind_param('i', $faculty_id);
            $stmt->execute();
            $stmt->close();
            $message = 'Faculty approval revoked successfully.';

        } elseif ($action === 'delete') {
            $stmt = $conn->prepare('DELETE FROM faculty WHERE id=?');
            $stmt->bind_param('i', $faculty_id);
            $stmt->execute();
            $stmt->close();
            $message = 'Faculty account removed successfully.';
        }
    }

    // Extension actions
    $ext_id = (int)($_POST['extension_id'] ?? 0);
    if ($ext_id > 0) {
        if ($action === 'ext_approve') {
            $stmt = $conn->prepare("UPDATE extension_requests SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?");
            $stmt->bind_param('ii', $admin_id, $ext_id);
            $stmt->execute();
            $stmt->close();

            // Update schedule extended_until
            $stmt = $conn->prepare('
                SELECT er.schedule_id, er.extend_mins, s.end_time
                FROM extension_requests er
                JOIN schedules s ON s.id = er.schedule_id
                WHERE er.id = ?
            ');
            $stmt->bind_param('i', $ext_id);
            $stmt->execute();
            $stmt->bind_result($sched_id, $extend_mins, $end_time);
            $stmt->fetch();
            $stmt->close();

            $new_end = date('H:i:s', strtotime($end_time) + ($extend_mins * 60));
            $stmt = $conn->prepare('UPDATE schedules SET extended_until=? WHERE id=?');
            $stmt->bind_param('si', $new_end, $sched_id);
            $stmt->execute();
            $stmt->close();

            $message = 'Extension request approved.';

        } elseif ($action === 'ext_reject') {
            $stmt = $conn->prepare("UPDATE extension_requests SET status='rejected', reviewed_by=?, reviewed_at=NOW() WHERE id=?");
            $stmt->bind_param('ii', $admin_id, $ext_id);
            $stmt->execute();
            $stmt->close();
            $message = 'Extension request rejected.';
        }
    }
}

// ── Data fetching ──────────────────────────────────────────────────────────

$total_faculty = $conn->query("SELECT COUNT(*) AS c FROM faculty")->fetch_assoc()['c'] ?? 0;
$pending_count = $conn->query("SELECT COUNT(*) AS c FROM faculty WHERE is_verified = 1 AND approved_by IS NULL")->fetch_assoc()['c'] ?? 0;

$ext_pending = 0;
if ($conn->query("SHOW TABLES LIKE 'extension_requests'")->num_rows > 0) {
    $ext_pending = $conn->query("SELECT COUNT(*) AS c FROM extension_requests WHERE status='pending'")->fetch_assoc()['c'] ?? 0;
}

$faculty_list = [];
$res = $conn->query("
    SELECT id, first_name, last_name, email, is_verified, approved_by, approved_at
    FROM faculty ORDER BY last_name ASC
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
        SELECT er.id, er.extend_mins, er.status, er.requested_at,
               CONCAT(f.first_name,' ',f.last_name) AS faculty_name,
               s.day_of_week, s.start_time, s.end_time, c.room_name
        FROM extension_requests er
        JOIN faculty f  ON f.id = er.faculty_id
        JOIN schedules s ON s.id = er.schedule_id
        JOIN classrooms c ON c.id = s.classroom_id
        ORDER BY er.id DESC
    ");
    while ($row = $res2->fetch_assoc()) $extensions[] = $row;
}