#!/usr/bin/env php
<?php
/**
 * NAS/VPN Health Monitor
 *
 * Runs as a cron job every 3 minutes. Checks the full VPN -> NAS -> NFS
 * chain, diagnoses the root cause of any failure, auto-recovers where
 * safe, and sends an email alert on state changes.
 *
 * State reconciliation:
 *   - Writes its own structured JSON to STATUS_FILE (consumed by the
 *     admin dashboard NAS-health widget).
 *   - When NAS is detected as down, also flips the shared Redis kill
 *     switch `nas:force_offline` (TTL 6 minutes, so the next monitor
 *     pass refreshes or clears it). The email app's NasHealthCheck
 *     reads this key, so a down detection by this monitor takes effect
 *     in the request path within seconds without waiting for the
 *     shared storage daemon's own probe cycle.
 *   - When NAS is recovered, the kill switch is cleared so request-path
 *     reads/writes resume immediately.
 *
 * Cron entry (NOTE the path - panel deploys to /var/www/vps-admin/, see
 * panel/DEPLOY.md; the previous comment pointed at /var/www/vps-email/
 * which never existed on the panel host and meant the monitor was almost
 * certainly never installed via copy-paste from this header):
 *   *\/3 * * * * /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/scripts/NasHealthMonitor.php
 *
 * Operator check on the server (run as root):
 *   crontab -l | grep NasHealthMonitor   # confirm cron is installed
 *   ls -l /var/www/vps-admin/data/nas-health.json  # confirm it's writing
 */

// ──────────────────────────────────────────────
// Configuration
// ──────────────────────────────────────────────
define('VPN_NAME',        'synology');
define('NAS_LAN_IP',      '192.168.1.106');
define('VPN_PORT',        1194);
define('NFS_MOUNT',       '/mnt/nas-drive');
define('DDNS_HOSTNAME',   'pixelranger.synology.me');
define('NFT_TABLE',       'inet cpguard_fw');
define('NFT_SET',         'tcp_out');

define('ALERT_EMAIL',     'admin@flowone.pro');
define('ALERT_FROM_NAME', 'FlowOne Monitor');
define('ALERT_FROM_ADDR', 'monitor@flowone.pro');

define('DATA_DIR',        '/var/www/vps-admin/data');
define('STATUS_FILE',     DATA_DIR . '/nas-health.json');
define('HISTORY_FILE',    DATA_DIR . '/nas-health-history.json');
define('MAX_HISTORY',     100);

define('AUTO_RECOVERY',   true);

// ──────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────

function run(string $cmd, int $timeout = 10): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($process)) {
        return ['ok' => false, 'out' => 'Failed to start process', 'code' => -1];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $deadline = time() + $timeout;

    while (true) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if (time() >= $deadline) {
            @posix_kill($status['pid'], 9);
            proc_close($process);
            return ['ok' => false, 'out' => 'Timed out', 'code' => -1];
        }
        $stdout .= @stream_get_contents($pipes[1]);
        $stderr .= @stream_get_contents($pipes[2]);
        usleep(50000);
    }

    $stdout .= @stream_get_contents($pipes[1]);
    $stderr .= @stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    return ['ok' => $code === 0, 'out' => trim($stdout ?: $stderr), 'code' => $code];
}

function check(string $id, string $label, string $icon, bool $passed, string $message, ?string $fix = null): array
{
    return [
        'id'      => $id,
        'label'   => $label,
        'icon'    => $icon,
        'status'  => $passed ? 'ok' : 'error',
        'message' => $message,
        'fix'     => $fix,
    ];
}

function checkWarn(string $id, string $label, string $icon, string $message): array
{
    return [
        'id'      => $id,
        'label'   => $label,
        'icon'    => $icon,
        'status'  => 'warning',
        'message' => $message,
        'fix'     => null,
    ];
}

// ──────────────────────────────────────────────
// Checks
// ──────────────────────────────────────────────

