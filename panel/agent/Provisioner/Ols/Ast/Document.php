<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Ols\Ast;

/**
 * Top-level container - the entire httpd_config.conf.
 *
 * Document is itself NOT a Node because it has no parent and no surrounding
 * block syntax. It owns a list of top-level children (BlockNode,
 * DirectiveNode, CommentNode, BlankLineNode) and knows how to serialize
 * the whole file.
 */
final class Document
{
    /** @var list<Node> */
    public array $children = [];

    public function addChild(Node $child): void
    {
        $this->children[] = $child;
    }

    public function findBlock(string $name, ?string $args = null): ?BlockNode
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
    public function findAllBlocks(string $name): array
    {
        $out = [];
        foreach ($this->children as $child) {
            if ($child instanceof BlockNode && strcasecmp($child->name, $name) === 0) {
                $out[] = $child;
            }
        }
        return $out;
    }

    public function removeBlock(BlockNode $block): bool
    {
        foreach ($this->children as $i => $child) {
            if ($child === $block) {
                array_splice($this->children, $i, 1);
                return true;
            }
        }
        return false;
    }

    public function findDirective(string $name): ?DirectiveNode
    {
        foreach ($this->children as $child) {
            if ($child instanceof DirectiveNode && strcasecmp($child->name, $name) === 0) {
                return $child;
            }
        }
        return null;
    }

    public function toString(): string
    {
        $out = '';
        foreach ($this->children as $child) {
            $out .= $child->toString();
        }
        return $out;
    }
}
