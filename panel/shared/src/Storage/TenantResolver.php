<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 3 tenant resolver.
 *
 * Single source of truth for mapping a logical tenant name to an
 * absolute filesystem path on the NAS mount. All consumers (drive
 * uploads, backup writes, retention sweep, future tier-down worker)
 * MUST route through this class — never concatenate paths by hand.
 *
 * Three responsibilities:
 *   1. Validate the tenant name is in the config allowlist.
 *   2. Refuse synthetic tenants when chaos mode is disabled (defence
 *      against accidental writes to chaos paths in production code).
 *   3. Validate any sub-path the caller supplies stays inside the
 *      tenant root (path-traversal protection). The
 *      Invariants::assertPathInsideTenant() check is the runtime
 *      guard; this class exposes the helper that produces verified
 *      paths in the first place.
 *
 * Phase 3 is pure infrastructure: no existing consumer is wired to
 * this class yet. DriveService et al. continue to write directly to
 * /mnt/nas-drive/{hash} until Phase 5's tier-down worker migrates
 * them under /mnt/nas-drive/drive/.
 */
final class TenantResolver
{
    /** @var array<string,array<string,mixed>> */
    private array $tenants;

    private string $mountPoint;
    private bool $chaosEnabled;

    /**
     * @param array<string,array<string,mixed>> $tenants  tenants[] from storage.php
     */
    public function __construct(array $tenants, string $mountPoint, bool $chaosEnabled)
    {
        $this->tenants = $tenants;
        $this->mountPoint = rtrim($mountPoint, '/');
        $this->chaosEnabled = $chaosEnabled;
    }

    public static function fromConfig(?array $config = null): self
    {
        $config = $config ?? Config::load();
        $chaosFlag = rtrim((string) $config['state']['dir'], '/') . '/' . (string) $config['state']['chaos_flag'];
        return new self(
            tenants:      $config['tenants'] ?? [],
            mountPoint:   (string) $config['nas']['mount_point'],
            chaosEnabled: is_file($chaosFlag)
        );
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->tenants);
    }

    /**
     * Tenant names that are eligible to be acted upon in the current
     * runtime. Synthetic tenants (is_synthetic=true) are excluded
     * unless chaos mode is enabled.
     *
     * @return list<string>
     */
    public function activeNames(): array
    {
        $out = [];
        foreach ($this->tenants as $name => $cfg) {
            if (($cfg['is_synthetic'] ?? false) && !$this->chaosEnabled) {
                continue;
            }
            $out[] = $name;
        }
        return $out;
    }

    public function exists(string $name): bool
    {
        return isset($this->tenants[$name]);
    }

    /**
     * @return array<string,mixed>
     * @throws \InvalidArgumentException when the tenant is unknown
     */
    public function definition(string $name): array
    {
        if (!isset($this->tenants[$name])) {
            throw new \InvalidArgumentException("unknown tenant: {$name}");
        }
        return $this->tenants[$name];
    }

    /**
     * Absolute root directory for the tenant on the NAS. Does NOT
     * guarantee the directory exists on disk; that's TenantBootstrap's
     * job. Refuses synthetic tenants unless chaos is enabled (I-12
     * cousin: synthetic data must never bleed into prod tenants).
     */
    public function rootFor(string $name): string
    {
        $def = $this->definition($name);
        if (($def['is_synthetic'] ?? false) && !$this->chaosEnabled) {
            throw new \RuntimeException(
                "tenant '{$name}' is synthetic and chaos mode is disabled; refusing path resolution"
            );
        }
        $subpath = (string) ($def['subpath'] ?? $name);
        if ($subpath === '' || str_contains($subpath, '/') || str_contains($subpath, '\0')) {
            throw new \RuntimeException("tenant '{$name}' has invalid subpath: " . var_export($subpath, true));
        }
        return $this->mountPoint . '/' . $subpath;
    }

    /**
     * Resolve a relative path under the tenant root, with path-safety
     * checks. The caller passes the relative portion (e.g. a file
     * hash, or "user-42/folder-7/file.bin"); we strip dot-segments and
     * verify the final realpath stays inside the tenant root.
     *
     * Two-stage check:
     *   1. Lexical: refuse "..", absolute paths, NUL bytes BEFORE we
     *      touch the filesystem.
     *   2. realpath: when the target exists, the real resolved path
     *      MUST start with the tenant root. (Symlink escape defence.)
     *
     * @throws \RuntimeException on any validation failure
     */
    public function pathInside(string $tenant, string $relative): string
    {
        if ($relative === '' || $relative === '/') {
            return $this->rootFor($tenant);
        }
        // Lexical guards — these must fire BEFORE filesystem access so
        // they catch attacks even when the path doesn't (yet) exist.
        if (str_contains($relative, "\0")) {
            throw new \RuntimeException('relative path contains NUL byte');
        }
        if (str_starts_with($relative, '/')) {
            throw new \RuntimeException('relative path must not be absolute: ' . $relative);
        }
        $parts = preg_split('#[/\\\\]+#', $relative) ?: [];
        foreach ($parts as $p) {
            if ($p === '..' || $p === '.') {
                throw new \RuntimeException('relative path contains dot-segment: ' . $relative);
            }
        }

        $root = $this->rootFor($tenant);
        $candidate = $root . '/' . ltrim($relative, '/');

        // realpath-based escape check applies only when the path (or
        // its parent) exists. For non-existent leaf nodes we walk up
        // to the first existing ancestor.
        $existing = $candidate;
        while ($existing !== '' && !file_exists($existing) && $existing !== '/') {
            $existing = dirname($existing);
        }
        if (file_exists($existing)) {
            $real = realpath($existing);
            $rootReal = realpath($root);
            // Normalise separators so the prefix check works on
            // Windows (where realpath returns backslashed paths). Pure
            // no-op on Linux/macOS where paths are already /-only.
            if ($real !== false) {
                $real = str_replace('\\', '/', $real);
            }
            if ($rootReal !== false) {
                $rootReal = str_replace('\\', '/', $rootReal);
            }
            if ($real !== false && $rootReal !== false && !str_starts_with($real . '/', $rootReal . '/')) {
                throw new \RuntimeException(sprintf(
                    'resolved path %s escapes tenant root %s (via symlink?)',
                    $real,
                    $rootReal
                ));
            }
        }
        return $candidate;
    }

    public function retentionDaysFor(string $name): ?int
    {
        $def = $this->definition($name);
        $r = $def['retention_days'] ?? null;
        return $r === null ? null : max(0, (int) $r);
    }

    public function isSynthetic(string $name): bool
    {
        return (bool) ($this->definition($name)['is_synthetic'] ?? false);
    }
}
