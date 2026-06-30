<?php

namespace Webmail\Services\Mentions;

use Webmail\Utils\EmailNormalizer;

/**
 * Extracts @mention tokens from an email body.
 *
 * Four recognition modes (combined, results deduped by canonical address):
 *
 * 1. **TipTap mention spans** — what our compose UI produces:
 *
 *      <span data-type="mention"
 *            data-id="robert@pixelranger.hu"
 *            data-label="Robert">@Robert</span>
 *
 *    This is the *trusted* path. The address came from our suggest popup,
 *    so we have high confidence it's a real mailbox.
 *
 * 2. **mailto-anchored chip** — Gmail's `gmail_plusreply` mention and the
 *    equivalent Outlook Web chip both serialise as:
 *
 *      <a href="mailto:robert@pixelranger.hu">@Fekete Róbert</a>
 *
 *    The address is in the `href` so resolution is exact — no hint pool
 *    needed. This is THE most common real-world mention format because
 *    Gmail is the dominant sender and it doesn't honour the TipTap span.
 *
 * 3. **Plain-text `@Display Name <email@domain>`** — what Gmail produces
 *    when MIME generates the text/plain part from its rich-text mention:
 *
 *      "hey @Fekete Róbert <robert@pixelranger.hu> what sup"
 *
 *    The angle-bracketed address is authoritative.
 *
 * 4. **Plain-text bare `@token`** — fallback for plain replies:
 *
 *      "yes @robert@pixelranger.hu can you review"
 *      "yes @robert"   ← name-only, requires hint resolution
 *
 *    The bare `@email@domain` form is recognised directly. The bare
 *    `@token` form (no domain) is recognised but NOT resolved here —
 *    callers pass a `$hints` map (e.g. To+Cc+Bcc address list plus
 *    colleagues + recent contacts) and we match `@robert` against
 *    `robert@*` in the hints. If a hint matches uniquely (or uniquely
 *    within the preferred domain), we treat it as a mention; otherwise
 *    it's discarded as noise.
 *
 * Why parser-not-tokenizer:
 *   The body is short (< 100 KB typical), single-pass regex is plenty fast,
 *   and we don't need positional info for downstream consumers. Keep it
 *   simple.
 *
 * Returns:
 *   array<int, array{
 *       email:       string,  // canonical normalised
 *       label:       string,  // display label (best-effort)
 *       text:        string,  // raw @-token as found (`@Robert`, `@robert@x.y`)
 *       source:      string,  // 'tiptap' | 'plain'
 *   }>
 */
final class MentionParser
{
    private const MAX_BODY_BYTES = 256 * 1024; // refuse to scan absurdly large bodies

    /**
     * @param string|null $html  HTML body (preferred; carries mention spans)
     * @param string|null $text  Plain-text fallback / additional source
     * @param string[]    $hints Canonical addresses already known (To+Cc+Bcc
     *                           plus optional owner-level expansion:
     *                           colleagues + contacts). Used to resolve
     *                           bare `@firstname` tokens.
     * @param array $opts        ['preferDomain' => 'pixelranger.hu'] — when
     *                           a bare token matches multiple hints, prefer
     *                           the one(s) in this domain. If exactly one
     *                           match remains after the filter, use it.
     *                           Falls back to the old strict-single-match
     *                           rule when no domain is passed.
     * @return array<int, array{email:string,label:string,text:string,source:string}>
     */
    public static function extract(?string $html, ?string $text = null, array $hints = [], array $opts = []): array
    {
        $found = [];
        $seen = []; // canonical email → true (dedup across both passes)

        $hintMap = self::buildHintMap($hints);
        $preferDomain = isset($opts['preferDomain']) && is_string($opts['preferDomain'])
            ? strtolower($opts['preferDomain'])
            : null;

        if ($html !== null && $html !== '' && strlen($html) <= self::MAX_BODY_BYTES) {
            // Order matters: TipTap (our own) → mailto anchors (Gmail/Outlook)
            // → plain-text fallback. Each pass that matches with high
            // confidence (TipTap, mailto) populates `$seen` so the lossy
            // plain-text pass doesn't re-attempt them.
            self::scanTipTapSpans($html, $found, $seen);
            self::scanMailtoAnchors($html, $found, $seen);
            // Strip tags and run the plain-text pass over the residual text
            // so mentions that lost their span (e.g. user pasted from a
            // different client) are still picked up.
            $stripped = self::stripHtml($html);
            self::scanPlain($stripped, $hintMap, $found, $seen, 'plain', $preferDomain);
        }

        if ($text !== null && $text !== '' && strlen($text) <= self::MAX_BODY_BYTES) {
            self::scanPlain($text, $hintMap, $found, $seen, 'plain', $preferDomain);
        }

        return array_values($found);
    }

