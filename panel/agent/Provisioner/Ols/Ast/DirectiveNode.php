<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Ols\Ast;

/**
 * A leaf directive: `name value [# inline comment]`.
 *
 * The original file uses a column-aligned style with the value at column
 * 24, e.g.
 *
 *     vhRoot                  /home/$VH_NAME
 *     map                     example.com example.com
 *
 * We preserve this when re-rendering modified directives. Indentation
 * is inherited from the parent block (two spaces per nesting level by
 * convention).
 */
final class DirectiveNode extends Node
{
    public const VALUE_COLUMN = 24;

    public function __construct(
        public string $name,
        public string $value,
        public string $indent = '',
        public ?string $inlineComment = null
    ) {
    }

    public function setValue(string $newValue): void
    {
        if ($this->value !== $newValue) {
            $this->value = $newValue;
            $this->markModified();
        }
    }

    protected function render(): string
    {
        $pad = max(1, self::VALUE_COLUMN - strlen($this->name));
        $line = $this->indent . $this->name . str_repeat(' ', $pad) . $this->value;
        if ($this->inlineComment !== null && $this->inlineComment !== '') {
            $line .= '  ' . $this->inlineComment;
        }
        return $line . "\n";
    }
}
