<?php

namespace Webmail\Services\Search;

/**
 * Catalog of every search operator the parser understands.
 *
 * Why a registry?
 *   Adding a new operator means listing it here once. The parser uses this to
 *   recognise tokens, the stringifier uses it to know whether to quote values,
 *   the Smart-Views UI uses it to know which operators are "structured" (can
 *   live in filters_json) vs "special" (need a handler) vs "reserved" (parsed
 *   but executed as a no-op for now).
 *
 * Three kinds:
 *   - STANDARD : maps cleanly to the IMAP search criteria already built by
 *                ImapService::parseSearchQuery. Stringified back to operator
 *                syntax and executed through the existing /mailbox/search.
 *   - SPECIAL  : requires DB lookup or domain knowledge; dispatched through
 *                SpecialSearchHandlers, which returns a UID set to intersect.
 *   - RESERVED : known to the parser (so user queries don't break), but the
 *                handler isn't wired up yet. Returns empty results today;
 *                future PRs plug in handlers without touching the parser.
 *
 * Adding a new operator: add one line below. Nothing else to wire.
 */
final class OperatorRegistry
{
    public const KIND_STANDARD = 'standard';
    public const KIND_SPECIAL  = 'special';
    public const KIND_RESERVED = 'reserved';

    /** Operators that take a free-text value (quoted if it contains spaces). */
    public const VALUE_TEXT = 'text';
    /** Operators that take an ISO date (YYYY-MM-DD) or natural language date. */
    public const VALUE_DATE = 'date';
    /** Operators that take an enum from a fixed list (validated at parse time). */
    public const VALUE_ENUM = 'enum';
    /** Operators that take no value (e.g. `has:attachment` — the "attachment" is the enum value, but `has:` is the operator). */
    public const VALUE_NONE = 'none';

    /**
     * @return array<string, array{kind:string, value:string, enum?:string[]}>
     */
    public static function all(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = [
            // ── Standard (map to ImapService::parseSearchQuery today) ──────
            'from'      => ['kind' => self::KIND_STANDARD, 'value' => self::VALUE_TEXT],
            'to'        => ['kind' => self::KIND_STANDARD, 'value' => self::VALUE_TEXT],
            'cc'        => ['kind' => self::KIND_STANDARD, 'value' => self::VALUE_TEXT],
            'bcc'       => ['kind' => self::KIND_STANDARD, 'value' => self::VALUE_TEXT],
            'involves'  => ['kind' => self::KIND_STANDARD, 'value' => self::VALUE_TEXT],
            'subject'   => ['kind' => self::KIND_STANDARD, 'value' => self::VALUE_TEXT],
            'body'      => ['kind' => self::KIND_STANDARD, 'value' => self::VALUE_TEXT],
            'msgid'     => ['kind' => self::KIND_STANDARD, 'value' => self::VALUE_TEXT],
            'label'     => ['kind' => self::KIND_STANDARD, 'value' => self::VALUE_TEXT],
            'after'     => ['kind' => self::KIND_STANDARD, 'value' => self::VALUE_DATE],
            'before'    => ['kind' => self::KIND_STANDARD, 'value' => self::VALUE_DATE],
            'has'       => [
                'kind' => self::KIND_STANDARD,
                'value' => self::VALUE_ENUM,
                'enum' => ['attachment', 'calendar', 'tasks'],
            ],
            'is'        => [
                'kind' => self::KIND_STANDARD,
                'value' => self::VALUE_ENUM,
                // 'unread', 'read', 'starred', 'flagged' map to IMAP flags
                // and are honoured by ImapService::parseSearchQuery.
                //
                // 'pinned' is honoured via a post-filter in
                // MailboxController::search: strip the token from the IMAP
                // query and intersect the result set with rows in the
                // `pinned_emails` DB table.
                // IMAP itself has no notion of "pinned".
                'enum' => ['unread', 'read', 'starred', 'flagged', 'pinned'],
            ],

            // ── Special (handler-dispatched) ───────────────────────────────
            //
            // These don't map to IMAP — they query our own DB (snooze table,
            // etc.) and return a UID set to intersect with IMAP results.
            // Handlers live in SpecialSearchHandlers; reserved here so the
            // parser accepts them even before handlers exist.
            'snoozed'   => ['kind' => self::KIND_SPECIAL,  'value' => self::VALUE_ENUM, 'enum' => ['any', 'today', 'tomorrow', 'this-week', 'next-week']],

            // ── Reserved (future) ──────────────────────────────────────────
            'crm'       => ['kind' => self::KIND_RESERVED, 'value' => self::VALUE_TEXT],   // crm:client_id
            'project'   => ['kind' => self::KIND_RESERVED, 'value' => self::VALUE_TEXT],   // project:slug
            'priority'  => ['kind' => self::KIND_RESERVED, 'value' => self::VALUE_ENUM, 'enum' => ['high', 'medium', 'low']],
        ];

        return $cache;
    }

    public static function has(string $operator): bool
    {
        return isset(self::all()[strtolower($operator)]);
    }

    public static function get(string $operator): ?array
    {
        return self::all()[strtolower($operator)] ?? null;
    }

    public static function kindOf(string $operator): ?string
    {
        $def = self::get($operator);
        return $def['kind'] ?? null;
    }

    /**
     * Validate that $value is acceptable for $operator. Returns true even when
     * the value is empty for VALUE_NONE operators. Used by the parser to
     * surface bad input at parse time rather than at query time.
     */
    public static function isValidValue(string $operator, string $value): bool
    {
        $def = self::get($operator);
        if (!$def) return false;

        switch ($def['value']) {
            case self::VALUE_NONE:
                return $value === '';
            case self::VALUE_ENUM:
                return in_array(strtolower($value), $def['enum'] ?? [], true);
            case self::VALUE_DATE:
                // Accept ISO date or anything strtotime() can handle. ImapService
                // does the strict normalisation later — at parse time we just need
                // to confirm it's plausibly a date.
                if ($value === '') return false;
                return strtotime($value) !== false;
            case self::VALUE_TEXT:
            default:
                return $value !== '';
        }
    }

    /**
     * Whether the operator's results require a special handler (DB lookup,
     * post-filter). Used by the SmartViewsService to decide if it can pass the
     * raw query string straight through to /mailbox/search or whether it has
     * to fan out via SpecialSearchHandlers first.
     */
    public static function isSpecial(string $operator): bool
    {
        return self::kindOf($operator) === self::KIND_SPECIAL;
    }

    public static function isReserved(string $operator): bool
    {
        return self::kindOf($operator) === self::KIND_RESERVED;
    }
}
