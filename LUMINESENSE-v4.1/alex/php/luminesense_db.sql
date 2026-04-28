-- Safe to re-run: uses IF NOT EXISTS.

CREATE DATABASE IF NOT EXISTS luminesense_db
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE luminesense_db;

-- ── admins ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admins (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    last_name      VARCHAR(50)  NOT NULL,
    first_name     VARCHAR(50)  NOT NULL,
    middle_initial VARCHAR(5)   DEFAULT '',
    email          VARCHAR(100) NOT NULL UNIQUE,
    password       VARCHAR(255) NOT NULL,
    is_verified    TINYINT(1)   DEFAULT 0,
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- ── faculty ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS faculty (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    last_name      VARCHAR(50)  NOT NULL,
    first_name     VARCHAR(50)  NOT NULL,
    middle_initial VARCHAR(5)   DEFAULT '',
    email          VARCHAR(100) NOT NULL UNIQUE,
    password       VARCHAR(255) NOT NULL,
    is_verified    TINYINT(1)   DEFAULT 0,
    approved_by    INT          DEFAULT NULL,  -- FK to admins.id
    approved_at    TIMESTAMP    NULL DEFAULT NULL,
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (approved_by) REFERENCES admins(id) ON DELETE SET NULL
);

-- ── classrooms ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS classrooms (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    room_name   VARCHAR(100) NOT NULL,
    room_size   ENUM('small','medium','large') DEFAULT 'medium',
    description TEXT         DEFAULT '',
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- ── schedules ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS schedules (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    classroom_id INT NOT NULL,
    day_of_week  ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
    start_time   TIME NOT NULL,
    end_time     TIME NOT NULL,
    created_by   INT NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by)   REFERENCES admins(id)
);

-- ── lighting_logs ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lighting_logs (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    classroom_id INT NOT NULL,
    event_type   ENUM('on','off','gesture','schedule','security_alert') NOT NULL,
    triggered_by VARCHAR(50) DEFAULT 'sensor',
    event_time   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
);

-- ═══════════════════════════════════════════════════════════════
--  SEED DATA  (test accounts — change passwords after testing!)
-- ═══════════════════════════════════════════════════════════════

-- Verified admin  |  email: admin@luminesense.edu.ph  |  password: Admin1234!
INSERT IGNORE INTO admins (last_name, first_name, middle_initial, email, password, is_verified)
VALUES ('Ballesteros', 'Alexandra', 'S', 'admin@luminesense.edu.ph',
        '$2y$10$TKh8H1.PfunDstripe7nf8uO8OI2LKe9aSLBLQEJmIDLVx/KVH84a6', 1);

-- Sample classroom
INSERT IGNORE INTO classrooms (room_name, room_size, description)
VALUES ('Room 101', 'medium', '7m x 9m – 3x3 bulb grid prototype');

-- ───────────────────────────────────────────────────────────────
--  HOW TO GET YOUR OWN HASH:
--  Open any .php file in htdocs and add:
--    echo password_hash('YourPassword123!', PASSWORD_BCRYPT);
--  Copy that output and paste it here for the password column.
-- ───────────────────────────────────────────────────────────────
