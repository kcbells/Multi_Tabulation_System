-- MySQL dump 10.13  Distrib 8.4.3, for Win64 (x86_64)
--
-- Host: localhost    Database: coc_tabulation
-- ------------------------------------------------------
-- Server version	8.4.3

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `access_codes`
--

DROP TABLE IF EXISTS `access_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `access_codes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int unsigned NOT NULL,
  `code` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('judge','facilitator') COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_codes_event_role_name` (`event_id`,`role`,`name`),
  CONSTRAINT `fk_codes_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `access_codes`
--

LOCK TABLES `access_codes` WRITE;
/*!40000 ALTER TABLE `access_codes` DISABLE KEYS */;
INSERT INTO `access_codes` VALUES (16,7,'7MX9EE8B','judge','ANNA',1,'2026-09-16 10:58:26','2026-09-16 10:49:37'),(17,7,'A22F96VJ','judge','IVAN',1,NULL,'2026-09-16 10:49:51'),(18,7,'E7WJ8JSV','judge','MICAH',1,NULL,'2026-09-16 10:49:58'),(32,7,'S4SW746S','facilitator','Display Operator',1,'2026-09-28 17:52:53','2026-09-28 17:52:40');
/*!40000 ALTER TABLE `access_codes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `activities`
--

DROP TABLE IF EXISTS `activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activities` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int unsigned NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `venue` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `schedule_at` datetime DEFAULT NULL,
  `status` enum('pending','open','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `nature` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `format` enum('score','bracket','round_robin','ranking') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'score',
  `score_label` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rank_direction` enum('desc','asc') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'desc',
  `third_place` tinyint(1) NOT NULL DEFAULT '1',
  `counts_to_overall` tinyint(1) NOT NULL DEFAULT '1',
  `scoring_method` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'average',
  `drop_extremes` tinyint(1) NOT NULL DEFAULT '0',
  `score_scale` decimal(6,2) DEFAULT NULL,
  `tie_break` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'share',
  `tie_criterion_id` int unsigned DEFAULT NULL,
  `source_activity_id` int unsigned DEFAULT NULL,
  `advance_count` int unsigned DEFAULT NULL,
  `carry_weight` decimal(5,2) NOT NULL DEFAULT '0.00',
  `certified_at` datetime DEFAULT NULL,
  `certified_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `certified_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `criteria_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `criteria_file_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `criteria_text` mediumtext COLLATE utf8mb4_unicode_ci,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activities_event_order` (`event_id`,`sort_order`,`id`),
  KEY `idx_activities_event_status` (`event_id`,`status`),
  KEY `idx_activities_event_nature` (`event_id`,`nature`),
  CONSTRAINT `fk_activities_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=80 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activities`
--

LOCK TABLES `activities` WRITE;
/*!40000 ALTER TABLE `activities` DISABLE KEYS */;
INSERT INTO `activities` VALUES (58,7,'AMATEUR',NULL,'PHINMA COC','2026-09-16 07:30:00','closed','Music & Dance','score',NULL,'desc',1,1,'average',0,NULL,'share',NULL,NULL,NULL,0.00,'2026-09-29 11:08:50','System Administrator','0a05dd4c88b6ae37264a80c3a25e62a001d3cfd4503fc0c6c5eda50bd36afaa4',NULL,NULL,NULL,1,'2026-09-16 10:45:46');
/*!40000 ALTER TABLE `activities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int unsigned DEFAULT NULL,
  `activity_id` int unsigned DEFAULT NULL,
  `actor_kind` enum('staff','code','guest') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'guest',
  `actor_id` int unsigned DEFAULT NULL,
  `actor_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actor_role` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta` text COLLATE utf8mb4_unicode_ci,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_logs_event_time` (`event_id`,`id`),
  KEY `idx_logs_action_time` (`action`,`id`),
  KEY `idx_logs_actor` (`actor_kind`,`actor_id`,`id`),
  KEY `idx_logs_created` (`created_at`),
  CONSTRAINT `fk_logs_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=449 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` VALUES (1,NULL,NULL,'guest',NULL,'admin-001',NULL,'auth.failed','Failed staff sign-in for username “admin-001”',NULL,'::1','2026-09-15 00:46:06'),(2,NULL,NULL,'guest',NULL,'admin-001',NULL,'auth.failed','Failed staff sign-in for username “admin-001”',NULL,'::1','2026-09-15 00:46:21'),(3,NULL,NULL,'guest',NULL,'admin',NULL,'auth.failed','Failed staff sign-in for username “admin”',NULL,'::1','2026-09-15 00:46:31'),(4,NULL,NULL,'guest',NULL,'admin',NULL,'auth.failed','Failed staff sign-in for username “admin”',NULL,'::1','2026-09-15 00:46:39'),(5,NULL,NULL,'guest',NULL,'admin',NULL,'auth.failed','Failed staff sign-in for username “admin”',NULL,'::1','2026-09-15 00:47:24'),(6,NULL,NULL,'guest',NULL,'admin',NULL,'auth.failed','Failed staff sign-in for username “admin”',NULL,'::1','2026-09-15 00:47:33'),(7,NULL,NULL,'guest',NULL,'admin',NULL,'auth.failed','Failed staff sign-in for username “admin”',NULL,'::1','2026-09-15 00:51:22'),(8,NULL,NULL,'guest',NULL,'admin',NULL,'auth.failed','Failed staff sign-in for username “admin”',NULL,'::1','2026-09-15 00:51:31'),(9,NULL,NULL,'staff',1,'System Administrator','admin','auth.login','Signed in with a staff account',NULL,'::1','2026-09-15 00:52:04'),(10,NULL,NULL,'staff',1,'System Administrator','admin','auth.logout','Signed out',NULL,'::1','2026-09-15 00:52:04'),(11,NULL,NULL,'staff',1,'System Administrator','admin','auth.login','Signed in with a staff account',NULL,'::1','2026-09-15 00:52:28'),(12,NULL,NULL,'staff',1,'System Administrator','admin','user.created','Created staff account “K C Joy V. Asne” (program_head)',NULL,'::1','2026-09-15 01:37:44'),(13,NULL,NULL,'staff',1,'System Administrator','admin','event.created','Created event “Foundation Day 2026”',NULL,'::1','2026-09-15 01:55:00'),(14,NULL,1,'staff',1,'System Administrator','admin','activity.created','Created activity “MLOB”',NULL,'::1','2026-09-15 01:57:29'),(15,NULL,1,'staff',1,'System Administrator','admin','contestant.created','Added contestant “cite” to “MLOB”',NULL,'::1','2026-09-15 01:58:04'),(16,NULL,1,'staff',1,'System Administrator','admin','contestant.created','Added contestant “coed” to “MLOB”',NULL,'::1','2026-09-15 01:58:18'),(17,NULL,1,'staff',1,'System Administrator','admin','activity.bracket','Created the 2-slot bracket of “MLOB”',NULL,'::1','2026-09-15 01:58:27'),(18,NULL,1,'staff',1,'System Administrator','admin','activity.status','Opened for scoring: “MLOB”',NULL,'::1','2026-09-15 01:58:37'),(19,NULL,1,'staff',1,'System Administrator','admin','contestant.updated','Updated contestant “cite” in “MLOB”',NULL,'::1','2026-09-15 10:04:56'),(20,NULL,1,'staff',1,'System Administrator','admin','contestant.updated','Updated contestant “coed” in “MLOB”',NULL,'::1','2026-09-15 10:05:06'),(21,NULL,NULL,'staff',1,'System Administrator','admin','code.issued','Issued 3 facilitator access codes (Facilitator 1 – Facilitator 3)',NULL,'::1','2026-09-15 10:08:24'),(22,NULL,1,'staff',1,'System Administrator','admin','activity.match','Recorded cite 1–0 coed — coed wins in “MLOB”',NULL,'::1','2026-09-15 10:10:01'),(23,NULL,NULL,'staff',1,'System Administrator','admin','event.status','Changed event status to ongoing',NULL,'::1','2026-09-15 10:11:17'),(24,NULL,NULL,'staff',1,'System Administrator','admin','team.created','Added team “CITE”',NULL,'::1','2026-09-15 10:12:13'),(25,NULL,NULL,'staff',1,'System Administrator','admin','team.created','Added team “COED”',NULL,'::1','2026-09-15 10:12:31'),(26,NULL,1,'staff',1,'System Administrator','admin','activity.match','Cleared a match result in “MLOB”',NULL,'::1','2026-09-15 10:12:43'),(27,NULL,NULL,'staff',1,'System Administrator','admin','event.created','Created event “MR & MS COC”',NULL,'::1','2026-09-15 13:09:24'),(28,NULL,1,'staff',1,'System Administrator','admin','activity.match','Recorded cite 2–0 coed — cite wins in “MLOB”',NULL,'::1','2026-09-15 15:00:43'),(29,NULL,NULL,'staff',1,'System Administrator','admin','event.scanned','Scanned the event document “foundation_day_event_activities.pdf” (13 activities detected)',NULL,'::1','2026-09-15 15:37:50'),(30,NULL,NULL,'staff',1,'System Administrator','admin','event.created','Created event “University Foundation Day 2026”',NULL,'::1','2026-09-15 15:38:36'),(31,NULL,NULL,'staff',1,'System Administrator','admin','event.document','Attached the scanned document “foundation_day_event_activities.pdf”',NULL,'::1','2026-09-15 15:38:36'),(32,NULL,2,'staff',1,'System Administrator','admin','activity.created','Created activity “Grand Vocal Solo Competition”',NULL,'::1','2026-09-15 15:38:36'),(33,NULL,3,'staff',1,'System Administrator','admin','activity.created','Created activity “Hip-Hop Dance Crew Showdown”',NULL,'::1','2026-09-15 15:38:36'),(34,NULL,4,'staff',1,'System Administrator','admin','activity.created','Created activity “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-15 15:38:36'),(35,NULL,5,'staff',1,'System Administrator','admin','activity.created','Created activity “Mr. & Ms. Foundation Day Pageant”',NULL,'::1','2026-09-15 15:38:36'),(36,NULL,6,'staff',1,'System Administrator','admin','activity.created','Created activity “Battle of the Wits Quiz Bowl”',NULL,'::1','2026-09-15 15:38:36'),(37,NULL,7,'staff',1,'System Administrator','admin','activity.created','Created activity “Acoustic Band Competition”',NULL,'::1','2026-09-15 15:38:36'),(38,NULL,8,'staff',1,'System Administrator','admin','activity.created','Created activity “Speed Hackathon & App Pitch”',NULL,'::1','2026-09-15 15:38:36'),(39,NULL,9,'staff',1,'System Administrator','admin','activity.created','Created activity “Digital Poster Making Contest”',NULL,'::1','2026-09-15 15:38:37'),(40,NULL,10,'staff',1,'System Administrator','admin','activity.created','Created activity “3x3 Street Basketball Tournament”',NULL,'::1','2026-09-15 15:38:37'),(41,NULL,11,'staff',1,'System Administrator','admin','activity.created','Created activity “Mixed Volleyball Championship”',NULL,'::1','2026-09-15 15:38:37'),(42,NULL,12,'staff',1,'System Administrator','admin','activity.created','Created activity “Chess Master Tournament”',NULL,'::1','2026-09-15 15:38:37'),(43,NULL,13,'staff',1,'System Administrator','admin','activity.created','Created activity “Extemporaneous Speech Competition”',NULL,'::1','2026-09-15 15:38:37'),(44,NULL,14,'staff',1,'System Administrator','admin','activity.created','Created activity “Cosplay Runway Contest”',NULL,'::1','2026-09-15 15:38:37'),(45,NULL,NULL,'staff',1,'System Administrator','admin','event.status','Changed event status to cancelled',NULL,'::1','2026-09-15 15:43:09'),(46,NULL,NULL,'staff',1,'System Administrator','admin','event.deleted','Deleted event “Foundation Day 2026”',NULL,'::1','2026-09-15 17:34:21'),(47,NULL,NULL,'staff',1,'System Administrator','admin','event.deleted','Deleted event “MR & MS COC”',NULL,'::1','2026-09-15 17:34:39'),(48,NULL,NULL,'staff',1,'System Administrator','admin','event.deleted','Deleted event “University Foundation Day 2026”',NULL,'::1','2026-09-15 17:34:59'),(49,NULL,NULL,'staff',1,'System Administrator','admin','event.scanned','Scanned the event document “foundation_day_event_activities.pdf” (13 activities detected)',NULL,'::1','2026-09-15 17:35:35'),(50,NULL,NULL,'staff',1,'System Administrator','admin','event.created','Created event “University Foundation Day 2026”',NULL,'::1','2026-09-15 17:37:42'),(51,NULL,NULL,'staff',1,'System Administrator','admin','event.document','Attached the scanned document “foundation_day_event_activities.pdf”',NULL,'::1','2026-09-15 17:37:42'),(52,NULL,15,'staff',1,'System Administrator','admin','activity.created','Created activity “Grand Vocal Solo Competition”',NULL,'::1','2026-09-15 17:37:42'),(53,NULL,16,'staff',1,'System Administrator','admin','activity.created','Created activity “Hip-Hop Dance Crew Showdown”',NULL,'::1','2026-09-15 17:37:42'),(54,NULL,17,'staff',1,'System Administrator','admin','activity.created','Created activity “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-15 17:37:42'),(55,NULL,18,'staff',1,'System Administrator','admin','activity.created','Created activity “Mr. & Ms. Foundation Day Pageant”',NULL,'::1','2026-09-15 17:37:42'),(56,NULL,19,'staff',1,'System Administrator','admin','activity.created','Created activity “Battle of the Wits Quiz Bowl”',NULL,'::1','2026-09-15 17:37:43'),(57,NULL,20,'staff',1,'System Administrator','admin','activity.created','Created activity “Acoustic Band Competition”',NULL,'::1','2026-09-15 17:37:43'),(58,NULL,21,'staff',1,'System Administrator','admin','activity.created','Created activity “Speed Hackathon & App Pitch”',NULL,'::1','2026-09-15 17:37:43'),(59,NULL,22,'staff',1,'System Administrator','admin','activity.created','Created activity “Digital Poster Making Contest”',NULL,'::1','2026-09-15 17:37:43'),(60,NULL,23,'staff',1,'System Administrator','admin','activity.created','Created activity “3x3 Street Basketball Tournament”',NULL,'::1','2026-09-15 17:37:43'),(61,NULL,24,'staff',1,'System Administrator','admin','activity.created','Created activity “Mixed Volleyball Championship”',NULL,'::1','2026-09-15 17:37:43'),(62,NULL,25,'staff',1,'System Administrator','admin','activity.created','Created activity “Chess Master Tournament”',NULL,'::1','2026-09-15 17:37:43'),(63,NULL,26,'staff',1,'System Administrator','admin','activity.created','Created activity “Extemporaneous Speech Competition”',NULL,'::1','2026-09-15 17:37:43'),(64,NULL,27,'staff',1,'System Administrator','admin','activity.created','Created activity “Cosplay Runway Contest”',NULL,'::1','2026-09-15 17:37:43'),(65,NULL,NULL,'staff',1,'System Administrator','admin','code.issued','Issued an access code to judge “Jane Asne”',NULL,'::1','2026-09-15 17:37:43'),(66,NULL,NULL,'staff',1,'System Administrator','admin','code.issued','Issued an access code to judge “Dandy Narciso”',NULL,'::1','2026-09-15 17:37:43'),(67,NULL,NULL,'staff',1,'System Administrator','admin','code.issued','Issued an access code to judge “Ivan Gonzales”',NULL,'::1','2026-09-15 17:37:43'),(68,NULL,NULL,'staff',1,'System Administrator','admin','code.issued','Issued an access code to facilitator “KC”',NULL,'::1','2026-09-15 17:37:43'),(69,NULL,NULL,'staff',1,'System Administrator','admin','code.issued','Issued an access code to facilitator “Andrew”',NULL,'::1','2026-09-15 17:37:43'),(70,NULL,NULL,'staff',1,'System Administrator','admin','code.issued','Issued an access code to facilitator “Ann”',NULL,'::1','2026-09-15 17:37:43'),(71,NULL,NULL,'staff',1,'System Administrator','admin','code.issued','Issued an access code to facilitator “Sandy”',NULL,'::1','2026-09-15 17:37:43'),(72,NULL,NULL,'staff',1,'System Administrator','admin','code.issued','Issued an access code to facilitator “Justine”',NULL,'::1','2026-09-15 17:37:43'),(73,NULL,NULL,'staff',1,'System Administrator','admin','code.issued','Issued an access code to facilitator “Paul”',NULL,'::1','2026-09-15 17:37:43'),(74,NULL,NULL,'staff',1,'System Administrator','admin','event.deleted','Deleted event “University Foundation Day 2026”',NULL,'::1','2026-09-15 17:52:48'),(75,NULL,NULL,'staff',1,'System Administrator','admin','event.scanned','Scanned the event document “foundation_day_event_activities.pdf” (15 activities detected)',NULL,'::1','2026-09-15 18:00:32'),(76,NULL,NULL,'staff',1,'System Administrator','admin','event.scanned','Scanned the event document “foundation_day_event_activities.pdf” (15 activities detected)',NULL,'::1','2026-09-15 18:38:23'),(77,NULL,NULL,'staff',1,'System Administrator','admin','event.scanned','Scanned the event document “foundation_day_event_activities.pdf” (15 activities detected)',NULL,'::1','2026-09-15 18:50:42'),(78,NULL,NULL,'staff',1,'System Administrator','admin','event.scanned','Scanned the event document “foundation_day_event_activities.pdf” (15 activities detected)',NULL,'::1','2026-09-15 19:13:10'),(79,NULL,NULL,'staff',1,'System Administrator','admin','event.scanned','Scanned the event document “foundation_day_event_activities.pdf” (15 activities detected)',NULL,'::1','2026-09-15 19:13:28'),(80,NULL,NULL,'staff',1,'System Administrator','admin','event.created','Created event “University Foundation Day 2026”',NULL,'::1','2026-09-15 19:23:11'),(81,NULL,NULL,'staff',1,'System Administrator','admin','event.document','Attached the scanned document “foundation_day_event_activities.pdf”',NULL,'::1','2026-09-15 19:23:11'),(82,NULL,28,'staff',1,'System Administrator','admin','activity.created','Created activity “Grand Vocal Solo Competition”',NULL,'::1','2026-09-15 19:23:11'),(83,NULL,28,'staff',1,'System Administrator','admin','criteria.saved','Saved 4 criteria (total 100) for “Grand Vocal Solo Competition”',NULL,'::1','2026-09-15 19:23:11'),(84,NULL,29,'staff',1,'System Administrator','admin','activity.created','Created activity “Hip-Hop Dance Crew Showdown”',NULL,'::1','2026-09-15 19:23:11'),(85,NULL,29,'staff',1,'System Administrator','admin','criteria.saved','Saved 4 criteria (total 100) for “Hip-Hop Dance Crew Showdown”',NULL,'::1','2026-09-15 19:23:11'),(86,NULL,30,'staff',1,'System Administrator','admin','activity.created','Created activity “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-15 19:23:11'),(87,NULL,31,'staff',1,'System Administrator','admin','activity.created','Created activity “Call of Duty: Mobile (CODM) Search & Destroy”',NULL,'::1','2026-09-15 19:23:11'),(88,NULL,32,'staff',1,'System Administrator','admin','activity.created','Created activity “Mr. & Ms. Foundation Day Pageant”',NULL,'::1','2026-09-15 19:23:11'),(89,NULL,32,'staff',1,'System Administrator','admin','criteria.saved','Saved 4 criteria (total 100) for “Mr. & Ms. Foundation Day Pageant”',NULL,'::1','2026-09-15 19:23:11'),(90,NULL,33,'staff',1,'System Administrator','admin','activity.created','Created activity “Battle of the Wits Quiz Bowl”',NULL,'::1','2026-09-15 19:23:11'),(91,NULL,34,'staff',1,'System Administrator','admin','activity.created','Created activity “Acoustic Band Competition”',NULL,'::1','2026-09-15 19:23:11'),(92,NULL,34,'staff',1,'System Administrator','admin','criteria.saved','Saved 4 criteria (total 100) for “Acoustic Band Competition”',NULL,'::1','2026-09-15 19:23:12'),(93,NULL,35,'staff',1,'System Administrator','admin','activity.created','Created activity “Speed Hackathon & App Pitch”',NULL,'::1','2026-09-15 19:23:12'),(94,NULL,35,'staff',1,'System Administrator','admin','criteria.saved','Saved 4 criteria (total 100) for “Speed Hackathon & App Pitch”',NULL,'::1','2026-09-15 19:23:12'),(95,NULL,36,'staff',1,'System Administrator','admin','activity.created','Created activity “Digital Poster Making Contest”',NULL,'::1','2026-09-15 19:23:12'),(96,NULL,36,'staff',1,'System Administrator','admin','criteria.saved','Saved 4 criteria (total 100) for “Digital Poster Making Contest”',NULL,'::1','2026-09-15 19:23:12'),(97,NULL,37,'staff',1,'System Administrator','admin','activity.created','Created activity “3x3 Street Basketball Tournament”',NULL,'::1','2026-09-15 19:23:12'),(98,NULL,38,'staff',1,'System Administrator','admin','activity.created','Created activity “Mixed Volleyball Championship”',NULL,'::1','2026-09-15 19:23:12'),(99,NULL,39,'staff',1,'System Administrator','admin','activity.created','Created activity “Chess Master Tournament”',NULL,'::1','2026-09-15 19:23:12'),(100,NULL,40,'staff',1,'System Administrator','admin','activity.created','Created activity “Spoken Word Poetry”',NULL,'::1','2026-09-15 19:23:12'),(101,NULL,40,'staff',1,'System Administrator','admin','criteria.saved','Saved 4 criteria (total 100) for “Spoken Word Poetry”',NULL,'::1','2026-09-15 19:23:12'),(102,NULL,41,'staff',1,'System Administrator','admin','activity.created','Created activity “Extemporaneous Speech Competition”',NULL,'::1','2026-09-15 19:23:12'),(103,NULL,41,'staff',1,'System Administrator','admin','criteria.saved','Saved 4 criteria (total 100) for “Extemporaneous Speech Competition”',NULL,'::1','2026-09-15 19:23:12'),(104,NULL,42,'staff',1,'System Administrator','admin','activity.created','Created activity “Cosplay Runway Contest”',NULL,'::1','2026-09-15 19:23:12'),(105,NULL,42,'staff',1,'System Administrator','admin','criteria.saved','Saved 4 criteria (total 100) for “Cosplay Runway Contest”',NULL,'::1','2026-09-15 19:23:12'),(106,NULL,NULL,'staff',1,'System Administrator','admin','code.issued','Issued an access code to judge “Jane Vicente”',NULL,'::1','2026-09-15 19:23:12'),(107,NULL,NULL,'staff',1,'System Administrator','admin','code.issued','Issued an access code to judge “Micah Lago”',NULL,'::1','2026-09-15 19:23:12'),(108,NULL,NULL,'staff',1,'System Administrator','admin','code.issued','Issued an access code to judge “Sean Pabs”',NULL,'::1','2026-09-15 19:23:12'),(109,NULL,NULL,'staff',1,'System Administrator','admin','team.created','Added team “CITE”',NULL,'::1','2026-09-15 19:24:28'),(110,NULL,NULL,'staff',1,'System Administrator','admin','team.created','Added team “COED”',NULL,'::1','2026-09-15 19:24:38'),(111,NULL,NULL,'staff',1,'System Administrator','admin','team.created','Added team “SSCJ”',NULL,'::1','2026-09-15 19:24:48'),(112,NULL,NULL,'staff',1,'System Administrator','admin','team.created','Added team “CEA”',NULL,'::1','2026-09-15 19:24:55'),(113,NULL,NULL,'staff',1,'System Administrator','admin','team.created','Added team “CAHS”',NULL,'::1','2026-09-15 19:25:17'),(114,NULL,NULL,'staff',1,'System Administrator','admin','team.created','Added team “CMA”',NULL,'::1','2026-09-15 19:25:34'),(115,NULL,30,'staff',1,'System Administrator','admin','contestant.created','Added 6 team entries to “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-15 19:25:56'),(116,NULL,30,'staff',1,'System Administrator','admin','contestant.created','Added contestant “team bangan” to “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-15 19:26:29'),(117,NULL,30,'staff',1,'System Administrator','admin','contestant.created','Added contestant “team burnek” to “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-15 19:26:47'),(118,NULL,30,'staff',1,'System Administrator','admin','contestant.created','Added contestant “team dinasure” to “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-15 19:27:02'),(119,NULL,30,'staff',1,'System Administrator','admin','contestant.deleted','Removed a contestant from “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-15 19:28:19'),(120,NULL,30,'staff',1,'System Administrator','admin','contestant.deleted','Removed a contestant from “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-15 19:28:23'),(121,NULL,30,'staff',1,'System Administrator','admin','contestant.deleted','Removed a contestant from “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-15 19:28:28'),(122,NULL,30,'staff',1,'System Administrator','admin','activity.bracket','Created the 8-slot bracket of “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-15 19:28:35'),(123,NULL,30,'staff',1,'System Administrator','admin','activity.bracket','Created the 8-slot bracket of “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-15 19:36:51'),(124,NULL,NULL,'staff',2,'K C Joy V. Asne','program_head','auth.login','Signed in with a staff account',NULL,'::1','2026-09-15 19:49:42'),(125,NULL,30,'staff',1,'System Administrator','admin','activity.bracket','Created the 8-slot bracket of “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-15 21:51:18'),(126,NULL,30,'staff',1,'System Administrator','admin','activity.bracket','Created the 8-slot bracket of “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-15 21:51:24'),(127,NULL,30,'staff',1,'System Administrator','admin','activity.bracket','Created the 8-slot bracket of “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-15 22:14:05'),(128,NULL,30,'staff',1,'System Administrator','admin','activity.status','Opened for scoring: “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-15 23:03:54'),(129,NULL,30,'staff',1,'System Administrator','admin','activity.status','Set to pending: “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-15 23:04:02'),(130,NULL,30,'staff',1,'System Administrator','admin','activity.status','Opened for scoring: “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-15 23:04:11'),(131,NULL,28,'staff',1,'System Administrator','admin','contestant.created','Added 6 team entries to “Grand Vocal Solo Competition”',NULL,'::1','2026-09-15 23:05:14'),(132,NULL,NULL,'staff',2,'K C Joy V. Asne','program_head','auth.login','Signed in with a staff account',NULL,'::1','2026-09-16 00:11:10'),(133,NULL,NULL,'staff',2,'K C Joy V. Asne','program_head','auth.logout','Signed out',NULL,'::1','2026-09-16 00:12:29'),(134,NULL,NULL,'code',13,'Jane Vicente','judge','auth.login','Signed in with an access code',NULL,'::1','2026-09-16 00:12:37'),(135,NULL,NULL,'code',13,'Jane Vicente','judge','auth.logout','Signed out',NULL,'::1','2026-09-16 00:49:57'),(136,NULL,31,'staff',2,'K C Joy V. Asne','program_head','contestant.created','Added 6 team entries to “Call of Duty: Mobile (CODM) Search & Destroy”',NULL,'::1','2026-09-16 00:50:54'),(137,NULL,31,'staff',2,'K C Joy V. Asne','program_head','activity.bracket','Created 15 round robin fixtures for “Call of Duty: Mobile (CODM) Search & Destroy”',NULL,'::1','2026-09-16 00:51:04'),(138,NULL,33,'staff',1,'System Administrator','admin','contestant.created','Added 6 team entries to “Battle of the Wits Quiz Bowl”',NULL,'::1','2026-09-16 01:01:36'),(139,NULL,33,'staff',1,'System Administrator','admin','activity.results','Saved ranking results for “Battle of the Wits Quiz Bowl”',NULL,'::1','2026-09-16 01:02:07'),(140,NULL,NULL,'staff',1,'System Administrator','admin','auth.login','Signed in with a staff account',NULL,'::1','2026-09-16 10:13:15'),(141,NULL,NULL,'staff',1,'System Administrator','admin','event.deleted','Deleted event “University Foundation Day 2026”',NULL,'::1','2026-09-16 10:16:46'),(142,NULL,NULL,'staff',1,'System Administrator','admin','event.scanned','Scanned the event document “foundation_day_event_activities.pdf” (15 activities detected)',NULL,'::1','2026-09-16 10:17:38'),(143,NULL,NULL,'staff',1,'System Administrator','admin','event.created','Created event “University Foundation Day 2026”',NULL,'::1','2026-09-16 10:18:29'),(144,NULL,NULL,'staff',1,'System Administrator','admin','event.document','Attached the scanned document “foundation_day_event_activities.pdf”',NULL,'::1','2026-09-16 10:18:29'),(145,NULL,43,'staff',1,'System Administrator','admin','activity.created','Created activity “Grand Vocal Solo Competition”',NULL,'::1','2026-09-16 10:18:29'),(146,NULL,43,'staff',1,'System Administrator','admin','criteria.saved','Saved 4 criteria (total 100) for “Grand Vocal Solo Competition”',NULL,'::1','2026-09-16 10:18:29'),(147,NULL,44,'staff',1,'System Administrator','admin','activity.created','Created activity “Hip-Hop Dance Crew Showdown”',NULL,'::1','2026-09-16 10:18:29'),(148,NULL,44,'staff',1,'System Administrator','admin','criteria.saved','Saved 4 criteria (total 100) for “Hip-Hop Dance Crew Showdown”',NULL,'::1','2026-09-16 10:18:29'),(149,NULL,45,'staff',1,'System Administrator','admin','activity.created','Created activity “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-16 10:18:29'),(150,NULL,46,'staff',1,'System Administrator','admin','activity.created','Created activity “Call of Duty: Mobile (CODM) Search & Destroy”',NULL,'::1','2026-09-16 10:18:29'),(151,NULL,47,'staff',1,'System Administrator','admin','activity.created','Created activity “Mr. & Ms. Foundation Day Pageant”',NULL,'::1','2026-09-16 10:18:29'),(152,NULL,47,'staff',1,'System Administrator','admin','criteria.saved','Saved 4 criteria (total 100) for “Mr. & Ms. Foundation Day Pageant”',NULL,'::1','2026-09-16 10:18:29'),(153,NULL,48,'staff',1,'System Administrator','admin','activity.created','Created activity “Battle of the Wits Quiz Bowl”',NULL,'::1','2026-09-16 10:18:29'),(154,NULL,49,'staff',1,'System Administrator','admin','activity.created','Created activity “Acoustic Band Competition”',NULL,'::1','2026-09-16 10:18:29'),(155,NULL,49,'staff',1,'System Administrator','admin','criteria.saved','Saved 4 criteria (total 100) for “Acoustic Band Competition”',NULL,'::1','2026-09-16 10:18:29'),(156,NULL,50,'staff',1,'System Administrator','admin','activity.created','Created activity “Speed Hackathon & App Pitch”',NULL,'::1','2026-09-16 10:18:29'),(157,NULL,50,'staff',1,'System Administrator','admin','criteria.saved','Saved 4 criteria (total 100) for “Speed Hackathon & App Pitch”',NULL,'::1','2026-09-16 10:18:29'),(158,NULL,51,'staff',1,'System Administrator','admin','activity.created','Created activity “Digital Poster Making Contest”',NULL,'::1','2026-09-16 10:18:29'),(159,NULL,51,'staff',1,'System Administrator','admin','criteria.saved','Saved 4 criteria (total 100) for “Digital Poster Making Contest”',NULL,'::1','2026-09-16 10:18:29'),(160,NULL,52,'staff',1,'System Administrator','admin','activity.created','Created activity “3x3 Street Basketball Tournament”',NULL,'::1','2026-09-16 10:18:29'),(161,NULL,53,'staff',1,'System Administrator','admin','activity.created','Created activity “Mixed Volleyball Championship”',NULL,'::1','2026-09-16 10:18:30'),(162,NULL,54,'staff',1,'System Administrator','admin','activity.created','Created activity “Chess Master Tournament”',NULL,'::1','2026-09-16 10:18:30'),(163,NULL,55,'staff',1,'System Administrator','admin','activity.created','Created activity “Spoken Word Poetry”',NULL,'::1','2026-09-16 10:18:30'),(164,NULL,55,'staff',1,'System Administrator','admin','criteria.saved','Saved 4 criteria (total 100) for “Spoken Word Poetry”',NULL,'::1','2026-09-16 10:18:30'),(165,NULL,56,'staff',1,'System Administrator','admin','activity.created','Created activity “Extemporaneous Speech Competition”',NULL,'::1','2026-09-16 10:18:30'),(166,NULL,56,'staff',1,'System Administrator','admin','criteria.saved','Saved 4 criteria (total 100) for “Extemporaneous Speech Competition”',NULL,'::1','2026-09-16 10:18:30'),(167,NULL,57,'staff',1,'System Administrator','admin','activity.created','Created activity “Cosplay Runway Contest”',NULL,'::1','2026-09-16 10:18:30'),(168,NULL,57,'staff',1,'System Administrator','admin','criteria.saved','Saved 4 criteria (total 100) for “Cosplay Runway Contest”',NULL,'::1','2026-09-16 10:18:30'),(169,NULL,NULL,'staff',1,'System Administrator','admin','team.created','Added team “CITE”',NULL,'::1','2026-09-16 10:19:19'),(170,NULL,NULL,'staff',1,'System Administrator','admin','team.created','Added team “COED”',NULL,'::1','2026-09-16 10:19:27'),(171,NULL,NULL,'staff',1,'System Administrator','admin','team.created','Added team “CMA”',NULL,'::1','2026-09-16 10:19:34'),(172,NULL,NULL,'staff',1,'System Administrator','admin','team.created','Added team “SSCJ”',NULL,'::1','2026-09-16 10:19:42'),(173,NULL,NULL,'staff',1,'System Administrator','admin','team.created','Added team “CEA”',NULL,'::1','2026-09-16 10:19:49'),(174,NULL,45,'staff',1,'System Administrator','admin','contestant.created','Added 5 team entries to “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-16 10:20:04'),(175,NULL,45,'staff',1,'System Administrator','admin','activity.bracket','Created the 8-slot bracket of “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-16 10:20:12'),(176,NULL,45,'staff',1,'System Administrator','admin','activity.match','Recorded CMA 1–2 COED — COED wins in “Mobile Legends: Bang Bang Tournament”',NULL,'::1','2026-09-16 10:20:38'),(177,NULL,NULL,'staff',1,'System Administrator','admin','event.deleted','Deleted event “University Foundation Day 2026”',NULL,'::1','2026-09-16 10:34:41'),(178,NULL,NULL,'staff',2,'K C Joy V. Asne','program_head','auth.login','Signed in with a staff account',NULL,'::1','2026-09-16 10:35:45'),(179,7,NULL,'staff',1,'System Administrator','admin','event.created','Created event “AMATEUR” (single competition)',NULL,'::1','2026-09-16 10:45:46'),(180,7,58,'staff',1,'System Administrator','admin','activity.created','Created the competition of “AMATEUR”',NULL,'::1','2026-09-16 10:45:46'),(181,7,NULL,'staff',1,'System Administrator','admin','event.updated','Updated event “AMATEUR” (default_format)',NULL,'::1','2026-09-16 10:47:35'),(182,7,58,'staff',1,'System Administrator','admin','criteria.saved','Saved 3 criteria (total 100) for “AMATEUR”',NULL,'::1','2026-09-16 10:49:18'),(183,7,NULL,'staff',1,'System Administrator','admin','code.issued','Issued an access code to judge “ANNA”',NULL,'::1','2026-09-16 10:49:37'),(184,7,NULL,'staff',1,'System Administrator','admin','code.issued','Issued an access code to judge “IVAN”',NULL,'::1','2026-09-16 10:49:51'),(185,7,NULL,'staff',1,'System Administrator','admin','code.issued','Issued an access code to judge “MICAH”',NULL,'::1','2026-09-16 10:49:58'),(186,7,58,'staff',1,'System Administrator','admin','contestant.created','Added contestant “JESSA MAE” to “AMATEUR”',NULL,'::1','2026-09-16 10:51:07'),(187,7,58,'staff',1,'System Administrator','admin','contestant.created','Added contestant “RHICK” to “AMATEUR”',NULL,'::1','2026-09-16 10:51:19'),(188,7,58,'staff',1,'System Administrator','admin','contestant.created','Added contestant “JANE” to “AMATEUR”',NULL,'::1','2026-09-16 10:51:30'),(189,7,58,'staff',1,'System Administrator','admin','contestant.created','Added contestant “JOSE” to “AMATEUR”',NULL,'::1','2026-09-16 10:51:51'),(190,7,58,'staff',1,'System Administrator','admin','contestant.created','Added contestant “JUAN” to “AMATEUR”',NULL,'::1','2026-09-16 10:52:02'),(191,7,NULL,'code',16,'ANNA','judge','auth.login','Signed in with an access code',NULL,'::1','2026-09-16 10:56:04'),(192,7,NULL,'staff',1,'System Administrator','admin','event.status','Changed event status to ongoing',NULL,'::1','2026-09-16 10:56:33'),(193,7,58,'staff',1,'System Administrator','admin','activity.status','Opened for scoring: “AMATEUR”',NULL,'::1','2026-09-16 10:57:17'),(194,NULL,NULL,'staff',2,'K C Joy V. Asne','program_head','auth.logout','Signed out',NULL,'::1','2026-09-16 10:58:02'),(195,7,NULL,'code',16,'ANNA','judge','auth.login','Signed in with an access code',NULL,'::1','2026-09-16 10:58:26'),(196,7,58,'code',16,'ANNA','judge','score.contestant','Submitted the scores for “JESSA MAE” in “AMATEUR”',NULL,'::1','2026-09-16 10:59:21'),(197,7,58,'code',16,'ANNA','judge','score.contestant','Submitted the scores for “RHICK” in “AMATEUR”',NULL,'::1','2026-09-16 10:59:52'),(198,7,58,'code',16,'ANNA','judge','score.contestant','Submitted the scores for “JANE” in “AMATEUR”',NULL,'::1','2026-09-16 11:02:16'),(199,7,58,'code',16,'ANNA','judge','score.contestant','Submitted the scores for “JOSE” in “AMATEUR”',NULL,'::1','2026-09-16 11:02:33'),(200,7,58,'code',16,'ANNA','judge','score.contestant','Submitted the scores for “JUAN” in “AMATEUR”',NULL,'::1','2026-09-16 11:02:48'),(201,7,58,'code',16,'ANNA','judge','score.submitted','Submitted final scores for “AMATEUR”',NULL,'::1','2026-09-16 11:02:48'),(202,NULL,NULL,'staff',1,'System Administrator','admin','auth.login','Signed in with a staff account',NULL,'::1','2026-09-28 16:21:12'),(203,7,NULL,'staff',1,'System Administrator','admin','code.issued','Issued an access code to display “Main Stage Wall”',NULL,'::1','2026-09-28 16:22:14'),(204,NULL,NULL,'staff',1,'System Administrator','admin','auth.logout','Signed out',NULL,'::1','2026-09-28 16:22:22'),(205,7,NULL,'code',19,'Main Stage Wall','display','auth.login','Signed in with an access code',NULL,'::1','2026-09-28 16:22:25'),(206,7,NULL,'code',19,'Main Stage Wall','display','auth.logout','Signed out',NULL,'::1','2026-09-28 16:26:18'),(207,NULL,NULL,'staff',1,'System Administrator','admin','auth.login','Signed in with a staff account',NULL,'::1','2026-09-28 16:26:33'),(208,7,NULL,'staff',1,'System Administrator','admin','event.status','Changed event status to completed',NULL,'::1','2026-09-28 16:26:58'),(209,NULL,NULL,'staff',1,'System Administrator','admin','auth.password','Changed their password',NULL,'::1','2026-09-28 16:27:30'),(210,NULL,NULL,'staff',1,'System Administrator','admin','auth.logout','Signed out',NULL,'::1','2026-09-28 16:28:00'),(211,NULL,NULL,'staff',1,'System Administrator','admin','auth.login','Signed in with a staff account',NULL,'::1','2026-09-28 16:28:39'),(212,7,NULL,'staff',1,'System Administrator','admin','event.public','Published the event on the public results page',NULL,'::1','2026-09-28 16:28:44'),(213,NULL,NULL,'staff',1,'System Administrator','admin','auth.logout','Signed out',NULL,'::1','2026-09-28 16:33:32'),(236,NULL,NULL,'staff',1,'System Administrator','admin','auth.login','Signed in with a staff account',NULL,'::1','2026-09-28 16:37:01'),(237,7,58,'staff',1,'System Administrator','admin','activity.status','Closed — scores are now final: “AMATEUR”',NULL,'::1','2026-09-28 16:37:20'),(238,7,NULL,'staff',1,'System Administrator','admin','event.public','Removed the event from the public results page',NULL,'::1','2026-09-28 16:42:41'),(239,7,NULL,'staff',1,'System Administrator','admin','event.public','Published the event on the public results page',NULL,'::1','2026-09-28 16:42:43'),(240,NULL,NULL,'staff',1,'System Administrator','admin','auth.logout','Signed out',NULL,'::1','2026-09-28 16:46:59'),(241,NULL,NULL,'staff',1,'System Administrator','admin','auth.login','Signed in with a staff account',NULL,'::1','2026-09-28 16:47:32'),(242,NULL,NULL,'staff',1,'System Administrator','admin','auth.logout','Signed out',NULL,'::1','2026-09-28 16:47:48'),(243,7,NULL,'code',19,'Main Stage Wall','display','auth.login','Signed in with an access code',NULL,'::1','2026-09-28 16:47:51'),(272,7,NULL,'code',19,'Main Stage Wall','display','auth.logout','Signed out',NULL,'::1','2026-09-28 16:48:59'),(273,NULL,NULL,'staff',1,'System Administrator','admin','auth.login','Signed in with a staff account',NULL,'::1','2026-09-28 16:49:03'),(274,7,NULL,'staff',1,'System Administrator','admin','display.background','Set the stage background for all contestants',NULL,'::1','2026-09-28 16:54:18'),(275,7,NULL,'staff',1,'System Administrator','admin','display.background','Removed the stage background for all contestants',NULL,'::1','2026-09-28 16:54:32'),(276,7,NULL,'staff',1,'System Administrator','admin','display.background','Set the stage background of “JESSA MAE”',NULL,'::1','2026-09-28 16:56:29'),(277,7,NULL,'staff',1,'System Administrator','admin','display.background','Removed the stage background of “JESSA MAE”',NULL,'::1','2026-09-28 16:56:40'),(306,7,58,'staff',1,'System Administrator','admin','display.background','Set the stage background of “JESSA MAE”',NULL,'::1','2026-09-28 16:58:46'),(307,7,58,'staff',1,'System Administrator','admin','contestant.updated','Updated contestant “JESSA MAE” in “AMATEUR”',NULL,'::1','2026-09-28 17:00:06'),(308,7,58,'staff',1,'System Administrator','admin','contestant.updated','Uploaded a picture for “JESSA MAE”',NULL,'::1','2026-09-28 17:00:06'),(309,7,58,'staff',1,'System Administrator','admin','contestant.updated','Updated contestant “RHICK” in “AMATEUR”',NULL,'::1','2026-09-28 17:00:16'),(310,7,58,'staff',1,'System Administrator','admin','contestant.updated','Uploaded a picture for “RHICK”',NULL,'::1','2026-09-28 17:00:16'),(311,7,58,'staff',1,'System Administrator','admin','contestant.updated','Updated contestant “JANE” in “AMATEUR”',NULL,'::1','2026-09-28 17:00:25'),(312,7,58,'staff',1,'System Administrator','admin','contestant.updated','Uploaded a picture for “JANE”',NULL,'::1','2026-09-28 17:00:25'),(313,7,58,'staff',1,'System Administrator','admin','contestant.updated','Updated contestant “JOSE” in “AMATEUR”',NULL,'::1','2026-09-28 17:00:40'),(314,7,58,'staff',1,'System Administrator','admin','contestant.updated','Uploaded a picture for “JOSE”',NULL,'::1','2026-09-28 17:00:40'),(315,7,58,'staff',1,'System Administrator','admin','contestant.updated','Updated contestant “JUAN” in “AMATEUR”',NULL,'::1','2026-09-28 17:00:59'),(316,7,58,'staff',1,'System Administrator','admin','contestant.updated','Updated contestant “JUAN” in “AMATEUR”',NULL,'::1','2026-09-28 17:01:09'),(317,7,58,'staff',1,'System Administrator','admin','contestant.updated','Uploaded a picture for “JUAN”',NULL,'::1','2026-09-28 17:01:09'),(318,7,58,'staff',1,'System Administrator','admin','display.background','Set the stage background of “RHICK”',NULL,'::1','2026-09-28 17:01:46'),(319,7,58,'staff',1,'System Administrator','admin','display.background','Set the stage background of “RHICK”',NULL,'::1','2026-09-28 17:01:59'),(320,7,58,'staff',1,'System Administrator','admin','display.background','Removed the stage background of “RHICK”',NULL,'::1','2026-09-28 17:03:59'),(405,NULL,NULL,'staff',1,'System Administrator','admin','auth.logout','Signed out',NULL,'::1','2026-09-28 17:36:51'),(406,NULL,NULL,'staff',1,'System Administrator','admin','auth.login','Signed in with a staff account',NULL,'::1','2026-09-28 17:49:21'),(407,NULL,NULL,'staff',1,'System Administrator','admin','user.updated','Updated staff account “K C Joy V. Asne” (program_head)',NULL,'::1','2026-09-28 17:52:12'),(408,7,NULL,'staff',1,'System Administrator','admin','code.issued','Issued an access code to facilitator “Display Operator”',NULL,'::1','2026-09-28 17:52:40'),(409,NULL,NULL,'staff',1,'System Administrator','admin','auth.logout','Signed out',NULL,'::1','2026-09-28 17:52:51'),(410,7,NULL,'code',32,'Display Operator','facilitator','auth.login','Signed in with an access code',NULL,'::1','2026-09-28 17:52:53'),(439,NULL,NULL,'staff',1,'System Administrator','admin','auth.login','Signed in with a staff account',NULL,'::1','2026-09-29 10:12:13'),(440,7,58,'staff',1,'System Administrator','admin','activity.awards','Saved 1 special award for “AMATEUR”',NULL,'::1','2026-09-29 10:13:41'),(441,7,NULL,'staff',1,'System Administrator','admin','event.public','Removed the event from the public results page',NULL,'::1','2026-09-29 10:14:56'),(442,7,NULL,'staff',1,'System Administrator','admin','event.public','Published the event on the public results page',NULL,'::1','2026-09-29 10:27:10'),(443,7,58,'staff',1,'System Administrator','admin','activity.status','“AMATEUR” is now live',NULL,'::1','2026-09-29 11:08:08'),(444,7,NULL,'staff',1,'System Administrator','admin','event.status','Event status changed to ongoing (follows its activities)',NULL,'::1','2026-09-29 11:08:08'),(445,7,58,'staff',1,'System Administrator','admin','activity.status','“AMATEUR” is final — results are locked',NULL,'::1','2026-09-29 11:08:27'),(446,7,NULL,'staff',1,'System Administrator','admin','event.status','Event status changed to completed (follows its activities)',NULL,'::1','2026-09-29 11:08:27'),(447,7,58,'staff',1,'System Administrator','admin','activity.certified','Certified the results of “AMATEUR” (0A05-DD4C-88B6)','{\"hash\":\"0a05dd4c88b6ae37264a80c3a25e62a001d3cfd4503fc0c6c5eda50bd36afaa4\"}','::1','2026-09-29 11:08:50'),(448,7,58,'staff',1,'System Administrator','admin','result.exported','Exported the results of “AMATEUR” to CSV',NULL,'::1','2026-09-29 11:09:40');
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `activity_results`
--

DROP TABLE IF EXISTS `activity_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_results` (
  `activity_id` int unsigned NOT NULL,
  `contestant_id` int unsigned NOT NULL,
  `value` decimal(12,3) DEFAULT NULL,
  `remarks` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`activity_id`,`contestant_id`),
  KEY `fk_results_contestant` (`contestant_id`),
  CONSTRAINT `fk_results_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_results_contestant` FOREIGN KEY (`contestant_id`) REFERENCES `contestants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_results`
--

LOCK TABLES `activity_results` WRITE;
/*!40000 ALTER TABLE `activity_results` DISABLE KEYS */;
/*!40000 ALTER TABLE `activity_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `awards`
--

DROP TABLE IF EXISTS `awards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `awards` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` int unsigned NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `criterion_id` int unsigned DEFAULT NULL,
  `contestant_id` int unsigned DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_awards_activity` (`activity_id`,`sort_order`),
  KEY `fk_awards_criterion` (`criterion_id`),
  KEY `fk_awards_contestant` (`contestant_id`),
  CONSTRAINT `fk_awards_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_awards_contestant` FOREIGN KEY (`contestant_id`) REFERENCES `contestants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_awards_criterion` FOREIGN KEY (`criterion_id`) REFERENCES `criteria` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `awards`
--

LOCK TABLES `awards` WRITE;
/*!40000 ALTER TABLE `awards` DISABLE KEYS */;
INSERT INTO `awards` VALUES (1,58,'Best in Audience Impact',NULL,35,1);
/*!40000 ALTER TABLE `awards` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contestant_submissions`
--

DROP TABLE IF EXISTS `contestant_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contestant_submissions` (
  `judge_id` int unsigned NOT NULL,
  `contestant_id` int unsigned NOT NULL,
  `submitted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`judge_id`,`contestant_id`),
  KEY `fk_cs_contestant` (`contestant_id`),
  CONSTRAINT `fk_cs_contestant` FOREIGN KEY (`contestant_id`) REFERENCES `contestants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cs_judge` FOREIGN KEY (`judge_id`) REFERENCES `access_codes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contestant_submissions`
--

LOCK TABLES `contestant_submissions` WRITE;
/*!40000 ALTER TABLE `contestant_submissions` DISABLE KEYS */;
INSERT INTO `contestant_submissions` VALUES (16,35,'2026-09-16 10:59:21'),(16,36,'2026-09-16 10:59:52'),(16,37,'2026-09-16 11:02:16'),(16,38,'2026-09-16 11:02:33'),(16,39,'2026-09-16 11:02:48');
/*!40000 ALTER TABLE `contestant_submissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contestants`
--

DROP TABLE IF EXISTS `contestants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contestants` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` int unsigned NOT NULL,
  `team_id` int unsigned DEFAULT NULL,
  `number` int NOT NULL DEFAULT '0',
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `members` text COLLATE utf8mb4_unicode_ci,
  `color` varchar(7) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stage_bg` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_contestant_id` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_contestants_team` (`team_id`),
  KEY `idx_contestants_activity_number` (`activity_id`,`number`),
  CONSTRAINT `fk_contestants_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_contestants_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=157 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contestants`
--

LOCK TABLES `contestants` WRITE;
/*!40000 ALTER TABLE `contestants` DISABLE KEYS */;
INSERT INTO `contestants` VALUES (35,58,NULL,1,'JESSA MAE',NULL,NULL,'#14532D','pictures/event-7/contestant-35-e40cd400979c.jpg','pictures/event-7/stage-contestant-35-1f0da618ba12.jpg',NULL,'2026-09-16 10:51:07'),(36,58,NULL,2,'RHICK',NULL,NULL,'#5B21B6','pictures/event-7/contestant-36-95fd680ccae4.jpg',NULL,NULL,'2026-09-16 10:51:19'),(37,58,NULL,3,'JANE',NULL,NULL,'#1A1A1A','pictures/event-7/contestant-37-929f201070a7.jpg',NULL,NULL,'2026-09-16 10:51:30'),(38,58,NULL,4,'JOSE',NULL,NULL,'#7F1D1D','pictures/event-7/contestant-38-19cd87149a78.png',NULL,NULL,'2026-09-16 10:51:51'),(39,58,NULL,5,'JUAN',NULL,NULL,'#172554','pictures/event-7/contestant-39-8f12b26200e5.jpg',NULL,NULL,'2026-09-16 10:52:02');
/*!40000 ALTER TABLE `contestants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `criteria`
--

DROP TABLE IF EXISTS `criteria`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `criteria` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` int unsigned NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `max_score` decimal(7,2) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_criteria_activity_order` (`activity_id`,`sort_order`,`id`),
  CONSTRAINT `fk_criteria_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=97 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `criteria`
--

LOCK TABLES `criteria` WRITE;
/*!40000 ALTER TABLE `criteria` DISABLE KEYS */;
INSERT INTO `criteria` VALUES (73,58,'VOICE QUALITY',NULL,50.00,0),(74,58,'AUDIENCE IMPACT',NULL,10.00,1),(75,58,'CLARITY',NULL,40.00,2);
/*!40000 ALTER TABLE `criteria` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `deductions`
--

DROP TABLE IF EXISTS `deductions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deductions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` int unsigned NOT NULL,
  `contestant_id` int unsigned NOT NULL,
  `points` decimal(7,2) NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_deductions_activity` (`activity_id`),
  KEY `fk_deductions_contestant` (`contestant_id`),
  CONSTRAINT `fk_deductions_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_deductions_contestant` FOREIGN KEY (`contestant_id`) REFERENCES `contestants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `deductions`
--

LOCK TABLES `deductions` WRITE;
/*!40000 ALTER TABLE `deductions` DISABLE KEYS */;
/*!40000 ALTER TABLE `deductions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `display_state`
--

DROP TABLE IF EXISTS `display_state`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `display_state` (
  `event_id` int unsigned NOT NULL,
  `state` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`),
  CONSTRAINT `fk_display_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `display_state`
--

LOCK TABLES `display_state` WRITE;
/*!40000 ALTER TABLE `display_state` DISABLE KEYS */;
INSERT INTO `display_state` VALUES (7,'{\"scene\":\"blank\",\"activity_id\":58,\"contestant_id\":36,\"show_scores\":false,\"reveal_step\":3,\"title\":\"Intermission Number By Ivan Gonzales\",\"text\":\"\",\"effect\":\"glitter\",\"burst\":58}',349,'2026-09-29 11:09:47');
/*!40000 ALTER TABLE `display_state` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `events`
--

DROP TABLE IF EXISTS `events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `venue` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nature` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_at` datetime DEFAULT NULL,
  `end_at` datetime DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('draft','upcoming','ongoing','completed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'upcoming',
  `default_format` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'score',
  `structure` enum('multi','single') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'multi',
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int unsigned DEFAULT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT '0',
  `has_overall` tinyint(1) NOT NULL DEFAULT '1',
  `points_mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'list',
  `placement_points` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '[10,7,5]',
  `participation_points` decimal(6,2) NOT NULL DEFAULT '2.00',
  `owner_id` int unsigned DEFAULT NULL,
  `program_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `program_file_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `program_text` mediumtext COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_events_owner_status` (`owner_id`,`status`,`start_date`),
  KEY `idx_events_archived` (`archived_at`),
  CONSTRAINT `fk_events_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `events`
--

LOCK TABLES `events` WRITE;
/*!40000 ALTER TABLE `events` DISABLE KEYS */;
INSERT INTO `events` VALUES (7,'AMATEUR',NULL,'PHINMA COC',NULL,'2026-09-16 07:30:00','2026-09-16 20:00:00','2026-09-16','2026-09-16','completed','score','single',NULL,NULL,1,0,'list','[10,7,5]',2.00,2,NULL,NULL,NULL,'2026-09-16 10:45:46');
/*!40000 ALTER TABLE `events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `judge_activities`
--

DROP TABLE IF EXISTS `judge_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `judge_activities` (
  `judge_id` int unsigned NOT NULL,
  `activity_id` int unsigned NOT NULL,
  PRIMARY KEY (`judge_id`,`activity_id`),
  KEY `idx_ja_activity_judge` (`activity_id`,`judge_id`),
  CONSTRAINT `fk_ja_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ja_judge` FOREIGN KEY (`judge_id`) REFERENCES `access_codes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `judge_activities`
--

LOCK TABLES `judge_activities` WRITE;
/*!40000 ALTER TABLE `judge_activities` DISABLE KEYS */;
INSERT INTO `judge_activities` VALUES (16,58),(17,58),(18,58);
/*!40000 ALTER TABLE `judge_activities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `judge_submissions`
--

DROP TABLE IF EXISTS `judge_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `judge_submissions` (
  `judge_id` int unsigned NOT NULL,
  `activity_id` int unsigned NOT NULL,
  `submitted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`judge_id`,`activity_id`),
  KEY `idx_js_activity_judge` (`activity_id`,`judge_id`),
  CONSTRAINT `fk_js_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_js_judge` FOREIGN KEY (`judge_id`) REFERENCES `access_codes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `judge_submissions`
--

LOCK TABLES `judge_submissions` WRITE;
/*!40000 ALTER TABLE `judge_submissions` DISABLE KEYS */;
INSERT INTO `judge_submissions` VALUES (16,58,'2026-09-16 11:02:48');
/*!40000 ALTER TABLE `judge_submissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `judge_unlocks`
--

DROP TABLE IF EXISTS `judge_unlocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `judge_unlocks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `judge_id` int unsigned NOT NULL,
  `activity_id` int unsigned NOT NULL,
  `unlocked_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unlocked_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_unlocks_activity` (`activity_id`,`judge_id`),
  KEY `fk_unlocks_judge` (`judge_id`),
  CONSTRAINT `fk_unlocks_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_unlocks_judge` FOREIGN KEY (`judge_id`) REFERENCES `access_codes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `judge_unlocks`
--

LOCK TABLES `judge_unlocks` WRITE;
/*!40000 ALTER TABLE `judge_unlocks` DISABLE KEYS */;
/*!40000 ALTER TABLE `judge_unlocks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_attempts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_ip` (`ip`,`attempted_at`),
  KEY `idx_attempts_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_attempts`
--

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `matches`
--

DROP TABLE IF EXISTS `matches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `matches` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` int unsigned NOT NULL,
  `stage` enum('main','third','rr') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'main',
  `round` smallint unsigned NOT NULL,
  `position` smallint unsigned NOT NULL,
  `contestant_a_id` int unsigned DEFAULT NULL,
  `contestant_b_id` int unsigned DEFAULT NULL,
  `score_a` decimal(10,2) DEFAULT NULL,
  `score_b` decimal(10,2) DEFAULT NULL,
  `winner_id` int unsigned DEFAULT NULL,
  `is_bye` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('pending','done') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `next_match_id` int unsigned DEFAULT NULL,
  `next_slot` enum('a','b') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_matches_a` (`contestant_a_id`),
  KEY `fk_matches_b` (`contestant_b_id`),
  KEY `fk_matches_winner` (`winner_id`),
  KEY `idx_matches_activity_order` (`activity_id`,`stage`,`round`,`position`),
  KEY `idx_matches_next` (`next_match_id`),
  CONSTRAINT `fk_matches_a` FOREIGN KEY (`contestant_a_id`) REFERENCES `contestants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_matches_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_matches_b` FOREIGN KEY (`contestant_b_id`) REFERENCES `contestants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_matches_winner` FOREIGN KEY (`winner_id`) REFERENCES `contestants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `matches`
--

LOCK TABLES `matches` WRITE;
/*!40000 ALTER TABLE `matches` DISABLE KEYS */;
/*!40000 ALTER TABLE `matches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `score_history`
--

DROP TABLE IF EXISTS `score_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `score_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` int unsigned NOT NULL,
  `judge_id` int unsigned NOT NULL,
  `contestant_id` int unsigned NOT NULL,
  `criterion_id` int unsigned NOT NULL,
  `old_score` decimal(7,2) DEFAULT NULL,
  `new_score` decimal(7,2) DEFAULT NULL,
  `after_unlock` tinyint(1) NOT NULL DEFAULT '0',
  `changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_history_activity` (`activity_id`,`changed_at`),
  CONSTRAINT `fk_history_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `score_history`
--

LOCK TABLES `score_history` WRITE;
/*!40000 ALTER TABLE `score_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `score_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `score_notes`
--

DROP TABLE IF EXISTS `score_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `score_notes` (
  `judge_id` int unsigned NOT NULL,
  `contestant_id` int unsigned NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`judge_id`,`contestant_id`),
  KEY `fk_notes_contestant` (`contestant_id`),
  CONSTRAINT `fk_notes_contestant` FOREIGN KEY (`contestant_id`) REFERENCES `contestants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notes_judge` FOREIGN KEY (`judge_id`) REFERENCES `access_codes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `score_notes`
--

LOCK TABLES `score_notes` WRITE;
/*!40000 ALTER TABLE `score_notes` DISABLE KEYS */;
/*!40000 ALTER TABLE `score_notes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `scores`
--

DROP TABLE IF EXISTS `scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scores` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `judge_id` int unsigned NOT NULL,
  `contestant_id` int unsigned NOT NULL,
  `criterion_id` int unsigned NOT NULL,
  `score` decimal(7,2) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_score` (`judge_id`,`contestant_id`,`criterion_id`),
  KEY `fk_scores_criterion` (`criterion_id`),
  KEY `idx_scores_contestant_judge` (`contestant_id`,`judge_id`,`criterion_id`,`score`),
  CONSTRAINT `fk_scores_contestant` FOREIGN KEY (`contestant_id`) REFERENCES `contestants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_scores_criterion` FOREIGN KEY (`criterion_id`) REFERENCES `criteria` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_scores_judge` FOREIGN KEY (`judge_id`) REFERENCES `access_codes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=157 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `scores`
--

LOCK TABLES `scores` WRITE;
/*!40000 ALTER TABLE `scores` DISABLE KEYS */;
INSERT INTO `scores` VALUES (3,16,35,73,30.00,'2026-09-16 10:59:01'),(4,16,35,74,6.00,'2026-09-16 10:59:06'),(7,16,35,75,25.00,'2026-09-16 10:59:13'),(9,16,36,73,0.00,'2026-09-16 10:59:43'),(10,16,36,74,0.00,'2026-09-16 10:59:45'),(11,16,36,75,0.00,'2026-09-16 10:59:48'),(12,16,37,73,49.00,'2026-09-16 10:59:56'),(13,16,37,74,9.00,'2026-09-16 11:00:01'),(14,16,37,75,38.00,'2026-09-16 11:02:11'),(16,16,38,73,48.00,'2026-09-16 11:02:21'),(18,16,38,74,10.00,'2026-09-16 11:02:26'),(19,16,38,75,26.00,'2026-09-16 11:02:30'),(20,16,39,73,36.00,'2026-09-16 11:02:39'),(21,16,39,74,8.00,'2026-09-16 11:02:41'),(22,16,39,75,37.00,'2026-09-16 11:02:44');
/*!40000 ALTER TABLE `scores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teams`
--

DROP TABLE IF EXISTS `teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teams` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int unsigned NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(7) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_teams_event_name` (`event_id`,`name`),
  CONSTRAINT `fk_teams_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teams`
--

LOCK TABLES `teams` WRITE;
/*!40000 ALTER TABLE `teams` DISABLE KEYS */;
/*!40000 ALTER TABLE `teams` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','program_head') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'program_head',
  `program` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_users_role_active` (`role`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'System Administrator','admin','$2y$10$MwkUx5MPPDGxPyPhN42csu6DBY.Dg4fwq9guy1CK5VcVoJJOU9UeC','admin',NULL,1,'2026-09-29 10:12:13','2026-09-15 00:08:19'),(2,'K C Joy V. Asne','keysiee','$2y$10$Cg0LK08o549A48dLHt0Xzu6lf080Sa35h/8/4oK6QTn8ihLiWgNo.','program_head',NULL,1,'2026-09-16 10:35:45','2026-09-15 01:37:44');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'coc_tabulation'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-29 11:18:28
