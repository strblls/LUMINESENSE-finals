-- ================================================================
-- MySQL EVENT: auto_approve_extensions_event
-- 
-- Auto-approves pending extension requests for today's classes
-- when the grace period is enabled in system_settings.
-- 
-- Runs every 1 minute as a database-level fallback so auto-accept
-- works regardless of PHP script execution.
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

    -- Read the grace period from system_settings
    SELECT CAST(setting_value AS UNSIGNED) INTO grace_val
    FROM system_settings
    WHERE setting_key = 'grace_minutes';

    -- Only proceed if grace period is enabled (> 0)
    IF grace_val > 0 THEN
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
