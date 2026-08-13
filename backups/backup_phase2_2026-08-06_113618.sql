-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: id
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=122 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_log`
--

LOCK TABLES `audit_log` WRITE;
/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
INSERT INTO `audit_log` VALUES (1,1,'Created organization','organization','Created organization ABC Inter','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-07-31 13:50:54'),(2,1,'Role Created','roles','Created role: \'g66666\' (ID: 4)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-07-31 14:06:46'),(3,1,'Role Created','roles','Created role: \'fdfdf\' (ID: 5)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-07-31 14:21:21'),(4,1,'Role Created','roles','Created role: \'cas\' (ID: 6)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-07-31 15:00:02'),(5,1,'Role Permissions Updated','roles','Updated permissions for role \'cas\' (ID: 6). Count: 6','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-07-31 15:00:09'),(6,1,'User Created','users','Created user: \'VIVIN\' (ID: 2) with role \'Registrar\'','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-07-31 15:17:58'),(7,1,'User Logout','auth','User \'admin\' logged out from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-07-31 15:18:06'),(8,2,'User Login','auth','Successful login: \'VIVIN\' (Role: Registrar) from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-07-31 15:18:15'),(9,2,'User Logout','auth','User \'VIVIN\' logged out from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 05:04:55'),(10,1,'User Login','auth','Successful login: \'admin\' (Role: Super Admin) from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 05:05:02'),(11,1,'User Login','auth','Successful login: \'admin\' (Role: Super Admin) from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 07:05:24'),(12,1,'Role Updated','roles','Updated role ID 6: \'cas\'','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:35:38'),(13,1,'Role Updated','roles','Updated role ID 6: \'hello\'','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:35:44'),(14,1,'Role Permissions Updated','roles','Updated permissions for role \'hello\' (ID: 6). Count: 49','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:35:54'),(15,1,'Role Created','roles','Created role: \'Finace\' (ID: 7)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:36:12'),(16,1,'Role Status Toggled','roles','Deactivated role \'Finace\' (ID: 7)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:36:19'),(17,1,'Role Status Toggled','roles','Activated role \'Finace\' (ID: 7)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:36:21'),(18,1,'Role Deleted','roles','Deleted role: \'hello\' (ID: 6)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:37:09'),(19,1,'Role Deleted','roles','Deleted role: \'fdfdf\' (ID: 5)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:37:12'),(20,1,'Role Deleted','roles','Deleted role: \'g66666\' (ID: 4)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:37:14'),(21,1,'User Updated','users','Updated user ID 2: \'VIVIN\'','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:42:52'),(22,1,'User Updated','users','Updated user ID 2: \'VIVIN\'','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:46:49'),(23,1,'User Updated','users','Updated user ID 2: \'VIVIN\'','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:46:57'),(24,1,'User Updated','users','Updated user ID 2: \'VIVIN\'','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:47:08'),(25,1,'User Created','users','Created user: \'VIVINX\' (ID: 3) with role \'Registrar\'','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:47:57'),(26,1,'User Updated','users','Updated user ID 3: \'VIVINX\'','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:49:50'),(27,1,'User Created','users','Created user: \'743vS\' (ID: 4) with role \'Registrar\'','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:50:16'),(28,1,'User Deleted','users','Soft-deleted user \'VIVIN\' (ID: 2)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:50:28'),(29,1,'User Status Toggled','users','Deactivated user \'VIVINX\' (ID: 3)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:50:34'),(30,1,'Created organization','organization','Created organization ABC',NULL,NULL,'2026-08-01 11:55:21'),(31,1,'Updated organization','organization','Updated organization ABC',NULL,NULL,'2026-08-01 11:56:33'),(32,1,'Updated organization','organization','Updated organization ABC',NULL,NULL,'2026-08-01 11:56:41'),(33,1,'Updated organization status','organization','Set organization status to 0','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:56:43'),(34,1,'Updated organization status','organization','Set organization status to 1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:56:45'),(35,1,'Updated organization','organization','Updated organization ABC',NULL,NULL,'2026-08-01 11:57:48'),(36,1,'Created organization','organization','Created organization ABC InterS',NULL,NULL,'2026-08-01 11:58:10'),(37,1,'Updated organization status','organization','Set organization status to 0','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:59:29'),(38,1,'Updated organization status','organization','Set organization status to 1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:59:32'),(39,1,'Updated organization status','organization','Set organization status to 0','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:59:36'),(40,1,'Updated organization status','organization','Set organization status to 1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:59:37'),(41,1,'Updated organization status','organization','Set organization status to 0','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 11:59:38'),(42,1,'Deleted organization','organization','Soft deleted organization 4','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 12:01:04'),(43,1,'Password Reset','users','Reset password for user ID 3 (\'VIVINX\')','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 12:25:15'),(44,1,'User Status Toggled','users','Activated user \'VIVINX\' (ID: 3)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 12:25:18'),(45,1,'User Logout','auth','User \'admin\' logged out from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 12:25:55'),(46,NULL,'Login Failed','auth','Failed login attempt for username: \'VIVINV\' from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 12:26:06'),(47,3,'User Login','auth','Successful login: \'VIVINX\' (Role: Registrar) from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 12:26:17'),(48,3,'User Login','auth','Successful login: \'VIVINX\' (Role: Registrar) from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 13:42:02'),(49,3,'User Logout','auth','User \'VIVINX\' logged out from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 13:44:07'),(50,1,'User Login','auth','Successful login: \'admin\' (Role: Super Admin) from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 13:44:17'),(51,1,'Role Permissions Updated','roles','Updated permissions for role \'Super Admin\' (ID: 1). Count: 49','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 13:51:00'),(52,1,'User Logout','auth','User \'admin\' logged out from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 14:28:55'),(53,NULL,'Login Failed','auth','Failed login attempt for username: \'VIVINV\' from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 14:29:05'),(54,3,'User Login','auth','Successful login: \'VIVINX\' (Role: Registrar) from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 14:29:15'),(55,3,'User Logout','auth','User \'VIVINX\' logged out from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 14:37:16'),(56,1,'User Login','auth','Successful login: \'admin\' (Role: Super Admin) from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-01 14:37:24'),(57,1,'User Login','auth','Successful login: \'admin\' (Role: Super Admin) from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-02 03:53:23'),(58,1,'User Login','auth','Successful login: \'admin\' (Role: Super Admin) from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 09:18:22'),(59,1,'User Login','auth','Successful login: \'admin\' (Role: Super Admin) from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 10:27:58'),(60,1,'Updated general settings','settings','General settings updated','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 11:08:24'),(61,1,'Updated avatar','users','Profile avatar updated','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 11:20:25'),(62,1,'Updated avatar','users','Profile avatar updated','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 11:20:43'),(63,1,'User Login','auth','Successful login: \'admin\' (Role: Super Admin) from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 11:53:52'),(64,1,'Duplicated template','templates','Original ID: 59, New ID: 60','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 12:16:49'),(65,1,'Set default template','templates','Template ID: 59','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 12:16:54'),(66,1,'Created template','templates','Template ID: 61, Name: vivin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 12:32:04'),(67,1,'Created template','templates','Template ID: 62, Name: vivin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 12:32:55'),(68,1,'Deleted template','templates','Template ID: 58','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 14:26:46'),(69,1,'Created template','templates','Template ID: 63, Name: vivin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 14:59:54'),(70,1,'Added template input field','templates','Template: 63, Field: field_1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 15:01:53'),(71,1,'Updated template','templates','Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 15:07:20'),(72,1,'Updated template','templates','Template ID: 14','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 15:08:40'),(73,1,'Duplicated template','templates','Original ID: 63, New ID: 64','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 15:10:07'),(74,1,'Generated ID card','cards','Member ID: 1, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 16:55:31'),(75,1,'Generated ID card','cards','Member ID: 1, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 17:03:10'),(76,1,'Generated ID card','cards','Member ID: 1, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 17:03:14'),(77,1,'Generated ID card','cards','Member ID: 1, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 17:03:18'),(78,1,'Generated ID card','cards','Member ID: 1, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 17:03:25'),(79,1,'Generated ID card','cards','Member ID: 1, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 17:04:11'),(80,1,'Generated ID card','cards','Member ID: 1, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 17:10:42'),(81,1,'Generated ID card','cards','Member ID: 1, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 17:11:08'),(82,1,'Generated ID card','cards','Member ID: 1, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 17:12:01'),(83,1,'Generated ID card','cards','Member ID: 1, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 17:17:54'),(84,1,'Generated ID card','cards','Member ID: 1, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 17:17:59'),(85,1,'Generated ID card','cards','Member ID: 5, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 17:20:22'),(86,1,'Generated ID card','cards','Member ID: 8, Template ID: 14','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 17:26:32'),(87,1,'Generated ID card','cards','Member ID: 1, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 17:34:39'),(88,1,'Generated ID card','cards','Member ID: 1, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 17:34:43'),(89,1,'Generated ID card','cards','Member ID: 2, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 17:35:40'),(90,1,'Generated ID card','cards','Member ID: 8, Template ID: 14','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 17:38:22'),(91,1,'Generated ID card','cards','Member ID: 1, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 17:47:39'),(92,1,'Generated ID card','cards','Member ID: 1, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 18:02:35'),(93,1,'Updated template layout','templates','Template ID: 63, Fields updated: 8','::1','Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-03 18:06:05'),(94,1,'Updated template layout','templates','Template ID: 63, Fields updated: 8','::1','Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-03 18:06:07'),(95,1,'Updated template layout','templates','Template ID: 63, Fields updated: 8','::1','Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-03 18:06:09'),(96,1,'Updated template layout','templates','Template ID: 63, Fields updated: 8','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 18:06:12'),(97,1,'Updated template layout','templates','Template ID: 63, Fields updated: 8','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 18:06:26'),(98,1,'Updated template layout','templates','Template ID: 63, Fields updated: 8','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 18:06:36'),(99,1,'Updated template layout','templates','Template ID: 63, Fields updated: 8','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 18:07:42'),(100,1,'Generated ID card','cards','Member ID: 1, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 18:08:03'),(101,1,'Generated ID card','cards','Member ID: 5, Template ID: 63','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 18:09:59'),(102,1,'Updated template layout','templates','Template ID: 63, Fields updated: 9','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 18:16:41'),(103,1,'Updated template layout','templates','Template ID: 63, Fields updated: 9','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 18:33:47'),(104,1,'Added template input field','templates','Template: 63, Field: field_2','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 18:35:18'),(105,1,'Added template field','templates','Template: 63, Field: field_10','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 18:38:19'),(106,1,'Updated template layout','templates','Template ID: 63, Fields updated: 13','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 19:14:08'),(107,1,'Updated template layout','templates','Template ID: 63, Fields updated: 13','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 19:17:22'),(108,1,'Updated template layout','templates','Template ID: 63, Fields updated: 10','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 19:19:06'),(109,1,'Updated template layout','templates','Template ID: 63, Fields updated: 10','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 19:19:08'),(110,1,'Updated template layout','templates','Template ID: 63, Fields updated: 10','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-03 19:19:11'),(111,1,'User Login','auth','Successful login: \'admin\' (Role: Super Admin) from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-05 18:17:28'),(112,1,'User Logout','auth','User \'admin\' logged out from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-05 18:17:34'),(113,NULL,'Login Failed','auth','Failed login attempt for username: \'VIVINX\' from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-05 18:17:44'),(114,3,'User Login','auth','Successful login: \'VIVINX\' (Role: Registrar) from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-05 18:17:53'),(115,3,'User Logout','auth','User \'VIVINX\' logged out from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-05 18:20:35'),(116,1,'User Login','auth','Successful login: \'admin\' (Role: Super Admin) from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-05 18:20:48'),(117,1,'Duplicated template','templates','Original ID: 63, New ID: 65','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-05 18:24:40'),(118,1,'User Login','auth','Successful login: \'admin\' (Role: Super Admin) from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-06 03:57:29'),(119,1,'Updated template layout','templates','Template ID: 63, Fields updated: 12','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-06 04:08:16'),(120,1,'User Login','auth','Successful login: \'admin\' (Role: Super Admin) from IP: ::1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-06 05:45:10'),(121,1,'Updated template layout','templates','Template ID: 63, Fields updated: 12','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-06 05:58:10');
/*!40000 ALTER TABLE `audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `backup_history`
--

