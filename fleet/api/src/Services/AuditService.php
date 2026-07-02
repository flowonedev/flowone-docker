<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;

/**
 * Post-deployment Audit Service
 *
 * Runs comprehensive integrity checks on a provisioned server to verify
 * that all services, databases, filesystems, SSL, firewalls, and applications
 * are correctly configured and running. Can be triggered on demand or
 * automatically at the end of a deployment.
 *
 * Also provides remediation actions to fix common failures directly via SSH.
 */
class AuditService
{
    private Container $container;
    private SSHService $ssh;
    private EncryptionService $encryption;
    private \PDO $db;
    private bool $lastMysqlOk = false;

    private const REQUIRED_SERVICES = [
        'lshttpd'      => 'OpenLiteSpeed',
        'mariadb'      => 'MariaDB',
        'redis-server' => 'Redis',
        'postfix'      => 'Postfix',
        'dovecot'      => 'Dovecot',
        'fail2ban'     => 'Fail2ban',
        'firewalld'    => 'FirewallD',
    ];

    private const OPTIONAL_SERVICES = [
        'meilisearch'     => 'Meilisearch',
        'spamd'           => 'SpamAssassin',
        'spamassassin'    => 'SpamAssassin',
        'opendkim'        => 'OpenDKIM',
        'collab-server'   => 'Collab Server',
        'mailsync-server' => 'MailSync Server',
    ];

    private const ALL_SERVICES = [
        'lshttpd'        => 'OpenLiteSpeed',
        'mariadb'        => 'MariaDB',
        'redis-server'   => 'Redis',
        'postfix'        => 'Postfix',
        'dovecot'        => 'Dovecot',
        'fail2ban'       => 'Fail2ban',
        'firewalld'      => 'FirewallD',
        'meilisearch'    => 'Meilisearch',
        'spamd'          => 'SpamAssassin',
        'spamassassin'   => 'SpamAssassin',
        'opendkim'       => 'OpenDKIM',
        'collab-server'  => 'Collab Server',
        'mailsync-server'=> 'MailSync Server',
        'vpsadmin-agent' => 'Panel Agent',
        'fleet-agent'    => 'Fleet Agent',
    ];

    private const CRITICAL_PACKAGES = ['postfix-mysql'];

    private const CONFIG_FILES_TO_SCAN = [
        '/etc/dovecot/dovecot.conf'                     => 'Dovecot config',
        '/etc/dovecot/dovecot-sql.conf.ext'             => 'Dovecot SQL config',
        '/etc/postfix/main.cf'                          => 'Postfix main config',
        '/usr/local/lsws/conf/httpd_config.conf'        => 'OLS main config',
        '/var/www/vps-admin/api/.env'                   => 'Panel API .env',
        '/var/www/vps-email/backend/.env'               => 'Email backend .env',
    ];

    private const SERVICE_PACKAGES = [
        'lshttpd'      => 'openlitespeed',
        'mariadb'      => 'mariadb-server',
        'redis-server' => 'redis-server',
        'postfix'      => 'postfix',
        'dovecot'      => 'dovecot-imapd',
        'fail2ban'     => 'fail2ban',
    ];

    private const SERVICE_CONFIG_FILES = [
        'dovecot'  => ['/etc/dovecot/dovecot.conf', '/etc/dovecot/dovecot-sql.conf.ext'],
        'postfix'  => ['/etc/postfix/main.cf'],
        'lshttpd'  => ['/usr/local/lsws/conf/httpd_config.conf'],
    ];

    private const REQUIRED_PATHS = [
        '/var/www/vps-admin'                            => 'Panel web root',
        '/var/www/vps-admin/api'                        => 'Panel API directory',
        '/var/www/vps-email'                            => 'Email app web root',
        '/var/www/vps-email/backend'                    => 'Email app backend',
        '/var/www/vps-email/dist'                       => 'Email app frontend',
        '/usr/local/lsws/conf/httpd_config.conf'        => 'OLS main config',
        '/usr/local/lsws/conf/vhosts'                   => 'OLS vhosts directory',
    ];

