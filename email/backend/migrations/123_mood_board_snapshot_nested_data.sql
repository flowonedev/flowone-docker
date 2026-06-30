-- Add columns for nested item data (todos, image-set items) so snapshot restore is complete
ALTER TABLE mood_board_snapshots
  ADD COLUMN todos_json LONGTEXT DEFAULT NULL AFTER connections_json,
  ADD COLUMN image_set_json LONGTEXT DEFAULT NULL AFTER todos_json;