function checkCpguardPort(): array
{
    $r = run('nft list set ' . NFT_TABLE . ' ' . NFT_SET . ' 2>&1');
    if (!$r['ok']) {
        return check('cpguard_port', 'CPGuard Outbound Port', 'shield',
            true, 'CPGuard nftables set not found (port filtering may be disabled)');
    }
    $has1194 = (bool)preg_match('/\b' . VPN_PORT . '\b/', $r['out']);
    return check('cpguard_port', 'CPGuard Outbound Port', 'shield',
        $has1194,
        $has1194
            ? 'Port ' . VPN_PORT . ' is in CPGuard TCP OUT allowed list'
            : 'Port ' . VPN_PORT . ' is MISSING from CPGuard TCP OUT -- outbound VPN blocked',
        $has1194 ? null : 'Add port ' . VPN_PORT . ' to CPGuard TCP OUT in the dashboard, or run: nft add element ' . NFT_TABLE . ' ' . NFT_SET . ' { ' . VPN_PORT . ' }');
}

function recoverCpguardPort(): bool
{
    $r = run('nft add element ' . NFT_TABLE . ' ' . NFT_SET . ' { ' . VPN_PORT . ' }');
    return $r['ok'];
}

function checkFirewall(): array
{
    $r = run('firewall-cmd --list-all 2>&1');
    if (!$r['ok']) {
        return checkWarn('firewall', 'Firewalld', 'local_fire_department', 'Could not query firewalld');
    }
    return check('firewall', 'Firewalld', 'local_fire_department',
        true, 'Firewalld active, outbound policy default (accept)');
}

function checkPortReachable(string $nasPublicIp): array
{
    $r = run('nc -z -w 5 ' . escapeshellarg($nasPublicIp) . ' ' . VPN_PORT . ' 2>&1', 8);
    return check('port_reachable', 'Port ' . VPN_PORT . ' Reachable', 'lan',
        $r['ok'],
        $r['ok']
            ? 'Port ' . VPN_PORT . ' is open on ' . $nasPublicIp
            : 'Port ' . VPN_PORT . ' is CLOSED on ' . $nasPublicIp . ' (router port forwarding or ISP issue)',
        $r['ok'] ? null : 'Check router port forwarding, ISP blocking, or NAS VPN server status');
}

function checkVpnService(): array
{
    $r = run('systemctl is-active openvpn-client@' . escapeshellarg(VPN_NAME) . ' 2>&1');
    $state = trim($r['out']);
    $active = ($state === 'active');
    return check('vpn_service', 'VPN Service', 'vpn_lock',
        $active,
        $active
            ? 'openvpn-client@' . VPN_NAME . ' is active'
            : 'openvpn-client@' . VPN_NAME . ' is ' . $state,
        $active ? null : 'Run: systemctl restart openvpn-client@' . VPN_NAME);
}

function recoverVpnService(): bool
{
    $r = run('systemctl restart openvpn-client@' . escapeshellarg(VPN_NAME), 15);
    if ($r['ok']) {
        sleep(3);
    }
    return $r['ok'];
}

function checkTunInterface(): array
{
    $r = run('ip addr show tun0 2>&1');
    $hasIp = (bool)preg_match('/inet\s+([\d.]+)/', $r['out']);
    return check('tun_interface', 'VPN Tunnel Interface', 'settings_ethernet',
        $hasIp,
        $hasIp
            ? 'tun0 interface is up'
            : 'tun0 interface not found or has no IP',
        $hasIp ? null : 'VPN service may need a restart');
}

function checkNasPing(): array
{
    $r = run('ping -c 1 -W 3 ' . escapeshellarg(NAS_LAN_IP) . ' 2>&1', 6);
    $latency = null;
    if (preg_match('/time[=<]([\d.]+)\s*ms/', $r['out'], $m)) {
        $latency = (float)$m[1];
    }
    $result = check('nas_ping', 'NAS Reachable', 'dns',
        $r['ok'],
        $r['ok']
            ? 'NAS responds at ' . NAS_LAN_IP . ($latency !== null ? " ({$latency}ms)" : '')
            : 'NAS unreachable at ' . NAS_LAN_IP . ' through VPN tunnel',
        $r['ok'] ? null : 'Check if the Synology NAS is powered on and its VPN server is running');
    if ($latency !== null) {
        $result['latency_ms'] = $latency;
    }
    return $result;
}

function checkNfsMount(): array
{
    $r = run('mountpoint -q ' . escapeshellarg(NFS_MOUNT) . ' 2>&1');
    return check('nfs_mount', 'NFS Mount', 'hard_drive',
        $r['ok'],
        $r['ok']
            ? NFS_MOUNT . ' is mounted'
            : NFS_MOUNT . ' is NOT mounted',
        $r['ok'] ? null : 'Run: mount ' . NFS_MOUNT);
}

