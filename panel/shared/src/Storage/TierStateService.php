<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 4 tier-state service.
 *
 * Generic PDO-aware adapter on top of any "drive_files"-shaped table.
 * The shared library MUST NOT take a hard dependency on the email
 * backend's specific DB schema, so the table name and the audit-log
 * table name are constructor parameters. The columns themselves are
 * fixed by the migration contract (see 167_drive_tier_state.sql):
 *
 *   files table requires:
 *     id INT/BIGINT PK
 *     storage_location VARCHAR (legacy; kept in sync during phase 4-5)
 *     tier_state ENUM matching FlowOne\Storage\TierState constants
 *     tier_changed_at TIMESTAMP
 *     tier_changed_by VARCHAR
 *     tier_recall_attempts INT
 *
 *   audit table requires:
 *     id BIGINT PK auto
 *     file_id INT
 *     from_state ENUM, to_state ENUM
 *     actor VARCHAR
 *     reason VARCHAR NULL
 *     boot_epoch INT NULL
 *     bytes BIGINT NULL
 *     duration_ms INT NULL
 *     created_at TIMESTAMP
 *
 * All state mutations:
 *   1. Validate the requested transition via TierState::canTransition()
 *      (throws RuntimeException on illegal transition).
 *   2. UPDATE the file row inside a transaction.
 *   3. INSERT the audit log row inside the same transaction.
 *   4. Commit.
 *
 * If the journal is provided, telemetry events (tier_transition_ok,
 * tier_transition_rejected) are written to the operation journal so
 * the storage daemon's audit trail covers DB-side changes too.
 *
 * This class is consciously dumb about WHERE the bytes physically live
 * — it only manipulates state. The tier-down/recall workers (Phase 5)
 * are responsible for moving the bytes and calling transitionTo()
 * after each step.
 */
