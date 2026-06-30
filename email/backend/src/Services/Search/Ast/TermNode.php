<?php

namespace Webmail\Services\Search\Ast;

/**
 * A bare search term (no operator) — these become IMAP TEXT criteria.
 * Whitespace in the value forces quoting when stringified.
 */
final class TermNode extends Node
{
    public function __construct(public readonly string $value) {}

    public function toQueryString(): string
    {
        if ($this->value === '') return '';
        return self::needsQuoting($this->value) ? '"' . $this->escape($this->value) . '"' : $this->value;
    }

    public function collectOperators(): array
    {
        return [];
    }

    public static function needsQuoting(string $value): bool
    {
        return preg_match('/[\s":]/u', $value) === 1;
    }

    private function escape(string $value): string
    {
        return str_replace('"', '\\"', $value);
    }
}
