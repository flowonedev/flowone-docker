<?php
/**
 * Database Action Handler
 * 
 * Manages MySQL/MariaDB databases and users.
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\Validator;

class DatabaseAction extends BaseAction
{
    private ?\PDO $pdo = null;
    private int $lastConnectionTime = 0;
    private const CONNECTION_MAX_AGE = 3600; // Reconnect every hour

    public function getNamespace(): string
    {
        return 'db';
    }

    public function getMethods(): array
    {
        return ['list', 'get', 'create', 'delete', 'users', 'createUser', 'deleteUser', 'resetPassword', 'size'];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['delete', 'deleteUser']);
    }

    /**
     * Get PDO connection with auto-reconnect
     */
    private function getConnection(): \PDO
    {
        $now = time();
        
        // Check if we need to reconnect (connection too old or doesn't exist)
        $needsReconnect = (
            $this->pdo === null || 
            ($now - $this->lastConnectionTime) > self::CONNECTION_MAX_AGE
        );
        
        // Test existing connection if it exists
        if ($this->pdo !== null && !$needsReconnect) {
            try {
                // Ping the connection to check if it's still alive
                $this->pdo->query('SELECT 1');
            } catch (\PDOException $e) {
                // Connection is dead, need to reconnect
                $needsReconnect = true;
                $this->pdo = null;
            }
        }
        
        if ($needsReconnect) {
            $this->pdo = null; // Clear old connection
            $socket = '/var/run/mysqld/mysqld.sock';
            $password = $this->getMySqlPassword();
            
            $this->pdo = new \PDO(
                "mysql:unix_socket={$socket}",
                'root',
                $password,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 10,
                    \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]
            );
            
            $this->lastConnectionTime = $now;
        }
        
        return $this->pdo;
    }

    /**
     * Read MySQL password from .my.cnf or config
     */
    private function getMySqlPassword(): string
    {
        // Try /root/.my.cnf first (CyberPanel default)
        $mycnf = '/root/.my.cnf';
        if (file_exists($mycnf)) {
            $content = file_get_contents($mycnf);
            if (preg_match('/password\s*=\s*["\']?([^"\'\\n]+)["\']?/i', $content, $matches)) {
                return trim($matches[1]);
            }
        }
        
        // Try agent config
        if (isset($this->config['mysql']['password'])) {
            return $this->config['mysql']['password'];
        }
        
        return '';
    }

    /**
     * List all databases
     */
    protected function actionList(array $params, string $actor): array
    {
        $pdo = $this->getConnection();
        $showAll = $params['show_all'] ?? true;
        
        $stmt = $pdo->query("SHOW DATABASES");
        $databases = [];
        
        $systemDbs = [
            'information_schema', 'mysql', 'performance_schema', 'sys',
            'devc_vps_dash', 'fleet_manager', 'mailserver',
        ];
        
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $dbName = $row['Database'];
            $isSystem = in_array($dbName, $systemDbs);
            
            if (!$showAll && $isSystem) {
                continue;
            }
            
            // Get users with access to this database
            $users = [];
            try {
                $userStmt = $pdo->prepare("SELECT DISTINCT User, Host FROM mysql.db WHERE Db = ?");
                $userStmt->execute([$dbName]);
                $users = $userStmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                // Skip if no permission
            }
            
            // Try to determine linked site/application from naming convention
            $linkedSite = null;
            // Common patterns: prefix_dbname, username_dbname
            if (preg_match('/^([a-z]+)_/', $dbName, $matches)) {
                $linkedSite = $matches[1];
            }
            
            $databases[] = [
                'name' => $dbName,
                'size' => $this->getDatabaseSize($dbName),
                'size_human' => $this->humanFileSize($this->getDatabaseSize($dbName)),
                'is_system' => $isSystem,
                'users' => $users,
                'linked_site' => $linkedSite,
                'tables_count' => $this->getTablesCount($dbName),
            ];
        }

        // Sort: user databases first, then system
        usort($databases, function($a, $b) {
            if ($a['is_system'] !== $b['is_system']) {
                return $a['is_system'] ? 1 : -1;
            }
            return strcmp($a['name'], $b['name']);
        });

        return $this->success(['databases' => $databases]);
    }

    /**
     * Get tables count for a database
     */
    private function getTablesCount(string $name): int
    {
        try {
            $pdo = $this->getConnection();
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?");
            $stmt->execute([$name]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return (int)($row['cnt'] ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get database details
     */
    protected function actionGet(array $params, string $actor): array
    {
        if (!isset($params['name'])) {
            return $this->error('Database name is required');
        }

        $name = $params['name'];
        
        if (!Validator::databaseName($name)) {
            return $this->error('Invalid database name');
        }

        $pdo = $this->getConnection();
        
        // Check if exists
        $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->execute([$name]);
        
        if (!$stmt->fetch()) {
            return $this->error("Database not found: {$name}");
        }

        // Get tables
        $stmt = $pdo->prepare("SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH 
                               FROM information_schema.TABLES 
                               WHERE TABLE_SCHEMA = ?");
        $stmt->execute([$name]);
        $tables = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Get users with access
        $stmt = $pdo->prepare("SELECT DISTINCT User, Host FROM mysql.db WHERE Db = ?");
        $stmt->execute([$name]);
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $this->success([
            'name' => $name,
            'size' => $this->getDatabaseSize($name),
            'tables' => $tables,
            'users' => $users,
        ]);
    }

    /**
     * Create a new database
     */
    protected function actionCreate(array $params, string $actor): array
    {
        if (!isset($params['name'])) {
            return $this->error('Database name is required');
        }

        $name = $params['name'];
        
        if (!Validator::databaseName($name)) {
            return $this->error('Invalid database name. Use only letters, numbers, and underscores.');
        }

        $pdo = $this->getConnection();

        // Check if exists
        $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->execute([$name]);
        
        if ($stmt->fetch()) {
            return $this->error("Database already exists: {$name}");
        }

        // Create database (name already validated by Validator::databaseName above)
        $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        $pdo->exec("CREATE DATABASE `{$safeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Create user if requested
        if (isset($params['user'])) {
            $user = $params['user'];
            $password = $params['password'] ?? $this->generatePassword();
            
            if (!Validator::username($user)) {
                return $this->error('Invalid username');
            }

            $host = $params['host'] ?? 'localhost';
            if (!Validator::dbHost($host)) {
                return $this->error('Invalid host value');
            }

            $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $user);
            $safeHost = preg_replace('/[^a-zA-Z0-9._%-]/', '', $host);
            $quotedPass = $pdo->quote($password);
            
            $pdo->exec("CREATE USER IF NOT EXISTS '{$safeUser}'@'{$safeHost}' IDENTIFIED BY {$quotedPass}");
            $pdo->exec("GRANT ALL PRIVILEGES ON `{$safeName}`.* TO '{$safeUser}'@'{$safeHost}'");
            $pdo->exec("FLUSH PRIVILEGES");

            return $this->success([
                'database' => $name,
                'user' => $user,
                'host' => $host,
                'password' => $password,
            ], "Database {$name} created with user {$user}");
        }

        return $this->success([
            'database' => $name,
        ], "Database {$name} created");
    }

    /**
     * Delete a database
     */
    protected function actionDelete(array $params, string $actor): array
    {
        if (!isset($params['name'])) {
            return $this->error('Database name is required');
        }

        $name = $params['name'];
        
        if (!Validator::databaseName($name)) {
            return $this->error('Invalid database name');
        }

        // Protected databases - system and panel-critical
        $protectedDbs = [
            // MySQL system databases
            'information_schema', 
            'mysql', 
            'performance_schema', 
            'sys',
            // Panel and hosting databases
            'devc_vps_dash',      // VPS Admin panel database
            'fleet_manager',      // Fleet Manager agent database
            'mailserver',         // Mail server database (Postfix/Dovecot)
            'cyberpanel',         // CyberPanel database
            'pdns',               // PowerDNS database
            'roundcubemail',      // Roundcube webmail
            'phpmyadmin',         // phpMyAdmin
            'postfixadmin',       // Postfix admin
        ];
        
        if (in_array(strtolower($name), array_map('strtolower', $protectedDbs))) {
            return $this->error('Cannot delete protected database: ' . $name . '. This database is required for system operation.');
        }

        $pdo = $this->getConnection();

        // Database name already validated by Validator::databaseName above.
        $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $name);

        // Nothing to do if it's already gone - report success so the UI
        // (and any retry) settles cleanly instead of erroring.
        $existsStmt = $pdo->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
        $existsStmt->execute([$safeName]);
        if (!$existsStmt->fetch()) {
            return $this->success([
                'database' => $name,
                'backup' => null,
            ], "Database {$name} already absent.");
        }

        // Backup database first. Best-effort + time-bounded: a failed or
        // skipped dump (missing backups path, mysqldump unavailable) must
        // NOT block the drop, but we record whether it succeeded so the
        // caller knows if a recovery artifact exists.
        $backupFile = null;
        $backupSaved = false;
        $backupRoot = $this->config['paths']['backups'] ?? null;
        if (is_string($backupRoot) && $backupRoot !== '') {
            $backupFile = rtrim($backupRoot, '/') . '/databases/' . $name . '_' . date('Y-m-d_H-i-s') . '.sql';
            $backupDir = dirname($backupFile);
            if (!is_dir($backupDir)) {
                @mkdir($backupDir, 0750, true);
            }
            if (is_dir($backupDir)) {
                $dump = $this->execCommand('mysqldump', [$name, '--result-file=' . $backupFile], 120);
                $backupSaved = ($dump['success'] ?? false) && is_file($backupFile);
            }
        }

        // Drop database.
        $pdo->exec("DROP DATABASE IF EXISTS `{$safeName}`");

        // Verify the drop actually took effect. DROP can return without
        // a PHP-level error yet leave the schema in place if the
        // connection lacks DROP privilege; re-probe so we never report a
        // false "deleted" to the UI.
        $verifyStmt = $pdo->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
        $verifyStmt->execute([$safeName]);
        if ($verifyStmt->fetch()) {
            return $this->error(
                "DROP DATABASE reported no error but '{$name}' still exists. "
                . 'The MySQL account used by the agent likely lacks DROP privilege on it.'
            );
        }

        $message = $backupSaved
            ? "Database {$name} deleted. Backup saved."
            : "Database {$name} deleted.";

        return $this->success([
            'database' => $name,
            'backup' => $backupSaved ? $backupFile : null,
        ], $message);
    }

    /**
     * List database users
     */
    protected function actionUsers(array $params, string $actor): array
    {
        $pdo = $this->getConnection();
        
        $stmt = $pdo->query("SELECT User, Host, 
                             GROUP_CONCAT(DISTINCT Db SEPARATOR ', ') as databases
                             FROM mysql.db 
                             WHERE User != '' 
                             GROUP BY User, Host");
        
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $this->success(['users' => $users]);
    }

    /**
     * Create a database user
     */
    protected function actionCreateUser(array $params, string $actor): array
    {
        if (!isset($params['user'])) {
            return $this->error('Username is required');
        }

        $user = $params['user'];
        $password = $params['password'] ?? $this->generatePassword();
        $host = $params['host'] ?? 'localhost';
        
        if (!Validator::username($user)) {
            return $this->error('Invalid username');
        }

        $pdo = $this->getConnection();

        // Check if exists
        $stmt = $pdo->prepare("SELECT User FROM mysql.user WHERE User = ? AND Host = ?");
        $stmt->execute([$user, $host]);
        
        if ($stmt->fetch()) {
            return $this->error("User already exists: {$user}@{$host}");
        }

        $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $user);
        $safeHost = preg_replace('/[^a-zA-Z0-9._%-]/', '', $host);
        if (!Validator::dbHost($host)) {
            return $this->error('Invalid host value');
        }
        $quotedPass = $pdo->quote($password);

        $pdo->exec("CREATE USER '{$safeUser}'@'{$safeHost}' IDENTIFIED BY {$quotedPass}");

        // Grant to database if specified
        if (isset($params['database'])) {
            $db = $params['database'];
            if (!Validator::databaseName($db)) {
                return $this->error('Invalid database name');
            }
            $safeDb = preg_replace('/[^a-zA-Z0-9_]/', '', $db);
            $pdo->exec("GRANT ALL PRIVILEGES ON `{$safeDb}`.* TO '{$safeUser}'@'{$safeHost}'");
        }

        $pdo->exec("FLUSH PRIVILEGES");

        return $this->success([
            'user' => $user,
            'host' => $host,
            'password' => $password,
        ], "User {$user}@{$host} created");
    }

    /**
     * Delete a database user
     */
    protected function actionDeleteUser(array $params, string $actor): array
    {
        if (!isset($params['user'])) {
            return $this->error('Username is required');
        }

        $user = $params['user'];
        $host = $params['host'] ?? 'localhost';
        
        if (!Validator::username($user)) {
            return $this->error('Invalid username');
        }

        // Protected users - system and panel-critical
        $protectedUsers = [
            'root',
            'mysql.sys',
            'mysql.session',
            'mysql.infoschema',
            'debian-sys-maint',
            'devc',              // VPS Admin panel user
            'devc_vps_dash',     // VPS Admin panel DB user
            'vpsadmin',          // VPS Admin panel DB user (alt)
            'fleet_manager',     // Fleet Manager agent DB user
            'mailuser',          // Mail server DB user (Postfix/Dovecot)
            'cyberpanel',        // CyberPanel user
            'pdns',              // PowerDNS user
            'roundcube',         // Roundcube user
            'postfixadmin',      // Postfix admin user
            'vmail',             // Virtual mail user
        ];
        
        if (in_array(strtolower($user), array_map('strtolower', $protectedUsers))) {
            return $this->error('Cannot delete protected user: ' . $user . '. This user is required for system operation.');
        }

        $pdo = $this->getConnection();

        $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $user);
        $safeHost = preg_replace('/[^a-zA-Z0-9._%-]/', '', $host);
        $pdo->exec("DROP USER IF EXISTS '{$safeUser}'@'{$safeHost}'");
        $pdo->exec("FLUSH PRIVILEGES");

        return $this->success([
            'user' => $user,
            'host' => $host,
        ], "User {$user}@{$host} deleted");
    }

    /**
     * Reset user password
     */
    protected function actionResetPassword(array $params, string $actor): array
    {
        if (!isset($params['user'])) {
            return $this->error('Username is required');
        }

        $user = $params['user'];
        $password = $params['password'] ?? $this->generatePassword();
        $host = $params['host'] ?? 'localhost';
        
        if (!Validator::username($user)) {
            return $this->error('Invalid username');
        }

        $pdo = $this->getConnection();

        $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $user);
        $safeHost = preg_replace('/[^a-zA-Z0-9._%-]/', '', $host);
        $quotedPass = $pdo->quote($password);

        // Self-healing reset. A degraded provision can leave the MySQL
        // user missing (or without grants) even though the panel still
        // lists it from database_links. A plain ALTER USER on a missing
        // user errors out, leaving the operator unable to log in. Create
        // the user if absent, then ALTER to set the password regardless -
        // both idempotent.
        $pdo->exec("CREATE USER IF NOT EXISTS '{$safeUser}'@'{$safeHost}' IDENTIFIED BY {$quotedPass}");
        $pdo->exec("ALTER USER '{$safeUser}'@'{$safeHost}' IDENTIFIED BY {$quotedPass}");

        // Re-apply the grant on the linked database when one is supplied,
        // so a user whose GRANT step degraded can actually use the schema.
        $granted = null;
        $database = $params['database'] ?? null;
        if (is_string($database) && $database !== '' && Validator::databaseName($database)) {
            $safeDb = str_replace('`', '', $database);
            $pdo->exec("GRANT ALL PRIVILEGES ON `{$safeDb}`.* TO '{$safeUser}'@'{$safeHost}'");
            $granted = $database;
        }

        $pdo->exec("FLUSH PRIVILEGES");

        return $this->success([
            'user' => $user,
            'host' => $host,
            'password' => $password,
            'granted_database' => $granted,
        ], "Password reset for {$user}@{$host}");
    }

    /**
     * Get database size
     */
    protected function actionSize(array $params, string $actor): array
    {
        if (!isset($params['name'])) {
            return $this->error('Database name is required');
        }

        $name = $params['name'];
        
        if (!Validator::databaseName($name)) {
            return $this->error('Invalid database name');
        }

        $size = $this->getDatabaseSize($name);

        return $this->success([
            'database' => $name,
            'size_bytes' => $size,
            'size_human' => $this->humanFileSize($size),
        ]);
    }

    /**
     * Get database size in bytes
     */
    private function getDatabaseSize(string $name): int
    {
        $pdo = $this->getConnection();
        
        $stmt = $pdo->prepare("SELECT SUM(data_length + index_length) as size 
                               FROM information_schema.TABLES 
                               WHERE table_schema = ?");
        $stmt->execute([$name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return (int)($row['size'] ?? 0);
    }

    /**
     * Generate a secure random password
     */
    private function generatePassword(int $length = 16): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        $max = strlen($chars) - 1;
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max)];
        }
        
        return $password;
    }

    /**
     * Convert bytes to human readable
     */
    private function humanFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}

