<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Append-only operation journal.
 *
 * One JSONL line per event, written with O_APPEND so concurrent writers
 * do not tear each other's records (POSIX guarantees atomic writes for
 * append on local filesystems up to PIPE_BUF; we keep entries small).
 *
 * Every entry is signed by the daemon's HMAC secret so post-incident
 * verification can rule out tampered logs. The signature is calculated
 * over the canonical JSON of the payload portion only — the envelope
 * around it (timestamp, pid, etc.) is part of the signed payload, not
 * around it.
 *
 * File location: /var/log/flowone/storage-journal.jsonl
 * Rotation is handled by the system's logrotate; we never rewrite.
 *
 * NOTE: this class deliberately has no read API. Operators read the
 * journal with `storage-ctl journal` or plain `tail`/`jq`. The class
 * exists to enforce the structured-write contract.
 */
final class OperationJournal
{
    public function __construct(
        private string $path,
        private HmacSigner $signer,
        private int $bootEpoch,
    ) {}

    /**
     * Build the canonical journal for this daemon, given the loaded config.
     */
    public static function fromConfig(HmacSigner $signer, int $bootEpoch): self
    {
        $config = Config::load();
        return new self(
            (string) $config['journal']['path'],
            $signer,
            $bootEpoch,
        );
    }

    /**
     * Append a structured event.
     *
     * @param string $event           Stable identifier (e.g. "nas_health_change", "mount_attempt").
     * @param array<string,mixed> $context  Event-specific fields.
     */
    public function record(string $event, array $context = []): void
    {
        $payload = [
            'ts'         => date('c'),                  // wall time, ISO-8601
            'ts_unix'    => time(),
            'host'       => gethostname() ?: 'unknown',
            'pid'        => getmypid() ?: 0,
            'boot_epoch' => $this->bootEpoch,
            'event'      => $event,
            'context'    => $context,
        ];

        try {
            $line = $this->signer->signToJson($payload);
        } catch (\Throwable $e) {
            error_log("[OperationJournal] sign failed: " . $e->getMessage());
            return;
        }

        $line .= "\n";

        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                error_log("[OperationJournal] cannot create dir {$dir}");
                return;
            }
        }

        $fh = @fopen($this->path, 'ab');
        if ($fh === false) {
            error_log("[OperationJournal] cannot open {$this->path}");
            return;
        }
        try {
            // LOCK_EX is belt-and-braces; O_APPEND already makes the write
            // atomic for short lines on POSIX local fs.
            @flock($fh, LOCK_EX);
            @fwrite($fh, $line);
            @fflush($fh);
            @flock($fh, LOCK_UN);
        } finally {
            @fclose($fh);
        }
    }
}
