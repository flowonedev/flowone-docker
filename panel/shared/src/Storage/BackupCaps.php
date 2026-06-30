<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 7 — Per-snapshot caps.
 *
 * The runner stops rsync at whichever cap is hit first:
 *
 *   - maxSeconds > 0: rsync is invoked with `timeout(1)` wrapping
 *     it so the kernel SIGKILLs the rsync child if it overruns. The
 *     manifest is NOT written in that case (we never publish a
 *     half-built snapshot).
 *   - maxBytes  > 0: rsync's --bwlimit + a post-transfer check
 *     against --stats output. Not a hard kernel-level cap.
 *
 * Both zero means "no cap".
 *
 * Pure value object.
 */
final class BackupCaps
{
    public function __construct(
        public readonly int $maxSeconds,
        public readonly int $maxBytes,
    ) {}

    public static function fromConfig(array $cfg): self
    {
        $caps = $cfg['backup']['caps'] ?? [];
        return new self(
            maxSeconds: max(0, (int) ($caps['max_seconds'] ?? 0)),
            maxBytes:   max(0, (int) ($caps['max_bytes']   ?? 0)),
        );
    }

    public function toArray(): array
    {
        return [
            'max_seconds' => $this->maxSeconds,
            'max_bytes'   => $this->maxBytes,
        ];
    }
}
