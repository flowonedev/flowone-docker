<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;

/**
 * Docker-aware Deployment Audit.
 *
 * The counterpart of AuditService for boxes provisioned via the Docker path
 * (servers.deployed_image_tag set). On these hybrid boxes the stack is split:
 *
 *   NATIVE HOST TIER : OpenLiteSpeed front (panel + email reverse-proxy),
 *                      DevCon Panel, PowerDNS, firewalld, fail2ban, agents
 *   CONTAINER TIER   : mariadb, redis, meilisearch, web, collab, mailsync
 *                      + the host-net `mail` pod (Postfix/Dovecot/DKIM/Rspamd/
 *                      SpamAssassin/ClamAV under supervisord)
 *
 * The native audit's systemctl/socket checks are meaningless for the container
 * tier (that's what produced walls of false FAILs), so this service checks:
 *   - containers via `docker compose ps --format json` (state + healthcheck)
 *   - mail services INSIDE the mail pod via `supervisorctl status`
 *   - databases over TCP to the container (127.0.0.1:3306), grants are @'%'
 *   - the native front tier via systemd exactly like the native audit
 *
 * Output shape is identical to AuditService (categories/checks/fix actions),
 * so the dashboard Audit tab works unchanged. AuditService::run()/fix()
 * delegate here automatically for Docker boxes.
 */
class DockerAuditService
{
    private Container $container;
    private SSHService $ssh;
    private EncryptionService $encryption;
    private \PDO $db;
    private bool $lastMysqlOk = false;

    /** Native systemd units that must run on the hybrid host. */
    private const NATIVE_REQUIRED = [
        'docker'    => 'Docker Engine',
        'lshttpd'   => 'OpenLiteSpeed (native front)',
        'firewalld' => 'FirewallD',
        'fail2ban'  => 'Fail2ban',
    ];

    /** Native units that may legitimately be absent (warn, don't fail). */
    private const NATIVE_OPTIONAL = [
        'pdns' => 'PowerDNS',
    ];

    /** Compose services (bridge tier + host-net mail pod). */
    private const CONTAINERS = [
        'mariadb'     => 'MariaDB (container)',
        'redis'       => 'Redis (container)',
        'meilisearch' => 'Meilisearch (container)',
        'web'         => 'Email App Web (container)',
        'collab'      => 'Collab Server (container)',
        'mailsync'    => 'MailSync Server (container)',
        'mail'        => 'Mail Pod (container)',
    ];

    /** supervisord programs inside the mail pod. required => fail when down. */
    private const MAIL_PROGRAMS = [
        'postfix'        => ['label' => 'Postfix (in mail pod)',        'required' => true],
        'dovecot'        => ['label' => 'Dovecot (in mail pod)',        'required' => true],
        'opendkim'       => ['label' => 'OpenDKIM (in mail pod)',       'required' => false],
        'opendmarc'      => ['label' => 'OpenDMARC (in mail pod)',      'required' => false],
        'rspamd'         => ['label' => 'Rspamd (in mail pod)',         'required' => false],
        'spamd'          => ['label' => 'SpamAssassin (in mail pod)',   'required' => false],
        'clamd'          => ['label' => 'ClamAV (in mail pod)',         'required' => false],
        'unbound'        => ['label' => 'Unbound DNS (in mail pod)',    'required' => false],
    ];

    /** Host paths that must exist on the hybrid box. */
    private const REQUIRED_PATHS = [
        '/opt/flowone/docker-compose.yml'        => 'Docker compose file',
        '/opt/flowone/.env'                      => 'Docker stack .env',
        '/var/www/vps-admin'                     => 'Panel web root (native)',
        '/var/www/vps-admin/api'                 => 'Panel API directory (native)',
        '/usr/local/lsws/conf/httpd_config.conf' => 'OLS main config',
        '/usr/local/lsws/conf/vhosts'            => 'OLS vhosts directory',
    ];

    /** Host config files scanned for unsubstituted {{VARS}}. */
    private const CONFIG_FILES_TO_SCAN = [
        '/usr/local/lsws/conf/httpd_config.conf' => 'OLS main config',
        '/var/www/vps-admin/api/.env'            => 'Panel API .env',
    ];

