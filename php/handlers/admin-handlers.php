<?php
ob_start();

$isStandalone = false;
if (!isset($conn) || !isset($admin_id)) {
    $isStandalone = true;
    session_start();
    require_once __DIR__ . '/../db_connect.php';
    if (!isset($_SESSION['admin_id']) || !$_SESSION['admin_logged_in']) {
        header('Location: ../../pages/admin-login.php');
        exit;
    }
    $admin_id = $_SESSION['admin_id'];
    $message = '';
}

function log_admin_action($conn, $admin_id, $action, $target_name = '', $notes = '') {
    $stmt = $conn->prepare('INSERT INTO admin_logs (admin_id, action, target_name, notes) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $admin_id, $action, $target_name, $notes);
    $stmt->execute();
    $stmt->close();
}

/**
 * Clean up all related records before deleting a faculty member.
 * Handles junction tables, review queue, and ID image file.
 * Returns ['name' => '...', 'email' => '...'] or null if not found.
 */
function faculty_delete_cleanup($conn, $faculty_id) {
    $stmt = $conn->prepare('SELECT first_name, last_name, email, id_image FROM faculty WHERE id = ?');
    $stmt->bind_param('i', $faculty_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $faculty = $result->fetch_assoc();
    $stmt->close();

    if (!$faculty) return null;

    $conn->query("DELETE FROM junction_faculty_department WHERE faculty_id = $faculty_id");
    $conn->query("DELETE FROM junction_faculty_subject      WHERE faculty_id = $faculty_id");
    $conn->query("DELETE FROM junction_faculty_subjectarea   WHERE faculty_id = $faculty_id");
    $conn->query("DELETE FROM id_review_queue WHERE account_type = 'faculty' AND account_id = $faculty_id");

    if (!empty($faculty['id_image'])) {
        $img_path = realpath(__DIR__ . '/../../' . $faculty['id_image']);
        if ($img_path && file_exists($img_path)) {
            unlink($img_path);
        }
    }

    return [
        'name'  => $faculty['first_name'] . ' ' . $faculty['last_name'],
        'email' => $faculty['email']
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $dept_id = (int)($_POST['department_id'] ?? 0);
    
    if ($action === 'delete_department' && $dept_id > 0) {
        $stmt = $conn->prepare('SELECT name FROM departments WHERE id = ?');
        $stmt->bind_param('i', $dept_id);
        $stmt->execute();
        $stmt->bind_result($dept_name);
        $stmt->fetch();
        $stmt->close();
        
        $conn->query("DELETE FROM junction_faculty_department WHERE department_id = $dept_id");
        $conn->query("DELETE FROM subject_area WHERE department_id = $dept_id");
        
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
        $subject_areas_text = isset($_POST['dept_subject_areas']) ? array_map('trim', $_POST['dept_subject_areas']) : [];
        
        if (!empty($dept_name)) {
            $stmt = $conn->prepare('INSERT INTO departments (name, description, head_faculty_id) VALUES (?, ?, ?)');
            $stmt->bind_param('ssi', $dept_name, $dept_desc, $head_id);
            $stmt->execute();
            $new_dept_id = $conn->insert_id;
            $stmt->close();
            
            if (!empty($faculty_members)) {
                $insert_values = [];
                foreach ($faculty_members as $member_id) {
                    $insert_values[] = "($member_id, $new_dept_id)";
                }
                if ($head_id && !in_array($head_id, $faculty_members)) {
                    $insert_values[] = "($head_id, $new_dept_id)";
                }
                if (!empty($insert_values)) {
                    $conn->query("INSERT INTO junction_faculty_department (faculty_id, department_id) VALUES " . implode(',', $insert_values));
                }
            } elseif ($head_id) {
                $conn->query("INSERT INTO junction_faculty_department (faculty_id, department_id) VALUES ($head_id, $new_dept_id)");
            }
            
            foreach ($subject_areas_text as $sa_name) {
                if (!empty($sa_name)) {
                    $stmt = $conn->prepare("SELECT id FROM subject_area WHERE name = ?");
                    $stmt->bind_param('s', $sa_name);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result && $result->num_rows > 0) {
                        $subject_area_row = $result->fetch_assoc();
                        $subject_area_id = $subject_area_row['id'];
                        $stmt_update = $conn->prepare("UPDATE subject_area SET department_id = ? WHERE id = ?");
                        $stmt_update->bind_param('ii', $new_dept_id, $subject_area_id);
                        $stmt_update->execute();
                        $stmt_update->close();
                    } else {
                        $stmt_insert = $conn->prepare("INSERT INTO subject_area (name, department_id) VALUES (?, ?)");
                        $stmt_insert->bind_param('si', $sa_name, $new_dept_id);
                        $stmt_insert->execute();
                        $stmt_insert->close();
                    }
                    $stmt->close();
                }
            }
            
            $message = 'Department added successfully.';
            log_admin_action($conn, $admin_id, 'department_added', $dept_name);
        }
        
    } elseif ($action === 'edit_department' && $dept_id > 0) {
        $dept_name = trim($_POST['dept_name'] ?? '');
        $dept_desc = trim($_POST['dept_description'] ?? '');
        $head_id = !empty($_POST['head_faculty_id']) ? (int)$_POST['head_faculty_id'] : null;
        $faculty_members = isset($_POST['faculty_members']) ? array_map('intval', $_POST['faculty_members']) : [];
        $subject_areas_text = isset($_POST['dept_subject_areas']) ? array_map('trim', $_POST['dept_subject_areas']) : [];
        
        // Remove empty entries
        $subject_areas_text = array_values(array_filter($subject_areas_text));
        
        // Departments without a head are forced to 'pending'
        $status = empty($head_id) ? 'pending' : trim($_POST['dept_status'] ?? 'active');
        if (!in_array($status, ['active', 'inactive', 'pending'])) {
            $status = 'active';
        }
        
        if (!empty($dept_name)) {
            $stmt = $conn->prepare('UPDATE departments SET name = ?, description = ?, head_faculty_id = ?, status = ? WHERE id = ?');
            $stmt->bind_param('ssiss', $dept_name, $dept_desc, $head_id, $status, $dept_id);
            $stmt->execute();
            $stmt->close();
            
            $conn->query("DELETE FROM junction_faculty_department WHERE department_id = $dept_id");
            if (!empty($faculty_members)) {
                $insert_values = [];
                foreach ($faculty_members as $member_id) {
                    $insert_values[] = "($member_id, $dept_id)";
                }
                if ($head_id && !in_array($head_id, $faculty_members)) {
                    $insert_values[] = "($head_id, $dept_id)";
                }
                if (!empty($insert_values)) {
                    $conn->query("INSERT INTO junction_faculty_department (faculty_id, department_id) VALUES " . implode(',', $insert_values));
                }
            } elseif ($head_id) {
                $conn->query("INSERT INTO junction_faculty_department (faculty_id, department_id) VALUES ($head_id, $dept_id)");
            }
            
            $conn->query("UPDATE subject_area SET department_id = NULL WHERE department_id = $dept_id");
            foreach ($subject_areas_text as $sa_name) {
                if (!empty($sa_name)) {
                    $stmt = $conn->prepare("SELECT id FROM subject_area WHERE name = ?");
                    $stmt->bind_param('s', $sa_name);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result && $result->num_rows > 0) {
                        $subject_area_row = $result->fetch_assoc();
                        $subject_area_id = $subject_area_row['id'];
                        $stmt_update = $conn->prepare("UPDATE subject_area SET department_id = ? WHERE id = ?");
                        $stmt_update->bind_param('ii', $dept_id, $subject_area_id);
                        $stmt_update->execute();
                        $stmt_update->close();
                    } else {
                        $stmt_insert = $conn->prepare("INSERT INTO subject_area (name, department_id) VALUES (?, ?)");
                        $stmt_insert->bind_param('si', $sa_name, $dept_id);
                        $stmt_insert->execute();
                        $stmt_insert->close();
                    }
                    $stmt->close();
                }
            }
            
            $message = 'Department updated successfully.';
            log_admin_action($conn, $admin_id, 'department_edited', $dept_name);
        }
    }
    
    if ($isStandalone) {
        $_SESSION['message'] = $message;
        header('Location: ../../pages/admin-home/admin-faculty-management.php');
        exit;
    }
}

if ($isStandalone && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../pages/admin-home/admin-faculty-management.php');
    exit;
}

$departments = [];
if (!$isStandalone) {
    $checkTable = $conn->query("SHOW TABLES LIKE 'departments'");
    if ($checkTable && $checkTable->num_rows > 0) {
        $result = $conn->query("SELECT d.*, h.first_name AS head_first_name, h.last_name AS head_last_name FROM departments d LEFT JOIN faculty h ON h.id = d.head_faculty_id ORDER BY d.name ASC");
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                if (!isset($row['status'])) {
                    $row['status'] = 'active';
                }
                
                $exclude_head = $row['head_faculty_id'] ? " AND f.id != " . (int)$row['head_faculty_id'] : "";
                $faculty_members_res = $conn->query("SELECT f.id, f.first_name, f.last_name FROM faculty f JOIN junction_faculty_department jfd ON f.id = jfd.faculty_id WHERE jfd.department_id = " . $row['id'] . $exclude_head);
                $row['faculty_members'] = [];
                if ($faculty_members_res) {
                    while ($member = $faculty_members_res->fetch_assoc()) {
                        $row['faculty_members'][] = $member;
                    }
                }
                
                $subject_area_res = $conn->query("SELECT sa.name FROM subject_area sa WHERE sa.department_id = " . $row['id']);
                $row['subject_areas'] = [];
                if ($subject_area_res) {
                    while ($sa_row = $subject_area_res->fetch_assoc()) {
                        $row['subject_areas'][] = $sa_row['name'];
                    }
                }
                
                $departments[] = $row;
            }
        }
    }
}

ob_end_clean();
