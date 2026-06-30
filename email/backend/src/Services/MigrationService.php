<?php

namespace Webmail\Services;

/**
 * MigrationService - Auto-run SQL migrations on first deployment
 * 
 * This service tracks which migrations have been executed and runs
 * any pending migrations in alphabetical order.
 */
class MigrationService
{
    private \PDO $db;
    private string $migrationsPath;
    private static bool $hasRun = false;
    
    public function __construct(array $config)
    {
        $dbConfig = $config['db'] ?? $config;
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $dbConfig['host'] ?? '127.0.0.1',
            $dbConfig['name'] ?? 'devc_vps_dash'
        );
        $this->db = new \PDO($dsn, $dbConfig['user'] ?? '', $dbConfig['pass'] ?? '', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);
        
        $this->migrationsPath = __DIR__ . '/../../migrations';
    }
    
    /**
     * Run all pending migrations
     * Returns array of results for each migration
     */
    public function runPendingMigrations(): array
    {
        // Only run once per request
        if (self::$hasRun) {
            return [];
        }
        self::$hasRun = true;

        // Cross-process guard: only one worker runs migrations at a time.
        // Without this, a burst of requests on a cold start (or any moment a
        // pending migration exists) each runs the FULL suite concurrently and
        // the parallel ALTER/CREATE statements deadlock on table metadata
        // locks. A MySQL named lock serialises the run: the first worker holds
        // it and migrates; the others block briefly and, once they acquire it,
        // see every migration already applied and no-op. If the lock can't be
        // acquired within the timeout (a long run on slow storage), skip
        // silently — a later request will finish the job.
        $lockName = 'flowone_migrations';
        $lockTimeout = 10; // seconds
        $gotLock = false;
        try {
            $stmt = $this->db->prepare('SELECT GET_LOCK(?, ?)');
            $stmt->execute([$lockName, $lockTimeout]);
            $gotLock = ((string)$stmt->fetchColumn() === '1');
        } catch (\Throwable $e) {
            // GET_LOCK unavailable on this engine — fall back to unguarded run.
            $gotLock = true;
        }

        if (!$gotLock) {
            return [];
        }

        try {
            $this->ensureMigrationsTableExists();

            $executed = $this->getExecutedMigrations();
            $files = $this->getMigrationFiles();

            $results = [];

            foreach ($files as $file) {
                $name = basename($file);

                if (!in_array($name, $executed)) {
                    $results[] = $this->runMigration($file, $name);
                }
            }

            return $results;
        } finally {
            try {
                $rel = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                $rel->execute([$lockName]);
            } catch (\Throwable $e) {
                // Best-effort release; the lock also frees when the session ends.
            }
        }
    }
    
    /**
     * Ensure the migrations tracking table exists
     */
    private function ensureMigrationsTableExists(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                success TINYINT(1) DEFAULT 1,
                error_message TEXT DEFAULT NULL,
                INDEX idx_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
    
    /**
     * Get list of already executed migrations
     */
    private function getExecutedMigrations(): array
    {
        $stmt = $this->db->query("SELECT name FROM migrations WHERE success = 1");
        return $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
    }
    
    /**
     * Get all migration files sorted alphabetically
     */
    private function getMigrationFiles(): array
    {
        $pattern = $this->migrationsPath . '/*.sql';
        $files = glob($pattern);
        
        if ($files === false) {
            return [];
        }
        
        sort($files); // Alphabetical order
        return $files;
    }
    
    /**
     * Execute a single migration
     */
    private function runMigration(string $file, string $name): array
    {
        $result = [
            'name' => $name,
            'success' => false,
            'error' => null
        ];
        
        try {
            $sql = file_get_contents($file);
            
            if ($sql === false) {
                throw new \Exception("Could not read migration file: $file");
            }
            
            // Skip empty files or comment-only files
            $cleanedSql = $this->removeComments($sql);
            if (empty(trim($cleanedSql))) {
                $result['success'] = true;
                $result['error'] = 'Skipped (empty or comments only)';
                $this->recordMigration($name, true, null);
                return $result;
            }
            
            // Split by semicolons and execute each statement
            // Note: MySQL auto-commits DDL statements (CREATE TABLE, ALTER TABLE, etc.)
            // so we execute each statement directly without wrapping in a transaction
            $statements = $this->splitStatements($sql);
            $idempotentWarnings = [];
            
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement)) {
                    try {
                        // Use query() instead of exec() so we get a PDOStatement and can
                        // consume any result sets. CALL <procedure> returns empty result
                        // sets that, if left unconsumed, trigger SQLSTATE[HY000] 2014
                        // ("Cannot execute queries while other unbuffered queries are active")
                        // on the next statement.
                        $stmt = $this->db->query($statement);
                        if ($stmt instanceof \PDOStatement) {
                            do {
                                $stmt->closeCursor();
                            } while ($stmt->nextRowset());
                        }
                    } catch (\Exception $stmtEx) {
                        // If this is an "already applied" error, skip and continue
                        if ($this->isIdempotentError($stmtEx)) {
                            $idempotentWarnings[] = $stmtEx->getMessage();
                            continue;
                        }
                        // Real error - rethrow
                        throw $stmtEx;
                    }
                }
            }
            
            // Record successful migration
            $note = !empty($idempotentWarnings)
                ? 'Completed with ' . count($idempotentWarnings) . ' skipped (already applied) statement(s)'
                : null;
            $this->recordMigration($name, true, $note);
            
            $result['success'] = true;
            error_log("Migration executed successfully: $name" . ($note ? " - $note" : ''));
            
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $result['error'] = $errorMessage;
            
            // Treat idempotent DDL errors as success (migration was already applied)
            // 1060 = Duplicate column name, 1050 = Table already exists,
            // 1061 = Duplicate key name, 1068 = Multiple primary key,
            // 01000 = Data truncated warning (harmless for ENUM expansions)
            if ($this->isIdempotentError($e)) {
                $this->recordMigration($name, true, 'Auto-resolved: ' . $errorMessage);
                $result['success'] = true;
                error_log("Migration auto-resolved (already applied): $name");
            } else {
                $this->recordMigration($name, false, $errorMessage);
                error_log("Migration failed: $name - $errorMessage");
            }
        }
        
        return $result;
    }
    
    /**
     * Check if a migration error is idempotent (means the change was already applied).
     * These errors are safe to treat as success.
     */
    private function isIdempotentError(\Exception $e): bool
    {
        $code = $e->getCode();
        $msg = $e->getMessage();
        
        // MySQL error codes that indicate "already done"
        $idempotentCodes = [
            '42S21',  // Column already exists (1060)
            '42S01',  // Table already exists (1050)
            '42000',  // Duplicate key name (1061), multiple primary key (1068)
            '01000',  // Data truncated warning (harmless for ENUM expansions)
        ];
        
        // Check SQLSTATE code
        if (in_array((string)$code, $idempotentCodes, true)) {
            return true;
        }
        
        // Also check by message patterns for safety
        $idempotentPatterns = [
            'Duplicate column name',
            'Table \'.*\' already exists',
            'Duplicate key name',
            'Duplicate foreign key constraint name',
            // MariaDB reports a re-added FK/constraint name as
            //   1005 Can't create table ... (errno: 121 "Duplicate key on write or update")
            // rather than the MySQL "Duplicate foreign key constraint name"
            // (1826). errno 121 specifically means the constraint name
            // already exists, so on a migration re-run it is idempotent.
            // (errno 150 = FK formation failure is a REAL error and is NOT
            // matched here.)
            'errno: 121',
            'Multiple primary key defined',
            'Data truncated for column',
        ];
        
        foreach ($idempotentPatterns as $pattern) {
            if (preg_match('/' . $pattern . '/i', $msg)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Record migration execution in the database
     */
    private function recordMigration(string $name, bool $success, ?string $error): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO migrations (name, success, error_message) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE success = VALUES(success), error_message = VALUES(error_message), executed_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$name, $success ? 1 : 0, $error]);
    }
    
    /**
     * Remove SQL comments from a string
     */
    private function removeComments(string $sql): string
    {
        // Remove -- style comments
        $sql = preg_replace('/--.*$/m', '', $sql);
        // Remove /* */ style comments
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        return $sql;
    }
    
    /**
     * Split SQL into individual statements
     * Handles DELIMITER changes for stored procedures
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $currentStatement = '';
        $delimiter = ';';
        $lines = explode("\n", $sql);
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            
            // Skip empty lines and comments
            if (empty($trimmedLine) || strpos($trimmedLine, '--') === 0) {
                continue;
            }
            
            // Check for DELIMITER change
            if (preg_match('/^DELIMITER\s+(\S+)\s*$/i', $trimmedLine, $matches)) {
                $delimiter = $matches[1];
                continue;
            }
            
            $currentStatement .= $line . "\n";
            
            // Check if line ends with current delimiter
            $delimiterPattern = preg_quote($delimiter, '/');
            if (preg_match('/' . $delimiterPattern . '\s*$/', $trimmedLine)) {
                // Remove the delimiter from the statement
                $stmt = rtrim($currentStatement);
                if ($delimiter !== ';') {
                    $stmt = preg_replace('/' . $delimiterPattern . '\s*$/', '', $stmt);
                }
                if (!empty(trim($stmt))) {
                    $statements[] = $stmt;
                }
                $currentStatement = '';
            }
        }
        
        // Add any remaining statement
        if (!empty(trim($currentStatement))) {
            $statements[] = $currentStatement;
        }
        
        return $statements;
    }
    
    /**
     * Get migration status (for debugging/admin)
     */
    public function getMigrationStatus(): array
    {
        $this->ensureMigrationsTableExists();
        
        $executed = [];
        $stmt = $this->db->query("SELECT name, executed_at, success, error_message FROM migrations ORDER BY executed_at DESC");
        while ($row = $stmt->fetch()) {
            $executed[$row['name']] = $row;
        }
        
        $files = $this->getMigrationFiles();
        $status = [];
        
        foreach ($files as $file) {
            $name = basename($file);
            $status[] = [
                'name' => $name,
                'executed' => isset($executed[$name]),
                'success' => isset($executed[$name]) ? (bool)$executed[$name]['success'] : null,
                'executed_at' => $executed[$name]['executed_at'] ?? null,
                'error' => $executed[$name]['error_message'] ?? null
            ];
        }
        
        return $status;
    }
}