DROP TABLE IF EXISTS `backup_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `backup_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `tables` text DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `backup_history`
--

LOCK TABLES `backup_history` WRITE;
/*!40000 ALTER TABLE `backup_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `backup_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `card_templates`
--

DROP TABLE IF EXISTS `card_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `card_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `mirror_print` tinyint(1) DEFAULT 0,
  `status` tinyint(1) DEFAULT 1,
  `font` varchar(80) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `downloads` int(11) NOT NULL DEFAULT 0,
  `rating` decimal(2,1) NOT NULL DEFAULT 5.0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_org_template` (`organization_id`)
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `card_templates`
--

LOCK TABLES `card_templates` WRITE;
/*!40000 ALTER TABLE `card_templates` DISABLE KEYS */;
INSERT INTO `card_templates` VALUES (14,1,'Modern Red Copy','Custom template created by admin','#dc2626','#fbbf24','#ffffff','portrait',0,0,NULL,0,1,'Poppins',0,0,5.0,1,'2026-08-01 09:31:54',1,NULL),(15,1,'Professional Green Copy','Custom template created by admin','#059669','#fbbf24','#ffffff','landscape',NULL,NULL,NULL,0,1,'Lato',0,0,5.0,1,'2026-08-01 13:50:01',NULL,NULL),(23,1,'Classic Blue Copy','Custom template created by admin','#2563eb','#fbbf24','#ffffff','landscape',NULL,NULL,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-01 13:59:12',NULL,NULL),(38,1,'Classic Blue Copy Copy','Custom template created by admin','#2563eb','#fbbf24','#ffffff','portrait',NULL,NULL,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-01 14:24:44',NULL,NULL),(41,1,'Modern Red Copy Copy','Custom template created by admin','#dc2626','#fbbf24','#ffffff','portrait',NULL,NULL,NULL,0,1,'Poppins',0,0,5.0,1,'2026-08-01 14:45:05',NULL,NULL),(45,1,'Classic Blue Copy','Custom template created by admin','#2563eb','#fbbf24','#ffffff','portrait',1,1,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-01 15:26:07',NULL,NULL),(46,1,'Classic Blue Copy','Custom template created by admin','#2563eb','#fbbf24','#ffffff','portrait',11,1,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-01 15:26:27',NULL,NULL),(47,1,'Classic Blue Copy','Custom template created by admin','#2563eb','#fbbf24','#ffffff','portrait',1,1,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-01 15:32:14',NULL,NULL),(48,1,'Classic Blue Copy','Custom template created by admin','#2563eb','#fbbf24','#ffffff','portrait',1,1,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-01 15:32:28',NULL,NULL),(49,1,'Classic Blue Copy','Custom template created by admin','#2563eb','#fbbf24','#ffffff','portrait',11,11,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-01 15:34:59',NULL,NULL),(50,1,'Classic Blue Copy','Custom template created by admin','#2563eb','#fbbf24','#ffffff','portrait',22,2,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-01 15:35:08',NULL,NULL),(51,1,'Classic Blue Copy','Custom template created by admin','#2563eb','#fbbf24','#ffffff','portrait',22,22,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-01 15:35:16',NULL,NULL),(52,1,'Classic Blue Copy','Custom template created by admin','#2563eb','#fbbf24','#ffffff','portrait',11,11,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-01 15:35:28',NULL,NULL),(53,1,'Classic Blue Copy','Custom template created by admin','#2563eb','#fbbf24','#ffffff','portrait',8,4,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-01 15:35:41',NULL,NULL),(54,1,'Classic Blue Copy','Custom template created by admin','#2563eb','#fbbf24','#ffffff','portrait',1,1,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-01 15:47:19',NULL,NULL),(55,1,'Classic Blue Copy','Custom template created by admin','#2563eb','#fbbf24','#ffffff','landscape',9,5,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-01 15:47:39',NULL,NULL),(56,1,'Classic Blue Copy','Custom template created by admin','#2563eb','#fbbf24','#ffffff','landscape',9,5,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-01 15:47:49',NULL,NULL),(57,1,'qqqqqqq','Custom template created by admin','#000000','#fbbf24','#ffffff','landscape',1,2,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-01 15:48:19',NULL,NULL),(58,1,'qqqqqqq Copy','Custom template created by admin','#000000','#fbbf24','#ffffff','landscape',2,2,NULL,0,0,'Inter',0,0,5.0,1,'2026-08-01 15:48:49',NULL,'2026-08-03 14:26:46'),(59,1,'Classic Blue Copy','Custom template created by admin','#2563eb','#fbbf24','#ffffff','portrait',5,9,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-01 16:01:02',NULL,NULL),(60,1,'Classic Blue Copy (Copy)','Custom template created by admin','#2563eb','#fbbf24','#ffffff','portrait',5,9,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-03 08:46:49',NULL,NULL),(61,0,'vivin','Hello','#0a1a2f','#1e3a5f','#ffffff','portrait',NULL,NULL,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-03 12:32:04',NULL,NULL),(62,0,'vivin','Hello','#0a1a2f','#1e3a5f','#ffffff','portrait',NULL,NULL,NULL,0,1,'Inter',0,0,5.0,1,'2026-08-03 12:32:55',NULL,NULL),(63,0,'vivin','','#44ff00','#006eff','#ff0000','portrait',0,0,NULL,1,1,'Inter',1,0,5.0,1,'2026-08-03 14:59:54',1,NULL),(64,0,'vivin (Copy)','','#44ff00','#006eff','#ff0000','portrait',0,0,NULL,1,1,'Inter',0,0,5.0,1,'2026-08-03 11:40:07',1,NULL),(65,0,'vivin (Copy)','','#44ff00','#006eff','#ff0000','portrait',0,0,NULL,1,1,'Inter',0,0,5.0,1,'2026-08-05 14:54:40',1,NULL);
/*!40000 ALTER TABLE `card_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `email_settings`
--

DROP TABLE IF EXISTS `email_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `email_settings`
--

LOCK TABLES `email_settings` WRITE;
/*!40000 ALTER TABLE `email_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `email_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_logins`
--

DROP TABLE IF EXISTS `failed_logins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_logins` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `attempt_time` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_username` (`username`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_attempt_time` (`attempt_time`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_logins`
--

LOCK TABLES `failed_logins` WRITE;
/*!40000 ALTER TABLE `failed_logins` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_logins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `generated_cards`
--

DROP TABLE IF EXISTS `generated_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `generated_cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `organization_id` int(11) DEFAULT NULL,
  `member_id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_generated_member` (`member_id`),
  KEY `idx_generated_template` (`template_id`),
  KEY `idx_generated_created` (`created_at`),
  KEY `idx_org_generated` (`organization_id`),
  CONSTRAINT `fk_generated_member` FOREIGN KEY (`member_id`) REFERENCES `id_members` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `generated_cards`
--

LOCK TABLES `generated_cards` WRITE;
/*!40000 ALTER TABLE `generated_cards` DISABLE KEYS */;
INSERT INTO `generated_cards` VALUES (1,NULL,1,63,'images/cards/card_1_1785780483.svg','2026-08-03 18:08:03'),(20,NULL,5,63,'images/cards/card_5_1785780599.svg','2026-08-03 18:09:59'),(21,NULL,8,14,'images/cards/card_8_1785778702.svg','2026-08-03 17:38:22'),(24,NULL,2,63,'images/cards/card_2_1785778540.svg','2026-08-03 17:35:40');
/*!40000 ALTER TABLE `generated_cards` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `id_members`
--

DROP TABLE IF EXISTS `id_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `id_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_unique_id` (`unique_id`),
  KEY `idx_member_type` (`member_type`),
  KEY `idx_member_name` (`name`),
  KEY `idx_org_member` (`organization_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `id_members`
--

LOCK TABLES `id_members` WRITE;
/*!40000 ALTER TABLE `id_members` DISABLE KEYS */;
INSERT INTO `id_members` VALUES (1,NULL,NULL,NULL,'en','student','REG001','Arun Kumar','Ramesh Kumar','10-A',NULL,NULL,NULL,NULL,'2008-05-12','Chennai, Tamil Nadu','9876543210','arun@gmail.com','2024-06-01','2026-06-01','arun.jpg',NULL,'2026-07-31 13:02:21','2026-07-31 13:02:21',NULL),(2,NULL,NULL,NULL,'en','employee','EMP1002','Priya Devi',NULL,NULL,'Administration','Accounts Officer',NULL,NULL,'1990-09-25','Madurai, Tamil Nadu','9898989898','priya@gmail.com','2024-06-01','2026-06-01','priya.jpg',NULL,'2026-07-31 13:02:21','2026-07-31 13:02:21',NULL),(3,NULL,NULL,NULL,'en','visitor','VIS3001','Karthik Raj',NULL,NULL,NULL,NULL,'Acme Corp','Facility tour',NULL,'Coimbatore, Tamil Nadu','9797979797','karthik@gmail.com','2024-06-01','2024-06-01','karthik.jpg',NULL,'2026-07-31 13:02:21','2026-07-31 13:02:21',NULL),(5,1,0,NULL,'en','student','STU2024002','Jane Smith','','10-B','Science','','','Student ID','2005-05-20','456 School Rd','9876543211','jane@example.com','2024-06-01','2026-05-31',NULL,NULL,'2026-08-03 10:52:40','2026-08-03 10:52:40',NULL),(6,1,0,NULL,'en','staff','STA2024003','Robert Johnson','','','Administration','Admin Staff','','','1985-10-10','789 Office Ln','9876543212','robert@example.com','2024-01-15','2025-12-31',NULL,NULL,'2026-08-03 10:52:40','2026-08-03 10:52:40',NULL),(8,1,14,NULL,'en','visitor','VIS2024005','David Brown','FDS','mca','MCA','Supervisor','Guest','Visitor','1988-07-07','555 Guest Rd','9876543214','david@example.com','2024-03-01','2026-10-13','member_1785754438_6a707346ad40c.jpg',NULL,'2026-08-03 10:52:40','2026-08-06 05:58:46',NULL);
/*!40000 ALTER TABLE `id_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `languages`
--

DROP TABLE IF EXISTS `languages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `languages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `language_code` varchar(20) NOT NULL,
  `language_name` varchar(100) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `language_code` (`language_code`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `languages`
--

LOCK TABLES `languages` WRITE;
/*!40000 ALTER TABLE `languages` DISABLE KEYS */;
INSERT INTO `languages` VALUES (1,'lang1','Language 1',1,1,'2026-08-01 08:49:56','2026-08-01 08:49:56'),(2,'lang2','Language 2',0,1,'2026-08-01 08:49:56','2026-08-01 08:49:56');
/*!40000 ALTER TABLE `languages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_history`
--

DROP TABLE IF EXISTS `login_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `user_agent` text DEFAULT NULL,
  `browser` varchar(100) DEFAULT NULL,
  `os` varchar(100) DEFAULT NULL,
  `login_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `logout_time` timestamp NULL DEFAULT NULL,
  `status` enum('success','failed','locked') NOT NULL DEFAULT 'success',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_username` (`username`),
  KEY `idx_login_time` (`login_time`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_history`
--

LOCK TABLES `login_history` WRITE;
/*!40000 ALTER TABLE `login_history` DISABLE KEYS */;
INSERT INTO `login_history` VALUES (1,2,'VIVIN','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-07-31 15:18:15','2026-08-01 05:04:55','success'),(2,1,'admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-01 05:05:02',NULL,'success'),(3,1,'admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-01 07:05:24','2026-08-01 12:25:55','success'),(4,NULL,'VIVINV','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-01 12:26:06',NULL,'failed'),(5,3,'VIVINX','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-01 12:26:17',NULL,'success'),(6,3,'VIVINX','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-01 13:42:01','2026-08-01 13:44:07','success'),(7,1,'admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-01 13:44:17','2026-08-01 14:28:55','success'),(8,NULL,'VIVINV','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-01 14:29:05',NULL,'failed'),(9,3,'VIVINX','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-01 14:29:15','2026-08-01 14:37:16','success'),(10,1,'admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-01 14:37:24',NULL,'success'),(11,1,'admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-02 03:53:23',NULL,'success'),(12,1,'admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-03 09:18:22',NULL,'success'),(13,1,'admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-03 10:27:58',NULL,'success'),(14,1,'admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-03 11:53:52',NULL,'success'),(15,1,'admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-05 18:17:28','2026-08-05 18:17:34','success'),(16,NULL,'VIVINX','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-05 18:17:44',NULL,'failed'),(17,3,'VIVINX','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-05 18:17:53','2026-08-05 18:20:35','success'),(18,1,'admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-05 18:20:48',NULL,'success'),(19,1,'admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-06 03:57:29',NULL,'success'),(20,1,'admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','Google Chrome','Windows 10/11','2026-08-06 05:45:10',NULL,'success');
/*!40000 ALTER TABLE `login_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `member_dynamic_values`
--

DROP TABLE IF EXISTS `member_dynamic_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `member_dynamic_values` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL DEFAULT 0,
  `field_key` varchar(80) NOT NULL,
  `field_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_member_dynamic_value` (`member_id`,`template_id`,`field_key`),
  KEY `idx_member_dynamic_member` (`member_id`),
  KEY `idx_member_dynamic_template` (`template_id`),
  CONSTRAINT `fk_member_dynamic_member` FOREIGN KEY (`member_id`) REFERENCES `id_members` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `member_dynamic_values`
--

LOCK TABLES `member_dynamic_values` WRITE;
/*!40000 ALTER TABLE `member_dynamic_values` DISABLE KEYS */;
/*!40000 ALTER TABLE `member_dynamic_values` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `member_field_translations`
--

DROP TABLE IF EXISTS `member_field_translations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `member_field_translations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `template_field_id` int(11) NOT NULL,
  `language_code` varchar(20) NOT NULL,
  `translated_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_member_field_translation` (`member_id`,`template_field_id`,`language_code`),
  KEY `idx_member_field_translation_member` (`member_id`),
  KEY `idx_member_field_translation_field` (`template_field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `member_field_translations`
--

LOCK TABLES `member_field_translations` WRITE;
/*!40000 ALTER TABLE `member_field_translations` DISABLE KEYS */;
/*!40000 ALTER TABLE `member_field_translations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `organizations`
--

DROP TABLE IF EXISTS `organizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `organizations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `organization_code` (`organization_code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `organizations`
--

LOCK TABLES `organizations` WRITE;
/*!40000 ALTER TABLE `organizations` DISABLE KEYS */;
INSERT INTO `organizations` VALUES (1,'Default Organization','ORG001',NULL,NULL,'9876543210','admin@example.com',NULL,'company','corporate',1,'2026-07-31 13:10:27','2026-07-31 13:10:27',NULL,NULL,NULL,NULL),(2,'ABC Inter','3434','org_6a6ca83ec800c6.60366135.png','vavarai,\r\nmathandam','09443177546','vijinvivin100@gmail.com','http://localhost/invoice/invoice/add_customer.php','school','residence',1,'2026-07-31 13:50:54','2026-07-31 13:50:54',1,1,NULL,NULL),(3,'ABC','A980','org_1785585401_7470.png','vavarai,\r\nmathandam','+469443177546','vijinvivin100S@gmail.com','http://localhost/invoice/invoice/add_customer.php','school','residence',1,'2026-08-01 11:55:21','2026-08-01 11:56:45',1,1,NULL,NULL),(4,'ABC InterS','AI372S','org_1785585490_8609.png','vavarai,\r\nmathandam','+46944317754622','vijinviviSSn100@gmail.com','http://localhost/invoice/invoice/add_customer.php','company','corporate',0,'2026-08-01 11:58:10','2026-08-01 12:01:04',1,1,1,'2026-08-01 12:01:04');
/*!40000 ALTER TABLE `organizations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token` varchar(128) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_token` (`token`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `permission_name` varchar(120) NOT NULL,
  `module_name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_perm` (`module_name`,`permission_name`)
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'View','Dashboard','View Dashboard','2026-07-31 14:02:29'),(2,'Export','Dashboard','Export Dashboard','2026-07-31 14:02:29'),(3,'View','Organizations','View Organizations','2026-07-31 14:02:29'),(4,'Create','Organizations','Create Organizations','2026-07-31 14:02:29'),(5,'Edit','Organizations','Edit Organizations','2026-07-31 14:02:29'),(6,'Delete','Organizations','Delete Organizations','2026-07-31 14:02:29'),(7,'Print','Organizations','Print Organizations','2026-07-31 14:02:29'),(8,'Export','Organizations','Export Organizations','2026-07-31 14:02:29'),(9,'Import','Organizations','Import Organizations','2026-07-31 14:02:29'),(10,'View','Users','View Users','2026-07-31 14:02:29'),(11,'Create','Users','Create Users','2026-07-31 14:02:29'),(12,'Edit','Users','Edit Users','2026-07-31 14:02:29'),(13,'Delete','Users','Delete Users','2026-07-31 14:02:29'),(14,'Print','Users','Print Users','2026-07-31 14:02:29'),(15,'Export','Users','Export Users','2026-07-31 14:02:29'),(16,'Import','Users','Import Users','2026-07-31 14:02:29'),(17,'View','Roles','View Roles','2026-07-31 14:02:29'),(18,'Create','Roles','Create Roles','2026-07-31 14:02:29'),(19,'Edit','Roles','Edit Roles','2026-07-31 14:02:29'),(20,'Delete','Roles','Delete Roles','2026-07-31 14:02:29'),(21,'Print','Roles','Print Roles','2026-07-31 14:02:29'),(22,'Export','Roles','Export Roles','2026-07-31 14:02:29'),(23,'Import','Roles','Import Roles','2026-07-31 14:02:29'),(24,'View','Members','View Members','2026-07-31 14:02:29'),(25,'Create','Members','Create Members','2026-07-31 14:02:29'),(26,'Edit','Members','Edit Members','2026-07-31 14:02:29'),(27,'Delete','Members','Delete Members','2026-07-31 14:02:29'),(28,'Print','Members','Print Members','2026-07-31 14:02:29'),(29,'Export','Members','Export Members','2026-07-31 14:02:29'),(30,'Import','Members','Import Members','2026-07-31 14:02:29'),(31,'View','Templates','View Templates','2026-07-31 14:02:29'),(32,'Create','Templates','Create Templates','2026-07-31 14:02:29'),(33,'Edit','Templates','Edit Templates','2026-07-31 14:02:29'),(34,'Delete','Templates','Delete Templates','2026-07-31 14:02:29'),(35,'Print','Templates','Print Templates','2026-07-31 14:02:29'),(36,'Export','Templates','Export Templates','2026-07-31 14:02:29'),(37,'Import','Templates','Import Templates','2026-07-31 14:02:29'),(38,'View','Generate ID','View Generate ID','2026-07-31 14:02:29'),(39,'Create','Generate ID','Create Generate ID','2026-07-31 14:02:29'),(40,'Edit','Generate ID','Edit Generate ID','2026-07-31 14:02:29'),(41,'Delete','Generate ID','Delete Generate ID','2026-07-31 14:02:29'),(42,'Print','Generate ID','Print Generate ID','2026-07-31 14:02:29'),(43,'Export','Generate ID','Export Generate ID','2026-07-31 14:02:29'),(44,'View','Reports','View Reports','2026-07-31 14:02:29'),(45,'Print','Reports','Print Reports','2026-07-31 14:02:29'),(46,'Export','Reports','Export Reports','2026-07-31 14:02:29'),(47,'View','Settings','View Settings','2026-07-31 14:02:29'),(48,'Edit','Settings','Edit Settings','2026-07-31 14:02:29'),(49,'Manage Settings','Settings','Manage Settings Settings','2026-07-31 14:02:29');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_perm` (`role_id`,`permission_id`),
  KEY `fk_rp_permission` (`permission_id`),
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=201 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` VALUES (50,2,1,'2026-07-31 14:02:29'),(51,2,2,'2026-07-31 14:02:29'),(52,2,3,'2026-07-31 14:02:29'),(53,2,4,'2026-07-31 14:02:29'),(54,2,5,'2026-07-31 14:02:29'),(55,2,6,'2026-07-31 14:02:29'),(56,2,7,'2026-07-31 14:02:29'),(57,2,8,'2026-07-31 14:02:29'),(58,2,9,'2026-07-31 14:02:29'),(59,2,24,'2026-07-31 14:02:29'),(60,2,25,'2026-07-31 14:02:29'),(61,2,26,'2026-07-31 14:02:29'),(62,2,27,'2026-07-31 14:02:29'),(63,2,28,'2026-07-31 14:02:29'),(64,2,29,'2026-07-31 14:02:29'),(65,2,30,'2026-07-31 14:02:29'),(66,2,31,'2026-07-31 14:02:29'),(67,2,32,'2026-07-31 14:02:29'),(68,2,33,'2026-07-31 14:02:29'),(69,2,34,'2026-07-31 14:02:29'),(70,2,35,'2026-07-31 14:02:29'),(71,2,36,'2026-07-31 14:02:29'),(72,2,37,'2026-07-31 14:02:29'),(73,2,38,'2026-07-31 14:02:29'),(74,2,39,'2026-07-31 14:02:29'),(75,2,40,'2026-07-31 14:02:29'),(76,2,41,'2026-07-31 14:02:29'),(77,2,42,'2026-07-31 14:02:29'),(78,2,43,'2026-07-31 14:02:29'),(79,2,44,'2026-07-31 14:02:29'),(80,2,45,'2026-07-31 14:02:29'),(81,2,46,'2026-07-31 14:02:29'),(82,3,1,'2026-07-31 14:02:29'),(83,3,2,'2026-07-31 14:02:29'),(84,3,24,'2026-07-31 14:02:29'),(85,3,25,'2026-07-31 14:02:29'),(86,3,26,'2026-07-31 14:02:29'),(87,3,27,'2026-07-31 14:02:29'),(88,3,28,'2026-07-31 14:02:29'),(89,3,29,'2026-07-31 14:02:29'),(90,3,30,'2026-07-31 14:02:29'),(91,3,38,'2026-07-31 14:02:29'),(92,3,39,'2026-07-31 14:02:29'),(93,3,40,'2026-07-31 14:02:29'),(94,3,41,'2026-07-31 14:02:29'),(95,3,42,'2026-07-31 14:02:29'),(96,3,43,'2026-07-31 14:02:29'),(152,1,1,'2026-08-01 13:51:00'),(153,1,2,'2026-08-01 13:51:00'),(154,1,38,'2026-08-01 13:51:00'),(155,1,39,'2026-08-01 13:51:00'),(156,1,40,'2026-08-01 13:51:00'),(157,1,41,'2026-08-01 13:51:00'),(158,1,42,'2026-08-01 13:51:00'),(159,1,43,'2026-08-01 13:51:00'),(160,1,24,'2026-08-01 13:51:00'),(161,1,25,'2026-08-01 13:51:00'),(162,1,26,'2026-08-01 13:51:00'),(163,1,27,'2026-08-01 13:51:00'),(164,1,28,'2026-08-01 13:51:00'),(165,1,29,'2026-08-01 13:51:00'),(166,1,30,'2026-08-01 13:51:00'),(167,1,3,'2026-08-01 13:51:00'),(168,1,4,'2026-08-01 13:51:00'),(169,1,5,'2026-08-01 13:51:00'),(170,1,6,'2026-08-01 13:51:00'),(171,1,7,'2026-08-01 13:51:00'),(172,1,8,'2026-08-01 13:51:00'),(173,1,9,'2026-08-01 13:51:00'),(174,1,44,'2026-08-01 13:51:00'),(175,1,45,'2026-08-01 13:51:00'),(176,1,46,'2026-08-01 13:51:00'),(177,1,17,'2026-08-01 13:51:00'),(178,1,18,'2026-08-01 13:51:00'),(179,1,19,'2026-08-01 13:51:00'),(180,1,20,'2026-08-01 13:51:00'),(181,1,21,'2026-08-01 13:51:00'),(182,1,22,'2026-08-01 13:51:00'),(183,1,23,'2026-08-01 13:51:00'),(184,1,47,'2026-08-01 13:51:00'),(185,1,48,'2026-08-01 13:51:00'),(186,1,49,'2026-08-01 13:51:00'),(187,1,31,'2026-08-01 13:51:00'),(188,1,32,'2026-08-01 13:51:00'),(189,1,33,'2026-08-01 13:51:00'),(190,1,34,'2026-08-01 13:51:00'),(191,1,35,'2026-08-01 13:51:00'),(192,1,36,'2026-08-01 13:51:00'),(193,1,37,'2026-08-01 13:51:00'),(194,1,10,'2026-08-01 13:51:00'),(195,1,11,'2026-08-01 13:51:00'),(196,1,12,'2026-08-01 13:51:00'),(197,1,13,'2026-08-01 13:51:00'),(198,1,14,'2026-08-01 13:51:00'),(199,1,15,'2026-08-01 13:51:00'),(200,1,16,'2026-08-01 13:51:00');
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'Super Admin','Full System Access',1,'2026-07-31 14:02:29','2026-07-31 14:02:29'),(2,'Organization Admin','Organization Administrator',1,'2026-07-31 14:02:29','2026-07-31 14:02:29'),(3,'Registrar','Member Registration',1,'2026-07-31 14:02:29','2026-07-31 14:02:29'),(7,'Finace','Hello',1,'2026-08-01 11:36:12','2026-08-01 11:36:21');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `support_tickets`
--

DROP TABLE IF EXISTS `support_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `support_tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_id` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `support_tickets`
--

LOCK TABLES `support_tickets` WRITE;
/*!40000 ALTER TABLE `support_tickets` DISABLE KEYS */;
/*!40000 ALTER TABLE `support_tickets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES (1,'organization_name','hii','text',NULL,'admin','2026-08-03 11:08:24'),(2,'organization_address','','text',NULL,'admin','2026-08-03 11:08:24'),(3,'organization_phone','','text',NULL,'admin','2026-08-03 11:08:24'),(4,'organization_email','','text',NULL,'admin','2026-08-03 11:08:24'),(5,'organization_website','','text',NULL,'admin','2026-08-03 11:08:24'),(6,'date_format','d/m/Y','text',NULL,'admin','2026-08-03 11:08:24'),(7,'timezone','Asia/Kolkata','text',NULL,'admin','2026-08-03 11:08:24'),(8,'items_per_page','25','number',NULL,'admin','2026-08-03 11:08:24'),(9,'default_template','0','number',NULL,'admin','2026-08-03 11:08:24'),(10,'enable_notifications','0','boolean',NULL,'admin','2026-08-03 11:08:24'),(11,'maintenance_mode','0','boolean',NULL,'admin','2026-08-03 11:08:24');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `template_fields`
--

DROP TABLE IF EXISTS `template_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `template_fields` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL,
  `field_key` varchar(64) NOT NULL,
  `side` varchar(10) NOT NULL DEFAULT 'front',
  `x` decimal(7,3) NOT NULL DEFAULT 0.000,
  `y` decimal(7,3) NOT NULL DEFAULT 0.000,
  `width` decimal(7,3) NOT NULL DEFAULT 0.000,
  `height` decimal(7,3) NOT NULL DEFAULT 0.000,
  `visible` tinyint(1) DEFAULT 1,
  `font_size` int(11) DEFAULT 12,
  `font_family` varchar(100) DEFAULT NULL,
  `color` varchar(32) DEFAULT NULL,
  `show_label` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_template_field_side` (`template_id`,`side`,`field_key`)
) ENGINE=InnoDB AUTO_INCREMENT=577 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `template_fields`
--

LOCK TABLES `template_fields` WRITE;
/*!40000 ALTER TABLE `template_fields` DISABLE KEYS */;
INSERT INTO `template_fields` VALUES (3,1,'header','front',10.000,10.000,180.000,80.000,1,12,NULL,NULL,1,'2026-08-01 07:04:32','2026-08-01 14:24:06'),(5,1,'photo','front',10.000,55.000,15.000,75.000,1,12,NULL,NULL,1,'2026-08-01 07:47:00','2026-08-01 14:24:06'),(6,1,'member_info','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 07:47:00','2026-08-01 07:47:00'),(7,1,'footer','front',10.000,140.000,180.000,40.000,1,12,NULL,NULL,1,'2026-08-01 07:47:00','2026-08-01 14:24:06'),(8,1,'back_header','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 07:47:00','2026-08-01 07:47:00'),(9,1,'back_body','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 07:47:00','2026-08-01 07:47:00'),(76,2,'header','front',0.000,10.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 09:31:34','2026-08-01 09:31:34'),(77,2,'photo','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 09:31:34','2026-08-01 09:31:34'),(78,2,'member_info','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 09:31:34','2026-08-01 09:31:34'),(79,2,'footer','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 09:31:34','2026-08-01 09:31:34'),(80,2,'back_header','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 09:31:34','2026-08-01 09:31:34'),(81,2,'back_body','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 09:31:34','2026-08-01 09:31:34'),(88,4,'header','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 13:50:01','2026-08-01 13:50:01'),(89,4,'photo','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 13:50:01','2026-08-01 13:50:01'),(90,4,'member_info','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 13:50:01','2026-08-01 13:50:01'),(91,4,'footer','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 13:50:01','2026-08-01 13:50:01'),(92,4,'back_header','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 13:50:01','2026-08-01 13:50:01'),(93,4,'back_body','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 13:50:01','2026-08-01 13:50:01'),(172,28,'header','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 14:03:27','2026-08-01 14:03:27'),(173,28,'photo','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 14:03:27','2026-08-01 14:03:27'),(174,28,'member_info','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 14:03:27','2026-08-01 14:03:27'),(175,28,'footer','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 14:03:27','2026-08-01 14:03:27'),(176,28,'back_header','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 14:03:27','2026-08-01 14:03:27'),(177,28,'back_body','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 14:03:27','2026-08-01 14:03:27'),(184,30,'header','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 14:15:37','2026-08-01 14:15:37'),(185,30,'photo','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 14:15:37','2026-08-01 14:15:37'),(186,30,'member_info','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 14:15:37','2026-08-01 14:15:37'),(187,30,'footer','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 14:15:37','2026-08-01 14:15:37'),(188,30,'back_header','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 14:15:37','2026-08-01 14:15:37'),(189,30,'back_body','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 14:15:37','2026-08-01 14:15:37'),(244,13,'header','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 14:45:05','2026-08-01 14:45:05'),(245,13,'photo','front',0.000,0.000,190.000,40.000,1,12,NULL,NULL,1,'2026-08-01 14:45:05','2026-08-01 14:45:05'),(246,13,'member_info','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 14:45:05','2026-08-01 14:45:05'),(247,13,'footer','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 14:45:05','2026-08-01 14:45:05'),(248,13,'back_header','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 14:45:05','2026-08-01 14:45:05'),(249,13,'back_body','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 14:45:05','2026-08-01 14:45:05'),(340,25,'header','front',0.000,0.000,0.000,40.000,1,12,NULL,NULL,1,'2026-08-01 15:48:19','2026-08-01 15:48:19'),(341,25,'photo','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 15:48:19','2026-08-01 15:48:19'),(342,25,'member_info','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 15:48:19','2026-08-01 15:48:19'),(343,25,'footer','front',0.000,0.000,0.000,40.000,1,12,NULL,NULL,1,'2026-08-01 15:48:19','2026-08-01 15:48:19'),(344,25,'back_header','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 15:48:19','2026-08-01 15:48:19'),(345,25,'back_body','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 15:48:19','2026-08-01 15:48:19'),(346,57,'header','front',0.000,0.000,0.000,40.000,1,12,NULL,NULL,1,'2026-08-01 15:48:49','2026-08-01 15:48:49'),(347,57,'photo','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 15:48:49','2026-08-01 15:48:49'),(348,57,'member_info','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 15:48:49','2026-08-01 15:48:49'),(349,57,'footer','front',0.000,0.000,0.000,40.000,1,12,NULL,NULL,1,'2026-08-01 15:48:49','2026-08-01 15:48:49'),(350,57,'back_header','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 15:48:49','2026-08-01 15:48:49'),(351,57,'back_body','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 15:48:49','2026-08-01 15:48:49'),(352,59,'header','front',100.000,100.000,180.000,80.000,1,12,NULL,NULL,1,'2026-08-01 16:01:02','2026-08-01 16:01:02'),(353,59,'photo','front',10.000,55.000,15.000,75.000,1,12,NULL,NULL,1,'2026-08-01 16:01:02','2026-08-01 16:01:02'),(354,59,'member_info','front',28.000,201.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 16:01:02','2026-08-01 16:01:02'),(355,59,'footer','front',10.000,140.000,180.000,40.000,1,12,NULL,NULL,1,'2026-08-01 16:01:02','2026-08-01 16:01:02'),(356,59,'back_header','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 16:01:02','2026-08-01 16:01:02'),(357,59,'back_body','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 16:01:02','2026-08-01 16:01:02'),(358,1,'signature','front',78.000,73.000,17.000,12.000,1,18,'','',1,'2026-08-01 16:45:56','2026-08-01 16:45:56'),(359,1,'issue_date','front',36.200,26.200,29.300,6.900,1,18,'popines','',1,'2026-08-01 16:45:56','2026-08-01 16:45:56'),(360,1,'expiry_date','front',31.000,51.000,30.000,6.000,1,18,'','',1,'2026-08-01 16:45:56','2026-08-01 16:45:56'),(361,1,'signature','back',70.000,75.000,22.000,13.000,1,18,'','',1,'2026-08-01 16:45:56','2026-08-01 16:45:56'),(362,1,'issue_date','back',8.000,45.000,35.000,7.000,1,18,'','',1,'2026-08-01 16:45:56','2026-08-01 16:45:56'),(363,1,'expiry_date','back',8.000,55.000,35.000,7.000,1,18,'','',1,'2026-08-01 16:45:56','2026-08-01 16:45:56'),(370,60,'back_body','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 16:01:02','2026-08-01 16:01:02'),(371,60,'back_header','front',0.000,0.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 16:01:02','2026-08-01 16:01:02'),(372,60,'footer','front',10.000,140.000,180.000,40.000,1,12,NULL,NULL,1,'2026-08-01 16:01:02','2026-08-01 16:01:02'),(373,60,'header','front',100.000,100.000,180.000,80.000,1,12,NULL,NULL,1,'2026-08-01 16:01:02','2026-08-01 16:01:02'),(374,60,'member_info','front',28.000,201.000,140.000,40.000,1,12,NULL,NULL,1,'2026-08-01 16:01:02','2026-08-01 16:01:02'),(375,60,'photo','front',10.000,55.000,15.000,75.000,1,12,NULL,NULL,1,'2026-08-01 16:01:02','2026-08-01 16:01:02'),(376,59,'name','front',10.000,10.000,20.000,10.000,1,12,NULL,NULL,1,'2026-08-03 12:18:49','2026-08-03 12:18:49'),(377,59,'car','front',10.000,10.000,20.000,10.000,1,12,NULL,NULL,1,'2026-08-03 12:19:45','2026-08-03 12:19:45'),(378,61,'organization_name','front',5.000,5.000,90.000,15.000,1,14,NULL,NULL,1,'2026-08-03 12:32:04','2026-08-03 12:32:04'),(379,61,'photo','front',35.000,25.000,30.000,30.000,1,0,NULL,NULL,1,'2026-08-03 12:32:04','2026-08-03 12:32:04'),(380,61,'name','front',5.000,60.000,90.000,10.000,1,12,NULL,NULL,1,'2026-08-03 12:32:04','2026-08-03 12:32:04'),(381,61,'unique_id','front',5.000,72.000,90.000,8.000,1,10,NULL,NULL,1,'2026-08-03 12:32:04','2026-08-03 12:32:04'),(382,61,'member_type','front',5.000,82.000,90.000,8.000,1,10,NULL,NULL,1,'2026-08-03 12:32:04','2026-08-03 12:32:04'),(383,61,'expiry_date','front',5.000,92.000,90.000,8.000,1,10,NULL,NULL,1,'2026-08-03 12:32:04','2026-08-03 12:32:04'),(384,61,'terms','back',5.000,5.000,90.000,90.000,1,10,NULL,NULL,1,'2026-08-03 12:32:04','2026-08-03 12:32:04'),(385,62,'organization_name','front',5.000,5.000,90.000,15.000,1,14,NULL,NULL,1,'2026-08-03 12:32:55','2026-08-03 12:32:55'),(386,62,'photo','front',35.000,25.000,30.000,30.000,1,0,NULL,NULL,1,'2026-08-03 12:32:55','2026-08-03 12:32:55'),(387,62,'name','front',5.000,60.000,90.000,10.000,1,12,NULL,NULL,1,'2026-08-03 12:32:55','2026-08-03 12:32:55'),(388,62,'unique_id','front',5.000,72.000,90.000,8.000,1,10,NULL,NULL,1,'2026-08-03 12:32:55','2026-08-03 12:32:55'),(389,62,'member_type','front',5.000,82.000,90.000,8.000,1,10,NULL,NULL,1,'2026-08-03 12:32:55','2026-08-03 12:32:55'),(390,62,'expiry_date','front',5.000,92.000,90.000,8.000,1,10,NULL,NULL,1,'2026-08-03 12:32:55','2026-08-03 12:32:55'),(391,62,'terms','back',5.000,5.000,90.000,90.000,1,10,NULL,NULL,1,'2026-08-03 12:32:55','2026-08-03 12:32:55'),(392,63,'organization_name','front',56.021,35.786,35.365,22.361,1,14,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-06 05:58:10'),(393,63,'photo','front',48.284,5.557,41.978,23.750,1,14,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-06 05:58:10'),(394,63,'name','front',4.824,45.185,37.972,16.111,1,12,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-06 05:58:10'),(395,63,'unique_id','front',9.869,66.444,52.256,11.019,1,10,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-06 05:58:10'),(396,63,'member_type','front',43.202,79.685,49.217,15.917,1,10,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-06 05:58:10'),(397,63,'expiry_date','front',5.000,92.000,32.015,5.926,1,10,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-06 05:58:10'),(398,63,'terms','back',5.000,5.000,90.000,90.000,1,10,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-03 14:59:54'),(399,63,'caroo','back',8.265,18.102,85.982,18.102,1,12,'','#000000',1,'2026-08-03 15:00:14','2026-08-06 04:08:16'),(400,64,'terms','back',5.000,5.000,90.000,90.000,1,10,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-03 14:59:54'),(401,64,'caroo','front',10.000,10.000,20.000,10.000,1,12,NULL,NULL,1,'2026-08-03 15:00:14','2026-08-03 15:00:14'),(402,64,'expiry_date','front',5.000,92.000,90.000,8.000,1,10,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-03 14:59:54'),(403,64,'member_type','front',5.000,82.000,90.000,8.000,1,10,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-03 14:59:54'),(404,64,'name','front',5.000,60.000,90.000,10.000,1,12,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-03 14:59:54'),(405,64,'organization_name','front',5.000,5.000,90.000,15.000,1,14,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-03 14:59:54'),(406,64,'photo','front',35.000,25.000,30.000,30.000,1,0,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-03 14:59:54'),(407,64,'unique_id','front',5.000,72.000,90.000,8.000,1,10,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-03 14:59:54'),(464,63,'name','back',14.233,60.463,76.929,12.778,1,12,NULL,NULL,1,'2026-08-03 18:16:33','2026-08-06 04:08:16'),(484,63,'field_10','front',0.000,0.000,39.209,5.000,1,12,'','#000000',1,'2026-08-03 18:38:19','2026-08-06 05:58:10'),(541,65,'caroo','back',8.265,18.102,85.982,15.324,1,12,'','#000000',1,'2026-08-03 15:00:14','2026-08-03 18:33:47'),(542,65,'name','back',13.109,61.389,76.929,12.778,1,12,NULL,NULL,1,'2026-08-03 18:16:33','2026-08-03 18:33:47'),(543,65,'terms','back',5.000,5.000,90.000,90.000,1,10,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-03 14:59:54'),(544,65,'expiry_date','front',5.000,92.000,9.543,1.424,1,10,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-03 19:14:08'),(545,65,'field_10','front',0.000,0.000,7.748,1.685,1,12,'','#000000',1,'2026-08-03 18:38:19','2026-08-03 19:14:08'),(546,65,'member_type','front',5.000,82.000,11.389,1.424,1,10,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-03 19:14:08'),(547,65,'name','front',11.191,42.870,37.972,16.111,1,12,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-03 19:19:06'),(548,65,'organization_name','front',64.635,52.916,35.365,22.361,1,14,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-03 19:19:06'),(549,65,'photo','front',58.022,9.492,16.893,30.232,1,14,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-03 19:19:11'),(550,65,'unique_id','front',5.000,72.000,52.256,11.019,1,10,NULL,NULL,1,'2026-08-03 14:59:54','2026-08-03 19:19:06'),(551,63,'kkkkk','front',10.000,10.000,20.000,10.000,1,12,NULL,NULL,1,'2026-08-06 04:07:37','2026-08-06 04:07:37'),(552,63,'vivin','front',5.506,25.972,33.483,11.852,1,12,NULL,NULL,1,'2026-08-06 04:07:46','2026-08-06 05:58:10');
/*!40000 ALTER TABLE `template_fields` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `template_input_fields`
--

DROP TABLE IF EXISTS `template_input_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `template_input_fields` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL DEFAULT 0,
  `field_key` varchar(80) NOT NULL,
  `field_label` varchar(120) NOT NULL,
  `field_type` varchar(32) NOT NULL DEFAULT 'text',
  `bilingual_mode` varchar(20) NOT NULL DEFAULT 'single',
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `placeholder` varchar(190) DEFAULT NULL,
  `validation_rules` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_template_input_field` (`template_id`,`field_key`)
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `template_input_fields`
--

LOCK TABLES `template_input_fields` WRITE;
/*!40000 ALTER TABLE `template_input_fields` DISABLE KEYS */;
INSERT INTO `template_input_fields` VALUES (1,1,'67','gghf','number','single',0,0,NULL,NULL,67,'2026-08-01 13:58:31','2026-08-01 16:45:25'),(3,1,'logo','Logo','text','single',0,1,NULL,NULL,0,'2026-08-01 16:43:37','2026-08-01 16:48:32'),(4,1,'photo','Photo','photo','single',0,1,NULL,NULL,0,'2026-08-01 16:43:37','2026-08-01 16:48:32'),(5,1,'name','Name','text','single',0,1,NULL,NULL,0,'2026-08-01 16:43:37','2026-08-01 16:48:32'),(6,1,'registration_no','Registration No','text','single',0,1,NULL,NULL,0,'2026-08-01 16:43:37','2026-08-01 16:48:32'),(7,1,'qr','QR Code','qr','single',0,0,NULL,NULL,0,'2026-08-01 16:43:37','2026-08-01 16:45:25'),(8,1,'barcode','Barcode','barcode','single',0,0,NULL,NULL,0,'2026-08-01 16:43:37','2026-08-01 16:45:25'),(9,1,'signature','Signature','signature','single',0,1,NULL,NULL,0,'2026-08-01 16:43:37','2026-08-01 16:45:25'),(10,1,'issue_date','Issue Date','date','single',0,1,NULL,NULL,0,'2026-08-01 16:43:37','2026-08-01 16:45:25'),(11,1,'expiry_date','Expiry Date','date','single',0,1,NULL,NULL,0,'2026-08-01 16:43:37','2026-08-01 16:45:25'),(22,46,'logo','Logo','logo','single',0,1,NULL,NULL,0,'2026-08-01 16:47:51','2026-08-01 16:47:51'),(23,46,'photo','Photo','photo','single',0,1,NULL,NULL,1,'2026-08-01 16:47:51','2026-08-01 16:47:51'),(24,46,'name','Name','text','single',0,1,NULL,NULL,2,'2026-08-01 16:47:51','2026-08-01 16:47:51'),(25,46,'registration_no','Registration No','text','single',0,1,NULL,NULL,3,'2026-08-01 16:47:51','2026-08-01 16:47:51'),(26,46,'qr','QR Code','qr','single',0,1,NULL,NULL,4,'2026-08-01 16:47:51','2026-08-01 16:47:51'),(27,46,'barcode','Barcode','barcode','single',0,1,NULL,NULL,5,'2026-08-01 16:47:51','2026-08-01 16:47:51'),(28,46,'signature','Signature','signature','single',0,1,NULL,NULL,6,'2026-08-01 16:47:51','2026-08-01 16:47:51'),(29,46,'issue_date','Issue Date','date','single',0,1,NULL,NULL,7,'2026-08-01 16:47:51','2026-08-01 16:47:51'),(30,46,'expiry_date','Expiry Date','date','single',0,1,NULL,NULL,8,'2026-08-01 16:47:51','2026-08-01 16:47:51'),(41,45,'logo','Logo','logo','single',0,1,NULL,NULL,0,'2026-08-01 16:50:59','2026-08-01 16:50:59'),(42,45,'photo','Photo','photo','single',0,1,NULL,NULL,1,'2026-08-01 16:50:59','2026-08-01 16:50:59'),(43,45,'name','Name','text','single',0,1,NULL,NULL,2,'2026-08-01 16:50:59','2026-08-01 16:50:59'),(44,45,'registration_no','Registration No','text','single',0,1,NULL,NULL,3,'2026-08-01 16:50:59','2026-08-01 16:50:59'),(45,45,'qr','QR Code','qr','single',0,1,NULL,NULL,4,'2026-08-01 16:50:59','2026-08-01 16:50:59'),(46,45,'barcode','Barcode','barcode','single',0,1,NULL,NULL,5,'2026-08-01 16:50:59','2026-08-01 16:50:59'),(47,45,'signature','Signature','signature','single',0,1,NULL,NULL,6,'2026-08-01 16:50:59','2026-08-01 16:50:59'),(48,45,'issue_date','Issue Date','date','single',0,1,NULL,NULL,7,'2026-08-01 16:50:59','2026-08-01 16:50:59'),(49,45,'expiry_date','Expiry Date','date','single',0,1,NULL,NULL,8,'2026-08-01 16:50:59','2026-08-01 16:50:59'),(50,2,'logo','Logo','logo','single',0,1,NULL,NULL,0,'2026-08-03 09:19:02','2026-08-03 09:19:02'),(51,2,'photo','Photo','photo','single',0,1,NULL,NULL,1,'2026-08-03 09:19:02','2026-08-03 09:19:02'),(52,2,'name','Name','text','single',0,1,NULL,NULL,2,'2026-08-03 09:19:02','2026-08-03 09:19:02'),(53,2,'registration_no','Registration No','text','single',0,1,NULL,NULL,3,'2026-08-03 09:19:02','2026-08-03 09:19:02'),(54,2,'qr','QR Code','qr','single',0,1,NULL,NULL,4,'2026-08-03 09:19:02','2026-08-03 09:19:02'),(55,2,'barcode','Barcode','barcode','single',0,1,NULL,NULL,5,'2026-08-03 09:19:02','2026-08-03 09:19:02'),(56,2,'signature','Signature','signature','single',0,1,NULL,NULL,6,'2026-08-03 09:19:02','2026-08-03 09:19:02'),(57,2,'issue_date','Issue Date','date','single',0,1,NULL,NULL,7,'2026-08-03 09:19:02','2026-08-03 09:19:02'),(58,2,'expiry_date','Expiry Date','date','single',0,1,NULL,NULL,8,'2026-08-03 09:19:02','2026-08-03 09:19:02'),(59,63,'field_1','kkkkk','text','single',0,1,'k','',1,'2026-08-03 15:01:53','2026-08-03 15:01:53'),(60,64,'field_1','kkkkk','text','single',0,1,'k','',1,'2026-08-03 15:01:53','2026-08-03 15:01:53'),(61,63,'field_2','vivin','signature','single',0,1,'k','',2,'2026-08-03 18:35:18','2026-08-03 18:35:18'),(62,65,'field_1','kkkkk','text','single',0,1,'k','',1,'2026-08-03 15:01:53','2026-08-03 15:01:53'),(63,65,'field_2','vivin','signature','single',0,1,'k','',2,'2026-08-03 18:35:18','2026-08-03 18:35:18');
/*!40000 ALTER TABLE `template_input_fields` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `fk_users_organization` (`organization_id`),
  KEY `fk_users_role` (`role_id`),
  CONSTRAINT `fk_users_organization` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,1,'admin','$2y$10$QeffgzsfSXXxQfQqKIjAiehqXv3lNjxTSbhGnU5M.X1uj/079JNdu','admin@example.com',NULL,'Administrator','avatar_1_1785756043.png',1,1,'admin','2026-08-06 05:45:10',NULL,'2026-07-31 13:02:21','2026-08-06 05:45:10'),(2,2,'VIVIN','$2y$10$9t4vyHA6oK2yncswv96NauG6h9U8GTjAt2upISc2jaJfvfZ/DLTx6','vijinvivin100@gmail.com','+469443177546','v','usr_6a6ddcbc40ad89.11615872.jpg',3,1,'','2026-07-31 15:18:15','2026-08-01 11:50:28','2026-07-31 15:17:58','2026-08-01 11:50:28'),(3,2,'VIVINX','$2y$10$bgaga7wj193lhZnl.0GIWe7xrD8bwGQxMmHz.zei2yr56dDHAgxHW','vijinviXvin100@gmail.com','+469443177546C','v','usr_6a6ddd5e389dd3.77934196.png',3,1,'','2026-08-05 18:17:53',NULL,'2026-08-01 11:47:57','2026-08-05 18:17:53'),(4,2,'743vS','$2y$10$LiyHPPJ/RcT1M2KGVhweFOEeVUSVA6.CRuZ1ibekAOt3UqPbTRS1u','vijinvivSSin100@gmail.com','+46944317754622','743 Vijin','usr_6a6ddd78980c57.78169264.png',3,1,'',NULL,NULL,'2026-08-01 11:50:16','2026-08-01 11:50:16');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'id'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-06 11:36:18
