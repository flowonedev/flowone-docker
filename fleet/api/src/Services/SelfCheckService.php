<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;

/**
 * Self-Check Service
 * 
 * Fleet Manager runs ON the main server. This service discovers its own state
 * automatically: PHP, database, agent, filesystem, config completeness.
 * Returns a structured JSON report with issues and suggested actions.
 */
class SelfCheckService
{
    private Container $container;
    private array $checks = [];
    private array $issues = [];

    // Required PHP extensions
    private const REQUIRED_EXTENSIONS = [
        'pdo_mysql',
        'json',
        'mbstring',
        'openssl',
        'curl',
        'sockets',
        'posix',
    ];

    // Required database tables
    private const REQUIRED_TABLES = [
        'admin_users',
        'sessions',
        'login_attempts',
        'blueprints',
        'blueprint_templates',
        'servers',
        'deployments',
        'server_health',
        'server_errors',
        'packages',
        'audit_logs',
        'settings',
        'migrations',
    ];

    // Required writable directories (relative to project root)
    private const REQUIRED_DIRS = [
        'var',
        'var/snapshots',
        'var/logs',
        'var/cache',
        'templates',
        'packages',
    ];

    // Required config keys in config.local.php
    private const REQUIRED_CONFIG_KEYS = [
        'database.password',
        'jwt.secret',
        'encryption.key',
    ];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Run all self-checks and return structured report
     */
    public function runAll(): array
    {
        $this->checks = [];
        $this->issues = [];

        $this->checkPhp();
        $this->checkDatabase();
        $this->checkAgent();
        $this->checkFilesystem();
        $this->checkConfig();
        $this->checkTemplates();
        $this->checkPackages();

        $overallStatus = 'healthy';
        $criticalCount = 0;
        $warningCount = 0;

        foreach ($this->issues as $issue) {
            if ($issue['severity'] === 'critical') {
                $criticalCount++;
                $overallStatus = 'unhealthy';
            } elseif ($issue['severity'] === 'warning') {
                $warningCount++;
                if ($overallStatus !== 'unhealthy') {
                    $overallStatus = 'degraded';
                }
            }
        }

        return [
            'status' => $overallStatus,
            'timestamp' => date('c'),
            'summary' => [
                'total_checks' => count($this->checks),
                'passed' => count(array_filter($this->checks, fn($c) => $c['status'] === 'ok')),
                'warnings' => $warningCount,
                'critical' => $criticalCount,
            ],
            'checks' => $this->checks,
            'issues' => $this->issues,
        ];
    }

    /**
     * Run bootstrap actions to fix auto-fixable issues
     */
    public function bootstrap(): array
    {
        $actions = [];

        // Create missing directories
        $projectRoot = dirname(__DIR__, 3);
        foreach (self::REQUIRED_DIRS as $dir) {
            $fullPath = $projectRoot . '/' . $dir;
            if (!is_dir($fullPath)) {
                if (@mkdir($fullPath, 0775, true)) {
                    $actions[] = ['action' => 'create_dir', 'path' => $dir, 'result' => 'created'];
                } else {
                    $actions[] = ['action' => 'create_dir', 'path' => $dir, 'result' => 'failed'];
                }
            }
        }

        // Run pending migrations
        try {
            $migrationsPath = $projectRoot . '/database/migrations';
            $migrationService = new MigrationService(
                $this->container->getDatabase(),
                $migrationsPath
            );
            $migrationResult = $migrationService->runPendingMigrations();
            $actions[] = [
                'action' => 'run_migrations',
                'result' => empty($migrationResult['errors']) ? 'success' : 'partial',
                'details' => $migrationResult,
            ];
        } catch (\Exception $e) {
            $actions[] = [
                'action' => 'run_migrations',
                'result' => 'failed',
                'error' => $e->getMessage(),
            ];
        }

        // Generate agent token if missing
        $tokenFile = $this->container->getConfig('agent.token_file')
            ?? '/var/www/vps-fleet/var/agent.token';
        if (!file_exists($tokenFile)) {
            $token = bin2hex(random_bytes(32));
            $tokenDir = dirname($tokenFile);
            if (!is_dir($tokenDir)) {
                @mkdir($tokenDir, 0775, true);
            }
            if (@file_put_contents($tokenFile, $token)) {
                @chmod($tokenFile, 0600);
                $actions[] = ['action' => 'generate_agent_token', 'result' => 'created'];
            } else {
                $actions[] = ['action' => 'generate_agent_token', 'result' => 'failed', 'error' => 'Cannot write token file'];
            }
        }

        return [
            'success' => true,
            'actions' => $actions,
        ];
    }

