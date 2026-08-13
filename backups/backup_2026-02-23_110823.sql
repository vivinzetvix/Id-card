-- Backup generated on 2026-02-23 11:08:23
SET FOREIGN_KEY_CHECKS=0;



CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_type` (`action_type`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `audit_log` VALUES ('1', '1', 'Added new member: leo', 'add', 'Member ID: STU1557', '::1', NULL, '2026-02-23 12:23:49');
INSERT INTO `audit_log` VALUES ('2', '1', 'Added new member: leodas', 'add', 'Member ID: STU4712', '::1', NULL, '2026-02-23 12:29:52');
INSERT INTO `audit_log` VALUES ('3', '1', 'Exported members data', 'export', 'Exported 5 members to CSV', '::1', NULL, '2026-02-23 12:30:12');
INSERT INTO `audit_log` VALUES ('4', '1', 'Generated ID card saved', 'create', 'Saved/generated card for member ID 5 with template ID 1', '::1', NULL, '2026-02-23 12:31:14');
INSERT INTO `audit_log` VALUES ('5', '1', 'Bulk Upload', 'bulk_upload', 'Bulk import summary - total: 6, inserted: 6, skipped: 0, failed: 0', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 12:38:12');
INSERT INTO `audit_log` VALUES ('6', '1', 'Generated ID card saved', 'create', 'Saved/generated card for member ID 10 with template ID 2', '::1', NULL, '2026-02-23 12:39:39');
INSERT INTO `audit_log` VALUES ('7', '1', 'Generated ID card saved', 'create', 'Saved/generated card for member ID 1 with template ID 4', '::1', NULL, '2026-02-23 12:43:32');
INSERT INTO `audit_log` VALUES ('8', '1', 'Generated ID card deleted', 'delete', 'Deleted generated card for member ID 1', '::1', NULL, '2026-02-23 12:43:50');
INSERT INTO `audit_log` VALUES ('9', '1', 'Cleared old audit logs', 'delete', 'Deleted 0 logs older than 90 days', '::1', NULL, '2026-02-23 13:34:54');
INSERT INTO `audit_log` VALUES ('10', '1', 'Added new member: leodas', 'add', 'Member ID: STU5524', '::1', NULL, '2026-02-23 15:20:08');
INSERT INTO `audit_log` VALUES ('11', '1', 'Bulk Upload', 'bulk_upload', 'Bulk import summary - total: 6, inserted: 0, skipped: 6, failed: 0', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 15:20:59');
INSERT INTO `audit_log` VALUES ('12', '1', 'Generated ID card saved', 'create', 'Saved/generated card for member ID 1 with template ID 1', '::1', NULL, '2026-02-23 15:22:16');
INSERT INTO `audit_log` VALUES ('13', '1', 'Generated ID card deleted', 'delete', 'Deleted generated card for member ID 1', '::1', NULL, '2026-02-23 15:22:20');
INSERT INTO `audit_log` VALUES ('14', '1', 'Generated ID card saved', 'create', 'Saved/generated card for member ID 4 with template ID 4', '::1', NULL, '2026-02-23 15:24:34');
INSERT INTO `audit_log` VALUES ('15', '1', 'Backup restore failed: id.sql', 'restore', 'Restore failed. Blocked unsupported or unsafe SQL command in backup file.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 15:37:24');


CREATE TABLE `backup_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `tables` text DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE `card_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `description` varchar(255) NOT NULL,
  `primary_color` varchar(120) NOT NULL,
  `secondary_color` varchar(120) NOT NULL,
  `text_color` varchar(20) NOT NULL DEFAULT '#ffffff',
  `font` varchar(80) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `downloads` int(11) NOT NULL DEFAULT 0,
  `rating` decimal(2,1) NOT NULL DEFAULT 5.0,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE `email_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mail_type` enum('smtp','sendmail','mail') DEFAULT 'mail',
  `smtp_host` varchar(255) DEFAULT NULL,
  `smtp_port` int(11) DEFAULT 587,
  `smtp_encryption` enum('tls','ssl','none') DEFAULT 'tls',
  `smtp_username` varchar(255) DEFAULT NULL,
  `smtp_password` varchar(255) DEFAULT NULL,
  `from_email` varchar(255) DEFAULT NULL,
  `from_name` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE `generated_cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_generated_member` (`member_id`),
  KEY `idx_generated_template` (`template_id`),
  KEY `idx_generated_created` (`created_at`),
  CONSTRAINT `fk_generated_member` FOREIGN KEY (`member_id`) REFERENCES `id_members` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `generated_cards` VALUES ('1', '5', '1', 'saved_cards/card_5_1771830074.png', '2026-02-23 12:31:14');
INSERT INTO `generated_cards` VALUES ('2', '10', '2', 'saved_cards/card_10_1771830579.png', '2026-02-23 12:39:39');
INSERT INTO `generated_cards` VALUES ('5', '4', '4', 'saved_cards/card_4_1771840474.png', '2026-02-23 15:24:34');


CREATE TABLE `id_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_unique_id` (`unique_id`),
  KEY `idx_member_type` (`member_type`),
  KEY `idx_member_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `id_members` VALUES ('1', 'student', 'REG001', 'Arun Kumar', 'Ramesh Kumar', '10-A', NULL, NULL, NULL, NULL, '2008-05-12', 'Chennai, Tamil Nadu', '9876543210', 'arun@gmail.com', '2024-06-01', '2026-06-01', 'arun.jpg', '2026-02-23 11:00:23', '2026-02-23 11:00:23');
