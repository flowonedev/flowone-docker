/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.15-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: devc_vps_dash
-- ------------------------------------------------------
-- Server version	10.11.15-MariaDB-ubu2204

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
-- Table structure for table `account_history`
--

DROP TABLE IF EXISTS `account_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `primary_email` varchar(255) NOT NULL COMMENT 'The main login email (owner)',
  `account_email` varchar(255) NOT NULL COMMENT 'The connected account email',
  `account_type` enum('imap','google_oauth','microsoft_oauth','google_calendar') NOT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `server_settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'IMAP/SMTP settings for quick reconnect' CHECK (json_valid(`server_settings`)),
  `provider` varchar(50) DEFAULT NULL COMMENT 'google, microsoft, or provider preset name',
  `disconnected_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_history` (`primary_email`,`account_email`,`account_type`),
  KEY `idx_primary_email` (`primary_email`),
  KEY `idx_account_type` (`account_type`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `activity_log`
--

DROP TABLE IF EXISTS `activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `entity_name` varchar(255) DEFAULT NULL,
  `board_id` int(11) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_board` (`board_id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_user` (`user_email`),
  KEY `idx_created` (`created_at`),
  KEY `idx_action` (`action_type`)
) ENGINE=InnoDB AUTO_INCREMENT=1582 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `admin_users`
--

DROP TABLE IF EXISTS `admin_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('super_admin','admin','user') DEFAULT 'user',
  `email` varchar(255) DEFAULT NULL,
  `status` enum('active','suspended') DEFAULT 'active',
  `totp_secret` varchar(64) DEFAULT NULL,
  `totp_enabled` tinyint(1) DEFAULT 0,
  `totp_backup_codes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ai_cached_issues`
--

DROP TABLE IF EXISTS `ai_cached_issues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_cached_issues` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `issue_type` varchar(100) NOT NULL,
  `service` varchar(50) DEFAULT NULL,
  `issue_key` varchar(255) NOT NULL,
  `severity` enum('low','medium','high','critical') DEFAULT 'medium',
  `description` text DEFAULT NULL,
  `detected_at` timestamp NULL DEFAULT current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_issue` (`issue_type`,`service`,`issue_key`),
  KEY `idx_service` (`service`),
  KEY `idx_severity` (`severity`),
  KEY `idx_resolved` (`resolved_at`),
  KEY `idx_detected_at` (`detected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ai_conversations`
--

DROP TABLE IF EXISTS `ai_conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_conversations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_updated_at` (`updated_at`),
  CONSTRAINT `ai_conversations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=81 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ai_helper_settings`
--

DROP TABLE IF EXISTS `ai_helper_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_helper_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ai_messages`
--

DROP TABLE IF EXISTS `ai_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(10) unsigned NOT NULL,
  `role` enum('user','assistant','system') NOT NULL,
  `content` text NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_conversation_id` (`conversation_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `ai_messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=393 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `app_templates`
--

DROP TABLE IF EXISTS `app_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `version` varchar(20) DEFAULT NULL,
  `category` enum('cms','ecommerce','forum','framework','other') DEFAULT 'cms',
  `icon` varchar(255) DEFAULT NULL,
  `download_url` varchar(500) DEFAULT NULL,
  `requirements` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '{"php": ">=8.0", "mysql": true, "extensions": ["curl", "gd"]}' CHECK (json_valid(`requirements`)),
  `install_script` text DEFAULT NULL,
  `post_install` text DEFAULT NULL,
  `status` enum('active','deprecated') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_app` varchar(50) NOT NULL DEFAULT 'panel',
  `severity` enum('critical','high','medium','low','info') NOT NULL DEFAULT 'info',
  `action` varchar(100) NOT NULL,
  `actor` varchar(50) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_email` varchar(255) DEFAULT NULL,
  `target` varchar(255) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `backup_path` varchar(500) DEFAULT NULL,
  `diff` mediumtext DEFAULT NULL,
  `outcome` enum('success','failed','rollback','error','warning') NOT NULL DEFAULT 'success',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_action` (`action`),
  KEY `idx_actor` (`actor`),
  KEY `idx_target` (`target`),
  KEY `idx_outcome` (`outcome`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_source_app` (`source_app`),
  KEY `idx_severity` (`severity`),
  KEY `idx_user_email` (`user_email`)
) ENGINE=InnoDB AUTO_INCREMENT=10281 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_desktop_tasks`
--

DROP TABLE IF EXISTS `automation_desktop_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_desktop_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `task_type` varchar(50) NOT NULL COMMENT 'printer_list, printer_print, etc.',
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Task config/parameters' CHECK (json_valid(`payload`)),
  `status` enum('pending','processing','completed','failed','timeout') DEFAULT 'pending',
  `result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Response from desktop app' CHECK (json_valid(`result`)),
  `workflow_execution_id` int(11) DEFAULT NULL COMMENT 'Associated workflow execution',
  `node_uid` varchar(64) DEFAULT NULL COMMENT 'Workflow node that triggered this task',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`,`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_hub_connections`
--

DROP TABLE IF EXISTS `automation_hub_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_hub_connections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `provider` varchar(50) NOT NULL,
  `access_token_encrypted` text DEFAULT NULL,
  `refresh_token_encrypted` text DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `api_key_encrypted` text DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `connected_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_provider` (`user_email`,`provider`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_hub_delayed_executions`
--

DROP TABLE IF EXISTS `automation_hub_delayed_executions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_hub_delayed_executions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `execution_id` int(10) unsigned NOT NULL,
  `resume_node_uid` varchar(36) NOT NULL,
  `resume_at` datetime NOT NULL,
  `input_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`input_data`)),
  `is_processed` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `execution_id` (`execution_id`),
  KEY `idx_resume` (`is_processed`,`resume_at`),
  CONSTRAINT `automation_hub_delayed_executions_ibfk_1` FOREIGN KEY (`execution_id`) REFERENCES `automation_hub_executions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_hub_edges`
--

DROP TABLE IF EXISTS `automation_hub_edges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_hub_edges` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `workflow_id` int(10) unsigned NOT NULL,
  `source_node_uid` varchar(36) NOT NULL,
  `target_node_uid` varchar(36) NOT NULL,
  `source_port` varchar(50) DEFAULT 'output',
  `target_port` varchar(50) DEFAULT 'input',
  `edge_style` enum('solid','dashed') DEFAULT 'solid',
  PRIMARY KEY (`id`),
  KEY `idx_workflow` (`workflow_id`),
  CONSTRAINT `automation_hub_edges_ibfk_1` FOREIGN KEY (`workflow_id`) REFERENCES `automation_hub_workflows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=173 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_hub_executions`
--

DROP TABLE IF EXISTS `automation_hub_executions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_hub_executions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `workflow_id` int(10) unsigned NOT NULL,
  `trigger_node_uid` varchar(36) DEFAULT NULL,
  `status` enum('running','completed','failed','cancelled') DEFAULT 'running',
  `trigger_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`trigger_data`)),
  `is_test` tinyint(1) DEFAULT 0,
  `started_at` datetime DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_workflow_status` (`workflow_id`,`status`),
  KEY `idx_started` (`started_at`),
  CONSTRAINT `automation_hub_executions_ibfk_1` FOREIGN KEY (`workflow_id`) REFERENCES `automation_hub_workflows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_hub_node_executions`
--

DROP TABLE IF EXISTS `automation_hub_node_executions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_hub_node_executions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `execution_id` int(10) unsigned NOT NULL,
  `node_uid` varchar(36) NOT NULL,
  `status` enum('pending','running','completed','failed','skipped') DEFAULT 'pending',
  `input_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`input_data`)),
  `output_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`output_data`)),
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `duration_ms` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_execution` (`execution_id`),
  CONSTRAINT `automation_hub_node_executions_ibfk_1` FOREIGN KEY (`execution_id`) REFERENCES `automation_hub_executions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=210 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_hub_nodes`
--

DROP TABLE IF EXISTS `automation_hub_nodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_hub_nodes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `workflow_id` int(10) unsigned NOT NULL,
  `node_uid` varchar(36) NOT NULL,
  `node_type` varchar(100) NOT NULL,
  `node_category` enum('trigger','action','logic') NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `position_x` float DEFAULT 0,
  `position_y` float DEFAULT 0,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_uid` (`workflow_id`,`node_uid`),
  KEY `idx_workflow` (`workflow_id`),
  CONSTRAINT `automation_hub_nodes_ibfk_1` FOREIGN KEY (`workflow_id`) REFERENCES `automation_hub_workflows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=242 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_hub_telegram_bots`
--

DROP TABLE IF EXISTS `automation_hub_telegram_bots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_hub_telegram_bots` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `bot_token` varchar(255) NOT NULL,
  `bot_username` varchar(100) DEFAULT NULL,
  `default_chat_id` varchar(100) DEFAULT NULL,
  `webhook_secret` varchar(64) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_hub_workflows`
--

DROP TABLE IF EXISTS `automation_hub_workflows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_hub_workflows` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 0,
  `category` varchar(50) DEFAULT 'custom',
  `canvas_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`canvas_data`)),
  `run_count` int(10) unsigned DEFAULT 0,
  `last_run_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_email`),
  KEY `idx_active` (`is_active`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `billing_emails`
--

DROP TABLE IF EXISTS `billing_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_emails` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `subscription_id` int(10) unsigned DEFAULT NULL,
  `email_type` enum('reminder_30','reminder_7','overdue','receipt') NOT NULL,
  `sent_to` varchar(255) NOT NULL,
  `sent_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `billing_emails_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `billing_settings`
--

DROP TABLE IF EXISTS `billing_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `board_group_access`
--

DROP TABLE IF EXISTS `board_group_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `board_group_access` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `group_id` int(10) unsigned NOT NULL,
  `can_edit` tinyint(1) DEFAULT 0,
  `can_view_financials` tinyint(1) DEFAULT 0,
  `granted_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_board_group` (`board_id`,`group_id`),
  KEY `idx_group` (`group_id`),
  KEY `idx_board` (`board_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `board_tracked_urls`
--

DROP TABLE IF EXISTS `board_tracked_urls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `board_tracked_urls` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `board_id` int(10) unsigned NOT NULL,
  `client_id` int(10) unsigned NOT NULL,
  `url_domain` varchar(255) NOT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `title_match` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_board_url` (`board_id`,`url_domain`),
  KEY `idx_board` (`board_id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `boardpro_automation_log`
--

DROP TABLE IF EXISTS `boardpro_automation_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `boardpro_automation_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rule_id` int(10) unsigned NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `target_type` varchar(50) NOT NULL,
  `target_id` int(10) unsigned NOT NULL,
  `action_taken` varchar(100) NOT NULL,
  `result_detail` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rule` (`rule_id`),
  KEY `idx_target` (`target_type`,`target_id`),
  KEY `idx_user_date` (`user_email`,`created_at`),
  CONSTRAINT `boardpro_automation_log_ibfk_1` FOREIGN KEY (`rule_id`) REFERENCES `boardpro_automation_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `boardpro_automation_rules`
--

DROP TABLE IF EXISTS `boardpro_automation_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `boardpro_automation_rules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `trigger_type` enum('card_moved_to_list','card_completed','card_overdue','card_idle_days','list_all_completed','email_received_on_card','checklist_completed','label_added','card_created') NOT NULL,
  `trigger_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`trigger_config`)),
  `action_type` enum('move_card','assign_member','add_label','create_invoice_draft','send_notification','send_email','update_deal_stage','start_crm_sequence','create_calendar_event','post_chat_message') NOT NULL,
  `action_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`action_config`)),
  `last_run_at` datetime DEFAULT NULL,
  `run_count` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_board` (`board_id`),
  KEY `idx_user` (`user_email`),
  KEY `idx_active` (`is_active`,`trigger_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `boardpro_board_settings`
--

DROP TABLE IF EXISTS `boardpro_board_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `boardpro_board_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `cached_total_revenue` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cached_total_revenue`)),
  `cached_total_cost` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cached_total_cost`)),
  `cached_health_score` int(11) DEFAULT NULL,
  `last_ai_summary` text DEFAULT NULL,
  `last_ai_summary_at` datetime DEFAULT NULL,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_board` (`board_id`)
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `boardpro_card_emails`
--

DROP TABLE IF EXISTS `boardpro_card_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `boardpro_card_emails` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `board_id` int(11) NOT NULL,
  `email_uid` int(11) NOT NULL,
  `email_folder` varchar(255) NOT NULL,
  `email_subject` varchar(500) DEFAULT NULL,
  `email_from` varchar(255) DEFAULT NULL,
  `email_date` datetime DEFAULT NULL,
  `thread_id` varchar(255) DEFAULT NULL,
  `reply_status` enum('none','replied','awaiting','forwarded') DEFAULT 'none',
  `linked_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_card` (`card_id`),
  KEY `idx_board` (`board_id`),
  KEY `idx_email` (`email_uid`,`email_folder`),
  KEY `idx_thread` (`thread_id`),
  KEY `idx_reply_status` (`reply_status`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `boardpro_card_financials`
--

DROP TABLE IF EXISTS `boardpro_card_financials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `boardpro_card_financials` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `estimated_revenue` decimal(15,2) DEFAULT NULL,
  `estimated_cost` decimal(15,2) DEFAULT NULL,
  `currency` varchar(3) DEFAULT 'HUF',
  `time_budget_hours` decimal(8,2) DEFAULT NULL,
  `invoice_status` enum('none','draft','sent','paid','overdue') DEFAULT 'none',
  `linked_invoice_id` int(10) unsigned DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_card` (`card_id`),
  KEY `idx_invoice` (`linked_invoice_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `boardpro_card_permissions`
--