    /**
     * Build a fast prefix map from canonical addresses.
     * Used to resolve `@robert` → `robert@pixelranger.hu` when only one
     * recipient matches that local-part.
     *
     * @param string[] $hints
     * @return array<string, string[]>  local-part → array of canonical addresses
     */
    private static function buildHintMap(array $hints): array
    {
        $map = [];
        foreach ($hints as $addr) {
            $norm = EmailNormalizer::normalize($addr);
            if ($norm === null) continue;
            $at = strrpos($norm, '@');
            if ($at === false) continue;
            $local = substr($norm, 0, $at);
            if (!isset($map[$local])) $map[$local] = [];
            if (!in_array($norm, $map[$local], true)) {
                $map[$local][] = $norm;
            }
        }
        return $map;
    }

    private static function scanTipTapSpans(string $html, array &$found, array &$seen): void
    {
        // Match: <span data-type="mention" … data-id="email@domain" … >@Label</span>
        // The attribute order is not guaranteed (TipTap can emit them in either
        // order, and downstream HTML reflow may reorder them), so we capture
        // attributes individually with non-greedy lookups.
        $pattern = '/<span\b[^>]*data-type\s*=\s*["\']mention["\'][^>]*>(.*?)<\/span>/is';
        if (!preg_match_all($pattern, $html, $tags, PREG_SET_ORDER)) return;

        foreach ($tags as $tag) {
            $full  = $tag[0];
            $inner = trim(strip_tags($tag[1]));
            if (preg_match('/data-id\s*=\s*["\']([^"\']+)["\']/i', $full, $idm)) {
                $email = EmailNormalizer::normalize($idm[1]);
                if ($email === null || isset($seen[$email])) continue;
                $label = '';
                if (preg_match('/data-label\s*=\s*["\']([^"\']+)["\']/i', $full, $lm)) {
                    $label = $lm[1];
                }
                if ($label === '') $label = $inner ?: $email;
                $seen[$email] = true;
                $found[$email] = [
                    'email'  => $email,
                    'label'  => $label,
                    'text'   => $inner ?: ('@' . $email),
                    'source' => 'tiptap',
                ];
            }
        }
    }

