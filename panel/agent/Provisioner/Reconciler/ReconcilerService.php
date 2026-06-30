<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Reconciler;

use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Exceptions\InvalidStateTransition;
use VpsAdmin\Agent\Provisioner\Exceptions\StateGuardFailed;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobDispatcher;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobPriorityClass;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobStatus;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobType;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\SiteStateMachine;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

/**
 * The reconciler is the system's self-healing loop.
 *
 * Once per cron tick (default: every 5 minutes), the service:
 *   1. Selects sites in actual_state worth checking (active, degraded,
 *      failed, pending_dns) - skipping in-flight states.
 *   2. Probes each one's real-world artifacts via {@see SiteProber}.
 *   3. Assesses drift via {@see DriftAssessor}.
 *   4. For sites that need a RECONCILE, enqueues a job under the
 *      `reconcile` priority class so operator-initiated work is never
 *      blocked.
 *   5. For sites flagged DEGRADE_ONLY, attempts a state-machine
 *      transition from active -> degraded (if legal) so the operator
 *      sees the drift in the dashboard.
 *
 * Dedup contract:
 *   - Before enqueueing, the service checks site_jobs for an existing
 *     queued / running job of type CREATE / RECONCILE / DELETE on the
 *     same domain. If one is found, the enqueue is skipped (with the
 *     reason captured for the operator dashboard). The worker's
 *     idempotent steps would have made a second job effectively a no-op
 *     anyway, but skipping prevents queue churn.
 *
 * Why the reconciler is a class and not just the cron entry point:
 *   - Tests can drive the reconciler against an in-memory site set
 *     without invoking the cron wrapper.
 *   - The operator UI's "Run reconciler now" button calls this same
 *     entrypoint via the agent socket.
 */
final class ReconcilerService
{
    /**
     * Sites in these actual_states are eligible for the scan. Anything
     * not in this list is either intentionally inactive (suspended,
     * archived, absent) or already mid-flight (provisioning, deleting,
     * restoring), so we leave them alone.
     */
    private const ELIGIBLE_ACTUAL_STATES = [
        'active',
        'degraded',
        'failed',
        'pending_dns',
    ];

    /**
     * Job types that, if present and active for a domain, block the
     * reconciler from enqueueing another job.
     */
    private const BLOCKING_JOB_TYPES = [
        JobType::CREATE,
        JobType::RECONCILE,
        JobType::RETRY,
        JobType::DELETE,
        JobType::RESTORE,
    ];

    public function __construct(
        private readonly PanelDatabase $database,
        private readonly JobDispatcher $dispatcher,
        private readonly SiteStateMachine $stateMachine,
        private readonly AuditLogger $audit,
        private readonly SiteProberInterface $prober,
        private readonly DriftAssessor $assessor,
        /** Max sites a single scan() call evaluates; protects long ticks. */
        private readonly int $batchSize = 200,
        /**
         * Read-only mode: probe + assess, but perform NO writes - no
         * RECONCILE enqueue, no degrade flip, no heal transition, and no
         * metric column writes. The per-site verdicts still land in the
         * returned run's `assessments` so the operator sees the plan.
         * Used by `reconcile-sites.php --dry-run`.
         */
        private readonly bool $dryRun = false,
    ) {
    }

