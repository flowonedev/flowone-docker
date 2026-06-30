<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Services;

use VpsAdmin\Agent\Provisioner\Exceptions\LockAcquisitionFailed;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

/**
 * Per-domain distributed mutex via the `site_locks` table.
 *
 * Why a DB table and not Redis (yet):
 *   - The panel already requires MariaDB, so this adds zero new infra.
 *   - The lock is inspectable (operator can `SELECT * FROM site_locks`
 *     to see who holds what and for how long).
 *   - Locks survive DB connection drops because rows are durable; only
 *     `lease_until` decides liveness, not a TCP connection.
 *   - Redis-based locks (Redlock, SET NX PX) are a drop-in upgrade behind
 *     the same `SiteLock` interface when scale demands it. Not now.
 *
 * Usage:
 *   $handle = $lock->acquire('example.com', 'worker-1', 'create job 42');
 *   try {
 *       // do work, periodically call $handle->heartbeat()
 *   } finally {
 *       $handle->release();
 *   }
 *
 * The API path uses `tryAcquire()` (non-blocking) so an in-flight job
 * causes an instant 409 Conflict rather than a hanging request.
 */
final class SiteLock
{
    public const DEFAULT_TTL_SECONDS = 60;

    public function __construct(
        private readonly PanelDatabase $database
    ) {
    }

    /**
     * Acquire or fail.
     *
     * @throws LockAcquisitionFailed if another live holder owns the domain.
     */
    public function acquire(
        string $domain,
        string $holderId,
        string $purpose = '',
        ?string $requestId = null,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ): SiteLockHandle {
        $handle = $this->tryAcquire($domain, $holderId, $purpose, $requestId, $ttlSeconds);
        if ($handle === null) {
            $current = $this->inspect($domain);
            throw new LockAcquisitionFailed(
                domain: $domain,
                heldBy: $current['holder_id'] ?? null,
                heldUntil: isset($current['lease_until'])
                    ? new \DateTimeImmutable($current['lease_until'])
                    : null,
            );
        }
        return $handle;
    }

    /**
     * Non-blocking attempt. Returns null when held by someone else with a
     * still-valid lease. The transactional check ensures no two callers
     * can both win the same race.
     *
     * All time arithmetic happens in MariaDB (NOW(), INTERVAL). We never
     * compute lease_until in PHP and we never compare DateTimes parsed
     * from MariaDB strings against PHP's local clock, because the two
     * processes may run in different timezones (MariaDB SYSTEM vs PHP
     * date.timezone). Trust the DB clock end-to-end.
     */
    public function tryAcquire(
        string $domain,
        string $holderId,
        string $purpose = '',
        ?string $requestId = null,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ): ?SiteLockHandle {
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            // The is_expired flag is computed by MariaDB so PHP's tz never
            // enters the comparison. is_ours is a pure string equality, tz-safe.
            $select = $pdo->prepare(
                'SELECT holder_id,
                        lease_until,
                        (lease_until < NOW()) AS is_expired
                   FROM site_locks
                  WHERE domain = :domain
                  FOR UPDATE'
            );
            $select->execute(['domain' => $domain]);
            $row = $select->fetch();

            if ($row !== false) {
                $isOurs = $row['holder_id'] === $holderId;
                $isExpired = (int) $row['is_expired'] === 1;

                if (!$isOurs && !$isExpired) {
                    $pdo->rollBack();
                    return null;
                }

                $update = $pdo->prepare(
                    'UPDATE site_locks
                        SET holder_id = :holder,
                            purpose = :purpose,
                            acquired_at = NOW(),
                            lease_until = NOW() + INTERVAL :ttl SECOND,
                            request_id = :request_id
                      WHERE domain = :domain'
                );
                $update->execute([
                    'holder' => $holderId,
                    'purpose' => $purpose,
                    'ttl' => $ttlSeconds,
                    'request_id' => $requestId,
                    'domain' => $domain,
                ]);
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO site_locks
                        (domain, holder_id, purpose,
                         acquired_at, lease_until, request_id)
                     VALUES
                        (:domain, :holder, :purpose,
                         NOW(), NOW() + INTERVAL :ttl SECOND, :request_id)'
                );
                $insert->execute([
                    'domain' => $domain,
                    'holder' => $holderId,
                    'purpose' => $purpose,
                    'ttl' => $ttlSeconds,
                    'request_id' => $requestId,
                ]);
            }

