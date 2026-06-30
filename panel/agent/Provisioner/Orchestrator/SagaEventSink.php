<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator;

use VpsAdmin\Agent\Provisioner\Step\StepEvent;

/**
 * Where the orchestrator streams events as they happen.
 *
 * Why a sink and not just collecting in memory + returning from
 * SagaResult:
 *   - The UI wants real-time progress (SSE / WebSocket / polling). A
 *     "fire and forget at the end" model means the user stares at a
 *     spinner for 30 seconds while sftp + db + ols restart all run.
 *   - The crashed-worker recovery path (Step 5c) reads events from the
 *     persistent sink to reconstruct the saga's state.
 *   - Audit needs to know the EXACT order in which events fired across
 *     all steps, not just the per-step snapshots.
 *
 * Implementations:
 *   - InMemorySagaEventSink: collect into a list; SagaResult uses it.
 *   - DbSagaEventSink (Step 5c): inserts each event row into
 *     site_audit_log with the saga's request_id.
 *   - TeeSagaEventSink (future): fans out to multiple sinks.
 *
 * The sink is responsible for ITS OWN persistence durability. The
 * orchestrator hands it events; what it does with them is its problem.
 */
interface SagaEventSink
{
    /**
     * Record one event. The stepName arg names the step that emitted
     * the event so a downstream UI can filter/index by step.
     */
    public function emit(string $stepName, StepEvent $event): void;

    /**
     * Convenience for the orchestrator's own narrative events (saga
     * start / saga end / direction switch). Maps to a synthetic step
     * name "__saga__" so a UI can render them as orchestrator banners
     * rather than per-step events.
     */
    public function emitSaga(StepEvent $event): void;

    /**
     * Return every event collected, in the order it was emitted. The
     * DB-backed impl returns events for the current saga scope only.
     *
     * @return list<array{step_name: string, event: StepEvent}>
     */
    public function drain(): array;
}
