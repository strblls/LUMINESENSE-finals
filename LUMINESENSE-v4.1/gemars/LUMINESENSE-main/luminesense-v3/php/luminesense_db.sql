-- ============================================================
--  luminesense_db.sql
--  Run this once in phpMyAdmin (XAMPP) to create the database
--  and all tables needed for the prototype.
--
--  HOW TO USE:
--  1. Open XAMPP → start Apache and MySQL
--  2. Open browser → go to http://localhost/phpmyadmin
--  3. Click "SQL" tab at the top
--  4. Paste ALL of this file → click "Go"
-- ============================================================

CREATE DATABASE IF NOT EXISTS luminesense_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE luminesense_db;

-- ------------------------------------------------------------
--  TABLE: admins
--  Stores Administrator accounts.
--  is_verified = 0  → pending approval from Info Systems Office
--  is_verified = 1  → approved, can log in
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    last_name       VARCHAR(50)  NOT NULL,
    first_name      VARCHAR(50)  NOT NULL,
    middle_initial  VARCHAR(5)   DEFAULT '',
    email           VARCHAR(100) NOT NULL UNIQUE,
    password        VARCHAR(255) NOT NULL,   -- stored as bcrypt hash, NEVER plain text
    is_verified     TINYINT(1)   DEFAULT 0,  -- 0 = pending, 1 = approved
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
--  TABLE: faculty
--  Stores Faculty Member accounts.
--  is_verified = 0  → pending approval from an Administrator
--  is_verified = 1  → approved, can log in
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS faculty (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    last_name       VARCHAR(50)  NOT NULL,
    first_name      VARCHAR(50)  NOT NULL,
    middle_initial  VARCHAR(5)   DEFAULT '',
    email           VARCHAR(100) NOT NULL UNIQUE,
    password        VARCHAR(255) NOT NULL,   -- stored as bcrypt hash
    is_verified     TINYINT(1)   DEFAULT 0,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
--  TABLE: classrooms
--  Each classroom the system monitors.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS classrooms (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    room_name       VARCHAR(100) NOT NULL,
    room_size       ENUM('small','medium','large') DEFAULT 'medium',
    description     TEXT         DEFAULT '',
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
--  TABLE: lighting_logs
--  Every time a light turns on or off, it is recorded here.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lighting_logs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    classroom_id    INT          NOT NULL,
    event_type      ENUM('on','off','gesture','schedule','security_alert') NOT NULL,
    triggered_by    VARCHAR(50)  DEFAULT 'sensor',  -- sensor / gesture / schedule / manual
    event_time      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (classroom_id) REFERENCES classrooms(id)
);

-- ------------------------------------------------------------
--  TABLE: schedules
--  Timetable entries set by Administrators.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schedules (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    classroom_id    INT          NOT NULL,
    day_of_week     ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
    start_time      TIME         NOT NULL,
    end_time        TIME         NOT NULL,
    created_by      INT          NOT NULL,   -- references admins.id
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (classroom_id) REFERENCES classrooms(id),
    FOREIGN KEY (created_by)   REFERENCES admins(id)
);

-- ============================================================
--  SAMPLE DATA (optional – helps test the prototype quickly)
-- ============================================================

-- One sample classroom
INSERT INTO classrooms (room_name, room_size, description)
VALUES ('Room 101', 'medium', 'Standard classroom – 7m x 9m, 3x3 bulb grid');

-- One sample verified admin (password: Admin@1234)
-- The hash below was generated with password_hash('Admin@1234', PASSWORD_BCRYPT)
INSERT INTO admins (last_name, first_name, middle_initial, email, password, is_verified)
VALUES (
    'Ballesteros', 'Alexandra', 'S',
    'admin@luminesense.edu.ph',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    1
);
-- NOTE: The hash above is the bcrypt of the literal string "password"
-- Change this immediately after testing!
