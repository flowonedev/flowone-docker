<?php

namespace Webmail\Utils;

/**
 * Canonical email-address normalisation.
 *
 * Why this exists
 * ───────────────
 *   Comparing user-entered addresses without normalisation is a footgun:
 *   `Robert@Pixelranger.HU`, `robert@pixelranger.hu` and `Robert@Pixelranger.HU `
 *   are all the same mailbox but compare as three different strings. Worse,
 *   IDN domains (`пример.рф`, `日本.jp`) need Punycode conversion before any
 *   ASCII comparison.
 *
 *   The mention system uses this as the *one* place to canonicalise so the
 *   UNIQUE index in webmail_message_mentions, the @-suggest cache, and the
 *   notification dedup hash all see the same string.
 *
 * Public surface
 * ──────────────
 *   ::normalize(string|null) : ?string
 *       Returns the canonical lowercase Punycode form, or NULL if the input
 *       isn't a parsable address. The trade-off: we are strict enough to
 *       reject obvious junk but lenient on edge cases (missing TLD is OK if
 *       the LHS looks like an address) so we don't drop messages with
 *       slightly-weird-but-valid headers.
 *
 *   ::isValid(string|null) : bool
 *       Wrapper around `filter_var(FILTER_VALIDATE_EMAIL)` on the normalised
 *       form. Use this as the gate before storing/comparing.
 *
 *   ::extractFromHeader(string) : string[]
 *       Pulls every address out of a free-form header line — "Alice
 *       <a@x.y>, \"Bob, Jr.\" <b@x.y>" → ['a@x.y', 'b@x.y']. Used by the
 *       mention parser when scanning headers for sender/recipient context.
 *
 *   ::domainOf(string) : ?string
 *       The lowercase Punycode domain portion, or NULL. Convenience for the
 *       trust-hierarchy check (sender domain vs recipient domain).
 *
 *   ::isSameMailbox(string, string) : bool
 *       Quick equality after normalisation. Use this everywhere the codebase
 *       currently does `strtolower($a) === strtolower($b)`.
 *
 * Out of scope (intentional)
 * ──────────────────────────
 *   - Plus-tag folding (`foo+anything@x.y` → `foo@x.y`). Many providers
 *     don't honour this and folding it would conflate genuinely different
 *     addresses. Mention-equality MUST be exact post-Punycode.
 *   - Gmail dot-folding. Same reason.
 */
final class EmailNormalizer
{
    /**
     * @return string|null Canonical lowercase Punycode form, or NULL if invalid.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) return null;

        // Strip display name and angle brackets:  "Alice <a@x.y>"  →  "a@x.y"
        $raw = trim($raw);
        if ($raw === '') return null;
        if (preg_match('/<([^>]+)>/', $raw, $m)) {
            $raw = $m[1];
        }
        $raw = trim($raw, " \t\r\n\0\x0B<>\"'");

        $at = strrpos($raw, '@');
        if ($at === false || $at === 0 || $at === strlen($raw) - 1) {
            return null;
        }

        $local  = substr($raw, 0, $at);
        $domain = substr($raw, $at + 1);

        // Local part: per RFC 5321 it is technically case-sensitive, but in
        // practice every consumer (Gmail, Outlook, Exchange, Postfix) treats
        // it case-insensitively. Folding to lowercase here lets the UNIQUE
        // index do its job without us ending up with `John@x.y` and
        // `john@x.y` as separate rows.
        $local = strtolower($local);

        // Domain: lowercase + Punycode. idn_to_ascii returns false on bad
        // input; we treat that as "invalid address" so callers get NULL.
        $domain = strtolower($domain);
        if (function_exists('idn_to_ascii')) {
            $ascii = @idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false && $ascii !== '') {
                $domain = $ascii;
            }
        }

        $normalised = $local . '@' . $domain;

        // Final sanity check — anything that still won't validate is junk.
        if (!filter_var($normalised, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        // Length cap matches the VARCHAR(255) columns we store into.
        if (strlen($normalised) > 255) {
            return null;
        }

        return $normalised;
    }

    public static function isValid(?string $raw): bool
    {
        return self::normalize($raw) !== null;
    }

    /**
     * Extract every email address from a free-form header value.
     *
     * Handles:
     *   - Comma-separated lists: "a@x.y, b@x.y"
     *   - Display names with quoted commas: "\"Bob, Jr.\" <b@x.y>"
     *   - Bare addresses: "a@x.y"
     *   - Mixed: "Alice <a@x.y>, b@x.y, \"Bob\" <b2@x.y>"
     *
     * Returns canonical normalised addresses (lowercase + Punycode), with
     * duplicates removed (first occurrence wins for ordering).
     *
     * @return string[]
     */
    public static function extractFromHeader(string $header): array
    {
        if ($header === '') return [];

        // Greedy match for any token that looks like an email. We accept the
        // RFC subset that filter_var blesses; `extractFromHeader` is the
        // intake point, normalize() is the canonicaliser.
        if (!preg_match_all('/[\w.+\-]+@[\w.\-]+\.[a-zA-Z]{2,}/u', $header, $m)) {
            return [];
        }

        $out  = [];
        $seen = [];
        foreach ($m[0] as $addr) {
            $norm = self::normalize($addr);
            if ($norm === null) continue;
            if (isset($seen[$norm])) continue;
            $seen[$norm] = true;
            $out[] = $norm;
        }
        return $out;
    }

    public static function domainOf(?string $raw): ?string
    {
        $norm = self::normalize($raw);
        if ($norm === null) return null;
        $at = strrpos($norm, '@');
        return $at === false ? null : substr($norm, $at + 1);
    }

    public static function isSameMailbox(?string $a, ?string $b): bool
    {
        $na = self::normalize($a);
        $nb = self::normalize($b);
        return $na !== null && $nb !== null && $na === $nb;
    }
}
