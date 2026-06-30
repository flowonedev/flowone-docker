<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Reconciler;

/**
 * Aggregate outcome of one ReconcilerService::scan() call.
 *
 * The cron entry point prints this for log scraping; the same shape is
 * returned via the API for the operator dashboard's "last reconciler
 * run" widget.
 */
final class ReconcilerRun
{
    /**
     * @param list<DriftAssessment> $assessments  Per-site verdicts.
     * @param list<int>             $enqueuedJobIds  RECONCILE jobs created.
     * @param list<array<string,mixed>> $skippedEnqueues  Sites where drift was
     *                                  detected but an existing in-flight
     *                                  job blocked enqueue.
     */
    public function __construct(
        public readonly int $sitesScanned,
        public readonly int $sitesHealthy,
        public readonly int $sitesReconciled,
        public readonly int $sitesDegraded,
        public readonly int $sitesSkipped,
        public readonly array $assessments,
        public readonly array $enqueuedJobIds,
        public readonly array $skippedEnqueues,
        public readonly float $startedAtUnix,
        public readonly float $finishedAtUnix,
        /** Sites promoted degraded -> active because they probed healthy. */
        public readonly int $sitesHealed = 0
    ) {
    }

    public function durationMs(): int
    {
        return (int) max(0, round(($this->finishedAtUnix - $this->startedAtUnix) * 1000));
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        return [
            'sites_scanned' => $this->sitesScanned,
            'sites_healthy' => $this->sitesHealthy,
            'sites_reconciled' => $this->sitesReconciled,
            'sites_degraded' => $this->sitesDegraded,
            'sites_healed' => $this->sitesHealed,
            'sites_skipped' => $this->sitesSkipped,
            'enqueued_job_ids' => $this->enqueuedJobIds,
            'skipped_enqueues' => $this->skippedEnqueues,
            'started_at_unix' => $this->startedAtUnix,
            'finished_at_unix' => $this->finishedAtUnix,
            'duration_ms' => $this->durationMs(),
        ];
    }
}
