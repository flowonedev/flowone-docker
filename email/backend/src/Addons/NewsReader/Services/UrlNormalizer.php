<?php

namespace Webmail\Addons\NewsReader\Services;

/**
 * Normalize feed URLs to a canonical form to avoid duplicate subscriptions
 * (http/https, trailing slash, utm params, case on host).
 */
class UrlNormalizer
{
    /**
     * @return array{canonical: string, hash: string}
     */
    public static function normalizeFeedUrl(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['canonical' => '', 'hash' => sha1('')];
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            // Fallback: lowercase and strip whitespace
            $canonical = preg_replace('/\s+/', '', strtolower($url));
            return ['canonical' => $canonical, 'hash' => sha1($canonical)];
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        if ($scheme === 'http') {
            $scheme = 'https';
        }
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';
        $path = rtrim($path, '/') ?: '/';

        $query = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $q);
            foreach (array_keys($q) as $k) {
                if (stripos($k, 'utm_') === 0) {
                    unset($q[$k]);
                }
            }
            if ($q !== []) {
                ksort($q);
                $query = '?' . http_build_query($q);
            }
        }

        $canonical = $scheme . '://' . $host . $path . $query;
        if (!empty($parts['fragment'])) {
            $canonical .= '#' . $parts['fragment'];
        }

        return ['canonical' => $canonical, 'hash' => sha1($canonical)];
    }
}
