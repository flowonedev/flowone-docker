<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 7 — Snapshot writer.
 *
 * One invocation does one snapshot:
 *
 *   1. Acquire snapshot-wide lock (so two cron runs cannot race).
 *   2. Probe destination health (mount, healthcheck file, writable).
 *   3. For each configured tenant:
 *      a. Resolve src + dst paths.
 *      b. Find previous snapshot (any kind, most recent dateKey < today)
 *         to use as --link-dest for hardlink dedup.
 *      c. Build rsync argv with safety flags + caps.
 *      d. Exec rsync with capped wall-clock.
 *      e. Parse --stats output for telemetry.
 *   4. Build + write HMAC-signed manifest at snapshot root.
 *   5. Atomically promote tmp -> final dir (rename).
 *   6. Release lock; return BackupRunResult struct.
 *
 * Failure modes (all journaled):
 *   - Lock already held -> skipped, returns ok=false reason=locked.
 *   - Health probe fails -> aborted, no rsync called.
 *   - rsync exit != 0 -> aborted; tmp dir left in place for manual
 *     inspection (not promoted, not seen by verifier).
 *   - Wall-clock cap hit -> rsync killed; same as rsync failure.
 *   - Manifest write fails -> tmp dir left in place; not promoted.
 *
 * The runner NEVER writes outside destination_root. The runner
 * NEVER modifies anything under source_root.
 */
final class BackupRunner
{
    public function __construct(
        private array            $config,
        private HmacSigner       $signer,
        private OperationJournal $journal,
        private BackupHealthCheck $health,
        private BackupManifest   $manifestService,
        private string           $lockDir,
    ) {}

    public static function build(array $config, HmacSigner $signer, OperationJournal $journal): self
    {
        return new self(
            config:           $config,
            signer:           $signer,
            journal:          $journal,
            health:           BackupHealthCheck::fromConfig($config),
            manifestService:  BackupManifest::fromConfig($config, $signer),
            lockDir:          rtrim((string) $config['state']['dir'], '/'),
        );
    }

