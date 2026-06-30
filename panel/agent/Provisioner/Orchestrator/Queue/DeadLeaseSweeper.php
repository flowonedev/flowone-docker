<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator\Queue;

use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

/**
 * Recovers `site_jobs` rows whose worker has crashed mid-execution.
 *
 * Crash detection is purely lease-based: the JobWorker writes
 * `lease_until = NOW() + LEASE_TTL_S` on claim. As long as the worker
 * lives it would extend the lease (5c-3 does NOT implement lease
 * extension yet — sagas under 60s don't need it; longer sagas are a
 * 5c-4 concern). If the worker process dies — segfault, OOM kill,
 * systemd restart, host reboot — nobody extends the lease and it
 * expires.
 *
 * The dead-lease sweeper runs periodically (systemd timer) and:
 *   1. SELECT rows WHERE status='running' AND lease_until < NOW() -
 *      grace_seconds. Grace exists so we don't race a slow worker
 *      that's about to commit its terminal UPDATE.
 *   2. For each row, UPDATE status='queued', clear locked_by /
 *      lease_until. Leave `attempts` AS-IS: the next worker that
 *      claims will increment again, so the attempts column correctly
 *      counts "times a worker grabbed this row."
 *   3. Audit each recovery with the dead worker's id captured in the
 *      reason text, so an operator can later correlate a spike of
 *      recoveries with a specific dying worker.
 *
 * Stuck-row detection (a row that crashes the worker every time):
 *   - Out of scope for 5c-3. The existing JobWorker logic marks a job
 *     FAILED when attempts >= max_attempts, which catches the case
 *     where every worker dies on the same row. The sweeper just
 *     keeps re-queueing until that ceiling is hit.
 *
 * The sweeper is idempotent — running it twice in a row on the same
 * stale row is a no-op the second time (UPDATE matches zero rows).
 * Tests rely on this.
 */
final class DeadLeaseSweeper
{
    /** Default grace period (seconds) past lease_until before we touch a row. */
    public const DEFAULT_GRACE_SECONDS = 10;

    public function __construct(
        private readonly PanelDatabase $database,
        private readonly AuditLogger $audit,
        private readonly int $graceSeconds = self::DEFAULT_GRACE_SECONDS
    ) {
        if ($graceSeconds < 0) {
            throw new \InvalidArgumentException('graceSeconds must be >= 0');
        }
    }

    /**
     * Scan for stale-leased running rows and recover them. Returns the
     * number of rows that were brought back to QUEUED.
     */
    public function sweep(?int $limit = null): DeadLeaseSweepResult
    {
        $started = microtime(true);
        $stale = $this->findStaleRows($limit);
        $recovered = 0;
        $skipped = 0;
        /** @var list<array{job_id:int, site_domain:string, dead_worker:?string, attempts:int}> */
        $recoveries = [];

        foreach ($stale as $row) {
            if ($this->recoverRow($row)) {
                $recovered++;
                $recoveries[] = [
                    'job_id' => (int) $row['id'],
                    'site_domain' => (string) $row['site_domain'],
                    'dead_worker' => $row['locked_by'] !== null ? (string) $row['locked_by'] : null,
                    'attempts' => (int) $row['attempts'],
                ];
            } else {
                // Lost the race (another worker / another sweeper). Not
                // an error: the row was concurrently moved out of the
                // stale state.
                $skipped++;
            }
        }

        return new DeadLeaseSweepResult(
            scanned: count($stale),
            recovered: $recovered,
            skipped: $skipped,
            recoveries: $recoveries,
            elapsedMs: (int) max(0, round((microtime(true) - $started) * 1000)),
        );
    }

    /**
     * Snapshot of currently-stale leases without performing recovery.
     * Useful for dashboards / dry-run diagnostics.
     *
     * @return list<array<string,mixed>>
     */
    public function listStale(?int $limit = null): array
    {
        return $this->findStaleRows($limit);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function findStaleRows(?int $limit): array
    {
        $sql = 'SELECT id, site_domain, locked_by, lease_until, attempts, started_at
                  FROM site_jobs
                 WHERE status = :status
                   AND lease_until IS NOT NULL
                   AND lease_until < DATE_SUB(NOW(3), INTERVAL :grace SECOND)';
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $stmt = $this->database->pdo()->prepare($sql);
        $stmt->execute([
            'status' => JobStatus::RUNNING->value,
            'grace' => $this->graceSeconds,
        ]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $rows === false ? [] : $rows;
    }

    /**
     * Atomically move one stale row back to QUEUED. Returns true if
     * THIS process won the race; false if another process beat us
     * (the UPDATE matched zero rows).
     *
     * @param array<string,mixed> $row
     */
    private function recoverRow(array $row): bool
    {
        $pdo = $this->database->pdo();
        $jobId = (int) $row['id'];
        $deadWorker = $row['locked_by'] !== null ? (string) $row['locked_by'] : 'unknown';

        $pdo->beginTransaction();
        try {
            // Update is gated by the exact (status, locked_by, lease_until)
            // we observed so we don't clobber a worker that legitimately
            // re-acquired the row between our SELECT and our UPDATE.
            $stmt = $pdo->prepare(
                'UPDATE site_jobs
                    SET status = :new_status,
                        locked_by = NULL,
                        lease_until = NULL
                  WHERE id = :id
                    AND status = :running_status
                    AND lease_until = :lease_until
                    AND (locked_by <=> :locked_by)'
            );
            $stmt->execute([
                'new_status' => JobStatus::QUEUED->value,
                'id' => $jobId,
                'running_status' => JobStatus::RUNNING->value,
                'lease_until' => $row['lease_until'],
                'locked_by' => $row['locked_by'],
            ]);
            $changed = $stmt->rowCount() > 0;

            if (!$changed) {
                $pdo->commit();
                return false;
            }

            // Best-effort audit. The recovery already succeeded; we
            // don't want to bubble an audit-table outage and re-stick
            // the row, so wrap in try/catch and continue. The row's
            // attempts counter remains the smoking gun in either case.
            try {
                $this->audit->record(
                    action: 'job_lease_recovered',
                    siteDomain: (string) $row['site_domain'],
                    reason: "dead worker '{$deadWorker}' lease expired; "
                        . "row returned to queued (attempt {$row['attempts']})",
                    before: [
                        'status' => JobStatus::RUNNING->value,
                        'locked_by' => $row['locked_by'],
                        'lease_until' => $row['lease_until'],
                    ],
                    after: [
                        'status' => JobStatus::QUEUED->value,
                        'locked_by' => null,
                        'lease_until' => null,
                    ],
                    actor: ActorContext::system('lease-sweeper'),
                    jobId: $jobId,
                );
            } catch (\Throwable) {
                // ignore — see comment above
            }

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
