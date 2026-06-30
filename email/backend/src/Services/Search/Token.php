<?php

namespace Webmail\Services\Search;

/**
 * A token emitted by the Lexer. We keep this dirt-simple: just a type tag
 * and a value. Position is tracked so future error messages can point at the
 * offending char without re-scanning.
 */
final class Token
{
    public const T_WORD     = 'word';      // bare term, may become a TermNode or an operator value
    public const T_QUOTED   = 'quoted';    // "foo bar" — preserves spaces in value
    public const T_OPERATOR = 'operator';  // `key:` prefix (the colon was consumed)

    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly int    $pos,
    ) {}
}
