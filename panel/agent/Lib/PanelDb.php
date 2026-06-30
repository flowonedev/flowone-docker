<?php

namespace VpsAdmin\Agent\Lib;

/**
 * Shared connector for the panel (API) database.
 *
 * Credentials always come from the API config
 * (/var/www/vps-admin/api/config.php + config.local.php). The agent's own
 * config.php only contains placeholder credentials and must never be used
 * for panel DB access - the cron backup runner doing exactly that is what
 * caused "Access denied for user 'devc_vps_dash'" and every scheduled NAS
 * backup silently degrading to local-only.
 *
 * The connection is cached but re-validated with a ping before reuse so
 * long-running daemons survive MariaDB's wait_timeout
 * ("MySQL server has gone away").
 */
class PanelDb
{
    private const CONFIG_FILE = '/var/www/vps-admin/api/config.php';
    private const LOCAL_CONFIG_FILE = '/var/www/vps-admin/api/config.local.php';

    private static ?\PDO $pdo = null;
    private static string $lastError = '';

    public static function get(): ?\PDO
    {
        if (self::$pdo !== null) {
            if (self::ping(self::$pdo)) {
                return self::$pdo;
            }
            // Stale connection (wait_timeout, server restart) - reconnect.
            self::$pdo = null;
        }

        return self::connect();
    }

    /** Human-readable reason for the last failed get()/connect(). */
    public static function lastError(): string
    {
        return self::$lastError;
    }

    private static function ping(\PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function connect(): ?\PDO
    {
        if (!file_exists(self::CONFIG_FILE)) {
            self::$lastError = 'API config not found: ' . self::CONFIG_FILE;
            return null;
        }

        $config = require self::CONFIG_FILE;
        if (file_exists(self::LOCAL_CONFIG_FILE)) {
            $localConfig = require self::LOCAL_CONFIG_FILE;
            $config = array_replace_recursive($config, $localConfig);
        }

        $db = $config['database'] ?? [];

        try {
            self::$pdo = new \PDO(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $db['host'] ?? 'localhost',
                    $db['port'] ?? 3306,
                    $db['name'] ?? '',
                    $db['charset'] ?? 'utf8mb4'
                ),
                $db['user'] ?? '',
                $db['password'] ?? '',
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 5,
                ]
            );
            self::$lastError = '';
            return self::$pdo;
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
            self::$pdo = null;
            return null;
        }
    }
}
