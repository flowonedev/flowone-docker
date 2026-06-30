<?php

namespace FleetManager\Api\Services;

/**
 * Database Migration Service
 * Automatically runs pending migrations on startup
 */
class MigrationService
{
    private \PDO $db;
    private string $migrationsPath;
    private string $migrationsTable = 'migrations';

    public function __construct(\PDO $db, string $migrationsPath)
    {
        $this->db = $db;
        $this->migrationsPath = $migrationsPath;
        
        // Enable buffered queries to avoid "unbuffered queries" error
        $this->db->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }

    /**
     * Run all pending migrations
     */
    public function runPendingMigrations(): array
    {
        $results = [
            'applied' => [],
            'skipped' => [],
            'errors' => [],
        ];

        // Ensure migrations table exists
        $this->createMigrationsTable();

        // Get already applied migrations
        $applied = $this->getAppliedMigrations();

        // Get all migration files
        $files = $this->getMigrationFiles();

        foreach ($files as $file) {
            $migrationName = basename($file);
            
            if (in_array($migrationName, $applied)) {
                $results['skipped'][] = $migrationName;
                continue;
            }

            try {
                $this->runMigration($file, $migrationName);
                $results['applied'][] = $migrationName;
            } catch (\Exception $e) {
                $results['errors'][$migrationName] = $e->getMessage();
                error_log("Migration error in {$migrationName}: " . $e->getMessage());
                // Don't stop on errors - continue with other migrations
            }
        }

        return $results;
    }

    /**
     * Create migrations tracking table
     */
    private function createMigrationsTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->migrationsTable} (
            id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            migration VARCHAR(255) NOT NULL,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_migration (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->db->exec($sql);
    }

    /**
     * Get list of already applied migrations
     */
    private function getAppliedMigrations(): array
    {
        try {
            $stmt = $this->db->query("SELECT migration FROM {$this->migrationsTable}");
            $result = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            $stmt->closeCursor();
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get all migration files sorted by name
     */
    private function getMigrationFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath . '/*.sql');
        sort($files); // Sort by filename (001_, 002_, etc.)
        
        return $files;
    }

    /**
     * Run a single migration
     */
    private function runMigration(string $file, string $migrationName): void
    {
        $sql = file_get_contents($file);
        
        if (empty(trim($sql))) {
            throw new \Exception('Empty migration file');
        }

        // Split by semicolons to run multiple statements
        // But be careful with statements that might contain semicolons in strings
        $statements = $this->splitSqlStatements($sql);

        $this->db->beginTransaction();

        try {
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement) && !$this->isCommentOnly($statement)) {
                    $stmt = $this->db->query($statement);
                    // Consume any results to avoid unbuffered query issues
                    if ($stmt) {
                        $stmt->closeCursor();
                    }
                }
            }

            // Record migration as applied
            $stmt = $this->db->prepare(
                "INSERT INTO {$this->migrationsTable} (migration) VALUES (?)"
            );
            $stmt->execute([$migrationName]);
            $stmt->closeCursor();

            $this->db->commit();
            
            error_log("Migration applied: {$migrationName}");
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Split SQL into individual statements
     */
    private function splitSqlStatements(string $sql): array
    {
        // Remove comments that are on their own lines
        $sql = preg_replace('/^--.*$/m', '', $sql);
        
        // Split by semicolons not inside quotes
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        
        for ($i = 0; $i < strlen($sql); $i++) {
            $char = $sql[$i];
            
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
            } elseif ($inString && $char === $stringChar && ($i === 0 || $sql[$i-1] !== '\\')) {
                $inString = false;
            }
            
            if ($char === ';' && !$inString) {
                $statements[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }
        
        if (!empty(trim($current))) {
            $statements[] = $current;
        }
        
        return $statements;
    }

    /**
     * Check if statement is just comments
     */
    private function isCommentOnly(string $statement): bool
    {
        $statement = preg_replace('/--.*$/m', '', $statement);
        $statement = preg_replace('/\/\*.*?\*\//s', '', $statement);
        return empty(trim($statement));
    }

    /**
     * Get migration status
     */
    public function getStatus(): array
    {
        $this->createMigrationsTable();
        
        $applied = $this->getAppliedMigrations();
        $files = array_map('basename', $this->getMigrationFiles());
        $pending = array_diff($files, $applied);

        return [
            'applied' => $applied,
            'pending' => array_values($pending),
            'total_files' => count($files),
            'total_applied' => count($applied),
        ];
    }
}

