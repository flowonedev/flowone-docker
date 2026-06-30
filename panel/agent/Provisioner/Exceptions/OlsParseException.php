<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Exceptions;

/**
 * Thrown by OlsConfigParser when the input file cannot be tokenized into
 * a well-formed AST. The exception carries the offending line number so
 * the operator can pinpoint the breakage in the source file without
 * eyeballing thousands of lines.
 */
class OlsParseException extends \RuntimeException
{
    public function __construct(
        public readonly int $sourceLine,
        string $reason,
        ?\Throwable $previous = null
    ) {
        parent::__construct("OLS config parse error at line {$sourceLine}: {$reason}", 0, $previous);
    }
}
