-- Migration 182: webmail_folder_tombstones
--
-- Phase 3 completion: the delta() endpoint needs to surface deletedUids
-- so the frontend can drop disappeared messages from the view without a
-- full refresh. Today delta() always returns deletedUids=[] because there
-- is no durable record of what disappeared between two polls.
--
-- This table is appended by:
--   - ConversationService::deleteConversationMember / bulkDeleteConversationMembers
--     (when the user deletes locally via the request path)
--   - MailboxSyncService::expungeReconcile
--     (when the sync engine detects a UID that vanished server-side)
--
-- It is read by:
--   - MailboxMirrorReadService::listDelta
--     -> response.deletedUids = rows since the client's watermark
--
-- Old rows are purged by cron/sync-mailbox.php (kept ~7 days so a client
-- offline over a long weekend still catches up correctly).

CREATE TABLE IF NOT EXISTS webmail_folder_tombstones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email   VARCHAR(191) NOT NULL,
    folder_id    CHAR(36)     NOT NULL,
    uid          INT UNSIGNED NOT NULL,
    deleted_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    source       ENUM('local_delete','imap_expunge','uidvalidity_reset') NOT NULL DEFAULT 'local_delete',

    INDEX idx_folder_recent (user_email, folder_id, deleted_at),
    INDEX idx_purge (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
