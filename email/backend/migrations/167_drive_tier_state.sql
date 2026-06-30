-- Migration 167: drive_files tier_state schema (Phase 4)
--
-- Adds a proper state machine on top of the existing primitive
-- `storage_location` column (added in 022_add_storage_location, refined
-- in 031_nas_direct_access). storage_location only tells us WHERE the
-- bytes are (local | nas | pending_migration); tier_state adds the
-- transitional states (tiering, recalling) and the audit trail required
-- to safely migrate bytes between tiers without losing data.
--
-- Phase 4 is purely additive: DriveService is NOT modified in this
-- phase. A nightly backfill cron keeps tier_state consistent with the
-- column DriveService still writes (`storage_location`). Phase 5
-- promotes tier_state to the authoritative column and switches reads.
--
-- States:
--   hot        : bytes live on VPS (and possibly also NAS).
--   tiering    : tier-down worker is copying VPS -> NAS, bytes still on VPS.
--   cold       : bytes live only on NAS (VPS copy deleted).
--   recalling  : recall worker is copying NAS -> VPS, bytes still on NAS.
--   lost       : integrity check failed and we could not recover.
--
-- Allowed transitions enforced at the application layer by
-- FlowOne\Storage\TierState::canTransition() — the DB lets any value
-- through because MariaDB ENUMs already constrain the alphabet.

-- ─────────────────────────────────────────────────────────────────────
-- 1. Helper procedures (167-suffixed to avoid clashes with other
--    migrations that define their own copies in the same connection).
-- ─────────────────────────────────────────────────────────────────────

DROP PROCEDURE IF EXISTS add_column_if_not_exists_167;
DELIMITER //
CREATE PROCEDURE add_column_if_not_exists_167(
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

DROP PROCEDURE IF EXISTS add_index_if_not_exists_167;
DELIMITER //
CREATE PROCEDURE add_index_if_not_exists_167(
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
        SET @sql = CONCAT('CREATE INDEX `', p_index, '` ON `', p_table, '`(', p_columns, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- ─────────────────────────────────────────────────────────────────────
-- 2. Add columns to drive_files.
-- ─────────────────────────────────────────────────────────────────────

CALL add_column_if_not_exists_167(
    'drive_files',
    'tier_state',
    "ENUM('hot','tiering','cold','recalling','lost') NOT NULL DEFAULT 'hot' COMMENT 'Phase 4 tier-state machine; backfilled from storage_location'"
);

CALL add_column_if_not_exists_167(
    'drive_files',
    'tier_changed_at',
    "TIMESTAMP NULL DEFAULT NULL COMMENT 'Last tier_state transition timestamp (NULL until first transition)'"
);

CALL add_column_if_not_exists_167(
    'drive_files',
    'tier_changed_by',
    "VARCHAR(64) DEFAULT NULL COMMENT 'Actor that last transitioned tier_state (system|user_email|cron name)'"
);

CALL add_column_if_not_exists_167(
    'drive_files',
    'tier_recall_attempts',
    "INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of recall attempts since last successful tier transition'"
);

-- Last time the bytes were verified to still match `checksum`. Used by
-- a future integrity sweep to detect bit-rot on cold storage.
CALL add_column_if_not_exists_167(
    'drive_files',
    'tier_verified_at',
    "TIMESTAMP NULL DEFAULT NULL COMMENT 'Last integrity verification of tiered file (Phase 6+)'"
);

-- ─────────────────────────────────────────────────────────────────────
-- 3. Backfill tier_state from existing storage_location values.
--    Idempotent: only updates rows where tier_state is still the default
--    AND storage_location is set. Rows uploaded after this migration
--    runs but before the first backfill cron pass will already have
--    tier_state='hot' from the column default — that's the correct
--    initial state for a fresh upload.
-- ─────────────────────────────────────────────────────────────────────

-- 'local' (or NULL) → 'hot'  (already the default, but we set tier_changed_at
--                              so the backfill cron can pick up new uploads.)
UPDATE drive_files
SET tier_state = 'hot',
    tier_changed_at = COALESCE(tier_changed_at, updated_at, created_at, CURRENT_TIMESTAMP),
    tier_changed_by = COALESCE(tier_changed_by, 'migration-167')
WHERE (storage_location IS NULL OR storage_location = 'local')
  AND (tier_changed_at IS NULL);

-- 'nas' → 'cold'  (bytes live only on NAS; this is the long-term tiered state.)
UPDATE drive_files
SET tier_state = 'cold',
    tier_changed_at = COALESCE(tier_changed_at, updated_at, created_at, CURRENT_TIMESTAMP),
    tier_changed_by = COALESCE(tier_changed_by, 'migration-167')
WHERE storage_location = 'nas'
  AND (tier_state = 'hot' OR tier_state IS NULL);

-- 'pending_migration' → 'tiering'  (transient state; migration-031's
-- drive_pending_nas_migration table records the in-flight rows.)
UPDATE drive_files
SET tier_state = 'tiering',
    tier_changed_at = COALESCE(tier_changed_at, updated_at, created_at, CURRENT_TIMESTAMP),
    tier_changed_by = COALESCE(tier_changed_by, 'migration-167')
WHERE storage_location = 'pending_migration'
  AND (tier_state = 'hot' OR tier_state IS NULL);

-- ─────────────────────────────────────────────────────────────────────
-- 4. Indexes for the Phase 5 tier-down worker query patterns.
--    The hot worker query is:
--      SELECT id FROM drive_files
--      WHERE tier_state='hot' AND tier_changed_at < (NOW() - INTERVAL N DAY)
--      ORDER BY tier_changed_at ASC LIMIT batch
--    Composite (state, changed_at) covers it.
-- ─────────────────────────────────────────────────────────────────────

CALL add_index_if_not_exists_167(
    'drive_files',
    'idx_tier_state_changed',
    '`tier_state`, `tier_changed_at`'
);

CALL add_index_if_not_exists_167(
    'drive_files',
    'idx_tier_state',
    '`tier_state`'
);

-- ─────────────────────────────────────────────────────────────────────
-- 5. Tier-transition audit log. Append-only history of every
--    tier_state change. Lets us forensically reconstruct what
--    happened to any file. Truncated by retention sweep in Phase 6+.
-- ─────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS drive_tier_transitions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    file_id INT NOT NULL,
    from_state ENUM('hot','tiering','cold','recalling','lost','unknown') NOT NULL DEFAULT 'unknown',
    to_state   ENUM('hot','tiering','cold','recalling','lost')           NOT NULL,
    actor      VARCHAR(64) NOT NULL DEFAULT 'system',
    reason     VARCHAR(255) DEFAULT NULL,
    boot_epoch INT UNSIGNED DEFAULT NULL COMMENT 'Storage daemon boot epoch at the time of transition',
    bytes      BIGINT UNSIGNED DEFAULT NULL,
    duration_ms INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_file_id (file_id),
    KEY idx_to_state (to_state),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────────────────────
-- 6. Cleanup
-- ─────────────────────────────────────────────────────────────────────

DROP PROCEDURE IF EXISTS add_column_if_not_exists_167;
DROP PROCEDURE IF EXISTS add_index_if_not_exists_167;
