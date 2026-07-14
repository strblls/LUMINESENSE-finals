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

    // Remove the scheduled setting so it doesn't re-fire
    $conn->query("DELETE FROM system_settings WHERE setting_key = 'extension_reset_datetime'");

    $log_stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, action, target_name, notes) VALUES (0, 'extension_flush', 'Extensions Cleared', 'Auto-cleared at scheduled extension time')");
    $log_stmt->execute();
    $log_stmt->close();

    return ['extensions_cleared'];
}

function execute_flush($conn, $admin_id, $flush_id, $flush_departments, $flush_subject_areas, $flush_subjects) {
    $executed_items = [];

    // 1. Flush all schedules
    $conn->query("DELETE FROM schedules");
    $executed_items[] = 'schedules_flushed';

    // Determine cascading level
    $do_departments   = $flush_departments;
    $do_subject_areas = $flush_subject_areas || $flush_departments;
    $do_subjects      = $flush_subjects || $flush_subject_areas || $flush_departments;

    // 3. If departments are to be flushed
    if ($do_departments) {
        $conn->query("DELETE FROM junction_faculty_department");
        $conn->query("DELETE FROM subject_area");
        $conn->query("DELETE FROM departments");
        $executed_items[] = 'departments_flushed';
    }

    // 4. If subject areas are to be flushed (but departments were not)
    if ($do_subject_areas && !$do_departments) {
        $conn->query("DELETE FROM junction_faculty_subjectarea");
        $conn->query("DELETE FROM subject_area");
        $executed_items[] = 'subject_areas_flushed';
    }

    // 5. If subjects are to be flushed
    if ($do_subjects) {
        $conn->query("DELETE FROM junction_faculty_subject");
        $conn->query("DELETE FROM subjects");
        $executed_items[] = 'subjects_flushed';
    }

    // 6. Clean up junction tables for departments if not flushed
    if ($do_departments) {
        $conn->query("DELETE FROM junction_faculty_subjectarea");
        $conn->query("DELETE FROM junction_faculty_subject");
    } elseif ($do_subject_areas) {
        $conn->query("DELETE FROM junction_faculty_subjectarea");
    } elseif ($do_subjects) {
        $conn->query("DELETE FROM junction_faculty_subject");
    }

    // Mark flush as executed
    $stmt = $conn->prepare("UPDATE flush_schedules SET executed = 1, executed_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $flush_id);
    $stmt->execute();
    $stmt->close();

    // Log admin action
    $notes = implode(', ', $executed_items);
    $log_stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, action, target_name, notes) VALUES (?, 'system_flush', 'System Flush Executed', ?)");
    $log_stmt->bind_param('is', $admin_id, $notes);
    $log_stmt->execute();
    $log_stmt->close();

    return $executed_items;
}
