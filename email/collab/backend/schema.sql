-- Collab System Database Schema
-- Run this on the server: mysql -u vpsadmin -p devc_vps_dash < schema.sql

-- Documents table
CREATE TABLE IF NOT EXISTS `collab_documents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` VARCHAR(36) NOT NULL,
  `owner_email` VARCHAR(255) NOT NULL,
  `title` VARCHAR(255) NOT NULL DEFAULT 'Untitled Document',
  `type` ENUM('document', 'presentation') NOT NULL DEFAULT 'document',
  `crdt_state` LONGBLOB NULL DEFAULT NULL COMMENT 'Y.js encoded state',
  `folder_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Drive folder ID where this doc was created',
  `drive_file_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Linked drive file ID',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `idx_owner` (`owner_email`),
  KEY `idx_deleted` (`deleted_at`),
  KEY `idx_updated` (`updated_at`),
  KEY `idx_folder` (`folder_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration: Add folder_id column if it doesn't exist
-- ALTER TABLE `collab_documents` ADD COLUMN `folder_id` INT UNSIGNED NULL DEFAULT NULL AFTER `crdt_state`;
-- ALTER TABLE `collab_documents` ADD KEY `idx_folder` (`folder_id`);

-- Permissions table
CREATE TABLE IF NOT EXISTS `collab_permissions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id` INT UNSIGNED NOT NULL,
  `user_email` VARCHAR(255) NOT NULL,
  `role` ENUM('owner', 'editor', 'viewer') NOT NULL DEFAULT 'viewer',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_user` (`document_id`, `user_email`),
  KEY `idx_user` (`user_email`),
  CONSTRAINT `fk_perm_document` FOREIGN KEY (`document_id`) REFERENCES `collab_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Versions table (for snapshots)
CREATE TABLE IF NOT EXISTS `collab_versions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id` INT UNSIGNED NOT NULL,
  `version_number` INT UNSIGNED NOT NULL,
  `version_name` VARCHAR(255) NULL DEFAULT NULL,
  `crdt_state` LONGBLOB NOT NULL,
  `created_by` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_version` (`document_id`, `version_number`),
  CONSTRAINT `fk_version_document` FOREIGN KEY (`document_id`) REFERENCES `collab_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comments table (optional, for document comments)
CREATE TABLE IF NOT EXISTS `collab_comments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id` INT UNSIGNED NOT NULL,
  `comment_id` VARCHAR(36) NOT NULL COMMENT 'Client-generated ID for Y.js sync',
  `user_email` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `range_start` INT NULL DEFAULT NULL,
  `range_end` INT NULL DEFAULT NULL,
  `resolved` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_comment` (`document_id`, `comment_id`),
  KEY `idx_document` (`document_id`),
  CONSTRAINT `fk_comment_document` FOREIGN KEY (`document_id`) REFERENCES `collab_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

