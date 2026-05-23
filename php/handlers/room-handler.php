<?php
require_once realpath(__DIR__ . '/../includes/admin-head.php');
require_once __DIR__ . '/admin-handlers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
    header('Location: ../../pages/admin-home/admin-room-manage.php'); exit;
}

// Helper to log to room_logs (shows on homepage)
function log_room_event(mysqli $conn, string $event_type, string $room_name, string $triggered_by = 'admin', string $notes = ''): void {
    $stmt = $conn->prepare("INSERT INTO room_logs (event_type, room_name, triggered_by, notes) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('ssss', $event_type, $room_name, $triggered_by, $notes);
    $stmt->execute();
    $stmt->close();
}

if ($_POST['action'] === 'add_room') {
    $room_name   = trim($_POST['room_name']   ?? '');
    $room_size   = trim($_POST['room_size']   ?? 'medium');
    $description = trim($_POST['description'] ?? '');
    if ($room_name !== '') {
        $stmt = $conn->prepare("INSERT INTO classrooms (room_name, room_size, description) VALUES (?,?,?)");
        $stmt->bind_param('sss', $room_name, $room_size, $description);
        $stmt->execute();
        $stmt->close();
        log_admin_action($conn, $_SESSION['admin_id'], 'room_added', $room_name);
        log_room_event($conn, 'room_added', $room_name, $_SESSION['admin_name'] ?? 'admin');
    }
}

if ($_POST['action'] === 'edit_room') {
    $id          = (int)($_POST['room_id']    ?? 0);
    $room_name   = trim($_POST['room_name']   ?? '');
    $room_size   = trim($_POST['room_size']   ?? 'medium');
    $description = trim($_POST['description'] ?? '');
    if ($id && $room_name !== '') {
        $stmt = $conn->prepare("UPDATE classrooms SET room_name=?, room_size=?, description=? WHERE id=?");
        $stmt->bind_param('sssi', $room_name, $room_size, $description, $id);
        $stmt->execute();
        $stmt->close();
        log_admin_action($conn, $_SESSION['admin_id'], 'room_updated', $room_name);
        log_room_event($conn, 'room_updated', $room_name, $_SESSION['admin_name'] ?? 'admin');
    }
}

if ($_POST['action'] === 'delete_room') {
    $id = (int)($_POST['room_id'] ?? 0);
    if ($id) {
        $room_name = '';
        $stmt = $conn->prepare("SELECT room_name FROM classrooms WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($room_name);
        $stmt->fetch();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM classrooms WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        log_admin_action($conn, $_SESSION['admin_id'], 'room_deleted', $room_name);
        log_room_event($conn, 'room_deleted', $room_name, $_SESSION['admin_name'] ?? 'admin');
    }
}

header('Location: ../../pages/admin-home/admin-room-manage.php'); exit;