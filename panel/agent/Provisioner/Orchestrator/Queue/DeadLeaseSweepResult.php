<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator\Queue;

/**
 * Outcome of one DeadLeaseSweeper::sweep() pass. Used by CLI scripts
 * to print a summary line and by tests to assert recovery counts.
 *
 * scanned == recovered + skipped (modulo concurrent races). A non-zero
 * `skipped` doesn't indicate a bug — it means another sweeper (or a
 * fresh worker) raced us to the same row, which is the expected
 * behaviour of the gated UPDATE.
 *
 * `recoveries` carries lightweight per-row details so the CLI can
 * print a deterministic list for journal log review. The full audit
 * trail (with before/after snapshots and timestamps) lives in
 * site_audit_log.
 */
final class DeadLeaseSweepResult
{
    /**
     * @param list<array{job_id:int, site_domain:string, dead_worker:?string, attempts:int}> $recoveries
     */
    public function __construct(
        public readonly int $scanned,
        public readonly int $recovered,
        public readonly int $skipped,
        public readonly array $recoveries,
        public readonly int $elapsedMs
    ) {
    }

    public function summary(): string
    {
        return sprintf(
            'scanned=%d recovered=%d skipped=%d elapsed=%dms',
            $this->scanned,
            $this->recovered,
            $this->skipped,
            $this->elapsedMs,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'scanned' => $this->scanned,
            'recovered' => $this->recovered,
            'skipped' => $this->skipped,
            'recoveries' => $this->recoveries,
            'elapsed_ms' => $this->elapsedMs,
        ];
    }
}
