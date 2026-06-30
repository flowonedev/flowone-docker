<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Support;

/**
 * Resolves the MariaDB administrative credentials used by
 * {@see \VpsAdmin\Agent\Provisioner\Adapters\MysqlAdapter}.
 *
 * Why this exists
 * ---------------
 * The provisioning saga's MySQL steps (DatabaseCreateStep,
 * DatabaseUserCreateStep, DatabaseGrantStep, DatabaseDropStep, ...)
 * need an admin connection that can run:
 *
 *   - CREATE / DROP DATABASE
 *   - CREATE / DROP USER
 *   - GRANT / REVOKE on arbitrary databases
 *   - SHOW GRANTS FOR <user>
 *
 * The panel's own DB user (`vpsadmin@localhost` in production) is
 * intentionally narrowly scoped to `devc_vps_dash.*` and CANNOT do
 * any of the above. Wiring the panel user into MysqlAdapter produces:
 *
 *   SQLSTATE[42000] 1044 Access denied for user 'vpsadmin'@'localhost'
 *   to database 'flowone_xxx'
 *
 * That is the bug we observed in production saga runs. This class
 * fixes it by resolving a separate admin credential without having
 * to copy/paste lookup logic into every CLI entrypoint.
 *
 * Resolution order (first match wins)
 * -----------------------------------
 *   1. `database_admin` block in the panel config
 *      (`/var/www/vps-admin/api/config.local.php` -> `database_admin`).
 *      Preferred in production: declarative, version-controlled (in
 *      config.local.php which is .gitignored), explicit.
 *
 *   2. `/root/.my.cnf` `[client]` block. This matches the existing
 *      pattern used by legacy {@see \VpsAdmin\Agent\Actions\DatabaseAction}
 *      and friends, so a freshly provisioned VPS that already has
 *      root credentials in /root/.my.cnf "just works".
 *
 *   3. Panel DB credentials. Last-resort fallback that emits a clear
 *      stderr warning the first time it's used. Will likely produce
 *      the 1044 error above on any CREATE/GRANT - we let it surface
 *      rather than silently hiding the misconfiguration.
 *
 * The closure returned here is the same shape MysqlAdapter expects:
 *   fn(): array{host:string, port:int, user:string, password:string, socket?:string|null}
 *
 * The provider re-resolves on every call so credential rotation
 * (rewriting config.local.php or /root/.my.cnf) is picked up without
 * restarting the worker daemon.
 */
final class MysqlAdminCredentials
{
    private const ROOT_MYCNF = '/root/.my.cnf';

    /**
     * Default MariaDB unix socket. When no explicit socket is resolved
     * and the connection targets localhost, we fall back to this path
     * so the provisioner's MysqlAdapter authenticates the SAME way the
     * proven manual path does ({@see \VpsAdmin\Agent\Actions\DatabaseAction}
     * connects via `mysql:unix_socket=/var/run/mysqld/mysqld.sock`).
     *
     * Without this, `normalize()` returns socket=null for the common
     * `/root/.my.cnf` case (which only lists user/password), the adapter
     * builds a `mysql:host=localhost` DSN, and root auth can fail with
     * SQLSTATE 1044/1045 - which parks the whole site in `degraded` with
     * no database created. A `socket=` line in /root/.my.cnf or a
     * `database_admin.socket` config value still takes precedence.
     */
    private const DEFAULT_SOCKET = '/var/run/mysqld/mysqld.sock';

    /**
     * One-shot warning latch so the worker journal doesn't fill up
     * with the same "no admin configured" message every loop tick.
     */
    private static bool $fallbackWarned = false;

    /**
     * Build a credentials provider closure suitable for
     * `new MysqlAdapter($runner, $closure)`.
     *
     * @param array<string,mixed> $panelConfig
     *   The full panel config map (the same structure config.php
     *   returns: `['database' => [...], 'database_admin' => [...]]`).
     *   Pass the result of `require '/var/www/vps-admin/api/config.php'`
     *   merged with config.local.php, or the equivalent test fixture.
     */
    public static function provider(array $panelConfig): \Closure
    {
        $admin = is_array($panelConfig['database_admin'] ?? null)
            ? $panelConfig['database_admin']
            : null;
        $panel = is_array($panelConfig['database'] ?? null)
            ? $panelConfig['database']
            : [];

        return static function () use ($admin, $panel): array {
            // 1. Explicit database_admin block.
            if ($admin !== null && self::looksUsable($admin)) {
                return self::normalize($admin, $panel);
            }

            // 2. /root/.my.cnf
            $rootCnf = self::readRootMyCnf();
            if ($rootCnf !== null) {
                return self::normalize($rootCnf, $panel);
            }

            // 3. Last-resort fallback.
            self::warnFallbackOnce();
            return self::normalize($panel, $panel);
        };
    }

