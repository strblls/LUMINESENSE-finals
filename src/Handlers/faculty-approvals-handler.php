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
if (!isset($conn) || !isset($admin_id)) {
    $isStandalone = true;
    session_start();
    require_once __DIR__ . '/../Config/db_connect.php';
    
    // Check admin is logged in BEFORE including admin-handlers.php
    // so admin-handlers.php sees $admin_id and doesn't redirect prematurely.
    if (!isset($_SESSION['admin_id']) || !$_SESSION['admin_logged_in']) {
        header('Location: ../../pages/admin-login.php');
        exit;
    }
    
    $admin_id = $_SESSION['admin_id'];
    require_once __DIR__ . '/admin-handlers.php';
    
    $message = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action     = $_POST['action'];
    $faculty_id = (int)($_POST['faculty_id'] ?? 0);

    // - Admin actions (admin_approve / admin_reject) -----------
    $admin_approve_id = (int)($_POST['admin_approve_id'] ?? 0);
    if ($action === 'admin_approve' && $admin_approve_id > 0) {
        $stmt = $conn->prepare('SELECT CONCAT(first_name, " ", last_name), email FROM admins WHERE id = ?');
        $stmt->bind_param('i', $admin_approve_id);
        $stmt->execute();
        $stmt->bind_result($a_name, $a_email);
        $stmt->fetch();
        $stmt->close();

        $stmt = $conn->prepare('UPDATE admins SET approved_by = ?, approved_at = NOW() WHERE id = ?');
        $stmt->bind_param('ii', $admin_id, $admin_approve_id);
        $stmt->execute();
        $stmt->close();

        $vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
        if (!empty($a_email) && file_exists(__DIR__ . '/../Services/mailer.php') && file_exists($vendorAutoload)) {
            require_once __DIR__ . '/../Services/mailer.php';
            sendApprovalEmail($a_email, $a_name);
        }

        $message = 'Admin account approved successfully.';
        log_admin_action($conn, $admin_id, 'admin_approved', $a_name, 'Admin ID: ' . $admin_approve_id);

    } elseif ($action === 'admin_reject' && $admin_approve_id > 0) {
        $stmt = $conn->prepare('SELECT CONCAT(first_name, " ", last_name) FROM admins WHERE id = ?');
        $stmt->bind_param('i', $admin_approve_id);
        $stmt->execute();
        $stmt->bind_result($a_name);
        $stmt->fetch();
        $stmt->close();

        $conn->query("DELETE FROM admin_login_logs WHERE admin_id = $admin_approve_id");
        $stmt = $conn->prepare('DELETE FROM admins WHERE id = ?');
        $stmt->bind_param('i', $admin_approve_id);
        $stmt->execute();
        $stmt->close();

        $message = 'Admin account rejected and removed.';
        log_admin_action($conn, $admin_id, 'admin_rejected', $a_name, 'Admin rejected on review');
    }

    // - Faculty actions (approve / revoke / delete) ------------
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
            $vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
            if (!empty($f_email) && file_exists(__DIR__ . '/../Services/mailer.php') && file_exists($vendorAutoload)) {
                require_once __DIR__ . '/../Services/mailer.php';
                sendApprovalEmail($f_email, $f_name);
            }

            $message = 'Faculty member approved successfully.';
            log_admin_action($conn, $_SESSION['admin_id'], 'faculty_approved', $f_name, 'Faculty ID: ' . $generated_faculty_id);

        } elseif ($action === 'reject' || $action === 'revoke') {
            $stmt = $conn->prepare('UPDATE faculty SET approved_by = NULL, approved_at = NULL WHERE id = ?');
            $stmt->bind_param('i', $faculty_id);
            $stmt->execute();
            $stmt->close();

            // Send rejection email if mailer exists
            $vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
            if (!empty($f_email) && file_exists(__DIR__ . '/../Services/mailer.php') && file_exists($vendorAutoload)) {
                require_once __DIR__ . '/../Services/mailer.php';
                sendRejectionEmail($f_email, $f_name);
            }

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

        } elseif ($action === 'reactivate') {
            $stmt = $conn->prepare('UPDATE faculty SET is_archived = 0 WHERE id = ?');
            $stmt->bind_param('i', $faculty_id);
            $stmt->execute();
            $stmt->close();

            // Send reactivation email
            $vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
            if (!empty($f_email) && file_exists(__DIR__ . '/../Services/mailer.php') && file_exists($vendorAutoload)) {
                require_once __DIR__ . '/../Services/mailer.php';
                sendReactivationEmail($f_email, $f_name);
            }

            $message = 'Faculty account reactivated successfully.';
            log_admin_action($conn, $_SESSION['admin_id'], 'reactivated', $f_name, 'Account reactivated from archive');

        } elseif ($action === 'reactivate_all') {
            $stmt = $conn->prepare('UPDATE faculty SET is_archived = 0 WHERE is_archived = 1');
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            $message = "$affected faculty account(s) reactivated.";
            log_admin_action($conn, $_SESSION['admin_id'], 'reactivated', 'All Archived Faculty', "$affected accounts reactivated");
        }
    }

    // - Extension actions (ext_approve / ext_reject) -----------
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

    // - Grace period setting -----------------------
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

