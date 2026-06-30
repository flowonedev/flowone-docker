<?php

declare(strict_types=1);

namespace Webmail\Addons\ProjectHub\Services;

use PDO;
use RuntimeException;

/**
 * Tokenized multi-file shares for client deliverables (Project Hub cards).
 */
class ProjectHubShareService
{
    private PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function userCanAccessCard(int $cardId, string $userEmail): bool
    {
        $email = strtolower(trim($userEmail));
        $stmt = $this->db->prepare("
            SELECT 1 FROM webmail_board_cards c
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            LEFT JOIN webmail_board_members bm ON bm.board_id = b.id AND LOWER(bm.user_email) = ?
            LEFT JOIN projecthub_card_assignees ca ON ca.card_id = c.id AND LOWER(ca.user_email) = ?
            WHERE c.id = ? AND (bm.user_email IS NOT NULL OR ca.user_email IS NOT NULL OR LOWER(b.owner_email) = ?)
            LIMIT 1
        ");
        $stmt->execute([$email, $email, $cardId, $email]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array<int, int> $driveFileIds
     * @param array{title?:?string,message?:?string,expires_at?:?string,max_downloads?:?int,password?:?string} $options
     * @return array<string, mixed>
     */
    public function createCardShare(int $cardId, string $createdBy, array $driveFileIds, array $options = []): array
    {
        $createdBy = strtolower(trim($createdBy));
        if (!$this->userCanAccessCard($cardId, $createdBy)) {
            throw new RuntimeException('Forbidden');
        }
        $driveFileIds = array_values(array_unique(array_map('intval', $driveFileIds)));
        if ($driveFileIds === []) {
            throw new RuntimeException('No files selected');
        }
        $this->assertDriveFilesTaggedForCard($cardId, $createdBy, $driveFileIds);

        $token = self::generateToken();
        $title = isset($options['title']) ? (string) $options['title'] : null;
        $message = isset($options['message']) ? (string) $options['message'] : null;
        $expiresAt = !empty($options['expires_at']) ? (string) $options['expires_at'] : null;
        $maxDl = isset($options['max_downloads']) && $options['max_downloads'] !== null && $options['max_downloads'] !== ''
            ? max(0, (int) $options['max_downloads']) : null;
        $pwd = !empty($options['password']) ? (string) $options['password'] : null;
        $hash = $pwd !== null && $pwd !== '' ? password_hash($pwd, PASSWORD_DEFAULT) : null;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO projecthub_card_shares
                    (card_id, share_token, created_by, title, message, expires_at, max_downloads, password_hash)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $cardId,
                $token,
                $createdBy,
                $title,
                $message,
                $expiresAt,
                $maxDl,
                $hash,
            ]);
            $shareId = (int) $this->db->lastInsertId();
            $insF = $this->db->prepare('
                INSERT INTO projecthub_card_share_files (share_id, drive_file_id, sort_order)
                VALUES (?, ?, ?)
            ');
            $order = 0;
            foreach ($driveFileIds as $fid) {
                $insF->execute([$shareId, $fid, $order++]);
            }
            $activity = new ProjectHubActivityService($this->config);
            $activity->log(
                $cardId,
                $createdBy,
                'client_share_created',
                ['share_id' => $shareId, 'share_token' => $token],
                false,
                true
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $row = $this->getShareById($shareId);
        if ($row) {
            unset($row['password_hash']);
        }
        $out = $row ?? ['id' => $shareId, 'share_token' => $token];

        try {
            $notif = new ProjectHubNotificationService($this->config);
            $cardTitle = $notif->getCardTitle($cardId) ?? 'Card';
            $notif->notifyCardAudience(
                $cardId,
                $createdBy,
                ['watchers'],
                'ph_share_created',
                'Client share created',
                $createdBy . ' created a deliverable share link for "' . $cardTitle . '"',
                ['share_id' => $shareId, 'share_token' => $token]
            );
        } catch (\Throwable $e) {
            error_log('[ProjectHubShareService] ph_share_created notify failed: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSharesForCard(int $cardId, string $userEmail): array
    {
        if (!$this->userCanAccessCard($cardId, strtolower(trim($userEmail)))) {
            return [];
        }
        $stmt = $this->db->prepare('SELECT * FROM projecthub_card_shares WHERE card_id = ? ORDER BY id DESC');
        $stmt->execute([$cardId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            unset($r['password_hash']);
        }
        unset($r);

        return $rows;
    }

    public function revokeShare(int $shareId, string $actorEmail): bool
    {
        $actor = strtolower(trim($actorEmail));
        $stmt = $this->db->prepare('SELECT s.* FROM projecthub_card_shares s WHERE s.id = ?');
        $stmt->execute([$shareId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $cardId = (int) $row['card_id'];
        if (!$this->userCanAccessCard($cardId, $actor)) {
            return false;
        }
        $this->db->prepare('UPDATE projecthub_card_shares SET revoked_at = NOW() WHERE id = ?')->execute([$shareId]);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPublicSharePayload(string $token, bool $incrementAccess): ?array
    {
        $share = $this->loadShareByToken($token);
        if (!$share) {
            return null;
        }
        if ($this->isShareLockedOrInactive($share)) {
            return null;
        }
        if ($incrementAccess) {
            $this->db->prepare('UPDATE projecthub_card_shares SET access_count = access_count + 1 WHERE id = ?')->execute([(int) $share['id']]);
        }

        $files = $this->buildFilePayloadForShare((int) $share['id'], $share['created_by']);

        return [
            'share' => [
                'id' => (int) $share['id'],
                'card_id' => (int) $share['card_id'],
                'title' => $share['title'],
                'message' => $share['message'],
                'expires_at' => $share['expires_at'],
                'max_downloads' => $share['max_downloads'],
                'download_count' => (int) $share['download_count'],
                'requires_password' => !empty($share['password_hash']),
                'revoked' => !empty($share['revoked_at']),
            ],
            'files' => $files,
        ];
    }

    /**
     * @return array{ok: bool, http: int, retry_after?: int}
     */
    public function validatePasswordWithRateLimit(string $token, string $password, string $clientIp, \Webmail\Services\RateLimiter $rateLimiter): array
    {
        $share = $this->loadShareByToken($token);
        if (!$share) {
            return ['ok' => false, 'http' => 404];
        }
        if (!empty($share['locked_until']) && strtotime((string) $share['locked_until']) > time()) {
            return ['ok' => false, 'http' => 423];
        }
        if (empty($share['password_hash'])) {
            return ['ok' => true, 'http' => 200];
        }
        $ok = password_verify($password, (string) $share['password_hash']);
        if ($ok) {
            $this->db->prepare('UPDATE projecthub_card_shares SET failed_password_attempts = 0, locked_until = NULL WHERE id = ?')
                ->execute([(int) $share['id']]);

            return ['ok' => true, 'http' => 200];
        }

        $ip = $clientIp ?: '0.0.0.0';
        $rl = $rateLimiter->allow('ph_share_pwd_fail:' . md5($ip . ':' . $token), 5, 900);
        if (!$rl['allowed']) {
            return ['ok' => false, 'http' => 429, 'retry_after' => $rl['retry_after']];
        }

        usleep(1_000_000);
        $this->db->prepare('
                UPDATE projecthub_card_shares
                SET failed_password_attempts = failed_password_attempts + 1,
                    locked_until = CASE WHEN failed_password_attempts + 1 >= 20 THEN DATE_ADD(NOW(), INTERVAL 1 HOUR) ELSE locked_until END
                WHERE id = ?
            ')->execute([(int) $share['id']]);

        return ['ok' => false, 'http' => 403];
    }

    public function isDriveFileInShare(int $shareId, int $driveFileId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM projecthub_card_share_files WHERE share_id = ? AND drive_file_id = ? LIMIT 1');
        $stmt->execute([$shareId, $driveFileId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Authorize a public download with explicit HTTP semantics (403 = wrong password or file not in share).
     *
     * @return array{ok: bool, http: int, error: string, data?: array<string, mixed>}
     */
    public function tryAuthorizeShareDownload(
        string $token,
        int $driveFileId,
        ?string $passwordPlain,
        \Webmail\Services\DriveService $driveService
    ): array {
        $share = $this->loadShareByToken($token);
        if (!$share) {
            return ['ok' => false, 'http' => 404, 'error' => 'not_found'];
        }

        $state = $this->classifyPublicToken($token);
        if ($state === 'locked') {
            return ['ok' => false, 'http' => 423, 'error' => 'locked'];
        }
        if ($state === 'revoked' || $state === 'expired') {
            return ['ok' => false, 'http' => 410, 'error' => $state];
        }

        if (!empty($share['max_downloads']) && (int) $share['download_count'] >= (int) $share['max_downloads']) {
            return ['ok' => false, 'http' => 410, 'error' => 'limit_reached'];
        }

        $shareId = (int) $share['id'];
        if (!$this->isDriveFileInShare($shareId, $driveFileId)) {
            return ['ok' => false, 'http' => 403, 'error' => 'file_not_in_share'];
        }

        if (!empty($share['password_hash'])) {
            $pwd = $passwordPlain ?? ($_GET['p'] ?? $_SERVER['HTTP_X_SHARE_PASSWORD'] ?? '');
            if (!password_verify((string) $pwd, (string) $share['password_hash'])) {
                return ['ok' => false, 'http' => 403, 'error' => 'invalid_password'];
            }
        }

        $stmt = $this->db->prepare("
            SELECT sf.id AS sf_id, sf.drive_file_id
            FROM projecthub_card_share_files sf
            JOIN projecthub_card_shares s ON s.id = sf.share_id
            WHERE s.share_token = ?
              AND sf.drive_file_id = ?
              AND s.revoked_at IS NULL
              AND (s.expires_at IS NULL OR s.expires_at > NOW())
              AND (s.max_downloads IS NULL OR s.download_count < s.max_downloads)
              AND (s.locked_until IS NULL OR s.locked_until < NOW())
            LIMIT 1
        ");
        $stmt->execute([$token, $driveFileId]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$link) {
            return ['ok' => false, 'http' => 410, 'error' => 'download_not_allowed'];
        }

        $owner = strtolower((string) $share['created_by']);
        $path = $driveService->getFilePath($owner, $driveFileId);
        if (!$path || !is_readable($path)) {
            return ['ok' => false, 'http' => 410, 'error' => 'file_unavailable'];
        }
        $fileRow = $driveService->getFile($owner, $driveFileId);
        if (!$fileRow) {
            return ['ok' => false, 'http' => 410, 'error' => 'file_unavailable'];
        }

        return [
            'ok' => true,
            'http' => 200,
            'error' => '',
            'data' => [
                'share' => $share,
                'owner_email' => $owner,
                'drive_file_id' => $driveFileId,
                'path' => $path,
                'mime' => (string) ($fileRow['mime_type'] ?? 'application/octet-stream'),
                'original_name' => (string) ($fileRow['original_name'] ?? 'download'),
                'sf_id' => (int) $link['sf_id'],
            ],
        ];
    }

    /**
     * @return array{share: array, owner_email: string, drive_file_id: int, path: string, mime: string, original_name: string, sf_id: int}|null
     */
    public function authorizeDownload(string $token, int $driveFileId, ?string $passwordPlain, \Webmail\Services\DriveService $driveService): ?array
    {
        $r = $this->tryAuthorizeShareDownload($token, $driveFileId, $passwordPlain, $driveService);

        return $r['ok'] ? ($r['data'] ?? null) : null;
    }

    public function incrementDownloadCounters(int $shareId, int $shareFileJoinId, int $driveFileId): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('
                UPDATE projecthub_card_share_files SET download_count = download_count + 1
                WHERE id = ? AND drive_file_id = ?
            ')->execute([$shareFileJoinId, $driveFileId]);
            $this->db->prepare('
                UPDATE projecthub_card_shares SET download_count = download_count + 1 WHERE id = ?
            ')->execute([$shareId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Transaction-wrapped download record (per 1X.3): re-verify file ↔ share linkage,
     * increment per-file + share counters, log activity. All-or-nothing — if any step
     * fails, no counter is incremented and no activity row is written.
     *
     * @return array{share_id: int, share_file_id: int, share_download_count: int, file_download_count: int}
     */
    public function recordDownload(int $shareId, int $driveFileId): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                SELECT s.id AS share_id, s.card_id, sf.id AS share_file_id,
                       s.download_count, sf.download_count AS file_download_count
                FROM projecthub_card_share_files sf
                JOIN projecthub_card_shares s ON s.id = sf.share_id
                WHERE s.id = ?
                  AND sf.drive_file_id = ?
                  AND s.revoked_at IS NULL
                  AND (s.expires_at IS NULL OR s.expires_at > NOW())
                  AND (s.max_downloads IS NULL OR s.download_count < s.max_downloads)
                  AND (s.locked_until IS NULL OR s.locked_until < NOW())
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$shareId, $driveFileId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('download_not_allowed');
            }

            $this->db->prepare('
                UPDATE projecthub_card_share_files SET download_count = download_count + 1
                WHERE id = ?
            ')->execute([(int) $row['share_file_id']]);

            $this->db->prepare('
                UPDATE projecthub_card_shares SET download_count = download_count + 1 WHERE id = ?
            ')->execute([(int) $row['share_id']]);

            $activity = new ProjectHubActivityService($this->config);
            $activity->log(
                (int) $row['card_id'],
                'public-share',
                'client_share_download',
                [
                    'share_id' => (int) $row['share_id'],
                    'drive_file_id' => $driveFileId,
                ],
                false,
                true
            );

            $this->db->commit();

            return [
                'share_id' => (int) $row['share_id'],
                'share_file_id' => (int) $row['share_file_id'],
                'share_download_count' => (int) $row['download_count'] + 1,
                'file_download_count' => (int) $row['file_download_count'] + 1,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<int, int> $driveFileIds
     */
    private function assertDriveFilesTaggedForCard(int $cardId, string $userEmail, array $driveFileIds): void
    {
        $tag = '%[PH-' . $cardId . ']%';
        $stmt = $this->db->prepare("
            SELECT id FROM drive_files
            WHERE user_email = ? AND id = ? AND original_name LIKE ?
              AND (is_trashed = 0 OR is_trashed IS NULL)
            LIMIT 1
        ");
        foreach ($driveFileIds as $fid) {
            $stmt->execute([strtolower($userEmail), $fid, $tag]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('Drive file ' . $fid . ' is not linked to this card');
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getShareById(int $shareId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM projecthub_card_shares WHERE id = ?');
        $stmt->execute([$shareId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findShareByToken(string $token): ?array
    {
        return $this->loadShareByToken($token);
    }

    public function classifyPublicToken(string $token): string
    {
        $s = $this->loadShareByToken($token);
        if (!$s) {
            return 'missing';
        }
        if (!empty($s['revoked_at'])) {
            return 'revoked';
        }
        if (!empty($s['expires_at']) && strtotime((string) $s['expires_at']) <= time()) {
            return 'expired';
        }
        if (!empty($s['locked_until']) && strtotime((string) $s['locked_until']) > time()) {
            return 'locked';
        }

        return 'ok';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadShareByToken(string $token): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM projecthub_card_shares WHERE share_token = ? LIMIT 1');
        $stmt->execute([$token]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ?: null;
    }

    /**
     * @param array<string, mixed> $share
     */
    private function isShareLockedOrInactive(array $share): bool
    {
        if (!empty($share['revoked_at'])) {
            return true;
        }
        if (!empty($share['expires_at']) && strtotime((string) $share['expires_at']) <= time()) {
            return true;
        }
        if (!empty($share['locked_until']) && strtotime((string) $share['locked_until']) > time()) {
            return true;
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildFilePayloadForShare(int $shareId, string $createdBy): array
    {
        $owner = strtolower(trim($createdBy));
        $stmt = $this->db->prepare('
            SELECT sf.id AS share_file_id, sf.drive_file_id, sf.download_count AS file_download_count
            FROM projecthub_card_share_files sf
            WHERE sf.share_id = ?
            ORDER BY sf.sort_order ASC, sf.id ASC
        ');
        $stmt->execute([$shareId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $fid = (int) $row['drive_file_id'];
            $fstmt = $this->db->prepare('SELECT id, original_name, mime_type, size FROM drive_files WHERE user_email = ? AND id = ? LIMIT 1');
            $fstmt->execute([$owner, $fid]);
            $f = $fstmt->fetch(PDO::FETCH_ASSOC);
            if ($f) {
                $out[] = [
                    'share_file_id' => (int) $row['share_file_id'],
                    'drive_file_id' => $fid,
                    'original_name' => $f['original_name'],
                    'mime_type' => $f['mime_type'],
                    'size' => (int) $f['size'],
                    'file_download_count' => (int) $row['file_download_count'],
                    'unavailable' => false,
                ];
            } else {
                $out[] = [
                    'share_file_id' => (int) $row['share_file_id'],
                    'drive_file_id' => $fid,
                    'original_name' => null,
                    'mime_type' => null,
                    'size' => 0,
                    'file_download_count' => (int) $row['file_download_count'],
                    'unavailable' => true,
                ];
            }
        }

        return $out;
    }
}
