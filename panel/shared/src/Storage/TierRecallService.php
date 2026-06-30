<?php

declare(strict_types=1);

namespace FlowOne\Storage;

use FlowOne\Storage\Breakers\RecoveryBreaker;

/**
 * Phase 5b synchronous tier recall.
 *
 * Orchestrates the cold -> recalling -> hot transition when a
 * consumer (DriveService) tries to read a file whose bytes have
 * been tiered to NAS. Brings the bytes back to VPS, verifies the
 * checksum, transitions state, and returns the freshly-warm VPS
 * path so the caller can serve the read normally.
 *
 * Concurrency model:
 *   - Per-file MountLock (file-scoped flock) keyed by file_id so
 *     two simultaneous reads of the same cold file recall ONCE,
 *     not twice. The second request waits for the lock and finds
 *     the row already hot when it acquires.
 *
 * Safety:
 *   - Preflight: StorageHealth must be readable (HEALTHY/DEGRADED/
 *     READ_ONLY/FROZEN). OFFLINE/QUARANTINED -> throw immediately.
 *   - RecoveryBreaker budget: refuses to attempt when the breaker
 *     is quarantined or permanent, so a flaky NAS doesn't cause a
 *     recall storm.
 *   - Hard refusal of nas_relative_path values that don't live
 *     under the expected tenant subpath (legacy pre-Phase-3 paths).
 *   - Atomic state transitions: cold->recalling BEFORE bytes move,
 *     recalling->hot AFTER mover verifies bytes. On any failure,
 *     rollback recalling->cold so the row is recoverable.
 *
 * NOT included in Phase 5b:
 *   - Background prefetch (consumers always pay the recall cost
 *     synchronously on first cold read)
 *   - Multi-version recall (we only recall the current version)
 *   - Legacy /mnt/nas-drive/{hash} reads — those still go through
 *     DriveService's existing resolveNasFilePath() fallback.
 */
final class TierRecallService
{
    public const DEFAULT_LOCK_WAIT_SEC = 30;

    public function __construct(
        private \PDO $pdo,
        private TierStateService $tierService,
        private TierBytesMover $mover,
        private HealthStatusProvider $health,
        private RecoveryBreaker $breaker,
        private TenantResolver $resolver,
        private string $tenant,
        private string $vpsBasePath,
        private string $lockDir,
        private ?OperationJournal $journal = null,
        private int $lockWaitSec = self::DEFAULT_LOCK_WAIT_SEC,
    ) {}

    /**
     * Compose a TierRecallService from the shared storage config.
     * Caller supplies the PDO that's bound to the drive_files DB.
     */
    public static function build(
        \PDO $pdo,
        string $tenant = 'email-drive',
        string $vpsBasePath = '/var/www/vps-email/storage/drive',
        ?array $storageConfig = null,
        ?OperationJournal $journal = null
    ): self {
        $config = $storageConfig ?? Config::load();

        if ($journal === null) {
            $signer = HmacSigner::fromKeyFile(
                (string) $config['state']['hmac_key_path'],
                (int) $config['state']['hmac_key_mode_max']
            );
            $journal = new OperationJournal(
                (string) $config['journal']['path'],
                $signer,
                0
            );
        }

        $resolver = TenantResolver::fromConfig($config);
        $invariants = new Invariants($journal, strict: false);
        $mover = new TierBytesMover($resolver, $invariants, $journal);
        $tierService = new TierStateService($pdo, 'drive_files', 'drive_tier_transitions', $journal);
        $health = StorageHealth::fromConfig(null);
        $breaker = RecoveryBreaker::fromConfig($config);
        $lockDir = rtrim((string) $config['state']['dir'], '/');

        return new self(
            pdo: $pdo,
            tierService: $tierService,
            mover: $mover,
            health: $health,
            breaker: $breaker,
            resolver: $resolver,
            tenant: $tenant,
            vpsBasePath: rtrim($vpsBasePath, '/'),
            lockDir: $lockDir,
            journal: $journal,
        );
    }

