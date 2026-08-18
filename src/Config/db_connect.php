<?php
error_reporting(0);
ini_set('display_errors', 0);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/load-env.php';
loadEnv();

require_once __DIR__ . '/config.php';

// ── Centralized logging (Monolog) ──────────────────────────
// Convert all PHP errors/exceptions into structured log entries.
error_reporting(E_ALL);
ini_set('display_errors', 0);

use LumineSense\Services\Logger;

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    // Respect @ suppression (e.g. @new mysqli(...) on DB connect failure).
    if (error_reporting() === 0) {
        return false;
    }
    // Never fatal if the logger isn't autoloadable (e.g. stale vendor/ on deploy).
    if (!class_exists('LumineSense\\Services\\Logger')) {
        return false;
    }
    $map = [
        E_WARNING           => 'warning',
        E_NOTICE            => 'notice',
        E_USER_WARNING      => 'warning',
        E_USER_NOTICE       => 'notice',
        E_DEPRECATED        => 'info',
        E_USER_DEPRECATED   => 'info',
        E_RECOVERABLE_ERROR => 'error',
    ];
    $level = $map[$severity] ?? 'warning';
    try {
        Logger::{$level}('PHP ' . strtoupper($level), [
            'message' => $message,
            'file'    => $file,
            'line'    => $line,
        ]);
    } catch (\Throwable $e) {
        // Never let the logger break the request.
    }
    return true;
});

set_exception_handler(function (\Throwable $e): void {
    if (class_exists('LumineSense\\Services\\Logger')) {
        try {
            Logger::error('Uncaught exception', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);
        } catch (\Throwable $ignored) {
        }
    }
});

register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (class_exists('LumineSense\\Services\\Logger')) {
            try {
                Logger::critical('PHP fatal error', [
                    'message' => $err['message'],
                    'file'    => $err['file'],
                    'line'    => $err['line'],
                ]);
            } catch (\Throwable $ignored) {
            }
        }
    }
});

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'luminesense_db');

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS);
if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'DB connection failed: ' . $conn->connect_error]));
}

// Create database if it doesn't exist
$conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->select_db(DB_NAME);

