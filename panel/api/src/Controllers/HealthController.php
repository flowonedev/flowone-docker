<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class HealthController extends BaseController
{
    /**
     * Hybrid boxes run these as Docker containers (flowone compose stack)
     * instead of systemd units. When the unit is inactive/not-found we consult
     * the container state before declaring the service down.
     */
    private const DOCKER_CONTAINERS = [
        'mariadb'         => 'flowone-mariadb-1',
        'redis-server'    => 'flowone-redis-1',
        'meilisearch'     => 'flowone-meilisearch-1',
        'collab-server'   => 'flowone-collab-1',
        'mailsync-server' => 'flowone-mailsync-1',
    ];

    /** Programs supervised INSIDE the host-networked mail pod. */
    private const MAIL_POD = 'flowone-mail-1';
    private const MAIL_POD_PROGRAMS = [
        'postfix'          => 'postfix',
        'dovecot'          => 'dovecot',
        'opendkim'         => 'opendkim',
        'opendmarc'        => 'opendmarc',
        'spamd'            => 'spamd',
        'clamav-daemon'    => 'clamd',
        'clamav-freshclam' => 'freshclam',
    ];

    /**
     * The mail pod entrypoint drops these from the supervisor config when
     * MAIL_ENABLE_CLAMAV / MAIL_ENABLE_SPAMASSASSIN are 0 (RAM savings).
     * Absent-from-pod means "disabled on purpose", not "crashed".
     */
    private const OPTIONAL_POD_PROGRAMS = ['spamd', 'clamd', 'freshclam'];

    private array $checks = [];
    private int $passed = 0;
    private int $failed = 0;
    private int $warnings = 0;
    /** Lazily fetched docker container + mail-pod program states. */
    private ?array $dockerStates = null;

    public function index(Request $request): Response
    {
        $this->checks = [];
        $this->passed = 0;
        $this->failed = 0;
        $this->warnings = 0;

        $this->checkServices();
        $this->checkPorts();
        $this->checkListening();
        $this->checkSSL();
        $this->checkHTTP();
        $this->checkDatabases();
        $this->checkMailData();
        $this->checkDnsData();
        $this->checkFilesystem();
        $this->checkDisk();

        $fixableCount = count(array_filter($this->checks, fn($c) => !empty($c['fix_command'])));

        return Response::success([
            'timestamp' => date('Y-m-d H:i:s'),
            'overall' => $this->failed === 0 ? 'pass' : 'fail',
            'passed' => $this->passed,
            'failed' => $this->failed,
            'warnings' => $this->warnings,
            'fixable_count' => $fixableCount,
            'checks' => $this->checks,
        ]);
    }

    /**
     * Execute a fix command from a health check result.
     * Only whitelisted command prefixes are allowed for safety.
     */
    public function fix(Request $request): Response
    {
        $command = $request->input('fix_command');
        if (empty($command)) {
            return Response::error('No fix_command provided');
        }

        $allowed = [
            'systemctl restart',
            'systemctl start',
            'systemctl reload',
            'firewall-cmd --permanent',
            'firewall-cmd --reload',
            'certbot certonly',
            'apt-get install',
            'mariadb --defaults-file=/root/.my.cnf',
            'docker restart flowone-',
            'docker exec flowone-mail-1 supervisorctl restart ',
        ];

        $isAllowed = false;
        foreach ($allowed as $prefix) {
            if (str_starts_with($command, $prefix)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            return Response::error('Command not in whitelist');
        }

        if (str_starts_with($command, 'certbot certonly')) {
            return $this->fixSSL($command);
        }

        $result = $this->agent->execute('system.exec', [
            'command' => $command,
        ], $this->getActor());

        $this->logAction('health.fix', $command, $result['success'] ? 'success' : 'failed');

        if ($result['success']) {
            return Response::success([
                'command' => $command,
                'output' => $result['data']['output'] ?? '',
            ], 'Fix applied');
        }

        return Response::error($result['error'] ?? 'Fix command failed');
    }

    /**
     * Handle SSL certificate fix: stop OLS to free port 80, clean up any
     * existing self-signed cert, run certbot --standalone, then restart OLS.
     */
    private function fixSSL(string $certbotCmd): Response
    {
        $output = [];

        if (preg_match('/-d\s+(\S+)/', $certbotCmd, $m)) {
            $domain = $m[1];
            $this->agent->execute('system.exec', [
                'command' => "rm -rf /etc/letsencrypt/live/{$domain} /etc/letsencrypt/archive/{$domain} /etc/letsencrypt/renewal/{$domain}.conf",
            ], $this->getActor());
            $output[] = "Cleaned up existing cert for {$domain}";
        }

        $this->agent->execute('system.exec', [
            'command' => 'systemctl stop lshttpd 2>/dev/null || /usr/local/lsws/bin/lswsctrl stop 2>/dev/null || true',
        ], $this->getActor());
        $output[] = 'Stopped OLS to free port 80';
        sleep(2);

        $result = $this->agent->execute('system.exec', [
            'command' => $certbotCmd . ' 2>&1',
        ], $this->getActor());
        $certbotOutput = $result['data']['output'] ?? '';
        $output[] = $certbotOutput;

        $this->agent->execute('system.exec', [
            'command' => 'systemctl start lshttpd 2>/dev/null || /usr/local/lsws/bin/lswsctrl start 2>/dev/null || true',
        ], $this->getActor());
        $output[] = 'Restarted OLS';

        $success = str_contains($certbotOutput, 'Successfully') || str_contains($certbotOutput, 'Certificate not yet due for renewal');
        $this->logAction('health.fix', $certbotCmd, $success ? 'success' : 'failed');

        if ($success) {
            return Response::success([
                'command' => $certbotCmd,
                'output' => implode("\n", $output),
            ], 'SSL certificate obtained');
        }

        return Response::error('Certbot failed: ' . $certbotOutput);
    }

    private function addCheck(string $category, string $name, string $status, string $detail, ?string $fixCmd = null, ?string $fixLabel = null): void
    {
        $check = [
            'category' => $category,
            'name' => $name,
            'status' => $status,
            'detail' => $detail,
        ];
        if ($fixCmd !== null) {
            $check['fix_command'] = $fixCmd;
            $check['fix_label'] = $fixLabel ?? 'Fix';
        }
        $this->checks[] = $check;
        match ($status) {
            'pass' => $this->passed++,
            'fail' => $this->failed++,
            'warning' => $this->warnings++,
            default => null,
        };
    }

    private function run(string $cmd): string
    {
        return trim(shell_exec($cmd . ' 2>/dev/null') ?? '');
    }

    /**
     * One-shot snapshot of the Docker side: running containers + the mail
     * pod's supervised program states. Cached per request — the health page
     * checks ~20 services and must not fork docker 20 times.
     */
    private function dockerStates(): array
    {
        if ($this->dockerStates !== null) {
            return $this->dockerStates;
        }
        $states = ['containers' => [], 'programs' => [], 'known' => []];
        $ps = $this->run("docker ps --format '{{.Names}}|{{.Status}}'");
        foreach (array_filter(explode("\n", $ps)) as $line) {
            [$cname, $cstatus] = array_pad(explode('|', $line, 2), 2, '');
            if (str_starts_with($cstatus, 'Up') && !str_contains($cstatus, 'unhealthy')) {
                $states['containers'][$cname] = true;
            }
        }
        if (isset($states['containers'][self::MAIL_POD])) {
            $sv = $this->run('docker exec ' . self::MAIL_POD . ' supervisorctl status');
            foreach (array_filter(explode("\n", $sv)) as $line) {
                if (preg_match('/^(\S+)\s+(\S+)/', $line, $m)) {
                    $states['known'][$m[1]] = $m[2];
                    if ($m[2] === 'RUNNING') {
                        $states['programs'][$m[1]] = true;
                    }
                }
            }
        }
        return $this->dockerStates = $states;
    }

    /**
     * Docker-side status for a service name, or null when it has no Docker
     * backing on this box. Returns [running(bool), detail, fixCmd].
     */
    private function dockerServiceState(string $svc): ?array
    {
        $states = $this->dockerStates();
        if (isset(self::DOCKER_CONTAINERS[$svc])) {
            $cname = self::DOCKER_CONTAINERS[$svc];
            $up = isset($states['containers'][$cname]);
            return [$up, "docker: {$cname} " . ($up ? 'up' : 'down'), "docker restart {$cname}"];
        }
        if (isset(self::MAIL_POD_PROGRAMS[$svc]) && isset($states['containers'][self::MAIL_POD])) {
            $prog = self::MAIL_POD_PROGRAMS[$svc];
            if (isset($states['programs'][$prog])) {
                return [true, "mail pod: {$prog} running", null];
            }
            // The entrypoint drops toggled-off programs from the supervisor
            // config entirely — absent means disabled, not crashed, and a
            // restart fix would fail against a nonexistent program.
            if (!isset($states['known'][$prog]) && in_array($prog, self::OPTIONAL_POD_PROGRAMS, true)) {
                return [false, "mail pod: {$prog} disabled (MAIL_ENABLE_* toggle)", null];
            }
            $state = strtolower($states['known'][$prog] ?? 'missing');
            return [false, "mail pod: {$prog} {$state}",
                'docker exec ' . self::MAIL_POD . " supervisorctl restart {$prog}"];
        }
        return null;
    }

    private function checkServices(): void
    {
        $required = [
            'lshttpd'     => 'OpenLiteSpeed',
            'mariadb'     => 'MariaDB',
            'redis-server' => 'Redis',
            'postfix'     => 'Postfix',
            'dovecot'     => 'Dovecot',
            'opendkim'    => 'OpenDKIM',
            'opendmarc'   => 'OpenDMARC',
            'pdns'        => 'PowerDNS',
            'fail2ban'    => 'Fail2ban',
            'firewalld'   => 'FirewallD',
        ];
        $optional = [
            'meilisearch'      => 'Meilisearch',
            'spamd'            => 'SpamAssassin',
            'clamav-daemon'    => 'ClamAV Daemon',
            'clamav-freshclam' => 'ClamAV Freshclam',
            'coturn'           => 'coTURN',
            'livekit-server'   => 'LiveKit',
            'stunnel4'         => 'stunnel4',
            'collab-server'    => 'Collab Server',
            'mailsync-server'  => 'MailSync Server',
            'vpsadmin-agent'   => 'Panel Agent',
            'fleet-agent'      => 'Fleet Agent',
        ];

        foreach ($required as $svc => $label) {
            [$running, $detail, $fixCmd] = $this->serviceState($svc);
            $this->addCheck('services', $label, $running ? 'pass' : 'fail',
                $detail, $running ? null : $fixCmd, 'Restart');
        }

        foreach ($optional as $svc => $label) {
            [$running, $detail, $fixCmd] = $this->serviceState($svc);
            if (!$running && $svc === 'spamd') {
                [$running, $altDetail] = $this->serviceState('spamassassin');
                if ($running) $detail = $altDetail;
            }
            $this->addCheck('services', $label, $running ? 'pass' : 'warning',
                $detail, $running ? null : $fixCmd, 'Restart');
        }
    }

    /**
     * Resolve a service's real state on a hybrid box: systemd first, then the
     * Docker container / mail-pod program that replaced it.
     * Returns [running(bool), detail, fixCmd].
     */
    private function serviceState(string $svc): array
    {
        $status = $this->run("systemctl is-active {$svc}");
        if (in_array($status, ['active', 'activating', 'exited'])) {
            return [true, "systemd: {$status}", null];
        }
        $docker = $this->dockerServiceState($svc);
        if ($docker !== null) {
            return $docker;
        }
        return [false, "systemd: {$status}", "systemctl restart {$svc}"];
    }

    private function checkPorts(): void
    {
        $fwPorts = $this->run('firewall-cmd --list-ports');
        $fwServices = $this->run('firewall-cmd --list-services');

        $portSet = array_flip(preg_split('/\s+/', $fwPorts, -1, PREG_SPLIT_NO_EMPTY));
        $svcMap = [
            'ssh' => '22/tcp', 'http' => '80/tcp', 'https' => '443/tcp',
            'smtp' => '25/tcp', 'smtps' => '465/tcp', 'smtp-submission' => '587/tcp',
            'pop3' => '110/tcp', 'pop3s' => '995/tcp', 'imap' => '143/tcp', 'imaps' => '993/tcp',
            'dns' => '53/tcp',
        ];
        foreach (preg_split('/\s+/', $fwServices, -1, PREG_SPLIT_NO_EMPTY) as $s) {
            if (isset($svcMap[$s])) $portSet[$svcMap[$s]] = true;
        }

        $ports = [
            '22/tcp'   => 'SSH',        '80/tcp'   => 'HTTP',
            '443/tcp'  => 'HTTPS',      '25/tcp'   => 'SMTP',
            '465/tcp'  => 'SMTPS',      '587/tcp'  => 'Submission',
            '110/tcp'  => 'POP3',       '143/tcp'  => 'IMAP',
            '993/tcp'  => 'IMAPS',      '995/tcp'  => 'POP3S',
            '4190/tcp' => 'ManageSieve', '53/tcp'  => 'DNS (TCP)',
            '53/udp'   => 'DNS (UDP)',  '3478/tcp' => 'STUN/TURN',
            '7080/tcp' => 'OLS Admin',  '7443/tcp' => 'stunnel',
            '7880/tcp' => 'LiveKit',    '7881/tcp' => 'LiveKit RTC',
        ];

        foreach ($ports as $port => $label) {
            $open = isset($portSet[$port]);
            $this->addCheck('ports', "{$port} ({$label})", $open ? 'pass' : 'fail',
                $open ? 'Open' : 'Closed',
                $open ? null : "firewall-cmd --permanent --add-port={$port} && firewall-cmd --reload",
                'Open port');
        }
    }

    private function checkListening(): void
    {
        $ss = $this->run('ss -tlnp');
        $checks = [
            [80,   'OLS HTTP',      'lshttpd'],
            [443,  'OLS HTTPS',     'lshttpd'],
            [25,   'Postfix SMTP',  'postfix'],
            [993,  'Dovecot IMAPS', 'dovecot'],
            [53,   'PowerDNS',      'pdns'],
            [3306, 'MariaDB',       'mariadb'],
            [6379, 'Redis',         'redis-server'],
        ];
        foreach ($checks as [$port, $label, $svc]) {
            $listening = str_contains($ss, ":{$port} ");
            $fix = null;
            if (!$listening) {
                // On hybrid boxes the port may belong to a container, so the
                // restart command must target docker, not a systemd unit.
                $docker = $this->dockerServiceState($svc);
                $fix = $docker !== null ? $docker[2] : "systemctl restart {$svc}";
            }
            $this->addCheck('listening', "{$label} (:{$port})", $listening ? 'pass' : 'fail',
                $listening ? 'Listening' : 'Not listening',
                $fix, 'Restart');
        }
    }

    private function checkSSL(): void
    {
        $domains = [];
        $confFile = '/var/www/vps-admin/api/config.local.php';
        if (file_exists($confFile)) {
            $conf = include $confFile;
            if (!empty($conf['panel_domain'])) $domains[] = $conf['panel_domain'];
            if (!empty($conf['email_domain'])) $domains[] = $conf['email_domain'];
            $base = preg_replace('/^panel\./', '', $conf['panel_domain'] ?? '');
            if (!empty($base) && !in_array($base, $domains)) $domains[] = $base;
        }
        if (empty($domains)) {
            $hostname = $this->run('hostname -f');
            if (!empty($hostname)) $domains[] = $hostname;
        }

        $myIp = $this->run("hostname -I | awk '{print \$1}'");

        foreach ($domains as $domain) {
            $certPath = "/etc/letsencrypt/live/{$domain}/fullchain.pem";
            $renewCmd = "certbot certonly --standalone -d {$domain} --non-interactive --agree-tos --register-unsafely-without-email";

            // Let's Encrypt HTTP validation is impossible when the domain has
            // no public A record (typical for a bare apex whose registrar DNS
            // only carries the subdomains). Report the real blocker instead
            // of offering a certbot run that is guaranteed to fail.
            $resolved = $this->run("dig +short {$domain} A @1.1.1.1 | head -n1");
            $dnsBlocked = $resolved === '' && $this->run('command -v dig') !== '';
            $dnsHint = "No public A record for {$domain} — Let's Encrypt cannot validate. "
                . "Add an A record ({$domain} -> {$myIp}) at your DNS provider, then re-check.";

            if (!file_exists($certPath)) {
                if ($dnsBlocked) {
                    $this->addCheck('ssl', "SSL: {$domain}", 'warning', $dnsHint);
                } else {
                    $this->addCheck('ssl', "SSL: {$domain}", 'fail',
                        'No certificate found', $renewCmd, 'Request cert');
                }
                continue;
            }

            $issuer = $this->run("openssl x509 -issuer -noout -in {$certPath}");
            $isLE = str_contains($issuer, "Let's Encrypt") || preg_match('/\b(R3|R10|R11|E[5-9])\b/', $issuer);

            if (!$isLE) {
                if ($dnsBlocked) {
                    $this->addCheck('ssl', "SSL: {$domain}", 'warning',
                        'Self-signed placeholder. ' . $dnsHint);
                } else {
                    $this->addCheck('ssl', "SSL: {$domain}", 'fail',
                        'Self-signed placeholder', $renewCmd, 'Request Let\'s Encrypt cert');
                }
                continue;
            }

            $expiryEpoch = intval($this->run("openssl x509 -enddate -noout -in {$certPath} | cut -d= -f2 | xargs -I{} date -d '{}' +%s"));
            $daysLeft = $expiryEpoch > 0 ? intval(($expiryEpoch - time()) / 86400) : -1;

            if ($daysLeft < 0) {
                $this->addCheck('ssl', "SSL: {$domain}", 'fail',
                    'EXPIRED', $renewCmd, 'Renew cert');
            } elseif ($daysLeft < 14) {
                $this->addCheck('ssl', "SSL: {$domain}", 'warning',
                    "Expires in {$daysLeft} days", $renewCmd, 'Renew cert');
            } else {
                $this->addCheck('ssl', "SSL: {$domain}", 'pass',
                    "Valid, {$daysLeft} days remaining");
            }
        }
    }

    private function checkHTTP(): void
    {
        $confFile = '/var/www/vps-admin/api/config.local.php';
        $domains = [];
        if (file_exists($confFile)) {
            $conf = include $confFile;
            if (!empty($conf['panel_domain'])) $domains[] = $conf['panel_domain'];
            if (!empty($conf['email_domain'])) $domains[] = $conf['email_domain'];
        }

        foreach ($domains as $domain) {
            $code = $this->run("curl -sSk -o /dev/null -w '%{http_code}' --connect-timeout 5 https://{$domain}/");
            if (empty($code)) $code = '000';
            $ok = in_array($code, ['200', '301', '302', '403']);
            $this->addCheck('http', "HTTPS {$domain}", $ok ? 'pass' : ($code === '000' ? 'fail' : 'warning'),
                "HTTP {$code}",
                $ok ? null : 'systemctl restart lshttpd', 'Restart OLS');
        }
    }

    private function checkDatabases(): void
    {
        $databases = ['devc_vps_dash' => 'Panel DB', 'mailserver' => 'Mailserver DB'];
        foreach ($databases as $db => $label) {
            $exists = $this->run("mariadb --defaults-file=/root/.my.cnf -N -e \"SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='{$db}'\"");
            $found = intval($exists) > 0;
            $this->addCheck('database', $label, $found ? 'pass' : 'fail',
                $found ? 'Exists' : 'Not found',
                $found ? null : "mariadb --defaults-file=/root/.my.cnf -e \"CREATE DATABASE IF NOT EXISTS \`{$db}\`\"",
                'Create database');

            if ($found) {
                $tblCount = intval($this->run("mariadb --defaults-file=/root/.my.cnf -N -e \"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$db}'\""));
                $this->addCheck('database', "{$label} tables", $tblCount > 0 ? 'pass' : 'fail',
                    "{$tblCount} table(s)");
            }
        }
    }

    private function checkMailData(): void
    {
        $confFile = '/var/www/vps-admin/api/config.local.php';
        $domain = '';
        if (file_exists($confFile)) {
            $conf = include $confFile;
            $domain = preg_replace('/^panel\./', '', $conf['panel_domain'] ?? '');
        }
        if (empty($domain)) return;

        $domCount = intval($this->run("mariadb --defaults-file=/root/.my.cnf devc_vps_dash -N -e \"SELECT COUNT(*) FROM mail_domains WHERE domain='{$domain}' AND status='active'\""));
        $this->addCheck('mail', "Mail domain '{$domain}'", $domCount > 0 ? 'pass' : 'fail',
            $domCount > 0 ? 'Active' : 'Missing from Panel DB');

        $acctCount = intval($this->run("mariadb --defaults-file=/root/.my.cnf devc_vps_dash -N -e \"SELECT COUNT(*) FROM mail_accounts WHERE domain='{$domain}'\""));
        $this->addCheck('mail', 'Mail accounts', $acctCount > 0 ? 'pass' : 'fail',
            $acctCount > 0 ? "{$acctCount} account(s)" : 'No accounts found');

        // Rate limit locks
        $locks = intval($this->run("mariadb --defaults-file=/root/.my.cnf devc_vps_dash -N -e \"SELECT COUNT(*) FROM login_rate_limits WHERE locked_until > NOW()\""));
        if ($locks > 0) {
            $this->addCheck('mail', 'Login rate limits', 'warning',
                "{$locks} active lock(s)",
                "mariadb --defaults-file=/root/.my.cnf devc_vps_dash -e \"TRUNCATE login_rate_limits; TRUNCATE login_attempts\"",
                'Clear locks');
        }
    }

    private function checkDnsData(): void
    {
        $confFile = '/var/www/vps-admin/api/config.local.php';
        $domain = '';
        if (file_exists($confFile)) {
            $conf = include $confFile;
            $domain = preg_replace('/^panel\./', '', $conf['panel_domain'] ?? '');
        }
        if (empty($domain)) return;

        $zoneCount = intval($this->run("mariadb --defaults-file=/root/.my.cnf devc_vps_dash -N -e \"SELECT COUNT(*) FROM dns_domains WHERE name='{$domain}'\""));
        $this->addCheck('dns', "DNS zone '{$domain}'", $zoneCount > 0 ? 'pass' : 'fail',
            $zoneCount > 0 ? 'Zone exists' : 'Missing');

        if ($zoneCount > 0) {
            $recCount = intval($this->run("mariadb --defaults-file=/root/.my.cnf devc_vps_dash -N -e \"SELECT COUNT(*) FROM dns_records dr JOIN dns_domains dd ON dr.domain_id=dd.id WHERE dd.name='{$domain}'\""));
            $this->addCheck('dns', 'DNS records', $recCount >= 5 ? 'pass' : 'warning',
                "{$recCount} record(s)");
        }

        // PowerDNS can resolve
        $soa = $this->run("dig @127.0.0.1 {$domain} SOA +short +time=3");
        $this->addCheck('dns', 'PowerDNS resolves zone', !empty($soa) ? 'pass' : 'warning',
            !empty($soa) ? 'Resolving' : 'Cannot resolve locally',
            empty($soa) ? 'systemctl restart pdns' : null, 'Restart PowerDNS');
    }

    private function checkFilesystem(): void
    {
        $paths = [
            '/var/www/vps-admin'      => 'Panel web root',
            '/var/www/vps-admin/api'  => 'Panel API',
            '/var/www/vps-email'      => 'Email app',
            '/var/www/vps-email/dist' => 'Email frontend',
        ];
        foreach ($paths as $path => $label) {
            $exists = is_dir($path) || is_file($path);
            $this->addCheck('filesystem', $label, $exists ? 'pass' : 'fail', $path);
        }
    }

    private function checkDisk(): void
    {
        $pct = intval($this->run("df / --output=pcent | tail -1 | tr -d ' %'"));
        $status = $pct >= 90 ? 'fail' : ($pct >= 75 ? 'warning' : 'pass');
        $this->addCheck('system', 'Disk usage', $status, "{$pct}% used");

        $mem = $this->run("free -m | awk '/^Mem:/ {printf \"%d/%dMB (%.0f%%)\", \$3, \$2, \$3/\$2*100}'");
        $this->addCheck('system', 'Memory', 'pass', $mem ?: 'unknown');

        $uptime = $this->run('uptime -p');
        $this->addCheck('system', 'Uptime', 'pass', $uptime ?: 'unknown');
    }
}
