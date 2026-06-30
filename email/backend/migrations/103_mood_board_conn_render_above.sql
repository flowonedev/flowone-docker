-- Per-connection toggle: render above items (1) or below items (0, default)
ALTER TABLE mood_board_connections ADD COLUMN IF NOT EXISTS render_above TINYINT(1) DEFAULT 0;
