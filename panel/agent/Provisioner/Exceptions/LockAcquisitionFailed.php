<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Exceptions;

/**
 * Thrown by SiteLock when an exclusive lock cannot be acquired for a
 * domain within the timeout. The caller should:
 *   - For an API call: return HTTP 409 Conflict so the operator retries.
 *   - For a worker: requeue the job with a small backoff.
 * Never spin-loop on this exception - it means another process owns
 * the domain right now.
 */
class LockAcquisitionFailed extends \RuntimeException
{
    public function __construct(
        public readonly string $domain,
        public readonly ?string $heldBy = null,
        public readonly ?\DateTimeImmutable $heldUntil = null
    ) {
        $msg = "Could not acquire site lock for {$domain}";
        if ($heldBy !== null) {
            $msg .= " (held by {$heldBy}";
            if ($heldUntil !== null) {
                $msg .= " until " . $heldUntil->format('Y-m-d H:i:s');
            }
            $msg .= ")";
        }
        parent::__construct($msg);
    }
}