    /**
     * Run one snapshot. `dateKey` defaults to today (UTC) so cron
     * runs at 23:55 vs 00:05 still produce sequential snapshots.
     *
     * @return array{
     *   ok: bool, reason: string|null, snapshot: array, tenants: array,
     *   files_total: int, bytes_total: int, elapsed_ms: int,
     *   started_at: int, locked: bool
     * }
     */
    public function run(?string $dateKey = null, bool $dryRun = false, ?BackupCaps $capsOverride = null): array
    {
        $caps = $capsOverride ?? BackupCaps::fromConfig($this->config);
        $dateKey = $dateKey ?? BackupSnapshot::todayKey(new \DateTimeZone('UTC'));

        $started = time();
        $startMs = (int) (microtime(true) * 1000);
        $snapshot = new BackupSnapshot(
            (string) $this->config['backup']['destination_root'],
            BackupSnapshot::KIND_DAILY,
            $dateKey,
        );

        // --- 1. Lock ---------------------------------------------------
        $lockPath = $this->lockDir . '/' . (string) ($this->config['backup']['lock_file'] ?? 'backup.lock');
        $lock = new MountLock($lockPath, waitTimeoutSec: 0);
        if (!$lock->tryAcquire()) {
            $this->journal->record('backup_skipped_locked', ['lock' => $lockPath]);
            return $this->makeResult($snapshot, false, 'locked_by_another_run', [], 0, 0, $started, $startMs, true);
        }

        try {
            // --- 2. Health probe ---------------------------------------
            $hp = $this->health->probe();
            if (!$hp['ok']) {
                $this->journal->record('backup_aborted_unhealthy', ['reasons' => $hp['reasons']]);
                return $this->makeResult($snapshot, false, 'destination_unhealthy: ' . implode('; ', $hp['reasons']),
                    [], 0, 0, $started, $startMs, false);
            }

            // --- 3. Plan ----------------------------------------------
            $tenants = (array) ($this->config['backup']['tenants'] ?? []);
            if (empty($tenants)) {
                return $this->makeResult($snapshot, false, 'no_tenants_configured', [], 0, 0, $started, $startMs, false);
            }

            $tmpDir = $snapshot->tmpPath();
            $finalDir = $snapshot->rootPath();
            $linkDest = $snapshot->findLinkDestCandidate();

            // Re-using yesterday's snapshot if we already promoted it
            // is fine — rsync just hardlinks unchanged files.
            if ($snapshot->exists()) {
                // Idempotent: if today's snapshot already exists, re-running
                // means "refresh it" — rsync into the existing dir directly.
                $writeDir = $finalDir;
                $isFreshSnapshot = false;
            } else {
                // Build into .tmp, atomically promote at the end. If
                // .tmp from a previous failed run still exists, reuse it
                // (rsync resumes naturally).
                $writeDir = $tmpDir;
                $isFreshSnapshot = true;
            }

            if (!is_dir($writeDir) && !@mkdir($writeDir, 0750, true)) {
                $this->journal->record('backup_mkdir_failed', ['dir' => $writeDir]);
                return $this->makeResult($snapshot, false, "mkdir_failed: {$writeDir}", [], 0, 0, $started, $startMs, false);
            }

            // --- 4. Run rsync per tenant -------------------------------
            $tenantResults = [];
            $filesTotal = 0;
            $bytesTotal = 0;
            $deadlineMs = $caps->maxSeconds > 0 ? $startMs + ($caps->maxSeconds * 1000) : 0;

            foreach ($tenants as $tenant) {
                if ($deadlineMs > 0 && (int) (microtime(true) * 1000) > $deadlineMs) {
                    $tenantResults[$tenant] = ['ok' => false, 'reason' => 'wall_clock_cap_before_start'];
                    break;
                }
                $tResult = $this->runRsyncForTenant(
                    tenant:      (string) $tenant,
                    writeDir:    $writeDir,
                    linkDest:    $linkDest,
                    deadlineMs:  $deadlineMs,
                    dryRun:      $dryRun,
                );
                $tenantResults[$tenant] = $tResult;
                if (!$tResult['ok']) {
                    $this->journal->record('backup_tenant_failed', ['tenant' => $tenant, 'reason' => $tResult['reason']]);
                    return $this->makeResult($snapshot, false, "tenant_failed: {$tenant}: " . $tResult['reason'],
                        $tenantResults, $filesTotal, $bytesTotal, $started, $startMs, false);
                }
                $filesTotal += (int) ($tResult['files'] ?? 0);
                $bytesTotal += (int) ($tResult['bytes'] ?? 0);
            }

            // --- 5. Manifest + promote ---------------------------------
            if (!$dryRun) {
                // Manifest is computed against the actual write
                // directory (which may be .tmp during the first build
                // of the day or finalDir during an idempotent re-run).
                $manifestPayload = $this->manifestService->buildAndWrite(
                    $snapshot,
                    $tenants,
                    explicitRoot: $writeDir
                );

                // Atomic promotion (only if we built into .tmp).
                if ($writeDir !== $finalDir) {
                    if (is_dir($finalDir)) {
                        // Should not happen — we only chose .tmp when finalDir didn't
                        // exist. Defensive: refuse to clobber.
                        $this->journal->record('backup_promote_skipped_conflict', ['final' => $finalDir, 'tmp' => $writeDir]);
                    } elseif (!@rename($writeDir, $finalDir)) {
                        $this->journal->record('backup_promote_failed', ['from' => $writeDir, 'to' => $finalDir]);
                        return $this->makeResult($snapshot, false, 'promote_rename_failed',
                            $tenantResults, $filesTotal, $bytesTotal, $started, $startMs, false);
                    }
                }
                $this->journal->record('backup_snapshot_complete', [
                    'snapshot'    => $snapshot->toArray(),
                    'files_total' => $filesTotal,
                    'bytes_total' => $bytesTotal,
                    'manifest'    => $manifestPayload['summary'] ?? null,
                ]);
            }

            return $this->makeResult($snapshot, true, null, $tenantResults, $filesTotal, $bytesTotal, $started, $startMs, false);
        } finally {
            $lock->release();
        }
    }

