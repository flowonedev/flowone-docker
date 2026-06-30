<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 6a — Storage budget accounting.
 *
 * Single source of truth for "how much VPS storage is available for
 * new Drive uploads". Consumed by:
 *   - Admission control (Phase 6b): refuse uploads when critical
 *   - Reclaim daemon (Phase 6c): trigger tier-down when high
 *   - storage-ctl budget (operator visibility)
 *
 * Computes two independent layers and surfaces the worst-case:
 *
 *   OS layer  — disk_free_space() / disk_total_space() against the
 *               VPS mount point. Always available; sub-millisecond.
 *
 *   Logical layer (optional) — SUM(size) of drive_files whose
 *               tier_state ∈ {hot, tiering}. Reflects what our app
 *               thinks is on VPS, which can lawfully differ from
 *               the OS view (e.g. tier-down in progress, orphaned
 *               files outside DB). Needs a PDO; when absent, the
 *               logical layer is reported as `available=false` and
 *               watermark decisions fall back to the OS layer only.
 *
 * Cache: process-local memoization with TTL. The cache is keyed by
 * the watermark-relevant inputs so callers can force a refresh by
 * passing $bypassCache. Long-running daemons (the reclaim loop in
 * 6c) get a fast hot path; short-lived CLI commands compute fresh.
 *
 * The class is intentionally synchronous and side-effect-free
 * (except for journal events when watermark changes). It does NOT:
 *   - Trigger tier-downs (that's the reclaim daemon's job)
 *   - Refuse uploads (that's admission control's job)
 *   - Persist anything to disk (the journal entry is the only write)
 */
final class StorageBudget
{
    public const DEFAULT_CACHE_TTL_SEC = 30;

    private ?StorageBudgetReport $cachedReport = null;
    private int $cachedAtNs = 0;
    private string $lastWatermark = StorageBudgetReport::WM_CLEAR;

    public function __construct(
        private string $vpsMountPoint,        // e.g. "/var/www/vps-email/storage/drive" or "/"
        private int    $driveQuotaBytes,      // 0 = no quota; only OS layer applies
        private int    $minFreeBytes,         // hard floor; below this -> critical
        private int    $warnVpsPct,           // OS layer watermarks
        private int    $highVpsPct,
        private int    $criticalVpsPct,
        private int    $warnDrivePct,         // logical layer watermarks
        private int    $highDrivePct,
        private int    $criticalDrivePct,
        private string $tableName = 'drive_files',
        private int    $cacheTtlSec = self::DEFAULT_CACHE_TTL_SEC,
        private ?\PDO  $pdo = null,
        private ?OperationJournal $journal = null,
    ) {}

    /**
     * Build a StorageBudget from the shared config + caller-supplied
     * PDO. PDO may be null (e.g. storage-ctl which doesn't have a DB
     * handle wired in) — the report will then omit the logical layer.
     *
     * @param array<string,mixed>|null $config  if null, loaded from Config::load()
     */
    public static function build(?\PDO $pdo, ?array $config = null, ?OperationJournal $journal = null): self
    {
        $cfg    = $config ?? Config::load();
        $budget = $cfg['tier']['budget'] ?? [];

        return new self(
            vpsMountPoint:    (string) ($budget['vps_mount_point']    ?? '/'),
            driveQuotaBytes:  (int)    ($budget['drive_quota_bytes']  ?? 0),
            minFreeBytes:     (int)    ($budget['min_free_bytes']     ?? 5_368_709_120), // 5 GiB
            warnVpsPct:       (int)    ($budget['warn_vps_pct']       ?? 70),
            highVpsPct:       (int)    ($budget['high_vps_pct']       ?? 80),
            criticalVpsPct:   (int)    ($budget['critical_vps_pct']   ?? 90),
            warnDrivePct:     (int)    ($budget['warn_drive_pct']     ?? 70),
            highDrivePct:     (int)    ($budget['high_drive_pct']     ?? 85),
            criticalDrivePct: (int)    ($budget['critical_drive_pct'] ?? 95),
            tableName:        (string) ($budget['table_name']         ?? 'drive_files'),
            cacheTtlSec:      (int)    ($budget['cache_ttl_sec']      ?? self::DEFAULT_CACHE_TTL_SEC),
            pdo:              $pdo,
            journal:          $journal,
        );
    }

    public function snapshot(bool $bypassCache = false): StorageBudgetReport
    {
        if (!$bypassCache && $this->cachedReport !== null && $this->cachedAtNs > 0) {
            $age = MonotonicClock::elapsedSec($this->cachedAtNs);
            if ($age < $this->cacheTtlSec) {
                // Return cached report with fromCache=true so callers
                // can tell at a glance whether a number is fresh.
                return $this->withFromCache($this->cachedReport, true);
            }
        }
        return $this->compute();
    }

