<?php

namespace Webmail\Core;

/**
 * Database Singleton
 * 
 * Provides a single shared PDO connection for the entire application.
 * Eliminates the connection explosion caused by every service creating
 * its own PDO instance (which was causing 60-80 sleeping MySQL threads).
 * 
 * Usage:
 *   $db = Database::getConnection($config);
 * 
 * All services and controllers should use this instead of new PDO().
 */
class Database
{
    private static ?\PDO $instance = null;
    private static ?string $currentDsn = null;

    /**
     * Get the shared database connection.
     * Creates it on first call, reuses it on subsequent calls.
     */
    public static function getConnection(array $config): \PDO
    {
        $dbConfig = $config['db'] ?? $config;

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $dbConfig['host'] ?? '127.0.0.1',
            $dbConfig['name'] ?? 'devc_vps_dash'
        );

        // If we already have a connection with the same DSN, reuse it
        if (self::$instance !== null && self::$currentDsn === $dsn) {
            // Verify the connection is still alive
            try {
                self::$instance->query('SELECT 1');
                return self::$instance;
            } catch (\PDOException $e) {
                // Connection died, will recreate below
                self::$instance = null;
            }
        }

        self::$instance = new \PDO(
            $dsn,
            $dbConfig['user'] ?? 'vpsadmin',
            $dbConfig['pass'] ?? '',
            [
                \PDO::ATTR_ERRMODE              => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE   => \PDO::FETCH_ASSOC,
                \PDO::MYSQL_ATTR_INIT_COMMAND   => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
                // Buffer SELECT result sets in PHP memory so the cursor
                // closes immediately after fetch* — without this, long-
                // running CLI processes (the sync daemon) intermittently
                // hit "SQLSTATE[HY000] 2014 Cannot execute queries while
                // other unbuffered queries are active" on the next write
                // after a fetch() that didn't drain the cursor.
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                // NO ATTR_PERSISTENT - that was causing the connection explosion
            ]
        );

        self::$currentDsn = $dsn;

        return self::$instance;
    }

    /**
     * Validate a previously-handed-out PDO and return either the same
     * instance (if it's still alive) or a fresh one. Long-running CLI
     * processes (crons that loop over many accounts) cache their PDO
     * at construction time and reuse it; MariaDB closes idle
     * connections after wait_timeout (often 120-600s), at which point
     * the cached PDO starts throwing "SQLSTATE[HY000] 2006 MySQL
     * server has gone away" on every subsequent query.
     *
     * Call this at the top of every tick / loop iteration. It costs
     * one round-trip when the connection IS alive (sub-millisecond
     * local Unix-socket SELECT 1) and one full reconnect when it's
     * dead. The returned PDO replaces any cached references the caller
     * was holding; the caller MUST reassign and also rebuild any
     * helpers it built on top of the old PDO.
     */
    public static function pingOrReconnect(\PDO $pdo, array $config): \PDO
    {
        try {
            $pdo->query('SELECT 1')->closeCursor();
            return $pdo;
        } catch (\PDOException $e) {
            // Force a fresh connect by invalidating the singleton.
            self::$instance = null;
            self::$currentDsn = null;
            return self::getConnection($config);
        }
    }

    /**
     * Return the already-established shared connection without needing $config,
     * or null if none has been created yet. Used by infrastructure helpers
     * (e.g. SchemaGuard) that run inside a service that just opened the
     * connection and so don't carry $config themselves.
     */
    public static function peek(): ?\PDO
    {
        return self::$instance;
    }

    /**
     * Close the shared connection (e.g. before a long-running operation).
     */
    public static function close(): void
    {
        self::$instance = null;
        self::$currentDsn = null;
    }

    /**
     * Prevent instantiation
     */
    private function __construct() {}
    private function __clone() {}
}

