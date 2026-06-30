-- =====================================================================
-- Wave 2 Cutover Stage 1 (REVERSIBLE).
--
-- Make the legacy `folder` column NULLABLE on the three dual-write
-- tables. This unblocks the new code (deployed in Stage 2) which omits
-- the `folder` column from INSERT statements entirely.
--
-- Why a separate stage:
--   - Stage 1 (this file) is REVERSIBLE: ALTER TABLE ... MODIFY ... NOT NULL
--     restores the original constraint.
--   - Stage 2 deploys the new code via apply-patches.sh. Reversible too
--     (revert-patches.sh restores the previous code).
--   - Stage 3 (`166_canonical_identity_cutover.sql`) drops the column.
--     IRREVERSIBLE.
--
-- The 5-minute observability window between Stage 2 and Stage 3 catches
-- any query the audit missed: Stage 2 alone surfaces the failure (code
-- referencing a column that's still present but no longer being
-- written to), and a `revert-patches.sh` rolls back to the dual-write
-- code with no schema damage.
--
-- Idempotency: ALTER ... MODIFY is idempotent (running it twice with
-- the same definition is a no-op).
--
-- This file is staged in `cutover/`, NOT in `migrations/`, so the
-- auto-runner cannot pick it up by mistake. To apply, the operator
-- runs the exact `mysql ... <` command from `RUN-CUTOVER.md`.
-- =====================================================================

ALTER TABLE pinned_emails                  MODIFY folder VARCHAR(255) NULL;
ALTER TABLE webmail_conversation_members   MODIFY folder VARCHAR(255) NULL;
ALTER TABLE webmail_conversations          MODIFY folder VARCHAR(255) NULL;

INSERT INTO migrations (name, success, executed_at)
VALUES ('165_make_folder_columns_nullable', 1, NOW())
ON DUPLICATE KEY UPDATE executed_at = NOW();
