<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Services;

/**
 * RAII-style handle returned by SiteLock::acquire().
 *
 * Callers should:
 *   $handle = $lock->acquire(...);
 *   try {
 *       // work
 *       $handle->heartbeat();
 *   } finally {
 *       $handle->release();
 *   }
 *
 * The destructor releases as a defensive measure if someone forgets the
 * finally block, BUT relying on the destructor is bad practice because
 * PHP's GC is not deterministic across long-running workers.
 */
final class SiteLockHandle
{
    private bool $released = false;

    public function __construct(
        private readonly SiteLock $lock,
        private readonly string $domain,
        private readonly string $holderId,
        private \DateTimeImmutable $leaseUntil,
        private readonly int $ttlSeconds
    ) {
    }

    public function heartbeat(?int $ttlSeconds = null): void
    {
        if ($this->released) {
            throw new \LogicException("Cannot heartbeat released lock for {$this->domain}");
        }
        $this->leaseUntil = $this->lock->heartbeat(
            $this->domain,
            $this->holderId,
            $ttlSeconds ?? $this->ttlSeconds
        );
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->lock->release($this->domain, $this->holderId);
        $this->released = true;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function holderId(): string
    {
        return $this->holderId;
    }

    public function leaseUntil(): \DateTimeImmutable
    {
        return $this->leaseUntil;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function __destruct()
    {
        if (!$this->released) {
            try {
                $this->release();
            } catch (\Throwable) {
                // Best effort - destructor must never throw.
            }
        }
    }
}
