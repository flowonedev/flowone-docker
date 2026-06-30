<?php

declare(strict_types=1);

/**
 * SFTP Session Tracking Action
 *
 * Read + sync surface for the per-session activity log of the additional
 * restricted SFTP users (sftp_users). Orchestration only:
 *   - sftpSession.sync : drain new sshd/internal-sftp journal entries into
 *                        sftp_sessions (called every minute by
 *                        scripts/sftp-session-sync.php, and on demand by an
 *                        admin). Delegates to SftpSessionIngestor.
 *   - sftpSession.list : recent sessions + lifetime totals for one user, for
 *                        the dashboard. Delegates to SftpSessionStore.
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\PanelDb;
use VpsAdmin\Agent\Sftp\SftpSessionIngestor;
use VpsAdmin\Agent\Sftp\SftpSessionParser;
use VpsAdmin\Agent\Sftp\SftpSessionStore;

class SftpSessionAction extends BaseAction
{
    private const DEFAULT_RETENTION_DAYS = 90;

    public function getNamespace(): string
    {
        return 'sftpSession';
    }

    public function getMethods(): array
    {
        return ['sync', 'list'];
    }

    public function requiresBackup(string $method): bool
    {
        // Pure DB reads/writes on app tables; no config files to back up.
        return false;
    }

    /**
     * Drain new journal entries into sftp_sessions. Idempotent and safe to
     * call as often as the cron fires (default every minute).
     *
     * Params:
     *   retention_days (optional int) prune sessions older than this (default 90)
     */
    protected function actionSync(array $params, string $actor): array
    {
        $db = PanelDb::get();
        if ($db === null) {
            return $this->error('Panel database unavailable');
        }

        $retention = isset($params['retention_days'])
            ? max(1, (int) $params['retention_days'])
            : self::DEFAULT_RETENTION_DAYS;

        try {
            $store = new SftpSessionStore($db);
            $ingestor = new SftpSessionIngestor($store, new SftpSessionParser());
            $summary = $ingestor->run($retention);
            return $this->success($summary, 'SFTP sessions synced');
        } catch (\Throwable $e) {
            $this->logger->error('sftpSession.sync failed', ['error' => $e->getMessage()]);
            return $this->error('SFTP session sync failed: ' . $e->getMessage());
        }
    }

    /**
     * Recent sessions + lifetime totals for one SFTP user.
     *
     * Params:
     *   id     (required int)    sftp_users.id
     *   domain (optional string) when set (per-site endpoint), enforces scope
     *   limit  (optional int)    max sessions to return (default 100, max 500)
     */
    protected function actionList(array $params, string $actor): array
    {
        $db = PanelDb::get();
        if ($db === null) {
            return $this->error('Panel database unavailable');
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return $this->error('id is required');
        }

        $stmt = $db->prepare('SELECT id, domain, linux_username FROM sftp_users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$user) {
            return $this->error('SFTP user not found');
        }
        if (!empty($params['domain']) && $user['domain'] !== $params['domain']) {
            return $this->error('SFTP user does not belong to this site');
        }

        $limit = (int) ($params['limit'] ?? 0);
        if ($limit <= 0) {
            $limit = 100;
        }

        try {
            $store = new SftpSessionStore($db);
            $store->ensureSchema();
            return $this->success([
                'linux_username' => $user['linux_username'],
                'sessions' => $store->listForUser($id, $limit),
                'totals' => $store->totalsForUser($id),
            ]);
        } catch (\Throwable $e) {
            return $this->error('Could not load SFTP sessions: ' . $e->getMessage());
        }
    }
}
