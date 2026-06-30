<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Ols\Ast;

/**
 * A whole-line comment. The text includes the leading `#` and any
 * preceding indentation so it round-trips faithfully:
 *
 *   "    # This is a comment about the listener below"
 *
 * Inline comments (after a directive on the same line) are attached
 * to the DirectiveNode/BlockNode that owns the line, not here.
 */
final class CommentNode extends Node
{
    public function __construct(public string $text)
    {
    }

    protected function render(): string
    {
        return $this->text . "\n";
    }
}
