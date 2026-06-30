<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Exceptions;

/**
 * Thrown by OlsConfigValidator when a Document fails structural or
 * semantic checks (unbalanced braces, missing required blocks, lswsctrl
 * test failure). Carries a list of human-readable problems so the
 * operator sees every issue at once instead of one-at-a-time.
 */
class OlsValidationException extends \RuntimeException
{
    /**
     * @param list<string> $problems
     */
    public function __construct(
        public readonly array $problems,
        ?\Throwable $previous = null
    ) {
        $head = $problems[0] ?? 'unknown validation error';
        $more = count($problems) > 1 ? ' (+' . (count($problems) - 1) . ' more)' : '';
        parent::__construct("OLS config validation failed: {$head}{$more}", 0, $previous);
    }
}
