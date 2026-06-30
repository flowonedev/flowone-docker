-- Migration 169: drive_files.source_email_* columns
--
-- Records which IMAP email message an attachment was saved from, so the
-- email view can persistently surface a "Saved to Drive" indicator and
-- a one-click Share action on the original attachment card.
--
-- All three columns are nullable so existing rows (and Drive uploads
-- that didn't originate from an email) are unaffected. The composite
-- index supports the lookup performed when rendering an email:
--   SELECT ... FROM drive_files
--   WHERE user_email = ?
--     AND source_email_folder = ?
--     AND source_email_uid = ?
-- which is the only access pattern these columns enable.
--
-- IMAP UIDs are folder-scoped and stable per folder, so (user_email,
-- folder, uid) uniquely identifies a message; the optional `part`
-- column then identifies a specific attachment inside that message.

-- ─────────────────────────────────────────────────────────────────────
-- 1. Idempotent helpers (suffixed -169 to avoid clashing with parallel
--    migration sessions still using the 167/168 helpers).
-- ─────────────────────────────────────────────────────────────────────

DROP PROCEDURE IF EXISTS add_column_if_not_exists_169;
DELIMITER //
CREATE PROCEDURE add_column_if_not_exists_169(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition VARCHAR(1000)
)
BEGIN
    DECLARE col_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO col_exists
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND COLUMN_NAME = p_column;
    IF col_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

DROP PROCEDURE IF EXISTS add_index_if_not_exists_169;
DELIMITER //
CREATE PROCEDURE add_index_if_not_exists_169(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_columns VARCHAR(255)
)
BEGIN
    DECLARE idx_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO idx_exists
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND INDEX_NAME = p_index;
    IF idx_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_columns, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- ─────────────────────────────────────────────────────────────────────
-- 2. Schema extension
-- ─────────────────────────────────────────────────────────────────────

CALL add_column_if_not_exists_169('drive_files', 'source_email_folder',
    'VARCHAR(255) NULL DEFAULT NULL COMMENT ''IMAP folder this attachment was saved from (NULL = not from email)''');

CALL add_column_if_not_exists_169('drive_files', 'source_email_uid',
    'INT NULL DEFAULT NULL COMMENT ''IMAP UID of the source message inside source_email_folder''');

CALL add_column_if_not_exists_169('drive_files', 'source_email_part',
    'VARCHAR(64) NULL DEFAULT NULL COMMENT ''MIME part identifier of the saved attachment inside the source message''');

-- Composite index for the email-view status lookup. Putting user_email
-- first keeps the index in line with the existing per-user filtering
-- pattern used by other drive_files indexes.
CALL add_index_if_not_exists_169('drive_files', 'idx_drive_files_email_source',
    '`user_email`, `source_email_folder`, `source_email_uid`');

-- ─────────────────────────────────────────────────────────────────────
-- 3. Cleanup
-- ─────────────────────────────────────────────────────────────────────

DROP PROCEDURE IF EXISTS add_column_if_not_exists_169;
DROP PROCEDURE IF EXISTS add_index_if_not_exists_169;
