-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 31, 2026 at 06:53 AM
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

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `AutoMatchItems` ()   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `AutoMoveExpiredFoundItemsToRedistribution` ()   BEGIN
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

--
-- Dumping data for table `chats`
--

INSERT INTO `chats` (`chat_id`, `match_id`, `sender_id`, `receiver_id`, `message`, `sent_at`) VALUES
(17, 24, 1, 2, 'v2:esyebQtwxRv+KTYF:gfkNHiZ18ph7IkSfDoQ=:FWO0J17i+HFfTtsfI+gCcA==', '2026-03-30 04:59:06'),
(18, 24, 2, 1, 'v2:vtLMGJ2r8ivGElKJ:HfRW:j+SQypUnUhYqw2STPVSk9Q==', '2026-03-30 04:59:23'),
(19, 24, 1, 2, 'v2:d6GI4fjywZQ6hTmU:NMNsGFk1EAdYE853fNOCSR7G8BdrACWeP8gAWYTPkva/xTaMn3JtpFcRWw==:UejpzdlZNCCbff8iU0B8mg==', '2026-03-30 05:00:34');

-- --------------------------------------------------------

--
-- Table structure for table `claim_object_verification_blocks`
--

CREATE TABLE `claim_object_verification_blocks` (
  `block_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `match_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `blocked_until` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `claim_object_verification_blocks`
--

INSERT INTO `claim_object_verification_blocks` (`block_id`, `user_id`, `match_id`, `item_id`, `blocked_until`, `created_at`, `updated_at`) VALUES
(1, 2, 25, 7, '2026-03-30 13:28:26', '2026-03-30 04:56:05', '2026-03-30 05:23:26');

-- --------------------------------------------------------

--
-- Table structure for table `claim_object_verification_logs`
--

CREATE TABLE `claim_object_verification_logs` (
  `log_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `match_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `selected_item_id` int(11) NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `attempt_number` tinyint(3) UNSIGNED NOT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT 'unknown',
  `user_agent` varchar(512) NOT NULL DEFAULT 'unknown',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `claim_object_verification_logs`
--

INSERT INTO `claim_object_verification_logs` (`log_id`, `user_id`, `match_id`, `item_id`, `selected_item_id`, `is_correct`, `attempt_number`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 2, 24, 2, 2, 1, 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 04:53:40'),
(2, 2, 25, 7, 11, 0, 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 04:56:00'),
(3, 2, 25, 7, 10, 0, 2, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 04:56:05'),
(4, 2, 25, 7, 11, 0, 3, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 05:23:18'),
(5, 2, 25, 7, 10, 0, 4, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 05:23:26');

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

--
-- Dumping data for table `claim_requests`
--

INSERT INTO `claim_requests` (`claim_id`, `match_id`, `claimant_user_id`, `item_id`, `request_message`, `status`, `admin_user_id`, `admin_response`, `created_at`, `updated_at`) VALUES
(8, 24, 2, 2, 'Object verification passed and claim submitted.', 'claimed', 1, 'waw', '2026-03-30 04:53:40', '2026-03-30 05:25:15');

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

--
-- Dumping data for table `finder_leaderboard`
--

INSERT INTO `finder_leaderboard` (`id`, `finder_name`, `total_points`, `created_at`, `updated_at`) VALUES
(1, 'Juan Dela Cruz', 10, '2026-03-30 05:25:15', '2026-03-30 05:25:15');

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

--
-- Dumping data for table `itemmatches`
--

INSERT INTO `itemmatches` (`match_id`, `lost_item_id`, `found_item_id`, `similarity_score`, `matched_at`, `status`, `admin_user_id`) VALUES
(24, 11, 2, 94.00, '2026-03-30 04:52:55', 'accepted', 1),
(25, 11, 7, 44.00, '2026-03-30 04:52:55', 'pending', NULL),
(26, 11, 10, 20.00, '2026-03-30 04:52:55', 'pending', NULL);

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

--
-- Dumping data for table `items`
--

INSERT INTO `items` (`item_id`, `user_id`, `title`, `description`, `category`, `location`, `item_image`, `status`, `report_date`, `hold_expiry`, `finder_name`, `finder_phone`, `updated_at`) VALUES
(1, 1, 'Black Wallet', 'Leather wallet with IDs inside', 'Accessories', 'Market Area', 'item_69cb2f8c90fcd_1774923660.png', 'found', '2026-03-29 13:11:36', '2026-04-29', NULL, NULL, '2026-03-31 02:21:00'),
(2, 1, 'iPhone 11', 'Black iPhone with cracked screen', 'Electronics', 'Bus Terminal', 'item_69cb3cd3143c7_1774927059.png', 'claimed', '2026-03-29 13:11:36', '2026-04-05', 'Juan Dela Cruz', '09123456789', '2026-03-31 04:04:45'),
(3, 1, 'School ID', 'ID with blue lace', 'Documents', 'High School Gate', 'item_69cb485148483_1774930001.png', 'found', '2026-03-29 13:11:36', '2026-04-29', NULL, NULL, '2026-03-31 04:06:41'),
(4, 1, 'Backpack', 'Red Nike backpack with books', 'Bags', 'Park Area', 'item_69cb30cbcac77_1774923979.png', 'found', '2026-03-29 13:11:36', '2026-04-03', 'Maria Santos', '09987654321', '2026-03-31 02:26:19'),
(5, 1, 'Silver Ring', 'Engraved silver ring', 'Jewelry', 'Public Market', 'item_69cb31174ad17_1774924055.png', 'found', '2026-03-29 13:11:36', '2026-04-29', NULL, NULL, '2026-03-31 02:27:35'),
(6, 1, 'Umbrella', 'Blue foldable umbrella', 'Accessories', 'Jeepney Stop', 'item_69cb3186c4e30_1774924166.png', 'found', '2026-03-29 13:11:36', '2026-04-29', 'Pedro Reyes', '09111222333', '2026-03-31 02:29:26'),
(7, 1, 'Laptop Bag', 'Black bag with charger inside', 'Electronics', 'Internet Cafe', 'item_69cb26767843c_1774921334.png', 'found', '2026-03-29 13:11:36', '2026-04-29', 'Ana Cruz', '09223334444', '2026-03-31 01:42:14'),
(8, 1, 'Watch', 'Digital Casio watch', 'Accessories', 'Gym Area', 'item_69cb2910408be_1774922000.png', 'found', '2026-03-29 13:11:36', '2026-04-29', 'Mark Lee', '09334445555', '2026-03-31 01:53:20'),
(9, 1, 'Keys', '3 keys with red keychain', 'Miscellaneous', 'Parking Lot', 'item_69cb30425d5f0_1774923842.png', 'found', '2026-03-29 13:11:36', '2026-04-29', 'John Lim', '09445556666', '2026-03-31 02:24:02'),
(10, 1, 'Tablet', 'Samsung tablet white', 'Electronics', 'Library', 'item_69cb3074e288a_1774923892.png', 'found', '2026-03-29 13:11:36', '2026-04-29', 'Library Staff', '09556667777', '2026-03-31 02:24:52'),
(11, 2, 'Iphone 11', 'black', 'Electronics', 'Bus Terminal', 'item_69cb3cd3143c7_1774927059.png', 'lost', '2026-03-30 04:05:19', NULL, NULL, NULL, '2026-03-31 03:17:39');

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

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `item_id`, `type`, `message`, `sent_at`, `is_read`) VALUES
(11, 1, 2, 'system', 'New claim request from Mark Lawrence Molinas', '2026-03-30 04:53:40', 0);

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

--
-- Dumping data for table `sms_logs`
--

INSERT INTO `sms_logs` (`log_id`, `user_id`, `phone_number`, `message`, `status`, `details`, `sent_at`) VALUES
(4, NULL, '9265852171', 'Good news! Your claimed item \'iPhone 11\' is ready for pickup. Please visit the office to collect it. - FindIt System', 'success', 'SMS sent successfully with ID: kSfrmP33DiODFMe79xehM', '2026-03-30 05:04:00'),
(5, NULL, '9265852171', 'Thank you for collecting your item \'iPhone 11\'! Your claim has been completed successfully. We\'re glad we could help reunite you with your belonging. - FindIt System', 'success', 'SMS sent successfully with ID: YY-BrPhtCgM-I5lGyQsEv', '2026-03-30 05:25:19');

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
(1, 'site_name', 'SEAIT - Lost & Found System', 'text', 'The name of the website displayed in headers and titles', '2026-03-30 05:48:42', '2025-11-04 09:32:44'),
(2, 'site_description', 'A comprehensive lost and found management system for connecting people with their lost belongings', 'textarea', 'The main description of the website', '2026-03-30 05:48:42', '2025-11-04 09:32:44'),
(3, 'contact_email', 'admin@seait.edu.gov', 'email', 'Main contact email for the system', '2026-03-30 05:48:42', '2025-11-04 09:32:44'),
(6, 'site_tagline', 'Find What\'s Lost. Return What\'s Found.', 'text', 'Main tagline displayed on the homepage', '2026-03-30 05:48:42', '2025-11-04 09:32:44'),
(10, 'hero_image', 'uploads/settings/hero_1762585637.jpg', 'image', 'Hero section background image for index page', '2026-03-30 05:48:42', '2025-11-04 09:32:44'),
(11, 'login_image', 'uploads/settings/login_1762585637.jpg', 'image', 'Background image for login page left panel', '2026-03-30 05:48:42', '2025-11-04 09:32:44'),
(12, 'register_image', 'uploads/settings/register_1762585637.jpg', 'image', 'Background image for register page left panel', '2026-03-30 05:48:42', '2025-11-04 09:32:44'),
(13, 'site_logo', 'uploads/settings/logo_1774846694.png', 'image', 'Site logo used in header, sidebar, and loading states', '2026-03-30 05:48:42', '2026-03-29 15:00:00');

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
(1, 'Administrator', 'michaeltorillo08@gmail.com', '9513491961', '$2y$10$EuDXswLKTe.EbwTLIM8WwuOi328epcQPqNEg.xU4q6oYWs7nJkw5.', 'admin', '5UT42MB27PLWETZ5', 1, '2026-03-31 12:52:26', 'uploads/profile_690eec32ed38e.jpg', '2025-10-01 14:46:40'),
(2, 'Javier Gabriel', 'michaeltorillo25@gmail.com', '9265852171', '$2y$10$JLf7soYzi7/Dk/2m0g8r0O0siG7aX55qbW1Ne1WZ1wd//mKU3a5C2', 'claimant', 'JY4WJFO2PAHZISEV', 1, '2026-03-28 21:23:15', NULL, '2025-11-13 01:20:04');

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
(1, 2, '$2y$10$5ycdUhGlZXqY296/JCY6EuR.6SLjqWX9mjyeSqYzUUKh6d/taghhi', 0, NULL, '2026-03-29 12:07:44', '2026-03-31 11:33:27'),
(2, 1, '$2y$10$TgVcYy19i0riKbbFdjlTVe9P5FoAaFSUVrePO2hXkUCwo0VZnUSWS', 0, NULL, '2026-03-30 12:58:54', '2026-03-31 11:21:00');

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

--
-- Dumping data for table `user_mfa_backup_codes`
--

INSERT INTO `user_mfa_backup_codes` (`backup_id`, `user_id`, `code_hash`, `used_at`, `created_at`) VALUES
(33, 1, '$2y$10$pziHdq17gx3I6vGCHffEKezSbfACpCRgVXh1hoXAFYewUAUq/IG3.', NULL, '2026-03-31 12:51:55'),
(34, 1, '$2y$10$RdXLmhZC0ZE06uoZ/b3L8OTaYkI9bx1ax1MDi2SpDioygPYGnNjjq', NULL, '2026-03-31 12:51:56'),
(35, 1, '$2y$10$.nNcFelY9j7Zruo9iKY8zu0c1KPj/3EoOOQwRZAmNYs2/zzMIbdYW', NULL, '2026-03-31 12:51:57'),
(36, 1, '$2y$10$KHhDsaN.5CUqOlUsDh0KlOUSmDZYGu4CXHyZfAzbVJwGAd/AY0Kui', NULL, '2026-03-31 12:51:58'),
(37, 1, '$2y$10$vIrRpmdsw3iqh7OJq6qLGuxAfbFx./qgLloaVgyqKIlXIMJlDWoWK', NULL, '2026-03-31 12:51:58'),
(38, 1, '$2y$10$jx88Z2T0gmiJbWlyb0qA5eFkSZbxt8Dt7QB2UFceNaplqA47fHDiu', NULL, '2026-03-31 12:51:59'),
(39, 1, '$2y$10$EC6MoLwChuTLb.4ru8lXVe.Uk3w.dkxTDzB5fZU4X.UEBQPz.Uv1q', NULL, '2026-03-31 12:52:00'),
(40, 1, '$2y$10$KIagendNwEQCqbLpB1AYSuGe4YkwiPjTTv.O4TwLadffqzeg1PBKO', NULL, '2026-03-31 12:52:01');

-- --------------------------------------------------------

--
-- Table structure for table `user_mfa_backup_code_snapshots`
--

CREATE TABLE `user_mfa_backup_code_snapshots` (
  `snapshot_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `display_order` tinyint(3) UNSIGNED NOT NULL,
  `encrypted_code` text NOT NULL,
  `code_fingerprint` char(64) DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_mfa_backup_code_snapshots`
--

INSERT INTO `user_mfa_backup_code_snapshots` (`snapshot_id`, `user_id`, `display_order`, `encrypted_code`, `code_fingerprint`, `used_at`, `created_at`) VALUES
(9, 1, 1, 'v1:E4YwOtVu8Fm0utUa:lzk2TNj4UVAs:mW9NfHsH/ORocKWjZtyqFw==', '19bd29202271d8e14afccad7381cc592887f17bf70d6e37c17f560b6a69e8bd8', NULL, '2026-03-31 12:52:01'),
(10, 1, 2, 'v1:O5lj1XSlUuduwQaL:ei0KyMH8U4mQ:QCQSpZtki/mWkomQhLugVQ==', '0ff86e71e88558386c66b161662f573ba66718e778037722c40d899f769598b5', NULL, '2026-03-31 12:52:01'),
(11, 1, 3, 'v1:bZiP9jreW70btrLT:MkwSJUZO8x/1:1s58Kdr5BJ96/qLJlk5wAg==', 'a3ab6f256cddb6d0656ef0af8bfd821cc5cd8777806d10869862d6705b70ad5a', NULL, '2026-03-31 12:52:01'),
(12, 1, 4, 'v1:lBduA+2tlxWHwEvc:5RpoErmIx4xD:i/SDrxN3J40hEESXrmIzMw==', '90f2515d2a72af8f33f0a4dad79597540deb151dc03751c195fd7b74951d5a7e', NULL, '2026-03-31 12:52:01'),
(13, 1, 5, 'v1:5w5ym5jH/LljQB7Q:K+VRXaI4BS9c:hGqQpr71iJvGJmajfT9PAg==', 'e882ed054e9a7abbfbd7a1ad930abb7511aea5a92e4d1b5884db0cb744bfa3b7', NULL, '2026-03-31 12:52:01'),
(14, 1, 6, 'v1:FsPNSKb9M1ikt4kQ:T/YATVJq91Hd:SXPFaF1WqArupEKKxbBZkQ==', '089aff01c6be678d0e7e6c35a2fa3b0ed69f044cda6320b68c201bf8ba77e5ba', NULL, '2026-03-31 12:52:01'),
(15, 1, 7, 'v1:P5TYIcfxH28XvkOi:hpoDnKL9lH4R:/RzAyqd3TlAb/x+JMLNcsw==', '3122950e63737956a0dab0d263a5fc0d5296a2f5b44c66746958d8bfba8985c0', NULL, '2026-03-31 12:52:01'),
(16, 1, 8, 'v1:oI8k/+YDmTQP+LWS:MFAcWMylQLev:TAxeiqVhJWIllEtrdPjL6g==', 'dbc6f8d44e8f0bfeb1c01de846f3ae1d009bd9c07e7fce1b79fd27774908d5cc', NULL, '2026-03-31 12:52:01');

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
-- Dumping data for table `user_remember_tokens`
--

INSERT INTO `user_remember_tokens` (`token_id`, `user_id`, `selector`, `token_hash`, `expires_at`, `created_at`, `last_used_at`, `created_ip`, `user_agent`) VALUES
(4, 2, 'b5cd711c3079454eb8', '04cc77cf08226cb87e77112f16ac3762ee0d15bc7056692ec7cf88bfe6d36659', '2026-04-30 06:27:23', '2026-03-31 12:27:23', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0');

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
-- Indexes for table `claim_object_verification_blocks`
--
ALTER TABLE `claim_object_verification_blocks`
  ADD PRIMARY KEY (`block_id`),
  ADD UNIQUE KEY `uq_claim_obj_block` (`user_id`,`match_id`,`item_id`),
  ADD KEY `idx_claim_obj_block_until` (`blocked_until`);

--
-- Indexes for table `claim_object_verification_logs`
--
ALTER TABLE `claim_object_verification_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_claim_obj_verify_user_match` (`user_id`,`match_id`),
  ADD KEY `idx_claim_obj_verify_item` (`item_id`);

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
-- Indexes for table `user_mfa_backup_code_snapshots`
--
ALTER TABLE `user_mfa_backup_code_snapshots`
  ADD PRIMARY KEY (`snapshot_id`),
  ADD UNIQUE KEY `uniq_user_order` (`user_id`,`display_order`),
  ADD KEY `idx_snapshot_user_id` (`user_id`),
  ADD KEY `idx_snapshot_used_at` (`used_at`),
  ADD KEY `idx_snapshot_fingerprint` (`user_id`,`code_fingerprint`);

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
  MODIFY `chat_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `claim_object_verification_blocks`
--
ALTER TABLE `claim_object_verification_blocks`
  MODIFY `block_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `claim_object_verification_logs`
--
ALTER TABLE `claim_object_verification_logs`
  MODIFY `log_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `claim_requests`
--
ALTER TABLE `claim_requests`
  MODIFY `claim_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `finder_leaderboard`
--
ALTER TABLE `finder_leaderboard`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `itemmatches`
--
ALTER TABLE `itemmatches`
  MODIFY `match_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `items`
--
ALTER TABLE `items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `leaderboard`
--
ALTER TABLE `leaderboard`
  MODIFY `leaderboard_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `redistribution_records`
--
ALTER TABLE `redistribution_records`
  MODIFY `redistribution_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `sms_logs`
--
ALTER TABLE `sms_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `user_chat_pins`
--
ALTER TABLE `user_chat_pins`
  MODIFY `pin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `user_mfa_backup_codes`
--
ALTER TABLE `user_mfa_backup_codes`
  MODIFY `backup_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `user_mfa_backup_code_snapshots`
--
ALTER TABLE `user_mfa_backup_code_snapshots`
  MODIFY `snapshot_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `user_password_resets`
--
ALTER TABLE `user_password_resets`
  MODIFY `reset_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_remember_tokens`
--
ALTER TABLE `user_remember_tokens`
  MODIFY `token_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

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
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
