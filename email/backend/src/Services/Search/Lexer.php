<?php

namespace Webmail\Services\Search;

/**
 * Tokenises a search query string into a flat token stream.
 *
 * Recognised forms:
 *   word                → T_WORD          ("hello")
 *   "quoted phrase"     → T_QUOTED        (escape with \" inside)
 *   key:                → T_OPERATOR      (must be followed by a T_WORD or T_QUOTED)
 *   key:value           → T_OPERATOR then T_WORD
 *   key:"value with sp" → T_OPERATOR then T_QUOTED
 *
 * Tolerant: malformed input produces empty/best-effort tokens rather than
 * exceptions, because user input lives on the hot search path and a crash is
 * worse than an unhelpful result. The Parser is responsible for semantic
 * validation against the OperatorRegistry.
 */
final class Lexer
{
    private string $src;
    private int $i = 0;
    private int $len = 0;

    public function __construct(string $src)
    {
        $this->src = $src;
        $this->len = strlen($src);
    }

    /** @return Token[] */
    public function tokenize(): array
    {
        $tokens = [];
        while ($this->i < $this->len) {
            $ch = $this->src[$this->i];

            if (ctype_space($ch)) {
                $this->i++;
                continue;
            }

            $start = $this->i;

            if ($ch === '"') {
                $tokens[] = new Token(Token::T_QUOTED, $this->readQuoted(), $start);
                continue;
            }

            $word = $this->readWord();
            if ($word === '') {
                $this->i++; // safety bump on unrecognised char
                continue;
            }

            // If the word is immediately followed by ':', it's an operator key.
            // We emit T_OPERATOR with the key (without the colon), then on the
            // next iteration the value (word or quoted) becomes its argument.
            if ($this->i < $this->len && $this->src[$this->i] === ':') {
                $this->i++; // consume ':'
                $tokens[] = new Token(Token::T_OPERATOR, $word, $start);
                continue;
            }

            $tokens[] = new Token(Token::T_WORD, $word, $start);
        }
        return $tokens;
    }

    private function readQuoted(): string
    {
        $this->i++; // skip opening "
        $buf = '';
        while ($this->i < $this->len) {
            $ch = $this->src[$this->i];
            if ($ch === '\\' && $this->i + 1 < $this->len && $this->src[$this->i + 1] === '"') {
                $buf .= '"';
                $this->i += 2;
                continue;
            }
            if ($ch === '"') {
                $this->i++; // skip closing "
                return $buf;
            }
            $buf .= $ch;
            $this->i++;
        }
        // Unterminated quote — return what we have. Common when the user is
        // mid-typing; the Parser will still produce sensible output.
        return $buf;
    }

    private function readWord(): string
    {
        $start = $this->i;
        while ($this->i < $this->len) {
            $ch = $this->src[$this->i];
            if (ctype_space($ch) || $ch === '"' || $ch === ':') break;
            $this->i++;
        }
        return substr($this->src, $start, $this->i - $start);
    }
}
