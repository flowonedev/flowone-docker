<?php

namespace VpsAdmin\Api\Services;

use VpsAdmin\Api\Core\Container;

/**
 * Service for communicating with the privileged agent
 */
class AgentService
{
    private Container $container;
    private string $socketPath;
    private ?string $token = null;
    private int $timeout;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->socketPath = $container->getConfig('agent.socket');
        $this->timeout = $container->getConfig('agent.timeout') ?? 30;
        
        // Load auth token
        $tokenFile = $container->getConfig('agent.token_file');
        if (file_exists($tokenFile)) {
            $this->token = trim(file_get_contents($tokenFile));
        }
    }

    /**
     * Execute an agent action
     * 
     * @param string $action Action in format "namespace.method"
     * @param array $params Parameters to pass
     * @param string $actor The user performing the action
     * @param int|null $timeout Optional custom timeout in seconds (for long-running operations)
     * @return array Response from agent
     */
    public function execute(string $action, array $params = [], string $actor = 'api', ?int $timeout = null): array
    {
        $request = [
            'action' => $action,
            'params' => $params,
            'actor' => $actor,
            'token' => $this->token,
        ];

        return $this->send($request, $timeout);
    }

    /**
     * Send request to agent
     * @param int|null $customTimeout Optional custom timeout override
     */
    private function send(array $request, ?int $customTimeout = null): array
    {
        $timeout = $customTimeout ?? $this->timeout;
        // Check socket exists
        if (!file_exists($this->socketPath)) {
            debug_log("Agent socket not found at: {$this->socketPath}");
            debug_log("Please ensure vpsadmin-agent service is running: systemctl status vpsadmin-agent");
            return [
                'success' => false,
                'error' => 'Agent service is not running. Please start it with: systemctl start vpsadmin-agent',
                'details' => [
                    'socket_path' => $this->socketPath,
                    'checked_at' => date('Y-m-d H:i:s'),
                ],
            ];
        }

        // Create socket
        $socket = @socket_create(AF_UNIX, SOCK_STREAM, 0);
        
        if ($socket === false) {
            $error = socket_strerror(socket_last_error());
            debug_log("Failed to create socket: {$error}");
            return [
                'success' => false,
                'error' => 'System error: Could not create socket connection',
                'details' => ['system_error' => $error],
            ];
        }

        // Set timeout
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $timeout, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $timeout, 'usec' => 0]);

        // Connect
        if (!@socket_connect($socket, $this->socketPath)) {
            $error = socket_strerror(socket_last_error($socket));
            debug_log("Failed to connect to agent socket: {$error}");
            debug_log("Socket path: {$this->socketPath}");
            debug_log("Check agent status: systemctl status vpsadmin-agent");
            socket_close($socket);
            return [
                'success' => false,
                'error' => 'Cannot connect to agent service. Please verify it is running.',
                'details' => [
                    'socket_path' => $this->socketPath,
                    'system_error' => $error,
                    'suggestion' => 'Run: systemctl status vpsadmin-agent',
                ],
            ];
        }

        // Send request
        $data = json_encode($request) . "\n\n";
        
        if (@socket_write($socket, $data) === false) {
            $error = socket_strerror(socket_last_error($socket));
            socket_close($socket);
            return [
                'success' => false,
                'error' => 'Failed to send request: ' . $error,
            ];
        }

        $response = '';
        $readStart = time();
        $eagainRetries = 0;
        $maxEagainRetries = 3;
        
        while (true) {
            $chunk = @socket_read($socket, 8192);
            
            if ($chunk === false) {
                $errno = socket_last_error($socket);
                $error = socket_strerror($errno);
                
                // EAGAIN/EWOULDBLOCK (11) = SO_RCVTIMEO expired, retry if within budget
                if ($errno === 11 && $eagainRetries < $maxEagainRetries) {
                    $eagainRetries++;
                    $elapsed = time() - $readStart;
                    debug_log("Agent read timeout (attempt {$eagainRetries}), elapsed: {$elapsed}s, action: " . ($request['action'] ?? '?'));
                    continue;
                }
                
                socket_close($socket);
                $elapsed = time() - $readStart;
                
                if ($errno === 11) {
                    return [
                        'success' => false,
                        'error' => "Agent operation timed out after {$elapsed}s. The operation may still be running on the server.",
                        'details' => [
                            'action' => $request['action'] ?? '?',
                            'timeout' => $timeout,
                            'elapsed' => $elapsed,
                        ],
                    ];
                }
                
                return [
                    'success' => false,
                    'error' => 'Failed to read agent response: ' . $error,
                ];
            }
            
            if ($chunk === '') {
                break;
            }
            
            $response .= $chunk;
            $eagainRetries = 0;
            
            if (strpos($response, "\n") !== false) {
                break;
            }
        }

        socket_close($socket);

        // Parse response
        $response = trim($response);
        
        if (empty($response)) {
            return [
                'success' => false,
                'error' => 'Empty response from agent',
            ];
        }

        $decoded = json_decode($response, true);
        
        if ($decoded === null) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response from agent',
            ];
        }

        return $decoded;
    }

    /**
     * Check if agent is running
     */
    public function isRunning(): bool
    {
        if (!file_exists($this->socketPath)) {
            return false;
        }

        $result = $this->execute('service.list');
        return $result['success'] ?? false;
    }

    /**
     * Helper methods for common actions
     */
    
    public function getServices(): array
    {
        return $this->execute('service.list');
    }

    public function getServiceStatus(string $name): array
    {
        return $this->execute('service.status', ['name' => $name]);
    }

    public function restartService(string $name): array
    {
        return $this->execute('service.restart', ['name' => $name]);
    }

    public function reloadService(string $name): array
    {
        return $this->execute('service.reload', ['name' => $name]);
    }

    public function getVhosts(): array
    {
        return $this->execute('vhost.list');
    }

    public function getVhost(string $domain): array
    {
        return $this->execute('vhost.get', ['domain' => $domain]);
    }

    // Note: createVhost() and deleteVhost() were removed in Phase 5 of
    // the V2 consolidation. Site provisioning now goes through
    // SiteProvisioningController (POST /api/sites/v2) which enqueues
    // saga jobs; teardown goes through DELETE /api/sites/v2/{domain}.
    // The underlying `vhost.create` / `vhost.delete` agent actions
    // have likewise been removed from VhostAction.

    public function getSslCertificates(): array
    {
        return $this->execute('ssl.list');
    }

    public function getSslCertificate(string $domain): array
    {
        return $this->execute('ssl.inspect', ['domain' => $domain]);
    }

    public function sslPreflight(string $domain): array
    {
        return $this->execute('ssl.preflight', ['domain' => $domain]);
    }

    public function issueSsl(string $domain, string $email = null): array
    {
        $params = ['domain' => $domain];
        if ($email) {
            $params['email'] = $email;
        }
        return $this->execute('ssl.issue', $params);
    }

    public function getDatabases(): array
    {
        return $this->execute('db.list');
    }

    public function getDatabase(string $name): array
    {
        return $this->execute('db.get', ['name' => $name]);
    }

    public function createDatabase(array $params): array
    {
        return $this->execute('db.create', $params);
    }

    public function deleteDatabase(string $name): array
    {
        return $this->execute('db.delete', ['name' => $name]);
    }
}

