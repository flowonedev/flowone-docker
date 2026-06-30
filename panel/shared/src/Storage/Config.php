<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Loads the shared storage configuration with optional per-host overrides.
 *
 * Lookup order:
 *   1. shared/config/storage.php   (canonical defaults, in-repo)
 *   2. /etc/flowone/storage.local.php  (per-host overrides; array_replace_recursive)
 *
 * Cached for the lifetime of the process. The Reset() entrypoint exists
 * purely for tests/long-running daemons that need to pick up an updated
 * config without a full restart.
 */
final class Config
{
    private static ?array $cached = null;

    private const DEFAULT_CANONICAL_PATH = __DIR__ . '/../../config/storage.php';
    private const DEFAULT_OVERRIDE_PATH  = '/etc/flowone/storage.local.php';

    public static function load(?string $canonicalPath = null, ?string $overridePath = null): array
    {
        if (self::$cached !== null && $canonicalPath === null && $overridePath === null) {
            return self::$cached;
        }

        $canonical = $canonicalPath ?? self::DEFAULT_CANONICAL_PATH;
        $override  = $overridePath  ?? self::DEFAULT_OVERRIDE_PATH;

        if (!is_readable($canonical)) {
            throw new \RuntimeException("FlowOne storage config not readable: {$canonical}");
        }

        /** @var array<string,mixed> $config */
        $config = require $canonical;
        if (!is_array($config)) {
            throw new \RuntimeException("FlowOne storage config did not return an array: {$canonical}");
        }

        if (is_readable($override)) {
            /** @var array<string,mixed>|mixed $local */
            $local = require $override;
            if (is_array($local)) {
                $config = array_replace_recursive($config, $local);
            }
        }

        if ($canonicalPath === null && $overridePath === null) {
            self::$cached = $config;
        }

        return $config;
    }

    public static function reset(): void
    {
        self::$cached = null;
    }

    /**
     * Convenience: dot-path getter. Returns $default when the key is missing.
     *
     * Example: Config::get('helper.socket_path')
     */
    public static function get(string $dotPath, mixed $default = null): mixed
    {
        $config = self::load();
        $cursor = $config;
        foreach (explode('.', $dotPath) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }
}
