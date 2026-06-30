<?php

namespace FleetManager\Agent\Lib;

/**
 * Update Scanner - Detects outdated OS packages (apt/dnf) and outdated npm
 * dependencies of the fleet-managed Node services (collab, mailsync).
 *
 * Results are cached on disk so the 30s heartbeat stays cheap; a full rescan
 * (which runs `apt-get update`) happens at most once per CACHE_TTL, or when
 * an update task forces a refresh.
 *
 * Used by heartbeat.php (require_once) and by TaskAction via the agent daemon.
 */
class UpdateScanner
{
    public const CACHE_TTL = 3600;        // seconds between automatic rescans
    private const LOCK_STALE = 900;       // consider a refresh lock dead after 15 min
    private const MAX_PACKAGES = 200;     // cap reported package lists

    /** Candidate npm app dirs -> systemd service to restart after npm updates */
    public const NPM_APPS = [
        '/var/www/vps-email/collab/server' => 'collab-server',
        '/var/www/email/collab/server' => 'collab-server',
        '/opt/collab-server' => 'collab-server',
        '/var/www/vps-email/mailsync' => 'mailsync-server',
        '/var/www/email/mailsync' => 'mailsync-server',
    ];

    public static function cachePath(): string
    {
        return dirname(__DIR__) . '/cache/updates.json';
    }

    private static function lockPath(): string
    {
        return dirname(__DIR__) . '/cache/updates.refresh.lock';
    }

    /**
     * Return the cached report, refreshing it when expired.
     * Never throws; returns null only when no data could be collected at all.
     */
    public function scan(bool $force = false): ?array
    {
        $cached = $this->readCache();

        if (!$force && $cached !== null) {
            $age = time() - strtotime($cached['checked_at'] ?? '1970-01-01');
            if ($age < self::CACHE_TTL) {
                return $cached;
            }
        }

        // Another heartbeat may already be refreshing; serve stale data meanwhile.
        $lock = self::lockPath();
        if (file_exists($lock) && (time() - (int)filemtime($lock)) < self::LOCK_STALE) {
            return $cached;
        }

        @mkdir(dirname($lock), 0755, true);
        @touch($lock);
        try {
            return $this->refresh();
        } catch (\Throwable $e) {
            error_log('Fleet UpdateScanner: refresh failed: ' . $e->getMessage());
            return $cached;
        } finally {
            @unlink($lock);
        }
    }

    /**
     * Force a full rescan and persist it to the cache file.
     */
    public function refresh(): array
    {
        $report = [
            'checked_at' => date('c'),
            'os' => $this->scanOs(),
            'npm' => $this->scanNpm(),
        ];

        $cache = self::cachePath();
        @mkdir(dirname($cache), 0755, true);
        @file_put_contents($cache, json_encode($report));

        return $report;
    }

    /**
     * Drop the cache so the next heartbeat rescans (used after applying updates).
     */
    public static function invalidate(): void
    {
        @unlink(self::cachePath());
    }

    /**
     * Existing npm app dirs (must contain package.json), mapped to their service.
     */
    public static function npmDirs(): array
    {
        $found = [];
        foreach (self::NPM_APPS as $dir => $service) {
            if (is_dir($dir) && file_exists($dir . '/package.json')) {
                $found[$dir] = $service;
            }
        }
        return $found;
    }

    public static function packageManager(): ?string
    {
        if (self::commandExists('apt-get')) {
            return 'apt';
        }
        if (self::commandExists('dnf')) {
            return 'dnf';
        }
        return null;
    }

    // ---------------------------------------------------------------- OS scan

    private function scanOs(): array
    {
        $manager = self::packageManager();
        $result = [
            'manager' => $manager,
            'packages' => [],
            'count' => 0,
            'reboot_required' => file_exists('/var/run/reboot-required'),
        ];

        if ($manager === 'apt') {
            // Refresh package lists quietly; tolerate failures (offline mirror etc.)
            @shell_exec('timeout 120 apt-get update -qq 2>/dev/null');

            $out = @shell_exec("apt list --upgradable 2>/dev/null") ?: '';
            foreach (explode("\n", $out) as $line) {
                $pkg = self::parseAptLine($line);
                if ($pkg !== null) {
                    $result['packages'][] = $pkg;
                }
            }
        } elseif ($manager === 'dnf') {
            // Exit code 100 means updates available
            $out = @shell_exec('timeout 120 dnf -q check-update 2>/dev/null') ?: '';
            foreach (explode("\n", $out) as $line) {
                $pkg = self::parseDnfLine($line);
                if ($pkg !== null) {
                    $result['packages'][] = $pkg;
                }
            }
        }

        $result['count'] = count($result['packages']);
        $result['packages'] = array_slice($result['packages'], 0, self::MAX_PACKAGES);

        return $result;
    }

    /**
     * Parse one `apt list --upgradable` line, e.g.:
     *   nginx/jammy-updates 1.18.0-6ubuntu14.4 amd64 [upgradable from: 1.18.0-6ubuntu14.3]
     */
    public static function parseAptLine(string $line): ?array
    {
        if (preg_match('#^([^/\s]+)/\S+\s+(\S+)\s+\S+\s+\[upgradable from:\s*([^\]]+)\]#', trim($line), $m)) {
            return ['name' => $m[1], 'current' => $m[3], 'available' => $m[2]];
        }
        return null;
    }

    /**
     * Parse one `dnf check-update` line, e.g.:
     *   kernel.x86_64    5.14.0-362.el9    baseos
     */
    public static function parseDnfLine(string $line): ?array
    {
        if (preg_match('/^(\S+)\.\S+\s+(\S+)\s+\S+$/', trim($line), $m)) {
            return ['name' => $m[1], 'current' => null, 'available' => $m[2]];
        }
        return null;
    }

    // --------------------------------------------------------------- npm scan

    private function scanNpm(): array
    {
        $apps = [];

        foreach (self::npmDirs() as $dir => $service) {
            $packages = [];
            // npm outdated exits 1 when outdated packages exist; output is still valid JSON
            $out = @shell_exec('cd ' . escapeshellarg($dir) . ' && timeout 120 npm outdated --json 2>/dev/null');
            $data = $out ? json_decode($out, true) : null;

            if (is_array($data)) {
                foreach ($data as $name => $info) {
                    $current = $info['current'] ?? null;
                    $wanted = $info['wanted'] ?? null;
                    // Only report packages that `npm update` can actually move
                    if ($current !== null && $wanted !== null && $current !== $wanted) {
                        $packages[] = [
                            'name' => $name,
                            'current' => $current,
                            'wanted' => $wanted,
                            'latest' => $info['latest'] ?? null,
                        ];
                    }
                }
            }

            if (!empty($packages)) {
                $apps[] = [
                    'dir' => $dir,
                    'service' => $service,
                    'packages' => array_slice($packages, 0, self::MAX_PACKAGES),
                    'count' => count($packages),
                ];
            }
        }

        return $apps;
    }

    private static function commandExists(string $cmd): bool
    {
        $out = @shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null');
        return !empty(trim((string)$out));
    }
}
