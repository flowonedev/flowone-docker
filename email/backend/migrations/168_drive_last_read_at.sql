-- Migration 168: drive_files.last_read_at (Phase 6d)
--
-- Tracks when a file's bytes were last served by DriveService. Used
-- by the LRU candidate selector in the tier-down worker so we evict
-- files that haven't been touched recently, instead of just the
-- oldest-created files.
--
-- The column is intentionally NULL on existing rows. The LRU ORDER BY
-- in TierStateService::findTierDownCandidates() uses
-- `COALESCE(last_read_at, tier_changed_at)` so untouched rows (which
-- are most rows when this migration first lands) sort as if they
-- haven't been read since they were last tiered — which is correct.
-- As real reads start touching last_read_at, the ordering becomes
-- progressively smarter without any data backfill.
--
-- Writes are throttled in DriveService via a conditional UPDATE
-- (`WHERE last_read_at IS NULL OR last_read_at < cutoff`) so
-- high-frequency reads of the same file don't hammer the DB.

-- ─────────────────────────────────────────────────────────────────────
-- 1. Reuse the helper procedure from migration 167 idempotently
--    (suffixed -168 to avoid clashing with parallel sessions).
-- ─────────────────────────────────────────────────────────────────────

DROP PROCEDURE IF EXISTS add_column_if_not_exists_168;
DELIMITER //
CREATE PROCEDURE add_column_if_not_exists_168(
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

DROP PROCEDURE IF EXISTS add_index_if_not_exists_168;
DELIMITER //
CREATE PROCEDURE add_index_if_not_exists_168(
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

CALL add_column_if_not_exists_168('drive_files', 'last_read_at',
    'TIMESTAMP NULL DEFAULT NULL COMMENT ''When a hot/tiering file was last served; NULL = never read since migration 168''');

-- Composite index supports the LRU candidate query:
--   WHERE tier_state = 'hot' ORDER BY COALESCE(last_read_at, tier_changed_at) ASC
-- Putting tier_state first lets MariaDB filter to the hot set before
-- doing the order-by; the second column accelerates the sort when the
-- set is large.
CALL add_index_if_not_exists_168('drive_files', 'idx_drive_files_lru',
    '`tier_state`, `last_read_at`');

-- ─────────────────────────────────────────────────────────────────────
-- 3. Cleanup
-- ─────────────────────────────────────────────────────────────────────

DROP PROCEDURE IF EXISTS add_column_if_not_exists_168;
DROP PROCEDURE IF EXISTS add_index_if_not_exists_168;
