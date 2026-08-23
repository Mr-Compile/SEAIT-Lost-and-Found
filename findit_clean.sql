-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 29, 2026 at 03:06 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `findit`
--

-- --------------------------------------------------------

--
-- Table structure for table `chats`
--

CREATE TABLE `chats` (
  `chat_id` int(11) NOT NULL,
  `match_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `claim_requests`
--

CREATE TABLE `claim_requests` (
  `claim_id` int(11) NOT NULL,
  `match_id` int(11) NOT NULL,
  `claimant_user_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `request_message` text DEFAULT NULL,
  `status` enum('pending','approved','ready_for_pickup','claimed','rejected') NOT NULL DEFAULT 'pending',
  `admin_user_id` int(11) DEFAULT NULL,
  `admin_response` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `finder_leaderboard`
--

CREATE TABLE `finder_leaderboard` (
  `id` int(11) NOT NULL,
  `finder_name` varchar(100) NOT NULL,
  `total_points` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `itemmatches`
--

CREATE TABLE `itemmatches` (
  `match_id` int(11) NOT NULL,
  `lost_item_id` int(11) NOT NULL,
  `found_item_id` int(11) NOT NULL,
  `similarity_score` decimal(5,2) DEFAULT NULL CHECK (`similarity_score` between 0 and 100),
  `matched_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','accepted','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `admin_user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `items`
--

CREATE TABLE `items` (
  `item_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(50) NOT NULL,
  `description` varchar(100) DEFAULT NULL,
  `category` varchar(20) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `item_image` varchar(200) DEFAULT NULL,
  `status` enum('lost','found','claimed','unclaimed','redistributed') NOT NULL,
  `report_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `hold_expiry` date DEFAULT NULL,
  `finder_name` varchar(100) DEFAULT NULL,
  `finder_phone` varchar(13) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `items` (`item_id`, `user_id`, `title`, `description`, `category`, `location`, `item_image`, `status`, `report_date`, `hold_expiry`, `finder_name`, `finder_phone`, `updated_at`) VALUES
(1, 1, 'Black Wallet', 'Leather wallet with IDs inside', 'Accessories', 'Market Area', 'wallet1.jpg', 'found', '2026-03-29 13:11:36', '2026-04-29', NULL, NULL, '2026-03-29 13:14:36'),
(2, 1, 'iPhone 11', 'Black iPhone with cracked screen', 'Electronics', 'Bus Terminal', 'iphone11.jpg', 'found', '2026-03-29 13:11:36', '2026-04-05', 'Juan Dela Cruz', '09123456789', '2026-03-29 13:11:36'),
(3, 1, 'School ID', 'ID with blue lace', 'Documents', 'High School Gate', 'id1.jpg', 'found', '2026-03-29 13:11:36', '2026-04-29', NULL, NULL, '2026-03-29 13:14:34'),
(4, 1, 'Backpack', 'Red Nike backpack with books', 'Bags', 'Park Area', 'bag1.jpg', 'found', '2026-03-29 13:11:36', '2026-04-03', 'Maria Santos', '09987654321', '2026-03-29 13:11:36'),
(5, 1, 'Silver Ring', 'Engraved silver ring', 'Jewelry', 'Public Market', 'ring1.jpg', 'found', '2026-03-29 13:11:36', '2026-04-29', NULL, NULL, '2026-03-29 13:14:31'),
(6, 1, 'Umbrella', 'Blue foldable umbrella', 'Accessories', 'Jeepney Stop', 'umbrella1.jpg', 'found', '2026-03-29 13:11:36', '2026-04-29', 'Pedro Reyes', '09111222333', '2026-03-29 13:14:23'),
(7, 1, 'Laptop Bag', 'Black bag with charger inside', 'Electronics', 'Internet Cafe', 'laptopbag.jpg', 'found', '2026-03-29 13:11:36', '2026-04-29', 'Ana Cruz', '09223334444', '2026-03-29 13:14:26'),
(8, 1, 'Watch', 'Digital Casio watch', 'Accessories', 'Gym Area', 'watch1.jpg', 'found', '2026-03-29 13:11:36', '2026-04-29', 'Mark Lee', '09334445555', '2026-03-29 13:14:20'),
(9, 1, 'Keys', '3 keys with red keychain', 'Miscellaneous', 'Parking Lot', 'keys1.jpg', 'found', '2026-03-29 13:11:36', '2026-04-29', 'John Lim', '09445556666', '2026-03-29 13:14:15'),
(10, 1, 'Tablet', 'Samsung tablet white', 'Electronics', 'Library', 'tablet1.jpg', 'found', '2026-03-29 13:11:36', '2026-04-29', 'Library Staff', '09556667777', '2026-03-29 13:14:28'),
(11, 2, 'Iphone 11', 'black', 'Electronics', NULL, 'item_69c9f67ee7b5f_1774843518.jpg', 'lost', '2026-03-30 04:05:19', NULL, NULL, NULL, '2026-03-30 04:05:19');

-- --------------------------------------------------------

--
-- Table structure for table `leaderboard`
--

CREATE TABLE `leaderboard` (
  `leaderboard_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `activity_type` enum('item_posted','successful_match','help_given','item_claimed','item_returned') NOT NULL,
  `points` int(11) DEFAULT 0 CHECK (`points` >= 0),
  `item_id` int(11) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `type` enum('email','sms','system') DEFAULT 'system',
  `message` text NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `redistribution_records`
--

CREATE TABLE `redistribution_records` (
  `redistribution_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `redistribution_method` enum('donation','give_to_finder','dispose','store_again','repurposing') NOT NULL,
  `redistribution_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `admin_user_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recipient_info` varchar(255) DEFAULT NULL,
  `status` enum('pending','completed','cancelled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sms_logs`
--

CREATE TABLE `sms_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `phone_number` varchar(15) NOT NULL,
  `message` text NOT NULL,
  `status` enum('success','failed') NOT NULL,
  `details` text DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `setting_type` varchar(50) DEFAULT 'text',
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `updated_at`, `created_at`) VALUES
(1, 'site_name', 'SEAIT - Lost & Found System', 'text', 'The name of the website displayed in headers and titles', '2026-03-19 16:24:50', '2025-11-04 09:32:44'),
(2, 'site_description', 'A comprehensive lost and found management system for connecting people with their lost belongings', 'textarea', 'The main description of the website', '2026-03-19 16:24:50', '2025-11-04 09:32:44'),
(3, 'contact_email', 'admin@seait.edu.gov', 'email', 'Main contact email for the system', '2026-03-19 16:24:50', '2025-11-04 09:32:44'),
(6, 'site_tagline', 'Find What\'s Lost. Return What\'s Found.', 'text', 'Main tagline displayed on the homepage', '2026-03-19 16:24:50', '2025-11-04 09:32:44'),
(10, 'hero_image', 'uploads/settings/hero_1762585637.jpg', 'image', 'Hero section background image for index page', '2026-03-19 16:24:50', '2025-11-04 09:32:44'),
(11, 'login_image', 'uploads/settings/login_1762585637.jpg', 'image', 'Background image for login page left panel', '2026-03-19 16:24:50', '2025-11-04 09:32:44'),
(12, 'register_image', 'uploads/settings/register_1762585637.jpg', 'image', 'Background image for register page left panel', '2026-03-19 16:24:50', '2025-11-04 09:32:44'),
(13, 'site_logo', '', 'image', 'Site logo used in header, sidebar, and loading states', '2026-03-29 15:00:00', '2026-03-29 15:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `full_name` varchar(30) NOT NULL,
  `email` varchar(30) NOT NULL,
  `phone` varchar(10) DEFAULT NULL,
  `password_hash` varchar(100) NOT NULL,
  `role` enum('claimant','admin') NOT NULL DEFAULT 'claimant',
  `mfa_secret` varchar(64) DEFAULT NULL,
  `mfa_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `mfa_backup_codes_downloaded_at` datetime DEFAULT NULL,
  `image_path` varchar(200) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `email`, `phone`, `password_hash`, `role`, `mfa_secret`, `mfa_enabled`, `mfa_backup_codes_downloaded_at`, `image_path`, `created_at`) VALUES
(1, 'Administrator', 'michaeltorillo08@gmail.com', '9513491961', '$2y$10$EuDXswLKTe.EbwTLIM8WwuOi328epcQPqNEg.xU4q6oYWs7nJkw5.', 'admin', '5UT42MB27PLWETZ5', 1, '2026-03-28 21:47:01', 'uploads/profile_690eec32ed38e.jpg', '2025-10-01 14:46:40'),
(2, 'Mark Lawrence Molinas', 'michaeltorillo25@gmail.com', '9265852171', '$2y$10$JLf7soYzi7/Dk/2m0g8r0O0siG7aX55qbW1Ne1WZ1wd//mKU3a5C2', 'claimant', 'JY4WJFO2PAHZISEV', 1, '2026-03-28 21:23:15', NULL, '2025-11-13 01:20:04');

-- --------------------------------------------------------

--
-- Table structure for table `user_chat_pins`
--

CREATE TABLE `user_chat_pins` (
  `pin_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `pin_hash` varchar(255) NOT NULL,
  `failed_attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_chat_pins`
--

INSERT INTO `user_chat_pins` (`pin_id`, `user_id`, `pin_hash`, `failed_attempts`, `locked_until`, `created_at`, `updated_at`) VALUES
(1, 2, '$2y$10$5ycdUhGlZXqY296/JCY6EuR.6SLjqWX9mjyeSqYzUUKh6d/taghhi', 0, NULL, '2026-03-29 12:07:44', '2026-03-29 13:44:15');

-- --------------------------------------------------------

--
-- Table structure for table `user_mfa_backup_codes`
--

CREATE TABLE `user_mfa_backup_codes` (
  `backup_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_password_resets`
--

CREATE TABLE `user_password_resets` (
  `reset_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `selector` char(16) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `otp_hash` varchar(255) DEFAULT NULL,
  `otp_expires_at` datetime DEFAULT NULL,
  `otp_verified_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `request_ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_remember_tokens`
--

CREATE TABLE `user_remember_tokens` (
  `token_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `selector` char(18) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `created_ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `chats`
--
ALTER TABLE `chats`
  ADD PRIMARY KEY (`chat_id`),
  ADD KEY `match_id` (`match_id`),
  ADD KEY `sender_id` (`sender_id`),
  ADD KEY `receiver_id` (`receiver_id`);

--
-- Indexes for table `claim_requests`
--
ALTER TABLE `claim_requests`
  ADD PRIMARY KEY (`claim_id`),
  ADD KEY `match_id` (`match_id`),
  ADD KEY `claimant_user_id` (`claimant_user_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `admin_user_id` (`admin_user_id`);

--
-- Indexes for table `finder_leaderboard`
--
ALTER TABLE `finder_leaderboard`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_finder_name` (`finder_name`),
  ADD KEY `idx_total_points` (`total_points`),
  ADD KEY `idx_points` (`total_points`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `itemmatches`
--
ALTER TABLE `itemmatches`
  ADD PRIMARY KEY (`match_id`),
  ADD UNIQUE KEY `uq_match` (`lost_item_id`,`found_item_id`,`matched_at`),
  ADD KEY `found_item_id` (`found_item_id`),
  ADD KEY `idx_itemmatches_status` (`status`),
  ADD KEY `itemmatches_ibfk_admin` (`admin_user_id`);

--
-- Indexes for table `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_location` (`location`);
ALTER TABLE `items` ADD FULLTEXT KEY `idx_title_description` (`title`,`description`);

--
-- Indexes for table `leaderboard`
--
ALTER TABLE `leaderboard`
  ADD PRIMARY KEY (`leaderboard_id`),
  ADD UNIQUE KEY `uq_leader` (`user_id`,`activity_type`,`item_id`,`created_at`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `redistribution_records`
--
ALTER TABLE `redistribution_records`
  ADD PRIMARY KEY (`redistribution_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `admin_user_id` (`admin_user_id`),
  ADD KEY `idx_redistribution_method` (`redistribution_method`),
  ADD KEY `idx_redistribution_date` (`redistribution_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_sent_at` (`sent_at`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_id`),
  ADD UNIQUE KEY `unique_setting_key` (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `phone` (`phone`);

--
-- Indexes for table `user_chat_pins`
--
ALTER TABLE `user_chat_pins`
  ADD PRIMARY KEY (`pin_id`),
  ADD UNIQUE KEY `uniq_user_chat_pin` (`user_id`),
  ADD KEY `idx_locked_until` (`locked_until`);

--
-- Indexes for table `user_mfa_backup_codes`
--
ALTER TABLE `user_mfa_backup_codes`
  ADD PRIMARY KEY (`backup_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_used_at` (`used_at`);

--
-- Indexes for table `user_password_resets`
--
ALTER TABLE `user_password_resets`
  ADD PRIMARY KEY (`reset_id`),
  ADD UNIQUE KEY `uniq_selector` (`selector`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_expires_at` (`expires_at`),
  ADD KEY `idx_used_at` (`used_at`);

--
-- Indexes for table `user_remember_tokens`
--
ALTER TABLE `user_remember_tokens`
  ADD PRIMARY KEY (`token_id`),
  ADD UNIQUE KEY `uniq_selector` (`selector`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_expires_at` (`expires_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `chats`
--
ALTER TABLE `chats`
  MODIFY `chat_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `claim_requests`
--
ALTER TABLE `claim_requests`
  MODIFY `claim_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `finder_leaderboard`
--
ALTER TABLE `finder_leaderboard`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `itemmatches`
--
ALTER TABLE `itemmatches`
  MODIFY `match_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `items`
--
ALTER TABLE `items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leaderboard`
--
ALTER TABLE `leaderboard`
  MODIFY `leaderboard_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `redistribution_records`
--
ALTER TABLE `redistribution_records`
  MODIFY `redistribution_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `sms_logs`
--
ALTER TABLE `sms_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `user_chat_pins`
--
ALTER TABLE `user_chat_pins`
  MODIFY `pin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `user_mfa_backup_codes`
--
ALTER TABLE `user_mfa_backup_codes`
  MODIFY `backup_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_password_resets`
--
ALTER TABLE `user_password_resets`
  MODIFY `reset_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_remember_tokens`
--
ALTER TABLE `user_remember_tokens`
  MODIFY `token_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `chats`
--
ALTER TABLE `chats`
  ADD CONSTRAINT `chats_ibfk_1` FOREIGN KEY (`match_id`) REFERENCES `itemmatches` (`match_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chats_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `chats_ibfk_3` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `claim_requests`
--
ALTER TABLE `claim_requests`
  ADD CONSTRAINT `fk_claim_admin` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_claim_claimant` FOREIGN KEY (`claimant_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_claim_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_claim_match` FOREIGN KEY (`match_id`) REFERENCES `itemmatches` (`match_id`) ON DELETE CASCADE;

--
-- Constraints for table `itemmatches`
--
ALTER TABLE `itemmatches`
  ADD CONSTRAINT `itemmatches_ibfk_1` FOREIGN KEY (`lost_item_id`) REFERENCES `items` (`item_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `itemmatches_ibfk_2` FOREIGN KEY (`found_item_id`) REFERENCES `items` (`item_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `itemmatches_ibfk_admin` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `items`
--
ALTER TABLE `items`
  ADD CONSTRAINT `items_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `leaderboard`
--
ALTER TABLE `leaderboard`
  ADD CONSTRAINT `leaderboard_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `leaderboard_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_chat_pins`
--
ALTER TABLE `user_chat_pins`
  ADD CONSTRAINT `fk_user_chat_pin_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_mfa_backup_codes`
--
ALTER TABLE `user_mfa_backup_codes`
  ADD CONSTRAINT `fk_user_mfa_backup_codes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Procedures
--
DELIMITER $$

DROP PROCEDURE IF EXISTS `AutoMatchItems`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `AutoMatchItems` ()
BEGIN
  DECLARE v_matches_created INT DEFAULT 0;

  DROP TEMPORARY TABLE IF EXISTS temp_matches;

  CREATE TEMPORARY TABLE temp_matches (
    lost_item_id INT NOT NULL,
    found_item_id INT NOT NULL,
    similarity_score DECIMAL(5,2) NOT NULL,
    PRIMARY KEY (lost_item_id, found_item_id)
  );

  INSERT INTO temp_matches (lost_item_id, found_item_id, similarity_score)
  SELECT
    scored.lost_item_id,
    scored.found_item_id,
    scored.similarity_score
  FROM (
    SELECT
      l.item_id AS lost_item_id,
      f.item_id AS found_item_id,
      ROUND(
        (CASE
          WHEN LOWER(TRIM(l.title)) = LOWER(TRIM(f.title)) THEN 35
          WHEN LOWER(f.title) LIKE CONCAT('%', LOWER(l.title), '%')
            OR LOWER(l.title) LIKE CONCAT('%', LOWER(f.title), '%') THEN 28
          WHEN LENGTH(l.title) >= 3 AND (
            LOWER(f.title) LIKE CONCAT('%', LOWER(SUBSTRING(l.title, 1, 3)), '%')
            OR LOWER(l.title) LIKE CONCAT('%', LOWER(SUBSTRING(f.title, 1, 3)), '%')
          ) THEN 18
          ELSE 0
        END)
        +
        (CASE
          WHEN l.description IS NOT NULL AND f.description IS NOT NULL
            AND TRIM(l.description) <> '' AND TRIM(f.description) <> '' THEN
            CASE
              WHEN LOWER(TRIM(l.description)) = LOWER(TRIM(f.description)) THEN 30
              WHEN LOWER(f.description) LIKE CONCAT('%', LOWER(l.description), '%')
                OR LOWER(l.description) LIKE CONCAT('%', LOWER(f.description), '%') THEN 24
              WHEN LENGTH(l.description) >= 5 AND (
                LOWER(f.description) LIKE CONCAT('%', LOWER(SUBSTRING(l.description, 1, 5)), '%')
                OR LOWER(l.description) LIKE CONCAT('%', LOWER(SUBSTRING(f.description, 1, 5)), '%')
              ) THEN 15
              ELSE 0
            END
          ELSE 0
        END)
        +
        (CASE
          WHEN l.category IS NOT NULL AND f.category IS NOT NULL
            AND LOWER(TRIM(l.category)) = LOWER(TRIM(f.category)) THEN 20
          ELSE 0
        END)
        +
        (CASE
          WHEN l.location IS NOT NULL AND f.location IS NOT NULL
            AND TRIM(l.location) <> '' AND TRIM(f.location) <> '' THEN
            CASE
              WHEN LOWER(TRIM(l.location)) = LOWER(TRIM(f.location)) THEN 15
              WHEN LOWER(f.location) LIKE CONCAT('%', LOWER(l.location), '%')
                OR LOWER(l.location) LIKE CONCAT('%', LOWER(f.location), '%') THEN 10
              ELSE 0
            END
          ELSE 0
        END),
        2
      ) AS similarity_score
    FROM items l
    INNER JOIN items f
      ON f.status = 'found'
    LEFT JOIN itemmatches im
      ON im.lost_item_id = l.item_id
      AND im.found_item_id = f.item_id
    WHERE l.status = 'lost'
      AND l.item_id <> f.item_id
      AND im.match_id IS NULL
  ) AS scored
  WHERE scored.similarity_score >= 20;

  INSERT INTO itemmatches (lost_item_id, found_item_id, similarity_score, matched_at, status)
  SELECT
    tm.lost_item_id,
    tm.found_item_id,
    tm.similarity_score,
    CURRENT_TIMESTAMP(),
    'pending'
  FROM temp_matches tm;

  SET v_matches_created = ROW_COUNT();

  SELECT v_matches_created AS matches_created;

  DROP TEMPORARY TABLE IF EXISTS temp_matches;
END$$

DROP PROCEDURE IF EXISTS `AutoMoveExpiredFoundItemsToRedistribution`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `AutoMoveExpiredFoundItemsToRedistribution` ()
BEGIN
  DECLARE v_records_created INT DEFAULT 0;
  DECLARE v_items_updated INT DEFAULT 0;

  DROP TEMPORARY TABLE IF EXISTS temp_expired_found_items;

  CREATE TEMPORARY TABLE temp_expired_found_items (
    item_id INT NOT NULL PRIMARY KEY
  );

  INSERT INTO temp_expired_found_items (item_id)
  SELECT i.item_id
  FROM items i
  LEFT JOIN redistribution_records rr
    ON rr.item_id = i.item_id
    AND rr.status IN ('pending', 'completed')
  WHERE i.status = 'found'
    AND i.hold_expiry IS NOT NULL
    AND i.hold_expiry < CURRENT_DATE()
    AND rr.redistribution_id IS NULL;

  INSERT INTO redistribution_records (
    item_id,
    redistribution_method,
    admin_user_id,
    notes,
    recipient_info,
    status,
    created_at,
    updated_at
  )
  SELECT
    t.item_id,
    'repurposing',
    NULL,
    CONCAT('Auto-moved due to hold expiry on ', CURRENT_DATE()),
    '',
    'pending',
    CURRENT_TIMESTAMP(),
    CURRENT_TIMESTAMP()
  FROM temp_expired_found_items t;

  SET v_records_created = ROW_COUNT();

  UPDATE items i
  INNER JOIN temp_expired_found_items t
    ON t.item_id = i.item_id
  SET i.status = 'redistributed',
    i.updated_at = CURRENT_TIMESTAMP()
  WHERE i.status = 'found';

  SET v_items_updated = ROW_COUNT();

  SELECT
    v_records_created AS redistribution_records_created,
    v_items_updated AS items_moved_to_redistributed;

  DROP TEMPORARY TABLE IF EXISTS temp_expired_found_items;
END$$

DELIMITER ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
