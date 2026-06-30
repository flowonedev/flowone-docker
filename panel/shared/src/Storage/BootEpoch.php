<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Monotonic boot-epoch counter.
 *
 * Bumped exactly once per daemon startup. Every payload the daemon publishes
 * (signed JSON state, Redis cache, journal entries, queued jobs) embeds the
 * current epoch. Consumers reject any action whose embedded epoch differs
 * from the daemon's current epoch (invariant I-10).
 *
 * Why an epoch rather than a uuid: an integer makes "is newer" trivial
 * (it's just >), and trips through Redis/SQL without quoting headaches.
 *
 * Persistence: a single-line file at /var/lib/flowone/storage-boot-epoch.
 * Written atomically (write-then-rename). Daemon increments on startup.
 *
 * Threading: there is exactly one writer (the daemon). Readers cache the
 * loaded value per process and re-read at most once per second.
 */
final class BootEpoch
{
    private const REFRESH_INTERVAL_SEC = 1.0;

    private ?int $cached = null;
    private float $cachedAtMonotonicSec = 0.0;

    public function __construct(private string $path) {}

    /**
     * Read current epoch. Returns 0 if the file is missing (i.e. the
     * daemon has never run on this host). Consumers should treat 0 as
     * "no daemon" and refuse to trust state.
     */
    public function current(): int
    {
        $now = MonotonicClock::nowSec();
        if ($this->cached !== null && ($now - $this->cachedAtMonotonicSec) < self::REFRESH_INTERVAL_SEC) {
            return $this->cached;
        }
        $value = $this->readFromDisk();
        $this->cached = $value;
        $this->cachedAtMonotonicSec = $now;
        return $value;
    }

    /**
     * Daemon-only: bump the epoch on startup and return the new value.
     * Atomic write-then-rename so partial writes can't yield a corrupt
     * epoch file.
     */
    public function bump(): int
    {
        $current = $this->readFromDisk();
        $next = $current + 1;
        $this->writeAtomically($next);
        $this->cached = $next;
        $this->cachedAtMonotonicSec = MonotonicClock::nowSec();
        return $next;
    }

    /**
     * Force a refresh on the next current() call. Tests + chaos use this.
     */
    public function invalidateCache(): void
    {
        $this->cached = null;
    }

    private function readFromDisk(): int
    {
        if (!is_file($this->path)) {
            return 0;
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            return 0;
        }
        $value = (int) trim($raw);
        return $value < 0 ? 0 : $value;
    }

    private function writeAtomically(int $value): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("BootEpoch: cannot create dir {$dir}");
            }
        }
        $tmp = $this->path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        $bytes = (string) $value . "\n";
        $fh = @fopen($tmp, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("BootEpoch: cannot open tmp {$tmp}");
        }
        try {
            $written = @fwrite($fh, $bytes);
            if ($written !== strlen($bytes)) {
                throw new \RuntimeException("BootEpoch: short write {$tmp}");
            }
            if (function_exists('fflush')) {
                @fflush($fh);
            }
            if (function_exists('fsync')) {
                @fsync($fh);
            }
        } finally {
            @fclose($fh);
        }
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException("BootEpoch: cannot rename {$tmp} -> {$this->path}");
        }
    }
}
