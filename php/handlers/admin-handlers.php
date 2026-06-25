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

// ── Departments ──────────────────────────────────────────────────────────────
$departments = [];
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


