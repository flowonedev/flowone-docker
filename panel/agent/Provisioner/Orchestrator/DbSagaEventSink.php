<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator;

use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

/**
 * DB-backed SagaEventSink writing to `site_job_events`.
 *
 * Each emit() / emitSaga() call inserts one row. The schema's
 * `idx_job (job_id, id)` index gives the UI an efficient
 * "events since last seen" cursor for SSE: tail with WHERE
 * job_id=? AND id > :last_id ORDER BY id ASC.
 *
 * step_name is NULL for orchestrator-level (saga) events and the
 * step's name() for step-emitted events. The UI distinguishes them
 * on render: saga events become banners, step events become rows in
 * the step's section.
 *
 * Metadata is JSON-encoded after passing through SecretMasker. If a
 * step accidentally embedded a secret in event metadata (the contract
 * forbids it but defensive masking matters), the on-disk value
 * becomes `[REDACTED]`.
 *
 * drain() returns the row list in chronological order, matching the
 * InMemorySagaEventSink contract so callers can swap sinks freely
 * (e.g. tee'ing for tests).
 */
final class DbSagaEventSink implements SagaEventSink
{
    public function __construct(
        private readonly PanelDatabase $database,
        private readonly SecretMasker $masker,
        private readonly int $jobId,
        private readonly string $siteDomain,
        private readonly string $requestId
    ) {
        if ($this->jobId <= 0) {
            throw new \InvalidArgumentException('DbSagaEventSink requires a positive jobId');
        }
        if ($this->siteDomain === '') {
            throw new \InvalidArgumentException('DbSagaEventSink requires a non-empty siteDomain');
        }
    }

    public function emit(string $stepName, StepEvent $event): void
    {
        $this->insertRow(
            stepName: $stepName === '' || $stepName === InMemorySagaEventSink::SAGA_STEP_NAME
                ? null
                : $stepName,
            event: $event,
        );
    }

    public function emitSaga(StepEvent $event): void
    {
        $this->insertRow(stepName: null, event: $event);
    }

    public function drain(): array
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT step_name, level, message, metadata, occurred_at
               FROM site_job_events
              WHERE job_id = :job_id
              ORDER BY id ASC'
        );
        $stmt->execute(['job_id' => $this->jobId]);

        $out = [];
        while ($row = $stmt->fetch()) {
            $metadata = [];
            if (!empty($row['metadata'])) {
                $decoded = json_decode((string) $row['metadata'], true);
                if (is_array($decoded)) {
                    $metadata = $decoded;
                }
            }
            try {
                $occurredAt = new \DateTimeImmutable((string) $row['occurred_at']);
            } catch (\Throwable) {
                $occurredAt = null;
            }
            $event = new StepEvent(
                level: (string) $row['level'],
                message: (string) $row['message'],
                metadata: $metadata,
                occurredAt: $occurredAt,
            );
            $out[] = [
                'step_name' => $row['step_name'] !== null
                    ? (string) $row['step_name']
                    : InMemorySagaEventSink::SAGA_STEP_NAME,
                'event' => $event,
            ];
        }
        return $out;
    }

    /**
     * Drop every recorded event for this job. Not part of the sink
     * interface; provided as a maintenance helper so tests can clean
     * up rows they wrote. The worker never calls this in production.
     */
    public function purge(): void
    {
        $stmt = $this->database->pdo()->prepare(
            'DELETE FROM site_job_events WHERE job_id = :job_id'
        );
        $stmt->execute(['job_id' => $this->jobId]);
    }

    private function insertRow(?string $stepName, StepEvent $event): void
    {
        $occurredAt = ($event->occurredAt ?? new \DateTimeImmutable('now'))
            ->format('Y-m-d H:i:s.v');
        $maskedMeta = $this->masker->maskArray($event->metadata);

        $stmt = $this->database->pdo()->prepare(
            'INSERT INTO site_job_events
                (job_id, site_domain, step_name, level, message,
                 metadata, request_id, occurred_at)
              VALUES
                (:job_id, :site_domain, :step_name, :level, :message,
                 :metadata, :request_id, :occurred_at)'
        );
        $stmt->execute([
            'job_id' => $this->jobId,
            'site_domain' => $this->siteDomain,
            'step_name' => $stepName,
            'level' => $event->level,
            'message' => mb_substr($event->message, 0, 16000),
            'metadata' => $maskedMeta === []
                ? null
                : json_encode($maskedMeta, JSON_UNESCAPED_SLASHES),
            'request_id' => $this->requestId,
            'occurred_at' => $occurredAt,
        ]);
    }
}
