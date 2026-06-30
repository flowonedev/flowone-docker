-- Migration 181: webmail_folder_sync_state
--
-- Phase 2 of the "finish Gmail-like" plan: the background sync engine
-- (MailboxSyncService + cron/sync-mailbox.php) needs durable per-folder
-- sync progress so it knows:
--
--   - Which folders have been fully mirrored (status = 'synced')
--   - The high-water UID it has already ingested into the mirror
--   - The HIGHESTMODSEQ it last applied for incremental flag sync
--   - The UIDVALIDITY snapshot - any change means re-sync from scratch
--   - When each sync phase last ran (full/incremental/expunge)
--   - Failure backoff state (so a misbehaving folder doesn't burn the
--     IMAP budget on every cron pass)
--
-- This is intentionally a NEW table rather than extending webmail_folder_index:
--   * webmail_folder_index is keyed by (user_email, folder PATH) and is owned
--     by the foreground request path (assignMessagesToConversations advances
--     last_indexed_uid lazily when the user scrolls).
--   * webmail_folder_sync_state is keyed by (user_email, folder_id) - the
--     canonical UUID - and is owned by the background sync cron. Path renames
--     don't orphan its rows.
--
-- The MailboxController read switch (phase 3) flips a folder onto the
-- mirror only when state.status = 'synced'. Until then reads stay on the
-- live IMAP path.

CREATE TABLE IF NOT EXISTS webmail_folder_sync_state (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Identity --------------------------------------------------------
    user_email     VARCHAR(191) NOT NULL COMMENT 'FlowOne login that owns the mirror rows',
    folder_id      CHAR(36)     NOT NULL COMMENT 'webmail_folder_identity.id - the canonical UUIDv7',
    account_email  VARCHAR(191) NOT NULL COMMENT 'IMAP account credentials key (may differ from user_email for OAuth aliases)',
    folder_path    VARCHAR(255) NOT NULL COMMENT 'Current IMAP path - cached for log output; folder_id is authoritative',

    -- Sync progress ---------------------------------------------------
    status         ENUM('pending','initial_syncing','synced','uidvalidity_reset','failed')
                       NOT NULL DEFAULT 'pending'
                       COMMENT 'Lifecycle: pending -> initial_syncing -> synced. uidvalidity_reset triggers re-sync. failed = backed off.',
    uidvalidity    BIGINT UNSIGNED NULL COMMENT 'Last UIDVALIDITY we synced. Mismatch -> nuke mirror and restart.',
    highest_uid    INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Largest UID present in the mirror for this folder',
    highest_modseq BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'HIGHESTMODSEQ at last incremental flag sync. 0 = never synced',
    message_count  INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'IMAP STATUS messages at last sync. Drift vs mirror row count signals reconcile.',

    -- Phase timestamps ------------------------------------------------
    last_full_sync_at        DATETIME NULL COMMENT 'Last successful full / initial walk',
    last_incremental_sync_at DATETIME NULL COMMENT 'Last successful CHANGEDSINCE + new-UID pass',
    last_expunge_sync_at     DATETIME NULL COMMENT 'Last successful UID SEARCH ALL reconciliation',

    -- Backoff ---------------------------------------------------------
    last_error      TEXT     NULL COMMENT 'Last error text (for the sync-issues banner)',
    attempts        SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Consecutive failed passes; reset on success',
    next_attempt_at DATETIME NULL COMMENT 'Earliest time the cron may try this folder again',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_user_folder (user_email, folder_id),
    INDEX idx_status_next   (status, next_attempt_at) COMMENT 'Cron picker: pending/failed rows ready to run',
    INDEX idx_account_status (account_email, status)  COMMENT 'Group work by IMAP connection',
    INDEX idx_user           (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
