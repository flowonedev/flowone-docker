<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step;

/**
 * Per-step persistent state, serialized into `sites.state` JSON and
 * snapshotted into `site_step_executions.input_snapshot/output_snapshot`.
 *
 * Invariants:
 *   - Immutable. Mutations return a new instance via `with*()`.
 *   - `schemaVersion` increments whenever a step changes the shape of
 *     its `data` map. The StepStateMigrator (Step 5) uses this to
 *     upgrade old persisted state before passing it to a new step.
 *   - `data` is opaque to the orchestrator. Steps interpret it.
 *     Secrets must NEVER appear here in plaintext - use SecretVault
 *     references instead (e.g. `["db_password_ref" => "site:foo/db_pass"]`).
 *   - `attemptCount` is incremented by the worker on each execute()
 *     call. Steps may use it to back off external calls or change
 *     strategy on retry.
 */
final class StepState
{
    public function __construct(
        public readonly string $stepName,
        public readonly int $schemaVersion = 1,
        public readonly array $data = [],
        public readonly ?\DateTimeImmutable $startedAt = null,
        public readonly ?\DateTimeImmutable $completedAt = null,
        public readonly int $attemptCount = 0
    ) {
    }

    public static function fresh(string $stepName, int $schemaVersion = 1): self
    {
        return new self(
            stepName: $stepName,
            schemaVersion: $schemaVersion,
            data: [],
            startedAt: null,
            completedAt: null,
            attemptCount: 0,
        );
    }

    /**
     * Hydrate from a persisted JSON blob.
     *
     * @param array<string, mixed> $row Decoded JSON shape
     */
    public static function fromArray(array $row): self
    {
        return new self(
            stepName: (string) ($row['step_name'] ?? ''),
            schemaVersion: (int) ($row['schema_version'] ?? 1),
            data: (array) ($row['data'] ?? []),
            startedAt: isset($row['started_at']) ? new \DateTimeImmutable((string) $row['started_at']) : null,
            completedAt: isset($row['completed_at']) ? new \DateTimeImmutable((string) $row['completed_at']) : null,
            attemptCount: (int) ($row['attempt_count'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'step_name' => $this->stepName,
            'schema_version' => $this->schemaVersion,
            'data' => $this->data,
            'started_at' => $this->startedAt?->format('Y-m-d H:i:s'),
            'completed_at' => $this->completedAt?->format('Y-m-d H:i:s'),
            'attempt_count' => $this->attemptCount,
        ];
    }

    public function withData(array $newData): self
    {
        return new self(
            $this->stepName,
            $this->schemaVersion,
            $newData,
            $this->startedAt,
            $this->completedAt,
            $this->attemptCount,
        );
    }

    public function mergeData(array $extra): self
    {
        return $this->withData(array_merge($this->data, $extra));
    }

    public function withStarted(?\DateTimeImmutable $when = null): self
    {
        return new self(
            $this->stepName,
            $this->schemaVersion,
            $this->data,
            $when ?? new \DateTimeImmutable('now'),
            $this->completedAt,
            $this->attemptCount,
        );
    }

    public function withCompleted(?\DateTimeImmutable $when = null): self
    {
        return new self(
            $this->stepName,
            $this->schemaVersion,
            $this->data,
            $this->startedAt,
            $when ?? new \DateTimeImmutable('now'),
            $this->attemptCount,
        );
    }

    public function withAttemptIncremented(): self
    {
        return new self(
            $this->stepName,
            $this->schemaVersion,
            $this->data,
            $this->startedAt,
            $this->completedAt,
            $this->attemptCount + 1,
        );
    }

    public function isComplete(): bool
    {
        return $this->completedAt !== null;
    }

    /**
     * SHA-256 of the persisted shape - used by the worker takeover code
     * to verify a job's state matches the checkpoint_hash we stored
     * before the previous worker died. Identical states produce
     * identical hashes regardless of JSON key order.
     */
    public function hash(): string
    {
        $arr = $this->toArray();
        $this->ksortRecursive($arr);
        return hash('sha256', (string) json_encode($arr, JSON_UNESCAPED_SLASHES));
    }

    private function ksortRecursive(array &$arr): void
    {
        ksort($arr);
        foreach ($arr as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }
}
