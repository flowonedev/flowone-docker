<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step;

/**
 * Return value of every StepInterface method (check/execute/compensate/verify).
 *
 * Carries:
 *   - The outcome (SUCCESS / FAILURE / SKIPPED / TIMEOUT / PARTIAL / RETRY_LATER)
 *   - The new persisted state (StepState) - the worker writes this to
 *     `sites.state` and `site_step_executions.output_snapshot`.
 *   - The events emitted during execution (streamed to `site_job_events`).
 *   - Optional error message for failure paths.
 *   - Optional metadata for telemetry (duration_ms, retry_after_ms,
 *     exit_code, stdout/stderr excerpts).
 *
 * Immutable.
 */
final class StepResult
{
    /**
     * @param list<StepEvent> $events
     * @param array<string, mixed> $metrics
     */
    public function __construct(
        public readonly StepOutcome $outcome,
        public readonly StepState $newState,
        public readonly ?string $error = null,
        public readonly array $events = [],
        public readonly array $metrics = []
    ) {
    }

    public static function success(StepState $newState, array $events = [], array $metrics = []): self
    {
        return new self(
            outcome: StepOutcome::SUCCESS,
            newState: $newState,
            events: $events,
            metrics: $metrics,
        );
    }

    public static function skipped(StepState $state, string $reason = '', array $metrics = []): self
    {
        return new self(
            outcome: StepOutcome::SKIPPED,
            newState: $state,
            events: $reason !== '' ? [StepEvent::info("skipped: {$reason}")] : [],
            metrics: $metrics,
        );
    }

    public static function failure(
        StepState $state,
        string $error,
        array $events = [],
        array $metrics = []
    ): self {
        return new self(
            outcome: StepOutcome::FAILURE,
            newState: $state,
            error: $error,
            events: array_merge($events, [StepEvent::error($error)]),
            metrics: $metrics,
        );
    }

    public static function timeout(StepState $state, string $detail = ''): self
    {
        return new self(
            outcome: StepOutcome::TIMEOUT,
            newState: $state,
            error: 'timeout' . ($detail !== '' ? ": {$detail}" : ''),
            events: [StepEvent::error('step timed out', ['detail' => $detail])],
        );
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public static function retryLater(
        StepState $state,
        string $reason,
        int $retryAfterMs,
        array $metrics = []
    ): self {
        return new self(
            outcome: StepOutcome::RETRY_LATER,
            newState: $state,
            error: $reason,
            events: [StepEvent::warning("retry later: {$reason}", ['retry_after_ms' => $retryAfterMs])],
            metrics: array_merge($metrics, ['retry_after_ms' => $retryAfterMs]),
        );
    }

    /**
     * @param list<StepEvent> $events
     */
    public static function partial(StepState $state, array $events = []): self
    {
        return new self(
            outcome: StepOutcome::PARTIAL,
            newState: $state,
            events: $events,
        );
    }

    public function isSuccess(): bool
    {
        return $this->outcome->isSuccessful();
    }

    public function isFailure(): bool
    {
        return $this->outcome->isFailure();
    }

    public function withEvent(StepEvent $event): self
    {
        return new self(
            $this->outcome,
            $this->newState,
            $this->error,
            [...$this->events, $event],
            $this->metrics,
        );
    }

    public function withMetric(string $key, mixed $value): self
    {
        return new self(
            $this->outcome,
            $this->newState,
            $this->error,
            $this->events,
            array_merge($this->metrics, [$key => $value]),
        );
    }
}
