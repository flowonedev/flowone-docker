#!/usr/bin/env php
<?php
/**
 * Agent Connection Test Script
 * 
 * Run this on your VPS to test agent connectivity
 * Usage: php test-agent.php
 */

echo "\n===========================================\n";
echo "  VPS Admin - Agent Connection Test\n";
echo "===========================================\n\n";

$config = [
    'socket' => '/run/vps-admin/agent.sock',
    'token_file' => '/var/www/vps-admin/var/agent.token',
];

// Test 1: Check if socket file exists
echo "[1/5] Checking socket file...\n";
if (file_exists($config['socket'])) {
    $perms = fileperms($config['socket']);
    $owner = posix_getpwuid(fileowner($config['socket']));
    $group = posix_getgrgid(filegroup($config['socket']));
    
    echo "  ✓ Socket exists: {$config['socket']}\n";
    echo "    Owner: {$owner['name']}\n";
    echo "    Group: {$group['name']}\n";
    echo "    Permissions: " . substr(sprintf('%o', $perms), -4) . "\n";
} else {
    echo "  ✗ Socket NOT found: {$config['socket']}\n";
    echo "    → Agent is not running!\n";
    echo "    → Run: systemctl start vpsadmin-agent\n\n";
    exit(1);
}

// Test 2: Check if token file exists
echo "\n[2/5] Checking token file...\n";
if (file_exists($config['token_file'])) {
    $token = trim(file_get_contents($config['token_file']));
    echo "  ✓ Token file exists\n";
    echo "    Length: " . strlen($token) . " characters\n";
} else {
    echo "  ✗ Token file NOT found: {$config['token_file']}\n";
    echo "    → Generate with: openssl rand -hex 32 > {$config['token_file']}\n\n";
    exit(1);
}

// Test 3: Check PHP extensions
echo "\n[3/5] Checking PHP extensions...\n";
$required = ['sockets', 'pcntl', 'posix', 'json', 'pdo_mysql'];
$missing = [];
foreach ($required as $ext) {
    if (extension_loaded($ext)) {
        echo "  ✓ {$ext}\n";
    } else {
        echo "  ✗ {$ext} (missing)\n";
        $missing[] = $ext;
    }
}

if (!empty($missing)) {
    echo "\n  → Install missing extensions\n";
    echo "    → On Ubuntu/Debian: apt install php-" . implode(' php-', $missing) . "\n\n";
    exit(1);
}

// Test 4: Connect to socket
echo "\n[4/5] Testing socket connection...\n";
$socket = @socket_create(AF_UNIX, SOCK_STREAM, 0);
if ($socket === false) {
    echo "  ✗ Failed to create socket: " . socket_strerror(socket_last_error()) . "\n\n";
    exit(1);
}

socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);
socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 5, 'usec' => 0]);

if (!@socket_connect($socket, $config['socket'])) {
    $error = socket_strerror(socket_last_error($socket));
    echo "  ✗ Failed to connect: {$error}\n";
    echo "    → Check if agent is running: systemctl status vpsadmin-agent\n\n";
    socket_close($socket);
    exit(1);
}

echo "  ✓ Connected to socket\n";

// Test 5: Send test request
echo "\n[5/5] Testing agent communication...\n";
$request = [
    'action' => 'service.list',
    'params' => [],
    'actor' => 'test-script',
    'token' => $token,
];

$data = json_encode($request) . "\n\n";

if (@socket_write($socket, $data) === false) {
    echo "  ✗ Failed to send request: " . socket_strerror(socket_last_error($socket)) . "\n\n";
    socket_close($socket);
    exit(1);
}

echo "  ✓ Request sent\n";

// Read response
$response = '';
while (true) {
    $chunk = @socket_read($socket, 8192);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $response .= $chunk;
    if (strpos($response, "\n") !== false) {
        break;
    }
}

socket_close($socket);

if (empty($response)) {
    echo "  ✗ No response from agent\n\n";
    exit(1);
}

$result = json_decode(trim($response), true);

if ($result === null) {
    echo "  ✗ Invalid JSON response\n";
    echo "    Response: " . substr($response, 0, 200) . "\n\n";
    exit(1);
}

if (isset($result['success']) && $result['success']) {
    echo "  ✓ Agent responding correctly\n";
    if (isset($result['data']['services'])) {
        echo "    Found " . count($result['data']['services']) . " services\n";
    }
} else {
    echo "  ✗ Agent returned error: " . ($result['error'] ?? 'Unknown error') . "\n\n";
    exit(1);
}

// All tests passed
echo "\n===========================================\n";
echo "  ✓ ALL TESTS PASSED\n";
echo "===========================================\n\n";
echo "Agent is running and accessible!\n";
echo "Your API should now work correctly.\n\n";

exit(0);

