#!/usr/bin/env php
<?php
/**
 * Fleet Manager Agent Daemon
 * 
 * This is the privileged agent that performs system operations.
 * It listens on a UNIX socket and only accepts allowlisted actions.
 * 
 * MUST be run as root.
 * 
 * Usage: php agent.php [--foreground]
 */

declare(strict_types=1);

// Autoload
spl_autoload_register(function ($class) {
    $prefix = 'FleetManager\\Agent\\';
    $baseDir = __DIR__ . '/';
    
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

use FleetManager\Agent\Lib\Logger;
use FleetManager\Agent\Lib\ActionInterface;

/**
 * Safely write to socket, suppressing broken pipe errors
 * Handles large data by writing in chunks
 * Returns false if write failed (client disconnected)
 */
function safeSocketWrite($socket, string $data): bool {
    $totalLength = strlen($data);
    $written = 0;
    
    while ($written < $totalLength) {
        $chunk = substr($data, $written, 65536); // 64KB chunks
        $result = @socket_write($socket, $chunk);
        
        if ($result === false) {
            $error = socket_last_error($socket);
            // 32 = EPIPE (Broken pipe), 104 = ECONNRESET (Connection reset)
            if ($error !== 32 && $error !== 104) {
                error_log("Socket write error: " . socket_strerror($error));
            }
            return false;
        }
        
        $written += $result;
        
        // If socket_write returned 0, something is wrong
        if ($result === 0) {
            error_log("Socket write returned 0, aborting");
            return false;
        }
    }
    
    return true;
}

// Load configuration
$config = require __DIR__ . '/config.php';

// Check if running as root
if (posix_getuid() !== 0) {
    fwrite(STDERR, "ERROR: Agent must run as root\n");
    exit(1);
}

// Parse arguments
$foreground = in_array('--foreground', $argv) || in_array('-f', $argv);

// Initialize components
$logger = new Logger($config);

// Load action handlers
$actions = [];
$actionFiles = glob(__DIR__ . '/Actions/*Action.php');

foreach ($actionFiles as $file) {
    $className = 'FleetManager\\Agent\\Actions\\' . basename($file, '.php');
    if (class_exists($className)) {
        $action = new $className($config, $logger);
        if ($action instanceof ActionInterface) {
            $actions[$action->getNamespace()] = $action;
        }
    }
}

$logger->info('Agent starting', [
    'actions' => array_keys($actions),
    'socket' => $config['socket']['path'],
]);

// Setup socket
$socketPath = $config['socket']['path'];
$socketDir = dirname($socketPath);

if (!is_dir($socketDir)) {
    mkdir($socketDir, 0750, true);
}

// Remove existing socket
if (file_exists($socketPath)) {
    unlink($socketPath);
}

// Create socket
$socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
if ($socket === false) {
    $logger->error('Failed to create socket', ['error' => socket_strerror(socket_last_error())]);
    exit(1);
}

if (!socket_bind($socket, $socketPath)) {
    $logger->error('Failed to bind socket', ['error' => socket_strerror(socket_last_error($socket))]);
    exit(1);
}

// Set socket permissions
chmod($socketPath, $config['socket']['permissions']);
if (isset($config['socket']['group'])) {
    $group = $config['socket']['group'];
    if (is_numeric($group)) {
        @chgrp($socketPath, (int)$group);
    } else {
        $groupInfo = posix_getgrnam($group);
        if ($groupInfo) {
            if (!@chgrp($socketPath, $groupInfo['gid'])) {
                $logger->warning('Could not set socket group', ['group' => $group]);
            }
        }
    }
}

if (!socket_listen($socket, 5)) {
    $logger->error('Failed to listen on socket', ['error' => socket_strerror(socket_last_error($socket))]);
    exit(1);
}

$logger->info('Agent listening on socket', ['path' => $socketPath]);

// Handle signals
$running = true;

pcntl_signal(SIGTERM, function() use (&$running, $logger) {
    $logger->info('Received SIGTERM, shutting down');
    $running = false;
});

pcntl_signal(SIGINT, function() use (&$running, $logger) {
    $logger->info('Received SIGINT, shutting down');
    $running = false;
});

// Daemonize if not foreground
if (!$foreground) {
    $pid = pcntl_fork();
    
    if ($pid < 0) {
        $logger->error('Failed to fork');
        exit(1);
    }
    
    if ($pid > 0) {
        // Parent exits
        exit(0);
    }
    
    // Create new session
    if (posix_setsid() < 0) {
        $logger->error('Failed to create new session');
        exit(1);
    }
    
    // Write PID file
    $pidFile = dirname($socketPath) . '/agent.pid';
    file_put_contents($pidFile, getmypid());
    
    // Close standard file descriptors
    fclose(STDIN);
    fclose(STDOUT);
    fclose(STDERR);
}

// Main loop
while ($running) {
    pcntl_signal_dispatch();
    
    // Accept connection with timeout
    $read = [$socket];
    $write = null;
    $except = null;
    
    $result = @socket_select($read, $write, $except, 1);
    
    if ($result === false) {
        $error = socket_last_error($socket);
        // EINTR (4) = interrupted by signal, 0 = no error (spurious wakeup)
        // Both are safe to continue
        if ($error === SOCKET_EINTR || $error === 0) {
            socket_clear_error($socket);
            continue;
        }
        $logger->error('Socket select error', ['error' => socket_strerror($error), 'code' => $error]);
        break;
    }
    
    if ($result === 0) {
        continue;
    }
    
    $client = socket_accept($socket);
    
    if ($client === false) {
        $logger->warning('Failed to accept connection');
        continue;
    }
    
    // Set longer timeout for extraction operations
    socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 300, 'usec' => 0]);
    socket_set_option($client, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 300, 'usec' => 0]);
    
    // Read request
    $request = '';
    while (true) {
        $chunk = socket_read($client, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $request .= $chunk;
        if (strpos($request, "\n\n") !== false) {
            break;
        }
    }
    
    $request = trim($request);
    
    if (empty($request)) {
        socket_close($client);
        continue;
    }
    
    $logger->debug('Received request', ['raw' => substr($request, 0, 200)]);
    
    // Parse request
    $data = json_decode($request, true);
    
    if (!$data || !isset($data['action'])) {
        $response = json_encode([
            'success' => false,
            'error' => 'Invalid request format',
        ]);
        safeSocketWrite($client, $response . "\n");
        socket_close($client);
        continue;
    }
    
    // Verify auth token
    if ($config['security']['require_auth_token']) {
        $token = $data['token'] ?? null;
        if (!validateToken($token, $config)) {
            $logger->warning('Invalid auth token', ['action' => $data['action']]);
            $response = json_encode([
                'success' => false,
                'error' => 'Unauthorized',
            ]);
            safeSocketWrite($client, $response . "\n");
            socket_close($client);
            continue;
        }
    }
    
    // Parse action (format: namespace.method)
    $actionParts = explode('.', $data['action'], 2);
    
    if (count($actionParts) !== 2) {
        $response = json_encode([
            'success' => false,
            'error' => 'Invalid action format. Use: namespace.method',
        ]);
        safeSocketWrite($client, $response . "\n");
        socket_close($client);
        continue;
    }
    
    $namespace = $actionParts[0];
    $method = $actionParts[1];
    $params = $data['params'] ?? [];
    $actor = $data['actor'] ?? 'unknown';
    
    // Check if action exists
    if (!isset($actions[$namespace])) {
        $logger->warning('Unknown action namespace', ['namespace' => $namespace]);
        $response = json_encode([
            'success' => false,
            'error' => "Unknown action namespace: {$namespace}",
        ]);
        safeSocketWrite($client, $response . "\n");
        socket_close($client);
        continue;
    }
    
    // Execute action
    try {
        $logger->info('Executing action', ['action' => $data['action'], 'actor' => $actor]);
        $result = $actions[$namespace]->execute($method, $params, $actor);
        
        // Try to encode response, handling encoding errors
        $response = json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        
        if ($response === false) {
            $jsonError = json_last_error_msg();
            $logger->error('JSON encode failed', ['action' => $data['action'], 'error' => $jsonError]);
            $response = json_encode([
                'success' => false,
                'error' => 'Failed to encode response: ' . $jsonError,
            ]);
        } else {
            $logger->info('Action completed', [
                'action' => $data['action'],
                'response_size' => strlen($response),
                'response_size_human' => round(strlen($response) / 1024, 2) . ' KB',
            ]);
        }
    } catch (\Throwable $e) {
        $logger->error('Action execution failed', [
            'action' => $data['action'],
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        $response = json_encode([
            'success' => false,
            'error' => 'Internal error: ' . $e->getMessage(),
        ]);
    }
    
    if (!safeSocketWrite($client, $response . "\n")) {
        $logger->warning('Failed to write response to client', ['action' => $data['action']]);
    }
    socket_close($client);
}

// Cleanup
socket_close($socket);
@unlink($socketPath);

$logger->info('Agent stopped');

/**
 * Validate authentication token
 */
function validateToken(?string $token, array $config): bool
{
    if ($token === null) {
        return false;
    }
    
    $tokenFile = $config['paths']['token_file'];
    
    if (!file_exists($tokenFile)) {
        return false;
    }
    
    $validToken = trim(file_get_contents($tokenFile));
    
    return hash_equals($validToken, $token);
}

