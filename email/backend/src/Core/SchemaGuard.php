<?php

namespace Webmail\Core;

/**
 * SchemaGuard — run a service's self-healing DDL at most once per code
 * version instead of on every request.
 *
 * Background: ~45 services historically called ensureTablesExist()/ensureX()
 * from their constructor. routes.php instantiates controllers (and therefore
 * their services) eagerly on every request, so that DDL — CREATE TABLE IF NOT
 * EXISTS plus a stack of guarded ALTERs and information_schema probes — ran on
 * EVERY request. Each statement takes a metadata lock + disk hit: sub-second on
 * fast storage but pathological on slow/network volumes, and pure waste at any
 * scale.
 *
 * SchemaGuard records in a tiny `schema_guards` table which (class, version)
 * pairs have already been applied and skips the DDL when the marker matches.
 * The version is the mtime of the service's own source file, so a redeploy that
 * changes the file re-runs its DDL exactly once and then skips again. A MySQL
 * named lock serialises that first run across workers (mirrors MigrationService)
 * so a cold-start burst cannot deadlock on metadata locks.
 *
 * Call site — in the service constructor, replacing `$this->ensureTablesExist();`:
 *
 *     \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTablesExist());
 *
 * The closure's defining class and source file are derived via reflection, so
 * no key, version, or connection needs to be passed.
 */
class SchemaGuard
{
    /** @var array<string,string> class/key => version already verified this request */
    private static array $verified = [];
    private static bool $tableReady = false;

    /**
     * Run $migrate at most once per (defining class, source-file mtime).
     *
     * Best-effort: any bookkeeping failure falls back to running $migrate so
     * the schema is never left un-healed.
     */
    public static function run(callable $migrate): void
    {
        try {
            $ref = new \ReflectionFunction(\Closure::fromCallable($migrate));
            $scope = $ref->getClosureScopeClass();
            $file = $ref->getFileName() ?: null;
            $key = $scope ? $scope->getName() : ($file ?: 'closure');
        } catch (\Throwable $e) {
            // Reflection failed — just run it.
            $migrate();
            return;
        }

        $version = ($file && @filemtime($file)) ? (string) filemtime($file) : '0';

        self::runFor(Database::peek(), $key, $version, $migrate);
    }

    /**
     * Lower-level entry point with an explicit key/version/connection. Used by
     * run() (which derives them via reflection) and by tests.
     */
    public static function runFor(?\PDO $db, string $key, string $version, callable $migrate): void
    {
        // In-process: this key was already verified at this version this request.
        if ((self::$verified[$key] ?? null) === $version) {
            return;
        }

        if (!$db instanceof \PDO) {
            // No shared connection yet — run unguarded (the DDL is idempotent).
            $migrate();
            self::$verified[$key] = $version;
            return;
        }

        try {
            self::ensureGuardTable($db);

            if (self::isApplied($db, $key, $version)) {
                self::$verified[$key] = $version;
                return;
            }

            // Serialise the first run across workers so concurrent CREATE/ALTER
            // statements can't deadlock on table metadata locks.
            $lockName = 'flowone_schema_' . substr(md5($key), 0, 24);
            if (!self::acquireLock($db, $lockName)) {
                // Another worker holds the lock and is applying it; a later
                // request will see the marker and skip. Don't run concurrently.
                return;
            }

            try {
                // Re-check now that we hold the lock — another worker may have
                // just finished applying it.
                if (!self::isApplied($db, $key, $version)) {
                    $migrate();
                    self::mark($db, $key, $version);
                }
                self::$verified[$key] = $version;
            } finally {
                self::releaseLock($db, $lockName);
            }
        } catch (\Throwable $e) {
            error_log('SchemaGuard(' . $key . ') error: ' . $e->getMessage());
            // Degrade gracefully: run the DDL directly this request.
            try {
                $migrate();
                self::$verified[$key] = $version;
            } catch (\Throwable $e2) {
                error_log('SchemaGuard(' . $key . ') fallback error: ' . $e2->getMessage());
            }
        }
    }

    private static function ensureGuardTable(\PDO $db): void
    {
        if (self::$tableReady) {
            return;
        }
        $db->exec(
            'CREATE TABLE IF NOT EXISTS schema_guards (
                guard_key VARCHAR(191) NOT NULL PRIMARY KEY,
                version VARCHAR(64) NOT NULL,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        self::$tableReady = true;
    }

    private static function isApplied(\PDO $db, string $key, string $version): bool
    {
        $stmt = $db->prepare('SELECT version FROM schema_guards WHERE guard_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $current = $stmt->fetchColumn();
        return $current !== false && (string) $current === $version;
    }

    private static function mark(\PDO $db, string $key, string $version): void
    {
        $stmt = $db->prepare(
            'INSERT INTO schema_guards (guard_key, version) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE version = VALUES(version)'
        );
        $stmt->execute([$key, $version]);
    }

    private static function acquireLock(\PDO $db, string $name): bool
    {
        $stmt = $db->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([$name, 5]);
        return (string) $stmt->fetchColumn() === '1';
    }

    private static function releaseLock(\PDO $db, string $name): void
    {
        try {
            $stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([$name]);
        } catch (\Throwable $e) {
            // The lock frees automatically when the session ends.
        }
    }
}
