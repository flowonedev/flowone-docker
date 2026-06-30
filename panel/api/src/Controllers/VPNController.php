<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * VPN Connection Management Controller
 * 
 * Handles OpenVPN client connections - create, delete, start, stop, status.
 * Only super_admin can access these endpoints.
 */
class VPNController extends BaseController
{
    /**
     * List all VPN connections with live status
     */
    public function index(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        // Get live status from agent
        $result = $this->agent->execute('vpn.list', [], $this->getActor());
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to list VPN connections');
        }

        // Merge with database records for additional info
        try {
            $db = $this->container->getDatabase();
            $stmt = $db->query("SELECT * FROM vpn_connections");
            $dbRecords = $stmt->fetchAll();
            
            $dbMap = [];
            foreach ($dbRecords as $record) {
                $dbMap[$record['name']] = $record;
            }
            
            // Enrich agent data with database info
            $connections = $result['data']['connections'] ?? [];
            foreach ($connections as &$conn) {
                if (isset($dbMap[$conn['name']])) {
                    $conn['description'] = $dbMap[$conn['name']]['description'];
                    $conn['notes'] = $dbMap[$conn['name']]['notes'];
                    $conn['server_address'] = $dbMap[$conn['name']]['server_address'];
                    $conn['db_id'] = $dbMap[$conn['name']]['id'];
                }
            }
            
            return Response::success([
                'connections' => $connections,
                'count' => count($connections),
            ]);
        } catch (\PDOException $e) {
            // Return agent data without DB enrichment
            return Response::success($result['data']);
        }
    }

    /**
     * Get single VPN connection details
     */
    public function show(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $name = $request->getParam('name');
        
        $result = $this->agent->execute('vpn.status', ['name' => $name], $this->getActor());
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'VPN connection not found');
        }

        // Get additional info from database
        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare("SELECT * FROM vpn_connections WHERE name = ?");
            $stmt->execute([$name]);
            $dbRecord = $stmt->fetch();
            
            if ($dbRecord) {
                $result['data']['description'] = $dbRecord['description'];
                $result['data']['notes'] = $dbRecord['notes'];
                $result['data']['server_address'] = $dbRecord['server_address'];
                $result['data']['db_id'] = $dbRecord['id'];
            }
        } catch (\PDOException $e) {
            // Continue without DB data
        }

        return Response::success($result['data']);
    }

    /**
     * Create new VPN connection
     */
    public function create(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $validation = $this->validateRequired($request, ['name', 'config_content']);
        if ($validation) return $validation;
        
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $request->input('name'));
        
        if (empty($name)) {
            return Response::validationError(['name' => 'Invalid VPN name - use only letters, numbers, dash, underscore']);
        }

        // Create config via agent
        $result = $this->agent->execute('vpn.create', [
            'name' => $name,
            'config_content' => $request->input('config_content'),
            'up_script' => $request->input('up_script'),
            'down_script' => $request->input('down_script'),
        ], $this->getActor());
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to create VPN connection');
        }

        // Store additional info in database
        try {
            $db = $this->container->getDatabase();
            
            // Extract server info from config if possible
            $configContent = $request->input('config_content');
            $serverAddress = null;
            $serverPort = 1194;
            $protocol = 'udp';
            
            if (preg_match('/remote\s+(\S+)\s+(\d+)/m', $configContent, $matches)) {
                $serverAddress = $matches[1];
                $serverPort = (int)$matches[2];
            }
            if (preg_match('/proto\s+(udp|tcp)/m', $configContent, $matches)) {
                $protocol = $matches[1];
            }
            
            $stmt = $db->prepare("
                INSERT INTO vpn_connections (name, config_name, description, server_address, server_port, protocol, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    description = VALUES(description),
                    server_address = VALUES(server_address),
                    server_port = VALUES(server_port),
                    protocol = VALUES(protocol),
                    notes = VALUES(notes)
            ");
            $stmt->execute([
                $name,
                $name,
                $request->input('description'),
                $serverAddress,
                $serverPort,
                $protocol,
                $request->input('notes'),
            ]);
        } catch (\PDOException $e) {
            // Log but don't fail - config was created
            $this->logger->warning("Failed to save VPN to database", ['error' => $e->getMessage()]);
        }
        
        $this->logAction('vpn.create', $name, 'success');
        
        return Response::success([
            'name' => $name,
        ], 'VPN connection created successfully', 201);
    }

    /**
     * Update an existing VPN connection's config and restart the tunnel
     * (re-import of a changed .ovpn from the UI).
     */
    public function update(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $validation = $this->validateRequired($request, ['config_content']);
        if ($validation) return $validation;

        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$request->getParam('name'));
        if (empty($name)) {
            return Response::validationError(['name' => 'Invalid VPN name']);
        }

        $result = $this->agent->execute('vpn.update', [
            'name' => $name,
            'config_content' => $request->input('config_content'),
            'up_script' => $request->input('up_script'),
            'down_script' => $request->input('down_script'),
        ], $this->getActor(), 60);

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to update VPN connection');
        }

        // Keep the DB record's parsed server info in sync with the new config.
        try {
            $configContent = (string)$request->input('config_content');
            $serverAddress = null;
            $serverPort = 1194;
            $protocol = 'udp';
            if (preg_match('/remote\s+(\S+)\s+(\d+)/m', $configContent, $matches)) {
                $serverAddress = $matches[1];
                $serverPort = (int)$matches[2];
            }
            if (preg_match('/proto\s+(udp|tcp)/m', $configContent, $matches)) {
                $protocol = $matches[1];
            }
            $db = $this->container->getDatabase();
            $stmt = $db->prepare("
                UPDATE vpn_connections
                SET server_address = ?, server_port = ?, protocol = ?
                WHERE name = ?
            ");
            $stmt->execute([$serverAddress, $serverPort, $protocol, $name]);
        } catch (\PDOException $e) {
            // Config was updated on disk; DB enrichment is best-effort.
        }

        if (!empty($result['data']['restarted'])) {
            $this->updateVpnStatus($name, 'connected', null, $result['data']['local_ip'] ?? null, $result['data']['remote_ip'] ?? null);
        }

        $this->logAction('vpn.update', $name, 'success', [
            'restarted' => !empty($result['data']['restarted']),
        ]);

        return Response::success($result['data'], $result['message'] ?? 'VPN connection updated');
    }

    /**
     * Delete VPN connection
     */
    public function delete(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $name = $request->getParam('name');
        
        // Delete via agent
        $result = $this->agent->execute('vpn.delete', ['name' => $name], $this->getActor());
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to delete VPN connection');
        }

        // Remove from database
        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare("DELETE FROM vpn_connections WHERE name = ?");
            $stmt->execute([$name]);
        } catch (\PDOException $e) {
            // Log but don't fail
        }
        
        $this->logAction('vpn.delete', $name, 'success');
        
        return Response::success(null, 'VPN connection deleted successfully');
    }

    /**
     * Start VPN connection
     */
    public function start(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $name = $request->getParam('name');
        
        $result = $this->agent->execute('vpn.start', ['name' => $name], $this->getActor());
        
        if (!$result['success']) {
            // Update database with error
            $this->updateVpnStatus($name, 'error', $result['error']);
            return Response::error($result['error'] ?? 'Failed to start VPN');
        }

        // Update database status
        $this->updateVpnStatus($name, 'connected', null, $result['data']['local_ip'] ?? null, $result['data']['remote_ip'] ?? null);
        
        $this->logAction('vpn.start', $name, 'success');
        
        return Response::success($result['data'], $result['message'] ?? 'VPN started');
    }

    /**
     * Stop VPN connection
     */
    public function stop(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $name = $request->getParam('name');
        
        $result = $this->agent->execute('vpn.stop', ['name' => $name], $this->getActor());
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to stop VPN');
        }

        // Update database status
        $this->updateVpnStatus($name, 'disconnected');
        
        $this->logAction('vpn.stop', $name, 'success');
        
        return Response::success($result['data'], $result['message'] ?? 'VPN stopped');
    }

    /**
     * Restart VPN connection
     */
    public function restart(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $name = $request->getParam('name');
        
        $result = $this->agent->execute('vpn.restart', ['name' => $name], $this->getActor());
        
        if (!$result['success']) {
            $this->updateVpnStatus($name, 'error', $result['error']);
            return Response::error($result['error'] ?? 'Failed to restart VPN');
        }

        // Update database status
        $this->updateVpnStatus($name, 'connected', null, $result['data']['local_ip'] ?? null, $result['data']['remote_ip'] ?? null);
        
        $this->logAction('vpn.restart', $name, 'success');
        
        return Response::success($result['data'], $result['message'] ?? 'VPN restarted');
    }

    /**
     * Get VPN logs
     */
    public function logs(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $name = $request->getParam('name');
        $lines = (int)$request->getQuery('lines', 50);
        
        $result = $this->agent->execute('vpn.logs', [
            'name' => $name,
            'lines' => min($lines, 500), // Cap at 500 lines
        ], $this->getActor());
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to get VPN logs');
        }

        return Response::success($result['data']);
    }

    /**
     * Get VPN config content
     */
    public function getConfig(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $name = $request->getParam('name');
        
        $result = $this->agent->execute('vpn.getConfig', ['name' => $name], $this->getActor());
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to get VPN config');
        }

        return Response::success($result['data']);
    }

    /**
     * Update VPN status in database
     */
    private function updateVpnStatus(string $name, string $status, ?string $error = null, ?string $localIp = null, ?string $remoteIp = null): void
    {
        try {
            $db = $this->container->getDatabase();
            
            $params = [$status];
            $sql = "UPDATE vpn_connections SET status = ?";
            
            if ($status === 'connected') {
                $sql .= ", connected_at = NOW(), local_ip = ?, remote_ip = ?, last_error = NULL";
                $params[] = $localIp;
                $params[] = $remoteIp;
            } elseif ($status === 'error' && $error) {
                $sql .= ", last_error = ?, connected_at = NULL, local_ip = NULL, remote_ip = NULL";
                $params[] = $error;
            } else {
                $sql .= ", connected_at = NULL, local_ip = NULL, remote_ip = NULL";
            }
            
            $sql .= " WHERE name = ?";
            $params[] = $name;
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
        } catch (\PDOException $e) {
            // Log but don't fail
        }
    }
}

