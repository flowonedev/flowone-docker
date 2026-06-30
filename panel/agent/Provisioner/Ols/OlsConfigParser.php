<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Ols;

use VpsAdmin\Agent\Provisioner\Exceptions\OlsParseException;
use VpsAdmin\Agent\Provisioner\Ols\Ast\BlankLineNode;
use VpsAdmin\Agent\Provisioner\Ols\Ast\BlockNode;
use VpsAdmin\Agent\Provisioner\Ols\Ast\CommentNode;
use VpsAdmin\Agent\Provisioner\Ols\Ast\DirectiveNode;
use VpsAdmin\Agent\Provisioner\Ols\Ast\Document;
use VpsAdmin\Agent\Provisioner\Ols\Ast\Node;

/**
 * Line-oriented recursive-descent parser for OpenLiteSpeed httpd_config.conf.
 *
 * Grammar (informal):
 *   document     := element*
 *   element      := blank | comment | directive | block
 *   blank        := /\s*\n/
 *   comment      := /\s*#.*\n/
 *   directive    := /<INDENT><name>\s+<value>(\s+#.*)?\n/
 *   block        := /<INDENT><name>(\s+<args>)?\s*\{\n/ element* /<INDENT>\}\n/
 *
 *   <name>       := [A-Za-z][A-Za-z0-9_]*
 *   <args>       := anything until '{' (trimmed), no '{' or '}' allowed
 *   <value>      := rest of line until optional '#' inline comment
 *
 * Each parsed node records its 1-based source line range and original
 * raw text so untouched nodes round-trip byte-for-byte through
 * Document::toString(). Modified nodes re-render via the canonical
 * formatter on each Node subclass.
 *
 * Edge cases handled:
 *   - "virtualhost example.com {"   (block on its own line)
 *   - "listener Default {"          (block with single-word args)
 *   - "map  example.com  example.com"  (directive with multi-token value)
 *   - lines with trailing whitespace
 *   - "} else {" - we DO NOT handle this; OLS doesn't have it
 *   - "{" on its own line - we DO NOT handle this (no real OLS config has it)
 *
 * Things this parser intentionally does NOT do:
 *   - resolve $VH_NAME / $SERVER_ROOT macros (mutator does that)
 *   - schema-validate directive names against OLS's allowed list
 *     (validator does that)
 *   - normalize whitespace on untouched nodes
 */
final class OlsConfigParser
{
    /** @var list<string> Source split by lines, each WITH trailing "\n" preserved. */
    private array $lines = [];
    /** @var int 0-based index into $lines. */
    private int $cursor = 0;

    public function parseFile(string $path): Document
    {
        if (!is_readable($path)) {
            throw new OlsParseException(0, "cannot read {$path}");
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new OlsParseException(0, "file_get_contents failed for {$path}");
        }
        return $this->parseString($content);
    }

    public function parseString(string $content): Document
    {
        $this->lines = $this->splitIntoLinesPreservingNewlines($content);
        $this->cursor = 0;

        $document = new Document();
        while ($this->cursor < count($this->lines)) {
            $node = $this->parseTopLevelElement();
            if ($node !== null) {
                $document->addChild($node);
            }
        }
        return $document;
    }

    /**
     * Split content into an array of lines, each KEEPING its trailing "\n"
     * so we can reconstruct the original byte-for-byte. The last line is
     * "\n"-terminated even if the source wasn't (we'll detect and preserve
     * absence at write time via OlsConfigWriter).
     */
    private function splitIntoLinesPreservingNewlines(string $content): array
    {
        if ($content === '') {
            return [];
        }
        // Normalize Windows CRLF -> Unix LF for predictable parsing.
        // The writer never emits CRLF, so this is a one-way normalization.
        $normalized = str_replace("\r\n", "\n", $content);
        $parts = explode("\n", $normalized);
        $lines = [];
        $last = count($parts) - 1;
        foreach ($parts as $i => $part) {
            if ($i === $last && $part === '') {
                // Trailing newline produced an empty final element -
                // don't emit an empty extra line.
                continue;
            }
            $lines[] = $part . "\n";
        }
        return $lines;
    }

