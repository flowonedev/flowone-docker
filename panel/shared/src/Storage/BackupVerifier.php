<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 7 — Snapshot verifier.
 *
 * Two modes:
 *
 *   - Light  (default): for each entry in the manifest, stat the
 *     file and confirm size + mtime match. Cheap; designed to run
 *     after every snapshot to catch deletion + truncation.
 *
 *   - Sample (--sample=N): light mode for everything, plus md5 for
 *     N random files. Catches bit-rot without paying the full
 *     re-hash cost.
 *
 *   - Full   (--full): light mode + md5 every file. Use for periodic
 *     deep audit (weekly or post-incident).
 *
 * The verifier ALWAYS checks the manifest's HMAC signature first.
 * If the signature is invalid the verifier refuses to interpret the
 * payload and returns ok=false with reason=manifest_corrupt.
 *
 * Result struct:
 *   ok:           true iff every check passed
 *   reason:       short failure summary (null on success)
 *   checked:      number of files inspected
 *   md5_checked:  number of files for which md5 was recomputed
 *   issues:       per-file findings (max 100; the verifier counts
 *                 everything beyond that as "issues_truncated")
 *   summary:      derived counters
 */
final class BackupVerifier
{
    public function __construct(
        private array            $config,
        private BackupManifest   $manifestService,
        private OperationJournal $journal,
    ) {}

    public static function build(array $config, HmacSigner $signer, OperationJournal $journal): self
    {
        return new self(
            $config,
            BackupManifest::fromConfig($config, $signer),
            $journal
        );
    }

    /**
     * Verify a single snapshot.
     *
     * @param 'light'|'sample'|'full' $mode
     */
    public function verify(BackupSnapshot $snapshot, string $mode = 'light', ?int $sampleSize = null): array
    {
        $started = microtime(true);
        $result = [
            'ok'           => false,
            'reason'       => null,
            'mode'         => $mode,
            'snapshot'     => $snapshot->toArray(),
            'checked'      => 0,
            'md5_checked'  => 0,
            'issues'       => [],
            'issues_truncated' => 0,
            'summary'      => [],
        ];

        $payload = $this->manifestService->read($snapshot);
        if ($payload === null) {
            $result['reason'] = 'manifest_corrupt_or_missing';
            $this->journal->record('backup_verify_failed', ['snapshot' => $snapshot->toArray(), 'reason' => $result['reason']]);
            return $result;
        }

        $files = (array) ($payload['files'] ?? []);
        if (empty($files)) {
            $result['ok'] = true;
            $result['summary'] = ['file_count' => 0, 'byte_count' => 0];
            return $result;
        }

        $sampleN = $mode === 'sample'
            ? ($sampleSize ?? (int) ($this->config['backup']['verify']['sample_size'] ?? 50))
            : ($mode === 'full' ? PHP_INT_MAX : 0);

        // Pick md5-target indices.
        $md5Targets = $this->pickMd5Targets($files, $sampleN);
        $md5Set = array_flip($md5Targets);

        $root = $snapshot->rootPath();
        $issuesMax = 100;
        foreach ($files as $rel => $meta) {
            $result['checked']++;
            $abs = $root . '/' . $rel;
            if (!is_file($abs)) {
                $this->reportIssue($result, $rel, 'missing', $meta, null, $issuesMax);
                continue;
            }
            $stat = @stat($abs);
            if ($stat === false) {
                $this->reportIssue($result, $rel, 'stat_failed', $meta, null, $issuesMax);
                continue;
            }
            if ((int) $stat['size'] !== (int) ($meta['size'] ?? -1)) {
                $this->reportIssue($result, $rel, 'size_drift', $meta, ['size' => (int) $stat['size']], $issuesMax);
                continue;
            }
            if (isset($meta['mtime']) && (int) $stat['mtime'] !== (int) $meta['mtime']) {
                // mtime drift is recorded but doesn't fail the verify by
                // default — operators occasionally `touch -d` snapshots.
                // Only flag when it differs by > 5s (filesystem-level
                // noise floor).
                if (abs((int) $stat['mtime'] - (int) $meta['mtime']) > 5) {
                    $this->reportIssue($result, $rel, 'mtime_drift', $meta, ['mtime' => (int) $stat['mtime']], $issuesMax);
                    continue;
                }
            }
            // md5 check (only for targets, only if manifest carries md5).
            if (isset($md5Set[$rel]) && isset($meta['md5'])) {
                $result['md5_checked']++;
                $actual = @md5_file($abs);
                if ($actual === false || $actual !== $meta['md5']) {
                    $this->reportIssue($result, $rel, 'md5_drift', $meta, ['md5' => $actual ?: '?'], $issuesMax);
                    continue;
                }
            }
        }

        $result['ok'] = count($result['issues']) === 0 && $result['issues_truncated'] === 0;
        $result['summary'] = [
            'file_count'   => (int) ($payload['summary']['file_count'] ?? count($files)),
            'byte_count'   => (int) ($payload['summary']['byte_count'] ?? 0),
            'elapsed_ms'   => (int) ((microtime(true) - $started) * 1000),
            'issue_count'  => count($result['issues']) + $result['issues_truncated'],
        ];
        if (!$result['ok']) {
            $result['reason'] = "issues_detected: " . $result['summary']['issue_count'];
        }

        $this->journal->record('backup_verify_complete', [
            'snapshot' => $snapshot->toArray(),
            'mode'     => $mode,
            'ok'       => $result['ok'],
            'summary'  => $result['summary'],
        ]);

        return $result;
    }

    /**
     * @param array<string,mixed> $files
     * @param int $sampleN  PHP_INT_MAX = all
     * @return list<string>  manifest keys
     */
    private function pickMd5Targets(array $files, int $sampleN): array
    {
        if ($sampleN <= 0) return [];
        $keys = array_keys($files);
        if ($sampleN >= count($keys)) return $keys;
        $picks = (array) array_rand($keys, $sampleN);
        return array_map(fn($i) => (string) $keys[$i], $picks);
    }

    private function reportIssue(array &$result, string $rel, string $kind, array $expected, ?array $actual, int $max): void
    {
        if (count($result['issues']) >= $max) {
            $result['issues_truncated']++;
            return;
        }
        $result['issues'][] = [
            'path'     => $rel,
            'kind'     => $kind,
            'expected' => $expected,
            'actual'   => $actual,
        ];
    }
}
