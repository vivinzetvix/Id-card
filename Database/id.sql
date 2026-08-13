-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 08, 2026 at 03:35 PM
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
-- Database: `id`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `organization_id` int(11) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_log`
--

-- --------------------------------------------------------

--
-- Table structure for table `backup_history`
--

CREATE TABLE `backup_history` (
  `id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `tables` text DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `card_templates`
--

CREATE TABLE `card_templates` (
  `id` int(11) NOT NULL,
  `organization_id` int(11) DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `description` varchar(255) NOT NULL,
  `primary_color` varchar(120) NOT NULL,
  `secondary_color` varchar(120) NOT NULL,
  `text_color` varchar(20) NOT NULL DEFAULT '#ffffff',
  `orientation` enum('portrait','landscape') DEFAULT 'portrait',
  `card_width` int(11) DEFAULT NULL,
  `card_height` int(11) DEFAULT NULL,
  `background_image` varchar(255) DEFAULT NULL,
  `front_image` varchar(255) DEFAULT NULL,
  `back_image` varchar(255) DEFAULT NULL,
  `mirror_print` tinyint(1) DEFAULT 0,
  `layout_version` int(11) NOT NULL DEFAULT 1,
  `status` tinyint(1) DEFAULT 1,
  `font` varchar(80) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `downloads` int(11) NOT NULL DEFAULT 0,
  `rating` decimal(2,1) NOT NULL DEFAULT 5.0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `bg_pos_x` decimal(6,2) NOT NULL DEFAULT 50.00,
  `bg_pos_y` decimal(6,2) NOT NULL DEFAULT 50.00,
  `bg_size` varchar(20) NOT NULL DEFAULT 'cover'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `card_templates`
--


-- --------------------------------------------------------

--
-- Table structure for table `email_settings`
--

CREATE TABLE `email_settings` (
  `id` int(11) NOT NULL,
  `mail_type` enum('smtp','sendmail','mail') DEFAULT 'mail',
  `smtp_host` varchar(255) DEFAULT NULL,
  `smtp_port` int(11) DEFAULT 587,
  `smtp_encryption` enum('tls','ssl','none') DEFAULT 'tls',
  `smtp_username` varchar(255) DEFAULT NULL,
  `smtp_password` varchar(255) DEFAULT NULL,
  `from_email` varchar(255) DEFAULT NULL,
  `from_name` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `failed_logins`
--

CREATE TABLE `failed_logins` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(100) NOT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `attempt_time` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `generated_cards`
--

CREATE TABLE `generated_cards` (
  `id` int(11) NOT NULL,
  `organization_id` int(11) DEFAULT NULL,
  `member_id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `generated_cards`
--

INSERT INTO `generated_cards` (`id`, `organization_id`, `member_id`, `template_id`, `image_path`, `created_at`) VALUES
(1, NULL, 1, 63, 'images/cards/card_1_1785780483.svg', '2026-08-03 18:08:03'),
(20, 1, 5, 63, 'images/cards/card_5_1785780599.svg', '2026-08-03 18:09:59'),
(21, 1, 8, 63, 'images/cards/card_8_1786014236.html', '2026-08-06 11:03:56'),
(24, NULL, 2, 63, 'images/cards/card_2_1785778540.svg', '2026-08-03 17:35:40'),
(38, 1, 12, 63, 'images/cards/card_12_1786190531.html', '2026-08-08 12:02:11');

-- --------------------------------------------------------

--
-- Table structure for table `id_members`
--

CREATE TABLE `id_members` (
  `id` int(11) NOT NULL,
  `organization_id` int(11) DEFAULT NULL,
  `template_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `language` varchar(10) DEFAULT 'en',
  `member_type` enum('student','employee','staff','faculty','visitor','office') NOT NULL DEFAULT 'student',
  `unique_id` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `guardian_name` varchar(100) DEFAULT NULL,
  `class` varchar(50) DEFAULT NULL,
  `department` varchar(80) DEFAULT NULL,
  `designation` varchar(80) DEFAULT NULL,
  `company` varchar(120) DEFAULT NULL,
  `purpose` varchar(255) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `address` text DEFAULT NULL,
  `emergency_contact` varchar(15) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `joined_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `signature` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `id_members`
--

INSERT INTO `id_members` (`id`, `organization_id`, `template_id`, `created_by`, `language`, `member_type`, `unique_id`, `name`, `guardian_name`, `class`, `department`, `designation`, `company`, `purpose`, `dob`, `address`, `emergency_contact`, `email`, `joined_date`, `expiry_date`, `photo`, `signature`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, NULL, NULL, NULL, 'en', 'student', 'REG001', 'Arun Kumar', 'Ramesh Kumar', '10-A', NULL, NULL, NULL, NULL, '2008-05-12', 'Chennai, Tamil Nadu', '9876543210', 'arun@gmail.com', '2024-06-01', '2026-06-01', 'arun.jpg', NULL, '2026-07-31 13:02:21', '2026-07-31 13:02:21', NULL),
(2, NULL, NULL, NULL, 'en', 'employee', 'EMP1002', 'Priya Devi', NULL, NULL, 'Administration', 'Accounts Officer', NULL, NULL, '1990-09-25', 'Madurai, Tamil Nadu', '9898989898', 'priya@gmail.com', '2024-06-01', '2026-06-01', 'priya.jpg', NULL, '2026-07-31 13:02:21', '2026-07-31 13:02:21', NULL),
(3, NULL, NULL, NULL, 'en', 'visitor', 'VIS3001', 'Karthik Raj', NULL, NULL, NULL, NULL, 'Acme Corp', 'Facility tour', NULL, 'Coimbatore, Tamil Nadu', '9797979797', 'karthik@gmail.com', '2024-06-01', '2024-06-01', 'karthik.jpg', NULL, '2026-07-31 13:02:21', '2026-07-31 13:02:21', NULL),
(5, 1, 0, NULL, 'en', 'student', 'STU2024002', 'Jane Smith', '', '10-B', 'Science', '', '', 'Student ID', '2005-05-20', '456 School Rd', '9876543211', 'jane@example.com', '2024-06-01', '2026-05-31', NULL, NULL, '2026-08-03 10:52:40', '2026-08-03 10:52:40', NULL),
(6, 1, 52, NULL, 'en', 'staff', 'STA2024003', 'Robert Johnson', NULL, NULL, 'Administration', 'Admin Staff', NULL, NULL, '1985-10-10', '789 Office Ln', '9876543212', 'robert@example.com', '2024-01-15', '2026-08-06', NULL, NULL, '2026-08-03 10:52:40', '2026-08-06 10:54:21', NULL),
(8, 1, 63, NULL, 'en', 'student', 'VIS2024005', 'David Brown', 'FDS', 'mca', 'MCA', NULL, NULL, NULL, '1988-07-07', '555 Guest Rd', '9876543214', 'david@example.com', '2024-03-01', '2027-08-31', 'member_1785754438_6a707346ad40c.jpg', 'member_1786007833_a270d2ee.jpg', '2026-08-03 10:52:40', '2026-08-08 08:51:45', NULL),
(12, 1, 63, NULL, 'en', 'student', 'MEM2026087993', '743 Vijin', '', '', '', '', '', '', '2026-08-12', '', '8870187982', 'vijinvivin100@gmail.com', '2026-08-06', '0000-00-00', 'member_1786036850_f0a73999.jpg', 'member_1786036850_8e418f59.jpg', '2026-08-06 17:20:50', '2026-08-06 17:51:37', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `languages`
--

CREATE TABLE `languages` (
  `id` int(11) NOT NULL,
  `language_code` varchar(20) NOT NULL,
  `language_name` varchar(100) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `languages`
--

INSERT INTO `languages` (`id`, `language_code`, `language_name`, `is_default`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'lang1', 'Language 1', 1, 1, '2026-08-01 08:49:56', '2026-08-01 08:49:56'),
(2, 'lang2', 'Language 2', 0, 1, '2026-08-01 08:49:56', '2026-08-01 08:49:56');

-- --------------------------------------------------------

--
-- Table structure for table `login_history`
--

CREATE TABLE `login_history` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `user_agent` text DEFAULT NULL,
  `browser` varchar(100) DEFAULT NULL,
  `os` varchar(100) DEFAULT NULL,
  `login_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `logout_time` timestamp NULL DEFAULT NULL,
  `status` enum('success','failed','locked') NOT NULL DEFAULT 'success'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `login_history`
--


--
-- Table structure for table `member_dynamic_values`
--

CREATE TABLE `member_dynamic_values` (
  `id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL DEFAULT 0,
  `field_key` varchar(80) NOT NULL,
  `field_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `member_dynamic_values`
--

INSERT INTO `member_dynamic_values` (`id`, `member_id`, `template_id`, `field_key`, `field_value`, `created_at`, `updated_at`) VALUES
(7, 8, 63, 'field_1', 'Hello', '2026-08-06 08:58:02', '2026-08-06 09:42:54'),
(8, 8, 63, 'field_2', 'member_1786007856_fa105ff5.webp', '2026-08-06 08:58:02', '2026-08-06 09:17:36'),
(19, 12, 63, 'field_1', 'Hello', '2026-08-06 17:20:50', '2026-08-06 17:20:50'),
(20, 12, 63, 'field_2', 'member_1786038697_f84855eb.jpg', '2026-08-06 17:20:50', '2026-08-06 17:51:37');

-- --------------------------------------------------------

--
-- Table structure for table `member_field_translations`
--

CREATE TABLE `member_field_translations` (
  `id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `template_field_id` int(11) NOT NULL,
  `language_code` varchar(20) NOT NULL,
  `translated_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `organizations`
--

CREATE TABLE `organizations` (
  `id` int(11) NOT NULL,
  `organization_name` varchar(150) NOT NULL,
  `organization_code` varchar(50) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `website` varchar(150) DEFAULT NULL,
  `organization_type` enum('school','college','company','government','hospital','ngo','other') DEFAULT 'company',
  `project_type` enum('residence','corporate') DEFAULT 'corporate',
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `organizations`
--

INSERT INTO `organizations` (`id`, `organization_name`, `organization_code`, `logo`, `address`, `phone`, `email`, `website`, `organization_type`, `project_type`, `status`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_by`, `deleted_at`) VALUES
(1, 'Helllo', 'ORG001', 'org_6a745365713f88.83851739.webp', 'Hmi', '9876543210', 'admin@example.com', '', 'company', 'corporate', 1, '2026-07-31 13:10:27', '2026-08-06 11:33:53', NULL, 1, NULL, NULL),
(2, 'ABC Inter', '3434', 'org_6a6ca83ec800c6.60366135.png', 'vavarai,\r\nmathandam', '09443177546', 'vijinvivin100@gmail.com', 'http://localhost/invoice/invoice/add_customer.php', 'school', 'residence', 1, '2026-07-31 13:50:54', '2026-07-31 13:50:54', 1, 1, NULL, NULL),
(3, 'ABC', 'A980', 'org_1785585401_7470.png', 'vavarai,\r\nmathandam', '+469443177546', 'vijinvivin100S@gmail.com', 'http://localhost/invoice/invoice/add_customer.php', 'school', 'residence', 1, '2026-08-01 11:55:21', '2026-08-01 11:56:45', 1, 1, NULL, NULL),
(4, 'ABC InterS', 'AI372S', 'org_1785585490_8609.png', 'vavarai,\r\nmathandam', '+46944317754622', 'vijinviviSSn100@gmail.com', 'http://localhost/invoice/invoice/add_customer.php', 'company', 'corporate', 0, '2026-08-01 11:58:10', '2026-08-01 12:01:04', 1, 1, 1, '2026-08-01 12:01:04');

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token` varchar(128) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `permission_name` varchar(120) NOT NULL,
  `module_name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `permission_name`, `module_name`, `description`, `created_at`) VALUES
(1, 'View', 'Dashboard', 'View Dashboard', '2026-07-31 14:02:29'),
(2, 'Export', 'Dashboard', 'Export Dashboard', '2026-07-31 14:02:29'),
(3, 'View', 'Organizations', 'View Organizations', '2026-07-31 14:02:29'),
(4, 'Create', 'Organizations', 'Create Organizations', '2026-07-31 14:02:29'),
(5, 'Edit', 'Organizations', 'Edit Organizations', '2026-07-31 14:02:29'),
(6, 'Delete', 'Organizations', 'Delete Organizations', '2026-07-31 14:02:29'),
(7, 'Print', 'Organizations', 'Print Organizations', '2026-07-31 14:02:29'),
(8, 'Export', 'Organizations', 'Export Organizations', '2026-07-31 14:02:29'),
(9, 'Import', 'Organizations', 'Import Organizations', '2026-07-31 14:02:29'),
(10, 'View', 'Users', 'View Users', '2026-07-31 14:02:29'),
(11, 'Create', 'Users', 'Create Users', '2026-07-31 14:02:29'),
(12, 'Edit', 'Users', 'Edit Users', '2026-07-31 14:02:29'),
(13, 'Delete', 'Users', 'Delete Users', '2026-07-31 14:02:29'),
(14, 'Print', 'Users', 'Print Users', '2026-07-31 14:02:29'),
(15, 'Export', 'Users', 'Export Users', '2026-07-31 14:02:29'),
(16, 'Import', 'Users', 'Import Users', '2026-07-31 14:02:29'),
(17, 'View', 'Roles', 'View Roles', '2026-07-31 14:02:29'),
(18, 'Create', 'Roles', 'Create Roles', '2026-07-31 14:02:29'),
(19, 'Edit', 'Roles', 'Edit Roles', '2026-07-31 14:02:29'),
(20, 'Delete', 'Roles', 'Delete Roles', '2026-07-31 14:02:29'),
(21, 'Print', 'Roles', 'Print Roles', '2026-07-31 14:02:29'),
(22, 'Export', 'Roles', 'Export Roles', '2026-07-31 14:02:29'),
(23, 'Import', 'Roles', 'Import Roles', '2026-07-31 14:02:29'),
(24, 'View', 'Members', 'View Members', '2026-07-31 14:02:29'),
(25, 'Create', 'Members', 'Create Members', '2026-07-31 14:02:29'),
(26, 'Edit', 'Members', 'Edit Members', '2026-07-31 14:02:29'),
(27, 'Delete', 'Members', 'Delete Members', '2026-07-31 14:02:29'),
(28, 'Print', 'Members', 'Print Members', '2026-07-31 14:02:29'),
(29, 'Export', 'Members', 'Export Members', '2026-07-31 14:02:29'),
(30, 'Import', 'Members', 'Import Members', '2026-07-31 14:02:29'),
(31, 'View', 'Templates', 'View Templates', '2026-07-31 14:02:29'),
(32, 'Create', 'Templates', 'Create Templates', '2026-07-31 14:02:29'),
(33, 'Edit', 'Templates', 'Edit Templates', '2026-07-31 14:02:29'),
(34, 'Delete', 'Templates', 'Delete Templates', '2026-07-31 14:02:29'),
(35, 'Print', 'Templates', 'Print Templates', '2026-07-31 14:02:29'),
(36, 'Export', 'Templates', 'Export Templates', '2026-07-31 14:02:29'),
(37, 'Import', 'Templates', 'Import Templates', '2026-07-31 14:02:29'),
(38, 'View', 'Generate ID', 'View Generate ID', '2026-07-31 14:02:29'),
(39, 'Create', 'Generate ID', 'Create Generate ID', '2026-07-31 14:02:29'),
(40, 'Edit', 'Generate ID', 'Edit Generate ID', '2026-07-31 14:02:29'),
(41, 'Delete', 'Generate ID', 'Delete Generate ID', '2026-07-31 14:02:29'),
(42, 'Print', 'Generate ID', 'Print Generate ID', '2026-07-31 14:02:29'),
(43, 'Export', 'Generate ID', 'Export Generate ID', '2026-07-31 14:02:29'),
(44, 'View', 'Reports', 'View Reports', '2026-07-31 14:02:29'),
(45, 'Print', 'Reports', 'Print Reports', '2026-07-31 14:02:29'),
(46, 'Export', 'Reports', 'Export Reports', '2026-07-31 14:02:29'),
(47, 'View', 'Settings', 'View Settings', '2026-07-31 14:02:29'),
(48, 'Edit', 'Settings', 'Edit Settings', '2026-07-31 14:02:29'),
(49, 'Manage Settings', 'Settings', 'Manage Settings Settings', '2026-07-31 14:02:29');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `role_name`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Super Admin', 'Full System Access', 1, '2026-07-31 14:02:29', '2026-07-31 14:02:29'),
(2, 'Organization Admin', 'Organization Administrator', 1, '2026-07-31 14:02:29', '2026-07-31 14:02:29'),
(3, 'Registrar', 'Member Registration', 1, '2026-07-31 14:02:29', '2026-07-31 14:02:29'),
(7, 'Finace', 'Archived test role (typo of Finance). Deactivated by Phase 2 migration. Safe to delete manually if unused.', 0, '2026-08-01 11:36:12', '2026-08-06 06:07:05');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role_permissions`
--


-- --------------------------------------------------------

--
-- Table structure for table `support_tickets`
--

CREATE TABLE `support_tickets` (
  `id` int(11) NOT NULL,
  `ticket_id` varchar(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `category` varchar(50) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
  `status` enum('open','in_progress','resolved','closed') DEFAULT 'open',
  `admin_response` text DEFAULT NULL,
  `responded_by` int(11) DEFAULT NULL,
  `responded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('text','number','boolean','json','color') DEFAULT 'text',
  `description` text DEFAULT NULL,
  `updated_by` varchar(50) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `updated_by`, `updated_at`) VALUES
(1, 'organization_name', 'hii', 'text', NULL, 'admin', '2026-08-03 11:08:24'),
(2, 'organization_address', '', 'text', NULL, 'admin', '2026-08-03 11:08:24'),
(3, 'organization_phone', '', 'text', NULL, 'admin', '2026-08-03 11:08:24'),
(4, 'organization_email', '', 'text', NULL, 'admin', '2026-08-03 11:08:24'),
(5, 'organization_website', '', 'text', NULL, 'admin', '2026-08-03 11:08:24'),
(6, 'date_format', 'd/m/Y', 'text', NULL, 'admin', '2026-08-03 11:08:24'),
(7, 'timezone', 'Asia/Kolkata', 'text', NULL, 'admin', '2026-08-03 11:08:24'),
(8, 'items_per_page', '25', 'number', NULL, 'admin', '2026-08-03 11:08:24'),
(9, 'default_template', '0', 'number', NULL, 'admin', '2026-08-03 11:08:24'),
(10, 'enable_notifications', '0', 'boolean', NULL, 'admin', '2026-08-03 11:08:24'),
(11, 'maintenance_mode', '0', 'boolean', NULL, 'admin', '2026-08-03 11:08:24');

-- --------------------------------------------------------

--
-- Table structure for table `template_fields`
--

CREATE TABLE `template_fields` (
  `id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL,
  `field_key` varchar(64) DEFAULT NULL,
  `object_type` varchar(32) NOT NULL DEFAULT 'dynamic',
  `side` varchar(10) NOT NULL DEFAULT 'front',
  `x` decimal(7,3) NOT NULL DEFAULT 0.000,
  `y` decimal(7,3) NOT NULL DEFAULT 0.000,
  `width` decimal(7,3) NOT NULL DEFAULT 0.000,
  `height` decimal(7,3) NOT NULL DEFAULT 0.000,
  `visible` tinyint(1) DEFAULT 1,
  `archived_at` timestamp NULL DEFAULT NULL,
  `font_size` int(11) DEFAULT 12,
  `font_family` varchar(100) DEFAULT NULL,
  `font_weight` varchar(16) DEFAULT NULL,
  `font_style` varchar(16) DEFAULT NULL,
  `color` varchar(32) DEFAULT NULL,
  `text_align` enum('left','center','right') NOT NULL DEFAULT 'left',
  `text_decoration` varchar(32) DEFAULT NULL,
  `opacity` decimal(4,3) NOT NULL DEFAULT 1.000,
  `border_width` decimal(6,2) DEFAULT NULL,
  `border_color` varchar(32) DEFAULT NULL,
  `border_style` varchar(16) DEFAULT NULL,
  `border_radius` decimal(6,2) DEFAULT NULL,
  `show_label` tinyint(1) NOT NULL DEFAULT 1,
  `content` text DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `z_index` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `template_fields`
--

-- --------------------------------------------------------

--
-- Table structure for table `template_input_fields`
--

CREATE TABLE `template_input_fields` (
  `id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL DEFAULT 0,
  `field_key` varchar(80) NOT NULL,
  `field_label` varchar(120) NOT NULL,
  `field_type` varchar(32) NOT NULL DEFAULT 'text',
  `bilingual_mode` varchar(20) NOT NULL DEFAULT 'single',
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `archived_at` timestamp NULL DEFAULT NULL,
  `placeholder` varchar(190) DEFAULT NULL,
  `default_value` text DEFAULT NULL,
  `validation_rules` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `template_input_fields`
--


-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `organization_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `role_id` int(11) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `role` enum('admin','editor','viewer') DEFAULT 'viewer',
  `last_login` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `organization_id`, `username`, `password`, `email`, `mobile`, `full_name`, `avatar`, `role_id`, `status`, `role`, `last_login`, `deleted_at`, `created_at`, `updated_at`) VALUES
(1, 1, 'admin', '$2y$10$QeffgzsfSXXxQfQqKIjAiehqXv3lNjxTSbhGnU5M.X1uj/079JNdu', 'admin@example.com', NULL, 'Administrator', 'avatar_1_1785756043.png', 1, 1, 'admin', '2026-08-08 11:40:47', NULL, '2026-07-31 13:02:21', '2026-08-08 11:40:47'),
(2, 2, 'VIVIN', '$2y$10$9t4vyHA6oK2yncswv96NauG6h9U8GTjAt2upISc2jaJfvfZ/DLTx6', 'vijinvivin100@gmail.com', '+469443177546', 'v', 'usr_6a6ddcbc40ad89.11615872.jpg', 3, 1, '', '2026-07-31 15:18:15', '2026-08-01 11:50:28', '2026-07-31 15:17:58', '2026-08-01 11:50:28'),
(3, 2, 'VIVINX', '$2y$10$bgaga7wj193lhZnl.0GIWe7xrD8bwGQxMmHz.zei2yr56dDHAgxHW', 'vijinviXvin100@gmail.com', '+469443177546C', 'v', 'usr_6a6ddd5e389dd3.77934196.png', 3, 1, '', '2026-08-05 18:17:53', NULL, '2026-08-01 11:47:57', '2026-08-05 18:17:53'),
(4, 2, '743vS', '$2y$10$LiyHPPJ/RcT1M2KGVhweFOEeVUSVA6.CRuZ1ibekAOt3UqPbTRS1u', 'vijinvivSSin100@gmail.com', '+46944317754622', '743 Vijin', 'usr_6a6ddd78980c57.78169264.png', 3, 1, '', NULL, NULL, '2026-08-01 11:50:16', '2026-08-08 09:50:06');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_user` (`user_id`),
  ADD KEY `idx_audit_type` (`action_type`),
  ADD KEY `idx_audit_created` (`created_at`),
  ADD KEY `idx_audit_organization` (`organization_id`);

--
-- Indexes for table `backup_history`
--
ALTER TABLE `backup_history`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `card_templates`
--
ALTER TABLE `card_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_org_template` (`organization_id`),
  ADD KEY `idx_template_org_status` (`organization_id`,`status`,`deleted_at`);

--
-- Indexes for table `email_settings`
--
ALTER TABLE `email_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `failed_logins`
--
ALTER TABLE `failed_logins`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_ip_address` (`ip_address`),
  ADD KEY `idx_attempt_time` (`attempt_time`);

--
-- Indexes for table `generated_cards`
--
ALTER TABLE `generated_cards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_generated_member` (`member_id`),
  ADD KEY `idx_generated_template` (`template_id`),
  ADD KEY `idx_generated_created` (`created_at`),
  ADD KEY `idx_org_generated` (`organization_id`);

--
-- Indexes for table `id_members`
--
ALTER TABLE `id_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_member_unique_id` (`unique_id`),
  ADD KEY `idx_member_type` (`member_type`),
  ADD KEY `idx_member_name` (`name`),
  ADD KEY `idx_org_member` (`organization_id`),
  ADD KEY `idx_member_template` (`template_id`),
  ADD KEY `idx_member_deleted` (`deleted_at`);

--
-- Indexes for table `languages`
--
ALTER TABLE `languages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `language_code` (`language_code`);

--
-- Indexes for table `login_history`
--
ALTER TABLE `login_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_login_time` (`login_time`);

--
-- Indexes for table `member_dynamic_values`
--
ALTER TABLE `member_dynamic_values`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_member_dynamic_value` (`member_id`,`template_id`,`field_key`),
  ADD KEY `idx_member_dynamic_member` (`member_id`),
  ADD KEY `idx_member_dynamic_template` (`template_id`);

--
-- Indexes for table `member_field_translations`
--
ALTER TABLE `member_field_translations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_member_field_translation` (`member_id`,`template_field_id`,`language_code`),
  ADD KEY `idx_member_field_translation_member` (`member_id`),
  ADD KEY `idx_member_field_translation_field` (`template_field_id`);

--
-- Indexes for table `organizations`
--
ALTER TABLE `organizations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `organization_code` (`organization_code`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_module_perm` (`module_name`,`permission_name`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_role_perm` (`role_id`,`permission_id`),
  ADD KEY `fk_rp_permission` (`permission_id`);

--
-- Indexes for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_id` (`ticket_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `template_fields`
--
ALTER TABLE `template_fields`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tf_template_side` (`template_id`,`side`,`archived_at`);

--
-- Indexes for table `template_input_fields`
--
ALTER TABLE `template_input_fields`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_template_input_field` (`template_id`,`field_key`),
  ADD KEY `idx_tif_template_enabled` (`template_id`,`is_enabled`,`archived_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `fk_users_organization` (`organization_id`),
  ADD KEY `fk_users_role` (`role_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=273;

--
-- AUTO_INCREMENT for table `backup_history`
--
ALTER TABLE `backup_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `card_templates`
--
ALTER TABLE `card_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=80;

--
-- AUTO_INCREMENT for table `email_settings`
--
ALTER TABLE `email_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `failed_logins`
--
ALTER TABLE `failed_logins`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `generated_cards`
--
ALTER TABLE `generated_cards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `id_members`
--
ALTER TABLE `id_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `languages`
--
ALTER TABLE `languages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `login_history`
--
ALTER TABLE `login_history`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `member_dynamic_values`
--
ALTER TABLE `member_dynamic_values`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `member_field_translations`
--
ALTER TABLE `member_field_translations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `organizations`
--
ALTER TABLE `organizations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=204;

--
-- AUTO_INCREMENT for table `support_tickets`
--
ALTER TABLE `support_tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `template_fields`
--
ALTER TABLE `template_fields`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=728;

--
-- AUTO_INCREMENT for table `template_input_fields`
--
ALTER TABLE `template_input_fields`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `generated_cards`
--
ALTER TABLE `generated_cards`
  ADD CONSTRAINT `fk_generated_member` FOREIGN KEY (`member_id`) REFERENCES `id_members` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `member_dynamic_values`
--
ALTER TABLE `member_dynamic_values`
  ADD CONSTRAINT `fk_member_dynamic_member` FOREIGN KEY (`member_id`) REFERENCES `id_members` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_organization` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
