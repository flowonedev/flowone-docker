<?php

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\BackupScheduleManager;
use VpsAdmin\Agent\Lib\PanelDb;

/**
 * Backup management actions
 * 
 * Uses panel's database for mail accounts and database links.
 * Supports NAS storage for remote backups.
 */
class BackupAction extends BaseAction
{
    private string $backupPath = '/var/www/vps-admin/backups';
    private string $cronFile = '/etc/cron.d/vps-admin-backups';

    /**
     * Get connection to our panel's database.
     *
     * Delegates to PanelDb, which pings the cached connection and
     * reconnects if MariaDB dropped it (wait_timeout in the long-running
     * agent daemon caused "MySQL server has gone away" here).
     */
    private function getPanelDb(): ?\PDO
    {
        $pdo = PanelDb::get();
        if ($pdo === null) {
            $this->logger->error('Failed to connect to panel database: ' . PanelDb::lastError());
        }
        return $pdo;
    }

    /**
     * Config paths for different services
     */
    private array $configPaths = [
        'webserver' => [
            'label' => 'Web Server (OpenLiteSpeed)',
            'paths' => [
                '/usr/local/lsws/conf/httpd_config.conf',
                '/usr/local/lsws/conf/vhosts/',
            ],
        ],
        'vhosts' => [
            'label' => 'Virtual Hosts (All Sites)',
            'paths' => [
                '/usr/local/lsws/conf/vhosts/',
            ],
        ],
        'php' => [
            'label' => 'PHP Configuration',
            'paths' => [
                '/usr/local/lsws/lsphp74/etc/php/7.4/litespeed/php.ini',
                '/usr/local/lsws/lsphp80/etc/php/8.0/litespeed/php.ini',
                '/usr/local/lsws/lsphp81/etc/php/8.1/litespeed/php.ini',
                '/usr/local/lsws/lsphp82/etc/php/8.2/litespeed/php.ini',
                '/usr/local/lsws/lsphp83/etc/php/8.3/litespeed/php.ini',
            ],
        ],
        'mysql' => [
            'label' => 'MySQL / MariaDB Configuration',
            'paths' => [
                '/etc/mysql/my.cnf',
                '/etc/mysql/mysql.conf.d/',
                '/etc/mysql/mariadb.conf.d/',
            ],
        ],
        'mail' => [
            'label' => 'Mail (Postfix & Dovecot)',
            'paths' => [
                '/etc/postfix/',
                '/etc/dovecot/',
            ],
        ],
        'dns' => [
            'label' => 'DNS (PowerDNS)',
            'paths' => [
                '/etc/powerdns/',
            ],
        ],
        'fail2ban' => [
            'label' => 'Fail2ban',
            'paths' => [
                '/etc/fail2ban/',
            ],
        ],
        'firewall' => [
            'label' => 'Firewall (firewalld)',
            'paths' => [
                '/etc/firewalld/',
            ],
        ],
        'ssl' => [
            'label' => 'SSL Certificates (Let\'s Encrypt)',
            'paths' => [
                '/etc/letsencrypt/',
                '/root/.acme.sh/',
            ],
        ],
        'modsec' => [
            'label' => 'ModSecurity (WAF Rules)',
            'paths' => [
                '/usr/local/lsws/conf/modsec.conf',
                '/usr/local/lsws/conf/modsec/',
            ],
        ],
        'cpguard' => [
            'label' => 'CPGuard (WAF & Security)',
            'paths' => [
                '/etc/cpguard/',
                '/opt/cpguard/blacklistips.txt',
                '/opt/cpguard/whitelistips.txt',
                '/opt/cpguard/whitelistdomains.txt',
            ],
        ],
        'cron' => [
            'label' => 'Cron Jobs',
            'paths' => [
                '/etc/crontab',
                '/etc/cron.d/',
                '/var/spool/cron/crontabs/',
            ],
        ],
        'ssh' => [
            'label' => 'SSH Configuration',
            'paths' => [
                '/etc/ssh/sshd_config',
                '/etc/ssh/ssh_config',
            ],
        ],
        'databases' => [
            'label' => 'All Databases (MySQL dump)',
            'paths' => [],
            'special' => 'databases',
        ],
    ];

    public function getNamespace(): string
    {
        return 'backup';
    }

    public function getMethods(): array
    {
        return [
            'create', 'getCategories', 'schedules', 'createSchedule', 'updateSchedule', 'deleteSchedule',
            'backupSites', 'backupSite', 'restoreSite', 'listSiteBackups', 'backupDatabase', 'restoreDatabase',
            // Inspection methods
            'inspectBackup', 'inspectConfigBackup',
            // Selective restore
            'restoreConfigBackup',
            // NAS methods
            'getNasConnections', 'listNasBackups', 'deleteNasBackups', 'transferToNas',
            // Schedule execution + cron daemon health
            'runSchedule', 'cronStatus', 'repairCron',
            // Status tracking
            'getBackupStatus', 'listRunningBackups',
            // Email backup methods
            'backupMail', 'restoreMail', 'listMailBackups', 'listMailDomains', 'inspectMailBackup'
        ];
    }

    public function requiresBackup(string $method): bool
    {
        return false;
    }

    protected function actionCreate(array $params, string $actor): array
    {
        return $this->create($params, $actor);
    }

    protected function actionGetCategories(array $params, string $actor): array
    {
        return $this->getCategories($params);
    }

    protected function actionSchedules(array $params, string $actor): array
    {
        return $this->getSchedules($params);
    }

    protected function actionCreateSchedule(array $params, string $actor): array
    {
        return $this->createSchedule($params, $actor);
    }

    protected function actionUpdateSchedule(array $params, string $actor): array
    {
        return $this->updateSchedule($params, $actor);
    }

    protected function actionDeleteSchedule(array $params, string $actor): array
    {
        return $this->deleteSchedule($params, $actor);
    }

    protected function actionBackupSites(array $params, string $actor): array
    {
        return $this->backupSites($params, $actor);
    }

    protected function actionBackupSite(array $params, string $actor): array
    {
        return $this->backupSite($params, $actor);
    }

    protected function actionRestoreSite(array $params, string $actor): array
    {
        return $this->restoreSite($params, $actor);
    }

    protected function actionListSiteBackups(array $params, string $actor): array
    {
        return $this->listSiteBackups($params);
    }

    protected function actionBackupDatabase(array $params, string $actor): array
    {
        return $this->backupDatabase($params, $actor);
    }

    protected function actionRestoreDatabase(array $params, string $actor): array
    {
        return $this->restoreDatabase($params, $actor);
    }
    
    protected function actionInspectBackup(array $params, string $actor): array
    {
        return $this->inspectBackup($params);
    }
    
    protected function actionInspectConfigBackup(array $params, string $actor): array
    {
        return $this->inspectConfigBackup($params);
    }
    
    protected function actionRestoreConfigBackup(array $params, string $actor): array
    {
        return $this->restoreConfigBackup($params, $actor);
    }
    
    protected function actionGetNasConnections(array $params, string $actor): array
    {
        return $this->getNasConnections();
    }
    
    protected function actionListNasBackups(array $params, string $actor): array
    {
        return $this->listNasBackups($params);
    }
    
    protected function actionDeleteNasBackups(array $params, string $actor): array
    {
        return $this->deleteNasBackups($params, $actor);
    }

    protected function actionTransferToNas(array $params, string $actor): array
    {
        return $this->transferToNas($params, $actor);
    }

    protected function actionRunSchedule(array $params, string $actor): array
    {
        return $this->runSchedule($params, $actor);
    }

    protected function actionCronStatus(array $params, string $actor): array
    {
        return $this->cronStatus();
    }

    protected function actionRepairCron(array $params, string $actor): array
    {
        return $this->repairCron($actor);
    }
    
    protected function actionGetBackupStatus(array $params, string $actor): array
    {
        return $this->getBackupStatus($params);
    }
    
    protected function actionListRunningBackups(array $params, string $actor): array
    {
        return $this->listRunningBackups();
    }

    // Email backup action handlers
    protected function actionBackupMail(array $params, string $actor): array
    {
        return $this->backupMail($params, $actor);
    }
    
    protected function actionRestoreMail(array $params, string $actor): array
    {
        return $this->restoreMail($params, $actor);
    }
    
    protected function actionListMailBackups(array $params, string $actor): array
    {
        return $this->listMailBackups($params);
    }
    
    protected function actionListMailDomains(array $params, string $actor): array
    {
        return $this->listMailDomains();
    }
    
    protected function actionInspectMailBackup(array $params, string $actor): array
    {
        return $this->inspectMailBackup($params);
    }

    /**
     * Get available backup categories
     */
    private function getCategories(array $params): array
    {
        $categories = [];
        
        foreach ($this->configPaths as $key => $config) {
            // Handle special database category
            if (isset($config['special']) && $config['special'] === 'databases') {
                $dbInfo = $this->getDatabasesInfo();
                $categories[] = [
                    'id' => $key,
                    'label' => $config['label'],
                    'exists' => $dbInfo['count'] > 0,
                    'size' => $dbInfo['size'],
                    'size_human' => $this->formatBytes($dbInfo['size']),
                    'paths' => [],
                    'description' => $dbInfo['count'] . ' databases',
                ];
                continue;
            }
            
            $exists = false;
            $size = 0;
            
            foreach ($config['paths'] as $path) {
                if (file_exists($path)) {
                    $exists = true;
                    if (is_file($path)) {
                        $size += filesize($path);
                    } elseif (is_dir($path)) {
                        $size += $this->getDirSize($path);
                    }
                }
            }
            
            $categories[] = [
                'id' => $key,
                'label' => $config['label'],
                'exists' => $exists,
                'size' => $size,
                'size_human' => $this->formatBytes($size),
                'paths' => $config['paths'],
            ];
        }
        
        return [
            'success' => true,
            'data' => [
                'categories' => $categories,
            ],
        ];
    }

    /**
     * Get info about all databases
     */
    private function getDatabasesInfo(): array
    {
        $count = 0;
        $totalSize = 0;
        
        try {
            $password = $this->getMySqlPassword();
            $pdo = new \PDO(
                "mysql:unix_socket=/var/run/mysqld/mysqld.sock",
                'root',
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );

            $systemDbs = ['information_schema', 'mysql', 'performance_schema', 'sys'];
            
            $stmt = $pdo->query("
                SELECT table_schema AS db, 
                       SUM(data_length + index_length) AS size 
                FROM information_schema.tables 
                GROUP BY table_schema
            ");
            
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                if (!in_array($row['db'], $systemDbs)) {
                    $count++;
                    $totalSize += (int)$row['size'];
                }
            }
        } catch (\Exception $e) {
            // Ignore errors
        }
        
        return [
            'count' => $count,
            'size' => $totalSize,
        ];
    }

    /**
     * Create a manual backup
     */
    private function create(array $params, string $actor): array
    {
        $categories = $params['categories'] ?? [];
        
        if (empty($categories)) {
            return ['success' => false, 'error' => 'No categories selected for backup'];
        }

        // Create backup directory
        $timestamp = date('Y-m-d_H-i-s');
        $backupDir = "{$this->backupPath}/manual/{$timestamp}";
        
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }

        $backedUp = [];
        $errors = [];

        foreach ($categories as $category) {
            if (!isset($this->configPaths[$category])) {
                $errors[] = "Unknown category: {$category}";
                continue;
            }

            $config = $this->configPaths[$category];
            $categoryDir = "{$backupDir}/{$category}";
            
            if (!is_dir($categoryDir)) {
                mkdir($categoryDir, 0750, true);
            }

            // Handle special categories
            if (isset($config['special']) && $config['special'] === 'databases') {
                $dbResult = $this->backupAllDatabases($categoryDir, $actor);
                if ($dbResult['success']) {
                    $backedUp = array_merge($backedUp, $dbResult['databases']);
                } else {
                    $errors[] = $dbResult['error'];
                }
                continue;
            }

            foreach ($config['paths'] as $path) {
                if (!file_exists($path)) {
                    continue;
                }

                $basename = basename($path);
                $destPath = "{$categoryDir}/{$basename}";

                if (is_file($path)) {
                    if (copy($path, $destPath)) {
                        $backedUp[] = $path;
                        $this->writeMetaFile($destPath, $path, 'manual_backup', $actor);
                    } else {
                        $errors[] = "Failed to backup: {$path}";
                    }
                } elseif (is_dir($path)) {
                    if ($this->copyDir($path, $destPath)) {
                        $backedUp[] = $path;
                        $this->writeMetaFile($destPath, $path, 'manual_backup', $actor);
                    } else {
                        $errors[] = "Failed to backup directory: {$path}";
                    }
                }
            }
        }

        // Create archive
        $archiveName = "backup_{$timestamp}.tar.gz";
        $archivePath = "{$this->backupPath}/manual/{$archiveName}";
        
        exec("cd {$backupDir} && tar -czf {$archivePath} . 2>&1", $output, $exitCode);
        
        // Clean up directory after archiving
        if ($exitCode === 0) {
            $this->removeDir($backupDir);
        }

        $this->logger->info('Manual backup created', [
            'actor' => $actor,
            'categories' => $categories,
            'backed_up' => count($backedUp),
            'errors' => count($errors),
        ]);

        // Copy to NAS if configured
        $destination = $params['destination'] ?? 'local'; // 'local', 'nas', 'both'
        $nasResult = null;
        $nasUploaded = false;
        
        if ($destination === 'nas' || $destination === 'both') {
            $nasResult = $this->uploadToNas($archivePath, 'manual');
            $nasUploaded = $nasResult['success'] ?? false;
        }

        // Create metadata file alongside the archive
        $archiveMeta = [
            'timestamp' => $timestamp,
            'actor' => $actor,
            'categories' => $categories,
            'category_labels' => array_map(fn($cat) => $this->configPaths[$cat]['label'] ?? $cat, $categories),
            'backed_up' => $backedUp,
            'errors' => $errors,
            'type' => 'config_backup',
            'destination' => $destination,
            'nas_uploaded' => $nasUploaded,
        ];
        file_put_contents("{$archivePath}.meta.json", json_encode($archiveMeta, JSON_PRETTY_PRINT));

