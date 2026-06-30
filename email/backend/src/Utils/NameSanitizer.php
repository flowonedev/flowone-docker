<?php

namespace Webmail\Utils;

/**
 * Sanitize guest display names before DB / LiveKit / email.
 */
final class NameSanitizer
{
    private const MAX_LEN = 60;

    public static function sanitize(string $raw): string
    {
        $s = trim(strip_tags($raw));
        // Strip control chars, BOM, bidi overrides
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u', '', $s) ?? '';
        $s = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $s) ?? '';
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
            if ($n !== false) {
                $s = $n;
            }
        }
        $s = preg_replace('/\s+/u', ' ', $s) ?? '';
        $s = mb_substr($s, 0, self::MAX_LEN, 'UTF-8');
        $s = trim($s);
        return $s === '' ? 'Guest' : $s;
    }
}
