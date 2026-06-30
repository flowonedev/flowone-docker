<?php

namespace Webmail\Middleware;

use Webmail\Services\RateLimiter;

/**
 * ApiRateLimiter - General API rate limiting middleware
 *
 * Delegates to Webmail\Services\RateLimiter (shared Lua sliding window).
 */
class ApiRateLimiter
{
    private RateLimiter $limiter;
    private bool $redisOk;

    private const AUTH_LIMIT = 600;
    private const UNAUTH_LIMIT = 40;
    private const WINDOW_SECONDS = 60;
    private const KEY_PREFIX = 'api_rate:';

    private const EXEMPT_PREFIXES = [
        '/auth/',
        '/track/',
        '/calendar/subscribe/',
        '/calendar/invite/',
        '/drive/share/',
        '/drive/folder-share/',
        '/colleagues/avatar/',
        '/guest/call/',
        '/mood-boards/share/',
    ];

    private const EXEMPT_PATTERNS = [
        '#/mood-boards/\d+/uploads/#',
    ];

    private const IP_SAFETY_CAP = 3000;

    private const TRUSTED_PROXIES = [
        '127.0.0.1',
        '::1',
    ];

    public function __construct(array $config)
    {
        $this->limiter = new RateLimiter($config);
        $this->redisOk = $this->limiter->isAvailable();
    }

    public function check(string $requestUri): ?array
    {
        if (!$this->redisOk) {
            return [
                'retry_after' => 30,
                'limit' => 0,
                'remaining' => 0,
            ];
        }

        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if (str_starts_with($requestUri, '/api' . $prefix) || str_starts_with($requestUri, $prefix)) {
                return null;
            }
        }

        foreach (self::EXEMPT_PATTERNS as $pattern) {
            if (preg_match($pattern, $requestUri)) {
                return null;
            }
        }

        $ip = $this->getClientIp();
        $isAuthenticated = !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_GET['token']);
        $limit = $isAuthenticated ? self::AUTH_LIMIT : self::UNAUTH_LIMIT;

        if ($isAuthenticated) {
            $userId = $this->extractUserIdFromJwt();
            $key = $userId
                ? self::KEY_PREFIX . 'user:' . $userId
                : self::KEY_PREFIX . 'auth:' . $ip;
        } else {
            $key = self::KEY_PREFIX . 'unauth:' . $ip;
        }

        try {
            $r = $this->limiter->allow($key, $limit, self::WINDOW_SECONDS);
            if (!$r['allowed']) {
                return [
                    'retry_after' => $r['retry_after'],
                    'limit' => $limit,
                    'remaining' => 0,
                ];
            }

            if ($isAuthenticated) {
                $ipKey = self::KEY_PREFIX . 'ip:' . $ip;
                $member = time() . ':' . mt_rand() . ':ip';
                $ipR = $this->limiter->allow($ipKey, self::IP_SAFETY_CAP, self::WINDOW_SECONDS);
                if (!$ipR['allowed']) {
                    return [
                        'retry_after' => $ipR['retry_after'],
                        'limit' => self::IP_SAFETY_CAP,
                        'remaining' => 0,
                    ];
                }
            }

            return null;
        } catch (\Exception $e) {
            error_log('ApiRateLimiter error: ' . $e->getMessage());
            return [
                'retry_after' => 30,
                'limit' => 0,
                'remaining' => 0,
            ];
        }
    }

    private function extractUserIdFromJwt(): ?string
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $token = $_GET['token'] ?? '';
            if (!$token) {
                return null;
            }
        } else {
            $token = $matches[1];
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = @json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $sub = $payload['sub'] ?? null;

        if (!$sub || !is_string($sub) || strlen($sub) > 254) {
            return null;
        }

        return $sub;
    }

    private function getClientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (in_array($remoteAddr, self::TRUSTED_PROXIES, true)) {
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $clientIp = trim($ips[0]);
                if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                    return $clientIp;
                }
            }

            if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $realIp = trim($_SERVER['HTTP_X_REAL_IP']);
                if (filter_var($realIp, FILTER_VALIDATE_IP)) {
                    return $realIp;
                }
            }
        }

        return $remoteAddr;
    }
}
