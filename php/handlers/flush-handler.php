<?php
/**
 * flush-handler.php
 * Handles schedule_flush, cancel_flush, confirm_flush, dismiss_reminder actions.
 * POST requests only. Returns JSON.
 */

header('Content-Type: application/json');

require_once realpath(__DIR__ . '/../includes/admin-head.php');
require_once __DIR__ . '/admin-handlers.php';
require_once __DIR__ . '/../cron/flush-executor.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']); exit;
}

// Ensure only seeded admin can access
$seed_check = $conn->query("SELECT is_seeded FROM admins WHERE id = " . (int)$admin_id);
$seed_row = $seed_check->fetch_assoc();
if (empty($seed_row['is_seeded'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']); exit;
}

$action = trim($_POST['action'] ?? '');

// ── SCHEDULE_FLUSH ─────────────────────────────────────────────────────────
if ($action === 'schedule_flush') {
    $scheduled_datetime = trim($_POST['scheduled_datetime'] ?? '');
    $flush_departments  = !empty($_POST['flush_departments']) ? 1 : 0;
    $flush_subject_areas = !empty($_POST['flush_subject_areas']) ? 1 : 0;
    $flush_subjects     = !empty($_POST['flush_subjects']) ? 1 : 0;
    if (!$scheduled_datetime) {
        echo json_encode(['success' => false, 'message' => 'Scheduled date and time is required.']); exit;
    }

    // Validate minimum 5 months from now
    $min_date = date('Y-m-d H:i:s', strtotime('+5 months'));
    if (strtotime($scheduled_datetime) < strtotime($min_date)) {
        echo json_encode(['success' => false, 'message' => 'Scheduled date must be at least 5 months from now.']); exit;
    }

    // Cancel any existing pending flush for this admin
    $conn->query("DELETE FROM flush_schedules WHERE created_by = $admin_id AND executed = 0");

    $stmt = $conn->prepare("
        INSERT INTO flush_schedules (scheduled_datetime, flush_schedules, flush_departments, flush_subject_areas, flush_subjects, created_by)
        VALUES (?, 1, ?, ?, ?, ?)
    ");
    if (!$stmt) { echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]); exit; }
    $stmt->bind_param('siiii', $scheduled_datetime, $flush_departments, $flush_subject_areas, $flush_subjects, $admin_id);
    if ($stmt->execute()) {
        $new_id = $conn->insert_id;
        $stmt->close();
        log_admin_action($conn, $admin_id, 'flush_scheduled', 'System Flush', $scheduled_datetime);
        echo json_encode(['success' => true, 'flush_id' => $new_id, 'message' => 'Flush scheduled successfully.']);
        exit;
    }
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error.']); exit;
}

// ── SCHEDULE_FLUSH_EXTENSIONS ──────────────────────────────────────────────
if ($action === 'schedule_flush_extensions') {
    $flush_ext_datetime = trim($_POST['flush_extensions_datetime'] ?? '');
    if (!$flush_ext_datetime) {
        echo json_encode(['success' => false, 'message' => 'Extensions date and time is required.']); exit;
    }

    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('extension_reset_datetime', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    if (!$stmt) { echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]); exit; }
    $stmt->bind_param('s', $flush_ext_datetime);
    if ($stmt->execute()) {
        $stmt->close();
        log_admin_action($conn, $admin_id, 'extensions_flush_scheduled', 'Extensions Reset', $flush_ext_datetime);
        echo json_encode(['success' => true, 'message' => 'Extensions reset scheduled successfully.']);
        exit;
    }
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]); exit;
}

// ── CANCEL_FLUSH ────────────────────────────────────────────────────────────
if ($action === 'cancel_flush') {
    $flush_id = (int)($_POST['flush_id'] ?? 0);
    if (!$flush_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid flush ID.']); exit;
    }

    $stmt = $conn->prepare("DELETE FROM flush_schedules WHERE id = ? AND created_by = ? AND executed = 0");
    $stmt->bind_param('ii', $flush_id, $admin_id);
    if ($stmt->execute()) {
        $stmt->close();
        log_admin_action($conn, $admin_id, 'flush_cancelled', 'System Flush', 'Cancelled by admin');
        echo json_encode(['success' => true, 'message' => 'Flush cancelled.']);
        exit;
    }
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error.']); exit;
}

// ── CONFIRM_FLUSH ───────────────────────────────────────────────────────────
if ($action === 'confirm_flush') {
    $flush_id = (int)($_POST['flush_id'] ?? 0);
    if (!$flush_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid flush ID.']); exit;
    }

    $stmt = $conn->prepare("UPDATE flush_schedules SET confirmed = 1, reminder_dismissed = 1 WHERE id = ? AND created_by = ? AND executed = 0");
    $stmt->bind_param('ii', $flush_id, $admin_id);
    if ($stmt->execute()) {
        $stmt->close();
        log_admin_action($conn, $admin_id, 'flush_confirmed', 'System Flush', 'Confirmed by admin');
        echo json_encode(['success' => true, 'message' => 'Flush confirmed.']);
        exit;
    }
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error.']); exit;
}

// ── DISMISS_REMINDER (Understood button) ────────────────────────────────────
if ($action === 'dismiss_reminder') {
    $flush_id = (int)($_POST['flush_id'] ?? 0);
    if (!$flush_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid flush ID.']); exit;
    }

    $stmt = $conn->prepare("UPDATE flush_schedules SET reminder_dismissed = 1 WHERE id = ? AND created_by = ? AND executed = 0");
    $stmt->bind_param('ii', $flush_id, $admin_id);
    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Reminder dismissed.']);
        exit;
    }
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error.']); exit;
}

// ── EXECUTE_FLUSH (immediate execution by admin) ────────────────────────────
if ($action === 'execute_flush') {
    $flush_id = (int)($_POST['flush_id'] ?? 0);
    if (!$flush_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid flush ID.']); exit;
    }

    // Fetch flush config
    $stmt = $conn->prepare("SELECT * FROM flush_schedules WHERE id = ? AND created_by = ? AND executed = 0");
    $stmt->bind_param('ii', $flush_id, $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $flush = $result->fetch_assoc();
    $stmt->close();

    if (!$flush) {
        echo json_encode(['success' => false, 'message' => 'Flush not found or already executed.']); exit;
    }

    $executed_items = execute_flush(
        $conn, $admin_id, $flush_id,
        $flush['flush_departments'], $flush['flush_subject_areas'], $flush['flush_subjects']
    );

    echo json_encode(['success' => true, 'message' => 'System flush completed.', 'items' => $executed_items]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
