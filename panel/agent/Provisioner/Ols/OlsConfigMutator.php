<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Ols;

use VpsAdmin\Agent\Provisioner\Ols\Ast\BlankLineNode;
use VpsAdmin\Agent\Provisioner\Ols\Ast\BlockNode;
use VpsAdmin\Agent\Provisioner\Ols\Ast\DirectiveNode;
use VpsAdmin\Agent\Provisioner\Ols\Ast\Document;

/**
 * High-level idempotent operations on the OLS config AST.
 *
 * Every operation has the contract:
 *   - safe to call when the target already exists in the desired shape
 *     (it's a no-op)
 *   - safe to call when the target is partially present (it converges)
 *   - never edits unrelated blocks (the goal is small, reviewable diffs)
 *   - returns true if it touched the document, false if it was a no-op
 *
 * This is the "what" of vhost provisioning; the writer is the "how"
 * (atomic save, ownership, restart). The parser/validator/writer all
 * remain ignorant of business semantics like "what does a virtualhost
 * block need to contain" - that knowledge lives here.
 */
final class OlsConfigMutator
{
    /**
     * Default child directives for a fresh virtualhost block. Picked to
     * match the values used by the legacy `addVhostToMainConfig` so
     * existing reverse-engineered behavior is preserved for new sites.
     *
     * Callers may override individual values via $overrides. Anything
     * NOT in the default set is appended after the standard directives
     * in the order it appears in $overrides.
     */
    private const VHOST_DEFAULTS = [
        'vhRoot' => '/home/$VH_NAME',
        'configFile' => '$SERVER_ROOT/conf/vhosts/$VH_NAME/vhost.conf',
        'allowSymbolLink' => '1',
        'enableScript' => '1',
        'restrained' => '1',
    ];

    /**
     * Listener block names where vhost map entries live. We intentionally
     * exclude any listener whose args contain "IPv6" to match the legacy
     * filter - those listeners do NOT carry vhost maps.
     */
    private const TARGETED_LISTENERS = ['Default', 'SSL'];

    /**
     * Insert or update the vhost block at the top level. Idempotent:
     *
     *   $changed1 = $m->upsertVirtualHost($doc, 'example.com', []);  // true
     *   $changed2 = $m->upsertVirtualHost($doc, 'example.com', []);  // false (same)
     *
     * Existing children of a present block are preserved unless their
     * value is overridden via $overrides. New children are appended in
     * the canonical order. Unknown existing children are kept untouched
     * so manual customizations survive.
     *
     * @param array<string, string> $overrides
     */
    public function upsertVirtualHost(Document $doc, string $domain, array $overrides = []): bool
    {
        $existing = $this->findVirtualHostBlock($doc, $domain);

        if ($existing === null) {
            $block = $this->buildVirtualHostBlock($domain, $overrides);
            // Prepend a blank line before the block so it stands apart
            // visually from whatever preceded it.
            $blank = new BlankLineNode(1);
            $blank->markModified();
            $doc->addChild($blank);
            $doc->addChild($block);
            return true;
        }

        // Block exists: apply $overrides idempotently to its directives.
        // Existing values that match the desired override are left alone.
        $changed = false;
        $effective = array_merge(self::VHOST_DEFAULTS, $overrides);
        foreach ($effective as $directiveName => $desiredValue) {
            $directive = $existing->findChildDirective($directiveName);
            if ($directive === null) {
                $existing->addChild(new DirectiveNode(
                    name: $directiveName,
                    value: $desiredValue,
                    indent: $this->childIndentFor($existing),
                ));
                $existing->markModified();
                $changed = true;
            } elseif ($directive->value !== $desiredValue) {
                $directive->setValue($desiredValue);
                $existing->markModified();
                $changed = true;
            }
        }
        return $changed;
    }

    public function removeVirtualHost(Document $doc, string $domain): bool
    {
        $block = $this->findVirtualHostBlock($doc, $domain);
        if ($block === null) {
            return false;
        }
        // Also remove any preceding blank line that we inserted with the
        // block to avoid drifting blank line counts on repeated cycles.
        $index = $this->indexOfTopLevel($doc, $block);
        $doc->removeBlock($block);
        if ($index > 0 && isset($doc->children[$index - 1])) {
            $prev = $doc->children[$index - 1];
            if ($prev instanceof BlankLineNode && $prev->isModified()) {
                array_splice($doc->children, $index - 1, 1);
            }
        }
        return true;
    }

