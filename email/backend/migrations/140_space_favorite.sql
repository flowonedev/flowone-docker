ALTER TABLE projecthub_spaces
  ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0 AFTER icon;
