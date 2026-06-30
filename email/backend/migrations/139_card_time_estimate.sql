ALTER TABLE webmail_board_cards
  ADD COLUMN time_estimate_seconds INT UNSIGNED DEFAULT NULL AFTER archived;
