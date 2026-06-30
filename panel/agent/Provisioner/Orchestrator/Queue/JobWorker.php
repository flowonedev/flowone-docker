<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator\Queue;

use VpsAdmin\Agent\Provisioner\Adapters\Adapters;
use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Exceptions\InvalidStateTransition;
use VpsAdmin\Agent\Provisioner\Exceptions\StateGuardFailed;
use VpsAdmin\Agent\Provisioner\Orchestrator\DbSagaEventSink;
use VpsAdmin\Agent\Provisioner\Orchestrator\DbStepStateStore;
use VpsAdmin\Agent\Provisioner\Orchestrator\ProvisioningRunResult;
use VpsAdmin\Agent\Provisioner\Orchestrator\ProvisioningSagaRunner;
use VpsAdmin\Agent\Provisioner\Orchestrator\SagaOutcome;
use VpsAdmin\Agent\Provisioner\Orchestrator\SagaResult;
use VpsAdmin\Agent\Provisioner\Orchestrator\SagaStepRecord;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\Services\SecretVault;
use VpsAdmin\Agent\Provisioner\Services\ServerCapabilities;
use VpsAdmin\Agent\Provisioner\Step\Saga\SagaRegistry;
use VpsAdmin\Agent\Provisioner\Step\Saga\SagaSequence;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepOutcome;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

/**
 * One worker tick = claim ONE job, run its saga end-to-end, persist
 * the outcome, release the lease. The worker daemon loops on tickOnce()
 * and decides whether to sleep based on the JobClaimResult.
 *
 * Concurrency:
 *   - Claim uses `FOR UPDATE SKIP LOCKED` so N parallel workers never
 *     race for the same row. Each worker picks the highest-priority
 *     queued+eligible job that nobody else has under lock.
 *   - A lease (locked_by + lease_until) is set on claim. If a worker
 *     crashes mid-saga the lease expires after LEASE_TTL_S and another
 *     worker can take over. The takeover is safe because the saga's
 *     StepStateStore is journal-style (DbStepStateStore) - the new
 *     worker's check() calls observe the partial state.
 *
 * Retry & backoff:
 *   - A step that returns RETRY_LATER or PARTIAL is the only "soft"
 *     failure that doesn't compensate. The worker detects this on the
 *     SagaResult and re-enqueues the job (status=QUEUED, enqueued_at
 *     in the future) with exponential backoff. The original row gets
 *     attempts+=1; once attempts >= max_attempts the worker stops
 *     re-enqueueing and lets the job land FAILED.
 *   - A hard saga failure (compensation completed) goes straight to
 *     FAILED. Operators decide whether to manually retry.
 *
 * Cancellation:
 *   - The worker re-reads status under the lease before invoking the
 *     saga. A row marked CANCELLED between enqueue and claim aborts
 *     the run without side effects.
 *
 * What this class does NOT do:
 *   - It does NOT manage a process loop or signals - that's the
 *     daemon (Step 5c-3) which calls tickOnce() in a loop.
 *   - It does NOT update site_jobs.current_step / step_state /
 *     checkpoint_hash. Those columns are reserved for the resume
 *     handshake (Step 5d's subprocess isolation); 5c-2 keeps the
 *     step-level state in site_step_executions via DbStepStateStore.
 *   - It does NOT broadcast SSE events. Events land in site_job_events
 *     via DbSagaEventSink; the API streams them on demand.
 */
final class JobWorker implements JobTicker
{
    /** How long a claim's lease lives (seconds). The daemon should
     *  tickOnce() faster than this; otherwise another worker steals. */
    public const LEASE_TTL_S = 60;

    /** Minimum backoff between retries (seconds). */
    public const RETRY_BACKOFF_MIN_S = 30;

    /** Maximum backoff between retries (seconds). 30 minutes. */
    public const RETRY_BACKOFF_MAX_S = 30 * 60;

    /** Multiplier applied per attempt (exponential). */
    public const RETRY_BACKOFF_FACTOR = 4;

    /** Default saga wallclock budget (seconds) when payload doesn't pin one. */
    public const DEFAULT_SAGA_DEADLINE_S = 300;

