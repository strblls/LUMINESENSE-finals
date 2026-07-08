<?php
error_reporting(0);
ini_set('display_errors', 0);
if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1') {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'luminesense_db');
} else {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'u805324966_luminesense');
    define('DB_PASS', 'E=P9p4KJc2ksX9T');
    define('DB_NAME', 'u805324966_luminesense_db');
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
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
        FOREIGN KEY (faculty_id)   REFERENCES faculty(id) ON DELETE SET NULL
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

// ── Runtime column migrations (safe – only adds if missing) ──────────────────
// light_status on classrooms (used by dashboard poll + Arduino PIR webhook)
$conn->query("ALTER TABLE classrooms ADD COLUMN IF NOT EXISTS light_status ENUM('on','off') DEFAULT 'off'");
$conn->query("ALTER TABLE classrooms ADD COLUMN IF NOT EXISTS row1_status ENUM('on','off') DEFAULT 'off'");
$conn->query("ALTER TABLE classrooms ADD COLUMN IF NOT EXISTS row2_status ENUM('on','off') DEFAULT 'off'");
$conn->query("ALTER TABLE classrooms ADD COLUMN IF NOT EXISTS row3_status ENUM('on','off') DEFAULT 'off'");
// pir_occupied flag – set by PIR webhook, cleared when occupancy ends
$conn->query("ALTER TABLE classrooms ADD COLUMN IF NOT EXISTS pir_occupied TINYINT(1) DEFAULT 0");
// pir_occupied_since – when occupancy started (drives System Uptime)
$conn->query("ALTER TABLE classrooms ADD COLUMN IF NOT EXISTS pir_since TIMESTAMP NULL DEFAULT NULL");
// extended_until on schedules (used by active-schedule query in faculty-home.php)
$conn->query("ALTER TABLE schedules ADD COLUMN IF NOT EXISTS extended_until TIME DEFAULT NULL");
$conn->query("ALTER TABLE schedules ADD COLUMN IF NOT EXISTS created_by INT DEFAULT NULL");
//pzem live readings on classrooms (updated by api/post_pzem.php)
$conn->query("ALTER TABLE classrooms ADD COLUMN IF NOT EXISTS pzem_voltage float DEFAULT NULL");
$conn->query("ALTER TABLE classrooms ADD COLUMN IF NOT EXISTS pzem_current float DEFAULT NULL");
$conn->query("ALTER TABLE classrooms ADD COLUMN IF NOT EXISTS pzem_power   float DEFAULT NULL");
$conn->query("ALTER TABLE classrooms ADD COLUMN IF NOT EXISTS pzem_energy  float DEFAULT NULL");


//Early adds
// ── Departments status column (added later — safe ADD IF NOT EXISTS) ──────
$conn->query("ALTER TABLE departments ADD COLUMN IF NOT EXISTS status ENUM('active','pending','inactive') NOT NULL DEFAULT 'active'");

// ── Faculty ID image and AI verification columns ──────────────────────────
$conn->query("ALTER TABLE faculty ADD COLUMN IF NOT EXISTS id_image VARCHAR(255) DEFAULT NULL");
$conn->query("ALTER TABLE faculty ADD COLUMN IF NOT EXISTS faculty_id VARCHAR(20) DEFAULT NULL");
$conn->query("ALTER TABLE faculty ADD COLUMN IF NOT EXISTS ai_match_status ENUM('matched','mismatched','unreadable') DEFAULT NULL");
$conn->query("ALTER TABLE faculty ADD COLUMN IF NOT EXISTS ai_extracted_name VARCHAR(100) DEFAULT NULL");
$conn->query("ALTER TABLE faculty ADD COLUMN IF NOT EXISTS ai_confidence_note TEXT DEFAULT NULL");
$conn->query("ALTER TABLE faculty ADD COLUMN IF NOT EXISTS department_id INT DEFAULT NULL");
$conn->query("ALTER TABLE faculty ADD COLUMN IF NOT EXISTS subject_area_id INT DEFAULT NULL");
$conn->query("ALTER TABLE subject_area ADD COLUMN IF NOT EXISTS subject_id INT DEFAULT NULL");

// pin_hash on faculty_permissions (4-digit PIN, bcrypt hashed)
$conn->query("ALTER TABLE faculty_permissions ADD COLUMN IF NOT EXISTS pin_hash VARCHAR(255) DEFAULT NULL");

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

$conn->query("ALTER TABLE schedules ADD COLUMN IF NOT EXISTS subject_id INT DEFAULT NULL");
$conn->query("ALTER TABLE schedules ADD COLUMN IF NOT EXISTS faculty_id INT DEFAULT NULL");
$conn->query("ALTER TABLE schedules ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL");
$conn->query("ALTER TABLE schedules ADD COLUMN IF NOT EXISTS updated_by INT DEFAULT NULL");


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