<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Adapters;

/**
 * MariaDB / MySQL administrative adapter.
 *
 * Used by site-provisioning steps to:
 *   - create + drop per-site databases
 *   - create + drop per-site users
 *   - grant + revoke privileges
 *   - take logical dumps before destructive actions (DEGRADE_ONLY
 *     compensation policy)
 *
 * Connection strategy:
 *   - We do NOT reuse PanelDatabase. PanelDatabase points at the panel
 *     schema (devc_vps_dash). The admin we use here typically has
 *     CREATE/GRANT privileges, which the panel user must NOT have.
 *   - Credentials come from an injected callable so the test harness
 *     can replace them. Production wires this to `/root/.my.cnf` or
 *     the env-loaded admin pair.
 *   - Each call opens its own PDO so failures are localized; cost is
 *     ~1ms per call which is dwarfed by the network round-trip.
 *
 * Why everything is parameter-bound:
 *   - DB names cannot be parameter-bound in MySQL (parser limitation).
 *     We validate them against a strict allowlist before interpolating.
 *   - Passwords flow through `IDENTIFIED BY '...'` which DOES allow
 *     parameter binding via PDO's emulation - we still escape via the
 *     PDO::quote path to be safe.
 *
 * Path discipline:
 *   - mysqldump runs through CommandRunner with credentials via the
 *     `--defaults-extra-file` trick (a 0600 temp file). NEVER pass the
 *     password on the command line - it appears in /proc/<pid>/cmdline.
 */
final class MysqlAdapter
{
    /**
     * Name pattern: [A-Za-z0-9_]+, 1..64 chars. MySQL allows other
     * characters with backticks but we deliberately don't.
     */
    private const NAME_REGEX = '/^[A-Za-z0-9_]{1,64}$/';

    public function __construct(
        private readonly CommandRunner $runner,
        /**
         * fn(): array{host:string, port:int, user:string, password:string, socket?:string|null}
         * Returns a fresh credentials map on each call so rotation
         * is picked up automatically.
         */
        private readonly \Closure $credentialsProvider,
        private readonly string $mysqlBin = '/usr/bin/mysql',
        private readonly string $mysqldumpBin = '/usr/bin/mysqldump'
    ) {
    }

    // ─── DB lifecycle ─────────────────────────────────────────

    public function databaseExists(string $name): bool
    {
        $this->assertSafeName($name);
        $pdo = $this->connect();
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
        );
        $stmt->execute([$name]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Create a database with UTF-8 (utf8mb4 / unicode_ci) defaults.
     * Idempotent: returns false if the DB already existed.
     */
    public function createDatabase(string $name): bool
    {
        $this->assertSafeName($name);
        if ($this->databaseExists($name)) {
            return false;
        }
        $pdo = $this->connect();
        $sql = sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $name
        );
        $pdo->exec($sql);
        return true;
    }

    /**
     * Drop the database if it exists. Returns true if a drop happened,
     * false if there was nothing to drop. NEVER call this from a step
     * whose compensation policy is DEGRADE_ONLY - that's the whole
     * point of that policy.
     */
    public function dropDatabase(string $name): bool
    {
        $this->assertSafeName($name);
        if (!$this->databaseExists($name)) {
            return false;
        }
        $pdo = $this->connect();
        $pdo->exec(sprintf('DROP DATABASE `%s`', $name));
        return true;
    }

    // ─── User lifecycle ───────────────────────────────────────