    /**
     * Scan up to `$batchSize` sites and react to drift.
     */
    public function scan(?ActorContext $actor = null): ReconcilerRun
    {
        $startedAt = microtime(true);
        $actor ??= ActorContext::reconciler();

        $rows = $this->fetchEligibleSites($this->batchSize);

        $assessments = [];
        $enqueued = [];
        $skipped = [];
        $healthy = 0;
        $reconciled = 0;
        $degraded = 0;
        $healed = 0;
        $skippedCount = 0;

        foreach ($rows as $row) {
            $probe = $this->prober->probe($row);
            // Metrics are a DB write; skip them in dry-run so the scan is
            // genuinely side-effect-free.
            if (!$this->dryRun) {
                $this->persistMetrics((int) $row['id'], $probe);
            }
            $assessment = $this->assessor->assess($row, $probe);
            $assessments[] = $assessment;

            switch ($assessment->recommendation) {
                case DriftAssessment::RECOMMEND_HEALTHY:
                    $healthy++;
                    // Self-heal: a site parked in `degraded` whose artifacts
                    // are now all present (e.g. the operator fixed the
                    // underlying problem - reset the DB password, repaired a
                    // grant) must be promoted back to `active`. Without this
                    // the HEALTHY branch takes no action and the row stays
                    // degraded forever even though everything works.
                    if (!$this->dryRun
                        && (string) ($row['actual_state'] ?? '') === 'degraded'
                        && $this->tryHealToActive($row, $assessment, $actor)) {
                        $healed++;
                    }
                    break;

                case DriftAssessment::RECOMMEND_SKIP:
                    $skippedCount++;
                    break;

                case DriftAssessment::RECOMMEND_RECONCILE:
                    if ($this->dryRun) {
                        // Record the plan without enqueueing.
                        $skipped[] = [
                            'domain' => (string) $row['domain'],
                            'reason' => 'dry_run',
                            'missing' => $assessment->missing,
                        ];
                        $skippedCount++;
                        break;
                    }
                    $jobId = $this->tryEnqueueReconcile($row, $assessment, $probe, $actor, $skipped);
                    if ($jobId !== null) {
                        $enqueued[] = $jobId;
                        $reconciled++;
                    }
                    break;

                case DriftAssessment::RECOMMEND_DEGRADE:
                    if ($this->dryRun) {
                        $skippedCount++;
                        break;
                    }
                    $applied = $this->tryFlipToDegraded($row, $assessment, $actor);
                    if ($applied) {
                        $degraded++;
                    } else {
                        $skippedCount++;
                    }
                    break;
            }
        }

        return new ReconcilerRun(
            sitesScanned: count($rows),
            sitesHealthy: $healthy,
            sitesReconciled: $reconciled,
            sitesDegraded: $degraded,
            sitesSkipped: $skippedCount,
            assessments: $assessments,
            enqueuedJobIds: $enqueued,
            skippedEnqueues: $skipped,
            startedAtUnix: $startedAt,
            finishedAtUnix: microtime(true),
            sitesHealed: $healed,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchEligibleSites(int $limit): array
    {
        $placeholders = implode(',', array_fill(0, count(self::ELIGIBLE_ACTUAL_STATES), '?'));
        $sql = "SELECT * FROM sites
                  WHERE actual_state IN ({$placeholders})
                  ORDER BY updated_at ASC, id ASC
                  LIMIT " . max(1, $limit);
        $stmt = $this->database->pdo()->prepare($sql);
        $stmt->execute(self::ELIGIBLE_ACTUAL_STATES);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $skipped
     */
    private function tryEnqueueReconcile(
        array $row,
        DriftAssessment $assessment,
        SiteHealthProbe $probe,
        ActorContext $actor,
        array &$skipped
    ): ?int {
        $domain = (string) $row['domain'];

        if ($this->hasActiveBlockingJob($domain)) {
            $skipped[] = [
                'domain' => $domain,
                'reason' => 'existing_job_in_flight',
                'missing' => $assessment->missing,
            ];
            return null;
        }

        $payload = [
            'reason' => 'reconciler_drift',
            'missing' => $assessment->missing,
            'severity' => $assessment->severity,
            'probed_at_unix' => $probe->probedAtUnix,
        ];

        $job = $this->dispatcher->enqueue(
            siteDomain: $domain,
            type: JobType::RECONCILE,
            payload: $payload,
            actor: $actor,
            requestId: $actor->requestId ?? 'reconciler-' . bin2hex(random_bytes(4)),
            priority: $this->severityToPriority($assessment->severity),
            priorityClass: JobPriorityClass::RECONCILE,
            maxAttempts: 3,
        );

        $this->audit->record(
            action: 'reconciler_enqueued',
            siteDomain: $domain,
            reason: 'drift detected: ' . implode(',', $assessment->missing),
            before: ['actual_state' => $row['actual_state'] ?? null],
            after: ['job_id' => $job->id, 'severity' => $assessment->severity],
            actor: $actor,
            jobId: $job->id,
        );

        return $job->id;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function tryFlipToDegraded(array $row, DriftAssessment $assessment, ActorContext $actor): bool
    {
        $currentState = (string) ($row['actual_state'] ?? '');
        // Only flip from 'active' -> 'degraded'. Any other state is
        // either already degraded (no-op) or out of the state machine's
        // legal transitions.
        if ($currentState !== 'active') {
            return false;
        }
        try {
            $this->stateMachine->transition(
                siteId: (int) $row['id'],
                from: 'active',
                to: 'degraded',
                reason: 'reconciler: ' . implode('; ', $assessment->reasons),
                actor: $actor,
            );
            return true;
        } catch (InvalidStateTransition | StateGuardFailed) {
            return false;
        }
    }

    /**
     * Promote a healthy-but-degraded site back to `active`. Only the
     * degraded -> active edge is legal here (failed -> active is not in
     * the state machine; those need an explicit re-provision). Guard
     * failures (concurrent transition) are swallowed - the next tick
     * retries.
     *
     * @param array<string, mixed> $row
     */
    private function tryHealToActive(array $row, DriftAssessment $assessment, ActorContext $actor): bool
    {
        try {
            $this->stateMachine->transition(
                siteId: (int) $row['id'],
                from: 'degraded',
                to: 'active',
                reason: 'reconciler: all probed subsystems present, clearing degraded',
                actor: $actor,
            );
            $this->audit->record(
                action: 'reconciler_healed',
                siteDomain: (string) $row['domain'],
                reason: 'degraded -> active: ' . implode('; ', $assessment->reasons),
                before: ['actual_state' => 'degraded'],
                after: ['actual_state' => 'active'],
                actor: $actor,
            );
            return true;
        } catch (InvalidStateTransition | StateGuardFailed) {
            return false;
        }
    }

    private function hasActiveBlockingJob(string $domain): bool
    {
        $typeValues = array_map(static fn(JobType $t) => $t->value, self::BLOCKING_JOB_TYPES);
        $placeholders = implode(',', array_fill(0, count($typeValues), '?'));
        $stmt = $this->database->pdo()->prepare(
            "SELECT COUNT(*) FROM site_jobs
              WHERE site_domain = ?
                AND status IN (?, ?)
                AND type IN ({$placeholders})"
        );
        $args = array_merge(
            [$domain, JobStatus::QUEUED->value, JobStatus::RUNNING->value],
            $typeValues,
        );
        $stmt->execute($args);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Write the metric fields the prober collected (size_bytes,
     * ssl_enabled, ssl_expires_at, ssl_issuer) back into the sites
     * row. These are NOT state transitions - the actual_state column
     * is left alone - so we update the row directly. Failures are
     * logged via the audit table but never abort the scan.
     *
     * Null fields are preserved (we don't blank out a previously
     * known size just because this tick's du failed).
     */
    private function persistMetrics(int $siteId, SiteHealthProbe $probe): void
    {
        $set = [];
        $params = ['id' => $siteId];

        if ($probe->sizeBytes !== null) {
            $set[] = 'size_bytes = :size_bytes';
            $set[] = 'size_probed_at = NOW()';
            $params['size_bytes'] = $probe->sizeBytes;
        }
        // SSL probe returns false meaning "we looked and there is no
        // cert" - that's a meaningful observation, so we write it.
        // null still means "could not probe" and is skipped.
        if ($probe->sslEnabled !== null) {
            $set[] = 'ssl_enabled = :ssl_enabled';
            $params['ssl_enabled'] = $probe->sslEnabled ? 1 : 0;
            $set[] = 'ssl_expires_at = :ssl_expires_at';
            $params['ssl_expires_at'] = $probe->sslExpiresAt;
            $set[] = 'ssl_issuer = :ssl_issuer';
            $params['ssl_issuer'] = $probe->sslIssuer;
        }

        if ($set === []) {
            return;
        }

        try {
            $sql = 'UPDATE sites SET ' . implode(', ', $set) . ' WHERE id = :id';
            $stmt = $this->database->pdo()->prepare($sql);
            $stmt->execute($params);
        } catch (\Throwable $e) {
            // Metrics are advisory; do not let a column-write failure
            // abort the rest of the scan. The next tick will retry.
            // (Logged via audit so operators can see if it's chronic.)
            try {
                $this->audit->record(
                    action: 'reconciler_metrics_write_failed',
                    siteDomain: $probe->domain,
                    reason: $e::class . ': ' . $e->getMessage(),
                    before: null,
                    after: null,
                    actor: ActorContext::reconciler(),
                );
            } catch (\Throwable) {
                // If even the audit insert fails, swallow and move on.
            }
        }
    }

    private function severityToPriority(string $severity): int
    {
        return match ($severity) {
            DriftAssessment::SEVERITY_HIGH => 30,
            DriftAssessment::SEVERITY_MEDIUM => 60,
            DriftAssessment::SEVERITY_LOW => 90,
            default => 100,
        };
    }
}
