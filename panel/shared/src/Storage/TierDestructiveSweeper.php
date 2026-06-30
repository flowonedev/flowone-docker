<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 5c destructive sweep — frees VPS bytes for files that have
 * lived in `cold` state long enough that we trust the NAS copy is
 * safe to make the only copy.
 *
 * NOT bundled with the tier-down pass on purpose:
 *
 *   The earlier in-pass approach (unlink VPS immediately after
 *   tier-down + cold transition) is dangerous: a checksum bug in
 *   the mover would silently destroy bytes because the VPS copy
 *   disappears in the same call that produced the (potentially
 *   broken) NAS copy. A separate grace-period pass gives:
 *     1. an operator window to catch a bad tier-down BEFORE bytes
 *        are gone from VPS, AND
 *     2. a chance to re-verify the NAS checksum at unlink time —
 *        catching silent bitrot or accidental NAS mutation between
 *        the original tier-down and the destructive unlink.
 *
 * Per-file safety contract:
 *   - Acquire the per-file MountLock (same name TierBytesMover /
 *     tier-down / TierRecallService use, so recall in progress
 *     never races with a destructive unlink).
 *   - Re-read `tier_state` under the lock. If it has drifted off
 *     `cold` (e.g. recall in progress, or operator manually re-
 *     hydrated), SKIP.
 *   - Re-checksum the NAS file. If it doesn't match the stored
 *     checksum, JOURNAL and SKIP (flag for operator attention).
 *   - Optionally compare against a fresh re-read of the VPS copy
 *     (when VPS file still exists) — strict mode only.
 *   - Only then `unlink()` the VPS copy and journal the bytes freed.
 *
 * What this class deliberately does NOT do:
 *   - Change `tier_state` (stays `cold`). The state already says the
 *     canonical bytes are on NAS — sweeping just removes the redundant
 *     VPS shadow.
 *   - Touch `nas_relative_path` or `checksum`.
 *   - Operate on `tiering` / `recalling` / `hot` / `lost` rows.
 *   - Operate on the same row twice in the same run (it filters by
 *     "VPS file still present").
 */
final class TierDestructiveSweeper
{
    public const DEFAULT_BATCH       = 25;
    public const DEFAULT_GRACE_HOURS = 24;

    public function __construct(
        private \PDO $pdo,
        private TierStateService $tierService,
        private TierBytesMover $mover,
        private TenantResolver $resolver,
        private string $tenant,
        private string $vpsBasePath,
        private string $lockDir,
        private OperationJournal $journal,
        private string $tableName = 'drive_files',
        private bool $strict = true,
        private int $lockWaitSec = 1,
    ) {}

