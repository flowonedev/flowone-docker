<?php

namespace FleetManager\Api\Controllers;

use FleetManager\Api\Core\Request;
use FleetManager\Api\Core\Response;
use FleetManager\Api\Services\EncryptionService;
use FleetManager\Api\Services\ServerReportService;

/**
 * Server management controller
 */
class ServerController extends BaseController
{
    /**
     * List all servers
     */
    public function index(Request $request): Response
    {
        $db = $this->getDatabase();
        $pagination = $this->getPagination($request);
        
        $status = $request->getQuery('status');
        $search = $request->getQuery('search');

        $where = [];
        $params = [];

        if ($status) {
            $where[] = 's.status = ?';
            $params[] = $status;
        }

        if ($search) {
            $where[] = '(s.name LIKE ? OR s.ip_address LIKE ? OR s.panel_domain LIKE ?)';
            $searchTerm = '%' . $search . '%';
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Get total count
        $stmt = $db->prepare("SELECT COUNT(*) FROM servers s {$whereClause}");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        // Get servers
        $sql = "SELECT s.id, s.name, s.ip_address, s.ssh_port, s.panel_domain, s.email_domain, 
                       s.mail_domain, s.status, s.panel_version, s.email_app_version, s.os_info,
                       s.last_heartbeat, s.last_error, s.vpn_enabled, s.nas_enabled,
                       s.created_at, s.updated_at,
                       b.name as blueprint_name,
                       (SELECT COUNT(*) FROM server_errors WHERE server_id = s.id AND resolved = 0) as error_count
                FROM servers s
                LEFT JOIN blueprints b ON s.blueprint_id = b.id
                {$whereClause}
                ORDER BY s.name ASC
                LIMIT ? OFFSET ?";

        $params[] = $pagination['per_page'];
        $params[] = $pagination['offset'];

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $servers = $stmt->fetchAll();

        // Get latest health + any in-flight deployment for each server
        foreach ($servers as &$server) {
            $healthStmt = $db->prepare(
                "SELECT * FROM server_health WHERE server_id = ? ORDER BY recorded_at DESC LIMIT 1"
            );
            $healthStmt->execute([$server['id']]);
            $server['health'] = $healthStmt->fetch() ?: null;

            // Surface the active deployment so the dashboard can show live progress
            // on provisioning cards (especially after a batch deploy).
            $depStmt = $db->prepare(
                "SELECT id, type, status, progress, current_step
                 FROM deployments
                 WHERE server_id = ? AND status IN ('pending', 'running')
                 ORDER BY created_at DESC LIMIT 1"
            );
            $depStmt->execute([$server['id']]);
            $server['active_deployment'] = $depStmt->fetch() ?: null;
        }

        return Response::success([
            'servers' => $servers,
            'total' => $total,
            'page' => $pagination['page'],
            'per_page' => $pagination['per_page'],
            'total_pages' => ceil($total / $pagination['per_page']),
        ]);
    }

    /**
     * Get single server details
     */
    public function show(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();

        $stmt = $db->prepare(
            "SELECT s.*, b.name as blueprint_name
             FROM servers s
             LEFT JOIN blueprints b ON s.blueprint_id = b.id
             WHERE s.id = ?"
        );
        $stmt->execute([$id]);
        $server = $stmt->fetch();

        if (!$server) {
            return Response::notFound('Server not found');
        }

        // Decrypt SSH password for display
        $encryption = $this->getEncryption();
        if (!empty($server['ssh_password_encrypted'])) {
            try {
                $server['ssh_password'] = $encryption->decrypt($server['ssh_password_encrypted']);
            } catch (\Exception $e) {
                $server['ssh_password'] = null;
            }
        } else {
            $server['ssh_password'] = null;
        }

        // CPGuard: tell the UI whether a license key is on file (never the key itself)
        $server['has_cpguard_license'] = !empty($server['cpguard_license_key_encrypted']);

        // Remove encrypted sensitive data
        unset($server['ssh_password_encrypted']);
        unset($server['db_root_password_encrypted']);
        unset($server['panel_db_password_encrypted']);
        unset($server['email_db_password_encrypted']);
        unset($server['mail_db_password_encrypted']);
        unset($server['panel_admin_password_encrypted']);
        unset($server['vpn_config_encrypted']);
        unset($server['cpguard_license_key_encrypted']);

        // Get latest health
        $healthStmt = $db->prepare(
            "SELECT * FROM server_health WHERE server_id = ? ORDER BY recorded_at DESC LIMIT 1"
        );
        $healthStmt->execute([$id]);
        $server['health'] = $healthStmt->fetch() ?: null;

        // Get recent errors
        $errorsStmt = $db->prepare(
            "SELECT * FROM server_errors WHERE server_id = ? AND resolved = 0 ORDER BY last_seen DESC LIMIT 10"
        );
        $errorsStmt->execute([$id]);
        $server['recent_errors'] = $errorsStmt->fetchAll();

        // Get recent deployments
        $deploymentsStmt = $db->prepare(
            "SELECT id, type, version, status, progress, started_at, completed_at 
             FROM deployments WHERE server_id = ? ORDER BY created_at DESC LIMIT 5"
        );
        $deploymentsStmt->execute([$id]);
        $server['recent_deployments'] = $deploymentsStmt->fetchAll();

        return Response::success($server);
    }

    /**
     * Create a new server
     */
    public function create(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['name', 'ip_address', 'panel_domain', 'email_domain']);
        if ($validation) return $validation;

        $db = $this->getDatabase();
        $encryption = $this->getEncryption();

        // Check if IP or domains already exist
        $stmt = $db->prepare(
            "SELECT id FROM servers WHERE ip_address = ? OR panel_domain = ? OR email_domain = ?"
        );
        $stmt->execute([
            $request->input('ip_address'),
            $request->input('panel_domain'),
            $request->input('email_domain'),
        ]);
        
        if ($stmt->fetch()) {
            return Response::error('Server with this IP or domain already exists', 400);
        }

        // Determine auth method - only store the relevant credential
        $authMethod = $request->input('auth_method', 'password');
        $sshPassword = ($authMethod === 'password') ? $request->input('ssh_password') : null;
        $sshPasswordEncrypted = $sshPassword ? $encryption->encrypt($sshPassword) : null;
        $keyPath = ($authMethod === 'key') ? $request->input('key_path') : null;

        // Check if this is a local server
        $isLocal = $request->input('is_local', false) 
            || $request->input('ip_address') === '127.0.0.1'
            || $request->input('ip_address') === 'localhost';

        // Generate agent token
        $agentToken = $encryption->generateToken(32);

        // Optional CPGuard license key (IP-bound, one per server). When present,
        // the install_cpguard provisioning step installs CPGuard with it.
        $cpguardKey = trim((string)$request->input('cpguard_license_key', ''));
        $cpguardKeyEncrypted = $cpguardKey !== '' ? $encryption->encrypt($cpguardKey) : null;

        $stmt = $db->prepare(
            "INSERT INTO servers (name, ip_address, ssh_port, ssh_user, ssh_password_encrypted,
                                  is_local, key_path, panel_domain, email_domain, mail_domain, 
                                  blueprint_id, agent_token, panel_admin_email, cpguard_license_key_encrypted, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)"
        );

        $stmt->execute([
            $request->input('name'),
            $request->input('ip_address'),
            $request->input('ssh_port', 22),
            $request->input('ssh_user', 'root'),
            $sshPasswordEncrypted,
            $isLocal ? 1 : 0,
            $keyPath,
            $request->input('panel_domain'),
            $request->input('email_domain'),
            $request->input('mail_domain', $request->input('email_domain')),
            $request->input('blueprint_id'),
            $agentToken,
            $request->input('admin_email'),
            $cpguardKeyEncrypted,
            $request->input('notes'),
        ]);

        $serverId = (int)$db->lastInsertId();

        $this->logAction('server.create', $serverId, $request->input('name'), 'success', [
            'ip' => $request->input('ip_address'),
            'panel_domain' => $request->input('panel_domain'),
        ]);

