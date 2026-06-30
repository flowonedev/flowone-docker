<?php

namespace Webmail\Services\SmartViews;

/**
 * Validate + normalise the `filters_json` payload before it hits the DB.
 *
 * Why this exists:
 *   The frontend can post any JSON it wants. Without a whitelist we'd be
 *   storing arbitrary structures that break the UI later, get reflected back
 *   in API responses, or smuggle in operators the parser doesn't understand.
 *
 * Contract:
 *   - Only recognised keys survive (everything else is silently dropped).
 *   - Each key has a strict type (bool, string, string[]).
 *   - String values are length-capped (anti-DOS).
 *   - Returns the cleaned structure suitable for json_encode + storage.
 *   - Throws \InvalidArgumentException only if the input is not an array at
 *     all (callers should pass [] when filters_json is omitted).
 *
 * Schema version: see webmail_smart_views.schema_version. Bump it whenever
 * this normaliser's output shape changes, and add a migration block that
 * upgrades older rows on read.
 */
final class FiltersNormalizer
{
    public const SCHEMA_VERSION = 1;

    private const MAX_STRING_LEN = 256;
    private const MAX_LABELS      = 32;
    private const MAX_LABEL_LEN   = 64;

    /** Allowed keys + their expected PHP type. */
    private const SCHEMA = [
        'from'          => 'string',
        'to'            => 'string',
        'cc'            => 'string',
        'subject'       => 'string',
        'body'          => 'string',
        'hasAttachment' => 'bool',
        'isUnread'      => 'bool',
        'isStarred'     => 'bool',
        'afterDate'     => 'string',   // YYYY-MM-DD; normalised by the parser
        'beforeDate'    => 'string',
        'labels'        => 'array',    // string[] of label names
        // Special (handler-resolved) — recognised so the UI can chip them.
        // The actual handler dispatch is decided at execution time, not here.
        'mentionsMe'    => 'bool',
        'snoozed'       => 'string',   // 'any' | 'today' | 'tomorrow' | …
    ];

    /**
     * @param  mixed $raw
     * @return array{filters: array<string, mixed>, schema_version: int}
     */
    public static function normalize(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return ['filters' => [], 'schema_version' => self::SCHEMA_VERSION];
        }

        // Accept JSON string OR already-decoded array.
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('filters_json is not valid JSON: ' . json_last_error_msg());
            }
            $raw = $decoded;
        }

        if (!is_array($raw)) {
            throw new \InvalidArgumentException('filters_json must be a JSON object');
        }

        $out = [];
        foreach (self::SCHEMA as $key => $type) {
            if (!array_key_exists($key, $raw)) continue;
            $value = $raw[$key];
            $clean = self::coerce($key, $type, $value);
            if ($clean !== null) {
                $out[$key] = $clean;
            }
        }

        return ['filters' => $out, 'schema_version' => self::SCHEMA_VERSION];
    }

    private static function coerce(string $key, string $type, mixed $value): mixed
    {
        switch ($type) {
            case 'bool':
                if (is_bool($value)) return $value;
                if (is_string($value)) {
                    $v = strtolower($value);
                    if ($v === 'true' || $v === '1')  return true;
                    if ($v === 'false' || $v === '0' || $v === '') return false;
                }
                if (is_int($value)) return $value !== 0;
                return null;

            case 'string':
                if (!is_string($value)) return null;
                $trimmed = trim($value);
                if ($trimmed === '') return null;
                if (mb_strlen($trimmed) > self::MAX_STRING_LEN) {
                    $trimmed = mb_substr($trimmed, 0, self::MAX_STRING_LEN);
                }
                return $trimmed;

            case 'array':
                if (!is_array($value)) return null;
                if ($key === 'labels') {
                    $cleaned = [];
                    foreach ($value as $label) {
                        if (!is_string($label)) continue;
                        $l = trim($label);
                        if ($l === '') continue;
                        if (mb_strlen($l) > self::MAX_LABEL_LEN) {
                            $l = mb_substr($l, 0, self::MAX_LABEL_LEN);
                        }
                        $cleaned[] = $l;
                        if (count($cleaned) >= self::MAX_LABELS) break;
                    }
                    return $cleaned ?: null;
                }
                return null;
        }
        return null;
    }
}