// Create tables if they don't exist
$conn->query("
    CREATE TABLE IF NOT EXISTS admins (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        last_name      VARCHAR(50)  NOT NULL,
        first_name     VARCHAR(50)  NOT NULL,
        middle_initial VARCHAR(5)   DEFAULT '',
        email          VARCHAR(100) NOT NULL UNIQUE,
        password       VARCHAR(255) NOT NULL,
        is_verified    TINYINT(1)   DEFAULT 0,
        otp_code       VARCHAR(6)   DEFAULT NULL,
        otp_expires_at DATETIME     DEFAULT NULL,
        created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS faculty (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        last_name      VARCHAR(50)  NOT NULL,
        first_name     VARCHAR(50)  NOT NULL,
        middle_initial VARCHAR(5)   DEFAULT '',
        email          VARCHAR(100) NOT NULL UNIQUE,
        password       VARCHAR(255) NOT NULL,
        is_verified    TINYINT(1)   DEFAULT 0,
        approved_by    INT          DEFAULT NULL,
        approved_at    TIMESTAMP    NULL DEFAULT NULL,
        otp_code       VARCHAR(6)   DEFAULT NULL,
        otp_expires_at DATETIME     DEFAULT NULL,
        created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (approved_by) REFERENCES admins(id) ON DELETE SET NULL
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS classrooms (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        room_name   VARCHAR(100) NOT NULL,
        room_size   ENUM('small','medium','large') DEFAULT 'medium',
        description TEXT         DEFAULT '',
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS schedules (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        classroom_id INT NOT NULL,
        day_of_week  ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
        start_time   TIME NOT NULL,
        end_time     TIME NOT NULL,
        created_by   INT NOT NULL,
        FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES faculty(id) ON DELETE CASCADE
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS lighting_logs (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        classroom_id  INT NOT NULL,
        faculty_id    INT DEFAULT NULL,
        event_type    ENUM('on','off','security_alert','gesture','schedule') NOT NULL,
        triggered_by  VARCHAR(100) DEFAULT '',
        event_time    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
        FOREIGN KEY (faculty_id)   REFERENCES faculty(id) ON DELETE SET NULL,
        INDEX idx_classroom_event (classroom_id, id)
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS extension_requests (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        schedule_id  INT NOT NULL,
        faculty_id   INT NOT NULL,
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        extend_mins  INT DEFAULT 30,
        status       ENUM('pending','approved','rejected') DEFAULT 'pending',
        reviewed_by  INT DEFAULT NULL,
        reviewed_at  TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
        FOREIGN KEY (faculty_id)  REFERENCES faculty(id) ON DELETE CASCADE,
        FOREIGN KEY (reviewed_by) REFERENCES admins(id) ON DELETE SET NULL
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS faculty_permissions (
        faculty_id       INT PRIMARY KEY,
        lighting_control TINYINT(1) DEFAULT 1,
        gesture_control  TINYINT(1) DEFAULT 1,
        FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$conn->set_charset('utf8mb4');
// Set MySQL session timezone to Asia/Manila (UTC+8) so TIMESTAMP columns
// (event_time, pir_since, created_at, etc.) store correct local time
$conn->query("SET time_zone = '+08:00'");

// ── Runtime column migrations (safe – only adds if missing) ──────────────────
// Helper to safely add a column if it doesn't exist
function addColIfMissing($conn, $table, $column, $definition) {
    $chk = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
// light_status on classrooms (used by dashboard poll + Arduino PIR webhook)
addColIfMissing($conn, 'classrooms', 'light_status', "ENUM('on','off') DEFAULT 'off'");
addColIfMissing($conn, 'classrooms', 'row1_status', "ENUM('on','off') DEFAULT 'off'");
addColIfMissing($conn, 'classrooms', 'row2_status', "ENUM('on','off') DEFAULT 'off'");
addColIfMissing($conn, 'classrooms', 'row3_status', "ENUM('on','off') DEFAULT 'off'");
// pir_occupied flag – set by PIR webhook, cleared when occupancy ends
addColIfMissing($conn, 'classrooms', 'pir_occupied', 'TINYINT(1) DEFAULT 0');
// pir_occupied_since – when occupancy started (drives System Uptime)
addColIfMissing($conn, 'classrooms', 'pir_since', 'TIMESTAMP NULL DEFAULT NULL');
// light_override – 1 when a human manually toggled the lights via the UI
// (faculty/admin/gesture). Auto-off (cron, PIR inactivity) must NOT revert it.
addColIfMissing($conn, 'classrooms', 'light_override', 'TINYINT(1) DEFAULT 0');
// extended_until on schedules (used by active-schedule query in faculty-home.php)
addColIfMissing($conn, 'schedules', 'extended_until', 'TIME DEFAULT NULL');
addColIfMissing($conn, 'schedules', 'created_by', 'INT DEFAULT NULL');
//pzem live readings on classrooms (updated by api/post_pzem.php)
addColIfMissing($conn, 'classrooms', 'pzem_voltage', 'float DEFAULT NULL');
addColIfMissing($conn, 'classrooms', 'pzem_current', 'float DEFAULT NULL');
addColIfMissing($conn, 'classrooms', 'pzem_power',   'float DEFAULT NULL');
addColIfMissing($conn, 'classrooms', 'pzem_energy',  'float DEFAULT NULL');
// is_prototype marks the rooms that have a physical ESP32+PZEM device attached.
// Missing before, this broke admin-overview / admin-analytics queries and made
// live-pzem.php + analytics.php return "No Device" for every room.
addColIfMissing($conn, 'classrooms', 'is_prototype', "TINYINT(1) DEFAULT 0");
$conn->query("UPDATE classrooms SET is_prototype = 1 WHERE room_name = 'SEL 1'");
$conn->query("UPDATE classrooms SET is_prototype = 1 WHERE pzem_voltage IS NOT NULL AND is_prototype = 0");


//Early adds

// ── Faculty ID image and AI verification columns ──────────────────────────
addColIfMissing($conn, 'faculty', 'id_image', 'VARCHAR(255) DEFAULT NULL');
addColIfMissing($conn, 'faculty', 'faculty_id', 'VARCHAR(20) DEFAULT NULL');
addColIfMissing($conn, 'faculty', 'ai_match_status', "ENUM('matched','mismatched','unreadable') DEFAULT NULL");
addColIfMissing($conn, 'faculty', 'ai_extracted_name', 'VARCHAR(100) DEFAULT NULL');
addColIfMissing($conn, 'faculty', 'ai_confidence_note', 'TEXT DEFAULT NULL');
addColIfMissing($conn, 'faculty', 'department_id', 'INT DEFAULT NULL');
addColIfMissing($conn, 'faculty', 'subject_area_id', 'INT DEFAULT NULL');
addColIfMissing($conn, 'subject_area', 'subject_id', 'INT DEFAULT NULL');

// pin_hash on faculty_permissions (4-digit PIN, bcrypt hashed)
addColIfMissing($conn, 'faculty_permissions', 'pin_hash', 'VARCHAR(255) DEFAULT NULL');

// ── Admin approval columns ─────────────────────────────────────────────────
$colCheck = $conn->query("SHOW COLUMNS FROM admins LIKE 'approved_by'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE admins ADD COLUMN approved_by INT DEFAULT NULL");
}
$colCheck = $conn->query("SHOW COLUMNS FROM admins LIKE 'approved_at'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE admins ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL");
}
$colCheck = $conn->query("SHOW COLUMNS FROM admins LIKE 'id_image'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE admins ADD COLUMN id_image VARCHAR(255) DEFAULT NULL");
}

$conn->query("
    CREATE TABLE IF NOT EXISTS subjects (
        id   INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$conn->query("
    CREATE TABLE IF NOT EXISTS subject_area (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(255) DEFAULT NULL,
        subject_id INT NOT NULL,
        FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

addColIfMissing($conn, 'schedules', 'subject_id', 'INT DEFAULT NULL');
addColIfMissing($conn, 'schedules', 'faculty_id', 'INT DEFAULT NULL');
addColIfMissing($conn, 'schedules', 'updated_at', 'TIMESTAMP NULL DEFAULT NULL');
addColIfMissing($conn, 'schedules', 'updated_by', 'INT DEFAULT NULL');


// ── Fix FK on schedules.created_by: should point to faculty, not admins ──
$db_name = DB_NAME;
$fk_bad = $conn->query("
    SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = '$db_name' AND TABLE_NAME = 'schedules'
      AND COLUMN_NAME = 'created_by' AND REFERENCED_TABLE_NAME = 'admins'
    LIMIT 1
");
$fk_good = $conn->query("
    SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = '$db_name' AND TABLE_NAME = 'schedules'
      AND COLUMN_NAME = 'created_by' AND REFERENCED_TABLE_NAME = 'faculty'
    LIMIT 1
");
if (($fk_bad && $fk_bad->num_rows > 0) || !($fk_good && $fk_good->num_rows > 0)) {
    if ($fk_bad && $row = $fk_bad->fetch_assoc()) {
        $conn->query("ALTER TABLE schedules DROP FOREIGN KEY `{$row['CONSTRAINT_NAME']}`");
    }
    // Fix orphaned created_by values that reference admins instead of faculty
    $conn->query("UPDATE schedules s LEFT JOIN faculty f ON f.id = s.created_by SET s.created_by = s.faculty_id WHERE f.id IS NULL AND s.faculty_id IS NOT NULL");
    // Delete orphaned schedules with no faculty owner
    $conn->query("DELETE s FROM schedules s LEFT JOIN faculty f ON f.id = s.created_by WHERE f.id IS NULL");
    $conn->query("ALTER TABLE schedules ADD FOREIGN KEY (created_by) REFERENCES faculty(id) ON DELETE CASCADE");
}

// ── Admin logs table ──────────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS admin_logs (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        admin_id    INT NOT NULL,
        action      VARCHAR(100) NOT NULL,
        target_name VARCHAR(100) DEFAULT '',
        notes       TEXT DEFAULT '',
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
    )
");

// ── Admin login logs ──────────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS admin_login_logs (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        admin_id   INT NOT NULL,
        login_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
    )
");

// ── Departments table ──────────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS departments (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        name            VARCHAR(255) NOT NULL,
        description     TEXT DEFAULT '',
        status          ENUM('active','inactive') DEFAULT 'active',
        head_faculty_id INT DEFAULT NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (head_faculty_id) REFERENCES faculty(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
// Add 'pending' to the status enum (safe to run repeatedly)
$conn->query("ALTER TABLE departments MODIFY COLUMN status ENUM('active','pending','inactive') NOT NULL DEFAULT 'active'");

// ── Junction tables (many-to-many relationships) ───────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS junction_faculty_department (
        faculty_id    INT NOT NULL,
        department_id INT NOT NULL,
        PRIMARY KEY (faculty_id, department_id),
        FOREIGN KEY (faculty_id)    REFERENCES faculty(id)     ON DELETE CASCADE,
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$conn->query("
    CREATE TABLE IF NOT EXISTS junction_faculty_subject (
        faculty_id INT NOT NULL,
        subject_id INT NOT NULL,
        PRIMARY KEY (faculty_id, subject_id),
        FOREIGN KEY (faculty_id) REFERENCES faculty(id)  ON DELETE CASCADE,
        FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$conn->query("
    CREATE TABLE IF NOT EXISTS junction_faculty_subjectarea (
        faculty_id      INT NOT NULL,
        subject_area_id INT NOT NULL,
        PRIMARY KEY (faculty_id, subject_area_id),
        FOREIGN KEY (faculty_id)      REFERENCES faculty(id)        ON DELETE CASCADE,
        FOREIGN KEY (subject_area_id) REFERENCES subject_area(id)   ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── System settings table ─────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS system_settings (
        setting_key   VARCHAR(64) PRIMARY KEY,
        setting_value TEXT DEFAULT NULL,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");
// Ensure a row for grace_minutes exists
$conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('grace_minutes', '0')");
// PIR inactivity timeout (in minutes) — used by ESP32/Mega firmware
$conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('pir_inactivity_timeout', '5')");

// ── Fix missing FKs on junction tables from prior schema ───────────────────
$junction_tables = [
    'junction_faculty_department' => [
        ['col' => 'faculty_id',    'ref' => 'faculty(id)'],
        ['col' => 'department_id', 'ref' => 'departments(id)'],
    ],
    'junction_faculty_subject' => [
        ['col' => 'faculty_id', 'ref' => 'faculty(id)'],
        ['col' => 'subject_id', 'ref' => 'subjects(id)'],
    ],
    'junction_faculty_subjectarea' => [
        ['col' => 'faculty_id',      'ref' => 'faculty(id)'],
        ['col' => 'subject_area_id', 'ref' => 'subject_area(id)'],
    ],
];
foreach ($junction_tables as $table => $fks) {
    $table_exists = $conn->query("SHOW TABLES LIKE '$table'");
    if (!$table_exists || $table_exists->num_rows === 0) continue;

    foreach ($fks as $fk) {
        $col = $fk['col'];
        $ref = $fk['ref'];
        $check = $conn->query("
            SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = '$db_name' AND TABLE_NAME = '$table'
              AND COLUMN_NAME = '$col' AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1
        ");
        if ($check && $check->num_rows > 0) continue;

        $bad_fks = $conn->query("
            SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = '$db_name' AND TABLE_NAME = '$table'
              AND COLUMN_NAME = '$col' AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        if ($bad_fks) {
            while ($bad = $bad_fks->fetch_assoc()) {
                $conn->query("ALTER TABLE `$table` DROP FOREIGN KEY `{$bad['CONSTRAINT_NAME']}`");
            }
        }
        $conn->query("ALTER TABLE `$table` ADD FOREIGN KEY (`$col`) REFERENCES $ref ON DELETE CASCADE");
    }
}

// ── id_review_queue table (for quarantined ID images) ──────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS id_review_queue (
        id                 INT AUTO_INCREMENT PRIMARY KEY,
        account_type       ENUM('faculty','admin') NOT NULL,
        account_id         INT NOT NULL,
        encrypted_blob     LONGTEXT DEFAULT NULL,
        ai_match_status    ENUM('matched','mismatched','unreadable') DEFAULT NULL,
        ai_extracted_name  VARCHAR(150) DEFAULT NULL,
        ai_confidence_note VARCHAR(255) DEFAULT NULL,
        created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at         DATETIME NOT NULL,
        reviewed           TINYINT(1) DEFAULT 0,
        reviewed_by        INT DEFAULT NULL,
        reviewed_at        DATETIME DEFAULT NULL,
        FOREIGN KEY (reviewed_by) REFERENCES admins(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
// Fix existing enums that might be missing 'matched'
$conn->query("ALTER TABLE id_review_queue MODIFY COLUMN ai_match_status ENUM('matched','mismatched','unreadable') DEFAULT NULL");

// ── is_seeded column for admins (seeded admin = super-admin) ─────────────────
addColIfMissing($conn, 'admins', 'is_seeded', "ENUM('1','0') DEFAULT '0'");

// ── must_change_password column for forced password change on first login ────
addColIfMissing($conn, 'admins', 'must_change_password', "ENUM('1','0') DEFAULT '0'");

// ── Dedicated PIR event log (every state change from Mega GPIO5) ─────────
$conn->query("
    CREATE TABLE IF NOT EXISTS pir_logs (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        classroom_id  INT NOT NULL,
        state         TINYINT(1) NOT NULL COMMENT '1=motion detected, 0=motion stopped',
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
        INDEX idx_pir_logs_room (classroom_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Tilt/manhandling alert log (tilt sensor + piezo buzzer on Mega) ──────
// state=1 is raised as a tilt_alert issue in room_logs (Issues Logged tab).
$conn->query("
    CREATE TABLE IF NOT EXISTS tilt_logs (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        classroom_id  INT NOT NULL,
        state         TINYINT(1) NOT NULL COMMENT '1=tilt detected, 0=sensor settled',
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
        INDEX idx_tilt_logs_room (classroom_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Class lifecycle logs (class_start / class_end) ──────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS class_logs (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        classroom_id  INT NOT NULL,
        event_type    VARCHAR(20) NOT NULL COMMENT 'class_start or class_end',
        triggered_by  VARCHAR(50) DEFAULT NULL,
        notes         VARCHAR(255) DEFAULT NULL,
        event_time    DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
        INDEX idx_class_logs_time (event_time),
        INDEX idx_class_logs_type (event_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Flush schedules table (end-of-semester auto-flush) ──────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS flush_schedules (
        id                 INT AUTO_INCREMENT PRIMARY KEY,
        scheduled_datetime DATETIME NOT NULL,
        flush_schedules    TINYINT(1) DEFAULT 1,
        flush_departments  TINYINT(1) DEFAULT 0,
        flush_subject_areas TINYINT(1) DEFAULT 0,
        flush_subjects     TINYINT(1) DEFAULT 0,
        reminder_dismissed TINYINT(1) DEFAULT 0,
        confirmation_sent  TINYINT(1) DEFAULT 0,
        confirmed          TINYINT(1) DEFAULT 0,
        executed           TINYINT(1) DEFAULT 0,
        executed_at        DATETIME DEFAULT NULL,
        created_by         INT NOT NULL,
        created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── pzem_archive (per-minute energy archive; survives the 7-day raw purge) ─
$conn->query("
    CREATE TABLE IF NOT EXISTS pzem_archive (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        classroom_id  INT NOT NULL,
        archive_date  DATE NOT NULL,
        minute        TIME NOT NULL,
        avg_voltage   FLOAT DEFAULT NULL,
        avg_current   FLOAT DEFAULT NULL,
        avg_power     FLOAT DEFAULT NULL,
        energy_wh     FLOAT DEFAULT NULL,
        reading_count INT NOT NULL DEFAULT 0,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_archive_minute (classroom_id, archive_date, minute),
        KEY archive_date (archive_date),
        FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Per-faculty energy attribution ────────────────────────────────────────────
// power_sessions.faculty_id credits a session to the faculty whose schedule
// overlapped the session window (Option A: first match, else NULL).
addColIfMissing($conn, 'power_sessions', 'faculty_id', 'INT DEFAULT NULL');
$idx = $conn->query("SHOW INDEX FROM power_sessions WHERE Key_name = 'idx_sessions_faculty'");
if ($idx && $idx->num_rows === 0) {
    $conn->query("ALTER TABLE power_sessions ADD INDEX idx_sessions_faculty (faculty_id, session_date)");
}

// faculty_energy_daily — compact per-faculty daily rollup (1 row/faculty/day).
// Feeds 7/14/30-day sparklines + charts with a single indexed query.
$conn->query("
    CREATE TABLE IF NOT EXISTS faculty_energy_daily (
        faculty_id  INT NOT NULL,
        day         DATE NOT NULL,
        energy_wh   DECIMAL(12,3) NOT NULL DEFAULT 0,
        minutes     INT NOT NULL DEFAULT 0,
        sessions    INT NOT NULL DEFAULT 0,
        avg_voltage FLOAT DEFAULT NULL,
        avg_current FLOAT DEFAULT NULL,
        avg_power   FLOAT DEFAULT NULL,
        peak_power  FLOAT DEFAULT NULL,
        PRIMARY KEY (faculty_id, day),
        CONSTRAINT fk_faculty_energy_daily_faculty
            FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── is_archived column for soft-delete archiving ─────────────────────────────
addColIfMissing($conn, 'faculty', 'is_archived', "TINYINT(1) DEFAULT 0");
addColIfMissing($conn, 'admins', 'is_archived', "TINYINT(1) DEFAULT 0");

// ── Semester/academic_year columns for flush_schedules ───────────────────────
addColIfMissing($conn, 'flush_schedules', 'semester', "VARCHAR(20) DEFAULT NULL");
addColIfMissing($conn, 'flush_schedules', 'academic_year', "VARCHAR(20) DEFAULT NULL");

// ── Archive registry (central log of every flush event) ──────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS archive_registry (
        id INT AUTO_INCREMENT PRIMARY KEY,
        semester VARCHAR(20) NOT NULL,
        academic_year VARCHAR(20) NOT NULL,
        flush_type ENUM('semester','pzem','manual') NOT NULL,
        flushed_by INT,
        flushed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        total_archived INT DEFAULT 0,
        total_deleted INT DEFAULT 0,
        total_cleared INT DEFAULT 0,
        notes TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Archived schedules ───────────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS archived_schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        registry_id INT NOT NULL,
        original_id INT,
        classroom_id INT,
        faculty_id INT,
        day_of_week VARCHAR(20),
        start_time TIME,
        end_time TIME,
        extended_until TIME,
        subject_id INT,
        created_by INT,
        archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (registry_id) REFERENCES archive_registry(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Archived departments ─────────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS archived_departments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        registry_id INT NOT NULL,
        original_id INT,
        name VARCHAR(100),
        head_faculty_id INT,
        archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (registry_id) REFERENCES archive_registry(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Archived subject areas ───────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS archived_subject_areas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        registry_id INT NOT NULL,
        original_id INT,
        name VARCHAR(100),
        department_id INT,
        archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (registry_id) REFERENCES archive_registry(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Archived subjects ────────────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS archived_subjects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        registry_id INT NOT NULL,
        original_id INT,
        name VARCHAR(100),
        subject_area_id INT,
        archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (registry_id) REFERENCES archive_registry(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Archived extension requests ──────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS archived_extension_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        registry_id INT NOT NULL,
        original_id INT,
        schedule_id INT,
        faculty_id INT,
        extend_mins INT,
        status VARCHAR(20),
        requested_at DATETIME,
        reviewed_by INT,
        reviewed_at DATETIME,
        archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (registry_id) REFERENCES archive_registry(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Archived subject assignments ─────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS archived_subject_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        registry_id INT NOT NULL,
        original_id INT,
        faculty_id INT,
        subject_id INT,
        archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (registry_id) REFERENCES archive_registry(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── MySQL EVENT: extension_flush_event (runs every Saturday 23:59) ──────────
$eventScheduler = $conn->query("SHOW VARIABLES LIKE 'event_scheduler'")->fetch_assoc();
$eventsEnabled = ($eventScheduler['Value'] ?? '') === 'ON';
if ($eventsEnabled) {
    $evtCheck = $conn->query("SHOW EVENTS LIKE 'extension_flush_event'");
    if ($evtCheck && $evtCheck->num_rows === 0) {
        @$conn->query("
            CREATE EVENT extension_flush_event
            ON SCHEDULE EVERY 1 WEEK
            STARTS CURRENT_TIMESTAMP + INTERVAL (6 - WEEKDAY(CURRENT_TIMESTAMP)) DAY + INTERVAL 23 HOUR + INTERVAL 59 MINUTE
            ON COMPLETION PRESERVE
            DO BEGIN
                UPDATE schedules SET extended_until = NULL WHERE extended_until IS NOT NULL;
                DELETE FROM extension_requests;
                INSERT INTO admin_logs (admin_id, action, target_name, notes)
                VALUES (0, 'extension_flush', 'Extensions Cleared', 'Auto-cleared by MySQL EVENT');
            END
        ");
    }
}
