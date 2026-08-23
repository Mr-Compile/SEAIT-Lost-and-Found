-- FindIt SQL Backup
-- Generated: 2026-03-30 07:58:58
-- Database: findit

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET FOREIGN_KEY_CHECKS=0;

-- --------------------------------------------------------
-- Table structure for table `chats`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `chats`;
CREATE TABLE `chats` (
  `chat_id` int(11) NOT NULL AUTO_INCREMENT,
  `match_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`chat_id`),
  KEY `match_id` (`match_id`),
  KEY `sender_id` (`sender_id`),
  KEY `receiver_id` (`receiver_id`),
  CONSTRAINT `chats_ibfk_1` FOREIGN KEY (`match_id`) REFERENCES `itemmatches` (`match_id`) ON DELETE CASCADE,
  CONSTRAINT `chats_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `chats_ibfk_3` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `chats`
INSERT INTO `chats` (`chat_id`, `match_id`, `sender_id`, `receiver_id`, `message`, `sent_at`) VALUES ('17', '24', '1', '2', 'v2:esyebQtwxRv+KTYF:gfkNHiZ18ph7IkSfDoQ=:FWO0J17i+HFfTtsfI+gCcA==', '2026-03-30 12:59:06');
INSERT INTO `chats` (`chat_id`, `match_id`, `sender_id`, `receiver_id`, `message`, `sent_at`) VALUES ('18', '24', '2', '1', 'v2:vtLMGJ2r8ivGElKJ:HfRW:j+SQypUnUhYqw2STPVSk9Q==', '2026-03-30 12:59:23');
INSERT INTO `chats` (`chat_id`, `match_id`, `sender_id`, `receiver_id`, `message`, `sent_at`) VALUES ('19', '24', '1', '2', 'v2:d6GI4fjywZQ6hTmU:NMNsGFk1EAdYE853fNOCSR7G8BdrACWeP8gAWYTPkva/xTaMn3JtpFcRWw==:UejpzdlZNCCbff8iU0B8mg==', '2026-03-30 13:00:34');

-- --------------------------------------------------------
-- Table structure for table `claim_object_verification_blocks`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `claim_object_verification_blocks`;
CREATE TABLE `claim_object_verification_blocks` (
  `block_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `match_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `blocked_until` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`block_id`),
  UNIQUE KEY `uq_claim_obj_block` (`user_id`,`match_id`,`item_id`),
  KEY `idx_claim_obj_block_until` (`blocked_until`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `claim_object_verification_blocks`
INSERT INTO `claim_object_verification_blocks` (`block_id`, `user_id`, `match_id`, `item_id`, `blocked_until`, `created_at`, `updated_at`) VALUES ('1', '2', '25', '7', '2026-03-30 13:28:26', '2026-03-30 12:56:05', '2026-03-30 13:23:26');

-- --------------------------------------------------------
-- Table structure for table `claim_object_verification_logs`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `claim_object_verification_logs`;
CREATE TABLE `claim_object_verification_logs` (
  `log_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `match_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `selected_item_id` int(11) NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `attempt_number` tinyint(3) unsigned NOT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT 'unknown',
  `user_agent` varchar(512) NOT NULL DEFAULT 'unknown',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_claim_obj_verify_user_match` (`user_id`,`match_id`),
  KEY `idx_claim_obj_verify_item` (`item_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `claim_object_verification_logs`
INSERT INTO `claim_object_verification_logs` (`log_id`, `user_id`, `match_id`, `item_id`, `selected_item_id`, `is_correct`, `attempt_number`, `ip_address`, `user_agent`, `created_at`) VALUES ('1', '2', '24', '2', '2', '1', '1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 12:53:40');
INSERT INTO `claim_object_verification_logs` (`log_id`, `user_id`, `match_id`, `item_id`, `selected_item_id`, `is_correct`, `attempt_number`, `ip_address`, `user_agent`, `created_at`) VALUES ('2', '2', '25', '7', '11', '0', '1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 12:56:00');
INSERT INTO `claim_object_verification_logs` (`log_id`, `user_id`, `match_id`, `item_id`, `selected_item_id`, `is_correct`, `attempt_number`, `ip_address`, `user_agent`, `created_at`) VALUES ('3', '2', '25', '7', '10', '0', '2', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 12:56:05');
INSERT INTO `claim_object_verification_logs` (`log_id`, `user_id`, `match_id`, `item_id`, `selected_item_id`, `is_correct`, `attempt_number`, `ip_address`, `user_agent`, `created_at`) VALUES ('4', '2', '25', '7', '11', '0', '3', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 13:23:18');
INSERT INTO `claim_object_verification_logs` (`log_id`, `user_id`, `match_id`, `item_id`, `selected_item_id`, `is_correct`, `attempt_number`, `ip_address`, `user_agent`, `created_at`) VALUES ('5', '2', '25', '7', '10', '0', '4', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 13:23:26');

-- --------------------------------------------------------
-- Table structure for table `claim_requests`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `claim_requests`;
CREATE TABLE `claim_requests` (
  `claim_id` int(11) NOT NULL AUTO_INCREMENT,
  `match_id` int(11) NOT NULL,
  `claimant_user_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `request_message` text DEFAULT NULL,
  `status` enum('pending','approved','ready_for_pickup','claimed','rejected') NOT NULL DEFAULT 'pending',
  `admin_user_id` int(11) DEFAULT NULL,
  `admin_response` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`claim_id`),
  KEY `match_id` (`match_id`),
  KEY `claimant_user_id` (`claimant_user_id`),
  KEY `item_id` (`item_id`),
  KEY `admin_user_id` (`admin_user_id`),
  CONSTRAINT `fk_claim_admin` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_claim_claimant` FOREIGN KEY (`claimant_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_claim_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_claim_match` FOREIGN KEY (`match_id`) REFERENCES `itemmatches` (`match_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `claim_requests`
INSERT INTO `claim_requests` (`claim_id`, `match_id`, `claimant_user_id`, `item_id`, `request_message`, `status`, `admin_user_id`, `admin_response`, `created_at`, `updated_at`) VALUES ('8', '24', '2', '2', 'Object verification passed and claim submitted.', 'claimed', '1', 'waw', '2026-03-30 12:53:40', '2026-03-30 13:25:15');

-- --------------------------------------------------------
-- Table structure for table `finder_leaderboard`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `finder_leaderboard`;
CREATE TABLE `finder_leaderboard` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `finder_name` varchar(100) NOT NULL,
  `total_points` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_finder_name` (`finder_name`),
  KEY `idx_total_points` (`total_points`),
  KEY `idx_points` (`total_points`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `finder_leaderboard`
INSERT INTO `finder_leaderboard` (`id`, `finder_name`, `total_points`, `created_at`, `updated_at`) VALUES ('1', 'Juan Dela Cruz', '10', '2026-03-30 13:25:15', '2026-03-30 13:25:15');

-- --------------------------------------------------------
-- Table structure for table `itemmatches`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `itemmatches`;
CREATE TABLE `itemmatches` (
  `match_id` int(11) NOT NULL AUTO_INCREMENT,
  `lost_item_id` int(11) NOT NULL,
  `found_item_id` int(11) NOT NULL,
  `similarity_score` decimal(5,2) DEFAULT NULL CHECK (`similarity_score` between 0 and 100),
  `matched_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','accepted','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `admin_user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`match_id`),
  UNIQUE KEY `uq_match` (`lost_item_id`,`found_item_id`,`matched_at`),
  KEY `found_item_id` (`found_item_id`),
  KEY `idx_itemmatches_status` (`status`),
  KEY `itemmatches_ibfk_admin` (`admin_user_id`),
  CONSTRAINT `itemmatches_ibfk_1` FOREIGN KEY (`lost_item_id`) REFERENCES `items` (`item_id`) ON DELETE CASCADE,
  CONSTRAINT `itemmatches_ibfk_2` FOREIGN KEY (`found_item_id`) REFERENCES `items` (`item_id`) ON DELETE CASCADE,
  CONSTRAINT `itemmatches_ibfk_admin` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `itemmatches`
INSERT INTO `itemmatches` (`match_id`, `lost_item_id`, `found_item_id`, `similarity_score`, `matched_at`, `status`, `admin_user_id`) VALUES ('24', '11', '2', '94.00', '2026-03-30 12:52:55', 'accepted', '1');
INSERT INTO `itemmatches` (`match_id`, `lost_item_id`, `found_item_id`, `similarity_score`, `matched_at`, `status`, `admin_user_id`) VALUES ('25', '11', '7', '44.00', '2026-03-30 12:52:55', 'pending', NULL);
INSERT INTO `itemmatches` (`match_id`, `lost_item_id`, `found_item_id`, `similarity_score`, `matched_at`, `status`, `admin_user_id`) VALUES ('26', '11', '10', '20.00', '2026-03-30 12:52:55', 'pending', NULL);

-- --------------------------------------------------------
-- Table structure for table `items`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `items`;
CREATE TABLE `items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`item_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_location` (`location`),
  FULLTEXT KEY `idx_title_description` (`title`,`description`),
  CONSTRAINT `items_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `items`
INSERT INTO `items` (`item_id`, `user_id`, `title`, `description`, `category`, `location`, `item_image`, `status`, `report_date`, `hold_expiry`, `finder_name`, `finder_phone`, `updated_at`) VALUES ('1', '1', 'Black Wallet', 'Leather wallet with IDs inside', 'Accessories', 'Market Area', 'wallet1.jpg', 'found', '2026-03-29 21:11:36', '2026-04-29', NULL, NULL, '2026-03-29 21:14:36');
INSERT INTO `items` (`item_id`, `user_id`, `title`, `description`, `category`, `location`, `item_image`, `status`, `report_date`, `hold_expiry`, `finder_name`, `finder_phone`, `updated_at`) VALUES ('2', '1', 'iPhone 11', 'Black iPhone with cracked screen', 'Electronics', 'Bus Terminal', 'iphone11.jpg', 'claimed', '2026-03-29 21:11:36', '2026-04-05', 'Juan Dela Cruz', '09123456789', '2026-03-30 13:25:15');
INSERT INTO `items` (`item_id`, `user_id`, `title`, `description`, `category`, `location`, `item_image`, `status`, `report_date`, `hold_expiry`, `finder_name`, `finder_phone`, `updated_at`) VALUES ('3', '1', 'School ID', 'ID with blue lace', 'Documents', 'High School Gate', 'id1.jpg', 'found', '2026-03-29 21:11:36', '2026-04-29', NULL, NULL, '2026-03-29 21:14:34');
INSERT INTO `items` (`item_id`, `user_id`, `title`, `description`, `category`, `location`, `item_image`, `status`, `report_date`, `hold_expiry`, `finder_name`, `finder_phone`, `updated_at`) VALUES ('4', '1', 'Backpack', 'Red Nike backpack with books', 'Bags', 'Park Area', 'bag1.jpg', 'found', '2026-03-29 21:11:36', '2026-04-03', 'Maria Santos', '09987654321', '2026-03-29 21:11:36');
INSERT INTO `items` (`item_id`, `user_id`, `title`, `description`, `category`, `location`, `item_image`, `status`, `report_date`, `hold_expiry`, `finder_name`, `finder_phone`, `updated_at`) VALUES ('5', '1', 'Silver Ring', 'Engraved silver ring', 'Jewelry', 'Public Market', 'ring1.jpg', 'found', '2026-03-29 21:11:36', '2026-04-29', NULL, NULL, '2026-03-29 21:14:31');
INSERT INTO `items` (`item_id`, `user_id`, `title`, `description`, `category`, `location`, `item_image`, `status`, `report_date`, `hold_expiry`, `finder_name`, `finder_phone`, `updated_at`) VALUES ('6', '1', 'Umbrella', 'Blue foldable umbrella', 'Accessories', 'Jeepney Stop', 'umbrella1.jpg', 'found', '2026-03-29 21:11:36', '2026-04-29', 'Pedro Reyes', '09111222333', '2026-03-29 21:14:23');
INSERT INTO `items` (`item_id`, `user_id`, `title`, `description`, `category`, `location`, `item_image`, `status`, `report_date`, `hold_expiry`, `finder_name`, `finder_phone`, `updated_at`) VALUES ('7', '1', 'Laptop Bag', 'Black bag with charger inside', 'Electronics', 'Internet Cafe', 'laptopbag.jpg', 'found', '2026-03-29 21:11:36', '2026-04-29', 'Ana Cruz', '09223334444', '2026-03-29 21:14:26');
INSERT INTO `items` (`item_id`, `user_id`, `title`, `description`, `category`, `location`, `item_image`, `status`, `report_date`, `hold_expiry`, `finder_name`, `finder_phone`, `updated_at`) VALUES ('8', '1', 'Watch', 'Digital Casio watch', 'Accessories', 'Gym Area', 'watch1.jpg', 'found', '2026-03-29 21:11:36', '2026-04-29', 'Mark Lee', '09334445555', '2026-03-29 21:14:20');
INSERT INTO `items` (`item_id`, `user_id`, `title`, `description`, `category`, `location`, `item_image`, `status`, `report_date`, `hold_expiry`, `finder_name`, `finder_phone`, `updated_at`) VALUES ('9', '1', 'Keys', '3 keys with red keychain', 'Miscellaneous', 'Parking Lot', 'keys1.jpg', 'found', '2026-03-29 21:11:36', '2026-04-29', 'John Lim', '09445556666', '2026-03-29 21:14:15');
INSERT INTO `items` (`item_id`, `user_id`, `title`, `description`, `category`, `location`, `item_image`, `status`, `report_date`, `hold_expiry`, `finder_name`, `finder_phone`, `updated_at`) VALUES ('10', '1', 'Tablet', 'Samsung tablet white', 'Electronics', 'Library', 'tablet1.jpg', 'found', '2026-03-29 21:11:36', '2026-04-29', 'Library Staff', '09556667777', '2026-03-29 21:14:28');
INSERT INTO `items` (`item_id`, `user_id`, `title`, `description`, `category`, `location`, `item_image`, `status`, `report_date`, `hold_expiry`, `finder_name`, `finder_phone`, `updated_at`) VALUES ('11', '2', 'Iphone 11', 'black', 'Electronics', 'Bus Terminal', 'item_69c9f67ee7b5f_1774843518.jpg', 'lost', '2026-03-30 12:05:19', NULL, NULL, NULL, '2026-03-30 12:52:54');

-- --------------------------------------------------------
-- Table structure for table `leaderboard`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `leaderboard`;
CREATE TABLE `leaderboard` (
  `leaderboard_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `activity_type` enum('item_posted','successful_match','help_given','item_claimed','item_returned') NOT NULL,
  `points` int(11) DEFAULT 0 CHECK (`points` >= 0),
  `item_id` int(11) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`leaderboard_id`),
  UNIQUE KEY `uq_leader` (`user_id`,`activity_type`,`item_id`,`created_at`),
  KEY `user_id` (`user_id`),
  KEY `item_id` (`item_id`),
  CONSTRAINT `leaderboard_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `leaderboard_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `notifications`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `type` enum('email','sms','system') DEFAULT 'system',
  `message` text NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`notification_id`),
  KEY `user_id` (`user_id`),
  KEY `item_id` (`item_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `notifications`
INSERT INTO `notifications` (`notification_id`, `user_id`, `item_id`, `type`, `message`, `sent_at`, `is_read`) VALUES ('11', '1', '2', 'system', 'New claim request from Mark Lawrence Molinas', '2026-03-30 12:53:40', '0');

-- --------------------------------------------------------
-- Table structure for table `redistribution_records`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `redistribution_records`;
CREATE TABLE `redistribution_records` (
  `redistribution_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `redistribution_method` enum('donation','give_to_finder','dispose','store_again','repurposing') NOT NULL,
  `redistribution_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `admin_user_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recipient_info` varchar(255) DEFAULT NULL,
  `status` enum('pending','completed','cancelled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`redistribution_id`),
  KEY `item_id` (`item_id`),
  KEY `admin_user_id` (`admin_user_id`),
  KEY `idx_redistribution_method` (`redistribution_method`),
  KEY `idx_redistribution_date` (`redistribution_date`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `sms_logs`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `sms_logs`;
CREATE TABLE `sms_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `phone_number` varchar(15) NOT NULL,
  `message` text NOT NULL,
  `status` enum('success','failed') NOT NULL,
  `details` text DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_sent_at` (`sent_at`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `sms_logs`
INSERT INTO `sms_logs` (`log_id`, `user_id`, `phone_number`, `message`, `status`, `details`, `sent_at`) VALUES ('4', NULL, '9265852171', 'Good news! Your claimed item \'iPhone 11\' is ready for pickup. Please visit the office to collect it. - FindIt System', 'success', 'SMS sent successfully with ID: kSfrmP33DiODFMe79xehM', '2026-03-30 13:04:00');
INSERT INTO `sms_logs` (`log_id`, `user_id`, `phone_number`, `message`, `status`, `details`, `sent_at`) VALUES ('5', NULL, '9265852171', 'Thank you for collecting your item \'iPhone 11\'! Your claim has been completed successfully. We\'re glad we could help reunite you with your belonging. - FindIt System', 'success', 'SMS sent successfully with ID: YY-BrPhtCgM-I5lGyQsEv', '2026-03-30 13:25:19');

-- --------------------------------------------------------
-- Table structure for table `system_settings`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `setting_type` varchar(50) DEFAULT 'text',
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `unique_setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `system_settings`
INSERT INTO `system_settings` (`setting_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `updated_at`, `created_at`) VALUES ('1', 'site_name', 'SEAIT - Lost & Found System', 'text', 'The name of the website displayed in headers and titles', '2026-03-30 13:48:42', '2025-11-04 17:32:44');
INSERT INTO `system_settings` (`setting_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `updated_at`, `created_at`) VALUES ('2', 'site_description', 'A comprehensive lost and found management system for connecting people with their lost belongings', 'textarea', 'The main description of the website', '2026-03-30 13:48:42', '2025-11-04 17:32:44');
INSERT INTO `system_settings` (`setting_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `updated_at`, `created_at`) VALUES ('3', 'contact_email', 'admin@seait.edu.gov', 'email', 'Main contact email for the system', '2026-03-30 13:48:42', '2025-11-04 17:32:44');
INSERT INTO `system_settings` (`setting_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `updated_at`, `created_at`) VALUES ('6', 'site_tagline', 'Find What\'s Lost. Return What\'s Found.', 'text', 'Main tagline displayed on the homepage', '2026-03-30 13:48:42', '2025-11-04 17:32:44');
INSERT INTO `system_settings` (`setting_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `updated_at`, `created_at`) VALUES ('10', 'hero_image', 'uploads/settings/hero_1762585637.jpg', 'image', 'Hero section background image for index page', '2026-03-30 13:48:42', '2025-11-04 17:32:44');
INSERT INTO `system_settings` (`setting_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `updated_at`, `created_at`) VALUES ('11', 'login_image', 'uploads/settings/login_1762585637.jpg', 'image', 'Background image for login page left panel', '2026-03-30 13:48:42', '2025-11-04 17:32:44');
INSERT INTO `system_settings` (`setting_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `updated_at`, `created_at`) VALUES ('12', 'register_image', 'uploads/settings/register_1762585637.jpg', 'image', 'Background image for register page left panel', '2026-03-30 13:48:42', '2025-11-04 17:32:44');
INSERT INTO `system_settings` (`setting_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `updated_at`, `created_at`) VALUES ('13', 'site_logo', 'uploads/settings/logo_1774846694.png', 'image', 'Site logo used in header, sidebar, and loading states', '2026-03-30 13:48:42', '2026-03-29 23:00:00');

-- --------------------------------------------------------
-- Table structure for table `user_chat_pins`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `user_chat_pins`;
CREATE TABLE `user_chat_pins` (
  `pin_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `pin_hash` varchar(255) NOT NULL,
  `failed_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`pin_id`),
  UNIQUE KEY `uniq_user_chat_pin` (`user_id`),
  KEY `idx_locked_until` (`locked_until`),
  CONSTRAINT `fk_user_chat_pin_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `user_chat_pins`
INSERT INTO `user_chat_pins` (`pin_id`, `user_id`, `pin_hash`, `failed_attempts`, `locked_until`, `created_at`, `updated_at`) VALUES ('1', '2', '$2y$10$5ycdUhGlZXqY296/JCY6EuR.6SLjqWX9mjyeSqYzUUKh6d/taghhi', '0', NULL, '2026-03-29 12:07:44', '2026-03-29 13:44:15');
INSERT INTO `user_chat_pins` (`pin_id`, `user_id`, `pin_hash`, `failed_attempts`, `locked_until`, `created_at`, `updated_at`) VALUES ('2', '1', '$2y$10$TgVcYy19i0riKbbFdjlTVe9P5FoAaFSUVrePO2hXkUCwo0VZnUSWS', '0', NULL, '2026-03-30 12:58:54', '2026-03-30 12:58:54');

-- --------------------------------------------------------
-- Table structure for table `user_mfa_backup_codes`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `user_mfa_backup_codes`;
CREATE TABLE `user_mfa_backup_codes` (
  `backup_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`backup_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_used_at` (`used_at`),
  CONSTRAINT `fk_user_mfa_backup_codes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `user_password_resets`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `user_password_resets`;
CREATE TABLE `user_password_resets` (
  `reset_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
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
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`reset_id`),
  UNIQUE KEY `uniq_selector` (`selector`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_used_at` (`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `user_remember_tokens`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `user_remember_tokens`;
CREATE TABLE `user_remember_tokens` (
  `token_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `selector` char(18) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `created_ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `uniq_selector` (`selector`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(30) NOT NULL,
  `email` varchar(30) NOT NULL,
  `phone` varchar(10) DEFAULT NULL,
  `password_hash` varchar(100) NOT NULL,
  `role` enum('claimant','admin') NOT NULL DEFAULT 'claimant',
  `mfa_secret` varchar(64) DEFAULT NULL,
  `mfa_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `mfa_backup_codes_downloaded_at` datetime DEFAULT NULL,
  `image_path` varchar(200) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `phone` (`phone`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `users`
INSERT INTO `users` (`user_id`, `full_name`, `email`, `phone`, `password_hash`, `role`, `mfa_secret`, `mfa_enabled`, `mfa_backup_codes_downloaded_at`, `image_path`, `created_at`) VALUES ('1', 'Administrator', 'michaeltorillo08@gmail.com', '9513491961', '$2y$10$EuDXswLKTe.EbwTLIM8WwuOi328epcQPqNEg.xU4q6oYWs7nJkw5.', 'admin', '5UT42MB27PLWETZ5', '1', '2026-03-28 21:47:01', 'uploads/profile_690eec32ed38e.jpg', '2025-10-01 22:46:40');
INSERT INTO `users` (`user_id`, `full_name`, `email`, `phone`, `password_hash`, `role`, `mfa_secret`, `mfa_enabled`, `mfa_backup_codes_downloaded_at`, `image_path`, `created_at`) VALUES ('2', 'Mark Lawrence Molinas', 'michaeltorillo25@gmail.com', '9265852171', '$2y$10$JLf7soYzi7/Dk/2m0g8r0O0siG7aX55qbW1Ne1WZ1wd//mKU3a5C2', 'claimant', 'JY4WJFO2PAHZISEV', '1', '2026-03-28 21:23:15', NULL, '2025-11-13 09:20:04');

SET FOREIGN_KEY_CHECKS=1;
