-- =====================================================================
-- Wave 2 Cutover (Track #4): drop the legacy `folder` columns and
-- promote `folder_id` to NOT NULL across the dual-write tables.
--
-- THIS FILE IS STAGED. It lives in `email/backend/cutover/`, NOT in
-- `email/backend/migrations/`, so the auto-runner cannot pick it up by
-- mistake. To apply, the operator MUST follow the steps in
-- `RUN-CUTOVER.md`.
--
-- IRREVERSIBLE:
--   - The legacy `folder` column is dropped, not nullified. After this
--     migration the only way to reach a row is via `folder_id`.
--   - The legacy unique keys / indexes that included `folder` are
--     dropped. Their `folder_id`-shaped replacements are added below.
--   - `folder_id` becomes NOT NULL. The preflight script verifies that
--     no rows have NULL folder_id BEFORE this runs.
--
-- Tables touched:
--   1. pinned_emails
--   2. webmail_conversation_members
--   3. webmail_conversations
--
-- Idempotency: ALTERs use `IF EXISTS` for index drops so a partial
-- run is recoverable. Column drops are NOT idempotent (DROP COLUMN
-- without IF EXISTS); the runbook keeps a record of when this ran.
--
-- Rollback: there is no in-place rollback. The recovery path is a
-- restore from the backup the runbook takes immediately before apply.
--
-- Note on transactions: MariaDB auto-commits every DDL statement
-- regardless of any START TRANSACTION wrapper. We deliberately do NOT
-- wrap the ALTERs in a transaction because doing so would create the
-- illusion of atomicity while providing none. If a statement fails
-- partway, restore from the runbook's pre-cutover backup.
-- =====================================================================

-- ---------------------------------------------------------------------
-- pinned_emails
-- ---------------------------------------------------------------------
ALTER TABLE pinned_emails DROP INDEX IF EXISTS unique_pin;
ALTER TABLE pinned_emails DROP INDEX IF EXISTS idx_user_folder;
ALTER TABLE pinned_emails DROP INDEX IF EXISTS idx_folder_id;
ALTER TABLE pinned_emails DROP INDEX IF EXISTS idx_user_folder_id_uid;

ALTER TABLE pinned_emails DROP COLUMN folder;

ALTER TABLE pinned_emails MODIFY folder_id CHAR(36) NOT NULL;

-- New canonical unique key. Folds in idx_user_folder_id_uid (composite
-- prefix matches the unique key) so we don't need a separate index.
ALTER TABLE pinned_emails ADD UNIQUE KEY unique_pin_id (user_email, folder_id, uid);

-- ---------------------------------------------------------------------
-- webmail_conversation_members
-- ---------------------------------------------------------------------
ALTER TABLE webmail_conversation_members DROP INDEX IF EXISTS unique_msg;
ALTER TABLE webmail_conversation_members DROP INDEX IF EXISTS idx_folder;
ALTER TABLE webmail_conversation_members DROP INDEX IF EXISTS idx_folder_conv;
ALTER TABLE webmail_conversation_members DROP INDEX IF EXISTS idx_folder_id;

ALTER TABLE webmail_conversation_members DROP COLUMN folder;

ALTER TABLE webmail_conversation_members MODIFY folder_id CHAR(36) NOT NULL;

-- One message per (user, folder, message_id). Covers the application's
-- ON DUPLICATE KEY UPDATE upsert path.
ALTER TABLE webmail_conversation_members
    ADD UNIQUE KEY unique_msg_id (user_email, folder_id, message_id);

-- Folder-scoped conversation count: WHERE user_email=? AND folder_id=?
-- GROUP BY conversation_id. Replaces idx_folder_conv.
ALTER TABLE webmail_conversation_members
    ADD INDEX idx_folder_id_conv (user_email, folder_id, conversation_id);

-- idx_user_folder_id_uid (added in 163) is still useful for the UID
-- lookup paths (deleteConversationMember, moveConversationMember,
-- updateMemberReadStatus). Leave it.
--
-- idx_msg_hash (added in 017) covers (user_email, message_id_hash)
-- queries. Leave it.

-- ---------------------------------------------------------------------
-- webmail_conversations
-- ---------------------------------------------------------------------
ALTER TABLE webmail_conversations DROP INDEX IF EXISTS idx_folder;
ALTER TABLE webmail_conversations DROP INDEX IF EXISTS idx_latest;
ALTER TABLE webmail_conversations DROP INDEX IF EXISTS idx_norm_subject;
ALTER TABLE webmail_conversations DROP INDEX IF EXISTS idx_folder_id;
ALTER TABLE webmail_conversations DROP INDEX IF EXISTS idx_user_folder_id;

ALTER TABLE webmail_conversations DROP COLUMN folder;

ALTER TABLE webmail_conversations MODIFY folder_id CHAR(36) NOT NULL;

-- Folder list query: WHERE user_email=? AND folder_id=? ORDER BY latest_date DESC.
-- Folds in the leading-prefix queries that idx_user_folder_id used to
-- serve (which is why we drop it above).
ALTER TABLE webmail_conversations
    ADD INDEX idx_latest_id (user_email, folder_id, latest_date DESC);

-- Subject-fallback threading lookup
-- (ConversationService::findConversationBySubject):
--   WHERE user_email=? AND folder_id=? AND normalized_subject=?
--   ORDER BY latest_date DESC LIMIT 1
ALTER TABLE webmail_conversations
    ADD INDEX idx_norm_subject_id (user_email, folder_id, normalized_subject);

-- ---------------------------------------------------------------------
-- Record the cutover timestamp in the migrations table so post-mortem
-- queries can correlate with the soak window.
-- ---------------------------------------------------------------------
INSERT INTO migrations (name, success, executed_at)
VALUES ('166_canonical_identity_cutover', 1, NOW())
ON DUPLICATE KEY UPDATE executed_at = NOW();
