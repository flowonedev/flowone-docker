-- Wave 2: Stable folder identity (UUIDv7) + path history + scan generations.
--
-- This migration is purely additive. No existing column is dropped or
-- modified. The new tables coexist with `webmail_folder_index` (which is
-- a per-user search-indexing flag table, unrelated to identity).
--
-- Folders are uniquely identified by `id` (UUIDv7, generated on first
-- discovery). The path is just the current pointer; renames are recorded in
-- `webmail_folder_path_history`. Identity is stable across renames, namespace
-- moves, and delimiter migrations.
--
-- For Wave 2 dual-write rollout, `pinned_emails` gets a NULLABLE folder_id
-- column. Reads continue to use (user_email, folder, uid); writes also
-- populate folder_id. The cutover (drop folder/uid lookup) happens later
-- when the four telemetry counters in `dual-write-readiness.php` are at
-- zero for 7 consecutive days.

CREATE TABLE IF NOT EXISTS webmail_folder_identity (
    id                CHAR(36)         NOT NULL PRIMARY KEY,                 -- UUIDv7
    account_id        VARCHAR(255)     NOT NULL,                             -- user_email (matches existing tables)
    current_path      VARCHAR(1024)    NOT NULL,
    display_name      VARCHAR(512)     NOT NULL,
    uidvalidity       BIGINT UNSIGNED  NULL,
    uidnext           BIGINT UNSIGNED  NULL,
    special_use       VARCHAR(32)      NULL,                                 -- \Sent, \Drafts, \All, etc.
    attributes        JSON             NULL,                                 -- raw IMAP flag list
    namespace_prefix  VARCHAR(64)      NULL,
    delimiter         VARCHAR(4)       NULL,
    is_selectable     TINYINT(1)       NOT NULL DEFAULT 1,
    parent_id         CHAR(36)         NULL,
    state             ENUM('healthy','degraded','quarantined','ignored','deleted')
                                       NOT NULL DEFAULT 'healthy',
    provider_type     ENUM('gmail','dovecot','exchange','cyrus','courier','unknown')
                                       NOT NULL DEFAULT 'unknown',
    message_count     INT UNSIGNED     NULL,
    first_seen_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_account_path (account_id, current_path(255)),
    KEY idx_account (account_id),
    KEY idx_special_use (special_use),
    KEY idx_state (state),
    KEY idx_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tombstone trail for every former path. Used by:
--   - Legacy URL redirect (Wave 3)
--   - Audit / forensic debugging
--   - Delimiter migrations (Dovecot . -> /)
--   - Namespace moves (personal -> shared)
CREATE TABLE IF NOT EXISTS webmail_folder_path_history (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    folder_id                CHAR(36)        NOT NULL,
    former_path              VARCHAR(1024)   NOT NULL,
    former_namespace_prefix  VARCHAR(64)     NULL,
    former_delimiter         VARCHAR(4)      NULL,
    recorded_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reason                   ENUM('rename','namespace_move','delimiter_change','manual','deleted')
                                             NOT NULL,
    KEY idx_folder (folder_id),
    KEY idx_former_path (former_path(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scan-generation fence: each scan cycle issues a monotonic generation_id
-- (ULID) per account. Cache writes / state transitions only commit if the
-- writer's generation matches account.current_generation, so a slow stale
-- scan can never overwrite a fast fresh one.
CREATE TABLE IF NOT EXISTS webmail_folder_scan_runs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id      VARCHAR(255)    NOT NULL,
    generation_id   CHAR(30)        NOT NULL,                                -- "req_" + ULID
    started_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at     TIMESTAMP       NULL,
    status          ENUM('running','complete','failed','superseded')
                                    NOT NULL DEFAULT 'running',
    KEY idx_account (account_id),
    KEY idx_generation (generation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provider fingerprint per account. Wave 2: derived from CAPABILITY/ID/
-- namespace layout. Surfaces in structured logs as `provider_type` for
-- telemetry segmentation ("degraded-folder rate by provider", etc.).
CREATE TABLE IF NOT EXISTS webmail_account_provider (
    account_id          VARCHAR(255)    NOT NULL PRIMARY KEY,
    provider_type       ENUM('gmail','dovecot','exchange','cyrus','courier','unknown')
                                        NOT NULL DEFAULT 'unknown',
    fingerprint_signals JSON            NULL,
    fingerprint_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_provider (provider_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wave 2 dual-write: pinned_emails gets a nullable folder_id column.
-- Reads still use (user_email, folder, uid); writes populate folder_id when
-- known. The cutover migration (drop the legacy columns) is gated by the
-- four-counter telemetry rollout in `dual-write-readiness.php` and is NOT
-- part of this migration.
--
-- The migration runner treats "duplicate column" / "duplicate key" errors
-- as idempotent successes (see MigrationService::isIdempotentError), so a
-- second run on a database that already has these columns is harmless.
ALTER TABLE pinned_emails ADD COLUMN folder_id CHAR(36) NULL AFTER folder;
ALTER TABLE pinned_emails ADD INDEX idx_folder_id (folder_id);