    /**
     * Match Gmail `gmail_plusreply` chips and Outlook Web's equivalent
     * mailto-anchored mention HTML:
     *
     *   <a class="gmail_plusreply" href="mailto:robert@pixelranger.hu">@Fekete Róbert</a>
     *   <a href="mailto:robert@pixelranger.hu">@Robert</a>
     *
     * Requirements:
     *   - Must be an <a> with href="mailto:..."
     *   - Inner text MUST start with `@` (otherwise this is just a normal
     *     mailto link in the body, not a mention).
     *
     * We don't require the `gmail_plusreply` class because Outlook Web,
     * Apple Mail, and Yandex all use the same convention without the class.
     */
    private static function scanMailtoAnchors(string $html, array &$found, array &$seen): void
    {
        $pattern = '/<a\b[^>]*\bhref\s*=\s*["\']mailto:([^"\']+)["\'][^>]*>(.*?)<\/a>/is';
        if (!preg_match_all($pattern, $html, $tags, PREG_SET_ORDER)) return;

        foreach ($tags as $tag) {
            $rawEmail = $tag[1] ?? '';
            // The href may carry RFC 2368 parameters (?subject=…&body=…) —
            // strip everything after the first '?' before normalising.
            $q = strpos($rawEmail, '?');
            if ($q !== false) $rawEmail = substr($rawEmail, 0, $q);

            $inner = html_entity_decode(strip_tags($tag[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $inner = trim($inner);
            // Mention requirement: inner text begins with '@'. A plain link
            // like <a href="mailto:foo@bar">contact us</a> is NOT a mention.
            if ($inner === '' || $inner[0] !== '@') continue;

            $email = EmailNormalizer::normalize($rawEmail);
            if ($email === null || isset($seen[$email])) continue;
            $label = ltrim($inner, '@');
            if ($label === '') $label = $email;

            $seen[$email] = true;
            $found[$email] = [
                'email'  => $email,
                'label'  => $label,
                'text'   => $inner,
                'source' => 'mailto',
            ];
        }
    }

    private static function scanPlain(string $text, array $hintMap, array &$found, array &$seen, string $source, ?string $preferDomain = null): void
    {
        // Pass A1: "@Display Name <email@domain>" — what Gmail emits in the
        // text/plain MIME part when the rich body had a mention chip. Must
        // come BEFORE Pass A2 (bare @email@domain) because A2 would otherwise
        // consume the angle-bracket-wrapped email as a bare hit, losing the
        // display name.
        if (preg_match_all('/(?<![\w@])@([^<\n\r]{1,80}?)\s*<([\w.+\-]+@[\w.\-]+\.[a-zA-Z]{2,})>/u', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $label = trim($hit[1] ?? '');
                $email = EmailNormalizer::normalize($hit[2] ?? '');
                if ($email === null || isset($seen[$email])) continue;
                $seen[$email] = true;
                $found[$email] = [
                    'email'  => $email,
                    'label'  => $label !== '' ? $label : $email,
                    'text'   => '@' . $label . ' <' . $email . '>',
                    'source' => $source,
                ];
            }
        }

        // Pass A2: explicit `@email@domain` form. Anchor with a non-word char
        // boundary so we don't accidentally match the local-part of an
        // address that someone pasted ("see foo@bar.com" must NOT produce a
        // mention; "@foo@bar.com" must).
        if (preg_match_all('/(?<![\w@])@([\w.+\-]+@[\w.\-]+\.[a-zA-Z]{2,})/u', $text, $m)) {
            foreach ($m[1] as $raw) {
                $email = EmailNormalizer::normalize($raw);
                if ($email === null || isset($seen[$email])) continue;
                $seen[$email] = true;
                $found[$email] = [
                    'email'  => $email,
                    'label'  => $email,
                    'text'   => '@' . $raw,
                    'source' => $source,
                ];
            }
        }

        // Pass B: bare `@token` form — resolved via hints only.
        // We exclude the explicit forms (already consumed by A1/A2 above)
        // because $seen contains the resolved email; the regex below can
        // re-fire on "@Fekete" parts but the address-level dedup at the
        // top of each match keeps us idempotent.
        if (preg_match_all('/(?<![\w@])@([a-z0-9._\-]{2,64})\b(?!@)/iu', $text, $m)) {
            foreach ($m[1] as $token) {
                $tokLower = strtolower($token);
                $matches = $hintMap[$tokLower] ?? [];
                if (empty($matches)) continue;

                // Domain bias: if multiple hints share a local-part (very
                // common once we expand hints with colleagues + contacts),
                // prefer the one in the recipient's organisation. This lets
                // Gmail-style "@robert" resolve to robert@<owner-domain>
                // even when an external robert@vendor.com exists too.
                $email = null;
                if (count($matches) > 1 && $preferDomain !== null) {
                    $internal = array_values(array_filter(
                        $matches,
                        static fn($a) => str_ends_with($a, '@' . $preferDomain)
                    ));
                    if (count($internal) === 1) {
                        $email = $internal[0];
                    }
                } elseif (count($matches) === 1) {
                    $email = $matches[0];
                }

                if ($email === null) continue; // still ambiguous → drop silently
                if (isset($seen[$email])) continue;
                $seen[$email] = true;
                $found[$email] = [
                    'email'  => $email,
                    'label'  => $token,
                    'text'   => '@' . $token,
                    'source' => $source,
                ];
            }
        }
    }

    private static function stripHtml(string $html): string
    {
        // Replace block-level tags with newlines so adjacent text doesn't
        // run together (would create spurious cross-tag matches).
        $html = preg_replace('#<(br|/p|/div|/li|/td|/tr)\b[^>]*>#i', "\n", $html);
        $html = strip_tags((string) $html);
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
