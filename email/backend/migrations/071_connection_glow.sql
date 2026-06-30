-- Migration 071: Add glow properties to mood board connections
-- Adds glow_enabled, glow_color, glow_opacity, glow_blur columns

ALTER TABLE mood_board_connections
    ADD COLUMN IF NOT EXISTS glow_enabled TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS glow_color VARCHAR(20) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS glow_opacity TINYINT UNSIGNED DEFAULT 60,
    ADD COLUMN IF NOT EXISTS glow_blur TINYINT UNSIGNED DEFAULT 6;

