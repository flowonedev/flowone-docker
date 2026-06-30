<?php

declare(strict_types=1);

namespace FlowOne\Storage\Exceptions;

/**
 * Thrown when a documented architectural invariant is violated at runtime.
 *
 * See shared/docs/INVARIANTS.md. Each violation carries the invariant
 * number (e.g. "I-1") and a structured context payload that is also
 * appended to the operation journal.
 *
 * In strict mode (config.strict_invariants = true), the assertion methods
 * in {@see \FlowOne\Storage\Invariants} throw this exception. In normal
 * mode they log and return false; callers decide whether to proceed.
 */
final class InvariantViolation extends \RuntimeException
{
    public function __construct(
        public readonly string $invariantId,
        string $message,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct("[{$invariantId}] {$message}", 0, $previous);
    }
}
