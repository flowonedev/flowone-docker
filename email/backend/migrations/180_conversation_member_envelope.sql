-- Migration 180: webmail_conversation_members envelope columns
--
-- Adds the remaining envelope/flag fields the conversation members table
-- needs in order to render a Gmail-style message list directly from the
-- DB mirror (without a live IMAP fetch).
--
-- Today the table stores: uid, folder_id, subject, from_email, from_name,
-- message_date, is_seen, has_attachment. The list view also needs:
--
--   to_recipients  - JSON-encoded array of { email, name } objects
--   cc_recipients  - JSON-encoded array of { email, name } objects
--   snippet        - short preview of the body for the list view
--   is_flagged     - mirror of IMAP \Flagged ("starred")
--   is_answered    - mirror of IMAP \Answered
--   internal_date  - IMAP INTERNALDATE (sort key Gmail uses, immune to client clock skew)
--   rfc822_size    - message size in bytes (for "show large attachments" etc.)
--
-- Once these are populated by the sync engine (migration 182 / phase 2),
-- MailboxController::messages() can serve folder lists from the mirror
-- instead of issuing a fresh IMAP fetch on every page load.
--
-- The columns are NULLABLE so backfill can happen incrementally without
-- breaking existing rows. The migration is idempotent and reuses the same
-- stored-procedure guard pattern as migration 170 so a second run on an
-- already-migrated database is a no-op.

DROP PROCEDURE IF EXISTS add_column_if_not_exists_180;
DELIMITER //
CREATE PROCEDURE add_column_if_not_exists_180(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition VARCHAR(1000)
)
BEGIN
    DECLARE col_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO col_exists
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND COLUMN_NAME = p_column;
    IF col_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

DROP PROCEDURE IF EXISTS add_index_if_not_exists_180;
DELIMITER //
CREATE PROCEDURE add_index_if_not_exists_180(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_columns VARCHAR(255)
)
BEGIN
    DECLARE idx_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO idx_exists
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND INDEX_NAME = p_index;
    IF idx_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_columns, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- Envelope fields needed by the mirror-backed list view.
CALL add_column_if_not_exists_180('webmail_conversation_members', 'to_recipients',
    'TEXT NULL COMMENT ''JSON array of { email, name } for the To header. Populated by MailboxSyncService and ConversationService::assignMessageToConversation.''');

CALL add_column_if_not_exists_180('webmail_conversation_members', 'cc_recipients',
    'TEXT NULL COMMENT ''JSON array of { email, name } for the Cc header.''');

CALL add_column_if_not_exists_180('webmail_conversation_members', 'snippet',
    'VARCHAR(512) NULL COMMENT ''Short preview of body text for the list view; trimmed to ~256 chars.''');

CALL add_column_if_not_exists_180('webmail_conversation_members', 'is_flagged',
    'TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Per-message mirror of IMAP \\Flagged ("starred").''');

CALL add_column_if_not_exists_180('webmail_conversation_members', 'is_answered',
    'TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Per-message mirror of IMAP \\Answered.''');

CALL add_column_if_not_exists_180('webmail_conversation_members', 'internal_date',
    'DATETIME NULL COMMENT ''IMAP INTERNALDATE - server-side arrival time, used as sort key for the list view (immune to client clock skew).''');

CALL add_column_if_not_exists_180('webmail_conversation_members', 'rfc822_size',
    'INT UNSIGNED NULL COMMENT ''Message size in bytes (RFC822.SIZE).''');

-- Composite index supporting the primary mirror-backed list query:
--   SELECT ... FROM webmail_conversation_members
--   WHERE user_email = ? AND folder_id = ?
--   ORDER BY internal_date DESC
-- LIMIT/offset paging fits this index well; the partial scan stops once
-- the page is filled.
CALL add_index_if_not_exists_180('webmail_conversation_members', 'idx_user_folder_internal_date',
    '`user_email`, `folder_id`, `internal_date` DESC');

-- Flagged-only list ("starred") view.
CALL add_index_if_not_exists_180('webmail_conversation_members', 'idx_user_folder_flagged',
    '`user_email`, `folder_id`, `is_flagged`');

DROP PROCEDURE IF EXISTS add_column_if_not_exists_180;
DROP PROCEDURE IF EXISTS add_index_if_not_exists_180;
