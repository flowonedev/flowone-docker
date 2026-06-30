<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 6c — Per-cycle reclaim limits.
 *
 * Read from config tier.reclaim.* at daemon startup and passed to
 * ReclaimRunner. The runner stops at whichever cap is hit first:
 *
 *   - $maxBytes        cumulative bytes successfully tiered down
 *   - $maxSeconds      wall-clock seconds spent inside the cycle
 *   - $maxCandidates   number of candidate rows considered (NOT tiered)
 *
 * The remaining parameters tune the underlying primitives:
 *
 *   - $ageDays         findTierDownCandidates() filter
 *   - $minFileBytes    skip files smaller than this (don't waste tier-down on tiny files)
 *   - $orderBy         'age' | 'lru' — passed to findTierDownCandidates()
 *   - $sweepBatch      TierDestructiveSweeper batch size
 *   - $graceHours      sweep grace period override (null = use config default)
 *
 * Pure value object — constructed once at daemon startup, immutable.
 */
final class ReclaimCaps
{
    public function __construct(
        public readonly int     $maxBytes,
        public readonly int     $maxSeconds,
        public readonly int     $maxCandidates,
        public readonly int     $ageDays,
        public readonly int     $minFileBytes,
        public readonly string  $orderBy,
        public readonly int     $sweepBatch,
        public readonly ?int    $graceHours = null,
    ) {}

    public static function fromConfig(array $cfg): self
    {
        $r = $cfg['tier']['reclaim'] ?? [];
        $orderBy = (string) ($r['order_by'] ?? 'lru');
        if ($orderBy !== 'age' && $orderBy !== 'lru') {
            $orderBy = 'lru';
        }
        return new self(
            maxBytes:      max(0, (int) ($r['max_bytes_per_cycle']    ?? 1073741824)),
            maxSeconds:    max(1, (int) ($r['max_seconds_per_cycle']  ?? 60)),
            maxCandidates: max(1, (int) ($r['max_candidates_per_cycle'] ?? 50)),
            ageDays:       max(0, (int) ($r['age_days']               ?? 30)),
            minFileBytes:  max(0, (int) ($r['min_file_bytes']         ?? 1048576)),
            orderBy:       $orderBy,
            sweepBatch:    max(1, (int) ($r['sweep_batch']            ?? 25)),
            graceHours:    isset($r['grace_hours_override']) ? max(0, (int) $r['grace_hours_override']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'max_bytes'      => $this->maxBytes,
            'max_seconds'    => $this->maxSeconds,
            'max_candidates' => $this->maxCandidates,
            'age_days'       => $this->ageDays,
            'min_file_bytes' => $this->minFileBytes,
            'order_by'       => $this->orderBy,
            'sweep_batch'    => $this->sweepBatch,
            'grace_hours'    => $this->graceHours,
        ];
    }
}
