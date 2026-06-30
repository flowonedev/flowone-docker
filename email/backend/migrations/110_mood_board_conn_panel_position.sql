-- Connection settings panel horizontal position (percentage 0-100, default 70 = 70% from left)
ALTER TABLE mood_boards ADD COLUMN IF NOT EXISTS conn_panel_position TINYINT UNSIGNED DEFAULT 70;
