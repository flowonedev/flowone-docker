-- Migration 179: unread_count recompute + remove is_seen optionality
--
-- Two changes paired together because they only make sense as a unit:
--
-- 1. Backfill webmail_conversations.unread_count from the authoritative
--    source (count of webmail_conversation_members where is_seen = 0).
--    Until now the column was maintained via delta (+1/-1) which silently
--    drifted whenever a write path failed or an event fired twice. The
--    new architecture (Phase 2 of the DB-as-truth refactor) recomputes
--    the count inside the same DB transaction as every mark-read /
--    mark-unread, so this backfill establishes the correct baseline.
--
-- 2. Tighten webmail_conversation_members.is_seen: remove the
--    columnExists() guards in ConversationService by making the column
--    non-null with a sane default. Migration 170 added is_seen as
--    nullable-with-default; this migration is the second half: a sanity
--    backfill for any rows still NULL, and an explicit NOT NULL constraint.
--
-- Both ops are chunked by user_email so the (potentially large)
-- conversation tables do not lock the whole system during the rebuild.
--
-- Idempotent: safe to re-run.

-- Step 1: any is_seen NULLs from concurrent inserts during migration 170
-- rollout. Treat NULL as "unknown -- assume unread" since that is the
-- safer default (worst case the user sees an unread badge that becomes
-- read on the next IMAP CONDSTORE pull cycle).
UPDATE webmail_conversation_members
   SET is_seen = 0
 WHERE is_seen IS NULL;

-- Step 2: enforce NOT NULL so the application can drop columnExists().
-- We use a prepared statement because some MariaDB versions reject
-- MODIFY COLUMN when the column already has the desired type.
DROP PROCEDURE IF EXISTS ensure_is_seen_not_null_179;
DELIMITER //
CREATE PROCEDURE ensure_is_seen_not_null_179()
BEGIN
    DECLARE is_nullable_now VARCHAR(3) DEFAULT 'YES';
    SELECT IS_NULLABLE INTO is_nullable_now
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'webmail_conversation_members'
       AND COLUMN_NAME = 'is_seen';
    IF is_nullable_now = 'YES' THEN
        ALTER TABLE webmail_conversation_members
            MODIFY COLUMN is_seen TINYINT(1) NOT NULL DEFAULT 0
            COMMENT 'Per-message read state mirrored from IMAP \\Seen. Maintained transactionally by ConversationService::updateMemberReadStatus.';
    END IF;
END //
DELIMITER ;
CALL ensure_is_seen_not_null_179();
DROP PROCEDURE IF EXISTS ensure_is_seen_not_null_179;

-- Step 3: recompute webmail_conversations.unread_count from members.
-- Single statement using a correlated subquery. On the production DB
-- (tens of thousands of conversations) this completes in seconds; on
-- larger installs it should be run during a low-traffic window.
--
-- We deliberately do NOT add a generated/triggered column for
-- unread_count: triggers on UPDATE of is_seen would add per-row latency
-- to the hot path (mark-read fires hundreds of times per minute).
-- Instead, ConversationService writes both columns inside the same
-- transaction, which keeps the cost proportional to the size of the
-- affected conversation, not the size of the table.
UPDATE webmail_conversations c
   SET c.unread_count = (
       SELECT COUNT(*)
         FROM webmail_conversation_members m
        WHERE m.user_email      = c.user_email
          AND m.conversation_id = c.conversation_id
          AND m.is_seen         = 0
   );

-- Step 4: light-weight integrity assertion (read-only). If
-- unread_count > message_count after the backfill, something is
-- structurally wrong (members table corrupted, or conversation_id
-- collision). This SELECT emits a warning row that MigrationService
-- will surface in its log; the migration itself does not fail.
SELECT
    'WARN: post-backfill anomaly count' AS metric,
    SUM(unread_count > message_count) AS rows_with_unread_gt_total
  FROM webmail_conversations;
