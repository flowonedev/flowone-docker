<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Sftp;

use PDO;

/**
 * All sftp_sessions / sftp_sync_state persistence for SFTP session tracking.
 *
 * Kept separate from the ingestor (which owns journald reading + parsing)
 * and the agent action (which owns request shaping) so the SQL lives in one
 * focused, testable place. Every write is idempotent: re-ingesting an
 * overlapping journal window upserts the same `session_key` row rather than
 * duplicating it.
 */
class SftpSessionStore
{
    private const CURSOR_KEY = 'journal_cursor';

    public function __construct(protected readonly PDO $db)
    {
    }

    /**
     * Create the tables if a deploy hasn't run the migration yet. Idempotent;
     * mirrors the panel's "endpoints self-heal the schema" convention.
     */
    public function ensureSchema(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS sftp_sessions (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                session_key VARCHAR(128) NOT NULL,
                sftp_user_id INT UNSIGNED NULL,
                linux_username VARCHAR(32) NOT NULL,
                domain VARCHAR(253) NULL,
                client_ip VARCHAR(45) NULL,
                conn_pid INT UNSIGNED NULL,
                login_at DATETIME NOT NULL,
                logout_at DATETIME NULL,
                duration_seconds INT UNSIGNED NULL,
                bytes_uploaded BIGINT UNSIGNED NOT NULL DEFAULT 0,
                bytes_downloaded BIGINT UNSIGNED NOT NULL DEFAULT 0,
                files_uploaded INT UNSIGNED NOT NULL DEFAULT 0,
                files_downloaded INT UNSIGNED NOT NULL DEFAULT 0,
                status ENUM('open','closed') NOT NULL DEFAULT 'open',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_session_key (session_key),
                INDEX idx_username (linux_username),
                INDEX idx_sftp_user_id (sftp_user_id),
                INDEX idx_login_at (login_at),
                INDEX idx_status (status),
                INDEX idx_open_pid (conn_pid, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS sftp_sync_state (
                k VARCHAR(64) PRIMARY KEY,
                v TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    // ─── Cursor ───────────────────────────────────────────────

    public function getCursor(): ?string
    {
        $stmt = $this->db->prepare('SELECT v FROM sftp_sync_state WHERE k = ? LIMIT 1');
        $stmt->execute([self::CURSOR_KEY]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null || $v === '') ? null : (string) $v;
    }

    public function setCursor(string $cursor): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO sftp_sync_state (k, v) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE v = VALUES(v)'
        );
        $stmt->execute([self::CURSOR_KEY, $cursor]);
    }

    // ─── User map (which Linux names we actually track) ───────

    /**
     * @return array<string,array{id:int,domain:?string}> keyed by linux_username
     */
    public function knownUsers(): array
    {
        $out = [];
        foreach ($this->db->query('SELECT id, linux_username, domain FROM sftp_users') as $row) {
            $out[(string) $row['linux_username']] = [
                'id' => (int) $row['id'],
                'domain' => $row['domain'] !== null ? (string) $row['domain'] : null,
            ];
        }
        return $out;
    }

    // ─── Session lifecycle ────────────────────────────────────

    /** Create (or no-op if already present) an open session row. */
    public function openSession(
        string $sessionKey,
        string $username,
        ?int $userId,
        ?string $domain,
        ?string $clientIp,
        int $pid,
        float $loginTs
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO sftp_sessions
                (session_key, sftp_user_id, linux_username, domain, client_ip, conn_pid, login_at, status)
             VALUES (?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), "open")
             ON DUPLICATE KEY UPDATE
                client_ip = VALUES(client_ip),
                conn_pid  = VALUES(conn_pid)'
        );
        $stmt->execute([$sessionKey, $userId, $username, $domain, $clientIp, $pid, (int) $loginTs]);
    }