function recoverNfsMount(): bool
{
    $r = run('mount ' . escapeshellarg(NFS_MOUNT), 30);
    return $r['ok'];
}

function checkNfsReadWrite(): array
{
    $testFile = rtrim(NFS_MOUNT, '/') . '/.health_monitor_test_' . getmypid();
    $content = 'health-' . time();
    $written = @file_put_contents($testFile, $content);
    if ($written === false) {
        return check('nfs_readwrite', 'NFS Read/Write', 'edit_note', false,
            'Cannot write to NFS mount (stale or permission issue)',
            'Run: umount -l ' . NFS_MOUNT . ' && mount ' . NFS_MOUNT);
    }
    $readBack = @file_get_contents($testFile);
    @unlink($testFile);
    $ok = ($readBack === $content);
    return check('nfs_readwrite', 'NFS Read/Write', 'edit_note', $ok,
        $ok ? 'NFS read/write test passed' : 'NFS read succeeded but data mismatch',
        $ok ? null : 'Possible NFS corruption -- remount the share');
}

function recoverNfsStale(): bool
{
    run('umount -l ' . escapeshellarg(NFS_MOUNT), 15);
    sleep(1);
    $r = run('mount ' . escapeshellarg(NFS_MOUNT), 30);
    return $r['ok'];
}

/**
 * Resolve DDNS hostname and return [valid => bool, ip => string].
 * Used early in the chain so the port check can use the resolved IP.
 */
function resolveDdns(): array
{
    $r = run('dig +short ' . escapeshellarg(DDNS_HOSTNAME) . ' A 2>&1', 8);
    $ip = trim($r['out']);
    $valid = !empty($ip) && filter_var($ip, FILTER_VALIDATE_IP);
    return ['valid' => $valid, 'ip' => $ip];
}

function checkDdns(array $ddns): array
{
    if ($ddns['valid']) {
        return check('ddns', 'DDNS Resolution', 'public', true,
            DDNS_HOSTNAME . ' resolves to ' . $ddns['ip']);
    }
    return checkWarn('ddns', 'DDNS Resolution', 'public',
        'Cannot resolve ' . DDNS_HOSTNAME . ' (got: ' . substr($ddns['ip'] ?: 'nothing', 0, 80) . ')');
}

// ──────────────────────────────────────────────
// Main logic
// ──────────────────────────────────────────────

