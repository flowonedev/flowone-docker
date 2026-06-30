<?php

namespace Webmail\Services\Search\Ast;

/**
 * The root of every parsed query. Children are implicitly AND-ed (matches
 * Gmail / Outlook semantics today — no OR or parentheses yet, but the AST
 * shape leaves room for future grouping/negation nodes).
 *
 * @template T of Node
 */
final class GroupNode extends Node
{
    /** @param Node[] $children */
    public function __construct(public array $children = []) {}

    public function add(Node $child): void
    {
        // Drop empty terms — they're noise in stringify/equality checks.
        if ($child instanceof TermNode && $child->value === '') return;
        $this->children[] = $child;
    }

    public function isEmpty(): bool
    {
        return empty($this->children);
    }

    public function toQueryString(): string
    {
        $parts = [];
        foreach ($this->children as $c) {
            $s = $c->toQueryString();
            if ($s !== '') $parts[] = $s;
        }
        return implode(' ', $parts);
    }

    public function collectOperators(): array
    {
        $out = [];
        foreach ($this->children as $c) {
            foreach ($c->collectOperators() as $op) {
                $out[] = $op;
            }
        }
        return $out;
    }
}