    /**
     * Convenience wrapper that loads the panel config using the same
     * lookup PanelDatabase uses, then returns a provider. Centralises
     * the file-IO so CLI entrypoints stay short.
     */
    public static function providerFromDefaultConfigFiles(): \Closure
    {
        $merged = self::loadMergedPanelConfig();
        return self::provider($merged);
    }

    /**
     * Attempt to load + merge config.php / config.local.php exactly the
     * way PanelDatabase does. Returns an empty array if neither file
     * is readable - the resolver will then fall back to /root/.my.cnf
     * or warn-and-fail.
     *
     * @return array<string,mixed>
     */
    public static function loadMergedPanelConfig(): array
    {
        $configFile = '/var/www/vps-admin/api/config.php';
        $localConfigFile = '/var/www/vps-admin/api/config.local.php';

        if (!is_file($configFile)) {
            return [];
        }
        $config = self::safeRequire($configFile);
        if (is_file($localConfigFile)) {
            $local = self::safeRequire($localConfigFile);
            if (is_array($local) && is_array($config)) {
                $config = array_replace_recursive($config, $local);
            } elseif (is_array($local)) {
                $config = $local;
            }
        }
        return is_array($config) ? $config : [];
    }

    /**
     * Treat the supplied error as "MariaDB refused us because we lack
     * privileges". Returns a hint string the operator can act on, or
     * null if this isn't a privilege error.
     *
     * Used by step error formatting so the saga event log says
     * something more useful than the raw SQLSTATE 1044.
     */
    public static function privilegeHint(\Throwable $e): ?string
    {
        $msg = $e->getMessage();
        // 1044: Access denied for user 'X'@'Y' to database 'Z'
        // 1045: Access denied for user 'X'@'Y' (using password: ...)
        // 1142: <command> command denied to user
        // 1227: Access denied; you need (at least one of) the <priv>
        if (
            !str_contains($msg, '1044')
            && !str_contains($msg, '1045')
            && !str_contains($msg, '1142')
            && !str_contains($msg, '1227')
            && stripos($msg, 'access denied') === false
        ) {
            return null;
        }
        return 'MariaDB refused the operation. The MysqlAdapter is using '
            . 'an under-privileged account. Set "database_admin" in '
            . '/var/www/vps-admin/api/config.local.php (with CREATE/DROP '
            . 'DATABASE, CREATE/DROP USER, GRANT OPTION) or place valid '
            . 'admin credentials in /root/.my.cnf.';
    }

    // ── internals ────────────────────────────────────────────

    /**
     * Treat the usual loopback aliases as "localhost" for the purpose
     * of choosing the unix socket.
     */
    private static function isLocalHost(string $host): bool
    {
        $h = strtolower(trim($host));
        return $h === '' || $h === 'localhost' || $h === '127.0.0.1' || $h === '::1';
    }

    private static function looksUsable(array $creds): bool
    {
        $user = (string) ($creds['user'] ?? '');
        if ($user === '') {
            return false;
        }
        $pass = (string) ($creds['password'] ?? $creds['pass'] ?? '');
        $socket = (string) ($creds['socket'] ?? '');
        // Allow empty password ONLY if a unix socket is given (root@localhost
        // via auth_socket is the canonical zero-password path).
        return $pass !== '' || $socket !== '';
    }

    /**
     * @param array<string,mixed> $creds
     * @param array<string,mixed> $panelDefaults
     * @return array{host:string,port:int,user:string,password:string,socket:?string}
     */
    private static function normalize(array $creds, array $panelDefaults): array
    {
        $host = (string) ($creds['host'] ?? $panelDefaults['host'] ?? 'localhost');
        $port = (int) ($creds['port'] ?? $panelDefaults['port'] ?? 3306);
        $user = (string) ($creds['user'] ?? '');
        $pass = (string) ($creds['password'] ?? $creds['pass'] ?? '');
        $socket = $creds['socket'] ?? $panelDefaults['socket'] ?? null;
        if ($socket !== null) {
            $socket = (string) $socket;
            if ($socket === '') {
                $socket = null;
            }
        }

        $host = $host !== '' ? $host : 'localhost';

        // When no explicit socket is configured and we're connecting to
        // localhost, prefer the canonical MariaDB unix socket. This keeps
        // root auth working (auth_socket / socket-scoped grants) and
        // matches DatabaseAction's proven connection path, instead of
        // letting PDO guess a default socket / TCP that fails with 1044/1045
        // and silently degrades the site.
        if ($socket === null && self::isLocalHost($host) && is_readable(self::DEFAULT_SOCKET)) {
            $socket = self::DEFAULT_SOCKET;
        }

        return [
            'host' => $host,
            'port' => $port > 0 ? $port : 3306,
            'user' => $user,
            'password' => $pass,
            'socket' => $socket,
        ];
    }

