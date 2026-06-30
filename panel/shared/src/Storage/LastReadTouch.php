<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 6d — Throttled "I was just read" stamping.
 *
 * DriveService calls touch() on every successful file-bytes read.
 * To avoid hammering the DB on chunked reads / thumbnail polls /
 * tight-loop downloaders, the UPDATE is conditional:
 *
 *   UPDATE drive_files SET last_read_at = NOW()
 *   WHERE id = :id
 *     AND tier_state IN ('hot','tiering')
 *     AND (last_read_at IS NULL OR last_read_at < cutoff)
 *
 * The conditional clause means MariaDB writes 0 rows when the row
 * was touched within the throttle window — cheap MVCC-friendly no-op,
 * no row lock acquired, no binlog entry generated.
 *
 * Why "hot/tiering only":
 *   - cold rows have no VPS shadow; touching them shouldn't shape
 *     future tier-down decisions (they're already cold). The next
 *     successful recall transitions them to hot, at which point the
 *     next read naturally stamps last_read_at.
 *   - recalling rows have an in-flight recall; once it finishes the
 *     state moves to hot and subsequent reads stamp normally.
 *   - lost rows: never touch.
 *
 * Idempotent + non-throwing:
 *   - All exceptions are swallowed and logged via the journal. A
 *     failed touch must NEVER block the user's actual read.
 */
final class LastReadTouch
{
    public const DEFAULT_MIN_INTERVAL_SEC = 60;

    /**
     * Process-local "I just touched this file_id" memo to elide even
     * the conditional UPDATE on rapid re-reads (e.g. when a single
     * request resolves the same path twice). Cleared on each PHP
     * request boundary, so no staleness across requests.
     *
     * @var array<int,int>  file_id => unix_ts of last process-local touch
     */
    private array $recentTouches = [];

    public function __construct(
        private \PDO $pdo,
        private string $tableName = 'drive_files',
        private int $minIntervalSec = self::DEFAULT_MIN_INTERVAL_SEC,
        private ?OperationJournal $journal = null,
    ) {}

    public static function build(\PDO $pdo, ?array $storageConfig = null, ?OperationJournal $journal = null): self
    {
        $cfg = $storageConfig ?? Config::load();
        $minInterval = (int) ($cfg['tier']['lru']['min_touch_interval_sec'] ?? self::DEFAULT_MIN_INTERVAL_SEC);
        return new self(
            pdo:            $pdo,
            tableName:      'drive_files',
            minIntervalSec: $minInterval,
            journal:        $journal,
        );
    }

    /**
     * Mark $fileId as just-read. Throttled to one DB write per
     * $this->minIntervalSec per file_id (per row, globally — the DB
     * enforces this via the conditional WHERE).
     *
     * Always returns void; failures are swallowed because a read
     * succeeded already and we will not punish that with an error.
     *
     * @return bool  true if a DB write was attempted, false if elided.
     */
    public function touch(int $fileId): bool
    {
        if ($fileId <= 0) {
            return false;
        }
        // Process-local elision: same request touching same file twice
        // can skip the DB entirely.
        $now = time();
        if (isset($this->recentTouches[$fileId])
            && ($now - $this->recentTouches[$fileId]) < $this->minIntervalSec) {
            return false;
        }

        try {
            // Driver-portable cutoff: MariaDB uses DATE_SUB(NOW(), ...);
            // SQLite (tests) uses datetime('now', '-N seconds').
            $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $secs = max(1, (int) $this->minIntervalSec); // sanitised int — safe to inline
            $cutoff = $driver === 'sqlite'
                ? "datetime('now', '-{$secs} seconds')"
                : "DATE_SUB(NOW(), INTERVAL {$secs} SECOND)";
            $nowExpr = $driver === 'sqlite' ? "datetime('now')" : "NOW()";

            $sql = "UPDATE {$this->tableName}
                    SET last_read_at = {$nowExpr}
                    WHERE id = :id
                      AND tier_state IN ('" . TierState::HOT . "', '" . TierState::TIERING . "')
                      AND (last_read_at IS NULL OR last_read_at < {$cutoff})";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $fileId, \PDO::PARAM_INT);
            $stmt->execute();
            // Always remember the touch attempt locally, even when the
            // DB elided it — saves the round-trip on the next call in
            // this request.
            $this->recentTouches[$fileId] = $now;
            return true;
        } catch (\Throwable $e) {
            // Read already succeeded; do not throw. Surface to the
            // journal so a recurring failure shows up in monitoring.
            $this->journal?->record('lru_touch_failed', [
                'file_id' => $fileId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Clear the per-process memo. Mainly for tests that simulate
     * "next request" without spawning a new PHP process.
     */
    public function resetMemo(): void
    {
        $this->recentTouches = [];
    }
}
