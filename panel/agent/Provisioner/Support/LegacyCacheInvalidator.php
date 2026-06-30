<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Support;

/**
 * Busts the legacy v1 Redis cache keys that the legacy SiteController
 * + SitesView depend on, so a v2 saga's mutation is reflected in the
 * legacy UI immediately rather than after the 60s TTL.
 *
 * Keys we invalidate (matches `panel/api/src/Services/CacheService.php`):
 *   - vps:sites:list                      legacy list view
 *   - vps:site:<domain>                   legacy detail view
 *   - vps:dns:<domain>                    DNS records list
 *   - vps:ssl:<domain>                    SSL certificate state
 *   - vps:mail:<domain>                   mail accounts/forwards
 *
 * Why an agent-side invalidator and not "just call CacheService":
 *   The HTTP-side CacheService extends a Container-bound singleton,
 *   wired through the request lifecycle. The agent / worker daemon
 *   doesn't have that container, so we open a direct Redis connection
 *   on demand. Connection is short-lived (open + DEL + close) so we
 *   don't accumulate handles across saga runs.
 *
 * Configuration:
 *   Reads `panel/api/config.php` (and config.local.php overlay) for
 *   the same Redis settings the legacy uses, so prefix + auth + db
 *   selection match. Failures are logged but never throw - the
 *   saga's terminal landing must not depend on Redis being up.
 *
 * Optional dependency:
 *   When ext-redis is missing on the worker host (rare; both
 *   panel-api and agent ship the extension), invalidation is a
 *   silent no-op and the legacy UI stays stale until its TTL.
 */
final class LegacyCacheInvalidator
{
    /**
     * @param string $host       Redis host (default 127.0.0.1).
     * @param int    $port       Redis port (default 6379).
     * @param ?string $password  Redis AUTH password (null = no auth).
     * @param int    $database   Redis DB index (default 0).
     * @param float  $timeout    Connect timeout in seconds.
     * @param string $prefix     Key prefix (default 'vps:' to match
     *                           legacy CacheService).
     */
    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        private readonly ?string $password = null,
        private readonly int $database = 0,
        private readonly float $timeout = 1.5,
        private readonly string $prefix = 'vps:',
    ) {
    }

    /**
     * Build an invalidator from the panel api/config.php so prefix /
     * auth / db settings stay in sync with the legacy cache writer.
     */
    public static function fromDefaultConfigFiles(): self
    {
        $configFile = '/var/www/vps-admin/api/config.php';
        $localConfigFile = '/var/www/vps-admin/api/config.local.php';

        if (!file_exists($configFile)) {
            return new self();
        }

        $config = require $configFile;
        if (file_exists($localConfigFile)) {
            $localConfig = require $localConfigFile;
            $config = array_replace_recursive($config, $localConfig);
        }

        $r = $config['redis'] ?? [];
        return new self(
            host: (string) ($r['host'] ?? '127.0.0.1'),
            port: (int) ($r['port'] ?? 6379),
            password: $r['password'] !== null && $r['password'] !== ''
                ? (string) $r['password'] : null,
            database: (int) ($r['database'] ?? 0),
            timeout: (float) ($r['timeout'] ?? 1.5),
            prefix: (string) ($r['prefix'] ?? 'vps:'),
        );
    }

    /**
     * Invalidate every legacy cache key that mentions $domain plus
     * the global sites:list. Exceptions are logged and swallowed.
     *
     * @return int Number of keys actually deleted (best-effort count).
     */
    public function invalidateForDomain(string $domain): int
    {
        if (!extension_loaded('redis') || !class_exists('\Redis')) {
            return 0;
        }

        $redis = new \Redis();
        try {
            $connected = @$redis->connect($this->host, $this->port, $this->timeout);
            if (!$connected) {
                return 0;
            }
            if ($this->password !== null) {
                $redis->auth($this->password);
            }
            if ($this->database !== 0) {
                $redis->select($this->database);
            }

            $keys = [
                $this->prefix . 'sites:list',
                $this->prefix . 'site:' . $domain,
                $this->prefix . 'dns:' . $domain,
                $this->prefix . 'ssl:' . $domain,
                $this->prefix . 'mail:' . $domain,
            ];

            $deleted = 0;
            foreach ($keys as $k) {
                $r = $redis->del($k);
                if (is_int($r)) {
                    $deleted += $r;
                }
            }
            return $deleted;
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[LegacyCacheInvalidator] redis del for domain=%s failed: %s',
                $domain,
                $e->getMessage(),
            ));
            return 0;
        } finally {
            try { $redis->close(); } catch (\Throwable) {}
        }
    }
}