DROP TABLE IF EXISTS `boardpro_card_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `boardpro_card_permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `visibility` enum('all','members_only','owner_only') DEFAULT 'all',
  `stage_lock_list_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`stage_lock_list_ids`)),
  `portal_visible` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_card` (`card_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `boardpro_email_rules`
--

DROP TABLE IF EXISTS `boardpro_email_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `boardpro_email_rules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `list_id` int(11) DEFAULT NULL,
  `rule_type` enum('subject_contains','sender_domain','sender_email','label_match') NOT NULL,
  `rule_value` varchar(500) NOT NULL,
  `auto_create_card` tinyint(1) DEFAULT 1,
  `auto_assign_to` varchar(255) DEFAULT NULL,
  `card_title_template` varchar(255) DEFAULT '',
  `type_categories` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`type_categories`)),
  `type_default` varchar(50) DEFAULT 'General',
  `body_handling` varchar(20) DEFAULT 'none',
  `checklist_title` varchar(100) DEFAULT '',
  `auto_link_email` tinyint(1) DEFAULT 1,
  `auto_attach_files` tinyint(1) DEFAULT 1,
  `run_count` int(10) unsigned DEFAULT 0,
  `last_run_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_board` (`board_id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `boardpro_member_stage_permissions`
--

DROP TABLE IF EXISTS `boardpro_member_stage_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `boardpro_member_stage_permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `list_id` int(11) NOT NULL,
  `can_view` tinyint(1) DEFAULT 1,
  `can_edit` tinyint(1) DEFAULT 0,
  `can_move_to` tinyint(1) DEFAULT 0,
  `can_move_from` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_member_stage` (`board_id`,`user_email`,`list_id`),
  KEY `idx_board` (`board_id`),
  KEY `idx_user` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `boardpro_moodboard_card_links`
--

DROP TABLE IF EXISTS `boardpro_moodboard_card_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `boardpro_moodboard_card_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `mood_board_id` int(11) NOT NULL,
  `mood_board_item_id` int(11) DEFAULT NULL,
  `linked_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_link` (`card_id`,`mood_board_item_id`),
  KEY `idx_card` (`card_id`),
  KEY `idx_mood` (`mood_board_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `boardpro_rule_processed_emails`
--

DROP TABLE IF EXISTS `boardpro_rule_processed_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `boardpro_rule_processed_emails` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rule_id` int(10) unsigned NOT NULL,
  `email_uid` int(11) NOT NULL,
  `email_folder` varchar(255) NOT NULL,
  `processed_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rule_email` (`rule_id`,`email_uid`,`email_folder`),
  KEY `idx_rule_id` (`rule_id`),
  CONSTRAINT `fk_processed_rule` FOREIGN KEY (`rule_id`) REFERENCES `boardpro_email_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=159 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `calendar_connections`
--

DROP TABLE IF EXISTS `calendar_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_connections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `primary_email` varchar(255) NOT NULL COMMENT 'The main login email (owner)',
  `google_email` varchar(255) NOT NULL COMMENT 'The Google account email',
  `display_name` varchar(255) DEFAULT NULL,
  `access_token_encrypted` text NOT NULL,
  `refresh_token_encrypted` text NOT NULL,
  `token_expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_calendar_connection` (`primary_email`,`google_email`),
  KEY `idx_primary_email` (`primary_email`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `calendar_event_participants`
--

DROP TABLE IF EXISTS `calendar_event_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_event_participants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `status` enum('pending','accepted','declined','tentative') NOT NULL DEFAULT 'pending',
  `invited_by_email` varchar(255) NOT NULL,
  `invited_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `responded_at` timestamp NULL DEFAULT NULL,
  `response_message` text DEFAULT NULL,
  `invite_token` varchar(64) NOT NULL,
  `reminder_sent` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_event_participant` (`event_id`,`user_email`),
  UNIQUE KEY `unique_invite_token` (`invite_token`),
  KEY `idx_event_id` (`event_id`),
  KEY `idx_user_email` (`user_email`),
  KEY `idx_status` (`status`),
  KEY `idx_invite_token` (`invite_token`)
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `calendar_events`
--

DROP TABLE IF EXISTS `calendar_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `calendar_id` int(11) NOT NULL,
  `uid` varchar(255) NOT NULL COMMENT 'Unique identifier for CalDAV',
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `all_day` tinyint(1) DEFAULT 0,
  `timezone` varchar(50) DEFAULT 'UTC',
  `recurrence` text DEFAULT NULL COMMENT 'iCal RRULE format',
  `reminders` text DEFAULT NULL COMMENT 'JSON array of reminders',
  `color` varchar(7) DEFAULT NULL,
  `is_meeting` tinyint(1) NOT NULL DEFAULT 0,
  `meeting_token` varchar(64) DEFAULT NULL,
  `meeting_conversation_id` int(11) DEFAULT NULL,
  `etag` varchar(64) DEFAULT NULL COMMENT 'Version tag for CalDAV',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `client_id` int(10) unsigned DEFAULT NULL,
  `board_id` int(10) unsigned DEFAULT NULL,
  `card_id` int(10) unsigned DEFAULT NULL,
  `time_bridged_at` datetime DEFAULT NULL,
  `linked_message_id` varchar(512) DEFAULT NULL COMMENT 'Email message ID reference',
  `linked_email_subject` varchar(500) DEFAULT NULL COMMENT 'Cached email subject for display',
  `linked_email_sender` varchar(255) DEFAULT NULL COMMENT 'Cached sender email/name',
  `linked_email_folder` varchar(255) DEFAULT NULL COMMENT 'Folder where email lives',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_meeting_token` (`meeting_token`),
  KEY `idx_calendar_id` (`calendar_id`),
  KEY `idx_start_time` (`start_time`),
  KEY `idx_uid` (`uid`),
  KEY `idx_client` (`client_id`),
  KEY `idx_linked_message_id` (`linked_message_id`(191)),
  KEY `idx_meeting_conversation` (`meeting_conversation_id`),
  CONSTRAINT `calendar_events_ibfk_1` FOREIGN KEY (`calendar_id`) REFERENCES `calendars` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=326 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `calendar_group_access`
--

DROP TABLE IF EXISTS `calendar_group_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_group_access` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `calendar_id` int(10) unsigned NOT NULL,
  `group_id` int(10) unsigned NOT NULL,
  `can_edit` tinyint(1) DEFAULT 0,
  `can_see_details` tinyint(1) DEFAULT 1 COMMENT 'See event details vs free/busy only',
  `granted_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_calendar_group` (`calendar_id`,`group_id`),
  KEY `idx_group` (`group_id`),
  CONSTRAINT `calendar_group_access_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `colleague_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `calendar_shares`
--

DROP TABLE IF EXISTS `calendar_shares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_shares` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `calendar_id` int(11) NOT NULL,
  `owner_email` varchar(255) NOT NULL COMMENT 'Calendar owner email',
  `shared_with_email` varchar(255) DEFAULT NULL COMMENT 'Individual user email',
  `shared_with_group_id` int(10) unsigned DEFAULT NULL COMMENT 'Group ID from colleague_groups',
  `permission` enum('view','edit') DEFAULT 'view',
  `can_see_details` tinyint(1) DEFAULT 1 COMMENT 'Can see event details or just busy/free',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_share` (`calendar_id`,`shared_with_email`),
  UNIQUE KEY `unique_group_share` (`calendar_id`,`shared_with_group_id`),
  KEY `idx_shared_email` (`shared_with_email`),
  KEY `idx_shared_group` (`shared_with_group_id`),
  KEY `idx_owner` (`owner_email`),
  CONSTRAINT `calendar_shares_ibfk_1` FOREIGN KEY (`calendar_id`) REFERENCES `calendars` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `calendar_sync_map`
--

DROP TABLE IF EXISTS `calendar_sync_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_sync_map` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `local_event_id` int(11) NOT NULL,
  `google_event_id` varchar(255) NOT NULL,
  `google_calendar_id` varchar(255) NOT NULL,
  `oauth_account_id` int(11) NOT NULL,
  `connection_type` enum('oauth','calendar_only') DEFAULT 'oauth',
  `last_synced` timestamp NULL DEFAULT current_timestamp(),
  `sync_direction` enum('local_to_google','google_to_local','bidirectional') DEFAULT 'bidirectional',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_sync` (`local_event_id`,`google_calendar_id`),
  KEY `idx_local_event` (`local_event_id`),
  KEY `idx_google_event` (`google_event_id`),
  KEY `idx_oauth_account` (`oauth_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=204 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `calendar_sync_state`
--

DROP TABLE IF EXISTS `calendar_sync_state`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_sync_state` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `oauth_account_id` int(11) NOT NULL,
  `connection_type` enum('oauth','calendar_only') DEFAULT 'oauth',
  `google_calendar_id` varchar(255) NOT NULL,
  `local_calendar_id` int(11) NOT NULL,
  `sync_token` varchar(255) DEFAULT NULL,
  `last_full_sync` timestamp NULL DEFAULT NULL,
  `sync_enabled` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_calendar_sync` (`oauth_account_id`,`google_calendar_id`,`connection_type`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `calendars`
--

DROP TABLE IF EXISTS `calendars`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendars` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT 'My Calendar',
  `color` varchar(7) DEFAULT '#3b82f6',
  `timezone` varchar(50) DEFAULT 'UTC',
  `is_default` tinyint(1) DEFAULT 0,
  `ctag` varchar(64) DEFAULT NULL COMMENT 'Sync token for CalDAV',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `subscription_token` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_email` (`user_email`),
  KEY `idx_subscription_token` (`subscription_token`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `call_history`
--

DROP TABLE IF EXISTS `call_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `call_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `call_id` varchar(64) NOT NULL,
  `conversation_id` int(10) unsigned NOT NULL,
  `initiated_by` int(10) unsigned NOT NULL,
  `call_type` enum('voice','video') NOT NULL DEFAULT 'voice',
  `status` enum('completed','missed','rejected','no_answer','cancelled','declined') NOT NULL DEFAULT 'completed',
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `answered_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `duration_seconds` int(10) unsigned DEFAULT 0,
  `participants` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`participants`)),
  `had_screen_share` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_call` (`call_id`),
  KEY `idx_conversation` (`conversation_id`,`started_at` DESC),
  KEY `idx_initiator` (`initiated_by`),
  KEY `idx_status` (`status`),
  KEY `idx_started` (`started_at` DESC),
  CONSTRAINT `call_history_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=247 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `campaign_engagement_fired`
--

DROP TABLE IF EXISTS `campaign_engagement_fired`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `campaign_engagement_fired` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rule_id` int(10) unsigned NOT NULL,
  `campaign_id` varchar(36) NOT NULL,
  `recipient_email` varchar(255) NOT NULL,
  `engagement_percent` decimal(5,1) DEFAULT NULL,
  `fired_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_fire` (`rule_id`,`campaign_id`,`recipient_email`),
  KEY `idx_campaign` (`campaign_id`),
  KEY `idx_rule` (`rule_id`),
  CONSTRAINT `campaign_engagement_fired_ibfk_1` FOREIGN KEY (`rule_id`) REFERENCES `crm_automation_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `card_asset_folders`
--

DROP TABLE IF EXISTS `card_asset_folders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `card_asset_folders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `drive_folder_id` int(11) DEFAULT NULL,
  `position` int(11) DEFAULT 0,
  `created_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_card_id` (`card_id`),
  KEY `idx_parent_id` (`parent_id`),
  CONSTRAINT `card_asset_folders_ibfk_1` FOREIGN KEY (`card_id`) REFERENCES `webmail_board_cards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `card_asset_folders_ibfk_2` FOREIGN KEY (`parent_id`) REFERENCES `card_asset_folders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_attachments`
--

DROP TABLE IF EXISTS `chat_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_attachments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(10) unsigned NOT NULL,
  `message_id` int(10) unsigned NOT NULL,
  `uploader_id` int(10) unsigned NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `file_path` varchar(512) NOT NULL,
  `file_size` int(10) unsigned NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_category` enum('image','video','audio','document','archive','other') NOT NULL DEFAULT 'other',
  `image_width` int(10) unsigned DEFAULT NULL,
  `image_height` int(10) unsigned DEFAULT NULL,
  `thumbnail_path` varchar(512) DEFAULT NULL,
  `drive_file_id` int(10) unsigned DEFAULT NULL,
  `saved_to_drive_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_conversation` (`conversation_id`),
  KEY `idx_message` (`message_id`),
  KEY `idx_uploader` (`uploader_id`),
  KEY `idx_category` (`conversation_id`,`file_category`),
  KEY `idx_created` (`conversation_id`,`created_at` DESC),
  CONSTRAINT `chat_attachments_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_attachments_ibfk_2` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_bookmarks`
--

DROP TABLE IF EXISTS `chat_bookmarks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_bookmarks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `message_id` int(10) unsigned NOT NULL,
  `colleague_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bookmark` (`message_id`,`colleague_id`),
  KEY `idx_colleague` (`colleague_id`,`created_at` DESC),
  CONSTRAINT `chat_bookmarks_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_bookmarks_ibfk_2` FOREIGN KEY (`colleague_id`) REFERENCES `organization_colleagues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_channel_categories`
--

DROP TABLE IF EXISTS `chat_channel_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_channel_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `organization_domain` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `position` int(10) unsigned DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cat_domain` (`organization_domain`),
  KEY `fk_cat_creator` (`created_by`),
  CONSTRAINT `fk_cat_creator` FOREIGN KEY (`created_by`) REFERENCES `organization_colleagues` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_conversations`
--

DROP TABLE IF EXISTS `chat_conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_conversations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `organization_domain` varchar(255) NOT NULL,
  `type` enum('direct','group','channel') NOT NULL DEFAULT 'direct',
  `created_by` int(10) unsigned NOT NULL,
  `last_message_at` timestamp NULL DEFAULT NULL,
  `last_message_preview` varchar(255) DEFAULT NULL,
  `last_message_sender_id` int(10) unsigned DEFAULT NULL,
  `message_count` int(10) unsigned DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `name` varchar(100) DEFAULT NULL COMMENT 'Group name (null for DM)',
  `avatar` varchar(500) DEFAULT NULL COMMENT 'Group avatar URL',
  `description` text DEFAULT NULL COMMENT 'Group description',
  `is_public` tinyint(1) DEFAULT 1,
  `slug` varchar(100) DEFAULT NULL,
  `topic` varchar(500) DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `category_id` int(10) unsigned DEFAULT NULL COMMENT 'FK to chat_channel_categories for channel grouping',
  `position` int(10) unsigned DEFAULT 0 COMMENT 'Sort order within a category',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_channel_slug` (`organization_domain`,`slug`),
  KEY `idx_domain` (`organization_domain`),
  KEY `idx_last_msg` (`organization_domain`,`last_message_at` DESC),
  KEY `idx_type` (`type`),
  KEY `idx_conv_category` (`category_id`),
  CONSTRAINT `fk_conv_category` FOREIGN KEY (`category_id`) REFERENCES `chat_channel_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_dm_lookup`
--

DROP TABLE IF EXISTS `chat_dm_lookup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_dm_lookup` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(10) unsigned NOT NULL,
  `colleague_a_id` int(10) unsigned NOT NULL,
  `colleague_b_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_dm` (`colleague_a_id`,`colleague_b_id`),
  KEY `idx_a` (`colleague_a_id`),
  KEY `idx_b` (`colleague_b_id`),
  KEY `conversation_id` (`conversation_id`),
  CONSTRAINT `chat_dm_lookup_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_group_invitations`
--

DROP TABLE IF EXISTS `chat_group_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_group_invitations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(10) unsigned NOT NULL,
  `invited_email` varchar(255) NOT NULL,
  `invited_by` int(10) unsigned NOT NULL COMMENT 'Colleague ID who sent invite',
  `token` varchar(100) NOT NULL COMMENT 'Unique invitation token',
  `status` enum('pending','accepted','declined','expired') DEFAULT 'pending',
  `message` text DEFAULT NULL COMMENT 'Optional invitation message',
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'When invitation expires',
  `responded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_invite` (`conversation_id`,`invited_email`),
  UNIQUE KEY `unique_token` (`token`),
  KEY `idx_email` (`invited_email`),
  KEY `idx_status` (`status`),
  CONSTRAINT `chat_group_invitations_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_huddle_participants`
--

DROP TABLE IF EXISTS `chat_huddle_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_huddle_participants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `huddle_id` int(10) unsigned NOT NULL,
  `colleague_id` int(10) unsigned NOT NULL,
  `joined_at` datetime NOT NULL DEFAULT current_timestamp(),
  `left_at` datetime DEFAULT NULL,
  `is_muted` tinyint(1) NOT NULL DEFAULT 0,
  `is_deafened` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_huddle_participant` (`huddle_id`,`colleague_id`),
  KEY `colleague_id` (`colleague_id`),
  CONSTRAINT `chat_huddle_participants_ibfk_1` FOREIGN KEY (`huddle_id`) REFERENCES `chat_huddles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_huddle_participants_ibfk_2` FOREIGN KEY (`colleague_id`) REFERENCES `organization_colleagues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_huddles`
--

DROP TABLE IF EXISTS `chat_huddles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_huddles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(10) unsigned NOT NULL,
  `started_by` int(10) unsigned NOT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ended_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `started_by` (`started_by`),
  KEY `idx_huddle_conv_active` (`conversation_id`,`is_active`),
  CONSTRAINT `chat_huddles_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_huddles_ibfk_2` FOREIGN KEY (`started_by`) REFERENCES `organization_colleagues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_invitations`
--

DROP TABLE IF EXISTS `chat_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_invitations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `inviter_email` varchar(255) NOT NULL,
  `invitee_email` varchar(255) NOT NULL,
  `organization_domain` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `status` enum('pending','accepted','declined','expired') DEFAULT 'pending',
  `conversation_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `accepted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_invitee` (`invitee_email`),
  KEY `idx_token` (`token`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_link_previews`
--

DROP TABLE IF EXISTS `chat_link_previews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_link_previews` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(2000) NOT NULL,
  `url_hash` varchar(64) NOT NULL,
  `title` varchar(500) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image_url` varchar(2000) DEFAULT NULL,
  `favicon_url` varchar(2000) DEFAULT NULL,
  `site_name` varchar(255) DEFAULT NULL,
  `fetched_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `url_hash` (`url_hash`),
  KEY `idx_url_hash` (`url_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_mentions`
--

DROP TABLE IF EXISTS `chat_mentions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_mentions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `message_id` int(10) unsigned NOT NULL,
  `conversation_id` int(10) unsigned NOT NULL,
  `mentioned_colleague_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL for @here/@channel',
  `mention_type` enum('user','here','channel') DEFAULT 'user',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mentioned` (`mentioned_colleague_id`,`created_at` DESC),
  KEY `idx_conversation` (`conversation_id`),
  KEY `message_id` (`message_id`),
  CONSTRAINT `chat_mentions_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_message_reactions`
--

DROP TABLE IF EXISTS `chat_message_reactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_message_reactions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `message_id` int(10) unsigned NOT NULL,
  `colleague_id` int(10) unsigned NOT NULL,
  `emoji` varchar(32) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_reaction` (`message_id`,`colleague_id`,`emoji`),
  KEY `idx_message` (`message_id`),
  KEY `idx_colleague` (`colleague_id`),
  CONSTRAINT `chat_message_reactions_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=80 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_messages`
--

DROP TABLE IF EXISTS `chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(10) unsigned NOT NULL,
  `sender_id` int(10) unsigned NOT NULL,
  `content` text NOT NULL,
  `content_type` enum('text','file','image','system','voice','call','embed') DEFAULT 'text',
  `reply_to_id` int(10) unsigned DEFAULT NULL,
  `attachments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attachments`)),
  `voice_duration` decimal(8,2) DEFAULT NULL COMMENT 'Duration in seconds for voice messages',
  `is_edited` tinyint(1) DEFAULT 0,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `pinned_at` timestamp NULL DEFAULT NULL,
  `pinned_by` int(10) unsigned DEFAULT NULL,
  `edited_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_conv_time` (`conversation_id`,`created_at` DESC),
  KEY `idx_conv_id` (`conversation_id`,`id` DESC),
  KEY `idx_sender` (`sender_id`),
  KEY `idx_reply` (`reply_to_id`),
  KEY `idx_deleted` (`deleted_at`),
  KEY `idx_pinned` (`conversation_id`,`is_pinned`,`pinned_at` DESC),
  FULLTEXT KEY `idx_content` (`content`),
  CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_messages_ibfk_2` FOREIGN KEY (`reply_to_id`) REFERENCES `chat_messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=749 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_participants`
--

DROP TABLE IF EXISTS `chat_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_participants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(10) unsigned NOT NULL,
  `colleague_id` int(10) unsigned NOT NULL,
  `last_read_message_id` int(10) unsigned DEFAULT NULL,
  `last_read_at` timestamp NULL DEFAULT NULL,
  `unread_count` int(10) unsigned DEFAULT 0,
  `is_pinned` tinyint(1) DEFAULT 0,
  `is_muted` tinyint(1) DEFAULT 0,
  `is_archived` tinyint(1) DEFAULT 0,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `messages_visible_from` datetime DEFAULT NULL,
  `joined_at` timestamp NULL DEFAULT current_timestamp(),
  `is_admin` tinyint(1) DEFAULT 0 COMMENT 'Can manage group members',
  `added_by` int(10) unsigned DEFAULT NULL COMMENT 'Who added this participant',
  `nickname` varchar(100) DEFAULT NULL COMMENT 'Custom nickname in this group',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_conv_user` (`conversation_id`,`colleague_id`),
  KEY `idx_colleague` (`colleague_id`),
  KEY `idx_unread` (`colleague_id`,`unread_count`),
  KEY `idx_archived` (`colleague_id`,`is_archived`),
  KEY `idx_admin` (`conversation_id`,`is_admin`),
  CONSTRAINT `chat_participants_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=94 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_read_receipts`
--

DROP TABLE IF EXISTS `chat_read_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_read_receipts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `message_id` int(10) unsigned NOT NULL,
  `colleague_id` int(10) unsigned NOT NULL,
  `read_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_receipt` (`message_id`,`colleague_id`),
  KEY `idx_message` (`message_id`),
  KEY `idx_colleague` (`colleague_id`),
  CONSTRAINT `chat_read_receipts_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1000 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_scheduled_messages`
--

DROP TABLE IF EXISTS `chat_scheduled_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_scheduled_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(10) unsigned NOT NULL,
  `colleague_id` int(10) unsigned NOT NULL,
  `content` text NOT NULL,
  `scheduled_at` datetime NOT NULL,
  `status` enum('pending','sent','cancelled') DEFAULT 'pending',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_colleague` (`colleague_id`,`status`),
  KEY `idx_scheduled` (`status`,`scheduled_at`),
  KEY `conversation_id` (`conversation_id`),
  CONSTRAINT `chat_scheduled_messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_scheduled_messages_ibfk_2` FOREIGN KEY (`colleague_id`) REFERENCES `organization_colleagues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_typing_status`
--

DROP TABLE IF EXISTS `chat_typing_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_typing_status` (
  `conversation_id` int(10) unsigned NOT NULL,
  `colleague_id` int(10) unsigned NOT NULL,
  `started_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`conversation_id`,`colleague_id`),
  CONSTRAINT `chat_typing_status_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_webhooks`
--

DROP TABLE IF EXISTS `chat_webhooks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_webhooks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(10) unsigned NOT NULL,
  `creator_id` int(10) unsigned NOT NULL,
  `name` varchar(100) NOT NULL DEFAULT 'Webhook',
  `avatar_url` varchar(500) DEFAULT NULL,
  `token` varchar(64) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `creator_id` (`creator_id`),
  KEY `idx_webhook_token` (`token`),
  KEY `idx_webhook_conversation` (`conversation_id`),
  CONSTRAINT `chat_webhooks_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_webhooks_ibfk_2` FOREIGN KEY (`creator_id`) REFERENCES `organization_colleagues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_boards`
--

DROP TABLE IF EXISTS `client_boards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_boards` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `board_id` int(10) unsigned NOT NULL,
  `linked_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_client_board` (`client_id`,`board_id`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_board_id` (`board_id`)
) ENGINE=InnoDB AUTO_INCREMENT=65 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_contacts`
--

DROP TABLE IF EXISTS `client_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_contacts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `email` varchar(255) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `last_email_at` datetime DEFAULT NULL,
  `email_count` int(10) unsigned DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_client_email` (`client_id`,`email`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=120 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_domain_aliases`
--

DROP TABLE IF EXISTS `client_domain_aliases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_domain_aliases` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `alias_domain` varchar(255) NOT NULL COMMENT 'The domain/email that was merged away',
  `client_id` int(10) unsigned NOT NULL COMMENT 'The primary client this alias points to',
  `merged_from_name` varchar(255) DEFAULT NULL COMMENT 'Original display name before merge',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_alias` (`user_email`,`alias_domain`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_user_email` (`user_email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_members`
--

DROP TABLE IF EXISTS `client_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `role` enum('owner','member') DEFAULT 'member',
  `added_by` varchar(255) NOT NULL,
  `added_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_member` (`client_id`,`user_email`),
  KEY `idx_client` (`client_id`),
  KEY `idx_user` (`user_email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `clients`
--

DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `user_email` varchar(255) NOT NULL,
  `domain` varchar(255) NOT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `billing_name` varchar(255) DEFAULT NULL COMMENT 'Company name for invoices',
  `billing_address` varchar(500) DEFAULT NULL COMMENT 'Billing street address',
  `billing_city` varchar(100) DEFAULT NULL COMMENT 'Billing city',
  `billing_zip` varchar(20) DEFAULT NULL COMMENT 'Billing postal/zip code',
  `billing_country` varchar(100) DEFAULT 'HU' COMMENT 'Billing country code',
  `billing_bank_account` varchar(100) DEFAULT NULL,
  `billing_tax_id` varchar(50) DEFAULT NULL COMMENT 'Tax number / VAT ID',
  `billing_eu_tax_id` varchar(50) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `company` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `payment_terms_days` int(11) DEFAULT 30,
  `hourly_rate` decimal(10,2) DEFAULT NULL COMMENT 'Hourly rate for time tracking calculations',
  `is_associated` tinyint(1) DEFAULT 0,
  `associated_with_client_id` int(10) unsigned DEFAULT NULL,
  `status` enum('active','waiting','attention') DEFAULT 'active',
  `last_activity_at` datetime DEFAULT NULL,
  `last_email_direction` enum('inbound','outbound') DEFAULT NULL,
  `last_outbound_at` datetime DEFAULT NULL,
  `last_inbound_at` datetime DEFAULT NULL,
  `open_task_count` int(10) unsigned DEFAULT 0,
  `overdue_task_count` int(10) unsigned DEFAULT 0,
  `next_deadline` date DEFAULT NULL,
  `drive_folder_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_domain` (`user_email`,`domain`),
  KEY `idx_user_email` (`user_email`),
  KEY `idx_domain` (`domain`),
  KEY `idx_status` (`status`),
  KEY `idx_last_activity` (`last_activity_at`)
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `collab_activity_log`
--

DROP TABLE IF EXISTS `collab_activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `collab_activity_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL COMMENT 'Document ID',
  `user_email` varchar(255) NOT NULL COMMENT 'User who performed action',
  `action` enum('created','viewed','edited','shared','unshared','restored','deleted','commented','resolved_comment') NOT NULL COMMENT 'Action type',
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional action details' CHECK (json_valid(`details`)),
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'Client IP address',
  `user_agent` varchar(500) DEFAULT NULL COMMENT 'Client user agent',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `collab_idx_activity_document` (`document_id`),
  KEY `collab_idx_activity_user` (`user_email`),
  KEY `collab_idx_activity_action` (`action`),
  KEY `collab_idx_activity_created` (`created_at`),
  CONSTRAINT `collab_fk_activity_doc` FOREIGN KEY (`document_id`) REFERENCES `collab_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `collab_comments`
--

DROP TABLE IF EXISTS `collab_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `collab_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL COMMENT 'Document ID',
  `thread_id` char(36) NOT NULL COMMENT 'Groups replies into threads',
  `parent_id` int(11) DEFAULT NULL COMMENT 'For nested replies',
  `user_email` varchar(255) NOT NULL COMMENT 'Comment author',
  `content` text NOT NULL COMMENT 'Comment text (can include @mentions)',
  `selection_anchor` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Y.js relative position for anchoring' CHECK (json_valid(`selection_anchor`)),
  `resolved_at` timestamp NULL DEFAULT NULL COMMENT 'When thread was resolved',
  `resolved_by` varchar(255) DEFAULT NULL COMMENT 'Who resolved the thread',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
  PRIMARY KEY (`id`),
  KEY `collab_idx_comment_document` (`document_id`),
  KEY `collab_idx_comment_thread` (`thread_id`),
  KEY `collab_idx_comment_parent` (`parent_id`),
  KEY `collab_idx_comment_resolved` (`resolved_at`),
  FULLTEXT KEY `collab_ft_comments` (`content`),
  CONSTRAINT `collab_fk_comment_doc` FOREIGN KEY (`document_id`) REFERENCES `collab_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `collab_fk_comment_parent` FOREIGN KEY (`parent_id`) REFERENCES `collab_comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `collab_documents`
--

DROP TABLE IF EXISTS `collab_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `collab_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL COMMENT 'Public document identifier',
  `owner_email` varchar(255) NOT NULL COMMENT 'Email of document creator/owner',
  `title` varchar(500) NOT NULL DEFAULT 'Untitled Document' COMMENT 'Document title',
  `type` enum('document','presentation') NOT NULL COMMENT 'Document type',
  `crdt_state` longblob DEFAULT NULL COMMENT 'Y.js encoded CRDT state (binary)',
  `folder_id` int(10) unsigned DEFAULT NULL,
  `snapshot_html` longtext DEFAULT NULL COMMENT 'Last rendered HTML snapshot for preview/search',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
  `drive_file_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `collab_idx_owner` (`owner_email`),
  KEY `collab_idx_type` (`type`),
  KEY `collab_idx_uuid` (`uuid`),
  KEY `collab_idx_deleted` (`deleted_at`),
  KEY `collab_idx_updated` (`updated_at`),
  KEY `idx_drive_file_id` (`drive_file_id`),
  KEY `idx_folder` (`folder_id`),
  KEY `idx_collab_folder_id` (`folder_id`),
  FULLTEXT KEY `collab_ft_search` (`title`,`snapshot_html`)
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `collab_permissions`
--

DROP TABLE IF EXISTS `collab_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `collab_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL COMMENT 'Document being shared',
  `user_email` varchar(255) NOT NULL COMMENT 'User granted access',
  `role` enum('owner','editor','viewer') NOT NULL DEFAULT 'viewer' COMMENT 'Permission level',
  `invited_by` varchar(255) DEFAULT NULL COMMENT 'Email of user who invited',
  `accepted_at` timestamp NULL DEFAULT NULL COMMENT 'When invitation was accepted',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `collab_uk_doc_user` (`document_id`,`user_email`),
  KEY `collab_idx_perm_user` (`user_email`),
  KEY `collab_idx_perm_role` (`role`),
  CONSTRAINT `collab_fk_perm_doc` FOREIGN KEY (`document_id`) REFERENCES `collab_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=91 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `collab_sessions`
--

DROP TABLE IF EXISTS `collab_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `collab_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL COMMENT 'Document being edited',
  `user_email` varchar(255) NOT NULL COMMENT 'User email',
  `connection_id` varchar(100) NOT NULL COMMENT 'WebSocket connection identifier',
  `cursor_position` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Current cursor position/selection' CHECK (json_valid(`cursor_position`)),
  `color` varchar(7) NOT NULL COMMENT 'Hex color for cursor/avatar',
  `user_name` varchar(255) DEFAULT NULL COMMENT 'Display name for presence',
  `connected_at` timestamp NULL DEFAULT current_timestamp(),
  `last_seen` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `collab_uk_sess_conn` (`connection_id`),
  KEY `collab_idx_sess_document` (`document_id`),
  KEY `collab_idx_sess_user` (`user_email`),
  KEY `collab_idx_sess_last_seen` (`last_seen`),
  CONSTRAINT `collab_fk_sess_doc` FOREIGN KEY (`document_id`) REFERENCES `collab_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `collab_slides`
--

DROP TABLE IF EXISTS `collab_slides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `collab_slides` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL COMMENT 'Parent presentation ID',
  `slide_index` int(11) NOT NULL DEFAULT 0 COMMENT 'Order within presentation (0-based)',
  `crdt_state` longblob DEFAULT NULL COMMENT 'Y.js state for this slide (optional, for sharding)',
  `thumbnail_url` varchar(500) DEFAULT NULL COMMENT 'URL to slide thumbnail image',
  `thumbnail_generated_at` timestamp NULL DEFAULT NULL COMMENT 'When thumbnail was last generated',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `collab_uk_doc_index` (`document_id`,`slide_index`),
  KEY `collab_idx_slides_document` (`document_id`),
  CONSTRAINT `collab_fk_slide_doc` FOREIGN KEY (`document_id`) REFERENCES `collab_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `collab_versions`
--

DROP TABLE IF EXISTS `collab_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `collab_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL COMMENT 'Document ID',
  `version_number` int(11) NOT NULL COMMENT 'Sequential version number',
  `version_name` varchar(255) DEFAULT NULL COMMENT 'Optional user-given name',
  `crdt_state` longblob NOT NULL COMMENT 'Y.js encoded state at this version',
  `snapshot_html` longtext DEFAULT NULL COMMENT 'HTML snapshot for preview',
  `created_by` varchar(255) NOT NULL COMMENT 'Email of user who created version',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `collab_uk_doc_version` (`document_id`,`version_number`),
  KEY `collab_idx_ver_created` (`created_at`),
  CONSTRAINT `collab_fk_ver_doc` FOREIGN KEY (`document_id`) REFERENCES `collab_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `colleague_group_members`
--

DROP TABLE IF EXISTS `colleague_group_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `colleague_group_members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int(10) unsigned NOT NULL,
  `colleague_id` int(10) unsigned NOT NULL,
  `added_by` varchar(255) NOT NULL,
  `added_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_group_colleague` (`group_id`,`colleague_id`),
  KEY `idx_colleague` (`colleague_id`),
  CONSTRAINT `colleague_group_members_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `colleague_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `colleague_group_members_ibfk_2` FOREIGN KEY (`colleague_id`) REFERENCES `organization_colleagues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `colleague_groups`
--

DROP TABLE IF EXISTS `colleague_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `colleague_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `organization_domain` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(20) DEFAULT '#6366f1' COMMENT 'Badge color',
  `icon` varchar(50) DEFAULT 'group' COMMENT 'Material Symbol icon',
  `can_see_all_boards` tinyint(1) NOT NULL DEFAULT 0,
  `can_see_all_tasks` tinyint(1) NOT NULL DEFAULT 0,
  `can_manage_members` tinyint(1) NOT NULL DEFAULT 0,
  `can_view_financials` tinyint(1) NOT NULL DEFAULT 0,
  `admin_equivalent` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `created_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_domain_name` (`organization_domain`,`name`),
  KEY `idx_domain` (`organization_domain`),
  KEY `idx_sort` (`organization_domain`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `colleague_profile_events`
--

DROP TABLE IF EXISTS `colleague_profile_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `colleague_profile_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `colleague_id` int(10) unsigned NOT NULL,
  `organization_domain` varchar(255) NOT NULL,
  `event_type` enum('profile_updated','avatar_changed','status_changed','group_added','group_removed') NOT NULL,
  `event_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`event_data`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_colleague` (`colleague_id`),
  KEY `idx_domain_time` (`organization_domain`,`created_at`),
  CONSTRAINT `colleague_profile_events_ibfk_1` FOREIGN KEY (`colleague_id`) REFERENCES `organization_colleagues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_automation_log`
--

DROP TABLE IF EXISTS `crm_automation_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_automation_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rule_id` int(10) unsigned NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `target_type` varchar(50) NOT NULL,
  `target_id` varchar(255) NOT NULL,
  `action_taken` varchar(100) NOT NULL,
  `result_detail` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rule` (`rule_id`),
  KEY `idx_target` (`target_type`,`target_id`),
  KEY `idx_user_date` (`user_email`,`created_at`),
  CONSTRAINT `crm_automation_log_ibfk_1` FOREIGN KEY (`rule_id`) REFERENCES `crm_automation_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_automation_rule_group_shares`
--

DROP TABLE IF EXISTS `crm_automation_rule_group_shares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_automation_rule_group_shares` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rule_id` int(10) unsigned NOT NULL,
  `group_id` int(10) unsigned NOT NULL,
  `permission` enum('viewer','editor') DEFAULT 'viewer',
  `shared_by_email` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_rule_group_share` (`rule_id`,`group_id`),
  KEY `idx_group` (`group_id`),
  KEY `idx_rule` (`rule_id`),
  CONSTRAINT `crm_automation_rule_group_shares_ibfk_1` FOREIGN KEY (`rule_id`) REFERENCES `crm_automation_rules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `crm_automation_rule_group_shares_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `colleague_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_automation_rule_shares`
--

DROP TABLE IF EXISTS `crm_automation_rule_shares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_automation_rule_shares` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rule_id` int(10) unsigned NOT NULL,
  `shared_with_email` varchar(255) NOT NULL,
  `permission` enum('viewer','editor') DEFAULT 'viewer',
  `shared_by_email` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_rule_share` (`rule_id`,`shared_with_email`),
  KEY `idx_shared_with` (`shared_with_email`),
  KEY `idx_rule` (`rule_id`),
  CONSTRAINT `crm_automation_rule_shares_ibfk_1` FOREIGN KEY (`rule_id`) REFERENCES `crm_automation_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_automation_rules`
--

DROP TABLE IF EXISTS `crm_automation_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_automation_rules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `visibility` enum('private','shared') DEFAULT 'private',
  `trigger_type` enum('deal_stage_idle','deal_stage_changed','client_health_low','invoice_overdue','no_contact_days','deal_won','deal_lost','task_changed','board_closed','moodboard_ready','time_spent_reached','colleague_sick_status','drive_folder_permission_changed','email_opened','email_link_clicked','campaign_engagement_threshold') NOT NULL,
  `trigger_config` longtext NOT NULL COMMENT '{"stage":"proposal","days":7}' CHECK (json_valid(`trigger_config`)),
  `action_type` enum('create_reminder','send_email','create_invoice_draft','move_deal_stage','notify_user','start_sequence','assign_task','send_chat_message','reassign_deals') NOT NULL,
  `action_config` longtext NOT NULL COMMENT '{"template_id":5,"delay_hours":0}' CHECK (json_valid(`action_config`)),
  `last_run_at` datetime DEFAULT NULL,
  `run_count` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_email`),
  KEY `idx_active` (`is_active`,`trigger_type`),
  KEY `idx_visibility` (`visibility`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_billing_settings`
--

DROP TABLE IF EXISTS `crm_billing_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_billing_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `provider` enum('billingo','szamlazz','none') DEFAULT 'none',
  `api_key` varchar(500) DEFAULT NULL COMMENT 'Encrypted API key for the billing platform',
  `billingo_block_id` int(11) DEFAULT NULL COMMENT 'Billingo invoice block ID',
  `szamlazz_agent_key` varchar(255) DEFAULT NULL COMMENT 'Szamlazz.hu agent key',
  `company_name` varchar(255) DEFAULT NULL,
  `company_address` text DEFAULT NULL,
  `company_tax_number` varchar(50) DEFAULT NULL,
  `company_eu_tax_number` varchar(50) DEFAULT NULL COMMENT 'EU VAT number',
  `company_bank_account` varchar(100) DEFAULT NULL,
  `company_bank_name` varchar(100) DEFAULT NULL,
  `company_email` varchar(255) DEFAULT NULL,
  `company_phone` varchar(50) DEFAULT NULL,
  `company_logo_drive_file_id` int(11) DEFAULT NULL,
  `default_currency` varchar(3) DEFAULT 'HUF',
  `default_tax_rate` decimal(5,2) DEFAULT 27.00 COMMENT 'Hungarian VAT default',
  `default_payment_terms_days` int(11) DEFAULT 8,
  `default_payment_method` enum('bank_transfer','cash','card','paypal','other') DEFAULT 'bank_transfer',
  `default_language` enum('hu','en','de') DEFAULT 'hu',
  `auto_save_to_drive` tinyint(1) DEFAULT 1 COMMENT 'Auto-save generated invoices to Drive',
  `drive_invoices_folder_id` int(11) DEFAULT NULL COMMENT 'Drive folder ID for Invoices system folder',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user` (`user_email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_call_log`
--

DROP TABLE IF EXISTS `crm_call_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_call_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `contact_id` int(10) unsigned DEFAULT NULL,
  `direction` enum('inbound','outbound') NOT NULL,
  `duration_minutes` int(11) DEFAULT NULL,
  `outcome` enum('connected','no_answer','voicemail','busy','callback_requested') DEFAULT 'connected',
  `notes` text DEFAULT NULL,
  `follow_up_reminder_id` int(10) unsigned DEFAULT NULL COMMENT 'Auto-created reminder',
  `call_date` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_user` (`user_email`),
  KEY `idx_date` (`call_date`),
  KEY `idx_contact` (`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_custom_field_definitions`
--

DROP TABLE IF EXISTS `crm_custom_field_definitions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_custom_field_definitions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `field_name` varchar(100) NOT NULL,
  `field_type` enum('text','number','date','select','url','email','phone') NOT NULL,
  `field_options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'For select type: ["Option A", "Option B"]' CHECK (json_valid(`field_options`)),
  `is_required` tinyint(1) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_field` (`user_email`,`field_name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_custom_field_values`
--

DROP TABLE IF EXISTS `crm_custom_field_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_custom_field_values` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `field_id` int(10) unsigned NOT NULL,
  `field_value` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_value` (`client_id`,`field_id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_field` (`field_id`),
  CONSTRAINT `crm_custom_field_values_ibfk_1` FOREIGN KEY (`field_id`) REFERENCES `crm_custom_field_definitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_deal_stage_history`
--

DROP TABLE IF EXISTS `crm_deal_stage_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_deal_stage_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` int(10) unsigned NOT NULL,
  `from_stage` varchar(50) DEFAULT NULL,
  `to_stage` varchar(50) NOT NULL,
  `changed_by` varchar(255) NOT NULL,
  `changed_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_deal` (`deal_id`),
  KEY `idx_stage` (`to_stage`),
  KEY `idx_changed_at` (`changed_at`),
  CONSTRAINT `crm_deal_stage_history_ibfk_1` FOREIGN KEY (`deal_id`) REFERENCES `crm_deals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_deals`
--

DROP TABLE IF EXISTS `crm_deals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_deals` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `pipeline_stage` enum('lead','contacted','proposal','negotiation','won','lost') DEFAULT 'lead',
  `expected_value` decimal(15,2) DEFAULT NULL,
  `currency` varchar(3) DEFAULT 'HUF',
  `probability` int(11) DEFAULT 50 COMMENT '0-100 percent',
  `expected_close_date` date DEFAULT NULL,
  `actual_close_date` date DEFAULT NULL,
  `lost_reason` text DEFAULT NULL,
  `contact_id` int(10) unsigned DEFAULT NULL COMMENT 'Primary contact for this deal',
  `assigned_to` varchar(255) DEFAULT NULL COMMENT 'Team member email',
  `board_id` int(11) DEFAULT NULL COMMENT 'Linked board',
  `invoice_id` int(10) unsigned DEFAULT NULL COMMENT 'Linked invoice when won',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_stage` (`pipeline_stage`),
  KEY `idx_assigned` (`assigned_to`),
  KEY `idx_close_date` (`expected_close_date`),
  KEY `idx_user` (`user_email`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_expenses`
--

DROP TABLE IF EXISTS `crm_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_expenses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `description` varchar(500) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'HUF',
  `expense_date` date NOT NULL,
  `category` varchar(100) DEFAULT NULL COMMENT 'software, hosting, travel, etc.',
  `receipt_drive_file_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_date` (`expense_date`),
  KEY `idx_user` (`user_email`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_group_access`
--

DROP TABLE IF EXISTS `crm_group_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_group_access` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `owner_email` varchar(255) NOT NULL,
  `group_id` int(10) unsigned NOT NULL,
  `permission` enum('viewer','editor','manager') DEFAULT 'viewer',
  `granted_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_group_share` (`owner_email`,`group_id`),
  KEY `idx_owner` (`owner_email`),
  KEY `idx_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_invoice_items`
--

DROP TABLE IF EXISTS `crm_invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_invoice_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int(10) unsigned NOT NULL,
  `description` varchar(500) NOT NULL,
  `quantity` decimal(10,2) DEFAULT 1.00,
  `unit` varchar(50) DEFAULT NULL COMMENT 'hours, pieces, months, etc.',
  `unit_price` decimal(15,2) NOT NULL,
  `tax_rate` decimal(5,2) DEFAULT NULL COMMENT 'Per-item override',
  `total` decimal(15,2) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `board_card_id` int(11) DEFAULT NULL COMMENT 'Linked to board card/milestone',
  PRIMARY KEY (`id`),
  KEY `idx_invoice` (`invoice_id`),
  CONSTRAINT `crm_invoice_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `crm_invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_invoice_payments`
--

DROP TABLE IF EXISTS `crm_invoice_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_invoice_payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int(10) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` varchar(255) NOT NULL COMMENT 'User who recorded the payment',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_invoice` (`invoice_id`),
  KEY `idx_date` (`payment_date`),
  CONSTRAINT `crm_invoice_payments_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `crm_invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_invoices`
--

DROP TABLE IF EXISTS `crm_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_invoices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `user_email` varchar(255) NOT NULL COMMENT 'Owner of this invoice',
  `invoice_number` varchar(50) NOT NULL COMMENT 'Auto: INV-2026-001',
  `status` enum('draft','sent','viewed','partial','paid','overdue','cancelled','refunded') DEFAULT 'draft',
  `issue_date` date NOT NULL,
  `due_date` date NOT NULL,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(5,2) DEFAULT 0.00 COMMENT 'Percentage',
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(3) DEFAULT 'HUF',
  `paid_amount` decimal(15,2) DEFAULT 0.00,
  `paid_at` datetime DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL COMMENT 'bank_transfer, cash, card, paypal',
  `payment_reference` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL COMMENT 'Shown on invoice',
  `internal_notes` text DEFAULT NULL COMMENT 'Not shown to client',
  `is_recurring` tinyint(1) DEFAULT 0,
  `recurrence_interval` enum('weekly','monthly','quarterly','yearly') DEFAULT NULL,
  `recurrence_end_date` date DEFAULT NULL,
  `parent_invoice_id` int(10) unsigned DEFAULT NULL COMMENT 'For recurring chain',
  `portal_document_id` int(10) unsigned DEFAULT NULL COMMENT 'Link to portal doc for delivery',
  `drive_file_id` int(11) DEFAULT NULL COMMENT 'Generated PDF stored in Drive',
  `board_card_id` int(11) DEFAULT NULL COMMENT 'Linked board milestone',
  `billing_provider` enum('billingo','szamlazz','manual') DEFAULT 'manual',
  `external_invoice_id` varchar(100) DEFAULT NULL COMMENT 'ID on external billing platform',
  `external_invoice_url` varchar(500) DEFAULT NULL COMMENT 'Link to view on billing platform',
  `external_pdf_url` varchar(500) DEFAULT NULL COMMENT 'Direct link to PDF on platform',
  `sent_at` datetime DEFAULT NULL,
  `viewed_at` datetime DEFAULT NULL,
  `reminder_count` int(11) DEFAULT 0,
  `last_reminder_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_invoice_number` (`user_email`,`invoice_number`),
  KEY `idx_client` (`client_id`),
  KEY `idx_status` (`status`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_recurring` (`is_recurring`,`recurrence_interval`),
  KEY `idx_user` (`user_email`),
  KEY `idx_external` (`billing_provider`,`external_invoice_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_meeting_notes`
--

DROP TABLE IF EXISTS `crm_meeting_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_meeting_notes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `title` varchar(500) NOT NULL,
  `content` text DEFAULT NULL,
  `meeting_date` datetime NOT NULL,
  `attendees` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '["email1", "email2"]' CHECK (json_valid(`attendees`)),
  `action_items` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '[{"text": "...", "assignee": "...", "done": false}]' CHECK (json_valid(`action_items`)),
  `calendar_event_id` int(11) DEFAULT NULL COMMENT 'Linked calendar event',
  `portal_call_id` int(10) unsigned DEFAULT NULL COMMENT 'Linked portal call',
  `deal_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_date` (`meeting_date`),
  KEY `idx_user` (`user_email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_reminders`
--

DROP TABLE IF EXISTS `crm_reminders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_reminders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `remind_at` datetime NOT NULL,
  `is_completed` tinyint(1) DEFAULT 0,
  `completed_at` datetime DEFAULT NULL,
  `is_recurring` tinyint(1) DEFAULT 0,
  `recurrence_interval` enum('daily','weekly','biweekly','monthly') DEFAULT NULL,
  `contact_id` int(10) unsigned DEFAULT NULL COMMENT 'Specific contact to follow up with',
  `deal_id` int(10) unsigned DEFAULT NULL COMMENT 'Linked deal',
  `notification_sent` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_remind` (`user_email`,`remind_at`),
  KEY `idx_client` (`client_id`),
  KEY `idx_pending` (`is_completed`,`remind_at`),
  KEY `idx_deal` (`deal_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_sequence_enrollments`
--

DROP TABLE IF EXISTS `crm_sequence_enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_sequence_enrollments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sequence_id` int(10) unsigned NOT NULL,
  `deal_id` int(10) unsigned DEFAULT NULL,
  `client_id` int(10) unsigned NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `current_step` int(11) DEFAULT 0,
  `status` enum('active','completed','cancelled','paused') DEFAULT 'active',
  `next_run_at` datetime DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sequence` (`sequence_id`),
  KEY `idx_status_next` (`status`,`next_run_at`),
  KEY `idx_deal` (`deal_id`),
  KEY `idx_user` (`user_email`),
  CONSTRAINT `crm_sequence_enrollments_ibfk_1` FOREIGN KEY (`sequence_id`) REFERENCES `crm_sequences` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_sequences`
--

DROP TABLE IF EXISTS `crm_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_sequences` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `trigger_stage` varchar(50) DEFAULT NULL COMMENT 'Auto-start when deal enters this stage',
  `is_active` tinyint(1) DEFAULT 1,
  `steps` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT '[{"delay_days":0,"template_id":1,"subject":"..."},{"delay_days":3,"template_id":2}]' CHECK (json_valid(`steps`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_email`),
  KEY `idx_trigger_stage` (`trigger_stage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_share_activity`
--

DROP TABLE IF EXISTS `crm_share_activity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_share_activity` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `owner_email` varchar(255) NOT NULL,
  `colleague_email` varchar(255) NOT NULL,
  `action` varchar(50) NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` int(10) unsigned DEFAULT NULL,
  `detail` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`owner_email`,`created_at`),
  KEY `idx_colleague` (`colleague_email`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_shares`
--

DROP TABLE IF EXISTS `crm_shares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_shares` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `owner_email` varchar(255) NOT NULL,
  `shared_with_email` varchar(255) NOT NULL,
  `permission` enum('viewer','editor','manager') DEFAULT 'viewer',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_share` (`owner_email`,`shared_with_email`),
  KEY `idx_owner` (`owner_email`),
  KEY `idx_shared_with` (`shared_with_email`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_tag_assignments`
--

DROP TABLE IF EXISTS `crm_tag_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_tag_assignments` (
  `client_id` int(10) unsigned NOT NULL,
  `tag_id` int(10) unsigned NOT NULL,
  `assigned_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`client_id`,`tag_id`),
  KEY `idx_tag` (`tag_id`),
  KEY `idx_client` (`client_id`),
  CONSTRAINT `crm_tag_assignments_ibfk_1` FOREIGN KEY (`tag_id`) REFERENCES `crm_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_tags`
--

DROP TABLE IF EXISTS `crm_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_tags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL COMMENT 'Tag belongs to this user workspace',
  `name` varchar(100) NOT NULL,
  `color` varchar(7) DEFAULT '#6366f1' COMMENT 'Hex color',
  `tag_group` varchar(100) DEFAULT NULL COMMENT 'industry, priority, source, etc.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_tag` (`user_email`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `database_links`
--

DROP TABLE IF EXISTS `database_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `database_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `db_name` varchar(64) NOT NULL,
  `db_user` varchar(64) DEFAULT NULL,
  `domain` varchar(255) NOT NULL,
  `db_host` varchar(255) NOT NULL DEFAULT 'localhost',
  `created_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_db_domain` (`db_name`,`domain`),
  KEY `idx_domain` (`domain`),
  KEY `idx_db_name` (`db_name`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dependency_scans`
--

DROP TABLE IF EXISTS `dependency_scans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dependency_scans` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `source_app` varchar(50) NOT NULL,
  `scan_type` varchar(20) NOT NULL COMMENT 'composer or npm',
  `vulnerabilities_found` int(11) NOT NULL DEFAULT 0,
  `critical_count` int(11) NOT NULL DEFAULT 0,
  `high_count` int(11) NOT NULL DEFAULT 0,
  `medium_count` int(11) NOT NULL DEFAULT 0,
  `low_count` int(11) NOT NULL DEFAULT 0,
  `results` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`results`)),
  `scanned_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_source` (`source_app`),
  KEY `idx_scanned` (`scanned_at`),
  KEY `idx_type` (`scan_type`)
) ENGINE=InnoDB AUTO_INCREMENT=293 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `deployment_steps`
--

DROP TABLE IF EXISTS `deployment_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `deployment_steps` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `deployment_id` int(10) unsigned NOT NULL,
  `step_key` varchar(50) NOT NULL,
  `step_name` varchar(100) NOT NULL,
  `step_order` int(10) unsigned NOT NULL,
  `weight` int(10) unsigned NOT NULL DEFAULT 1,
  `status` enum('pending','running','success','failed','skipped','warning') DEFAULT 'pending',
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `duration_ms` int(10) unsigned DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `error_type` enum('script_bug','server_issue','timeout','dependency','race_condition','ssh_error','unknown') DEFAULT NULL,
  `retry_count` int(10) unsigned DEFAULT 0,
  `max_retries` int(10) unsigned DEFAULT 2,
  `command_log` longtext DEFAULT NULL,
  `can_skip` tinyint(1) DEFAULT 0,
  `idempotent` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_deployment_step` (`deployment_id`,`step_key`),
  KEY `idx_deployment` (`deployment_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dns_domainmetadata`
--

DROP TABLE IF EXISTS `dns_domainmetadata`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dns_domainmetadata` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain_id` int(11) NOT NULL,
  `kind` varchar(32) NOT NULL,
  `content` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_domain_id` (`domain_id`),
  CONSTRAINT `dns_domainmetadata_ibfk_1` FOREIGN KEY (`domain_id`) REFERENCES `dns_domains` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dns_domains`
--

DROP TABLE IF EXISTS `dns_domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dns_domains` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `master` varchar(128) DEFAULT NULL,
  `last_check` int(11) DEFAULT NULL,
  `type` varchar(6) NOT NULL DEFAULT 'NATIVE',
  `notified_serial` int(11) DEFAULT NULL,
  `account` varchar(40) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dns_migration_status`
--

DROP TABLE IF EXISTS `dns_migration_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dns_migration_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `migration_phase` enum('not_started','syncing','dual_write','switched','completed') NOT NULL DEFAULT 'not_started',
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `zones_synced` int(11) DEFAULT 0,
  `records_synced` int(11) DEFAULT 0,
  `pdns_config_updated` tinyint(1) DEFAULT 0,
  `rollback_available` tinyint(1) DEFAULT 1,
  `notes` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dns_records`
--

DROP TABLE IF EXISTS `dns_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dns_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` varchar(10) NOT NULL,
  `content` text NOT NULL,
  `ttl` int(11) DEFAULT 3600,
  `prio` int(11) DEFAULT 0,
  `disabled` tinyint(1) DEFAULT 0,
  `ordername` varchar(255) DEFAULT NULL,
  `auth` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_domain_id` (`domain_id`),
  KEY `idx_name_type` (`name`,`type`),
  KEY `idx_ordername` (`ordername`),
  CONSTRAINT `dns_records_ibfk_1` FOREIGN KEY (`domain_id`) REFERENCES `dns_domains` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1026 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary table structure for view `domainmetadata`
--

DROP TABLE IF EXISTS `domainmetadata`;
/*!50001 DROP VIEW IF EXISTS `domainmetadata`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `domainmetadata` AS SELECT
 1 AS `id`,
  1 AS `domain_id`,
  1 AS `kind`,
  1 AS `content` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `domains`
--

DROP TABLE IF EXISTS `domains`;
/*!50001 DROP VIEW IF EXISTS `domains`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `domains` AS SELECT
 1 AS `id`,
  1 AS `name`,
  1 AS `master`,
  1 AS `last_check`,
  1 AS `type`,
  1 AS `notified_serial`,
  1 AS `account` */;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `drive_editing_status`
--

DROP TABLE IF EXISTS `drive_editing_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `drive_editing_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file_id` int(11) DEFAULT NULL,
  `filename` varchar(255) NOT NULL COMMENT 'Original filename being edited',
  `folder_id` int(11) DEFAULT NULL,
  `user_email` varchar(255) NOT NULL,
  `started_at` timestamp NULL DEFAULT current_timestamp(),
  `last_heartbeat` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_file_id` (`file_id`),
  KEY `idx_folder_id` (`folder_id`),
  KEY `idx_user_email` (`user_email`),
  KEY `idx_last_heartbeat` (`last_heartbeat`)
) ENGINE=InnoDB AUTO_INCREMENT=201 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `drive_file_versions`
--

DROP TABLE IF EXISTS `drive_file_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `drive_file_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file_id` int(11) NOT NULL,
  `version_number` int(11) NOT NULL DEFAULT 1,
  `filename` varchar(255) NOT NULL COMMENT 'Stored filename (hashed)',
  `size` bigint(20) NOT NULL DEFAULT 0,
  `modified_by` varchar(255) NOT NULL COMMENT 'Email of who uploaded this version',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_file_id` (`file_id`),
  KEY `idx_version_number` (`file_id`,`version_number`),
  CONSTRAINT `drive_file_versions_ibfk_1` FOREIGN KEY (`file_id`) REFERENCES `drive_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=77 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `drive_files`
--

DROP TABLE IF EXISTS `drive_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `drive_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `folder_id` int(11) DEFAULT NULL,
  `filename` varchar(255) NOT NULL COMMENT 'Stored filename (hashed)',
  `original_name` varchar(255) NOT NULL,
  `size` bigint(20) NOT NULL DEFAULT 0,
  `mime_type` varchar(100) DEFAULT 'application/octet-stream',
  `share_token` varchar(64) DEFAULT NULL,
  `share_expires` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_email_attachment` tinyint(1) DEFAULT 0,
  `created_by` varchar(255) DEFAULT NULL COMMENT 'Email of original uploader',
  `last_modified_by` varchar(255) DEFAULT NULL,
  `current_version` int(11) DEFAULT 1,
  `last_opened_at` timestamp NULL DEFAULT NULL,
  `last_opened_by` varchar(255) DEFAULT NULL,
  `is_trashed` tinyint(1) DEFAULT 0,
  `trashed_at` timestamp NULL DEFAULT NULL,
  `original_folder_id` int(11) DEFAULT NULL COMMENT 'Folder before trashing',
  `max_downloads` int(11) DEFAULT NULL,
  `download_count` int(11) DEFAULT 0,
  `share_password` varchar(255) DEFAULT NULL,
  `checksum` varchar(64) DEFAULT NULL COMMENT 'MD5 checksum for sync',
  `storage_location` varchar(20) DEFAULT 'local',
  `nas_relative_path` varchar(1000) DEFAULT NULL COMMENT 'Path relative to NAS mount for direct access',
  PRIMARY KEY (`id`),
  KEY `idx_user_email` (`user_email`),
  KEY `idx_folder_id` (`folder_id`),
  KEY `idx_share_token` (`share_token`),
  KEY `idx_is_trashed` (`is_trashed`),
  KEY `idx_trashed_at` (`trashed_at`),
  KEY `idx_checksum` (`checksum`),
  CONSTRAINT `drive_files_ibfk_1` FOREIGN KEY (`folder_id`) REFERENCES `drive_folders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2170 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `drive_folder_collaborators`
--

DROP TABLE IF EXISTS `drive_folder_collaborators`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `drive_folder_collaborators` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `folder_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `permission` enum('viewer','editor') DEFAULT 'viewer',
  `invited_by` varchar(255) NOT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_folder_user` (`folder_id`,`user_email`),
  KEY `idx_user_email` (`user_email`),
  CONSTRAINT `drive_folder_collaborators_ibfk_1` FOREIGN KEY (`folder_id`) REFERENCES `drive_folders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `drive_folder_group_access`
--

DROP TABLE IF EXISTS `drive_folder_group_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `drive_folder_group_access` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `folder_id` int(11) NOT NULL,
  `group_id` int(10) unsigned NOT NULL,
  `permission` enum('viewer','editor') DEFAULT 'viewer',
  `granted_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_folder_group` (`folder_id`,`group_id`),
  KEY `idx_group` (`group_id`),
  KEY `idx_folder` (`folder_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `drive_folders`
--

DROP TABLE IF EXISTS `drive_folders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `drive_folders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `share_token` varchar(64) DEFAULT NULL,
  `share_expires` datetime DEFAULT NULL,
  `color` varchar(20) DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `is_trashed` tinyint(1) DEFAULT 0,
  `trashed_at` timestamp NULL DEFAULT NULL,
  `original_parent_id` int(11) DEFAULT NULL COMMENT 'Parent before trashing',
  `max_downloads` int(11) DEFAULT NULL,
  `download_count` int(11) DEFAULT 0,
  `share_password` varchar(255) DEFAULT NULL,
  `board_id` int(10) unsigned DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `size` bigint(20) DEFAULT 0,
  `nas_relative_path` varchar(1000) DEFAULT NULL COMMENT 'Path relative to NAS mount for direct access',
  PRIMARY KEY (`id`),
  KEY `idx_user_email` (`user_email`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_folder_is_trashed` (`is_trashed`),
  KEY `idx_folder_trashed_at` (`trashed_at`),
  KEY `idx_board_id` (`board_id`),
  KEY `idx_client_id` (`client_id`),
  CONSTRAINT `drive_folders_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `drive_folders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=814 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `drive_pending_nas_migration`
--

DROP TABLE IF EXISTS `drive_pending_nas_migration`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `drive_pending_nas_migration` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file_id` int(11) NOT NULL,
  `local_path` varchar(1000) NOT NULL COMMENT 'Current path on server local storage',
  `nas_target_path` varchar(1000) NOT NULL COMMENT 'Target path on NAS when migrated',
  `user_email` varchar(255) NOT NULL COMMENT 'Owner of the file',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `migrated_at` timestamp NULL DEFAULT NULL COMMENT 'When migration completed',
  `attempts` int(11) DEFAULT 0 COMMENT 'Number of migration attempts',
  `last_attempt_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','migrating','completed','failed') DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pending_migration_status` (`status`),
  KEY `idx_pending_migration_user` (`user_email`),
  KEY `idx_pending_migration_file` (`file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `drive_quotas`
--

DROP TABLE IF EXISTS `drive_quotas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `drive_quotas` (
  `user_email` varchar(255) NOT NULL,
  `quota_bytes` bigint(20) DEFAULT -1 COMMENT '-1 = unlimited',
  `used_bytes` bigint(20) DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `emailAddons_assignments`
--

DROP TABLE IF EXISTS `emailAddons_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `emailAddons_assignments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `addon_slug` varchar(50) NOT NULL,
  `target_type` enum('user','group') NOT NULL,
  `target_id` varchar(255) NOT NULL COMMENT 'email for user, group_id for group',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `assigned_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_assignment` (`addon_slug`,`target_type`,`target_id`)
) ENGINE=InnoDB AUTO_INCREMENT=145 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `emailAddons_group_members`
--

DROP TABLE IF EXISTS `emailAddons_group_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `emailAddons_group_members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int(10) unsigned NOT NULL,
  `email` varchar(255) NOT NULL,
  `added_by` varchar(255) NOT NULL,
  `added_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_group_email` (`group_id`,`email`),
  KEY `idx_email` (`email`),
  CONSTRAINT `emailAddons_group_members_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `emailAddons_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `emailAddons_groups`
--

DROP TABLE IF EXISTS `emailAddons_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `emailAddons_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(20) DEFAULT '#6366f1',
  `icon` varchar(50) DEFAULT 'group',
  `created_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_campaign_log`
--

DROP TABLE IF EXISTS `email_campaign_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_campaign_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` varchar(36) NOT NULL,
  `event_type` varchar(50) NOT NULL,
  `recipient_email` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_campaign_time` (`campaign_id`,`created_at`),
  CONSTRAINT `fk_log_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `email_campaigns` (`campaign_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_campaigns`
--

DROP TABLE IF EXISTS `email_campaigns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_campaigns` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` varchar(36) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `subject` varchar(500) NOT NULL,
  `body_html` longtext DEFAULT NULL,
  `body_text` text DEFAULT NULL,
  `from_name` varchar(255) DEFAULT NULL,
  `attachments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attachments`)),
  `in_reply_to` varchar(255) DEFAULT NULL,
  `references` text DEFAULT NULL,
  `track_read` tinyint(1) DEFAULT 1,
  `mailing_list_id` int(10) unsigned DEFAULT NULL,
  `total_recipients` int(10) unsigned DEFAULT 0,
  `sent_count` int(10) unsigned DEFAULT 0,
  `failed_count` int(10) unsigned DEFAULT 0,
  `status` enum('draft','pending','processing','completed','paused','cancelled') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `source` varchar(50) DEFAULT 'manual',
  `source_id` varchar(255) DEFAULT NULL,
  `parent_campaign_id` varchar(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `campaign_id` (`campaign_id`),
  KEY `idx_user_status` (`user_email`,`status`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_click_events`
--

DROP TABLE IF EXISTS `email_click_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_click_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `link_token` varchar(64) NOT NULL,
  `recipient_token` varchar(64) DEFAULT NULL,
  `recipient_email` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `clicked_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_link_token` (`link_token`),
  KEY `idx_recipient` (`recipient_token`),
  KEY `idx_rate_limit` (`link_token`,`recipient_email`,`ip_address`,`clicked_at`),
  CONSTRAINT `email_click_events_ibfk_1` FOREIGN KEY (`link_token`) REFERENCES `email_link_tracking` (`link_token`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=170 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_contacts`
--

DROP TABLE IF EXISTS `email_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_contacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL COMMENT 'Owner/sender email',
  `contact_email` varchar(255) NOT NULL,
  `contact_name` varchar(255) DEFAULT NULL,
  `use_count` int(11) DEFAULT 1,
  `last_used` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_contact` (`user_email`,`contact_email`),
  KEY `idx_user_email` (`user_email`),
  KEY `idx_use_count` (`use_count` DESC),
  KEY `idx_last_used` (`last_used` DESC)
) ENGINE=InnoDB AUTO_INCREMENT=448 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_link_tracking`
--

DROP TABLE IF EXISTS `email_link_tracking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_link_tracking` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tracking_id` varchar(64) NOT NULL,
  `link_token` varchar(64) NOT NULL,
  `original_url` text NOT NULL,
  `link_index` int(11) NOT NULL DEFAULT 0,
  `block_id` varchar(36) DEFAULT NULL,
  `block_type` varchar(50) DEFAULT NULL,
  `block_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `link_token` (`link_token`),
  KEY `idx_tracking_id` (`tracking_id`),
  KEY `idx_link_token` (`link_token`),
  KEY `idx_block_id` (`block_id`),
  CONSTRAINT `email_link_tracking_ibfk_1` FOREIGN KEY (`tracking_id`) REFERENCES `email_tracking` (`tracking_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1014 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_queue`
--

DROP TABLE IF EXISTS `email_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_queue` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` varchar(36) NOT NULL,
  `recipient_email` varchar(255) NOT NULL,
  `recipient_name` varchar(255) DEFAULT NULL,
  `recipient_type` enum('to','cc','bcc') DEFAULT 'to',
  `recipient_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`recipient_data`)),
  `status` enum('pending','sending','sent','failed','rate_limited') DEFAULT 'pending',
  `attempts` int(10) unsigned DEFAULT 0,
  `max_attempts` int(10) unsigned DEFAULT 3,
  `scheduled_at` timestamp NULL DEFAULT current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `last_attempt_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_campaign` (`campaign_id`),
  KEY `idx_status_scheduled` (`status`,`scheduled_at`),
  KEY `idx_recipient` (`recipient_email`),
  CONSTRAINT `fk_queue_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `email_campaigns` (`campaign_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_rate_limits`
--

DROP TABLE IF EXISTS `email_rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_rate_limits` (
  `user_email` varchar(255) NOT NULL,
  `hourly_count` int(10) unsigned DEFAULT 0,
  `hourly_reset_at` timestamp NULL DEFAULT current_timestamp(),
  `daily_count` int(10) unsigned DEFAULT 0,
  `daily_reset_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_email`),
  KEY `idx_hourly_reset` (`hourly_reset_at`),
  KEY `idx_daily_reset` (`daily_reset_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_read_events`
--

DROP TABLE IF EXISTS `email_read_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_read_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tracking_id` varchar(64) NOT NULL,
  `recipient_email` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tracking_id` (`tracking_id`),
  KEY `idx_rate_limit` (`tracking_id`,`recipient_email`,`ip_address`,`read_at`),
  CONSTRAINT `email_read_events_ibfk_1` FOREIGN KEY (`tracking_id`) REFERENCES `email_tracking` (`tracking_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3591 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_templates`
--

DROP TABLE IF EXISTS `email_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `created_by` varchar(255) NOT NULL,
  `organization_domain` varchar(255) NOT NULL,
  `name` varchar(200) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'custom',
  `icon` varchar(50) DEFAULT 'dashboard_customize',
  `html_content` mediumtext NOT NULL,
  `thumbnail` text DEFAULT NULL,
  `is_shared` tinyint(1) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_creator` (`created_by`),
  KEY `idx_org` (`organization_domain`),
  KEY `idx_shared` (`organization_domain`,`is_shared`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_tracking`
--

DROP TABLE IF EXISTS `email_tracking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_tracking` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL COMMENT 'Sender email',
  `tracking_id` varchar(64) NOT NULL,
  `campaign_id` varchar(36) DEFAULT NULL,
  `subject` varchar(500) DEFAULT NULL,
  `recipients` text DEFAULT NULL COMMENT 'JSON array of recipients',
  `sender_ip` varchar(45) DEFAULT NULL COMMENT 'IP address when email was sent',
  `sender_ua_hash` varchar(64) DEFAULT NULL COMMENT 'Hash of user-agent when email was sent',
  `sent_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `tracking_id` (`tracking_id`),
  KEY `idx_user_email` (`user_email`),
  KEY `idx_tracking_id` (`tracking_id`),
  KEY `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB AUTO_INCREMENT=610 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_tracking_recipients`
--

DROP TABLE IF EXISTS `email_tracking_recipients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_tracking_recipients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tracking_id` varchar(64) NOT NULL,
  `recipient_email` varchar(255) NOT NULL,
  `recipient_token` varchar(64) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `recipient_token` (`recipient_token`),
  KEY `idx_tracking_id` (`tracking_id`),
  KEY `idx_token` (`recipient_token`),
  CONSTRAINT `email_tracking_recipients_ibfk_1` FOREIGN KEY (`tracking_id`) REFERENCES `email_tracking` (`tracking_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=819 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_unsubscribes`
--

DROP TABLE IF EXISTS `email_unsubscribes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_unsubscribes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL COMMENT 'Sender who was unsubscribed from',
  `unsubscribed_email` varchar(255) NOT NULL COMMENT 'Recipient who unsubscribed',
  `reason` varchar(500) DEFAULT NULL,
  `unsubscribed_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_unsub` (`user_email`,`unsubscribed_email`),
  KEY `idx_user` (`user_email`),
  KEY `idx_recipient` (`unsubscribed_email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `guest_call_tokens`
--

DROP TABLE IF EXISTS `guest_call_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `guest_call_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(128) NOT NULL,
  `portal_call_id` int(11) DEFAULT NULL,
  `room_name` varchar(255) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `guest_name` varchar(255) DEFAULT NULL,
  `guest_email` varchar(255) DEFAULT NULL,
  `role` enum('admin','guest') NOT NULL DEFAULT 'guest',
  `created_by` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `use_count` int(11) NOT NULL DEFAULT 0,
  `max_uses` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','expired','revoked') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_token` (`token`),
  KEY `idx_room` (`room_name`),
  KEY `idx_status_expires` (`status`,`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=60 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `hosting_clients`
--

DROP TABLE IF EXISTS `hosting_clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `hosting_clients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `company` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `hosting_domains`
--

DROP TABLE IF EXISTS `hosting_domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `hosting_domains` (
  `client_id` int(10) unsigned NOT NULL,
  `domain` varchar(255) NOT NULL,
  PRIMARY KEY (`client_id`,`domain`),
  CONSTRAINT `hosting_domains_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `hosting_payments`
--

DROP TABLE IF EXISTS `hosting_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `hosting_payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `subscription_id` int(10) unsigned DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'HUF',
  `payment_date` date NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `transaction_ref` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `subscription_id` (`subscription_id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_date` (`payment_date`),
  CONSTRAINT `hosting_payments_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hosting_payments_ibfk_2` FOREIGN KEY (`subscription_id`) REFERENCES `hosting_subscriptions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `hosting_subscriptions`
--

DROP TABLE IF EXISTS `hosting_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `hosting_subscriptions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `plan_name` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'HUF',
  `billing_cycle` enum('monthly','yearly') DEFAULT 'yearly',
  `start_date` date NOT NULL,
  `next_due_date` date NOT NULL,
  `status` enum('active','cancelled','expired') DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  KEY `idx_next_due` (`next_due_date`),
  KEY `idx_status` (`status`),
  CONSTRAINT `hosting_subscriptions_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `imap_migrations`
--

DROP TABLE IF EXISTS `imap_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `imap_migrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('single','batch') NOT NULL DEFAULT 'single',
  `source_host` varchar(255) NOT NULL,
  `source_port` int(11) DEFAULT 993,
  `source_ssl` tinyint(1) DEFAULT 1,
  `dest_host` varchar(255) NOT NULL,
  `dest_port` int(11) DEFAULT 993,
  `dest_ssl` tinyint(1) DEFAULT 1,
  `accounts` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Array of {email, source_password, dest_email, dest_password}' CHECK (json_valid(`accounts`)),
  `total_accounts` int(11) DEFAULT 1,
  `completed_accounts` int(11) DEFAULT 0,
  `status` enum('pending','running','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  `progress` int(11) DEFAULT 0 COMMENT 'Overall progress percentage',
  `current_account` varchar(255) DEFAULT NULL COMMENT 'Currently migrating account',
  `pid` int(11) DEFAULT NULL COMMENT 'Process ID of running imapsync',
  `log_file` varchar(512) DEFAULT NULL COMMENT 'Path to log file',
  `error_message` text DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `location_profiles`
--

DROP TABLE IF EXISTS `location_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `location_profiles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL COMMENT 'e.g. Office, Home, VPN',
  `base_path` varchar(500) NOT NULL COMMENT 'e.g. Z:\\ or /Volumes/NAS',
  `is_default` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_profile` (`user_email`,`name`),
  KEY `idx_user` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `success` tinyint(1) DEFAULT 0,
  `attempted_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip` (`ip_address`),
  KEY `idx_username` (`username`),
  KEY `idx_attempted_at` (`attempted_at`),
  KEY `idx_ip_time` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=180 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `login_rate_limits`
--

DROP TABLE IF EXISTS `login_rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_rate_limits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `identifier` varchar(255) NOT NULL COMMENT 'email or IP address',
  `identifier_type` enum('email','ip') NOT NULL DEFAULT 'email',
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 1,
  `first_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
  `locked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_identifier` (`identifier`,`identifier_type`),
  KEY `idx_locked` (`locked_until`),
  KEY `idx_cleanup` (`first_attempt_at`)
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mail_accounts`
--

DROP TABLE IF EXISTS `mail_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mail_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `domain` varchar(255) NOT NULL,
  `username` varchar(64) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `quota_mb` int(11) DEFAULT 512,
  `disk_usage_kb` bigint(20) DEFAULT 0,
  `maildir_path` varchar(512) DEFAULT NULL,
  `status` enum('active','suspended','vacation') NOT NULL DEFAULT 'active',
  `login_suspended` tinyint(1) NOT NULL DEFAULT 0,
  `suspended_at` timestamp NULL DEFAULT NULL,
  `suspended_reason` varchar(255) DEFAULT NULL,
  `vacation_message` text DEFAULT NULL,
  `vacation_subject` varchar(255) DEFAULT NULL,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_domain` (`domain`),
  KEY `idx_status` (`status`),
  KEY `idx_login_suspended` (`login_suspended`)
) ENGINE=InnoDB AUTO_INCREMENT=132 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mail_domains`
--

DROP TABLE IF EXISTS `mail_domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mail_domains` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) NOT NULL,
  `dkim_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `dkim_selector` varchar(64) DEFAULT 'default',
  `dkim_private_key` text DEFAULT NULL,
  `dkim_public_key` text DEFAULT NULL,
  `spf_record` varchar(512) DEFAULT NULL,
  `dmarc_record` varchar(512) DEFAULT NULL,
  `catch_all_email` varchar(255) DEFAULT NULL,
  `max_accounts` int(11) DEFAULT 100,
  `max_quota_mb` int(11) DEFAULT 5120,
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain` (`domain`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=205 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mail_forwards`
--

DROP TABLE IF EXISTS `mail_forwards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mail_forwards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_email` varchar(255) NOT NULL,
  `source_domain` varchar(255) NOT NULL,
  `destination` varchar(512) NOT NULL,
  `keep_copy` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_forward` (`source_email`,`destination`),
  KEY `idx_source` (`source_email`),
  KEY `idx_domain` (`source_domain`)
) ENGINE=InnoDB AUTO_INCREMENT=74 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mail_migration_status`
--

DROP TABLE IF EXISTS `mail_migration_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mail_migration_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `migration_phase` enum('not_started','syncing','dual_write','switched','completed') NOT NULL DEFAULT 'not_started',
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `accounts_synced` int(11) DEFAULT 0,
  `forwards_synced` int(11) DEFAULT 0,
  `domains_synced` int(11) DEFAULT 0,
  `postfix_config_updated` tinyint(1) DEFAULT 0,
  `dovecot_config_updated` tinyint(1) DEFAULT 0,
  `rollback_available` tinyint(1) DEFAULT 1,
  `notes` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mailing_list_contacts`
--

DROP TABLE IF EXISTS `mailing_list_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mailing_list_contacts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `list_id` int(10) unsigned NOT NULL,
  `email` varchar(255) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `position` varchar(255) DEFAULT NULL COMMENT 'Job title/position',
  `company` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `custom_fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`custom_fields`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_list_email` (`list_id`,`email`),
  KEY `idx_email` (`email`),
  KEY `idx_name` (`name`),
  CONSTRAINT `mailing_list_contacts_ibfk_1` FOREIGN KEY (`list_id`) REFERENCES `mailing_lists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=466 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mailing_list_custom_fields`
--

DROP TABLE IF EXISTS `mailing_list_custom_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mailing_list_custom_fields` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `list_id` int(10) unsigned NOT NULL,
  `field_key` varchar(50) NOT NULL,
  `field_label` varchar(100) NOT NULL,
  `field_type` enum('text','number','date','select') DEFAULT 'text',
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`options`)),
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_list_field` (`list_id`,`field_key`),
  KEY `idx_list` (`list_id`),
  CONSTRAINT `mailing_list_custom_fields_ibfk_1` FOREIGN KEY (`list_id`) REFERENCES `mailing_lists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mailing_list_imports`
--

DROP TABLE IF EXISTS `mailing_list_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mailing_list_imports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `list_id` int(10) unsigned NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `total_rows` int(11) DEFAULT 0,
  `imported_count` int(11) DEFAULT 0,
  `skipped_count` int(11) DEFAULT 0,
  `error_count` int(11) DEFAULT 0,
  `errors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Details of import errors' CHECK (json_valid(`errors`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_list` (`list_id`),
  CONSTRAINT `mailing_list_imports_ibfk_1` FOREIGN KEY (`list_id`) REFERENCES `mailing_lists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mailing_lists`
--

DROP TABLE IF EXISTS `mailing_lists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mailing_lists` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL COMMENT 'Owner of the mailing list',
  `organization_domain` varchar(255) NOT NULL DEFAULT '',
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(20) DEFAULT '#6366f1' COMMENT 'Badge color',
  `icon` varchar(50) DEFAULT 'mail' COMMENT 'Material Symbol icon',
  `is_shared` tinyint(1) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_name` (`user_email`,`name`),
  KEY `idx_user` (`user_email`),
  KEY `idx_sort` (`user_email`,`sort_order`),
  KEY `idx_domain_shared` (`organization_domain`,`is_shared`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `executed_at` timestamp NULL DEFAULT current_timestamp(),
  `success` tinyint(1) DEFAULT 1,
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=3474045 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mood_board_activity`
--

DROP TABLE IF EXISTS `mood_board_activity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mood_board_activity` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `user_name` varchar(255) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `item_type` varchar(50) DEFAULT NULL,
  `item_label` varchar(500) DEFAULT NULL,
  `target_item_id` int(11) DEFAULT NULL,
  `target_label` varchar(500) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_board` (`board_id`),
  KEY `idx_user` (`user_email`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `mood_board_activity_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `mood_boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6017 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mood_board_board_links`
--

DROP TABLE IF EXISTS `mood_board_board_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mood_board_board_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mood_board_id` int(11) NOT NULL,
  `kanban_board_id` int(11) NOT NULL COMMENT 'References webmail_boards.id',
  `linked_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_mood_kanban` (`mood_board_id`,`kanban_board_id`),
  KEY `idx_mood` (`mood_board_id`),
  KEY `idx_kanban` (`kanban_board_id`),
  CONSTRAINT `mood_board_board_links_ibfk_1` FOREIGN KEY (`mood_board_id`) REFERENCES `mood_boards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mood_board_board_links_ibfk_2` FOREIGN KEY (`kanban_board_id`) REFERENCES `webmail_boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mood_board_client_links`
--

DROP TABLE IF EXISTS `mood_board_client_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mood_board_client_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `mood_board_id` int(11) NOT NULL,
  `linked_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_client_mood` (`client_id`,`mood_board_id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_mood_board` (`mood_board_id`),
  CONSTRAINT `mood_board_client_links_ibfk_1` FOREIGN KEY (`mood_board_id`) REFERENCES `mood_boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mood_board_comments`
--

DROP TABLE IF EXISTS `mood_board_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mood_board_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `thread_id` char(36) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `author_email` varchar(255) DEFAULT NULL,
  `author_name` varchar(255) NOT NULL,
  `author_avatar_color` varchar(7) DEFAULT NULL,
  `content` text NOT NULL,
  `pin_x` decimal(10,4) DEFAULT NULL,
  `pin_y` decimal(10,4) DEFAULT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 0,
  `share_token` varchar(64) DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mbc_board` (`board_id`),
  KEY `idx_mbc_item` (`item_id`),
  KEY `idx_mbc_thread` (`thread_id`),
  KEY `idx_mbc_parent` (`parent_id`),
  KEY `idx_mbc_deleted` (`deleted_at`),
  CONSTRAINT `fk_mbc_board` FOREIGN KEY (`board_id`) REFERENCES `mood_boards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mbc_parent` FOREIGN KEY (`parent_id`) REFERENCES `mood_board_comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mood_board_components`
--

DROP TABLE IF EXISTS `mood_board_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mood_board_components` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_email` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT 'Untitled Component',
  `description` text DEFAULT NULL,
  `thumbnail_url` varchar(500) DEFAULT NULL,
  `items_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Array of item definitions (positions, styles, content)' CHECK (json_valid(`items_data`)),
  `is_global` tinyint(1) DEFAULT 0 COMMENT 'If 1, shared with all team members',
  `category` varchar(100) DEFAULT 'custom' COMMENT 'Category: custom, button, card, layout, etc.',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`owner_email`),
  KEY `idx_category` (`category`),
  KEY `idx_global` (`is_global`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mood_board_connections`
--

DROP TABLE IF EXISTS `mood_board_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mood_board_connections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `from_item_id` int(11) NOT NULL,
  `to_item_id` int(11) NOT NULL,
  `line_style` enum('solid','dashed','dotted') DEFAULT 'solid',
  `line_color` varchar(20) DEFAULT '#666666',
  `line_width` tinyint(3) unsigned DEFAULT 2,
  `arrow_start` tinyint(1) DEFAULT 0,
  `arrow_end` tinyint(1) DEFAULT 1,
  `label` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `from_anchor_x` float DEFAULT NULL,
  `from_anchor_y` float DEFAULT NULL,
  `to_anchor_x` float DEFAULT NULL,
  `to_anchor_y` float DEFAULT NULL,
  `glow_enabled` tinyint(1) DEFAULT 0,
  `glow_color` varchar(20) DEFAULT NULL,
  `glow_opacity` tinyint(3) unsigned DEFAULT 60,
  `glow_blur` tinyint(3) unsigned DEFAULT 6,
  `gradient_enabled` tinyint(1) DEFAULT 0,
  `gradient_color_start` varchar(20) DEFAULT NULL,
  `gradient_color_end` varchar(20) DEFAULT NULL,
  `bend_x` float DEFAULT NULL,
  `bend_y` float DEFAULT NULL,
  `bend2_x` float DEFAULT NULL,
  `bend2_y` float DEFAULT NULL,
  `render_above` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_board` (`board_id`),
  KEY `idx_from` (`from_item_id`),
  KEY `idx_to` (`to_item_id`),
  CONSTRAINT `mood_board_connections_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `mood_boards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mood_board_connections_ibfk_2` FOREIGN KEY (`from_item_id`) REFERENCES `mood_board_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mood_board_connections_ibfk_3` FOREIGN KEY (`to_item_id`) REFERENCES `mood_board_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1154 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mood_board_folders`
--

DROP TABLE IF EXISTS `mood_board_folders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mood_board_folders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_email` varchar(255) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `color` varchar(20) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`owner_email`),
  KEY `idx_parent` (`parent_id`),
  CONSTRAINT `mood_board_folders_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `mood_board_folders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mood_board_group_access`
--

DROP TABLE IF EXISTS `mood_board_group_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mood_board_group_access` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `group_id` int(10) unsigned NOT NULL,
  `role` enum('viewer','editor') DEFAULT 'editor',
  `granted_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_mood_board_group` (`board_id`,`group_id`),
  KEY `idx_group` (`group_id`),
  CONSTRAINT `mood_board_group_access_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `mood_boards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mood_board_group_access_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `colleague_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mood_board_image_set_items`
--

DROP TABLE IF EXISTS `mood_board_image_set_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mood_board_image_set_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL COMMENT 'The parent image_set item',
  `image_url` varchar(500) NOT NULL,
  `thumbnail_url` varchar(500) DEFAULT NULL,
  `drive_file_id` int(11) DEFAULT NULL COMMENT 'If sourced from Drive',
  `original_filename` varchar(255) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `width_px` int(11) DEFAULT NULL,
  `height_px` int(11) DEFAULT NULL,
  `position` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_item` (`item_id`),
  CONSTRAINT `mood_board_image_set_items_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `mood_board_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mood_board_items`
--

DROP TABLE IF EXISTS `mood_board_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mood_board_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL COMMENT 'For grouping items inside a frame',
  `type` enum('note','image','text','link','todo_list','file','color_swatch','board_link','frame','image_set','calendar_event','drawing','table','column','folder','shape','pen_shape','video','youtube','line','artboard','audio','slide','group','repeat_grid') NOT NULL,
  `pos_x` int(11) NOT NULL DEFAULT 0,
  `pos_y` int(11) NOT NULL DEFAULT 0,
  `width` int(11) DEFAULT 240,
  `height` int(11) DEFAULT NULL COMMENT 'Auto-calculated or manual',
  `rotation` decimal(5,2) DEFAULT 0.00,
  `z_index` int(11) DEFAULT 0,
  `slide_order` int(11) DEFAULT NULL COMMENT 'Presentation slide order (NULL = not a slide)',
  `transition_type` varchar(20) DEFAULT 'fly',
  `transition_duration` decimal(5,2) DEFAULT NULL,
  `presenter_notes` text DEFAULT NULL COMMENT 'Speaker notes for presentation mode',
  `locked` tinyint(1) DEFAULT 0,
  `title` varchar(500) DEFAULT NULL,
  `content` mediumtext DEFAULT NULL,
  `color` varchar(20) DEFAULT NULL COMMENT 'Card/note background color',
  `color_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Extended color data: hex, rgb, cmyk' CHECK (json_valid(`color_data`)),
  `url` varchar(2000) DEFAULT NULL COMMENT 'For link/embed types',
  `drive_file_id` int(11) DEFAULT NULL COMMENT 'Link to drive files table',
  `image_url` varchar(500) DEFAULT NULL COMMENT 'Uploaded or external image URL',
  `thumbnail_url` varchar(500) DEFAULT NULL,
  `linked_board_id` int(11) DEFAULT NULL COMMENT 'Reference to a Kanban board',
  `linked_card_id` int(11) DEFAULT NULL COMMENT 'Reference to a board card',
  `calendar_event_id` int(11) DEFAULT NULL COMMENT 'Reference to calendar event',
  `style_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Font, border, shadow, opacity etc.' CHECK (json_valid(`style_data`)),
  `created_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `component_id` int(11) DEFAULT NULL COMMENT 'Source component ID (null = not from a component)',
  `component_instance_id` varchar(36) DEFAULT NULL COMMENT 'Groups items placed together from one component placement',
  `component_item_index` smallint(6) DEFAULT NULL COMMENT 'Index within the component items_data array',
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_board` (`board_id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_type` (`type`),
  KEY `idx_slide_order` (`board_id`,`slide_order`),
  KEY `idx_component_id` (`component_id`),
  KEY `idx_component_instance` (`component_instance_id`),
  KEY `idx_mbi_deleted` (`deleted_at`),
  CONSTRAINT `mood_board_items_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `mood_boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=76650 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mood_board_measurements`
--

DROP TABLE IF EXISTS `mood_board_measurements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mood_board_measurements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `x1` float NOT NULL,
  `y1` float NOT NULL,
  `x2` float NOT NULL,
  `y2` float NOT NULL,
  `distance` int(11) NOT NULL DEFAULT 0,
  `width` int(11) NOT NULL DEFAULT 0,
  `height` int(11) NOT NULL DEFAULT 0,
  `angle` float NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_board` (`board_id`),
  CONSTRAINT `mood_board_measurements_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `mood_boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mood_board_members`
--

DROP TABLE IF EXISTS `mood_board_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mood_board_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` enum('viewer','editor','admin') DEFAULT 'editor',
  `invited_by` varchar(255) DEFAULT NULL,
  `added_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_board_member` (`board_id`,`email`),
  KEY `idx_board` (`board_id`),
  KEY `idx_email` (`email`),
  CONSTRAINT `mood_board_members_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `mood_boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mood_board_share_views`
--

DROP TABLE IF EXISTS `mood_board_share_views`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mood_board_share_views` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `session_id` varchar(64) NOT NULL COMMENT 'Client-generated session ID for duration tracking',
  `visitor_ip` varchar(45) DEFAULT NULL COMMENT 'IPv4 or IPv6 address',
  `user_agent` varchar(500) DEFAULT NULL,
  `referrer` varchar(2000) DEFAULT NULL,
  `device_type` enum('desktop','mobile','tablet','unknown') DEFAULT 'unknown',
  `browser` varchar(100) DEFAULT NULL,
  `os` varchar(100) DEFAULT NULL,
  `duration_seconds` int(11) DEFAULT 0 COMMENT 'Total viewing time in seconds',
  `slides_viewed` int(11) DEFAULT 0 COMMENT 'Number of presentation slides viewed',
  `started_at` timestamp NULL DEFAULT current_timestamp(),
  `last_heartbeat_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_board` (`board_id`),
  KEY `idx_session` (`session_id`),
  KEY `idx_started` (`started_at`),
  CONSTRAINT `mood_board_share_views_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `mood_boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=120 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mood_board_snapshots`
--

DROP TABLE IF EXISTS `mood_board_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mood_board_snapshots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `trigger_type` varchar(30) NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `items_json` longtext NOT NULL,
  `connections_json` text DEFAULT NULL,
  `todos_json` longtext DEFAULT NULL,
  `image_set_json` longtext DEFAULT NULL,
  `item_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_snap_board` (`board_id`),
  KEY `idx_snap_created` (`created_at`),
  CONSTRAINT `mood_board_snapshots_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `mood_boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=151 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mood_board_todos`
--

DROP TABLE IF EXISTS `mood_board_todos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mood_board_todos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `text` varchar(500) NOT NULL,
  `completed` tinyint(1) DEFAULT 0,
  `position` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_item` (`item_id`),
  CONSTRAINT `mood_board_todos_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `mood_board_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mood_board_uploads`
--

DROP TABLE IF EXISTS `mood_board_uploads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mood_board_uploads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL COMMENT 'Links to the mood_board_item this upload is used in',
  `original_filename` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int(11) DEFAULT 0,
  `thumbnail_filename` varchar(255) DEFAULT NULL COMMENT 'Generated thumbnail filename in /thumbs/ subdirectory',
  `width_px` int(11) DEFAULT NULL,
  `height_px` int(11) DEFAULT NULL,
  `uploaded_by` varchar(255) DEFAULT NULL,
  `drive_file_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_board` (`board_id`),
  KEY `idx_item` (`item_id`),
  CONSTRAINT `mood_board_uploads_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `mood_boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=409 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mood_board_user_palettes`
--

DROP TABLE IF EXISTS `mood_board_user_palettes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mood_board_user_palettes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL COMMENT 'Owner email',
  `name` varchar(100) NOT NULL DEFAULT 'Untitled Palette',
  `colors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array of hex color strings' CHECK (json_valid(`colors`)),
  `gradients` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array of gradient objects' CHECK (json_valid(`gradients`)),
  `is_shared` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = visible to colleagues on same domain',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_shared` (`is_shared`,`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mood_boards`
--

DROP TABLE IF EXISTS `mood_boards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mood_boards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_email` varchar(255) NOT NULL,
  `client_id` int(10) unsigned DEFAULT NULL COMMENT 'Optional link to a client',
  `folder_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `background_color` varchar(20) DEFAULT '#f5f5f5',
  `background_image` varchar(500) DEFAULT NULL,
  `background_image_size` varchar(20) DEFAULT 'cover',
  `background_spline_url` varchar(500) DEFAULT NULL,
  `canvas_width` int(11) DEFAULT 4000,
  `canvas_height` int(11) DEFAULT 3000,
  `zoom_level` decimal(4,2) DEFAULT 1.00,
  `viewport_x` int(11) DEFAULT 0,
  `viewport_y` int(11) DEFAULT 0,
  `canvas_strokes` longtext DEFAULT NULL,
  `is_template` tinyint(1) DEFAULT 0,
  `archived` tinyint(1) DEFAULT 0,
  `is_ready` tinyint(1) DEFAULT 0,
  `ready_at` datetime DEFAULT NULL,
  `marked_ready_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `share_token` varchar(64) DEFAULT NULL,
  `share_mode` enum('off','view','edit') DEFAULT 'off',
  `share_password` varchar(255) DEFAULT NULL,
  `share_expires` timestamp NULL DEFAULT NULL,
  `allow_comments` tinyint(1) NOT NULL DEFAULT 1,
  `notify_on_comment` tinyint(1) NOT NULL DEFAULT 1,
  `motion_settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Per-board motion/animation settings (enabled, cards, elements, lines, intensity, speed, etc.)' CHECK (json_valid(`motion_settings`)),
  `color_palette` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Saved color swatches for the board' CHECK (json_valid(`color_palette`)),
  `background_effect` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Background effects: grain, blur, gradient, noise' CHECK (json_valid(`background_effect`)),
  `guides` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array of {id, axis, position} guide lines' CHECK (json_valid(`guides`)),
  `gradient_palette` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`gradient_palette`)),
  `brush_presets` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`brush_presets`)),
  `brush_settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`brush_settings`)),
  `bg_audio` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Background audio: {type: youtube|file, url: string, volume: 0-100, loop: bool}' CHECK (json_valid(`bg_audio`)),
  `conn_panel_position` tinyint(3) unsigned DEFAULT 70,
  `design_tokens` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Named design tokens: colors, fonts, etc.' CHECK (json_valid(`design_tokens`)),
  `global_text_styles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`global_text_styles`)),
  `measure_color` varchar(20) DEFAULT '#0ea5e9',
  `measure_width` decimal(3,1) DEFAULT 1.5,
  `measure_visible` tinyint(1) DEFAULT 1,
  `global_css_classes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`global_css_classes`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_mood_share_token` (`share_token`),
  KEY `idx_owner` (`owner_email`),
  KEY `idx_client` (`client_id`),
  KEY `idx_archived` (`archived`),
  KEY `idx_folder` (`folder_id`),
  CONSTRAINT `fk_mood_boards_folder` FOREIGN KEY (`folder_id`) REFERENCES `mood_board_folders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ms_calendar_sync_map`
--

DROP TABLE IF EXISTS `ms_calendar_sync_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ms_calendar_sync_map` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `local_event_id` int(11) NOT NULL,
  `ms_event_id` varchar(255) NOT NULL,
  `ms_calendar_id` varchar(255) NOT NULL,
  `oauth_account_id` int(11) NOT NULL,
  `connection_type` varchar(50) DEFAULT 'microsoft_oauth',
  `sync_direction` enum('ms_to_local','local_to_ms','bidirectional') DEFAULT 'bidirectional',
  `last_synced` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_sync` (`local_event_id`,`ms_event_id`,`oauth_account_id`),
  KEY `idx_ms_event` (`ms_event_id`),
  KEY `idx_local_event` (`local_event_id`),
  KEY `idx_oauth_account` (`oauth_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ms_calendar_sync_state`
--

DROP TABLE IF EXISTS `ms_calendar_sync_state`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ms_calendar_sync_state` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `oauth_account_id` int(11) NOT NULL,
  `ms_calendar_id` varchar(255) NOT NULL,
  `local_calendar_id` int(11) NOT NULL,
  `connection_type` varchar(50) DEFAULT 'microsoft_oauth',
  `delta_link` text DEFAULT NULL COMMENT 'Delta link for incremental sync',
  `sync_enabled` tinyint(1) DEFAULT 1,
  `last_full_sync` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_sync_state` (`oauth_account_id`,`ms_calendar_id`,`connection_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `nas_connection_config`
--

DROP TABLE IF EXISTS `nas_connection_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `nas_connection_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `config_key` varchar(100) NOT NULL,
  `config_value` text NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_key` (`config_key`),
  KEY `idx_nas_config_key` (`config_key`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `nas_connections`
--

DROP TABLE IF EXISTS `nas_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `nas_connections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `driver` enum('local','nfs','cifs') DEFAULT 'nfs',
  `mount_point` varchar(500) NOT NULL,
  `nfs_server` varchar(255) DEFAULT NULL,
  `nfs_path` varchar(500) DEFAULT NULL,
  `nfs_options` varchar(500) DEFAULT 'rw,soft,timeo=10,retrans=3',
  `vpn_enabled` tinyint(1) DEFAULT 0,
  `vpn_config_path` varchar(500) DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `status` enum('active','inactive','error') DEFAULT 'active',
  `last_check` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_default` (`is_default`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `nas_domain_overrides`
--

DROP TABLE IF EXISTS `nas_domain_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `nas_domain_overrides` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nas_connection_id` int(10) unsigned NOT NULL,
  `domain` varchar(255) NOT NULL,
  `sub_path` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_domain` (`domain`),
  KEY `nas_connection_id` (`nas_connection_id`),
  KEY `idx_domain` (`domain`),
  CONSTRAINT `nas_domain_overrides_ibfk_1` FOREIGN KEY (`nas_connection_id`) REFERENCES `nas_connections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'system',
  `title` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `data` text DEFAULT NULL COMMENT 'JSON additional data',
  `is_read` tinyint(1) DEFAULT 0,
  `pinned` tinyint(1) DEFAULT 0,
  `tracking_id` varchar(64) DEFAULT NULL,
  `campaign_id` varchar(36) DEFAULT NULL,
  `read_events` text DEFAULT NULL COMMENT 'JSON array of all read events',
  `last_read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_email` (`user_email`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_pinned` (`pinned`),
  KEY `idx_tracking_id` (`tracking_id`),
  KEY `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1685 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `onboarding_quiz_scores`
--

DROP TABLE IF EXISTS `onboarding_quiz_scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `onboarding_quiz_scores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `score` int(11) NOT NULL DEFAULT 0,
  `total` int(11) NOT NULL DEFAULT 0,
  `percent` int(11) NOT NULL DEFAULT 0,
  `attempts` int(11) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ooo_auto_reply_log`
--

DROP TABLE IF EXISTS `ooo_auto_reply_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ooo_auto_reply_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `event_type` varchar(50) NOT NULL,
  `sender_email` varchar(255) DEFAULT NULL,
  `original_subject` varchar(500) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_email`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ooo_auto_reply_tracking`
--

DROP TABLE IF EXISTS `ooo_auto_reply_tracking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ooo_auto_reply_tracking` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL COMMENT 'The user who has OOO enabled',
  `sender_email` varchar(255) NOT NULL COMMENT 'The sender who received the auto-reply',
  `original_message_id` varchar(255) DEFAULT NULL COMMENT 'Message-ID of the email that triggered reply',
  `original_subject` varchar(500) DEFAULT NULL COMMENT 'Subject of original email',
  `replied_at` timestamp NULL DEFAULT current_timestamp() COMMENT 'When auto-reply was sent',
  `reply_message_id` varchar(255) DEFAULT NULL COMMENT 'Message-ID of the auto-reply sent',
  `ooo_period_start` datetime NOT NULL COMMENT 'Start of the OOO period when reply was sent',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_sender_period` (`user_email`,`sender_email`,`ooo_period_start`),
  KEY `idx_user_email` (`user_email`),
  KEY `idx_replied_at` (`replied_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `organization_colleagues`
--

DROP TABLE IF EXISTS `organization_colleagues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `organization_colleagues` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `organization_domain` varchar(255) NOT NULL COMMENT 'e.g., pixelranger.hu',
  `email` varchar(255) NOT NULL COMMENT 'Full email address',
  `display_name` varchar(255) DEFAULT NULL,
  `avatar_path` varchar(500) DEFAULT NULL COMMENT 'Path to avatar in Drive storage',
  `job_title` varchar(255) DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `is_admin` tinyint(1) DEFAULT 0 COMMENT 'Can manage colleagues/groups',
  `status` enum('active','away','offline','do_not_disturb') DEFAULT 'active',
  `status_text` varchar(100) DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `profile_updated_at` timestamp NULL DEFAULT NULL,
  `synced_from_mailserver` tinyint(1) DEFAULT 0 COMMENT 'Was auto-synced from Dovecot',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_email` (`email`),
  KEY `idx_domain` (`organization_domain`),
  KEY `idx_admin` (`organization_domain`,`is_admin`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=233 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `panel_addons`
--

DROP TABLE IF EXISTS `panel_addons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `panel_addons` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT 'extension',
  `enabled` tinyint(1) DEFAULT 0,
  `enabled_at` datetime DEFAULT NULL,
  `enabled_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pinned_emails`
--

DROP TABLE IF EXISTS `pinned_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pinned_emails` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `folder` varchar(255) NOT NULL,
  `uid` int(11) NOT NULL,
  `message_id` varchar(512) DEFAULT NULL,
  `subject` varchar(512) DEFAULT NULL,
  `pinned_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_pin` (`user_email`,`folder`,`uid`),
  KEY `idx_user_folder` (`user_email`,`folder`),
  KEY `idx_message_id` (`message_id`)
) ENGINE=InnoDB AUTO_INCREMENT=109 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_access`
--

DROP TABLE IF EXISTS `portal_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_access` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `contact_id` int(10) unsigned DEFAULT NULL COMMENT 'Links to client_contacts.id',
  `email` varchar(255) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `session_count` int(11) DEFAULT 0,
  `created_by` varchar(255) NOT NULL COMMENT 'Internal user who granted access',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_client_email` (`client_id`,`email`),
  KEY `idx_client` (`client_id`),
  KEY `idx_email` (`email`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_annotation_attachments`
--

DROP TABLE IF EXISTS `portal_annotation_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_annotation_attachments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `comment_id` int(10) unsigned NOT NULL,
  `filename` varchar(500) NOT NULL COMMENT 'Stored filename on disk',
  `original_name` varchar(500) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` int(10) unsigned NOT NULL DEFAULT 0,
  `file_path` varchar(1000) DEFAULT NULL COMMENT 'Absolute path on disk (fallback)',
  `drive_file_id` int(10) unsigned DEFAULT NULL COMMENT 'Drive file reference if uploaded to Drive',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_comment` (`comment_id`),
  CONSTRAINT `portal_annotation_attachments_ibfk_1` FOREIGN KEY (`comment_id`) REFERENCES `portal_annotation_comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_annotation_comments`
--

DROP TABLE IF EXISTS `portal_annotation_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_annotation_comments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `annotation_id` int(10) unsigned NOT NULL,
  `parent_comment_id` int(10) unsigned DEFAULT NULL COMMENT 'For threaded replies',
  `author_email` varchar(255) NOT NULL,
  `author_name` varchar(255) DEFAULT NULL,
  `author_type` enum('internal','portal') NOT NULL DEFAULT 'internal',
  `content` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_annotation` (`annotation_id`),
  KEY `idx_parent` (`parent_comment_id`),
  CONSTRAINT `portal_annotation_comments_ibfk_1` FOREIGN KEY (`annotation_id`) REFERENCES `portal_document_annotations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `portal_annotation_comments_ibfk_2` FOREIGN KEY (`parent_comment_id`) REFERENCES `portal_annotation_comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_call_participants`
--

DROP TABLE IF EXISTS `portal_call_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_call_participants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `call_id` int(10) unsigned NOT NULL,
  `participant_type` enum('internal','portal','guest') NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `joined_at` datetime DEFAULT NULL,
  `left_at` datetime DEFAULT NULL,
  `duration_seconds` int(10) unsigned DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_call` (`call_id`),
  KEY `idx_email` (`email`),
  CONSTRAINT `portal_call_participants_ibfk_1` FOREIGN KEY (`call_id`) REFERENCES `portal_calls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_calls`
--

DROP TABLE IF EXISTS `portal_calls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_calls` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `board_id` int(10) unsigned DEFAULT NULL,
  `card_id` int(10) unsigned DEFAULT NULL,
  `created_by` varchar(255) NOT NULL COMMENT 'Internal user who created the call',
  `room_name` varchar(100) NOT NULL COMMENT 'LiveKit room name',
  `call_type` enum('instant','scheduled') DEFAULT 'instant',
  `status` enum('waiting','active','ended','cancelled') DEFAULT 'waiting',
  `scheduled_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `duration_seconds` int(11) DEFAULT 0,
  `had_screen_share` tinyint(1) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `chat_transcript` longtext DEFAULT NULL,
  `transcript_sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_name` (`room_name`),
  KEY `idx_client` (`client_id`),
  KEY `idx_status` (`status`),
  KEY `idx_scheduled` (`scheduled_at`),
  KEY `idx_room` (`room_name`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_comments`
--

DROP TABLE IF EXISTS `portal_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_comments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `update_id` int(10) unsigned NOT NULL,
  `author_type` enum('internal','portal') NOT NULL,
  `author_email` varchar(255) NOT NULL,
  `author_name` varchar(255) DEFAULT NULL,
  `content_text` text NOT NULL,
  `parent_comment_id` int(10) unsigned DEFAULT NULL COMMENT 'Threaded replies',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_update` (`update_id`),
  KEY `idx_parent` (`parent_comment_id`),
  KEY `idx_author` (`author_type`,`author_email`),
  CONSTRAINT `portal_comments_ibfk_1` FOREIGN KEY (`update_id`) REFERENCES `portal_updates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_document_annotations`
--

DROP TABLE IF EXISTS `portal_document_annotations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_document_annotations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int(10) unsigned NOT NULL,
  `page_number` int(11) NOT NULL DEFAULT 1 COMMENT '1-based page index (1 for single-page images)',
  `x_percent` decimal(7,4) NOT NULL COMMENT 'Pin X position as % of page/image width',
  `y_percent` decimal(7,4) NOT NULL COMMENT 'Pin Y position as % of page/image height',
  `status` enum('open','resolved') NOT NULL DEFAULT 'open',
  `created_by_email` varchar(255) NOT NULL,
  `created_by_name` varchar(255) DEFAULT NULL,
  `created_by_type` enum('internal','portal') NOT NULL DEFAULT 'internal',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_document_page` (`document_id`,`page_number`),
  KEY `idx_status` (`document_id`,`status`),
  CONSTRAINT `portal_document_annotations_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `portal_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_document_audit`
--

DROP TABLE IF EXISTS `portal_document_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_document_audit` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int(10) unsigned NOT NULL,
  `action` enum('created','sent','viewed','downloaded','signed','rejected','uploaded','reminder_sent','expired','archived','version_created') NOT NULL,
  `actor_type` enum('internal','portal','system') NOT NULL,
  `actor_email` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional action-specific data' CHECK (json_valid(`details`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_action` (`action`),
  CONSTRAINT `portal_document_audit_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `portal_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=45 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_document_signers`
--

DROP TABLE IF EXISTS `portal_document_signers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_document_signers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int(10) unsigned NOT NULL,
  `portal_access_id` int(10) unsigned DEFAULT NULL,
  `signer_email` varchar(255) NOT NULL,
  `signer_name` varchar(255) DEFAULT NULL,
  `status` enum('pending','signed','rejected') DEFAULT 'pending',
  `signed_at` datetime DEFAULT NULL,
  `signature_type` enum('upload','pad') DEFAULT NULL,
  `uploaded_file_path` varchar(1000) DEFAULT NULL,
  `uploaded_filename` varchar(500) DEFAULT NULL,
  `signature_data` text DEFAULT NULL COMMENT 'Base64 PNG for pad signatures',
  `stamp_data` text DEFAULT NULL COMMENT 'Base64 PNG of uploaded company stamp',
  `stamp_file_path` varchar(1000) DEFAULT NULL COMMENT 'Disk path if stamp uploaded as file',
  `signature_ip` varchar(45) DEFAULT NULL,
  `signature_user_agent` varchar(500) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `reminder_count` int(11) DEFAULT 0,
  `last_reminder_at` datetime DEFAULT NULL,
  `sign_order` int(11) DEFAULT 0 COMMENT '0 = parallel, 1+ = sequential',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_doc_signer` (`document_id`,`signer_email`),
  KEY `idx_document` (`document_id`),
  KEY `idx_status` (`status`),
  KEY `idx_portal_access` (`portal_access_id`),
  CONSTRAINT `portal_document_signers_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `portal_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_document_zones`
--

DROP TABLE IF EXISTS `portal_document_zones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_document_zones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int(10) unsigned NOT NULL,
  `signer_id` int(10) unsigned DEFAULT NULL COMMENT 'FK to portal_document_signers, nullable for assign-later',
  `signer_email` varchar(255) DEFAULT NULL COMMENT 'Fallback for zone-to-signer mapping',
  `zone_type` enum('signature','stamp','signature_and_stamp') NOT NULL DEFAULT 'signature',
  `page_number` int(11) NOT NULL DEFAULT 1 COMMENT '1-based page index',
  `x_percent` decimal(7,4) NOT NULL COMMENT 'X position as % of page width',
  `y_percent` decimal(7,4) NOT NULL COMMENT 'Y position as % of page height',
  `width_percent` decimal(7,4) NOT NULL COMMENT 'Zone width as % of page width',
  `height_percent` decimal(7,4) NOT NULL COMMENT 'Zone height as % of page height',
  `label` varchar(255) DEFAULT NULL COMMENT 'Optional label shown on the zone',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_signer` (`signer_id`),
  KEY `idx_page` (`document_id`,`page_number`),
  CONSTRAINT `portal_document_zones_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `portal_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `portal_document_zones_ibfk_2` FOREIGN KEY (`signer_id`) REFERENCES `portal_document_signers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_documents`
--

DROP TABLE IF EXISTS `portal_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `created_by` varchar(255) NOT NULL,
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `document_type` enum('contract','invoice','proposal','quote','nda','agreement','receipt','other') NOT NULL,
  `status` enum('draft','sent','viewed','signing','signed','rejected','expired','archived') DEFAULT 'draft',
  `filename` varchar(255) NOT NULL COMMENT 'Stored filename',
  `original_name` varchar(500) NOT NULL COMMENT 'Original upload name',
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int(10) unsigned DEFAULT 0,
  `file_path` varchar(1000) NOT NULL COMMENT 'Relative storage path',
  `signed_file_path` varchar(1000) DEFAULT NULL COMMENT 'Path to final merged signed PDF',
  `signed_filename` varchar(500) DEFAULT NULL COMMENT 'Filename of the signed PDF',
  `drive_file_id` int(11) DEFAULT NULL COMMENT 'Optional link to Drive file',
  `signing_method` enum('upload','pad','both') DEFAULT 'both',
  `requires_all_signers` tinyint(1) DEFAULT 1,
  `signing_deadline` date DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `currency` varchar(3) DEFAULT 'HUF',
  `reference_number` varchar(100) DEFAULT NULL,
  `version` int(11) DEFAULT 1,
  `parent_document_id` int(10) unsigned DEFAULT NULL COMMENT 'Previous version of this document',
  `viewed_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `reminder_sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_status` (`status`),
  KEY `idx_type` (`document_type`),
  KEY `idx_deadline` (`signing_deadline`),
  KEY `idx_parent` (`parent_document_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_magic_links`
--

DROP TABLE IF EXISTS `portal_magic_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_magic_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `portal_access_id` int(10) unsigned NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL COMMENT '24 hours from creation',
  `used_at` datetime DEFAULT NULL COMMENT 'NULL until consumed',
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_portal_access` (`portal_access_id`),
  KEY `idx_token` (`token`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `portal_magic_links_ibfk_1` FOREIGN KEY (`portal_access_id`) REFERENCES `portal_access` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_sessions`
--

DROP TABLE IF EXISTS `portal_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `portal_access_id` int(10) unsigned NOT NULL,
  `session_token` varchar(64) NOT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `expires_at` datetime NOT NULL COMMENT '30 days',
  `last_active_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_token` (`session_token`),
  KEY `idx_portal_access` (`portal_access_id`),
  KEY `idx_session_token` (`session_token`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `portal_sessions_ibfk_1` FOREIGN KEY (`portal_access_id`) REFERENCES `portal_access` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_update_files`
--

DROP TABLE IF EXISTS `portal_update_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_update_files` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `update_id` int(10) unsigned NOT NULL,
  `filename` varchar(255) NOT NULL COMMENT 'Stored filename',
  `original_name` varchar(500) NOT NULL COMMENT 'Original upload name',
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int(10) unsigned DEFAULT 0,
  `drive_file_id` int(11) DEFAULT NULL COMMENT 'Optional link to Drive file',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_update` (`update_id`),
  CONSTRAINT `portal_update_files_ibfk_1` FOREIGN KEY (`update_id`) REFERENCES `portal_updates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_update_reads`
--

DROP TABLE IF EXISTS `portal_update_reads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_update_reads` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `update_id` int(10) unsigned NOT NULL,
  `portal_access_id` int(10) unsigned NOT NULL,
  `read_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_read` (`update_id`,`portal_access_id`),
  KEY `idx_update` (`update_id`),
  KEY `idx_portal_access` (`portal_access_id`),
  CONSTRAINT `portal_update_reads_ibfk_1` FOREIGN KEY (`update_id`) REFERENCES `portal_updates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `portal_update_reads_ibfk_2` FOREIGN KEY (`portal_access_id`) REFERENCES `portal_access` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_updates`
--

DROP TABLE IF EXISTS `portal_updates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_updates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `created_by` varchar(255) NOT NULL COMMENT 'Internal user who created the update',
  `title` varchar(500) NOT NULL,
  `content_html` text DEFAULT NULL,
  `content_text` text DEFAULT NULL,
  `update_type` enum('general','design','milestone','deliverable') DEFAULT 'general',
  `mood_board_id` int(11) DEFAULT NULL COMMENT 'Optional linked mood board',
  `mood_board_share_token` varchar(64) DEFAULT NULL,
  `drive_file_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array of drive file IDs' CHECK (json_valid(`drive_file_ids`)),
  `board_id` int(11) DEFAULT NULL,
  `board_card_id` int(11) DEFAULT NULL,
  `is_pinned` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_type` (`update_type`),
  KEY `idx_pinned` (`is_pinned`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_bookmarks`
--

DROP TABLE IF EXISTS `projecthub_bookmarks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_bookmarks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `folder_id` int(10) unsigned NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `url` varchar(2000) NOT NULL,
  `favicon_url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_folder` (`folder_id`),
  CONSTRAINT `projecthub_bookmarks_ibfk_1` FOREIGN KEY (`folder_id`) REFERENCES `projecthub_folders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_card_assignees`
--

DROP TABLE IF EXISTS `projecthub_card_assignees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_card_assignees` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `role` enum('assignee','reviewer','observer') DEFAULT 'assignee',
  `status` enum('assigned','working','review','done','blocked') DEFAULT 'assigned',
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `time_spent_seconds` int(10) unsigned DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `difficulty_weight` tinyint(3) unsigned DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_card_user` (`card_id`,`user_email`),
  KEY `idx_user` (`user_email`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_card_calendar_map`
--

DROP TABLE IF EXISTS `projecthub_card_calendar_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_card_calendar_map` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `calendar_event_id` int(10) unsigned DEFAULT NULL,
  `google_event_id` varchar(255) DEFAULT NULL,
  `calendar_id` varchar(255) DEFAULT NULL,
  `user_email` varchar(255) NOT NULL,
  `sync_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_card_user` (`card_id`,`user_email`),
  KEY `idx_card` (`card_id`),
  KEY `idx_user` (`user_email`),
  KEY `idx_google_event` (`google_event_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_card_dependencies`
--

DROP TABLE IF EXISTS `projecthub_card_dependencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_card_dependencies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `depends_on_card_id` int(11) NOT NULL,
  `type` enum('finish_to_start','start_to_start','finish_to_finish') DEFAULT 'finish_to_start',
  `created_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_dependency` (`card_id`,`depends_on_card_id`),
  KEY `idx_card` (`card_id`),
  KEY `idx_depends_on` (`depends_on_card_id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_card_tracked_urls`
--

DROP TABLE IF EXISTS `projecthub_card_tracked_urls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_card_tracked_urls` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `url_domain` varchar(255) NOT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `title_match` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_card_url` (`card_id`,`url_domain`),
  KEY `idx_card` (`card_id`),
  KEY `idx_domain` (`url_domain`),
  KEY `idx_active` (`is_active`),
  CONSTRAINT `projecthub_card_tracked_urls_ibfk_1` FOREIGN KEY (`card_id`) REFERENCES `webmail_board_cards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_comment_attachments`
--

DROP TABLE IF EXISTS `projecthub_comment_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_comment_attachments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `comment_id` int(11) NOT NULL,
  `type` enum('file','drive_file','drive_folder','url') DEFAULT 'file',
  `drive_file_id` int(10) unsigned DEFAULT NULL,
  `drive_folder_id` int(10) unsigned DEFAULT NULL,
  `url` varchar(2000) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_comment` (`comment_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_comment_reactions`
--

DROP TABLE IF EXISTS `projecthub_comment_reactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_comment_reactions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `comment_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `emoji` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_reaction` (`comment_id`,`user_email`,`emoji`),
  KEY `idx_comment` (`comment_id`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_comment_reads`
--

DROP TABLE IF EXISTS `projecthub_comment_reads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_comment_reads` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `last_read_comment_id` int(10) unsigned DEFAULT NULL,
  `last_read_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_card_user` (`card_id`,`user_email`)
) ENGINE=InnoDB AUTO_INCREMENT=754 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_folder_boards`
--

DROP TABLE IF EXISTS `projecthub_folder_boards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_folder_boards` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `folder_id` int(10) unsigned NOT NULL,
  `board_id` int(11) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_folder_board` (`folder_id`,`board_id`),
  KEY `idx_board` (`board_id`),
  CONSTRAINT `projecthub_folder_boards_ibfk_1` FOREIGN KEY (`folder_id`) REFERENCES `projecthub_folders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_folder_file_views`
--

DROP TABLE IF EXISTS `projecthub_folder_file_views`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_folder_file_views` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `folder_id` int(10) unsigned NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `last_seen_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_folder_user` (`folder_id`,`user_email`),
  KEY `idx_folder` (`folder_id`)
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_folder_files`
--

DROP TABLE IF EXISTS `projecthub_folder_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_folder_files` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `folder_id` int(10) unsigned NOT NULL,
  `drive_file_id` int(10) unsigned NOT NULL,
  `group_name` varchar(50) NOT NULL DEFAULT 'General',
  `added_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_folder` (`folder_id`),
  KEY `idx_drive_file` (`drive_file_id`),
  KEY `idx_folder_group` (`folder_id`,`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_folder_links`
--

DROP TABLE IF EXISTS `projecthub_folder_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_folder_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `folder_id` int(10) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `url` text NOT NULL,
  `link_type` varchar(30) NOT NULL DEFAULT 'url',
  `group_name` varchar(50) DEFAULT NULL,
  `added_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_folder` (`folder_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_folders`
--

DROP TABLE IF EXISTS `projecthub_folders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_folders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `space_id` int(10) unsigned NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `color` varchar(20) DEFAULT NULL,
  `icon` varchar(50) DEFAULT 'folder',
  `sort_order` int(11) DEFAULT 0,
  `archived` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_space` (`space_id`),
  KEY `idx_sort` (`sort_order`),
  CONSTRAINT `projecthub_folders_ibfk_1` FOREIGN KEY (`space_id`) REFERENCES `projecthub_spaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_notification_prefs`
--

DROP TABLE IF EXISTS `projecthub_notification_prefs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_notification_prefs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `notif_type` varchar(50) NOT NULL,
  `channel_inapp` tinyint(1) NOT NULL DEFAULT 1,
  `channel_push` tinyint(1) NOT NULL DEFAULT 1,
  `channel_email` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_type` (`user_email`,`notif_type`),
  KEY `idx_user` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_role_statuses`
--

DROP TABLE IF EXISTS `projecthub_role_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_role_statuses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int(10) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `color` varchar(20) DEFAULT '#6b7280',
  `icon` varchar(50) DEFAULT 'circle',
  `is_terminal` tinyint(1) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_role_status` (`role_id`,`slug`),
  KEY `idx_sort` (`role_id`,`sort_order`),
  CONSTRAINT `projecthub_role_statuses_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `projecthub_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_roles`
--

DROP TABLE IF EXISTS `projecthub_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `color` varchar(20) DEFAULT '#6366f1',
  `icon` varchar(50) DEFAULT 'badge',
  `description` varchar(500) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_slug` (`slug`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_space_members`
--

DROP TABLE IF EXISTS `projecthub_space_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_space_members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `space_id` int(10) unsigned NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `role` varchar(20) DEFAULT 'member',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_space_member` (`space_id`,`user_email`),
  CONSTRAINT `projecthub_space_members_ibfk_1` FOREIGN KEY (`space_id`) REFERENCES `projecthub_spaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_spaces`
--

DROP TABLE IF EXISTS `projecthub_spaces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_spaces` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `color` varchar(20) DEFAULT '#6366f1',
  `icon` varchar(50) DEFAULT 'folder_special',
  `is_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `archived` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `client_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_space_name` (`user_email`,`name`),
  KEY `idx_user` (`user_email`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_subtask_card_links`
--

DROP TABLE IF EXISTS `projecthub_subtask_card_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_subtask_card_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent_card_id` int(11) NOT NULL,
  `subtask_card_id` int(11) NOT NULL,
  `linked_card_id` int(11) NOT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subtask_card` (`subtask_card_id`),
  UNIQUE KEY `uq_linked_card` (`linked_card_id`),
  KEY `idx_parent_card` (`parent_card_id`),
  KEY `idx_linked_card` (`linked_card_id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_user_roles`
--

DROP TABLE IF EXISTS `projecthub_user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_user_roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  `assigned_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_role` (`user_email`,`role_id`),
  KEY `idx_user` (`user_email`),
  KEY `idx_role` (`role_id`),
  CONSTRAINT `projecthub_user_roles_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `projecthub_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_watchers`
--

DROP TABLE IF EXISTS `projecthub_watchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_watchers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_card_watcher` (`card_id`,`user_email`),
  KEY `idx_card` (`card_id`),
  KEY `idx_user` (`user_email`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projecthub_work_sessions`
--

DROP TABLE IF EXISTS `projecthub_work_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projecthub_work_sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `source` enum('manual','drive_edit','board_view','timer','card_view','website_work','portal_call','calendar_event','local_watch') DEFAULT 'manual',
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(10) unsigned DEFAULT NULL,
  `entity_name` varchar(255) DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `duration_seconds` int(10) unsigned DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_card_user` (`card_id`,`user_email`),
  KEY `idx_user` (`user_email`),
  KEY `idx_started` (`started_at`)
) ENGINE=InnoDB AUTO_INCREMENT=422 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `push_subscriptions`
--

DROP TABLE IF EXISTS `push_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `push_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `endpoint` text NOT NULL,
  `p256dh` varchar(512) NOT NULL,
  `auth` varchar(255) NOT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_endpoint` (`endpoint`(500)),
  KEY `idx_user_email` (`user_email`)
) ENGINE=InnoDB AUTO_INCREMENT=687 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary table structure for view `records`
--

DROP TABLE IF EXISTS `records`;
/*!50001 DROP VIEW IF EXISTS `records`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `records` AS SELECT
 1 AS `id`,
  1 AS `domain_id`,
  1 AS `name`,
  1 AS `type`,
  1 AS `content`,
  1 AS `ttl`,
  1 AS `prio`,
  1 AS `disabled`,
  1 AS `ordername`,
  1 AS `auth` */;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `scheduled_emails`
--

DROP TABLE IF EXISTS `scheduled_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scheduled_emails` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` varchar(36) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `email_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`email_payload`)),
  `scheduled_at` timestamp NOT NULL,
  `timezone` varchar(64) DEFAULT 'UTC',
  `schedule_kind` enum('scheduled_send','undo_send') NOT NULL DEFAULT 'scheduled_send',
  `status` enum('pending','sending','sent','failed','cancelled') DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `attempts` int(10) unsigned DEFAULT 0,
  `max_attempts` int(10) unsigned DEFAULT 3,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `schedule_id` (`schedule_id`),
  KEY `idx_user_status` (`user_email`,`status`),
  KEY `idx_status_scheduled` (`status`,`scheduled_at`),
  KEY `idx_schedule_id` (`schedule_id`)
) ENGINE=InnoDB AUTO_INCREMENT=164 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(64) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `last_activity` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `location` varchar(100) DEFAULT NULL,
  `device_name` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `site_applications`
--

DROP TABLE IF EXISTS `site_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `site_applications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) NOT NULL,
  `app_slug` varchar(50) NOT NULL,
  `app_version` varchar(20) DEFAULT NULL,
  `install_path` varchar(500) NOT NULL,
  `admin_url` varchar(255) DEFAULT NULL,
  `admin_user` varchar(100) DEFAULT NULL,
  `database_name` varchar(100) DEFAULT NULL,
  `installed_at` timestamp NULL DEFAULT current_timestamp(),
  `installed_by` int(10) unsigned DEFAULT NULL,
  `status` enum('active','updating','failed') DEFAULT 'active',
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_domain` (`domain`),
  KEY `idx_app_slug` (`app_slug`),
  KEY `installed_by` (`installed_by`),
  CONSTRAINT `site_applications_ibfk_1` FOREIGN KEY (`installed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ssl_check_results`
--

DROP TABLE IF EXISTS `ssl_check_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ssl_check_results` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) NOT NULL,
  `grade` varchar(5) NOT NULL,
  `score` int(11) NOT NULL DEFAULT 0,
  `protocols` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`protocols`)),
  `ciphers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ciphers`)),
  `vulnerabilities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`vulnerabilities`)),
  `certificate` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`certificate`)),
  `security_headers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`security_headers`)),
  `deductions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`deductions`)),
  `scan_duration` decimal(8,2) DEFAULT NULL,
  `scanned_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_domain` (`domain`),
  KEY `idx_grade` (`grade`),
  KEY `idx_scanned_at` (`scanned_at`)
) ENGINE=InnoDB AUTO_INCREMENT=86 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sso_codes`
--

DROP TABLE IF EXISTS `sso_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sso_codes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(16) NOT NULL,
  `nonce` varchar(16) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_code` (`code`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sso_seeds`
--

DROP TABLE IF EXISTS `sso_seeds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sso_seeds` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `seed_id` varchar(64) NOT NULL,
  `seed_secret_hmac` varchar(128) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `revoked` tinyint(1) NOT NULL DEFAULT 0,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_seed_id` (`seed_id`),
  KEY `idx_user_active` (`user_email`,`revoked`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=103 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sso_user_state`
--

DROP TABLE IF EXISTS `sso_user_state`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sso_user_state` (
  `user_email` varchar(255) NOT NULL,
  `logout_epoch` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `template_deployments`
--

DROP TABLE IF EXISTS `template_deployments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `template_deployments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) NOT NULL,
  `template_type` enum('site_placeholder','site_coming_soon','site_maintenance') NOT NULL,
  `deployed_at` timestamp NULL DEFAULT current_timestamp(),
  `deployed_by` varchar(50) DEFAULT NULL,
  `backup_file` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_domain` (`domain`),
  KEY `idx_domain` (`domain`),
  KEY `idx_template_type` (`template_type`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `trusted_devices`
--

DROP TABLE IF EXISTS `trusted_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trusted_devices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `device_name` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_user` (`user_id`),
  KEY `idx_token` (`token_hash`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `universal_search_index`
--

DROP TABLE IF EXISTS `universal_search_index`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `universal_search_index` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `source_type` enum('email','email_attachment','calendar_event','drive_file','drive_folder','board','card','checklist_item','todo','client','contact','collab_doc','chat_message','mood_board_item') NOT NULL,
  `source_id` varchar(255) NOT NULL,
  `title` varchar(500) DEFAULT NULL,
  `content_text` longtext DEFAULT NULL,
  `content_snippet` varchar(1000) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `client_name` varchar(255) DEFAULT NULL,
  `board_id` int(11) DEFAULT NULL,
  `board_name` varchar(255) DEFAULT NULL,
  `folder_id` int(11) DEFAULT NULL,
  `folder_name` varchar(255) DEFAULT NULL,
  `list_id` int(11) DEFAULT NULL,
  `list_name` varchar(255) DEFAULT NULL,
  `source_date` datetime DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `extra_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extra_data`)),
  `indexed_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_source` (`user_email`,`source_type`,`source_id`),
  KEY `idx_user` (`user_email`),
  KEY `idx_client` (`user_email`,`client_id`),
  KEY `idx_board` (`user_email`,`board_id`),
  KEY `idx_date` (`source_date`),
  KEY `idx_type` (`user_email`,`source_type`),
  KEY `idx_title` (`title`(100)),
  FULLTEXT KEY `ft_search` (`title`,`content_text`)
) ENGINE=InnoDB AUTO_INCREMENT=117727 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_accounts`
--

DROP TABLE IF EXISTS `user_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `account_email` varchar(255) NOT NULL,
  `account_name` varchar(255) DEFAULT NULL,
  `imap_host` varchar(255) NOT NULL DEFAULT 'localhost',
  `imap_port` int(11) NOT NULL DEFAULT 993,
  `imap_encryption` varchar(10) DEFAULT 'ssl',
  `smtp_host` varchar(255) NOT NULL DEFAULT 'localhost',
  `smtp_port` int(11) NOT NULL DEFAULT 587,
  `smtp_encryption` varchar(10) DEFAULT 'tls',
  `password` text NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_account` (`user_email`,`account_email`),
  KEY `idx_user_email` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_sites`
--

DROP TABLE IF EXISTS `user_sites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_sites` (
  `user_id` int(10) unsigned NOT NULL,
  `domain` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`domain`),
  CONSTRAINT `user_sites_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vpn_connections`
--

DROP TABLE IF EXISTS `vpn_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vpn_connections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `config_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `server_address` varchar(255) DEFAULT NULL,
  `server_port` int(11) DEFAULT 1194,
  `protocol` enum('udp','tcp') DEFAULT 'udp',
  `status` enum('connected','disconnected','connecting','error') DEFAULT 'disconnected',
  `local_ip` varchar(45) DEFAULT NULL,
  `remote_ip` varchar(45) DEFAULT NULL,
  `connected_at` timestamp NULL DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `auto_start` tinyint(1) DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_name` (`name`),
  KEY `idx_status` (`status`),
  KEY `idx_config_name` (`config_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `watch_folder_activity`
--

DROP TABLE IF EXISTS `watch_folder_activity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `watch_folder_activity` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `watch_folder_id` int(10) unsigned NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) DEFAULT NULL COMMENT 'Path relative to watch folder root',
  `duration_seconds` int(10) unsigned DEFAULT 0,
  `client_id` int(10) unsigned DEFAULT NULL,
  `board_id` int(10) unsigned DEFAULT NULL,
  `card_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_watch_folder` (`watch_folder_id`),
  KEY `idx_card` (`card_id`),
  KEY `idx_board` (`board_id`),
  KEY `idx_user` (`user_email`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `watch_folder_path_overrides`
--

DROP TABLE IF EXISTS `watch_folder_path_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `watch_folder_path_overrides` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `match_prefix` varchar(500) NOT NULL COMMENT 'Office prefix to match e.g. Z:\\',
  `replace_prefix` varchar(500) NOT NULL COMMENT 'Local prefix e.g. \\\\192.168.1.106\\share',
  `label` varchar(100) DEFAULT NULL COMMENT 'e.g. Home, VPN, Laptop',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_prefix` (`user_email`,`match_prefix`),
  KEY `idx_user` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `watch_folders`
--

DROP TABLE IF EXISTS `watch_folders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `watch_folders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `creator_email` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `folder_path` varchar(500) NOT NULL COMMENT 'Full canonical path e.g. Z:\\Clients\\BV Boros\\Design',
  `client_id` int(10) unsigned NOT NULL,
  `board_id` int(10) unsigned DEFAULT NULL,
  `card_id` int(10) unsigned DEFAULT NULL,
  `scope` enum('shared') DEFAULT 'shared',
  `assigned_emails` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'null = visible to all board members' CHECK (json_valid(`assigned_emails`)),
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_creator` (`creator_email`),
  KEY `idx_client` (`client_id`),
  KEY `idx_board` (`board_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_2fa`
--

DROP TABLE IF EXISTS `webmail_2fa`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_2fa` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `secret` varchar(64) DEFAULT NULL,
  `enabled` tinyint(4) DEFAULT 0,
  `backup_codes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_2fa_trusted_devices`
--

DROP TABLE IF EXISTS `webmail_2fa_trusted_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_2fa_trusted_devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `device_token_hash` varchar(255) NOT NULL,
  `device_name` varchar(255) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL,
  `last_used_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_token_hash` (`device_token_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=235 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_accounts`
--

DROP TABLE IF EXISTS `webmail_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `primary_email` varchar(255) NOT NULL COMMENT 'The main login email (owner)',
  `account_email` varchar(255) NOT NULL COMMENT 'The linked account email',
  `display_name` varchar(255) DEFAULT NULL,
  `imap_host` varchar(255) NOT NULL,
  `imap_port` int(11) DEFAULT 993,
  `imap_encryption` enum('ssl','tls','none') DEFAULT 'ssl',
  `smtp_host` varchar(255) NOT NULL,
  `smtp_port` int(11) DEFAULT 587,
  `smtp_encryption` enum('ssl','tls','none') DEFAULT 'tls',
  `credentials_encrypted` text NOT NULL COMMENT 'AES-encrypted password',
  `is_default` tinyint(1) DEFAULT 0,
  `account_type` enum('separate','linked') DEFAULT 'separate',
  `sync_frequency` int(11) DEFAULT 15,
  `leave_on_server` tinyint(1) DEFAULT 1,
  `auto_label` varchar(255) DEFAULT NULL,
  `signature` text DEFAULT NULL,
  `sync_enabled` tinyint(1) DEFAULT 1,
  `last_sync` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account` (`primary_email`,`account_email`),
  KEY `idx_primary_email` (`primary_email`),
  KEY `idx_account_type` (`account_type`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_blocked_senders`
--

DROP TABLE IF EXISTS `webmail_blocked_senders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_blocked_senders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `blocked_email` varchar(255) NOT NULL,
  `blocked_domain` varchar(255) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_block` (`user_email`,`blocked_email`),
  KEY `idx_user` (`user_email`)
) ENGINE=InnoDB AUTO_INCREMENT=118 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_board_cards`
--

DROP TABLE IF EXISTS `webmail_board_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_board_cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `list_id` int(11) NOT NULL,
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `position` int(11) DEFAULT 0,
  `due_date` datetime DEFAULT NULL,
  `start_date` datetime DEFAULT NULL,
  `completed` tinyint(1) DEFAULT 0,
  `completed_at` timestamp NULL DEFAULT NULL,
  `cover_color` varchar(20) DEFAULT NULL,
  `cover_image_id` int(11) DEFAULT NULL,
  `card_color` varchar(7) DEFAULT NULL,
  `calendar_event_id` int(11) DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `assigned_to` varchar(255) DEFAULT NULL,
  `archived` tinyint(1) DEFAULT 0,
  `time_estimate_seconds` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `parent_card_id` int(11) DEFAULT NULL,
  `full_task_visibility` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_list_id` (`list_id`),
  KEY `idx_position` (`position`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_parent_card` (`parent_card_id`),
  CONSTRAINT `webmail_board_cards_ibfk_1` FOREIGN KEY (`list_id`) REFERENCES `webmail_board_lists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=299 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_board_emails`
--

DROP TABLE IF EXISTS `webmail_board_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_board_emails` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `email_uid` int(11) NOT NULL,
  `email_folder` varchar(255) NOT NULL,
  `email_subject` varchar(500) DEFAULT NULL,
  `email_from` varchar(255) DEFAULT NULL,
  `thread_id` varchar(255) DEFAULT NULL,
  `linked_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_board_id` (`board_id`),
  KEY `idx_email` (`email_uid`,`email_folder`),
  KEY `idx_thread` (`thread_id`),
  CONSTRAINT `webmail_board_emails_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `webmail_boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_board_labels`
--

DROP TABLE IF EXISTS `webmail_board_labels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_board_labels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `color` varchar(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `is_type` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_board_id` (`board_id`),
  CONSTRAINT `webmail_board_labels_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `webmail_boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=169 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_board_lists`
--

DROP TABLE IF EXISTS `webmail_board_lists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_board_lists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `position` int(11) DEFAULT 0,
  `archived` tinyint(1) DEFAULT 0,
  `collapsed` tinyint(1) NOT NULL DEFAULT 0,
  `list_color` varchar(7) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `expected_amount` decimal(12,2) DEFAULT NULL,
  `invoice_date` date DEFAULT NULL,
  `is_milestone` tinyint(1) DEFAULT 0,
  `currency` varchar(3) DEFAULT 'HUF',
  `payment_status` varchar(20) DEFAULT 'unpaid',
  PRIMARY KEY (`id`),
  KEY `idx_board_id` (`board_id`),
  KEY `idx_position` (`position`),
  CONSTRAINT `webmail_board_lists_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `webmail_boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=112 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_board_members`
--

DROP TABLE IF EXISTS `webmail_board_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_board_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `role` enum('owner','editor','viewer') DEFAULT 'viewer',
  `member_type` enum('internal','guest') NOT NULL DEFAULT 'internal',
  `invited_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `can_view_financials` tinyint(1) DEFAULT 0,
  `can_view_client` tinyint(1) DEFAULT 0,
  `can_view_contacts` tinyint(1) DEFAULT 0,
  `can_view_emails` tinyint(1) DEFAULT 0,
  `can_access_drive` tinyint(1) DEFAULT 0,
  `drive_folder_id` int(11) DEFAULT NULL,
  `drive_permission` enum('viewer','editor') DEFAULT 'viewer',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_board_member` (`board_id`,`user_email`),
  KEY `idx_user_email` (`user_email`),
  CONSTRAINT `webmail_board_members_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `webmail_boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_board_progress_reports`
--

DROP TABLE IF EXISTS `webmail_board_progress_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_board_progress_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `sent_by` varchar(255) NOT NULL,
  `sent_to` varchar(500) NOT NULL,
  `subject` varchar(500) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `cards_included` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cards_included`)),
  `sent_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_board_id` (`board_id`),
  KEY `idx_sent_at` (`sent_at`),
  CONSTRAINT `webmail_board_progress_reports_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `webmail_boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_boards`
--

DROP TABLE IF EXISTS `webmail_boards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_boards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_email` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `background_color` varchar(20) DEFAULT '#1e1e26',
  `background_image` varchar(500) DEFAULT NULL,
  `archived` tinyint(1) DEFAULT 0,
  `is_closed` tinyint(1) DEFAULT 0,
  `closed_at` datetime DEFAULT NULL,
  `closed_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `background_blur` int(11) DEFAULT 0,
  `background_overlay_color` varchar(20) DEFAULT NULL,
  `background_overlay_opacity` int(11) DEFAULT 0,
  `payment_terms_days` int(11) DEFAULT NULL,
  `client_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`owner_email`),
  KEY `idx_archived` (`archived`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_card_activity`
--

DROP TABLE IF EXISTS `webmail_card_activity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_card_activity` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `action` varchar(50) NOT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_card_id` (`card_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `webmail_card_activity_ibfk_1` FOREIGN KEY (`card_id`) REFERENCES `webmail_board_cards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=425 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_card_attachments`
--

DROP TABLE IF EXISTS `webmail_card_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_card_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `drive_file_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `url` varchar(1000) DEFAULT NULL,
  `is_cover` tinyint(1) DEFAULT 0,
  `created_by` varchar(255) DEFAULT NULL,
  `folder_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_card_id` (`card_id`),
  KEY `idx_drive_file_id` (`drive_file_id`),
  KEY `idx_folder_id` (`folder_id`),
  CONSTRAINT `webmail_card_attachments_ibfk_1` FOREIGN KEY (`card_id`) REFERENCES `webmail_board_cards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=96 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_card_checklists`
--

DROP TABLE IF EXISTS `webmail_card_checklists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_card_checklists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `position` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_card_id` (`card_id`),
  CONSTRAINT `webmail_card_checklists_ibfk_1` FOREIGN KEY (`card_id`) REFERENCES `webmail_board_cards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=116 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_card_comments`
--

DROP TABLE IF EXISTS `webmail_card_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_card_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `parent_comment_id` int(11) DEFAULT NULL,
  `edited_at` timestamp NULL DEFAULT NULL,
  `mentions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`mentions`)),
  PRIMARY KEY (`id`),
  KEY `idx_card_id` (`card_id`),
  CONSTRAINT `webmail_card_comments_ibfk_1` FOREIGN KEY (`card_id`) REFERENCES `webmail_board_cards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_card_labels`
--

DROP TABLE IF EXISTS `webmail_card_labels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_card_labels` (
  `card_id` int(11) NOT NULL,
  `label_id` int(11) NOT NULL,
  PRIMARY KEY (`card_id`,`label_id`),
  KEY `label_id` (`label_id`),
  CONSTRAINT `webmail_card_labels_ibfk_1` FOREIGN KEY (`card_id`) REFERENCES `webmail_board_cards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `webmail_card_labels_ibfk_2` FOREIGN KEY (`label_id`) REFERENCES `webmail_board_labels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_checklist_items`
--

DROP TABLE IF EXISTS `webmail_checklist_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_checklist_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `checklist_id` int(11) NOT NULL,
  `title` text NOT NULL,
  `completed` tinyint(1) DEFAULT 0,
  `position` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `drive_file_id` int(11) DEFAULT NULL,
  `assigned_to` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_checklist_id` (`checklist_id`),
  CONSTRAINT `webmail_checklist_items_ibfk_1` FOREIGN KEY (`checklist_id`) REFERENCES `webmail_card_checklists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=462 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_client_time_tracking`
--

DROP TABLE IF EXISTS `webmail_client_time_tracking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_client_time_tracking` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `client_id` int(10) unsigned NOT NULL,
  `activity_type` enum('email_read','email_compose','calendar_event','board_view','board_task','drive_browse','document_open','document_edit','website_work','mood_board_view','mood_board_edit','client_call','manual_entry') NOT NULL,
  `entity_id` varchar(255) DEFAULT NULL,
  `entity_name` varchar(500) DEFAULT NULL,
  `source` enum('cloud','local_watch') DEFAULT 'cloud',
  `duration_seconds` int(11) NOT NULL DEFAULT 0,
  `tracked_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_track` (`user_email`,`client_id`,`activity_type`,`entity_id`,`tracked_date`),
  KEY `idx_user_client_date` (`user_email`,`client_id`,`tracked_date`),
  KEY `idx_client_date` (`client_id`,`tracked_date`),
  KEY `idx_activity` (`activity_type`)
) ENGINE=InnoDB AUTO_INCREMENT=6712 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_contact_stats`
--

DROP TABLE IF EXISTS `webmail_contact_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_contact_stats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `contact_email` varchar(255) NOT NULL,
  `contact_name` varchar(255) DEFAULT NULL,
  `emails_sent` int(11) NOT NULL DEFAULT 0,
  `emails_received` int(11) NOT NULL DEFAULT 0,
  `last_contact` timestamp NULL DEFAULT NULL,
  `avg_reply_time_seconds` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_contact` (`user_email`,`contact_email`),
  KEY `idx_user` (`user_email`),
  KEY `idx_frequency` (`user_email`,`emails_sent`,`emails_received`)
) ENGINE=InnoDB AUTO_INCREMENT=28948 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_conversation_members`
--

DROP TABLE IF EXISTS `webmail_conversation_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_conversation_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(191) NOT NULL,
  `conversation_id` varchar(64) NOT NULL,
  `message_id` varchar(512) NOT NULL,
  `message_id_hash` varchar(32) NOT NULL,
  `folder` varchar(191) NOT NULL,
  `uid` int(11) NOT NULL DEFAULT 0,
  `subject` varchar(512) DEFAULT NULL,
  `from_email` varchar(255) DEFAULT NULL,
  `from_name` varchar(255) DEFAULT NULL,
  `message_date` datetime DEFAULT NULL,
  `has_attachment` tinyint(1) DEFAULT 0,
  `is_user_override` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_verified_at` timestamp NULL DEFAULT NULL COMMENT 'Last time this record was verified to exist in IMAP',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_msg` (`user_email`,`folder`,`message_id_hash`),
  KEY `idx_conv` (`user_email`,`conversation_id`),
  KEY `idx_folder` (`user_email`,`folder`),
  KEY `idx_msgid` (`message_id_hash`),
  KEY `idx_verified` (`user_email`,`last_verified_at`),
  KEY `idx_msg_hash` (`user_email`,`message_id_hash`),
  KEY `idx_has_attachment` (`user_email`,`has_attachment`)
) ENGINE=InnoDB AUTO_INCREMENT=6036 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_conversations`
--

DROP TABLE IF EXISTS `webmail_conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_conversations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(191) NOT NULL,
  `conversation_id` varchar(64) NOT NULL,
  `folder` varchar(191) NOT NULL,
  `subject` varchar(512) DEFAULT NULL,
  `message_count` int(11) DEFAULT 0,
  `unread_count` int(11) DEFAULT 0,
  `has_attachment` tinyint(1) DEFAULT 0,
  `latest_date` datetime DEFAULT NULL,
  `latest_from` varchar(255) DEFAULT NULL,
  `latest_uid` int(11) DEFAULT 0,
  `latest_message_id` varchar(512) DEFAULT NULL,
  `snippet` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `normalized_subject` varchar(512) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_conv` (`user_email`,`conversation_id`),
  KEY `idx_folder` (`user_email`,`folder`),
  KEY `idx_latest` (`user_email`,`folder`,`latest_date`),
  KEY `idx_norm_subject` (`user_email`,`folder`,`normalized_subject`(100))
) ENGINE=InnoDB AUTO_INCREMENT=7764 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_devices`
--

DROP TABLE IF EXISTS `webmail_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL COMMENT 'User email',
  `device_id` varchar(255) NOT NULL COMMENT 'Unique device fingerprint (machine ID + hash)',
  `device_name` varchar(255) DEFAULT NULL COMMENT 'User-friendly device name',
  `platform` enum('web','desktop','drive') NOT NULL DEFAULT 'web' COMMENT 'App type',
  `os` varchar(100) DEFAULT NULL COMMENT 'Operating system',
  `app_version` varchar(50) DEFAULT NULL COMMENT 'App version',
  `status` enum('active','blocked','wipe_pending','wiped') NOT NULL DEFAULT 'active',
  `last_ip` varchar(45) DEFAULT NULL COMMENT 'Last known IP address',
  `last_seen_at` timestamp NULL DEFAULT current_timestamp() COMMENT 'Last activity from this device',
  `wipe_requested_at` timestamp NULL DEFAULT NULL COMMENT 'When wipe was requested',
  `wipe_confirmed_at` timestamp NULL DEFAULT NULL COMMENT 'When device confirmed wipe complete',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email_device` (`email`,`device_id`),
  KEY `idx_email` (`email`),
  KEY `idx_device_id` (`device_id`),
  KEY `idx_status` (`status`),
  KEY `idx_last_seen` (`last_seen_at`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_drive_sync_events`
--

DROP TABLE IF EXISTS `webmail_drive_sync_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_drive_sync_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `event_type` enum('file_created','file_updated','file_deleted','folder_created','folder_deleted') NOT NULL,
  `file_id` int(11) DEFAULT NULL,
  `folder_id` int(11) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `new_version` int(11) DEFAULT NULL,
  `modified_by` varchar(255) DEFAULT NULL,
  `source` varchar(50) DEFAULT 'web',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_email`,`created_at`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2941 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_email_attachments`
--

DROP TABLE IF EXISTS `webmail_email_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_email_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `folder` varchar(255) NOT NULL,
  `uid` int(11) NOT NULL,
  `message_id` varchar(512) DEFAULT NULL,
  `filename` varchar(500) NOT NULL,
  `part` varchar(50) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `size` int(11) DEFAULT 0,
  `from_email` varchar(255) DEFAULT NULL,
  `from_name` varchar(255) DEFAULT NULL,
  `subject` varchar(512) DEFAULT NULL,
  `message_date` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `content_indexed` tinyint(1) DEFAULT 0,
  `content_indexed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_attachment` (`user_email`,`folder`,`uid`,`filename`(255)),
  KEY `idx_user_email` (`user_email`),
  KEY `idx_filename` (`filename`(100)),
  KEY `idx_mime_type` (`mime_type`),
  KEY `idx_from_email` (`from_email`),
  KEY `idx_date` (`message_date`),
  KEY `idx_part` (`part`),
  KEY `idx_content_indexed` (`user_email`,`content_indexed`,`mime_type`),
  KEY `idx_indexing_queue` (`content_indexed`,`message_date` DESC)
) ENGINE=InnoDB AUTO_INCREMENT=466 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_filters`
--

DROP TABLE IF EXISTS `webmail_filters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_filters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `enabled` tinyint(1) DEFAULT 1,
  `priority` int(11) DEFAULT 0,
  `conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`conditions`)),
  `actions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`actions`)),
  `stop_processing` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB AUTO_INCREMENT=97 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_folder_counts`
--

DROP TABLE IF EXISTS `webmail_folder_counts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_folder_counts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(191) NOT NULL,
  `folder` varchar(191) NOT NULL,
  `total_count` int(11) DEFAULT 0,
  `unread_count` int(11) DEFAULT 0,
  `uidnext` int(11) DEFAULT 0,
  `uidvalidity` int(11) DEFAULT 0,
  `last_synced` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_folder` (`user_email`,`folder`),
  KEY `idx_user` (`user_email`),
  KEY `idx_last_synced` (`last_synced`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Server-authoritative folder counts for real-time sync. Clients should not manipulate counts locally.';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_folder_index`
--

DROP TABLE IF EXISTS `webmail_folder_index`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_folder_index` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(191) NOT NULL,
  `folder` varchar(191) NOT NULL,
  `is_indexed` tinyint(1) DEFAULT 0,
  `last_indexed_uid` int(11) DEFAULT 0,
  `message_count` int(11) DEFAULT 0,
  `indexed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `highest_modseq` bigint(20) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_folder` (`user_email`,`folder`)
) ENGINE=InnoDB AUTO_INCREMENT=1514 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_labels`
--

DROP TABLE IF EXISTS `webmail_labels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_labels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `color` varchar(7) DEFAULT '#3b82f6',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_label` (`email`,`name`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_message_labels`
--

DROP TABLE IF EXISTS `webmail_message_labels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_message_labels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `message_id` varchar(512) NOT NULL,
  `label_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_message_label` (`email`,`message_id`,`label_id`),
  KEY `idx_email` (`email`),
  KEY `idx_message_id` (`message_id`),
  KEY `label_id` (`label_id`),
  CONSTRAINT `webmail_message_labels_ibfk_1` FOREIGN KEY (`label_id`) REFERENCES `webmail_labels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=158 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_oauth_tokens`
--

DROP TABLE IF EXISTS `webmail_oauth_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_oauth_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `primary_email` varchar(255) NOT NULL COMMENT 'The main login email (owner)',
  `oauth_email` varchar(255) NOT NULL COMMENT 'The Google account email',
  `provider` varchar(50) DEFAULT 'google',
  `access_token_encrypted` text NOT NULL,
  `refresh_token_encrypted` text NOT NULL,
  `token_expires_at` timestamp NOT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `account_type` enum('separate','linked') DEFAULT 'separate',
  `sync_frequency` int(11) DEFAULT 15,
  `leave_on_server` tinyint(1) DEFAULT 1,
  `auto_label` varchar(255) DEFAULT NULL,
  `signature` text DEFAULT NULL,
  `sync_enabled` tinyint(1) DEFAULT 1,
  `last_sync` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_oauth_account` (`primary_email`,`oauth_email`,`provider`),
  KEY `idx_primary_email` (`primary_email`),
  KEY `idx_provider` (`provider`)
) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_preference_stats`
--

DROP TABLE IF EXISTS `webmail_preference_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_preference_stats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `preference_type` varchar(50) NOT NULL,
  `preference_value` varchar(100) NOT NULL,
  `usage_count` int(11) NOT NULL DEFAULT 1,
  `last_used` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_pref` (`user_email`,`preference_type`,`preference_value`),
  KEY `idx_user_type` (`user_email`,`preference_type`)
) ENGINE=InnoDB AUTO_INCREMENT=1276 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_reactions`
--

DROP TABLE IF EXISTS `webmail_reactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_reactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `message_id` varchar(500) NOT NULL,
  `reactor_email` varchar(255) NOT NULL,
  `reactor_name` varchar(255) DEFAULT NULL,
  `emoji` varchar(20) NOT NULL,
  `participants` text NOT NULL,
  `subject` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_reaction` (`message_id`(191),`reactor_email`(100),`emoji`),
  KEY `idx_message_id` (`message_id`(191)),
  KEY `idx_reactor` (`reactor_email`)
) ENGINE=InnoDB AUTO_INCREMENT=122 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_safe_senders`
--

DROP TABLE IF EXISTS `webmail_safe_senders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_safe_senders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `safe_email` varchar(255) NOT NULL,
  `safe_domain` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_safe` (`user_email`,`safe_email`),
  KEY `idx_user` (`user_email`)
) ENGINE=InnoDB AUTO_INCREMENT=143 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_sessions`
--

DROP TABLE IF EXISTS `webmail_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `device_id` varchar(255) DEFAULT NULL COMMENT 'Links to webmail_devices.device_id',
  `session_token_hash` varchar(255) NOT NULL,
  `encrypted_password` text DEFAULT NULL COMMENT 'AES-encrypted IMAP password, stored server-side instead of in JWT',
  `device_name` varchar(255) DEFAULT NULL,
  `browser` varchar(100) DEFAULT NULL,
  `os` varchar(100) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `is_current` tinyint(1) DEFAULT 0,
  `is_valid` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Whether session is still valid (for instant revocation)',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `last_active_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `expires_at` timestamp NOT NULL,
  `refresh_token_hash` varchar(128) DEFAULT NULL,
  `previous_refresh_token_hash` varchar(128) DEFAULT NULL,
  `refresh_rotated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_token_hash` (`session_token_hash`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_last_active` (`last_active_at`),
  KEY `idx_device_id` (`device_id`),
  KEY `idx_is_valid` (`is_valid`)
) ENGINE=InnoDB AUTO_INCREMENT=854 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_spam_settings`
--

DROP TABLE IF EXISTS `webmail_spam_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_spam_settings` (
  `user_email` varchar(255) NOT NULL,
  `auto_delete_days` int(11) DEFAULT 30,
  `auto_training_enabled` tinyint(1) DEFAULT 1,
  `spam_folder` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_spam_stats`
--

DROP TABLE IF EXISTS `webmail_spam_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_spam_stats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `action` enum('reported_spam','not_spam','blocked','unblocked','safe_added','safe_removed','auto_deleted') NOT NULL,
  `target_email` varchar(255) DEFAULT NULL,
  `message_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_date` (`user_email`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=306 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_statistics`
--

DROP TABLE IF EXISTS `webmail_statistics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_statistics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `stat_type` varchar(50) NOT NULL,
  `period` varchar(20) NOT NULL,
  `period_start` date NOT NULL,
  `value` double NOT NULL DEFAULT 0,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_stat` (`user_email`,`stat_type`,`period`,`period_start`),
  KEY `idx_user_type` (`user_email`,`stat_type`),
  KEY `idx_period` (`period`,`period_start`)
) ENGINE=InnoDB AUTO_INCREMENT=32133 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_stats_events`
--

DROP TABLE IF EXISTS `webmail_stats_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_stats_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `event_type` varchar(50) NOT NULL,
  `event_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`event_data`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_type` (`user_email`,`event_type`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4340 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_synced_messages`
--

DROP TABLE IF EXISTS `webmail_synced_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_synced_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL COMMENT 'FK to webmail_accounts',
  `source_uid` int(11) NOT NULL COMMENT 'UID in source mailbox',
  `source_folder` varchar(255) DEFAULT 'INBOX' COMMENT 'Source folder name',
  `message_id` varchar(512) NOT NULL COMMENT 'Message-ID header for matching',
  `local_uid` int(11) DEFAULT NULL COMMENT 'UID in local mailbox after copy',
  `synced_at` timestamp NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT 'When deleted locally (to sync back)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_message` (`account_id`,`source_folder`,`source_uid`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_source_uid` (`account_id`,`source_folder`,`source_uid`),
  KEY `idx_message_id` (`message_id`(255))
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_time_tracking`
--

DROP TABLE IF EXISTS `webmail_time_tracking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_time_tracking` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `section` varchar(50) NOT NULL,
  `folder` varchar(255) DEFAULT NULL,
  `duration_seconds` int(11) NOT NULL DEFAULT 0,
  `tracked_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_track` (`user_email`,`section`,`folder`,`tracked_date`),
  KEY `idx_user_date` (`user_email`,`tracked_date`),
  KEY `idx_section` (`section`)
) ENGINE=InnoDB AUTO_INCREMENT=10408 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webmail_todos`
--

DROP TABLE IF EXISTS `webmail_todos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webmail_todos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `completed` tinyint(1) DEFAULT 0,
  `priority` enum('low','normal','high') DEFAULT 'normal',
  `due_date` date DEFAULT NULL,
  `ref_folder` varchar(255) DEFAULT NULL,
  `ref_uid` int(11) DEFAULT NULL,
  `ref_message_id` varchar(500) DEFAULT NULL,
  `ref_subject` varchar(500) DEFAULT NULL,
  `ref_from` varchar(255) DEFAULT NULL,
  `ref_date` datetime DEFAULT NULL,
  `ref_selected_text` text DEFAULT NULL,
  `calendar_event_id` int(11) DEFAULT NULL,
  `position` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_completed` (`completed`),
  KEY `idx_ref_message_id` (`ref_message_id`(191)),
  KEY `idx_calendar_event_id` (`calendar_event_id`)
) ENGINE=InnoDB AUTO_INCREMENT=258 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Final view structure for view `domainmetadata`
--

/*!50001 DROP VIEW IF EXISTS `domainmetadata`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `domainmetadata` AS select `dns_domainmetadata`.`id` AS `id`,`dns_domainmetadata`.`domain_id` AS `domain_id`,`dns_domainmetadata`.`kind` AS `kind`,`dns_domainmetadata`.`content` AS `content` from `dns_domainmetadata` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `domains`
--

/*!50001 DROP VIEW IF EXISTS `domains`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `domains` AS select `dns_domains`.`id` AS `id`,`dns_domains`.`name` AS `name`,`dns_domains`.`master` AS `master`,`dns_domains`.`last_check` AS `last_check`,`dns_domains`.`type` AS `type`,`dns_domains`.`notified_serial` AS `notified_serial`,`dns_domains`.`account` AS `account` from `dns_domains` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `records`
--

/*!50001 DROP VIEW IF EXISTS `records`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `records` AS select `dns_records`.`id` AS `id`,`dns_records`.`domain_id` AS `domain_id`,`dns_records`.`name` AS `name`,`dns_records`.`type` AS `type`,`dns_records`.`content` AS `content`,`dns_records`.`ttl` AS `ttl`,`dns_records`.`prio` AS `prio`,`dns_records`.`disabled` AS `disabled`,`dns_records`.`ordername` AS `ordername`,`dns_records`.`auth` AS `auth` from `dns_records` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-04-27 10:18:17
