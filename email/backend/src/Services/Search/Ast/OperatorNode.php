<?php

namespace Webmail\Services\Search\Ast;

use Webmail\Services\Search\OperatorRegistry;

/**
 * A `key:value` pair (e.g. `from:foo@bar.com`, `is:unread`, `label:"my work"`).
 * Operator is always lowercased; value retains its original case for things
 * like email addresses and label names.
 */
final class OperatorNode extends Node
{
    public function __construct(
        public readonly string $operator,
        public readonly string $value,
    ) {}

    public function toQueryString(): string
    {
        $op = $this->operator;
        $val = $this->value;
        if (TermNode::needsQuoting($val)) {
            $val = '"' . str_replace('"', '\\"', $val) . '"';
        }
        return $op . ':' . $val;
    }

    public function collectOperators(): array
    {
        return [$this];
    }

    public function isSpecial(): bool
    {
        return OperatorRegistry::isSpecial($this->operator);
    }

    public function isReserved(): bool
    {
        return OperatorRegistry::isReserved($this->operator);
    }
}
