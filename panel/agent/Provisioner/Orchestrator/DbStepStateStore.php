<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator;

use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\Step\StepOutcome;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

/**
 * DB-backed StepStateStore writing to `site_step_executions`.
 *
 * Lifecycle inside a saga run:
 *   1. The worker enqueues the job and constructs this store scoped to
 *      (jobId, siteDomain, requestId). Any prior rows for jobId
 *      reflect a previous worker's attempt; they remain in the table
 *      as a forensic record.
 *   2. The orchestrator's load() pulls the latest snapshot per step
 *      name (highest id wins). On a resume after worker crash, this
 *      returns the state the dead worker last wrote.
 *   3. Each orchestrator save() inserts an APPEND-ONLY row carrying
 *      the (input_snapshot, output_snapshot, outcome, error,
 *      attempt_number, schema_version, duration_ms, started_at,
 *      finished_at). Many rows per attempt are normal (one per
 *      forward+verify+backward call).
 *   4. clear() deletes every row for this jobId. Used by callers that
 *      want a fully fresh start (e.g. operator-issued "wipe and
 *      retry"). The orchestrator itself never calls clear().
 *
 * Secret hygiene:
 *   - StepState.data may contain *references* to vault secrets (e.g.
 *     `db_password_ref`). It MUST NOT contain plaintext credentials
 *     by step contract. As a defence-in-depth, every snapshot is
 *     piped through SecretMasker before writing, so accidental leaks
 *     surface as `[REDACTED]` rather than ending up on disk.
 *
 * Concurrency:
 *   - The schema does NOT have a unique constraint on
 *     (job_id, step_name, attempt_number). That's intentional: each
 *     save() journals an event. Concurrent writers against the same
 *     job_id are an error elsewhere (the job lease prevents this);
 *     we rely on auto-incrementing id for ordering.
 */
final class DbStepStateStore implements StepStateStore
{
    public function __construct(
        private readonly PanelDatabase $database,
        private readonly SecretMasker $masker,
        private readonly int $jobId,
        private readonly string $siteDomain,
        private readonly string $requestId,
        private readonly ?string $workerId = null
    ) {
        if ($this->jobId <= 0) {
            throw new \InvalidArgumentException('DbStepStateStore requires a positive jobId');
        }
        if ($this->siteDomain === '') {
            throw new \InvalidArgumentException('DbStepStateStore requires a non-empty siteDomain');
        }
    }

