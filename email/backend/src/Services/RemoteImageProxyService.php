<?php

namespace Webmail\Services;

/**
 * Proxies remote email images through the server to protect user privacy.
 * Prevents sender from seeing user's IP, referrer, or access timing.
 * Includes SSRF protections against internal network access.
 */
class RemoteImageProxyService
{
    private const MAX_RESPONSE_SIZE = 10 * 1024 * 1024; // 10 MB
    private const TIMEOUT_SECONDS = 10;
    private const MAX_REDIRECTS = 3;

    private const BLOCKED_PORTS = [
        21, 22, 23, 25, 53, 110, 143, 445, 587, 993, 995, 3306, 5432, 6379, 11211, 27017,
    ];

    private const ALLOWED_CONTENT_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
        'image/bmp', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/avif',
    ];

    /**
     * Neutral gray placeholder served when a remote fetch fails (expired CDN
     * signatures, dead hosts...). Returning an image instead of an error
     * keeps the browser from rendering huge alt texts that destroy layouts.
     */
    public static function placeholderSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 80" width="100" height="80">'
            . '<rect width="100" height="80" fill="#e5e7eb"/>'
            . '<path d="M35 50 L50 35 L65 50 L55 50 L55 55 L45 55 L45 50 Z" fill="#9ca3af"/>'
            . '<circle cx="65" cy="30" r="8" fill="#9ca3af"/>'
            . '</svg>';
    }

    /**
     * Validate and fetch a remote image, returning the binary data and content type.
     * Returns ['data' => string, 'content_type' => string, 'cache_seconds' => int] on success.
     * Throws \RuntimeException on failure.
     */
    public function fetch(string $url): array
    {
        $this->validateUrl($url);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'FlowOne-ImageProxy/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: image/*',
                'Referer: ',
            ],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $downloadSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);

        if ($data === false || $error) {
            throw new \RuntimeException('Image fetch failed: ' . ($error ?: 'unknown error'));
        }

        if ($httpCode < 200 || $httpCode >= 400) {
            throw new \RuntimeException("Image fetch returned HTTP {$httpCode}");
        }

        if ($downloadSize > self::MAX_RESPONSE_SIZE) {
            throw new \RuntimeException('Image exceeds maximum allowed size');
        }

        if ($effectiveUrl) {
            $this->validateUrl($effectiveUrl);
        }

        $contentType = $this->normalizeContentType($contentType);
        if (!in_array($contentType, self::ALLOWED_CONTENT_TYPES, true)) {
            throw new \RuntimeException("Disallowed content type: {$contentType}");
        }

        return [
            'data' => $data,
            'content_type' => $contentType,
            'cache_seconds' => 86400,
        ];
    }

    private function validateUrl(string $url): void
    {
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            throw new \RuntimeException('Invalid URL');
        }

        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Only http/https URLs are allowed');
        }

        $port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (in_array((int) $port, self::BLOCKED_PORTS, true)) {
            throw new \RuntimeException('Blocked port');
        }

        $host = strtolower($parsed['host']);
        if ($this->isPrivateHost($host)) {
            throw new \RuntimeException('Private/internal hosts are not allowed');
        }

        $ips = gethostbynamel($host);
        if ($ips) {
            foreach ($ips as $ip) {
                if ($this->isPrivateIp($ip)) {
                    throw new \RuntimeException('URL resolves to a private IP');
                }
            }
        }
    }

    private function isPrivateHost(string $host): bool
    {
        $blocked = ['localhost', '127.0.0.1', '::1', '0.0.0.0', '[::1]'];
        if (in_array($host, $blocked, true)) {
            return true;
        }
        if (str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }
        return false;
    }

    private function isPrivateIp(string $ip): bool
    {
        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    private function normalizeContentType(?string $contentType): string
    {
        if (!$contentType) {
            return 'application/octet-stream';
        }
        $parts = explode(';', $contentType);
        return strtolower(trim($parts[0]));
    }
}
