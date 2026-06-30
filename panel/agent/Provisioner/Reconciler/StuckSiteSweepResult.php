<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Reconciler;

/**
 * Outcome of one StuckSiteSweeper::sweep() pass. Returned to the CLI
 * for JSON / human-readable summaries and consumed by the test suite
 * for assertions.
 */
final class StuckSiteSweepResult
{
    /**
     * @param list<array{
     *   site_id:int,
     *   domain:string,
     *   from:string,
     *   to:string,
     *   updated_at:string,
     *   dry_run:bool,
     * }> $recoveries
     */
    public function __construct(
        public readonly int $scanned,
        public readonly int $recovered,
        public readonly int $skipped,
        public readonly array $recoveries,
        public readonly int $elapsedMs,
        public readonly bool $dryRun,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scanned' => $this->scanned,
            'recovered' => $this->recovered,
            'skipped' => $this->skipped,
            'elapsed_ms' => $this->elapsedMs,
            'dry_run' => $this->dryRun,
            'recoveries' => $this->recoveries,
        ];
    }
}
