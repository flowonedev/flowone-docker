-- Migration 178: imap_outbox
--
-- The durable write queue that decouples the UI's request path from
-- IMAP latency. Every user-initiated state change (mark-read, move,
-- delete, rename, etc.) is committed to MariaDB in the same transaction
-- as an outbox row that the mailsync worker later drains to IMAP with
-- retry/backoff.
--
-- Design choices:
--   * `op` is an ENUM, not a free-form string, so the worker can switch
--     on it without parsing JSON.
--   * `payload` is JSON for op-specific extras (target folder names,
--     flag specs, batch lists). The fixed columns above it cover the
--     90% case so the most common queries do not need JSON_EXTRACT.
--   * Idempotency is enforced via a stable hash key computed by
--     OutboxService::computeIdempotencyKey. UNIQUE on idempotency_key
--     means a retry of the same logical operation is a no-op insert
--     instead of a duplicate IMAP write.
--   * `status` is ENUM not TINYINT because grafana/log filters are
--     read frequently and "status='dead'" is more obvious than =4.
--   * The reaper relies on `claimed_at` to detect rows stuck in
--     `running` (worker crash mid-batch). After 5 minutes a stuck
--     row is reset to `pending` by the supervisor.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + ADD INDEX IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS imap_outbox (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_email VARCHAR(255) NOT NULL
        COMMENT 'Owning user (login email)',
    account_email VARCHAR(255) NOT NULL
        COMMENT 'IMAP account email (= user_email for primary, OAuth or aliased mailbox for secondaries)',
    op ENUM(
        'set_flag',
        'clear_flag',
        'move',
        'copy',
        'delete',
        'rename_folder',
        'create_folder',
        'delete_folder'
    ) NOT NULL,
    folder_id CHAR(36) NULL
        COMMENT 'Source folder identity (webmail_folder_identity.id). NULL for create_folder.',
    uid INT UNSIGNED NULL
        COMMENT 'IMAP UID at source folder. NULL for folder-level ops.',
    target_folder_id CHAR(36) NULL
        COMMENT 'Destination folder identity (for move / copy ops).',
    payload JSON NOT NULL
        COMMENT 'Op-specific extras: {flag, value, imapFlags, target_path, old_path, new_path, ...}',
    idempotency_key CHAR(64) NOT NULL
        COMMENT 'sha256 of {user,op,folder_id,uid,target_folder_id,nonce}. UNIQUE so retries collapse.',
    status ENUM('pending','running','done','failed','dead') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL
        COMMENT 'Earliest moment a worker may claim this row (used for exponential backoff).',
    claimed_at DATETIME NULL
        COMMENT 'Set when status transitions to running. Reaper resets rows where claimed_at < NOW() - 5min.',
    last_error TEXT NULL,
    -- After a successful IMAP write the worker fills in the new UID so
    -- downstream readers of conversation_members can stop treating the
    -- placeholder uid as authoritative. NULL while pending; populated on done.
    result_uid INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_idempotency (idempotency_key),
    -- Worker claim query:
    --   SELECT * FROM imap_outbox WHERE status='pending' AND next_attempt_at <= NOW()
    --   ORDER BY id LIMIT N
    -- Composite index on (status, next_attempt_at) is the single most
    -- frequent lookup; matches the claim WHERE clause perfectly.
    INDEX idx_pending (status, next_attempt_at),
    -- Per-user observability + UI "sync issues" banner query:
    --   SELECT COUNT(*) FROM imap_outbox WHERE user_email=? AND status IN ('pending','failed','dead')
    INDEX idx_user (user_email, account_email, status),
    -- Reaper query (find stuck "running" rows):
    --   SELECT id FROM imap_outbox WHERE status='running' AND claimed_at < ?
    INDEX idx_claimed (status, claimed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
