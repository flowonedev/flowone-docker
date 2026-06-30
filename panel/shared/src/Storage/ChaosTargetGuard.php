<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Safety guard for the chaos test harness.
 *
 * The harness runs on the live VPS (no separate staging available). To
 * guarantee that no chaos scenario can ever touch real Drive or backup
 * data, every destructive scenario MUST route its target paths through
 * this guard. The guard refuses any path not under the chaos tenant's
 * subtree.
 *
 * Two gates:
 *
 *   1. {@see assertEnabled()} — confirms /var/lib/flowone/chaos.enabled
 *      exists. The operator creates it via `storage-ctl chaos enable`.
 *      Without it, no scenario will run.
 *
 *   2. {@see assertSafePath()} — confirms the resolved path lives under
 *      the chaos tenant subtree. Uses realpath() to defeat symlink and
 *      ../ escape attempts.
 *
 * Bypassing either gate by calling raw filesystem ops directly is a CI-
 * caught violation. Scenarios MUST use the guard.
 */
final class ChaosTargetGuard
{
    public const SYNTHETIC_TENANT_KEY = 'chaos-test';

    public function __construct(
        private string $chaosFlagPath,
        private string $nasMountPoint,
        private string $tenantSubpath,
    ) {}

    public static function fromConfig(): self
    {
        $config = Config::load();
        $tenants = $config['tenants'] ?? [];
        if (!isset($tenants[self::SYNTHETIC_TENANT_KEY])) {
            throw new \RuntimeException(
                'ChaosTargetGuard: synthetic tenant "' . self::SYNTHETIC_TENANT_KEY
                . '" missing from storage.php tenants[]'
            );
        }
        $tenant = $tenants[self::SYNTHETIC_TENANT_KEY];
        if (empty($tenant['is_synthetic'])) {
            throw new \RuntimeException(
                'ChaosTargetGuard: tenant "' . self::SYNTHETIC_TENANT_KEY
                . '" must have is_synthetic=true'
            );
        }
        return new self(
            rtrim((string) $config['state']['dir'], '/') . '/' . (string) $config['state']['chaos_flag'],
            (string) $config['nas']['mount_point'],
            (string) $tenant['subpath'],
        );
    }

    /**
     * Refuses to proceed unless `storage-ctl chaos enable` has been run.
     * Call this once at scenario startup.
     */
    public function assertEnabled(): void
    {
        if (!is_file($this->chaosFlagPath)) {
            throw new \RuntimeException(
                'ChaosTargetGuard: chaos is not enabled. Run `storage-ctl chaos enable` first.'
            );
        }
    }

    /**
     * Refuses any path that does not resolve under the synthetic tenant's
     * subdirectory.
     *
     * Two forms of comparison:
     *   - If the path exists, realpath() it to defeat symlinks.
     *   - If the path does NOT yet exist (typical for "create then check"),
     *     resolve the longest-existing prefix and append the remainder.
     *     The result is canonicalised with no .. segments.
     *
     * Throws on any escape attempt. Returns the canonical safe path.
     */
    public function assertSafePath(string $path): string
    {
        $tenantRoot = rtrim($this->nasMountPoint, '/') . '/' . trim($this->tenantSubpath, '/');

        $realTenantRoot = $this->canonicaliseExistingOrPartial($tenantRoot);
        $realTarget = $this->canonicaliseExistingOrPartial($path);

        // Normalise trailing slashes for prefix comparison.
        $rootPrefix = rtrim($realTenantRoot, '/') . '/';
        $targetWithSlash = rtrim($realTarget, '/');

        if ($realTarget !== rtrim($realTenantRoot, '/') && !str_starts_with($targetWithSlash . '/', $rootPrefix)) {
            throw new \RuntimeException(sprintf(
                'ChaosTargetGuard: refused path outside synthetic tenant subtree.\n'
                . '  given:    %s\n'
                . '  resolved: %s\n'
                . '  required: under %s',
                $path,
                $realTarget,
                $rootPrefix
            ));
        }

        return $realTarget;
    }

    /**
     * Canonical absolute path, handling non-existent leaf segments by
     * resolving the longest existing ancestor and appending the rest with
     * .. and . segments collapsed.
     */
    private function canonicaliseExistingOrPartial(string $path): string
    {
        if ($path === '') {
            throw new \InvalidArgumentException('ChaosTargetGuard: empty path');
        }

        if ($path[0] !== '/') {
            $path = getcwd() . '/' . $path;
        }

        $real = @realpath($path);
        if ($real !== false) {
            return $real;
        }

        // Walk up until we find something that does exist.
        $segments = explode('/', $path);
        $tail = [];
        while (!empty($segments)) {
            $candidate = implode('/', $segments);
            if ($candidate === '' || $candidate === '/') {
                break;
            }
            $resolved = @realpath($candidate);
            if ($resolved !== false) {
                return $this->normalise($resolved . '/' . implode('/', array_reverse($tail)));
            }
            $tail[] = array_pop($segments);
        }
        return $this->normalise($path);
    }

    private function normalise(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        return '/' . implode('/', $out);
    }
}