// - Grace Period Auto-Approval ----------------------
$grace_minutes = (int)($_SESSION['ext_grace_minutes'] ?? 0);
if ($grace_minutes <= 0) {
    $r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'grace_minutes'");
    if ($r && $row = $r->fetch_assoc()) $grace_minutes = (int)$row['setting_value'];
}
// Sync session with DB so the admin dropdown displays correctly after re-login
$_SESSION['ext_grace_minutes'] = $grace_minutes;
if (!$isStandalone && $grace_minutes > 0 && $conn->query("SHOW TABLES LIKE 'extension_requests'")->num_rows > 0) {
    $today = date('l');

    $stmt = $conn->prepare("
        SELECT er.id, er.extend_mins, er.schedule_id,
               COALESCE(s.extended_until, s.end_time) AS current_end,
               s.classroom_id, c.room_name,
               CONCAT(f.first_name, ' ', f.last_name) AS faculty_name
        FROM extension_requests er
        JOIN schedules   s ON s.id = er.schedule_id
        JOIN classrooms  c ON c.id = s.classroom_id
        JOIN faculty     f ON f.id = er.faculty_id
        WHERE er.status = 'pending'
          AND s.day_of_week = ?
    ");
    if ($stmt) {
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $stmt->bind_result($ext_id, $ext_mins, $sched_id, $current_end, $classroom_id, $room_name, $faculty_name);

    $reviewer_id = !empty($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;

    // Fetch all pending extensions first to avoid "Commands out of sync"
    $pending_extensions = [];
    while ($stmt->fetch()) {
        $pending_extensions[] = [
            'ext_id'       => $ext_id,
            'ext_mins'     => $ext_mins,
            'sched_id'     => $sched_id,
            'current_end'  => $current_end,
            'classroom_id' => $classroom_id,
            'room_name'    => $room_name,
            'faculty_name' => $faculty_name,
        ];
    }
    $stmt->close();

    foreach ($pending_extensions as $ext) {
        $new_end = date('H:i:s', strtotime($ext['current_end']) + ($ext['ext_mins'] * 60));

        if ($reviewer_id) {
            $upd = $conn->prepare("UPDATE extension_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
            if ($upd) $upd->bind_param('ii', $reviewer_id, $ext['ext_id']);
        } else {
            $upd = $conn->prepare("UPDATE extension_requests SET status = 'approved', reviewed_at = NOW() WHERE id = ?");
            if ($upd) $upd->bind_param('i', $ext['ext_id']);
        }
        if ($upd) { $upd->execute(); $upd->close(); } else continue;

        $upd = $conn->prepare('UPDATE schedules SET extended_until = ? WHERE id = ?');
        if (!$upd) continue;
        $upd->bind_param('si', $new_end, $ext['sched_id']);
        $upd->execute();
        $upd->close();

        $checkCol = $conn->query("SHOW COLUMNS FROM classrooms LIKE 'schedule_dirty'");
        if ($checkCol && $checkCol->num_rows > 0) {
            $conn->query("UPDATE classrooms SET schedule_dirty = 1 WHERE id = {$ext['classroom_id']}");
        }

        if ($reviewer_id) {
            log_admin_action(
                $conn,
                $reviewer_id,
                'extension_approved',
                $ext['faculty_name'] . ' (' . $ext['room_name'] . ')',
                $ext['ext_mins'] . ' min extension (auto-approved via grace period)'
            );
        }
    }
    } // end if ($stmt)
}

// - Data fetching (only for when included, not standalone) ---------
$total_faculty = 0;
$total_admins = 0;
$total_accounts = 0;
$pending_count = 0;
$admin_pending_count = 0;
$ext_pending = 0;
$faculty_list = [];
$admin_list = [];
$pending_admins = [];
$extensions = [];
$all_faculty_map = [];

if (!$isStandalone) {
    $total_faculty = $conn->query("SELECT COUNT(*) AS c FROM faculty")->fetch_assoc()['c'] ?? 0;
    $total_admins = $conn->query("SELECT COUNT(*) AS c FROM admins")->fetch_assoc()['c'] ?? 0;
    $total_accounts = $total_faculty + $total_admins;
    $pending_count = $conn->query("SELECT COUNT(*) AS c FROM faculty WHERE is_verified = 1 AND approved_by IS NULL")->fetch_assoc()['c'] ?? 0;

    $admin_pending_count = 0;
    $admin_pending_q = $conn->query("SELECT COUNT(*) AS c FROM admins WHERE is_verified = 1 AND approved_by IS NULL");
    if ($admin_pending_q) $admin_pending_count = (int)$admin_pending_q->fetch_assoc()['c'];

    if ($conn->query("SHOW TABLES LIKE 'extension_requests'")->num_rows > 0) {
        $ext_pending = $conn->query("SELECT COUNT(*) AS c FROM extension_requests WHERE status = 'pending'")->fetch_assoc()['c'] ?? 0;
    }

    $res = $conn->query("
        SELECT id, first_name, last_name, email, department_id, is_verified, approved_by, approved_at, created_at,
               faculty_id, id_image, ai_match_status, ai_extracted_name, ai_confidence_note
        FROM faculty
        ORDER BY last_name ASC
    ");
    if ($res) while ($row = $res->fetch_assoc()) {
        $row['status_label'] = match(true) {
            $row['is_verified'] == 1 && $row['approved_by'] !== null => 'approved',
            $row['is_verified'] == 1 && $row['approved_by'] === null => 'pending',
            default => 'unverified'
        };
        $faculty_list[] = $row;
    }

    $res_admins = $conn->query("
        SELECT id, first_name, last_name, email, is_verified, approved_by, approved_at, created_at
        FROM admins
        ORDER BY last_name ASC
    ");
    if ($res_admins) while ($row = $res_admins->fetch_assoc()) {
        $row['status_label'] = match(true) {
            $row['approved_by'] !== null => 'approved',
            default => 'pending'
        };
        $admin_list[] = $row;
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
        if ($res2) while ($row = $res2->fetch_assoc()) $extensions[] = $row;
    }

    // - Pending admin registrations --------------------
    $res_admin = $conn->query("
        SELECT id, first_name, last_name, email, created_at
        FROM admins
        WHERE is_verified = 1 AND approved_by IS NULL
        ORDER BY created_at DESC
    ");
    if ($res_admin) while ($row = $res_admin->fetch_assoc()) {
        $pending_admins[] = $row;
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