    public function load(string $stepName): ?StepState
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT schema_version, output_snapshot, started_at, finished_at, attempt_number
               FROM site_step_executions
              WHERE job_id = :job_id AND step_name = :step_name
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([
            'job_id' => $this->jobId,
            'step_name' => $stepName,
        ]);
        $row = $stmt->fetch();
        if ($row === false || $row === null) {
            return null;
        }
        return $this->stateFromRow($stepName, $row);
    }

    public function save(
        StepState $state,
        ?StepOutcome $lastOutcome = null,
        ?string $lastError = null
    ): void {
        $startedAt = $state->startedAt?->format('Y-m-d H:i:s.v')
            ?? (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.v');
        $finishedAt = $state->completedAt?->format('Y-m-d H:i:s.v');

        $durationMs = null;
        if ($state->startedAt !== null && $state->completedAt !== null) {
            $delta = (float) $state->completedAt->format('U.u')
                - (float) $state->startedAt->format('U.u');
            $durationMs = (int) max(0, round($delta * 1000));
        }

        // Output snapshot carries the orchestrator-visible state shape.
        // Pre-mask in case a step accidentally embedded a secret blob.
        $masked = $this->masker->maskArray($state->toArray());

        $stmt = $this->database->pdo()->prepare(
            'INSERT INTO site_step_executions
                (job_id, site_domain, step_name, attempt_number, schema_version,
                 started_at, finished_at, duration_ms,
                 outcome, output_snapshot, error,
                 worker_id, request_id)
              VALUES
                (:job_id, :site_domain, :step_name, :attempt_number, :schema_version,
                 :started_at, :finished_at, :duration_ms,
                 :outcome, :output_snapshot, :error,
                 :worker_id, :request_id)'
        );
        $stmt->execute([
            'job_id' => $this->jobId,
            'site_domain' => $this->siteDomain,
            'step_name' => $state->stepName,
            'attempt_number' => max(0, $state->attemptCount),
            'schema_version' => max(1, $state->schemaVersion),
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
            'outcome' => $this->mapOutcome($lastOutcome),
            'output_snapshot' => json_encode($masked, JSON_UNESCAPED_SLASHES),
            'error' => $lastError !== null ? mb_substr($lastError, 0, 8000) : null,
            'worker_id' => $this->workerId,
            'request_id' => $this->requestId,
        ]);
    }

    public function all(): array
    {
        // Latest row per step_name for this job.
        $stmt = $this->database->pdo()->prepare(
            'SELECT t.step_name, t.schema_version, t.output_snapshot,
                    t.started_at, t.finished_at, t.attempt_number
               FROM site_step_executions t
               JOIN (
                   SELECT step_name, MAX(id) AS max_id
                     FROM site_step_executions
                    WHERE job_id = :job_id
                    GROUP BY step_name
               ) latest ON latest.max_id = t.id
              WHERE t.job_id = :job_id2'
        );
        $stmt->execute([
            'job_id' => $this->jobId,
            'job_id2' => $this->jobId,
        ]);

        $out = [];
        while ($row = $stmt->fetch()) {
            $name = (string) $row['step_name'];
            $state = $this->stateFromRow($name, $row);
            if ($state !== null) {
                $out[$name] = $state;
            }
        }
        return $out;
    }

    public function clear(): void
    {
        $stmt = $this->database->pdo()->prepare(
            'DELETE FROM site_step_executions WHERE job_id = :job_id'
        );
        $stmt->execute(['job_id' => $this->jobId]);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function stateFromRow(string $stepName, array $row): ?StepState
    {
        $rawSnapshot = $row['output_snapshot'] ?? null;
        if ($rawSnapshot === null || $rawSnapshot === '') {
            return null;
        }
        $decoded = json_decode((string) $rawSnapshot, true);
        if (!is_array($decoded)) {
            return null;
        }
        // Trust the snapshot's own step_name to handle the rare case of
        // a renamed step; default to the queried name.
        $decoded['step_name'] = $decoded['step_name'] ?? $stepName;
        // started_at / completed_at MIGHT be in the snapshot already,
        // but the DB row's started_at/finished_at are the source of
        // truth (MySQL stores them in canonical Y-m-d H:i:s.v).
        if (!isset($decoded['started_at']) && isset($row['started_at'])) {
            $decoded['started_at'] = (string) $row['started_at'];
        }
        if (!isset($decoded['completed_at']) && isset($row['finished_at'])) {
            $decoded['completed_at'] = (string) $row['finished_at'];
        }
        if (!isset($decoded['attempt_count']) && isset($row['attempt_number'])) {
            $decoded['attempt_count'] = (int) $row['attempt_number'];
        }
        if (!isset($decoded['schema_version']) && isset($row['schema_version'])) {
            $decoded['schema_version'] = (int) $row['schema_version'];
        }
        return StepState::fromArray($decoded);
    }

    /**
     * Map the orchestrator-level StepOutcome to the journal's narrower
     * ENUM. The journal does NOT have a distinct "partial" or
     * "retry_later" - we collapse those to 'failure' for the row,
     * preserving the original outcome in the SagaResult.
     */
    private function mapOutcome(?StepOutcome $outcome): ?string
    {
        if ($outcome === null) {
            return null;
        }
        return match ($outcome) {
            StepOutcome::SUCCESS => 'success',
            StepOutcome::SKIPPED => 'skipped',
            StepOutcome::TIMEOUT => 'timeout',
            StepOutcome::FAILURE,
            StepOutcome::PARTIAL,
            StepOutcome::RETRY_LATER => 'failure',
        };
    }
}
