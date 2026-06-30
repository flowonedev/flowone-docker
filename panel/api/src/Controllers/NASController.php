<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * NAS Storage Management Controller
 * 
 * Handles NAS connections, domain assignments, and storage configuration.
 * Only super_admin can access these endpoints.
 */
class NASController extends BaseController
{
    /**
     * List all NAS connections
     */
    public function index(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $db = $this->container->getDatabase();
            
            $stmt = $db->prepare("
                SELECT n.*, 
                    (SELECT COUNT(*) FROM nas_domain_overrides WHERE nas_connection_id = n.id) as domain_count
                FROM nas_connections n
                ORDER BY n.is_default DESC, n.name ASC
            ");
            $stmt->execute();
            $connections = $stmt->fetchAll();
            
            return Response::success([
                'connections' => $connections,
                'count' => count($connections),
            ]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get single NAS connection details
     */
    public function show(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            $stmt = $db->prepare("SELECT * FROM nas_connections WHERE id = ?");
            $stmt->execute([$id]);
            $connection = $stmt->fetch();
            
            if (!$connection) {
                return Response::notFound('NAS connection not found');
            }
            
            // Get domain overrides
            $stmt = $db->prepare("SELECT * FROM nas_domain_overrides WHERE nas_connection_id = ? ORDER BY domain");
            $stmt->execute([$id]);
            $connection['domain_overrides'] = $stmt->fetchAll();
            
            return Response::success(['connection' => $connection]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a new NAS connection
     */
    public function create(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $validation = $this->validateRequired($request, ['name', 'mount_point']);
        if ($validation) return $validation;
        
        try {
            $db = $this->container->getDatabase();
            
            $isDefault = $request->input('is_default', false);
            
            // If setting as default, unset other defaults
            if ($isDefault) {
                $db->exec("UPDATE nas_connections SET is_default = 0");
            }
            
            $stmt = $db->prepare("
                INSERT INTO nas_connections 
                (name, driver, mount_point, nfs_server, nfs_path, nfs_options, vpn_enabled, vpn_config_path, is_default, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                trim($request->input('name')),
                $request->input('driver', 'nfs'),
                trim($request->input('mount_point')),
                $request->input('nfs_server'),
                $request->input('nfs_path'),
                $request->input('nfs_options', 'rw,soft,timeo=10,retrans=3'),
                $request->input('vpn_enabled', false) ? 1 : 0,
                $request->input('vpn_config_path'),
                $isDefault ? 1 : 0,
                $request->input('notes'),
            ]);
            
            $connectionId = $db->lastInsertId();
            
            $this->logAction('nas.create', $request->input('name'), 'success', [
                'driver' => $request->input('driver', 'nfs'),
                'mount_point' => $request->input('mount_point'),
            ]);
            
            return Response::success([
                'id' => $connectionId,
                'name' => $request->input('name'),
            ], 'NAS connection created successfully', 201);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update a NAS connection
     */
    public function update(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            $stmt = $db->prepare("SELECT * FROM nas_connections WHERE id = ?");
            $stmt->execute([$id]);
            $connection = $stmt->fetch();
            
            if (!$connection) {
                return Response::notFound('NAS connection not found');
            }
            
            // Handle default flag
            if ($request->has('is_default') && $request->input('is_default')) {
                $db->exec("UPDATE nas_connections SET is_default = 0");
            }
            
            $fields = ['name', 'driver', 'mount_point', 'nfs_server', 'nfs_path', 'nfs_options', 
                       'vpn_enabled', 'vpn_config_path', 'is_default', 'status', 'notes'];
            
            $updates = [];
            $params = [];
            
            foreach ($fields as $field) {
                if ($request->has($field)) {
                    $value = $request->input($field);
                    if (in_array($field, ['vpn_enabled', 'is_default'])) {
                        $value = $value ? 1 : 0;
                    }
                    if (in_array($field, ['name', 'mount_point', 'nfs_server', 'nfs_path'])) {
                        $value = trim($value);
                    }
                    $updates[] = "{$field} = ?";
                    $params[] = $value;
                }
            }
            
            if (!empty($updates)) {
                $params[] = $id;
                $sql = "UPDATE nas_connections SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
            }
            
            $this->logAction('nas.update', $connection['name'], 'success', [
                'fields_updated' => array_keys(array_filter($request->all(), fn($k) => in_array($k, $fields), ARRAY_FILTER_USE_KEY)),
            ]);
            
            return Response::success(null, 'NAS connection updated successfully');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a NAS connection
     */
    public function delete(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            $stmt = $db->prepare("SELECT * FROM nas_connections WHERE id = ?");
            $stmt->execute([$id]);
            $connection = $stmt->fetch();
            
            if (!$connection) {
                return Response::notFound('NAS connection not found');
            }
            
            if ($connection['is_default']) {
                return Response::error('Cannot delete the default storage connection', 400);
            }
            
            $stmt = $db->prepare("DELETE FROM nas_connections WHERE id = ?");
            $stmt->execute([$id]);
            
            $this->logAction('nas.delete', $connection['name'], 'success');
            
            return Response::success(null, 'NAS connection deleted successfully');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Test NAS connection (mount check)
     */
    public function test(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            $stmt = $db->prepare("SELECT * FROM nas_connections WHERE id = ?");
            $stmt->execute([$id]);
            $connection = $stmt->fetch();
            
            if (!$connection) {
                return Response::notFound('NAS connection not found');
            }
            
            $result = $this->agent->execute('nas.test', [
                'driver' => $connection['driver'],
                'mount_point' => $connection['mount_point'],
                'nfs_server' => $connection['nfs_server'],
                'nfs_path' => $connection['nfs_path'],
            ], $this->getActor(), 90);
            
            // Update last_check and status
            $status = $result['success'] ? 'active' : 'error';
            $stmt = $db->prepare("UPDATE nas_connections SET last_check = NOW(), status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            
            $this->logAction('nas.test', $connection['name'], $result['success'] ? 'success' : 'failed');
            
            if ($result['success']) {
                return Response::success($result['data'], 'Connection test successful');
            }
            
            return Response::error($result['error'] ?? 'Connection test failed');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Manually mount a NAS connection (for recovery when NFS unmounted)
     */
    public function mount(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            $stmt = $db->prepare("SELECT * FROM nas_connections WHERE id = ?");
            $stmt->execute([$id]);
            $connection = $stmt->fetch();
            
            if (!$connection) {
                return Response::notFound('NAS connection not found');
            }
            
            if ($connection['driver'] !== 'nfs') {
                return Response::error('Mount action only available for NFS connections', 400);
            }

            // Refuse with a clear message instead of letting mount hang for 60s
            // against an unreachable server when the tunnel is down.
            if (!empty($connection['vpn_enabled'])) {
                $vpnName = $this->vpnNameFor($connection);
                if ($vpnName !== null) {
                    $vpnStatus = $this->agent->execute('vpn.status', ['name' => $vpnName], $this->getActor(), 30);
                    $connected = ($vpnStatus['data']['status'] ?? '') === 'connected';
                    if (!$connected) {
                        return Response::error(
                            "VPN '{$vpnName}' is not connected - mount would time out. Start the VPN first (or use the setup wizard, which starts it automatically).",
                            409
                        );
                    }
                }
            }

            $result = $this->agent->execute('nas.mount', [
                'mount_point' => $connection['mount_point'],
                'nfs_server' => $connection['nfs_server'],
                'nfs_path' => $connection['nfs_path'],
                'nfs_options' => $connection['nfs_options'] ?? 'rw,soft,intr,timeo=30',
            ], $this->getActor(), 90);
            
            if ($result['success']) {
                // Update status
                $stmt = $db->prepare("UPDATE nas_connections SET last_check = NOW(), status = 'active' WHERE id = ?");
                $stmt->execute([$id]);
                
                $this->logAction('nas.mount', $connection['name'], 'success');
                return Response::success($result['data'], 'NFS share mounted successfully');
            }
            
            $this->logAction('nas.mount', $connection['name'], 'failed', ['error' => $result['error'] ?? 'Unknown error']);
            return Response::error($result['error'] ?? 'Mount failed');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * One-shot provisioning for the NAS setup wizard.
     *
     * Runs the full connect sequence server-side and returns per-step results
     * so the UI can render a live checklist:
     *   preflight -> VPN ensure -> mount -> write test -> fstab persist -> set default
     *
     * Idempotent and safe to re-run: every step is a no-op when already done.
     */
    public function provision(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        // Worst case (preflight package install + VPN + mount) takes minutes.
        set_time_limit(600);

        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();

            $stmt = $db->prepare("SELECT * FROM nas_connections WHERE id = ?");
            $stmt->execute([$id]);
            $connection = $stmt->fetch();

            if (!$connection) {
                return Response::notFound('NAS connection not found');
            }

            $setDefault = (bool)$request->input('set_default', false);
            [$steps, $ok] = $this->runProvisionSteps($connection);

            $stmt = $db->prepare("UPDATE nas_connections SET last_check = NOW(), status = ? WHERE id = ?");
            $stmt->execute([$ok ? 'active' : 'error', $id]);

            $defaultApplied = false;
            if ($setDefault) {
                if ($ok) {
                    $db->exec("UPDATE nas_connections SET is_default = 0");
                    $stmt = $db->prepare("UPDATE nas_connections SET is_default = 1 WHERE id = ?");
                    $stmt->execute([$id]);
                    $defaultApplied = true;
                }
                $steps[] = [
                    'step' => 'set_default',
                    'label' => 'Set as default storage',
                    'status' => $defaultApplied ? 'ok' : 'skipped',
                    'message' => $defaultApplied
                        ? 'This connection is now the default storage (apps pick it up within ~5 minutes)'
                        : 'Skipped - provisioning did not complete',
                ];
            }

            $this->logAction('nas.provision', $connection['name'], $ok ? 'success' : 'failed', [
                'steps' => array_map(fn($s) => $s['step'] . ':' . $s['status'], $steps),
            ]);

            return Response::success([
                'ok' => $ok,
                'steps' => $steps,
                'default_applied' => $defaultApplied,
            ], $ok ? 'NAS connection is ready' : 'Provisioning failed - see steps for details');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Execute the provisioning sequence. Returns [steps, ok].
     * Stops at the first hard failure; later steps are reported as skipped.
     */
    private function runProvisionSteps(array $connection): array
    {
        $isNfs = $connection['driver'] === 'nfs';
        $vpnName = !empty($connection['vpn_enabled']) ? $this->vpnNameFor($connection) : null;

        $sequence = [
            ['preflight', 'Preflight checks', fn() => $this->stepPreflight($connection, $vpnName)],
            ['vpn', 'VPN tunnel', fn() => $this->stepVpn($connection, $vpnName)],
            ['mount', 'Mount NFS share', fn() => $this->stepMount($connection, $isNfs)],
            ['write_test', 'Write test', fn() => $this->stepWriteTest($connection)],
            ['persist', 'Survive reboots (fstab)', fn() => $this->stepPersist($connection, $isNfs)],
        ];

        $steps = [];
        $ok = true;
        foreach ($sequence as [$key, $label, $runner]) {
            if (!$ok) {
                $steps[] = ['step' => $key, 'label' => $label, 'status' => 'skipped', 'message' => 'Skipped due to earlier failure'];
                continue;
            }
            $result = $runner();
            $steps[] = array_merge(['step' => $key, 'label' => $label], $result);
            if ($result['status'] === 'error') {
                $ok = false;
            }
        }

        return [$steps, $ok];
    }

    private function stepPreflight(array $connection, ?string $vpnName): array
    {
        $result = $this->agent->execute('nas.preflight', [
            'mount_point' => $connection['mount_point'],
            'vpn_enabled' => !empty($connection['vpn_enabled']),
            'vpn_port' => $this->vpnPortFor($vpnName),
        ], $this->getActor(), 240);

        if ($result['success']) {
            return ['status' => 'ok', 'message' => $result['message'] ?? 'Preflight checks passed', 'checks' => $result['data']['checks'] ?? []];
        }
        return [
            'status' => 'error',
            'message' => $result['error'] ?? 'Preflight failed',
            'checks' => $result['data']['checks'] ?? [],
            'fix_hint' => 'Resolve the failed checks above, then retry',
        ];
    }

    private function stepVpn(array $connection, ?string $vpnName): array
    {
        if (empty($connection['vpn_enabled'])) {
            return ['status' => 'ok', 'message' => 'No VPN required for this connection'];
        }
        if ($vpnName === null) {
            return [
                'status' => 'error',
                'message' => 'VPN is enabled but no VPN config is linked to this connection',
                'fix_hint' => 'Edit the connection and select a VPN, or create one in the VPN tab',
            ];
        }

        $result = $this->agent->execute('vpn.ensure', ['name' => $vpnName], $this->getActor(), 90);
        if ($result['success']) {
            $ip = $result['data']['local_ip'] ?? null;
            return ['status' => 'ok', 'message' => ($result['message'] ?? "VPN {$vpnName} connected") . ($ip ? " (tunnel IP {$ip})" : '')];
        }
        return [
            'status' => 'error',
            'message' => $result['error'] ?? "VPN {$vpnName} did not connect",
            'fix_hint' => 'Check the VPN logs in the VPN tab - certificate, port forwarding or DDNS issues are the usual causes',
            'recent_logs' => $result['data']['recent_logs'] ?? null,
        ];
    }

    private function stepMount(array $connection, bool $isNfs): array
    {
        if (!$isNfs) {
            return ['status' => 'ok', 'message' => 'Local storage - nothing to mount'];
        }

        $result = $this->agent->execute('nas.mount', [
            'mount_point' => $connection['mount_point'],
            'nfs_server' => $connection['nfs_server'],
            'nfs_path' => $connection['nfs_path'],
            'nfs_options' => $connection['nfs_options'] ?? 'rw,soft,timeo=10,retrans=3',
        ], $this->getActor(), 90);

        if ($result['success']) {
            $already = !empty($result['data']['already_mounted']);
            return ['status' => 'ok', 'message' => $already ? 'Share was already mounted' : 'NFS share mounted'];
        }
        return [
            'status' => 'error',
            'message' => $result['error'] ?? 'Mount failed',
            'fix_hint' => 'Verify the NFS server IP and export path, and that the NAS allows this server in its NFS permissions',
        ];
    }

    private function stepWriteTest(array $connection): array
    {
        $result = $this->agent->execute('nas.test', [
            'driver' => $connection['driver'],
            'mount_point' => $connection['mount_point'],
            'nfs_server' => $connection['nfs_server'],
            'nfs_path' => $connection['nfs_path'],
        ], $this->getActor(), 90);

        if ($result['success']) {
            $free = $result['data']['checks']['space_available']['free_human'] ?? null;
            return ['status' => 'ok', 'message' => 'Read/write verified' . ($free ? " ({$free} free)" : '')];
        }
        return [
            'status' => 'error',
            'message' => $result['error'] ?? 'Write test failed',
            'fix_hint' => 'Check the NFS export squash/permission settings - the share must be writable by this server',
        ];
    }

    private function stepPersist(array $connection, bool $isNfs): array
    {
        if (!$isNfs) {
            return ['status' => 'ok', 'message' => 'Local storage - no fstab entry needed'];
        }

        $result = $this->agent->execute('nas.persist', [
            'mount_point' => $connection['mount_point'],
            'nfs_server' => $connection['nfs_server'],
            'nfs_path' => $connection['nfs_path'],
        ], $this->getActor(), 60);

        if ($result['success']) {
            return ['status' => 'ok', 'message' => 'fstab entry written - mount survives reboots'];
        }
        return [
            'status' => 'error',
            'message' => $result['error'] ?? 'Could not persist mount in fstab',
            'fix_hint' => 'The share is mounted but will not survive a reboot until fstab is fixed',
        ];
    }

    /**
     * Derive the OpenVPN config name from the connection's vpn_config_path
     * (e.g. /etc/openvpn/client/synology.conf -> synology).
     */
    private function vpnNameFor(array $connection): ?string
    {
        $path = trim((string)($connection['vpn_config_path'] ?? ''));
        if ($path === '') {
            return null;
        }
        $name = basename($path);
        if (str_ends_with($name, '.conf')) {
            $name = substr($name, 0, -5);
        }
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        return $name !== '' ? $name : null;
    }

    /**
     * Look up the VPN server port (for the CPGuard outbound check).
     */
    private function vpnPortFor(?string $vpnName): int
    {
        if ($vpnName === null) {
            return 1194;
        }
        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare("SELECT server_port FROM vpn_connections WHERE name = ?");
            $stmt->execute([$vpnName]);
            $port = (int)($stmt->fetchColumn() ?: 0);
            return $port > 0 ? $port : 1194;
        } catch (\PDOException $e) {
            return 1194;
        }
    }

    /**
     * Unmount a NAS connection
     */
    public function unmount(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            $stmt = $db->prepare("SELECT * FROM nas_connections WHERE id = ?");
            $stmt->execute([$id]);
            $connection = $stmt->fetch();
            
            if (!$connection) {
                return Response::notFound('NAS connection not found');
            }
            
            if ($connection['driver'] !== 'nfs') {
                return Response::error('Unmount action only available for NFS connections', 400);
            }
            
            $result = $this->agent->execute('nas.unmount', [
                'mount_point' => $connection['mount_point'],
            ], $this->getActor(), 60);
            
            if ($result['success']) {
                // 'inactive' = deliberately offline. ('unknown' is not in the
                // status ENUM - MariaDB silently coerced it to '', which
                // locked the row out of every status-filtered query forever.)
                $stmt = $db->prepare("UPDATE nas_connections SET status = 'inactive' WHERE id = ?");
                $stmt->execute([$id]);
                
                $this->logAction('nas.unmount', $connection['name'], 'success');
                return Response::success($result['data'], 'NFS share unmounted');
            }
            
            return Response::error($result['error'] ?? 'Unmount failed');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get storage stats for a NAS connection
     */
    public function stats(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            $stmt = $db->prepare("SELECT * FROM nas_connections WHERE id = ?");
            $stmt->execute([$id]);
            $connection = $stmt->fetch();
            
            if (!$connection) {
                return Response::notFound('NAS connection not found');
            }
            
            $result = $this->agent->execute('nas.stats', [
                'mount_point' => $connection['mount_point'],
            ], $this->getActor(), 60);
            
            return $result['success'] 
                ? Response::success($result['data'])
                : Response::error($result['error'] ?? 'Failed to get storage stats');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get all domain overrides (for listing)
     */
    public function allDomainOverrides(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $db = $this->container->getDatabase();
            
            $stmt = $db->prepare("
                SELECT o.*, n.name as nas_name, n.driver, n.mount_point
                FROM nas_domain_overrides o
                JOIN nas_connections n ON n.id = o.nas_connection_id
                ORDER BY o.domain
            ");
            $stmt->execute();
            $overrides = $stmt->fetchAll();
            
            return Response::success(['overrides' => $overrides]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get domain overrides for a specific connection
     */
    public function getDomainOverrides(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            $stmt = $db->prepare("SELECT * FROM nas_domain_overrides WHERE nas_connection_id = ? ORDER BY domain");
            $stmt->execute([$id]);
            $overrides = $stmt->fetchAll();
            
            return Response::success(['overrides' => $overrides]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Assign domain to NAS connection
     */
    public function assignDomain(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $validation = $this->validateRequired($request, ['domain']);
        if ($validation) return $validation;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            // Check connection exists
            $stmt = $db->prepare("SELECT * FROM nas_connections WHERE id = ?");
            $stmt->execute([$id]);
            $connection = $stmt->fetch();
            
            if (!$connection) {
                return Response::notFound('NAS connection not found');
            }
            
            $domain = strtolower(trim($request->input('domain')));
            $subPath = $request->input('sub_path');
            
            // Insert or update
            $stmt = $db->prepare("
                INSERT INTO nas_domain_overrides (nas_connection_id, domain, sub_path)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE nas_connection_id = VALUES(nas_connection_id), sub_path = VALUES(sub_path)
            ");
            $stmt->execute([$id, $domain, $subPath]);
            
            $this->logAction('nas.assign_domain', $domain, 'success', [
                'nas_id' => $id,
                'nas_name' => $connection['name'],
            ]);
            
            return Response::success(null, 'Domain assigned successfully');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove domain from NAS connection
     */
    public function removeDomain(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $domain = $request->getParam('domain');
            $db = $this->container->getDatabase();
            
            $stmt = $db->prepare("DELETE FROM nas_domain_overrides WHERE domain = ?");
            $stmt->execute([$domain]);
            
            if ($stmt->rowCount() === 0) {
                return Response::notFound('Domain override not found');
            }
            
            $this->logAction('nas.remove_domain', $domain, 'success');
            
            return Response::success(null, 'Domain removed from NAS connection');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get NAS config for a specific domain (used by email app)
     * This endpoint can be accessed by any authenticated user
     */
    public function getConfigForDomain(Request $request): Response
    {
        try {
            $domain = strtolower(trim($request->getParam('domain')));
            $db = $this->container->getDatabase();
            
            // First check domain override
            $stmt = $db->prepare("
                SELECT n.*, o.sub_path 
                FROM nas_domain_overrides o
                JOIN nas_connections n ON n.id = o.nas_connection_id
                WHERE o.domain = ? AND n.status = 'active'
            ");
            $stmt->execute([$domain]);
            $config = $stmt->fetch();
            
            // If no override, get default
            if (!$config) {
                $stmt = $db->prepare("SELECT * FROM nas_connections WHERE is_default = 1 AND status = 'active'");
                $stmt->execute();
                $config = $stmt->fetch();
            }
            
            if (!$config) {
                return Response::error('No active storage configuration found', 404);
            }
            
            return Response::success([
                'driver' => $config['driver'],
                'mount_point' => $config['mount_point'],
                'sub_path' => $config['sub_path'] ?? null,
                'nfs_server' => $config['nfs_server'],
                'nfs_path' => $config['nfs_path'],
            ]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Run full NAS/VPN connectivity diagnostics
     */
    public function diagnostics(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $db = $this->container->getDatabase();
            
            // Gather NAS connection info for diagnostics context
            $nasId = $request->getQuery('nas_id');
            $vpnName = $request->getQuery('vpn_name', '');
            $nasIp = '';
            $nasPath = '';
            $mountPoint = '';
            
            if ($nasId) {
                $stmt = $db->prepare("SELECT * FROM nas_connections WHERE id = ?");
                $stmt->execute([(int)$nasId]);
                $connection = $stmt->fetch();
                
                if ($connection) {
                    $nasIp = $connection['nfs_server'] ?? '';
                    $nasPath = $connection['nfs_path'] ?? '';
                    $mountPoint = $connection['mount_point'] ?? '';
                    
                    if (empty($vpnName) && $connection['vpn_enabled'] && !empty($connection['vpn_config_path'])) {
                        $vpnName = $this->extractVpnName($connection['vpn_config_path']);
                    }
                }
            }

            // If no NAS specified but VPN name provided, still run VPN checks
            if (empty($vpnName)) {
                $vpnName = $request->getQuery('vpn_name', '');
            }
            if (empty($nasIp)) {
                $nasIp = $request->getQuery('nas_ip', '');
            }
            if (empty($mountPoint)) {
                $mountPoint = $request->getQuery('mount_point', '');
            }
            
            $driver = 'nfs';
            if (isset($connection) && !empty($connection['driver'])) {
                $driver = $connection['driver'];
            }
            
            $result = $this->agent->execute('diagnostics.nasFull', [
                'vpn_name' => $vpnName,
                'nas_ip' => $nasIp,
                'nas_path' => $nasPath,
                'mount_point' => $mountPoint,
                'driver' => $driver,
            ], $this->getActor(), 120);
            
            if ($result['success']) {
                return Response::success($result['data']);
            }
            
            return Response::error($result['error'] ?? 'Diagnostics failed');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Extract the VPN connection name from a full path or bare name.
     * "/etc/openvpn/client/synology.conf" -> "synology"
     * "synology" -> "synology"
     */
    private function extractVpnName(string $configPath): string
    {
        $name = basename($configPath);
        if (str_ends_with($name, '.conf')) {
            $name = substr($name, 0, -5);
        }
        return $name;
    }

    /**
     * Set a connection as default
     */
    public function setDefault(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            $stmt = $db->prepare("SELECT * FROM nas_connections WHERE id = ?");
            $stmt->execute([$id]);
            $connection = $stmt->fetch();
            
            if (!$connection) {
                return Response::notFound('NAS connection not found');
            }
            
            // Unset all defaults, then set this one
            $db->exec("UPDATE nas_connections SET is_default = 0");
            $stmt = $db->prepare("UPDATE nas_connections SET is_default = 1 WHERE id = ?");
            $stmt->execute([$id]);
            
            $this->logAction('nas.set_default', $connection['name'], 'success');
            
            return Response::success(null, 'Default storage connection updated');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get complete storage configuration for external apps (Email App)
     * Returns default storage + all domain overrides in one call
     * 
     * This endpoint is designed for the Email App to cache the entire
     * storage configuration and route emails to the correct storage
     * based on the user's email domain.
     * 
     * Auth: X-Api-Key header or api_key query param
     */
    public function getStorageConfig(Request $request): Response
    {
        // Validate API key (timing-safe comparison)
        $apiKey = $request->getHeader('X-Api-Key') ?? $request->getQuery('api_key');
        $validKeys = $this->container->getConfig('external_api.keys', []);
        
        $keyValid = false;
        if ($apiKey) {
            foreach ($validKeys as $name => $validKey) {
                if (hash_equals((string) $validKey, (string) $apiKey)) {
                    $keyValid = true;
                    break;
                }
            }
        }
        if (!$keyValid) {
            return Response::unauthorized('Invalid or missing API key');
        }
        
        try {
            $db = $this->container->getDatabase();
            
            // Get default storage
            $stmt = $db->prepare("
                SELECT id, name, driver, mount_point, nfs_server, nfs_path, status
                FROM nas_connections 
                WHERE is_default = 1 AND status = 'active'
                LIMIT 1
            ");
            $stmt->execute();
            $defaultStorage = $stmt->fetch();
            
            // Format default storage response
            $defaultData = null;
            if ($defaultStorage) {
                $defaultData = [
                    'id' => (int)$defaultStorage['id'],
                    'name' => $defaultStorage['name'],
                    'type' => $defaultStorage['driver'],
                    'mount_point' => $defaultStorage['mount_point'],
                    'nfs_server' => $defaultStorage['nfs_server'],
                    'nfs_path' => $defaultStorage['nfs_path'],
                    'status' => $defaultStorage['status'],
                ];
            }
            
            // Get all domain overrides with their storage info
            $stmt = $db->prepare("
                SELECT 
                    o.domain,
                    o.sub_path,
                    n.id as storage_id,
                    n.name as storage_name,
                    n.driver,
                    n.mount_point,
                    n.nfs_server,
                    n.nfs_path,
                    n.status
                FROM nas_domain_overrides o
                JOIN nas_connections n ON n.id = o.nas_connection_id
                WHERE n.status = 'active'
                ORDER BY o.domain
            ");
            $stmt->execute();
            $overrides = $stmt->fetchAll();
            
            // Format domain overrides
            $domainOverrides = [];
            foreach ($overrides as $override) {
                $domainOverrides[] = [
                    'domain' => $override['domain'],
                    'sub_path' => $override['sub_path'],
                    'storage' => [
                        'id' => (int)$override['storage_id'],
                        'name' => $override['storage_name'],
                        'type' => $override['driver'],
                        'mount_point' => $override['mount_point'],
                        'nfs_server' => $override['nfs_server'],
                        'nfs_path' => $override['nfs_path'],
                    ],
                ];
            }
            
            return Response::success([
                'default_storage' => $defaultData,
                'domain_overrides' => $domainOverrides,
            ]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get NAS health status.
     *
     * Prefers the always-on storage-monitord daemon's live signed state
     * (via the agent's nasmonitor.getStatus, which itself prefers the
     * shared daemon and falls back to the legacy JSON). This is what lets
     * the dashboard reflect automatic recovery WITHOUT anyone pressing a
     * button — the daemon republishes HEALTHY within seconds of the NAS
     * coming back, and this endpoint serves that fresh state on the next
     * poll. If the agent is unreachable we fall back to the legacy file so
     * the widget still renders something.
     */
    public function healthStatus(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        try {
            $result = $this->agent->execute('nasmonitor.getStatus', [], $this->getActor(), 15);
            if (!empty($result['success'])) {
                return Response::success($result['data']);
            }
        } catch (\Throwable $e) {
            error_log('[NASController] live getStatus failed, falling back to file: ' . $e->getMessage());
        }

        $statusFile = '/var/www/vps-admin/data/nas-health.json';

        if (!file_exists($statusFile)) {
            return Response::success([
                'status'    => 'unknown',
                'checks'    => [],
                'timestamp' => null,
                'message'   => 'No health check has run yet. The cron monitor may not be installed.',
            ]);
        }

        $data = @json_decode(file_get_contents($statusFile), true);
        if (!is_array($data)) {
            return Response::error('Health status file is corrupt', 500);
        }

        return Response::success($data);
    }

    /**
     * Trigger a live on-demand health check via the agent
     */
    public function healthCheck(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $result = $this->agent->execute('nasmonitor.healthCheck', [], $this->getActor(), 60);

        if ($result['success']) {
            return Response::success($result['data']);
        }

        return Response::error($result['error'] ?? 'Health check failed');
    }

    /**
     * Get NAS health check history
     */
    public function healthHistory(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $limit = (int)$request->getQuery('limit', 20);

        $result = $this->agent->execute('nasmonitor.getHistory', [
            'limit' => min($limit, 100),
        ], $this->getActor(), 10);

        if ($result['success']) {
            return Response::success($result['data']);
        }

        return Response::error($result['error'] ?? 'Failed to get history');
    }
}

