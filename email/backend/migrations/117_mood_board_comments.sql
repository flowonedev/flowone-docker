-- Migration 117: Mood Board Comments
-- Adds commenting system for mood boards (internal + public/shared)

CREATE TABLE IF NOT EXISTS `mood_board_comments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `board_id` INT(11) NOT NULL,
  `item_id` INT(11) DEFAULT NULL COMMENT 'NULL = board-level comment, otherwise pinned to an item',
  `thread_id` CHAR(36) NOT NULL COMMENT 'Groups replies into threads',
  `parent_id` INT(11) DEFAULT NULL COMMENT 'For nested replies within a thread',
  `author_email` VARCHAR(255) DEFAULT NULL COMMENT 'NULL for anonymous public commenters',
  `author_name` VARCHAR(255) NOT NULL,
  `author_avatar_color` VARCHAR(7) DEFAULT NULL COMMENT 'Hex color for avatar circle',
  `content` TEXT NOT NULL,
  `pin_x` DECIMAL(10,4) DEFAULT NULL COMMENT 'Relative X position on item (0-1)',
  `pin_y` DECIMAL(10,4) DEFAULT NULL COMMENT 'Relative Y position on item (0-1)',
  `is_public` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=from public share link, 0=from internal user',
  `share_token` VARCHAR(64) DEFAULT NULL COMMENT 'Which share link was used (if public)',
  `resolved_at` TIMESTAMP NULL DEFAULT NULL,
  `resolved_by` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete',
  PRIMARY KEY (`id`),
  KEY `idx_mbc_board` (`board_id`),
  KEY `idx_mbc_item` (`item_id`),
  KEY `idx_mbc_thread` (`thread_id`),
  KEY `idx_mbc_parent` (`parent_id`),
  KEY `idx_mbc_deleted` (`deleted_at`),
  CONSTRAINT `fk_mbc_board` FOREIGN KEY (`board_id`) REFERENCES `mood_boards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mbc_parent` FOREIGN KEY (`parent_id`) REFERENCES `mood_board_comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add allow_comments + notify_on_comment to mood_boards table
ALTER TABLE `mood_boards`
  ADD COLUMN `allow_comments` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Whether public viewers can comment' AFTER `share_expires`,
  ADD COLUMN `notify_on_comment` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Email owner on new comments' AFTER `allow_comments`;
