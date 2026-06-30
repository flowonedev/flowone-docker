<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 6c — Persisted daemon state.
 *
 * The reclaim daemon publishes its current state + counters to a
 * DurableJson file under state.dir. Operators read this via
 * `storage-ctl reclaim status`; if the daemon dies mid-cycle, the
 * file remains and the next startup can resume cooldown timing
 * without "amnesia".
 *
 * Payload is HMAC-signed using the shared storage signer so an
 * accidental edit (or partial write) is detected on read.
 *
 * Schema:
 *   state:               current ReclaimState
 *   last_decision:       last ReclaimDecision::toArray()
 *   last_reclaim_at:     unix ts of last RECLAIMING tick (0 if never)
 *   last_cycle_summary:  ReclaimRunner result struct (or null)
 *   counters:            cumulative since daemon startup
 *   pid:                 daemon PID (sanity for storage-ctl)
 *   boot_epoch:          shared boot epoch (matches health state)
 *   updated_at:          unix ts of last publish
 *
 * Counters intentionally non-cumulative across daemon restarts — the
 * file is re-created with zeros on each boot.
 */
final class ReclaimDaemonStateStore
{
    private DurableJson $file;
    private HmacSigner  $signer;

    public function __construct(DurableJson $file, HmacSigner $signer)
    {
        $this->file   = $file;
        $this->signer = $signer;
    }

    public static function fromConfig(array $config, HmacSigner $signer): self
    {
        $reclaim  = $config['tier']['reclaim'] ?? [];
        $stateDir = (string) $config['state']['dir'];
        $name     = (string) ($reclaim['state_file'] ?? 'reclaim-daemon.json');
        return new self(
            new DurableJson(
                $stateDir,
                $name,
                (string) ($config['state']['tmp_suffix'] ?? '.tmp'),
                (string) ($config['state']['bak_suffix'] ?? '.bak'),
            ),
            $signer
        );
    }

    public function publish(array $payload): void
    {
        $payload['updated_at'] = time();
        $this->file->write($this->signer->signToJson($payload));
    }

    /**
     * Read current published state. Tries current first, falls back
     * to backup if current is unreadable, missing, or signature-invalid.
     * Returns null only when both fail (treat as "no published state").
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

    public function currentPath(): string
    {
        return $this->file->currentPath();
    }
}
