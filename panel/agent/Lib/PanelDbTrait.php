<?php
/**
 * PanelDbTrait
 *
 * Shared access to the panel/app database (the same MariaDB the Email App and
 * Dovecot/Postfix use). Extracted from MailAction so other agent actions can
 * reuse the proven connect + stale-connection healthcheck without duplicating
 * the logic. The connection is cached and re-validated with a cheap SELECT 1
 * because the agent is a long-running daemon and MySQL drops idle connections.
 */

namespace VpsAdmin\Agent\Lib;

trait PanelDbTrait
{
    protected ?\PDO $panelPdo = null;

    /**
     * Get the panel database connection, reconnecting if it has gone stale.
     * Returns null if the panel config or connection is unavailable.
     */
    protected function getPanelDb(): ?\PDO
    {
        // Reuse the cached connection only if it is still alive.
        if ($this->panelPdo !== null) {
            try {
                $this->panelPdo->query('SELECT 1');
                return $this->panelPdo;
            } catch (\PDOException $e) {
                $this->panelPdo = null;
                if (isset($this->logger)) {
                    $this->logger->warning('Panel database connection was stale, reconnecting', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $configFile = '/var/www/vps-admin/api/config.php';
        $localConfigFile = '/var/www/vps-admin/api/config.local.php';

        if (!file_exists($configFile)) {
            return null;
        }

        $config = require $configFile;
        if (file_exists($localConfigFile)) {
            $localConfig = require $localConfigFile;
            $config = array_replace_recursive($config, $localConfig);
        }

        try {
            $dbConfig = $config['database'] ?? [];
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $dbConfig['host'] ?? 'localhost',
                $dbConfig['port'] ?? 3306,
                $dbConfig['name'] ?? '',
                $dbConfig['charset'] ?? 'utf8mb4'
            );

            $this->panelPdo = new \PDO(
                $dsn,
                $dbConfig['user'] ?? '',
                $dbConfig['password'] ?? '',
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 5,
                    \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
                ]
            );
            return $this->panelPdo;
        } catch (\Exception $e) {
            error_log('Failed to connect to panel database: ' . $e->getMessage());
            return null;
        }
    }
}
