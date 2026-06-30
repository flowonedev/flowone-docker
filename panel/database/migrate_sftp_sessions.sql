-- =====================================================
-- SFTP Session Tracking
--
-- Per-session activity log for the additional restricted SFTP users in
-- `sftp_users`. One row per login -> logout, carrying:
--   - when they logged in / out and how long the session lasted,
--   - the client IP,
--   - how much data moved (bytes + file counts), split by direction.
--
-- Rows are populated by parsing the systemd journal (sshd + internal-sftp
-- with `-l INFO`) on a 1-minute cadence; see scripts/sftp-session-sync.php
-- and the agent action `sftpSession.sync`. A session is created `open` on
-- the "Accepted" auth line and flipped to `closed` (with logout_at +
-- duration) on the matching "Disconnected"/"session closed" line. Transfer
-- byte counters are accumulated from each sftp `close "..." bytes read N
-- written M` line in between.
--
-- Byte direction (from the server's point of view, as OpenSSH logs it):
--   bytes read    = server read the file  -> client DOWNLOAD  -> bytes_downloaded
--   bytes written = server wrote the file -> client UPLOAD    -> bytes_uploaded
--
-- Apply on server:
--   mysql -u vpsadmin -p'7bcf619af819e4e274e5cfdfba022274' \
--     devc_vps_dash < /var/www/vps-admin/database/migrate_sftp_sessions.sql
-- =====================================================

CREATE TABLE IF NOT EXISTS sftp_sessions (
    id                BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    -- Correlation key: linux_username + ':' + connection PID + ':' + login
    -- unix timestamp. Unique so re-parsing the same journal window (or an
    -- overlapping cursor) upserts the same row instead of duplicating it.
    session_key       VARCHAR(128) NOT NULL,

    -- Soft link to sftp_users.id (resolved at ingest time by linux_username).
    -- Nullable so a session whose user row was deleted is still retained.
    sftp_user_id      INT UNSIGNED NULL,

    linux_username    VARCHAR(32) NOT NULL,
    domain            VARCHAR(253) NULL,
    client_ip         VARCHAR(45) NULL,

    -- Connection PID. Transfer + logout journal lines arrive in a later
    -- sync run than the login (and the transfer lines carry no username),
    -- so we correlate them back to the open session by PID.
    conn_pid          INT UNSIGNED NULL,

    login_at          DATETIME NOT NULL,
    logout_at         DATETIME NULL,
    duration_seconds  INT UNSIGNED NULL,

    bytes_uploaded    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_downloaded  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    files_uploaded    INT UNSIGNED NOT NULL DEFAULT 0,
    files_downloaded  INT UNSIGNED NOT NULL DEFAULT 0,

    -- open   = login seen, awaiting logout (counters still accumulating)
    -- closed = logout seen, final
    status            ENUM('open','closed') NOT NULL DEFAULT 'open',

    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_session_key (session_key),
    INDEX idx_username (linux_username),
    INDEX idx_sftp_user_id (sftp_user_id),
    INDEX idx_login_at (login_at),
    INDEX idx_status (status),
    INDEX idx_open_pid (conn_pid, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Small key/value scratch for the ingestor: the journald cursor high-water
-- mark (`journal_cursor`) so each run only reads new entries.
CREATE TABLE IF NOT EXISTS sftp_sync_state (
    k           VARCHAR(64) PRIMARY KEY,
    v           TEXT NULL,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