    private const FIREWALL_REQUIRED_PORTS = [
        '25/tcp'   => 'SMTP',
        '80/tcp'   => 'HTTP',
        '443/tcp'  => 'HTTPS',
        '587/tcp'  => 'SMTP Submission',
        '993/tcp'  => 'IMAPS',
        '4190/tcp' => 'ManageSieve',
    ];

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->ssh = $container->get(SSHService::class);
        $this->encryption = $container->get(EncryptionService::class);
        $this->db = $container->getDatabase();
    }

    // =========================================================================
    //  RUN
    // =========================================================================

    public function run(int $serverId): array
    {
        $totalStart = microtime(true);

        $stmt = $this->db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$server) {
            return ['success' => false, 'error' => 'Server not found'];
        }

        if (!$this->connect($server)) {
            return ['success' => false, 'error' => 'Cannot connect to server via SSH'];
        }

        $variables = $this->container->get(TemplateService::class)->generateServerVariables($server);

        $results = [
            'timestamp' => date('Y-m-d H:i:s'),
            'server_id' => $serverId,
            'mode'      => 'docker',
            'checks'    => [],
            'passed'    => 0,
            'failed'    => 0,
            'warnings'  => 0,
            'total'     => 0,
        ];

        $states = $this->composeStates();

        $this->checkNativeServices($results);
        $this->checkContainers($results, $states);
        $this->checkMailPod($results, $states);
        $this->checkDatabases($results, $variables);
        $this->checkFilesystem($results);
        $this->checkSSL($results, $variables);
        $this->checkHTTP($results, $variables);
        $this->checkLoopbackEndpoints($results, $states);
        $this->checkFirewall($results);
        $this->checkPanelAdmin($results, $variables);
        $this->checkAgents($results);
        $this->checkMailConfig($results, $states);
        $this->checkConfigVariables($results);

        $results['total'] = $results['passed'] + $results['failed'] + $results['warnings'];
        $results['overall'] = $results['failed'] === 0 ? 'pass' : 'fail';
        $results['duration_ms'] = (int) round((microtime(true) - $totalStart) * 1000);

        $this->updateVersionsFromServer($serverId, $server);
        $this->storeResults($serverId, $results);
        $this->ssh->disconnect();

        return ['success' => true, 'audit' => $results];
    }

    // =========================================================================
    //  FIX
    // =========================================================================

    public function fix(int $serverId, string $action, array $params = []): array
    {
        $stmt = $this->db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$server) {
            return ['success' => false, 'error' => 'Server not found'];
        }
        if (!$this->connect($server)) {
            return ['success' => false, 'error' => 'Cannot connect to server via SSH'];
        }

        $variables = $this->container->get(TemplateService::class)->generateServerVariables($server);

        try {
            $result = match ($action) {
                'restart_container'    => $this->fixRestartContainer($params),
                'restart_mail_program' => $this->fixRestartMailProgram($params),
                'restart_service'      => $this->fixRestartNativeService($params),
                'create_database'      => $this->fixCreateDatabase($params, $variables),
                'fix_db_user'          => $this->fixDatabaseUser($params, $variables),
                'renew_ssl'            => $this->fixRenewSSL($params),
                'open_port'            => $this->fixOpenPort($params),
                'fix_all'              => $this->fixAll($variables),
                default                => ['success' => false, 'message' => "Unknown fix action: {$action}"],
            };
        } catch (\Throwable $e) {
            $result = ['success' => false, 'message' => $e->getMessage()];
        }

        $this->ssh->disconnect();
        return $result;
    }

    private function fixAll(array $variables): array
    {
        $results = [];

        // Containers that aren't running/healthy -> compose restart (or up -d).
        $states = $this->composeStates();
        foreach (array_keys(self::CONTAINERS) as $svc) {
            $s = $states[$svc] ?? null;
            $running = $s && ($s['state'] ?? '') === 'running'
                && (($s['health'] ?? '') === '' || ($s['health'] ?? '') === 'healthy');
            if (!$running) {
                $results[] = $this->fixRestartContainer(['service' => $svc]);
            }
        }

        // Mail-pod programs that supervisord reports down.
        foreach ($this->mailProgramStates() as $prog => $state) {
            if (isset(self::MAIL_PROGRAMS[$prog]) && $state !== 'RUNNING' && $state !== 'STARTING') {
                $results[] = $this->fixRestartMailProgram(['program' => $prog]);
            }
        }

        // Native units.
        $native = array_merge(self::NATIVE_REQUIRED, self::NATIVE_OPTIONAL, [
            'vpsadmin-agent' => 'Panel Agent',
            'fleet-agent'    => 'Fleet Agent',
        ]);
        foreach ($native as $svc => $label) {
            $unit = $this->exec("systemctl cat {$svc} > /dev/null 2>&1 && echo found || echo missing");
            if ($unit === 'missing') continue;
            $out = trim($this->exec("systemctl is-active {$svc} 2>/dev/null")) ?: 'inactive';
            if (!in_array($out, ['active', 'exited', 'activating'])) {
                $results[] = $this->fixRestartNativeService(['service' => $svc]);
            }
        }

        // DB users over TCP.
        $databases = [
            $variables['PANEL_DB_NAME'] => ['user' => $variables['PANEL_DB_USER'], 'pass' => $variables['PANEL_DB_PASS']],
            $variables['MAIL_DB_NAME']  => ['user' => $variables['MAIL_DB_USER'], 'pass' => $variables['MAIL_DB_PASS']],
        ];
        foreach ($databases as $dbName => $cred) {
            if (!$this->mysqlCanConnect($cred['user'], $cred['pass'], $dbName)) {
                $results[] = $this->fixDatabaseUser(['db_name' => $dbName, 'db_user' => $cred['user']], $variables);
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

    private function fixRestartContainer(array $params): array
    {
        $service = $params['service'] ?? '';
        if (!isset(self::CONTAINERS[$service])) {
            return ['success' => false, 'message' => "Invalid container service: {$service}"];
        }
        $label = self::CONTAINERS[$service];

        // restart when it exists, `up -d` when it was never created.
        $output = $this->exec($this->compose() . " restart " . escapeshellarg($service) . " 2>&1"
            . " || " . $this->compose() . " up -d --no-deps " . escapeshellarg($service) . " 2>&1");

        // poll compose ps for the service state
        $ok = false; $state = 'unknown'; $health = '';
        for ($i = 0; $i < 6; $i++) {
            sleep(3);
            $states = $this->composeStates();
            $s = $states[$service] ?? null;
            $state = $s['state'] ?? 'missing';
            $health = $s['health'] ?? '';
            if ($state === 'running' && ($health === '' || $health === 'healthy')) { $ok = true; break; }
            if ($state === 'running' && $health === 'starting') continue;
        }
        // 'starting' healthcheck counts as progress, not failure.
        if (!$ok && $state === 'running' && $health === 'starting') {
            return ['success' => true, 'message' => "{$label} restarted (healthcheck still starting)", 'output' => $output];
        }

        if (!$ok) {
            $output .= "\n--- container logs ---\n"
                . $this->exec($this->compose() . " logs --tail=20 " . escapeshellarg($service) . " 2>&1");
        }

        return [
            'success' => $ok,
            'message' => $ok ? "{$label} restarted and healthy" : "{$label} still not healthy (state={$state}, health=" . ($health ?: 'n/a') . ")",
            'output' => $output,
        ];
    }

    private function fixRestartMailProgram(array $params): array
    {
        $program = $params['program'] ?? '';
        if (!isset(self::MAIL_PROGRAMS[$program])) {
            return ['success' => false, 'message' => "Invalid mail-pod program: {$program}"];
        }
        $label = self::MAIL_PROGRAMS[$program]['label'];

        $output = $this->exec($this->compose() . " exec -T mail supervisorctl restart " . escapeshellarg($program) . " 2>&1");
        sleep(3);
        $state = $this->mailProgramStates()[$program] ?? 'UNKNOWN';
        $ok = in_array($state, ['RUNNING', 'STARTING']);

        if (!$ok) {
            $output .= "\n--- supervisord tail ---\n"
                . $this->exec($this->compose() . " exec -T mail supervisorctl tail " . escapeshellarg($program) . " stderr 2>&1 | tail -15");
        }

        return [
            'success' => $ok,
            'message' => $ok ? "{$label} restarted" : "{$label} still {$state} after restart",
            'output' => $output,
        ];
    }

    private function fixRestartNativeService(array $params): array
    {
        $service = $params['service'] ?? '';
        $labels = array_merge(self::NATIVE_REQUIRED, self::NATIVE_OPTIONAL, [
            'vpsadmin-agent' => 'Panel Agent',
            'fleet-agent'    => 'Fleet Agent',
        ]);
        if (!$service || !isset($labels[$service])) {
            return ['success' => false, 'message' => "Invalid native service: {$service}"];
        }
        $label = $labels[$service];

        $unit = $this->exec("systemctl cat {$service} > /dev/null 2>&1 && echo found || echo missing");
        if ($unit === 'missing') {
            return ['success' => false, 'message' => "{$label} is not installed on this server"];
        }

        $output = $this->exec("systemctl restart {$service} 2>&1");
        $status = 'unknown';
        for ($i = 0; $i < 5; $i++) {
            sleep(2);
            $status = trim($this->exec("systemctl is-active {$service} 2>/dev/null")) ?: 'unknown';
            if (in_array($status, ['active', 'exited', 'failed', 'inactive'])) break;
        }
        $ok = in_array($status, ['active', 'exited']);
        if (!$ok) {
            $output .= "\n--- journal ---\n" . $this->exec("journalctl -u {$service} --no-pager -n 10 2>&1");
        }

        return [
            'success' => $ok,
            'message' => $ok ? "{$label} restarted" : "{$label} still failing: {$status}",
            'output' => $output,
        ];
    }

    private function fixCreateDatabase(array $params, array $variables): array
    {
        $dbName = $params['db_name'] ?? '';
        if (!$dbName) {
            return ['success' => false, 'message' => 'No database name specified'];
        }
        $rootPass = $variables['DB_ROOT_PASS'];
        $output = $this->mysqlExec('root', $rootPass, "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        $check = $this->mysqlExec('root', $rootPass, "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='{$dbName}';");
        $ok = strpos($check, $dbName) !== false && strpos($check, 'ERROR') === false;

        return [
            'success' => $ok,
            'message' => $ok ? "Database '{$dbName}' created (container DB)" : "Failed to create database '{$dbName}'",
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

        $esc = str_replace(['\\', "'"], ['\\\\', "\\'"], $dbPass);

        // Container DB: clients arrive over the docker bridge / host loopback,
        // so the grants must be @'%' (localhost would only match the container).
        $sql = "CREATE USER IF NOT EXISTS '{$dbUser}'@'%' IDENTIFIED BY '{$esc}';"
             . "ALTER USER '{$dbUser}'@'%' IDENTIFIED BY '{$esc}';"
             . "GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'%';"
             . "FLUSH PRIVILEGES;";

        $output = $this->mysqlExec('root', $rootPass, $sql);
        $ok = $this->mysqlCanConnect($dbUser, $dbPass, $dbName);

        return [
            'success' => $ok,
            'message' => $ok ? "User '{$dbUser}'@'%' can now access '{$dbName}'" : 'DB user fix failed (see output)',
            'output' => $output,
        ];
    }

    private function fixRenewSSL(array $params): array
    {
        $domain = $params['domain'] ?? '';
        if (!$domain || !preg_match('/^[a-z0-9.-]+$/i', $domain)) {
            return ['success' => false, 'message' => "Invalid domain: {$domain}"];
        }

        // Webroot via the native OLS docroot; fall back to standalone (stop OLS
        // for the handshake, then bring it back).
        $output = $this->exec(
            "certbot certonly --webroot -w /var/www/html -d {$domain} --non-interactive --agree-tos --register-unsafely-without-email 2>&1"
            . " || { systemctl stop lshttpd 2>/dev/null; certbot certonly --standalone -d {$domain} --non-interactive --agree-tos --register-unsafely-without-email 2>&1; systemctl start lshttpd 2>/dev/null; }"
        );
        $check = $this->exec("test -f /etc/letsencrypt/live/{$domain}/fullchain.pem && echo OK || echo FAIL");
        $ok = strpos($check, 'OK') !== false;
        if ($ok) {
            // Mail pod copies the cert at boot; flip it to the fresh cert.
            $this->exec($this->compose() . ' restart mail 2>&1 | tail -2');
            $this->exec('systemctl restart lshttpd 2>/dev/null || true');
        }

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

    // =========================================================================
    //  CHECKS
    // =========================================================================

    private function checkNativeServices(array &$results): void
    {
        foreach (self::NATIVE_REQUIRED as $svc => $label) {
            $out = trim($this->exec("systemctl is-active {$svc} 2>/dev/null")) ?: 'inactive';
            $running = in_array($out, ['active', 'exited']);
            $this->addCheck($results, 'services', $label, $running ? 'pass' : 'fail',
                "systemd: {$out}", 'restart_service', ['service' => $svc]);
        }

        foreach (self::NATIVE_OPTIONAL as $svc => $label) {
            $unit = $this->exec("systemctl cat {$svc} > /dev/null 2>&1 && echo found || echo missing");
            if ($unit === 'missing') {
                $this->addCheck($results, 'services', $label, 'warning', 'Not installed');
                continue;
            }
            $out = trim($this->exec("systemctl is-active {$svc} 2>/dev/null")) ?: 'inactive';
            $running = in_array($out, ['active', 'activating', 'exited']);
            $this->addCheck($results, 'services', $label, $running ? 'pass' : 'warning',
                "systemd: {$out}", 'restart_service', ['service' => $svc]);
        }
    }

    private function checkContainers(array &$results, array $states): void
    {
        if (empty($states)) {
            $this->addCheck($results, 'containers', 'Docker Compose stack', 'fail',
                'No containers found (compose ps returned nothing) - is the stack up?');
            return;
        }

        foreach (self::CONTAINERS as $svc => $label) {
            $s = $states[$svc] ?? null;
            if ($s === null) {
                $this->addCheck($results, 'containers', $label, 'fail', 'Container not created',
                    'restart_container', ['service' => $svc]);
                continue;
            }
            $state = $s['state'] ?? 'unknown';
            $health = $s['health'] ?? '';
            if ($state === 'running' && ($health === '' || $health === 'healthy')) {
                $this->addCheck($results, 'containers', $label, 'pass',
                    'running' . ($health ? " ({$health})" : ''));
            } elseif ($state === 'running' && $health === 'starting') {
                $this->addCheck($results, 'containers', $label, 'warning',
                    'running (healthcheck starting)', 'restart_container', ['service' => $svc]);
            } else {
                $this->addCheck($results, 'containers', $label, 'fail',
                    "state={$state}" . ($health ? ", health={$health}" : ''),
                    'restart_container', ['service' => $svc]);
            }
        }
    }

    private function checkMailPod(array &$results, array $states): void
    {
        $mail = $states['mail'] ?? null;
        if (!$mail || ($mail['state'] ?? '') !== 'running') {
            // Container-level failure is already reported by checkContainers.
            return;
        }

        $programs = $this->mailProgramStates();
        if (empty($programs)) {
            $this->addCheck($results, 'mail', 'Mail pod supervisord', 'warning',
                'Could not read supervisorctl status from the mail container');
            return;
        }

        foreach (self::MAIL_PROGRAMS as $prog => $meta) {
            if (!array_key_exists($prog, $programs)) {
                // Not listed = disabled by the entrypoint (e.g. ClamAV off on a
                // small box). That's a deliberate profile choice, not a fault.
                $this->addCheck($results, 'mail', $meta['label'], 'pass', 'Disabled by profile (not running by design)');
                continue;
            }
            $state = $programs[$prog];
            $up = in_array($state, ['RUNNING', 'STARTING']);
            if ($up) {
                $this->addCheck($results, 'mail', $meta['label'], 'pass', "supervisord: {$state}");
            } else {
                $this->addCheck($results, 'mail', $meta['label'],
                    $meta['required'] ? 'fail' : 'warning',
                    "supervisord: {$state}", 'restart_mail_program', ['program' => $prog]);
            }
        }
    }

    private function checkDatabases(array &$results, array $vars): void
    {
        $rootPass = $vars['DB_ROOT_PASS'];

        // Root reachability over TCP first - every other DB check depends on it.
        $rootOk = $this->mysqlCanConnect('root', $rootPass, 'mysql');
        $this->addCheck($results, 'database', 'Container DB reachable (root@127.0.0.1:3306)',
            $rootOk ? 'pass' : 'fail', $rootOk ? 'Connected over TCP' : 'Cannot connect - check the mariadb container and root@% grant');
        if (!$rootOk) return;

        $databases = [
            $vars['PANEL_DB_NAME'] => ['user' => $vars['PANEL_DB_USER'], 'pass' => $vars['PANEL_DB_PASS']],
            $vars['MAIL_DB_NAME']  => ['user' => $vars['MAIL_DB_USER'], 'pass' => $vars['MAIL_DB_PASS']],
        ];

        foreach ($databases as $dbName => $cred) {
            $check = $this->mysqlExec('root', $rootPass, "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='{$dbName}';");
            $exists = strpos($check, $dbName) !== false && strpos($check, 'ERROR') === false;
            $this->addCheck($results, 'database', "Database '{$dbName}' exists", $exists ? 'pass' : 'fail',
                $exists ? 'Found (container DB)' : 'Not found', 'create_database', ['db_name' => $dbName]);

            if ($exists) {
                $tableCount = trim($this->mysqlExec('root', $rootPass, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$dbName}';"));
                $lines = explode("\n", $tableCount);
                $count = end($lines);
                $hasTables = intval($count) > 0;
                $this->addCheck($results, 'database', "Database '{$dbName}' has tables", $hasTables ? 'pass' : 'fail', "{$count} table(s)");
            }

            $canConnect = $this->mysqlCanConnect($cred['user'], $cred['pass'], $dbName);
            $this->addCheck($results, 'database', "User '{$cred['user']}' connects to '{$dbName}'",
                $canConnect ? 'pass' : 'fail', $canConnect ? 'OK (TCP)' : 'Access denied',
                'fix_db_user', ['db_name' => $dbName, 'db_user' => $cred['user']]);
        }
    }

    private function checkFilesystem(array &$results): void
    {
        foreach (self::REQUIRED_PATHS as $path => $label) {
            $check = $this->exec("test -e {$path} && echo EXISTS || echo MISSING");
            $exists = strpos($check, 'EXISTS') !== false;
            $this->addCheck($results, 'filesystem', $label, $exists ? 'pass' : 'fail', $path);
        }

        // Critical named volumes: user mail + uploads + DB + JWT keys.
        $volumes = $this->exec("docker volume ls --format '{{.Name}}' 2>/dev/null");
        foreach (['flowone_mariadb_data' => 'MariaDB data volume',
                  'flowone_mail_vmail' => 'Mail storage volume',
                  'flowone_vps_email_files' => 'Email user-files volume',
                  'flowone_jwt_keys' => 'JWT keys volume'] as $vol => $label) {
            $exists = strpos($volumes, $vol) !== false;
            $this->addCheck($results, 'filesystem', $label, $exists ? 'pass' : 'fail', $vol);
        }
    }

    private function checkSSL(array &$results, array $vars): void
    {
        $domains = array_values(array_unique(array_filter([
            $vars['PANEL_DOMAIN'] ?? '',
            $vars['EMAIL_DOMAIN'] ?? '',
            $vars['MAIL_DOMAIN'] ?? '',
        ])));

        // All lineages actually present (a SAN cert may cover several hosts).
        $lineages = array_filter(array_map('trim', explode("\n",
            $this->exec("ls /etc/letsencrypt/live 2>/dev/null | grep -v README"))));

        $serverIp = trim($this->exec("hostname -I 2>/dev/null | awk '{print \$1}'"));

        foreach ($domains as $domain) {
            $certPath = "/etc/letsencrypt/live/{$domain}/fullchain.pem";
            $hasOwn = strpos($this->exec("test -f {$certPath} && echo EXISTS || echo MISSING"), 'EXISTS') !== false;

            // Let's Encrypt HTTP validation is impossible while the domain has
            // no public A record (typical for a bare apex whose registrar DNS
            // only carries the subdomains). Offering a renew_ssl action there
            // guarantees a failed Fix, so report the real blocker instead.
            $digProbe = trim($this->exec(
                "command -v dig >/dev/null 2>&1 && (dig +short {$domain} A @1.1.1.1 2>/dev/null | head -n1) || echo NODIG"
            ));
            $dnsBlocked = $digProbe === '';
            $dnsHint = "No public A record for {$domain} — Let's Encrypt cannot validate. "
                . "Add an A record ({$domain} -> {$serverIp}) at your DNS provider, then re-audit.";

            if ($hasOwn) {
                $expiry = $this->exec("openssl x509 -enddate -noout -in {$certPath} 2>/dev/null");
                $issuer = $this->exec("openssl x509 -issuer -noout -in {$certPath} 2>/dev/null");
                $selfSigned = stripos($issuer, 'Fleet Manager') !== false || stripos($issuer, 'self') !== false;
                if ($selfSigned && $dnsBlocked) {
                    $this->addCheck($results, 'ssl', "SSL cert for {$domain}", 'warning',
                        'Self-signed placeholder. ' . $dnsHint);
                } else {
                    $this->addCheck($results, 'ssl', "SSL cert for {$domain}", $selfSigned ? 'warning' : 'pass',
                        $selfSigned ? 'Self-signed (needs Let\'s Encrypt)' : $expiry, 'renew_ssl', ['domain' => $domain]);
                }
                continue;
            }

            // SAN coverage in any other lineage.
            $covered = false;
            foreach ($lineages as $lineage) {
                $san = $this->exec("openssl x509 -text -noout -in /etc/letsencrypt/live/{$lineage}/fullchain.pem 2>/dev/null | grep -o 'DNS:{$domain}'");
                if (strpos($san, $domain) !== false) { $covered = true; break; }
            }
            if ($covered) {
                $this->addCheck($results, 'ssl', "SSL cert for {$domain}", 'pass', 'Covered by combined (SAN) certificate');
            } elseif ($dnsBlocked) {
                $this->addCheck($results, 'ssl', "SSL cert for {$domain}", 'warning', $dnsHint);
            } else {
                $this->addCheck($results, 'ssl', "SSL cert for {$domain}", 'fail',
                    "No certificate found for {$domain}", 'renew_ssl', ['domain' => $domain]);
            }
        }
    }

    private function checkHTTP(array &$results, array $vars): void
    {
        foreach (array_unique(array_filter([$vars['PANEL_DOMAIN'] ?? '', $vars['EMAIL_DOMAIN'] ?? ''])) as $domain) {
            $httpCode = $this->exec("curl -sSk -o /dev/null -w '%{http_code}' --connect-timeout 5 https://{$domain}/ 2>/dev/null || echo 000");
            $isOk = in_array($httpCode, ['200', '301', '302', '403']);
            $status = $isOk ? 'pass' : ($httpCode === '000' ? 'fail' : 'warning');
            $this->addCheck($results, 'http', "HTTPS response for {$domain}", $status, "HTTP {$httpCode}");
        }
    }

    /**
     * The loopback contracts the native OLS front depends on: web container on
     * 127.0.0.1:8080, collab WS on :1234, mailsync WS on :1235. If these are
     * down, email.<domain> 503s even though the containers "run".
     */
    private function checkLoopbackEndpoints(array &$results, array $states): void
    {
        $webCode = $this->exec("curl -s -o /dev/null -w '%{http_code}' --max-time 10 http://127.0.0.1:8080/api/auth/me 2>/dev/null || echo 000");
        $webOk = in_array($webCode, ['200', '401']);
        $this->addCheck($results, 'http', 'Email web backend (127.0.0.1:8080)',
            $webOk ? 'pass' : 'fail', "HTTP {$webCode}" . ($webOk ? ' (app booted, DB answered)' : ''),
            'restart_container', ['service' => 'web']);

        $listening = $this->exec("ss -ltn 2>/dev/null | awk '{print \$4}'");
        foreach (['1234' => ['Collab WebSocket (127.0.0.1:1234)', 'collab'],
                  '1235' => ['MailSync WebSocket (127.0.0.1:1235)', 'mailsync']] as $port => [$label, $svc]) {
            $up = (bool) preg_match('/[:.]' . $port . '$/m', $listening);
            $this->addCheck($results, 'http', $label, $up ? 'pass' : 'fail',
                $up ? 'Listening' : 'Port not listening', 'restart_container', ['service' => $svc]);
        }
    }

    private function checkFirewall(array &$results): void
    {
        $fwActive = $this->exec("systemctl is-active firewalld 2>/dev/null || echo inactive");
        if ($fwActive !== 'active') {
            $this->addCheck($results, 'firewall', 'FirewallD active', 'fail', "Status: {$fwActive}",
                'restart_service', ['service' => 'firewalld']);
            return;
        }

        $openPorts = $this->exec("firewall-cmd --list-ports 2>/dev/null");
        $openServices = $this->exec("firewall-cmd --list-services 2>/dev/null");

        // SSH may have been moved by hardening (e.g. 1985). Hardening writes the
        // port into a sshd_config.d drop-in, so scan those too - last match wins,
        // same precedence sshd itself uses with Include at the top of the config.
        $sshPort = trim($this->exec("grep -hE '^\\s*Port\\s+[0-9]+' /etc/ssh/sshd_config /etc/ssh/sshd_config.d/*.conf 2>/dev/null | awk '{print \$2}' | tail -1")) ?: '22';
        $ports = ["{$sshPort}/tcp" => "SSH (port {$sshPort})"] + self::FIREWALL_REQUIRED_PORTS;

        foreach ($ports as $port => $label) {
            $portNum = explode('/', $port)[0];
            $found = strpos($openPorts, $port) !== false || strpos($openPorts, $portNum) !== false;
            if (!$found) {
                $serviceMap = ['22/tcp' => 'ssh', '25/tcp' => 'smtp', '80/tcp' => 'http', '443/tcp' => 'https', '993/tcp' => 'imaps'];
                if (isset($serviceMap[$port]) && strpos($openServices, $serviceMap[$port]) !== false) {
                    $found = true;
                }
            }
            $this->addCheck($results, 'firewall', "Port {$port} ({$label})", $found ? 'pass' : 'warning',
                $found ? 'Open' : 'Not found in firewall rules', 'open_port', ['port' => $port]);
        }
    }

    private function checkPanelAdmin(array &$results, array $vars): void
    {
        $result = $this->mysqlExec('root', $vars['DB_ROOT_PASS'],
            "SELECT COUNT(*) FROM admin_users WHERE username='pxradmin';", $vars['PANEL_DB_NAME']);
        $lines = explode("\n", trim($result));
        $hasAdmin = intval(end($lines)) > 0;
        $this->addCheck($results, 'application', 'Panel admin user exists', $hasAdmin ? 'pass' : 'fail',
            $hasAdmin ? 'Found' : 'Missing');
    }

    private function checkAgents(array &$results): void
    {
        foreach (['vpsadmin-agent' => 'Panel Agent running', 'fleet-agent' => 'Fleet Agent running'] as $svc => $label) {
            $out = $this->exec("systemctl is-active {$svc} 2>/dev/null || echo inactive");
            $ok = $out === 'active';
            $this->addCheck($results, 'agent', $label, $ok ? 'pass' : 'warning', "systemd: {$out}",
                'restart_service', ['service' => $svc]);
        }
    }

    /** Postfix/Dovecot config checks run INSIDE the mail pod. */
    private function checkMailConfig(array &$results, array $states): void
    {
        $mail = $states['mail'] ?? null;
        if (!$mail || ($mail['state'] ?? '') !== 'running') return;

        $execMail = fn(string $cmd) => $this->exec($this->compose() . " exec -T mail sh -c " . escapeshellarg($cmd) . " 2>/dev/null");

        $hostname = trim($execMail('postconf -h myhostname'));
        $this->addCheck($results, 'security', 'Postfix myhostname set (in pod)',
            $hostname !== '' ? 'pass' : 'warning', $hostname ?: 'Not configured');

        $maps = trim($execMail('postconf -h virtual_mailbox_maps'));
        $hasMysql = strpos($maps, 'mysql:') !== false;
        $this->addCheck($results, 'security', 'Postfix MySQL maps configured (in pod)',
            $hasMysql ? 'pass' : 'fail', $hasMysql ? 'Active' : 'Missing virtual_mailbox_maps');

        $sqlConf = trim($execMail('test -f /etc/dovecot/dovecot-sql.conf.ext && echo EXISTS || echo MISSING'));
        $this->addCheck($results, 'security', 'Dovecot SQL config exists (in pod)',
            $sqlConf === 'EXISTS' ? 'pass' : 'fail', '/etc/dovecot/dovecot-sql.conf.ext');

        $sslCert = trim($execMail('doveconf -n ssl_cert 2>/dev/null | head -1'));
        $hasSsl = $sslCert !== '' && strpos($sslCert, '<') !== false;
        $this->addCheck($results, 'security', 'Dovecot SSL configured (in pod)',
            $hasSsl ? 'pass' : 'warning', $sslCert ?: 'Not set');
    }

    private function checkConfigVariables(array &$results): void
    {
        foreach (self::CONFIG_FILES_TO_SCAN as $file => $label) {
            $exists = $this->exec("test -f {$file} && echo OK || echo MISSING");
            if (strpos($exists, 'MISSING') !== false) continue;
            $count = $this->exec("grep -cP '\\{\\{[A-Z_]+\\}\\}' {$file} 2>/dev/null || echo 0");
            $unsubstituted = intval(trim($count));
            if ($unsubstituted > 0) {
                $vars = $this->exec("grep -oP '\\{\\{[A-Z_]+\\}\\}' {$file} 2>/dev/null | sort -u | head -5");
                $this->addCheck($results, 'config', "{$label} has unsubstituted variables", 'fail',
                    "{$unsubstituted} placeholder(s): {$vars}");
            }
        }
    }

    // =========================================================================
    //  HELPERS
    // =========================================================================

    private function connect(array $server): bool
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

    private function compose(): string
    {
        return DockerProvisioningService::composeBase();
    }

    /** service => ['state' => ..., 'health' => ...] from compose ps. */
    private function composeStates(): array
    {
        $raw = $this->exec(DockerProvisioningService::psJsonCmd());
        return DockerProvisioningService::parsePsJson($raw);
    }

    /**
     * Program => STATE from `supervisorctl status` inside the mail pod, e.g.
     * "postfix   RUNNING   pid 120, uptime 2:03:11". Programs the entrypoint
     * disabled (small-box profile) simply don't appear.
     */
    private function mailProgramStates(): array
    {
        $raw = $this->exec($this->compose() . " exec -T mail supervisorctl status 2>/dev/null");
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            if (preg_match('/^(\S+)\s+([A-Z]+)/', trim($line), $m)) {
                $out[$m[1]] = $m[2];
            }
        }
        return $out;
    }

    /**
     * MySQL over TCP to the CONTAINER (127.0.0.1:3306) via temp defaults-file,
     * exactly like the native audit but with an explicit host so the socket-less
     * hybrid host works. Base64 shuttling avoids all shell-escaping pitfalls.
     */
    private function mysqlExec(string $user, string $password, string $sql, ?string $database = null): string
    {
        $uid = bin2hex(random_bytes(4));
        $cnfFile = "/tmp/fleet_{$uid}.cnf";
        $sqlFile = "/tmp/fleet_{$uid}.sql";
        $dbArg = $database ? " {$database}" : '';

        $cnf = "[client]\nhost=127.0.0.1\nport=3306\nuser={$user}\npassword={$password}\n";
        $b64Cnf = base64_encode($cnf);
        $b64Sql = base64_encode($sql);

        $this->exec("echo '{$b64Cnf}' | base64 -d > {$cnfFile} && chmod 600 {$cnfFile}");
        $this->exec("echo '{$b64Sql}' | base64 -d > {$sqlFile}");

        // Prefer the host client; fall back to the client inside the mariadb
        // container (docker path boxes may not have mariadb-client natively).
        $raw = $this->exec(
            "if command -v mysql >/dev/null 2>&1 || command -v mariadb >/dev/null 2>&1; then "
            . "(mysql --defaults-extra-file={$cnfFile}{$dbArg} < {$sqlFile} 2>&1 "
            . "|| mariadb --defaults-extra-file={$cnfFile}{$dbArg} < {$sqlFile} 2>&1); "
            . "else "
            . "docker cp {$cnfFile} \$(docker ps -qf name=mariadb | head -1):/tmp/a.cnf >/dev/null 2>&1; "
            . "docker cp {$sqlFile} \$(docker ps -qf name=mariadb | head -1):/tmp/a.sql >/dev/null 2>&1; "
            . $this->compose() . " exec -T mariadb sh -c 'mysql --defaults-extra-file=/tmp/a.cnf{$dbArg} < /tmp/a.sql; rc=\$?; rm -f /tmp/a.cnf /tmp/a.sql; exit \$rc' 2>&1; "
            . "fi; echo \"EXIT_CODE:\$?\""
        );

        $this->exec("rm -f {$cnfFile} {$sqlFile}");

        $this->lastMysqlOk = strpos($raw, 'EXIT_CODE:0') !== false;
        return trim(preg_replace('/\n?EXIT_CODE:\d+\s*$/', '', $raw));
    }

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

    /** Panel is native even on Docker boxes; agent too. Email version = image tag. */
    private function updateVersionsFromServer(int $serverId, array $server): void
    {
        try {
            $panelVersion = $this->exec('cat /var/www/vps-admin/VERSION 2>/dev/null');
            $agentVersion = $this->exec('cat /opt/fleet-agent/VERSION 2>/dev/null');

            $updates = [];
            $params = [];
            if (!empty($panelVersion)) { $updates[] = 'panel_version = ?'; $params[] = $panelVersion; }
            if (!empty($agentVersion)) { $updates[] = 'agent_version = ?'; $params[] = $agentVersion; }
            if (!empty($server['deployed_image_tag'])) {
                $updates[] = 'email_app_version = ?';
                $params[] = $server['deployed_image_tag'];
            }
            if (!empty($updates)) {
                $params[] = $serverId;
                $this->db->prepare("UPDATE servers SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
            }
        } catch (\Exception $e) {
            // Non-fatal
        }
    }

    private function storeResults(int $serverId, array $results): void
    {
        $auditJson = json_encode($results, JSON_PRETTY_PRINT);

        $stmt = $this->db->prepare(
            "INSERT INTO server_credentials (server_id, category, credential_key, label, value_encrypted, is_secret)
             VALUES (?, 'audit', 'LAST_AUDIT', 'Last Deployment Audit', ?, 0)
             ON DUPLICATE KEY UPDATE value_encrypted = VALUES(value_encrypted), updated_at = NOW()"
        );
        $stmt->execute([$serverId, $this->encryption->encrypt($auditJson)]);

        if ($results['failed'] > 0) {
            $failedNames = array_map(
                fn($c) => $c['name'],
                array_filter($results['checks'], fn($c) => $c['status'] === 'fail')
            );
            $this->db->prepare("UPDATE servers SET last_error = ? WHERE id = ?")->execute([
                "Audit: {$results['failed']} check(s) failed - " . implode(', ', $failedNames),
                $serverId,
            ]);
        } else {
            $this->db->prepare("UPDATE servers SET last_error = NULL WHERE id = ?")->execute([$serverId]);
        }
    }
}
