-- Migration 060: Persist motion/animation settings per mood board
-- Currently these are only in-memory and reset every time the board is opened

ALTER TABLE mood_boards
    ADD COLUMN IF NOT EXISTS motion_settings JSON DEFAULT NULL
    COMMENT 'Per-board motion/animation settings (enabled, cards, elements, lines, intensity, speed, etc.)';