    /**
     * Compute a fresh report and refresh the cache. Journals watermark
     * transitions (only when the watermark CHANGED, never on plain
     * refreshes) so the operation journal carries a useful event
     * stream rather than a flood of duplicates.
     */
    private function compute(): StorageBudgetReport
    {
        $startNs = MonotonicClock::nowNs();

        // ─── OS layer ──────────────────────────────────────────────
        // disk_total_space/disk_free_space against the configured
        // mount point. Both return 0.0 (cast to int) on failure; we
        // detect with is_dir() pre-check + sanity bounds.
        $mount = $this->vpsMountPoint;
        if (!is_dir($mount)) {
            // Soft fallback: if the configured mount doesn't exist,
            // walk up to the nearest existing ancestor. Avoids
            // crashing on misconfig (e.g. drive_storage_path not yet
            // created on a fresh install).
            $probe = $mount;
            while ($probe !== '' && $probe !== '/' && !is_dir($probe)) {
                $probe = dirname($probe);
            }
            $mount = $probe !== '' ? $probe : '/';
        }
        $total = (int) @disk_total_space($mount);
        $free  = (int) @disk_free_space($mount);
        if ($total <= 0) {
            // Last-resort fallback — we still want to publish SOMETHING
            // so admission control can refuse new bytes; mark as
            // critical with an explanatory reason.
            return $this->buildFallbackReport($mount, 'disk_total_space failed');
        }
        $used    = max(0, $total - $free);
        $usedPct = $total > 0 ? ($used / $total) * 100.0 : 0.0;

        // ─── Logical layer (drive_files SUM) ───────────────────────
        $driveUsed   = null;
        $driveQuota  = null;
        $driveFree   = null;
        $driveUsedPct = null;
        $driveRows   = null;
        if ($this->pdo !== null && $this->driveQuotaBytes > 0) {
            try {
                $sumQuery = "SELECT COALESCE(SUM(size), 0) AS bytes, COUNT(*) AS rows_count
                             FROM {$this->tableName}
                             WHERE tier_state IN ('" . TierState::HOT . "', '" . TierState::TIERING . "')";
                $stmt = $this->pdo->query($sumQuery);
                if ($stmt !== false) {
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                    if (is_array($row)) {
                        $driveUsed    = (int) ($row['bytes'] ?? 0);
                        $driveRows    = (int) ($row['rows_count'] ?? 0);
                        $driveQuota   = $this->driveQuotaBytes;
                        $driveFree    = max(0, $driveQuota - $driveUsed);
                        $driveUsedPct = $driveQuota > 0 ? ($driveUsed / $driveQuota) * 100.0 : 0.0;
                    }
                }
            } catch (\Throwable $e) {
                // Logical layer unavailable -> fall back to OS-only.
                // Journal so an operator can see if this is recurring.
                $this->journal?->record('budget_sum_query_failed', [
                    'error' => $e->getMessage(),
                    'table' => $this->tableName,
                ]);
                $driveUsed = $driveQuota = $driveFree = $driveUsedPct = $driveRows = null;
            }
        }

        // ─── Watermark decision ────────────────────────────────────
        // Take the WORST of OS-layer and logical-layer. The free-bytes
        // hard floor and quota overrun always escalate to critical.
        $watermark = StorageBudgetReport::WM_CLEAR;
        $reasons   = [];

        if ($free < $this->minFreeBytes) {
            $watermark = self::worse($watermark, StorageBudgetReport::WM_CRITICAL);
            $reasons[] = sprintf('vps_free_bytes=%d below min_free_bytes=%d', $free, $this->minFreeBytes);
        }
        if ($usedPct >= $this->criticalVpsPct) {
            $watermark = self::worse($watermark, StorageBudgetReport::WM_CRITICAL);
            $reasons[] = sprintf('vps_used_pct=%.1f >= critical_vps_pct=%d', $usedPct, $this->criticalVpsPct);
        } elseif ($usedPct >= $this->highVpsPct) {
            $watermark = self::worse($watermark, StorageBudgetReport::WM_HIGH);
            $reasons[] = sprintf('vps_used_pct=%.1f >= high_vps_pct=%d', $usedPct, $this->highVpsPct);
        } elseif ($usedPct >= $this->warnVpsPct) {
            $watermark = self::worse($watermark, StorageBudgetReport::WM_WARN);
            $reasons[] = sprintf('vps_used_pct=%.1f >= warn_vps_pct=%d', $usedPct, $this->warnVpsPct);
        }

        if ($driveQuota !== null && $driveUsedPct !== null) {
            if ($driveUsed > $driveQuota) {
                $watermark = self::worse($watermark, StorageBudgetReport::WM_CRITICAL);
                $reasons[] = sprintf('drive_used_bytes=%d > drive_quota_bytes=%d', $driveUsed, $driveQuota);
            } elseif ($driveUsedPct >= $this->criticalDrivePct) {
                $watermark = self::worse($watermark, StorageBudgetReport::WM_CRITICAL);
                $reasons[] = sprintf('drive_used_pct=%.1f >= critical_drive_pct=%d', $driveUsedPct, $this->criticalDrivePct);
            } elseif ($driveUsedPct >= $this->highDrivePct) {
                $watermark = self::worse($watermark, StorageBudgetReport::WM_HIGH);
                $reasons[] = sprintf('drive_used_pct=%.1f >= high_drive_pct=%d', $driveUsedPct, $this->highDrivePct);
            } elseif ($driveUsedPct >= $this->warnDrivePct) {
                $watermark = self::worse($watermark, StorageBudgetReport::WM_WARN);
                $reasons[] = sprintf('drive_used_pct=%.1f >= warn_drive_pct=%d', $driveUsedPct, $this->warnDrivePct);
            }
        }

        if ($reasons === []) {
            $reasons = ['within all watermarks'];
        }

        $elapsedMs = MonotonicClock::elapsedSec($startNs) * 1000.0;

        $report = new StorageBudgetReport(
            vpsTotalBytes:     $total,
            vpsFreeBytes:      $free,
            vpsUsedBytes:      $used,
            vpsUsedPct:        $usedPct,
            vpsMountPoint:     $mount,
            driveQuotaBytes:   $driveQuota,
            driveUsedBytes:    $driveUsed,
            driveFreeBytes:    $driveFree,
            driveUsedPct:      $driveUsedPct,
            driveHotRows:      $driveRows,
            watermark:         $watermark,
            reasons:           $reasons,
            computedAtUnix:    time(),
            computeDurationMs: $elapsedMs,
            fromCache:         false,
        );

        $this->cachedReport = $report;
        $this->cachedAtNs = MonotonicClock::nowNs();

        // Journal watermark transitions only — refreshes that stay at
        // the same level are silent to avoid flooding the journal.
        if ($watermark !== $this->lastWatermark) {
            $this->journal?->record('budget_watermark_change', [
                'from'          => $this->lastWatermark,
                'to'            => $watermark,
                'vps_used_pct'  => round($usedPct, 1),
                'vps_free_bytes'=> $free,
                'drive_used_pct'=> $driveUsedPct === null ? null : round($driveUsedPct, 1),
                'reasons'       => $reasons,
            ]);
            $this->lastWatermark = $watermark;
        }

        return $report;
    }

