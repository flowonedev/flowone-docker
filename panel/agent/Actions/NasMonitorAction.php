<?php
/**
 * NAS Monitor Action
 *
 * On-demand health checks and status retrieval for the NAS/VPN/NFS chain.
 *
 * As of Phase 1 of the unified storage architecture (see
 * shared/docs/INVARIANTS.md):
 *
 *   - actionGetStatus()  prefers the shared FlowOne\Storage\StorageHealth
 *     client (single signed source of truth), falling back to the legacy
 *     /var/www/vps-admin/data/nas-health.json file when the daemon has
 *     not yet rolled out.
 *
 *   - actionHealthCheck() still runs the inline chain because the
 *     dashboard's "run check now" button explicitly asks for a fresh
 *     probe; we keep the existing behaviour and ALSO surface the shared
 *     client's status alongside it in the response so the operator can
 *     spot drift between the daemon and an ad-hoc probe.
 *
 *   - actionGetHistory() is unchanged (the rolling history file is the
 *     legacy cron's responsibility; Phase 2/3 introduces the journal as
 *     the canonical event log).
 *
 * Invariant I-11 (MountLock): this action no longer performs any
 * mount/umount/systemctl operations directly. Recovery actions go through
 * the privileged flowone-storage-helper.
 */

namespace VpsAdmin\Agent\Actions;

use FlowOne\Storage\Config as StorageConfig;
use FlowOne\Storage\StorageHealth;
use VpsAdmin\Agent\Lib\BaseAction;

class NasMonitorAction extends BaseAction
{
    private const DATA_DIR     = '/var/www/vps-admin/data';
    private const STATUS_FILE  = self::DATA_DIR . '/nas-health.json';
    private const HISTORY_FILE = self::DATA_DIR . '/nas-health-history.json';

    public function getNamespace(): string
    {
        return 'nasmonitor';
    }

    public function getMethods(): array
    {
        return ['healthCheck', 'getStatus', 'getHistory'];
    }

    public function requiresBackup(string $method): bool
    {
        return false;
    }

    /**
     * NAS/VPN connectivity settings from the shared storage config
     * (shared/config/storage.php merged with /etc/flowone/storage.local.php).
     * No values are hardcoded here: a fleet-provisioned box without NAS gets
     * enabled=false via its local override and this action then reports
     * "not configured" instead of probing someone else's NAS.
     *
     * @return array{enabled:bool, lan_ip:string, ddns:string, mount:string,
     *               vpn_name:string, vpn_port:int, nft_set:string}
     */
    private function nasSettings(): array
    {
        $defaults = [
            'enabled'  => false,
            'lan_ip'   => '',
            'ddns'     => '',
            'mount'    => '',
            'vpn_name' => '',
            'vpn_port' => 0,
            'nft_set'  => 'tcp_out',
        ];

        if (!class_exists(StorageConfig::class)) {
            return $defaults;
        }

        try {
            $cfg = StorageConfig::load();
        } catch (\Throwable $e) {
            error_log('[NasMonitorAction] storage config unavailable: ' . $e->getMessage());
            return $defaults;
        }

        return [
            'enabled'  => (bool) ($cfg['nas']['enabled'] ?? true),
            'lan_ip'   => (string) ($cfg['nas']['lan_ip'] ?? ''),
            'ddns'     => (string) ($cfg['nas']['ddns_hostname'] ?? ''),
            'mount'    => (string) ($cfg['nas']['mount_point'] ?? ''),
            'vpn_name' => (string) ($cfg['vpn']['name'] ?? ''),
            'vpn_port' => (int) ($cfg['vpn']['port'] ?? 0),
            'nft_set'  => (string) ($cfg['firewall']['nft_set'] ?? 'tcp_out'),
        ];
    }

    /** Uniform "no NAS on this box" payload for both status + healthCheck. */
    private function notConfiguredPayload(): array
    {
        return [
            'status'            => 'not_configured',
            'root_cause'        => null,
            'root_cause_detail' => null,
            'auto_recovery'     => ['attempted' => false, 'action' => null, 'success' => null],
            'checks'            => [],
            'timestamp'         => date('Y-m-d H:i:s'),
            'message'           => 'No NAS is configured for this server. Enable it in Fleet Manager (server settings) or via /etc/flowone/storage.local.php.',
        ];
    }

