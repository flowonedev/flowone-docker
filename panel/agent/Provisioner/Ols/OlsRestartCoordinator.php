<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Ols;

use VpsAdmin\Agent\Provisioner\Services\SiteLock;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

/**
 * Serialize and debounce OLS restarts.
 *
 * Why we need this:
 *   - Three concurrent create jobs each calling `lswsctrl restart`
 *     produce three restarts in 1-2 seconds; the second one usually
 *     races the first, the third gets EBUSY, OLS half-loads vhost
 *     state, and some sites end up returning 503 until manually nudged.
 *   - The legacy code retries restarts 3x with no coordination - which
 *     turns one bad reload into three.
 *
 * Strategy:
 *   1. Acquire a panel-DB lock named `ols_restart` for the duration of
 *      the restart. Two callers cannot both hold it; the second one
 *      blocks (or fails fast with $blocking=false).
 *   2. Inside the lock, check `last_restart_at` in the DB. If less than
 *      $debounceMs ago, the previous restart already covered our
 *      change - return without restarting.
 *   3. Otherwise call the injected `exec(lswsctrl, ['restart'])`
 *      closure, record the new `last_restart_at`, release the lock,
 *      return.
 *
 * This makes parallel "I need an OLS restart" callers naturally
 * coalesce into one actual restart per debounce window.
 *
 * We deliberately reuse SiteLock (via the synthetic domain
 * "::ols_restart::") rather than building a second lock primitive.
 * One inspectable lock model is easier to operate.
 */
final class OlsRestartCoordinator
{
    public const SYNTHETIC_LOCK_DOMAIN = '::ols_restart::';
    public const DEFAULT_DEBOUNCE_MS = 2000;
    public const DEFAULT_LOCK_TTL_SECONDS = 60;

    public function __construct(
        private readonly PanelDatabase $database,
        private readonly SiteLock $siteLock,
        /**
         * fn(string $binary, array $args): array{exit:int,stdout:string,stderr:string}
         */
        private readonly \Closure $execCommand,
        private readonly string $lswsctrlBin = '/usr/local/lsws/bin/lswsctrl',
        private readonly int $debounceMs = self::DEFAULT_DEBOUNCE_MS,
        private readonly int $lockTtlSeconds = self::DEFAULT_LOCK_TTL_SECONDS
    ) {
    }

    /**
     * Request an OLS restart.
     *
     * Returns one of:
     *   - 'restarted'  - we acquired the lock and restarted OLS.
     *   - 'debounced'  - another restart finished too recently, no-op.
     *   - 'contended'  - we couldn't get the lock and gave up
     *                    (only when $blocking=false).
     *
     * @param string $holderId      caller identity (worker id, "cli:foo", etc.)
     * @param string $requestId     correlation id for the audit trail
     * @param bool   $blocking      wait for the lock or fail fast
     * @param int    $maxWaitMs     max wait time when $blocking=true
     *
     * @throws \RuntimeException if lswsctrl exits non-zero
     */
    public function request(
        string $holderId,
        string $requestId,
        bool $blocking = true,
        int $maxWaitMs = 30000
    ): string {
        $handle = $this->acquireLock($holderId, $requestId, $blocking, $maxWaitMs);
        if ($handle === null) {
            return 'contended';
        }

        try {
            if ($this->isDebounced()) {
                return 'debounced';
            }
            $this->executeRestart();
            $this->recordRestartTimestamp($holderId);
            return 'restarted';
        } finally {
            $handle->release();
        }
    }

    /**
     * The timestamp of the most recent successful restart, or null if
     * we've never restarted in this DB era. Useful for the reconciler
     * and audit views.
     */
    public function lastRestartAt(): ?\DateTimeImmutable
    {
        $pdo = $this->database->pdo();
        $row = $pdo->query(
            "SELECT MAX(occurred_at) FROM site_audit_log WHERE action = 'ols_restart'"
        )->fetchColumn();
        if ($row === false || $row === null) {
            return null;
        }
        return new \DateTimeImmutable((string) $row);
    }

    private function acquireLock(
        string $holderId,
        string $requestId,
        bool $blocking,
        int $maxWaitMs
    ): ?\VpsAdmin\Agent\Provisioner\Services\SiteLockHandle {
        if (!$blocking) {
            return $this->siteLock->tryAcquire(
                self::SYNTHETIC_LOCK_DOMAIN,
                $holderId,
                'ols restart',
                $requestId,
                $this->lockTtlSeconds,
            );
        }

        $deadline = microtime(true) + ($maxWaitMs / 1000);
        $backoffMs = 50;
        while (true) {
            $handle = $this->siteLock->tryAcquire(
                self::SYNTHETIC_LOCK_DOMAIN,
                $holderId,
                'ols restart',
                $requestId,
                $this->lockTtlSeconds,
            );
            if ($handle !== null) {
                return $handle;
            }
            if (microtime(true) >= $deadline) {
                return null;
            }
            usleep($backoffMs * 1000);
            // Light exponential backoff capped at 500ms.
            $backoffMs = min(500, $backoffMs * 2);
        }
    }

    private function isDebounced(): bool
    {
        $last = $this->lastRestartAt();
        if ($last === null) {
            return false;
        }
        $sinceMs = (int) ((microtime(true) - $last->getTimestamp()) * 1000);
        return $sinceMs < $this->debounceMs;
    }

    private function executeRestart(): void
    {
        $result = ($this->execCommand)($this->lswsctrlBin, ['restart']);
        $exit = $result['exit'] ?? -1;
        if ($exit !== 0) {
            $stderr = $result['stderr'] ?? '';
            throw new \RuntimeException(
                "lswsctrl restart failed (exit {$exit}): " . substr($stderr, 0, 500)
            );
        }
    }

    private function recordRestartTimestamp(string $holderId): void
    {
        $pdo = $this->database->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO site_audit_log
                (occurred_at, action, actor_username, source_ip, reason)
              VALUES
                (NOW(3), :action, :actor, NULL, :reason)'
        );
        $stmt->execute([
            'action' => 'ols_restart',
            'actor' => $holderId,
            'reason' => 'coalesced restart',
        ]);
    }
}
