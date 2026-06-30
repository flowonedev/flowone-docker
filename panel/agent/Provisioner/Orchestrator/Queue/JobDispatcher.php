<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator\Queue;

use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

/**
 * The one place job rows are CREATED in `site_jobs`.
 *
 * HTTP controllers, the reconciler, the worker (when it re-enqueues
 * itself for retry), and CLI scripts all enqueue through this class.
 * Centralizing the insert means every job picks up:
 *   - the payload masking (defence-in-depth in case a caller leaks),
 *   - the audit log entry, so the timeline never has a silent enqueue,
 *   - the request_id propagation,
 *   - default priority / max_attempts values.
 *
 * Idempotency:
 *   - The dispatcher does NOT enforce "one queued job per (domain,
 *     type)" at insert time. That's the worker's job: it claims jobs in
 *     FIFO order under a site lock; conflicting concurrent CREATES on
 *     the same domain are serialized by the lock and the second one
 *     either no-ops (the site is already there) or fails with a clear
 *     "already exists" error.
 *   - Callers who want stricter de-duplication should pre-check via
 *     `listQueuedForDomain()` and skip the enqueue.
 *
 * Errors:
 *   - The dispatcher throws on schema problems (missing columns, JSON
 *     too large) so calling controllers fail-fast. It does NOT catch
 *     audit-logger errors - if we can't audit the enqueue we don't do
 *     it.
 */
final class JobDispatcher
{
    /** Max payload size in bytes after json_encode. MySQL LONGTEXT is
     *  effectively unbounded, but a 1 MB hard cap keeps the queue snappy. */
    public const MAX_PAYLOAD_BYTES = 1024 * 1024;

    public function __construct(
        private readonly PanelDatabase $database,
        private readonly SecretMasker $masker,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * Enqueue a single job. Returns the persisted SiteJob with its
     * assigned id. The row is inserted in status=QUEUED and is
     * immediately eligible for claim by any worker tick.
     *
     * @param array<string,mixed> $payload Caller-provided input. Will be
     *                                     masked before insert.
     */
    public function enqueue(
        string $siteDomain,
        JobType $type,
        array $payload,
        ActorContext $actor,
        ?string $requestId = null,
        ?int $parentJobId = null,
        int $priority = 50,
        JobPriorityClass $priorityClass = JobPriorityClass::OPERATOR,
        int $maxAttempts = 3,
        bool $dryRun = false
    ): SiteJob {
        $this->assertValidDomain($siteDomain);
        $this->assertValidPriority($priority);

        $masked = $this->masker->maskArray($payload);
        $encoded = $this->encodePayload($masked);

        $pdo = $this->database->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO site_jobs
                (site_domain, type, status,
                 priority, priority_class,
                 payload, schema_version,
                 attempts, max_attempts,
                 dry_run,
                 request_id, parent_job_id,
                 actor, actor_user_id, source_ip,
                 enqueued_at)
              VALUES
                (:site_domain, :type, :status,
                 :priority, :priority_class,
                 :payload, :schema_version,
                 0, :max_attempts,
                 :dry_run,
                 :request_id, :parent_job_id,
                 :actor, :actor_user_id, :source_ip,
                 NOW(3))'
        );
        $stmt->execute([
            'site_domain' => $siteDomain,
            'type' => $type->value,
            'status' => JobStatus::QUEUED->value,
            'priority' => $priority,
            'priority_class' => $priorityClass->value,
            'payload' => $encoded,
            'schema_version' => 1,
            'max_attempts' => max(1, $maxAttempts),
            'dry_run' => $dryRun ? 1 : 0,
            'request_id' => $requestId,
            'parent_job_id' => $parentJobId,
            'actor' => mb_substr($actor->username, 0, 128),
            'actor_user_id' => $actor->userId,
            'source_ip' => $actor->sourceIp,
        ]);

        $id = (int) $pdo->lastInsertId();
        if ($id <= 0) {
            throw new \RuntimeException('site_jobs INSERT returned no id');
        }

        $this->audit->record(
            action: 'job_enqueued',
            siteDomain: $siteDomain,
            reason: "enqueue {$type->value} job (priority {$priorityClass->value}/{$priority})",
            before: null,
            after: [
                'job_id' => $id,
                'type' => $type->value,
                'priority_class' => $priorityClass->value,
                'priority' => $priority,
                'dry_run' => $dryRun,
                'parent_job_id' => $parentJobId,
                'request_id' => $requestId,
            ],
            actor: $actor,
            jobId: $id,
        );

        $row = $this->fetchRowById($id);
        if ($row === null) {
            throw new \RuntimeException("site_jobs row {$id} disappeared right after insert");
        }
        return SiteJob::fromRow($row);
    }