            // Read back the canonical lease_until string from the DB. PHP
            // only uses this for display in exception messages and handle
            // metadata - it never participates in expiry comparisons.
            $readBack = $pdo->prepare(
                'SELECT lease_until FROM site_locks WHERE domain = :domain'
            );
            $readBack->execute(['domain' => $domain]);
            $leaseUntilStr = (string) $readBack->fetchColumn();

            $pdo->commit();

            return new SiteLockHandle(
                lock: $this,
                domain: $domain,
                holderId: $holderId,
                leaseUntil: new \DateTimeImmutable($leaseUntilStr),
                ttlSeconds: $ttlSeconds,
            );
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Extend an existing lock we own. Returns the new lease_until as
     * computed by the DB clock.
     *
     * Throws LockAcquisitionFailed if we no longer own the lock (e.g. our
     * lease expired and another worker reclaimed it).
     */
    public function heartbeat(string $domain, string $holderId, int $ttlSeconds): \DateTimeImmutable
    {
        $pdo = $this->database->pdo();

        $stmt = $pdo->prepare(
            'UPDATE site_locks
                SET lease_until = NOW() + INTERVAL :ttl SECOND
              WHERE domain = :domain
                AND holder_id = :holder'
        );
        $stmt->execute([
            'ttl' => $ttlSeconds,
            'domain' => $domain,
            'holder' => $holderId,
        ]);

        if ($stmt->rowCount() === 0) {
            $current = $this->inspect($domain);
            throw new LockAcquisitionFailed(
                domain: $domain,
                heldBy: $current['holder_id'] ?? null,
                heldUntil: isset($current['lease_until'])
                    ? new \DateTimeImmutable($current['lease_until'])
                    : null,
            );
        }

        $readBack = $pdo->prepare(
            'SELECT lease_until FROM site_locks WHERE domain = :domain'
        );
        $readBack->execute(['domain' => $domain]);
        return new \DateTimeImmutable((string) $readBack->fetchColumn());
    }

    /**
     * Release our lock. No-ops if we don't own it (someone else's lock
     * stays untouched).
     */
    public function release(string $domain, string $holderId): void
    {
        $pdo = $this->database->pdo();
        $stmt = $pdo->prepare(
            'DELETE FROM site_locks WHERE domain = :domain AND holder_id = :holder'
        );
        $stmt->execute([
            'domain' => $domain,
            'holder' => $holderId,
        ]);
    }

    /**
     * Inspect who holds a lock right now (or null if unlocked / expired).
     * Used by the API to render meaningful 409 errors.
     *
     * Expiry is filtered in SQL via `lease_until > NOW()` so callers see
     * the same view as tryAcquire() regardless of PHP timezone.
     */
    public function inspect(string $domain): ?array
    {
        $pdo = $this->database->pdo();
        $stmt = $pdo->prepare(
            'SELECT domain, holder_id, purpose, acquired_at, lease_until, request_id
               FROM site_locks
              WHERE domain = :domain
                AND lease_until > NOW()'
        );
        $stmt->execute(['domain' => $domain]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Sweep all expired locks. Safe to call from a cron / reconciler.
     * Returns count of rows removed.
     */
    public function sweepExpired(): int
    {
        $pdo = $this->database->pdo();
        $stmt = $pdo->prepare('DELETE FROM site_locks WHERE lease_until < NOW()');
        $stmt->execute();
        return $stmt->rowCount();
    }

}
