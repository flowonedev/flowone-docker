<?php

namespace Webmail\Services\Search\Ast;

/**
 * Base class for every AST node the search parser emits. We use a tiny
 * algebra (Term, Operator, Group, Empty) rather than a fully generic node
 * class so the consumers (stringifier, executor, UI) can pattern-match on
 * concrete types and avoid stringly-typed switches.
 */
abstract class Node
{
    /**
     * Render this node as canonical operator syntax (round-trippable). The
     * empty node returns an empty string. Group nodes recursively join their
     * children with spaces (implicit AND).
     */
    abstract public function toQueryString(): string;

    /**
     * Walk the AST collecting every OperatorNode. Used by the SmartViewsService
     * to find special operators that need handler dispatch.
     *
     * @return OperatorNode[]
     */
    abstract public function collectOperators(): array;
}
