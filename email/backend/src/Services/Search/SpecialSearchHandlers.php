<?php

namespace Webmail\Services\Search;

use Webmail\Services\Search\Ast\OperatorNode;

/**
 * Registry for "special" search operators that can't be expressed as plain
 * IMAP criteria (e.g. `is:snoozed`, `mentions:me`).
 *
 * Each handler is a callable:
 *   function (string $email, string $value, array $context): array
 *     - returns ['uids' => int[], 'folders' => string[]?]
 *       where `uids` are message UIDs to intersect with the IMAP result set
 *       and `folders` (optional) lets the handler scope itself to specific
 *       IMAP folders.
 *
 * Phase 2 ships the registry + stubs that return empty arrays. Real handlers
 * land in Phase 3 (mentions) and a later snooze PR. The crucial property:
 * adding a handler tomorrow doesn't require touching the parser, the route,
 * or any controller — just register it here.
 *
 * Why a class instead of a static map?
 *   - Lets us inject `$config` and lazy-build dependencies (DB connections)
 *     only when a handler actually fires. Smart Views that only use standard
 *     IMAP operators pay zero cost for the special-handler path.
 */
final class SpecialSearchHandlers
{
    /** @var array<string, callable> */
    private array $handlers = [];

    public function __construct(private readonly array $config)
    {
        $this->registerDefaults();
    }

    /**
     * Register a handler for a special operator (e.g. 'mentions', 'snoozed').
     * Re-registering an existing operator overrides the previous handler.
     */
    public function register(string $operator, callable $handler): void
    {
        $this->handlers[strtolower($operator)] = $handler;
    }

    public function has(string $operator): bool
    {
        return isset($this->handlers[strtolower($operator)]);
    }

    /**
     * Resolve a special OperatorNode to a result set. Returns null when no
     * handler is registered (caller decides whether to ignore or short-circuit
     * the whole query).
     *
     * Result shape (any subset OK):
     *   uids:        int[]      IMAP UIDs to intersect with the result set
     *   message_ids: string[]   canonical RFC 5322 Message-IDs (no <>) — used
     *                           when UID isn't known (mentions case)
     *   folders:     string[]   optional folder-scope hint
     *
     * @return array{uids?:int[], message_ids?:string[], folders?:string[]}|null
     */
    public function resolve(OperatorNode $node, string $email, array $context = []): ?array
    {
        $key = strtolower($node->operator);
        if (!isset($this->handlers[$key])) return null;
        return ($this->handlers[$key])($email, $node->value, $context);
    }

    /**
     * Default handlers.
     *
     * `snoozed` is still a stub (will be filled in by a later snooze PR).
     */
    private function registerDefaults(): void
    {
        $this->register('snoozed', static fn(string $email, string $value, array $ctx): array
            => ['uids' => []]);
    }
}
