<?php
require_once realpath(__DIR__ . '/../includes/admin-head.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
    header('Location: ../../pages/admin-home/admin-room-manage.php'); exit;
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
    }
}

if ($_POST['action'] === 'delete_room') {
    $id = (int)($_POST['room_id'] ?? 0);
    if ($id) {
        $stmt = $conn->prepare("DELETE FROM classrooms WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }
}

header('Location: ../../pages/admin-home/admin-room-manage.php'); exit;