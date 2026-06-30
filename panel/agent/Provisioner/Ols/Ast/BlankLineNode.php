<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Ols\Ast;

/**
 * One or more consecutive blank lines. Preserved so that re-rendering
 * doesn't collapse paragraphs between blocks (a real complaint when the
 * legacy code ran). Modified BlankLineNodes are rare - usually we just
 * delete/insert other nodes around them.
 */
final class BlankLineNode extends Node
{
    public function __construct(public int $count = 1)
    {
    }

    protected function render(): string
    {
        return str_repeat("\n", max(1, $this->count));
    }
}