    /**
     * On-demand chain probe. Runs the full diagnostic chain inline AND
     * also reports the shared-daemon status if available, so the dashboard
     * can show both the ad-hoc result and the daemon's current view.
     */
    public function actionHealthCheck(array $params, string $actor): array
    {
        $nas = $this->nasSettings();
        if (!$nas['enabled']) {
            return $this->success($this->notConfiguredPayload(), 'No NAS configured on this server');
        }

        $checks = [];
        $rootCause = null;
        $rootCauseDetail = null;
        $status = 'healthy';

        // Resolve DDNS to get the current public IP (no hardcoded IP)
        $r = $this->execCommand($this->which('dig'), ['+short', $nas['ddns'], 'A']);
        $nasPublicIp = trim($r['output']);
        $ddnsResolved = !empty($nasPublicIp) && filter_var($nasPublicIp, FILTER_VALIDATE_IP);

        $checks['ddns'] = [
            'status'  => $ddnsResolved ? 'ok' : 'warning',
            'label'   => 'DDNS Resolution',
            'icon'    => 'public',
            'message' => $ddnsResolved
                ? $nas['ddns'] . ' -> ' . $nasPublicIp
                : 'Cannot resolve ' . $nas['ddns'] . ' (got: ' . substr($nasPublicIp ?: 'nothing', 0, 80) . ')',
        ];

        $r = $this->execCommand($this->which('nft'), ['list', 'set', 'inet', 'cpguard_fw', $nas['nft_set']]);
        $hasVpnPort = $r['success'] && preg_match('/\b' . $nas['vpn_port'] . '\b/', $r['output']);
        $checks['cpguard_port'] = [
            'status'  => (!$r['success'] || $hasVpnPort) ? 'ok' : 'error',
            'label'   => 'CPGuard Outbound Port',
            'icon'    => 'shield',
            'message' => $hasVpnPort
                ? 'Port ' . $nas['vpn_port'] . ' in CPGuard TCP OUT'
                : ($r['success'] ? 'Port ' . $nas['vpn_port'] . ' MISSING from CPGuard TCP OUT' : 'CPGuard set not found (filtering may be disabled)'),
        ];
        if ($checks['cpguard_port']['status'] === 'error') {
            $rootCause = 'CPGuard blocking outbound port ' . $nas['vpn_port'];
            $rootCauseDetail = 'CPGuard update removed port ' . $nas['vpn_port'] . ' from TCP OUT';
            $status = 'down';
        }

        $checks['firewall'] = [
            'status'  => 'ok',
            'label'   => 'Firewalld',
            'icon'    => 'local_fire_department',
            'message' => 'Outbound policy default (accept)',
        ];

        if ($status !== 'down') {
            if (!$ddnsResolved) {
                $checks['port_reachable'] = [
                    'status'  => 'warning',
                    'label'   => 'Port ' . $nas['vpn_port'] . ' Reachable',
                    'icon'    => 'lan',
                    'message' => 'Skipped -- DDNS did not resolve to a valid IP',
                ];
            } else {
                $r = $this->execCommand($this->which('nc'), ['-z', '-w', '5', $nasPublicIp, (string) $nas['vpn_port']]);
                $checks['port_reachable'] = [
                    'status'  => $r['success'] ? 'ok' : 'error',
                    'label'   => 'Port ' . $nas['vpn_port'] . ' Reachable',
                    'icon'    => 'lan',
                    'message' => $r['success']
                        ? 'Port open on ' . $nasPublicIp
                        : 'Port CLOSED on ' . $nasPublicIp,
                ];
                if (!$r['success']) {
                    $rootCause = 'Port ' . $nas['vpn_port'] . ' unreachable';
                    $rootCauseDetail = 'Router port forwarding, ISP, or NAS VPN server issue';
                    $status = 'down';
                }
            }
        }

        if ($status !== 'down') {
            $r = $this->execCommand($this->which('systemctl'), ['is-active', 'openvpn-client@' . $nas['vpn_name']]);
            $active = trim($r['output']) === 'active';
            $checks['vpn_service'] = [
                'status'  => $active ? 'ok' : 'error',
                'label'   => 'VPN Service',
                'icon'    => 'vpn_lock',
                'message' => $active ? 'Active' : trim($r['output']),
            ];
            if (!$active) {
                $rootCause = 'OpenVPN service not running';
                $rootCauseDetail = 'openvpn-client@' . $nas['vpn_name'] . ' is ' . trim($r['output']);
                $status = 'down';
            }
        }

        if ($status !== 'down') {
            $r = $this->execCommand($this->which('ip'), ['addr', 'show', 'tun0']);
            $hasTun = (bool)preg_match('/inet\s+([\d.]+)/', $r['output']);
            $checks['tun_interface'] = [
                'status'  => $hasTun ? 'ok' : 'error',
                'label'   => 'VPN Tunnel',
                'icon'    => 'settings_ethernet',
                'message' => $hasTun ? 'tun0 is up' : 'tun0 not found',
            ];
            if (!$hasTun) {
                $rootCause = 'VPN tunnel not established';
                $rootCauseDetail = 'tun0 missing despite service running';
                $status = 'down';
            }
        }

        if ($status !== 'down') {
            $r = $this->execCommand($this->which('ping'), ['-c', '1', '-W', '3', $nas['lan_ip']]);
            $latency = null;
            if (preg_match('/time[=<]([\d.]+)\s*ms/', $r['output'], $m)) {
                $latency = (float)$m[1];
            }
            $checks['nas_ping'] = [
                'status'     => $r['success'] ? 'ok' : 'error',
                'label'      => 'NAS Reachable',
                'icon'       => 'dns',
                'message'    => $r['success'] ? 'Responds' . ($latency ? " ({$latency}ms)" : '') : 'Unreachable',
                'latency_ms' => $latency,
            ];
            if (!$r['success']) {
                $rootCause = 'NAS unreachable through VPN';
                $rootCauseDetail = 'NAS at ' . $nas['lan_ip'] . ' does not respond';
                $status = 'down';
            }
        }

        if ($status !== 'down') {
            $r = $this->execCommand($this->which('mountpoint'), ['-q', $nas['mount']]);
            $checks['nfs_mount'] = [
                'status'  => $r['success'] ? 'ok' : 'error',
                'label'   => 'NFS Mount',
                'icon'    => 'hard_drive',
                'message' => $r['success'] ? 'Mounted' : 'Not mounted',
            ];
            if (!$r['success']) {
                $rootCause = 'NFS not mounted';
                $rootCauseDetail = $nas['mount'] . ' is not mounted';
                $status = 'down';
            }
        }

        if ($status !== 'down') {
            $testFile = rtrim($nas['mount'], '/') . '/.health_agent_test_' . getmypid();
            $content = 'agent-' . time();
            $written = @file_put_contents($testFile, $content);
            $readBack = ($written !== false) ? @file_get_contents($testFile) : false;
            @unlink($testFile);
            $ok = ($readBack === $content);
            $checks['nfs_readwrite'] = [
                'status'  => $ok ? 'ok' : 'error',
                'label'   => 'NFS Read/Write',
                'icon'    => 'edit_note',
                'message' => $ok ? 'Passed' : 'Failed (stale or permission issue)',
            ];
            if (!$ok) {
                $rootCause = 'NFS read/write failure';
                $rootCauseDetail = 'Cannot write to ' . self::NFS_MOUNT;
                $status = 'down';
            }
        }

        $payload = [
            'status'            => $status,
            'root_cause'        => $rootCause,
            'root_cause_detail' => $rootCauseDetail,
            'auto_recovery'     => ['attempted' => false, 'action' => null, 'success' => null],
            'checks'            => $checks,
            'timestamp'         => date('Y-m-d H:i:s'),
            'shared_daemon'     => $this->readSharedDaemonStatus(),
        ];

        if (!is_dir(self::DATA_DIR)) {
            @mkdir(self::DATA_DIR, 0755, true);
        }
        @file_put_contents(self::STATUS_FILE, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $this->success($payload, 'Health check complete');
    }

    /**
     * Cached status. Prefers the shared daemon's signed state; falls back
     * to the legacy JSON file when the daemon hasn't published yet.
     */
    public function actionGetStatus(array $params, string $actor): array
    {
        if (!$this->nasSettings()['enabled']) {
            return $this->success($this->notConfiguredPayload());
        }

        $shared = $this->readSharedDaemonStatus();
        if ($shared !== null && !$shared['is_stale']) {
            return $this->success($this->mapSharedToLegacyShape($shared));
        }

        if (!file_exists(self::STATUS_FILE)) {
            // No daemon, no legacy file — surface what we DO have so the
            // dashboard renders something useful instead of "no data".
            if ($shared !== null) {
                return $this->success($this->mapSharedToLegacyShape($shared));
            }
            return $this->success([
                'status'    => 'unknown',
                'checks'    => [],
                'timestamp' => null,
                'message'   => 'No health check has run yet',
            ]);
        }

        $data = @json_decode(file_get_contents(self::STATUS_FILE), true);
        if (!is_array($data)) {
            return $this->error('Corrupt status file');
        }
        $data['shared_daemon'] = $shared;
        return $this->success($data);
    }

    public function actionGetHistory(array $params, string $actor): array
    {
        $limit = min((int)($params['limit'] ?? 20), 100);

        if (!file_exists(self::HISTORY_FILE)) {
            return $this->success(['entries' => [], 'count' => 0]);
        }

        $history = @json_decode(file_get_contents(self::HISTORY_FILE), true);
        if (!is_array($history)) {
            return $this->error('Corrupt history file');
        }

        $entries = array_slice($history, -$limit);
        $entries = array_reverse($entries);

        return $this->success(['entries' => $entries, 'count' => count($entries)]);
    }

    /**
     * Resolve a command to its full path so the agent works even when
     * systemd provides a minimal PATH.
     */
    private function which(string $cmd): string
    {
        foreach (['/usr/sbin', '/usr/bin', '/sbin', '/bin', '/usr/local/bin', '/usr/local/sbin'] as $dir) {
            if (file_exists("{$dir}/{$cmd}")) {
                return "{$dir}/{$cmd}";
            }
        }
        return $cmd;
    }

    /**
     * Read the shared daemon's signed status. Returns null silently when
     * the daemon is not installed / not running / config missing — we
     * never want this to block the dashboard.
     *
     * @return array<string,mixed>|null
     */
    private function readSharedDaemonStatus(): ?array
    {
        if (!class_exists(StorageHealth::class)) {
            return null;
        }
        try {
            StorageHealth::resetProcessCache();
            $client = StorageHealth::fromConfig();
            return $client->getStatus()->toArray();
        } catch (\Throwable $e) {
            error_log('[NasMonitorAction] shared StorageHealth unavailable: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Map the shared daemon payload into the legacy dashboard shape so
     * the frontend doesn't need a v2 release to keep working.
     */
    private function mapSharedToLegacyShape(array $shared): array
    {
        $statusMap = [
            'healthy'  => 'healthy',
            'degraded' => 'degraded',
            'offline'  => 'down',
            'unknown'  => 'unknown',
        ];
        $legacy = [
            'status'            => $statusMap[$shared['status']] ?? 'unknown',
            'root_cause'        => $shared['root_cause'] ?? null,
            'root_cause_detail' => $shared['root_cause_detail'] ?? null,
            // Surface the daemon's auto-recovery activity (published by
            // RecoveryOrchestrator) so the dashboard can show that the
            // system reconnected itself, with no operator input.
            'auto_recovery'     => (isset($shared['auto_recovery']) && is_array($shared['auto_recovery']))
                ? $shared['auto_recovery']
                : ['attempted' => false, 'action' => null, 'success' => null],
            'checks'            => [],
            'timestamp'         => $shared['published_at'] ? date('Y-m-d H:i:s', (int) $shared['published_at']) : date('Y-m-d H:i:s'),
            'shared_daemon'     => $shared,
        ];
        // Translate the daemon's typed checks into the icon-rich shape
        // the dashboard expects.
        $iconMap = [
            'nas_health_file' => ['label' => 'NAS Reachable', 'icon' => 'dns'],
            'nas_read_write'  => ['label' => 'NFS Read/Write', 'icon' => 'edit_note'],
            'helper_socket'   => ['label' => 'Storage Helper', 'icon' => 'settings_input_component'],
        ];
        foreach ($shared['checks'] ?? [] as $name => $check) {
            $legacy['checks'][$name] = [
                'status'  => $check['status'] ?? 'unknown',
                'label'   => $iconMap[$name]['label'] ?? ucwords(str_replace('_', ' ', $name)),
                'icon'    => $iconMap[$name]['icon']  ?? 'help_outline',
                'message' => $check['message'] ?? '',
            ];
        }
        return $legacy;
    }
}