    /**
     * Add a `map` directive inside each targeted listener (Default + SSL).
     * Idempotent across both listeners.
     *
     * The map line format that OLS understands is:
     *   map  <vhostName>  <domain> [domain ...]
     *
     * If $includeMail is true we add a second map line for "mail.<domain>"
     * pointing at the same vhost.
     */
    public function upsertListenerMaps(
        Document $doc,
        string $vhostName,
        array $domains,
        bool $includeMail = false
    ): bool {
        $changed = false;
        foreach (self::TARGETED_LISTENERS as $listenerName) {
            foreach ($doc->findAllBlocks('listener') as $listener) {
                if ($listener->args !== $listenerName) {
                    continue;
                }
                // Skip IPv6 listeners regardless of name match.
                if (stripos($listener->args, 'IPv6') !== false) {
                    continue;
                }
                $value = trim($vhostName . ' ' . implode(' ', $domains));
                if ($this->upsertMapDirective($listener, $value)) {
                    $changed = true;
                }
                if ($includeMail) {
                    $mailValue = trim($vhostName . ' mail.' . $vhostName);
                    if ($this->upsertMapDirective($listener, $mailValue)) {
                        $changed = true;
                    }
                }
            }
        }
        return $changed;
    }

    public function removeListenerMaps(Document $doc, string $vhostName): bool
    {
        $changed = false;
        foreach ($doc->findAllBlocks('listener') as $listener) {
            $maps = $listener->findAllChildDirectives('map');
            foreach ($maps as $map) {
                // Match by vhost name (first token of the value). This
                // catches both `map vhost domain` and `map vhost mail.domain`.
                $firstToken = strtok($map->value, " \t");
                if ($firstToken === $vhostName) {
                    $listener->removeChild($map);
                    $changed = true;
                }
            }
        }
        return $changed;
    }

    /**
     * Find a vhost block by domain, tolerating both `virtualhost` and
     * `virtualHost` casing.
     */
    public function findVirtualHostBlock(Document $doc, string $domain): ?BlockNode
    {
        foreach ($doc->findAllBlocks('virtualhost') as $block) {
            if ($block->args === $domain) {
                return $block;
            }
        }
        return null;
    }

    /**
     * Construct the canonical virtualhost block.
     *
     * @param array<string, string> $overrides
     */
    private function buildVirtualHostBlock(string $domain, array $overrides): BlockNode
    {
        $block = new BlockNode(
            name: 'virtualhost',
            args: $domain,
            indent: ''
        );
        $childIndent = '  ';

        // Apply defaults first, then overrides (overrides win).
        $merged = array_merge(self::VHOST_DEFAULTS, $overrides);
        foreach ($merged as $name => $value) {
            $block->addChild(new DirectiveNode($name, $value, $childIndent));
        }
        return $block;
    }

    /**
     * Upsert a `map` directive in a listener block. The matching key is
     * the *exact* value string ("vhost domain1 domain2") so callers that
     * want to add a different combination get a new line rather than
     * silently overwriting an unrelated one.
     */
    private function upsertMapDirective(BlockNode $listener, string $desiredValue): bool
    {
        foreach ($listener->findAllChildDirectives('map') as $existing) {
            if ($existing->value === $desiredValue) {
                return false;
            }
        }

        $newMap = new DirectiveNode(
            name: 'map',
            value: $desiredValue,
            indent: $this->childIndentFor($listener)
        );

        // Insert adjacent to the last existing map directive when present,
        // so all `map` lines stay grouped together. Otherwise append.
        $lastMapIdx = $listener->lastIndexOfDirective('map');
        if ($lastMapIdx >= 0) {
            $listener->insertChildAt($lastMapIdx + 1, $newMap);
        } else {
            $listener->addChild($newMap);
        }
        return true;
    }

    private function childIndentFor(BlockNode $parent): string
    {
        // Children sit one level deeper than the parent header. The OLS
        // default file uses two-space indents per level. We do not infer
        // from the file because the legacy file mixes 2 and 4 spaces.
        return $parent->indent . '  ';
    }

    private function indexOfTopLevel(Document $doc, BlockNode $needle): int
    {
        foreach ($doc->children as $i => $child) {
            if ($child === $needle) {
                return $i;
            }
        }
        return -1;
    }
}
