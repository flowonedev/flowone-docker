<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Support;

/**
 * Lazy, reconnecting PDO accessor for the panel MariaDB.
 *
 * Foundation rationale:
 *   - Every Provisioner service needs a PDO. Building a new one per
 *     service per call would saturate connections and slow down hot
 *     paths (state transitions, audit writes).
 *   - The agent daemon is long-lived. Connections go stale across
 *     forks and MariaDB restarts. We `SELECT 1` before reuse to detect
 *     stale handles and transparently reconnect.
 *   - In subprocesses spawned via fork (heavy actions in agent.php,
 *     step-runner subprocess later) the parent's connection must NEVER
 *     be reused. After fork we drop our cached handle and force a
 *     fresh connect on next access. Caller signals this with
 *     `forgetConnection()` which the agent's fork wrapper will invoke.
 *
 * Config lookup order:
 *   - Explicit array passed to ::fromConfig()
 *   - /var/www/vps-admin/api/config.local.php  (overlay)
 *   - /var/www/vps-admin/api/config.php
 *   - Throws if neither exists.
 */
final class PanelDatabase
{
    private static ?self $instance = null;

    private ?\PDO $pdo = null;
    private array $dbConfig;

    private function __construct(array $dbConfig)
    {
        $this->dbConfig = $dbConfig;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = self::fromDefaultConfigFiles();
        }
        return self::$instance;
    }

    public static function fromConfig(array $dbConfig): self
    {
        return new self($dbConfig);
    }

    public static function fromDefaultConfigFiles(): self
    {
        $configFile = '/var/www/vps-admin/api/config.php';
        $localConfigFile = '/var/www/vps-admin/api/config.local.php';

        if (!file_exists($configFile)) {
            // Local-dev fallback so tests can run without the panel installed.
            return new self([
                'host' => 'localhost',
                'port' => 3306,
                'name' => 'devc_vps_dash',
                'user' => 'vpsadmin',
                'password' => '7bcf619af819e4e274e5cfdfba022274',
                'charset' => 'utf8mb4',
            ]);
        }

        $config = require $configFile;
        if (file_exists($localConfigFile)) {
            $localConfig = require $localConfigFile;
            $config = array_replace_recursive($config, $localConfig);
        }

        return new self($config['database'] ?? []);
    }

    public function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            try {
                $this->pdo->query('SELECT 1');
                return $this->pdo;
            } catch (\PDOException) {
                $this->pdo = null;
            }
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->dbConfig['host'] ?? 'localhost',
            $this->dbConfig['port'] ?? 3306,
            $this->dbConfig['name'] ?? '',
            $this->dbConfig['charset'] ?? 'utf8mb4'
        );

        $this->pdo = new \PDO(
            $dsn,
            $this->dbConfig['user'] ?? '',
            $this->dbConfig['password'] ?? $this->dbConfig['pass'] ?? '',
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        return $this->pdo;
    }

    /**
     * Drop the cached connection. MUST be called by the child immediately
     * after pcntl_fork() so the child does not share a MySQL handle with
     * the parent (which corrupts both sides).
     */
    public function forgetConnection(): void
    {
        $this->pdo = null;
    }

    /**
     * Return the resolved DB config. Used by adapters that need the
     * same credentials without re-implementing the config-loading
     * pipeline (e.g. MysqlAdapter test wiring its credentialsProvider).
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->dbConfig;
    }

    /**
     * Test-only: reset the static instance so each test starts with a fresh state.
     */
    public static function resetForTesting(): void
    {
        self::$instance = null;
    }
}