    public function __construct(
        private readonly PanelDatabase $database,
        private readonly SecretMasker $masker,
        private readonly SecretVault $vault,
        private readonly AuditLogger $audit,
        private readonly ServerCapabilities $capabilities,
        private readonly SagaRegistry $registry,
        private readonly ProvisioningSagaRunner $runner,
        private readonly string $workerId,
        private readonly ?Adapters $adapters = null
    ) {
        if ($workerId === '') {
            throw new \InvalidArgumentException('JobWorker requires a non-empty workerId');
        }
    }

    /**
     * Atomically claim and run the next eligible job, OR report idle.
     */
    public function tickOnce(): JobClaimResult
    {
        $startMicro = microtime(true);

        $job = $this->claim();
        if ($job === null) {
            return JobClaimResult::empty();
        }

        return $this->runClaimedJob($job, $startMicro);
    }

    /**
     * Atomic claim: SELECT ... FOR UPDATE SKIP LOCKED + UPDATE under a
     * single transaction. Returns the freshly-claimed SiteJob (with
     * attempts+=1 and status=RUNNING) or NULL when nothing eligible.
     */
    private function claim(): ?SiteJob
    {
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $select = $pdo->prepare(
                'SELECT id FROM site_jobs
                  WHERE status = :status
                    AND enqueued_at <= NOW(3)
                    AND (lease_until IS NULL OR lease_until < NOW(3))
                  ORDER BY priority_class, priority, enqueued_at
                  LIMIT 1
                  FOR UPDATE SKIP LOCKED'
            );
            $select->execute(['status' => JobStatus::QUEUED->value]);
            $idRow = $select->fetch(\PDO::FETCH_ASSOC);
            if ($idRow === false) {
                $pdo->commit();
                return null;
            }
            $jobId = (int) $idRow['id'];

            $update = $pdo->prepare(
                'UPDATE site_jobs
                    SET status = :status,
                        locked_by = :locked_by,
                        lease_until = DATE_ADD(NOW(3), INTERVAL :lease_s SECOND),
                        started_at = COALESCE(started_at, NOW(3)),
                        attempts = attempts + 1
                    WHERE id = :id'
            );
            $update->execute([
                'status' => JobStatus::RUNNING->value,
                'locked_by' => $this->workerId,
                'lease_s' => self::LEASE_TTL_S,
                'id' => $jobId,
            ]);

            $row = $this->fetchRowByIdForUpdate($pdo, $jobId);
            $pdo->commit();
            return $row === null ? null : SiteJob::fromRow($row);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Dispatch a claimed job to the saga runner, persist the outcome,
     * release the lease. The job is in status=RUNNING with attempts
     * already incremented at this point.
     */
    private function runClaimedJob(SiteJob $job, float $startMicro): JobClaimResult
    {
        $direction = $job->type->toSagaDirection();
        if ($direction === null || !$job->type->isImplemented()) {
            $this->finishUnsupported($job);
            return JobClaimResult::rejected(
                $job,
                JobStatus::FAILED->value,
                "job type '{$job->type->value}' is not implemented yet",
                $this->elapsedMs($startMicro),
            );
        }

        $siteRow = $this->loadOrCreateSiteRow($job);
        if ($siteRow === null) {
            $this->finishFailed(
                $job,
                error: "no sites row exists for domain '{$job->siteDomain}' and creation is not yet wired",
                resultSummary: ['reason' => 'missing_site_row'],
            );
            return JobClaimResult::rejected(
                $job,
                JobStatus::FAILED->value,
                'missing site row',
                $this->elapsedMs($startMicro),
            );
        }

        // Re-read status under the lease - cancellation can race with us.
        $fresh = $this->fetchRow($job->id);
        if ($fresh !== null && (string) $fresh['status'] === JobStatus::CANCELLED->value) {
            $this->releaseLease($job->id);
            return JobClaimResult::rejected(
                $job,
                JobStatus::CANCELLED->value,
                'cancelled before run',
                $this->elapsedMs($startMicro),
            );
        }

        try {
            $sequence = $this->buildSequence($job->type);
        } catch (\Throwable $e) {
            $this->finishFailed(
                $job,
                error: 'saga registry lookup failed: ' . $e->getMessage(),
                resultSummary: ['reason' => 'no_saga_for_type'],
            );
            return JobClaimResult::rejected(
                $job,
                JobStatus::FAILED->value,
                'no saga for type',
                $this->elapsedMs($startMicro),
            );
        }

        $actor = $this->actorFromJob($job);
        $deadline = microtime(true) + self::DEFAULT_SAGA_DEADLINE_S;

        $ctx = new SiteContext(
            siteRow: $siteRow,
            jobId: $job->id,
            requestId: $job->requestId ?? 'job-' . $job->id,
            actor: $actor,
            audit: $this->audit,
            vault: $this->vault,
            capabilities: $this->capabilities,
            database: $this->database,
            payload: $job->payload,
            dryRun: $job->dryRun,
            deadlineUnixMicro: $deadline,
            adapters: $this->adapters,
        );

        $store = new DbStepStateStore(
            database: $this->database,
            masker: $this->masker,
            jobId: $job->id,
            siteDomain: $job->siteDomain,
            requestId: $ctx->requestId,
            workerId: $this->workerId,
        );
        $sink = new DbSagaEventSink(
            database: $this->database,
            masker: $this->masker,
            jobId: $job->id,
            siteDomain: $job->siteDomain,
            requestId: $ctx->requestId,
        );

        // The saga itself.
        try {
            $runResult = $this->runner->run($direction, $sequence, $ctx, $store, $sink);
        } catch (InvalidStateTransition | StateGuardFailed $e) {
            $this->finishFailed(
                $job,
                error: 'state machine refused saga: ' . $e->getMessage(),
                resultSummary: ['reason' => 'state_transition'],
            );
            return JobClaimResult::processed(
                $job, JobStatus::FAILED->value, $e->getMessage(),
                $this->elapsedMs($startMicro),
            );
        } catch (\Throwable $e) {
            $sink->emitSaga(StepEvent::error(
                'worker caught uncaught throwable from saga runner',
                ['class' => $e::class, 'error' => $e->getMessage()]
            ));
            $this->finishFailed(
                $job,
                error: 'worker uncaught throwable: ' . $e->getMessage(),
                resultSummary: ['reason' => 'worker_exception', 'class' => $e::class],
            );
            return JobClaimResult::processed(
                $job, JobStatus::FAILED->value, $e->getMessage(),
                $this->elapsedMs($startMicro),
            );
        }

        return $this->persistOutcome($job, $runResult, $startMicro);
    }

    private function persistOutcome(SiteJob $job, ProvisioningRunResult $runResult, float $startMicro): JobClaimResult
    {
        $saga = $runResult->saga;
        $summary = $this->summariseRun($runResult);
        $sagaDeadline = $runResult->saga->outcome === SagaOutcome::ABORTED;

        // ABORTED: deadline exceeded between steps. Worker treats this
        // as "needs another tick" - re-enqueue immediately (no backoff
        // delay) so the next worker picks it up.
        if ($sagaDeadline) {
            if ($job->attemptsExhausted()) {
                $this->finishFailed(
                    $job,
                    error: 'saga aborted (deadline) and attempts exhausted',
                    resultSummary: $summary,
                );
                return JobClaimResult::processed(
                    $job, JobStatus::FAILED->value, 'aborted: attempts exhausted',
                    $this->elapsedMs($startMicro),
                );
            }
            $this->reEnqueueWithBackoff($job, /*backoffSeconds*/ 0, 'saga aborted (deadline)');
            return JobClaimResult::processed(
                $job, JobStatus::QUEUED->value, 're-enqueued (aborted)',
                $this->elapsedMs($startMicro),
            );
        }

        // RETRY_LATER / PARTIAL on the failing step: re-enqueue with
        // exponential backoff. Both outcomes are surfaced as a FAILED
        // SagaResult by the orchestrator, but the SagaStepRecord
        // preserves the original StepOutcome so we can distinguish.
        //
        // IMPORTANT: SagaResult::findStep() returns the LATEST record
        // for a step, and after compensation runs the latest record is
        // the backward (compensate) record - which is usually SUCCESS
        // even when the forward record was RETRY_LATER. We have to
        // look at the FORWARD record specifically.
        if ($saga->isFailure() && $saga->failureStepName !== null) {
            $forwardRecord = $this->findForwardRecord($saga, $saga->failureStepName);
            $isSoft = $forwardRecord !== null
                && in_array($forwardRecord->outcome, [
                    StepOutcome::RETRY_LATER,
                    StepOutcome::PARTIAL,
                    StepOutcome::TIMEOUT,
                ], true);
            if ($isSoft && !$job->attemptsExhausted()) {
                $backoff = $this->backoffSecondsFor($job->attempts);
                $this->reEnqueueWithBackoff(
                    $job,
                    $backoff,
                    "soft failure ({$forwardRecord->outcome->value}) on {$saga->failureStepName}",
                );
                return JobClaimResult::processed(
                    $job,
                    JobStatus::QUEUED->value,
                    "re-enqueued in {$backoff}s (attempt {$job->attempts}/{$job->maxAttempts})",
                    $this->elapsedMs($startMicro),
                );
            }
        }

        // Hard terminal: succeeded / failed / degraded all collapse to
        // SUCCEEDED or FAILED on the job row. The site state is the
        // bridge's responsibility (saga.outcome=DEGRADED still maps to
        // a "failed" job because the saga didn't reach its happy path).
        $jobStatus = $saga->isSuccess() ? JobStatus::SUCCEEDED : JobStatus::FAILED;
        $error = $saga->failureError;

        $this->finalize(
            $job->id,
            $jobStatus,
            $summary,
            $error,
        );

        return JobClaimResult::processed(
            $job,
            $jobStatus->value,
            $saga->outcome->value,
            $this->elapsedMs($startMicro),
        );
    }

    /**
     * Re-enqueue a job for a future attempt. Resets status to QUEUED,
     * pushes enqueued_at forward by $backoffSeconds, and clears the
     * lease so a fresh worker (possibly this one) can claim it after
     * the delay elapses.
     */
    private function reEnqueueWithBackoff(SiteJob $job, int $backoffSeconds, string $reason): void
    {
        $stmt = $this->database->pdo()->prepare(
            'UPDATE site_jobs
                SET status = :status,
                    locked_by = NULL,
                    lease_until = NULL,
                    enqueued_at = DATE_ADD(NOW(3), INTERVAL :backoff SECOND),
                    error = :error
                WHERE id = :id'
        );
        $stmt->execute([
            'status' => JobStatus::QUEUED->value,
            'backoff' => max(0, $backoffSeconds),
            'error' => mb_substr($reason, 0, 8000),
            'id' => $job->id,
        ]);

        $this->audit->record(
            action: 'job_retry_scheduled',
            siteDomain: $job->siteDomain,
            reason: $reason,
            before: ['status' => JobStatus::RUNNING->value, 'attempts' => $job->attempts],
            after: [
                'status' => JobStatus::QUEUED->value,
                'attempts' => $job->attempts,
                'max_attempts' => $job->maxAttempts,
                'backoff_seconds' => $backoffSeconds,
            ],
            actor: $this->actorFromJob($job),
            jobId: $job->id,
        );
    }

    /**
     * Exponential backoff capped at RETRY_BACKOFF_MAX_S.
     *
     * attempt 1 -> 30s
     * attempt 2 -> 120s
     * attempt 3 -> 480s
     * attempt 4 -> 1800s (cap)
     */
    public function backoffSecondsFor(int $attemptNumber): int
    {
        if ($attemptNumber < 1) {
            return self::RETRY_BACKOFF_MIN_S;
        }
        $exp = self::RETRY_BACKOFF_MIN_S
            * (self::RETRY_BACKOFF_FACTOR ** ($attemptNumber - 1));
        return (int) min(self::RETRY_BACKOFF_MAX_S, max(self::RETRY_BACKOFF_MIN_S, $exp));
    }

    private function finalize(int $jobId, JobStatus $status, array $resultSummary, ?string $error): void
    {
        $stmt = $this->database->pdo()->prepare(
            'UPDATE site_jobs
                SET status = :status,
                    locked_by = NULL,
                    lease_until = NULL,
                    finished_at = NOW(3),
                    result = :result,
                    error = :error
                WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status->value,
            'result' => json_encode($resultSummary, JSON_UNESCAPED_SLASHES),
            'error' => $error === null ? null : mb_substr($error, 0, 8000),
            'id' => $jobId,
        ]);
    }

