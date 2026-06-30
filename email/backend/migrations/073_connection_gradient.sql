-- Migration 073: Add gradient properties to mood board connections
-- Adds gradient_enabled, gradient_color_start, gradient_color_end columns

ALTER TABLE mood_board_connections
    ADD COLUMN IF NOT EXISTS gradient_enabled TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS gradient_color_start VARCHAR(20) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS gradient_color_end VARCHAR(20) DEFAULT NULL;

