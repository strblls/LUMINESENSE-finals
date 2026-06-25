<?php
/**
 * Admin Handlers
 * Handles: add_department, edit_department, delete_department
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
    require_once __DIR__ . '/../db_connect.php';
    
    // Check admin is logged in
    if (!isset($_SESSION['admin_id']) || !$_SESSION['admin_logged_in']) {
        header('Location: ../../pages/admin-login.php');
        exit;
    }
    
    $admin_id = $_SESSION['admin_id'];
    $message = '';
}

function log_admin_action(
    mysqli $conn,
    int    $admin_id,
    string $action,
    string $target_name = '',
    string $notes       = ''
): void {
    $stmt = $conn->prepare(
        'INSERT INTO admin_logs (admin_id, action, target_name, notes)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('isss', $admin_id, $action, $target_name, $notes);
    $stmt->execute();
    $stmt->close();
}

// ── Handle POST requests ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $dept_id = (int)($_POST['department_id'] ?? 0);
    
    if ($action === 'delete_department' && $dept_id > 0) {
        // Get department name for logging
        $stmt = $conn->prepare('SELECT name FROM departments WHERE id = ?');
        $stmt->bind_param('i', $dept_id);
        $stmt->execute();
        $stmt->bind_result($dept_name);
        $stmt->fetch();
        $stmt->close();
        
        // Unlink faculty members first
        $conn->query("UPDATE faculty SET department_id = NULL WHERE department_id = $dept_id");
        
        // Delete department
        $stmt = $conn->prepare('DELETE FROM departments WHERE id = ?');
        $stmt->bind_param('i', $dept_id);
        $stmt->execute();
        $stmt->close();
        
        $message = 'Department deleted successfully.';
        log_admin_action($conn, $admin_id, 'department_deleted', $dept_name ?? 'Unknown');
        
    } elseif ($action === 'add_department') {
        $dept_name = trim($_POST['dept_name'] ?? '');
        $dept_desc = trim($_POST['dept_description'] ?? '');
        $head_id = isset($_POST['head_faculty_id']) ? (int)$_POST['head_faculty_id'] : null;
        $faculty_members = isset($_POST['faculty_members']) ? array_map('intval', $_POST['faculty_members']) : [];
        
        if (!empty($dept_name)) {
            $stmt = $conn->prepare('INSERT INTO departments (name, description, head_faculty_id) VALUES (?, ?, ?)');
            $stmt->bind_param('ssi', $dept_name, $dept_desc, $head_id);
            $stmt->execute();
            $new_dept_id = $conn->insert_id;
            $stmt->close();
            
            // Update faculty members
            if (!empty($faculty_members)) {
                $member_ids = implode(',', $faculty_members);
                $conn->query("UPDATE faculty SET department_id = $new_dept_id WHERE id IN ($member_ids)");
            }
            
            // If head is set, make sure they're in the department
            if ($head_id) {
                $conn->query("UPDATE faculty SET department_id = $new_dept_id WHERE id = $head_id");
            }
            
            $message = 'Department added successfully.';
            log_admin_action($conn, $admin_id, 'department_added', $dept_name);
        }
        
    } elseif ($action === 'edit_department' && $dept_id > 0) {
        $dept_name = trim($_POST['dept_name'] ?? '');
        $dept_desc = trim($_POST['dept_description'] ?? '');
        $head_id = isset($_POST['head_faculty_id']) ? (int)$_POST['head_faculty_id'] : null;
        $faculty_members = isset($_POST['faculty_members']) ? array_map('intval', $_POST['faculty_members']) : [];
        
        if (!empty($dept_name)) {
            $stmt = $conn->prepare('UPDATE departments SET name = ?, description = ?, head_faculty_id = ? WHERE id = ?');
            $stmt->bind_param('ssii', $dept_name, $dept_desc, $head_id, $dept_id);
            $stmt->execute();
            $stmt->close();
            
            // Unlink all faculty first, then link new ones
            $conn->query("UPDATE faculty SET department_id = NULL WHERE department_id = $dept_id");
            
            if (!empty($faculty_members)) {
                $member_ids = implode(',', $faculty_members);
                $conn->query("UPDATE faculty SET department_id = $dept_id WHERE id IN ($member_ids)");
            }
            
            // If head is set, make sure they're in the department
            if ($head_id) {
                $conn->query("UPDATE faculty SET department_id = $dept_id WHERE id = $head_id");
            }
            
            $message = 'Department updated successfully.';
            log_admin_action($conn, $admin_id, 'department_edited', $dept_name);
        }
    }
    
    // If called directly, redirect back to faculty management page
    if ($isStandalone) {
        $_SESSION['message'] = $message;
        header('Location: ../../pages/admin-home/admin-faculty-management.php');
        exit;
    }
}

// If accessed directly via GET (not POST), redirect to management page
if ($isStandalone && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../pages/admin-home/admin-faculty-management.php');
    exit;
}

// ── Departments (only for when included, not standalone) ─────────────────────
$departments = [];
if (!$isStandalone) {
    // Check if departments table exists first
    $checkTable = $conn->query("SHOW TABLES LIKE 'departments'");
    if ($checkTable && $checkTable->num_rows > 0) {
        $result = $conn->query("
            SELECT
                d.*,
                h.first_name AS head_first_name,
                h.last_name  AS head_last_name
            FROM departments d
            LEFT JOIN faculty h ON h.id = d.head_faculty_id
            ORDER BY d.name ASC
        ");
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                if (!isset($row['status'])) {
                    $row['status'] = 'active';
                }
                $departments[] = $row;
            }
        }
    }
}

// Clean output buffer to ensure no accidental output
ob_end_clean();