    /**
     * Fetch one job by id. Returns null when not found.
     */
    public function getById(int $id): ?SiteJob
    {
        $row = $this->fetchRowById($id);
        return $row === null ? null : SiteJob::fromRow($row);
    }

    /**
     * List jobs for a domain in reverse-chronological order. Used by the
     * UI / API to render the timeline; the worker does NOT use this.
     *
     * @return list<SiteJob>
     */
    public function listForDomain(string $siteDomain, int $limit = 50): array
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT * FROM site_jobs
              WHERE site_domain = :site_domain
              ORDER BY id DESC
              LIMIT :limit'
        );
        $stmt->bindValue('site_domain', $siteDomain);
        $stmt->bindValue('limit', max(1, min(500, $limit)), \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(
            static fn(array $r) => SiteJob::fromRow($r),
            $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * List queued jobs in dequeue order (highest priority first). Used
     * by operator dashboards to see the queue depth and ordering.
     *
     * @return list<SiteJob>
     */
    public function listQueued(int $limit = 100): array
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT * FROM site_jobs
              WHERE status = :status
              ORDER BY priority_class, priority, enqueued_at
              LIMIT :limit'
        );
        $stmt->bindValue('status', JobStatus::QUEUED->value);
        $stmt->bindValue('limit', max(1, min(500, $limit)), \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(
            static fn(array $r) => SiteJob::fromRow($r),
            $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Same as listForDomain but filtered to currently-queued rows. Used
     * to detect "is there already a pending CREATE for this domain?"
     * before enqueueing a duplicate.
     *
     * @return list<SiteJob>
     */
    public function listQueuedForDomain(string $siteDomain): array
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT * FROM site_jobs
              WHERE site_domain = :site_domain AND status = :status
              ORDER BY priority_class, priority, enqueued_at'
        );
        $stmt->execute([
            'site_domain' => $siteDomain,
            'status' => JobStatus::QUEUED->value,
        ]);
        return array_map(
            static fn(array $r) => SiteJob::fromRow($r),
            $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Count rows by status. Cheap to call from a health endpoint.
     *
     * @return array<string,int>
     */
    public function countByStatus(): array
    {
        $stmt = $this->database->pdo()->query(
            'SELECT status, COUNT(*) AS n FROM site_jobs GROUP BY status'
        );
        $out = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $out[(string) $row['status']] = (int) $row['n'];
        }
        foreach (JobStatus::cases() as $case) {
            $out[$case->value] = $out[$case->value] ?? 0;
        }
        return $out;
    }

    /**
     * Operator-initiated cancellation. Marks the job CANCELLED if it is
     * still QUEUED. Refuses to cancel RUNNING jobs - cancellation under
     * a worker lease would race; operators have to wait for the current
     * step to finish (or for the lease to expire) and the worker will
     * observe the cancellation between steps.
     */
    public function cancel(int $id, string $reason, ActorContext $actor): bool
    {
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM site_jobs WHERE id = :id FOR UPDATE'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) {
                $pdo->rollBack();
                return false;
            }
            if ((string) $row['status'] !== JobStatus::QUEUED->value) {
                $pdo->rollBack();
                return false;
            }

            $update = $pdo->prepare(
                'UPDATE site_jobs
                    SET status = :status, finished_at = NOW(3), error = :error
                    WHERE id = :id'
            );
            $update->execute([
                'status' => JobStatus::CANCELLED->value,
                'error' => mb_substr("cancelled by operator: {$reason}", 0, 8000),
                'id' => $id,
            ]);

            $this->audit->record(
                action: 'job_cancelled',
                siteDomain: (string) ($row['site_domain'] ?? ''),
                reason: $reason,
                before: ['status' => $row['status']],
                after: ['status' => JobStatus::CANCELLED->value],
                actor: $actor,
                jobId: $id,
            );

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchRowById(int $id): ?array
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT * FROM site_jobs WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function assertValidDomain(string $domain): void
    {
        if ($domain === '') {
            throw new \InvalidArgumentException('site domain is empty');
        }
        if (strlen($domain) > 253) {
            throw new \InvalidArgumentException('site domain exceeds 253 chars');
        }
    }

    private function assertValidPriority(int $priority): void
    {
        if ($priority < 0 || $priority > 255) {
            throw new \InvalidArgumentException(
                "priority must be 0..255 (TINYINT UNSIGNED), got {$priority}"
            );
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function encodePayload(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException(
                'payload could not be encoded to JSON: ' . json_last_error_msg()
            );
        }
        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw new \RuntimeException(sprintf(
                'payload exceeds %d bytes (%d) - move large blobs to the vault',
                self::MAX_PAYLOAD_BYTES,
                strlen($encoded)
            ));
        }
        return $encoded;
    }
}
