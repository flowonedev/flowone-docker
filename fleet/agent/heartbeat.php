#!/usr/bin/env php
<?php
/**
 * Fleet Agent Heartbeat Client
 * 
 * Sends heartbeat to Fleet Panel and processes returned tasks.
 * Run this from cron every 30 seconds:
 * 
 * * * * * * php /opt/fleet-agent/heartbeat.php
 * * * * * * sleep 30; php /opt/fleet-agent/heartbeat.php
 */

declare(strict_types=1);

// Load configuration
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    error_log("Fleet Heartbeat: Config file not found");
    exit(1);
}

$config = require $configFile;

require_once __DIR__ . '/Lib/UpdateScanner.php';

use FleetManager\Agent\Lib\UpdateScanner;

// Panel API configuration
$panelUrl = $config['panel']['url'] ?? null;
$agentToken = $config['panel']['agent_token'] ?? null;

if (!$panelUrl || !$agentToken) {
    error_log("Fleet Heartbeat: Panel URL or agent token not configured");
    exit(1);
}

// Collect health data
$health = collectHealth();

// Collect installed versions
$versions = collectVersions();

// Collect the live SSH connection facts (port + auth method). Deploys can apply
// a cloned/hardened sshd_config that moves SSH to a new port and/or disables
// password auth; reporting it keeps the Fleet Manager's stored connection in sync.
$ssh = collectSsh();

// Collect pending OS/npm updates (disk-cached, full rescan at most hourly)
$updates = [];
try {
    $updates = (new UpdateScanner())->scan() ?? [];
} catch (\Throwable $e) {
    error_log("Fleet Heartbeat: Update scan failed: " . $e->getMessage());
}

// Send heartbeat to panel
$response = sendHeartbeat($panelUrl, $agentToken, $health, $versions, $ssh, $updates);

if (!$response) {
    error_log("Fleet Heartbeat: Failed to contact panel");
    exit(1);
}

// Process any pending tasks
if (!empty($response['tasks'])) {
    processTasks($response['tasks'], $panelUrl, $agentToken, $config);
}

exit(0);

/**
 * Collect server health information
 */
