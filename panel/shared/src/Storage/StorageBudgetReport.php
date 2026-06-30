<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Immutable snapshot of VPS storage budget at a point in time.
 *
 * Produced by StorageBudget::snapshot(). Consumers (admission
 * control, reclaim daemon, operator CLI) decide what to do with
 * it. The report is intentionally a dumb DTO: no behaviour, no
 * I/O, no decisions. Serialisable as JSON in one call.
 *
 * Two layers of numbers, both nullable so the report still works
 * when one layer can't be computed:
 *
 *   - OS layer (always present): disk_free_space / disk_total_space
 *     against the VPS mount point that hosts the drive. Cheap.
 *
 *   - Logical layer (present only when a PDO is wired in): SUM(size)
 *     of drive_files rows whose tier_state is HOT or TIERING — i.e.
 *     bytes our app considers "currently on VPS". This is the number
 *     admission control compares against drive_quota_bytes.
 *
 * Watermark is the worst (highest) of: OS layer + logical layer.
 * `reasons` enumerates which checks tripped, in human-readable form.
 */
final class StorageBudgetReport
{
    public const WM_CLEAR    = 'clear';
    public const WM_WARN     = 'warn';
    public const WM_HIGH     = 'high';
    public const WM_CRITICAL = 'critical';

    public const WATERMARK_RANK = [
        self::WM_CLEAR    => 0,
        self::WM_WARN     => 1,
        self::WM_HIGH     => 2,
        self::WM_CRITICAL => 3,
    ];

    /**
     * @param array<int,string> $reasons
     */
    public function __construct(
        // OS layer (always non-null)
        public readonly int    $vpsTotalBytes,
        public readonly int    $vpsFreeBytes,
        public readonly int    $vpsUsedBytes,
        public readonly float  $vpsUsedPct,
        public readonly string $vpsMountPoint,
        // Logical layer (null when no PDO was provided)
        public readonly ?int   $driveQuotaBytes,
        public readonly ?int   $driveUsedBytes,
        public readonly ?int   $driveFreeBytes,
        public readonly ?float $driveUsedPct,
        public readonly ?int   $driveHotRows,
        // Decision
        public readonly string $watermark,
        public readonly array  $reasons,
        // Telemetry
        public readonly int    $computedAtUnix,
        public readonly float  $computeDurationMs,
        public readonly bool   $fromCache,
    ) {}

    public function isCritical(): bool { return $this->watermark === self::WM_CRITICAL; }
    public function isHigh(): bool     { return $this->watermark === self::WM_HIGH; }
    public function isWarn(): bool     { return $this->watermark === self::WM_WARN; }
    public function isClear(): bool    { return $this->watermark === self::WM_CLEAR; }

    /**
     * Will accepting $bytes of new upload bytes push us over a hard
     * boundary? Returns false when:
     *   - VPS free space minus $bytes would dip below the configured
     *     min_free_bytes floor, OR
     *   - drive_used_bytes + $bytes would exceed drive_quota_bytes
     *     (only when the logical layer is available).
     * Callers can also separately consult $report->watermark to refuse
     * uploads when already at critical even before this check.
     */
    public function canAccept(int $bytes, int $minFreeBytes): bool
    {
        if ($bytes < 0) return false;
        if ($this->vpsFreeBytes - $bytes < $minFreeBytes) {
            return false;
        }
        if ($this->driveQuotaBytes !== null && $this->driveUsedBytes !== null) {
            if ($this->driveUsedBytes + $bytes > $this->driveQuotaBytes) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'watermark'         => $this->watermark,
            'reasons'           => $this->reasons,
            'computed_at_unix'  => $this->computedAtUnix,
            'compute_ms'        => round($this->computeDurationMs, 2),
            'from_cache'        => $this->fromCache,
            'vps' => [
                'mount_point'  => $this->vpsMountPoint,
                'total_bytes'  => $this->vpsTotalBytes,
                'used_bytes'   => $this->vpsUsedBytes,
                'free_bytes'   => $this->vpsFreeBytes,
                'used_pct'     => round($this->vpsUsedPct, 2),
            ],
            'drive' => $this->driveQuotaBytes === null
                ? ['available' => false]
                : [
                    'available'    => true,
                    'quota_bytes'  => $this->driveQuotaBytes,
                    'used_bytes'   => $this->driveUsedBytes,
                    'free_bytes'   => $this->driveFreeBytes,
                    'used_pct'     => $this->driveUsedPct === null ? null : round($this->driveUsedPct, 2),
                    'hot_rows'     => $this->driveHotRows,
                ],
        ];
    }
}