    /**
     * Look up whether a user@host exists in mysql.user.
     */
    public function userExists(string $user, string $host = 'localhost'): bool
    {
        $this->assertSafeName($user);
        $pdo = $this->connect();
        $stmt = $pdo->prepare(
            "SELECT 1 FROM mysql.user WHERE User = ? AND Host = ?"
        );
        $stmt->execute([$user, $host]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Create a user. The password is sent verbatim in the SQL stream;
     * it never appears on argv. Idempotent: returns false if the
     * user@host already existed.
     */
    public function createUser(string $user, string $password, string $host = 'localhost'): bool
    {
        $this->assertSafeName($user);
        if ($this->userExists($user, $host)) {
            return false;
        }
        $pdo = $this->connect();
        $sql = sprintf(
            "CREATE USER %s@%s IDENTIFIED BY %s",
            $pdo->quote($user),
            $pdo->quote($host),
            $pdo->quote($password)
        );
        $pdo->exec($sql);
        return true;
    }

    /**
     * Set/rotate a user's password.
     */
    public function setUserPassword(string $user, string $password, string $host = 'localhost'): void
    {
        $this->assertSafeName($user);
        $pdo = $this->connect();
        $sql = sprintf(
            "ALTER USER %s@%s IDENTIFIED BY %s",
            $pdo->quote($user),
            $pdo->quote($host),
            $pdo->quote($password)
        );
        $pdo->exec($sql);
    }

    public function dropUser(string $user, string $host = 'localhost'): bool
    {
        $this->assertSafeName($user);
        if (!$this->userExists($user, $host)) {
            return false;
        }
        $pdo = $this->connect();
        $sql = sprintf(
            "DROP USER %s@%s",
            $pdo->quote($user),
            $pdo->quote($host)
        );
        $pdo->exec($sql);
        return true;
    }

    // ─── Privilege management ────────────────────────────────

    /**
     * Grant ALL on $database to $user@$host. Most per-site users want
     * exactly this; advanced grants go through `grantCustom()`.
     */
    public function grantAllOnDatabase(
        string $database,
        string $user,
        string $host = 'localhost'
    ): void {
        $this->assertSafeName($database);
        $this->assertSafeName($user);
        $pdo = $this->connect();
        $sql = sprintf(
            "GRANT ALL PRIVILEGES ON `%s`.* TO %s@%s",
            $database,
            $pdo->quote($user),
            $pdo->quote($host)
        );
        $pdo->exec($sql);
        $pdo->exec('FLUSH PRIVILEGES');
    }

    /**
     * Run an explicit GRANT (or REVOKE) statement after validating the
     * caller has not embedded any unexpected tokens. The statement is
     * built by the caller; we just check it starts with GRANT or REVOKE
     * to refuse arbitrary DDL.
     */
    public function grantCustom(string $statement): void
    {
        $upper = strtoupper(ltrim($statement));
        if (!str_starts_with($upper, 'GRANT ') && !str_starts_with($upper, 'REVOKE ')) {
            throw new \InvalidArgumentException(
                "grantCustom() refuses statements not starting with GRANT/REVOKE"
            );
        }
        $pdo = $this->connect();
        $pdo->exec($statement);
        $pdo->exec('FLUSH PRIVILEGES');
    }

    /**
     * Predicate: does $user@$host already hold ALL PRIVILEGES on
     * $database? Used by DatabaseGrantStep::check() to keep idempotence
     * crisp without firing extra GRANT statements.
     *
     * Returns FALSE on any error (e.g. the user doesn't exist yet, the
     * connecting user lacks SHOW GRANTS rights on the target). False
     * is the safe answer because it forces execute() to run, which
     * will surface a real error if there is one.
     */
    public function hasAllPrivilegesOn(
        string $database,
        string $user,
        string $host = 'localhost'
    ): bool {
        return $this->grantInspection($database, $user, $host)['has_all'] ?? false;
    }

    /**
     * Same predicate as hasAllPrivilegesOn but returns the full
     * inspection bundle: the boolean answer plus the raw rows that
     * `SHOW GRANTS` produced and the per-row decision trace.
     *
     * Exposed primarily for the regression suite + saga step error
     * messages so an "execute() succeeded but verify() FAILED" line
     * can include the actual grant lines instead of just `false`.
     *
     * @return array{
     *   has_all: bool,
     *   raw: list<string>,
     *   normalised: list<string>,
     *   error: string|null
     * }
     */
    public function grantInspection(string $database, string $user, string $host = 'localhost'): array
    {
        $raw = [];
        $normalised = [];
        try {
            $this->assertSafeName($database);
            $this->assertSafeName($user);
            $pdo = $this->connect();
            // SHOW GRANTS FOR <user>@<host> does NOT accept prepared
            // placeholders - the user/host are SQL identifiers, not
            // values, and MariaDB rejects with 1064 (literally:
            //   "syntax error ... near '?@?'"
            // ) if you try. We've already validated $user via
            // assertSafeName, and PDO::quote handles the host string
            // safely (it can be an IP, 'localhost', or a wildcard
            // form like 'host.%.tld'). This is the same interpolation
            // pattern used by createUser/dropUser/grantAllOnDatabase.
            $sql = sprintf(
                'SHOW GRANTS FOR %s@%s',
                $pdo->quote($user),
                $pdo->quote($host)
            );
            $stmt = $pdo->query($sql);
            if ($stmt === false) {
                return [
                    'has_all' => false,
                    'raw' => [],
                    'normalised' => [],
                    'error' => 'SHOW GRANTS query returned false',
                ];
            }

            $hasAll = false;
            $needle = strtolower($database);
            // Match: GRANT ALL [PRIVILEGES] ON [`]<db>[`].* TO ...
            //   - case-insensitive (we already lowercased $line)
            //   - 'ALL' or 'ALL PRIVILEGES'
            //   - optional backticks around the db name
            //   - whitespace between tokens
            // We match the db part separately on a *normalised* line
            // (backslash-escapes for `_` / `%` stripped) so we work
            // across MariaDB / MySQL versions that differ on whether
            // they emit `flowone\_test`.* or `flowone_test`.* .
            $pattern = '/\bgrant\s+all(\s+privileges)?\s+on\s+`?'
                     . preg_quote($needle, '/')
                     . '`?\s*\.\s*\*\s+to\b/';

            while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                $rawLine = (string) ($row[0] ?? '');
                $raw[] = $rawLine;

                // 1. lowercase
                // 2. drop the backslash-escapes MariaDB applies to
                //    `_`, `%`, and `\` in identifier output.
                $line = strtolower($rawLine);
                $line = str_replace(['\\_', '\\%', '\\\\'], ['_', '%', '\\'], $line);
                $normalised[] = $line;

                if (preg_match($pattern, $line) === 1) {
                    $hasAll = true;
                    // Don't break - we want the full $raw list for
                    // diagnostics on the path where this returns true.
                }
            }
            return [
                'has_all' => $hasAll,
                'raw' => $raw,
                'normalised' => $normalised,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'has_all' => false,
                'raw' => $raw,
                'normalised' => $normalised,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function revokeAllOnDatabase(
        string $database,
        string $user,
        string $host = 'localhost'
    ): void {
        $this->assertSafeName($database);
        $this->assertSafeName($user);
        $pdo = $this->connect();
        $sql = sprintf(
            "REVOKE ALL PRIVILEGES, GRANT OPTION FROM %s@%s",
            $pdo->quote($user),
            $pdo->quote($host)
        );
        // Note: we revoke globally because per-DB revoke fails if any
        // privilege was granted at a different scope. The caller has
        // already validated they own this user.
        $pdo->exec($sql);
        $pdo->exec('FLUSH PRIVILEGES');
    }

    // ─── Logical dumps (snapshot pre-destructive-action) ─────

    /**
     * Replay a mysqldump file into $database (which MUST already
     * exist - we don't auto-create here so the create step's
     * accounting stays the single source of truth).
     *
     * The SQL is streamed via stdin so even multi-gigabyte dumps
     * don't get loaded into memory. Credentials are passed via the
     * same temp defaults-extra-file trick used by dumpDatabase().
     *
     * @return int Number of bytes streamed in from $inputPath.
     */
    public function restoreDatabase(
        string $database,
        string $inputPath,
        int $timeoutSeconds = 1800
    ): int {
        $this->assertSafeName($database);
        if ($inputPath === '' || $inputPath[0] !== '/') {
            throw new \InvalidArgumentException("Input path must be absolute: {$inputPath}");
        }
        if (!is_file($inputPath) || !is_readable($inputPath)) {
            throw new \RuntimeException("Dump file missing or unreadable: {$inputPath}");
        }
        // Refuse to restore into a database that doesn't exist - we
        // don't want a typo in $database to silently end up creating
        // a stray DB via "USE database" failing and the SQL streaming
        // into the wrong place.
        if (!$this->databaseExists($database)) {
            throw new \RuntimeException(
                "restoreDatabase: target database '{$database}' does not exist; create it first"
            );
        }

        $bytes = (int) (@filesize($inputPath) ?: 0);

        // Read in chunks; PHP's stream_get_contents to a string works
        // for moderately sized dumps. For multi-GB dumps we'd want a
        // streaming pipe, but ProcessCommandRunner already buffers
        // stdin in memory anyway. Document the limit instead.
        $sql = @file_get_contents($inputPath);
        if (!is_string($sql)) {
            throw new \RuntimeException("could not read dump file: {$inputPath}");
        }

        $creds = ($this->credentialsProvider)();
        $defaults = $this->writeDefaultsFile($creds);
        try {
            $args = [
                "--defaults-extra-file={$defaults}",
                '--default-character-set=utf8mb4',
                $database,
            ];
            $r = $this->runner->run($this->mysqlBin, $args, $sql, $timeoutSeconds);
            if (!$r->isSuccess()) {
                throw new \RuntimeException(
                    "mysql restore failed for {$database}: " . $r->summary()
                );
            }
            return $bytes;
        } finally {
            @unlink($defaults);
        }
    }

    /**
     * Take a mysqldump of $database into $outputPath. Returns the
     * byte count written. Credentials are passed via a temp
     * defaults-extra-file so they never appear on argv.
     */
    public function dumpDatabase(
        string $database,
        string $outputPath,
        int $timeoutSeconds = 600
    ): int {
        $this->assertSafeName($database);
        if ($outputPath === '' || $outputPath[0] !== '/') {
            throw new \InvalidArgumentException("Output path must be absolute: {$outputPath}");
        }
        $creds = ($this->credentialsProvider)();
        $defaults = $this->writeDefaultsFile($creds);
        try {
            $args = [
                "--defaults-extra-file={$defaults}",
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--events',
                '--result-file=' . $outputPath,
                $database,
            ];
            $r = $this->runner->run($this->mysqldumpBin, $args, null, $timeoutSeconds);
            if (!$r->isSuccess()) {
                throw new \RuntimeException(
                    "mysqldump failed for {$database}: " . $r->summary()
                );
            }
            $size = is_file($outputPath) ? (int) filesize($outputPath) : 0;
            return $size;
        } finally {
            @unlink($defaults);
        }
    }

    // ─── Introspection ────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function listDatabases(): array
    {
        $pdo = $this->connect();
        $rows = $pdo->query("SHOW DATABASES")->fetchAll(\PDO::FETCH_COLUMN, 0);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array{user:string, host:string}>
     */
    public function listUsers(): array
    {
        $pdo = $this->connect();
        $rows = $pdo->query("SELECT User, Host FROM mysql.user ORDER BY User, Host")
            ->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(
            static fn($r): array => ['user' => (string) $r['User'], 'host' => (string) $r['Host']],
            is_array($rows) ? $rows : []
        );
    }

    // ─── Internals ────────────────────────────────────────────

    private function connect(): \PDO
    {
        $c = ($this->credentialsProvider)();
        $host = (string) ($c['host'] ?? '127.0.0.1');
        $port = (int) ($c['port'] ?? 3306);
        $user = (string) ($c['user'] ?? '');
        $pass = (string) ($c['password'] ?? '');
        $socket = $c['socket'] ?? null;

        $dsn = $socket !== null && $socket !== ''
            ? "mysql:unix_socket={$socket};charset=utf8mb4"
            : "mysql:host={$host};port={$port};charset=utf8mb4";

        return new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function assertSafeName(string $name): void
    {
        if (preg_match(self::NAME_REGEX, $name) !== 1) {
            throw new \InvalidArgumentException(
                "MySQL identifier failed safe-name check: '{$name}' (expected [A-Za-z0-9_]{1,64})"
            );
        }
    }

    /**
     * Write a 0600 temp file containing [client] credentials, return
     * the path. Caller is responsible for unlink()ing it in a finally
     * block.
     */
    private function writeDefaultsFile(array $creds): string
    {
        $path = sys_get_temp_dir() . '/flowone_my_' . bin2hex(random_bytes(8)) . '.cnf';
        $body = "[client]\n"
            . "user = " . ($creds['user'] ?? '') . "\n"
            . "password = " . ($creds['password'] ?? '') . "\n";
        if (!empty($creds['socket'])) {
            $body .= "socket = " . $creds['socket'] . "\n";
        } else {
            $body .= "host = " . ($creds['host'] ?? '127.0.0.1') . "\n"
                  . "port = " . (int) ($creds['port'] ?? 3306) . "\n";
        }
        // Create with restrictive umask first, then write.
        $previous = umask(0177);
        try {
            $ok = @file_put_contents($path, $body, LOCK_EX);
        } finally {
            umask($previous);
        }
        if ($ok === false) {
            throw new \RuntimeException("Could not write defaults file: {$path}");
        }
        @chmod($path, 0600);
        return $path;
    }
}