function runAllChecks(): array
{
    $checks = [];
    $rootCause = null;
    $rootCauseDetail = null;
    $recovery = ['attempted' => false, 'action' => null, 'success' => null];
    $status = 'healthy';

    // Resolve DDNS first so we have the public IP for later checks
    $ddns = resolveDdns();

    $c = checkDdns($ddns);
    $checks[$c['id']] = $c;

    // 1. CPGuard
    $c = checkCpguardPort();
    $checks[$c['id']] = $c;
    if ($c['status'] === 'error') {
        $rootCause = 'CPGuard blocking outbound port ' . VPN_PORT;
        $rootCauseDetail = 'CPGuard update removed port ' . VPN_PORT . ' from the TCP OUT outbound filter';
        $status = 'down';
        if (AUTO_RECOVERY) {
            $recovery = ['attempted' => true, 'action' => 'nft add port ' . VPN_PORT . ' to tcp_out', 'success' => recoverCpguardPort()];
            if ($recovery['success']) {
                $checks[$c['id']]['status'] = 'ok';
                $checks[$c['id']]['message'] = 'Port ' . VPN_PORT . ' was missing -- auto-recovered';
                $rootCause = null;
                $status = 'healthy';
            }
        }
        if ($status === 'down') {
            return compact('status', 'rootCause', 'rootCauseDetail', 'recovery', 'checks');
        }
    }

    // 2. Firewall
    $c = checkFirewall();
    $checks[$c['id']] = $c;

    // 3. Port reachable (uses DDNS-resolved IP)
    if (!$ddns['valid']) {
        $checks['port_reachable'] = [
            'id'      => 'port_reachable',
            'label'   => 'Port ' . VPN_PORT . ' Reachable',
            'icon'    => 'lan',
            'status'  => 'warning',
            'message' => 'Skipped -- DDNS did not resolve to a valid IP',
            'fix'     => null,
        ];
    } else {
        $c = checkPortReachable($ddns['ip']);
        $checks[$c['id']] = $c;
        if ($c['status'] === 'error') {
            $rootCause = 'Port ' . VPN_PORT . ' unreachable on ' . $ddns['ip'];
            $rootCauseDetail = 'Router port forwarding broken, ISP blocking, or NAS VPN server down';
            $status = 'down';
            return compact('status', 'rootCause', 'rootCauseDetail', 'recovery', 'checks');
        }
    }

    // 4. VPN service
    $c = checkVpnService();
    $checks[$c['id']] = $c;
    if ($c['status'] === 'error') {
        $rootCause = 'OpenVPN service not running';
        $rootCauseDetail = 'openvpn-client@' . VPN_NAME . ' is not active';
        $status = 'down';
        if (AUTO_RECOVERY) {
            $recovery = ['attempted' => true, 'action' => 'systemctl restart openvpn-client@' . VPN_NAME, 'success' => recoverVpnService()];
            if ($recovery['success']) {
                $c2 = checkVpnService();
                if ($c2['status'] === 'ok') {
                    $checks[$c['id']] = $c2;
                    $checks[$c['id']]['message'] = 'VPN was down -- auto-restarted';
                    $rootCause = null;
                    $status = 'healthy';
                }
            }
        }
        if ($status === 'down') {
            return compact('status', 'rootCause', 'rootCauseDetail', 'recovery', 'checks');
        }
    }

    // 5. TUN interface
    $c = checkTunInterface();
    $checks[$c['id']] = $c;
    if ($c['status'] === 'error') {
        $rootCause = 'VPN tunnel not established';
        $rootCauseDetail = 'tun0 interface missing despite VPN service running -- connection may be initialising';
        $status = 'down';
        return compact('status', 'rootCause', 'rootCauseDetail', 'recovery', 'checks');
    }

    // 6. NAS ping
    $c = checkNasPing();
    $checks[$c['id']] = $c;
    if ($c['status'] === 'error') {
        $rootCause = 'NAS unreachable through VPN tunnel';
        $rootCauseDetail = 'VPN is up but NAS at ' . NAS_LAN_IP . ' does not respond (NAS powered off or LAN issue)';
        $status = 'down';
        return compact('status', 'rootCause', 'rootCauseDetail', 'recovery', 'checks');
    }

    // 7. NFS mount
    $c = checkNfsMount();
    $checks[$c['id']] = $c;
    if ($c['status'] === 'error') {
        $rootCause = 'NFS share not mounted';
        $rootCauseDetail = NFS_MOUNT . ' is not mounted';
        $status = 'down';
        if (AUTO_RECOVERY) {
            $recovery = ['attempted' => true, 'action' => 'mount ' . NFS_MOUNT, 'success' => recoverNfsMount()];
            if ($recovery['success']) {
                $checks[$c['id']]['status'] = 'ok';
                $checks[$c['id']]['message'] = 'NFS was not mounted -- auto-mounted';
                $rootCause = null;
                $status = 'healthy';
            }
        }
        if ($status === 'down') {
            return compact('status', 'rootCause', 'rootCauseDetail', 'recovery', 'checks');
        }
    }

    // 8. NFS read/write
    $c = checkNfsReadWrite();
    $checks[$c['id']] = $c;
    if ($c['status'] === 'error') {
        $rootCause = 'NFS mount stale or read/write failure';
        $rootCauseDetail = 'Cannot write to ' . NFS_MOUNT . ' (stale mount or permission issue)';
        $status = 'down';
        if (AUTO_RECOVERY) {
            $recovery = ['attempted' => true, 'action' => 'umount -l && mount ' . NFS_MOUNT, 'success' => recoverNfsStale()];
            if ($recovery['success']) {
                $c2 = checkNfsReadWrite();
                if ($c2['status'] === 'ok') {
                    $checks[$c['id']] = $c2;
                    $checks[$c['id']]['message'] = 'NFS was stale -- auto-remounted';
                    $rootCause = null;
                    $status = 'healthy';
                }
            }
        }
        if ($status === 'down') {
            return compact('status', 'rootCause', 'rootCauseDetail', 'recovery', 'checks');
        }
    }

    // DDNS warning (resolution failed but everything else is fine)
    if (!$ddns['valid'] && $status === 'healthy') {
        $status = 'degraded';
        $rootCause = 'DDNS resolution failed';
        $rootCauseDetail = $checks['ddns']['message'];
    }

    return compact('status', 'rootCause', 'rootCauseDetail', 'recovery', 'checks');
}