    private function parseTopLevelElement(): ?Node
    {
        $rawLine = $this->lines[$this->cursor];
        $lineNo = $this->cursor + 1;

        // Blank line (or whitespace-only)
        if (trim($rawLine) === '') {
            $count = 0;
            $start = $lineNo;
            while ($this->cursor < count($this->lines) && trim($this->lines[$this->cursor]) === '') {
                $count++;
                $this->cursor++;
            }
            $node = new BlankLineNode($count);
            $node->setRawSource(str_repeat("\n", $count));
            $node->setLineRange($start, $start + $count - 1);
            return $node;
        }

        // Comment line
        $trimmed = ltrim($rawLine);
        if ($trimmed !== '' && $trimmed[0] === '#') {
            $this->cursor++;
            $node = new CommentNode(rtrim($rawLine, "\n"));
            $node->setRawSource($rawLine);
            $node->setLineRange($lineNo, $lineNo);
            return $node;
        }

        // A line ending in "{" is the start of a block.
        // We need to be careful: the brace might be at end of line OR
        // separated by whitespace. We do not support `{` on its own line.
        $strippedNoComment = $this->stripInlineComment($rawLine);
        $rtrimmed = rtrim($strippedNoComment);
        if (str_ends_with($rtrimmed, '{')) {
            return $this->parseBlock();
        }

        // Otherwise treat as a top-level directive (e.g. `serverName foo`).
        return $this->parseDirective();
    }

    /**
     * Parse a block whose opening line is at $this->cursor. The header's
     * own indentation is captured from the source; we do not need a
     * pre-known indent depth.
     */
    private function parseBlock(): BlockNode
    {
        $headerLine = $this->lines[$this->cursor];
        $headerLineNo = $this->cursor + 1;
        $this->cursor++;

        [$indent, $name, $args, $afterBraceInlineComment] = $this->dissectBlockHeader($headerLine, $headerLineNo);

        $block = new BlockNode($name, $args, $indent);
        // For untouched preservation we DO want raw, but a block's raw
        // contains its children too. So we store the WHOLE block raw
        // when we finish parsing it (see below).
        $block->setLineRange($headerLineNo, $headerLineNo);

        // Parse children until matching `}`
        while ($this->cursor < count($this->lines)) {
            $line = $this->lines[$this->cursor];
            $trimmedLine = trim($line);

            // Closing brace? It must be on its own (possibly indented) line.
            if ($trimmedLine === '}' || str_starts_with($trimmedLine, '}#') || preg_match('/^\}\s*(#.*)?$/', $trimmedLine)) {
                $closeLineNo = $this->cursor + 1;
                $this->cursor++;
                $block->setLineRange($headerLineNo, $closeLineNo);
                // Reconstruct raw source from the source lines, so an
                // untouched block round-trips byte-for-byte.
                $block->setRawSource($this->joinSourceLines($headerLineNo, $closeLineNo));
                return $block;
            }

            $child = $this->parseChildElement();
            if ($child !== null) {
                $block->children[] = $child;
            }
        }

        throw new OlsParseException(
            $headerLineNo,
            "unterminated block '{$name}' (missing closing brace)"
        );
    }

    private function parseChildElement(): ?Node
    {
        $rawLine = $this->lines[$this->cursor];
        $lineNo = $this->cursor + 1;

        if (trim($rawLine) === '') {
            $count = 0;
            $start = $lineNo;
            while ($this->cursor < count($this->lines) && trim($this->lines[$this->cursor]) === '') {
                $count++;
                $this->cursor++;
            }
            $node = new BlankLineNode($count);
            $node->setRawSource(str_repeat("\n", $count));
            $node->setLineRange($start, $start + $count - 1);
            return $node;
        }

        $ltrimmed = ltrim($rawLine);
        if ($ltrimmed !== '' && $ltrimmed[0] === '#') {
            $this->cursor++;
            $node = new CommentNode(rtrim($rawLine, "\n"));
            $node->setRawSource($rawLine);
            $node->setLineRange($lineNo, $lineNo);
            return $node;
        }

        $stripped = $this->stripInlineComment($rawLine);
        $rtrimmed = rtrim($stripped);
        if (str_ends_with($rtrimmed, '{')) {
            return $this->parseBlock();
        }

        return $this->parseDirective();
    }