    /**
     * Find + sweep up to $batch rows whose `cold` window has aged
     * past $graceHours. Returns a structured result for the worker
     * cron to render.
     *
     * @return array{
     *     candidates:int,
     *     attempted:int,
     *     swept:int,
     *     bytes_total:int,
     *     skipped_locked:int,
     *     skipped_state_drift:int,
     *     skipped_nas_missing:int,
     *     skipped_checksum_drift:int,
     *     skipped_vps_missing:int,
     *     failed:int,
     *     entries:list<array<string,mixed>>,
     * }
     */
    public function sweep(int $graceHours, int $batch, bool $dryRun, int $maxSeconds): array
    {
        $startMs = (int) (microtime(true) * 1000);
        $deadlineMs = $startMs + $maxSeconds * 1000;

        $summary = [
            'candidates'             => 0,
            'attempted'              => 0,
            'swept'                  => 0,
            'bytes_total'            => 0,
            'skipped_locked'         => 0,
            'skipped_state_drift'    => 0,
            'skipped_nas_missing'    => 0,
            'skipped_checksum_drift' => 0,
            'skipped_vps_missing'    => 0,
            'failed'                 => 0,
            'entries'                => [],
        ];

        $candidates = $this->findCandidates($graceHours, $batch);
        $summary['candidates'] = count($candidates);

        foreach ($candidates as $row) {
            if ((int) (microtime(true) * 1000) > $deadlineMs) {
                break;
            }
            $fileId = (int) $row['id'];
            $summary['attempted']++;

            $lock = new MountLock(
                $this->lockDir . '/tier-' . $fileId . '.lock',
                $this->lockWaitSec
            );
            if (!$lock->tryAcquire()) {
                $summary['skipped_locked']++;
                continue;
            }

            try {
                // Re-read state UNDER the lock. May have drifted to
                // recalling / hot since we picked the candidate.
                $state = $this->tierService->getState($fileId);
                if ($state !== TierState::COLD) {
                    $summary['skipped_state_drift']++;
                    $this->journal->record('tier_sweep_state_drift', [
                        'file_id' => $fileId,
                        'state'   => $state,
                    ]);
                    continue;
                }

                $vpsPath = $this->vpsPathFor($row);
                if (!is_file($vpsPath)) {
                    // Already swept (or never existed) — nothing to do.
                    $summary['skipped_vps_missing']++;
                    continue;
                }

                // Resolve NAS source from tenant prefix.
                $nasRel = trim((string) ($row['nas_relative_path'] ?? ''));
                $tenantSubpath = (string) ($this->resolver->definition($this->tenant)['subpath'] ?? $this->tenant);
                $prefix = $tenantSubpath . '/';
                if ($nasRel === '' || !str_starts_with($nasRel, $prefix)) {
                    $summary['skipped_nas_missing']++;
                    $this->journal->record('tier_sweep_bad_nas_path', [
                        'file_id'    => $fileId,
                        'nas_rel'    => $nasRel,
                        'tenant'     => $this->tenant,
                    ]);
                    continue;
                }
                $relUnderTenant = substr($nasRel, strlen($prefix));
                $nasAbs = $this->resolver->pathInside($this->tenant, $relUnderTenant);
                if (!is_file($nasAbs)) {
                    $summary['skipped_nas_missing']++;
                    $this->journal->record('tier_sweep_nas_missing', [
                        'file_id' => $fileId,
                        'nas_abs' => $nasAbs,
                    ]);
                    continue;
                }

                // Re-verify NAS checksum. Catches silent bitrot /
                // accidental NAS mutation between tier-down and sweep.
                $storedChecksum = (string) ($row['checksum'] ?? '');
                if ($storedChecksum === '') {
                    $summary['skipped_checksum_drift']++;
                    $this->journal->record('tier_sweep_no_checksum', [
                        'file_id' => $fileId,
                    ]);
                    continue;
                }
                $nasChecksum = md5_file($nasAbs) ?: '';
                if ($nasChecksum !== $storedChecksum) {
                    $summary['skipped_checksum_drift']++;
                    $this->journal->record('tier_sweep_nas_checksum_drift', [
                        'file_id'  => $fileId,
                        'stored'   => $storedChecksum,
                        'actual'   => $nasChecksum,
                        'nas_abs'  => $nasAbs,
                    ]);
                    continue;
                }

                // Strict mode: also re-checksum VPS copy. If it doesn't
                // match the stored hash either, something has tampered
                // with it locally — refuse to delete it (operator
                // attention required).
                if ($this->strict) {
                    $vpsChecksum = md5_file($vpsPath) ?: '';
                    if ($vpsChecksum !== $storedChecksum) {
                        $summary['skipped_checksum_drift']++;
                        $this->journal->record('tier_sweep_vps_checksum_drift', [
                            'file_id'  => $fileId,
                            'stored'   => $storedChecksum,
                            'actual'   => $vpsChecksum,
                            'vps_path' => $vpsPath,
                        ]);
                        continue;
                    }
                }

                $size = (int) ($row['size'] ?? 0);
                if ($size <= 0) {
                    // Backfill from filesystem; cheap stat.
                    $size = (int) @filesize($vpsPath);
                }

                if ($dryRun) {
                    $summary['entries'][] = [
                        'file_id'  => $fileId,
                        'vps_path' => $vpsPath,
                        'bytes'    => $size,
                        'action'   => 'would-unlink',
                    ];
                    $summary['swept']++;
                    $summary['bytes_total'] += $size;
                    continue;
                }

                // Actually delete it.
                if (!$this->mover->unlinkVpsCopy($vpsPath)) {
                    $summary['failed']++;
                    $this->journal->record('tier_sweep_unlink_failed', [
                        'file_id'  => $fileId,
                        'vps_path' => $vpsPath,
                    ]);
                    continue;
                }

                $summary['swept']++;
                $summary['bytes_total'] += $size;
                $summary['entries'][] = [
                    'file_id'  => $fileId,
                    'vps_path' => $vpsPath,
                    'bytes'    => $size,
                    'action'   => 'unlinked',
                ];
                $this->journal->record('tier_sweep_unlinked_vps', [
                    'file_id' => $fileId,
                    'bytes'   => $size,
                ]);
            } catch (\Throwable $e) {
                $summary['failed']++;
                $this->journal->record('tier_sweep_exception', [
                    'file_id' => $fileId,
                    'error'   => $e->getMessage(),
                ]);
            } finally {
                $lock->release();
            }
        }

        return $summary;
    }

    /**
     * Candidate selection: `cold` rows whose `tier_changed_at` is
     * older than `graceHours`. We don't filter by VPS-file-presence
     * at the SQL layer (the filesystem state can drift between query
     * and processing); the loop handles that under the lock.
     *
     * @return list<array<string,mixed>>
     */
    private function findCandidates(int $graceHours, int $batch): array
    {
        $graceHours = max(0, (int) $graceHours);
        $batch = max(1, (int) $batch);

        // Inline the cast int instead of binding it: PDO_SQLITE's
        // handling of bound INT parameters inside `||`-concatenated
        // datetime() modifiers is unreliable (silently produces zero
        // results); inlining works on both drivers and is safe
        // because $graceHours has already been int-cast above.
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $cutoffExpr = $driver === 'sqlite'
            ? "datetime('now', '-{$graceHours} hours')"
            : "DATE_SUB(NOW(), INTERVAL {$graceHours} HOUR)";

        $sql = "SELECT id, user_email, filename, size, checksum, nas_relative_path,
                       tier_state, tier_changed_at
                FROM {$this->tableName}
                WHERE tier_state = :cold
                  AND tier_changed_at IS NOT NULL
                  AND tier_changed_at < {$cutoffExpr}
                  AND nas_relative_path IS NOT NULL
                  AND nas_relative_path <> ''
                ORDER BY tier_changed_at ASC
                LIMIT :batch";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':cold',  TierState::COLD, \PDO::PARAM_STR);
        $stmt->bindValue(':batch', $batch,          \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function vpsPathFor(array $row): string
    {
        $userHash = md5(strtolower((string) $row['user_email']));
        return rtrim($this->vpsBasePath, '/') . '/' . $userHash . '/' . (string) $row['filename'];
    }
}
