-- Migration 170: webmail_conversation_members.is_seen
--
-- Adds a per-message read-state column to the conversation members table so
-- that ConversationService::updateMemberReadStatus can keep
-- webmail_conversations.unread_count in sync without re-querying IMAP.
--
-- Without this column, marking a message read only invalidates the Redis
-- cache and the next DB read returns the original (stale) unread_count,
-- which makes the conversation-level unread badge revert in the UI even
-- though the underlying IMAP flag is correctly stored.
--
-- The column is nullable-with-default to keep ALTERing the (potentially
-- large) members table non-blocking on MariaDB 10.4+.

DROP PROCEDURE IF EXISTS add_column_if_not_exists_170;
DELIMITER //
CREATE PROCEDURE add_column_if_not_exists_170(
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

DROP PROCEDURE IF EXISTS add_index_if_not_exists_170;
DELIMITER //
CREATE PROCEDURE add_index_if_not_exists_170(
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

CALL add_column_if_not_exists_170('webmail_conversation_members', 'is_seen',
    'TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Per-message read state mirrored from IMAP \\Seen flag. Maintained by ConversationService::updateMemberReadStatus and rebuildConversationUnreadCount.''');

-- Composite index supporting the unread recompute query
--   SELECT COUNT(*) FROM webmail_conversation_members
--   WHERE user_email = ? AND conversation_id = ? AND is_seen = 0
-- which is the only access pattern this column enables.
CALL add_index_if_not_exists_170('webmail_conversation_members', 'idx_conv_member_unread',
    '`user_email`, `conversation_id`, `is_seen`');

DROP PROCEDURE IF EXISTS add_column_if_not_exists_170;
DROP PROCEDURE IF EXISTS add_index_if_not_exists_170;
