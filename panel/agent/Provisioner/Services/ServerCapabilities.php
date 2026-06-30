<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Services;

/**
 * Detect what subsystems are available on this host, so the Provisioner
 * can skip steps whose dependencies are absent and mark the corresponding
 * health bucket as `not_available` rather than `degraded`.
 *
 * Why this exists:
 *   - One day this code may run on a mail-only host that has no OLS.
 *   - Or on a CI/test box without certbot, postfix, or pdns.
 *   - Without capability negotiation every step would either crash or be
 *     wrapped in defensive `if (file_exists(...))` checks, which gets
 *     ugly fast.
 *
 * Detection is cached for the lifetime of the process. Restarting the
 * worker (which we do every 100 jobs anyway via WorkerSupervisor)
 * re-detects, so adding/removing a service doesn't require a manual
 * reload.
 *
 * Each capability is detected by the cheapest reliable signal we can find:
 *   - Binary on PATH (`which`)
 *   - Service running (`systemctl is-active --quiet`)
 *   - Config file or socket present
 *   - In dev, env vars can force-override for tests
 */
final class ServerCapabilities
{
    /** @var array<string, bool>|null */
    private ?array $cache = null;

    public function __construct(
        /** Lets tests pin specific capabilities without touching the host. */
        private readonly array $overrides = []
    ) {
    }

    public function hasOls(): bool        { return $this->check('ols'); }
    public function hasMariadb(): bool    { return $this->check('mariadb'); }
    public function hasPostfix(): bool    { return $this->check('postfix'); }
    public function hasDovecot(): bool    { return $this->check('dovecot'); }
    public function hasOpendkim(): bool   { return $this->check('opendkim'); }
    public function hasPowerdns(): bool   { return $this->check('powerdns'); }
    public function hasCertbot(): bool    { return $this->check('certbot'); }
    public function hasRedis(): bool      { return $this->check('redis'); }
    public function hasNasBackup(): bool  { return $this->check('nas_backup'); }
    public function hasSodium(): bool     { return $this->check('sodium'); }

    /**
     * @return array<string, bool>
     */
    public function snapshot(): array
    {
        if ($this->cache === null) {
            $this->cache = [
                'ols'         => $this->detectOls(),
                'mariadb'     => $this->detectMariadb(),
                'postfix'     => $this->detectPostfix(),
                'dovecot'     => $this->detectDovecot(),
                'opendkim'    => $this->detectOpendkim(),
                'powerdns'    => $this->detectPowerdns(),
                'certbot'     => $this->detectCertbot(),
                'redis'       => $this->detectRedis(),
                'nas_backup'  => $this->detectNasBackup(),
                'sodium'      => extension_loaded('sodium'),
            ];
        }
        // Apply overrides on top (e.g. force `has_mail=false` in tests)
        return array_merge($this->cache, $this->overrides);
    }

    public function has(string $capability): bool
    {
        $snapshot = $this->snapshot();
        return $snapshot[$capability] ?? false;
    }

    /**
     * Force re-detection (e.g. after the operator installed a missing service).
     */
    public function refresh(): void
    {
        $this->cache = null;
    }

    private function check(string $capability): bool
    {
        if (array_key_exists($capability, $this->overrides)) {
            return (bool) $this->overrides[$capability];
        }
        return $this->snapshot()[$capability] ?? false;
    }

    private function detectOls(): bool
    {
        return is_executable('/usr/local/lsws/bin/lswsctrl');
    }

    private function detectMariadb(): bool
    {
        return file_exists('/var/run/mysqld/mysqld.sock')
            || file_exists('/var/lib/mysql/mysql.sock')
            || $this->binaryOnPath('mysql');
    }

    private function detectPostfix(): bool
    {
        return is_dir('/etc/postfix') && $this->binaryOnPath('postfix');
    }

    private function detectDovecot(): bool
    {
        return is_dir('/etc/dovecot') && $this->binaryOnPath('dovecot');
    }

    private function detectOpendkim(): bool
    {
        return is_dir('/etc/opendkim') && $this->binaryOnPath('opendkim');
    }

    private function detectPowerdns(): bool
    {
        return $this->binaryOnPath('pdns_control')
            || $this->binaryOnPath('pdnsutil');
    }

    private function detectCertbot(): bool
    {
        return $this->binaryOnPath('certbot');
    }

    private function detectRedis(): bool
    {
        // We don't require the PhpRedis extension yet (DB locks for v1),
        // but if Redis is reachable, downstream Redlock-backed services can use it.
        $sock = '/var/run/redis/redis-server.sock';
        if (file_exists($sock)) {
            return true;
        }
        $conn = @stream_socket_client('tcp://127.0.0.1:6379', $errno, $errstr, 0.5);
        if ($conn !== false) {
            fclose($conn);
            return true;
        }
        return false;
    }

    private function detectNasBackup(): bool
    {
        // Matches the existing NasHealthCheck mount path.
        return is_dir('/mnt/nas-drive') && is_writable('/mnt/nas-drive');
    }

    private function binaryOnPath(string $binary): bool
    {
        $output = [];
        $exit = 1;
        // PHP_OS prevents accidental Windows attempts during local dev.
        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            return false;
        }
        @exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null', $output, $exit);
        return $exit === 0 && !empty($output);
    }
}
