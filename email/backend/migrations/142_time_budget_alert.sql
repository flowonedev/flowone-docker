ALTER TABLE webmail_board_cards ADD COLUMN time_budget_alert_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER time_estimate_seconds;