    /**
     * Synchronously recall a cold file to VPS. Returns the absolute
     * VPS path of the now-hot file. Throws on any failure (caller
     * should present a "try again shortly" error to the user).
     *
     * Idempotent: if the file is already hot/tiering/recalling when
     * we acquire the lock, returns the VPS path without doing work.
     */
    public function recallCold(int $fileId): string
    {
        $startMs = (int) (microtime(true) * 1000);

        // 1. Preflight: NAS readable?
        $health = $this->health->getStatus();
        if (!HealthState::isReadable($health->status)) {
            $this->journal?->record('recall_aborted_nas_unreadable', [
                'file_id' => $fileId,
                'status'  => $health->status,
            ]);
            throw new \RuntimeException(
                "NAS not readable ({$health->status}); recall refused for file_id {$fileId}"
            );
        }

        // 2. Per-file mutex. Same lock name as the tier-down worker
        //    + destructive sweeper, so the three operations
        //    (tier-down, recall, sweep) all serialise on a single
        //    file_id-scoped flock. Two concurrent recalls of the same
        //    cold file collapse to one I/O, and a sweep can never
        //    delete the VPS copy while a recall is mid-flight.
        $lock = new MountLock(
            $this->lockDir . '/tier-' . $fileId . '.lock',
            $this->lockWaitSec
        );
        try {
            $lock->acquire(); // throws on timeout
        } catch (\Throwable $e) {
            $this->journal?->record('recall_lock_timeout', [
                'file_id' => $fileId,
                'wait_sec' => $this->lockWaitSec,
            ]);
            throw new \RuntimeException(
                "could not acquire recall lock for file_id {$fileId}: {$e->getMessage()}"
            );
        }

        try {
            // 3. Re-read state under the lock. Another worker may
            //    have completed the recall while we were waiting.
            $row = $this->fetchRow($fileId);
            if ($row === null) {
                throw new \RuntimeException("file_id {$fileId} not found");
            }
            $state = (string) $row['tier_state'];

            if (TierState::bytesOnVps($state)) {
                // hot/tiering/recalling — bytes already on VPS (or about to be)
                // Return the VPS path; caller's existing read logic takes over.
                $this->journal?->record('recall_no_op_already_warm', [
                    'file_id' => $fileId,
                    'state'   => $state,
                ]);
                return $this->vpsPathFor($row);
            }
            if ($state === TierState::LOST) {
                throw new \RuntimeException("file_id {$fileId} is marked lost; cannot recall");
            }
            if ($state !== TierState::COLD) {
                // Anything else (including UNKNOWN somehow) — refuse.
                throw new \RuntimeException("cannot recall from state '{$state}' (file_id {$fileId})");
            }

            // 4. Breaker budget: refuse if the breaker is quarantined
            //    or permanently open. Prevents recall storms when NAS
            //    is misbehaving.
            if (!$this->breaker->canAttempt()) {
                $snap = $this->breaker->snapshot();
                $this->journal?->record('recall_breaker_open', [
                    'file_id' => $fileId,
                    'breaker' => $snap,
                ]);
                throw new \RuntimeException(
                    "recovery breaker open (permanent=" . ($snap['permanent'] ? 'yes' : 'no') .
                    "); recall deferred for file_id {$fileId}"
                );
            }

            // 5. Resolve NAS source from nas_relative_path. The
            //    Phase 5a worker stores paths as "drive/{hash}/{file}";
            //    older pre-Phase-3 rows have flat paths like
            //    "{hash}/{file}" — those are out of scope for Phase 5b.
            $nasRel = trim((string) ($row['nas_relative_path'] ?? ''));
            if ($nasRel === '') {
                throw new \RuntimeException("file_id {$fileId} has empty nas_relative_path; cannot recall");
            }
            $tenantSubpath = (string) ($this->resolver->definition($this->tenant)['subpath'] ?? $this->tenant);
            $prefix = $tenantSubpath . '/';
            if (!str_starts_with($nasRel, $prefix)) {
                throw new \RuntimeException(
                    "file_id {$fileId} nas_relative_path '{$nasRel}' not under tenant subpath '{$tenantSubpath}'; " .
                    "legacy file recall requires manual operator action"
                );
            }
            $relUnderTenant = substr($nasRel, strlen($prefix));

            $checksum = (string) ($row['checksum'] ?? '');
            if ($checksum === '') {
                throw new \RuntimeException("file_id {$fileId} has empty checksum; cannot verify recall");
            }

            $vpsPath = $this->vpsPathFor($row);

            // 6. Begin recall: cold -> recalling.
            $this->tierService->transitionTo(
                $fileId, TierState::RECALLING, 'tier-recall', 'sync recall on read'
            );
            $this->breaker->recordAttempt();

            // 7. Move bytes NAS -> VPS with checksum verify.
            $out = $this->mover->recall($this->tenant, $relUnderTenant, $vpsPath, $checksum);

            if (!$out['ok']) {
                // Rollback: recalling -> cold so a retry can pick it up.
                try {
                    $this->tierService->transitionTo(
                        $fileId, TierState::COLD, 'tier-recall',
                        'rollback: ' . ($out['error'] ?? 'unknown')
                    );
                } catch (\Throwable $rb) {
                    $this->journal?->record('recall_rollback_failed', [
                        'file_id' => $fileId, 'error' => $rb->getMessage(),
                    ]);
                }
                $this->breaker->recordFailure();
                throw new \RuntimeException(
                    "recall failed for file_id {$fileId}: " . ($out['error'] ?? 'unknown')
                );
            }

            // 8. recalling -> hot commits the recall.
            $this->tierService->transitionTo(
                $fileId, TierState::HOT, 'tier-recall', 'recall committed',
                null, $out['bytes'], $out['duration_ms']
            );
            $this->breaker->recordSuccess();

            $elapsedMs = (int) (microtime(true) * 1000) - $startMs;
            $this->journal?->record('recall_ok', [
                'file_id'    => $fileId,
                'bytes'      => $out['bytes'],
                'mover_ms'   => $out['duration_ms'],
                'total_ms'   => $elapsedMs,
            ]);

            return $vpsPath;
        } finally {
            $lock->release();
        }
    }

    /**
     * Lookup an existing row's tier_state without doing any work.
     * Used by DriveService to decide whether to call recallCold() at
     * all (fast path: hot/tiering rows skip recall entirely).
     */
    public function currentState(int $fileId): ?string
    {
        return $this->tierService->getState($fileId);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchRow(int $fileId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, user_email, filename, size, checksum,
                    tier_state, storage_location, nas_relative_path
             FROM drive_files WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $fileId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function vpsPathFor(array $row): string
    {
        $userHash = md5(strtolower((string) $row['user_email']));
        return $this->vpsBasePath . '/' . $userHash . '/' . (string) $row['filename'];
    }
}
