-- ─────────────────────────────────────────────────────────────────────────────
-- 166_message_mentions.sql
--
-- @mentions index. One row per (message, mentioned-email) pair, so a single
-- message that mentions three people produces three rows.
--
-- Tenancy model:
--   `owner_email`     = the inbox owner who can see this mention (the
--                       recipient on inbound, the sender on the saved Sent
--                       copy). This is the column the `mentions:me` query
--                       filters on.
--   `mentioned_email` = the canonical lowercase address that was @mentioned.
--   `mentioned_user_email` = NULL for external mentions, otherwise the same
--                       address — but populated only after identity check
--                       (the address corresponds to a real local user). The
--                       split lets us later expose "external mentions of me"
--                       vs "internal mentions of me" without re-parsing.
--
-- Source of truth:
--   `message_id` = the canonical (angle-bracket-stripped) RFC 5322 Message-ID
--                  of the email. Sent-folder IMAP UIDs are unreliable at send
--                  time (assigned later when the Sent folder syncs), so we
--                  intentionally key on message_id and not UID.
--
-- Trust hierarchy — purely informational today, drives the UI badge in the
-- mention notification:
--   verified  = sender is the local account holder (you @-mentioned yourself
--               in a draft, basically — rare but possible)
--   internal  = sender domain matches recipient domain
--   external  = sender domain differs
--
-- Dedup:
--   UNIQUE (owner_email, message_id, mentioned_email_norm) — guarantees that
--   re-processing the same email never inserts a second row. Combined with
--   INSERT … ON DUPLICATE KEY UPDATE this also doubles as the idempotency
--   guard for the cron / reindex paths.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS webmail_message_mentions (
    id                     BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_email            VARCHAR(255)     NOT NULL,
    message_id             VARCHAR(998)     NOT NULL,           -- RFC 5322 line-length cap
    folder                 VARCHAR(1024)    DEFAULT NULL,       -- IMAP folder when known
    uid                    INT UNSIGNED     DEFAULT NULL,       -- IMAP UID when known (may be NULL on outbound)
    direction              ENUM('inbound','outbound') NOT NULL,
    sender_email           VARCHAR(255)     NOT NULL,           -- canonical, lowercase
    mentioned_email        VARCHAR(255)     NOT NULL,           -- original-cased display
    mentioned_email_norm   VARCHAR(255)     NOT NULL,           -- lowercased + punycode (for matching)
    mentioned_user_email   VARCHAR(255)     DEFAULT NULL,       -- non-NULL → resolved local user
    mention_text           VARCHAR(255)     DEFAULT NULL,       -- the literal `@robert` token as it appeared
    trust                  ENUM('verified','internal','external') NOT NULL DEFAULT 'external',
    subject                VARCHAR(998)     DEFAULT NULL,       -- denormalised for the notification UI
    sent_at                TIMESTAMP        NULL DEFAULT NULL,  -- mail Date header
    created_at             TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_owner_msg_mentioned (owner_email, message_id, mentioned_email_norm),
    KEY idx_owner_recent  (owner_email, created_at),
    KEY idx_mentioned_norm (mentioned_email_norm),
    KEY idx_message_id     (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────────────
-- Bolt a `dedup_hash` onto the existing `notifications` table so we can
-- INSERT … ON DUPLICATE KEY UPDATE for mention notifications without
-- re-engineering the existing TrackingService::createNotification call sites.
--
-- Hash convention: sha256("{user_email}|{type}|{stable-key}") — for mention
-- notifications, stable-key is the canonical Message-ID. For other types we
-- leave it NULL and the column has no effect (uniqueness is enforced only
-- when the column is NON-NULL, which is exactly how the UNIQUE works in
-- MySQL: NULLs are treated as distinct).
-- ─────────────────────────────────────────────────────────────────────────────

-- Note: ALTERs that may have already been applied are tolerated by the
-- MigrationService idempotency rule (it treats "Duplicate column" and
-- "Duplicate key" PDO errors as success).
ALTER TABLE notifications
    ADD COLUMN dedup_hash CHAR(64) DEFAULT NULL AFTER data;

ALTER TABLE notifications
    ADD UNIQUE KEY uq_user_dedup (user_email, dedup_hash);
