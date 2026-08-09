<?php
/**
 * flush-executor.php
 * Shared logic for executing a scheduled flush.
 * Included by flush-handler.php and check-flush-schedule.php
 */

/**
 * Execute only the extension flush (called by cron when extension_reset_datetime is reached).
 */
function execute_extension_flush($conn) {
    $conn->query("UPDATE schedules SET extended_until = NULL WHERE extended_until IS NOT NULL");
    $conn->query("DELETE FROM extension_requests");

    $conn->query("DELETE FROM system_settings WHERE setting_key = 'extension_reset_datetime'");

    $log_stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, action, target_name, notes) VALUES (0, 'extension_flush', 'Extensions Cleared', 'Auto-cleared at scheduled extension time')");
    $log_stmt->execute();
    $log_stmt->close();

    return ['extensions_cleared'];
}

/**
 * Execute system flush with archiving.
 */
function execute_flush($conn, $admin_id, $flush_id, $flush_departments, $flush_subject_areas, $flush_subjects, $semester = null, $academic_year = null) {
    $executed_items = [];
    $total_archived = 0;
    $total_deleted = 0;
    $total_cleared = 0;

    $semester = $semester ?: 'Unknown';
    $academic_year = $academic_year ?: date('Y') . '-' . (date('Y') + 1);

    // Insert into archive_registry
    $reg_stmt = $conn->prepare("INSERT INTO archive_registry (semester, academic_year, flush_type, flushed_by, notes) VALUES (?, ?, 'semester', ?, ?)");
    $notes = 'System flush: schedules';
    if ($flush_departments) $notes .= ', departments';
    if ($flush_subject_areas) $notes .= ', subject areas';
    if ($flush_subjects) $notes .= ', subjects';
    $reg_stmt->bind_param('ssis', $semester, $academic_year, $admin_id, $notes);
    $reg_stmt->execute();
    $registry_id = $conn->insert_id;
    $reg_stmt->close();

    // 1. Archive schedules before deleting
    $conn->query("INSERT INTO archived_schedules (registry_id, original_id, classroom_id, faculty_id, day_of_week, start_time, end_time, extended_until, subject_id, created_by)
                  SELECT $registry_id, id, classroom_id, faculty_id, day_of_week, start_time, end_time, extended_until, subject_id, created_by FROM schedules");
    $total_archived += $conn->affected_rows;

    // 2. Delete schedules
    $conn->query("DELETE FROM schedules");
    $total_deleted += $conn->affected_rows;
    $executed_items[] = 'schedules_flushed';

    // Determine cascading level
    $do_departments   = $flush_departments;
    $do_subject_areas = $flush_subject_areas || $flush_departments;
    $do_subjects      = $flush_subjects || $flush_subject_areas || $flush_departments;

    // 3. Archive + delete extension requests
    $conn->query("INSERT INTO archived_extension_requests (registry_id, original_id, schedule_id, faculty_id, extend_mins, status, requested_at, reviewed_by, reviewed_at)
                  SELECT $registry_id, id, schedule_id, faculty_id, extend_mins, status, requested_at, reviewed_by, reviewed_at FROM extension_requests");
    $total_archived += $conn->affected_rows;
    $conn->query("DELETE FROM extension_requests");
    $total_cleared += $conn->affected_rows;

    // 4. Archive + delete departments
    if ($do_departments) {
        $conn->query("INSERT INTO archived_departments (registry_id, original_id, name, head_faculty_id)
                      SELECT $registry_id, id, name, head_faculty_id FROM departments");
        $total_archived += $conn->affected_rows;
        $conn->query("DELETE FROM junction_faculty_department");
        $conn->query("DELETE FROM subject_area");
        $conn->query("DELETE FROM departments");
        $total_deleted += $conn->affected_rows;
        $executed_items[] = 'departments_flushed';
    }

    // 5. Archive + delete subject areas
    if ($do_subject_areas && !$do_departments) {
        $conn->query("INSERT INTO archived_subject_areas (registry_id, original_id, name, department_id)
                      SELECT $registry_id, id, name, department_id FROM subject_area");
        $total_archived += $conn->affected_rows;
        $conn->query("DELETE FROM junction_faculty_subjectarea");
        $conn->query("DELETE FROM subject_area");
        $total_deleted += $conn->affected_rows;
        $executed_items[] = 'subject_areas_flushed';
    }

    // 6. Archive + delete subjects
    if ($do_subjects) {
        $conn->query("INSERT INTO archived_subjects (registry_id, original_id, name, subject_area_id)
                      SELECT $registry_id, id, name, subject_area_id FROM subjects");
        $total_archived += $conn->affected_rows;
        $conn->query("DELETE FROM junction_faculty_subject");
        $conn->query("DELETE FROM subjects");
        $total_deleted += $conn->affected_rows;
        $executed_items[] = 'subjects_flushed';
    }

    // 7. Clean up junction tables
    if ($do_departments) {
        $conn->query("DELETE FROM junction_faculty_subjectarea");
        $conn->query("DELETE FROM junction_faculty_subject");
    } elseif ($do_subject_areas) {
        $conn->query("DELETE FROM junction_faculty_subjectarea");
    } elseif ($do_subjects) {
        $conn->query("DELETE FROM junction_faculty_subject");
    }

    // Update registry with counts
    $conn->query("UPDATE archive_registry SET total_archived = $total_archived, total_deleted = $total_deleted, total_cleared = $total_cleared WHERE id = $registry_id");

    // Mark flush as executed
    $stmt = $conn->prepare("UPDATE flush_schedules SET executed = 1, executed_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $flush_id);
    $stmt->execute();
    $stmt->close();

    // Log admin action
    $log_notes = implode(', ', $executed_items) . " | Archived: $total_archived, Deleted: $total_deleted, Cleared: $total_cleared";
    $log_stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, action, target_name, notes) VALUES (?, 'system_flush', 'System Flush Executed', ?)");
    $log_stmt->bind_param('is', $admin_id, $log_notes);
    $log_stmt->execute();
    $log_stmt->close();

    return $executed_items;
}
