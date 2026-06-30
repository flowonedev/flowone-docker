<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Invariant I-11: any mount-table mutation (mount, umount, umount -l,
 * remount) MUST acquire this lock first. This includes operations
 * performed by the daemon, the helper, backup-runner, AND any manual
 * operator commands.
 *
 * Implementation: exclusive flock on a sentinel file at
 * /var/lock/flowone-mount.lock. flock is process-bound and released on
 * close (including abnormal termination), which is exactly what we want —
 * a crashed mount helper does not leave the lock held forever.
 *
 * Usage:
 *
 *   $lock = MountLock::fromConfig();
 *   $lock->withExclusive(function () {
 *       // mount / umount here
 *   });
 *
 * If acquisition fails within the configured timeout, throws.
 */
final class MountLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(
        private string $path,
        private int $waitTimeoutSec = 60,
    ) {}

    public static function fromConfig(): self
    {
        $config = Config::load();
        return new self(
            $config['mount_lock']['path'],
            (int) $config['mount_lock']['wait_timeout_sec'],
        );
    }

    /**
     * Acquire exclusive lock, run $fn, release lock. Always releases even
     * if $fn throws. Returns whatever $fn returns.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function withExclusive(callable $fn): mixed
    {
        $this->acquire();
        try {
            return $fn();
        } finally {
            $this->release();
        }
    }

    /**
     * Try to acquire without blocking. Returns true on success.
     * If returns false, the caller must NOT proceed with a mount op.
     */
    public function tryAcquire(): bool
    {
        return $this->acquireInternal(blocking: false);
    }

    public function acquire(): void
    {
        if (!$this->acquireInternal(blocking: true)) {
            throw new \RuntimeException(sprintf(
                'MountLock: could not acquire %s within %ds',
                $this->path,
                $this->waitTimeoutSec
            ));
        }
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        @flock($this->handle, LOCK_UN);
        @fclose($this->handle);
        $this->handle = null;
    }

    /**
     * True iff this process currently holds the lock.
     */
    public function isHeld(): bool
    {
        return $this->handle !== null;
    }

    private function acquireInternal(bool $blocking): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("MountLock: cannot create dir {$dir}");
            }
        }

        $handle = @fopen($this->path, 'cb+');
        if ($handle === false) {
            throw new \RuntimeException("MountLock: cannot open {$this->path}");
        }

        $flags = LOCK_EX | ($blocking ? 0 : LOCK_NB);

        if (!$blocking) {
            if (!@flock($handle, $flags)) {
                @fclose($handle);
                return false;
            }
            $this->handle = $handle;
            return true;
        }

        // Blocking with timeout: loop with LOCK_NB so we can give up.
        $deadlineNs = MonotonicClock::nowNs() + ($this->waitTimeoutSec * 1_000_000_000);
        while (true) {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                $this->handle = $handle;
                return true;
            }
            if (MonotonicClock::nowNs() >= $deadlineNs) {
                @fclose($handle);
                return false;
            }
            usleep(100_000); // 100ms
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
