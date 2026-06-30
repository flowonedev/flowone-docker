<?php

namespace FleetManager\Api\Core;

/**
 * Simple dependency injection container
 */
class Container
{
    private array $config;
    private array $instances = [];
    private ?\PDO $db = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getConfig(string $key = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return null;
            }
            $value = $value[$k];
        }

        return $value;
    }

    public function getDatabase(): \PDO
    {
        if ($this->db === null) {
            $dbConfig = $this->config['database'];
            
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['name'],
                $dbConfig['charset']
            );

            $this->db = new \PDO(
                $dsn,
                $dbConfig['user'],
                $dbConfig['password'],
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        }

        return $this->db;
    }

    public function get(string $class): ?object
    {
        // If already instantiated, return it
        if (isset($this->instances[$class])) {
            return $this->instances[$class];
        }
        
        // If it's a class that exists, instantiate it
        if (class_exists($class)) {
            $this->instances[$class] = new $class($this);
            return $this->instances[$class];
        }
        
        // Not a class and not set - return null
        return null;
    }
    
    public function has(string $key): bool
    {
        return isset($this->instances[$key]);
    }

    public function set(string $key, object $instance): void
    {
        $this->instances[$key] = $instance;
    }

    /**
     * Reset the database connection (forces reconnect on next getDatabase() call)
     * Used by long-running CLI processes when MySQL times out the idle connection.
     */
    public function resetDatabase(): void
    {
        $this->db = null;
    }
}

