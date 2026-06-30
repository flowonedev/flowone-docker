<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 7 — Automated restore drill.
 *
 * "A backup that's never been restored isn't a backup."
 *
 * Once per scheduled interval (default quarterly; operator wires the
 * cron) this picks a random file from a random recent snapshot,
 * copies it into a tmp dir, verifies the md5 against the manifest,
 * then deletes the restored copy. The whole drill journals pass/fail
 * so monitoring can alert on a missed quarter.
 *
 * Conservative defaults: small file picked (skip > 100 MiB by
 * default), copy + verify only (never restores to the live drive
 * tree). Operators do real point-in-time restores via storage-ctl
 * backup restore.
 *
 * Pre-condition: manifest must have md5 entries (full_checksum=true
 * at snapshot time). When md5 is missing the drill records
 * skipped=manifest_missing_md5 and exits successfully — operators
 * can flip backup.manifest.full_checksum=true to gain real drill
 * coverage.
 */
final class BackupRestoreDriller
{
    public function __construct(
        private array            $config,
        private BackupManifest   $manifestService,
        private OperationJournal $journal,
        private string           $tmpDir,
        private int              $maxSnapshots,
        private int              $maxBytesPerFile = 104857600, // 100 MiB
    ) {}

    public static function build(array $config, HmacSigner $signer, OperationJournal $journal): self
    {
        $rd = (array) ($config['backup']['restore_drill'] ?? []);
        return new self(
            config:          $config,
            manifestService: BackupManifest::fromConfig($config, $signer),
            journal:         $journal,
            tmpDir:          (string) ($rd['tmp_dir']       ?? '/tmp/flowone-restore-drill'),
            maxSnapshots:    max(1, (int) ($rd['max_snapshots'] ?? 7)),
            maxBytesPerFile: max(1024, (int) ($rd['max_bytes_per_file'] ?? 104857600)),
        );
    }

    /**
     * Run one drill. Returns a structured result. Never throws.
     */
    public function run(): array
    {
        $started = microtime(true);
        $result = [
            'ok'         => false,
            'reason'     => null,
            'snapshot'   => null,
            'file'       => null,
            'bytes'      => 0,
            'elapsed_ms' => 0,
        ];

        $dest = (string) ($this->config['backup']['destination_root'] ?? '');
        if ($dest === '' || !is_dir($dest)) {
            $result['reason'] = "destination_root missing: {$dest}";
            $this->journal->record('backup_drill_aborted', ['reason' => $result['reason']]);
            return $this->finalise($result, $started);
        }

        // Pick a snapshot at random from the union of all kinds,
        // weighted toward more recent (we take the last N).
        $pool = [];
        foreach ([BackupSnapshot::KIND_DAILY, BackupSnapshot::KIND_WEEKLY, BackupSnapshot::KIND_MONTHLY] as $kind) {
            foreach (BackupSnapshot::listKind($dest, $kind) as $s) {
                $pool[] = $s;
            }
        }
        if (empty($pool)) {
            $result['reason'] = 'no_snapshots_to_drill';
            $this->journal->record('backup_drill_skipped', ['reason' => $result['reason']]);
            return $this->finalise($result, $started);
        }
        usort($pool, fn(BackupSnapshot $a, BackupSnapshot $b) => strcmp($b->dateKey, $a->dateKey));
        $pool = array_slice($pool, 0, $this->maxSnapshots);

        try {
            $snapshot = $pool[random_int(0, count($pool) - 1)];
        } catch (\Throwable $e) {
            $result['reason'] = 'random_int_failed: ' . $e->getMessage();
            return $this->finalise($result, $started);
        }
        $result['snapshot'] = $snapshot->toArray();

        $payload = $this->manifestService->read($snapshot);
        if ($payload === null) {
            $result['reason'] = 'manifest_corrupt_or_missing';
            $this->journal->record('backup_drill_failed', $result);
            return $this->finalise($result, $started);
        }
        $files = (array) ($payload['files'] ?? []);
        if (empty($files)) {
            $result['reason'] = 'manifest_empty';
            return $this->finalise($result, $started);
        }

        // Filter to candidate files (have md5, within size cap).
        $candidates = [];
        foreach ($files as $rel => $meta) {
            if (!isset($meta['md5'])) continue;
            if ((int) ($meta['size'] ?? PHP_INT_MAX) > $this->maxBytesPerFile) continue;
            $candidates[$rel] = $meta;
        }
        if (empty($candidates)) {
            $result['reason'] = 'manifest_missing_md5_or_too_large';
            $result['ok'] = true;   // not a hard failure
            $this->journal->record('backup_drill_skipped', $result);
            return $this->finalise($result, $started);
        }

        $relList = array_keys($candidates);
        try {
            $rel = $relList[random_int(0, count($relList) - 1)];
        } catch (\Throwable $e) {
            $result['reason'] = 'random_int_failed: ' . $e->getMessage();
            return $this->finalise($result, $started);
        }
        $meta = $candidates[$rel];
        $abs = $snapshot->rootPath() . '/' . $rel;

        if (!is_file($abs)) {
            $result['reason'] = "candidate_missing_on_disk: {$abs}";
            $this->journal->record('backup_drill_failed', $result);
            return $this->finalise($result, $started);
        }

        // Copy to tmp, verify md5, delete.
        if (!is_dir($this->tmpDir) && !@mkdir($this->tmpDir, 0700, true)) {
            $result['reason'] = "tmp_dir_unavailable: {$this->tmpDir}";
            return $this->finalise($result, $started);
        }
        $dst = $this->tmpDir . '/drill_' . bin2hex(random_bytes(4)) . '.bin';
        try {
            if (!@copy($abs, $dst)) {
                $result['reason'] = "copy_failed: {$abs} -> {$dst}";
                $this->journal->record('backup_drill_failed', $result);
                return $this->finalise($result, $started);
            }
            $actual = @md5_file($dst);
            if ($actual === false || $actual !== ($meta['md5'] ?? '')) {
                $result['reason'] = "md5_mismatch: expected={$meta['md5']} actual=" . ($actual ?: '?');
                $this->journal->record('backup_drill_failed', $result);
                return $this->finalise($result, $started);
            }
            $result['ok'] = true;
            $result['file'] = $rel;
            $result['bytes'] = (int) ($meta['size'] ?? 0);
            $this->journal->record('backup_drill_passed', [
                'snapshot' => $snapshot->toArray(),
                'file'     => $rel,
                'bytes'    => $result['bytes'],
            ]);
        } finally {
            @unlink($dst);
        }
        return $this->finalise($result, $started);
    }

    private function finalise(array $result, float $started): array
    {
        $result['elapsed_ms'] = (int) ((microtime(true) - $started) * 1000);
        return $result;
    }
}
