<?php

declare(strict_types=1);

namespace FlowOne\Storage;

use FlowOne\Storage\Breakers\RecoveryBreaker;
use FlowOne\Storage\Exceptions\HelperRpcException;

/**
 * Automatic NAS/VPN recovery driver.
 *
 * The monitor daemon is read-only by contract: it probes and publishes
 * state but never performs privileged actions. Before this class existed,
 * recovery required a human pressing "Mount"/"Test" in the Panel, which is
 * why a NAS that came back after an outage (e.g. power loss) stayed DOWN
 * until someone logged in.
 *
 * This orchestrator closes that gap WITHOUT violating the privilege split:
 * it only *requests* privileged operations over the helper socket (the
 * sanctioned mechanism — SO_PEERCRED + MountLock + boot-epoch are enforced
 * on the helper side). The monitor still never mounts or restarts services
 * directly.
 *
 * Staged recovery (cheapest first):
 *   1. mount_nfs                       — remount a dropped/stale NFS share.
 *   2. systemctl restart <vpn>, settle, mount_nfs
 *                                      — when the share is unreachable
 *                                        because the VPN tunnel died (the
 *                                        power-outage case).
 *
 * Bounded by RecoveryBreaker (attempts / quarantine / permanent) so a
 * genuinely dead NAS is not hammered, and skipped entirely when the
 * operator freeze flag is present. On success it clears the request-path
 * kill switch (nas:force_offline) so the email/Drive app resumes reads
 * immediately, then the daemon's next probe republishes HEALTHY.
 */
final class RecoveryOrchestrator
{
    public function __construct(
        private HelperClient $helper,
        private RecoveryBreaker $breaker,
        private OperationJournal $journal,
        private int $bootEpoch,
        private array $config,
        private ?\Redis $redis = null,
    ) {}

    /**
     * Attempt recovery if the current state warrants it. Returns an
     * auto_recovery block suitable for embedding in the published payload:
     *   { attempted: bool, action: ?string, success: ?bool, breaker: array }
     *
     * @param array<string,mixed> $probe   raw probe outcomes from runProbes()
     * @return array<string,mixed>
     */
    public function maybeRecover(array $probe, string $status): array
    {
        // Healthy: nothing to do. Reset the breaker cycle and lift the
        // request-path kill switch so consumers resume immediately.
        if ($status === HealthState::HEALTHY) {
            $this->breaker->recordSuccess();
            $this->clearKillSwitch();
            return $this->block(false, null, null);
        }

        // Only act when reads/writes are actually failing — a remount or
        // VPN bounce can't help a merely-slow ('degraded') mount.
        $readOk  = (bool) ($probe['read_ok'] ?? false);
        $writeOk = (bool) ($probe['write_ok'] ?? false);
        if ($readOk && $writeOk) {
            return $this->block(false, null, null);
        }

        // Operator freeze: never auto-recover during a maintenance freeze.
        if ($this->isFrozen()) {
            $this->journal->record('auto_recovery_skipped', ['reason' => 'frozen']);
            return $this->block(false, 'frozen', null);
        }

        // No helper => we cannot perform privileged recovery at all.
        if (!($probe['helper_up'] ?? false) && !$this->helper->ping()) {
            $this->journal->record('auto_recovery_skipped', ['reason' => 'helper_unreachable']);
            return $this->block(false, 'helper_unreachable', false);
        }

        // Circuit breaker: respect quarantine / permanent block.
        if (!$this->breaker->canAttempt()) {
            $this->journal->record('auto_recovery_skipped', [
                'reason'  => 'breaker',
                'breaker' => $this->breaker->snapshot(),
            ]);
            return $this->block(false, 'quarantined', false);
        }

        $this->breaker->recordAttempt();
        $this->journal->record('auto_recovery_started', [
            'status'     => $status,
            'root_cause' => $probe['primary_failure'] ?? null,
            'breaker'    => $this->breaker->snapshot(),
        ]);

        $actions = [];

        // Stage 1: remount (covers a dropped/stale NFS share when the
        // tunnel is still up).
        $actions[] = 'mount_nfs';
        $this->safeCall('mount_nfs');
        if ($this->healthFilePresent()) {
            return $this->finishSuccess($actions);
        }

        // Stage 2: restart the VPN, let the tunnel settle, remount. This is
        // the power-outage path: the tunnel died, so the share is
        // unreachable until the tunnel is back.
        $vpnUnit = (string) ($this->config['vpn']['service_unit'] ?? 'openvpn-client@synology');
        $actions[] = 'systemctl restart ' . $vpnUnit;
        $this->safeCall('systemctl', ['action' => 'restart', 'unit' => $vpnUnit]);

        $settle = (int) ($this->config['recovery']['vpn_settle_sec'] ?? 5);
        if ($settle > 0) {
            sleep($settle);
        }

        $actions[] = 'mount_nfs';
        $this->safeCall('mount_nfs');
        if ($this->healthFilePresent()) {
            return $this->finishSuccess($actions);
        }

        // Still down — record the failure (may trip the breaker into
        // quarantine, preventing a hammer loop).
        $this->breaker->recordFailure();
        $this->journal->record('auto_recovery_failed', [
            'actions' => $actions,
            'breaker' => $this->breaker->snapshot(),
        ]);
        return $this->block(true, implode(' -> ', $actions), false);
    }

    /**
     * @param list<string> $actions
     * @return array<string,mixed>
     */
    private function finishSuccess(array $actions): array
    {
        $this->breaker->recordSuccess();
        $this->clearKillSwitch();
        $this->journal->record('auto_recovery_success', [
            'actions' => $actions,
            'breaker' => $this->breaker->snapshot(),
        ]);
        return $this->block(true, implode(' -> ', $actions), true);
    }

    /** @param array<string,mixed> $args */
    private function safeCall(string $action, array $args = []): void
    {
        try {
            $resp = $this->helper->call($action, $args, null, $this->bootEpoch);
            $this->journal->record('auto_recovery_helper_call', [
                'action' => $action,
                'ok'     => $resp['ok'] ?? false,
                'error'  => $resp['error'] ?? null,
            ]);
        } catch (HelperRpcException $e) {
            $this->journal->record('auto_recovery_helper_error', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    private function healthFilePresent(): bool
    {
        $mount = rtrim((string) ($this->config['nas']['mount_point'] ?? '/mnt/nas-drive'), '/');
        $file  = (string) ($this->config['nas']['health_file'] ?? '.healthcheck');
        $path  = $mount . '/' . $file;
        clearstatcache(true, $path);
        return @file_exists($path);
    }

    private function isFrozen(): bool
    {
        $flag = rtrim((string) ($this->config['state']['dir'] ?? '/var/lib/flowone'), '/')
            . '/' . (string) ($this->config['state']['freeze_flag'] ?? 'freeze.flag');
        return is_file($flag);
    }

    private function clearKillSwitch(): void
    {
        if ($this->redis === null) {
            return;
        }
        try {
            // The email app reads BOTH the legacy and namespaced keys
            // (see NasHealthCheck::isForceOffline). Clear both.
            $this->redis->del('nas:force_offline');
            $this->redis->del('flowone:storage:nas:force_offline');
        } catch (\Throwable $e) {
            $this->journal->record('auto_recovery_killswitch_clear_failed', ['error' => $e->getMessage()]);
        }
    }

    /** @return array<string,mixed> */
    private function block(bool $attempted, ?string $action, ?bool $success): array
    {
        return [
            'attempted' => $attempted,
            'action'    => $action,
            'success'   => $success,
            'breaker'   => $this->breaker->snapshot(),
        ];
    }
}
