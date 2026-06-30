<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;

/**
 * Schema Service - Dumps and manages database schemas from the main server
 * Used during provisioning to clone the exact DB structure to new servers
 */
class SchemaService
{
    private Container $container;
    private string $schemaDir;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->schemaDir = ($container->getConfig('templates.path') ?? __DIR__ . '/../../../templates') . '/database';
    }

    /**
     * Get the provisioning database config
     * Auto-detects fleet source_db from the running database config
     */
    public function getDbConfig(): array
    {
        $config = $this->container->getConfig('provisioning.databases') ?? [
            'shared' => [
                'source_db' => 'devc_vps_dash',
                'target_db' => 'devc_vps_dash',
                'target_user' => 'vpsadmin',
            ],
            'mail' => [
                'source_db' => 'mailserver',
                'target_db' => 'mailserver',
                'target_user' => 'mailuser',
            ],
            'fleet' => [
                'source_db' => 'fleet_manager',
                'target_db' => 'fleet_manager',
                'target_user' => 'fleet_manager',
            ],
        ];

        // Auto-detect fleet source_db from the running database config
        // On the main server, the fleet DB may have a different name (e.g., fleet_devcon1_hu)
        $actualDbName = $this->container->getConfig('database.name');
        if ($actualDbName && isset($config['fleet'])) {
            $config['fleet']['source_db'] = $actualDbName;
        }

        return $config;
    }

    /**
     * Ensure all schema dumps exist. If missing, dump from local DB or use static fallback.
     * Called automatically at the start of provisioning.
     */
    public function ensureSchemas(): bool
    {
        if (!is_dir($this->schemaDir)) {
            mkdir($this->schemaDir, 0755, true);
        }

        $dbConfig = $this->getDbConfig();
        $allOk = true;

        foreach ($dbConfig as $key => $config) {
            $schemaFile = $this->getSchemaPath($key);

            // Dump if file doesn't exist, is older than 7 days, or is suspiciously small
            $needsDump = !file_exists($schemaFile) 
                || (time() - filemtime($schemaFile)) > 604800
                || filesize($schemaFile) < 500;
            if ($needsDump) {
                if (!$this->dumpSchema($config['source_db'], $schemaFile)) {
                    // Try static fallback schema (for databases that don't exist on the main server)
                    if (!$this->useStaticFallback($key, $schemaFile)) {
                        $allOk = false;
                    }
                }
            }
        }

        return $allOk;
    }

    /**
     * Use a static fallback schema file when the source DB doesn't exist on the main server.
     * This is common for 'mailserver' which only exists on deployed servers, not the fleet manager server.
     */
    private function useStaticFallback(string $key, string $outputPath): bool
    {
        $dbConfig = $this->getDbConfig();
        $sourceDb = $dbConfig[$key]['source_db'] ?? $key;
        $targetDb = $dbConfig[$key]['target_db'] ?? $key;

        // Look for static schema files - try multiple naming conventions
        $candidates = [
            $this->schemaDir . '/' . $targetDb . '-static.sql',   // e.g., mailserver-static.sql
            $this->schemaDir . '/' . $key . '-static.sql',        // e.g., mail-static.sql
        ];

        foreach ($candidates as $staticFile) {
            if (file_exists($staticFile)) {
                $content = file_get_contents($staticFile);
                if (!empty($content) && strlen($content) > 50) {
                    $header = "-- Static schema (source DB '{$sourceDb}' not available on main server)\n";
                    $header .= "-- Loaded from: {$staticFile}\n";
                    $header .= "-- Applied at: " . date('Y-m-d H:i:s') . "\n\n";
                    $header .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
                    $footer = "\n\nSET FOREIGN_KEY_CHECKS=1;\n";
                    file_put_contents($outputPath, $header . $content . $footer);
                    error_log("SchemaService: Using static fallback schema for '{$key}' from {$staticFile}");
                    return true;
                }
            }
        }

        error_log("SchemaService: No static fallback found for '{$key}' (tried: " . implode(', ', $candidates) . ")");
        return false;
    }

    /**
     * Dump schema (structure only) from a local database using mysqldump/mariadb-dump
     * Runs locally on the main server where Fleet Manager is installed
     */
    public function dumpSchema(string $dbName, string $outputPath): bool
    {
        // Try mariadb-dump first (modern), then mysqldump
        $tools = ['mariadb-dump', 'mysqldump'];
        $output = null;

        // Build credential sets to try: root socket auth first, then configured credentials
        $credentialSets = [
            ['user' => 'root', 'pass' => null], // root socket auth
        ];

        // If the database matches our configured DB, use its credentials too
        $configDbName = $this->container->getConfig('database.name');
        $configDbUser = $this->container->getConfig('database.user');
        $configDbPass = $this->container->getConfig('database.password');
        if ($configDbName === $dbName && $configDbUser && $configDbPass) {
            $credentialSets[] = ['user' => $configDbUser, 'pass' => $configDbPass];
        }

        // Also try provisioning DB credentials for known databases (e.g., mailserver uses mailuser)
        $provDatabases = $this->container->getConfig('provisioning.databases') ?? [];
        foreach ($provDatabases as $provConfig) {
            if (($provConfig['source_db'] ?? '') === $dbName && !empty($provConfig['source_user']) && !empty($provConfig['source_pass'])) {
                $credentialSets[] = ['user' => $provConfig['source_user'], 'pass' => $provConfig['source_pass']];
            }
        }

        foreach ($tools as $tool) {
            foreach ($credentialSets as $creds) {
                if ($creds['pass']) {
                    // Use MYSQL_PWD env var to avoid password on command line
                    $cmd = sprintf(
                        'MYSQL_PWD=%s %s -u %s --no-data --skip-comments --skip-add-drop-table --compact %s 2>/dev/null',
                        escapeshellarg($creds['pass']),
                        $tool,
                        escapeshellarg($creds['user']),
                        escapeshellarg($dbName)
                    );
                } else {
                    $cmd = sprintf(
                        '%s -u root --no-data --skip-comments --skip-add-drop-table --compact %s 2>/dev/null',
                        $tool,
                        escapeshellarg($dbName)
                    );
                }

                $output = shell_exec($cmd);
                if (!empty($output)) {
                    break 2;
                }
            }
        }

        if (empty($output) || strlen(trim($output)) < 50) {
            error_log("SchemaService: Failed to dump schema for {$dbName} - mysqldump/mariadb-dump returned empty/tiny output");
            return false;
        }

        // Validate dump contains actual table definitions (not just whitespace/headers)
        if (stripos($output, 'CREATE TABLE') === false) {
            error_log("SchemaService: Dump for {$dbName} contains no CREATE TABLE statements ({$dbName} may be empty) - will use static fallback");
            return false;
        }

        // Post-process the SQL dump:
        // 1. Add IF NOT EXISTS to CREATE TABLE statements (safe re-import)
        $output = str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $output);

        // 2. Remove any USE or CREATE DATABASE statements
        $output = preg_replace('/^(USE |CREATE DATABASE ).*$/m', '', $output);

        // 3. Remove empty lines from cleanup
        $output = preg_replace('/\n{3,}/', "\n\n", $output);

        // 4. Add header with FK checks disabled (tables may be dumped in wrong order)
        $header = "-- Schema dump from main server\n";
        $header .= "-- Source database: {$dbName}\n";
        $header .= "-- Dumped at: " . date('Y-m-d H:i:s') . "\n";
        $header .= "-- This file is auto-generated by Fleet Manager SchemaService\n\n";
        $header .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        $footer = "\n\nSET FOREIGN_KEY_CHECKS=1;\n";

        $output = $header . $output . $footer;

        return file_put_contents($outputPath, $output) !== false;
    }

    /**
     * Get the file path for a schema dump
     * Uses the config key (shared/mail/fleet) as filename for stability
     */
    public function getSchemaPath(string $key): string
    {
        return $this->schemaDir . '/' . $key . '-schema.sql';
    }

    /**
     * Check if all schema dumps are available
     */
    public function hasSchemas(): bool
    {
        $dbConfig = $this->getDbConfig();

        foreach ($dbConfig as $key => $config) {
            if (!file_exists($this->getSchemaPath($key))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Force refresh all schema dumps (called from API or CLI)
     */
    public function refreshSchemas(): array
    {
        if (!is_dir($this->schemaDir)) {
            mkdir($this->schemaDir, 0755, true);
        }

        $dbConfig = $this->getDbConfig();
        $results = [];

        foreach ($dbConfig as $key => $config) {
            $sourceDb = $config['source_db'];
            $schemaFile = $this->getSchemaPath($key);
            $success = $this->dumpSchema($sourceDb, $schemaFile);

            $results[$key] = [
                'database' => $sourceDb,
                'success' => $success,
                'path' => $schemaFile,
                'size' => $success ? filesize($schemaFile) : 0,
            ];
        }

        return $results;
    }

    /**
     * Get status of all schema dumps
     */
    public function getStatus(): array
    {
        $dbConfig = $this->getDbConfig();
        $status = [];

        foreach ($dbConfig as $key => $config) {
            $path = $this->getSchemaPath($key);
            $exists = file_exists($path);

            $status[$key] = [
                'database' => $config['source_db'],
                'target_db' => $config['target_db'],
                'target_user' => $config['target_user'],
                'schema_exists' => $exists,
                'schema_size' => $exists ? filesize($path) : 0,
                'schema_age' => $exists ? time() - filemtime($path) : null,
                'schema_date' => $exists ? date('Y-m-d H:i:s', filemtime($path)) : null,
            ];
        }

        return $status;
    }
}