    /** Open session id for a connection PID, or null. */
    public function findOpenIdByPid(int $pid): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM sftp_sessions
             WHERE conn_pid = ? AND status = "open"
             ORDER BY login_at DESC LIMIT 1'
        );
        $stmt->execute([$pid]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function addBytes(int $id, int $bytesRead, int $bytesWritten): void
    {
        // read = downloaded by client, written = uploaded by client.
        $stmt = $this->db->prepare(
            'UPDATE sftp_sessions SET
                bytes_downloaded = bytes_downloaded + ?,
                bytes_uploaded   = bytes_uploaded + ?,
                files_downloaded = files_downloaded + ?,
                files_uploaded   = files_uploaded + ?
             WHERE id = ?'
        );
        $stmt->execute([
            $bytesRead,
            $bytesWritten,
            $bytesRead > 0 ? 1 : 0,
            $bytesWritten > 0 ? 1 : 0,
            $id,
        ]);
    }

    public function closeSession(int $id, float $logoutTs): void
    {
        $stmt = $this->db->prepare(
            'UPDATE sftp_sessions SET
                logout_at = FROM_UNIXTIME(?),
                duration_seconds = GREATEST(0, ? - UNIX_TIMESTAMP(login_at)),
                status = "closed"
             WHERE id = ? AND status = "open"'
        );
        $stmt->execute([(int) $logoutTs, (int) $logoutTs, $id]);
    }

    /** Update the rolling last-login telemetry on the sftp_users row. */
    public function touchAggregate(int $userId, ?string $clientIp, float $loginTs): void
    {
        $stmt = $this->db->prepare(
            'UPDATE sftp_users SET
                last_login_at = FROM_UNIXTIME(?),
                last_login_ip = ?,
                login_count = login_count + 1
             WHERE id = ?'
        );
        $stmt->execute([(int) $loginTs, $clientIp, $userId]);
    }

    // ─── Reads for the UI ─────────────────────────────────────

    /**
     * Recent sessions for one tracked user, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function listForUser(int $userId, int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $stmt = $this->db->prepare(
            'SELECT id, client_ip, login_at, logout_at, duration_seconds,
                    bytes_uploaded, bytes_downloaded, files_uploaded, files_downloaded, status
             FROM sftp_sessions
             WHERE sftp_user_id = ?
             ORDER BY login_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Per-user roll-up for the list view: how many sessions are open right
     * now (online indicator), total sessions, and lifetime bytes moved.
     * One GROUP BY query for the whole page (no N+1).
     *
     * @param list<int> $userIds
     * @return array<int,array{active:int,sessions:int,total_bytes:int}>
     */
    public function aggregatesForUsers(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        $sql = "SELECT sftp_user_id,
                       SUM(status = 'open') AS active,
                       COUNT(*) AS sessions,
                       COALESCE(SUM(bytes_uploaded + bytes_downloaded), 0) AS total_bytes
                FROM sftp_sessions
                WHERE sftp_user_id IN ({$in})
                GROUP BY sftp_user_id";
        $out = [];
        foreach ($this->db->query($sql) as $row) {
            $out[(int) $row['sftp_user_id']] = [
                'active' => (int) $row['active'],
                'sessions' => (int) $row['sessions'],
                'total_bytes' => (int) $row['total_bytes'],
            ];
        }
        return $out;
    }

    /** @return array<string,int> totals across all sessions for a user. */
    public function totalsForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                COUNT(*) AS sessions,
                COALESCE(SUM(bytes_uploaded), 0) AS bytes_uploaded,
                COALESCE(SUM(bytes_downloaded), 0) AS bytes_downloaded,
                COALESCE(SUM(files_uploaded), 0) AS files_uploaded,
                COALESCE(SUM(files_downloaded), 0) AS files_downloaded,
                COALESCE(SUM(duration_seconds), 0) AS duration_seconds
             FROM sftp_sessions WHERE sftp_user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'sessions' => (int) ($row['sessions'] ?? 0),
            'bytes_uploaded' => (int) ($row['bytes_uploaded'] ?? 0),
            'bytes_downloaded' => (int) ($row['bytes_downloaded'] ?? 0),
            'files_uploaded' => (int) ($row['files_uploaded'] ?? 0),
            'files_downloaded' => (int) ($row['files_downloaded'] ?? 0),
            'duration_seconds' => (int) ($row['duration_seconds'] ?? 0),
        ];
    }

    // ─── Maintenance ──────────────────────────────────────────

    /** Delete sessions whose login is older than $days. Returns rows removed. */
    public function pruneOlderThan(int $days): int
    {
        $days = max(1, $days);
        $stmt = $this->db->prepare(
            'DELETE FROM sftp_sessions WHERE login_at < (NOW() - INTERVAL ? DAY)'
        );
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }

    /**
     * Force-close sessions left 'open' for longer than $hours (a missed
     * logout line, e.g. a hard network drop). Best-effort tidiness so the
     * UI doesn't show ancient "open" sessions forever.
     */
    public function closeStaleOpen(int $hours): int
    {
        $hours = max(1, $hours);
        $stmt = $this->db->prepare(
            'UPDATE sftp_sessions
             SET status = "closed",
                 logout_at = COALESCE(logout_at, updated_at),
                 duration_seconds = COALESCE(duration_seconds,
                     GREATEST(0, UNIX_TIMESTAMP(updated_at) - UNIX_TIMESTAMP(login_at)))
             WHERE status = "open" AND login_at < (NOW() - INTERVAL ? HOUR)'
        );
        $stmt->execute([$hours]);
        return $stmt->rowCount();
    }
}
