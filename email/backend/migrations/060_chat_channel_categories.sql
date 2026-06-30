-- =====================================================
-- CHAT CHANNEL CATEGORIES
-- Migration: 060_chat_channel_categories.sql
--
-- Adds Discord-style channel categories for organizing
-- channels into named, collapsible groups.
-- =====================================================

CREATE TABLE IF NOT EXISTS chat_channel_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_domain VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    position INT UNSIGNED DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cat_domain (organization_domain),
    CONSTRAINT fk_cat_creator FOREIGN KEY (created_by)
        REFERENCES organization_colleagues(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add category and position columns to chat_conversations
ALTER TABLE chat_conversations
  ADD COLUMN category_id INT UNSIGNED NULL COMMENT 'FK to chat_channel_categories for channel grouping';

ALTER TABLE chat_conversations
  ADD COLUMN position INT UNSIGNED DEFAULT 0 COMMENT 'Sort order within a category';

ALTER TABLE chat_conversations
  ADD INDEX idx_conv_category (category_id);

ALTER TABLE chat_conversations
  ADD CONSTRAINT fk_conv_category FOREIGN KEY (category_id)
      REFERENCES chat_channel_categories(id) ON DELETE SET NULL;