    private function releaseLease(int $jobId): void
    {
        $stmt = $this->database->pdo()->prepare(
            'UPDATE site_jobs
                SET locked_by = NULL, lease_until = NULL
                WHERE id = :id'
        );
        $stmt->execute(['id' => $jobId]);
    }

    /**
     * Failure path used when we never reached the saga (unsupported
     * type, missing site row, registry crash). Sets the row FAILED so
     * the queue doesn't immediately re-claim the same broken row.
     *
     * @param array<string,mixed> $resultSummary
     */
    private function finishFailed(SiteJob $job, string $error, array $resultSummary): void
    {
        $this->finalize($job->id, JobStatus::FAILED, $resultSummary, $error);
    }

    private function finishUnsupported(SiteJob $job): void
    {
        $error = "job type '{$job->type->value}' has no implementation in this build";
        $this->finishFailed(
            $job,
            error: $error,
            resultSummary: [
                'reason' => 'unsupported_type',
                'type' => $job->type->value,
            ],
        );
    }

    private function buildSequence(JobType $type): SagaSequence
    {
        return match ($type) {
            JobType::CREATE, JobType::RETRY, JobType::RECONCILE
                => $this->registry->createSequence(),
            JobType::DELETE
                => $this->registry->deleteSequence(),
            JobType::SUSPEND
                => $this->registry->suspendSequence(),
            JobType::RESUME
                => $this->registry->resumeSequence(),
            JobType::ARCHIVE
                => $this->registry->archiveSequence(),
            JobType::RESTORE
                => $this->registry->restoreSequence(),
        };
    }