INSERT INTO `id_members` VALUES ('2', 'employee', 'EMP1002', 'Priya Devi', NULL, NULL, 'Administration', 'Accounts Officer', NULL, NULL, '1990-09-25', 'Madurai, Tamil Nadu', '9898989898', 'priya@gmail.com', '2024-06-01', '2026-06-01', 'priya.jpg', '2026-02-23 11:00:23', '2026-02-23 11:00:23');
INSERT INTO `id_members` VALUES ('3', 'visitor', 'VIS3001', 'Karthik Raj', NULL, NULL, NULL, NULL, 'Acme Corp', 'Facility tour', NULL, 'Coimbatore, Tamil Nadu', '9797979797', 'karthik@gmail.com', '2024-06-01', '2024-06-01', 'karthik.jpg', '2026-02-23 11:00:23', '2026-02-23 11:00:23');
INSERT INTO `id_members` VALUES ('4', 'student', 'STU1557', 'leo', '', '', '', '', '', '', NULL, 'asdofjosnfojoiwjf', '39048509384', 'info.themeinnova@gmail.com', '2026-02-23', '2027-02-23', '699bf97d320c9_1771829629.jpg', '2026-02-23 12:23:49', '2026-02-23 12:23:49');
INSERT INTO `id_members` VALUES ('5', 'faculty', 'STU4712', 'leodas', '', '', 'gedg', 'dfgdfg', '', '', '2000-05-12', 'sarfefvgsfe', '2342343423', 'email@gmail.com', '2026-02-23', '2027-02-23', '699bfae8b87b4_1771829992.jpg', '2026-02-23 12:29:52', '2026-02-23 12:29:52');
INSERT INTO `id_members` VALUES ('6', 'student', 'STU001', 'Ravi Kumar', 'Arun Kumar', '10-A', '', '', '', '', '2008-04-12', 'Chennai', '9876543210', 'ravi@example.com', '2024-06-01', '2026-06-01', NULL, '2026-02-23 12:38:12', '2026-02-23 12:38:12');
INSERT INTO `id_members` VALUES ('7', 'employee', 'EMP007', 'Meena Raj', '', '', 'HR', 'Manager', '', '', '1990-09-22', 'Coimbatore', '9876543211', 'meena@example.com', '2024-06-01', '2026-06-01', NULL, '2026-02-23 12:38:12', '2026-02-23 12:38:12');
INSERT INTO `id_members` VALUES ('8', 'staff', 'STF020', 'Karthik', '', '', 'Admin', 'Clerk', '', '', '1988-01-15', 'Salem', '9876543212', 'karthik@example.com', '2024-06-01', '2026-06-01', NULL, '2026-02-23 12:38:12', '2026-02-23 12:38:12');
INSERT INTO `id_members` VALUES ('9', 'faculty', 'FAC100', 'Suresh', '', '', 'Science', 'Professor', '', '', '1982-05-18', 'Madurai', '9876543213', 'suresh@example.com', '2024-06-01', '2026-06-01', NULL, '2026-02-23 12:38:12', '2026-02-23 12:38:12');
INSERT INTO `id_members` VALUES ('10', 'visitor', 'VIS500', 'Anita', '', '', '', '', 'Tech Corp', 'Interview', NULL, 'Trichy', '9876543214', 'anita@example.com', '2024-06-01', '2024-06-01', NULL, '2026-02-23 12:38:12', '2026-02-23 12:38:12');
INSERT INTO `id_members` VALUES ('11', 'office', 'OFF300', 'Prakash', '', '', 'Operations', 'Supervisor', '', '', '1985-07-20', 'Erode', '9876543215', 'prakash@example.com', '2024-06-01', '2026-06-01', NULL, '2026-02-23 12:38:12', '2026-02-23 12:38:12');
INSERT INTO `id_members` VALUES ('12', 'student', 'STU5524', 'leodas', 'wefaefwaef', '10', '', '', '', '', '0200-05-12', 'fwefwef', 'drgerer', 'sdfrfgerg@gmail.com', '2026-02-23', '2027-02-23', NULL, '2026-02-23 15:20:08', '2026-02-23 15:20:08');


CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('text','number','boolean','json','color') DEFAULT 'text',
  `description` text DEFAULT NULL,
  `updated_by` varchar(50) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `system_settings` VALUES ('1', 'organization_name', 'ABC ', 'text', NULL, 'admin', '2026-02-23 12:28:12');
INSERT INTO `system_settings` VALUES ('2', 'organization_address', '', 'text', NULL, 'admin', '2026-02-23 12:28:12');
INSERT INTO `system_settings` VALUES ('3', 'organization_phone', '', 'text', NULL, 'admin', '2026-02-23 12:28:12');
INSERT INTO `system_settings` VALUES ('4', 'organization_email', '', 'text', NULL, 'admin', '2026-02-23 12:28:12');
INSERT INTO `system_settings` VALUES ('5', 'organization_website', '', 'text', NULL, 'admin', '2026-02-23 12:28:12');
INSERT INTO `system_settings` VALUES ('6', 'school_name', 'ABC ', 'text', NULL, 'admin', '2026-02-23 12:28:12');
INSERT INTO `system_settings` VALUES ('7', 'school_address', '', 'text', NULL, 'admin', '2026-02-23 12:28:12');
INSERT INTO `system_settings` VALUES ('8', 'school_phone', '', 'text', NULL, 'admin', '2026-02-23 12:28:12');
INSERT INTO `system_settings` VALUES ('9', 'school_email', '', 'text', NULL, 'admin', '2026-02-23 12:28:12');
INSERT INTO `system_settings` VALUES ('10', 'school_website', '', 'text', NULL, 'admin', '2026-02-23 12:28:12');
INSERT INTO `system_settings` VALUES ('11', 'date_format', 'd/m/Y', 'text', NULL, 'admin', '2026-02-23 12:28:12');
INSERT INTO `system_settings` VALUES ('12', 'timezone', 'Asia/Kolkata', 'text', NULL, 'admin', '2026-02-23 12:28:12');
INSERT INTO `system_settings` VALUES ('13', 'items_per_page', '25', 'number', NULL, 'admin', '2026-02-23 12:28:12');
INSERT INTO `system_settings` VALUES ('14', 'default_template', '1', 'number', NULL, 'admin', '2026-02-23 12:28:12');
INSERT INTO `system_settings` VALUES ('15', 'enable_notifications', '0', 'boolean', NULL, 'admin', '2026-02-23 12:28:12');
INSERT INTO `system_settings` VALUES ('16', 'maintenance_mode', '0', 'boolean', NULL, 'admin', '2026-02-23 12:28:12');


CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `role` enum('admin','editor','viewer') DEFAULT 'viewer',
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `users` VALUES ('1', 'admin', '$2y$10$QeffgzsfSXXxQfQqKIjAiehqXv3lNjxTSbhGnU5M.X1uj/079JNdu', 'admin@example.com', 'Administrator', 'avatar_1_83852dea5d11deb4b1c8905e24bc5575.jpg', 'admin', NULL, '2026-02-23 11:00:23', '2026-02-23 15:35:47');

SET FOREIGN_KEY_CHECKS=1;
