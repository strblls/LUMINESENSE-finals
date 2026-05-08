<?php
if (session_status() === PHP_SESSION_NONE) session_start();

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'luminesense_db');

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
        is_verified    TINYINT(1)   DEFAULT 1,
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
        event_type    ENUM('on','off','security_alert','gesture','schedule') NOT NULL,
        triggered_by  VARCHAR(100) DEFAULT '',
        event_time    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
    )
");

$conn->set_charset('utf8mb4');