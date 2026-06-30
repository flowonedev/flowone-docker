<?php

namespace FleetManager\Api\Controllers;

use FleetManager\Api\Core\Request;
use FleetManager\Api\Core\Response;
use FleetManager\Api\Services\TaskService;
use FleetManager\Api\Services\ServerReportService;

/**
 * Fleet Agent communication controller
 * Handles heartbeats and reports from agents on managed servers
 */
class AgentController extends BaseController
{
    /**
     * Receive heartbeat from agent
     */
    public function heartbeat(Request $request): Response
    {
        $server = $this->getCurrentServer();
        if (!$server) {
            return Response::unauthorized('Invalid agent token');
        }

        $db = $this->getDatabase();

        // Update last heartbeat
        $stmt = $db->prepare("UPDATE servers SET last_heartbeat = NOW(), status = 'active' WHERE id = ?");
        $stmt->execute([$server->id]);

        // Store health data
        $health = $request->input('health');
        if ($health && is_array($health)) {
            try {
                // Sanitize service statuses: ENUM columns only allow running/stopped/error/unknown
                $validStatuses = ['running', 'stopped', 'error', 'unknown'];
                $validOpenvpn = ['running', 'stopped', 'error', 'unknown', 'disabled'];
                $svc = function(string $key) use ($health, $validStatuses): string {
                    $val = $health['services'][$key] ?? 'unknown';
                    return in_array($val, $validStatuses) ? $val : 'stopped';
                };

                $stmt = $db->prepare(
                    "INSERT INTO server_health (server_id, openlitespeed_status, mariadb_status, 
                        postfix_status, dovecot_status, fail2ban_status, firewalld_status, 
                        fleet_agent_status, openvpn_status,
                        redis_status, meilisearch_status, spamassassin_status,
                        opendkim_status, opendmarc_status, clamav_status, pdns_status,
                        coturn_status, livekit_status, stunnel_status,
                        collab_status, mailsync_status,
                        disk_total_gb, disk_used_gb, disk_percent,
                        memory_total_mb, memory_used_mb, memory_percent, cpu_load_1m, cpu_load_5m, 
                        cpu_load_15m, panel_ssl_expiry, email_ssl_expiry, uptime_seconds)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );

                $openvpnRaw = $health['services']['openvpn'] ?? 'disabled';
                $openvpnStatus = in_array($openvpnRaw, $validOpenvpn) ? $openvpnRaw : 'disabled';

                $stmt->execute([
                    $server->id,
                    $svc('openlitespeed'),
                    $svc('mariadb'),
                    $svc('postfix'),
                    $svc('dovecot'),
                    $svc('fail2ban'),
                    $svc('firewalld'),
                    'running',
                    $openvpnStatus,
                    $svc('redis'),
                    $svc('meilisearch'),
                    $svc('spamassassin'),
                    $svc('opendkim'),
                    $svc('opendmarc'),
                    $svc('clamav'),
                    $svc('pdns'),
                    $svc('coturn'),
                    $svc('livekit'),
                    $svc('stunnel'),
                    $svc('collab'),
                    $svc('mailsync'),
                    $health['disk']['total_gb'] ?? null,
                    $health['disk']['used_gb'] ?? null,
                    $health['disk']['percent'] ?? null,
                    $health['memory']['total_mb'] ?? null,
                    $health['memory']['used_mb'] ?? null,
                    $health['memory']['percent'] ?? null,
                    $health['cpu']['load_1m'] ?? null,
                    $health['cpu']['load_5m'] ?? null,
                    $health['cpu']['load_15m'] ?? null,
                    $health['ssl']['panel_expiry'] ?? null,
                    $health['ssl']['email_expiry'] ?? null,
                    $health['uptime'] ?? null,
                ]);

                // Check for issues and log them
                $this->checkAndLogIssues($server->id, $health);
            } catch (\Exception $e) {
                error_log("Fleet heartbeat: Failed to store health for server {$server->id}: " . $e->getMessage());
            }
        }