final class TierStateService
{
    public function __construct(
        private \PDO $pdo,
        private string $table = 'drive_files',
        private string $auditTable = 'drive_tier_transitions',
        private ?OperationJournal $journal = null,
    ) {
        // We need exceptions for fail-fast behaviour inside transactions.
        $current = $this->pdo->getAttribute(\PDO::ATTR_ERRMODE);
        if ($current !== \PDO::ERRMODE_EXCEPTION) {
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
    }

    /**
     * Look up the current tier state of a file. Returns null when the
     * row does not exist (caller decides whether that's an error).
     */
    public function getState(int $fileId): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT tier_state FROM `{$this->table}` WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $fileId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    /**
     * Full record needed by the tier-down worker. Returns null on miss.
     * Keys: id, tier_state, storage_location, tier_changed_at,
     *       tier_recall_attempts, size, checksum, nas_relative_path.
     *
     * @return array<string,mixed>|null
     */
    public function getRecord(int $fileId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, tier_state, storage_location, tier_changed_at,
                    tier_recall_attempts, size, checksum, nas_relative_path
             FROM `{$this->table}` WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $fileId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Transition a file from its current tier_state to $to. Validates
     * the transition is legal, writes the row update AND the audit-log
     * insert atomically. Returns true on success.
     *
     * @throws \RuntimeException when the transition is illegal or the
     *                           row does not exist.
     */
    public function transitionTo(
        int $fileId,
        string $to,
        string $actor,
        ?string $reason = null,
        ?int $bootEpoch = null,
        ?int $bytes = null,
        ?int $durationMs = null
    ): bool {
        if (!TierState::isValid($to)) {
            throw new \RuntimeException("invalid target tier_state: {$to}");
        }
        // We do the read inside the transaction so the canTransition
        // check is consistent with the UPDATE we're about to perform.
        $this->pdo->beginTransaction();
        try {
            // SELECT ... FOR UPDATE to serialise concurrent transitions
            // on the same file_id. SQLite (used in tests) does not
            // support FOR UPDATE — silently fall back when missing.
            $sql = "SELECT tier_state FROM `{$this->table}` WHERE id = :id";
            if ($this->driver() === 'mysql') {
                $sql .= ' FOR UPDATE';
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':id' => $fileId]);
            $from = $stmt->fetchColumn();
            if ($from === false) {
                throw new \RuntimeException("file_id {$fileId} not found");
            }
            $from = (string) $from;

            if (!TierState::canTransition($from, $to)) {
                $this->journal?->record('tier_transition_rejected', [
                    'file_id' => $fileId,
                    'from'    => $from,
                    'to'      => $to,
                    'actor'   => $actor,
                ]);
                throw new \RuntimeException(
                    "illegal tier_state transition: {$from} -> {$to} (file_id {$fileId})"
                );
            }

            // Update primary row.
            $legacy = TierState::toLegacyLocation($to);
            $recallExpr = $to === TierState::RECALLING
                ? '`tier_recall_attempts` + 1'
                : '`tier_recall_attempts`';
            $upd = $this->pdo->prepare(
                "UPDATE `{$this->table}`
                 SET tier_state = :to,
                     tier_changed_at = CURRENT_TIMESTAMP,
                     tier_changed_by = :actor,
                     storage_location = :legacy,
                     tier_recall_attempts = {$recallExpr}
                 WHERE id = :id"
            );
            $upd->execute([
                ':to'     => $to,
                ':actor'  => $actor,
                ':legacy' => $legacy,
                ':id'     => $fileId,
            ]);

            // Write audit log row.
            $aud = $this->pdo->prepare(
                "INSERT INTO `{$this->auditTable}`
                 (file_id, from_state, to_state, actor, reason, boot_epoch, bytes, duration_ms)
                 VALUES (:fid, :from, :to, :actor, :reason, :be, :bytes, :dur)"
            );
            $aud->execute([
                ':fid'    => $fileId,
                ':from'   => $from,
                ':to'     => $to,
                ':actor'  => $actor,
                ':reason' => $reason,
                ':be'     => $bootEpoch,
                ':bytes'  => $bytes,
                ':dur'    => $durationMs,
            ]);

            $this->pdo->commit();
            $this->journal?->record('tier_transition_ok', [
                'file_id'    => $fileId,
                'from'       => $from,
                'to'         => $to,
                'actor'      => $actor,
                'bytes'      => $bytes,
                'duration_ms' => $durationMs,
            ]);
            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Idempotent reconciliation: if a file's storage_location and
     * tier_state disagree, force tier_state to match what
     * storage_location implies. Used by the backfill cron to keep the
     * two columns in sync during the Phase 4/5 transition window.
     *
     * Returns the row count actually updated. Audit log entries are
     * recorded with actor "{prefix}-backfill" so the source is clear.
     */
    public function reconcileLegacyLocation(
        int $batchLimit = 500,
        string $actor = 'drive-tier-backfill',
        bool $dryRun = false
    ): array {
        $select = $this->pdo->prepare(
            "SELECT id, tier_state, storage_location
             FROM `{$this->table}`
             WHERE storage_location IS NOT NULL
             ORDER BY id ASC
             LIMIT :lim"
        );
        $select->bindValue(':lim', $batchLimit, \PDO::PARAM_INT);
        $select->execute();
        $rows = $select->fetchAll(\PDO::FETCH_ASSOC);

        $stats = [
            'scanned'   => count($rows),
            'in_sync'   => 0,
            'updated'   => 0,
            'skipped_terminal' => 0,
            'failed'    => 0,
        ];
        foreach ($rows as $row) {
            $expected = TierState::fromLegacyLocation((string) $row['storage_location']);
            $current  = (string) $row['tier_state'];
            if ($expected === $current) {
                $stats['in_sync']++;
                continue;
            }
            if ($current === TierState::LOST) {
                // Never resurrect a lost file via a backfill loop —
                // that requires an explicit operator decision.
                $stats['skipped_terminal']++;
                continue;
            }
            if (!TierState::canTransition($current, $expected)) {
                // Skipping these silently would hide schema drift;
                // bump failed and let the operator look.
                $stats['failed']++;
                $this->journal?->record('tier_backfill_skipped_illegal', [
                    'file_id' => (int) $row['id'],
                    'from'    => $current,
                    'to'      => $expected,
                ]);
                continue;
            }
            if ($dryRun) {
                $stats['updated']++;
                continue;
            }
            try {
                $this->transitionTo(
                    (int) $row['id'],
                    $expected,
                    $actor,
                    "reconciled from storage_location={$row['storage_location']}"
                );
                $stats['updated']++;
            } catch (\Throwable $e) {
                $stats['failed']++;
                $this->journal?->record('tier_backfill_failed', [
                    'file_id' => (int) $row['id'],
                    'error'   => $e->getMessage(),
                ]);
            }
        }
        return $stats;
    }

    /**
     * Count of files per tier_state. Cheap aggregate used by the
     * `storage-ctl tiers` dashboard and tests.
     *
     * @return array<string,int>
     */
    public function counts(): array
    {
        $stmt = $this->pdo->query(
            "SELECT tier_state, COUNT(*) AS c FROM `{$this->table}` GROUP BY tier_state"
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $out = array_fill_keys(TierState::all(), 0);
        foreach ($rows as $r) {
            $out[(string) $r['tier_state']] = (int) $r['c'];
        }
        return $out;
    }

    /**
     * Return the N oldest files in `hot` state that have been hot for
     * at least $ageDays. Phase 5's tier-down worker iterates this.
     *
     * @return list<array<string,mixed>>
     */
    /**
     * Find rows that are eligible for tier-down (still hot, last
     * tiered more than $ageDays ago).
     *
     * Two ordering strategies:
     *   - 'age' (default, pre-6d behaviour): oldest tier_changed_at
     *     first. Predictable, no LRU column required.
     *   - 'lru' (Phase 6d): COALESCE(last_read_at, tier_changed_at)
     *     ascending — files that have never been read since migration
     *     168 fall back to their tier_changed_at, so the first
     *     candidates are the genuinely-cold ones; files read recently
     *     drift to the back of the queue.
     *
     * @param int    $ageDays  rows newer than this are skipped entirely
     * @param int    $limit    max rows returned
     * @param string $orderBy  'age'|'lru'  (unknown values fall back to 'age')
     * @param bool   $hasLastReadAt  whether the `last_read_at` column
     *                               exists in this DB. Defaults to
     *                               auto-detect via $this->hasColumn().
     *                               Pass false in tests where the
     *                               migration hasn't been applied.
     */
    public function findTierDownCandidates(
        int $ageDays,
        int $limit = 100,
        string $orderBy = 'age',
        ?bool $hasLastReadAt = null,
    ): array {
        // Sanitised int — inline-safe, sidesteps a PDO_SQLITE quirk
        // where `'-' || :age || ' days'` with a bound int produces a
        // bogus modifier string and silently returns 0 rows.
        $age = max(0, $ageDays);

        $cutoff = $this->driver() === 'sqlite'
            ? "datetime('now', '-{$age} days')"
            : "DATE_SUB(NOW(), INTERVAL {$age} DAY)";

        $orderBy = $orderBy === 'lru' ? 'lru' : 'age';
        if ($hasLastReadAt === null) {
            // Cheap one-shot probe; cached after the first call per
            // connection because PRAGMA / INFORMATION_SCHEMA are quick.
            $hasLastReadAt = $this->columnExists('last_read_at');
        }
        $effectiveOrderBy = ($orderBy === 'lru' && $hasLastReadAt) ? 'lru' : 'age';

        // The LRU ordering is COALESCE(last_read_at, tier_changed_at):
        // rows that have never been touched (the common case right
        // after migration 168 lands) fall back to their tier_changed_at,
        // which means the existing age-based ordering is the natural
        // boundary condition.
        $orderClause = $effectiveOrderBy === 'lru'
            ? 'ORDER BY COALESCE(last_read_at, tier_changed_at) ASC'
            : 'ORDER BY tier_changed_at ASC';

        $selectExtra = $hasLastReadAt ? ', last_read_at' : '';

        $sql = "SELECT id, size, checksum, storage_location, tier_changed_at{$selectExtra}
                FROM `{$this->table}`
                WHERE tier_state = :hot
                  AND tier_changed_at IS NOT NULL
                  AND tier_changed_at < {$cutoff}
                {$orderClause}
                LIMIT :lim";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':hot', TierState::HOT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Lightweight column existence probe. Cached per connection.
     */
    private array $columnCache = [];
    private function columnExists(string $column): bool
    {
        if (isset($this->columnCache[$column])) {
            return $this->columnCache[$column];
        }
        try {
            if ($this->driver() === 'sqlite') {
                $stmt = $this->pdo->query("PRAGMA table_info(`{$this->table}`)");
                $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
                $exists = false;
                foreach ($rows as $row) {
                    if (($row['name'] ?? null) === $column) { $exists = true; break; }
                }
            } else {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = :t
                       AND COLUMN_NAME = :c"
                );
                $stmt->execute([':t' => $this->table, ':c' => $column]);
                $exists = ((int) $stmt->fetchColumn()) > 0;
            }
        } catch (\Throwable) {
            $exists = false;
        }
        $this->columnCache[$column] = $exists;
        return $exists;
    }

    /**
     * Read the last N audit entries for a file. Mostly used by tests
     * and by an operator forensics endpoint.
     *
     * @return list<array<string,mixed>>
     */
    public function auditTrail(int $fileId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT from_state, to_state, actor, reason, bytes, duration_ms, created_at
             FROM `{$this->auditTable}`
             WHERE file_id = :fid
             ORDER BY id DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':fid', $fileId, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function driver(): string
    {
        return (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }
}
