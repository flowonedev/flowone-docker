<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator\Queue;

/**
 * Immutable in-memory representation of one `site_jobs` row.
 *
 * The DTO is the boundary type that flows between JobDispatcher,
 * JobWorker, controllers, and the UI. Anything that reads or writes
 * site_jobs goes through this class so the column shape is consistent
 * and we have one place to evolve schema_version, payload typing, and
 * lease semantics.
 *
 * Design notes:
 *
 *   - The DTO carries the row as it was AT FETCH TIME. Fields like
 *     `attempts` and `status` can move forward in the DB between the
 *     instant we built this DTO and the instant we read it; callers
 *     who need fresh state must refetch via JobDispatcher::getById().
 *
 *   - `payload` and `result` are decoded arrays (not raw JSON strings).
 *     The dispatcher / worker handle (de)serialization at the SQL
 *     boundary. Treating them as arrays everywhere else avoids
 *     scattered json_decode() calls.
 *
 *   - `error` is a plaintext column (TEXT) capped at 64K by MySQL. The
 *     worker truncates long error blobs before insert. The full stack
 *     trace lives in site_job_events, not here.
 *
 *   - `requestId` correlates across the API -> dispatcher -> worker ->
 *     step -> log chain. Always propagate it; never invent a fresh one.
 *
 *   - Datetime fields are nullable ISO 8601 strings (the raw MySQL
 *     DATETIME repr is microsecond-truncated; we keep a string so
 *     timezone surprises don't bite us at the boundary). Use
 *     `enqueuedAtDt()` etc. when you need a DateTimeImmutable.
 */
final class SiteJob
{
    /**
     * @param array<string,mixed> $payload  Decoded job input.
     * @param array<string,mixed>|null $result Decoded saga summary when terminal.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $siteDomain,
        public readonly JobType $type,
        public readonly JobStatus $status,
        public readonly int $priority,
        public readonly JobPriorityClass $priorityClass,
        public readonly ?int $agedPriority,
        public readonly array $payload,
        public readonly int $schemaVersion,
        public readonly ?string $currentStep,
        public readonly ?array $stepState,
        public readonly ?string $checkpointHash,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly ?string $lockedBy,
        public readonly ?string $leaseUntil,
        public readonly bool $dryRun,
        public readonly ?string $requestId,
        public readonly ?int $parentJobId,
        public readonly ?array $result,
        public readonly ?string $error,
        public readonly string $actor,
        public readonly ?int $actorUserId,
        public readonly ?string $sourceIp,
        public readonly string $enqueuedAt,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt
    ) {
    }

    /**
     * Hydrate from a raw associative array as returned by PDO's
     * FETCH_ASSOC. Decodes JSON columns. Throws on malformed enum
     * values - silently downgrading would mask DB corruption.
     *
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $payload = self::decodeJsonObject($row['payload'] ?? null, 'payload');
        $stepState = self::decodeJsonObjectNullable($row['step_state'] ?? null, 'step_state');
        $result = self::decodeJsonObjectNullable($row['result'] ?? null, 'result');

        return new self(
            id: (int) ($row['id'] ?? 0),
            siteDomain: (string) ($row['site_domain'] ?? ''),
            type: JobType::from((string) $row['type']),
            status: JobStatus::from((string) $row['status']),
            priority: (int) ($row['priority'] ?? 50),
            priorityClass: JobPriorityClass::from((string) $row['priority_class']),
            agedPriority: isset($row['aged_priority']) ? (int) $row['aged_priority'] : null,
            payload: $payload,
            schemaVersion: (int) ($row['schema_version'] ?? 1),
            currentStep: self::nullableString($row['current_step'] ?? null),
            stepState: $stepState,
            checkpointHash: self::nullableString($row['checkpoint_hash'] ?? null),
            attempts: (int) ($row['attempts'] ?? 0),
            maxAttempts: (int) ($row['max_attempts'] ?? 3),
            lockedBy: self::nullableString($row['locked_by'] ?? null),
            leaseUntil: self::nullableString($row['lease_until'] ?? null),
            dryRun: !empty($row['dry_run']),
            requestId: self::nullableString($row['request_id'] ?? null),
            parentJobId: isset($row['parent_job_id']) && $row['parent_job_id'] !== null
                ? (int) $row['parent_job_id']
                : null,
            result: $result,
            error: self::nullableString($row['error'] ?? null),
            actor: (string) ($row['actor'] ?? 'unknown'),
            actorUserId: isset($row['actor_user_id']) && $row['actor_user_id'] !== null
                ? (int) $row['actor_user_id']
                : null,
            sourceIp: self::nullableString($row['source_ip'] ?? null),
            enqueuedAt: (string) ($row['enqueued_at'] ?? ''),
            startedAt: self::nullableString($row['started_at'] ?? null),
            finishedAt: self::nullableString($row['finished_at'] ?? null),
        );
    }

    /**
     * Whether this job is terminal (no further worker activity).
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Whether this job has burnt through every retry attempt.
     */
    public function attemptsExhausted(): bool
    {
        return $this->attempts >= $this->maxAttempts;
    }

    /**
     * Lossy summary for log lines and human display.
     *
     * @return array<string,mixed>
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'site_domain' => $this->siteDomain,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'max_attempts' => $this->maxAttempts,
            'priority' => $this->priority,
            'priority_class' => $this->priorityClass->value,
            'request_id' => $this->requestId,
            'enqueued_at' => $this->enqueuedAt,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = (string) $value;
        return $s === '' ? null : $s;
    }

    /**
     * @return array<string,mixed>
     */
    private static function decodeJsonObject(mixed $value, string $columnName): array
    {
        if ($value === null || $value === '') {
            // payload is NOT NULL in the schema; an empty object is
            // still legal but a literal NULL means the row is broken.
            throw new \RuntimeException("site_jobs.{$columnName} is empty / NULL");
        }
        $decoded = json_decode((string) $value, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("site_jobs.{$columnName} is not a JSON object");
        }
        return $decoded;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function decodeJsonObjectNullable(mixed $value, string $columnName): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode((string) $value, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("site_jobs.{$columnName} is not a JSON object");
        }
        return $decoded;
    }
}
