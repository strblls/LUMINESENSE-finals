<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../php/session_guard.php';
check_faculty();
require_once __DIR__ . '/../php/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

$faculty_id = (int) $_SESSION['faculty_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$pin    = $input['pin'] ?? '';

if (!preg_match('/^\d{4}$/', $pin)) {
    echo json_encode(['success' => false, 'message' => 'PIN must be exactly 4 digits.']);
    exit;
}

switch ($action) {

    // ── Save a new PIN (first-time setup, no old PIN required) ──
    case 'save':
        // Ensure a row exists, then set pin_hash
        $conn->query("INSERT IGNORE INTO faculty_permissions (faculty_id) VALUES ($faculty_id)");
        $hash = password_hash($pin, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE faculty_permissions SET pin_hash = ? WHERE faculty_id = ?");
        $stmt->bind_param('si', $hash, $faculty_id);
        $stmt->execute();
        echo json_encode(['success' => true, 'message' => 'PIN saved successfully.']);
        break;

    // ── Verify an existing PIN ──
    case 'verify':
        $stmt = $conn->prepare("SELECT pin_hash FROM faculty_permissions WHERE faculty_id = ?");
        $stmt->bind_param('i', $faculty_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row || !$row['pin_hash']) {
            echo json_encode(['success' => false, 'message' => 'No PIN set.']);
            exit;
        }
        if (!password_verify($pin, $row['pin_hash'])) {
            echo json_encode(['success' => false, 'message' => 'Incorrect PIN.']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'PIN verified.']);
        break;

    // ── Change PIN (requires old_pin) ──
    case 'change':
        $old_pin = $input['old_pin'] ?? '';
        if (!preg_match('/^\d{4}$/', $old_pin)) {
            echo json_encode(['success' => false, 'message' => 'Current PIN must be exactly 4 digits.']);
            exit;
        }
        $stmt = $conn->prepare("SELECT pin_hash FROM faculty_permissions WHERE faculty_id = ?");
        $stmt->bind_param('i', $faculty_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row || !$row['pin_hash']) {
            echo json_encode(['success' => false, 'message' => 'No PIN set.']);
            exit;
        }
        if (!password_verify($old_pin, $row['pin_hash'])) {
            echo json_encode(['success' => false, 'message' => 'Current PIN is incorrect.']);
            exit;
        }
        $hash = password_hash($pin, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE faculty_permissions SET pin_hash = ? WHERE faculty_id = ?");
        $stmt->bind_param('si', $hash, $faculty_id);
        $stmt->execute();
        echo json_encode(['success' => true, 'message' => 'PIN changed successfully.']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
