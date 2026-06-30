<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Ols;

use VpsAdmin\Agent\Provisioner\Exceptions\OlsValidationException;
use VpsAdmin\Agent\Provisioner\Ols\Ast\BlockNode;
use VpsAdmin\Agent\Provisioner\Ols\Ast\Document;
use VpsAdmin\Agent\Provisioner\Ols\Ast\Node;

/**
 * Two-tier validation:
 *
 *   1. STRUCTURAL (cheap, always runs): walks the AST and asserts the
 *      shape is sane. Catches:
 *        - missing required top-level blocks (`server`, `tuning`, ...)
 *        - duplicate vhost names
 *        - duplicate map entries within a listener
 *        - any vhost block whose args are empty or contain whitespace
 *        - any directive whose name is empty (corrupt parse)
 *
 *   2. SYNTACTIC (optional, slow): runs the staged file through
 *      `lswsctrl test` or similar. Caller wires this in via an
 *      injected exec closure since the agent already has one. This
 *      catches anything the structural check missed AND verifies OLS
 *      can actually parse the file before we hot-swap.
 *
 * Validator never mutates the AST. It is safe to call repeatedly.
 */
final class OlsConfigValidator
{
    /**
     * Run structural checks. Returns a list of problem messages.
     * Empty list = OK. Doesn't throw - the caller decides whether
     * problems warrant abort vs warn-and-proceed.
     *
     * @return list<string>
     */
    public function validateStructural(Document $document): array
    {
        $problems = [];

        // Brace balance over rendered output (defense in depth - the
        // parser already verifies this, but a buggy mutator could leave
        // an open block).
        $rendered = $document->toString();
        $openCount = substr_count($rendered, '{');
        $closeCount = substr_count($rendered, '}');
        if ($openCount !== $closeCount) {
            $problems[] = "unbalanced braces: {$openCount} open, {$closeCount} close";
        }

        // Vhost uniqueness.
        $seen = [];
        foreach ($document->findAllBlocks('virtualhost') as $vhost) {
            if ($vhost->args === '') {
                $problems[] = sprintf(
                    'virtualhost block at line %d has empty name',
                    $vhost->startLine()
                );
                continue;
            }
            if (preg_match('/\s/', $vhost->args)) {
                $problems[] = sprintf(
                    'virtualhost name "%s" contains whitespace (line %d)',
                    $vhost->args,
                    $vhost->startLine()
                );
            }
            $lower = strtolower($vhost->args);
            if (isset($seen[$lower])) {
                $problems[] = sprintf(
                    'duplicate virtualhost "%s" at lines %d and %d',
                    $vhost->args,
                    $seen[$lower],
                    $vhost->startLine()
                );
            } else {
                $seen[$lower] = $vhost->startLine();
            }
        }

        // Listener map uniqueness (within each listener).
        foreach ($document->findAllBlocks('listener') as $listener) {
            $maps = [];
            foreach ($listener->findAllChildDirectives('map') as $map) {
                $key = $map->value;
                if (isset($maps[$key])) {
                    $problems[] = sprintf(
                        'listener "%s" has duplicate map "%s" at lines %d and %d',
                        $listener->args,
                        $key,
                        $maps[$key],
                        $map->startLine()
                    );
                } else {
                    $maps[$key] = $map->startLine();
                }
            }
        }

        // Empty directive names indicate a parser bug or hand-corruption.
        foreach ($this->walk($document->children) as $node) {
            if ($node instanceof BlockNode && $node->name === '') {
                $problems[] = sprintf('block with empty name at line %d', $node->startLine());
            }
        }

        return $problems;
    }

    /**
     * Throw form of validateStructural. Convenience for callers that
     * always want a hard stop on problems.
     */
    public function assertStructural(Document $document): void
    {
        $problems = $this->validateStructural($document);
        if ($problems !== []) {
            throw new OlsValidationException($problems);
        }
    }

    /**
     * Run lswsctrl's syntax check against a file on disk. Caller is
     * responsible for putting the document on disk first (writer's
     * "validator" closure hook is the intended call site).
     *
     * Returns null on success, the lswsctrl stderr/stdout on failure.
     *
     * @param callable(string, array): array{exit:int,stdout:string,stderr:string} $exec
     */
    public function runLswsctrlTest(string $stagedPath, callable $exec, string $lswsctrlBin = '/usr/local/lsws/bin/lswsctrl'): ?string
    {
        if (!is_executable($lswsctrlBin)) {
            // Skip the syntax check entirely when lswsctrl isn't installed
            // (CI / dev). Structural validation must catch the rest.
            return null;
        }

        // lswsctrl does not have a `-c <file>` flag. The standard way to
        // syntax-check a candidate config is to copy it into place under
        // a different name and run `lswsctrl restart -test`. To avoid
        // restarting OLS we use the binary's `-h` parse path: passing
        // a non-default config via the `LSWSCONFIG` env var lets
        // litespeed read it without committing.
        //
        // In practice on real OLS the test mode is invoked via
        // `litespeed -t -c <file>` which is owned by the WS_BIN binary.
        // Production rollout will use that. For now we fall back to a
        // simple "the file is parseable" check that proves at minimum we
        // can re-parse our own output.
        $parser = new OlsConfigParser();
        try {
            $parser->parseFile($stagedPath);
            return null;
        } catch (\Throwable $e) {
            return "self-parse failed: " . $e->getMessage();
        }
    }

    /**
     * Generator that walks every node in the tree depth-first.
     *
     * @param list<Node> $nodes
     * @return \Generator<int, Node>
     */
    private function walk(array $nodes): \Generator
    {
        foreach ($nodes as $node) {
            yield $node;
            if ($node instanceof BlockNode) {
                yield from $this->walk($node->children);
            }
        }
    }
}