    private function buildFallbackReport(string $mount, string $reason): StorageBudgetReport
    {
        return new StorageBudgetReport(
            vpsTotalBytes: 0, vpsFreeBytes: 0, vpsUsedBytes: 0, vpsUsedPct: 100.0,
            vpsMountPoint: $mount,
            driveQuotaBytes: null, driveUsedBytes: null, driveFreeBytes: null,
            driveUsedPct: null, driveHotRows: null,
            watermark: StorageBudgetReport::WM_CRITICAL,
            reasons: ['fallback: ' . $reason],
            computedAtUnix: time(),
            computeDurationMs: 0.0,
            fromCache: false,
        );
    }

    private function withFromCache(StorageBudgetReport $r, bool $fromCache): StorageBudgetReport
    {
        return new StorageBudgetReport(
            vpsTotalBytes:     $r->vpsTotalBytes,
            vpsFreeBytes:      $r->vpsFreeBytes,
            vpsUsedBytes:      $r->vpsUsedBytes,
            vpsUsedPct:        $r->vpsUsedPct,
            vpsMountPoint:     $r->vpsMountPoint,
            driveQuotaBytes:   $r->driveQuotaBytes,
            driveUsedBytes:    $r->driveUsedBytes,
            driveFreeBytes:    $r->driveFreeBytes,
            driveUsedPct:      $r->driveUsedPct,
            driveHotRows:      $r->driveHotRows,
            watermark:         $r->watermark,
            reasons:           $r->reasons,
            computedAtUnix:    $r->computedAtUnix,
            computeDurationMs: $r->computeDurationMs,
            fromCache:         $fromCache,
        );
    }

    /**
     * Returns whichever of $a / $b is the worse (higher) watermark.
     */
    private static function worse(string $a, string $b): string
    {
        $rA = StorageBudgetReport::WATERMARK_RANK[$a] ?? 0;
        $rB = StorageBudgetReport::WATERMARK_RANK[$b] ?? 0;
        return $rA >= $rB ? $a : $b;
    }
}
