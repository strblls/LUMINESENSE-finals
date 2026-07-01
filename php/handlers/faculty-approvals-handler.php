<?php
/**
 * Faculty Management Handler
 * Handles: approve, reject, revoke, delete, ext_approve, ext_reject
 *
 * Can be included or called directly as POST handler
 */

// Start output buffering to prevent any accidental output
ob_start();

// If called directly, initialize required variables
$isStandalone = false;
if (!isset($conn) || !isset($admin_id) || !isset($phpRoot)) {
    $isStandalone = true;
    session_start();
    require_once __DIR__ . '/../db_connect.php';
    
    // Check admin is logged in BEFORE including admin-handlers.php
    // so admin-handlers.php sees $admin_id and doesn't redirect prematurely.
    if (!isset($_SESSION['admin_id']) || !$_SESSION['admin_logged_in']) {
        header('Location: ../../pages/admin-login.php');
        exit;
    }
    
    $admin_id = $_SESSION['admin_id'];
    require_once __DIR__ . '/admin-handlers.php';
    
    $phpRoot = __DIR__ . '/..';
    $message = '';
}

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
            $cleanup = faculty_delete_cleanup($conn, $faculty_id);
            $stmt = $conn->prepare('DELETE FROM faculty WHERE id = ?');
            $stmt->bind_param('i', $faculty_id);
            $stmt->execute();
            $stmt->close();
            $message = 'Faculty account removed successfully.';
            $log_name = ($cleanup['name'] ?? '') ?: $f_name;
            log_admin_action($conn, $_SESSION['admin_id'], 'faculty_rejected', $log_name, 'Record deleted');
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
                COALESCE(s.extended_until, s.end_time) AS end_time,
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

                // Notify ESP32 that schedule changed (only if schedule_dirty column exists)
                $checkCol = $conn->query("SHOW COLUMNS FROM classrooms LIKE 'schedule_dirty'");
                if ($checkCol && $checkCol->num_rows > 0) {
                    $conn->query("
                        UPDATE classrooms c
                        JOIN schedules s ON s.classroom_id = c.id
                        SET c.schedule_dirty = 1
                        WHERE s.id = $sched_id
                    ");
                }
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

    // ── Grace period setting ─────────────────────────────────────────────
    if ($action === 'set_grace_period') {
        $minutes = (int)($_POST['grace_minutes'] ?? 0);
        if ($minutes === 0 || in_array($minutes, [15, 30, 60], true)) {
            $_SESSION['ext_grace_minutes'] = $minutes;
            $conn->query("UPDATE system_settings SET setting_value = '$minutes' WHERE setting_key = 'grace_minutes'");
            $message = $minutes > 0 ? "Grace period set to {$minutes} minutes." : 'Grace period disabled.';
        }
    }

    // Redirect after POST to prevent re-submission (Post/Redirect/Get)
    $_SESSION['message'] = $message;
    header('Location: ../../pages/admin-home/admin-faculty-management.php');
    exit;
}

// If accessed directly via GET (not POST), redirect to management page
if ($isStandalone && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../pages/admin-home/admin-faculty-management.php');
    exit;
}