function collectHealth(): array
{
    $health = [
        'services' => [],
        'disk' => [],
        'memory' => [],
        'cpu' => [],
        'ssl' => [],
        'uptime' => null,
    ];

    try {
        // Check services (key = column name prefix, value = systemd unit)
        $services = [
            'openlitespeed' => 'lshttpd',
            'mariadb' => 'mariadb',
            'postfix' => 'postfix',
            'dovecot' => 'dovecot',
            'fail2ban' => 'fail2ban',
            'firewalld' => 'firewalld',
            'openvpn' => 'openvpn',
            'redis' => 'redis-server',
            'meilisearch' => 'meilisearch',
            'spamassassin' => 'spamd',
            'opendkim' => 'opendkim',
            'opendmarc' => 'opendmarc',
            'clamav' => 'clamav-daemon',
            'pdns' => 'pdns',
            'coturn' => 'coturn',
            'livekit' => 'livekit-server',
            'stunnel' => 'stunnel4',
            'collab' => 'collab-server',
            'mailsync' => 'mailsync-server',
        ];

        foreach ($services as $name => $service) {
            $output = [];
            $code = -1;
            @exec("systemctl is-active {$service} 2>/dev/null", $output, $code);
            $health['services'][$name] = match ($code) {
                0 => 'running',
                3 => 'stopped',
                4 => 'disabled',
                default => 'unknown',
            };
        }

        // OnlyOffice Document Server runs as a Docker container, not a
        // systemd unit. Report 'disabled' when Docker/the container is
        // absent so servers without the office stack stay green.
        $health['services']['office'] = checkOfficeContainer();
    } catch (\Throwable $e) {
        error_log("Fleet Heartbeat: Service check failed: " . $e->getMessage());
    }

    try {
        // Disk usage
        $diskOutput = @shell_exec("df -BG / 2>/dev/null | tail -1");
        if ($diskOutput && preg_match('/(\d+)G\s+(\d+)G\s+(\d+)G\s+(\d+)%/', $diskOutput, $matches)) {
            $health['disk'] = [
                'total_gb' => (int)$matches[1],
                'used_gb' => (int)$matches[2],
                'free_gb' => (int)$matches[3],
                'percent' => (int)$matches[4],
            ];
        }
    } catch (\Throwable $e) {
        error_log("Fleet Heartbeat: Disk check failed: " . $e->getMessage());
    }

    try {
        // Memory
        $memOutput = @shell_exec("free -m 2>/dev/null | grep Mem");
        if ($memOutput && preg_match('/Mem:\s+(\d+)\s+(\d+)/', $memOutput, $matches)) {
            $total = (int)$matches[1];
            $used = (int)$matches[2];
            $health['memory'] = [
                'total_mb' => $total,
                'used_mb' => $used,
                'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
            ];
        }
    } catch (\Throwable $e) {
        error_log("Fleet Heartbeat: Memory check failed: " . $e->getMessage());
    }

    try {
        // CPU load
        $loadAvg = @sys_getloadavg();
        if ($loadAvg) {
            $health['cpu'] = [
                'load_1m' => round($loadAvg[0], 2),
                'load_5m' => round($loadAvg[1], 2),
                'load_15m' => round($loadAvg[2], 2),
            ];
        }
    } catch (\Throwable $e) {
        error_log("Fleet Heartbeat: CPU check failed: " . $e->getMessage());
    }

    try {
        // Uptime
        $uptimeOutput = @shell_exec("cat /proc/uptime 2>/dev/null");
        if ($uptimeOutput && preg_match('/^([\d.]+)/', $uptimeOutput, $matches)) {
            $health['uptime'] = (int)$matches[1];
        }
    } catch (\Throwable $e) {
        error_log("Fleet Heartbeat: Uptime check failed: " . $e->getMessage());
    }

    // SSL expiry -- read domain names from the local Panel config
    try {
        $panelConf = '/var/www/vps-admin/api/config.local.php';
        if (file_exists($panelConf)) {
            $pCfg = include $panelConf;
            $sslDomains = [
                'panel_expiry' => $pCfg['panel_domain'] ?? '',
                'email_expiry' => $pCfg['email_domain'] ?? '',
            ];
            foreach ($sslDomains as $key => $domain) {
                if (empty($domain)) continue;
                $cert = "/etc/letsencrypt/live/{$domain}/fullchain.pem";
                if (!file_exists($cert)) continue;
                $expiry = @shell_exec("openssl x509 -enddate -noout -in {$cert} 2>/dev/null | cut -d= -f2");
                if ($expiry) {
                    $ts = strtotime(trim($expiry));
                    if ($ts) {
                        $health['ssl'][$key] = date('Y-m-d', $ts);
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        error_log("Fleet Heartbeat: SSL check failed: " . $e->getMessage());
    }

    return $health;
}

/**
 * Status of the OnlyOffice Document Server Docker container.
 * 'disabled' when Docker or the container doesn't exist (server has no
 * office stack), 'running'/'stopped' otherwise.
 */
function checkOfficeContainer(): string
{
    $output = [];
    $code = -1;
    @exec('command -v docker 2>/dev/null', $output, $code);
    if ($code !== 0) {
        return 'disabled';
    }

    $output = [];
    @exec("docker inspect -f '{{.State.Running}}' flowone-office 2>/dev/null", $output, $code);
    if ($code !== 0) {
        return 'disabled'; // container doesn't exist
    }
    return trim($output[0] ?? '') === 'true' ? 'running' : 'stopped';
}

/**
 * Collect installed application versions from VERSION files
 */
function collectVersions(): array
{
    $versions = [];

    $versionFiles = [
        'panel' => '/var/www/vps-admin/VERSION',
        'email_app' => '/var/www/vps-email/VERSION',
        'agent' => '/opt/fleet-agent/VERSION',
    ];

    foreach ($versionFiles as $key => $file) {
        if (file_exists($file)) {
            $ver = trim(file_get_contents($file));
            if (!empty($ver)) {
                $versions[$key] = $ver;
            }
        }
    }

    // OS distro + version (e.g. "Ubuntu 24.04.1 LTS"), reported so the dashboard
    // can show what each server actually runs and keep it fresh after install.
    $os = @shell_exec("grep -E '^PRETTY_NAME=' /etc/os-release 2>/dev/null | head -1 | cut -d'\"' -f2");
    $os = $os !== null ? trim($os) : '';
    if ($os === '') {
        $os = trim(@shell_exec('lsb_release -ds 2>/dev/null || uname -sr') ?? '');
    }
    if ($os !== '') {
        $versions['os'] = substr($os, 0, 100);
    }

    return $versions;
}

/**
 * Collect the live SSH connection facts so the Fleet Manager can keep its stored
 * connection (port + auth method) in sync after a hardened/cloned sshd_config is
 * applied. Reports the port the running sshd actually listens on (truthful even
 * if the config was edited but not yet reloaded) and whether password auth is on.
 */
function collectSsh(): array
{
    $ssh = [];

    try {
        // Actual listening port(s) of the running sshd (lowest if several).
        $out = @shell_exec(
            "ss -H -tlnp 2>/dev/null | grep -w sshd | awk '{print \$4}' "
            . "| sed 's/.*://' | grep -E '^[0-9]+$' | sort -un | head -1"
        );
        if ($out !== null && (int)trim($out) > 0) {
            $ssh['port'] = (int)trim($out);
        }

        // Fallback: the configured port from the effective sshd config.
        if (empty($ssh['port'])) {
            $cfg = @shell_exec("sshd -T 2>/dev/null | awk '\$1==\"port\"{print \$2; exit}'");
            if ($cfg !== null && (int)trim($cfg) > 0) {
                $ssh['port'] = (int)trim($cfg);
            }
        }

        // Whether password auth is enabled (drives the displayed auth method).
        $pa = @shell_exec("sshd -T 2>/dev/null | awk '\$1==\"passwordauthentication\"{print \$2; exit}'");
        if ($pa !== null && trim($pa) !== '') {
            $ssh['password_auth'] = (strtolower(trim($pa)) === 'yes');
        }
    } catch (\Throwable $e) {
        error_log("Fleet Heartbeat: SSH check failed: " . $e->getMessage());
    }

    return $ssh;
}

/**
 * Send heartbeat to Fleet Panel
 */
function sendHeartbeat(string $panelUrl, string $agentToken, array $health, array $versions = [], array $ssh = [], array $updates = []): ?array
{
    $url = rtrim($panelUrl, '/') . '/api/agent/heartbeat';

    $payload = ['health' => $health];
    if (!empty($versions)) {
        $payload['versions'] = $versions;
    }
    if (!empty($ssh)) {
        $payload['ssh'] = $ssh;
    }
    if (!empty($updates)) {
        $payload['updates'] = $updates;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Agent-Token: ' . $agentToken,
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("Fleet Heartbeat: cURL error: {$error}");
        return null;
    }

    if ($httpCode !== 200) {
        error_log("Fleet Heartbeat: HTTP {$httpCode} - {$response}");
        return null;
    }

    $data = json_decode($response, true);

    if (!$data || !isset($data['success']) || !$data['success']) {
        error_log("Fleet Heartbeat: Invalid response: {$response}");
        return null;
    }

    return $data['data'] ?? [];
}

/**
 * Process pending tasks from panel
 */
function processTasks(array $tasks, string $panelUrl, string $agentToken, array $config): void
{
    foreach ($tasks as $task) {
        $taskId = $task['id'];
        $taskType = $task['type'];
        $payload = $task['payload'] ?? [];

        error_log("Fleet Task: Processing task #{$taskId} ({$taskType})");

        // Report task started
        reportTaskStatus($panelUrl, $agentToken, $taskId, 'start');

        try {
            // Execute task via local agent socket
            $result = executeTaskViaAgent($taskType, $payload, $config);

            if ($result['success']) {
                // Report success
                reportTaskStatus($panelUrl, $agentToken, $taskId, 'complete', [
                    'result' => json_encode($result),
                ]);
                error_log("Fleet Task: Task #{$taskId} completed successfully");
            } else {
                // Report failure
                reportTaskStatus($panelUrl, $agentToken, $taskId, 'fail', [
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
                error_log("Fleet Task: Task #{$taskId} failed: " . ($result['error'] ?? 'Unknown'));
            }
        } catch (\Throwable $e) {
            reportTaskStatus($panelUrl, $agentToken, $taskId, 'fail', [
                'error' => $e->getMessage(),
            ]);
            error_log("Fleet Task: Task #{$taskId} exception: " . $e->getMessage());
        }
    }
}

/**
 * Execute task via local agent socket
 */
function executeTaskViaAgent(string $taskType, array $payload, array $config): array
{
    $socketPath = $config['socket']['path'] ?? '/run/fleet-agent/agent.sock';

    if (!file_exists($socketPath)) {
        return ['success' => false, 'error' => 'Agent socket not found'];
    }

    // Map task types to agent actions
    $actionMap = [
        'run_command' => 'task.run_command',
        'sync_files' => 'task.sync_files',
        'restart_service' => 'task.restart_service',
        'update_agent' => 'task.update_agent',
        'health_check' => 'task.health_check',
        'update_packages' => 'task.update_packages',
    ];

    $action = $actionMap[$taskType] ?? null;

    if (!$action) {
        return ['success' => false, 'error' => "Unknown task type: {$taskType}"];
    }

    // Connect to agent socket
    $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
    if (!$socket) {
        return ['success' => false, 'error' => 'Failed to create socket'];
    }

    if (!@socket_connect($socket, $socketPath)) {
        $error = socket_strerror(socket_last_error($socket));
        socket_close($socket);
        return ['success' => false, 'error' => "Failed to connect to agent: {$error}"];
    }

    // Read token for auth
    $tokenFile = $config['paths']['token_file'] ?? '/etc/fleet-agent/token';
    $token = file_exists($tokenFile) ? trim(file_get_contents($tokenFile)) : '';

    // Send request
    $request = json_encode([
        'action' => $action,
        'params' => $payload,
        'token' => $token,
        'actor' => 'heartbeat',
    ]) . "\n\n";

    socket_write($socket, $request);

    // Read response
    $response = '';
    while (($chunk = socket_read($socket, 8192)) !== false && $chunk !== '') {
        $response .= $chunk;
        if (strpos($response, "\n") !== false) {
            break;
        }
    }

    socket_close($socket);

    $result = json_decode(trim($response), true);

    if (!$result) {
        return ['success' => false, 'error' => 'Invalid response from agent'];
    }

    return $result;
}

/**
 * Report task status to panel
 */
function reportTaskStatus(string $panelUrl, string $agentToken, int $taskId, string $status, array $data = []): void
{
    $endpoints = [
        'start' => '/api/agent/task/' . $taskId . '/start',
        'progress' => '/api/agent/task/' . $taskId . '/progress',
        'complete' => '/api/agent/task/' . $taskId . '/complete',
        'fail' => '/api/agent/task/' . $taskId . '/fail',
    ];

    $url = rtrim($panelUrl, '/') . ($endpoints[$status] ?? '');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Agent-Token: ' . $agentToken,
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    curl_exec($ch);
    curl_close($ch);
}

