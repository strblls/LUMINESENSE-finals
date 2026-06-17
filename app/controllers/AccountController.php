<?php
/**
 * app/Controllers/AccountController.php
 *
 * Manages faculty account approval for the LumineSense prototype.
 *
 * WHO CAN USE THIS:
 *   - Head Teacher (role = 'head_teacher') — approves/rejects teachers
 *     in their own department only.
 *   - Super Admin / Principal (role = 'super_admin') — can see all faculty
 *     across all departments.
 *
 * WHAT IT DOES:
 *   GET  ?filter=pending|verified|all   → lists faculty accounts
 *   POST action=approve   faculty_id=X  → approves a teacher
 *   POST action=reject    faculty_id=X  → rejects a pending teacher
 *   POST action=revoke    faculty_id=X  → removes access from an approved teacher
 *   POST action=delete    faculty_id=X  → permanently deletes a teacher account
 *
 * PROTOTYPE NOTE:
 *   During the prototype phase, email sending is wrapped in a try/catch
 *   so that a missing mail config does not break the approval flow.
 *   In the full deployment, configure mailer.php with the school's SMTP.
 */

require_once __DIR__ . '/../../php/session_guard.php';
require_once __DIR__ . '/../../php/db_connect.php';

header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

// ── Auth check ────────────────────────────────────────────────────────────────
// Only head teachers and the principal (super admin) may manage accounts.
$role     = $_SESSION['role']     ?? '';
$admin_id = (int)($_SESSION['admin_id'] ?? 0);

$allowed_roles = ['head_teacher', 'super_admin'];
if (!in_array($role, $allowed_roles) || !$admin_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

// ── GET: list faculty ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filter = $_GET['filter'] ?? 'all';

    // Head teachers only see their own department's faculty.
    // The principal sees everyone.
    $dept_filter = '';
    if ($role === 'head_teacher') {
        $dept_id     = (int)($_SESSION['department_id'] ?? 0);
        $dept_filter = $dept_id ? "AND f.department_id = $dept_id" : "AND 1=0";
    }

    $status_filter = match ($filter) {
        'pending'  => 'WHERE f.is_verified = 0',
        'verified' => 'WHERE f.is_verified = 1',
        default    => 'WHERE 1=1',
    };

    $rows = [];
    $result = $conn->query("
        SELECT
            f.id,
            f.last_name,
            f.first_name,
            f.middle_initial,
            f.email,
            f.employee_id,
            f.is_verified,
            f.created_at,
            f.approved_at,
            d.name            AS department_name,
            CONCAT(a.first_name, ' ', a.last_name) AS approved_by_name
        FROM faculty f
        LEFT JOIN admins      a ON a.id = f.approved_by
        LEFT JOIN departments d ON d.id = f.department_id
        $status_filter
        $dept_filter
        ORDER BY f.is_verified ASC, f.created_at ASC
    ");

    if (!$result) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error.']);
        exit;
    }

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

// ── POST: approve / reject / revoke / delete ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = trim($_POST['action']     ?? '');
    $faculty_id = (int)($_POST['faculty_id'] ?? 0);

    if (!$faculty_id) {
        echo json_encode(['success' => false, 'message' => 'faculty_id is required.']);
        exit;
    }

    // Head teachers may only act on faculty inside their own department.
    if ($role === 'head_teacher') {
        $dept_id = (int)($_SESSION['department_id'] ?? 0);

        $chk = $conn->prepare(
            'SELECT id FROM faculty WHERE id = ? AND department_id = ? LIMIT 1'
        );
        $chk->bind_param('ii', $faculty_id, $dept_id);
        $chk->execute();
        $chk->store_result();

        if ($chk->num_rows === 0) {
            $chk->close();
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'You can only manage faculty in your own department.',
            ]);
            exit;
        }
        $chk->close();
    }

    // ── approve ───────────────────────────────────────────────────────────────
    if ($action === 'approve') {
        // Fetch faculty details first so we can send the email.
        $stmt = $conn->prepare(
            'SELECT email, first_name FROM faculty WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $faculty_id);
        $stmt->execute();
        $stmt->bind_result($faculty_email, $faculty_first_name);
        $found = $stmt->fetch();
        $stmt->close();

        if (!$found || empty($faculty_email) || empty($faculty_first_name)) {
            echo json_encode([
                'success' => false,
                'message' => 'Faculty record not found or incomplete.',
            ]);
            exit;
        }

        // Mark as approved in the database.
        $stmt = $conn->prepare(
            'UPDATE faculty
             SET is_verified = 1, approved_by = ?, approved_at = NOW()
             WHERE id = ?'
        );
        $stmt->bind_param('ii', $admin_id, $faculty_id);
        $stmt->execute();
        $stmt->close();

        // Send approval email.
        // Wrapped in try/catch so a broken mail config does not fail the whole action.
        // During the prototype, you can comment the sendApprovalEmail() call out
        // and just check that the DB update works first.
        try {
            require_once __DIR__ . '/../../php/mailer.php';
            sendApprovalEmail($faculty_email, $faculty_first_name);
        } catch (\Throwable $e) {
            // Email failed — log it but still return success for the DB update.
            error_log('[AccountController] sendApprovalEmail failed: ' . $e->getMessage());
        }

        echo json_encode(['success' => true, 'message' => 'Faculty account approved.']);
        exit;
    }

    // ── reject ────────────────────────────────────────────────────────────────
    if ($action === 'reject') {
        $stmt = $conn->prepare(
            'UPDATE faculty
             SET is_verified = 0, approved_by = NULL, approved_at = NULL
             WHERE id = ?'
        );
        $stmt->bind_param('i', $faculty_id);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => true, 'message' => 'Faculty account rejected.']);
        exit;
    }

    // ── revoke ────────────────────────────────────────────────────────────────
    // Same DB change as reject, but the message is different.
    // Revoke = they were approved before, now we take it back.
    if ($action === 'revoke') {
        $stmt = $conn->prepare(
            'UPDATE faculty
             SET is_verified = 0, approved_by = NULL, approved_at = NULL
             WHERE id = ?'
        );
        $stmt->bind_param('i', $faculty_id);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => true, 'message' => 'Faculty access revoked.']);
        exit;
    }

    // ── delete ────────────────────────────────────────────────────────────────
    // Permanently removes the teacher from the system.
    // Only the principal (super_admin) should be allowed to permanently delete.
    if ($action === 'delete') {
        if ($role !== 'super_admin') {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Only the Principal can permanently delete accounts.',
            ]);
            exit;
        }

        $stmt = $conn->prepare('DELETE FROM faculty WHERE id = ?');
        $stmt->bind_param('i', $faculty_id);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => true, 'message' => 'Faculty account deleted.']);
        exit;
    }

    // Unknown action
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// Any other HTTP method (PUT, DELETE, PATCH...) is not allowed.
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);