<?php
/**
 * phpMyAdmin Access Gate
 * 
 * This script validates access tokens before allowing phpMyAdmin access.
 * It should be deployed as: /var/www/vps-admin/phpmyadmin/gate.php
 * 
 * Flow:
 * 1. User clicks phpMyAdmin link in panel
 * 2. Panel API generates a signed token
 * 3. User is redirected to gate.php?token=xxx
 * 4. This script validates the token
 * 5. If valid, redirects to phpMyAdmin with the database pre-selected
 * 6. If invalid, shows 403 error
 */

// Prevent direct access to phpMyAdmin files
define('PHPMYADMIN_GATE', true);

// Configuration - MUST match the API's JWT secret
// Copy this file to /var/www/vps-admin/phpmyadmin/gate.php
// Then update the secret below with your actual JWT secret from config.local.php
$config = [
    'jwt_secret' => '', // SET THIS! Copy from /var/www/vps-admin/api/config.local.php
    'token_suffix' => '_pma',
    'phpmyadmin_path' => '/phpmyadmin/index.php',
];

// Load config from panel if available
$configLocalPath = __DIR__ . '/../api/config.local.php';
if (file_exists($configLocalPath)) {
    $localConfig = require $configLocalPath;
    if (isset($localConfig['jwt']['secret'])) {
        $config['jwt_secret'] = $localConfig['jwt']['secret'];
    }
}

// Check if secret is configured
if (empty($config['jwt_secret'])) {
    http_response_code(500);
    die('Gate configuration error: JWT secret not set');
}

/**
 * Validate the access token
 */
function validateToken(string $token, string $secret): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }
    
    [$payloadBase64, $signature] = $parts;
    
    // Verify signature
    $expectedSignature = hash_hmac('sha256', $payloadBase64, $secret);
    if (!hash_equals($expectedSignature, $signature)) {
        return null;
    }
    
    // Decode payload
    $payloadJson = base64_decode(strtr($payloadBase64, '-_', '+/'));
    $payload = json_decode($payloadJson, true);
    
    if (!$payload) {
        return null;
    }
    
    // Check expiry
    if (!isset($payload['exp']) || $payload['exp'] < time()) {
        return null;
    }
    
    // Check required fields
    if (!isset($payload['uid']) || !isset($payload['db'])) {
        return null;
    }
    
    return $payload;
}

/**
 * Show access denied page
 */
function showAccessDenied(string $reason = 'Invalid or expired token')
{
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Access Denied - phpMyAdmin</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #e4e4e7;
            }
            .container {
                text-align: center;
                padding: 3rem;
                background: rgba(255,255,255,0.05);
                border-radius: 1rem;
                border: 1px solid rgba(255,255,255,0.1);
                max-width: 500px;
            }
            .icon {
                font-size: 4rem;
                margin-bottom: 1rem;
            }
            h1 {
                font-size: 1.5rem;
                margin-bottom: 0.5rem;
                color: #ef4444;
            }
            p {
                color: #a1a1aa;
                margin-bottom: 1.5rem;
            }
            .reason {
                font-size: 0.875rem;
                color: #71717a;
                padding: 0.75rem;
                background: rgba(0,0,0,0.3);
                border-radius: 0.5rem;
                margin-bottom: 1.5rem;
            }
            a {
                display: inline-block;
                padding: 0.75rem 1.5rem;
                background: #10b981;
                color: white;
                text-decoration: none;
                border-radius: 9999px;
                font-weight: 500;
                transition: background 0.2s;
            }
            a:hover { background: #059669; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="icon">&#128274;</div>
            <h1>Access Denied</h1>
            <p>You cannot access phpMyAdmin directly.</p>
            <div class="reason"><?= htmlspecialchars($reason) ?></div>
            <a href="https://panel.devcon1.hu">Go to Panel</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Main logic
$token = $_GET['token'] ?? '';

if (empty($token)) {
    showAccessDenied('No access token provided. Please access phpMyAdmin through the panel.');
}

// Validate token
$signingSecret = $config['jwt_secret'] . $config['token_suffix'];
$payload = validateToken($token, $signingSecret);

if (!$payload) {
    showAccessDenied('Invalid or expired access token. Please try again from the panel.');
}

// Token is valid - redirect to phpMyAdmin with database selected
$database = $payload['db'];
$redirectUrl = $config['phpmyadmin_path'] . '?db=' . urlencode($database);

// Log successful access (optional)
error_log(sprintf(
    'phpMyAdmin access granted: user=%d, db=%s, ip=%s',
    $payload['uid'],
    $database,
    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
));

// Redirect to phpMyAdmin
header('Location: ' . $redirectUrl);
exit;

