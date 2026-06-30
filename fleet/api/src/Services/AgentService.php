<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;

/**
 * Service for communicating with the privileged agent daemon
 * 
 * The agent runs as root and handles all system-level operations
 * like reading protected config files and extracting server configs.
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
        $this->socketPath = $container->getConfig('agent.socket') ?? '/run/fleet-manager/agent.sock';
        $this->timeout = $container->getConfig('agent.timeout') ?? 300;
        
        // Load auth token
        $tokenFile = $container->getConfig('agent.token_file') ?? '/var/www/vps-fleet/var/agent.token';
        if (file_exists($tokenFile)) {
            $this->token = trim(file_get_contents($tokenFile));
        }
    }

    /**
     * Execute an agent action
     * 
     * @param string $action Action in format "namespace.method" (e.g., "extractor.extract")
     * @param array $params Parameters to pass to the action
     * @param int|null $customTimeout Custom timeout for long-running operations
     * @return array Result with 'success' key
     */
    public function execute(string $action, array $params = [], ?int $customTimeout = null): array
    {
        $timeout = $customTimeout ?? $this->timeout;
        
        // Build request
        $request = [
            'action' => $action,
            'params' => $params,
            'token' => $this->token,
            'actor' => 'api',
        ];

        return $this->sendRequest($request, $timeout);
    }

    /**
     * Send request to agent via Unix socket
     */
    private function sendRequest(array $request, int $timeout): array
    {
        // Check socket exists
        if (!file_exists($this->socketPath)) {
            error_log("Agent socket not found at: {$this->socketPath}");
            return [
                'success' => false,
                'error' => 'Agent service is not running. Please start it with: systemctl start fleet-agent',
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
            error_log("Failed to create socket: {$error}");
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
            error_log("Failed to connect to agent socket: {$error}");
            socket_close($socket);
            return [
                'success' => false,
                'error' => 'Cannot connect to agent service. Please verify it is running.',
                'details' => [
                    'socket_path' => $this->socketPath,
                    'system_error' => $error,
                    'suggestion' => 'Run: systemctl status fleet-agent',
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

        // Read response - keep reading until connection closes or timeout
        // Don't break early on '}' as large JSON responses may have intermediate chunks ending with '}'
        $response = '';
        $startTime = time();
        
        while (true) {
            // Check for timeout
            if ((time() - $startTime) > $timeout) {
                socket_close($socket);
                return [
                    'success' => false,
                    'error' => 'Agent response timeout after ' . $timeout . ' seconds',
                ];
            }
            
            $chunk = @socket_read($socket, 65536); // Read larger chunks (64KB)
            
            if ($chunk === false) {
                $error = socket_strerror(socket_last_error($socket));
                // If we already have data and got an error, try to use what we have
                if (!empty($response)) {
                    break;
                }
                socket_close($socket);
                return [
                    'success' => false,
                    'error' => 'Failed to read response: ' . $error,
                ];
            }
            
            // Empty string means connection closed - we're done
            if ($chunk === '') {
                break;
            }
            
            $response .= $chunk;
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
            error_log("Failed to parse agent response: " . substr($response, 0, 200));
            return [
                'success' => false,
                'error' => 'Invalid response from agent',
                'details' => ['raw_response' => substr($response, 0, 500)],
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

        $result = $this->execute('extractor.test', [], 5);
        return $result['success'] ?? false;
    }

    /**
     * Extract configs from local server
     */
    public function extract(bool $dryRun = false, ?array $categories = null, array $options = []): array
    {
        $params = [
            'dry_run' => $dryRun,
            'categories' => $categories,
        ];
        
        // Add extraction mode options
        if (isset($options['mode'])) {
            $params['mode'] = $options['mode'];
        }
        if (isset($options['include_core_apps'])) {
            $params['include_core_apps'] = $options['include_core_apps'];
        }
        if (isset($options['selected_vhosts'])) {
            $params['selected_vhosts'] = $options['selected_vhosts'];
        }
        
        return $this->execute('extractor.extract', $params, 300); // 5 minute timeout for extraction
    }
    
    /**
     * Get extraction categories with type info
     */
    public function getCategories(string $mode = 'full_clone'): array
    {
        return $this->execute('extractor.categories', [
            'mode' => $mode,
        ], 30);
    }
}

