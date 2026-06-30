<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 6c — One reclaim cycle (tier-down pass + sweep pass).
 *
 * Wraps the same primitives the hourly cron uses (drive-tier-down.php)
 * but expressed as a reusable class so the daemon can invoke a single
 * cycle on demand with explicit caps. The cron is NOT refactored to
 * call this class — that's a follow-up. For now both paths share the
 * underlying TierStateService / TierBytesMover / TierDestructiveSweeper
 * primitives, so the behaviour is provably identical at the row level.
 *
 * Single cycle semantics:
 *   - Hard caps from ReclaimCaps (bytes, seconds, candidates) — first
 *     cap hit stops the cycle.
 *   - Per-file MountLock at /var/lib/flowone/tier-{file_id}.lock —
 *     same path the cron uses, so cron and daemon cannot race on the
 *     same row.
 *   - Each iteration: lock -> fetch row -> verify VPS file -> compute
 *     checksum if missing -> transitionTo(TIERING) -> mover.tierDown ->
 *     update nas_relative_path -> transitionTo(COLD). Rollback to HOT
 *     on any failure.
 *   - Sweep pass runs after tier-down if destructive flag is on AND
 *     budget gives us spare time inside the cycle cap.
 *
 * Non-throwing: every per-file failure is journaled and counted but
 * never propagates to the daemon loop. The cycle always returns a
 * result struct.
 */
final class ReclaimRunner
{
    public function __construct(
        private \PDO                    $pdo,
        private TierStateService        $tierService,
        private TierBytesMover          $mover,
        private TierDestructiveSweeper  $sweeper,
        private string                  $tenant,
        private string                  $tenantSubpath,    // e.g. 'drive' for email-drive
        private string                  $vpsBase,
        private string                  $lockDir,
        private OperationJournal        $journal,
        private bool                    $destructiveEnabled,
    ) {}

    public static function build(
        \PDO $pdo,
        OperationJournal $journal,
        Invariants $invariants,
        array $config,
        string $tenant,
        string $vpsBase,
        bool $destructiveEnabled
    ): self {
        $resolver = TenantResolver::fromConfig($config);
        $mover    = new TierBytesMover($resolver, $invariants, $journal);
        $tier     = new TierStateService($pdo, 'drive_files', 'drive_tier_transitions', $journal);
        $sweeper  = new TierDestructiveSweeper(
            pdo:         $pdo,
            tierService: $tier,
            mover:       $mover,
            resolver:    $resolver,
            tenant:      $tenant,
            vpsBasePath: $vpsBase,
            lockDir:     rtrim((string) $config['state']['dir'], '/'),
            journal:     $journal,
            tableName:   'drive_files',
            strict:      false,
            lockWaitSec: 2,
        );
        $subpath = (string) ($config['tenants'][$tenant]['subpath'] ?? $tenant);
        return new self(
            pdo:                $pdo,
            tierService:        $tier,
            mover:              $mover,
            sweeper:            $sweeper,
            tenant:             $tenant,
            tenantSubpath:      $subpath,
            vpsBase:            rtrim($vpsBase, '/'),
            lockDir:            rtrim((string) $config['state']['dir'], '/'),
            journal:            $journal,
            destructiveEnabled: $destructiveEnabled,
        );
    }

