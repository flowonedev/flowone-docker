-- =====================================================
-- Add size_bytes (+ probe timestamp) to the v2 sites table.
--
-- Why: SitesV2View needs to show "Size" alongside "Document root"
-- like the legacy view does. Computing du -sb on demand from the
-- list endpoint is far too slow (each call would walk every home
-- dir), so the reconciler caches it and the list endpoint just
-- reads the cached value.
--
-- size_bytes is nullable so backfilled rows that have not yet been
-- probed render as "—" until the first reconciler tick fills them
-- in. size_probed_at lets the UI show "stale" badges and gives the
-- reconciler an upper bound on how often to recompute.
--
-- Apply on server:
--   mysql -u vpsadmin -p'7bcf619af819e4e274e5cfdfba022274' \
--     devc_vps_dash < /var/www/vps-admin/database/migrate_sites_size_bytes.sql
-- =====================================================

-- Wrap in an idempotency check so the migration runner can replay
-- this file safely. INFORMATION_SCHEMA lookups avoid the "duplicate
-- column" error and keep the migration runner's audit log clean.

SET @col_exists := (
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'sites'
       AND COLUMN_NAME  = 'size_bytes'
);

SET @sql := IF(@col_exists = 0,
    'ALTER TABLE sites
        ADD COLUMN size_bytes      BIGINT UNSIGNED NULL
            COMMENT ''du -sb home_dir, cached by the reconciler''
            AFTER document_root,
        ADD COLUMN size_probed_at  DATETIME NULL
            COMMENT ''last time size_bytes was refreshed''
            AFTER size_bytes',
    'SELECT ''sites.size_bytes already present, skipping'''
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
