-- Wave 2 P1: state-interval path history + raw folder snapshots.
--
-- Two purpose-built tables for asynchronous rename analysis:
--
-- 1) webmail_folder_path_intervals
--    A state-interval representation of "which folder owned which path at
--    which time". Every folder always has exactly one OPEN interval (
--    valid_to IS NULL) for its current_path. When a rename is confirmed,
--    we close the old row (set valid_to = NOW()) and INSERT a new row
--    with valid_from = NOW(). This is more useful than the existing
--    `webmail_folder_path_history` event log because:
--      - getByPath(account_id, path, at_time) becomes a single point
--        query against an indexed range (no ORDER BY scan).
--      - Path uniqueness can be enforced: at any given moment a path
--        belongs to at most one folder.
--      - Reconciliation can detect drift trivially (count open
--        intervals != count distinct folder_ids per account).
--
-- 2) webmail_folder_snapshots
--    Each /mailbox/folders refresh writes one snapshot row for the
--    account, containing the raw IMAP folder listing as JSON. The
--    asynchronous rename analyzer consumes the LATEST unconsumed
--    snapshot per account, diffs it against the prior snapshot, and
--    runs detectRenames over the diff. Older snapshots are kept for
--    forensic replay (default 7 days) and pruned by a separate cron.
--    Unbounded snapshots would cost ~10MB per active account per week,
--    so the prune is non-optional.
--
-- All tables additive. No existing column is dropped or modified.

-- ----------------------------------------------------------------------
-- webmail_folder_path_intervals
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webmail_folder_path_intervals (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    folder_id     CHAR(36)        NOT NULL,
    account_id    VARCHAR(255)    NOT NULL,
    path          VARCHAR(1024)   NOT NULL,
    valid_from    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- NULL valid_to means "currently open" (this folder owns this path now).
    -- A non-NULL valid_to means the path was reassigned/renamed at that time.
    valid_to      TIMESTAMP       NULL,
    reason        ENUM('initial','rename','namespace_move','delimiter_change','reconcile')
                                  NOT NULL DEFAULT 'initial',
    KEY idx_account_path (account_id, path(255)),
    KEY idx_folder       (folder_id),
    -- Open-interval lookup: WHERE account_id = ? AND path = ? AND valid_to IS NULL
    KEY idx_open         (account_id, path(255), valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- webmail_folder_snapshots
-- ----------------------------------------------------------------------
-- One row per /mailbox/folders refresh per account. The async analyzer
-- pops the latest unconsumed snapshot per account; diffs it against the
-- prior snapshot for the same account; runs detectRenames; marks both
-- as consumed.
CREATE TABLE IF NOT EXISTS webmail_folder_snapshots (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id   VARCHAR(255)    NOT NULL,
    snapshot     LONGTEXT        NOT NULL,        -- JSON: array of folder rows
    folder_count INT UNSIGNED    NOT NULL DEFAULT 0,
    captured_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    consumed_at  TIMESTAMP       NULL,            -- non-null after analyzer drain
    request_id   VARCHAR(64)     NULL,            -- correlate to log lines
    KEY idx_account_captured (account_id, captured_at),
    KEY idx_account_consumed (account_id, consumed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
