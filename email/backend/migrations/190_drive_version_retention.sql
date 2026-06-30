-- Drive versioning overhaul: per-version metadata, integrity, pin/label.
--
-- drive_file_versions gains:
--   storage_location  where the version's bytes live ('nfs' | 'local'),
--                     recorded at version-creation time so path resolution
--                     no longer guesses from the parent file's current tier
--   mime_type         the version's own MIME type (formats can change
--                     across versions; downloads must not inherit the
--                     parent's current type)
--   checksum          content hash recorded by the desktop sync client
--   label             optional user-given name ("Final draft sent to client")
--   is_pinned         pinned versions are never auto-pruned by the
--                     retention engine ("keep forever")
--
-- Integrity: a unique key on (file_id, version_number). Historic rows may
-- contain duplicates (the old NAS-write path derived numbers from
-- MAX(version_number) which collides after deletions), so duplicates are
-- removed first, keeping the newest row of each pair.
--
-- Idempotent: IF NOT EXISTS guards + the dedupe DELETE is a no-op on a
-- clean table.

DELETE v1 FROM drive_file_versions v1
INNER JOIN drive_file_versions v2
   ON v1.file_id = v2.file_id
  AND v1.version_number = v2.version_number
  AND v1.id < v2.id;

ALTER TABLE drive_file_versions
    ADD COLUMN IF NOT EXISTS storage_location VARCHAR(10) NULL DEFAULT NULL AFTER size,
    ADD COLUMN IF NOT EXISTS mime_type VARCHAR(255) NULL DEFAULT NULL AFTER storage_location,
    ADD COLUMN IF NOT EXISTS checksum VARCHAR(64) NULL DEFAULT NULL AFTER mime_type,
    ADD COLUMN IF NOT EXISTS label VARCHAR(255) NULL DEFAULT NULL AFTER checksum,
    ADD COLUMN IF NOT EXISTS is_pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER label;

ALTER TABLE drive_file_versions
    ADD UNIQUE KEY IF NOT EXISTS uq_file_version (file_id, version_number);

-- When a current file that is still queued for local->NAS migration gets
-- archived as a version, the queue row is re-pointed at the version row
-- (version_id). The migration worker then stamps the version's
-- storage_location instead of the parent file's.
ALTER TABLE drive_pending_nas_migration
    ADD COLUMN IF NOT EXISTS version_id INT NULL DEFAULT NULL AFTER file_id;

