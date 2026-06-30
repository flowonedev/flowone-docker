ALTER TABLE webmail_board_cards
    ADD COLUMN IF NOT EXISTS full_task_visibility TINYINT(1) NOT NULL DEFAULT 0;
