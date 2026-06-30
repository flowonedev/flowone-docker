<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 7 — Backup operational state.
 *
 * Single DurableJson + HMAC document operators read via
 * `storage-ctl backup status`. Captures:
 *
 *   - last successful snapshot per kind (date, files, bytes, elapsed)
 *   - last failed snapshot attempt (date + reason)
 *   - last verify outcome (date, mode, ok, issue count)
 *   - last restore drill outcome (date, ok, file)
 *   - retention summary (counts per kind, oldest + newest dateKey)
 *   - last full payload from BackupRunner / BackupVerifier / BackupRestoreDriller
 *
 * Operators don't need to grep journal to know "did backup run
 * yesterday? did verify pass? when was the last drill?" — this
 * single file is the answer.
 */
final class BackupStateStore
{
    public function __construct(
        private DurableJson $file,
        private HmacSigner  $signer,
    ) {}

    public static function fromConfig(array $cfg, HmacSigner $signer): self
    {
        $stateDir = (string) $cfg['state']['dir'];
        $name     = (string) ($cfg['backup']['state_file'] ?? 'nas-backup.json');
        return new self(
            new DurableJson(
                $stateDir, $name,
                (string) ($cfg['state']['tmp_suffix'] ?? '.tmp'),
                (string) ($cfg['state']['bak_suffix'] ?? '.bak'),
            ),
            $signer
        );
    }

    public function currentPath(): string { return $this->file->currentPath(); }

    /**
     * Read current state. Falls back to backup on signature failure.
     */
    public function read(): ?array
    {
        $current = $this->file->readCurrent();
        if ($current !== null) {
            $verified = $this->signer->verifyJson($current);
            if (is_array($verified)) return $verified;
        }
        $backup = $this->file->readBackup();
        if ($backup !== null) {
            $verified = $this->signer->verifyJson($backup);
            if (is_array($verified)) return $verified;
        }
        return null;
    }

    /**
     * Atomically merge $partial into current state and publish.
     * Existing keys outside $partial are preserved.
     */
    public function publishPartial(array $partial): void
    {
        $current = $this->read() ?? [];
        $merged = array_replace_recursive($current, $partial);
        $merged['updated_at'] = time();
        $this->file->write($this->signer->signToJson($merged));
    }

    /**
     * Record a snapshot run result.
     */
    public function recordSnapshot(array $runnerResult): void
    {
        $snap = $runnerResult['snapshot'] ?? [];
        $kind = (string) ($snap['kind'] ?? BackupSnapshot::KIND_DAILY);
        $key  = $runnerResult['ok'] ? 'last_snapshot_ok' : 'last_snapshot_failed';
        $this->publishPartial([
            $key => [
                'kind'        => $kind,
                'date_key'    => $snap['date_key'] ?? null,
                'files_total' => $runnerResult['files_total'] ?? 0,
                'bytes_total' => $runnerResult['bytes_total'] ?? 0,
                'elapsed_ms'  => $runnerResult['elapsed_ms'] ?? 0,
                'started_at'  => $runnerResult['started_at'] ?? time(),
                'reason'      => $runnerResult['reason'] ?? null,
            ],
        ]);
    }

    public function recordVerify(array $verifyResult): void
    {
        $this->publishPartial([
            'last_verify' => [
                'snapshot'    => $verifyResult['snapshot'] ?? null,
                'mode'        => $verifyResult['mode'] ?? null,
                'ok'          => (bool) ($verifyResult['ok'] ?? false),
                'checked'     => (int)  ($verifyResult['checked'] ?? 0),
                'md5_checked' => (int)  ($verifyResult['md5_checked'] ?? 0),
                'issue_count' => count($verifyResult['issues'] ?? []) + (int) ($verifyResult['issues_truncated'] ?? 0),
                'completed_at' => time(),
                'reason'      => $verifyResult['reason'] ?? null,
            ],
        ]);
    }

    public function recordDrill(array $drillResult): void
    {
        $this->publishPartial([
            'last_drill' => [
                'ok'        => (bool) ($drillResult['ok'] ?? false),
                'snapshot'  => $drillResult['snapshot'] ?? null,
                'file'      => $drillResult['file'] ?? null,
                'bytes'     => (int)  ($drillResult['bytes'] ?? 0),
                'elapsed_ms'=> (int)  ($drillResult['elapsed_ms'] ?? 0),
                'completed_at' => time(),
                'reason'    => $drillResult['reason'] ?? null,
            ],
        ]);
    }

    /**
     * Refresh the retention summary (counts + oldest/newest per kind).
     * Called after every snapshot + retention apply.
     */
    public function recordRetentionSummary(string $destinationRoot): void
    {
        $summary = [];
        foreach ([BackupSnapshot::KIND_DAILY, BackupSnapshot::KIND_WEEKLY, BackupSnapshot::KIND_MONTHLY] as $kind) {
            $list = BackupSnapshot::listKind($destinationRoot, $kind);
            $summary[$kind] = [
                'count'  => count($list),
                'oldest' => $list[0]->dateKey ?? null,
                'newest' => $list[count($list) - 1]->dateKey ?? null,
            ];
        }
        $this->publishPartial(['retention' => $summary]);
    }
}
