<?php
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