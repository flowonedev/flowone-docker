<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Ols\Ast;

/**
 * Base of the OLS httpd_config.conf AST.
 *
 * Every node knows how to render itself back to a string. The parser
 * preserves the original raw text on each node so untouched nodes
 * round-trip byte-for-byte. When a node is mutated (`markModified()`),
 * render() falls back to a canonical formatter so the output is well-
 * formed even if the original whitespace is now inconsistent with the
 * new values.
 *
 * This dual mode ("preserve when untouched, normalize when modified")
 * gives us:
 *   - clean diffs in code review (untouched sites stay byte-identical)
 *   - safe writes (we never inherit a now-stale formatting decision)
 */
abstract class Node
{
    /**
     * Verbatim text from the source file, including the trailing newline
     * unless this is the last node in the file. Empty for nodes built
     * programmatically.
     */
    protected string $rawSource = '';

    protected bool $modified = false;

    /**
     * 1-based line range in the source file. Inclusive on both ends.
     * Used by the writer to compute minimal patches and by the validator
     * to point error messages at the right place.
     */
    protected int $startLine = 0;
    protected int $endLine = 0;

    public function rawSource(): string
    {
        return $this->rawSource;
    }

    public function setRawSource(string $raw): void
    {
        $this->rawSource = $raw;
    }

    public function startLine(): int
    {
        return $this->startLine;
    }

    public function endLine(): int
    {
        return $this->endLine;
    }

    public function setLineRange(int $start, int $end): void
    {
        $this->startLine = $start;
        $this->endLine = $end;
    }

    public function isModified(): bool
    {
        return $this->modified;
    }

    public function markModified(): void
    {
        $this->modified = true;
    }

    /**
     * Public render entry point. Returns raw source for untouched nodes,
     * canonical render() for modified ones.
     */
    public function toString(): string
    {
        if (!$this->modified && $this->rawSource !== '') {
            return $this->rawSource;
        }
        return $this->render();
    }

    /**
     * Canonical rendering. Subclasses implement formatting from
     * properties when raw source is unavailable or stale.
     */
    abstract protected function render(): string;
}