    private const FIREWALL_REQUIRED_PORTS = [
        '22/tcp'   => 'SSH',
        '25/tcp'   => 'SMTP',
        '80/tcp'   => 'HTTP',
        '443/tcp'  => 'HTTPS',
        '587/tcp'  => 'SMTP Submission',
        '993/tcp'  => 'IMAPS',
        '4190/tcp' => 'ManageSieve',
        '7080/tcp' => 'OLS Admin',
    ];

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->ssh = $container->get(SSHService::class);
        $this->encryption = $container->get(EncryptionService::class);
        $this->db = $container->getDatabase();
    }

    /**
     * Run full audit for a server. Connects via SSH and verifies everything.
     */
    public function run(int $serverId): array
    {
        $totalStart = microtime(true);

        $stmt = $this->db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$server) {
            return ['success' => false, 'error' => 'Server not found'];
        }

        // Docker boxes get the container-aware audit (compose ps, in-pod mail
        // checks, TCP database checks). This native audit would report walls of
        // false FAILs for services that deliberately live in containers.
        if (!empty($server['deployed_image_tag'])) {
            return $this->container->get(DockerAuditService::class)->run($serverId);
        }

        // Connect to server
        $sshOk = $this->connectToServer($server);
        if (!$sshOk) {
            return [
                'success' => false,
                'error' => 'Cannot connect to server via SSH',
            ];
        }

        // Generate variables from server record
        $templates = $this->container->get(TemplateService::class);
        $variables = $templates->generateServerVariables($server);

        $results = [
            'timestamp' => date('Y-m-d H:i:s'),
            'server_id' => $serverId,
            'checks' => [],
            'passed' => 0,
            'failed' => 0,
            'warnings' => 0,
            'total' => 0,
        ];

        // Run each audit category
        $this->checkServices($results);
        $this->checkPackages($results);
        $this->checkDatabases($results, $variables);
        $this->checkFilesystem($results);
        $this->checkSSL($results, $variables);
        $this->checkHTTP($results, $variables);
        $this->checkFirewall($results);
        $this->checkPanelAdmin($results, $variables);
        $this->checkAgents($results);
        $this->checkPostfixConfig($results, $variables);
        $this->checkDovecotConfig($results, $variables);
        $this->checkConfigVariables($results);

        $results['total'] = $results['passed'] + $results['failed'] + $results['warnings'];
        $results['overall'] = $results['failed'] === 0 ? 'pass' : 'fail';
        $results['duration_ms'] = (int)round((microtime(true) - $totalStart) * 1000);

        // Read and update deployed versions via SSH
        $this->updateVersionsFromServer($serverId);

        // Store results
        $this->storeResults($serverId, $results);

        $this->ssh->disconnect();

        return [
            'success' => true,
            'audit' => $results,
        ];
    }

    /**
     * Execute a fix action for a failed audit check.
     * Returns the result with output and whether the fix was successful.
     */
    public function fix(int $serverId, string $action, array $params = []): array
    {
        $stmt = $this->db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$server) {
            return ['success' => false, 'error' => 'Server not found'];
        }

        // Docker boxes: container-aware fixes (compose restart, in-pod
        // supervisorctl, TCP DB grants) instead of systemctl/apt remediation.
        if (!empty($server['deployed_image_tag'])) {
            return $this->container->get(DockerAuditService::class)->fix($serverId, $action, $params);
        }

        if (!$this->connectToServer($server)) {
            return ['success' => false, 'error' => 'Cannot connect to server via SSH'];
        }

        $templates = $this->container->get(TemplateService::class);
        $variables = $templates->generateServerVariables($server);

        try {
            $result = match ($action) {
                'restart_service'    => $this->fixRestartService($params, $variables),
                'reinstall_service'  => $this->fixReinstallService($params, $variables),
                'install_package'    => $this->fixInstallPackage($params),
                'fix_db_user'        => $this->fixDatabaseUser($params, $variables),
                'create_database'    => $this->fixCreateDatabase($params, $variables),
                'renew_ssl'          => $this->fixRenewSSL($params),
                'open_port'          => $this->fixOpenPort($params),
                'fix_config_vars'    => $this->fixConfigVariables($params, $variables),
                'fix_all'            => $this->fixAll($serverId, $variables),
                default              => ['success' => false, 'message' => "Unknown fix action: {$action}"],
            };
        } catch (\Throwable $e) {
            $result = ['success' => false, 'message' => $e->getMessage()];
        }

        $this->ssh->disconnect();
        return $result;
    }

    /**
     * Fix all failed/warning checks in one batch.
     */
    private function fixAll(int $serverId, array $variables): array
    {
        $results = [];

        // Fix all config variables first (before restarting services)
        foreach (self::CONFIG_FILES_TO_SCAN as $file => $label) {
            $exists = $this->exec("test -f {$file} && echo 'OK' || echo 'MISSING'");
            if (strpos($exists, 'MISSING') !== false) continue;
            $count = $this->exec("grep -cP '\\{\\{[A-Z_]+\\}\\}' {$file} 2>/dev/null || echo '0'");
            if (intval(trim($count)) > 0) {
                $results[] = $this->fixConfigVariables(['file' => $file], $variables);
            }
        }

        // Fix all failed services (smart: diagnose + fix + restart)
        foreach (self::REQUIRED_SERVICES as $svc => $label) {
            $out = trim($this->exec("systemctl is-active {$svc} 2>/dev/null")) ?: 'inactive';
            if (!in_array($out, ['active', 'exited'])) {
                $results[] = $this->fixRestartService(['service' => $svc], $variables);
            }
        }
        foreach (self::OPTIONAL_SERVICES as $svc => $label) {
            $unitExists = $this->exec("systemctl cat {$svc} > /dev/null 2>&1 && echo 'found' || echo 'missing'");
            if ($unitExists === 'missing') continue;
            $out = trim($this->exec("systemctl is-active {$svc} 2>/dev/null")) ?: 'inactive';
            if (!in_array($out, ['active', 'exited', 'activating'])) {
                $results[] = $this->fixRestartService(['service' => $svc], $variables);
            }
        }

        // Fix DB users
        $databases = [
            $variables['PANEL_DB_NAME'] => ['user' => $variables['PANEL_DB_USER'], 'pass' => $variables['PANEL_DB_PASS']],
            $variables['MAIL_DB_NAME']  => ['user' => $variables['MAIL_DB_USER'], 'pass' => $variables['MAIL_DB_PASS']],
        ];
        foreach ($databases as $dbName => $cred) {
            if (!$this->mysqlCanConnect($cred['user'], $cred['pass'], $dbName)) {
                $results[] = $this->fixDatabaseUser(['db_name' => $dbName, 'db_user' => $cred['user']], $variables);
            }
        }

        // Fix agents
        foreach (['vpsadmin-agent', 'fleet-agent'] as $svc) {
            $out = trim($this->exec("systemctl is-active {$svc} 2>/dev/null")) ?: 'inactive';
            if ($out !== 'active') {
                $results[] = $this->fixRestartService(['service' => $svc]);
            }
        }

        $succeeded = count(array_filter($results, fn($r) => $r['success']));
        $failed = count($results) - $succeeded;

        return [
            'success' => $failed === 0,
            'message' => "{$succeeded} fix(es) applied" . ($failed > 0 ? ", {$failed} failed" : ''),
            'details' => $results,
        ];
    }

    /**
     * Smart service fix: diagnose root cause, fix it, then restart.
     * Order: stop -> fix config -> fix binary -> restart -> verify.
     */
    private function fixRestartService(array $params, array $variables = []): array
    {
        $service = $params['service'] ?? '';
        $labelMap = self::ALL_SERVICES;
        $label = $labelMap[$service] ?? $service;

        if (!$service || !isset($labelMap[$service])) {
            return ['success' => false, 'message' => "Invalid service: {$service}"];
        }

        $output = '';

        // 1) Check if unit exists
        $unitExists = $this->exec("systemctl cat {$service} > /dev/null 2>&1 && echo 'found' || echo 'missing'");
        if ($unitExists === 'missing') {
            return [
                'success' => false,
                'message' => "{$label} is not installed on this server",
                'output' => "Unit {$service}.service not found",
            ];
        }

        // Stop the service first so dpkg won't trigger a broken restart
        $this->exec("systemctl stop {$service} 2>/dev/null");

        // 2) Fix config variables FIRST (before any restart)
        $configFiles = self::SERVICE_CONFIG_FILES[$service] ?? [];
        foreach ($configFiles as $configFile) {
            $exists = $this->exec("test -f {$configFile} && echo 'OK' || echo 'MISSING'");
            if (strpos($exists, 'MISSING') !== false) continue;

            $count = $this->exec("grep -cP '\\{\\{[A-Z_]+\\}\\}' {$configFile} 2>/dev/null || echo '0'");
            if (intval(trim($count)) > 0) {
                $output .= "[config] Fixing variables in {$configFile}\n";
                $fixResult = $this->fixConfigVariables(['file' => $configFile], $variables);
                $output .= $fixResult['output'] . "\n";
            }
        }

        // 3) Check for missing binaries (look for specific binary errors, not general "No such file")
        $journal = $this->exec("journalctl -u {$service} --no-pager -n 20 2>/dev/null");
        $binaryMissing = strpos($journal, 'Cannot find /') !== false
                      || preg_match('/access\([^)]+\) failed: No such file/', $journal);

        if ($binaryMissing) {
            $package = self::SERVICE_PACKAGES[$service] ?? null;
            if ($package) {
                $output .= "[reinstall] Binary missing, reinstalling {$package}\n";
                $installOut = $this->exec("DEBIAN_FRONTEND=noninteractive apt-get install --reinstall -y {$package} 2>&1");
                $output .= $installOut . "\n";
            }

            // For Dovecot, also ensure pop3d is installed if config references it
            if ($service === 'dovecot') {
                $needsPop3 = $this->exec("grep -q 'pop3' /etc/dovecot/dovecot.conf 2>/dev/null && echo 'yes' || echo 'no'");
                if ($needsPop3 === 'yes') {
                    $pop3Check = $this->exec("dpkg -l dovecot-pop3d 2>/dev/null | grep -c '^ii' || echo '0'");
                    if ($pop3Check === '0') {
                        $output .= "[install] Installing dovecot-pop3d\n";
                        $output .= $this->exec("DEBIAN_FRONTEND=noninteractive apt-get install -y dovecot-pop3d 2>&1") . "\n";
                    }
                }
            }
        }

        // 4) Start the service
        $output .= "[restart] systemctl start {$service}\n";
        $restartOutput = $this->exec("systemctl start {$service} 2>&1");
        if ($restartOutput) $output .= $restartOutput . "\n";

        // 5) Poll for status
        $status = 'unknown';
        for ($i = 0; $i < 5; $i++) {
            sleep(2);
            $status = trim($this->exec("systemctl is-active {$service} 2>/dev/null"));
            if (!$status) $status = 'unknown';
            if (in_array($status, ['active', 'exited', 'failed', 'inactive'])) {
                break;
            }
        }

        $ok = in_array($status, ['active', 'exited']);
        $output .= "Final status: {$status}";

        if (!$ok) {
            $freshJournal = $this->exec("journalctl -u {$service} --no-pager -n 10 2>&1");
            $output .= "\n--- journal ---\n" . $freshJournal;
        }

        return [
            'success' => $ok,
            'message' => $ok ? "{$label} fixed and running" : "{$label} still failing: {$status}",
            'output' => $output,
        ];
    }

    /**
     * Reinstall a service whose binary is missing or broken.
     */
    private function fixReinstallService(array $params, array $variables = []): array
    {
        $service = $params['service'] ?? '';
        $labelMap = self::ALL_SERVICES;
        $label = $labelMap[$service] ?? $service;
        $package = self::SERVICE_PACKAGES[$service] ?? null;

        if (!$service || !$package) {
            return ['success' => false, 'message' => "No known package for service: {$service}"];
        }

        $this->exec("systemctl stop {$service} 2>/dev/null");

        $output = "[reinstall] Package: {$package}\n";
        $output .= $this->exec("DEBIAN_FRONTEND=noninteractive apt-get install --reinstall -y {$package} 2>&1");

        // Check binary exists now
        $binaryMap = [
            'lshttpd' => '/usr/local/lsws/bin/litespeed',
        ];
        if (isset($binaryMap[$service])) {
            $binCheck = $this->exec("test -f {$binaryMap[$service]} && echo 'OK' || echo 'MISSING'");
            $output .= "\nBinary check: {$binCheck}";
            if (strpos($binCheck, 'MISSING') !== false) {
                return [
                    'success' => false,
                    'message' => "{$label} binary still missing after reinstall. Package may need manual repo setup.",
                    'output' => $output,
                ];
            }
        }

        // Fix config variables before starting
        $configFiles = self::SERVICE_CONFIG_FILES[$service] ?? [];
        foreach ($configFiles as $configFile) {
            $exists = $this->exec("test -f {$configFile} && echo 'OK' || echo 'MISSING'");
            if (strpos($exists, 'MISSING') !== false) continue;
            $count = $this->exec("grep -cP '\\{\\{[A-Z_]+\\}\\}' {$configFile} 2>/dev/null || echo '0'");
            if (intval(trim($count)) > 0) {
                $output .= "\n[config] Fixing variables in {$configFile}\n";
                $fixResult = $this->fixConfigVariables(['file' => $configFile], $variables);
                $output .= $fixResult['output'] . "\n";
            }
        }

        // Start the service
        $output .= "\n[restart] Starting {$service}\n";
        $this->exec("systemctl start {$service} 2>&1");

        $status = 'unknown';
        for ($i = 0; $i < 5; $i++) {
            sleep(2);
            $status = trim($this->exec("systemctl is-active {$service} 2>/dev/null"));
            if (!$status) $status = 'unknown';
            if (in_array($status, ['active', 'exited', 'failed', 'inactive'])) {
                break;
            }
        }

        $ok = in_array($status, ['active', 'exited']);
        $output .= "Final status: {$status}";

        if (!$ok) {
            $journal = $this->exec("journalctl -u {$service} --no-pager -n 10 2>&1");
            $output .= "\n--- journal ---\n" . $journal;
        }

        return [
            'success' => $ok,
            'message' => $ok ? "{$label} reinstalled and running" : "{$label} reinstalled but failed to start: {$status}",
            'output' => $output,
        ];
    }

    /**
     * Find and replace unsubstituted {{VARIABLE}} placeholders in a config file.
     */
    private function fixConfigVariables(array $params, array $variables): array
    {
        $file = $params['file'] ?? '';

        if (!$file || !preg_match('#^/[a-zA-Z0-9_./-]+$#', $file)) {
            return ['success' => false, 'message' => "Invalid file path: {$file}"];
        }

        $exists = $this->exec("test -f {$file} && echo 'OK' || echo 'MISSING'");
        if (strpos($exists, 'MISSING') !== false) {
            return ['success' => false, 'message' => "File not found: {$file}"];
        }

        // Find all {{VAR}} placeholders in the file
        $raw = $this->exec("grep -oP '\\{\\{[A-Z_]+\\}\\}' {$file} 2>/dev/null | sort -u");
        $placeholders = array_filter(array_map('trim', explode("\n", $raw)));

        if (empty($placeholders)) {
            return ['success' => true, 'message' => "No unsubstituted variables found in {$file}", 'output' => ''];
        }

        // Build sed replacements for each placeholder
        $output = "Found placeholders: " . implode(', ', $placeholders) . "\n";
        $sedParts = [];
        $resolved = 0;
        $unresolved = [];

        foreach ($placeholders as $placeholder) {
            $varName = trim($placeholder, '{}');
            $value = $variables[$varName] ?? null;

            // Try common aliases if exact name not found
            if ($value === null) {
                $aliases = [
                    'MAIL_DB_PASSWORD' => 'MAIL_DB_PASS',
                    'PANEL_DB_PASSWORD' => 'PANEL_DB_PASS',
                    'EMAIL_DB_PASSWORD' => 'EMAIL_DB_PASS',
                    'DB_ROOT_PASSWORD' => 'DB_ROOT_PASS',
                    'REDIS_PASSWORD' => 'REDIS_PASS',
                    'ADMIN_PASSWORD' => 'ADMIN_PASS',
                ];
                $aliasKey = $aliases[$varName] ?? null;
                if ($aliasKey && isset($variables[$aliasKey])) {
                    $value = $variables[$aliasKey];
                }
            }

            if ($value !== null) {
                $escapedValue = str_replace(['|', '&'], ['\\|', '\\&'], $value);
                // Use [{}] character classes so sed treats braces as literals
                $sedPattern = str_replace(['{', '}'], ['[{]', '[}]'], $placeholder);
                $sedParts[] = "s|{$sedPattern}|{$escapedValue}|g";
                $output .= "  {$placeholder} -> {$value}\n";
                $resolved++;
            } else {
                $unresolved[] = $varName;
                $output .= "  {$placeholder} -> [NO VALUE - skipped]\n";
            }
        }

        if (empty($sedParts)) {
            return [
                'success' => false,
                'message' => "No variable values available for: " . implode(', ', $unresolved),
                'output' => $output,
            ];
        }

        // Backup and apply
        $this->exec("cp {$file} {$file}.bak.$(date +%Y%m%d%H%M%S)");
        $sedCmd = "sed -i '" . implode('; ', $sedParts) . "' {$file} 2>&1";
        $sedResult = $this->exec($sedCmd);
        $output .= $sedResult ? "sed output: {$sedResult}\n" : '';

        // Verify no placeholders remain
        $remaining = $this->exec("grep -c '{{' {$file} 2>/dev/null || echo '0'");
        $allFixed = intval(trim($remaining)) === 0;
        $output .= "Remaining placeholders: {$remaining}\n";
        $output .= "Resolved: {$resolved}, Unresolved: " . count($unresolved);

        return [
            'success' => $allFixed && $resolved > 0,
            'message' => $allFixed
                ? "{$resolved} variable(s) substituted in {$file}"
                : "{$resolved} fixed, " . count($unresolved) . " unresolved in {$file}",
            'output' => $output,
        ];
    }

    private function fixInstallPackage(array $params): array
    {
        $package = $params['package'] ?? '';
        if (!$package || !preg_match('/^[a-z0-9._-]+$/i', $package)) {
            return ['success' => false, 'message' => "Invalid package name: {$package}"];
        }

        $output = $this->exec("DEBIAN_FRONTEND=noninteractive apt-get install -y {$package} 2>&1");
        $check = $this->exec("dpkg -l {$package} 2>/dev/null | grep -c '^ii' || echo '0'");
        $ok = $check !== '0';

        return [
            'success' => $ok,
            'message' => $ok ? "Package '{$package}' installed" : "Failed to install '{$package}'",
            'output' => $output,
        ];
    }

    private function fixDatabaseUser(array $params, array $variables): array
    {
        $dbName = $params['db_name'] ?? '';
        $dbUser = $params['db_user'] ?? '';
        $rootPass = $variables['DB_ROOT_PASS'];

        if ($dbUser === $variables['PANEL_DB_USER']) {
            $dbPass = $variables['PANEL_DB_PASS'];
        } elseif ($dbUser === $variables['MAIL_DB_USER']) {
            $dbPass = $variables['MAIL_DB_PASS'];
        } else {
            return ['success' => false, 'message' => "Unknown database user: {$dbUser}"];
        }

        // Escape password for MySQL SQL string literals (double single-quotes and backslashes)
        $escapedPass = str_replace(['\\', "'"], ['\\\\', "\\'"], $dbPass);

        $steps = [
            'create_user' => "CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$escapedPass}';",
            'alter_user'  => "ALTER USER '{$dbUser}'@'localhost' IDENTIFIED BY '{$escapedPass}';",
            'grant'       => "GRANT ALL PRIVILEGES ON {$dbName}.* TO '{$dbUser}'@'localhost';",
            'flush'       => "FLUSH PRIVILEGES;",
        ];

        // First verify root access works
        $rootTest = $this->mysqlExec('root', $rootPass, 'SELECT 1;');
        $output = "[root_test] {$rootTest}\n";

        foreach ($steps as $stepName => $sql) {
            $result = $this->mysqlExec('root', $rootPass, $sql);
            $hasError = strpos($result, 'ERROR') !== false;
            $output .= "[{$stepName}] " . ($result ?: 'OK') . "\n";
        }

        $ok = $this->mysqlCanConnect($dbUser, $dbPass, $dbName);
        $output .= "[verify] " . ($ok ? 'OK' : 'FAIL - Access denied') . "\n";

        return [
            'success' => $ok,
            'message' => $ok ? "User '{$dbUser}' can now access '{$dbName}'" : "DB user fix failed (see output for details)",
            'output' => $output,
        ];
    }

    private function fixCreateDatabase(array $params, array $variables): array
    {
        $dbName = $params['db_name'] ?? '';
        $rootPass = $variables['DB_ROOT_PASS'];

        if (!$dbName) {
            return ['success' => false, 'message' => 'No database name specified'];
        }

        $output = $this->mysqlExec('root', $rootPass, "CREATE DATABASE IF NOT EXISTS {$dbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        $check = $this->mysqlExec('root', $rootPass, "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='{$dbName}';");
        $ok = strpos($check, $dbName) !== false && strpos($check, 'ERROR') === false;

        return [
            'success' => $ok,
            'message' => $ok ? "Database '{$dbName}' created" : "Failed to create database '{$dbName}'",
            'output' => $output,
        ];
    }

    private function fixRenewSSL(array $params): array
    {
        $domain = $params['domain'] ?? '';
        if (!$domain || !preg_match('/^[a-z0-9.-]+$/i', $domain)) {
            return ['success' => false, 'message' => "Invalid domain: {$domain}"];
        }

        $output = $this->exec("certbot certonly --webroot -w /var/www/html -d {$domain} --non-interactive --agree-tos --register-unsafely-without-email 2>&1 || certbot certonly --standalone -d {$domain} --non-interactive --agree-tos --register-unsafely-without-email 2>&1");
        $check = $this->exec("test -f /etc/letsencrypt/live/{$domain}/fullchain.pem && echo 'OK' || echo 'FAIL'");
        $ok = strpos($check, 'OK') !== false;

        return [
            'success' => $ok,
            'message' => $ok ? "SSL certificate obtained for {$domain}" : "Failed to obtain SSL for {$domain}",
            'output' => $output,
        ];
    }

    private function fixOpenPort(array $params): array
    {
        $port = $params['port'] ?? '';
        if (!$port || !preg_match('/^\d+\/tcp$/', $port)) {
            return ['success' => false, 'message' => "Invalid port: {$port}"];
        }

        $output = $this->exec("firewall-cmd --permanent --add-port={$port} 2>&1 && firewall-cmd --reload 2>&1");
        $check = $this->exec("firewall-cmd --list-ports 2>/dev/null");
        $ok = strpos($check, $port) !== false;

        return [
            'success' => $ok,
            'message' => $ok ? "Port {$port} opened in firewall" : "Failed to open port {$port}",
            'output' => $output,
        ];
    }

    private function connectToServer(array $server): bool
    {
        try {
            return $this->ssh->connectToServer($server);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function exec(string $command): string
    {
        $result = $this->ssh->exec($command);
        return trim($result['output'] ?? '');
    }

    /**
     * Write an entry to the audit_logs table.
     */
    public function log(
        string $action,
        ?string $userId = null,
        ?int $serverId = null,
        ?string $target = null,
        string $outcome = 'success',
        array $details = []
    ): void {
        try {
            $validOutcomes = ['success', 'failed'];
            $dbOutcome = in_array($outcome, $validOutcomes) ? $outcome : 'success';

            $stmt = $this->db->prepare(
                "INSERT INTO audit_logs (user_id, server_id, action, target, details, ip_address, outcome)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $userId !== null ? (int)$userId : null,
                $serverId,
                $action,
                $target,
                !empty($details) ? json_encode($details) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $dbOutcome,
            ]);
        } catch (\Throwable $e) {
            // Non-fatal: never break the request because of audit logging
        }
    }

    // =========================================================================
    //  Audit Checks
    // =========================================================================

    private function checkServices(array &$results): void
    {
        foreach (self::REQUIRED_SERVICES as $svc => $label) {
            $out = trim($this->exec("systemctl is-active {$svc} 2>/dev/null")) ?: 'inactive';
            $running = in_array($out, ['active', 'exited']);
            if ($running) {
                $this->addCheck($results, 'services', $label, 'pass', "systemd status: {$out}");
            } else {
                $fixAction = 'restart_service';
                $fixParams = ['service' => $svc];
                $detail = "systemd status: {$out}";

                // Check if the binary is actually missing (needs reinstall, not just restart).
                // For OLS: directly check the binary path instead of relying on journal text.
                if (isset(self::SERVICE_PACKAGES[$svc])) {
                    $binaryMap = [
                        'lshttpd'      => '/usr/local/lsws/bin/litespeed',
                        'postfix'      => '/usr/sbin/postfix',
                        'dovecot'      => '/usr/sbin/dovecot',
                        'fail2ban'     => '/usr/bin/fail2ban-server',
                        'redis-server' => '/usr/bin/redis-server',
                    ];
                    $binaryPath = $binaryMap[$svc] ?? null;
                    if ($binaryPath) {
                        $binCheck = $this->exec("test -f {$binaryPath} -o -L {$binaryPath} && echo 'ok' || echo 'missing'");
                        if (strpos($binCheck, 'missing') !== false) {
                            $fixAction = 'reinstall_service';
                            $detail = "Binary missing - needs reinstall";
                        }
                    }
                }

                $this->addCheck($results, 'services', $label, 'fail', $detail, $fixAction, $fixParams);
            }
        }

        $checkedLabels = [];
        foreach (self::OPTIONAL_SERVICES as $svc => $label) {
            // Skip duplicate labels (spamd + spamassassin both map to 'SpamAssassin')
            if (isset($checkedLabels[$label])) continue;

            $unitExists = $this->exec("systemctl cat {$svc} > /dev/null 2>&1 && echo 'found' || echo 'missing'");
            if ($unitExists === 'missing') {
                // For SpamAssassin: try the alternative service name before reporting missing
                if ($svc === 'spamd') {
                    $altCheck = $this->exec("systemctl cat spamassassin > /dev/null 2>&1 && echo 'found' || echo 'missing'");
                    if ($altCheck === 'found') {
                        $svc = 'spamassassin';
                        $unitExists = 'found';
                    }
                } elseif ($svc === 'spamassassin') {
                    $altCheck = $this->exec("systemctl cat spamd > /dev/null 2>&1 && echo 'found' || echo 'missing'");
                    if ($altCheck === 'found') {
                        $svc = 'spamd';
                        $unitExists = 'found';
                    }
                }

                if ($unitExists === 'missing') {
                    $this->addCheck($results, 'services', $label, 'warning', 'Not installed');
                    $checkedLabels[$label] = true;
                    continue;
                }
            }
            $checkedLabels[$label] = true;
            $out = trim($this->exec("systemctl is-active {$svc} 2>/dev/null")) ?: 'inactive';
            $running = in_array($out, ['active', 'activating', 'exited']);
            $this->addCheck($results, 'services', $label, $running ? 'pass' : 'warning', "systemd status: {$out}", 'restart_service', ['service' => $svc]);
        }
    }

    private function checkPackages(array &$results): void
    {
        foreach (self::CRITICAL_PACKAGES as $pkg) {
            $out = $this->exec("dpkg -l {$pkg} 2>/dev/null | grep -c '^ii' || echo '0'");
            $installed = $out !== '0';
            $this->addCheck($results, 'packages', "Package '{$pkg}'", $installed ? 'pass' : 'fail', $installed ? 'Installed' : 'Missing', 'install_package', ['package' => $pkg]);
        }
    }

    private function checkDatabases(array &$results, array $vars): void
    {
        $rootPass = $vars['DB_ROOT_PASS'];
        $databases = [
            $vars['PANEL_DB_NAME'] => ['user' => $vars['PANEL_DB_USER'], 'pass' => $vars['PANEL_DB_PASS']],
            $vars['MAIL_DB_NAME']  => ['user' => $vars['MAIL_DB_USER'], 'pass' => $vars['MAIL_DB_PASS']],
        ];

        foreach ($databases as $dbName => $cred) {
            $check = $this->mysqlExec('root', $rootPass, "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='{$dbName}';");
            $exists = strpos($check, $dbName) !== false && strpos($check, 'ERROR') === false;
            $this->addCheck($results, 'database', "Database '{$dbName}' exists", $exists ? 'pass' : 'fail', $exists ? 'Found' : 'Not found', 'create_database', ['db_name' => $dbName]);

            if ($exists) {
                $tableCount = trim($this->mysqlExec('root', $rootPass, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$dbName}';"));
                $lines = explode("\n", $tableCount);
                $count = end($lines);
                $hasTables = intval($count) > 0;
                $this->addCheck($results, 'database', "Database '{$dbName}' has tables", $hasTables ? 'pass' : 'fail', "{$count} table(s)");
            }

            $canConnect = $this->mysqlCanConnect($cred['user'], $cred['pass'], $dbName);
            $this->addCheck($results, 'database', "User '{$cred['user']}' connects to '{$dbName}'", $canConnect ? 'pass' : 'fail', $canConnect ? 'OK' : 'Access denied', 'fix_db_user', ['db_name' => $dbName, 'db_user' => $cred['user']]);
        }
    }

    private function checkFilesystem(array &$results): void
    {
        foreach (self::REQUIRED_PATHS as $path => $label) {
            $check = $this->exec("test -e {$path} && echo 'EXISTS' || echo 'MISSING'");
            $exists = strpos($check, 'EXISTS') !== false;
            $this->addCheck($results, 'filesystem', $label, $exists ? 'pass' : 'fail', $path);
        }
    }

    private function checkSSL(array &$results, array $vars): void
    {
        $domains = [$vars['PANEL_DOMAIN'], $vars['EMAIL_DOMAIN']];
        $mailDomain = $vars['MAIL_DOMAIN'] ?? '';
        if (!empty($mailDomain) && !in_array($mailDomain, $domains)) {
            $domains[] = $mailDomain;
        }

        foreach ($domains as $domain) {
            $certPath = "/etc/letsencrypt/live/{$domain}/fullchain.pem";
            $hasCert = strpos($this->exec("test -f {$certPath} && echo 'EXISTS' || echo 'MISSING'"), 'EXISTS') !== false;

            if ($hasCert) {
                $expiry = $this->exec("openssl x509 -enddate -noout -in {$certPath} 2>/dev/null");
                $issuer = $this->exec("openssl x509 -issuer -noout -in {$certPath} 2>/dev/null");
                $isSelfSigned = strpos($issuer, 'O = Fleet Manager') !== false;
                $status = $isSelfSigned ? 'warning' : 'pass';
                $this->addCheck($results, 'ssl', "SSL cert for {$domain}", $status, $isSelfSigned ? 'Self-signed (needs Let\'s Encrypt)' : $expiry, 'renew_ssl', ['domain' => $domain]);
            } else {
                $altPath = "/etc/letsencrypt/live/" . $domains[0] . "/fullchain.pem";
                $san = $this->exec("openssl x509 -text -noout -in {$altPath} 2>/dev/null | grep -o 'DNS:{$domain}'");
                $inSan = strpos($san, $domain) !== false;
                if ($inSan) {
                    $this->addCheck($results, 'ssl', "SSL cert for {$domain}", 'pass', 'Covered by combined certificate');
                } else {
                    $this->addCheck($results, 'ssl', "SSL cert for {$domain}", 'fail', "No certificate found at {$certPath}", 'renew_ssl', ['domain' => $domain]);
                }
            }
        }
    }

    private function checkHTTP(array &$results, array $vars): void
    {
        $domains = [$vars['PANEL_DOMAIN'], $vars['EMAIL_DOMAIN']];
        foreach ($domains as $domain) {
            $httpCode = $this->exec("curl -sSk -o /dev/null -w '%{http_code}' --connect-timeout 5 https://{$domain}/ 2>/dev/null || echo '000'");
            $isOk = in_array($httpCode, ['200', '301', '302', '403']);
            $status = $isOk ? 'pass' : ($httpCode === '000' ? 'fail' : 'warning');
            $this->addCheck($results, 'http', "HTTPS response for {$domain}", $status, "HTTP {$httpCode}");
        }
    }

    private function checkFirewall(array &$results): void
    {
        $fwActive = $this->exec("systemctl is-active firewalld 2>/dev/null || echo 'inactive'");
        if ($fwActive !== 'active') {
            $this->addCheck($results, 'firewall', 'FirewallD active', 'fail', "Status: {$fwActive}", 'restart_service', ['service' => 'firewalld']);
            return;
        }

        $openPorts = $this->exec("firewall-cmd --list-ports 2>/dev/null");
        $openServices = $this->exec("firewall-cmd --list-services 2>/dev/null");

        foreach (self::FIREWALL_REQUIRED_PORTS as $port => $label) {
            $portNum = explode('/', $port)[0];
            $found = (strpos($openPorts, $port) !== false)
                  || (strpos($openPorts, $portNum) !== false);

            if (!$found) {
                $serviceMap = ['22/tcp' => 'ssh', '25/tcp' => 'smtp', '80/tcp' => 'http', '443/tcp' => 'https', '993/tcp' => 'imaps', '143/tcp' => 'imap', '465/tcp' => 'smtps'];
                if (isset($serviceMap[$port]) && strpos($openServices, $serviceMap[$port]) !== false) {
                    $found = true;
                }
            }

            $this->addCheck($results, 'firewall', "Port {$port} ({$label})", $found ? 'pass' : 'warning', $found ? 'Open' : 'Not found in firewall rules', 'open_port', ['port' => $port]);
        }
    }

    private function checkPanelAdmin(array &$results, array $vars): void
    {
        $rootPass = $vars['DB_ROOT_PASS'];
        $panelDb = $vars['PANEL_DB_NAME'];
        $result = $this->mysqlExec('root', $rootPass, "SELECT COUNT(*) FROM admin_users WHERE username='pxradmin';", $panelDb);
        $lines = explode("\n", trim($result));
        $count = end($lines);
        $hasAdmin = intval($count) > 0;
        $this->addCheck($results, 'application', 'Panel admin user exists', $hasAdmin ? 'pass' : 'fail', $hasAdmin ? 'Found' : 'Missing');
    }

    private function checkAgents(array &$results): void
    {
        $panelAgent = $this->exec("systemctl is-active vpsadmin-agent 2>/dev/null || echo 'inactive'");
        $panelOk = $panelAgent === 'active';
        $this->addCheck($results, 'agent', 'Panel Agent running', $panelOk ? 'pass' : 'warning', "systemd: {$panelAgent}", 'restart_service', ['service' => 'vpsadmin-agent']);

        $fleetAgent = $this->exec("systemctl is-active fleet-agent 2>/dev/null || echo 'inactive'");
        $fleetOk = $fleetAgent === 'active';
        $this->addCheck($results, 'agent', 'Fleet Agent running', $fleetOk ? 'pass' : 'warning', "systemd: {$fleetAgent}", 'restart_service', ['service' => 'fleet-agent']);
    }

    private function checkPostfixConfig(array &$results, array $vars): void
    {
        $mailDomain = $vars['MAIL_DOMAIN'] ?? '';
        if (empty($mailDomain)) return;

        $hostname = $this->exec("postconf -h myhostname 2>/dev/null");
        $hasHostname = !empty($hostname);
        $this->addCheck($results, 'security', 'Postfix myhostname set', $hasHostname ? 'pass' : 'warning', $hostname ?: 'Not configured');

        $mysqlMaps = $this->exec("postconf -h virtual_mailbox_maps 2>/dev/null");
        $hasMysql = strpos($mysqlMaps, 'mysql:') !== false;
        $this->addCheck($results, 'security', 'Postfix MySQL maps configured', $hasMysql ? 'pass' : 'fail', $hasMysql ? 'Active' : 'Missing virtual_mailbox_maps');
    }

    private function checkDovecotConfig(array &$results, array $vars): void
    {
        $dovecotSql = $this->exec("test -f /etc/dovecot/dovecot-sql.conf.ext && echo 'EXISTS' || echo 'MISSING'");
        $hasSql = strpos($dovecotSql, 'EXISTS') !== false;
        $this->addCheck($results, 'security', 'Dovecot SQL config exists', $hasSql ? 'pass' : 'fail', '/etc/dovecot/dovecot-sql.conf.ext');

        $sslCert = $this->exec("doveconf -n ssl_cert 2>/dev/null | head -1");
        $hasSsl = !empty($sslCert) && strpos($sslCert, '<') !== false;
        $this->addCheck($results, 'security', 'Dovecot SSL configured', $hasSsl ? 'pass' : 'warning', $sslCert ?: 'Not set');
    }

    private function checkConfigVariables(array &$results): void
    {
        foreach (self::CONFIG_FILES_TO_SCAN as $file => $label) {
            $exists = $this->exec("test -f {$file} && echo 'OK' || echo 'MISSING'");
            if (strpos($exists, 'MISSING') !== false) continue;

            $count = $this->exec("grep -cP '\\{\\{[A-Z_]+\\}\\}' {$file} 2>/dev/null || echo '0'");
            $unsubstituted = intval(trim($count));
            if ($unsubstituted > 0) {
                $vars = $this->exec("grep -oP '\\{\\{[A-Z_]+\\}\\}' {$file} 2>/dev/null | sort -u | head -5");
                $this->addCheck(
                    $results, 'config', "{$label} has unsubstituted variables",
                    'fail', "{$unsubstituted} placeholder(s): {$vars}",
                    'fix_config_vars', ['file' => $file]
                );
            }
        }
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    /**
     * Run a MySQL command safely via temp files to avoid all shell escaping issues.
     * Both the SQL and the credentials are written via base64 so no shell
     * interpretation of special characters can occur.
     */
    private function mysqlExec(string $user, string $password, string $sql, ?string $database = null): string
    {
        $uid = bin2hex(random_bytes(4));
        $cnfFile = "/tmp/fleet_{$uid}.cnf";
        $sqlFile = "/tmp/fleet_{$uid}.sql";
        $dbArg = $database ? " {$database}" : '';

        $cnf = "[client]\nuser={$user}\npassword={$password}\n";
        $b64Cnf = base64_encode($cnf);
        $b64Sql = base64_encode($sql);

        $this->exec("echo '{$b64Cnf}' | base64 -d > {$cnfFile} && chmod 600 {$cnfFile}");
        $this->exec("echo '{$b64Sql}' | base64 -d > {$sqlFile}");

        $raw = $this->exec("mysql --defaults-extra-file={$cnfFile}{$dbArg} < {$sqlFile} 2>&1; echo \"EXIT_CODE:\$?\"");

        $this->exec("rm -f {$cnfFile} {$sqlFile}");

        // Extract exit code and return clean output
        $this->lastMysqlOk = strpos($raw, 'EXIT_CODE:0') !== false;
        $clean = trim(preg_replace('/\n?EXIT_CODE:\d+\s*$/', '', $raw));

        return $clean;
    }

    /**
     * Test if a MySQL user can connect (returns true/false).
     */
    private function mysqlCanConnect(string $user, string $password, string $database): bool
    {
        $this->mysqlExec($user, $password, 'SELECT 1;', $database);
        return $this->lastMysqlOk;
    }

    private function addCheck(array &$results, string $category, string $name, string $status, string $detail, ?string $fixAction = null, ?array $fixParams = null): void
    {
        $check = [
            'category' => $category,
            'name' => $name,
            'status' => $status,
            'detail' => $detail,
        ];
        if ($fixAction && $status !== 'pass') {
            $check['fix_action'] = $fixAction;
            if ($fixParams) $check['fix_params'] = $fixParams;
        }
        $results['checks'][] = $check;
        if ($status === 'pass') $results['passed']++;
        elseif ($status === 'fail') $results['failed']++;
        else $results['warnings']++;
    }

    private function updateVersionsFromServer(int $serverId): void
    {
        try {
            $panelVersion = $this->exec('cat /var/www/vps-admin/VERSION 2>/dev/null');
            $emailVersion = $this->exec('cat /var/www/vps-email/VERSION 2>/dev/null');
            $agentVersion = $this->exec('cat /opt/fleet-agent/VERSION 2>/dev/null');

            $updates = [];
            $params = [];

            if (!empty($panelVersion)) {
                $updates[] = 'panel_version = ?';
                $params[] = $panelVersion;
            }
            if (!empty($emailVersion)) {
                $updates[] = 'email_app_version = ?';
                $params[] = $emailVersion;
            }
            if (!empty($agentVersion)) {
                $updates[] = 'agent_version = ?';
                $params[] = $agentVersion;
            }

            if (!empty($updates)) {
                $params[] = $serverId;
                $this->db->prepare(
                    "UPDATE servers SET " . implode(', ', $updates) . " WHERE id = ?"
                )->execute($params);
            }
        } catch (\Exception $e) {
            // Non-fatal
        }
    }

    private function storeResults(int $serverId, array $results): void
    {
        $auditJson = json_encode($results, JSON_PRETTY_PRINT);

        // Store in server_credentials for quick access from ServerDetailView
        $stmt = $this->db->prepare(
            "INSERT INTO server_credentials (server_id, category, credential_key, label, value_encrypted, is_secret)
             VALUES (?, 'audit', 'LAST_AUDIT', 'Last Deployment Audit', ?, 0)
             ON DUPLICATE KEY UPDATE value_encrypted = VALUES(value_encrypted), updated_at = NOW()"
        );
        $stmt->execute([$serverId, $this->encryption->encrypt($auditJson)]);

        // Update server last_error if failures
        if ($results['failed'] > 0) {
            $failedNames = array_map(
                fn($c) => $c['name'],
                array_filter($results['checks'], fn($c) => $c['status'] === 'fail')
            );
            $this->db->prepare(
                "UPDATE servers SET last_error = ? WHERE id = ?"
            )->execute([
                "Audit: {$results['failed']} check(s) failed - " . implode(', ', $failedNames),
                $serverId,
            ]);
        } else {
            $this->db->prepare(
                "UPDATE servers SET last_error = NULL WHERE id = ?"
            )->execute([$serverId]);
        }
    }
}
