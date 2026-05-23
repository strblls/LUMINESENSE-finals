-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 22, 2026 at 11:07 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `luminesense_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_initial` varchar(5) DEFAULT '',
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `otp_code` varchar(6) DEFAULT NULL,
  `otp_expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `last_name`, `first_name`, `middle_initial`, `email`, `password`, `is_verified`, `otp_code`, `otp_expires_at`, `created_at`) VALUES
(1, 'Ballesteros', 'Alexandra', 'S', 'admin@luminesense.edu.ph', '$2y$10$TKh8H1.PfunDstripe7nf8uO8OI2LKe9aSLBLQEJmIDLVx/KVH84a6', 1, NULL, NULL, '2026-05-18 07:41:47'),
(2, 'Ballesteros', 'Alexandra', 'S', 'stereoballsgrande@gmail.com', '$2y$10$SHQLFOTo8ecVWhyQr2t8q.oYGybslZ0C4d73T2jIo1JaYNnV64hY2', 1, NULL, NULL, '2026-05-18 08:08:47');

-- --------------------------------------------------------

--
-- Table structure for table `admin_logs`
--

CREATE TABLE `admin_logs` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) DEFAULT NULL COMMENT 'FK → admins.id (NULL = system)',
  `action` varchar(60) NOT NULL COMMENT 'e.g. room_added, faculty_rejected, admin_login',
  `target_name` varchar(150) DEFAULT '' COMMENT 'Human-readable target: room name, faculty name, etc.',
  `performed_by` varchar(100) DEFAULT '' COMMENT 'Fallback display name if admin_id is NULL',
  `notes` text DEFAULT NULL COMMENT 'Optional detail or reason',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admin_logs`
--

INSERT INTO `admin_logs` (`id`, `admin_id`, `action`, `target_name`, `performed_by`, `notes`, `created_at`) VALUES
(1, 2, 'faculty_approved', 'Jaz Entapa', '', '', '2026-05-22 02:29:31'),
(2, 2, 'room_deleted', '', '', '', '2026-05-22 02:54:03'),
(3, 2, 'room_added', 'SEL 3', '', '', '2026-05-22 02:56:46'),
(4, 2, 'room_deleted', 'SEL 3', '', '', '2026-05-22 03:00:23'),
(5, 2, 'room_added', 'SEL 3', '', '', '2026-05-22 03:09:35'),
(6, 2, 'schedule_created', 'SEL 2 – Friday', '', 'Carl Xander Ballesteros 10:00–12:00', '2026-05-22 03:36:19'),
(7, 2, 'faculty_rejected', 'Jaz Entapa', '', 'Access revoked', '2026-05-22 03:43:55'),
(8, 2, 'faculty_approved', 'Jaz Entapa', '', '', '2026-05-22 03:44:05'),
(9, 2, 'schedule_created', 'SEL 2 – Friday', '', 'Jaz Entapa 16:30–17:30', '2026-05-22 08:29:10');

-- --------------------------------------------------------

--
-- Table structure for table `classrooms`
--

