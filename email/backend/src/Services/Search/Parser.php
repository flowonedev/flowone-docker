<?php

namespace Webmail\Services\Search;

use Webmail\Services\Search\Ast\GroupNode;
use Webmail\Services\Search\Ast\Node;
use Webmail\Services\Search\Ast\OperatorNode;
use Webmail\Services\Search\Ast\TermNode;

/**
 * Parses a flat token stream from Lexer into a GroupNode AST.
 *
 * Grammar (informal):
 *   query        := term*
 *   term         := operator-expr | text-term
 *   operator-expr:= KEY ':' value
 *   value        := WORD | QUOTED
 *   text-term    := WORD | QUOTED
 *
 * Validation policy:
 *   - Unknown operators are silently demoted to text terms (the original
 *     `key:value` is reconstructed as a single TermNode). This mirrors Gmail
 *     behaviour — typing `foo:bar` when `foo` isn't an operator just searches
 *     the literal text.
 *   - Operators with invalid values (e.g. `is:nonsense`) are also demoted.
 *
 * The parser does NOT throw on bad input — the search box is a hot UI surface
 * and silent fallback beats red squigglies and exception spam.
 */
final class Parser
{
    /** @var Token[] */
    private array $tokens;
    private int $i = 0;
    private int $count = 0;

    /** @param Token[] $tokens */
    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
        $this->count  = count($tokens);
    }

    public static function parseString(string $query): GroupNode
    {
        $tokens = (new Lexer($query))->tokenize();
        return (new self($tokens))->parse();
    }

    public function parse(): GroupNode
    {
        $root = new GroupNode();
        while ($this->i < $this->count) {
            $tok = $this->tokens[$this->i];

            if ($tok->type === Token::T_OPERATOR) {
                $node = $this->readOperator($tok);
                if ($node) {
                    $root->add($node);
                }
                continue;
            }

            // T_WORD or T_QUOTED
            $root->add(new TermNode($tok->value));
            $this->i++;
        }
        return $root;
    }

    private function readOperator(Token $opToken): ?Node
    {
        $this->i++; // consume operator key
        $key   = strtolower($opToken->value);
        $valTok = $this->tokens[$this->i] ?? null;

        // Operator with no following value → demote to literal text "key:"
        if (!$valTok || ($valTok->type !== Token::T_WORD && $valTok->type !== Token::T_QUOTED)) {
            return new TermNode($opToken->value . ':');
        }

        $this->i++; // consume value
        $val = $valTok->value;

        // Unknown / invalid operator-value → demote to a single literal term.
        if (!OperatorRegistry::has($key) || !OperatorRegistry::isValidValue($key, $val)) {
            return new TermNode($this->reconstruct($opToken->value, $val, $valTok->type));
        }

        return new OperatorNode($key, $val);
    }

    private function reconstruct(string $key, string $val, string $valType): string
    {
        if ($valType === Token::T_QUOTED) {
            return $key . ':"' . str_replace('"', '\\"', $val) . '"';
        }
        return $key . ':' . $val;
    }
}
