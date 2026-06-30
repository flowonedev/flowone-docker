<?php

namespace FleetManager\Api\Controllers;

use FleetManager\Api\Core\Request;
use FleetManager\Api\Core\Response;

/**
 * Dashboard overview controller
 */
class DashboardController extends BaseController
{
    /**
     * Get dashboard overview
     */
    public function index(Request $request): Response
    {
        $db = $this->getDatabase();

        // Server counts by status
        $stmt = $db->query(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'offline' THEN 1 ELSE 0 END) as offline,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error,
                SUM(CASE WHEN status = 'provisioning' THEN 1 ELSE 0 END) as provisioning,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
             FROM servers"
        );
        $serverStats = $stmt->fetch();

        // Unresolved errors count
        $errorCount = $db->query("SELECT COUNT(*) FROM server_errors WHERE resolved = 0")->fetchColumn();

        // Recent deployments
        $stmt = $db->query(
            "SELECT d.*, s.name as server_name
             FROM deployments d
             JOIN servers s ON d.server_id = s.id
             ORDER BY d.created_at DESC
             LIMIT 5"
        );
        $recentDeployments = $stmt->fetchAll();

        foreach ($recentDeployments as &$d) {
            if (!empty($d['preflight_results']) && is_string($d['preflight_results'])) {
                $d['preflight_results'] = json_decode($d['preflight_results'], true);
            }
        }
        unset($d);

        // Servers needing attention (offline, error, or stale heartbeat)
        $stmt = $db->query(
            "SELECT id, name, ip_address, status, last_heartbeat, last_error
             FROM servers
             WHERE status IN ('offline', 'error')
                OR (status = 'active' AND last_heartbeat < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
             ORDER BY last_heartbeat ASC
             LIMIT 10"
        );
        $serversNeedingAttention = $stmt->fetchAll();

        // Blueprint count
        $blueprintCount = $db->query("SELECT COUNT(*) FROM blueprints")->fetchColumn();

        // Recent critical errors
        $stmt = $db->query(
            "SELECT e.*, s.name as server_name
             FROM server_errors e
             JOIN servers s ON e.server_id = s.id
             WHERE e.resolved = 0 AND e.severity IN ('critical', 'error')
             ORDER BY e.last_seen DESC
             LIMIT 10"
        );
        $recentErrors = $stmt->fetchAll();

        return Response::success([
            'servers' => [
                'total' => (int)$serverStats['total'],
                'active' => (int)$serverStats['active'],
                'offline' => (int)$serverStats['offline'],
                'error' => (int)$serverStats['error'],
                'provisioning' => (int)$serverStats['provisioning'],
                'pending' => (int)$serverStats['pending'],
            ],
            'errors' => [
                'unresolved' => (int)$errorCount,
            ],
            'blueprints' => (int)$blueprintCount,
            'recent_deployments' => $recentDeployments,
            'servers_needing_attention' => $serversNeedingAttention,
            'recent_errors' => $recentErrors,
        ]);
    }
}

