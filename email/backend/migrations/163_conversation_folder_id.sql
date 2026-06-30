-- Wave 2 P0 / dual-write rollout: extend folder_id dual-write to the
-- conversation tables and add the composite indexes the dual-read code
-- paths will rely on.
--
-- This migration is purely additive. No existing column is dropped or
-- modified. After this migration runs:
--
--   pinned_emails has:
--     - folder        (legacy, NOT NULL, kept until cutover)
--     - folder_id     (new,    NULLABLE, populated by dual-write)
--     - composite index (user_email, folder_id, uid) for dual-read
--
--   webmail_conversation_members has:
--     - folder        (legacy)
--     - folder_id     (new, NULLABLE)
--     - composite index (user_email, folder_id, uid) for dual-read
--
--   webmail_conversations has:
--     - folder        (legacy)
--     - folder_id     (new, NULLABLE)
--     - composite index (user_email, folder_id) for dual-read
--
-- The composite indexes are CRITICAL for dual-read performance. Without
-- them, every dual-read fallback query becomes a full-table scan. The P0
-- deployment checklist requires running EXPLAIN against each dual-read
-- query before traffic is allowed in.
--
-- Cutover (drop legacy `folder` columns, add NOT NULL on `folder_id`,
-- replace unique keys) happens in migration 166_canonical_identity_cutover.sql
-- and is gated by 7 consecutive days of all-zero readiness counters in
-- dual-write-readiness.php. See that script's header for details.
--
-- The migration runner treats "duplicate column" / "duplicate key" errors
-- as idempotent successes (see MigrationService::isIdempotentError), so a
-- second run on a database that already has these columns is harmless.

-- ----------------------------------------------------------------------
-- pinned_emails: composite index for dual-read.
-- The folder_id column itself was added in migration 160_folder_identity.sql.
-- ----------------------------------------------------------------------
ALTER TABLE pinned_emails
    ADD INDEX idx_user_folder_id_uid (user_email, folder_id, uid);

-- ----------------------------------------------------------------------
-- webmail_conversation_members: dual-write target.
-- ----------------------------------------------------------------------
ALTER TABLE webmail_conversation_members
    ADD COLUMN folder_id CHAR(36) NULL AFTER folder;

ALTER TABLE webmail_conversation_members
    ADD INDEX idx_folder_id (folder_id);

ALTER TABLE webmail_conversation_members
    ADD INDEX idx_user_folder_id_uid (user_email, folder_id, uid);

-- ----------------------------------------------------------------------
-- webmail_conversations: dual-write target.
-- ----------------------------------------------------------------------
ALTER TABLE webmail_conversations
    ADD COLUMN folder_id CHAR(36) NULL AFTER folder;

ALTER TABLE webmail_conversations
    ADD INDEX idx_folder_id (folder_id);

-- Conversations are unique per (user_email, conversation_id), not per
-- (user_email, folder, conversation_id), so the dual-read index is on
-- (user_email, folder_id) only -- callers filter by folder_id then look
-- up the conversation row directly.
ALTER TABLE webmail_conversations
    ADD INDEX idx_user_folder_id (user_email, folder_id);
