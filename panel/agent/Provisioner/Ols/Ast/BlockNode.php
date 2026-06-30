<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Ols\Ast;

/**
 * A `name args { ... }` block.
 *
 * Examples seen in real httpd_config.conf:
 *   virtualhost example.com { ... }
 *   listener Default { ... }
 *   listener SSL { ... }
 *   module cache { ... }
 *   tuning { ... }
 *
 * args is the token(s) between the name and the opening brace. For
 * top-level blocks like `tuning` it is empty; for vhost blocks it is
 * the domain name.
 *
 * Name comparison is case-insensitive (we have seen both
 * `virtualHost example.com` and `virtualhost example.com` in the wild)
 * but the original casing is preserved for round-tripping.
 */
final class BlockNode extends Node
{
    /** @var list<Node> */
    public array $children = [];

    public function __construct(
        public string $name,
        public string $args = '',
        public string $indent = ''
    ) {
    }

    public function addChild(Node $child): void
    {
        $this->children[] = $child;
        $this->markModified();
    }

    public function prependChild(Node $child): void
    {
        array_unshift($this->children, $child);
        $this->markModified();
    }

    public function insertChildAt(int $index, Node $child): void
    {
        array_splice($this->children, $index, 0, [$child]);
        $this->markModified();
    }

    public function removeChild(Node $child): bool
    {
        foreach ($this->children as $i => $existing) {
            if ($existing === $child) {
                array_splice($this->children, $i, 1);
                $this->markModified();
                return true;
            }
        }
        return false;
    }

    /**
     * Case-insensitive name match for child blocks. If $args is provided,
     * the child must match it exactly (case-sensitive - args are usually
     * domains or labels where casing matters).
     */
    public function findChildBlock(string $name, ?string $args = null): ?BlockNode
    {
        foreach ($this->children as $child) {
            if ($child instanceof BlockNode
                && strcasecmp($child->name, $name) === 0
                && ($args === null || $child->args === $args)
            ) {
                return $child;
            }
        }
        return null;
    }

    /**
     * @return list<BlockNode>
     */
    public function findAllChildBlocks(string $name): array
    {
        $out = [];
        foreach ($this->children as $child) {
            if ($child instanceof BlockNode && strcasecmp($child->name, $name) === 0) {
                $out[] = $child;
            }
        }
        return $out;
    }

    public function findChildDirective(string $name): ?DirectiveNode
    {
        foreach ($this->children as $child) {
            if ($child instanceof DirectiveNode && strcasecmp($child->name, $name) === 0) {
                return $child;
            }
        }
        return null;
    }

    /**
     * @return list<DirectiveNode>
     */
    public function findAllChildDirectives(string $name): array
    {
        $out = [];
        foreach ($this->children as $child) {
            if ($child instanceof DirectiveNode && strcasecmp($child->name, $name) === 0) {
                $out[] = $child;
            }
        }
        return $out;
    }

    /**
     * Find the index of the last DirectiveNode with the given name (case-
     * insensitive) among direct children. Returns -1 if not found.
     * Used by the mutator to insert new map entries adjacent to existing
     * ones rather than scattered at the end.
     */
    public function lastIndexOfDirective(string $name): int
    {
        $found = -1;
        foreach ($this->children as $i => $child) {
            if ($child instanceof DirectiveNode && strcasecmp($child->name, $name) === 0) {
                $found = $i;
            }
        }
        return $found;
    }

    /**
     * Recursive modification check. A block's stored raw source spans its
     * entire body, so if ANY descendant is modified the raw is stale and
     * we must re-render.
     */
    public function hasModifiedDescendant(): bool
    {
        foreach ($this->children as $child) {
            if ($child->isModified()) {
                return true;
            }
            if ($child instanceof BlockNode && $child->hasModifiedDescendant()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Override Node::toString so the raw-source fast path is only taken
     * when no descendant has changed.
     */
    public function toString(): string
    {
        if (!$this->isModified() && !$this->hasModifiedDescendant() && $this->rawSource() !== '') {
            return $this->rawSource();
        }
        return $this->render();
    }

    protected function render(): string
    {
        $header = $this->indent . $this->name;
        if ($this->args !== '') {
            $header .= ' ' . $this->args;
        }
        $out = $header . " {\n";
        foreach ($this->children as $child) {
            $out .= $child->toString();
        }
        $out .= $this->indent . "}\n";
        return $out;
    }
}
