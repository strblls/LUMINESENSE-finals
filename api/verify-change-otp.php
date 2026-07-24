<?php
require_once __DIR__ . '/../php/session_guard.php';
require_once __DIR__ . '/../php/db_connect.php';
header('Content-Type: application/json');

$isAdmin = !empty($_SESSION['admin_logged_in']);
$isFaculty = !empty($_SESSION['faculty_logged_in']);

if (!$isAdmin && !$isFaculty) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$table = $isAdmin ? 'admins' : 'faculty';
$id    = $isAdmin ? (int)$_SESSION['admin_id'] : (int)$_SESSION['faculty_id'];

$otp = trim($_POST['otp'] ?? '');
if (!$otp) {
    echo json_encode(['success' => false, 'message' => 'Code is required.']);
    exit;
}

$stmt = $conn->prepare("SELECT otp_code, otp_expires_at FROM $table WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || $row['otp_code'] !== $otp) {
    echo json_encode(['success' => false, 'message' => 'Invalid verification code.']);
    exit;
}

if (strtotime($row['otp_expires_at']) < time()) {
    echo json_encode(['success' => false, 'message' => 'Code has expired. Request a new one.']);
    exit;
}

$_SESSION['otp_change_verified'] = true;
echo json_encode(['success' => true, 'message' => 'Verified successfully.']);
$conn->close();
