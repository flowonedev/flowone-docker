-- Soft delete support for mood board items
-- Instead of hard DELETE, items get deleted_at set to NOW()
-- All SELECT queries filter by deleted_at IS NULL

ALTER TABLE mood_board_items ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE mood_board_items ADD INDEX idx_mbi_deleted (deleted_at);