    /**
     * Execute one reclaim cycle. Always returns a result struct,
     * never throws.
     *
     * @return array{
     *   started_at: int,
     *   elapsed_ms: int,
     *   stopped_by: string,
     *   tier: array{candidates: int, attempted: int, tiered: int, failed: int,
     *                skipped_small: int, skipped_locked: int, skipped_missing: int,
     *                bytes_total: int},
     *   sweep: array{ran: bool, candidates: int, swept: int, failed: int,
     *                  skipped_locked: int, skipped_state_drift: int,
     *                  skipped_nas_missing: int, skipped_checksum_drift: int,
     *                  skipped_vps_missing: int, bytes_total: int}|null,
     * }
     */
    public function runCycle(ReclaimCaps $caps, bool $dryRun): array
    {
        $startedAt = time();
        $startMs   = (int) (microtime(true) * 1000);
        $deadlineMs = $startMs + $caps->maxSeconds * 1000;
        $byteBudget = $caps->maxBytes;

        $tier = [
            'candidates'       => 0,
            'attempted'        => 0,
            'tiered'           => 0,
            'failed'           => 0,
            'skipped_small'    => 0,
            'skipped_locked'   => 0,
            'skipped_missing'  => 0,
            'bytes_total'      => 0,
        ];
        $stoppedBy = 'no_more_candidates';

        // --- Tier-down pass -----------------------------------------
        try {
            $candidates = $this->tierService->findTierDownCandidates(
                $caps->ageDays,
                min($caps->maxCandidates, 500),
                $caps->orderBy,
            );
            $tier['candidates'] = count($candidates);
        } catch (\Throwable $e) {
            $this->journal->record('reclaim_runner_candidates_failed', ['error' => $e->getMessage()]);
            return $this->makeResult($startedAt, $startMs, 'candidates_query_failed', $tier, null);
        }

        foreach ($candidates as $cand) {
            if ((int) (microtime(true) * 1000) > $deadlineMs) {
                $stoppedBy = 'wall_clock_cap';
                break;
            }
            if ($byteBudget <= 0) {
                $stoppedBy = 'byte_cap';
                break;
            }
            if ($tier['attempted'] >= $caps->maxCandidates) {
                $stoppedBy = 'candidate_cap';
                break;
            }
            $fileId = (int) $cand['id'];
            $size   = (int) $cand['size'];

            if ($size < $caps->minFileBytes) {
                $tier['skipped_small']++;
                continue;
            }
            $tier['attempted']++;

            $lock = new MountLock($this->lockDir . '/tier-' . $fileId . '.lock', waitTimeoutSec: 1);
            if (!$lock->tryAcquire()) {
                $tier['skipped_locked']++;
                continue;
            }

            try {
                $bytesMoved = $this->processOneFile($fileId, $size, $dryRun, $tier);
                if ($bytesMoved > 0) {
                    $byteBudget -= $bytesMoved;
                }
            } catch (\Throwable $e) {
                $tier['failed']++;
                $this->journal->record('reclaim_runner_file_exception', [
                    'file_id' => $fileId, 'error' => $e->getMessage(),
                ]);
                // Best-effort rollback if we left the row in TIERING.
                try {
                    if ($this->tierService->getState($fileId) === TierState::TIERING) {
                        $this->tierService->transitionTo($fileId, TierState::HOT, 'reclaim-daemon', 'rollback: exception');
                    }
                } catch (\Throwable) { /* swallow */ }
            } finally {
                $lock->release();
            }
        }

        // --- Sweep pass ---------------------------------------------
        $sweep = null;
        if ($this->destructiveEnabled && !$dryRun) {
            $remainingMs = max(0, $deadlineMs - (int) (microtime(true) * 1000));
            if ($remainingMs >= 1000) {
                try {
                    $sweepRes = $this->sweeper->sweep(
                        graceHours: $caps->graceHours ?? 24,
                        batch:      $caps->sweepBatch,
                        dryRun:     false,
                        maxSeconds: max(1, (int) ($remainingMs / 1000)),
                    );
                    $sweep = $this->normaliseSweepResult($sweepRes, true);
                } catch (\Throwable $e) {
                    $this->journal->record('reclaim_runner_sweep_failed', ['error' => $e->getMessage()]);
                    $sweep = $this->normaliseSweepResult([], true);
                    $sweep['failed'] = 1;
                }
            }
        }

        return $this->makeResult($startedAt, $startMs, $stoppedBy, $tier, $sweep);
    }