        return [
            'success' => true,
            'data' => [
                'archive' => $archiveName,
                'path' => $archivePath,
                'backed_up' => $backedUp,
                'errors' => $errors,
                'nas' => $nasResult,
                'nas_uploaded' => $nasUploaded,
            ],
            'message' => count($backedUp) . ' items backed up' . ($nasUploaded ? ' (copied to NAS)' : ''),
        ];
    }

    /**
     * Backup all databases
     */
    private function backupAllDatabases(string $outputDir, string $actor): array
    {
        $databases = [];
        $errors = [];
        
        try {
            $password = $this->getMySqlPassword();
            $pdo = new \PDO(
                "mysql:unix_socket=/var/run/mysqld/mysqld.sock",
                'root',
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );

            // Get all databases except system ones
            $stmt = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA");
            $systemDbs = ['information_schema', 'mysql', 'performance_schema', 'sys'];
            
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $dbName = $row['SCHEMA_NAME'];
                
                if (in_array($dbName, $systemDbs)) {
                    continue;
                }

                $dumpFile = "{$outputDir}/{$dbName}.sql";
                $dumpResult = $this->dumpDatabase($dbName, $dumpFile);
                
                if ($dumpResult['success']) {
                    $databases[] = "database:{$dbName}";
                    
                    // Write meta file
                    $this->writeMetaFile($dumpFile, "mysql://{$dbName}", 'database_backup', $actor);
                } else {
                    $errors[] = "Failed to backup database {$dbName}";
                }
            }

            return [
                'success' => true,
                'databases' => $databases,
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Database backup failed: ' . $e->getMessage(),
                'databases' => $databases,
            ];
        }
    }

    /**
     * Get scheduled backups
     */
    private function getSchedules(array $params): array
    {
        $schedules = [];
        $runState = BackupScheduleManager::readRunState();

        if (file_exists($this->cronFile)) {
            $content = file_get_contents($this->cronFile);

            foreach (explode("\n", $content) as $line) {
                $schedule = BackupScheduleManager::parseCronLine($line);
                if ($schedule === null) {
                    continue;
                }

                // Determine backup type and parse relevant options
                if (preg_match('/--sites=([^\s]+)/', $schedule['command'], $siteMatches)) {
                    // Site backup schedule
                    $schedule['type'] = 'site';
                    $schedule['sites'] = $siteMatches[1] === 'all' ? 'all' : explode(',', $siteMatches[1]);

                    // Parse components
                    if (preg_match('/--components=([^\s]+)/', $schedule['command'], $compMatches)) {
                        $schedule['components'] = explode(',', $compMatches[1]);
                    } else {
                        $schedule['components'] = ['all'];
                    }

                    $schedule['component_labels'] = $this->getComponentLabels($schedule['components']);
                } else if (preg_match('/--categories=([^\s]+)/', $schedule['command'], $catMatches)) {
                    // Config backup schedule
                    $schedule['type'] = 'config';
                    $schedule['categories'] = explode(',', $catMatches[1]);
                    $schedule['category_labels'] = array_map(
                        fn($cat) => $this->configPaths[$cat]['label'] ?? $cat,
                        $schedule['categories']
                    );
                }

                // Parse retention
                if (preg_match('/--retention=(\d+)/', $schedule['command'], $retMatches)) {
                    $schedule['retention'] = (int)$retMatches[1];
                }

                // Parse destination
                if (preg_match('/--destination=([^\s]+)/', $schedule['command'], $destMatches)) {
                    $schedule['destination'] = $destMatches[1];
                } else {
                    $schedule['destination'] = 'local';
                }

                // Calculate time from hour/minute (format as HH:MM)
                $hour = $schedule['hour'] !== '*' ? str_pad($schedule['hour'], 2, '0', STR_PAD_LEFT) : '00';
                $minute = $schedule['minute'] !== '*' ? str_pad($schedule['minute'], 2, '0', STR_PAD_LEFT) : '00';
                $schedule['time'] = "{$hour}:{$minute}";

                // Last run outcome (written by backup-runner.php) + next fire time
                $stateKey = BackupScheduleManager::runStateKeyFromCommand($schedule['command']);
                if ($stateKey !== null && isset($runState[$stateKey])) {
                    $schedule['last_run'] = $runState[$stateKey]['time'] ?? null;
                    $schedule['last_status'] = $runState[$stateKey]['status'] ?? null;
                    $schedule['last_message'] = $runState[$stateKey]['message'] ?? null;
                } else {
                    $schedule['last_run'] = null;
                    $schedule['last_status'] = null;
                    $schedule['last_message'] = null;
                }

                $nextRun = $schedule['enabled'] ? BackupScheduleManager::nextRunAt($schedule) : null;
                $schedule['next_run'] = $nextRun !== null ? date('Y-m-d H:i:s', $nextRun) : null;

                $schedules[] = $schedule;
            }
        }

        $cronStatus = $this->cronStatus();

        return [
            'success' => true,
            'data' => [
                'schedules' => $schedules,
                'cron_daemon' => $cronStatus['data'] ?? null,
            ],
        ];
    }

    /**
     * Create a backup schedule
     */
    private function createSchedule(array $params, string $actor): array
    {
        $type = $params['type'] ?? 'config'; // 'config' or 'site'
        $frequency = $params['frequency'] ?? 'daily';
        $time = $params['time'] ?? '03:00';
        $retention = $params['retention'] ?? 7;
        $destination = $params['destination'] ?? 'local'; // 'local', 'nas', 'both'
        $dayOfWeek = (int)($params['day_of_week'] ?? 0); // 0=Sunday .. 6=Saturday (weekly only)

        // Parse time
        list($hour, $minute) = explode(':', $time);
        $hour = (int)$hour;
        $minute = (int)$minute;

        $cronExpr = BackupScheduleManager::buildCronExpr($frequency, $hour, $minute, $dayOfWeek);
        $command = $this->buildRunnerCommand($type, $params, $retention, $destination);

        $cronLine = "{$cronExpr} root {$command}";

        // Read existing cron file
        $existingContent = '';
        if (file_exists($this->cronFile)) {
            $existingContent = file_get_contents($this->cronFile);
        }

        // Add new line
        $newContent = trim($existingContent) . "\n" . $cronLine;

        if (!$this->writeCronFile($newContent)) {
            return ['success' => false, 'error' => 'Failed to write cron file'];
        }

        $this->logger->info('Backup schedule created', [
            'actor' => $actor,
            'type' => $type,
            'frequency' => $frequency,
            'destination' => $destination,
            'categories' => $params['categories'] ?? null,
            'sites' => $params['sites'] ?? null,
            'components' => $params['components'] ?? null,
        ]);

        $responseData = [
            'id' => md5($cronLine),
            'type' => $type,
            'frequency' => $frequency,
            'time' => $time,
            'destination' => $destination,
            'day_of_week' => $frequency === 'weekly' ? $dayOfWeek : null,
        ];
        
        if ($type === 'site') {
            $responseData['sites'] = $params['sites'] ?? 'all';
            $responseData['components'] = $params['components'] ?? ['all'];
        } else {
            $responseData['categories'] = $params['categories'] ?? ['webserver', 'mysql', 'mail'];
        }

        return [
            'success' => true,
            'data' => $responseData,
            'message' => 'Backup schedule created',
        ];
    }

    /**
     * Update a backup schedule
     */
    private function updateSchedule(array $params, string $actor): array
    {
        $id = $params['id'] ?? null;

        if (!$id) {
            return ['success' => false, 'error' => 'Schedule ID required'];
        }

        if (!file_exists($this->cronFile)) {
            return ['success' => false, 'error' => 'No schedules found'];
        }

        // Check if this is just an enable/disable toggle
        $isToggleOnly = isset($params['enabled']) && count($params) === 2;
        
        if ($isToggleOnly) {
            // Simple enable/disable toggle
            $enabled = $params['enabled'];
            $content = file_get_contents($this->cronFile);
            $lines = explode("\n", $content);
            $newLines = [];
            $found = false;

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (md5($trimmed) === $id || md5(ltrim($trimmed, '# ')) === $id) {
                    $found = true;
                    if ($enabled) {
                        $newLines[] = ltrim($trimmed, '# ');
                    } else {
                        $newLines[] = '# ' . ltrim($trimmed, '# ');
                    }
                } else {
                    $newLines[] = $line;
                }
            }

            if (!$found) {
                return ['success' => false, 'error' => 'Schedule not found'];
            }

            if (!$this->writeCronFile(implode("\n", $newLines))) {
                return ['success' => false, 'error' => 'Failed to write cron file'];
            }

            $this->logger->info('Backup schedule toggled', [
                'actor' => $actor,
                'id' => $id,
                'enabled' => $enabled,
            ]);

            return [
                'success' => true,
                'message' => $enabled ? 'Schedule enabled' : 'Schedule disabled',
            ];
        }

        // Full schedule update - delete old and create new
        $type = $params['type'] ?? 'config';
        $frequency = $params['frequency'] ?? 'daily';
        $time = $params['time'] ?? '03:00';
        $retention = $params['retention'] ?? 7;
        $destination = $params['destination'] ?? 'local';
        $enabled = $params['enabled'] ?? true;
        $dayOfWeek = (int)($params['day_of_week'] ?? 0);

        // Parse time
        list($hour, $minute) = explode(':', $time);
        $hour = (int)$hour;
        $minute = (int)$minute;

        $cronExpr = BackupScheduleManager::buildCronExpr($frequency, $hour, $minute, $dayOfWeek);
        $command = $this->buildRunnerCommand($type, $params, $retention, $destination);

        $newCronLine = "{$cronExpr} root {$command}";
        if (!$enabled) {
            $newCronLine = '# ' . $newCronLine;
        }

        // Remove old schedule and add new one
        $content = file_get_contents($this->cronFile);
        $lines = explode("\n", $content);
        $newLines = [];
        $found = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (md5($trimmed) === $id || md5(ltrim($trimmed, '# ')) === $id) {
                $found = true;
                $newLines[] = $newCronLine; // Replace with new line
            } else {
                $newLines[] = $line;
            }
        }

        if (!$found) {
            return ['success' => false, 'error' => 'Schedule not found'];
        }

        if (!$this->writeCronFile(implode("\n", $newLines))) {
            return ['success' => false, 'error' => 'Failed to write cron file'];
        }

        $this->logger->info('Backup schedule updated', [
            'actor' => $actor,
            'id' => $id,
            'type' => $type,
            'frequency' => $frequency,
            'destination' => $destination,
        ]);

        $responseData = [
            'id' => md5(ltrim($newCronLine, '# ')),
            'type' => $type,
            'frequency' => $frequency,
            'time' => $time,
            'retention' => $retention,
            'destination' => $destination,
            'enabled' => $enabled,
            'day_of_week' => $frequency === 'weekly' ? $dayOfWeek : null,
        ];

        if ($type === 'site') {
            $responseData['sites'] = $params['sites'] ?? 'all';
            $responseData['components'] = $params['components'] ?? ['all'];
        } else {
            $responseData['categories'] = $params['categories'] ?? ['webserver', 'mysql', 'mail'];
        }

        return [
            'success' => true,
            'data' => $responseData,
            'message' => 'Schedule updated',
        ];
    }

    /**
     * Delete a backup schedule
     */
    private function deleteSchedule(array $params, string $actor): array
    {
        $id = $params['id'] ?? null;

        if (!$id) {
            return ['success' => false, 'error' => 'Schedule ID required'];
        }

        if (!file_exists($this->cronFile)) {
            return ['success' => false, 'error' => 'No schedules found'];
        }

        $content = file_get_contents($this->cronFile);
        $lines = explode("\n", $content);
        $newLines = [];
        $found = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (md5($trimmed) === $id || md5(ltrim($trimmed, '#')) === $id) {
                $found = true;
                // Skip this line (delete it)
            } else {
                $newLines[] = $line;
            }
        }

        if (!$found) {
            return ['success' => false, 'error' => 'Schedule not found'];
        }

        if (!$this->writeCronFile(implode("\n", $newLines))) {
            return ['success' => false, 'error' => 'Failed to write cron file'];
        }

        $this->logger->info('Backup schedule deleted', [
            'actor' => $actor,
            'id' => $id,
        ]);

        return [
            'success' => true,
            'message' => 'Schedule deleted',
        ];
    }

    /**
     * Build the backup-runner command for a schedule.
     *
     * Uses the agent's own CLI binary (PHP_BINARY) instead of a hardcoded
     * /usr/bin/php, which does not exist on lsphp-only stacks - a silent
     * killer for scheduled backups.
     */
    private function buildRunnerCommand(string $type, array $params, int|string $retention, string $destination): string
    {
        $php = (PHP_BINARY !== '' && PHP_SAPI === 'cli') ? PHP_BINARY : '/usr/local/lsws/lsphp83/bin/php';
        $runner = '/var/www/vps-admin/agent/backup-runner.php';

        if ($type === 'site') {
            $sites = $params['sites'] ?? 'all';
            $components = $params['components'] ?? ['all'];

            $sitesStr = is_array($sites) ? implode(',', $sites) : $sites;
            $componentsStr = implode(',', $components);

            return "{$php} {$runner} --sites={$sitesStr} --components={$componentsStr} --retention={$retention} --destination={$destination}";
        }

        $categories = $params['categories'] ?? ['webserver', 'mysql', 'mail'];
        $categoriesStr = implode(',', $categories);

        return "{$php} {$runner} --categories={$categoriesStr} --retention={$retention} --destination={$destination}";
    }

    /**
     * Write the cron file with normalized content (guaranteed trailing
     * newline) and correct permissions on EVERY write - cron silently
     * ignores files with wrong perms or a missing final newline.
     */
    private function writeCronFile(string $content): bool
    {
        $normalized = BackupScheduleManager::normalizeCronContent($content);

        if (file_put_contents($this->cronFile, $normalized) === false) {
            return false;
        }

        @chmod($this->cronFile, 0644);
        @chown($this->cronFile, 'root');
        @chgrp($this->cronFile, 'root');

        return true;
    }

    /**
     * Run a schedule's backup command immediately in the background.
     * Lets the operator verify a schedule works without waiting for cron.
     */
    private function runSchedule(array $params, string $actor): array
    {
        $id = $params['id'] ?? null;

        if (!$id) {
            return ['success' => false, 'error' => 'Schedule ID required'];
        }

        if (!file_exists($this->cronFile)) {
            return ['success' => false, 'error' => 'No schedules found'];
        }

        $command = null;
        foreach (explode("\n", (string)file_get_contents($this->cronFile)) as $line) {
            $schedule = BackupScheduleManager::parseCronLine($line);
            if ($schedule !== null && $schedule['id'] === $id) {
                $command = $schedule['command'];
                break;
            }
        }

        if ($command === null) {
            return ['success' => false, 'error' => 'Schedule not found'];
        }

        // Safety: only ever launch the backup runner, never arbitrary cron payloads.
        if (strpos($command, BackupScheduleManager::RUNNER_MARKER) === false) {
            return ['success' => false, 'error' => 'Schedule command is not a backup runner'];
        }

        // Mark as running so the UI shows immediate feedback; the runner
        // overwrites this with the final outcome when it exits.
        $stateKey = BackupScheduleManager::runStateKeyFromCommand($command);
        if ($stateKey !== null) {
            BackupScheduleManager::writeRunState($stateKey, 'running', 'Manual run started by ' . $actor);
        }

        $logDir = '/var/www/vps-admin/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0750, true);
        }
        $logFile = "{$logDir}/backup-run-now.log";

        exec(sprintf(
            'nohup sh -c %s >> %s 2>&1 & echo $!',
            escapeshellarg($command),
            escapeshellarg($logFile)
        ), $output, $exitCode);

        $pid = isset($output[0]) ? (int)$output[0] : null;

        $this->logger->info('Backup schedule run triggered manually', [
            'actor' => $actor,
            'id' => $id,
            'pid' => $pid,
        ]);

        return [
            'success' => true,
            'data' => [
                'pid' => $pid,
                'log' => $logFile,
            ],
            'message' => 'Backup started in background',
        ];
    }

    /**
     * Report cron daemon health: is a cron service installed, enabled and
     * running, and is our cron file sane? Nothing else in the stack ever
     * verifies this - on a minimal image the daemon can simply be absent.
     */
    private function cronStatus(): array
    {
        $unit = $this->detectCronUnit();

        $status = [
            'unit' => $unit,
            'installed' => $unit !== null,
            'active' => false,
            'enabled' => false,
            'cron_file_exists' => file_exists($this->cronFile),
            'cron_file_ok' => false,
            'healthy' => false,
        ];

        if ($unit !== null) {
            $active = $this->execCommand('systemctl', ['is-active', $unit], 10);
            $status['active'] = trim($active['output']) === 'active';

            $enabled = $this->execCommand('systemctl', ['is-enabled', $unit], 10);
            $status['enabled'] = trim($enabled['output']) === 'enabled';
        }

        if ($status['cron_file_exists']) {
            $perms = @fileperms($this->cronFile);
            $content = (string)@file_get_contents($this->cronFile);
            $status['cron_file_ok'] = $perms !== false
                && (($perms & 0o022) === 0)            // not group/other writable
                && ($content === '' || str_ends_with($content, "\n"));
        } else {
            // No schedules yet is not a defect.
            $status['cron_file_ok'] = true;
        }

        $status['healthy'] = $status['installed'] && $status['active'] && $status['cron_file_ok'];

        return ['success' => true, 'data' => $status];
    }

    /**
     * Detect the systemd cron unit name ('crond' on RHEL-family,
     * 'cron' on Debian-family). Returns null when none exists.
     */
    private function detectCronUnit(): ?string
    {
        foreach (['crond', 'cron'] as $candidate) {
            $result = $this->execCommand('systemctl', ['cat', $candidate . '.service'], 10);
            if ($result['success']) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Self-heal the cron daemon: install the package if missing, enable and
     * start the service, and normalize our cron file. Mirrors the NAS
     * preflight pattern (auto-install NFS tools).
     */
    private function repairCron(string $actor): array
    {
        $steps = [];

        $unit = $this->detectCronUnit();

        // 1. Install a cron daemon if none exists.
        if ($unit === null) {
            if ($this->commandExists('dnf')) {
                $install = $this->execCommand('dnf', ['install', '-y', 'cronie'], 300);
            } elseif ($this->commandExists('yum')) {
                $install = $this->execCommand('yum', ['install', '-y', 'cronie'], 300);
            } elseif ($this->commandExists('apt-get')) {
                $install = $this->execCommand('apt-get', ['install', '-y', 'cron'], 300);
            } else {
                return ['success' => false, 'error' => 'No supported package manager found (dnf/yum/apt-get)'];
            }

            $steps[] = ['step' => 'install', 'success' => $install['success'], 'output' => substr($install['output'], 0, 500)];

            if (!$install['success']) {
                return ['success' => false, 'error' => 'Failed to install cron daemon', 'data' => ['steps' => $steps]];
            }

            $unit = $this->detectCronUnit();
            if ($unit === null) {
                return ['success' => false, 'error' => 'Cron daemon installed but no systemd unit found', 'data' => ['steps' => $steps]];
            }
        }

        // 2. Enable + start the service.
        $enable = $this->execCommand('systemctl', ['enable', '--now', $unit], 60);
        $steps[] = ['step' => 'enable', 'success' => $enable['success'], 'output' => substr($enable['output'], 0, 500)];

        // 3. Normalize our cron file (perms + trailing newline).
        if (file_exists($this->cronFile)) {
            $this->writeCronFile((string)file_get_contents($this->cronFile));
            $steps[] = ['step' => 'normalize_cron_file', 'success' => true, 'output' => ''];
        }

        $status = $this->cronStatus();

        $this->logger->info('Cron daemon repair executed', [
            'actor' => $actor,
            'unit' => $unit,
            'healthy' => $status['data']['healthy'] ?? false,
        ]);

        return [
            'success' => ($status['data']['healthy'] ?? false),
            'data' => [
                'steps' => $steps,
                'status' => $status['data'] ?? null,
            ],
            'message' => ($status['data']['healthy'] ?? false)
                ? 'Cron daemon is installed, enabled and running'
                : 'Repair ran but cron is still not healthy - check the steps output',
        ];
    }

    /**
     * Check whether a command exists on this system.
     */
    private function commandExists(string $cmd): bool
    {
        $result = $this->execCommand('command -v', [$cmd], 10);
        return $result['success'] && trim($result['output']) !== '';
    }

    /**
     * Copy directory recursively
     */
    private function copyDir(string $src, string $dst): bool
    {
        $dir = opendir($src);
        if (!$dir) {
            return false;
        }

        if (!is_dir($dst)) {
            mkdir($dst, 0750, true);
        }

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $srcPath = "{$src}/{$file}";
            $dstPath = "{$dst}/{$file}";

            if (is_dir($srcPath)) {
                $this->copyDir($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }

        closedir($dir);
        return true;
    }

    /**
     * Remove directory recursively
     */
    private function removeDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = "{$dir}/{$file}";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        return rmdir($dir);
    }

    /**
     * Get directory size
     */
    private function getDirSize(string $dir): int
    {
        $size = 0;
        
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
        
        return $size;
    }

    /**
     * Write metadata file
     */
    private function writeMetaFile(string $backupPath, string $originalPath, string $action, string $actor): void
    {
        $meta = [
            'original_path' => $originalPath,
            'action' => $action,
            'actor' => $actor,
            'date' => date('Y-m-d H:i:s'),
        ];
        
        file_put_contents("{$backupPath}.meta.json", json_encode($meta, JSON_PRETTY_PRINT));
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get human-readable labels for site backup components
     */
    private function getComponentLabels(array $components): array
    {
        $labels = [
            'all' => 'Everything',
            'database' => 'Database',
            'plugins' => 'Plugins',
            'themes' => 'Themes',
            'uploads' => 'Uploads',
            'wpcore' => 'WP Core',
            'ssl' => 'SSL Certs',
            'vhost' => 'vHost Config',
        ];
        
        return array_map(fn($c) => $labels[$c] ?? $c, $components);
    }

    /**
     * Create backup archive with optional splitting for large backups
     * 
     * @param string $tempDir Source directory to archive
     * @param string $archivePath Target archive path
     * @param string $domain Domain name for logging
     * @param int $chunkSizeMB Split threshold in MB (default 500MB)
     * @return array Result with success, split flag, parts count, etc.
     */
    private function createBackupArchive(string $tempDir, string $archivePath, string $domain, int $chunkSizeMB = 1024): array
    {
        // Estimate source size
        exec("du -sb " . escapeshellarg($tempDir) . " 2>/dev/null", $sizeOutput);
        $estimatedBytes = (int)explode("\t", $sizeOutput[0] ?? "0")[0];
        $estimatedMB = $estimatedBytes / 1024 / 1024;
        
        $this->logger->info("Creating backup archive", [
            'domain' => $domain,
            'estimated_size_mb' => round($estimatedMB, 2),
            'threshold_mb' => $chunkSizeMB,
        ]);
        
        // For backups > threshold, use split archives
        if ($estimatedMB > $chunkSizeMB) {
            return $this->createSplitArchive($tempDir, $archivePath, $domain, $chunkSizeMB);
        }
        
        // Standard single archive for smaller backups
        $tarCmd = "cd " . escapeshellarg($tempDir) . " && tar -czf " . escapeshellarg($archivePath) . " . 2>&1";
        exec($tarCmd, $output, $exitCode);
        
        if ($exitCode !== 0) {
            return [
                'success' => false,
                'error' => 'Failed to create archive: ' . implode("\n", $output),
            ];
        }
        
        if (!file_exists($archivePath)) {
            return ['success' => false, 'error' => 'Archive file was not created'];
        }
        
        return [
            'success' => true,
            'split' => false,
            'parts_count' => 1,
            'total_size' => filesize($archivePath),
        ];
    }

    /**
     * Create a split archive for large backups
     * Creates archive.tar.gz.part_aa, archive.tar.gz.part_ab, etc.
     * Plus a manifest file for reassembly
     */
    private function createSplitArchive(string $tempDir, string $archivePath, string $domain, int $chunkSizeMB): array
    {
        $this->logger->info("Creating split archive for large backup", [
            'domain' => $domain,
            'chunk_size_mb' => $chunkSizeMB,
        ]);
        
        // Create tar and pipe to split
        $splitCmd = "cd " . escapeshellarg($tempDir) . " && tar -czf - . 2>/dev/null | split -b {$chunkSizeMB}m - " . 
                    escapeshellarg($archivePath . ".part_");
        exec($splitCmd, $output, $exitCode);
        
        if ($exitCode !== 0) {
            // Fallback to single archive if split fails
            $this->logger->warning("Split archive failed, falling back to single archive", ['domain' => $domain]);
            
            $tarCmd = "cd " . escapeshellarg($tempDir) . " && tar -czf " . escapeshellarg($archivePath) . " . 2>&1";
            exec($tarCmd, $output2, $exitCode2);
            
            if ($exitCode2 !== 0 || !file_exists($archivePath)) {
                return ['success' => false, 'error' => 'Failed to create archive'];
            }
            
            return [
                'success' => true,
                'split' => false,
                'parts_count' => 1,
                'total_size' => filesize($archivePath),
            ];
        }
        
        // Get list of parts
        $parts = glob($archivePath . ".part_*");
        sort($parts);
        
        if (empty($parts)) {
            return ['success' => false, 'error' => 'No archive parts were created'];
        }
        
        // Calculate total size
        $totalSize = 0;
        $partFiles = [];
        foreach ($parts as $part) {
            $partSize = filesize($part);
            $totalSize += $partSize;
            $partFiles[] = [
                'name' => basename($part),
                'size' => $partSize,
            ];
        }
        
        // Create manifest for reassembly
        $manifest = [
            'original_name' => basename($archivePath),
            'domain' => $domain,
            'parts' => $partFiles,
            'parts_count' => count($parts),
            'total_size' => $totalSize,
            'chunk_size_mb' => $chunkSizeMB,
            'created_at' => date('Y-m-d H:i:s'),
            'reassemble_command' => 'cat ' . basename($archivePath) . '.part_* > ' . basename($archivePath),
        ];
        
        file_put_contents($archivePath . '.manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        
        // Also create an empty marker file at the original path for listing
        touch($archivePath);
        
        $this->logger->info("Split archive created", [
            'domain' => $domain,
            'parts' => count($parts),
            'total_size_mb' => round($totalSize / 1024 / 1024, 2),
        ]);
        
        return [
            'success' => true,
            'split' => true,
            'parts_count' => count($parts),
            'parts' => $partFiles,
            'total_size' => $totalSize,
            'manifest' => $archivePath . '.manifest.json',
        ];
    }

    /**
     * Reassemble a split archive back into a single file
     */
    private function reassembleSplitArchive(string $archivePath): array
    {
        $manifestPath = $archivePath . '.manifest.json';
        
        if (!file_exists($manifestPath)) {
            return ['success' => false, 'error' => 'Manifest file not found'];
        }
        
        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!$manifest || empty($manifest['parts'])) {
            return ['success' => false, 'error' => 'Invalid manifest'];
        }
        
        $baseDir = dirname($archivePath);

        // Reassemble into local scratch space, never next to the parts: for
        // NAS-resident sets dirname() is the NFS mount, and writing a multi-GB
        // temp file back over the VPN/NFS link is slow and fills the NAS.
        $scratchDir = "{$this->backupPath}/tmp";
        if (!is_dir($scratchDir) && !@mkdir($scratchDir, 0750, true)) {
            $scratchDir = sys_get_temp_dir();
        }
        $tempArchive = $scratchDir . '/' . basename($archivePath) . '.' . uniqid() . '.reassembled';

        // Free-space guard: the reassembled file needs total_size bytes plus
        // headroom; failing early beats filling the disk mid-cat.
        $needed = (int)($manifest['total_size'] ?? 0);
        $free = @disk_free_space($scratchDir);
        if ($needed > 0 && $free !== false && $free < $needed + (1024 ** 3)) {
            return ['success' => false, 'error' => 'Not enough local disk space to reassemble: ' .
                $this->formatBytes($needed) . ' needed, ' . $this->formatBytes((int)$free) . " free in {$scratchDir}"];
        }
        
        // Reassemble parts
        $catCmd = "cat";
        foreach ($manifest['parts'] as $part) {
            $partPath = $baseDir . '/' . $part['name'];
            if (!file_exists($partPath)) {
                return ['success' => false, 'error' => "Missing part: {$part['name']}"];
            }
            $catCmd .= " " . escapeshellarg($partPath);
        }
        $catCmd .= " > " . escapeshellarg($tempArchive);
        
        exec($catCmd . " 2>&1", $output, $exitCode);
        
        if ($exitCode !== 0 || !file_exists($tempArchive)) {
            @unlink($tempArchive);
            return ['success' => false, 'error' => 'Failed to reassemble archive'];
        }
        
        // Quick size check against the manifest before the (slow) tar verify.
        clearstatcache(true, $tempArchive);
        if ($needed > 0 && filesize($tempArchive) !== $needed) {
            $actual = filesize($tempArchive);
            unlink($tempArchive);
            return ['success' => false, 'error' => 'Reassembled size mismatch: expected ' .
                $this->formatBytes($needed) . ', got ' . $this->formatBytes((int)$actual)];
        }

        // Verify the reassembled archive
        exec("tar -tzf " . escapeshellarg($tempArchive) . " >/dev/null 2>&1", $verifyOutput, $verifyExit);
        
        if ($verifyExit !== 0) {
            unlink($tempArchive);
            return ['success' => false, 'error' => 'Reassembled archive is corrupted'];
        }
        
        return [
            'success' => true,
            'path' => $tempArchive,
            'size' => filesize($tempArchive),
        ];
    }

    /**
     * Upload split archive parts to NAS.
     *
     * Each part goes through uploadToNas() and is therefore individually
     * size+checksum verified. 'verified' is only true when every part (and
     * the manifest) verified - that is the precondition for freeing the
     * local copy on a move.
     */
    private function uploadSplitArchiveToNas(string $archivePath, string $remotePath, ?string $statusId = null): array
    {
        $manifestPath = $archivePath . '.manifest.json';
        
        if (!file_exists($manifestPath)) {
            // Not a split archive, use regular upload
            return $this->uploadToNas($archivePath, $remotePath);
        }
        
        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!$manifest || empty($manifest['parts'])) {
            return ['success' => false, 'error' => 'Invalid manifest'];
        }
        
        $baseDir = dirname($archivePath);
        $totalParts = count($manifest['parts']);
        $uploadedParts = [];
        $errors = [];
        $allVerified = true;
        $remoteDir = null;
        
        // Upload each part
        foreach (array_values($manifest['parts']) as $i => $part) {
            $partPath = $baseDir . '/' . $part['name'];
            if (!file_exists($partPath)) {
                // A listed part missing locally means the set is incomplete -
                // uploading the rest would store an unrestorable backup.
                $errors[] = "Missing local part: {$part['name']}";
                $allVerified = false;
                continue;
            }

            if ($statusId !== null) {
                $pct = 10 + (int)floor(($i / max(1, $totalParts)) * 75);
                $this->updateBackupTracking($statusId, 'uploading', $pct,
                    'Uploading part ' . ($i + 1) . "/{$totalParts} to NAS...");
            }

            $result = $this->uploadToNas($partPath, $remotePath);
            if (!empty($result['success'])) {
                $uploadedParts[] = $part['name'];
                if (empty($result['verified'])) {
                    $allVerified = false;
                }
                if ($remoteDir === null && !empty($result['remote_path'])) {
                    $remoteDir = dirname($result['remote_path']);
                }
            } else {
                $errors[] = "Failed to upload {$part['name']}: " . ($result['error'] ?? $result['reason'] ?? 'unknown error');
                $allVerified = false;
            }
        }
        
        // Upload manifest
        $manifestResult = $this->uploadToNas($manifestPath, $remotePath);
        if (empty($manifestResult['success'])) {
            $errors[] = 'Failed to upload manifest: ' . ($manifestResult['error'] ?? $manifestResult['reason'] ?? 'unknown error');
            $allVerified = false;
        } elseif ($remoteDir === null && !empty($manifestResult['remote_path'])) {
            $remoteDir = dirname($manifestResult['remote_path']);
        }
        
        // Upload meta file if exists
        $metaPath = $archivePath . '.meta.json';
        if (file_exists($metaPath)) {
            $this->uploadToNas($metaPath, $remotePath);
        }

        // Drop a zero-byte marker next to the parts so NAS-side scans (and
        // the Server/NAS listing merge) recognise the set as one backup.
        // uploadToNas() rejects empty files by design, so touch it directly.
        $remoteMarker = null;
        if ($remoteDir !== null && count($errors) === 0) {
            $candidate = $remoteDir . '/' . basename($archivePath);
            if (@touch($candidate)) {
                $remoteMarker = $candidate;
            }
        }

        $success = count($errors) === 0 && count($uploadedParts) === $totalParts;

        return [
            'success' => $success,
            'uploaded_parts' => count($uploadedParts),
            'total_parts' => $totalParts,
            'errors' => $errors,
            'error' => $success ? null : implode('; ', $errors),
            'verified' => $success && $allVerified,
            'remote_path' => $remoteMarker,
            'remote_dir' => $remoteDir,
            'split' => true,
        ];
    }

    /**
     * Free the local payload of a split archive after a verified NAS upload:
     * deletes the .part_* files and the zero-byte marker, but keeps
     * .manifest.json and .meta.json behind so the backup stays visible in
     * listings as a NAS-only stub and restore can locate the parts on NAS.
     */
    private function removeLocalSplitPayload(string $archivePath): bool
    {
        $ok = true;
        foreach (glob($archivePath . '.part_*') ?: [] as $partFile) {
            if (!@unlink($partFile)) {
                $ok = false;
            }
        }
        if (file_exists($archivePath) && !@unlink($archivePath)) {
            $ok = false;
        }
        return $ok;
    }

    // =========================================================================
    // ASYNC BACKGROUND BACKUP
    // =========================================================================

    /**
     * Start a backup in a background process
     * 
     * This spawns a separate PHP process to run the backup so the API can
     * return immediately with a status_id for progress polling.
     * 
     * @param string $domain Site domain
     * @param array $components Components to backup
     * @param string $destination Backup destination
     * @param string $statusId Pre-created status ID
     * @param string $actor User performing action
     * @return array Response with status_id for polling
     */
    private function startBackgroundBackup(
        string $domain,
        array $components,
        string $destination,
        string $statusId,
        string $actor
    ): array {
        // Path to the background runner script
        $runnerScript = __DIR__ . '/../backup-site-runner.php';
        
        if (!file_exists($runnerScript)) {
            $this->completeBackupTracking($statusId, false, ['error' => 'Background runner script not found']);
            return ['success' => false, 'error' => 'Background runner script not found'];
        }
        
        // Build parameters for the background process
        $params = [
            'domain' => $domain,
            'components' => $components,
            'destination' => $destination,
            'status_id' => $statusId,
            'actor' => $actor,
        ];
        
        $paramsJson = escapeshellarg(json_encode($params));
        $phpBinary = PHP_BINARY ?: '/usr/bin/php';
        
        // Log file for background process output
        $logFile = '/var/log/vpsadmin/backup-runner.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0750, true);
        }
        
        // Spawn background process
        // Using nohup and & to ensure process continues after parent exits
        $cmd = sprintf(
            'nohup %s %s %s >> %s 2>&1 &',
            escapeshellarg($phpBinary),
            escapeshellarg($runnerScript),
            $paramsJson,
            escapeshellarg($logFile)
        );
        
        exec($cmd);
        
        $this->logger->info("Background backup started", [
            'domain' => $domain,
            'status_id' => $statusId,
            'destination' => $destination,
        ]);
        
        return [
            'success' => true,
            'async' => true,
            'data' => [
                'status_id' => $statusId,
                'domain' => $domain,
                'message' => 'Backup started in background',
            ],
            'message' => 'Backup started - poll /api/backups/status for progress',
        ];
    }

    // =========================================================================
    // BACKUP STATUS TRACKING
    // =========================================================================

    /**
     * Status file directory for tracking running backups
     */
    private function getStatusDir(): string
    {
        $dir = "{$this->backupPath}/.status";
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /**
     * Start tracking a backup operation
     */
    private function startBackupTracking(string $domain, string $type = 'site'): string
    {
        $statusId = uniqid("{$domain}_");
        $statusFile = $this->getStatusDir() . "/{$statusId}.json";
        
        $status = [
            'id' => $statusId,
            'domain' => $domain,
            'type' => $type,
            'status' => 'running',
            'started_at' => date('Y-m-d H:i:s'),
            'step' => 'initializing',
            'progress' => 0,
            'message' => 'Starting backup...',
        ];
        
        file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));
        
        return $statusId;
    }

    /**
     * Update backup tracking status
     */
    private function updateBackupTracking(string $statusId, string $step, int $progress, string $message = ''): void
    {
        $statusFile = $this->getStatusDir() . "/{$statusId}.json";
        
        if (!file_exists($statusFile)) {
            return;
        }
        
        $status = json_decode(file_get_contents($statusFile), true);
        $status['step'] = $step;
        $status['progress'] = min(100, max(0, $progress));
        $status['message'] = $message ?: $step;
        $status['updated_at'] = date('Y-m-d H:i:s');
        
        file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));
    }

    /**
     * Complete backup tracking (success or failure)
     */
    private function completeBackupTracking(string $statusId, bool $success, array $result = []): void
    {
        $statusFile = $this->getStatusDir() . "/{$statusId}.json";
        
        if (!file_exists($statusFile)) {
            return;
        }
        
        $status = json_decode(file_get_contents($statusFile), true);
        $status['status'] = $success ? 'completed' : 'failed';
        $status['progress'] = $success ? 100 : $status['progress'];
        $status['completed_at'] = date('Y-m-d H:i:s');
        $status['result'] = $result;
        
        // Calculate duration
        $startTime = strtotime($status['started_at']);
        $status['duration_seconds'] = time() - $startTime;
        
        file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));
        
        // Auto-cleanup: delete status file after 1 hour for completed backups
        // Leave failed ones for debugging
        if ($success) {
            // Schedule deletion (we'll do it on next status list call)
        }
    }

    /**
     * Get status of a specific backup operation
     */
    private function getBackupStatus(array $params): array
    {
        $statusId = $params['status_id'] ?? null;
        $domain = $params['domain'] ?? null;
        
        if ($statusId) {
            $statusFile = $this->getStatusDir() . "/{$statusId}.json";
            
            if (file_exists($statusFile)) {
                $status = json_decode(file_get_contents($statusFile), true);
                return ['success' => true, 'data' => $status];
            }
            
            return ['success' => false, 'error' => 'Status not found'];
        }
        
        if ($domain) {
            // Find status for a domain
            $files = glob($this->getStatusDir() . "/{$domain}_*.json");
            
            if (!empty($files)) {
                // Get the most recent one
                usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
                $status = json_decode(file_get_contents($files[0]), true);
                return ['success' => true, 'data' => $status];
            }
            
            return ['success' => false, 'error' => 'No backup status found for domain'];
        }
        
        return ['success' => false, 'error' => 'status_id or domain required'];
    }

    /**
     * List all running backup operations
     */
    private function listRunningBackups(): array
    {
        $statusDir = $this->getStatusDir();
        $files = glob("{$statusDir}/*.json");
        
        $running = [];
        $completed = [];
        $cutoffTime = time() - 3600; // 1 hour ago
        
        foreach ($files as $file) {
            $status = json_decode(file_get_contents($file), true);
            
            if (!$status) {
                continue;
            }
            
            if ($status['status'] === 'running') {
                // Check if it's stale (more than 2 hours old)
                $startTime = strtotime($status['started_at'] ?? 'now');
                if (time() - $startTime > 7200) {
                    $status['status'] = 'stale';
                    $status['message'] = 'Backup appears to have stalled';
                }
                $running[] = $status;
            } else {
                // Cleanup old completed status files
                $completedTime = strtotime($status['completed_at'] ?? $status['updated_at'] ?? 'now');
                if ($completedTime < $cutoffTime) {
                    unlink($file);
                } else {
                    $completed[] = $status;
                }
            }
        }
        
        return [
            'success' => true,
            'data' => [
                'running' => $running,
                'recent_completed' => array_slice($completed, 0, 10),
            ],
        ];
    }

    // =========================================================================
    // SITE BACKUP METHODS
    // =========================================================================

    /**
     * Backup multiple sites (files + database)
     */
    private function backupSites(array $params, string $actor): array
    {
        $sites = $params['sites'] ?? [];
        $components = $params['components'] ?? ['all'];
        $destination = $params['destination'] ?? 'local';
        
        // If 'all', get all sites from vhosts directory
        if ($sites === 'all') {
            $sites = $this->getAllSites();
        }
        
        if (empty($sites)) {
            return ['success' => false, 'error' => 'No sites specified'];
        }

        $results = [
            'sites' => [],
            'errors' => [],
        ];

        foreach ($sites as $domain) {
            $siteResult = $this->backupSite([
                'domain' => $domain,
                'components' => $components,
                'destination' => $destination,
            ], $actor);
            
            if ($siteResult['success']) {
                $results['sites'][] = [
                    'domain' => $domain,
                    'archive' => $siteResult['data']['archive'] ?? null,
                    'size' => $siteResult['data']['size_human'] ?? null,
                    'components' => $components,
                ];
            } else {
                $results['errors'][] = [
                    'domain' => $domain,
                    'error' => $siteResult['error'] ?? 'Unknown error',
                ];
            }
        }

        $this->logger->info('Sites backup completed', [
            'actor' => $actor,
            'sites_backed_up' => count($results['sites']),
            'components' => $components,
            'destination' => $destination,
            'errors' => count($results['errors']),
        ]);

        return [
            'success' => count($results['errors']) === 0,
            'data' => $results,
            'message' => count($results['sites']) . ' sites backed up' . 
                        (count($results['errors']) > 0 ? ', ' . count($results['errors']) . ' errors' : ''),
        ];
    }

    /**
     * Site backup component paths (relative to public_html)
     */
    private array $siteComponents = [
        'all' => null, // Special: backup everything
        'database' => null, // Special: handled separately
        'plugins' => 'wp-content/plugins',
        'themes' => 'wp-content/themes',
        'uploads' => 'wp-content/uploads',
        'wpcore' => null, // Special: everything except wp-content
    ];

    /**
     * Backup a single site (files + database)
     * 
     * @param array $params Parameters including:
     *   - domain: Site domain to backup (required)
     *   - components: Array of components to backup (default: ['all'])
     *   - destination: 'local', 'nas', or 'both' (default: 'local')
     *   - async: If true, spawn background process and return status_id immediately
     *   - status_id_override: Use existing status_id (for background runner)
     * @param string $actor The user performing the action
     * @return array Result with success/error or async status_id
     */
    private function backupSite(array $params, string $actor): array
    {
        // Allow unlimited execution time for large site backups
        set_time_limit(0);
        
        $domain = $params['domain'] ?? null;
        $components = $params['components'] ?? ['all'];
        $destination = $params['destination'] ?? 'local';
        $async = $params['async'] ?? false;
        $statusIdOverride = $params['status_id_override'] ?? null;
        
        if (!$domain) {
            return ['success' => false, 'error' => 'Domain is required'];
        }

        // Validate site exists before starting
        $homeDir = "/home/{$domain}";
        if (!is_dir($homeDir)) {
            return ['success' => false, 'error' => "Site directory not found: {$homeDir}"];
        }

        // Start status tracking for this backup
        // Use override if provided (from background runner)
        $statusId = $statusIdOverride ?? $this->startBackupTracking($domain, 'site');

        // Async mode: spawn background process and return immediately
        if ($async && !$statusIdOverride) {
            return $this->startBackgroundBackup($domain, $components, $destination, $statusId, $actor);
        }

        $publicHtml = "{$homeDir}/public_html";
        $timestamp = date('Y-m-d_H-i-s');
        $backupDir = "{$this->backupPath}/sites/{$domain}";
        
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }

        // Generate archive name with components info
        $componentSuffix = in_array('all', $components) ? 'full' : implode('-', $components);
        $archiveName = "{$domain}_{$timestamp}_{$componentSuffix}.tar.gz";
        $archivePath = "{$backupDir}/{$archiveName}";
        $tempDir = sys_get_temp_dir() . '/vps-backup-' . uniqid();
        
        if (!mkdir($tempDir, 0750, true)) {
            $this->completeBackupTracking($statusId, false, ['error' => 'Failed to create temp directory']);
            return ['success' => false, 'error' => 'Failed to create temp directory'];
        }

        $backedUp = [];
        $errors = [];
        $backupAll = in_array('all', $components);

        try {
            // Update status: starting file backup
            $this->updateBackupTracking($statusId, 'files', 10, 'Backing up files...');
            
            // Common rsync excludes
            $baseExcludes = [
                '--exclude=*.log',
                '--exclude=.git',
                '--exclude=node_modules',
                '--exclude=cache',
                '--exclude=*.cache',
                '--exclude=tmp/*',
            ];

            // 1. Backup files based on components
            // IMPORTANT: Structure must be files/{domain}/ to match restore expectations
            $filesBackup = "{$tempDir}/files";
            $domainFilesDir = "{$filesBackup}/{$domain}";
            mkdir($domainFilesDir, 0750, true);

            if ($backupAll) {
                // Full backup - everything from home directory
                // Store at files/{domain}/ so restore can find it
                $excludes = $baseExcludes;
                $rsyncCmd = 'rsync -a ' . implode(' ', $excludes) . ' ' . 
                            escapeshellarg($homeDir . '/') . ' ' . 
                            escapeshellarg($domainFilesDir . '/');
                
                exec($rsyncCmd . ' 2>&1', $output, $exitCode);
                
                if ($exitCode === 0) {
                    $backedUp[] = 'files';
                } else {
                    // Fallback to cp if rsync fails
                    $this->execCommand('cp', ['-r', $homeDir . '/.', $domainFilesDir]);
                    $backedUp[] = 'files';
                }
            } else {
                // Component-based backup
                // Store at files/{domain}/public_html/... to match restore structure
                $backupPublicHtml = "{$domainFilesDir}/public_html";
                mkdir($backupPublicHtml, 0750, true);
                
                if (in_array('wpcore', $components)) {
                    // Backup WP core (everything except wp-content)
                    $excludes = array_merge($baseExcludes, ['--exclude=wp-content']);
                    $rsyncCmd = 'rsync -a ' . implode(' ', $excludes) . ' ' . 
                                escapeshellarg($publicHtml . '/') . ' ' . 
                                escapeshellarg($backupPublicHtml . '/');
                    exec($rsyncCmd . ' 2>&1', $output, $exitCode);
                    if ($exitCode === 0) {
                        $backedUp[] = 'wpcore';
                    } else {
                        $errors[] = 'Failed to backup WordPress core files';
                    }
                }
                
                if (in_array('plugins', $components)) {
                    $pluginsPath = "{$publicHtml}/wp-content/plugins";
                    $backupPluginsPath = "{$backupPublicHtml}/wp-content/plugins";
                    if (is_dir($pluginsPath)) {
                        mkdir(dirname($backupPluginsPath), 0750, true);
                        $rsyncCmd = 'rsync -a ' . escapeshellarg($pluginsPath . '/') . ' ' . 
                                    escapeshellarg($backupPluginsPath . '/');
                        exec($rsyncCmd . ' 2>&1', $output, $exitCode);
                        if ($exitCode === 0) {
                            $backedUp[] = 'plugins';
                        } else {
                            $errors[] = 'Failed to backup plugins';
                        }
                    }
                }
                
                if (in_array('themes', $components)) {
                    $themesPath = "{$publicHtml}/wp-content/themes";
                    $backupThemesPath = "{$backupPublicHtml}/wp-content/themes";
                    if (is_dir($themesPath)) {
                        mkdir(dirname($backupThemesPath), 0750, true);
                        $rsyncCmd = 'rsync -a ' . escapeshellarg($themesPath . '/') . ' ' . 
                                    escapeshellarg($backupThemesPath . '/');
                        exec($rsyncCmd . ' 2>&1', $output, $exitCode);
                        if ($exitCode === 0) {
                            $backedUp[] = 'themes';
                        } else {
                            $errors[] = 'Failed to backup themes';
                        }
                    }
                }
                
                if (in_array('uploads', $components)) {
                    $uploadsPath = "{$publicHtml}/wp-content/uploads";
                    $backupUploadsPath = "{$backupPublicHtml}/wp-content/uploads";
                    if (is_dir($uploadsPath)) {
                        mkdir(dirname($backupUploadsPath), 0750, true);
                        $rsyncCmd = 'rsync -a ' . escapeshellarg($uploadsPath . '/') . ' ' . 
                                    escapeshellarg($backupUploadsPath . '/');
                        exec($rsyncCmd . ' 2>&1', $output, $exitCode);
                        if ($exitCode === 0) {
                            $backedUp[] = 'uploads';
                        } else {
                            $errors[] = 'Failed to backup uploads';
                        }
                    }
                }
            }

            // Update status: files complete, starting database
            $this->updateBackupTracking($statusId, 'database', 30, 'Backing up databases...');
            
            // 2. Backup database(s) for this site (if 'all' or 'database' component selected)
            $databases = [];
            if ($backupAll || in_array('database', $components)) {
                $databases = $this->findSiteDatabases($domain);
                
                if (!empty($databases)) {
                    $dbBackupDir = "{$tempDir}/databases";
                    mkdir($dbBackupDir, 0750, true);
                    
                    $dbCount = count($databases);
                    $dbDone = 0;
                    
                    foreach ($databases as $dbName) {
                        $dbFile = "{$dbBackupDir}/{$dbName}.sql";
                        $this->updateBackupTracking($statusId, 'database', 30 + (int)(($dbDone / $dbCount) * 20), "Dumping database: {$dbName}");
                        
                        $dumpResult = $this->dumpDatabase($dbName, $dbFile);
                        
                        if ($dumpResult['success']) {
                            $backedUp[] = "database:{$dbName}";
                        } else {
                            $errors[] = "Failed to backup database {$dbName}: " . ($dumpResult['error'] ?? 'Unknown');
                        }
                        $dbDone++;
                    }
                }
            }

            // Update status: config backup phase
            $this->updateBackupTracking($statusId, 'config', 55, 'Backing up configuration...');
            
            // 3. Backup vhost config (if 'all' or 'vhost' component selected)
            if ($backupAll || in_array('vhost', $components)) {
                $vhostPath = $this->config['paths']['ols_vhosts'] . '/' . $domain;
                if (is_dir($vhostPath)) {
                    $configBackup = "{$tempDir}/vhost";
                    mkdir($configBackup, 0750, true);
                    $this->copyDir($vhostPath, $configBackup);
                    $backedUp[] = 'vhost_config';
                }
            }

            // 4. Backup SSL certificates (if 'all' or 'ssl' component selected)
            if ($backupAll || in_array('ssl', $components)) {
                $sslPath = "/etc/letsencrypt/live/{$domain}";
                if (is_dir($sslPath)) {
                    $sslBackup = "{$tempDir}/ssl";
                    mkdir($sslBackup, 0750, true);
                    // Copy actual cert files, not symlinks
                    foreach (['fullchain.pem', 'privkey.pem', 'cert.pem', 'chain.pem'] as $certFile) {
                        $certPath = "{$sslPath}/{$certFile}";
                        if (file_exists($certPath)) {
                            copy(realpath($certPath), "{$sslBackup}/{$certFile}");
                        }
                    }
                    $backedUp[] = 'ssl_certs';
                }
            }

            // 5-6. DNS and Mail only for full backups
            if ($backupAll) {
                // 5. Backup DNS zone records
                $dnsRecords = $this->backupDnsZone($domain);
                if (!empty($dnsRecords)) {
                    $dnsBackup = "{$tempDir}/dns";
                    mkdir($dnsBackup, 0750, true);
                    file_put_contents("{$dnsBackup}/zone.json", json_encode($dnsRecords, JSON_PRETTY_PRINT));
                    $backedUp[] = 'dns_zone';
                }

                // 6. Backup mail accounts and forwards
                $mailData = $this->backupMailAccounts($domain);
                if (!empty($mailData['accounts']) || !empty($mailData['forwards'])) {
                    $mailBackup = "{$tempDir}/mail";
                    mkdir($mailBackup, 0750, true);
                    file_put_contents("{$mailBackup}/accounts.json", json_encode($mailData, JSON_PRETTY_PRINT));
                    
                    // Also backup DKIM keys if they exist
                    $dkimPath = "/etc/opendkim/keys/{$domain}";
                    if (is_dir($dkimPath)) {
                        $dkimBackup = "{$mailBackup}/dkim";
                        mkdir($dkimBackup, 0750, true);
                        $this->copyDir($dkimPath, $dkimBackup);
                        $backedUp[] = 'dkim_keys';
                    }
                    
                    $backedUp[] = 'mail_accounts';
                }
            }

            // Update status: creating archive
            $this->updateBackupTracking($statusId, 'archiving', 70, 'Creating backup archive...');
            
            // 7. Create metadata file
            $siteUser = $this->getSiteUser($domain);
            $meta = [
                'domain' => $domain,
                'timestamp' => $timestamp,
                'actor' => $actor,
                'components' => $components,
                'backed_up' => $backedUp,
                'errors' => $errors,
                'home_dir' => $homeDir,
                'databases' => $databases,
                'sftp_user' => $siteUser,
                'type' => 'site_backup',
            ];
            file_put_contents("{$tempDir}/backup_meta.json", json_encode($meta, JSON_PRETTY_PRINT));

            // 8. Create archive (with splitting for large backups)
            $archiveResult = $this->createBackupArchive($tempDir, $archivePath, $domain);
            
            if (!$archiveResult['success']) {
                $this->removeDir($tempDir);
                return ['success' => false, 'error' => $archiveResult['error'] ?? 'Failed to create archive'];
            }

            // Cleanup temp directory
            $this->removeDir($tempDir);

            // Get archive size (total of all parts if split)
            $size = $archiveResult['total_size'] ?? filesize($archivePath);
            $isSplit = $archiveResult['split'] ?? false;
            $partsCount = $archiveResult['parts_count'] ?? 1;

            $this->logger->info('Site backup created', [
                'domain' => $domain,
                'archive' => $archiveName,
                'size' => $this->formatBytes($size),
                'components' => $components,
                'backed_up' => $backedUp,
                'actor' => $actor,
                'split' => $isSplit,
                'parts' => $partsCount,
            ]);

            // Copy to NAS if configured
            $destination = $params['destination'] ?? 'local'; // 'local', 'nas', 'both'
            $nasResult = null;
            $nasUploaded = false;
            
            if ($destination === 'nas' || $destination === 'both') {
                $this->updateBackupTracking($statusId, 'uploading', 90, 'Uploading to NAS...');
                if ($isSplit) {
                    // Upload all parts for split archives
                    $nasResult = $this->uploadSplitArchiveToNas($archivePath, "sites/{$domain}");
                } else {
                    $nasResult = $this->uploadToNas($archivePath, "sites/{$domain}");
                }
                $nasUploaded = $nasResult['success'] ?? false;
            }

            // Create metadata file alongside the archive for listing
            $archiveMeta = [
                'domain' => $domain,
                'timestamp' => $timestamp,
                'actor' => $actor,
                'components' => $components,
                'component_labels' => $this->getComponentLabels($components),
                'backed_up' => $backedUp,
                'databases' => $databases,
                'type' => 'site_backup',
                'destination' => $destination,
                'nas_uploaded' => $nasUploaded,
                'archive_size' => $size,
                'split' => $isSplit,
                'parts_count' => $partsCount,
            ];
            file_put_contents("{$archivePath}.meta.json", json_encode($archiveMeta, JSON_PRETTY_PRINT));

            // Keep the NAS-side meta in sync (uploadToNas ran before the
            // meta existed locally, so push it now).
            if ($nasUploaded && !empty($nasResult['remote_path'])) {
                @copy("{$archivePath}.meta.json", $nasResult['remote_path'] . '.meta.json');
            }

            // destination=nas means MOVE: free the server copy once the NAS
            // upload is checksum-verified. The .meta.json stub stays so the
            // backup remains visible in listings as NAS-only (split sets also
            // keep the small .manifest.json). If the upload failed or could
            // not be verified, the local archive is kept - a backup is never
            // lost to a flaky NAS.
            $movedToNas = false;
            if ($destination === 'nas' && $nasUploaded && !empty($nasResult['verified'])) {
                $movedToNas = $isSplit
                    ? $this->removeLocalSplitPayload($archivePath)
                    : @unlink($archivePath);
                if (!$movedToNas) {
                    $this->logger->warning('Failed to remove local archive after verified NAS move', [
                        'archive' => $archivePath,
                    ]);
                }
            } elseif ($destination === 'nas' && !$nasUploaded) {
                $this->logger->error('Site backup NAS upload failed/unverified; keeping local copy', [
                    'domain' => $domain,
                    'archive' => $archivePath,
                    'nas_error' => $nasResult['error'] ?? null,
                ]);
            }

            // Complete status tracking - success
            $this->completeBackupTracking($statusId, true, [
                'archive' => $archiveName,
                'size_human' => $this->formatBytes($size),
                'backed_up' => $backedUp,
            ]);

            return [
                'success' => true,
                'data' => [
                    'domain' => $domain,
                    'archive' => $archiveName,
                    'path' => $archivePath,
                    'size' => $size,
                    'size_human' => $this->formatBytes($size),
                    'backed_up' => $backedUp,
                    'databases' => $databases,
                    'errors' => $errors,
                    'nas' => $nasResult,
                    'nas_uploaded' => $nasUploaded,
                    'split' => $isSplit,
                    'parts_count' => $partsCount,
                    'status_id' => $statusId,
                ],
                'message' => "Site {$domain} backed up successfully" . 
                            ($isSplit ? " (split into {$partsCount} parts)" : '') .
                            ($movedToNas ? ' (moved to NAS)' : ($nasUploaded ? ' (copied to NAS)' : '')),
            ];

        } catch (\Exception $e) {
            $this->removeDir($tempDir);
            // Complete status tracking - failure
            $this->completeBackupTracking($statusId, false, ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Backup failed: ' . $e->getMessage()];
        }
    }

    /**
     * Restore a site from backup
     * 
     * @param array $params {
     *   domain: string,
     *   archive: string,
     *   restore_files: bool|array - true/false or array of components ['plugins', 'themes', 'uploads', 'wpcore']
     *   restore_database: bool|array - true/false or array of database names to restore
     *   restore_config: bool - restore vhost config
     *   restore_ssl: bool - restore SSL certificates
     *   restore_dns: bool - restore DNS zone
     *   restore_mail: bool - restore mail accounts
     *   mode: string - 'merge' (default, safe) or 'replace' (uses --delete, destructive)
     *   dry_run: bool - if true, only analyze and report what would happen without making changes
     * }
     */
    private function restoreSite(array $params, string $actor): array
    {
        // Allow unlimited execution time for large site restores
        set_time_limit(0);
        
        $domain = $params['domain'] ?? null;
        $archivePath = $params['archive'] ?? null;
        $restoreFiles = $params['restore_files'] ?? true;
        $restoreDatabase = $params['restore_database'] ?? true;
        $restoreConfig = $params['restore_config'] ?? false;
        $restoreSsl = $params['restore_ssl'] ?? false;
        $restoreDns = $params['restore_dns'] ?? false;
        $restoreMail = $params['restore_mail'] ?? false;
        $dryRun = $params['dry_run'] ?? false;
        
        // IMPORTANT: Default to 'merge' mode (safe) - 'replace' mode uses --delete which removes files not in backup
        $mode = $params['mode'] ?? 'merge';
        
        // Detailed logs for dry run and debugging
        $logs = [];
        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => ($dryRun ? '[DRY RUN] ' : '') . "Starting restore for {$domain}"];
        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Mode: {$mode}, Actor: {$actor}"];
        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Options: files=" . json_encode($restoreFiles) . ", database=" . json_encode($restoreDatabase) . ", config={$restoreConfig}, ssl={$restoreSsl}, dns={$restoreDns}, mail={$restoreMail}"];
        
        if (!$domain || !$archivePath) {
            $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => 'Missing required parameters: domain or archive path'];
            return ['success' => false, 'error' => 'Domain and archive path are required', 'logs' => $logs];
        }
        
        if (!in_array($mode, ['merge', 'replace'])) {
            $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => "Invalid mode: {$mode}"];
            return ['success' => false, 'error' => 'Invalid mode. Use "merge" (safe) or "replace" (destructive)', 'logs' => $logs];
        }

        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Archive path: {$archivePath}"];
        
        // Check if this is a split archive
        $manifestPath = $archivePath . '.manifest.json';
        $isSplit = file_exists($manifestPath);
        $actualArchive = $archivePath;
        $tempReassembled = null;
        
        if ($isSplit) {
            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Detected split archive, reassembling...'];
            // Reassemble the split archive first
            $this->logger->info('Reassembling split archive for restore', ['domain' => $domain]);
            $reassembleResult = $this->reassembleSplitArchive($archivePath);
            
            if (!$reassembleResult['success']) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => 'Failed to reassemble split archive: ' . ($reassembleResult['error'] ?? 'Unknown error')];
                return ['success' => false, 'error' => 'Failed to reassemble split archive: ' . ($reassembleResult['error'] ?? 'Unknown error'), 'logs' => $logs];
            }
            
            $actualArchive = $reassembleResult['path'];
            $tempReassembled = $actualArchive;
            $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => 'Split archive reassembled successfully'];
        } else {
            if (!file_exists($archivePath)) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => "Archive not found: {$archivePath}"];
                return ['success' => false, 'error' => 'Archive not found', 'logs' => $logs];
            }
            $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => 'Archive file verified: ' . $this->formatBytes(filesize($archivePath))];
        }

        $homeDir = "/home/{$domain}";
        $timestamp = date('Y-m-d_H-i-s');
        $tempDir = sys_get_temp_dir() . '/vps-restore-' . uniqid();
        
        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Creating temp directory: {$tempDir}"];
        
        if (!mkdir($tempDir, 0750, true)) {
            if ($tempReassembled) @unlink($tempReassembled);
            $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => 'Failed to create temp directory'];
            return ['success' => false, 'error' => 'Failed to create temp directory', 'logs' => $logs];
        }

        $restored = [];
        $wouldRestore = []; // For dry run - what would be restored
        $errors = [];
        $analysis = []; // Detailed analysis for dry run

        try {
            // Extract archive
            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Extracting archive...'];
            exec("tar -xzf " . escapeshellarg($actualArchive) . " -C " . escapeshellarg($tempDir) . " 2>&1", $output, $exitCode);
            
            // Clean up reassembled archive after extraction
            if ($tempReassembled && file_exists($tempReassembled)) {
                @unlink($tempReassembled);
                $tempReassembled = null;
            }
            
            if ($exitCode !== 0) {
                $this->removeDir($tempDir);
                $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => 'Failed to extract archive: ' . implode("\n", $output)];
                return ['success' => false, 'error' => 'Failed to extract archive', 'logs' => $logs];
            }
            $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => 'Archive extracted successfully'];

            // Read metadata
            $metaFile = "{$tempDir}/backup_meta.json";
            $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : null;
            if ($meta) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Backup metadata: created ' . ($meta['timestamp'] ?? 'unknown') . ' by ' . ($meta['actor'] ?? 'unknown')];
                $analysis['backup_date'] = $meta['timestamp'] ?? null;
                $analysis['backup_actor'] = $meta['actor'] ?? null;
            }

            // 1. Create pre-restore backup of current state
            $preRestoreDir = "{$this->backupPath}/pre-restore/{$domain}_{$timestamp}";
            if (!$dryRun) {
                if (!is_dir(dirname($preRestoreDir))) {
                    mkdir(dirname($preRestoreDir), 0750, true);
                }
            }
            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => ($dryRun ? '[DRY RUN] Would create' : 'Pre-restore backup') . " path: {$preRestoreDir}"];

            // Backup current files before restore
            if (is_dir($homeDir) && $restoreFiles) {
                if ($dryRun) {
                    $currentSize = $this->getDirectorySize($homeDir);
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "[DRY RUN] Would backup current site files ({$this->formatBytes($currentSize)}) before restore"];
                } else {
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Creating pre-restore backup of current files...'];
                    exec("tar -czf " . escapeshellarg("{$preRestoreDir}_files.tar.gz") . " -C /home " . escapeshellarg($domain) . " 2>&1", $tarOutput, $tarExit);
                    if ($tarExit === 0) {
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => 'Pre-restore file backup created'];
                    } else {
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => 'Pre-restore file backup may have failed: ' . implode(' ', $tarOutput)];
                    }
                }
            }

            // 2. Restore files
            if ($restoreFiles && is_dir("{$tempDir}/files")) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => '--- FILE RESTORE ---'];
                
                // Analyze backup files - detect backup structure
                $backupFilesDir = "{$tempDir}/files";
                $backupFileCount = 0;
                $backupFileSize = 0;
                $backupStructure = 'unknown'; // 'new' = files/{domain}/, 'old_full' = files/public_html/, 'old_component' = files/plugins/ etc.
                
                // Check for new structure: files/{domain}/
                if (is_dir("{$backupFilesDir}/{$domain}")) {
                    $backupFileSize = $this->getDirectorySize("{$backupFilesDir}/{$domain}");
                    $backupFileCount = $this->countFiles("{$backupFilesDir}/{$domain}");
                    $backupStructure = 'new';
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Detected backup format: new (files/{domain}/)'];
                }
                // Check for old full backup structure: files/public_html/
                elseif (is_dir("{$backupFilesDir}/public_html")) {
                    $backupFileSize = $this->getDirectorySize($backupFilesDir);
                    $backupFileCount = $this->countFiles($backupFilesDir);
                    $backupStructure = 'old_full';
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Detected backup format: old full (files/public_html/)'];
                }
                // Check for old component structure: files/plugins/, files/themes/, etc.
                elseif (is_dir("{$backupFilesDir}/plugins") || is_dir("{$backupFilesDir}/themes") || 
                        is_dir("{$backupFilesDir}/uploads") || is_dir("{$backupFilesDir}/wpcore")) {
                    $backupFileSize = $this->getDirectorySize($backupFilesDir);
                    $backupFileCount = $this->countFiles($backupFilesDir);
                    $backupStructure = 'old_component';
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Detected backup format: old component (files/plugins/ etc.)'];
                }
                
                $analysis['files'] = [
                    'backup_file_count' => $backupFileCount,
                    'backup_size' => $backupFileSize,
                    'backup_size_human' => $this->formatBytes($backupFileSize),
                    'target_dir' => $homeDir,
                    'target_exists' => is_dir($homeDir),
                    'backup_structure' => $backupStructure,
                ];
                
                if (is_dir($homeDir)) {
                    $currentSize = $this->getDirectorySize($homeDir);
                    $currentCount = $this->countFiles($homeDir);
                    $analysis['files']['current_file_count'] = $currentCount;
                    $analysis['files']['current_size'] = $currentSize;
                    $analysis['files']['current_size_human'] = $this->formatBytes($currentSize);
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Current site: {$currentCount} files, {$this->formatBytes($currentSize)}"];
                } else {
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => "Target directory does not exist: {$homeDir}"];
                }
                
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Backup contains: {$backupFileCount} files, {$this->formatBytes($backupFileSize)}"];
                
                if ($dryRun) {
                    $wouldRestore[] = 'files';
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "[DRY RUN] Would restore files using mode: {$mode}"];
                    if ($mode === 'replace') {
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => '[DRY RUN] REPLACE mode: Files not in backup WOULD BE DELETED'];
                    }
                } else {
                    // Ensure home directory exists
                    if (!is_dir($homeDir)) {
                        mkdir($homeDir, 0755, true);
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Created target directory: {$homeDir}"];
                    }

                    $filesRestored = false;
                    
                    // Check if restoreFiles is an array of specific components
                    if (is_array($restoreFiles)) {
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Selective component restore: ' . implode(', ', $restoreFiles)];
                        // Selective component restore
                        $filesRestored = $this->restoreFileComponents(
                            $tempDir, $domain, $homeDir, $restoreFiles, $mode, $restored, $errors, $backupStructure
                        );
                        if ($filesRestored) {
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => 'Selected file components restored'];
                        } else {
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => 'File component restore failed'];
                        }
                    } else {
                        // Full files restore
                        // IMPORTANT: Only use --delete in 'replace' mode - it removes files not in backup!
                        $rsyncFlags = '-av --stats';
                        if ($mode === 'replace') {
                            $rsyncFlags .= ' --delete';
                            $this->logger->warning('Site restore using REPLACE mode (--delete flag)', [
                                'domain' => $domain,
                                'actor' => $actor,
                            ]);
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => 'Using REPLACE mode with --delete flag'];
                        }
                        
                        // Determine source path based on backup structure
                        $sourcePath = "{$tempDir}/files/{$domain}/"; // New structure
                        if ($backupStructure === 'old_full') {
                            $sourcePath = "{$tempDir}/files/"; // Old full backup
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Using old backup format source path'];
                        }
                        
                        $rsyncCmd = "rsync {$rsyncFlags} " . 
                                    escapeshellarg($sourcePath) . ' ' . 
                                    escapeshellarg($homeDir . '/');
                        
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Executing rsync..."];
                        exec($rsyncCmd . ' 2>&1', $rsyncOutput, $exitCode);
                        
                        if ($exitCode === 0) {
                            $restored[] = 'files';
                            $filesRestored = true;
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => 'Files restored successfully'];
                            // Parse rsync stats if available
                            foreach ($rsyncOutput as $line) {
                                if (strpos($line, 'Number of files') !== false || 
                                    strpos($line, 'Total file size') !== false ||
                                    strpos($line, 'Total transferred') !== false) {
                                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => trim($line)];
                                }
                            }
                        } else {
                            $errorMsg = 'Failed to restore files: ' . implode("\n", array_slice($rsyncOutput, -5));
                            $errors[] = $errorMsg;
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => $errorMsg];
                        }
                    }
                    
                    // Fix ownership after file restore
                    if ($filesRestored) {
                        $siteUser = $this->getSiteUser($domain);
                        if ($siteUser) {
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Fixing ownership to {$siteUser}:{$siteUser}"];
                            $this->execCommand('chown', ['-R', "{$siteUser}:{$siteUser}", $homeDir]);
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => 'Ownership fixed'];
                        } else {
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => "Could not determine site user for {$domain}"];
                        }
                    }
                }
            } elseif ($restoreFiles) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => 'Files restore requested but no files directory found in backup'];
            }

            // 3. Restore database(s)
            if ($restoreDatabase && is_dir("{$tempDir}/databases")) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => '--- DATABASE RESTORE ---'];
                
                $dbFiles = glob("{$tempDir}/databases/*.sql");
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Found ' . count($dbFiles) . ' database(s) in backup'];
                
                // If restoreDatabase is an array, filter to only those databases
                $allowedDatabases = is_array($restoreDatabase) ? $restoreDatabase : null;
                if ($allowedDatabases) {
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Selective restore: ' . implode(', ', $allowedDatabases)];
                }
                
                $analysis['databases'] = [];
                
                foreach ($dbFiles as $dbFile) {
                    $dbName = basename($dbFile, '.sql');
                    $dbSize = filesize($dbFile);
                    
                    // Skip if selective restore and this DB not in the list
                    if ($allowedDatabases !== null && !in_array($dbName, $allowedDatabases)) {
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Skipping database {$dbName} (not in selection)"];
                        continue;
                    }
                    
                    $analysis['databases'][$dbName] = [
                        'backup_size' => $dbSize,
                        'backup_size_human' => $this->formatBytes($dbSize),
                    ];
                    
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Database: {$dbName} ({$this->formatBytes($dbSize)})"];
                    
                    if ($dryRun) {
                        $wouldRestore[] = "database:{$dbName}";
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "[DRY RUN] Would restore database {$dbName}"];
                        
                        // Check if database exists
                        $dbExists = $this->databaseExists($dbName);
                        $analysis['databases'][$dbName]['exists'] = $dbExists;
                        if ($dbExists) {
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => "[DRY RUN] Database {$dbName} exists and WOULD BE OVERWRITTEN"];
                        } else {
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "[DRY RUN] Database {$dbName} does not exist, would be created"];
                        }
                    } else {
                        // Backup current database before restore
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Creating pre-restore backup of {$dbName}..."];
                        $dumpResult = $this->dumpDatabase($dbName, "{$preRestoreDir}_{$dbName}.sql");
                        if ($dumpResult['success']) {
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => "Pre-restore backup of {$dbName} created"];
                        } else {
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => "Could not backup {$dbName}: " . ($dumpResult['error'] ?? 'Database may not exist')];
                        }
                        
                        // Restore
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Restoring {$dbName}..."];
                        $restoreResult = $this->restoreDatabaseFromFile($dbName, $dbFile);
                        
                        if ($restoreResult['success']) {
                            $restored[] = "database:{$dbName}";
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => "Database {$dbName} restored successfully"];
                        } else {
                            $errorMsg = "Failed to restore database {$dbName}: " . ($restoreResult['error'] ?? 'Unknown error');
                            $errors[] = $errorMsg;
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => $errorMsg];
                        }
                    }
                }
            } elseif ($restoreDatabase) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => 'Database restore requested but no databases directory found in backup'];
            }

            // 4. Restore vhost config (optional)
            if ($restoreConfig && is_dir("{$tempDir}/vhost")) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => '--- VHOST CONFIG RESTORE ---'];
                $vhostPath = $this->config['paths']['ols_vhosts'] . '/' . $domain;
                
                $vhostFiles = glob("{$tempDir}/vhost/*");
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Backup contains ' . count($vhostFiles) . ' vhost config file(s)'];
                
                if ($dryRun) {
                    $wouldRestore[] = 'vhost_config';
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "[DRY RUN] Would restore vhost config to: {$vhostPath}"];
                    if (is_dir($vhostPath)) {
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => '[DRY RUN] Current vhost config EXISTS and would be backed up then overwritten'];
                    }
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => '[DRY RUN] Would reload OpenLiteSpeed after restore'];
                } else {
                    // Backup current config
                    if (is_dir($vhostPath)) {
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Backing up current vhost config...'];
                        $this->copyDir($vhostPath, "{$preRestoreDir}_vhost");
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => 'Current vhost config backed up'];
                    }
                    
                    // Restore config
                    if (!is_dir($vhostPath)) {
                        mkdir($vhostPath, 0755, true);
                    }
                    $this->copyDir("{$tempDir}/vhost", $vhostPath);
                    $restored[] = 'vhost_config';
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => 'Vhost config restored'];
                    
                    // Reload OLS
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Reloading OpenLiteSpeed...'];
                    $this->execCommand('/usr/local/lsws/bin/lswsctrl', ['reload']);
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => 'OpenLiteSpeed reloaded'];
                }
            } elseif ($restoreConfig) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => 'Vhost config restore requested but no vhost directory found in backup'];
            }

            // 4b. Restore SSL certificates (optional)
            if ($restoreSsl && is_dir("{$tempDir}/ssl")) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => '--- SSL CERTIFICATES RESTORE ---'];
                $sslPath = "/etc/letsencrypt/live/{$domain}";
                
                $certFiles = glob("{$tempDir}/ssl/*.pem");
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Backup contains ' . count($certFiles) . ' SSL certificate file(s)'];
                
                if ($dryRun) {
                    $wouldRestore[] = 'ssl_certs';
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "[DRY RUN] Would restore SSL certs to: {$sslPath}"];
                    if (is_dir($sslPath)) {
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => '[DRY RUN] Current SSL certs EXIST and would be backed up then overwritten'];
                    }
                } else {
                    // Backup current SSL if exists
                    if (is_dir($sslPath)) {
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Backing up current SSL certificates...'];
                        $sslBackupDir = "{$preRestoreDir}_ssl";
                        if (!is_dir($sslBackupDir)) {
                            mkdir($sslBackupDir, 0750, true);
                        }
                        foreach (['fullchain.pem', 'privkey.pem', 'cert.pem', 'chain.pem'] as $certFile) {
                            $certPath = "{$sslPath}/{$certFile}";
                            if (file_exists($certPath)) {
                                copy(realpath($certPath), "{$sslBackupDir}/{$certFile}");
                            }
                        }
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => 'Current SSL certificates backed up'];
                    }
                    
                    // Restore SSL certs
                    if (!is_dir($sslPath)) {
                        mkdir($sslPath, 0755, true);
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Created SSL directory: {$sslPath}"];
                    }
                    foreach (['fullchain.pem', 'privkey.pem', 'cert.pem', 'chain.pem'] as $certFile) {
                        $srcCert = "{$tempDir}/ssl/{$certFile}";
                        if (file_exists($srcCert)) {
                            copy($srcCert, "{$sslPath}/{$certFile}");
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Restored: {$certFile}"];
                        }
                    }
                    $restored[] = 'ssl_certs';
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => 'SSL certificates restored'];
                }
            } elseif ($restoreSsl) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => 'SSL restore requested but no ssl directory found in backup'];
            }

            // 5. Restore DNS zone (optional)
            if ($restoreDns && file_exists("{$tempDir}/dns/zone.json")) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => '--- DNS ZONE RESTORE ---'];
                $dnsData = json_decode(file_get_contents("{$tempDir}/dns/zone.json"), true);
                $recordCount = count($dnsData['records'] ?? []);
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Backup contains {$recordCount} DNS record(s)"];
                
                if ($dryRun) {
                    $wouldRestore[] = 'dns_zone';
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "[DRY RUN] Would restore {$recordCount} DNS records for {$domain}"];
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => '[DRY RUN] Existing DNS records would be replaced'];
                } else {
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Restoring DNS zone...'];
                    if ($dnsData && $this->restoreDnsZone($domain, $dnsData)) {
                        $restored[] = 'dns_zone';
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => "DNS zone restored ({$recordCount} records)"];
                    } else {
                        $errors[] = 'Failed to restore DNS zone';
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => 'Failed to restore DNS zone'];
                    }
                }
            } elseif ($restoreDns) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => 'DNS restore requested but no zone.json found in backup'];
            }

            // 6. Restore mail accounts (optional)
            if ($restoreMail && file_exists("{$tempDir}/mail/accounts.json")) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => '--- MAIL ACCOUNTS RESTORE ---'];
                $mailData = json_decode(file_get_contents("{$tempDir}/mail/accounts.json"), true);
                $accountCount = isset($mailData['accounts']) ? count($mailData['accounts']) : 0;
                $forwardCount = isset($mailData['forwards']) ? count($mailData['forwards']) : 0;
                $hasDkim = is_dir("{$tempDir}/mail/dkim");
                
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Backup contains {$accountCount} mail account(s), {$forwardCount} forward(s)" . ($hasDkim ? ', DKIM keys' : '')];
                
                if ($dryRun) {
                    $wouldRestore[] = 'mail_accounts';
                    if ($hasDkim) {
                        $wouldRestore[] = 'dkim_keys';
                    }
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "[DRY RUN] Would restore {$accountCount} mail accounts and {$forwardCount} forwards"];
                    if ($hasDkim) {
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => '[DRY RUN] Would restore DKIM keys'];
                    }
                } else {
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Restoring mail accounts...'];
                    if ($mailData && $this->restoreMailAccounts($domain, $mailData)) {
                        $restored[] = 'mail_accounts';
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => "Mail accounts restored ({$accountCount} accounts, {$forwardCount} forwards)"];
                        
                        // Restore DKIM keys if they exist
                        if ($hasDkim) {
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Restoring DKIM keys...'];
                            $dkimPath = "/etc/opendkim/keys/{$domain}";
                            if (!is_dir($dkimPath)) {
                                mkdir($dkimPath, 0700, true);
                            }
                            $this->copyDir("{$tempDir}/mail/dkim", $dkimPath);
                            $this->execCommand('chown', ['-R', 'opendkim:opendkim', $dkimPath]);
                            $restored[] = 'dkim_keys';
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => 'DKIM keys restored'];
                            
                            // Reload opendkim
                            $this->execCommand('systemctl', ['reload', 'opendkim']);
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'OpenDKIM reloaded'];
                        }
                    } else {
                        $errors[] = 'Failed to restore mail accounts';
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => 'Failed to restore mail accounts'];
                    }
                }
            } elseif ($restoreMail) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => 'Mail restore requested but no accounts.json found in backup'];
            }

            // 7. Recreate site user if missing (only for actual restore, not dry run)
            if (!$dryRun) {
                $siteUser = $this->getSiteUser($domain);
                if (!$siteUser && is_dir($homeDir)) {
                    // Try to create the user from metadata
                    if ($meta && !empty($meta['sftp_user'])) {
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Site user missing, recreating from backup metadata...'];
                        $this->recreateSiteUser($domain, $meta['sftp_user'], $homeDir);
                        $restored[] = 'sftp_user';
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => "Site user recreated: {$meta['sftp_user']}"];
                    }
                }
            }

            // Cleanup
            $this->removeDir($tempDir);
            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Cleanup completed'];

            // Final summary
            if ($dryRun) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => '=== DRY RUN COMPLETE ==='];
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Components that WOULD be restored: ' . (count($wouldRestore) > 0 ? implode(', ', $wouldRestore) : 'none')];
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'No actual changes were made'];
                
                return [
                    'success' => true,
                    'data' => [
                        'domain' => $domain,
                        'dry_run' => true,
                        'would_restore' => $wouldRestore,
                        'analysis' => $analysis,
                        'logs' => $logs,
                    ],
                    'message' => 'Dry run complete - ' . count($wouldRestore) . ' component(s) would be restored',
                    'logs' => $logs,
                ];
            }

            $this->logger->info('Site restored from backup', [
                'domain' => $domain,
                'archive' => basename($archivePath),
                'restored' => $restored,
                'errors' => $errors,
                'actor' => $actor,
            ]);

            $logs[] = ['time' => date('H:i:s'), 'level' => count($errors) === 0 ? 'success' : 'warning', 'message' => '=== RESTORE COMPLETE ==='];
            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Restored: ' . (count($restored) > 0 ? implode(', ', $restored) : 'none')];
            if (count($errors) > 0) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => 'Errors: ' . count($errors)];
            }

            return [
                'success' => count($errors) === 0,
                'data' => [
                    'domain' => $domain,
                    'restored' => $restored,
                    'errors' => $errors,
                    'pre_restore_backup' => $preRestoreDir,
                    'logs' => $logs,
                ],
                'message' => count($restored) . ' components restored' . 
                            (count($errors) > 0 ? ', ' . count($errors) . ' errors' : ''),
                'logs' => $logs,
            ];

        } catch (\Exception $e) {
            $this->removeDir($tempDir);
            $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => 'Exception: ' . $e->getMessage()];
            $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => 'Stack trace: ' . $e->getTraceAsString()];
            return ['success' => false, 'error' => 'Restore failed: ' . $e->getMessage(), 'logs' => $logs];
        }
    }

    /**
     * List backups for a specific site (or all sites).
     *
     * Returns one unified list across both storage locations. Each entry has
     * a 'location' field: 'local' (server only), 'nas' (NAS only) or 'both'.
     * NAS entries come from (a) .meta.json stubs left behind by move-to-NAS
     * and (b) a direct scan of the mounted NAS backups folder.
     */
    private function listSiteBackups(array $params): array
    {
        $domain = $params['domain'] ?? null;

        $backupDir = $domain
            ? "{$this->backupPath}/sites/{$domain}"
            : "{$this->backupPath}/sites";

        // Keyed by filename so local/stub/NAS information merges per backup.
        $entries = [];

        try {
            $this->scanLocalSiteBackups($backupDir, $entries);
        } catch (\Exception $e) {
            // Continue with whatever was collected.
        }

        // NAS side: only when a connection exists and the mount is live.
        $nas = $this->getBackupNasConnection();
        $nasMounted = false;
        if ($nas) {
            $mountPoint = rtrim($nas['mount_point'], '/');
            $nasMounted = is_dir($mountPoint) && $this->isMounted($mountPoint);
            if ($nasMounted) {
                $nasDir = $domain
                    ? "{$mountPoint}/backups/sites/{$domain}"
                    : "{$mountPoint}/backups/sites";
                try {
                    $this->mergeNasSiteBackups($nasDir, $entries);
                } catch (\Exception $e) {
                    // NAS scan failure must not break the local listing.
                }
            }
        }

        $backups = [];
        foreach ($entries as $entry) {
            // Stubs that claim a NAS copy which no longer exists (NAS was
            // scanned and the file is gone) are stale - drop them and clean
            // up the orphaned stub file.
            if (!empty($entry['_stub_only']) && $nasMounted && empty($entry['_found_on_nas'])) {
                if (!empty($entry['_stub_file'])) {
                    @unlink($entry['_stub_file']);
                }
                continue;
            }

            // NAS-only entries are unavailable for download/restore while the
            // NAS is unreachable; the UI shows them greyed out instead of
            // hiding them.
            if ($entry['location'] === 'nas') {
                $entry['available'] = $nasMounted && !empty($entry['_found_on_nas']);
            } else {
                $entry['available'] = true;
            }

            unset($entry['_stub_only'], $entry['_found_on_nas'], $entry['_stub_file']);
            $backups[] = $entry;
        }

        usort($backups, fn($a, $b) => strcmp($b['date'], $a['date']));

        return [
            'success' => true,
            'data' => [
                'backups' => $backups,
                'nas_mounted' => $nasMounted,
            ],
        ];
    }

    /**
     * Scan the local site backups directory into the merge map.
     * Collects both real archives and NAS-move stubs (.meta.json without
     * a matching .tar.gz).
     */
    private function scanLocalSiteBackups(string $backupDir, array &$entries): void
    {
        if (!is_dir($backupDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backupDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;

            $filename = $file->getFilename();

            // NAS-move stub: meta file whose archive is gone.
            if (str_ends_with($filename, '.tar.gz.meta.json')) {
                $archiveName = substr($filename, 0, -strlen('.meta.json'));
                $archivePath = dirname($file->getPathname()) . '/' . $archiveName;
                if (file_exists($archivePath)) {
                    continue; // archive present - handled below as a real file
                }

                $meta = json_decode((string)file_get_contents($file->getPathname()), true) ?: [];
                if (empty($meta['nas_uploaded'])) {
                    continue; // orphan meta without a NAS copy - nothing to show
                }

                $parsed = $this->parseSiteBackupFilename($archiveName);
                if ($parsed === null) {
                    continue;
                }

                $entries[$archiveName] = [
                    'id' => base64_encode($archivePath), // replaced by NAS path during merge
                    'domain' => $meta['domain'] ?? $parsed['domain'],
                    'filename' => $archiveName,
                    'path' => $archivePath,
                    'size' => $meta['archive_size'] ?? 0,
                    'size_human' => isset($meta['archive_size']) ? $this->formatBytes($meta['archive_size']) : 'N/A',
                    'date' => $parsed['date'],
                    'type' => 'site',
                    'backup_type' => $parsed['backup_type'],
                    'components' => $meta['components'] ?? null,
                    'databases' => $meta['databases'] ?? null,
                    'destination' => $meta['destination'] ?? 'nas',
                    'nas_uploaded' => true,
                    'split' => !empty($meta['split']),
                    'parts_count' => (int)($meta['parts_count'] ?? 1),
                    'location' => 'nas',
                    '_stub_only' => true,
                    '_stub_file' => $file->getPathname(),
                ];
                continue;
            }

            if (!str_ends_with($filename, '.tar.gz')) continue;

            $parsed = $this->parseSiteBackupFilename($filename);
            if ($parsed === null) {
                continue;
            }

            // Read metadata if exists
            $metaFile = $file->getPathname() . '.meta.json';
            $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : null;

            // Check if this is a split archive (has manifest)
            $manifestFile = $file->getPathname() . '.manifest.json';
            $isSplit = file_exists($manifestFile);
            $totalSize = $file->getSize();
            $partsCount = 1;

            if ($isSplit) {
                $manifest = json_decode(file_get_contents($manifestFile), true);
                $totalSize = $manifest['total_size'] ?? 0;
                $partsCount = $manifest['parts_count'] ?? 1;
            }

            $nasUploaded = $meta['nas_uploaded'] ?? false;

            $entries[$filename] = [
                'id' => base64_encode($file->getPathname()),
                'domain' => $parsed['domain'],
                'filename' => $filename,
                'path' => $file->getPathname(),
                'size' => $totalSize,
                'size_human' => $this->formatBytes($totalSize),
                'date' => date('Y-m-d H:i:s', $file->getMTime()),
                'type' => 'site',
                'backup_type' => $parsed['backup_type'],
                'components' => $meta['components'] ?? null,
                'databases' => $meta['databases'] ?? null,
                'destination' => $meta['destination'] ?? 'local',
                'nas_uploaded' => $nasUploaded,
                'split' => $isSplit,
                'parts_count' => $partsCount,
                'location' => $nasUploaded ? 'both' : 'local',
            ];
        }
    }

    /**
     * Merge the NAS-side scan into the entry map: confirm 'both' locations,
     * resolve stub entries to real NAS paths, and surface NAS-only backups
     * the server has no record of (e.g. moved there by external tooling).
     */
    private function mergeNasSiteBackups(string $nasDir, array &$entries): void
    {
        if (!is_dir($nasDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($nasDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;

            $filename = $file->getFilename();

            // Split sets uploaded before the zero-byte marker existed have
            // only .part_* + manifest on NAS. Recognise them by manifest so
            // they are not invisible (and their local stubs not pruned).
            if (str_ends_with($filename, '.tar.gz.manifest.json')) {
                $archiveName = substr($filename, 0, -strlen('.manifest.json'));
                $archiveBase = dirname($file->getPathname()) . '/' . $archiveName;
                if (file_exists($archiveBase)) {
                    continue; // marker present - handled by the .tar.gz branch
                }
                $this->mergeNasSiteArchive($archiveBase, $archiveName, $entries);
                continue;
            }

            if (!str_ends_with($filename, '.tar.gz')) continue;

            $this->mergeNasSiteArchive($file->getPathname(), $filename, $entries);
        }
    }

    /**
     * Merge one NAS-side archive (marker file or manifest-derived base path)
     * into the listing entry map.
     */
    private function mergeNasSiteArchive(string $nasArchivePath, string $filename, array &$entries): void
    {
        $parsed = $this->parseSiteBackupFilename($filename);
        if ($parsed === null) {
            return;
        }

        // Split sets keep the payload in .part_* files; the .tar.gz itself is
        // a zero-byte marker. Real size/parts come from the NAS-side manifest.
        $nasManifest = file_exists($nasArchivePath . '.manifest.json')
            ? (json_decode((string)file_get_contents($nasArchivePath . '.manifest.json'), true) ?: null)
            : null;
        $isSplit = $nasManifest !== null;
        $nasSize = $isSplit
            ? (int)($nasManifest['total_size'] ?? 0)
            : (int)(@filesize($nasArchivePath) ?: 0);
        $partsCount = $isSplit ? (int)($nasManifest['parts_count'] ?? count($nasManifest['parts'] ?? [])) : 1;

        if (isset($entries[$filename])) {
            $entry = &$entries[$filename];

            if (!empty($entry['_found_on_nas'])) {
                unset($entry);
                return; // already merged via the other branch
            }

            $entry['_found_on_nas'] = true;
            $entry['nas_uploaded'] = true;

            if (!empty($entry['_stub_only']) || $entry['location'] === 'nas') {
                // NAS-only: point the id/path at the real NAS file so
                // download/restore/delete work directly off the mount.
                $entry['id'] = base64_encode($nasArchivePath);
                $entry['path'] = $nasArchivePath;
                $entry['size'] = $nasSize ?: $entry['size'];
                $entry['size_human'] = $this->formatBytes($nasSize ?: (int)$entry['size']);
                if ($isSplit) {
                    $entry['split'] = true;
                    $entry['parts_count'] = $partsCount;
                }
                $entry['location'] = 'nas';
            } else {
                $entry['location'] = 'both';
            }
            unset($entry);
            return;
        }

        // Present on NAS but unknown locally. The filename carries the real
        // backup timestamp; marker mtime is the (later) upload time.
        $metaFile = $nasArchivePath . '.meta.json';
        $meta = file_exists($metaFile) ? (json_decode((string)file_get_contents($metaFile), true) ?: null) : null;

        $entries[$filename] = [
            'id' => base64_encode($nasArchivePath),
            'domain' => $meta['domain'] ?? $parsed['domain'],
            'filename' => $filename,
            'path' => $nasArchivePath,
            'size' => $nasSize,
            'size_human' => $this->formatBytes($nasSize),
            'date' => $parsed['date'],
            'type' => 'site',
            'backup_type' => $parsed['backup_type'],
            'components' => $meta['components'] ?? null,
            'databases' => $meta['databases'] ?? null,
            'destination' => 'nas',
            'nas_uploaded' => true,
            'split' => $isSplit,
            'parts_count' => $partsCount,
            'location' => 'nas',
            '_found_on_nas' => true,
        ];
    }

    /**
     * Parse a site backup filename into its parts.
     * Formats:
     *   domain_YYYY-MM-DD_HH-MM-SS_full.tar.gz
     *   domain_YYYY-MM-DD_HH-MM-SS_database.tar.gz
     *   domain_YYYY-MM-DD_HH-MM-SS.tar.gz
     */
    private function parseSiteBackupFilename(string $filename): ?array
    {
        if (!preg_match('/^(.+?)_(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})(?:_([a-z-]+))?\.tar\.gz$/', $filename, $m)) {
            return null;
        }

        return [
            'domain' => $m[1],
            'date' => "{$m[2]} {$m[3]}:{$m[4]}:{$m[5]}",
            'backup_type' => $m[6] ?? 'full',
        ];
    }

    /**
     * Inspect a site backup archive contents (FAST - no full extraction)
     * Returns detailed information about what can be restored
     * Uses tar --list to scan contents without extracting entire archive
     * Supports split archives (reassembles temporarily if needed)
     */
    private function inspectBackup(array $params): array
    {
        $archivePath = $params['archive'] ?? null;
        
        if (!$archivePath) {
            return ['success' => false, 'error' => 'Archive path is required'];
        }
        
        // Check if this is a split archive
        $manifestPath = $archivePath . '.manifest.json';
        $isSplit = file_exists($manifestPath);
        $actualArchive = $archivePath;
        $tempReassembled = null;
        $totalSize = 0;
        $downloadedFromNas = false;
        $nasOnly = false;
        
        if ($isSplit) {
            // Read manifest to get total size and reassemble
            $manifest = json_decode(file_get_contents($manifestPath), true);
            if (!$manifest) {
                return ['success' => false, 'error' => 'Invalid split archive manifest'];
            }
            
            $totalSize = $manifest['total_size'] ?? 0;
            
            // Reassemble the archive temporarily
            $reassembleResult = $this->reassembleSplitArchive($archivePath);
            if (!$reassembleResult['success']) {
                return ['success' => false, 'error' => 'Failed to reassemble split archive: ' . ($reassembleResult['error'] ?? 'Unknown error')];
            }
            
            $actualArchive = $reassembleResult['path'];
            $tempReassembled = $actualArchive; // Mark for cleanup
        } else {
            if (!file_exists($archivePath)) {
                // Check if this is a NAS-only backup
                $metaFile = "{$archivePath}.meta.json";
                if (file_exists($metaFile)) {
                    $meta = json_decode(file_get_contents($metaFile), true);
                    if (!empty($meta['nas_uploaded'])) {
                        $nasOnly = true;
                        // Extract domain from path for NAS lookup
                        $domain = $meta['domain'] ?? null;
                        if (!$domain) {
                            // Try to extract from path: .../sites/{domain}/filename.tar.gz
                            if (preg_match('#/sites/([^/]+)/#', $archivePath, $matches)) {
                                $domain = $matches[1];
                            }
                        }
                        
                        // Download from NAS
                        $downloadResult = $this->downloadFromNas($archivePath, $domain);
                        if (!$downloadResult['success']) {
                            return ['success' => false, 'error' => 'Backup is on NAS only but download failed: ' . ($downloadResult['error'] ?? 'Unknown error')];
                        }
                        $downloadedFromNas = true;
                        $totalSize = filesize($archivePath);
                    } else {
                        return ['success' => false, 'error' => 'Backup archive not found'];
                    }
                } else {
                    return ['success' => false, 'error' => 'Backup archive not found'];
                }
            } else {
                $totalSize = filesize($archivePath);
            }
        }
        
        try {
            $contents = [
                'meta' => null,
                'files' => [],
                'databases' => [],
                'components' => [],
                'vhost' => false,
                'ssl' => false,
                'dns' => false,
                'mail' => false,
                'archive_size' => $totalSize,
                'archive_size_human' => $this->formatBytes($totalSize),
                'split' => $isSplit,
                'nas_only' => $nasOnly,
                'downloaded_from_nas' => $downloadedFromNas,
            ];
            
            // FAST: Use tar --list to get archive contents without extraction
            exec("tar -tzf " . escapeshellarg($actualArchive) . " 2>&1", $listing, $exitCode);
            
            if ($exitCode !== 0) {
                if ($tempReassembled) @unlink($tempReassembled);
                return ['success' => false, 'error' => 'Failed to read archive contents: ' . implode("\n", $listing)];
            }
            
            $contents['total_files'] = count($listing);
            $filesCount = 0;
            $dbFiles = [];
            
            // Parse the listing to detect components
            foreach ($listing as $path) {
                $path = ltrim($path, './');
                
                // Detect databases
                if (preg_match('#^databases/([^/]+)\.sql$#', $path, $matches)) {
                    $dbFiles[$matches[1]] = true;
                }
                
                // Detect files and components
                if (strpos($path, 'files/') === 0) {
                    $filesCount++;
                    if (strpos($path, 'wp-content/plugins') !== false) {
                        $contents['components']['plugins'] = true;
                    }
                    if (strpos($path, 'wp-content/themes') !== false) {
                        $contents['components']['themes'] = true;
                    }
                    if (strpos($path, 'wp-content/uploads') !== false) {
                        $contents['components']['uploads'] = true;
                    }
                    if (strpos($path, 'wp-config.php') !== false || strpos($path, 'wp-includes/') !== false) {
                        $contents['components']['wpcore'] = true;
                    }
                }
                
                // Detect other components
                if (strpos($path, 'vhost/') === 0) $contents['vhost'] = true;
                if (strpos($path, 'ssl/') === 0) $contents['ssl'] = true;
                if (strpos($path, 'dns/') === 0) $contents['dns'] = true;
                if (strpos($path, 'mail/') === 0) $contents['mail'] = true;
            }
            
            $contents['files_count'] = $filesCount;
            
            // Add database names (without sizes - we'd need to extract to get those)
            foreach (array_keys($dbFiles) as $dbName) {
                $contents['databases'][] = [
                    'name' => $dbName,
                    'size' => null, // Size unknown without extraction
                    'size_human' => 'N/A',
                ];
            }
            
            // Extract ONLY the metadata file (small, fast)
            $tempDir = sys_get_temp_dir() . '/vps-meta-' . uniqid();
            mkdir($tempDir, 0750, true);
            
            // Try to extract just backup_meta.json
            exec("tar -xzf " . escapeshellarg($actualArchive) . " -C " . escapeshellarg($tempDir) . " ./backup_meta.json 2>/dev/null");
            
            $metaFile = "{$tempDir}/backup_meta.json";
            if (file_exists($metaFile)) {
                $contents['meta'] = json_decode(file_get_contents($metaFile), true);
            }
            
            // Try to extract dns/zone.json for record count (small file)
            if ($contents['dns']) {
                exec("tar -xzf " . escapeshellarg($actualArchive) . " -C " . escapeshellarg($tempDir) . " ./dns/zone.json 2>/dev/null");
                $dnsFile = "{$tempDir}/dns/zone.json";
                if (file_exists($dnsFile)) {
                    $dnsData = json_decode(file_get_contents($dnsFile), true);
                    $contents['dns_records_count'] = count($dnsData['records'] ?? []);
                }
            }
            
            // Try to extract mail/accounts.json for counts (small file)
            if ($contents['mail']) {
                exec("tar -xzf " . escapeshellarg($actualArchive) . " -C " . escapeshellarg($tempDir) . " ./mail/accounts.json 2>/dev/null");
                $mailFile = "{$tempDir}/mail/accounts.json";
                if (file_exists($mailFile)) {
                    $mailData = json_decode(file_get_contents($mailFile), true);
                    $contents['mail_accounts_count'] = count($mailData['accounts'] ?? []);
                    $contents['mail_forwards_count'] = count($mailData['forwards'] ?? []);
                }
            }
            
            // Cleanup temp directory and reassembled archive
            $this->removeDir($tempDir);
            if ($tempReassembled && file_exists($tempReassembled)) {
                @unlink($tempReassembled);
            }
            
            return [
                'success' => true,
                'data' => $contents,
            ];
            
        } catch (\Exception $e) {
            if ($tempReassembled && file_exists($tempReassembled)) {
                @unlink($tempReassembled);
            }
            return ['success' => false, 'error' => 'Inspection failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Inspect a config backup archive contents (FAST - minimal extraction)
     * Returns detailed information about which categories can be restored
     * Uses tar --list for fast scanning, only extracts meta files
     */
    private function inspectConfigBackup(array $params): array
    {
        $archivePath = $params['archive'] ?? null;
        
        if (!$archivePath) {
            return ['success' => false, 'error' => 'Archive path is required'];
        }
        
        if (!file_exists($archivePath)) {
            return ['success' => false, 'error' => 'Backup archive not found'];
        }
        
        try {
            // FAST: Use tar --list to get archive contents without extraction
            exec("tar -tzf " . escapeshellarg($archivePath) . " 2>&1", $listing, $exitCode);
            
            if ($exitCode !== 0) {
                return ['success' => false, 'error' => 'Failed to read archive contents'];
            }
            
            // Parse listing to find categories and meta files
            $categoryData = [];
            $metaFiles = [];
            
            foreach ($listing as $path) {
                $path = ltrim($path, './');
                $parts = explode('/', $path);
                
                if (count($parts) < 1) continue;
                
                $categoryId = $parts[0];
                
                // Skip non-category entries
                if (!isset($this->configPaths[$categoryId])) continue;
                
                // Initialize category if not exists
                if (!isset($categoryData[$categoryId])) {
                    $categoryData[$categoryId] = [
                        'files_count' => 0,
                        'meta_files' => [],
                    ];
                }
                
                // Track meta files for extraction
                if (str_ends_with($path, '.meta.json')) {
                    $categoryData[$categoryId]['meta_files'][] = $path;
                    $metaFiles[] = $path;
                } else {
                    $categoryData[$categoryId]['files_count']++;
                }
            }
            
            // Now extract only the meta files to get original paths
            $categories = [];
            
            if (!empty($metaFiles)) {
                $tempDir = sys_get_temp_dir() . '/vps-inspect-config-' . uniqid();
                mkdir($tempDir, 0750, true);
                
                // Extract only meta files (small, fast)
                $metaFilesArg = implode(' ', array_map(function($f) { 
                    return escapeshellarg('./' . $f); 
                }, array_slice($metaFiles, 0, 100))); // Limit to first 100 meta files
                
                exec("tar -xzf " . escapeshellarg($archivePath) . " -C " . escapeshellarg($tempDir) . " {$metaFilesArg} 2>/dev/null");
                
                foreach ($categoryData as $categoryId => $data) {
                    $items = [];
                    
                    foreach ($data['meta_files'] as $metaPath) {
                        $fullPath = "{$tempDir}/{$metaPath}";
                        if (file_exists($fullPath)) {
                            $meta = json_decode(file_get_contents($fullPath), true);
                            if ($meta && isset($meta['original_path'])) {
                                $items[] = [
                                    'original_path' => $meta['original_path'],
                                    'backup_date' => $meta['date'] ?? null,
                                ];
                            }
                        }
                    }
                    
                    $categories[] = [
                        'id' => $categoryId,
                        'label' => $this->configPaths[$categoryId]['label'] ?? $categoryId,
                        'items' => $items,
                        'items_count' => count($items),
                        'files_count' => $data['files_count'],
                        'total_size' => null, // Unknown without full extraction
                        'total_size_human' => 'N/A',
                    ];
                }
                
                // Cleanup
                $this->removeDir($tempDir);
            } else {
                // No meta files, just list categories
                foreach ($categoryData as $categoryId => $data) {
                    $categories[] = [
                        'id' => $categoryId,
                        'label' => $this->configPaths[$categoryId]['label'] ?? $categoryId,
                        'items' => [],
                        'items_count' => 0,
                        'files_count' => $data['files_count'],
                        'total_size' => null,
                        'total_size_human' => 'N/A',
                    ];
                }
            }
            
            return [
                'success' => true,
                'data' => [
                    'categories' => $categories,
                    'archive_size' => filesize($archivePath),
                    'archive_size_human' => $this->formatBytes(filesize($archivePath)),
                ],
            ];
            
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Inspection failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Restore selected categories from a config backup
     * 
     * @param array $params {
     *   archive: string - path to the backup archive
     *   categories: array - list of category IDs to restore (e.g., ['webserver', 'mysql', 'mail'])
     *   dry_run: bool - if true, only simulate the restore and return what would happen
     * }
     */
    private function restoreConfigBackup(array $params, string $actor): array
    {
        $archivePath = $params['archive'] ?? null;
        $categoriesToRestore = $params['categories'] ?? [];
        $dryRun = $params['dry_run'] ?? false;
        
        $logs = [];
        
        if ($dryRun) {
            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => '[DRY RUN] Starting config restore simulation'];
        }
        
        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Actor: ' . $actor];
        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Categories: ' . implode(', ', $categoriesToRestore)];
        
        if (!$archivePath) {
            return ['success' => false, 'error' => 'Archive path is required', 'logs' => $logs];
        }
        
        if (empty($categoriesToRestore)) {
            return ['success' => false, 'error' => 'No categories selected for restore', 'logs' => $logs];
        }
        
        if (!file_exists($archivePath)) {
            return ['success' => false, 'error' => 'Backup archive not found', 'logs' => $logs];
        }
        
        $archiveSize = filesize($archivePath);
        $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => 'Archive verified: ' . $this->formatBytes($archiveSize)];
        
        $tempDir = sys_get_temp_dir() . '/vps-restore-config-' . uniqid();
        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Creating temp directory: ' . $tempDir];
        
        if (!mkdir($tempDir, 0750, true)) {
            return ['success' => false, 'error' => 'Failed to create temp directory', 'logs' => $logs];
        }
        
        try {
            // Extract archive
            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Extracting archive...'];
            exec("tar -xzf " . escapeshellarg($archivePath) . " -C " . escapeshellarg($tempDir) . " 2>&1", $output, $exitCode);
            
            if ($exitCode !== 0) {
                $this->removeDir($tempDir);
                $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => 'Failed to extract archive'];
                return ['success' => false, 'error' => 'Failed to extract archive', 'logs' => $logs];
            }
            
            $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => 'Archive extracted successfully'];
            
            $restored = [];
            $wouldRestore = [];
            $errors = [];
            $timestamp = date('Y-m-d_H-i-s');
            $preRestoreDir = $this->backupPath . '/pre-restore/' . $timestamp;
            
            if ($dryRun) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => '[DRY RUN] Would create pre-restore backup at: ' . $preRestoreDir];
            } else {
                if (!is_dir($preRestoreDir)) {
                    mkdir($preRestoreDir, 0750, true);
                }
            }
            
            // Process each category directory in the backup
            $dirs = glob("{$tempDir}/*", GLOB_ONLYDIR);
            
            // Get category names for logging
            $categoryNames = [
                'webserver' => 'OpenLiteSpeed Config',
                'vhosts' => 'Virtual Hosts',
                'mysql' => 'MySQL/MariaDB',
                'databases' => 'All Databases',
                'mail' => 'Mail Server',
                'dns' => 'DNS Server',
                'fail2ban' => 'Fail2ban',
                'firewall' => 'Firewall',
                'ssl' => 'SSL Certificates',
                'php' => 'PHP Configuration',
                'cron' => 'Cron Jobs',
                'system' => 'System Config',
            ];
            
            foreach ($dirs as $dir) {
                $categoryId = basename($dir);
                
                // Skip categories not selected for restore
                if (!in_array($categoryId, $categoriesToRestore)) {
                    continue;
                }
                
                $categoryName = $categoryNames[$categoryId] ?? $categoryId;
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "--- {$categoryName} ---"];
                
                // Skip unknown categories
                if (!isset($this->configPaths[$categoryId])) {
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => "Unknown category: {$categoryId}"];
                    continue;
                }
                
                // Count items in this category
                $categoryItemCount = 0;
                $categoryTotalSize = 0;
                
                // Scan for .meta.json files to find restore targets
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
                );
                
                $itemsToRestore = [];
                
                foreach ($iterator as $file) {
                    if (!$file->isFile()) continue;
                    
                    $filename = $file->getFilename();
                    if (!str_ends_with($filename, '.meta.json')) continue;
                    
                    $meta = json_decode(file_get_contents($file->getPathname()), true);
                    if (!$meta || !isset($meta['original_path'])) continue;
                    
                    $originalPath = $meta['original_path'];
                    $backedUpPath = str_replace('.meta.json', '', $file->getPathname());
                    
                    if (!file_exists($backedUpPath)) {
                        $errors[] = "Backup file not found: " . basename($backedUpPath);
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => 'Backup file missing: ' . basename($backedUpPath)];
                        continue;
                    }
                    
                    $itemsToRestore[] = [
                        'original' => $originalPath,
                        'backup' => $backedUpPath,
                        'meta' => $meta,
                    ];
                    
                    $categoryItemCount++;
                    if (is_dir($backedUpPath)) {
                        $categoryTotalSize += $this->getDirectorySize($backedUpPath);
                    } else {
                        $categoryTotalSize += filesize($backedUpPath);
                    }
                }
                
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Backup contains {$categoryItemCount} item(s), " . $this->formatBytes($categoryTotalSize)];
                
                // Process each item
                foreach ($itemsToRestore as $item) {
                    $originalPath = $item['original'];
                    $backedUpPath = $item['backup'];
                    $isDir = is_dir($backedUpPath);
                    $itemName = basename($originalPath);
                    
                    // Check if current file/dir exists
                    $currentExists = file_exists($originalPath);
                    $currentSize = 0;
                    if ($currentExists) {
                        $currentSize = $isDir ? $this->getDirectorySize($originalPath) : filesize($originalPath);
                    }
                    
                    $backupSize = $isDir ? $this->getDirectorySize($backedUpPath) : filesize($backedUpPath);
                    
                    if ($dryRun) {
                        if ($currentExists) {
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => "[DRY RUN] {$itemName}: EXISTS (" . $this->formatBytes($currentSize) . ") - would be backed up then overwritten"];
                        } else {
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "[DRY RUN] {$itemName}: would be created (" . $this->formatBytes($backupSize) . ")"];
                        }
                        $wouldRestore[] = $originalPath;
                    } else {
                        // Create pre-restore backup of current file/dir
                        if ($currentExists) {
                            $preRestorePath = $preRestoreDir . $originalPath;
                            $preRestoreParent = dirname($preRestorePath);
                            
                            if (!is_dir($preRestoreParent)) {
                                mkdir($preRestoreParent, 0750, true);
                            }
                            
                            if ($isDir) {
                                $this->copyDir($originalPath, $preRestorePath);
                            } else {
                                copy($originalPath, $preRestorePath);
                            }
                        }
                        
                        // Ensure target directory exists
                        $targetDir = dirname($originalPath);
                        if (!is_dir($targetDir)) {
                            mkdir($targetDir, 0755, true);
                        }
                        
                        // Restore the file/directory
                        if ($isDir) {
                            if ($this->copyDir($backedUpPath, $originalPath)) {
                                $restored[] = $originalPath;
                                $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => "Restored: {$itemName}"];
                            } else {
                                $errors[] = "Failed to restore: {$originalPath}";
                                $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => "Failed to restore: {$itemName}"];
                            }
                        } else {
                            if (copy($backedUpPath, $originalPath)) {
                                $restored[] = $originalPath;
                                $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => "Restored: {$itemName}"];
                            } else {
                                $errors[] = "Failed to restore: {$originalPath}";
                                $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => "Failed to restore: {$itemName}"];
                            }
                        }
                    }
                }
            }
            
            // Clean up temp directory
            $this->removeDir($tempDir);
            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Cleanup completed'];
            
            // Reload services if needed
            $servicesReloaded = [];
            $servicesToReload = [];
            
            if (in_array('webserver', $categoriesToRestore) || in_array('vhosts', $categoriesToRestore)) {
                $servicesToReload[] = ['name' => 'OpenLiteSpeed', 'cmd' => '/usr/local/lsws/bin/lswsctrl', 'args' => ['reload']];
            }
            if (in_array('mail', $categoriesToRestore)) {
                $servicesToReload[] = ['name' => 'Postfix', 'cmd' => 'systemctl', 'args' => ['reload', 'postfix']];
                $servicesToReload[] = ['name' => 'Dovecot', 'cmd' => 'systemctl', 'args' => ['reload', 'dovecot']];
            }
            if (in_array('fail2ban', $categoriesToRestore)) {
                $servicesToReload[] = ['name' => 'Fail2ban', 'cmd' => 'systemctl', 'args' => ['reload', 'fail2ban']];
            }
            if (in_array('firewall', $categoriesToRestore)) {
                $servicesToReload[] = ['name' => 'Firewalld', 'cmd' => 'systemctl', 'args' => ['reload', 'firewalld']];
            }
            if (in_array('databases', $categoriesToRestore) || in_array('mysql', $categoriesToRestore)) {
                $servicesToReload[] = ['name' => 'MariaDB', 'cmd' => 'systemctl', 'args' => ['reload', 'mariadb']];
            }
            
            if (!empty($servicesToReload)) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => '--- SERVICE RELOAD ---'];
                foreach ($servicesToReload as $service) {
                    if ($dryRun) {
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "[DRY RUN] Would reload: {$service['name']}"];
                    } else {
                        $this->execCommand($service['cmd'], $service['args']);
                        $servicesReloaded[] = $service['name'];
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => "Reloaded: {$service['name']}"];
                    }
                }
            }
            
            // Final summary
            if ($dryRun) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => '=== DRY RUN COMPLETE ==='];
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Items that WOULD be restored: ' . count($wouldRestore)];
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Services that WOULD be reloaded: ' . implode(', ', array_column($servicesToReload, 'name'))];
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'No actual changes were made'];
                
                return [
                    'success' => true,
                    'data' => [
                        'dry_run' => true,
                        'would_restore' => $wouldRestore,
                        'categories' => $categoriesToRestore,
                        'services_to_reload' => array_column($servicesToReload, 'name'),
                        'logs' => $logs,
                    ],
                    'message' => 'Dry run complete - ' . count($wouldRestore) . ' items would be restored',
                    'logs' => $logs,
                ];
            }
            
            $this->logger->info('Config backup restored selectively', [
                'actor' => $actor,
                'categories' => $categoriesToRestore,
                'restored_count' => count($restored),
                'errors_count' => count($errors),
                'services_reloaded' => $servicesReloaded,
            ]);
            
            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => '=== RESTORE COMPLETE ==='];
            $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => count($restored) . ' items restored'];
            if (count($errors) > 0) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => count($errors) . ' errors occurred'];
            }
            
            return [
                'success' => count($errors) === 0,
                'data' => [
                    'restored' => $restored,
                    'errors' => $errors,
                    'categories_restored' => $categoriesToRestore,
                    'pre_restore_backup' => $preRestoreDir,
                    'services_reloaded' => $servicesReloaded,
                    'logs' => $logs,
                ],
                'message' => count($restored) . ' items restored from ' . count($categoriesToRestore) . ' categories' .
                            (count($errors) > 0 ? ', ' . count($errors) . ' errors' : ''),
                'logs' => $logs,
            ];
            
        } catch (\Exception $e) {
            $this->removeDir($tempDir);
            $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => 'Restore failed: ' . $e->getMessage()];
            return ['success' => false, 'error' => 'Restore failed: ' . $e->getMessage(), 'logs' => $logs];
        }
    }
    
    /**
     * Analyze files directory and return summary
     */
    private function analyzeFilesDirectory(string $dir): array
    {
        $summary = [];
        $totalFiles = 0;
        $totalSize = 0;
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            // Group files by top-level directory
            $groups = [];
            
            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                
                $totalFiles++;
                $totalSize += $file->getSize();
                
                // Get relative path from files dir
                $relativePath = str_replace($dir . '/', '', $file->getPathname());
                $parts = explode('/', $relativePath);
                
                // Group by first directory component (usually domain name)
                $firstDir = $parts[0] ?? 'root';
                if (!isset($groups[$firstDir])) {
                    $groups[$firstDir] = ['count' => 0, 'size' => 0];
                }
                $groups[$firstDir]['count']++;
                $groups[$firstDir]['size'] += $file->getSize();
            }
            
            foreach ($groups as $name => $info) {
                $summary[] = [
                    'path' => $name,
                    'files_count' => $info['count'],
                    'size' => $info['size'],
                    'size_human' => $this->formatBytes($info['size']),
                ];
            }
            
        } catch (\Exception $e) {
            // Return empty on error
        }
        
        return [
            'total_files' => $totalFiles,
            'total_size' => $totalSize,
            'total_size_human' => $this->formatBytes($totalSize),
            'directories' => $summary,
        ];
    }
    
    /**
     * Restore specific file components selectively
     * 
     * @param string $tempDir Extracted backup temp directory
     * @param string $domain Domain name
     * @param string $homeDir Home directory path
     * @param array $components Components to restore ['plugins', 'themes', 'uploads', 'wpcore']
     * @param string $mode 'merge' or 'replace'
     * @param array &$restored Array to track restored items
     * @param array &$errors Array to track errors
     * @param string $backupStructure Backup structure type: 'new', 'old_full', or 'old_component'
     * @return bool Success status
     */
    private function restoreFileComponents(
        string $tempDir, 
        string $domain, 
        string $homeDir, 
        array $components, 
        string $mode,
        array &$restored,
        array &$errors,
        string $backupStructure = 'new'
    ): bool {
        $publicHtml = "{$homeDir}/public_html";
        $backupFilesBase = "{$tempDir}/files";
        
        // Determine paths based on backup structure
        if ($backupStructure === 'new') {
            // New structure: files/{domain}/public_html/...
            $backupFiles = "{$backupFilesBase}/{$domain}";
            $backupPublicHtml = "{$backupFiles}/public_html";
        } elseif ($backupStructure === 'old_full') {
            // Old full backup: files/public_html/...
            $backupFiles = $backupFilesBase;
            $backupPublicHtml = "{$backupFilesBase}/public_html";
        } else {
            // Old component: files/plugins/, files/themes/, etc.
            $backupFiles = $backupFilesBase;
            $backupPublicHtml = $backupFilesBase; // Components are at root level
        }
        
        // For new and old_full, check if public_html exists
        if ($backupStructure !== 'old_component' && !is_dir($backupPublicHtml)) {
            $errors[] = "Backup files directory not found: {$backupPublicHtml}";
            return false;
        }
        
        $success = true;
        $rsyncFlags = '-a';
        if ($mode === 'replace') {
            $rsyncFlags .= ' --delete';
        }
        
        foreach ($components as $component) {
            switch ($component) {
                case 'plugins':
                    // Determine source based on structure
                    if ($backupStructure === 'old_component') {
                        $src = "{$backupFilesBase}/plugins/";
                    } else {
                        $src = "{$backupPublicHtml}/wp-content/plugins/";
                    }
                    $dst = "{$publicHtml}/wp-content/plugins/";
                    if (is_dir(rtrim($src, '/'))) {
                        if (!is_dir($dst)) {
                            mkdir($dst, 0755, true);
                        }
                        exec("rsync {$rsyncFlags} " . escapeshellarg($src) . " " . escapeshellarg($dst) . " 2>&1", $output, $exitCode);
                        if ($exitCode === 0) {
                            $restored[] = 'files:plugins';
                        } else {
                            $errors[] = 'Failed to restore plugins';
                            $success = false;
                        }
                    } else {
                        $errors[] = "Plugins directory not found in backup: {$src}";
                    }
                    break;
                    
                case 'themes':
                    if ($backupStructure === 'old_component') {
                        $src = "{$backupFilesBase}/themes/";
                    } else {
                        $src = "{$backupPublicHtml}/wp-content/themes/";
                    }
                    $dst = "{$publicHtml}/wp-content/themes/";
                    if (is_dir(rtrim($src, '/'))) {
                        if (!is_dir($dst)) {
                            mkdir($dst, 0755, true);
                        }
                        exec("rsync {$rsyncFlags} " . escapeshellarg($src) . " " . escapeshellarg($dst) . " 2>&1", $output, $exitCode);
                        if ($exitCode === 0) {
                            $restored[] = 'files:themes';
                        } else {
                            $errors[] = 'Failed to restore themes';
                            $success = false;
                        }
                    } else {
                        $errors[] = "Themes directory not found in backup: {$src}";
                    }
                    break;
                    
                case 'uploads':
                    if ($backupStructure === 'old_component') {
                        $src = "{$backupFilesBase}/uploads/";
                    } else {
                        $src = "{$backupPublicHtml}/wp-content/uploads/";
                    }
                    $dst = "{$publicHtml}/wp-content/uploads/";
                    if (is_dir(rtrim($src, '/'))) {
                        if (!is_dir($dst)) {
                            mkdir($dst, 0755, true);
                        }
                        exec("rsync {$rsyncFlags} " . escapeshellarg($src) . " " . escapeshellarg($dst) . " 2>&1", $output, $exitCode);
                        if ($exitCode === 0) {
                            $restored[] = 'files:uploads';
                        } else {
                            $errors[] = 'Failed to restore uploads';
                            $success = false;
                        }
                    } else {
                        $errors[] = "Uploads directory not found in backup: {$src}";
                    }
                    break;
                    
                case 'wpcore':
                    // Restore WP core files (everything except wp-content)
                    $excludes = ['--exclude=wp-content'];
                    if ($backupStructure === 'old_component') {
                        $src = "{$backupFilesBase}/wpcore/";
                    } else {
                        $src = "{$backupPublicHtml}/";
                    }
                    $dst = "{$publicHtml}/";
                    if (is_dir(rtrim($src, '/'))) {
                        $rsyncCmd = "rsync {$rsyncFlags} " . implode(' ', $excludes) . " " . 
                                    escapeshellarg($src) . " " . escapeshellarg($dst);
                        exec($rsyncCmd . " 2>&1", $output, $exitCode);
                        if ($exitCode === 0) {
                            $restored[] = 'files:wpcore';
                        } else {
                            $errors[] = 'Failed to restore WP core files';
                            $success = false;
                        }
                    } else {
                        $errors[] = "WP core directory not found in backup: {$src}";
                    }
                    break;
                    
                case 'all':
                    // Full files restore
                    if ($backupStructure === 'new') {
                        $src = "{$backupFilesBase}/{$domain}/";
                    } else {
                        $src = "{$backupFilesBase}/";
                    }
                    if (is_dir(rtrim($src, '/'))) {
                        exec("rsync {$rsyncFlags} " . escapeshellarg($src) . " " . escapeshellarg($homeDir . '/') . " 2>&1", $output, $exitCode);
                        if ($exitCode === 0) {
                            $restored[] = 'files';
                        } else {
                            $errors[] = 'Failed to restore all files';
                            $success = false;
                        }
                    } else {
                        $errors[] = "Backup files directory not found: {$src}";
                    }
                    break;
            }
        }
        
        return $success;
    }

    /**
     * Backup a single database
     */
    private function backupDatabase(array $params, string $actor): array
    {
        $dbName = $params['database'] ?? null;
        
        if (!$dbName) {
            return ['success' => false, 'error' => 'Database name is required'];
        }

        $timestamp = date('Y-m-d_H-i-s');
        $backupDir = "{$this->backupPath}/databases";
        
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }

        $backupFile = "{$backupDir}/{$dbName}_{$timestamp}.sql.gz";
        
        $result = $this->dumpDatabase($dbName, $backupFile, true);
        
        if ($result['success']) {
            $this->logger->info('Database backup created', [
                'database' => $dbName,
                'file' => basename($backupFile),
                'actor' => $actor,
            ]);
            
            return [
                'success' => true,
                'data' => [
                    'database' => $dbName,
                    'file' => basename($backupFile),
                    'path' => $backupFile,
                    'size' => filesize($backupFile),
                    'size_human' => $this->formatBytes(filesize($backupFile)),
                ],
                'message' => "Database {$dbName} backed up",
            ];
        }

        return $result;
    }

    /**
     * Restore a database from backup
     */
    private function restoreDatabase(array $params, string $actor): array
    {
        $dbName = $params['database'] ?? null;
        $backupFile = $params['file'] ?? null;
        
        if (!$dbName || !$backupFile) {
            return ['success' => false, 'error' => 'Database and backup file are required'];
        }

        if (!file_exists($backupFile)) {
            return ['success' => false, 'error' => 'Backup file not found'];
        }

        // Create pre-restore backup
        $timestamp = date('Y-m-d_H-i-s');
        $preRestoreFile = "{$this->backupPath}/pre-restore/{$dbName}_{$timestamp}.sql";
        
        if (!is_dir(dirname($preRestoreFile))) {
            mkdir(dirname($preRestoreFile), 0750, true);
        }
        
        $this->dumpDatabase($dbName, $preRestoreFile);

        // Restore
        $result = $this->restoreDatabaseFromFile($dbName, $backupFile);
        
        if ($result['success']) {
            $this->logger->info('Database restored', [
                'database' => $dbName,
                'file' => basename($backupFile),
                'actor' => $actor,
            ]);
        }

        return $result;
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get all site domains from vhosts directory
     */
    private function getAllSites(): array
    {
        $sites = [];
        $vhostsPath = $this->config['paths']['ols_vhosts'] ?? '/usr/local/lsws/conf/vhosts';
        
        if (is_dir($vhostsPath)) {
            $dirs = glob($vhostsPath . '/*', GLOB_ONLYDIR);
            foreach ($dirs as $dir) {
                $sites[] = basename($dir);
            }
        }
        
        return $sites;
    }

    /**
     * Find databases associated with a site
     * Supports: WordPress, Joomla, Drupal, Magento, Laravel, PrestaShop, OpenCart
     */
    private function findSiteDatabases(string $domain): array
    {
        $databases = [];
        $publicHtml = "/home/{$domain}/public_html";
        
        // 1. Check wp-config.php for WordPress sites
        $wpConfig = "{$publicHtml}/wp-config.php";
        if (file_exists($wpConfig)) {
            $content = file_get_contents($wpConfig);
            if (preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $matches)) {
                $databases[] = $matches[1];
            }
        }

        // 2. Check Joomla configuration.php
        $joomlaConfig = "{$publicHtml}/configuration.php";
        if (file_exists($joomlaConfig)) {
            $content = file_get_contents($joomlaConfig);
            if (preg_match("/public\s+\\\$db\s*=\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
                if (!in_array($matches[1], $databases)) {
                    $databases[] = $matches[1];
                }
            }
        }

        // 3. Check Drupal settings.php
        $drupalSettings = "{$publicHtml}/sites/default/settings.php";
        if (file_exists($drupalSettings)) {
            $content = file_get_contents($drupalSettings);
            // Drupal 8/9/10 format
            if (preg_match("/['\"]database['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
                if (!in_array($matches[1], $databases)) {
                    $databases[] = $matches[1];
                }
            }
        }

        // 4. Check Magento env.php
        $magentoEnv = "{$publicHtml}/app/etc/env.php";
        if (file_exists($magentoEnv)) {
            $config = @include($magentoEnv);
            if (is_array($config) && isset($config['db']['connection']['default']['dbname'])) {
                $dbName = $config['db']['connection']['default']['dbname'];
                if (!in_array($dbName, $databases)) {
                    $databases[] = $dbName;
                }
            }
        }

        // 5. Check Laravel .env
        $laravelEnv = "{$publicHtml}/.env";
        if (file_exists($laravelEnv)) {
            $content = file_get_contents($laravelEnv);
            if (preg_match("/DB_DATABASE\s*=\s*(.+)/", $content, $matches)) {
                $dbName = trim($matches[1]);
                if (!empty($dbName) && !in_array($dbName, $databases)) {
                    $databases[] = $dbName;
                }
            }
        }

        // 6. Check PrestaShop parameters.php (1.7+)
        $prestashopParams = "{$publicHtml}/app/config/parameters.php";
        if (file_exists($prestashopParams)) {
            $config = @include($prestashopParams);
            if (is_array($config) && isset($config['parameters']['database_name'])) {
                $dbName = $config['parameters']['database_name'];
                if (!in_array($dbName, $databases)) {
                    $databases[] = $dbName;
                }
            }
        }
        
        // PrestaShop older versions (settings.inc.php)
        $prestashopOld = "{$publicHtml}/config/settings.inc.php";
        if (file_exists($prestashopOld)) {
            $content = file_get_contents($prestashopOld);
            if (preg_match("/define\s*\(\s*['\"]_DB_NAME_['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $matches)) {
                if (!in_array($matches[1], $databases)) {
                    $databases[] = $matches[1];
                }
            }
        }

        // 7. Check OpenCart config.php
        $opencartConfig = "{$publicHtml}/config.php";
        if (file_exists($opencartConfig)) {
            $content = file_get_contents($opencartConfig);
            if (preg_match("/define\s*\(\s*['\"]DB_DATABASE['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $matches)) {
                if (!in_array($matches[1], $databases)) {
                    $databases[] = $matches[1];
                }
            }
        }

        // 8. Check generic .env files in root
        $envFile = "{$publicHtml}/../.env";
        if (file_exists($envFile)) {
            $content = file_get_contents($envFile);
            foreach (['DB_DATABASE', 'DATABASE_NAME', 'DB_NAME', 'MYSQL_DATABASE'] as $key) {
                if (preg_match("/{$key}\s*=\s*(.+)/", $content, $matches)) {
                    $dbName = trim(trim($matches[1]), '"\'');
                    if (!empty($dbName) && !in_array($dbName, $databases)) {
                        $databases[] = $dbName;
                    }
                    break;
                }
            }
        }
        
        // 9. Check our panel's database_links table
        try {
            $panelDb = $this->getPanelDb();
            if ($panelDb) {
                $stmt = $panelDb->prepare("SELECT db_name FROM database_links WHERE domain = ?");
                $stmt->execute([$domain]);
                
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    if (!in_array($row['db_name'], $databases)) {
                        $databases[] = $row['db_name'];
                    }
                }
            }
        } catch (\Exception $e) {
            // Continue without linked databases
        }

        // 10. Look for databases matching domain pattern
        try {
            $password = $this->getMySqlPassword();
            $pdo = new \PDO(
                "mysql:unix_socket=/var/run/mysqld/mysqld.sock",
                'root',
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );

            $domainPattern = str_replace(['.', '-'], '_', $domain);
            $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA 
                                   WHERE SCHEMA_NAME LIKE ? OR SCHEMA_NAME LIKE ?");
            $stmt->execute(["%{$domainPattern}%", "wp_{$domainPattern}%"]);
            
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $dbName = $row['SCHEMA_NAME'];
                // Exclude system databases
                if (!in_array($dbName, ['information_schema', 'mysql', 'performance_schema', 'sys', 'cyberpanel', 'devc_vps_dash']) 
                    && !in_array($dbName, $databases)) {
                    $databases[] = $dbName;
                }
            }
        } catch (\Exception $e) {
            // Continue
        }

        return $databases;
    }

    /**
     * Dump a database to file (optimized for large databases)
     * 
     * @param string $dbName Database name
     * @param string $outputFile Output file path
     * @param bool $compress Whether to gzip compress
     * @param bool $chunked For very large DBs, dump table-by-table
     */
    private function dumpDatabase(string $dbName, string $outputFile, bool $compress = false, bool $chunked = false): array
    {
        $password = $this->getMySqlPassword();
        
        // Check database size to determine approach
        $dbSizeMB = $this->getDatabaseSizeMB($dbName);
        
        // Auto-enable chunked mode for databases > 500MB
        if ($dbSizeMB > 500 && !$chunked) {
            $chunked = true;
            $this->logger->info("Large database detected ({$dbSizeMB}MB), using chunked dump", ['database' => $dbName]);
        }
        
        if ($chunked) {
            return $this->dumpDatabaseChunked($dbName, dirname($outputFile));
        }
        
        // Optimized mysqldump options for large databases:
        // --quick: Retrieve rows one at a time (less memory)
        // --single-transaction: Consistent snapshot for InnoDB
        // --max_allowed_packet: Allow larger packets
        // --net_buffer_length: Optimize network buffer
        $dumpOpts = '--quick --single-transaction --routines --triggers ' .
                    '--max_allowed_packet=512M --net_buffer_length=32768';
        
        if ($compress || str_ends_with($outputFile, '.gz')) {
            $cmd = "mysqldump {$dumpOpts} " .
                   escapeshellarg($dbName) . " | gzip > " . escapeshellarg($outputFile);
        } else {
            $cmd = "mysqldump {$dumpOpts} " .
                   escapeshellarg($dbName) . " > " . escapeshellarg($outputFile);
        }
        
        // Set password via environment
        putenv("MYSQL_PWD={$password}");
        exec($cmd . " 2>&1", $output, $exitCode);
        putenv("MYSQL_PWD=");

        if ($exitCode === 0 && file_exists($outputFile)) {
            return ['success' => true, 'size_mb' => $dbSizeMB];
        }

        return ['success' => false, 'error' => implode("\n", $output)];
    }
    
    /**
     * Get database size in MB
     */
    private function getDatabaseSizeMB(string $dbName): float
    {
        try {
            $password = $this->getMySqlPassword();
            $pdo = new \PDO(
                "mysql:unix_socket=/var/run/mysqld/mysqld.sock",
                'root',
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            
            $stmt = $pdo->prepare("
                SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
                FROM information_schema.tables 
                WHERE table_schema = ?
            ");
            $stmt->execute([$dbName]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return (float)($result['size_mb'] ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * Dump a large database table-by-table (chunked)
     * Creates a directory with structure.sql and individual table files
     */
    private function dumpDatabaseChunked(string $dbName, string $outputDir): array
    {
        $password = $this->getMySqlPassword();
        
        // Get list of tables
        putenv("MYSQL_PWD={$password}");
        exec("mysql -N -e 'SHOW TABLES' " . escapeshellarg($dbName) . " 2>&1", $tables, $exitCode);
        
        if ($exitCode !== 0 || empty($tables)) {
            putenv("MYSQL_PWD=");
            // Fallback to regular dump
            return $this->dumpDatabase($dbName, "{$outputDir}/{$dbName}.sql", false, false);
        }
        
        // Create chunked directory
        $dbDir = "{$outputDir}/{$dbName}_chunked";
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0750, true);
        }
        
        $dumpOpts = '--quick --single-transaction --max_allowed_packet=512M';
        $dumpedTables = [];
        $errors = [];
        
        // 1. Dump structure first (schema, routines, triggers)
        $structureCmd = "mysqldump --no-data --routines --triggers {$dumpOpts} " . 
                       escapeshellarg($dbName) . " > " . escapeshellarg("{$dbDir}/_structure.sql") . " 2>&1";
        exec($structureCmd, $structureOutput, $structureExit);
        
        if ($structureExit !== 0) {
            $errors[] = "Failed to dump structure: " . implode("\n", $structureOutput);
        }
        
        // 2. Dump each table's data
        foreach ($tables as $table) {
            $table = trim($table);
            if (empty($table)) continue;
            
            $tableFile = "{$dbDir}/{$table}.sql.gz";
            $tableCmd = "mysqldump --no-create-info {$dumpOpts} " .
                       escapeshellarg($dbName) . " " . escapeshellarg($table) . 
                       " | gzip > " . escapeshellarg($tableFile) . " 2>&1";
            
            exec($tableCmd, $tableOutput, $tableExit);
            
            if ($tableExit === 0) {
                $dumpedTables[] = $table;
            } else {
                $errors[] = "Failed to dump table {$table}";
            }
        }
        
        putenv("MYSQL_PWD=");
        
        // 3. Create manifest for reassembly
        $manifest = [
            'database' => $dbName,
            'chunked' => true,
            'tables' => $dumpedTables,
            'tables_count' => count($dumpedTables),
            'timestamp' => date('Y-m-d H:i:s'),
            'errors' => $errors,
        ];
        file_put_contents("{$dbDir}/_manifest.json", json_encode($manifest, JSON_PRETTY_PRINT));
        
        $this->logger->info('Chunked database backup created', [
            'database' => $dbName,
            'tables' => count($dumpedTables),
            'errors' => count($errors),
        ]);
        
        return [
            'success' => count($errors) === 0,
            'chunked' => true,
            'tables' => count($dumpedTables),
            'errors' => $errors,
            'path' => $dbDir,
        ];
    }

    /**
     * Restore a database from SQL file
     */
    private function restoreDatabaseFromFile(string $dbName, string $sqlFile): array
    {
        $password = $this->getMySqlPassword();
        
        // Check if this is a chunked database backup (directory with manifest)
        $chunkedDir = str_replace('.sql', '_chunked', $sqlFile);
        if (is_dir($chunkedDir) && file_exists("{$chunkedDir}/_manifest.json")) {
            return $this->restoreChunkedDatabase($dbName, $chunkedDir);
        }
        
        if (str_ends_with($sqlFile, '.gz')) {
            $cmd = "gunzip -c " . escapeshellarg($sqlFile) . " | mysql " . escapeshellarg($dbName);
        } else {
            $cmd = "mysql " . escapeshellarg($dbName) . " < " . escapeshellarg($sqlFile);
        }
        
        putenv("MYSQL_PWD={$password}");
        exec($cmd . " 2>&1", $output, $exitCode);
        putenv("MYSQL_PWD=");

        if ($exitCode === 0) {
            return ['success' => true];
        }

        return ['success' => false, 'error' => implode("\n", $output)];
    }

    /**
     * Restore a chunked database backup (table-by-table)
     */
    private function restoreChunkedDatabase(string $dbName, string $chunkedDir): array
    {
        $manifestFile = "{$chunkedDir}/_manifest.json";
        $manifest = json_decode(file_get_contents($manifestFile), true);
        
        if (!$manifest) {
            return ['success' => false, 'error' => 'Invalid manifest file'];
        }
        
        $password = $this->getMySqlPassword();
        putenv("MYSQL_PWD={$password}");
        
        $errors = [];
        $tablesRestored = 0;
        
        // 1. Restore structure first
        $structureFile = "{$chunkedDir}/_structure.sql";
        if (file_exists($structureFile)) {
            $cmd = "mysql " . escapeshellarg($dbName) . " < " . escapeshellarg($structureFile) . " 2>&1";
            exec($cmd, $output, $exitCode);
            
            if ($exitCode !== 0) {
                $errors[] = "Failed to restore structure: " . implode("\n", $output);
            }
        }
        
        // 2. Restore each table's data
        foreach ($manifest['tables'] ?? [] as $table) {
            $tableFile = "{$chunkedDir}/{$table}.sql.gz";
            
            if (!file_exists($tableFile)) {
                $errors[] = "Missing table file: {$table}.sql.gz";
                continue;
            }
            
            $cmd = "gunzip -c " . escapeshellarg($tableFile) . " | mysql " . escapeshellarg($dbName) . " 2>&1";
            exec($cmd, $output, $exitCode);
            
            if ($exitCode === 0) {
                $tablesRestored++;
            } else {
                $errors[] = "Failed to restore table {$table}";
            }
        }
        
        putenv("MYSQL_PWD=");
        
        $this->logger->info('Chunked database restored', [
            'database' => $dbName,
            'tables_restored' => $tablesRestored,
            'errors' => count($errors),
        ]);
        
        return [
            'success' => count($errors) === 0,
            'chunked' => true,
            'tables_restored' => $tablesRestored,
            'errors' => $errors,
        ];
    }

    /**
     * Get MySQL password from config
     */
    private function getMySqlPassword(): string
    {
        $mycnf = '/root/.my.cnf';
        if (file_exists($mycnf)) {
            $content = file_get_contents($mycnf);
            if (preg_match('/password\s*=\s*["\']?([^"\'\\n]+)["\']?/i', $content, $matches)) {
                return trim($matches[1]);
            }
        }
        return '';
    }

    /**
     * Get the site user (owner of home directory)
     */
    private function getSiteUser(string $domain): ?string
    {
        $homeDir = "/home/{$domain}";
        
        if (is_dir($homeDir)) {
            $stat = stat($homeDir);
            if ($stat) {
                $userInfo = posix_getpwuid($stat['uid']);
                if ($userInfo && $userInfo['name'] !== 'root') {
                    return $userInfo['name'];
                }
            }
        }
        
        return null;
    }

    /**
     * Backup DNS zone records for a domain
     */
    private function backupDnsZone(string $domain): array
    {
        try {
            $panelDb = $this->getPanelDb();
            if (!$panelDb) {
                throw new \Exception('Cannot connect to panel database');
            }

            // Get zone ID from our dns_domains table
            $stmt = $panelDb->prepare("SELECT id FROM dns_domains WHERE name = ?");
            $stmt->execute([$domain]);
            $zone = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$zone) {
                return [];
            }

            // Get all records for this zone from our dns_records table
            $stmt = $panelDb->prepare("SELECT name, type, content, ttl, prio FROM dns_records WHERE domain_id = ?");
            $stmt->execute([$zone['id']]);
            $records = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'domain' => $domain,
                'zone_id' => $zone['id'],
                'records' => $records,
            ];

        } catch (\Exception $e) {
            $this->logger->error("Failed to backup DNS zone: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Restore DNS zone records for a domain
     */
    private function restoreDnsZone(string $domain, array $dnsData): bool
    {
        if (empty($dnsData['records'])) {
            return false;
        }

        try {
            $panelDb = $this->getPanelDb();
            if (!$panelDb) {
                throw new \Exception('Cannot connect to panel database');
            }

            // Check if zone exists in our dns_domains table
            $stmt = $panelDb->prepare("SELECT id FROM dns_domains WHERE name = ?");
            $stmt->execute([$domain]);
            $zone = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$zone) {
                // Create the zone
                $stmt = $panelDb->prepare("INSERT INTO dns_domains (name, type) VALUES (?, 'NATIVE')");
                $stmt->execute([$domain]);
                $zoneId = $panelDb->lastInsertId();
            } else {
                $zoneId = $zone['id'];
                // Clear existing records
                $stmt = $panelDb->prepare("DELETE FROM dns_records WHERE domain_id = ?");
                $stmt->execute([$zoneId]);
            }

            // Restore all records to our dns_records table
            $stmt = $panelDb->prepare("INSERT INTO dns_records (domain_id, name, type, content, ttl, prio) VALUES (?, ?, ?, ?, ?, ?)");
            
            foreach ($dnsData['records'] as $record) {
                $stmt->execute([
                    $zoneId,
                    $record['name'],
                    $record['type'],
                    $record['content'],
                    $record['ttl'] ?? 3600,
                    $record['prio'] ?? 0,
                ]);
            }

            // Notify PowerDNS
            $this->execCommand('pdns_control', ['notify', $domain]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error("Failed to restore DNS zone: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Backup mail accounts and forwards for a domain
     */
    private function backupMailAccounts(string $domain): array
    {
        $result = [
            'domain' => $domain,
            'accounts' => [],
            'forwards' => [],
        ];

        try {
            $panelDb = $this->getPanelDb();
            if (!$panelDb) {
                throw new \Exception('Cannot connect to panel database');
            }

            // Get mail accounts from our mail_accounts table
            $stmt = $panelDb->prepare("SELECT email, password_hash FROM mail_accounts WHERE domain = ? AND status = 'active'");
            $stmt->execute([$domain]);
            $accounts = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            foreach ($accounts as $account) {
                $result['accounts'][] = [
                    'email' => $account['email'],
                    'password_hash' => $account['password_hash'],
                ];
            }
            
            // Get mail forwards from our mail_forwards table
            $stmt = $panelDb->prepare("SELECT source_email, destination FROM mail_forwards WHERE source_domain = ? AND status = 'active'");
            $stmt->execute([$domain]);
            $forwards = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            foreach ($forwards as $forward) {
                $result['forwards'][] = [
                    'source' => $forward['source_email'],
                    'destination' => $forward['destination'],
                ];
            }

            // Get mail forwards/aliases from postfix virtual aliases
            $virtualAliases = '/etc/postfix/valiases/' . $domain;
            if (file_exists($virtualAliases)) {
                $content = file_get_contents($virtualAliases);
                $lines = explode("\n", $content);
                
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || strpos($line, '#') === 0) {
                        continue;
                    }
                    
                    // Format: source@domain destination@domain
                    if (preg_match('/^(\S+)\s+(\S+)$/', $line, $matches)) {
                        $result['forwards'][] = [
                            'source' => $matches[1],
                            'destination' => $matches[2],
                        ];
                    }
                }
            }

            // Get catchall if configured
            $virtualDomains = '/etc/postfix/virtual_domains';
            if (file_exists($virtualDomains)) {
                $content = file_get_contents($virtualDomains);
                if (strpos($content, $domain) !== false) {
                    $result['virtual_domain'] = true;
                }
            }

        } catch (\Exception $e) {
            $this->logger->error("Failed to backup mail accounts: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * Restore mail accounts and forwards for a domain
     */
    private function restoreMailAccounts(string $domain, array $mailData): bool
    {
        if (empty($mailData['accounts']) && empty($mailData['forwards'])) {
            return false;
        }

        try {
            $panelDb = $this->getPanelDb();
            if (!$panelDb) {
                throw new \Exception('Cannot connect to panel database');
            }

            // Ensure mail domain exists
            $stmt = $panelDb->prepare("INSERT IGNORE INTO mail_domains (domain, status) VALUES (?, 'active')");
            $stmt->execute([$domain]);

            // Restore mail accounts
            foreach ($mailData['accounts'] as $account) {
                $email = $account['email'];
                $localPart = explode('@', $email)[0];
                $mailboxPath = "/home/vmail/{$domain}/{$localPart}";

                // Check if account exists
                $stmt = $panelDb->prepare("SELECT id FROM mail_accounts WHERE email = ?");
                $stmt->execute([$email]);
                
                if ($stmt->fetch()) {
                    // Update password
                    $stmt = $panelDb->prepare("UPDATE mail_accounts SET password_hash = ? WHERE email = ?");
                    $stmt->execute([$account['password_hash'], $email]);
                } else {
                    // Insert new account
                    $stmt = $panelDb->prepare("
                        INSERT INTO mail_accounts (email, domain, password_hash, maildir_path, status) 
                        VALUES (?, ?, ?, ?, 'active')
                    ");
                    $stmt->execute([$email, $domain, $account['password_hash'], $mailboxPath]);
                }

                // Ensure mail directory exists
                if (!is_dir($mailboxPath)) {
                    mkdir($mailboxPath, 0700, true);
                    $this->execCommand('chown', ['-R', 'vmail:vmail', $mailboxPath]);
                }
            }

            // Restore forwards
            foreach ($mailData['forwards'] ?? [] as $forward) {
                $stmt = $panelDb->prepare("
                    INSERT INTO mail_forwards (source_email, source_domain, destination, status)
                    VALUES (?, ?, ?, 'active')
                    ON DUPLICATE KEY UPDATE destination = VALUES(destination), status = 'active'
                ");
                $stmt->execute([$forward['source'], $domain, $forward['destination']]);
            }

            return true;

        } catch (\Exception $e) {
            $this->logger->error("Failed to restore mail accounts: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Recreate site user if missing during restore
     */
    private function recreateSiteUser(string $domain, string $username, string $homeDir): bool
    {
        // Check if user exists
        exec("id {$username} 2>/dev/null", $output, $exitCode);
        if ($exitCode === 0) {
            // User already exists
            return true;
        }

        try {
            // Ensure sftpusers group exists
            exec('getent group sftpusers 2>/dev/null', $output, $exitCode);
            if ($exitCode !== 0) {
                $this->execCommand('groupadd', ['sftpusers']);
            }

            // Create user with restricted shell for SFTP-only access
            $result = $this->execCommand('useradd', [
                '-d', $homeDir,
                '-s', '/usr/sbin/nologin',
                '-c', "SFTP user for {$domain} (restored)",
                '-g', 'sftpusers',
                '-M', // Don't create home directory
                $username
            ]);

            if (!$result['success']) {
                $this->logger->error("Failed to recreate user: " . $result['output']);
                return false;
            }

            // Add user to www-data group
            $this->execCommand('usermod', ['-a', '-G', 'www-data', $username]);

            // Set up directory structure for chroot jail
            $this->execCommand('chown', ['root:root', $homeDir]);
            $this->execCommand('chmod', ['755', $homeDir]);

            // Set ownership of subdirectories
            $publicHtml = $homeDir . '/public_html';
            if (is_dir($publicHtml)) {
                $this->execCommand('chown', ['-R', "{$username}:sftpusers", $publicHtml]);
            }

            foreach (['logs', 'tmp'] as $subdir) {
                $subdirPath = $homeDir . '/' . $subdir;
                if (is_dir($subdirPath)) {
                    $this->execCommand('chown', ['-R', "{$username}:sftpusers", $subdirPath]);
                }
            }

            $this->logger->info("Recreated site user: {$username} for {$domain}");
            return true;

        } catch (\Exception $e) {
            $this->logger->error("Failed to recreate site user: " . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // Helper Methods for Backup Analysis
    // =========================================================================

    /**
     * Get the total size of a directory in bytes
     */
    private function getDirectorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $size = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Exception $e) {
            // Return 0 on error
        }
        
        return $size;
    }

    /**
     * Count the number of files in a directory (recursively)
     */
    private function countFiles(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $count = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $count++;
                }
            }
        } catch (\Exception $e) {
            // Return 0 on error
        }
        
        return $count;
    }

    /**
     * Check if a database exists
     */
    private function databaseExists(string $dbName): bool
    {
        try {
            $result = $this->execCommand('mysql', [
                '-N', '-e', "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$dbName}'"
            ]);
            
            return !empty(trim($result['output'] ?? ''));
        } catch (\Exception $e) {
            return false;
        }
    }

    // =========================================================================
    // NAS Remote Backup Methods
    // =========================================================================

    /**
     * Get available NAS connections for backup
     */
    private function getNasConnections(): array
    {
        try {
            $panelDb = $this->getPanelDb();
            if (!$panelDb) {
                return $this->error('Cannot connect to panel database');
            }

            // Show everything except operator-disabled rows; the status field
            // is included so the UI can badge 'error' connections instead of
            // silently hiding them (a transient failed test must not make a
            // connection unselectable).
            $stmt = $panelDb->query("
                SELECT id, name, driver, mount_point, nfs_server, nfs_path, 
                       vpn_enabled, is_default, status, last_check, notes
                FROM nas_connections 
                WHERE status <> 'inactive'
                ORDER BY is_default DESC, name ASC
            ");
            
            $connections = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            return $this->success([
                'connections' => $connections,
                'count' => count($connections),
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to get NAS connections: ' . $e->getMessage());
        }
    }
    
    /**
     * Get the backup NAS connection (default or specified)
     */
    private function getBackupNasConnection(int $nasId = null): ?array
    {
        try {
            $panelDb = $this->getPanelDb();
            if (!$panelDb) {
                return null;
            }

            // The status column is only the cached result of the last
            // test/mount/unmount and lags reality (a failed test flips it to
            // 'error' even when the mount is fine, and unmount used to write
            // an out-of-enum value). Every caller verifies the live mount
            // right after this lookup, so only exclude connections an
            // operator deliberately disabled ('inactive').
            if ($nasId) {
                $stmt = $panelDb->prepare("SELECT * FROM nas_connections WHERE id = ? AND status <> 'inactive'");
                $stmt->execute([$nasId]);
            } else {
                // Default backup NAS: prefer the default row, then healthy ones
                $stmt = $panelDb->query("
                    SELECT * FROM nas_connections
                    WHERE status <> 'inactive'
                    ORDER BY is_default DESC, (status = 'active') DESC, id ASC
                    LIMIT 1
                ");
            }
            
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get NAS connection: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * List backups on NAS storage
     * 
     * @param array $params
     *   - nas_id: optional NAS connection ID
     *   - path: optional subpath (defaults to 'backups')
     *   - type: optional filter - 'config', 'sites', 'emails', or null for all
     */
    private function listNasBackups(array $params): array
    {
        // Clear PHP's file stat cache to ensure fresh results after deletions
        clearstatcache(true);
        
        $nasId = $params['nas_id'] ?? null;
        $type = $params['type'] ?? null; // 'config', 'sites', 'emails'
        $subPath = $params['path'] ?? 'backups';
        
        $nas = $this->getBackupNasConnection($nasId);
        
        if (!$nas) {
            return $this->error('No active NAS connection found');
        }
        
        $mountPoint = rtrim($nas['mount_point'], '/');
        $backupsDir = "{$mountPoint}/backups";
        
        // Determine which paths to scan based on type filter
        $pathsToScan = [];
        if ($type === 'config') {
            $pathsToScan[] = "{$backupsDir}/manual";
        } elseif ($type === 'sites') {
            $pathsToScan[] = "{$backupsDir}/sites";
        } elseif ($type === 'emails') {
            $pathsToScan[] = "{$backupsDir}/emails";
        } else {
            // Scan all if no type specified
            $basePath = "{$mountPoint}/" . ltrim($subPath, '/');
            $pathsToScan[] = $basePath;
        }
        
        $backups = [];
        $scanWarnings = [];

        try {
            foreach ($pathsToScan as $basePath) {
                if (!is_dir($basePath)) {
                    continue;
                }

                if (!is_readable($basePath)) {
                    $scanWarnings[] = "Not readable: {$basePath}";
                    $this->logger->warning("NAS backup path not readable", ['path' => $basePath]);
                    continue;
                }

                // CATCH_GET_CHILD: skip unreadable subdirectories (NFS root
                // squash) instead of aborting the entire listing.
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST,
                    \RecursiveIteratorIterator::CATCH_GET_CHILD
                );

                foreach ($iterator as $file) {
                    if (!$file->isFile()) continue;
                    
                    $filename = $file->getFilename();

                    // Marker-less split sets (no zero-byte .tar.gz on NAS):
                    // surface them via their manifest so they are not
                    // invisible in the NAS tab.
                    $viaManifest = false;
                    if (str_ends_with($filename, '.tar.gz.manifest.json')) {
                        $archiveName = substr($filename, 0, -strlen('.manifest.json'));
                        if (file_exists(dirname($file->getPathname()) . '/' . $archiveName)) {
                            continue; // marker present - listed normally
                        }
                        $filename = $archiveName;
                        $viaManifest = true;
                    }

                    if (str_ends_with($filename, '.tar.gz') || 
                        str_ends_with($filename, '.sql.gz') ||
                        str_ends_with($filename, '.sql') ||
                        str_ends_with($filename, '.zip')) {
                        
                        // Determine the backup type based on path
                        $fullPath = $viaManifest
                            ? dirname($file->getPathname()) . '/' . $filename
                            : $file->getPathname();
                        $relativePath = str_replace($backupsDir . '/', '', $fullPath);
                        $backupType = 'unknown';
                        $domain = null;
                        
                        if (strpos($relativePath, 'manual/') === 0) {
                            $backupType = 'config';
                        } elseif (strpos($relativePath, 'sites/') === 0) {
                            $backupType = 'site';
                            // Extract domain from path: sites/{domain}/file.tar.gz
                            $parts = explode('/', $relativePath);
                            if (count($parts) >= 2) {
                                $domain = $parts[1];
                            }
                        } elseif (strpos($relativePath, 'emails/') === 0) {
                            $backupType = 'email';
                            // Extract domain from path: emails/{domain}/file.tar.gz
                            $parts = explode('/', $relativePath);
                            if (count($parts) >= 2) {
                                $domain = $parts[1];
                            }
                        }
                        
                        // Load metadata if available
                        $metaPath = $fullPath . '.meta.json';
                        $meta = null;
                        if (file_exists($metaPath)) {
                            $meta = json_decode(file_get_contents($metaPath), true);
                        }

                        // Split sets: the .tar.gz is a zero-byte marker;
                        // report the real payload size from the manifest.
                        $size = $file->getSize();
                        $isSplit = false;
                        $partsCount = 1;
                        $splitManifestPath = $fullPath . '.manifest.json';
                        if (file_exists($splitManifestPath)) {
                            $splitManifest = json_decode((string)file_get_contents($splitManifestPath), true) ?: [];
                            $isSplit = true;
                            $size = (int)($splitManifest['total_size'] ?? 0);
                            $partsCount = (int)($splitManifest['parts_count'] ?? count($splitManifest['parts'] ?? []));
                        }
                        
                        $backups[] = [
                            'name' => $filename,
                            'path' => $fullPath,
                            'relative_path' => $relativePath,
                            'type' => $backupType,
                            'domain' => $domain ?? ($meta['domain'] ?? null),
                            'size' => $size,
                            'size_human' => $this->formatBytes($size),
                            'split' => $isSplit,
                            'parts_count' => $partsCount,
                            'modified' => $file->getMTime(),
                            'modified_human' => date('Y-m-d H:i:s', $file->getMTime()),
                            'accounts' => $meta['accounts_count'] ?? null,
                            'meta' => $meta,
                        ];
                    }
                }
            }
            
            // Sort by modified date descending
            usort($backups, fn($a, $b) => ($b['modified'] ?? 0) - ($a['modified'] ?? 0));
            
            return $this->success([
                'backups' => $backups,
                'type' => $type,
                'count' => count($backups),
                'nas_name' => $nas['name'],
                'warnings' => $scanWarnings,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('NAS backup listing failed', [
                'paths' => $pathsToScan,
                'error' => $e->getMessage(),
            ]);
            return $this->error('Failed to list NAS backups: ' . $e->getMessage());
        }
    }
    
    /**
     * Delete backups from NAS storage
     */
    private function deleteNasBackups(array $params, string $actor): array
    {
        $paths = $params['paths'] ?? [];
        $nasId = $params['nas_id'] ?? null;
        
        if (empty($paths)) {
            return $this->error('No backup paths specified');
        }
        
        $nas = $this->getBackupNasConnection($nasId);
        
        if (!$nas) {
            return $this->error('No active NAS connection found');
        }
        
        $mountPoint = rtrim($nas['mount_point'], '/');
        $backupsDir = "{$mountPoint}/backups";
        $deleted = [];
        $errors = [];
        
        foreach ($paths as $path) {
            // If path is relative (doesn't start with /), prepend the backups directory
            if (strpos($path, '/') !== 0) {
                $fullPath = "{$backupsDir}/{$path}";
            } else {
                $fullPath = $path;
            }
            
            // Normalize the path (remove ../ etc) without requiring file existence
            $normalizedPath = str_replace(['/../', '/./'], '/', $fullPath);
            $normalizedPath = preg_replace('#/+#', '/', $normalizedPath);
            
            // Security: ensure path is within the NAS mount point
            if (strpos($normalizedPath, $mountPoint) !== 0) {
                $errors[] = "Invalid path (outside mount): {$path}";
                continue;
            }
            
            // Split sets may exist without the zero-byte marker (older
            // uploads): the manifest alone is enough to identify and delete
            // the .part_* payload.
            $manifestFile = $normalizedPath . '.manifest.json';
            if (!file_exists($normalizedPath) && !file_exists($manifestFile)) {
                // Not an error - file may already be deleted or never uploaded
                $this->logger->debug("NAS file not found (may already be deleted)", [
                    'path' => $normalizedPath,
                ]);
                continue;
            }
            
            // Only allow deleting backup files
            $filename = basename($normalizedPath);
            if (!str_ends_with($filename, '.tar.gz') && 
                !str_ends_with($filename, '.sql.gz') &&
                !str_ends_with($filename, '.sql') &&
                !str_ends_with($filename, '.zip')) {
                $errors[] = "Not a backup file: {$filename}";
                continue;
            }
            
            if (!file_exists($normalizedPath) || unlink($normalizedPath)) {
                $deleted[] = $filename;
                $this->logger->info("NAS backup deleted", [
                    'actor' => $actor,
                    'path' => $normalizedPath,
                    'nas' => $nas['name'],
                ]);
                
                // Also delete .meta.json if exists
                $metaFile = $normalizedPath . '.meta.json';
                if (file_exists($metaFile)) {
                    unlink($metaFile);
                }
                
                // Also delete manifest and parts for split archives
                if (file_exists($manifestFile)) {
                    $manifest = json_decode(file_get_contents($manifestFile), true);
                    if (!empty($manifest['parts'])) {
                        $baseDir = dirname($normalizedPath);
                        foreach ($manifest['parts'] as $part) {
                            $partPath = $baseDir . '/' . $part['name'];
                            @unlink($partPath);
                        }
                    }
                    @unlink($manifestFile);
                }
            } else {
                $errors[] = "Failed to delete: {$filename}";
            }
        }
        
        if (empty($deleted) && !empty($errors)) {
            return $this->error('Failed to delete any backups: ' . implode(', ', $errors));
        }
        
        // Clear PHP's file stat cache after deletions
        clearstatcache(true);
        
        return $this->success([
            'deleted' => $deleted,
            'deleted_count' => count($deleted),
            'errors' => $errors,
            'error_count' => count($errors),
        ], count($deleted) . ' backup(s) deleted from NAS');
    }
    
    /**
     * Manually transfer an existing local backup to NAS.
     *
     * Params:
     *   - id:   base64-encoded local archive path (same id the listings use)
     *   - mode: 'copy' (default - keep local) or 'move' (delete local after
     *           verified upload, keep the .meta.json stub so the backup stays
     *           visible in the list as NAS-only)
     */
    private function transferToNas(array $params, string $actor): array
    {
        $id = $params['id'] ?? null;
        $mode = $params['mode'] ?? 'copy';

        if (!$id) {
            return ['success' => false, 'error' => 'Backup ID required'];
        }
        if (!in_array($mode, ['copy', 'move'], true)) {
            return ['success' => false, 'error' => "Invalid mode: {$mode} (use copy or move)"];
        }

        $path = base64_decode($id, true);
        if ($path === false || $path === '') {
            return ['success' => false, 'error' => 'Invalid backup ID'];
        }

        $validation = $this->validateTransferPath($path);
        if ($validation !== null) {
            return $validation;
        }

        // Split archives: the .tar.gz is a zero-byte marker, the payload is
        // the .part_* set described by the manifest. Both copy and move are
        // supported - move frees the parts only after every part verified.
        $isSplit = file_exists($path . '.manifest.json');
        $manifest = $isSplit
            ? (json_decode((string)file_get_contents($path . '.manifest.json'), true) ?: [])
            : [];

        $remoteDir = $this->remoteDirFor($path);
        if ($remoteDir === null) {
            return ['success' => false, 'error' => 'Could not determine NAS target folder for this backup'];
        }

        // Async mode: all validation passed, hand the slow upload to a
        // detached runner so the agent loop (and the panel) stay responsive.
        $statusId = $params['status_id_override'] ?? null;
        if (!empty($params['async']) && $statusId === null) {
            return $this->startBackgroundTransfer($id, $mode, $path, $actor);
        }

        if ($statusId !== null) {
            $totalBytes = $isSplit ? (int)($manifest['total_size'] ?? 0) : (int)@filesize($path);
            $sizeHuman = $this->formatBytes($totalBytes);
            $partsNote = $isSplit ? (' in ' . count($manifest['parts'] ?? []) . ' parts') : '';
            $this->updateBackupTracking($statusId, 'uploading', 10, "Uploading {$sizeHuman}{$partsNote} to NAS...");
        }

        $result = $isSplit
            ? $this->uploadSplitArchiveToNas($path, $remoteDir, $statusId)
            : $this->uploadToNas($path, $remoteDir);

        if (empty($result['success'])) {
            $error = $result['error'] ?? ($result['reason'] ?? 'NAS upload failed');
            if ($statusId !== null) {
                $this->completeBackupTracking($statusId, false, ['error' => $error]);
            }
            return ['success' => false, 'error' => $error];
        }

        if ($statusId !== null) {
            $this->updateBackupTracking($statusId, 'finalizing', 90, 'Upload verified, updating metadata...');
        }

        // Update the metadata so listings reflect the new location.
        $metaFile = $path . '.meta.json';
        $meta = file_exists($metaFile) ? (json_decode((string)file_get_contents($metaFile), true) ?: []) : [];
        $meta['nas_uploaded'] = true;
        $meta['destination'] = $mode === 'move' ? 'nas' : 'both';
        if ($isSplit) {
            $meta['split'] = true;
            $meta['parts_count'] = (int)($manifest['parts_count'] ?? count($manifest['parts'] ?? []));
            $meta['archive_size'] = $meta['archive_size'] ?? (int)($manifest['total_size'] ?? 0);
        } else {
            $meta['archive_size'] = $meta['archive_size'] ?? @filesize($path);
        }
        file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT));

        $moved = false;
        if ($mode === 'move') {
            // Free the local copy only after a checksum-verified upload. The
            // .meta.json stub (and the manifest for split sets) stay behind
            // so the backup remains visible in the list as NAS-only.
            if (!empty($result['verified'])) {
                $moved = $isSplit
                    ? $this->removeLocalSplitPayload($path)
                    : @unlink($path);
            } else {
                $this->logger->warning('NAS upload not fully verified - keeping local copy despite move request', [
                    'path' => $path,
                ]);
            }

            // Re-sync the stub meta to NAS so both sides agree.
            if ($moved && !empty($result['remote_path'])) {
                @copy($metaFile, $result['remote_path'] . '.meta.json');
            }
        }

        $this->logger->info('Backup transferred to NAS', [
            'actor' => $actor,
            'path' => $path,
            'mode' => $mode,
            'moved' => $moved,
            'remote' => $result['remote_path'] ?? null,
        ]);

        $data = [
            'remote_path' => $result['remote_path'] ?? null,
            'mode' => $mode,
            'local_removed' => $moved,
            'verified' => $result['verified'] ?? null,
        ];
        $message = $mode === 'move'
            ? ($moved
                ? 'Backup moved to NAS (server copy freed)'
                : 'Backup copied to NAS but the local copy was kept (verification incomplete)')
            : 'Backup copied to NAS';

        if ($statusId !== null) {
            $this->completeBackupTracking($statusId, true, $data + ['message' => $message]);
        }

        return ['success' => true, 'data' => $data, 'message' => $message];
    }

    /**
     * Spawn the detached transfer runner and return a status_id immediately.
     * Mirrors the site/mail backup pattern: the agent's single-threaded
     * socket loop must never block on a multi-minute NFS upload.
     */
    private function startBackgroundTransfer(string $id, string $mode, string $path, string $actor): array
    {
        $runnerScript = __DIR__ . '/../backup-transfer-runner.php';

        if (!file_exists($runnerScript)) {
            return ['success' => false, 'error' => 'Transfer runner script not found'];
        }

        $statusId = $this->startBackupTracking(basename($path), 'transfer');
        $this->updateBackupTracking($statusId, 'initializing', 1, 'Transfer queued...');

        $runnerParams = [
            'id' => $id,
            'mode' => $mode,
            'status_id' => $statusId,
            'actor' => $actor,
        ];

        $paramsJson = escapeshellarg(json_encode($runnerParams));
        $phpBinary = PHP_BINARY ?: '/usr/local/lsws/lsphp83/bin/php';

        $logFile = '/var/log/vpsadmin/backup-runner.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0750, true);
        }

        $cmd = sprintf(
            'nohup %s %s %s >> %s 2>&1 &',
            escapeshellarg($phpBinary),
            escapeshellarg($runnerScript),
            $paramsJson,
            escapeshellarg($logFile)
        );

        exec($cmd);

        $this->logger->info('Background NAS transfer started', [
            'file' => basename($path),
            'mode' => $mode,
            'status_id' => $statusId,
        ]);

        return [
            'success' => true,
            'async' => true,
            'data' => [
                'status_id' => $statusId,
                'mode' => $mode,
                'file' => basename($path),
            ],
            'message' => 'Transfer started - poll /api/backups/status for progress',
        ];
    }

    /**
     * Validate that a transfer source path is a real backup archive inside
     * the backup root. Returns an error response array or null when valid.
     */
    private function validateTransferPath(string $path): ?array
    {
        $real = realpath($path);
        $rootReal = realpath($this->backupPath);

        if ($real === false || $rootReal === false || strpos($real, $rootReal) !== 0) {
            return ['success' => false, 'error' => 'Backup not found or outside the backup directory'];
        }
        if (!is_file($real)) {
            return ['success' => false, 'error' => 'Backup file not found'];
        }

        $name = basename($real);
        $allowed = str_ends_with($name, '.tar.gz')
            || str_ends_with($name, '.sql.gz')
            || str_ends_with($name, '.bak');
        if (!$allowed) {
            return ['success' => false, 'error' => 'Not a transferable backup archive'];
        }

        return null;
    }

    /**
     * Map a local backup path to its NAS folder, mirroring where creation-time
     * uploads go: sites/{domain}, emails/{domain} (local dir is mail/),
     * manual, scheduled, ...
     */
    private function remoteDirFor(string $path): ?string
    {
        $rootReal = realpath($this->backupPath);
        $real = realpath($path);
        if ($rootReal === false || $real === false) {
            return null;
        }

        $relative = trim(str_replace('\\', '/', substr(dirname($real), strlen($rootReal))), '/');
        if ($relative === '') {
            return null;
        }

        // Mail backups live locally under mail/ but on NAS under emails/.
        if ($relative === 'mail' || str_starts_with($relative, 'mail/')) {
            $relative = 'emails' . substr($relative, 4);
        }

        return $relative;
    }

    /**
     * Upload/copy a backup file to NAS storage
     */
    private function uploadToNas(string $localPath, string $remotePath = null): array
    {
        $nas = $this->getBackupNasConnection();

        if (!$nas) {
            // Distinguish "panel DB unreachable" from "genuinely no NAS row" -
            // reporting the former as "not configured" sent us debugging the
            // wrong thing when the agent's DB connection had simply died.
            $dbErr = PanelDb::lastError();
            $reason = $dbErr !== ''
                ? "Cannot read NAS config (panel DB: {$dbErr})"
                : 'No NAS connection configured';
            return ['success' => false, 'skipped' => true, 'reason' => $reason];
        }
        
        $mountPoint = rtrim($nas['mount_point'], '/');
        
        // Check if mount point exists and is mounted
        if (!is_dir($mountPoint)) {
            return ['success' => false, 'error' => "NAS mount point not found: {$mountPoint}"];
        }
        
        // Check if actually mounted (not empty mount point)
        if (!$this->isMounted($mountPoint)) {
            return ['success' => false, 'error' => "NAS not mounted at: {$mountPoint}"];
        }
        
        // Determine remote path
        $filename = basename($localPath);
        $backupsDir = "{$mountPoint}/backups";
        
        if ($remotePath) {
            $fullRemotePath = "{$backupsDir}/{$remotePath}";
        } else {
            // Auto-organize by date
            $date = date('Y-m-d');
            $fullRemotePath = "{$backupsDir}/{$date}";
        }
        
        // Ensure directory exists
        if (!is_dir($fullRemotePath)) {
            if (!mkdir($fullRemotePath, 0755, true)) {
                return ['success' => false, 'error' => "Failed to create directory: {$fullRemotePath}"];
            }
        }
        
        $destPath = "{$fullRemotePath}/{$filename}";

        $this->logger->info("Copying backup to NAS", [
            'local' => $localPath,
            'remote' => $destPath,
            'nas' => $nas['name'],
        ]);

        $localSize = filesize($localPath);
        if ($localSize === false || $localSize === 0) {
            return ['success' => false, 'error' => "Source archive is empty or unreadable: {$localPath}"];
        }

        // Compute the source checksum BEFORE the NFS copy so we can detect
        // partial writes that PHP's copy() over a soft NFS mount can silently
        // return true on. We use crc32b for speed - this is corruption-
        // detection (not crypto), and over a soft NFS mount, partial writes
        // are the dominant failure mode.
        $localChecksum = @hash_file('crc32b', $localPath);
        if ($localChecksum === false) {
            return ['success' => false, 'error' => "Failed to compute source checksum: {$localPath}"];
        }

        $startTime = microtime(true);

        if (!@copy($localPath, $destPath)) {
            $err = error_get_last()['message'] ?? 'unknown copy() failure';
            $this->logger->error("Failed to copy backup to NAS", [
                'local' => $localPath,
                'remote' => $destPath,
                'php_error' => $err,
            ]);
            @unlink($destPath);
            return ['success' => false, 'error' => "Failed to copy file to NAS: {$err}"];
        }

        // Clear any cached stat() result so we read the real on-NAS state.
        clearstatcache(true, $destPath);

        $remoteSize = @filesize($destPath);
        if ($remoteSize === false || $remoteSize !== $localSize) {
            $this->logger->error("NAS copy size mismatch - rejecting", [
                'local' => $localPath,
                'remote' => $destPath,
                'local_size' => $localSize,
                'remote_size' => $remoteSize,
            ]);
            @unlink($destPath);
            return [
                'success' => false,
                'error' => "Size mismatch after copy (local={$localSize}, remote=" . var_export($remoteSize, true) . ')',
            ];
        }

        $remoteChecksum = @hash_file('crc32b', $destPath);
        if ($remoteChecksum === false || $remoteChecksum !== $localChecksum) {
            $this->logger->error("NAS copy checksum mismatch - rejecting", [
                'local' => $localPath,
                'remote' => $destPath,
                'local_checksum' => $localChecksum,
                'remote_checksum' => $remoteChecksum,
            ]);
            @unlink($destPath);
            return [
                'success' => false,
                'error' => "Checksum mismatch after copy (local={$localChecksum}, remote=" . var_export($remoteChecksum, true) . ')',
            ];
        }

        $duration = microtime(true) - $startTime;
        $fileSize = $localSize;

        // Also copy + verify metadata file if it exists. The .meta.json is
        // small but we still verify so a partial meta write does not leave a
        // valid archive paired with a corrupt manifest.
        $metaPath = $localPath . '.meta.json';
        $metaOk = true;
        if (file_exists($metaPath)) {
            $metaLocalSize = filesize($metaPath);
            $metaLocalSum  = @hash_file('crc32b', $metaPath);
            if (!@copy($metaPath, $destPath . '.meta.json')) {
                $metaOk = false;
            } else {
                clearstatcache(true, $destPath . '.meta.json');
                $metaRemoteSize = @filesize($destPath . '.meta.json');
                $metaRemoteSum  = @hash_file('crc32b', $destPath . '.meta.json');
                if ($metaRemoteSize !== $metaLocalSize || $metaRemoteSum !== $metaLocalSum) {
                    $metaOk = false;
                }
            }
            if (!$metaOk) {
                $this->logger->error("NAS metadata copy verification failed - rejecting whole upload", [
                    'meta_local' => $metaPath,
                    'meta_remote' => $destPath . '.meta.json',
                ]);
                @unlink($destPath . '.meta.json');
                @unlink($destPath);
                return ['success' => false, 'error' => 'Metadata file copy verification failed'];
            }
        }

        $this->logger->info("Backup copied to NAS and verified", [
            'remote' => $destPath,
            'size' => $fileSize,
            'checksum' => $localChecksum,
            'duration' => round($duration, 2),
            'nas' => $nas['name'],
        ]);

        return [
            'success' => true,
            'remote_path' => $destPath,
            'size' => $fileSize,
            'checksum' => $localChecksum,
            'verified' => true,
            'duration' => round($duration, 2),
            'speed' => $duration > 0 ? round($fileSize / $duration / 1024 / 1024, 2) . ' MB/s' : 'N/A',
            'nas_name' => $nas['name'],
        ];
    }
    
    /**
     * Check if a path is a mounted filesystem
     */
    private function isMounted(string $path): bool
    {
        $output = [];
        exec("mountpoint -q " . escapeshellarg($path) . " 2>/dev/null", $output, $exitCode);
        return $exitCode === 0;
    }
    
    /**
     * Download a backup file from NAS storage
     * Used for restoring NAS-only backups
     */
    private function downloadFromNas(string $localPath, ?string $domain = null): array
    {
        $nas = $this->getBackupNasConnection();

        if (!$nas) {
            $dbErr = PanelDb::lastError();
            return ['success' => false, 'error' => $dbErr !== ''
                ? "Cannot read NAS config (panel DB: {$dbErr})"
                : 'No NAS connection configured'];
        }
        
        $mountPoint = rtrim($nas['mount_point'], '/');
        
        // Check if mount point exists and is mounted
        if (!is_dir($mountPoint) || !$this->isMounted($mountPoint)) {
            return ['success' => false, 'error' => 'NAS not mounted'];
        }
        
        $filename = basename($localPath);
        $backupsDir = "{$mountPoint}/backups";
        
        // Try to find the file on NAS
        // For email backups: backups/emails/{domain}/{filename}
        // For site backups: backups/sites/{domain}/{filename}
        // For config backups: backups/manual/{filename}
        $possiblePaths = [];
        
        if ($domain) {
            // Email backup path
            $possiblePaths[] = "{$backupsDir}/emails/{$domain}/{$filename}";
            // Site backup path
            $possiblePaths[] = "{$backupsDir}/sites/{$domain}/{$filename}";
        }
        // Config backup path
        $possiblePaths[] = "{$backupsDir}/manual/{$filename}";
        // Auto-organized by date (check recent dates)
        for ($i = 0; $i < 7; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $possiblePaths[] = "{$backupsDir}/{$date}/{$filename}";
        }
        
        $remotePath = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $remotePath = $path;
                break;
            }
        }
        
        if (!$remotePath) {
            $this->logger->warning("Backup not found on NAS", [
                'filename' => $filename,
                'searched_paths' => $possiblePaths,
            ]);
            return ['success' => false, 'error' => 'Backup file not found on NAS'];
        }
        
        // Ensure local directory exists
        $localDir = dirname($localPath);
        if (!is_dir($localDir)) {
            mkdir($localDir, 0750, true);
        }
        
        $this->logger->info("Downloading backup from NAS", [
            'remote' => $remotePath,
            'local' => $localPath,
        ]);
        
        $startTime = microtime(true);
        
        // Copy the file
        if (!copy($remotePath, $localPath)) {
            return ['success' => false, 'error' => 'Failed to copy file from NAS'];
        }
        
        $duration = microtime(true) - $startTime;
        $fileSize = filesize($localPath);
        
        $this->logger->info("Backup downloaded from NAS", [
            'local' => $localPath,
            'size' => $fileSize,
            'duration' => round($duration, 2),
        ]);
        
        return [
            'success' => true,
            'local_path' => $localPath,
            'remote_path' => $remotePath,
            'size' => $fileSize,
            'duration' => round($duration, 2),
        ];
    }

    // =========================================================================
    // EMAIL BACKUP METHODS
    // =========================================================================

    /**
     * Mail storage path
     */
    private string $mailPath = '/home/vmail';

    /**
     * List all mail domains that have email accounts
     */
    private function listMailDomains(): array
    {
        $domains = [];
        
        // Get mail domains from panel database
        $panelDb = $this->getPanelDb();
        if ($panelDb) {
            try {
                $stmt = $panelDb->query("SELECT DISTINCT domain FROM mail_domains WHERE status = 'active' ORDER BY domain");
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $domain = $row['domain'];
                    $mailDir = "{$this->mailPath}/{$domain}";
                    
                    // Count accounts
                    $countStmt = $panelDb->prepare("SELECT COUNT(*) FROM mail_accounts WHERE domain = ?");
                    $countStmt->execute([$domain]);
                    $accountCount = (int)$countStmt->fetchColumn();
                    
                    // Get mailbox size
                    $size = is_dir($mailDir) ? $this->getDirectorySize($mailDir) : 0;
                    
                    $domains[] = [
                        'domain' => $domain,
                        'accounts' => $accountCount,
                        'size' => $size,
                        'size_human' => $this->formatBytes($size),
                        'has_maildir' => is_dir($mailDir),
                    ];
                }
            } catch (\Exception $e) {
                $this->logger->error("Failed to list mail domains: " . $e->getMessage());
            }
        }
        
        // Also check filesystem for domains not in database
        if (is_dir($this->mailPath)) {
            $existingDomains = array_column($domains, 'domain');
            foreach (scandir($this->mailPath) as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                $mailDir = "{$this->mailPath}/{$dir}";
                if (is_dir($mailDir) && !in_array($dir, $existingDomains)) {
                    $size = $this->getDirectorySize($mailDir);
                    $domains[] = [
                        'domain' => $dir,
                        'accounts' => count(array_filter(scandir($mailDir), fn($f) => $f !== '.' && $f !== '..')),
                        'size' => $size,
                        'size_human' => $this->formatBytes($size),
                        'has_maildir' => true,
                        'orphaned' => true, // Not in database
                    ];
                }
            }
        }
        
        return [
            'success' => true,
            'data' => ['domains' => $domains],
        ];
    }

    /**
     * List email backups for a domain (or all)
     */
    private function listMailBackups(array $params): array
    {
        $domain = $params['domain'] ?? null;

        // Self-heal first: NAS is the source of truth for NAS-only email
        // backups. This (re)creates any missing/incomplete local stub so the
        // scan below surfaces backups that exist on the NAS but vanished here.
        $this->reconcileMailStubsFromNas($domain);

        $backupDir = "{$this->backupPath}/mail";
        
        if (!is_dir($backupDir)) {
            return ['success' => true, 'data' => ['backups' => []]];
        }
        
        $backups = [];
        
        if ($domain) {
            // Specific domain
            $domainDir = "{$backupDir}/{$domain}";
            if (is_dir($domainDir)) {
                $backups = $this->scanMailBackupDir($domainDir, $domain);
            }
        } else {
            // All domains
            foreach (scandir($backupDir) as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                $domainDir = "{$backupDir}/{$dir}";
                if (is_dir($domainDir)) {
                    $backups = array_merge($backups, $this->scanMailBackupDir($domainDir, $dir));
                }
            }
        }
        
        // Sort by date descending
        usort($backups, fn($a, $b) => strcmp($b['date'], $a['date']));
        
        return [
            'success' => true,
            'data' => ['backups' => $backups],
        ];
    }

    /**
     * Scan a mail backup directory for backup files
     * Also includes NAS-only backups (where local .tar.gz was removed but .meta.json exists)
     */
    private function scanMailBackupDir(string $dir, string $domain): array
    {
        $backups = [];
        $seenFiles = [];
        
        // First, scan for actual .tar.gz files (local backups)
        foreach (glob("{$dir}/*.tar.gz") as $file) {
            $filename = basename($file);
            $metaFile = "{$file}.meta.json";
            $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : null;
            $seenFiles[$filename] = true;
            
            $backups[] = [
                'id' => base64_encode($file),
                'domain' => $domain,
                'filename' => $filename,
                'path' => $file,
                'size' => filesize($file),
                'size_human' => $this->formatBytes(filesize($file)),
                'date' => date('Y-m-d H:i:s', filemtime($file)),
                'accounts' => $meta['accounts_count'] ?? null,
                'has_mailboxes' => $meta['has_mailboxes'] ?? true,
                'nas_uploaded' => $meta['nas_uploaded'] ?? false,
                'nas_only' => false,
                'meta' => $meta,
            ];
        }
        
        // Then, scan for .meta.json files without corresponding .tar.gz (NAS-only backups)
        foreach (glob("{$dir}/*.tar.gz.meta.json") as $metaFile) {
            $filename = str_replace('.meta.json', '', basename($metaFile));
            
            // Skip if we already have this backup locally
            if (isset($seenFiles[$filename])) {
                continue;
            }
            
            $meta = json_decode(file_get_contents($metaFile), true);
            if (!$meta) continue;
            
            // Only include if it was uploaded to NAS (nas_only backup)
            if (empty($meta['nas_uploaded'])) continue;
            
            $archivePath = str_replace('.meta.json', '', $metaFile);
            
            $backups[] = [
                'id' => base64_encode($archivePath),
                'domain' => $domain,
                'filename' => $filename,
                'path' => $archivePath, // Path to where it would be locally
                'size' => $meta['archive_size'] ?? 0,
                'size_human' => isset($meta['archive_size']) ? $this->formatBytes($meta['archive_size']) : 'N/A',
                'date' => isset($meta['timestamp']) ? str_replace('_', ' ', $meta['timestamp']) : date('Y-m-d H:i:s', filemtime($metaFile)),
                'accounts' => $meta['accounts_count'] ?? null,
                'has_mailboxes' => $meta['has_mailboxes'] ?? true,
                'nas_uploaded' => true,
                'nas_only' => true, // Flag indicating this backup is ONLY on NAS
                'meta' => $meta,
            ];
        }
        
        return $backups;
    }

    /**
     * Reconcile the local mail-backup stub directory against the NAS.
     *
     * The Email Backups tab lists from local {backupPath}/mail/{domain}/ and
     * only surfaces a NAS-only backup when a local .meta.json stub exists with
     * nas_uploaded=true. If a NAS upload finished but the stub was never written
     * (or was left flagged nas_uploaded=false by older/killed runs), the backup
     * is safe on the NAS yet invisible here. This pass treats the NAS as the
     * source of truth and (re)creates any missing/incomplete stub so the
     * listing, inspect, download and restore paths all work again.
     *
     * Degrades silently when no NAS is configured or mounted - the mail listing
     * must never fail just because the NAS is unreachable.
     */
    private function reconcileMailStubsFromNas(?string $domain = null): void
    {
        try {
            $nas = $this->getBackupNasConnection();
            if (!$nas || empty($nas['mount_point'])) {
                return;
            }

            $mountPoint = rtrim($nas['mount_point'], '/');
            if (!is_dir($mountPoint) || !$this->isMounted($mountPoint)) {
                return;
            }

            $emailsBase = "{$mountPoint}/backups/emails";
            if (!is_dir($emailsBase)) {
                return;
            }

            // Scan a single domain when requested, otherwise every domain dir.
            $domainDirs = [];
            if ($domain) {
                $domainDirs[$domain] = "{$emailsBase}/{$domain}";
            } else {
                foreach (scandir($emailsBase) as $entry) {
                    if ($entry === '.' || $entry === '..') continue;
                    $path = "{$emailsBase}/{$entry}";
                    if (is_dir($path)) {
                        $domainDirs[$entry] = $path;
                    }
                }
            }

            foreach ($domainDirs as $nasDomain => $nasDir) {
                if (!is_dir($nasDir)) continue;
                foreach (glob("{$nasDir}/*.tar.gz") as $nasArchive) {
                    $this->writeMailStubFromNas($nasArchive, $nasDomain);
                }
            }
        } catch (\Throwable $e) {
            // Never let reconciliation break the listing.
            $this->logger->warning('Mail stub NAS reconciliation failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ensure a local .meta.json stub exists for a single NAS email backup.
     *
     * Skips files that already have a local archive or a stub already flagged
     * nas_uploaded=true. Otherwise it reconstructs the stub from the NAS
     * companion .meta.json (falling back to filename/size) and forces
     * nas_uploaded=true so scanMailBackupDir() surfaces it as nas_only.
     */
    private function writeMailStubFromNas(string $nasArchive, string $nasDomain): void
    {
        $filename = basename($nasArchive);
        $localDir = "{$this->backupPath}/mail/{$nasDomain}";
        $localArchive = "{$localDir}/{$filename}";
        $localStub = "{$localArchive}.meta.json";

        // Local archive present -> it will be scanned directly.
        if (file_exists($localArchive)) {
            return;
        }

        // Start from an existing stub or the NAS companion meta, if any.
        $meta = [];
        if (file_exists($localStub)) {
            $existing = json_decode((string)file_get_contents($localStub), true);
            if (is_array($existing)) {
                if (!empty($existing['nas_uploaded'])) {
                    return; // Already represented and complete.
                }
                $meta = $existing;
            }
        }

        if ($meta === []) {
            $companion = "{$nasArchive}.meta.json";
            if (file_exists($companion)) {
                $companionMeta = json_decode((string)file_get_contents($companion), true);
                if (is_array($companionMeta)) {
                    $meta = $companionMeta;
                }
            }
        }

        // Determine archive size: prefer a recorded size, then a split
        // manifest total, then the on-disk NAS size (0 for split markers).
        if (empty($meta['archive_size'])) {
            $size = @filesize($nasArchive) ?: 0;
            $manifest = "{$nasArchive}.manifest.json";
            if ($size === 0 && file_exists($manifest)) {
                $m = json_decode((string)file_get_contents($manifest), true);
                $size = (int)($m['total_size'] ?? 0);
            }
            $meta['archive_size'] = $size;
        }

        // Backfill timestamp from the filename when missing.
        if (empty($meta['timestamp']) &&
            preg_match('/_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.tar\.gz$/', $filename, $tsMatch)) {
            $meta['timestamp'] = $tsMatch[1];
        }

        $meta['domain'] = $meta['domain'] ?? $nasDomain;
        $meta['nas_uploaded'] = true;

        if (!is_dir($localDir) && !@mkdir($localDir, 0750, true) && !is_dir($localDir)) {
            $this->logger->warning('Could not create local mail stub dir', ['dir' => $localDir]);
            return;
        }

        if (file_put_contents($localStub, json_encode($meta, JSON_PRETTY_PRINT)) !== false) {
            $this->logger->info('Reconstructed NAS-only mail backup stub', [
                'domain' => $nasDomain,
                'file' => $filename,
            ]);
        }
    }

    /**
     * Backup all email for a domain
     */
    private function backupMail(array $params, string $actor): array
    {
        set_time_limit(0);
        
        $domain = $params['domain'] ?? null;
        $destination = $params['destination'] ?? 'local';
        $async = $params['async'] ?? false;
        $statusIdOverride = $params['status_id_override'] ?? null;
        
        if (!$domain) {
            return ['success' => false, 'error' => 'Domain is required'];
        }
        
        $mailDir = "{$this->mailPath}/{$domain}";
        
        // Start status tracking
        $statusId = $statusIdOverride ?? $this->startBackupTracking($domain, 'mail');
        
        // Async mode: spawn background process
        if ($async && !$statusIdOverride) {
            return $this->startBackgroundMailBackup($domain, $destination, $statusId, $actor);
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $backupDir = "{$this->backupPath}/mail/{$domain}";
        
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }
        
        $archiveName = "mail_{$domain}_{$timestamp}.tar.gz";
        $archivePath = "{$backupDir}/{$archiveName}";
        $tempDir = sys_get_temp_dir() . '/vps-mail-backup-' . uniqid();
        
        if (!mkdir($tempDir, 0750, true)) {
            $this->completeBackupTracking($statusId, false, ['error' => 'Failed to create temp directory']);
            return ['success' => false, 'error' => 'Failed to create temp directory'];
        }
        
        $backedUp = [];
        $errors = [];
        $accountsCount = 0;
        $totalSize = 0;
        
        try {
            // Update status: backing up mailboxes
            $this->updateBackupTracking($statusId, 'mailboxes', 10, 'Backing up mailboxes...');
            
            // 1. Backup mailbox content
            if (is_dir($mailDir)) {
                $mailboxBackup = "{$tempDir}/mailboxes";
                mkdir($mailboxBackup, 0750, true);
                
                $rsyncCmd = sprintf(
                    'rsync -a %s/ %s/',
                    escapeshellarg($mailDir),
                    escapeshellarg($mailboxBackup)
                );
                exec($rsyncCmd . ' 2>&1', $output, $exitCode);
                
                if ($exitCode === 0) {
                    $backedUp[] = 'mailboxes';
                    $totalSize = $this->getDirectorySize($mailboxBackup);
                } else {
                    $errors[] = 'Failed to backup mailboxes';
                }
            }
            
            // Update status: backing up accounts
            $this->updateBackupTracking($statusId, 'accounts', 40, 'Backing up account data...');
            
            // 2. Backup mail accounts from database
            $panelDb = $this->getPanelDb();
            $accounts = [];
            $forwards = [];
            
            if ($panelDb) {
                // Get accounts - dynamically check which columns exist
                $columnsResult = $panelDb->query("DESCRIBE mail_accounts")->fetchAll(\PDO::FETCH_COLUMN);
                $wantedCols = ['email', 'domain', 'username', 'password_hash', 'maildir_path', 'quota', 'status'];
                $selectCols = array_intersect($wantedCols, $columnsResult);
                $stmt = $panelDb->prepare("SELECT " . implode(', ', $selectCols) . " FROM mail_accounts WHERE domain = ?");
                $stmt->execute([$domain]);
                $accounts = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $accountsCount = count($accounts);
                
                // Get forwards - check if table and columns exist
                $forwards = [];
                try {
                    $tableCheck = $panelDb->query("SHOW TABLES LIKE 'mail_forwards'")->fetch();
                    if ($tableCheck) {
                        $fwdCols = $panelDb->query("DESCRIBE mail_forwards")->fetchAll(\PDO::FETCH_COLUMN);
                        // Try common column names for source/destination
                        $srcCol = in_array('source', $fwdCols) ? 'source' : (in_array('from_email', $fwdCols) ? 'from_email' : (in_array('email', $fwdCols) ? 'email' : null));
                        $dstCol = in_array('destination', $fwdCols) ? 'destination' : (in_array('to_email', $fwdCols) ? 'to_email' : (in_array('forward_to', $fwdCols) ? 'forward_to' : null));
                        
                        if ($srcCol && $dstCol) {
                            $domainCol = in_array('domain', $fwdCols) ? 'domain' : null;
                            if ($domainCol) {
                                $stmt = $panelDb->prepare("SELECT {$srcCol} as source, {$dstCol} as destination FROM mail_forwards WHERE {$domainCol} = ?");
                                $stmt->execute([$domain]);
                            } else {
                                $stmt = $panelDb->prepare("SELECT {$srcCol} as source, {$dstCol} as destination FROM mail_forwards WHERE {$srcCol} LIKE ?");
                                $stmt->execute(['%@' . $domain]);
                            }
                            $forwards = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                        }
                    }
                } catch (\Exception $e) {
                    // Table doesn't exist or other error - continue without forwards
                    $this->logger->warning("Could not backup mail forwards: " . $e->getMessage());
                }
            }
            
            file_put_contents("{$tempDir}/accounts.json", json_encode([
                'accounts' => $accounts,
                'forwards' => $forwards,
            ], JSON_PRETTY_PRINT));
            $backedUp[] = 'accounts';
            
            // Update status: backing up DKIM
            $this->updateBackupTracking($statusId, 'dkim', 60, 'Backing up DKIM keys...');
            
            // 3. Backup DKIM keys
            $dkimPath = "/etc/opendkim/keys/{$domain}";
            if (is_dir($dkimPath)) {
                $dkimBackup = "{$tempDir}/dkim";
                mkdir($dkimBackup, 0750, true);
                $this->copyDir($dkimPath, $dkimBackup);
                $backedUp[] = 'dkim';
            }
            
            // Update status: creating archive
            $this->updateBackupTracking($statusId, 'archiving', 70, 'Creating backup archive...');
            
            // 4. Create metadata
            $meta = [
                'domain' => $domain,
                'timestamp' => $timestamp,
                'actor' => $actor,
                'accounts_count' => $accountsCount,
                'forwards_count' => count($forwards),
                'has_mailboxes' => in_array('mailboxes', $backedUp),
                'has_dkim' => in_array('dkim', $backedUp),
                'total_size' => $totalSize,
                'backed_up' => $backedUp,
                'type' => 'mail_backup',
            ];
            file_put_contents("{$tempDir}/metadata.json", json_encode($meta, JSON_PRETTY_PRINT));
            
            // 5. Create archive
            $tarCmd = sprintf(
                'tar -czf %s -C %s .',
                escapeshellarg($archivePath),
                escapeshellarg($tempDir)
            );
            exec($tarCmd . ' 2>&1', $output, $exitCode);
            
            if ($exitCode !== 0) {
                throw new \Exception('Failed to create archive');
            }
            
            $archiveSize = filesize($archivePath);

            // Save metadata BEFORE the NAS upload so that uploadToNas() picks
            // it up as a companion .meta.json and copies+verifies it together
            // with the archive. (The old order wrote the meta after a
            // potential unlink(), leaving the meta orphaned and never on NAS.)
            $meta['archive_size'] = $archiveSize;
            // nas_uploaded is updated below once we know the verified result.
            $meta['nas_uploaded'] = false;
            file_put_contents("{$archivePath}.meta.json", json_encode($meta, JSON_PRETTY_PRINT));

            // 6. Upload to NAS if requested.
            $nasUploaded = false;
            $nasResult = null;
            if ($destination === 'nas' || $destination === 'both') {
                $this->updateBackupTracking($statusId, 'uploading', 85, 'Uploading to NAS...');

                $nasResult = $this->uploadToNas($archivePath, "emails/{$domain}");
                $nasUploaded = !empty($nasResult['success']) && !empty($nasResult['verified']);

                // Only remove the local archive when:
                //   - the operator explicitly asked for NAS-only (destination=nas), AND
                //   - the NAS copy was fully verified (size + checksum) by uploadToNas().
                // If verification fails we keep the local copy so the data is
                // never lost. The local .meta.json companion is ALWAYS kept:
                // scanMailBackupDir() uses it as the stub that keeps NAS-only
                // backups visible (and restorable) in the panel listing.
                if ($destination === 'nas' && $nasUploaded) {
                    @unlink($archivePath);
                } elseif ($destination === 'nas' && !$nasUploaded) {
                    // Operator wanted nas-only but the upload could not be
                    // verified. Surface the error path so the caller can
                    // alert; the local copy is preserved as a safety net.
                    $this->logger->error('Mail backup NAS upload failed/unverified; preserving local copy', [
                        'domain' => $domain,
                        'archive' => $archivePath,
                        'nas_error' => $nasResult['error'] ?? null,
                    ]);
                }
            }

            // Refresh the meta with the final NAS state. If the archive is
            // still local (or destination was 'both'), update the on-disk
            // meta so listings reflect reality.
            if (file_exists("{$archivePath}.meta.json")) {
                $meta['nas_uploaded'] = $nasUploaded;
                file_put_contents("{$archivePath}.meta.json", json_encode($meta, JSON_PRETTY_PRINT));
            }
            
            // Cleanup temp directory
            $this->removeDir($tempDir);
            
            // Complete tracking
            $this->completeBackupTracking($statusId, true, [
                'archive' => $archiveName,
                'size_human' => $this->formatBytes($archiveSize),
                'accounts' => $accountsCount,
            ]);
            
            $this->logger->info('Mail backup created', [
                'domain' => $domain,
                'archive' => $archiveName,
                'size' => $archiveSize,
                'accounts' => $accountsCount,
                'nas' => $nasUploaded,
                'actor' => $actor,
            ]);
            
            return [
                'success' => true,
                'data' => [
                    'archive' => $archiveName,
                    'path' => $archivePath,
                    'size' => $archiveSize,
                    'size_human' => $this->formatBytes($archiveSize),
                    'accounts' => $accountsCount,
                    'forwards' => count($forwards),
                    'backed_up' => $backedUp,
                    'nas_uploaded' => $nasUploaded,
                ],
                'message' => "Mail backup created for {$domain}",
            ];
            
        } catch (\Exception $e) {
            $this->removeDir($tempDir);
            $this->completeBackupTracking($statusId, false, ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Mail backup failed: ' . $e->getMessage()];
        }
    }

    /**
     * Start background mail backup
     */
    private function startBackgroundMailBackup(string $domain, string $destination, string $statusId, string $actor): array
    {
        // Spawn a detached runner process, exactly like site backups do.
        // Running the backup inline here would block the agent's
        // single-threaded socket loop for the whole backup (and with it
        // every status poll), which is what made the panel look frozen.
        $runnerScript = __DIR__ . '/../backup-mail-runner.php';

        if (!file_exists($runnerScript)) {
            $this->completeBackupTracking($statusId, false, ['error' => 'Mail backup runner script not found']);
            return ['success' => false, 'error' => 'Mail backup runner script not found'];
        }

        $params = [
            'domain' => $domain,
            'destination' => $destination,
            'status_id' => $statusId,
            'actor' => $actor,
        ];

        $paramsJson = escapeshellarg(json_encode($params));
        $phpBinary = PHP_BINARY ?: '/usr/local/lsws/lsphp83/bin/php';

        $logFile = '/var/log/vpsadmin/backup-runner.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0750, true);
        }

        $cmd = sprintf(
            'nohup %s %s %s >> %s 2>&1 &',
            escapeshellarg($phpBinary),
            escapeshellarg($runnerScript),
            $paramsJson,
            escapeshellarg($logFile)
        );

        exec($cmd);

        $this->logger->info('Background mail backup started', [
            'domain' => $domain,
            'status_id' => $statusId,
            'destination' => $destination,
        ]);

        return [
            'success' => true,
            'async' => true,
            'data' => [
                'status_id' => $statusId,
                'domain' => $domain,
                'message' => 'Mail backup started in background',
            ],
            'message' => 'Mail backup started - poll /api/backups/status for progress',
        ];
    }

    /**
     * Inspect a mail backup
     */
    private function inspectMailBackup(array $params): array
    {
        $id = $params['id'] ?? null;
        
        if (!$id) {
            return ['success' => false, 'error' => 'Backup ID is required'];
        }
        
        $path = base64_decode($id);
        $metaFile = "{$path}.meta.json";
        $nasOnly = false;
        $downloadedFromNas = false;
        
        // Check if file exists locally
        if (!$path || !file_exists($path)) {
            // Check if this is a NAS-only backup (meta.json exists but archive doesn't)
            if (file_exists($metaFile)) {
                $meta = json_decode(file_get_contents($metaFile), true);
                if (!empty($meta['nas_uploaded'])) {
                    $nasOnly = true;
                    // For inspection, we can use the metadata without downloading
                    // But if we need full inspection, download from NAS
                    if (!$meta || empty($meta['accounts_count'])) {
                        // Download from NAS for full inspection
                        $downloadResult = $this->downloadFromNas($path, $meta['domain'] ?? null);
                        if (!$downloadResult['success']) {
                            // Return metadata-only response for NAS-only backups
                            return [
                                'success' => true,
                                'data' => [
                                    'id' => $id,
                                    'path' => $path,
                                    'filename' => basename($path),
                                    'size' => $meta['archive_size'] ?? 0,
                                    'size_human' => isset($meta['archive_size']) ? $this->formatBytes($meta['archive_size']) : 'N/A',
                                    'date' => isset($meta['timestamp']) ? str_replace('_', ' ', $meta['timestamp']) : 'N/A',
                                    'nas_only' => true,
                                    'meta' => $meta,
                                ],
                            ];
                        }
                        $downloadedFromNas = true;
                    } else {
                        // Return metadata without downloading
                        return [
                            'success' => true,
                            'data' => [
                                'id' => $id,
                                'path' => $path,
                                'filename' => basename($path),
                                'size' => $meta['archive_size'] ?? 0,
                                'size_human' => isset($meta['archive_size']) ? $this->formatBytes($meta['archive_size']) : 'N/A',
                                'date' => isset($meta['timestamp']) ? str_replace('_', ' ', $meta['timestamp']) : 'N/A',
                                'nas_only' => true,
                                'meta' => $meta,
                            ],
                        ];
                    }
                } else {
                    return ['success' => false, 'error' => 'Backup not found'];
                }
            } else {
                return ['success' => false, 'error' => 'Backup not found'];
            }
        }
        
        $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : null;
        
        // Extract and inspect if no metadata
        if (!$meta) {
            $tempDir = sys_get_temp_dir() . '/vps-mail-inspect-' . uniqid();
            mkdir($tempDir, 0750, true);
            
            exec("tar -xzf " . escapeshellarg($path) . " -C " . escapeshellarg($tempDir) . " 2>&1");
            
            // Read accounts
            $accountsFile = "{$tempDir}/accounts.json";
            $accountsData = file_exists($accountsFile) ? json_decode(file_get_contents($accountsFile), true) : [];
            
            // Read metadata
            $metadataFile = "{$tempDir}/metadata.json";
            $meta = file_exists($metadataFile) ? json_decode(file_get_contents($metadataFile), true) : [];
            
            $meta['accounts'] = $accountsData['accounts'] ?? [];
            $meta['forwards'] = $accountsData['forwards'] ?? [];
            
            // Check mailboxes
            $mailboxesDir = "{$tempDir}/mailboxes";
            if (is_dir($mailboxesDir)) {
                $mailboxes = [];
                foreach (scandir($mailboxesDir) as $user) {
                    if ($user === '.' || $user === '..') continue;
                    $userDir = "{$mailboxesDir}/{$user}";
                    if (is_dir($userDir)) {
                        $mailboxes[] = [
                            'user' => $user,
                            'size' => $this->getDirectorySize($userDir),
                            'size_human' => $this->formatBytes($this->getDirectorySize($userDir)),
                        ];
                    }
                }
                $meta['mailboxes'] = $mailboxes;
            }
            
            $this->removeDir($tempDir);
        }
        
        return [
            'success' => true,
            'data' => [
                'id' => $id,
                'path' => $path,
                'filename' => basename($path),
                'size' => filesize($path),
                'size_human' => $this->formatBytes(filesize($path)),
                'date' => date('Y-m-d H:i:s', filemtime($path)),
                'nas_only' => $nasOnly,
                'downloaded_from_nas' => $downloadedFromNas,
                'meta' => $meta,
            ],
        ];
    }

    /**
     * Restore mail from backup
     * Supports dry_run mode to preview what would be restored
     */
    private function restoreMail(array $params, string $actor): array
    {
        set_time_limit(0);
        
        $id = $params['id'] ?? null;
        $domain = $params['domain'] ?? null;
        $restoreMailboxes = $params['restore_mailboxes'] ?? true;
        $restoreAccounts = $params['restore_accounts'] ?? true;
        $restoreDkim = $params['restore_dkim'] ?? true;
        $mode = $params['mode'] ?? 'merge'; // 'merge' or 'replace'
        $dryRun = $params['dry_run'] ?? false;
        
        if (!$id) {
            return ['success' => false, 'error' => 'Backup ID is required'];
        }
        
        $path = base64_decode($id);
        $downloadedFromNas = false;
        
        // Check if file exists locally
        if (!$path || !file_exists($path)) {
            // Check if this is a NAS-only backup (meta.json exists but archive doesn't)
            $metaPath = $path . '.meta.json';
            if (file_exists($metaPath)) {
                $meta = json_decode(file_get_contents($metaPath), true);
                if (!empty($meta['nas_uploaded'])) {
                    // Download from NAS first
                    $downloadResult = $this->downloadFromNas($path, $meta['domain'] ?? $domain);
                    if (!$downloadResult['success']) {
                        return ['success' => false, 'error' => 'Failed to download backup from NAS: ' . ($downloadResult['error'] ?? 'Unknown error')];
                    }
                    $downloadedFromNas = true;
                } else {
                    return ['success' => false, 'error' => 'Backup not found'];
                }
            } else {
                return ['success' => false, 'error' => 'Backup not found'];
            }
        }
        
        $tempDir = sys_get_temp_dir() . '/vps-mail-restore-' . uniqid();
        mkdir($tempDir, 0750, true);
        
        $restored = [];
        $wouldRestore = [];
        $errors = [];
        $logs = [];
        $analysis = [];
        
        try {
            // Initial log
            $prefix = $dryRun ? '[DRY RUN] ' : '';
            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "{$prefix}Starting mail restore"];
            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Mode: {$mode}, Actor: {$actor}"];
            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Options: mailboxes=" . ($restoreMailboxes ? 'yes' : 'no') . ", accounts=" . ($restoreAccounts ? 'yes' : 'no') . ", dkim=" . ($restoreDkim ? 'yes' : 'no')];
            
            if ($downloadedFromNas) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Backup downloaded from NAS'];
            }
            
            // Verify archive
            $archiveSize = filesize($path);
            $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => "Archive verified: {$this->formatBytes($archiveSize)}"];
            
            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Extracting backup archive...'];
            
            // Extract archive
            exec("tar -xzf " . escapeshellarg($path) . " -C " . escapeshellarg($tempDir) . " 2>&1", $output, $exitCode);
            
            if ($exitCode !== 0) {
                throw new \Exception('Failed to extract archive');
            }
            $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => 'Archive extracted successfully'];
            
            // Read metadata
            $metaFile = "{$tempDir}/metadata.json";
            $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : [];
            $backupDomain = $meta['domain'] ?? $domain;
            
            if (!$backupDomain) {
                throw new \Exception('Cannot determine domain from backup');
            }
            
            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Domain: {$backupDomain}"];
            if (!empty($meta['timestamp'])) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Backup created: {$meta['timestamp']} by " . ($meta['actor'] ?? 'unknown')];
            }
            
            $mailDir = "{$this->mailPath}/{$backupDomain}";
            
            // 1. Analyze/Restore mailboxes
            if ($restoreMailboxes) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => '--- MAILBOXES ---'];
                $mailboxesDir = "{$tempDir}/mailboxes";
                
                if (is_dir($mailboxesDir)) {
                    // Count mailboxes in backup
                    $backupMailboxes = [];
                    foreach (scandir($mailboxesDir) as $user) {
                        if ($user === '.' || $user === '..') continue;
                        $userDir = "{$mailboxesDir}/{$user}";
                        if (is_dir($userDir)) {
                            $size = $this->getDirectorySize($userDir);
                            $backupMailboxes[] = ['user' => $user, 'size' => $size];
                        }
                    }
                    
                    $backupSize = $this->getDirectorySize($mailboxesDir);
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Backup contains " . count($backupMailboxes) . " mailbox(es), {$this->formatBytes($backupSize)}"];
                    
                    $analysis['mailboxes'] = [
                        'backup_count' => count($backupMailboxes),
                        'backup_size' => $backupSize,
                        'backup_size_human' => $this->formatBytes($backupSize),
                        'mailboxes' => $backupMailboxes,
                    ];
                    
                    // Check current state
                    if (is_dir($mailDir)) {
                        $currentSize = $this->getDirectorySize($mailDir);
                        $currentCount = count(array_filter(scandir($mailDir), fn($d) => $d !== '.' && $d !== '..'));
                        $analysis['mailboxes']['current_count'] = $currentCount;
                        $analysis['mailboxes']['current_size'] = $currentSize;
                        $analysis['mailboxes']['current_size_human'] = $this->formatBytes($currentSize);
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Current mailboxes: {$currentCount}, {$this->formatBytes($currentSize)}"];
                        
                        if ($mode === 'replace') {
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => "{$prefix}REPLACE mode: Current mailboxes would be backed up then overwritten"];
                        }
                    } else {
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Mail directory does not exist yet: {$mailDir}"];
                    }
                    
                    if ($dryRun) {
                        $wouldRestore[] = 'mailboxes';
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "[DRY RUN] Would restore " . count($backupMailboxes) . " mailbox(es)"];
                    } else {
                        // Create mail directory if needed
                        if (!is_dir($mailDir)) {
                            mkdir($mailDir, 0700, true);
                        }
                        
                        if ($mode === 'replace' && is_dir($mailDir)) {
                            // Backup existing before replace
                            $backupExisting = "{$mailDir}.pre-restore-" . date('Y-m-d_H-i-s');
                            rename($mailDir, $backupExisting);
                            mkdir($mailDir, 0700, true);
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => "Existing mailboxes backed up to " . basename($backupExisting)];
                        }
                        
                        // Rsync mailboxes
                        $rsyncCmd = sprintf(
                            'rsync -a %s/ %s/',
                            escapeshellarg($mailboxesDir),
                            escapeshellarg($mailDir)
                        );
                        exec($rsyncCmd . ' 2>&1', $output, $exitCode);
                        
                        // Fix ownership
                        $this->execCommand('chown', ['-R', 'vmail:vmail', $mailDir]);
                        $this->execCommand('chmod', ['-R', '700', $mailDir]);
                        
                        $restored[] = 'mailboxes';
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => 'Mailboxes restored'];
                    }
                } else {
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => 'No mailboxes in backup'];
                }
            }
            
            // 2. Analyze/Restore accounts
            if ($restoreAccounts) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => '--- MAIL ACCOUNTS ---'];
                $accountsFile = "{$tempDir}/accounts.json";
                
                if (file_exists($accountsFile)) {
                    $accountsData = json_decode(file_get_contents($accountsFile), true);
                    $backupAccountsCount = count($accountsData['accounts'] ?? []);
                    $backupForwardsCount = count($accountsData['forwards'] ?? []);
                    
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Backup contains {$backupAccountsCount} account(s), {$backupForwardsCount} forward(s)"];
                    
                    $analysis['accounts'] = [
                        'backup_accounts_count' => $backupAccountsCount,
                        'backup_forwards_count' => $backupForwardsCount,
                        'accounts' => array_map(fn($a) => $a['email'] ?? 'unknown', $accountsData['accounts'] ?? []),
                    ];
                    
                    // Check current state
                    $panelDb = $this->getPanelDb();
                    if ($panelDb) {
                        $stmt = $panelDb->prepare("SELECT COUNT(*) FROM mail_accounts WHERE domain = ?");
                        $stmt->execute([$backupDomain]);
                        $currentAccountsCount = (int)$stmt->fetchColumn();
                        
                        $analysis['accounts']['current_accounts_count'] = $currentAccountsCount;
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Current accounts in database: {$currentAccountsCount}"];
                        
                        // Count how many would be new vs updated
                        $newAccounts = 0;
                        $existingAccounts = 0;
                        foreach ($accountsData['accounts'] ?? [] as $account) {
                            $stmt = $panelDb->prepare("SELECT id FROM mail_accounts WHERE email = ?");
                            $stmt->execute([$account['email']]);
                            if ($stmt->fetch()) {
                                $existingAccounts++;
                            } else {
                                $newAccounts++;
                            }
                        }
                        $analysis['accounts']['would_create'] = $newAccounts;
                        $analysis['accounts']['would_update'] = $mode === 'replace' ? $existingAccounts : 0;
                        
                        if ($dryRun) {
                            $wouldRestore[] = 'accounts';
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "[DRY RUN] Would create {$newAccounts} new account(s)"];
                            if ($mode === 'replace' && $existingAccounts > 0) {
                                $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => "[DRY RUN] Would update {$existingAccounts} existing account(s)"];
                            } elseif ($existingAccounts > 0) {
                                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "[DRY RUN] {$existingAccounts} account(s) already exist (merge mode - skipped)"];
                            }
                            if ($backupForwardsCount > 0) {
                                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "[DRY RUN] Would restore up to {$backupForwardsCount} forward(s)"];
                            }
                        } else {
                            // Ensure domain exists
                            $stmt = $panelDb->prepare("INSERT IGNORE INTO mail_domains (domain, status) VALUES (?, 'active')");
                            $stmt->execute([$backupDomain]);
                            
                            $accountsRestored = 0;
                            $forwardsRestored = 0;
                            
                            // Restore accounts
                            foreach ($accountsData['accounts'] ?? [] as $account) {
                                $email = $account['email'];
                                $stmt = $panelDb->prepare("SELECT id FROM mail_accounts WHERE email = ?");
                                $stmt->execute([$email]);
                                
                                if ($stmt->fetch()) {
                                    if ($mode === 'replace') {
                                        $stmt = $panelDb->prepare("UPDATE mail_accounts SET password_hash = ?, status = 'active' WHERE email = ?");
                                        $stmt->execute([$account['password_hash'], $email]);
                                        $accountsRestored++;
                                    }
                                } else {
                                    $stmt = $panelDb->prepare("INSERT INTO mail_accounts (email, domain, username, password_hash, maildir_path, status) VALUES (?, ?, ?, ?, ?, 'active')");
                                    $stmt->execute([
                                        $email,
                                        $account['domain'],
                                        $account['username'],
                                        $account['password_hash'],
                                        $account['maildir_path'] ?? null,
                                    ]);
                                    $accountsRestored++;
                                }
                            }
                            
                            // Restore forwards - check if table exists first
                            try {
                                $tableCheck = $panelDb->query("SHOW TABLES LIKE 'mail_forwards'")->fetch();
                                if ($tableCheck && !empty($accountsData['forwards'])) {
                                    foreach ($accountsData['forwards'] as $forward) {
                                        // Skip if forward data is incomplete
                                        if (empty($forward['source']) || empty($forward['destination'])) continue;
                                        
                                        // Check if already exists by trying flexible column names
                                        $fwdCols = $panelDb->query("DESCRIBE mail_forwards")->fetchAll(\PDO::FETCH_COLUMN);
                                        $srcCol = in_array('source', $fwdCols) ? 'source' : (in_array('from_email', $fwdCols) ? 'from_email' : (in_array('email', $fwdCols) ? 'email' : 'source_email'));
                                        $dstCol = in_array('destination', $fwdCols) ? 'destination' : (in_array('to_email', $fwdCols) ? 'to_email' : (in_array('forward_to', $fwdCols) ? 'forward_to' : 'destination'));
                                        
                                        $stmt = $panelDb->prepare("SELECT * FROM mail_forwards WHERE {$srcCol} = ? LIMIT 1");
                                        $stmt->execute([$forward['source']]);
                                        
                                        if (!$stmt->fetch()) {
                                            // Build INSERT based on available columns
                                            $insertCols = [$srcCol, $dstCol];
                                            $insertVals = [$forward['source'], $forward['destination']];
                                            
                                            if (in_array('domain', $fwdCols)) {
                                                $insertCols[] = 'domain';
                                                $insertVals[] = $backupDomain;
                                            }
                                            if (in_array('status', $fwdCols)) {
                                                $insertCols[] = 'status';
                                                $insertVals[] = 'active';
                                            }
                                            
                                            $placeholders = str_repeat('?,', count($insertCols) - 1) . '?';
                                            $stmt = $panelDb->prepare("INSERT INTO mail_forwards (" . implode(',', $insertCols) . ") VALUES ({$placeholders})");
                                            $stmt->execute($insertVals);
                                            $forwardsRestored++;
                                        }
                                    }
                                }
                            } catch (\Exception $e) {
                                $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => 'Could not restore forwards: ' . $e->getMessage()];
                            }
                            
                            $restored[] = 'accounts';
                            $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => "Restored {$accountsRestored} accounts, {$forwardsRestored} forwards"];
                        }
                    }
                } else {
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => 'No accounts data in backup'];
                }
            }
            
            // 3. Analyze/Restore DKIM keys
            if ($restoreDkim) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => '--- DKIM KEYS ---'];
                $dkimDir = "{$tempDir}/dkim";
                
                if (is_dir($dkimDir)) {
                    $dkimFiles = glob("{$dkimDir}/*");
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Backup contains " . count($dkimFiles) . " DKIM file(s)"];
                    
                    $analysis['dkim'] = [
                        'backup_files_count' => count($dkimFiles),
                    ];
                    
                    $dkimPath = "/etc/opendkim/keys/{$backupDomain}";
                    $currentDkimExists = is_dir($dkimPath);
                    $analysis['dkim']['current_exists'] = $currentDkimExists;
                    
                    if ($currentDkimExists) {
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "Current DKIM keys exist at: {$dkimPath}"];
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => "{$prefix}Current DKIM keys would be overwritten"];
                    }
                    
                    if ($dryRun) {
                        $wouldRestore[] = 'dkim';
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "[DRY RUN] Would restore DKIM keys to: {$dkimPath}"];
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => "[DRY RUN] Would reload opendkim service"];
                    } else {
                        if (!is_dir(dirname($dkimPath))) {
                            mkdir(dirname($dkimPath), 0700, true);
                        }
                        if (!is_dir($dkimPath)) {
                            mkdir($dkimPath, 0700, true);
                        }
                        
                        $this->copyDir($dkimDir, $dkimPath);
                        $this->execCommand('chown', ['-R', 'opendkim:opendkim', $dkimPath]);
                        $this->execCommand('chmod', ['-R', '600', $dkimPath]);
                        
                        // Reload opendkim if running
                        exec('systemctl is-active opendkim 2>/dev/null', $output, $exitCode);
                        if ($exitCode === 0) {
                            $this->execCommand('systemctl', ['reload', 'opendkim']);
                        }
                        
                        $restored[] = 'dkim';
                        $logs[] = ['time' => date('H:i:s'), 'level' => 'success', 'message' => 'DKIM keys restored'];
                    }
                } else {
                    $logs[] = ['time' => date('H:i:s'), 'level' => 'warning', 'message' => 'No DKIM keys in backup'];
                }
            }
            
            // Cleanup
            $this->removeDir($tempDir);
            
            if ($dryRun) {
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => '=== DRY RUN COMPLETE ==='];
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Components that WOULD be restored: ' . implode(', ', $wouldRestore)];
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'No actual changes were made'];
                
                return [
                    'success' => true,
                    'data' => [
                        'domain' => $backupDomain,
                        'dry_run' => true,
                        'would_restore' => $wouldRestore,
                        'analysis' => $analysis,
                        'logs' => $logs,
                    ],
                    'message' => "[DRY RUN] Mail restore preview for {$backupDomain}",
                ];
            }
            
            // Reload dovecot to pick up changes
            exec('systemctl is-active dovecot 2>/dev/null', $output, $exitCode);
            if ($exitCode === 0) {
                $this->execCommand('systemctl', ['reload', 'dovecot']);
                $logs[] = ['time' => date('H:i:s'), 'level' => 'info', 'message' => 'Dovecot reloaded'];
            }
            
            $this->logger->info('Mail backup restored', [
                'domain' => $backupDomain,
                'restored' => $restored,
                'actor' => $actor,
            ]);
            
            return [
                'success' => true,
                'data' => [
                    'domain' => $backupDomain,
                    'restored' => $restored,
                    'logs' => $logs,
                ],
                'message' => "Mail restored for {$backupDomain}",
            ];
            
        } catch (\Exception $e) {
            $this->removeDir($tempDir);
            $logs[] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => $e->getMessage()];
            return [
                'success' => false,
                'error' => 'Mail restore failed: ' . $e->getMessage(),
                'data' => ['logs' => $logs],
            ];
        }
    }
}