    /**
     * Build the rsync argv and exec. Returns a per-tenant result.
     *
     * @param BackupSnapshot|null $linkDest previous snapshot to use as --link-dest source
     */
    private function runRsyncForTenant(
        string $tenant,
        string $writeDir,
        ?BackupSnapshot $linkDest,
        int $deadlineMs,
        bool $dryRun
    ): array {
        $srcRoot = rtrim((string) $this->config['backup']['source_root'], '/');
        $src = $srcRoot . '/' . $tenant . '/';   // trailing slash matters in rsync
        $dst = rtrim($writeDir, '/') . '/' . $tenant . '/';

        if (!is_dir($src)) {
            return ['ok' => false, 'reason' => "source_tenant_missing: {$src}"];
        }
        if (!is_dir($dst) && !@mkdir($dst, 0750, true)) {
            return ['ok' => false, 'reason' => "mkdir_failed: {$dst}"];
        }

        $rsyncPath = (string) ($this->config['backup']['rsync_path'] ?? '/usr/bin/rsync');
        if (!is_executable($rsyncPath)) {
            return ['ok' => false, 'reason' => "rsync_not_executable: {$rsyncPath}"];
        }

        $flags = (array) ($this->config['backup']['rsync_flags']       ?? []);
        $extra = (array) ($this->config['backup']['rsync_flags_extra'] ?? []);

        $args = array_merge([$rsyncPath], $flags, $extra);
        if ($linkDest !== null) {
            $linkSrc = rtrim($linkDest->rootPath(), '/') . '/' . $tenant;
            if (is_dir($linkSrc)) {
                $args[] = '--link-dest=' . $linkSrc;
            }
        }
        if ($dryRun) {
            $args[] = '--dry-run';
        }
        $args[] = $src;
        $args[] = $dst;

        // Wall-clock cap via timeout(1). 0 means no cap.
        if ($deadlineMs > 0) {
            $remainingMs = $deadlineMs - (int) (microtime(true) * 1000);
            if ($remainingMs <= 0) {
                return ['ok' => false, 'reason' => 'wall_clock_cap_before_rsync'];
            }
            $timeoutSec = max(1, (int) ceil($remainingMs / 1000));
            array_unshift($args, '/usr/bin/timeout', '--kill-after=10s', (string) $timeoutSec);
        }

        // Build the command string with proper escaping.
        $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        $start = microtime(true);
        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);
        $elapsedMs = (int) ((microtime(true) - $start) * 1000);

        if ($exit !== 0) {
            return [
                'ok'         => false,
                'reason'     => "rsync_exit_{$exit}: " . implode(' | ', array_slice($output, -3)),
                'elapsed_ms' => $elapsedMs,
            ];
        }

        // Parse --stats output for telemetry.
        $stats = $this->parseRsyncStats($output);
        return [
            'ok'         => true,
            'files'      => $stats['files'],
            'bytes'      => $stats['bytes'],
            'elapsed_ms' => $elapsedMs,
            'tenant'     => $tenant,
            'link_dest'  => $linkDest?->dateKey,
        ];
    }

    /**
     * Extract file count + total byte count from rsync --stats output.
     * Tolerant of locale / version differences.
     *
     * @param list<string> $lines
     * @return array{files: int, bytes: int}
     */
    private function parseRsyncStats(array $lines): array
    {
        $files = 0; $bytes = 0;
        foreach ($lines as $line) {
            // "Number of regular files transferred: 12"
            if (preg_match('/Number of regular files transferred:\s*([\d,]+)/', $line, $m)) {
                $files = (int) str_replace([',', '.'], '', $m[1]);
            } elseif (preg_match('/Number of files transferred:\s*([\d,]+)/', $line, $m)) {
                if ($files === 0) $files = (int) str_replace([',', '.'], '', $m[1]);
            }
            // "Total transferred file size: 4,096 bytes"
            if (preg_match('/Total transferred file size:\s*([\d,]+)\s*bytes/', $line, $m)) {
                $bytes = (int) str_replace([',', '.'], '', $m[1]);
            }
        }
        return ['files' => $files, 'bytes' => $bytes];
    }

    private function makeResult(
        BackupSnapshot $snapshot,
        bool $ok,
        ?string $reason,
        array $tenants,
        int $files,
        int $bytes,
        int $started,
        int $startMs,
        bool $locked
    ): array {
        return [
            'ok'          => $ok,
            'reason'      => $reason,
            'snapshot'    => $snapshot->toArray(),
            'tenants'     => $tenants,
            'files_total' => $files,
            'bytes_total' => $bytes,
            'elapsed_ms'  => (int) (microtime(true) * 1000) - $startMs,
            'started_at'  => $started,
            'locked'      => $locked,
        ];
    }
}