    /**
     * Process a single candidate row. Returns the number of bytes
     * successfully moved (0 on skip/failure).
     */
    private function processOneFile(int $fileId, int $size, bool $dryRun, array &$tier): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, user_email, filename, size, checksum, nas_relative_path, tier_state
             FROM drive_files WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $fileId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            $tier['skipped_missing']++;
            return 0;
        }

        // Defensive: state may have transitioned since the candidate query
        // (e.g. a concurrent recall). Only operate on truly hot rows.
        if (($row['tier_state'] ?? '') !== TierState::HOT) {
            $tier['skipped_missing']++;
            return 0;
        }

        $userHash = md5(strtolower((string) $row['user_email']));
        $vpsPath  = "{$this->vpsBase}/{$userHash}/{$row['filename']}";
        if (!is_file($vpsPath)) {
            $tier['skipped_missing']++;
            $this->journal->record('reclaim_runner_vps_missing', [
                'file_id' => $fileId, 'path' => $vpsPath,
            ]);
            return 0;
        }

        $checksum = (string) ($row['checksum'] ?? '');
        if ($checksum === '') {
            $checksum = (string) (md5_file($vpsPath) ?: '');
            if ($checksum === '') {
                $tier['failed']++;
                $this->journal->record('reclaim_runner_checksum_unreadable', [
                    'file_id' => $fileId, 'path' => $vpsPath,
                ]);
                return 0;
            }
            $upd = $this->pdo->prepare("UPDATE drive_files SET checksum = :c WHERE id = :id");
            $upd->execute([':c' => $checksum, ':id' => $fileId]);
        }

        $relUnderTenant = "{$userHash}/{$row['filename']}";
        $relUnderMount  = "{$this->tenantSubpath}/{$relUnderTenant}";

        if ($dryRun) {
            $tier['tiered']++;
            return 0; // dry-run: no actual byte move counted
        }

        // hot -> tiering
        $this->tierService->transitionTo($fileId, TierState::TIERING, 'reclaim-daemon', 'reclaim cycle');

        $move = $this->mover->tierDown($vpsPath, $this->tenant, $relUnderTenant, $checksum);
        if (!$move['ok']) {
            try {
                $this->tierService->transitionTo($fileId, TierState::HOT, 'reclaim-daemon', 'rollback: ' . ($move['error'] ?? 'unknown'));
            } catch (\Throwable $e) {
                $this->journal->record('reclaim_runner_rollback_failed', [
                    'file_id' => $fileId, 'error' => $e->getMessage(),
                ]);
            }
            $tier['failed']++;
            return 0;
        }

        $upd = $this->pdo->prepare(
            "UPDATE drive_files SET nas_relative_path = :nrp WHERE id = :id"
        );
        $upd->execute([':nrp' => $relUnderMount, ':id' => $fileId]);

        // tiering -> cold
        $this->tierService->transitionTo(
            $fileId,
            TierState::COLD,
            'reclaim-daemon',
            'tier-down committed by reclaim cycle',
            null,
            (int) ($move['bytes'] ?? 0),
            (int) ($move['duration_ms'] ?? 0),
        );

        $bytes = (int) ($move['bytes'] ?? $size);
        $tier['tiered']++;
        $tier['bytes_total'] += $bytes;
        return $bytes;
    }

    private function normaliseSweepResult(array $raw, bool $ran): array
    {
        return [
            'ran'                    => $ran,
            'candidates'             => (int) ($raw['candidates']             ?? 0),
            'swept'                  => (int) ($raw['swept']                  ?? 0),
            'failed'                 => (int) ($raw['failed']                 ?? 0),
            'skipped_locked'         => (int) ($raw['skipped_locked']         ?? 0),
            'skipped_state_drift'    => (int) ($raw['skipped_state_drift']    ?? 0),
            'skipped_nas_missing'    => (int) ($raw['skipped_nas_missing']    ?? 0),
            'skipped_checksum_drift' => (int) ($raw['skipped_checksum_drift'] ?? 0),
            'skipped_vps_missing'    => (int) ($raw['skipped_vps_missing']    ?? 0),
            'bytes_total'            => (int) ($raw['bytes_total']            ?? 0),
        ];
    }

    private function makeResult(int $startedAt, int $startMs, string $stoppedBy, array $tier, ?array $sweep): array
    {
        return [
            'started_at' => $startedAt,
            'elapsed_ms' => (int) (microtime(true) * 1000) - $startMs,
            'stopped_by' => $stoppedBy,
            'tier'       => $tier,
            'sweep'      => $sweep,
        ];
    }
}
