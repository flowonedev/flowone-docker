<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator;

use VpsAdmin\Agent\Provisioner\Step\StepEvent;

/**
 * Collects every emitted event into a flat list. The orchestrator then
 * folds it into the SagaResult at the end of the run.
 *
 * The DB-backed impl lands in Step 5c.
 */
final class InMemorySagaEventSink implements SagaEventSink
{
    public const SAGA_STEP_NAME = '__saga__';

    /** @var list<array{step_name: string, event: StepEvent}> */
    private array $buffer = [];

    public function emit(string $stepName, StepEvent $event): void
    {
        $this->buffer[] = [
            'step_name' => $stepName,
            'event' => $event,
        ];
    }

    public function emitSaga(StepEvent $event): void
    {
        $this->emit(self::SAGA_STEP_NAME, $event);
    }

    public function drain(): array
    {
        return $this->buffer;
    }

    /**
     * Flatten to a plain list of StepEvent in chronological order.
     *
     * @return list<StepEvent>
     */
    public function events(): array
    {
        return array_map(
            static fn(array $row): StepEvent => $row['event'],
            $this->buffer,
        );
    }
}