        // Update deployed versions from agent report
        $versions = $request->input('versions');
        if ($versions && is_array($versions)) {
            $versionUpdates = [];
            $versionParams = [];

            if (!empty($versions['panel'])) {
                $versionUpdates[] = 'panel_version = ?';
                $versionParams[] = $versions['panel'];
            }
            if (!empty($versions['email_app'])) {
                $versionUpdates[] = 'email_app_version = ?';
                $versionParams[] = $versions['email_app'];
            }
            if (!empty($versions['agent'])) {
                $versionUpdates[] = 'agent_version = ?';
                $versionParams[] = $versions['agent'];
            }

            if (!empty($versionUpdates)) {
                $versionParams[] = $server->id;
                $stmt = $db->prepare(
                    "UPDATE servers SET " . implode(', ', $versionUpdates) . " WHERE id = ?"
                );
                $stmt->execute($versionParams);
            }

            // OS info goes in its own guarded update: the os_info column is newer
            // (migration 022) so on an un-migrated DB this must not break the
            // heartbeat's version/health sync.
            if (!empty($versions['os'])) {
                try {
                    $stmt = $db->prepare("UPDATE servers SET os_info = ? WHERE id = ?");
                    $stmt->execute([substr((string)$versions['os'], 0, 100), $server->id]);
                } catch (\Exception $e) {
                    error_log("Fleet heartbeat: Failed to sync os_info for server {$server->id}: " . $e->getMessage());
                }
            }
        }

        // Keep the stored SSH connection in sync with the box. A deploy can apply
        // a cloned/hardened sshd_config that moves SSH to a new port and/or
        // disables password auth; without this the Fleet Manager keeps trying the
        // original port/credentials and the connection (and audits) break.
        $ssh = $request->input('ssh');
        if ($ssh && is_array($ssh)) {
            $sshUpdates = [];
            $sshParams = [];
            if (!empty($ssh['port']) && (int)$ssh['port'] > 0 && (int)$ssh['port'] <= 65535) {
                $sshUpdates[] = 'ssh_port = ?';
                $sshParams[] = (int)$ssh['port'];
            }
            if (array_key_exists('password_auth', $ssh)) {
                $sshUpdates[] = 'ssh_auth_method = ?';
                $sshParams[] = $ssh['password_auth'] ? 'password' : 'key';
            }
            if (!empty($sshUpdates)) {
                try {
                    $sshParams[] = $server->id;
                    $stmt = $db->prepare(
                        "UPDATE servers SET " . implode(', ', $sshUpdates) . " WHERE id = ?"
                    );
                    $stmt->execute($sshParams);
                } catch (\Exception $e) {
                    // ssh_auth_method column may not exist on very old schemas; never
                    // let SSH sync break the heartbeat.
                    error_log("Fleet heartbeat: Failed to sync SSH info for server {$server->id}: " . $e->getMessage());
                }
            }
        }

        // Store the pending OS/npm updates report (one row per server)
        $updates = $request->input('updates');
        if ($updates && is_array($updates)) {
            try {
                $osPending = (int)($updates['os']['count'] ?? 0);
                $npmPending = 0;
                foreach ((array)($updates['npm'] ?? []) as $app) {
                    $npmPending += (int)($app['count'] ?? 0);
                }
                $rebootRequired = !empty($updates['os']['reboot_required']) ? 1 : 0;
                // Stored as UTC; the dashboard appends 'Z' when parsing
                $checkedAt = !empty($updates['checked_at'])
                    ? gmdate('Y-m-d H:i:s', strtotime((string)$updates['checked_at']))
                    : gmdate('Y-m-d H:i:s');

                $stmt = $db->prepare(
                    "INSERT INTO server_updates
                        (server_id, os_pending, npm_pending, reboot_required, payload, checked_at)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        os_pending = VALUES(os_pending),
                        npm_pending = VALUES(npm_pending),
                        reboot_required = VALUES(reboot_required),
                        payload = VALUES(payload),
                        checked_at = VALUES(checked_at)"
                );
                $stmt->execute([
                    $server->id,
                    $osPending,
                    $npmPending,
                    $rebootRequired,
                    json_encode($updates),
                    $checkedAt,
                ]);
            } catch (\Exception $e) {
                // server_updates table is newer (migration 025); never let it
                // break the heartbeat on an un-migrated DB.
                error_log("Fleet heartbeat: Failed to store updates for server {$server->id}: " . $e->getMessage());
            }
        }