    // ----- Individual Check Methods -----

    private function checkPhp(): void
    {
        // PHP version
        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '8.1.0', '>=');
        $this->addCheck('php_version', 'PHP Version', $phpOk ? 'ok' : 'fail', [
            'version' => $phpVersion,
            'required' => '>= 8.1.0',
        ]);
        if (!$phpOk) {
            $this->addIssue('critical', 'PHP version ' . $phpVersion . ' is too old. Requires >= 8.1.0', 'php_version');
        }

        // Extensions
        $missing = [];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }
        $this->addCheck('php_extensions', 'PHP Extensions', empty($missing) ? 'ok' : 'fail', [
            'required' => self::REQUIRED_EXTENSIONS,
            'loaded' => array_values(array_filter(self::REQUIRED_EXTENSIONS, fn($e) => extension_loaded($e))),
            'missing' => $missing,
        ]);
        if (!empty($missing)) {
            $this->addIssue(
                'critical',
                'Missing PHP extensions: ' . implode(', ', $missing),
                'php_extensions',
                'Install with: apt install ' . implode(' ', array_map(fn($e) => "php8.3-{$e}", $missing))
            );
        }
    }

    private function checkDatabase(): void
    {
        // Connection
        try {
            $db = $this->container->getDatabase();
            $db->query("SELECT 1");
            $this->addCheck('db_connection', 'Database Connection', 'ok', [
                'host' => $this->container->getConfig('database.host'),
                'name' => $this->container->getConfig('database.name'),
            ]);
        } catch (\Exception $e) {
            $this->addCheck('db_connection', 'Database Connection', 'fail', [
                'error' => $e->getMessage(),
            ]);
            $this->addIssue('critical', 'Cannot connect to database: ' . $e->getMessage(), 'db_connection');
            return; // Can't check tables if DB is down
        }

        // Tables
        $existingTables = [];
        try {
            $stmt = $db->query("SHOW TABLES");
            $existingTables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Exception $e) {
            // ignore
        }

        $missingTables = array_diff(self::REQUIRED_TABLES, $existingTables);
        $this->addCheck('db_tables', 'Database Tables', empty($missingTables) ? 'ok' : 'warn', [
            'required' => self::REQUIRED_TABLES,
            'existing' => $existingTables,
            'missing' => array_values($missingTables),
        ]);
        if (!empty($missingTables)) {
            $this->addIssue(
                'warning',
                'Missing database tables: ' . implode(', ', $missingTables),
                'db_tables',
                'Run bootstrap to apply pending migrations'
            );
        }

        // Pending migrations
        try {
            $projectRoot = dirname(__DIR__, 3);
            $migrationService = new MigrationService($db, $projectRoot . '/database/migrations');
            $status = $migrationService->getStatus();
            $hasPending = count($status['pending']) > 0;
            $this->addCheck('db_migrations', 'Database Migrations', $hasPending ? 'warn' : 'ok', $status);
            if ($hasPending) {
                $this->addIssue(
                    'warning',
                    count($status['pending']) . ' pending migration(s): ' . implode(', ', $status['pending']),
                    'db_migrations',
                    'Run bootstrap to apply pending migrations'
                );
            }
        } catch (\Exception $e) {
            $this->addCheck('db_migrations', 'Database Migrations', 'warn', ['error' => $e->getMessage()]);
        }
    }

    private function checkAgent(): void
    {
        $socketPath = $this->container->getConfig('agent.socket') ?? '/run/fleet-manager/agent.sock';
        $tokenFile = $this->container->getConfig('agent.token_file') ?? '/var/www/vps-fleet/var/agent.token';

        // Socket exists
        $socketExists = file_exists($socketPath);
        
        // Token file exists
        $tokenExists = file_exists($tokenFile);

        // Try connecting to agent
        $agentResponding = false;
        if ($socketExists) {
            try {
                $agentService = $this->container->get(AgentService::class);
                $agentResponding = $agentService->isRunning();
            } catch (\Exception $e) {
                // ignore
            }
        }

        $agentStatus = 'fail';
        if ($agentResponding) {
            $agentStatus = 'ok';
        } elseif ($socketExists) {
            $agentStatus = 'warn';
        }

        $this->addCheck('agent', 'Fleet Agent Daemon', $agentStatus, [
            'socket_path' => $socketPath,
            'socket_exists' => $socketExists,
            'token_file' => $tokenFile,
            'token_exists' => $tokenExists,
            'responding' => $agentResponding,
        ]);

        if (!$socketExists) {
            $this->addIssue(
                'critical',
                'Fleet Agent is not installed or not running',
                'agent',
                'Install with: bash /var/www/vps-fleet/agent/install.sh && systemctl start fleet-agent'
            );
        } elseif (!$agentResponding) {
            $this->addIssue(
                'warning',
                'Fleet Agent socket exists but not responding',
                'agent',
                'Restart with: systemctl restart fleet-agent'
            );
        }

        if (!$tokenExists) {
            $this->addIssue(
                'warning',
                'Agent token file missing',
                'agent_token',
                'Run bootstrap to generate agent token'
            );
        }
    }

    private function checkFilesystem(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $missingDirs = [];
        $nonWritable = [];

        foreach (self::REQUIRED_DIRS as $dir) {
            $fullPath = $projectRoot . '/' . $dir;
            if (!is_dir($fullPath)) {
                $missingDirs[] = $dir;
            } elseif (!is_writable($fullPath)) {
                $nonWritable[] = $dir;
            }
        }

        $fsOk = empty($missingDirs) && empty($nonWritable);
        $this->addCheck('filesystem', 'Filesystem', $fsOk ? 'ok' : 'warn', [
            'project_root' => $projectRoot,
            'missing_dirs' => $missingDirs,
            'non_writable' => $nonWritable,
        ]);

        if (!empty($missingDirs)) {
            $this->addIssue(
                'warning',
                'Missing directories: ' . implode(', ', $missingDirs),
                'filesystem',
                'Run bootstrap to create them'
            );
        }
        if (!empty($nonWritable)) {
            $this->addIssue(
                'warning',
                'Non-writable directories: ' . implode(', ', $nonWritable),
                'filesystem',
                'Fix with: chown -R www-data:www-data ' . $projectRoot . '/var'
            );
        }
    }

    private function checkConfig(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $localConfigPath = $projectRoot . '/api/config.local.php';
        $localConfigExists = file_exists($localConfigPath);

        $this->addCheck('config_local', 'Local Config File', $localConfigExists ? 'ok' : 'fail', [
            'path' => $localConfigPath,
            'exists' => $localConfigExists,
        ]);

        if (!$localConfigExists) {
            $this->addIssue(
                'critical',
                'config.local.php not found - database credentials and secrets are not configured',
                'config_local',
                'Create api/config.local.php with database password, jwt.secret, and encryption.key'
            );
            return;
        }

        // Check required config values are set
        $missingKeys = [];
        foreach (self::REQUIRED_CONFIG_KEYS as $key) {
            $value = $this->container->getConfig($key);
            if (empty($value)) {
                $missingKeys[] = $key;
            }
        }

        $this->addCheck('config_values', 'Config Values', empty($missingKeys) ? 'ok' : 'warn', [
            'missing_keys' => $missingKeys,
        ]);

        if (!empty($missingKeys)) {
            $this->addIssue(
                'warning',
                'Empty config values: ' . implode(', ', $missingKeys),
                'config_values',
                'Set these values in api/config.local.php'
            );
        }
    }

    private function checkTemplates(): void
    {
        // Check blueprint templates in database (the primary source)
        $dbTemplateCount = 0;
        $blueprintCount = 0;
        $dbCategories = [];

        try {
            $db = $this->container->getDatabase();
            
            // Count blueprints
            $stmt = $db->query("SELECT COUNT(*) FROM blueprints");
            $blueprintCount = (int) $stmt->fetchColumn();

            // Count templates per category in DB
            $stmt = $db->query(
                "SELECT category, COUNT(*) as cnt FROM blueprint_templates GROUP BY category ORDER BY category"
            );
            while ($row = $stmt->fetch()) {
                $dbCategories[$row['category']] = (int) $row['cnt'];
            }
            $dbTemplateCount = array_sum($dbCategories);
        } catch (\Exception $e) {
            // DB might not be ready yet
        }

        // Also check reference templates on disk
        $templatesPath = $this->container->getConfig('templates.path')
            ?? dirname(__DIR__, 3) . '/templates';

        $diskCategories = [];
        if (is_dir($templatesPath)) {
            $dirs = @scandir($templatesPath);
            if ($dirs) {
                foreach ($dirs as $dir) {
                    if ($dir === '.' || $dir === '..') continue;
                    $catPath = $templatesPath . '/' . $dir;
                    if (is_dir($catPath)) {
                        $files = glob($catPath . '/*.template');
                        if (count($files) > 0) {
                            $diskCategories[$dir] = count($files);
                        }
                    }
                }
            }
        }

        // Check snapshots
        $snapshotsPath = dirname(__DIR__, 3) . '/var/snapshots';
        $snapshotCount = 0;
        if (is_dir($snapshotsPath)) {
            $snapshotFiles = glob($snapshotsPath . '/*.json');
            // Exclude index.json
            $snapshotCount = count(array_filter($snapshotFiles, fn($f) => basename($f) !== 'index.json'));
        }

        $hasBlueprints = $blueprintCount > 0;
        $hasDbTemplates = $dbTemplateCount > 0;
        $hasDiskTemplates = !empty($diskCategories);
        $hasSnapshots = $snapshotCount > 0;

        // Status logic: OK if we have blueprints with templates, WARN otherwise
        $status = 'warn';
        if ($hasDbTemplates) {
            $status = 'ok';
        } elseif ($hasDiskTemplates) {
            $status = 'ok';
        }

        $this->addCheck('templates', 'Config Templates', $status, [
            'blueprints' => $blueprintCount,
            'db_templates' => $dbTemplateCount,
            'db_categories' => $dbCategories,
            'reference_templates_path' => $templatesPath,
            'reference_templates' => array_sum($diskCategories),
            'reference_categories' => $diskCategories,
            'snapshots' => $snapshotCount,
            'note' => 'Templates are dynamically generated from server snapshots. Take a snapshot first, then create a blueprint.',
        ]);

        if (!$hasDbTemplates && !$hasSnapshots) {
            $this->addIssue(
                'warning',
                'No server snapshots or blueprint templates found. Take a snapshot of this server to generate templates dynamically.',
                'templates',
                'Go to Blueprints > Create Blueprint > Take Snapshot to read the server config and generate templates.'
            );
        } elseif ($hasSnapshots && !$hasDbTemplates) {
            $this->addIssue(
                'info',
                'Snapshots exist but no blueprints created yet. Create a blueprint from a snapshot to generate deployable templates.',
                'templates',
                'Go to Blueprints > Create from Snapshot.'
            );
        }
    }

    private function checkPackages(): void
    {
        $packagesPath = $this->container->getConfig('packages.path')
            ?? dirname(__DIR__, 3) . '/packages';

        $available = [];
        foreach (['panel', 'email', 'agent', 'fleet'] as $pkg) {
            $pkgDir = $packagesPath . '/' . $pkg;
            $available[$pkg] = [
                'dir_exists' => is_dir($pkgDir),
                'has_build_script' => file_exists($pkgDir . '/build.sh'),
                'has_install_script' => file_exists($pkgDir . '/install.sh'),
            ];
        }

        $this->addCheck('packages', 'Deployment Packages', 'ok', [
            'path' => $packagesPath,
            'available' => $available,
        ]);
    }

    // ----- Helpers -----

    private function addCheck(string $id, string $label, string $status, array $details = []): void
    {
        $this->checks[] = [
            'id' => $id,
            'label' => $label,
            'status' => $status, // ok, warn, fail
            'details' => $details,
        ];
    }

    private function addIssue(string $severity, string $message, string $checkId, ?string $fix = null): void
    {
        $issue = [
            'severity' => $severity, // critical, warning, info
            'message' => $message,
            'check_id' => $checkId,
        ];
        if ($fix) {
            $issue['fix'] = $fix;
        }
        $this->issues[] = $issue;
    }
}