    /**
     * Parse /root/.my.cnf if readable. Returns null if missing,
     * unreadable, or doesn't contain a usable [client] block.
     *
     * @return array<string,mixed>|null
     */
    private static function readRootMyCnf(): ?array
    {
        if (!is_readable(self::ROOT_MYCNF)) {
            return null;
        }
        $parsed = @parse_ini_file(self::ROOT_MYCNF, true, INI_SCANNER_RAW);
        if (!is_array($parsed)) {
            // Some .my.cnf files have unquoted special chars that
            // parse_ini_file can't cope with. Fall through to the
            // line-based fallback below.
            return self::parseRootMyCnfFallback();
        }
        $block = null;
        foreach (['client', 'mysql', 'mysqldump', 'mariadb'] as $name) {
            if (isset($parsed[$name]) && is_array($parsed[$name])) {
                $block = array_merge($block ?? [], $parsed[$name]);
            }
        }
        if ($block === null) {
            return self::parseRootMyCnfFallback();
        }
        $creds = [
            'user' => isset($block['user']) ? self::stripQuotes((string) $block['user']) : '',
            'password' => isset($block['password'])
                ? self::stripQuotes((string) $block['password'])
                : (isset($block['pass']) ? self::stripQuotes((string) $block['pass']) : ''),
            'host' => isset($block['host']) ? self::stripQuotes((string) $block['host']) : 'localhost',
            'port' => isset($block['port']) ? (int) self::stripQuotes((string) $block['port']) : 3306,
            'socket' => isset($block['socket']) ? self::stripQuotes((string) $block['socket']) : null,
        ];
        return self::looksUsable($creds) ? $creds : null;
    }

    /**
     * Last-ditch line-based parser for /root/.my.cnf installs that
     * have unquoted '#' or other tokens parse_ini_file can't handle.
     *
     * @return array<string,mixed>|null
     */
    private static function parseRootMyCnfFallback(): ?array
    {
        $body = @file_get_contents(self::ROOT_MYCNF);
        if (!is_string($body) || $body === '') {
            return null;
        }
        $creds = ['host' => 'localhost', 'port' => 3306];
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';' || $line[0] === '[') {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = strtolower(trim($k));
            $v = self::stripQuotes(trim($v));
            switch ($k) {
                case 'user':
                    $creds['user'] = $v;
                    break;
                case 'password':
                case 'pass':
                    $creds['password'] = $v;
                    break;
                case 'host':
                    if ($v !== '') {
                        $creds['host'] = $v;
                    }
                    break;
                case 'port':
                    $creds['port'] = (int) $v;
                    break;
                case 'socket':
                    $creds['socket'] = $v;
                    break;
            }
        }
        return self::looksUsable($creds) ? $creds : null;
    }

    private static function stripQuotes(string $v): string
    {
        $v = trim($v);
        $len = strlen($v);
        if ($len >= 2) {
            $first = $v[0];
            $last = $v[$len - 1];
            if (($first === '"' && $last === '"') || ($first === '\'' && $last === '\'')) {
                return substr($v, 1, $len - 2);
            }
        }
        return $v;
    }

    private static function safeRequire(string $path): mixed
    {
        try {
            return require $path;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function warnFallbackOnce(): void
    {
        if (self::$fallbackWarned) {
            return;
        }
        self::$fallbackWarned = true;
        @fwrite(
            STDERR,
            "[mysql-admin] WARNING: no 'database_admin' block in panel config "
            . "and /root/.my.cnf is not readable. MysqlAdapter is falling back "
            . "to the panel DB user, which lacks CREATE/GRANT privileges. "
            . "Provision steps that touch databases will FAIL with SQLSTATE 1044. "
            . "Add a 'database_admin' block to /var/www/vps-admin/api/config.local.php.\n"
        );
    }

    /**
     * Test-only: reset the fallback-warned latch so unit tests don't
     * accidentally suppress the warning across runs.
     */
    public static function resetForTesting(): void
    {
        self::$fallbackWarned = false;
    }
}
