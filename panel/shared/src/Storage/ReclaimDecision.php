<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 6c — Reclaim daemon decision struct.
 *
 * Returned by ReclaimController::decide() given the current
 * StorageBudgetReport + ReclaimDaemonState. Tells the daemon:
 *
 *   - What state to transition into next
 *   - How long to sleep before the next decision
 *   - Whether to run a reclaim cycle BEFORE entering the next state
 *   - A human-readable reason (logged to the journal on every transition)
 *
 * Pure value object. ReclaimDaemon never mutates these.
 */
final class ReclaimDecision
{
    public function __construct(
        public readonly string $nextState,           // ReclaimState::*
        public readonly int    $pollIntervalSec,     // seconds to sleep before next tick
        public readonly bool   $shouldReclaim,       // run a tier-down + sweep cycle this tick
        public readonly string $reason,              // free-form explanation
        public readonly string $watermark,           // budget watermark at decision time
        public readonly bool   $paused,              // operator pause observed
        public readonly bool   $killed,              // phase6c kill switch off
    ) {}

    public function toArray(): array
    {
        return [
            'next_state'        => $this->nextState,
            'poll_interval_sec' => $this->pollIntervalSec,
            'should_reclaim'    => $this->shouldReclaim,
            'reason'            => $this->reason,
            'watermark'         => $this->watermark,
            'paused'            => $this->paused,
            'killed'            => $this->killed,
        ];
    }
}