        return Response::success(['id' => $serverId, 'agent_token' => $agentToken], 'Server created successfully');
    }

    /**
     * Update server
     */
    public function update(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();

        $stmt = $db->prepare("SELECT id, name FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();

        if (!$server) {
            return Response::notFound('Server not found');
        }

        $updates = [];
        $params = [];

        $fields = ['name', 'ip_address', 'ssh_port', 'ssh_user', 'ssh_auth_method', 'panel_domain', 'email_domain',
                   'mail_domain', 'blueprint_id', 'status', 'notes', 'vpn_enabled', 'nas_enabled',
                   'nas_ip', 'nas_path', 'nas_mount', 'ns1_domain', 'ns2_domain'];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $updates[] = "{$field} = ?";
                $params[] = $request->input($field);
            }
        }

        // Handle encrypted fields
        $encryption = $this->getEncryption();
        
        if ($request->has('ssh_password') && $request->input('ssh_password')) {
            $updates[] = "ssh_password_encrypted = ?";
            $params[] = $encryption->encrypt($request->input('ssh_password'));
        }

        if ($request->has('cpguard_license_key')) {
            $cpguardKey = trim((string)$request->input('cpguard_license_key'));
            $updates[] = "cpguard_license_key_encrypted = ?";
            $params[] = $cpguardKey !== '' ? $encryption->encrypt($cpguardKey) : null;
        }

        if (empty($updates)) {
            return Response::error('No fields to update', 400);
        }

        $params[] = $id;
        $stmt = $db->prepare("UPDATE servers SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);

        $this->logAction('server.update', $id, $server['name'], 'success');

        return Response::success(null, 'Server updated successfully');
    }

    /**
     * Push the per-server policy files (nameservers + NAS opt-in) to the box
     * over SSH — day-2, no redeploy. Writes /var/www/vps-admin/.dns_ns_config.json
     * and /etc/flowone/storage.local.php from the server row, so the operator
     * can manage both from Fleet without logging in to the panel.
     *
     * POST /api/servers/{id}/apply-settings
     */
    public function applySettings(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();

        $stmt = $db->prepare("SELECT id, name FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();
        if (!$server) {
            return Response::notFound('Server not found');
        }

        $provisioning = $this->container->get(\FleetManager\Api\Services\ProvisioningService::class);
        $result = $provisioning->applyHostPolicy($id);

        $this->logAction('server.apply_settings', $id, $server['name'], $result['success'] ? 'success' : 'failed');

        if (empty($result['success'])) {
            return Response::error(
                'Could not apply settings on the server: ' . ($result['error'] ?? 'unknown error'),
                502
            );
        }

        return Response::success(
            ['log' => $result['log'] ?? []],
            'Nameserver + NAS settings applied on the server'
        );
    }

    /**
     * Delete server
     */
    public function delete(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();

        $stmt = $db->prepare("SELECT id, name FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();

        if (!$server) {
            return Response::notFound('Server not found');
        }

        try {
            $db->beginTransaction();

            // Delete related records first (in case CASCADE isn't working)
            // Suppress errors for tables that might not exist
            $relatedTables = [
                'agent_tasks',
                'deployment_logs', 
                'server_configs',
                'server_errors',
                'server_health',
                'server_packages',
                'server_issues',
                'deployments',
                'ai_cached_issues',
            ];
            
            foreach ($relatedTables as $table) {
                try {
                    $db->prepare("DELETE FROM {$table} WHERE server_id = ?")->execute([$id]);
                } catch (\Exception $e) {
                    // Table might not exist, continue
                }
            }

            // Delete the server
            $stmt = $db->prepare("DELETE FROM servers WHERE id = ?");
            $stmt->execute([$id]);

            $db->commit();

            // server_id is NULL because the server no longer exists (FK constraint)
            $this->logAction('server.delete', null, $server['name'], 'success', ['deleted_server_id' => $id]);

            return Response::success(null, 'Server deleted successfully');
        } catch (\Exception $e) {
            $db->rollBack();
            error_log("Server delete error: " . $e->getMessage());
            return Response::error('Failed to delete server: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Test connection to an existing server
     * POST /api/servers/{id}/test-connection
     */
    public function testConnection(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();
        
        $stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();
        
        if (!$server) {
            return Response::notFound('Server not found');
        }
        
        // If local server, test via agent socket
        if ($server['is_local']) {
            $agentService = $this->container->get(\FleetManager\Api\Services\AgentService::class);
            try {
                $result = $agentService->sendCommand('ping', []);
                return Response::success([
                    'connected' => true,
                    'method' => 'agent',
                    'message' => 'Agent connection successful'
                ]);
            } catch (\Exception $e) {
                return Response::success([
                    'connected' => false,
                    'method' => 'agent',
                    'message' => 'Agent not responding: ' . $e->getMessage()
                ]);
            }
        }
        
        // Remote server - test via SSH. We do NOT blindly trust the stored profile:
        // the moment a deploy hardens the box (pxr@1985, key-only, root denied,
        // port 22 closed), the old root@22/password row goes stale and Test
        // Connection would be permanently "Failed". Instead we probe a small,
        // ordered matrix of likely profiles, take the first that works, and SYNC
        // the servers row so the panel PORT/USER/AUTH and the copy-paste SSH
        // command self-heal after every deploy.
        $ssh = $this->container->get(\FleetManager\Api\Services\SSHService::class);
        $encryption = $this->getEncryption();

        $hardenPort = (int)($this->container->getConfig('ssh.harden_port') ?: 1985);
        $storedPort = (int)($server['ssh_port'] ?: 22);
        $storedUser = (string)($server['ssh_user'] ?: 'root');
        $storedAuth = (string)($server['ssh_auth_method'] ?? '');

        // Resolve the Fleet Manager's pxr key for this server: the stored path if it
        // exists, else the deterministic location the hardening step writes to (it
        // may exist from a prior deploy even when key_path wasn't persisted).
        $keyDir = rtrim((string)($this->container->getConfig('ssh.key_path') ?: '/var/www/vps-fleet/var/keys/'), '/') . '/';
        $pxrKeyFile = $keyDir . "server_{$id}_pxr.key";
        $keyPath = (!empty($server['key_path']) && @file_exists($server['key_path']))
            ? $server['key_path']
            : (@file_exists($pxrKeyFile) ? $pxrKeyFile : null);

        $password = null;
        if (!empty($server['ssh_password_encrypted'])) {
            try { $password = $encryption->decrypt($server['ssh_password_encrypted']); }
            catch (\Exception $e) { $password = null; }
        }

        // The fleet-wide MANAGEMENT key (private half of pxr_authorized_key, e.g.
        // vps-sftp-access). Authorized on pxr on every hardened box, so it recovers
        // access even when the per-server key is gone or the record was re-added.
        $mgmt = $ssh->managementKey(); // ['path'=>..., 'passphrase'=>...] or null

        // Build candidate profiles, most-likely-correct first to minimise timeouts.
        // Each carries its own key source so we can try BOTH the per-server key and
        // the (passphrase-protected) management key at the same user@port.
        $candidates = [];
        $addKey = function (int $port, string $user, ?string $keyfile, ?string $pass) use (&$candidates) {
            if (!$keyfile) return;
            $candidates[] = ['auth' => 'key', 'port' => $port, 'user' => $user, 'keyfile' => $keyfile, 'passphrase' => $pass];
        };
        $addPw = function (int $port, string $user) use (&$candidates, $password) {
            if ($password === null || $password === '') return;
            $candidates[] = ['auth' => 'password', 'port' => $port, 'user' => $user, 'keyfile' => null, 'passphrase' => null];
        };

        // Per-server FM key (no passphrase) - the target hardened profile first.
        if ($keyPath !== null) {
            $addKey($hardenPort, 'pxr', $keyPath, null);
            $addKey($storedPort, $storedUser, $keyPath, null);
            $addKey(22, 'pxr', $keyPath, null);
        }
        // Fleet-wide management/operator key (with passphrase) - recovers orphaned boxes.
        if ($mgmt !== null) {
            $addKey($hardenPort, 'pxr', $mgmt['path'], $mgmt['passphrase']);
            $addKey(22, 'pxr', $mgmt['path'], $mgmt['passphrase']);
            $addKey($storedPort, $storedUser, $mgmt['path'], $mgmt['passphrase']);
        }
        // Stored profile as recorded.
        if ($storedAuth === 'key') {
            $addKey($storedPort, $storedUser, $keyPath ?? ($mgmt['path'] ?? null), $keyPath ? null : ($mgmt['passphrase'] ?? null));
        } else {
            $addPw($storedPort, $storedUser);
        }
        // Pre-deploy / legacy / mid-transition: root+password fallback. ONLY when
        // we have no key at all - on a hardened box these are guaranteed auth
        // failures, and repeating them every test trips fail2ban's sshd jail and
        // DROP-bans the panel's own IP (=> "connection timed out" on every probe).
        if ($keyPath === null && $mgmt === null) {
            $addPw(22, 'root');
            $addPw($hardenPort, 'root');
        }

        // De-duplicate (keyed on auth+port+user+keyfile so per-server vs management
        // key are both kept), preserving order.
        $seen = [];
        $ordered = [];
        foreach ($candidates as $c) {
            $k = $c['auth'] . ':' . $c['port'] . ':' . $c['user'] . ':' . ($c['keyfile'] ?? '-');
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $ordered[] = $c;
        }

        if (empty($ordered)) {
            return Response::success([
                'connected' => false,
                'method' => 'ssh',
                'message' => 'No SSH credentials configured (no key or password on file)'
            ]);
        }

        $result = ['success' => false, 'error' => 'unknown'];
        $working = null;
        $attempts = []; // per-candidate error, so a closed port 22 doesn't mask the real pxr@1985 failure
        foreach ($ordered as $c) {
            if ($c['auth'] === 'key') {
                $r = $ssh->testConnectionWithKey($server['ip_address'], $c['port'], $c['user'], $c['keyfile'], $c['passphrase'] ?? '', 7);
            } else {
                $r = $ssh->testConnection($server['ip_address'], $c['port'], $c['user'], $password, 7);
            }
            if (!empty($r['success'])) { $result = $r; $working = $c; break; }
            // Tag which key was used so the two pxr@1985 rows are distinguishable.
            $keyTag = '';
            if ($c['auth'] === 'key') {
                $keyTag = ($mgmt !== null && ($c['keyfile'] ?? null) === ($mgmt['path'] ?? null))
                    ? ' (mgmt key)' : ' (server key)';
            }
            $attempts[] = "{$c['user']}@{$c['port']}/{$c['auth']}{$keyTag}: " . ($r['error'] ?? 'unknown');
            $result = $r;
        }

        if (!$working) {
            $joined = implode("\n", $attempts);
            $message = "Could not connect on any known profile.\n" . $joined;
            // A timeout (vs. an auth failure) means the packet never completed a
            // handshake - most often the PANEL host's OUTBOUND egress blocks the
            // hardened port. Classic symptom: you can SSH from your own machine but
            // the panel times out. Point the operator at the real fix.
            if (stripos($joined, 'timed out') !== false || stripos($joined, 'timeout') !== false) {
                $message .= "\n\nHint: a timeout (not 'auth failed') usually means port {$hardenPort} is unreachable "
                    . "from the panel host. If you CAN ssh to this box from your own machine but the panel times out, the "
                    . "panel host's OUTBOUND egress is blocking it - add {$hardenPort} to CPGuard/CSF 'TCP_OUT' "
                    . "(or your provider's egress firewall).";
            }
            return Response::success([
                'connected' => false,
                'method' => 'ssh',
                // Each attempt's own error - the relevant one is the pxr@<hardenPort>
                // line, not the trailing port-22 timeout (22 is closed once hardened).
                'message' => $message,
            ]);
        }

        // Sync the servers row to whatever actually worked so the UI self-corrects.
        $newAuth = $working['auth'] === 'key' ? 'key' : 'password';
        $workingKey = $working['keyfile'] ?? null;
        $changed = ($storedPort !== (int)$working['port'])
            || ($storedUser !== $working['user'])
            || ($storedAuth !== $newAuth);
        try {
            $sql = "UPDATE servers SET ssh_port = ?, ssh_user = ?, ssh_auth_method = ?";
            $params = [(int)$working['port'], $working['user'], $newAuth];
            if ($newAuth === 'key' && $workingKey) { $sql .= ", key_path = ?"; $params[] = $workingKey; }
            if (!empty($result['os'])) { $sql .= ", os_info = ?"; $params[] = substr((string)$result['os'], 0, 100); }
            $sql .= " WHERE id = ?";
            $params[] = $id;
            $db->prepare($sql)->execute($params);

            // Keep the human-facing "SSH Access" credential rows in sync with the live
            // working profile, so the Credentials card never shows a stale root@22
            // after the box was hardened to pxr@<port>. Self-heals existing servers
            // on the next page load / Test Connection - no redeploy needed.
            $credStmt = $db->prepare(
                "INSERT INTO server_credentials (server_id, category, credential_key, label, value_encrypted, is_secret)
                 VALUES (?, 'ssh', ?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE value_encrypted = VALUES(value_encrypted), label = VALUES(label)"
            );
            $credStmt->execute([$id, 'SSH_USER', 'SSH User', $encryption->encrypt((string)$working['user'])]);
            $credStmt->execute([$id, 'SSH_PORT', 'SSH Port', $encryption->encrypt((string)(int)$working['port'])]);
        } catch (\Exception $e) {
            error_log("test-connection: failed to sync ssh profile for server {$id}: " . $e->getMessage());
        }

        $msg = "Connected as {$working['user']}@{$working['port']} via {$newAuth}";
        if ($changed) { $msg .= ' - stored connection updated'; }

        return Response::success([
            'connected' => true,
            'method' => 'ssh',
            'message' => $msg,
            'detected' => [
                'ssh_port' => (int)$working['port'],
                'ssh_user' => $working['user'],
                'ssh_auth_method' => $newAuth,
                'changed' => $changed,
            ],
            'server_info' => [
                'hostname' => $result['hostname'] ?? null,
                'os' => $result['os'] ?? null,
                'uptime' => $result['uptime'] ?? null,
                'uptime_seconds' => $result['uptime_seconds'] ?? null,
                'cpu' => $result['cpu'] ?? null,
                'memory' => $result['memory'] ?? null,
                'disk' => $result['disk'] ?? null,
                'services' => $result['services'] ?? null,
            ]
        ]);
    }

    /**
     * Set the operator SSH PUBLIC key authorized on the pxr account for this
     * server. Stored for the next hardening run, and pushed live immediately if
     * the box is already hardened (ssh_user=pxr) and reachable. An empty value
     * clears the override (falls back to the fleet-wide default key).
     *
     * The Fleet Manager's own management key is always kept authorized as well,
     * so updating this never locks the panel out.
     *
     * POST /api/servers/{id}/authorized-key   { "public_key": "ssh-ed25519 ..." }
     */
    public function updateAuthorizedKey(Request $request, string $id): Response
    {
        $id = (int)$id;
        $db = $this->getDatabase();

        $stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();
        if (!$server) {
            return Response::notFound('Server not found');
        }

        $key = trim((string)$request->input('public_key', ''));

        if ($key !== '' && !$this->isValidSshPublicKey($key)) {
            return Response::error(
                'That does not look like a valid SSH public key. Expected e.g. "ssh-ed25519 AAAA... comment".',
                422
            );
        }

        try {
            $stmt = $db->prepare("UPDATE servers SET ssh_authorized_key = ? WHERE id = ?");
            $stmt->execute([$key !== '' ? $key : null, $id]);
        } catch (\Exception $e) {
            return Response::error('Failed to save key: ' . $e->getMessage(), 500);
        }

        $isHardened = ($server['ssh_user'] ?? '') === 'pxr'
            && !empty($server['key_path'])
            && file_exists($server['key_path']);

        // Not hardened yet -> nothing to push; it will be applied at harden time.
        if (!$isHardened) {
            return Response::success([
                'saved'   => true,
                'pushed'  => false,
                'message' => $key === ''
                    ? 'Key cleared. The fleet default key will be authorized when the server is hardened.'
                    : 'Key saved. It will be authorized when the server is hardened.',
            ]);
        }

        // Live push: rewrite pxr's authorized_keys = FM management key + this key.
        $pushed = false;
        $pushError = null;
        try {
            $ssh = $this->container->get(\FleetManager\Api\Services\SSHService::class);
            if ($ssh->connectToServer($server)) {
                $fmPub = '';
                try {
                    $priv = \phpseclib3\Crypt\PublicKeyLoader::load(file_get_contents($server['key_path']));
                    $fmPub = trim($priv->getPublicKey()->toString('OpenSSH', ['comment' => "fleet-mgmt@server{$id}"]));
                } catch (\Exception $e) {
                    // Non-fatal: we still push the operator key below.
                }

                $effective = $key !== ''
                    ? $key
                    : trim((string)$this->container->getConfig('ssh.pxr_authorized_key'));

                $content = "# Managed by Fleet Manager - do not edit by hand\n";
                if ($fmPub !== '') {
                    $content .= $fmPub . "\n";
                }
                if ($effective !== '' && $this->isValidSshPublicKey($effective)) {
                    $content .= trim($effective) . "\n";
                }

                $ssh->uploadContent($content, '/tmp/.fleet-pxr-authkeys');
                $r = $ssh->exec(
                    'install -d -m 700 -o pxr -g pxr /home/pxr/.ssh && '
                    . 'install -m 600 -o pxr -g pxr /tmp/.fleet-pxr-authkeys /home/pxr/.ssh/authorized_keys && '
                    . 'rm -f /tmp/.fleet-pxr-authkeys'
                );
                $pushed = !empty($r['success']);
                if (!$pushed) {
                    $pushError = trim((string)($r['output'] ?? $r['error'] ?? 'unknown error'));
                }
                $ssh->disconnect();
            } else {
                $pushError = 'could not connect to the server';
            }
        } catch (\Exception $e) {
            $pushError = $e->getMessage();
        }

        return Response::success([
            'saved'   => true,
            'pushed'  => $pushed,
            'message' => $pushed
                ? 'Authorized key updated and pushed to the server.'
                : 'Key saved, but the live push failed (' . ($pushError ?? 'unknown') . '). It will be applied on the next deploy.',
        ]);
    }

    /**
     * Loose validation that a string is a single SSH public key line.
     */
    private function isValidSshPublicKey(string $key): bool
    {
        $key = trim($key);
        if ($key === '') {
            return false;
        }
        return (bool)preg_match(
            '#^(ssh-(ed25519|rsa|dss)|ecdsa-sha2-[\w-]+|sk-(ssh-ed25519|ecdsa-sha2-[\w-]+)@openssh\.com)\s+[A-Za-z0-9+/]+=*(\s+.+)?$#',
            $key
        );
    }

    /**
     * Reset server status (for stuck deployments)
     * POST /api/servers/{id}/reset-status
     */
    public function resetStatus(Request $request, string $id): Response
    {
        $id = (int)$id;
        $db = $this->getDatabase();

        $stmt = $db->prepare("SELECT id, name, status FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();

        if (!$server) {
            return Response::notFound('Server not found');
        }

        // Reset server status to active (allows all deployment types)
        $stmt = $db->prepare("UPDATE servers SET status = 'active', provision_step = NULL, last_error = NULL WHERE id = ?");
        $stmt->execute([$id]);

        // Cancel any stuck deployments for this server
        $stmt = $db->prepare("UPDATE deployments SET status = 'cancelled', error_message = 'Manually reset by admin' WHERE server_id = ? AND status IN ('pending', 'running')");
        $stmt->execute([$id]);

        $this->logAction('server.reset_status', $id, $server['name'], 'success', [
            'previous_status' => $server['status']
        ]);

        return Response::success([
            'previous_status' => $server['status'],
            'new_status' => 'active'
        ], 'Server status reset to active');
    }

    /**
     * Get server statistics overview
     */
    public function stats(Request $request): Response
    {
        $db = $this->getDatabase();

        // Total servers
        $total = $db->query("SELECT COUNT(*) FROM servers")->fetchColumn();
        
        // By status
        $stmt = $db->query("SELECT status, COUNT(*) as count FROM servers GROUP BY status");
        $byStatus = [];
        while ($row = $stmt->fetch()) {
            $byStatus[$row['status']] = (int)$row['count'];
        }

        // Servers needing attention (offline or error)
        $needsAttention = $db->query(
            "SELECT COUNT(*) FROM servers WHERE status IN ('offline', 'error')"
        )->fetchColumn();

        // Unresolved errors
        $unresolvedErrors = $db->query(
            "SELECT COUNT(*) FROM server_errors WHERE resolved = 0"
        )->fetchColumn();

        // Servers with expiring SSL (within 30 days)
        $expiringSSL = $db->query(
            "SELECT COUNT(DISTINCT server_id) FROM server_health 
             WHERE panel_ssl_expiry <= DATE_ADD(NOW(), INTERVAL 30 DAY)
             OR email_ssl_expiry <= DATE_ADD(NOW(), INTERVAL 30 DAY)"
        )->fetchColumn();

        return Response::success([
            'total' => (int)$total,
            'by_status' => $byStatus,
            'needs_attention' => (int)$needsAttention,
            'unresolved_errors' => (int)$unresolvedErrors,
            'expiring_ssl' => (int)$expiringSSL,
        ]);
    }

    /**
     * Regenerate agent token
     */
    public function regenerateToken(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();
        $encryption = $this->getEncryption();

        $stmt = $db->prepare("SELECT id, name FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();

        if (!$server) {
            return Response::notFound('Server not found');
        }

        $newToken = $encryption->generateToken(32);

        $stmt = $db->prepare("UPDATE servers SET agent_token = ? WHERE id = ?");
        $stmt->execute([$newToken, $id]);

        $this->logAction('server.regenerate_token', $id, $server['name'], 'success');

        return Response::success(['agent_token' => $newToken], 'Token regenerated');
    }

    // ==========================================
    // Task Management Endpoints
    // ==========================================

    /**
     * Get tasks for a server
     * GET /api/servers/{id}/tasks
     */
    public function getTasks(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $status = $request->getQuery('status');
        $limit = (int)$request->getQuery('limit', 50);

        $taskService = $this->container->get(\FleetManager\Api\Services\TaskService::class);
        $tasks = $taskService->getServerTasks($id, $status, $limit);

        return Response::success($tasks);
    }

    /**
     * Create a task for a server
     * POST /api/servers/{id}/tasks
     */
    public function createTask(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $type = $request->input('type');
        $payload = $request->input('payload', []);
        $priority = (int)$request->input('priority', 5);

        if (!$type) {
            return Response::validationError(['type' => 'Task type is required']);
        }

        // Verify server exists
        $db = $this->getDatabase();
        $stmt = $db->prepare("SELECT id, name FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();

        if (!$server) {
            return Response::notFound('Server not found');
        }

        $user = $this->getCurrentUser();
        $taskService = $this->container->get(\FleetManager\Api\Services\TaskService::class);

        $task = $taskService->createTask(
            $id,
            $type,
            $payload,
            $priority,
            $user ? $user->sub : null
        );

        $this->logAction('task.create', $id, $server['name'], 'success', [
            'task_id' => $task['id'],
            'type' => $type,
        ]);

        return Response::success($task, 'Task created');
    }

    /**
     * Run a command on a server
     * POST /api/servers/{id}/run-command
     */
    public function runCommand(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $command = $request->input('command');
        $timeout = (int)$request->input('timeout', 300);

        if (!$command) {
            return Response::validationError(['command' => 'Command is required']);
        }

        // Verify server exists
        $db = $this->getDatabase();
        $stmt = $db->prepare("SELECT id, name FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();

        if (!$server) {
            return Response::notFound('Server not found');
        }

        $user = $this->getCurrentUser();
        $taskService = $this->container->get(\FleetManager\Api\Services\TaskService::class);

        $task = $taskService->createCommandTask($id, $command, $user ? $user->sub : null, $timeout);

        $this->logAction('task.run_command', $id, $server['name'], 'success', [
            'task_id' => $task['id'],
            'command' => substr($command, 0, 100),
        ]);

        return Response::success($task, 'Command queued');
    }

    /**
     * Sync files to a server
     * POST /api/servers/{id}/sync-files
     */
    public function syncFiles(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $files = $request->input('files', []);

        if (empty($files)) {
            return Response::validationError(['files' => 'Files array is required']);
        }

        // Verify server exists
        $db = $this->getDatabase();
        $stmt = $db->prepare("SELECT id, name FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();

        if (!$server) {
            return Response::notFound('Server not found');
        }

        $user = $this->getCurrentUser();
        $taskService = $this->container->get(\FleetManager\Api\Services\TaskService::class);

        $task = $taskService->createSyncFilesTask($id, $files, $user ? $user->sub : null);

        $this->logAction('task.sync_files', $id, $server['name'], 'success', [
            'task_id' => $task['id'],
            'file_count' => count($files),
        ]);

        return Response::success($task, 'File sync queued');
    }

    /**
     * Restart a service on a server
     * POST /api/servers/{id}/restart-service
     */
    public function restartService(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $service = $request->input('service');

        if (!$service) {
            return Response::validationError(['service' => 'Service name is required']);
        }

        // Verify server exists
        $db = $this->getDatabase();
        $stmt = $db->prepare("SELECT id, name FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();

        if (!$server) {
            return Response::notFound('Server not found');
        }

        $user = $this->getCurrentUser();
        $taskService = $this->container->get(\FleetManager\Api\Services\TaskService::class);

        $task = $taskService->createRestartServiceTask($id, $service, $user ? $user->sub : null);

        $this->logAction('task.restart_service', $id, $server['name'], 'success', [
            'task_id' => $task['id'],
            'service' => $service,
        ]);

        return Response::success($task, 'Service restart queued');
    }

    /**
     * Get the pending OS/npm updates report for a server
     * GET /api/servers/{id}/updates
     */
    public function getUpdates(Request $request): Response
    {
        $id = (int)$request->getParam('id');

        $db = $this->getDatabase();
        try {
            $stmt = $db->prepare("SELECT * FROM server_updates WHERE server_id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
        } catch (\Exception $e) {
            return Response::success(null, 'No update report yet');
        }

        if (!$row) {
            return Response::success(null, 'No update report yet');
        }

        return Response::success([
            'os_pending' => (int)$row['os_pending'],
            'npm_pending' => (int)$row['npm_pending'],
            'reboot_required' => (bool)$row['reboot_required'],
            'checked_at' => $row['checked_at'],
            'report' => json_decode($row['payload'], true),
        ]);
    }

    /**
     * Queue an update task on a server (system packages, npm deps, or both).
     * Affected services are restarted automatically by the agent; the machine
     * itself is never rebooted.
     * POST /api/servers/{id}/updates/apply
     */
    public function applyUpdates(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $scope = (string)$request->input('scope', 'all');

        if (!in_array($scope, ['check', 'system', 'npm', 'all'], true)) {
            return Response::validationError(['scope' => 'Scope must be one of: check, system, npm, all']);
        }

        $db = $this->getDatabase();
        $stmt = $db->prepare("SELECT id, name FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();

        if (!$server) {
            return Response::notFound('Server not found');
        }

        $payload = ['scope' => $scope];
        $packages = $request->input('packages');
        if (is_array($packages) && !empty($packages)) {
            $payload['packages'] = array_values(array_filter($packages, 'is_string'));
        }
        $services = $request->input('services');
        if (is_array($services) && !empty($services)) {
            $payload['services'] = array_values(array_filter($services, 'is_string'));
        }

        $user = $this->getCurrentUser();
        $taskService = $this->container->get(\FleetManager\Api\Services\TaskService::class);
        $task = $taskService->createUpdatePackagesTask($id, $payload, $user ? $user->sub : null);

        $this->logAction('task.update_packages', $id, $server['name'], 'success', [
            'task_id' => $task['id'],
            'scope' => $scope,
        ]);

        $message = $scope === 'check' ? 'Update check queued' : 'Update task queued';
        return Response::success($task, $message);
    }

    /**
     * Get a specific task
     * GET /api/servers/{id}/tasks/{taskId}
     */
    public function getTask(Request $request): Response
    {
        $taskId = (int)$request->getParam('taskId');

        $taskService = $this->container->get(\FleetManager\Api\Services\TaskService::class);
        $task = $taskService->getTask($taskId);

        if (!$task) {
            return Response::notFound('Task not found');
        }

        // Include logs
        $task['logs'] = $taskService->getTaskLogs($taskId);

        return Response::success($task);
    }

    /**
     * Cancel a task
     * POST /api/servers/{id}/tasks/{taskId}/cancel
     */
    public function cancelTask(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $taskId = (int)$request->getParam('taskId');

        $taskService = $this->container->get(\FleetManager\Api\Services\TaskService::class);
        
        $task = $taskService->getTask($taskId);
        if (!$task || $task['server_id'] != $id) {
            return Response::notFound('Task not found');
        }

        $cancelled = $taskService->cancelTask($taskId);

        if (!$cancelled) {
            return Response::error('Task cannot be cancelled (already running or completed)', 400);
        }

        return Response::success(null, 'Task cancelled');
    }

    /**
     * Wipe server - remove all installed software and configs
     * POST /api/servers/{id}/wipe
     */
    public function wipe(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();

        // Get server
        $stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();

        if (!$server) {
            return Response::notFound('Server not found');
        }

        // Get options from request
        $options = [
            'remove_packages' => $request->getBody()['remove_packages'] ?? true,
            'remove_apps' => $request->getBody()['remove_apps'] ?? true,
            'remove_databases' => $request->getBody()['remove_databases'] ?? false,
            'remove_vhosts' => $request->getBody()['remove_vhosts'] ?? true,
            'remove_ssl' => $request->getBody()['remove_ssl'] ?? false,
        ];

        // Update server status
        $db->prepare("UPDATE servers SET status = 'provisioning', provision_step = 'Wiping server...' WHERE id = ?")
            ->execute([$id]);

        // Create a deployment record for tracking
        $stmt = $db->prepare(
            "INSERT INTO deployments (server_id, type, status, current_step, started_at) VALUES (?, 'wipe', 'running', 'Starting wipe...', NOW())"
        );
        $stmt->execute([$id]);
        $deploymentId = (int)$db->lastInsertId();

        // Run wipe in background
        $optionsJson = escapeshellarg(json_encode($options));
        $cmd = sprintf(
            'cd %s && php cli/wipe.php %d %s > /dev/null 2>&1 &',
            dirname(__DIR__, 2),
            $id,
            $optionsJson
        );
        exec($cmd);

        $this->logAction('server.wipe', $id, $server['name'], 'initiated', $options);

        return Response::success([
            'deployment_id' => $deploymentId,
            'message' => 'Server wipe started',
        ]);
    }

    /**
     * List available server info reports
     * GET /api/servers/{id}/reports
     */
    public function listReports(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        
        $reportService = $this->container->get(ServerReportService::class);
        $reports = $reportService->listReports($id);
        
        return Response::success(['reports' => $reports]);
    }

    /**
     * Download a server info report
     * GET /api/servers/{id}/reports/download?filename=xxx
     */
    public function downloadReport(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $filename = $request->getQuery('filename');
        
        $reportService = $this->container->get(ServerReportService::class);
        $report = $reportService->getReport($id, $filename);
        
        if (!$report) {
            return Response::notFound('Report not found');
        }
        
        // Return as downloadable file
        return Response::fileContent(
            $report['content'],
            $report['filename'],
            'text/plain'
        );
    }

    /**
     * Generate a new server info report
     * POST /api/servers/{id}/reports/generate
     */
    public function generateReport(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        
        $db = $this->getDatabase();
        $stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();
        
        if (!$server) {
            return Response::notFound('Server not found');
        }
        
        $reportService = $this->container->get(ServerReportService::class);
        
        try {
            $filename = $reportService->generateServerReport($id);
            
            return Response::success([
                'filename' => $filename,
                'message' => 'Report generated successfully',
            ]);
        } catch (\Exception $e) {
            return Response::error('Failed to generate report: ' . $e->getMessage());
        }
    }

    /**
     * List issues for a server (from heartbeat monitoring)
     * GET /api/servers/{id}/issues?since=xxx&limit=xxx
     */
    public function listIssues(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $since = $request->getQuery('since');
        $limit = (int)($request->getQuery('limit') ?? 100);
        
        $reportService = $this->container->get(ServerReportService::class);
        $issues = $reportService->getIssues($id, $limit, $since);
        
        return Response::success(['issues' => $issues]);
    }

    /**
     * List available issue log files for a server
     * GET /api/servers/{id}/issue-logs
     */
    public function listIssueLogs(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        
        $reportService = $this->container->get(ServerReportService::class);
        $logs = $reportService->listIssueLogs($id);
        
        return Response::success(['logs' => $logs]);
    }

    /**
     * Get issue log content for a specific date
     * GET /api/servers/{id}/issue-logs/{date}
     */
    public function getIssueLog(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $date = $request->getParam('date');
        
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Response::error('Invalid date format. Use YYYY-MM-DD');
        }
        
        $reportService = $this->container->get(ServerReportService::class);
        $content = $reportService->getIssueLog($id, $date);
        
        if ($content === null) {
            return Response::notFound('Issue log not found for this date');
        }
        
        return Response::success([
            'date' => $date,
            'content' => $content,
        ]);
    }

    /**
     * Get all credentials for a server
     * GET /api/servers/{id}/credentials
     */
    public function getCredentials(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();
        $encryption = $this->getEncryption();

        // Verify server exists
        $stmt = $db->prepare("SELECT id, name FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            return Response::notFound('Server not found');
        }

        // Get all credentials (exclude audit data)
        $stmt = $db->prepare(
            "SELECT id, category, credential_key, label, value_encrypted, is_secret, updated_at
             FROM server_credentials
             WHERE server_id = ? AND category != 'audit'
             ORDER BY FIELD(category, 'panel', 'ssh', 'database', 'mail', 'services', 'agent', 'secrets', 'dns'), credential_key"
        );
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll();

        $credentials = [];
        foreach ($rows as $row) {
            $value = null;
            try {
                $value = $encryption->decrypt($row['value_encrypted']);
            } catch (\Exception $e) {
                $value = '[decryption failed]';
            }

            $credentials[] = [
                'id' => (int)$row['id'],
                'category' => $row['category'],
                'key' => $row['credential_key'],
                'label' => $row['label'],
                'value' => $value,
                'is_secret' => (bool)$row['is_secret'],
                'updated_at' => $row['updated_at'],
            ];
        }

        return Response::success(['credentials' => $credentials]);
    }

    /**
     * Fetch the Fleet Manager provisioning log that is written ONTO the target
     * server (/var/log/fleet/), so the operator can pull it without SSHing in.
     * Optional ?file=/var/log/fleet/provision-YYYYmmdd-HHMMSS.log selects a
     * specific historical run (validated against the on-box listing).
     *
     * GET /api/servers/{id}/provision-log
     */
    public function getProvisionLog(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();

        $stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();
        if (!$server) {
            return Response::notFound('Server not found');
        }

        $ssh = $this->container->get(\FleetManager\Api\Services\SSHService::class);
        if (!$ssh->connectToServer($server)) {
            return Response::error(
                'Could not reach the server over SSH to read its logs. Run Test Connection first - '
                . 'the Fleet Manager needs a working key/port (e.g. pxr@1985) to fetch the on-box log.',
                502
            );
        }

        try {
            // Newest first; tolerate the directory not existing yet.
            $listRes = $ssh->exec("ls -1t /var/log/fleet/*.log 2>/dev/null || true");
            $files = array_values(array_filter(array_map('trim', explode("\n", (string)($listRes['output'] ?? '')))));

            $path = '/var/log/fleet/provision-latest.log';
            $which = trim((string)$request->getQuery('file', ''));
            if ($which !== '' && in_array($which, $files, true)) {
                $path = $which;
            }

            $catRes = $ssh->exec('cat ' . escapeshellarg($path) . ' 2>/dev/null || true');
            $log = (string)($catRes['output'] ?? '');

            return Response::success([
                'path'  => $path,
                'files' => $files,
                'log'   => $log !== '' ? $log : '(no provisioning log found on this server yet)',
            ]);
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * List ALL DNS records for a server, read LIVE from the box's panel DB
     * (dns_domains / dns_records - the same tables PowerDNS serves from). Includes
     * anything the operator added in the panel, not just the deploy baseline.
     *
     * GET /api/servers/{id}/dns
     */
    public function getDns(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();

        $stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();
        if (!$server) {
            return Response::notFound('Server not found');
        }

        $ssh = $this->container->get(\FleetManager\Api\Services\SSHService::class);
        if (!$ssh->connectToServer($server)) {
            return Response::error(
                'Could not reach the server over SSH to read its DNS zone. Run Test Connection first.',
                502
            );
        }

        try {
            $baseDomain = preg_replace('/^panel\./', '', (string)($server['panel_domain'] ?? ''));
            $result = $this->fetchDnsRecords($ssh, (string)$baseDomain);
            return Response::success($result);
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Re-seed the mail-deliverability DNS records (SPF, DMARC, MX, DKIM) for the
     * box's base domain - idempotently (WHERE NOT EXISTS), so existing rows are
     * untouched and only the MISSING ones are added. Primary use: backfill the
     * DKIM TXT on boxes deployed before the DKIM-resolution fix, without a full
     * redeploy. DKIM is resolved live from the box's OpenDKIM key (the .txt, or
     * derived from the private key when the .txt is missing).
     *
     * POST /api/servers/{id}/dns/reseed
     */
    public function reseedDns(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();

        $stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();
        if (!$server) {
            return Response::notFound('Server not found');
        }

        $baseDomain = preg_replace('/^panel\./', '', (string)($server['panel_domain'] ?? ''));
        $serverIp   = (string)($server['ip_address'] ?? '');
        if ($baseDomain === '' || $serverIp === '') {
            return Response::error('Server is missing a panel domain or IP - cannot re-seed DNS.', 422);
        }
        $mailDomain = preg_replace('/^mail\./', '', (string)($server['mail_domain'] ?: $server['email_domain'] ?: $baseDomain));

        $ssh = $this->container->get(\FleetManager\Api\Services\SSHService::class);
        if (!$ssh->connectToServer($server)) {
            return Response::error(
                'Could not reach the server over SSH to re-seed DNS. Run Test Connection first.',
                502
            );
        }

        try {
            // 1. Resolve panel DB + DKIM public record live from the box.
            //    DB: prefer the schema that actually holds this box's zone (there
            //    can be several DBs with a dns_records table); fall back to the one
            //    with the most rows. DKIM: search the whole OpenDKIM key tree for a
            //    .private (selector/dir layout differs across boxes), derive the
            //    public part from the .txt or, if missing, straight from the key.
            $resolve = <<<'BASH'
CNF=/root/.my.cnf
echo "WHOAMI=$(id -un 2>/dev/null)"
BD="@@BASEDOMAIN@@"
cands=$(mariadb --defaults-file="$CNF" -N -e "SELECT table_schema FROM information_schema.tables WHERE table_name='dns_records' AND table_schema NOT IN ('mysql','information_schema','performance_schema','sys')" 2>/dev/null)
echo "DBCANDS=$(printf '%s ' $cands)"
DB=""
for db in $cands; do
  has=$(mariadb --defaults-file="$CNF" "$db" -N -e "SELECT 1 FROM dns_domains WHERE name='$BD' LIMIT 1" 2>/dev/null)
  if [ -n "$has" ]; then DB="$db"; break; fi
done
if [ -z "$DB" ]; then
  bestcount=-1
  for db in $cands; do
    cnt=$(mariadb --defaults-file="$CNF" "$db" -N -e "SELECT COUNT(*) FROM dns_records" 2>/dev/null)
    [ -z "$cnt" ] && cnt=0
    if [ "$cnt" -gt "$bestcount" ]; then bestcount="$cnt"; DB="$db"; fi
  done
fi
echo "PANELDB=$DB"
# DKIM key MUST belong to THIS box's own mail/base domain. A blind
# `find ... | head -1` grabs whichever domain the filesystem lists first, so on
# a multi-domain box (e.g. CyberPanel) it would publish a FOREIGN domain's DKIM
# key into this zone. Only look inside this domain's own key directory; if it has
# no key, publish nothing (a missing DKIM record is far better than a wrong one).
MD="@@MAILDOMAIN@@"
PRIV=""
for d in "$MD" "$BD"; do
  [ -z "$d" ] && continue
  [ -d "/etc/opendkim/keys/$d" ] || continue
  cand=$(find "/etc/opendkim/keys/$d" -maxdepth 1 -type f -name '*.private' 2>/dev/null | head -1)
  if [ -n "$cand" ]; then PRIV="$cand"; break; fi
done
echo "DKIMPRIV=$PRIV"
SEL=""; DOM=""
if [ -n "$PRIV" ]; then
  SEL=$(basename "$PRIV" .private)
  DOM=$(basename "$(dirname "$PRIV")")
fi
echo "DKIMSEL=$SEL"
echo "DKIMDOMAIN=$DOM"
VAL=""
if [ -n "$PRIV" ]; then
  TXT="$(dirname "$PRIV")/$SEL.txt"
  if [ -s "$TXT" ]; then VAL=$(cat "$TXT"); else
    PUB=$(openssl rsa -in "$PRIV" -pubout 2>/dev/null | grep -v '^-----' | tr -d '\n')
    [ -n "$PUB" ] && VAL="v=DKIM1;h=sha256;k=rsa;p=$PUB"
  fi
fi
CLEAN=$(printf '%s' "$VAL" | tr -d '\n' | sed -E 's/^[^(]*\(//; s/\).*$//' | tr -d '"' | tr -d '[:space:]')
echo "DKIMVALB64=$(printf '%s' "$CLEAN" | base64 | tr -d '\n')"
BASH;
            $resolve = str_replace(
                ['@@BASEDOMAIN@@', '@@MAILDOMAIN@@'],
                [
                    str_replace(['"', '$', '`', "'", '\\'], '', $baseDomain),
                    str_replace(['"', '$', '`', "'", '\\'], '', $mailDomain),
                ],
                $resolve
            );
            $execRes = $ssh->exec($resolve);
            $rOut    = (string)($execRes['output'] ?? '');
            $rCode   = $execRes['exit_code'] ?? null;

            $kv = [];
            foreach (preg_split('/\r\n|\r|\n/', $rOut) as $line) {
                $line = trim($line);
                if (strpos($line, '=') === false) continue;
                [$k, $v] = explode('=', $line, 2);
                $kv[trim($k)] = trim($v);
            }
            $panelDb   = $kv['PANELDB'] ?? '';
            $dkimDom   = ($kv['DKIMDOMAIN'] ?? '') !== '' ? $kv['DKIMDOMAIN'] : $mailDomain;
            $dkimSel   = $kv['DKIMSEL'] ?? '';
            $dkimValue = isset($kv['DKIMVALB64']) ? (string)base64_decode($kv['DKIMVALB64']) : '';
            $dkimPriv  = $kv['DKIMPRIV'] ?? '';
            $dbCands   = $kv['DBCANDS'] ?? '';
            $whoami    = $kv['WHOAMI'] ?? '?';

            if ($panelDb === '') {
                // The resolver echoes PANELDB= even when no DB is found, so a MISSING
                // PANELDB line means the SSH channel returned truncated/empty output -
                // NOT that the panel DB is absent. Distinguish the two so we stop
                // misreporting a flaky exec as "no dns_records table".
                $sawPanelLine = array_key_exists('PANELDB', $kv);
                if (!$sawPanelLine) {
                    $rawDump = trim($rOut);
                    if (strlen($rawDump) > 800) {
                        $rawDump = substr($rawDump, 0, 800) . ' ...[truncated]';
                    }
                    if ($rawDump === '') {
                        $rawDump = '(empty)';
                    }
                    return Response::error(
                        'DNS re-seed could not read the box: the SSH resolver returned incomplete output '
                        . '(the panel-DB probe never finished). This is an SSH/exec problem, not a missing '
                        . 'database. Diagnostics: ssh_user=' . $ssh->getConnectionUser()
                        . " exit_code=" . var_export($rCode, true)
                        . " whoami='{$whoami}' output_bytes=" . strlen($rOut)
                        . ' raw_output=<<' . $rawDump . '>>',
                        502
                    );
                }

                $hint = ($whoami !== 'root')
                    ? ' This box is NOT escalating to root over SSH - pxr passwordless sudo (NOPASSWD) is not working, '
                        . 'so the panel DB and DKIM key cannot be read. Fix the SSH-hardening sudoers entry (re-deploy), '
                        . 'or grant pxr NOPASSWD sudo on this box.'
                    : ' Connected as root but no dns_records table was found - the panel database may not be installed on this box.';
                return Response::error(
                    'Could not locate the panel database on the server (no dns_records table). '
                    . "Diagnostics: running as '{$whoami}'. "
                    . 'Candidates seen: ' . ($dbCands !== '' ? $dbCands : '(none)') . '.'
                    . $hint,
                    502
                );
            }

            // 2. Build idempotent INSERTs that mirror ProvisioningService::seedDnsRecords.
            $esc = fn($s) => str_replace("'", "", (string)$s);
            $dnsIns = function (string $name, string $type, string $content, int $ttl = 3600, int $prio = 0) use ($esc) {
                $prioCol = $prio ? ", prio" : "";
                $prioVal = $prio ? ", {$prio}" : "";
                $n = $esc($name); $t = $esc($type); $c = $esc($content);
                return "INSERT INTO dns_records (domain_id, name, type, content, ttl{$prioCol}) "
                    . "SELECT @zid, '{$n}', '{$t}', '{$c}', {$ttl}{$prioVal} "
                    . "FROM DUAL WHERE @zid IS NOT NULL AND NOT EXISTS ("
                    . "SELECT 1 FROM dns_records WHERE domain_id = @zid AND name = '{$n}' AND type = '{$t}'"
                    . ");\n";
            };

            $bd = $esc($baseDomain);
            $sql  = "USE `" . str_replace('`', '', $panelDb) . "`;\n";
            $sql .= "INSERT IGNORE INTO dns_domains (name, type) VALUES ('{$bd}', 'NATIVE');\n";
            $sql .= "SET @zid = (SELECT id FROM dns_domains WHERE name = '{$bd}');\n";
            $sql .= $dnsIns($baseDomain, 'TXT', "v=spf1 a mx ip4:{$serverIp} -all");
            $sql .= $dnsIns("_dmarc.{$baseDomain}", 'TXT', "v=DMARC1; p=reject; adkim=s; aspf=s; pct=100; rua=mailto:postmaster@{$baseDomain}; fo=1");
            $sql .= $dnsIns($baseDomain, 'MX', $baseDomain, 3600, 10);

            $seededDkim = false;
            if ($dkimSel !== '' && strpos($dkimValue, 'p=') !== false) {
                $dkimName = "{$dkimSel}._domainkey.{$dkimDom}";
                $sql .= $dnsIns($dkimName, 'TXT', $dkimValue);
                $seededDkim = true;
            }

            $ssh->uploadContent($sql, '/tmp/fleet-dns-reseed.sql');
            $runCmd = 'CNF=/root/.my.cnf; [ -f "$CNF" ] || CNF=""; '
                . 'if [ -n "$CNF" ]; then mariadb --defaults-file="$CNF" < /tmp/fleet-dns-reseed.sql 2>&1; '
                . 'else mariadb < /tmp/fleet-dns-reseed.sql 2>&1; fi; rm -f /tmp/fleet-dns-reseed.sql';
            $runOut = (string)($ssh->exec($runCmd)['output'] ?? '');

            // 3. Return the fresh, full record list so the UI updates immediately.
            $records = $this->fetchDnsRecords($ssh, $baseDomain);

            $dkimHint = $dkimPriv !== ''
                ? "DKIM private key was found ({$dkimPriv}) but its public value could not be derived."
                : "No OpenDKIM private key was found for this box's own domain "
                    . "(/etc/opendkim/keys/{$mailDomain} or /etc/opendkim/keys/{$baseDomain}). "
                    . 'Generate one for this domain, then re-seed.';
            $msg = $seededDkim
                ? 'DNS re-seeded (SPF, DMARC, MX, DKIM ensured).'
                : "DNS re-seeded (SPF, DMARC, MX ensured). DKIM not published: {$dkimHint}";
            return Response::success([
                'message'        => $msg,
                'seeded_dkim'    => $seededDkim,
                'panel_db'       => $panelDb,
                'db_candidates'  => $dbCands,
                'dkim_private'   => $dkimPriv,
                'dkim_selector'  => $dkimSel,
                'dkim_domain'    => $dkimDom,
                'sql_output'     => trim($runOut),
                'records'        => $records['records'] ?? [],
                'db'             => $records['db'] ?? $panelDb,
            ]);
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Live CPGuard state for a server: installed or not, service status and the
     * license file presence - read over SSH so it reflects reality, not our DB.
     *
     * GET /api/servers/{id}/cpguard
     */
    public function cpguardStatus(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();

        $stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();
        if (!$server) {
            return Response::notFound('Server not found');
        }

        $hasLicense = !empty($server['cpguard_license_key_encrypted']);

        $ssh = $this->container->get(\FleetManager\Api\Services\SSHService::class);
        if (!$ssh->connectToServer($server)) {
            return Response::success([
                'reachable'   => false,
                'installed'   => null,
                'has_license' => $hasLicense,
                'message'     => 'Server unreachable over SSH - run Test Connection first.',
            ]);
        }

        try {
            // App dir / systemd unit, NOT bare config dirs: blueprint config
            // deployment creates /etc/cpguard + /opt/cpguard even before CPGuard
            // is installed, so directory existence alone is a false positive.
            $res = $ssh->exec(
                'if [ -d /opt/cpguard/app ] || systemctl list-unit-files cpguard.service 2>/dev/null | grep -q "^cpguard"; then echo INSTALLED; else echo MISSING; fi; '
                . 'systemctl is-active cpguard 2>/dev/null || true; '
                . 'test -f /etc/cpguard/LICENSE_cPGuard && echo LICENSE_FILE || true'
            );
            $out = (string)($res['output'] ?? '');

            return Response::success([
                'reachable'    => true,
                'installed'    => strpos($out, 'INSTALLED') !== false,
                'service'      => preg_match('/^active$/m', $out) ? 'active' : 'inactive',
                'license_file' => strpos($out, 'LICENSE_FILE') !== false,
                'has_license'  => $hasLicense,
            ]);
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Live Docker container health for a Docker-provisioned server. Runs
     * `docker compose ps --format json` over SSH and returns per-service
     * state/health so the dashboard can show real container status instead of
     * the native systemd service list (which doesn't apply to a Docker box).
     *
     * GET /api/servers/{id}/docker-status
     */
    public function dockerStatus(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();

        $stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();
        if (!$server) {
            return Response::notFound('Server not found');
        }

        // A box is "Docker" once it has recorded a deployed image tag.
        $isDocker = !empty($server['deployed_image_tag']);

        $ssh = $this->container->get(\FleetManager\Api\Services\SSHService::class);
        if (!$ssh->connectToServer($server)) {
            return Response::success([
                'reachable' => false,
                'is_docker' => $isDocker,
                'tag'       => $server['deployed_image_tag'] ?? null,
                'services'  => [],
                'healthy'   => false,
                'message'   => 'Server unreachable over SSH - run Test Connection first.',
            ]);
        }

        try {
            $res = $ssh->exec(\FleetManager\Api\Services\DockerProvisioningService::psJsonCmd());
            $raw = (string)($res['output'] ?? '');
            $states = \FleetManager\Api\Services\DockerProvisioningService::parsePsJson($raw);
            $healthy = !empty($states)
                && \FleetManager\Api\Services\DockerProvisioningService::isStackHealthy($states);

            return Response::success([
                'reachable' => true,
                'is_docker' => $isDocker,
                'tag'       => $server['deployed_image_tag'] ?? null,
                'services'  => $states,
                'healthy'   => $healthy,
            ]);
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Install CPGuard on a provisioned server with a license key. The key comes
     * from the request body (and is then stored encrypted on the server row) or,
     * when omitted, from the key already on file. Runs the official installer:
     *   curl -sL https://download.configserver.com/cpguard/install.sh | bash -s -- <key>
     *
     * POST /api/servers/{id}/cpguard/install   { "license_key": "..." (optional) }
     */
    public function installCpguard(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();
        $encryption = $this->getEncryption();

        $stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();
        if (!$server) {
            return Response::notFound('Server not found');
        }

        // Resolve the license key: request body wins, fall back to the stored one
        $licenseKey = trim((string)$request->input('license_key', ''));
        if ($licenseKey === '' && !empty($server['cpguard_license_key_encrypted'])) {
            try {
                $licenseKey = $encryption->decrypt($server['cpguard_license_key_encrypted']);
            } catch (\Exception $e) {
                $licenseKey = '';
            }
        }
        if ($licenseKey === '') {
            return Response::validationError(['license_key' => 'No CPGuard license key provided or on file for this server']);
        }

        $ssh = $this->container->get(\FleetManager\Api\Services\SSHService::class);
        if (!$ssh->connectToServer($server)) {
            return Response::error(
                'Could not reach the server over SSH to install CPGuard. Run Test Connection first.',
                502
            );
        }

        try {
            // Already installed? Just refresh the license instead of re-running
            // the installer. App dir / unit check, not bare config dirs (those
            // can exist from blueprint config deployment alone).
            $installedCheck = 'if [ -d /opt/cpguard/app ] || systemctl list-unit-files cpguard.service 2>/dev/null | grep -q "^cpguard"; then echo INSTALLED; fi';
            $check = $ssh->exec($installedCheck);
            $alreadyInstalled = strpos((string)($check['output'] ?? ''), 'INSTALLED') !== false;

            if ($alreadyInstalled) {
                $res = $ssh->execWithTimeout(
                    'mkdir -p /etc/cpguard && echo ' . escapeshellarg($licenseKey) . ' > /etc/cpguard/LICENSE_cPGuard'
                    . ' && systemctl restart cpguard 2>/dev/null; echo DONE',
                    120
                );
                $output = (string)($res['output'] ?? '');
                $message = 'CPGuard was already installed - license key updated';
            } else {
                $res = $ssh->execWithTimeout(
                    'curl -sL https://download.configserver.com/cpguard/install.sh 2>/dev/null | bash -s -- '
                    . escapeshellarg($licenseKey) . ' 2>&1',
                    900
                );
                $output = (string)($res['output'] ?? '');

                $verify = $ssh->exec($installedCheck);
                if (strpos((string)($verify['output'] ?? ''), 'INSTALLED') === false) {
                    $this->logAction('server.cpguard_install', $id, $server['name'], 'failed', [
                        'output_tail' => substr($output, -500),
                    ]);
                    return Response::error(
                        "CPGuard installer finished but no installation was detected. Installer output (tail):\n"
                        . substr($output, -2000),
                        500
                    );
                }

                // Overlay the blueprint's extracted CPGuard tuning (live-server
                // main.conf, modsec wiring, badbots/rules, white/blacklists) on
                // top of the installer's stock files. Fresh installs only - an
                // already-installed CPGuard keeps its own tuning. Best-effort:
                // a failed overlay leaves a working stock CPGuard.
                $configsApplied = 0;
                if (!empty($server['blueprint_id'])) {
                    try {
                        $templateService = $this->container->get(\FleetManager\Api\Services\TemplateService::class);
                        $variables = $templateService->generateServerVariables($server);
                        $templates = $templateService->processBlueprintTemplates((int)$server['blueprint_id'], $variables);
                        foreach ($templates as $tpl) {
                            $path = (string)($tpl['target_path'] ?? '');
                            if (!str_starts_with($path, '/etc/cpguard/') && !str_starts_with($path, '/opt/cpguard/')) {
                                continue;
                            }
                            if (!empty($templateService->findUnresolvedPlaceholders($tpl['content'] ?? ''))) {
                                continue;
                            }
                            $ssh->mkdir(dirname($path));
                            $ssh->uploadContent($tpl['content'], $path);
                            $ssh->chmod($path, $tpl['permissions']);
                            $ssh->chown($path, $tpl['owner'], $tpl['group']);
                            $configsApplied++;
                        }
                    } catch (\Throwable $e) {
                        // Stock configs remain usable; surface nothing fatal here.
                    }
                }
                $ssh->exec('systemctl restart cpguard 2>/dev/null || true');

                // CPGuard's Login Defender replaces fail2ban (mirrors the live
                // server). Stop + disable so a reboot doesn't bring it back to
                // double-ban alongside CPGuard.
                $ssh->exec('systemctl stop fail2ban 2>/dev/null; systemctl disable fail2ban 2>/dev/null || true');

                $message = $configsApplied > 0
                    ? "CPGuard installed and {$configsApplied} tuning config(s) applied from the blueprint (fail2ban disabled - superseded)"
                    : 'CPGuard installed successfully (stock configuration - blueprint had no CPGuard tuning); fail2ban disabled - superseded';
            }

            // Persist the key (encrypted) so reinstalls/redeploys reuse it
            $upd = $db->prepare("UPDATE servers SET cpguard_license_key_encrypted = ? WHERE id = ?");
            $upd->execute([$encryption->encrypt($licenseKey), $id]);

            $this->logAction('server.cpguard_install', $id, $server['name'], 'success', [
                'already_installed' => $alreadyInstalled,
            ]);

            return Response::success([
                'installed'   => true,
                'message'     => $message,
                'output_tail' => substr($output, -2000),
            ], $message);
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Read all DNS records from the box's panel DB over an already-open SSH
     * session. Returns ['db' => string|null, 'records' => [ {zone,name,type,
     * content,ttl,prio}, ... ]].
     */
    private function fetchDnsRecords(\FleetManager\Api\Services\SSHService $ssh, string $baseDomain = ''): array
    {
        $script = <<<'BASH'
CNF=/root/.my.cnf
BD="@@BASEDOMAIN@@"
cands=$(mariadb --defaults-file="$CNF" -N -e "SELECT table_schema FROM information_schema.tables WHERE table_name='dns_records' AND table_schema NOT IN ('mysql','information_schema','performance_schema','sys')" 2>/dev/null)
DB=""
if [ -n "$BD" ]; then
  for db in $cands; do
    has=$(mariadb --defaults-file="$CNF" "$db" -N -e "SELECT 1 FROM dns_domains WHERE name='$BD' LIMIT 1" 2>/dev/null)
    if [ -n "$has" ]; then DB="$db"; break; fi
  done
fi
if [ -z "$DB" ]; then
  bestcount=-1
  for db in $cands; do
    cnt=$(mariadb --defaults-file="$CNF" "$db" -N -e "SELECT COUNT(*) FROM dns_records" 2>/dev/null)
    [ -z "$cnt" ] && cnt=0
    if [ "$cnt" -gt "$bestcount" ]; then bestcount="$cnt"; DB="$db"; fi
  done
fi
[ -z "$DB" ] && { echo "__CANDS__=$(printf '%s ' $cands)"; echo "__NODB__"; exit 0; }
echo "__DB__=$DB"
mariadb --defaults-file="$CNF" "$DB" -N -e "SELECT CONCAT_WS('@@SEP@@', d.name, r.name, r.type, r.content, r.ttl, COALESCE(r.prio,0)) FROM dns_records r JOIN dns_domains d ON d.id=r.domain_id ORDER BY d.name, FIELD(r.type,'SOA','NS','A','AAAA','MX','TXT','CNAME','SRV','CAA'), r.name" 2>/dev/null
BASH;
        $script = str_replace(
            ['@@SEP@@', '@@BASEDOMAIN@@'],
            ['~|~', str_replace(['"', '$', '`', "'", '\\'], '', $baseDomain)],
            $script
        );
        $out = (string)($ssh->exec($script)['output'] ?? '');

        $db = null;
        $cands = null;
        $records = [];
        foreach (preg_split('/\r\n|\r|\n/', $out) as $line) {
            $line = rtrim($line);
            if ($line === '' || $line === '__NODB__') continue;
            if (strpos($line, '__CANDS__=') === 0) { $cands = trim(substr($line, 10)); continue; }
            if (strpos($line, '__DB__=') === 0) { $db = substr($line, 7); continue; }
            $parts = explode('~|~', $line);
            if (count($parts) < 6) continue;
            $records[] = [
                'zone'    => $parts[0],
                'name'    => $parts[1],
                'type'    => $parts[2],
                'content' => $parts[3],
                'ttl'     => (int)$parts[4],
                'prio'    => (int)$parts[5],
            ];
        }
        return ['db' => $db, 'records' => $records, 'candidates' => $cands];
    }

    /**
     * Get the last audit results for a server
     * GET /api/servers/{id}/audit
     */
    public function getAudit(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();
        $encryption = $this->getEncryption();

        // Get audit from server_credentials
        $stmt = $db->prepare(
            "SELECT value_encrypted, updated_at FROM server_credentials
             WHERE server_id = ? AND credential_key = 'LAST_AUDIT'
             ORDER BY updated_at DESC LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            return Response::success(['audit' => null, 'message' => 'No audit data available']);
        }

        try {
            $audit = json_decode($encryption->decrypt($row['value_encrypted']), true);
            $audit['stored_at'] = $row['updated_at'];
            return Response::success(['audit' => $audit]);
        } catch (\Exception $e) {
            return Response::error('Failed to decrypt audit data');
        }
    }
}

