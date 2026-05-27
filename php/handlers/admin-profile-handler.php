<?php
/**
 * php/handlers/admin-profile-handler.php
 * Handles admin contact info updates.
 * Place at: php/handlers/admin-profile-handler.php
 */
require_once __DIR__ . '/../session_guard.php';
check_admin();
require_once __DIR__ . '/../db_connect.php';

$action = $_POST['action'] ?? '';

if ($action === 'update_contact') {
    $name  = trim($_POST['admin_name']  ?? '');
    $email = trim($_POST['admin_email'] ?? '');

    if (!$name || !$email) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Name and email are required.'];
        header('Location: ../../admin/pages/admin-profile-settings.php');
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid email address.'];
        header('Location: ../../admin/pages/admin-profile-settings.php');
        exit;
    }

    $admin_id = $_SESSION['admin_id'];

    // Check email not already taken by another admin
    $stmt = $conn->prepare("SELECT id FROM admins WHERE email = ? AND id != ?");
    $stmt->bind_param('si', $email, $admin_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'That email is already in use.'];
        header('Location: ../../admin/pages/admin-profile-settings.php');
        exit;
    }
    $stmt->close();

    $stmt = $conn->prepare("UPDATE admins SET name = ?, email = ? WHERE id = ?");
    $stmt->bind_param('ssi', $name, $email, $admin_id);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();

    if ($ok) {
        // Update session so topbar/sidebar reflect new name immediately
        $_SESSION['admin_name']  = $name;
        $_SESSION['admin_email'] = $email;
        $parts = explode(' ', $name);
        $_SESSION['initials'] = strtoupper(
            substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : '')
        );
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Profile updated successfully.'];
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Update failed. Please try again.'];
    }

    header('Location: ../../admin/pages/admin-profile-settings.php');
    exit;
}

// Unknown action
$_SESSION['flash'] = ['type' => 'error', 'msg' => 'Unknown action.'];
header('Location: ../../admin/pages/admin-profile-settings.php');
exit;