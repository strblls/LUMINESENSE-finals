-- ================================================================
-- MySQL EVENT: auto_approve_extensions_event
-- 
-- Auto-approves pending extension requests for today's classes
-- when the grace period is enabled in system_settings.
--
-- Runs every 1 minute as a database-level fallback so auto-accept
-- works regardless of PHP script execution.
--
-- NOTE: db_connect.php auto-installs this same v2 body on page load
-- (version-guarded); manual install below is only needed if the
-- migration is ever bypassed.
--
-- v2: flags affected classrooms dirty (device refresh), writes an
-- admin_logs audit row, and pins Asia/Manila time for DAYNAME().
--
-- HOW TO INSTALL (via phpMyAdmin):
-- 1. Open phpMyAdmin and select the 'luminesense_db' database
-- 2. Click the "SQL" tab
-- 3. Paste the entire contents of this file
-- 4. Click "Go"
-- 
-- To verify: SHOW EVENTS FROM luminesense_db;
-- ================================================================

DELIMITER //

DROP PROCEDURE IF EXISTS auto_approve_extensions_proc//
CREATE PROCEDURE auto_approve_extensions_proc()
BEGIN
    DECLARE grace_val INT DEFAULT 0;

    -- Deterministic weekday regardless of server TZ
    SET time_zone = '+08:00';

    -- Read the grace period from system_settings
    SELECT CAST(setting_value AS UNSIGNED) INTO grace_val
    FROM system_settings
    WHERE setting_key = 'grace_minutes';

    -- Only proceed if grace period is enabled (> 0)
    IF grace_val > 0 THEN
        -- Flag affected rooms so ESP32s refetch schedules promptly
        UPDATE classrooms c
        JOIN schedules s ON s.classroom_id = c.id
        JOIN extension_requests er ON er.schedule_id = s.id
        SET c.schedule_dirty = 1
        WHERE er.status = 'pending'
          AND s.day_of_week = DAYNAME(CURDATE());
        -- Auto-approve all pending extension requests for today
        UPDATE extension_requests er
        JOIN schedules s ON s.id = er.schedule_id
        SET er.status = 'approved',
            er.reviewed_at = NOW(),
            s.extended_until = ADDTIME(
                COALESCE(s.extended_until, s.end_time),
                SEC_TO_TIME(er.extend_mins * 60)
            )
        WHERE er.status = 'pending'
          AND s.day_of_week = DAYNAME(CURDATE());
        INSERT INTO admin_logs (admin_id, action, target_name, notes)
        VALUES (0, 'extension_auto_approved', 'Extensions Auto-approved', CONCAT('Auto-approved ', ROW_COUNT(), ' extension(s) by MySQL EVENT'));
    END IF;
END//

-- Drop the event first if it already exists
DROP EVENT IF EXISTS auto_approve_extensions_event//

CREATE EVENT auto_approve_extensions_event
ON SCHEDULE EVERY 1 MINUTE
STARTS CURRENT_TIMESTAMP
DO
    CALL auto_approve_extensions_proc()//

DELIMITER ;