CREATE TABLE `classrooms` (
  `id` int(11) NOT NULL,
  `room_name` varchar(100) NOT NULL,
  `room_size` enum('small','medium','large') DEFAULT 'medium',
  `description` text DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `light_status` varchar(10) NOT NULL DEFAULT 'off'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `classrooms`
--

INSERT INTO `classrooms` (`id`, `room_name`, `room_size`, `description`, `created_at`, `light_status`) VALUES
(3, 'SEL 1', 'medium', 'NEar uhuhu', '2026-05-20 04:25:45', 'off'),
(4, 'SEL 2', 'medium', 'NA', '2026-05-21 06:25:38', 'off'),
(8, 'SEL 3', 'medium', 'Near Canteen', '2026-05-22 03:09:35', 'off');

-- --------------------------------------------------------

--
-- Table structure for table `extension_requests`
--

CREATE TABLE `extension_requests` (
  `id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `extend_mins` int(11) DEFAULT 30,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `faculty`
--

CREATE TABLE `faculty` (
  `id` int(11) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_initial` varchar(5) DEFAULT '',
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `otp_code` varchar(6) DEFAULT NULL,
  `otp_expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `faculty`
--

INSERT INTO `faculty` (`id`, `last_name`, `first_name`, `middle_initial`, `email`, `password`, `is_verified`, `approved_by`, `approved_at`, `otp_code`, `otp_expires_at`, `created_at`) VALUES
(1, 'Ballesteros', 'Carl Xander', 'S', 'safetylovender@gmail.com', '$2y$10$rzQ24zpI6tQEcEYlUwm7leqifeZp9RinZG0wmeC1BuKx4aZlUPKXC', 1, 2, '2026-05-19 06:56:37', NULL, NULL, '2026-05-19 06:41:15'),
(2, 'Entapa', 'Jaz', 'E', 'johngemare@gmail.com', '$2y$10$7dPWtA5ztjzHviPku4sWgugYEM0UJSiAsqtUo/j7W6tP8K0E5mZp.', 1, 2, '2026-05-22 03:43:59', NULL, NULL, '2026-05-19 10:12:27');

-- --------------------------------------------------------

--
-- Table structure for table `faculty_permissions`
--

CREATE TABLE `faculty_permissions` (
  `faculty_id` int(11) NOT NULL,
  `lighting_control` tinyint(1) DEFAULT 1,
  `gesture_control` tinyint(1) DEFAULT 1,
  `request_access` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lighting_logs`
--

CREATE TABLE `lighting_logs` (
  `id` int(11) NOT NULL,
  `classroom_id` int(11) NOT NULL,
  `faculty_id` int(11) DEFAULT NULL,
  `event_type` enum('on','off','gesture','schedule','security_alert') NOT NULL,
  `triggered_by` varchar(50) DEFAULT 'sensor',
  `event_time` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `room_logs`
--

CREATE TABLE `room_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `event_type` varchar(50) NOT NULL,
  `room_name` varchar(100) NOT NULL,
  `triggered_by` varchar(100) NOT NULL DEFAULT 'system',
  `event_time` datetime NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `room_logs`
--

INSERT INTO `room_logs` (`id`, `event_type`, `room_name`, `triggered_by`, `event_time`, `notes`) VALUES
(1, 'room_added', 'SEL 3', 'Alexandra Ballesteros', '2026-05-22 10:56:46', ''),
(2, 'room_deleted', 'SEL 3', 'Alexandra Ballesteros', '2026-05-22 11:00:23', ''),
(3, 'room_added', 'SEL 3', 'Alexandra Ballesteros', '2026-05-22 11:09:35', '');

-- --------------------------------------------------------

--
-- Table structure for table `schedules`
--

CREATE TABLE `schedules` (
  `id` int(11) NOT NULL,
  `classroom_id` int(11) NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `extended_until` time DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `schedules`
--

INSERT INTO `schedules` (`id`, `classroom_id`, `day_of_week`, `start_time`, `end_time`, `extended_until`, `created_by`, `created_at`) VALUES
(1, 4, 'Monday', '08:00:00', '12:00:00', NULL, 1, '2026-05-22 00:24:53'),
(2, 4, 'Friday', '10:00:00', '12:00:00', NULL, 1, '2026-05-22 03:36:19'),
(3, 4, 'Friday', '16:30:00', '17:30:00', NULL, 2, '2026-05-22 08:29:10');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `admin_logs`
--
ALTER TABLE `admin_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `action` (`action`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `classrooms`
--
ALTER TABLE `classrooms`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `extension_requests`
--
ALTER TABLE `extension_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `schedule_id` (`schedule_id`),
  ADD KEY `faculty_id` (`faculty_id`),
  ADD KEY `reviewed_by` (`reviewed_by`);

--
-- Indexes for table `faculty`
--
ALTER TABLE `faculty`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `approved_by` (`approved_by`);

--
-- Indexes for table `faculty_permissions`
--
ALTER TABLE `faculty_permissions`
  ADD PRIMARY KEY (`faculty_id`);

--
-- Indexes for table `lighting_logs`
--
ALTER TABLE `lighting_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `classroom_id` (`classroom_id`),
  ADD KEY `faculty_id` (`faculty_id`);

--
-- Indexes for table `room_logs`
--
ALTER TABLE `room_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `schedules`
--
ALTER TABLE `schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `classroom_id` (`classroom_id`),
  ADD KEY `created_by` (`created_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `admin_logs`
--
ALTER TABLE `admin_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `classrooms`
--
ALTER TABLE `classrooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `extension_requests`
--
ALTER TABLE `extension_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `faculty`
--
ALTER TABLE `faculty`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `lighting_logs`
--
ALTER TABLE `lighting_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `room_logs`
--
ALTER TABLE `room_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `schedules`
--
ALTER TABLE `schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin_logs`
--
ALTER TABLE `admin_logs`
  ADD CONSTRAINT `admin_logs_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `extension_requests`
--
ALTER TABLE `extension_requests`
  ADD CONSTRAINT `extension_requests_ibfk_1` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `extension_requests_ibfk_2` FOREIGN KEY (`faculty_id`) REFERENCES `faculty` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `extension_requests_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `faculty`
--
ALTER TABLE `faculty`
  ADD CONSTRAINT `faculty_ibfk_1` FOREIGN KEY (`approved_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `faculty_permissions`
--
ALTER TABLE `faculty_permissions`
  ADD CONSTRAINT `faculty_permissions_ibfk_1` FOREIGN KEY (`faculty_id`) REFERENCES `faculty` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `lighting_logs`
--
ALTER TABLE `lighting_logs`
  ADD CONSTRAINT `lighting_logs_ibfk_1` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lighting_logs_ibfk_2` FOREIGN KEY (`faculty_id`) REFERENCES `faculty` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `schedules`
--
ALTER TABLE `schedules`
  ADD CONSTRAINT `schedules_ibfk_1` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `schedules_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