    /**
     * Load the sites row this job operates on. For CREATE jobs against
     * a non-existent site, this method does NOT auto-insert - that's
     * the API controller's responsibility (via
     * SiteStateMachine::createInProvisioning()) so the row exists
     * BEFORE the job is enqueued. Step 5c-2 keeps the worker
     * read-only on sites; the bridge is the only place that writes.
     *
     * @return array<string,mixed>|null
     */
    private function loadOrCreateSiteRow(SiteJob $job): ?array
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT * FROM sites WHERE domain = :domain'
        );
        $stmt->execute(['domain' => $job->siteDomain]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchRow(int $id): ?array
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT * FROM site_jobs WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchRowByIdForUpdate(\PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM site_jobs WHERE id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function actorFromJob(SiteJob $job): ActorContext
    {
        $original = new ActorContext(
            username: $job->actor,
            userId: $job->actorUserId,
            sourceIp: $job->sourceIp,
            requestId: $job->requestId,
        );
        return ActorContext::worker($this->workerId, $original);
    }

    /**
     * Find the FORWARD-direction SagaStepRecord for a given step. The
     * orchestrator records two entries per step that ran AND
     * compensated (one forward + one backward); findStep() returns the
     * latest (backward), which hides whether the forward attempt was a
     * RETRY_LATER. This helper walks the records in order and returns
     * the first forward record for the name (there is at most one).
     */
    private function findForwardRecord(SagaResult $saga, string $stepName): ?SagaStepRecord
    {
        foreach ($saga->stepRecords as $record) {
            if ($record->stepName === $stepName
                && $record->direction === SagaStepRecord::DIRECTION_FORWARD) {
                return $record;
            }
        }
        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function summariseRun(ProvisioningRunResult $run): array
    {
        return [
            'direction' => $run->direction->value,
            'previous_state' => $run->previousState,
            'in_flight_state' => $run->inFlightState,
            'final_state' => $run->finalState,
            'entered_in_flight' => $run->enteredInFlight,
            'exited_in_flight' => $run->exitedInFlight,
            'saga' => $run->saga->toArray(),
            'worker_id' => $this->workerId,
        ];
    }

    private function elapsedMs(float $startMicro): int
    {
        return (int) max(0, round((microtime(true) - $startMicro) * 1000));
    }
}