        // Get pending tasks for this agent
        $taskService = $this->container->get(TaskService::class);
        $pendingTasks = $taskService->getPendingTasks($server->id);

        // Return response with pending tasks
        return Response::success([
            'status' => 'ok',
            'server_time' => date('Y-m-d H:i:s'),
            'tasks' => $pendingTasks,
            'task_count' => count($pendingTasks),
        ]);
    }

    /**
     * Report task started
     * POST /api/agent/task/{id}/start
     */
    public function taskStart(Request $request): Response
    {
        $server = $this->getCurrentServer();
        if (!$server) {
            return Response::unauthorized('Invalid agent token');
        }

        $taskId = (int)$request->getParam('id');
        $taskService = $this->container->get(TaskService::class);
        
        // Verify task belongs to this server
        $task = $taskService->getTask($taskId);
        if (!$task || $task['server_id'] != $server->id) {
            return Response::notFound('Task not found');
        }

        $taskService->startTask($taskId);

        return Response::success(['status' => 'started']);
    }

    /**
     * Report task progress
     * POST /api/agent/task/{id}/progress
     */
    public function taskProgress(Request $request): Response
    {
        $server = $this->getCurrentServer();
        if (!$server) {
            return Response::unauthorized('Invalid agent token');
        }

        $taskId = (int)$request->getParam('id');
        $progress = (int)$request->input('progress', 0);
        $message = $request->input('message');

        $taskService = $this->container->get(TaskService::class);
        
        // Verify task belongs to this server
        $task = $taskService->getTask($taskId);
        if (!$task || $task['server_id'] != $server->id) {
            return Response::notFound('Task not found');
        }

        $taskService->updateProgress($taskId, $progress, $message);

        return Response::success(['status' => 'updated']);
    }

    /**
     * Report task completed
     * POST /api/agent/task/{id}/complete
     */
    public function taskComplete(Request $request): Response
    {
        $server = $this->getCurrentServer();
        if (!$server) {
            return Response::unauthorized('Invalid agent token');
        }

        $taskId = (int)$request->getParam('id');
        $result = $request->input('result');

        $taskService = $this->container->get(TaskService::class);
        
        // Verify task belongs to this server
        $task = $taskService->getTask($taskId);
        if (!$task || $task['server_id'] != $server->id) {
            return Response::notFound('Task not found');
        }

        $taskService->completeTask($taskId, $result);

        return Response::success(['status' => 'completed']);
    }

    /**
     * Report task failed
     * POST /api/agent/task/{id}/fail
     */
    public function taskFail(Request $request): Response
    {
        $server = $this->getCurrentServer();
        if (!$server) {
            return Response::unauthorized('Invalid agent token');
        }

        $taskId = (int)$request->getParam('id');
        $error = $request->input('error', 'Unknown error');

        $taskService = $this->container->get(TaskService::class);
        
        // Verify task belongs to this server
        $task = $taskService->getTask($taskId);
        if (!$task || $task['server_id'] != $server->id) {
            return Response::notFound('Task not found');
        }

        $taskService->failTask($taskId, $error);

        return Response::success(['status' => 'failed']);
    }

    /**
     * Report errors from agent
     */
    public function reportErrors(Request $request): Response
    {
        $server = $this->getCurrentServer();
        if (!$server) {
            return Response::unauthorized('Invalid agent token');
        }

        $errors = $request->input('errors', []);
        $db = $this->getDatabase();

        foreach ($errors as $error) {
            // Check if similar error exists (by source and message hash)
            $messageHash = md5($error['message'] ?? '');
            
            $stmt = $db->prepare(
                "SELECT id, occurrence_count FROM server_errors 
                 WHERE server_id = ? AND source = ? AND MD5(message) = ? AND resolved = 0"
            );
            $stmt->execute([$server->id, $error['source'] ?? 'unknown', $messageHash]);
            $existing = $stmt->fetch();

            if ($existing) {
                // Update existing error
                $stmt = $db->prepare(
                    "UPDATE server_errors SET occurrence_count = occurrence_count + 1, last_seen = NOW() WHERE id = ?"
                );
                $stmt->execute([$existing['id']]);
            } else {
                // Insert new error
                $stmt = $db->prepare(
                    "INSERT INTO server_errors (server_id, severity, source, message, details, log_file)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $server->id,
                    $error['severity'] ?? 'error',
                    $error['source'] ?? 'unknown',
                    $error['message'] ?? '',
                    $error['details'] ?? null,
                    $error['log_file'] ?? null,
                ]);
            }
        }

        // Update server status if critical errors
        $hasCritical = false;
        foreach ($errors as $error) {
            if (($error['severity'] ?? '') === 'critical') {
                $hasCritical = true;
                break;
            }
        }

        if ($hasCritical) {
            $stmt = $db->prepare("UPDATE servers SET status = 'error' WHERE id = ?");
            $stmt->execute([$server->id]);
        }

        return Response::success(['received' => count($errors)]);
    }

    /**
     * Report deployment progress from agent
     */
    public function reportProgress(Request $request): Response
    {
        $server = $this->getCurrentServer();
        if (!$server) {
            return Response::unauthorized('Invalid agent token');
        }

        $deploymentId = $request->input('deployment_id');
        $progress = $request->input('progress');
        $step = $request->input('step');
        $log = $request->input('log');
        $status = $request->input('status');

        $db = $this->getDatabase();

        $updates = ['progress = ?'];
        $params = [$progress];

        if ($step) {
            $updates[] = 'current_step = ?';
            $params[] = $step;
        }

        if ($log) {
            $updates[] = 'log = CONCAT(IFNULL(log, \'\'), ?)';
            $params[] = $log . "\n";
        }

        if ($status) {
            $updates[] = 'status = ?';
            $params[] = $status;

            if ($status === 'success' || $status === 'failed') {
                $updates[] = 'completed_at = NOW()';
            }
        }

        $params[] = $deploymentId;
        $params[] = $server->id;

        $stmt = $db->prepare(
            "UPDATE deployments SET " . implode(', ', $updates) . " WHERE id = ? AND server_id = ?"
        );
        $stmt->execute($params);

        return Response::success(null);
    }

    /**
     * Get agent configuration
     */
    public function getConfig(Request $request): Response
    {
        $server = $this->getCurrentServer();
        if (!$server) {
            return Response::unauthorized('Invalid agent token');
        }

        $db = $this->getDatabase();

        // Get full server config
        $stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$server->id]);
        $serverData = $stmt->fetch();

        return Response::success([
            'server_id' => $server->id,
            'heartbeat_interval' => $this->container->getConfig('agent.heartbeat_interval'),
            'panel_domain' => $serverData['panel_domain'],
            'email_domain' => $serverData['email_domain'],
            'vpn_enabled' => (bool)$serverData['vpn_enabled'],
            'nas_enabled' => (bool)$serverData['nas_enabled'],
        ]);
    }

    /**
     * Check health data for issues and log them
     */
    private function checkAndLogIssues(int $serverId, array $health): void
    {
        try {
            $reportService = $this->container->get(ServerReportService::class);
            
            // Check services
            $criticalServices = ['openlitespeed', 'mariadb', 'postfix', 'dovecot'];
            foreach ($criticalServices as $service) {
                $status = $health['services'][$service] ?? 'unknown';
                if ($status === 'stopped' || $status === 'failed') {
                    $reportService->logIssue(
                        $serverId,
                        'service_down',
                        "Service {$service} is {$status}",
                        ['service' => $service, 'status' => $status]
                    );
                }
            }

            // Check disk usage (warn at 80%, critical at 90%)
            $diskPercent = $health['disk']['percent'] ?? 0;
            if ($diskPercent >= 90) {
                $reportService->logIssue(
                    $serverId,
                    'disk_critical',
                    "Disk usage critical: {$diskPercent}%",
                    ['percent' => $diskPercent, 'used_gb' => $health['disk']['used_gb'] ?? 0]
                );
            } elseif ($diskPercent >= 80) {
                $reportService->logIssue(
                    $serverId,
                    'disk_warning',
                    "Disk usage high: {$diskPercent}%",
                    ['percent' => $diskPercent, 'used_gb' => $health['disk']['used_gb'] ?? 0]
                );
            }

            // Check memory usage (warn at 85%, critical at 95%)
            $memPercent = $health['memory']['percent'] ?? 0;
            if ($memPercent >= 95) {
                $reportService->logIssue(
                    $serverId,
                    'memory_critical',
                    "Memory usage critical: {$memPercent}%",
                    ['percent' => $memPercent, 'used_mb' => $health['memory']['used_mb'] ?? 0]
                );
            } elseif ($memPercent >= 85) {
                $reportService->logIssue(
                    $serverId,
                    'memory_warning',
                    "Memory usage high: {$memPercent}%",
                    ['percent' => $memPercent, 'used_mb' => $health['memory']['used_mb'] ?? 0]
                );
            }

            // Check CPU load (warn at 5.0, critical at 10.0 for 5m average)
            $cpuLoad = $health['cpu']['load_5m'] ?? 0;
            if ($cpuLoad >= 10.0) {
                $reportService->logIssue(
                    $serverId,
                    'cpu_critical',
                    "CPU load critical: {$cpuLoad}",
                    ['load_1m' => $health['cpu']['load_1m'] ?? 0, 'load_5m' => $cpuLoad, 'load_15m' => $health['cpu']['load_15m'] ?? 0]
                );
            } elseif ($cpuLoad >= 5.0) {
                $reportService->logIssue(
                    $serverId,
                    'cpu_warning',
                    "CPU load high: {$cpuLoad}",
                    ['load_1m' => $health['cpu']['load_1m'] ?? 0, 'load_5m' => $cpuLoad, 'load_15m' => $health['cpu']['load_15m'] ?? 0]
                );
            }

            // Check SSL expiry (warn at 30 days, critical at 7 days)
            foreach (['panel_expiry', 'email_expiry'] as $sslKey) {
                $expiry = $health['ssl'][$sslKey] ?? null;
                if ($expiry) {
                    $daysLeft = (strtotime($expiry) - time()) / 86400;
                    $domain = $sslKey === 'panel_expiry' ? 'panel' : 'email';
                    
                    if ($daysLeft <= 7 && $daysLeft > 0) {
                        $reportService->logIssue(
                            $serverId,
                            'ssl_critical',
                            "SSL certificate for {$domain} expires in " . round($daysLeft) . " days",
                            ['domain' => $domain, 'expiry' => $expiry, 'days_left' => round($daysLeft)]
                        );
                    } elseif ($daysLeft <= 30 && $daysLeft > 7) {
                        $reportService->logIssue(
                            $serverId,
                            'ssl_warning',
                            "SSL certificate for {$domain} expires in " . round($daysLeft) . " days",
                            ['domain' => $domain, 'expiry' => $expiry, 'days_left' => round($daysLeft)]
                        );
                    } elseif ($daysLeft <= 0) {
                        $reportService->logIssue(
                            $serverId,
                            'ssl_expired',
                            "SSL certificate for {$domain} has EXPIRED",
                            ['domain' => $domain, 'expiry' => $expiry]
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently ignore logging errors - don't break heartbeat
            error_log("Issue logging failed for server {$serverId}: " . $e->getMessage());
        }
    }
}

