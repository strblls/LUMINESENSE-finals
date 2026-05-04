<?php
// api/accounts.php
// Admin only. Manages faculty account approval.
// GET  ?action=list&filter=pending|verified|all  → returns faculty list
// POST action=approve  faculty_id=X
// POST action=reject   faculty_id=X
// POST action=revoke   faculty_id=X

require_once '../php/db_connect.php';
header('Content-Type: application/json');

// Must be logged in as admin
if (empty($_SESSION['admin_logged_in']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
}

$admin_id = (int)$_SESSION['admin_id'];

// ── GET: list faculty ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filter = $_GET['filter'] ?? 'all';

    $where = match($filter) {
        'pending'  => 'WHERE f.is_verified = 0',
        'verified' => 'WHERE f.is_verified = 1',
        default    => ''
    };

    $rows = [];
    $r = $conn->query("
        SELECT f.id, f.last_name, f.first_name, f.middle_initial,
               f.email, f.is_verified, f.created_at,
               CONCAT(a.first_name,' ',a.last_name) AS approved_by_name,
               f.approved_at
        FROM faculty f
        LEFT JOIN admins a ON a.id = f.approved_by
        $where
        ORDER BY f.is_verified ASC, f.created_at ASC
    ");
    while ($row = $r->fetch_assoc()) $rows[] = $row;

    echo json_encode(['success' => true, 'data' => $rows]); exit;
}

// ── POST: approve / reject / revoke ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action']     ?? '';
    $faculty_id = (int)($_POST['faculty_id'] ?? 0);

    if (!$faculty_id) {
        echo json_encode(['success' => false, 'message' => 'faculty_id required.']); exit;
    }

    if ($action === 'approve') {
        $stmt = $conn->prepare('UPDATE faculty SET is_verified=1, approved_by=?, approved_at=NOW() WHERE id=?');
        $stmt->bind_param('ii', $admin_id, $faculty_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Faculty account approved.']); exit;
    }

    if ($action === 'reject' || $action === 'revoke') {
        // Soft revoke: set is_verified=0 and clear approval info
        $stmt = $conn->prepare('UPDATE faculty SET is_verified=0, approved_by=NULL, approved_at=NULL WHERE id=?');
        $stmt->bind_param('i', $faculty_id);
        $stmt->execute();
        $stmt->close();
        $msg = $action === 'reject' ? 'Faculty account rejected.' : 'Faculty access revoked.';
        echo json_encode(['success' => true, 'message' => $msg]); exit;
    }

    if ($action === 'delete') {
        $stmt = $conn->prepare('DELETE FROM faculty WHERE id=?');
        $stmt->bind_param('i', $faculty_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Faculty account deleted.']); exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']); exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
