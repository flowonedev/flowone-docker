-- Drive: Starred + Recent support
--
-- Adds:
--   drive_files.is_starred         (TINYINT)   - per-user star flag
--   drive_folders.is_starred       (TINYINT)
--   drive_folders.last_accessed_at (TIMESTAMP) - mirrors drive_files.last_opened_at
--
-- drive_files already has `last_opened_at` (added in 006_drive_versioning_trash.sql)
-- so we reuse it for the "Recent" feature instead of adding a duplicate column.
--
-- Migration runner treats duplicate-column / duplicate-key errors as
-- idempotent successes (MigrationService::isIdempotentError), so a second
-- run on a DB that already has these is harmless.

ALTER TABLE drive_files ADD COLUMN is_starred TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE drive_files ADD INDEX idx_files_starred (user_email, is_starred);
ALTER TABLE drive_files ADD INDEX idx_files_recent  (user_email, last_opened_at);

ALTER TABLE drive_folders ADD COLUMN is_starred TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE drive_folders ADD COLUMN last_accessed_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE drive_folders ADD INDEX idx_folders_starred (user_email, is_starred);
ALTER TABLE drive_folders ADD INDEX idx_folders_recent  (user_email, last_accessed_at);
