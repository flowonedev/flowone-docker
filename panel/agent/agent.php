#!/usr/bin/env php
<?php
/**
 * VPS Admin Agent Daemon
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
    $prefix = 'VpsAdmin\\Agent\\';
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

// Autoload shared FlowOne\Storage\ classes. The shared library is loaded
// by both this agent (for NasMonitorAction thin-wrapper delegation) and
// by the email backend (via Composer).
//
// Path resolution differs between production and local dev:
//   PRODUCTION:  /var/www/vps-admin/agent/agent.php with shared at
//                /var/www/shared/  => __DIR__/../../shared
//   LOCAL DEV:   panel/agent/agent.php with shared at panel/shared/
//                => __DIR__/../shared
// We try both candidate roots; whichever one exists wins.
spl_autoload_register(function ($class) {
    $prefix = 'FlowOne\\Storage\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    static $sharedRoot = null;
    if ($sharedRoot === null) {
        foreach ([__DIR__ . '/../../shared', __DIR__ . '/../shared'] as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved !== false && is_dir($resolved . '/src/Storage')) {
                $sharedRoot = $resolved;
                break;
            }
        }
        if ($sharedRoot === null) {
            $sharedRoot = false;
        }
    }
    if ($sharedRoot === false) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $f = $sharedRoot . '/src/Storage/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($f)) {
        require $f;
    }
});

use VpsAdmin\Agent\Lib\BackupManager;
use VpsAdmin\Agent\Lib\DiffGenerator;
use VpsAdmin\Agent\Lib\Logger;
use VpsAdmin\Agent\Lib\ActionInterface;

/**
 * Safely write to socket, suppressing broken pipe errors
 * Returns false if write failed (client disconnected)
 */
function safeSocketWrite($socket, string $data): bool {
    // Suppress warnings for broken pipe (client disconnected)
    $result = @socket_write($socket, $data);
    if ($result === false) {
        $error = socket_last_error($socket);
        // 32 = EPIPE (Broken pipe), 104 = ECONNRESET (Connection reset)
        if ($error !== 32 && $error !== 104) {
            // Log unexpected errors
            error_log("Socket write error: " . socket_strerror($error));
        }
        return false;
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
$backup = new BackupManager($config);
$diff = new DiffGenerator();

// Load action handlers
$actions = [];
$actionFiles = glob(__DIR__ . '/Actions/*Action.php');

foreach ($actionFiles as $file) {
    $className = 'VpsAdmin\\Agent\\Actions\\' . basename($file, '.php');
    if (class_exists($className)) {
        $action = new $className($config, $backup, $diff, $logger);
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
        chgrp($socketPath, (int)$group);
    } else {
        $groupInfo = posix_getgrnam($group);
        if ($groupInfo) {
            chgrp($socketPath, $groupInfo['gid']);
        }
    }
}

if (!socket_listen($socket, 64)) {
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

pcntl_signal(SIGCHLD, SIG_DFL);

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
    
    // Reap finished child processes (heavy actions that were forked)
    while (pcntl_waitpid(-1, $childStatus, WNOHANG) > 0) {}
    
    // Accept connection with timeout
    $read = [$socket];
    $write = null;
    $except = null;
    
    $result = @socket_select($read, $write, $except, 1);
    
    if ($result === false) {
        if (socket_last_error($socket) === SOCKET_EINTR) {
            continue;
        }
        $logger->error('Socket select error', ['error' => socket_strerror(socket_last_error($socket))]);
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
    
    // Verify auth token if required
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
    
    // Heavy actions are forked so the main loop stays responsive for quick queries.
    // Site provisioning + teardown now run via the saga worker daemon
    // (panel/agent/worker-daemon.php), so vhost.create / vhost.delete
    // no longer appear here.
    $heavyActions = ['ssl.issue', 'app.install', 'mailsec.install', 'mailsec.setupResolver'];
    $fullAction = $data['action'];
    
    if (in_array($fullAction, $heavyActions)) {
        // Fork a child to handle the long-running action
        $pid = pcntl_fork();
        
        if ($pid === -1) {
            $logger->error('Failed to fork for heavy action', ['action' => $fullAction]);
            $response = json_encode(['success' => false, 'error' => 'Server busy, please try again']);
            safeSocketWrite($client, $response . "\n");
            socket_close($client);
            continue;
        }
        
        if ($pid > 0) {
            // Parent: close client socket (child owns it), reap zombies, continue loop
            socket_close($client);
            // Non-blocking reap of any finished children
            while (pcntl_waitpid(-1, $childStatus, WNOHANG) > 0) {}
            continue;
        }
        
        // Child process: handle the long-running request
        // Do NOT close $socket here -- PHP resource sharing after fork means
        // socket_close() would close the underlying fd for both processes.
        // Instead, just let it go out of scope when the child exits via posix_exit().
        
        try {
            $result = $actions[$namespace]->execute($method, $params, $actor);
            $response = json_encode($result);
        } catch (\Throwable $e) {
            $logger->error('Action execution failed', [
                'action' => $fullAction,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $response = json_encode([
                'success' => false,
                'error' => 'Internal error: ' . $e->getMessage(),
            ]);
        }
        
        safeSocketWrite($client, $response . "\n");
        @socket_close($client);
        // Use posix_exit to avoid PHP shutdown handlers that could interfere
        // with the parent's resources (socket file, log handles, DB connections)
        posix_exit(0);
    }
    
    // Light actions run inline (no fork overhead)
    try {
        $result = $actions[$namespace]->execute($method, $params, $actor);
        $response = json_encode($result);
    } catch (\Throwable $e) {
        $logger->error('Action execution failed', [
            'action' => $fullAction,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        $response = json_encode([
            'success' => false,
            'error' => 'Internal error: ' . $e->getMessage(),
        ]);
    }
    
    safeSocketWrite($client, $response . "\n");
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
    
    // Token is stored in a secure file
    $tokenFile = $config['paths']['base'] . '/var/agent.token';
    
    if (!file_exists($tokenFile)) {
        return false;
    }
    
    $validToken = trim(file_get_contents($tokenFile));
    
    return hash_equals($validToken, $token);
}