    private function parseDirective(): DirectiveNode
    {
        $rawLine = $this->lines[$this->cursor];
        $lineNo = $this->cursor + 1;
        $this->cursor++;

        // Split into [indent, body, inline-comment]
        if (!preg_match('/^(\s*)(.*?)\s*$/s', rtrim($rawLine, "\n"), $m)) {
            throw new OlsParseException($lineNo, "could not lex directive");
        }
        $indent = $m[1];
        $body = $m[2];

        $inlineComment = null;
        $hashPos = $this->findInlineCommentStart($body);
        if ($hashPos !== null) {
            $inlineComment = rtrim(substr($body, $hashPos));
            $body = rtrim(substr($body, 0, $hashPos));
        }

        // Split body into name + value on first run of whitespace.
        if (!preg_match('/^(\S+)(?:\s+(.*))?$/', $body, $m)) {
            throw new OlsParseException($lineNo, "could not lex directive: '{$body}'");
        }
        $name = $m[1];
        $value = $m[2] ?? '';

        $node = new DirectiveNode($name, $value, $indent, $inlineComment);
        $node->setRawSource($rawLine);
        $node->setLineRange($lineNo, $lineNo);
        return $node;
    }

    /**
     * Find the start index of an inline comment in a line body, or null.
     * Naively assumes `#` is not legal inside any OLS value - true for
     * every directive that ships in the default config.
     */
    private function findInlineCommentStart(string $body): ?int
    {
        $pos = strpos($body, '#');
        return $pos === false ? null : $pos;
    }

    /**
     * Remove an inline comment from a single-line snippet so we can
     * inspect the "real" trailing token (e.g. detect a "{" before a "# foo").
     */
    private function stripInlineComment(string $line): string
    {
        $hashPos = strpos($line, '#');
        if ($hashPos === false) {
            return $line;
        }
        return substr($line, 0, $hashPos);
    }

    /**
     * Pull apart a block header line:
     *   "  listener Default { # primary http"
     * into:
     *   indent='  ', name='listener', args='Default', inlineComment='# primary http'
     */
    private function dissectBlockHeader(string $line, int $lineNo): array
    {
        $body = rtrim($line, "\n");
        if (!preg_match('/^(\s*)(.*)$/s', $body, $m)) {
            throw new OlsParseException($lineNo, "could not parse block header");
        }
        $indent = $m[1];
        $rest = $m[2];

        $inlineComment = null;
        $hashPos = strpos($rest, '#');
        if ($hashPos !== false) {
            $inlineComment = rtrim(substr($rest, $hashPos));
            $rest = rtrim(substr($rest, 0, $hashPos));
        }

        $bracePos = strrpos($rest, '{');
        if ($bracePos === false) {
            throw new OlsParseException($lineNo, "block header missing '{'");
        }
        $beforeBrace = rtrim(substr($rest, 0, $bracePos));

        if (!preg_match('/^(\S+)(?:\s+(.+))?$/', $beforeBrace, $m)) {
            throw new OlsParseException($lineNo, "block header has no name");
        }
        $name = $m[1];
        $args = isset($m[2]) ? trim($m[2]) : '';

        return [$indent, $name, $args, $inlineComment];
    }

    private function joinSourceLines(int $start1, int $end1): string
    {
        $start = $start1 - 1;
        $end = $end1 - 1;
        $out = '';
        for ($i = $start; $i <= $end; $i++) {
            $out .= $this->lines[$i];
        }
        return $out;
    }
}