function loadPreviousStatus(): ?array
{
    if (!file_exists(STATUS_FILE)) {
        return null;
    }
    $data = @json_decode(file_get_contents(STATUS_FILE), true);
    return is_array($data) ? $data : null;
}

function saveStatus(array $result): void
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0755, true);
    }

    $payload = [
        'status'            => $result['status'],
        'root_cause'        => $result['rootCause'],
        'root_cause_detail' => $result['rootCauseDetail'],
        'auto_recovery'     => $result['recovery'],
        'checks'            => $result['checks'],
        'timestamp'         => date('Y-m-d H:i:s'),
    ];

    file_put_contents(STATUS_FILE, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Append to history
    $history = [];
    if (file_exists(HISTORY_FILE)) {
        $history = @json_decode(file_get_contents(HISTORY_FILE), true) ?: [];
    }
    $history[] = $payload;
    if (count($history) > MAX_HISTORY) {
        $history = array_slice($history, -MAX_HISTORY);
    }
    file_put_contents(HISTORY_FILE, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function stateChanged(array $current, ?array $previous): bool
{
    if ($previous === null) {
        return $current['status'] !== 'healthy';
    }
    if ($current['status'] !== ($previous['status'] ?? null)) {
        return true;
    }
    if ($current['rootCause'] !== ($previous['root_cause'] ?? null)) {
        return true;
    }
    return false;
}

function sendAlert(array $result, ?array $previous): void
{
    $status = strtoupper($result['status']);
    if ($result['status'] === 'healthy' && $previous !== null && ($previous['status'] ?? 'healthy') !== 'healthy') {
        $status = 'RECOVERED';
    }

    $rootCause = $result['rootCause'] ?? 'None';
    $subject = "[FlowOne] NAS/VPN Alert: {$status} - {$rootCause}";

    $checksHtml = '';
    foreach ($result['checks'] as $c) {
        $icon = match ($c['status']) {
            'ok'      => '<span style="color:#16a34a">&#10003;</span>',
            'error'   => '<span style="color:#dc2626">&#10007;</span>',
            'warning' => '<span style="color:#d97706">&#9888;</span>',
            default   => '<span style="color:#6b7280">&#8212;</span>',
        };
        $fixLine = !empty($c['fix']) ? "<br><small style=\"color:#6b7280\">{$c['fix']}</small>" : '';
        $checksHtml .= "<tr><td style=\"padding:6px 12px;border-bottom:1px solid #e5e7eb\">{$icon}</td>"
            . "<td style=\"padding:6px 12px;border-bottom:1px solid #e5e7eb;font-weight:600\">{$c['label']}</td>"
            . "<td style=\"padding:6px 12px;border-bottom:1px solid #e5e7eb\">{$c['message']}{$fixLine}</td></tr>";
    }

    $recoveryHtml = '';
    if ($result['recovery']['attempted']) {
        $rStatus = $result['recovery']['success'] ? 'Succeeded' : 'Failed';
        $rColor = $result['recovery']['success'] ? '#16a34a' : '#dc2626';
        $recoveryHtml = "<div style=\"margin:16px 0;padding:12px;background:#f9fafb;border-radius:8px\">"
            . "<strong>Auto-Recovery:</strong> {$result['recovery']['action']} "
            . "-- <span style=\"color:{$rColor};font-weight:600\">{$rStatus}</span></div>";
    }

    $statusColor = match ($result['status']) {
        'healthy'  => '#16a34a',
        'degraded' => '#d97706',
        default    => '#dc2626',
    };

    $vpnMessage = $result['checks']['vpn_service']['message'] ?? 'Status check';
    $timestamp = $result['timestamp'] ?? date('Y-m-d H:i:s');

    $body = <<<HTML
    <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:640px;margin:0 auto">
        <div style="background:#111827;color:white;padding:20px 24px;border-radius:12px 12px 0 0">
            <h2 style="margin:0;font-size:18px">NAS/VPN Health Monitor</h2>
            <p style="margin:4px 0 0;opacity:.7;font-size:13px">{$vpnMessage}</p>
        </div>
        <div style="background:white;padding:24px;border:1px solid #e5e7eb;border-top:0">
            <div style="display:inline-block;padding:6px 16px;border-radius:999px;background:{$statusColor};color:white;font-weight:700;font-size:14px;margin-bottom:16px">
                {$status}
            </div>

            <div style="margin:16px 0">
                <strong>Root Cause:</strong>
                <span style="color:{$statusColor}">{$rootCause}</span>
            </div>

            {$recoveryHtml}

            <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:16px">
                <thead><tr style="background:#f3f4f6">
                    <th style="padding:8px 12px;text-align:left;width:30px"></th>
                    <th style="padding:8px 12px;text-align:left;width:160px">Check</th>
                    <th style="padding:8px 12px;text-align:left">Result</th>
                </tr></thead>
                <tbody>{$checksHtml}</tbody>
            </table>

            <p style="margin:20px 0 0;font-size:12px;color:#9ca3af">
                Checked at {$result['checks']['cpguard_port']['label']} &middot; FlowOne Health Monitor
            </p>
        </div>
        <div style="background:#f9fafb;padding:12px 24px;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 12px 12px;font-size:12px;color:#6b7280">
            Timestamp: {$timestamp}
        </div>
    </div>
    HTML;

    $headers = implode("\r\n", [
        'From: ' . ALERT_FROM_NAME . ' <' . ALERT_FROM_ADDR . '>',
        'Reply-To: ' . ALERT_FROM_ADDR,
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'X-Mailer: FlowOne NAS Monitor',
    ]);

    @mail(ALERT_EMAIL, $subject, $body, $headers);
}

// ──────────────────────────────────────────────
// Entry point
// ──────────────────────────────────────────────

$previous = loadPreviousStatus();
$result   = runAllChecks();

$result['timestamp'] = date('Y-m-d H:i:s');
saveStatus($result);

// Reconcile with the request-path NAS health source (Redis kill switch
// `nas:force_offline`). When this monitor decides NAS is unusable, we
// flip the switch immediately so the email/Drive app stops attempting
// NAS reads/writes within seconds - much faster than waiting for the
// shared storage daemon's own probe cycle to converge.
//
// TTL is set just above this monitor's cadence (3 min) so a healthy
// monitor pass implicitly clears the switch by NOT refreshing it; a
// missed monitor run does not pin the system into offline mode forever.
publishRedisKillSwitch($result['status']);

if (stateChanged($result, $previous)) {
    sendAlert($result, $previous);
    echo "[" . date('Y-m-d H:i:s') . "] State changed: {$result['status']}"
        . ($result['rootCause'] ? " -- {$result['rootCause']}" : '')
        . " -- email sent\n";
} else {
    echo "[" . date('Y-m-d H:i:s') . "] Status: {$result['status']}\n";
}

/**
 * Bridge this script's view of NAS health into the runtime kill switch
 * the email app's NasHealthCheck reads. Best-effort: if Redis is down
 * the email app's own probe still works, this just adds a fast path.
 *
 * Status mapping:
 *   - 'down'     => set nas:force_offline=1 (TTL 6 minutes)
 *   - 'degraded' => leave whatever was there; degraded usually means
 *                   DDNS-only issues which don't affect VPN/NFS
 *   - 'healthy'  => delete nas:force_offline so app traffic resumes
 */
function publishRedisKillSwitch(string $status): void
{
    if (!class_exists(\Redis::class)) {
        return;
    }
    try {
        $r = new \Redis();
        if (!@$r->connect('127.0.0.1', 6379, 1.0)) {
            return;
        }
        // The email app reads BOTH the legacy `nas:force_offline` key and
        // the newer `flowone:storage:nas:force_offline` key (see
        // NasHealthCheck::isForceOffline). Touch both so either generation
        // of the lookup picks it up.
        if ($status === 'down') {
            $r->setex('nas:force_offline', 360, '1');
            $r->setex('flowone:storage:nas:force_offline', 360, '1');
        } elseif ($status === 'healthy') {
            $r->del('nas:force_offline');
            $r->del('flowone:storage:nas:force_offline');
        }
        $r->close();
    } catch (\Throwable $e) {
        error_log('[NasHealthMonitor] redis bridge failed: ' . $e->getMessage());
    }
}