// ── Grace Period Auto-Approval ───────────────────────────────────────────
$grace_minutes = (int)($_SESSION['ext_grace_minutes'] ?? 0);
if ($grace_minutes <= 0) {
    $r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'grace_minutes'");
    if ($r && $row = $r->fetch_assoc()) $grace_minutes = (int)$row['setting_value'];
}
if (!$isStandalone && $grace_minutes > 0 && $conn->query("SHOW TABLES LIKE 'extension_requests'")->num_rows > 0) {
    $now_time = date('H:i:s');
    $today = date('l');

    $stmt = $conn->prepare("
        SELECT er.id, er.extend_mins, er.schedule_id,
               COALESCE(s.extended_until, s.end_time) AS end_time,
               s.classroom_id, c.room_name,
               CONCAT(f.first_name, ' ', f.last_name) AS faculty_name
        FROM extension_requests er
        JOIN schedules   s ON s.id = er.schedule_id
        JOIN classrooms  c ON c.id = s.classroom_id
        JOIN faculty     f ON f.id = er.faculty_id
        WHERE er.status = 'pending'
          AND s.day_of_week = ?
          AND s.start_time <= ?
          AND COALESCE(s.extended_until, s.end_time) >= ?
          AND TIME_TO_SEC(TIMEDIFF(COALESCE(s.extended_until, s.end_time), ?)) / 60 <= ?
    ");
    $stmt->bind_param('ssssi', $today, $now_time, $now_time, $now_time, $grace_minutes);
    $stmt->execute();
    $result = $stmt->get_result();

    $reviewer_id = !empty($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;

    while ($row = $result->fetch_assoc()) {
        $new_end = date('H:i:s', strtotime($row['end_time']) + ($row['extend_mins'] * 60));

        if ($reviewer_id) {
            $upd = $conn->prepare("UPDATE extension_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
            $upd->bind_param('ii', $reviewer_id, $row['id']);
        } else {
            $upd = $conn->prepare("UPDATE extension_requests SET status = 'approved', reviewed_at = NOW() WHERE id = ?");
            $upd->bind_param('i', $row['id']);
        }
        $upd->execute();
        $upd->close();

        $upd = $conn->prepare('UPDATE schedules SET extended_until = ? WHERE id = ?');
        $upd->bind_param('si', $new_end, $row['schedule_id']);
        $upd->execute();
        $upd->close();

        $checkCol = $conn->query("SHOW COLUMNS FROM classrooms LIKE 'schedule_dirty'");
        if ($checkCol && $checkCol->num_rows > 0) {
            $conn->query("
                UPDATE classrooms c
                JOIN schedules s ON s.classroom_id = c.id
                SET c.schedule_dirty = 1
                WHERE s.id = {$row['schedule_id']}
            ");
        }

        if ($reviewer_id) {
            log_admin_action(
                $conn,
                $reviewer_id,
                'extension_approved',
                $row['faculty_name'] . ' (' . $row['room_name'] . ')',
                $row['extend_mins'] . ' min extension (auto-approved via grace period)'
            );
        }
    }
    $stmt->close();
}

// ── Data fetching (only for when included, not standalone) ─────────────────
$total_faculty = 0;
$pending_count = 0;
$ext_pending = 0;
$faculty_list = [];
$extensions = [];
$all_faculty_map = [];

if (!$isStandalone) {
    $total_faculty = $conn->query("SELECT COUNT(*) AS c FROM faculty")->fetch_assoc()['c'] ?? 0;
    $pending_count = $conn->query("SELECT COUNT(*) AS c FROM faculty WHERE is_verified = 1 AND approved_by IS NULL")->fetch_assoc()['c'] ?? 0;

    if ($conn->query("SHOW TABLES LIKE 'extension_requests'")->num_rows > 0) {
        $ext_pending = $conn->query("SELECT COUNT(*) AS c FROM extension_requests WHERE status = 'pending'")->fetch_assoc()['c'] ?? 0;
    }

    $res = $conn->query("
        SELECT id, first_name, last_name, email, department_id, is_verified, approved_by, approved_at,
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
                c.room_name,
                sub.name AS subject_name
            FROM extension_requests er
            JOIN faculty     f ON f.id = er.faculty_id
            JOIN schedules   s ON s.id = er.schedule_id
            JOIN classrooms  c ON c.id = s.classroom_id
            LEFT JOIN subjects sub ON sub.id = s.subject_id
            ORDER BY er.id DESC
        ");
        while ($row = $res2->fetch_assoc()) $extensions[] = $row;
    }

    // Build the lookup map for the view file
    foreach ($faculty_list as $f) {
        $all_faculty_map[$f['id']] = [
            'name' => $f['first_name'] . ' ' . $f['last_name'],
            'email' => $f['email']
        ];
    }
    $GLOBALS['all_faculty_map'] = $all_faculty_map;
}

// Clean output buffer to ensure no accidental output
ob_end_clean();
