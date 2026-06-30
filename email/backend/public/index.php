<?php

declare(strict_types=1);

// =============================================================================
// FAST PATH: Serve mood board uploads/thumbs without full app bootstrap.
// These files use unguessable hash filenames and don't require auth, so we can
// serve them directly. This prevents PHP worker exhaustion when a mood board
// with many images loads simultaneously (avoids 503 errors).
// =============================================================================
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$uriPath = parse_url($requestUri, PHP_URL_PATH);

// Match: /api/mood-boards/{id}/uploads/thumbs/{filename}
// Match: /api/mood-boards/{id}/uploads/{filename}
if ($uriPath && preg_match('#^/api/mood-boards/(\d+)/uploads/(?:(thumbs)/)?([a-f0-9]+\.\w{3,4})$#', $uriPath, $m)) {
    $boardId = $m[1];
    $isThumb = !empty($m[2]);
    $filename = $m[3];
    
    // Sanitize - only allow safe filenames (hex hash + extension)
    if (!preg_match('/^[a-f0-9]{20,64}\.(png|jpg|jpeg|gif|webp|svg|mp4|mov|pdf)$/i', $filename)) {
        http_response_code(400);
        exit;
    }
    
    $storagePath = __DIR__ . '/../storage/mood-uploads/' . $boardId;
    $filePath = $isThumb
        ? $storagePath . '/thumbs/' . $filename
        : $storagePath . '/' . $filename;
    
    if (file_exists($filePath)) {
        $fileSize = filesize($filePath);
        $etagSeed = ($isThumb ? 'thumb/' : '') . $boardId . '/' . $filename;
        $etag = '"' . md5($etagSeed . '-' . $fileSize . '-' . filemtime($filePath)) . '"';
        
        // 304 Not Modified
        $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($clientEtag === $etag) {
            http_response_code(304);
            header('Cache-Control: public, max-age=31536000, immutable');
            header('ETag: ' . $etag);
            exit;
        }
        
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        header('Vary: Accept-Encoding');
        header('X-Content-Type-Options: nosniff');
        if (stripos($mimeType, 'svg') !== false || stripos($mimeType, 'html') !== false || stripos($mimeType, 'xml') !== false) {
            // Neutralize stored-XSS via SVG/HTML/XML payloads when opened directly
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
        }
        set_time_limit(15);
        $handle = @fopen($filePath, 'rb');
        if ($handle) {
            while (!feof($handle)) {
                echo fread($handle, 65536);
                if (connection_aborted()) {
                    break;
                }
            }
            fclose($handle);
        } else {
            readfile($filePath);
        }
        exit;
    }
    // If file doesn't exist, fall through to the full app (might be Drive-stored)
}

// Load .env file if it exists (sets environment variables for config.php)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // Strip surrounding quotes
        if ((strlen($value) > 1) && ($value[0] === '"' || $value[0] === "'") && $value[0] === $value[strlen($value) - 1]) {
            $value = substr($value, 1, -1);
        }
        if (!getenv($key)) {
            putenv("$key=$value");
        }
    }
}

// CORS - restrict to known origins instead of wildcard.
// Each server runs on its own domain (email.<domain>). The allowed web origin
// is this deployment's own origin: taken from FRONTEND_URL if set, otherwise
// derived from the request host so it works with zero env config. Native app
// shells (Capacitor) present as localhost / capacitor://localhost.
$frontendUrl = rtrim((string) (getenv('FRONTEND_URL') ?: ''), '/');
if ($frontendUrl === '') {
    $reqHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($reqHost !== '') {
        $reqScheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)) ? 'https' : 'http';
        $frontendUrl = $reqScheme . '://' . $reqHost;
    }
}
$allowedOrigins = [];
if ($frontendUrl !== '') {
    $allowedOrigins[] = $frontendUrl;
}
foreach (explode(',', (string) (getenv('APP_ALLOWED_ORIGINS') ?: '')) as $extraOrigin) {
    $extraOrigin = rtrim(trim($extraOrigin), '/');
    if ($extraOrigin !== '') {
        $allowedOrigins[] = $extraOrigin;
    }
}
// Default Capacitor iOS custom-scheme origin (Android + iOS https scheme present
// as https://localhost, covered by the regex below).
$allowedOrigins[] = 'capacitor://localhost';
// Keep the canonical deployment working if no env override is set.
if ($frontendUrl === '') {
    $allowedOrigins[] = 'https://flowone.pro';
}
$allowedOrigins = array_values(array_unique($allowedOrigins));

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$isAllowed = in_array($origin, $allowedOrigins, true);
// Native WebView shells and local dev servers all present as localhost.
if (!$isAllowed && $origin !== '' && preg_match('#^https?://localhost(:\d+)?$#', $origin)) {
    $isAllowed = true;
}
if ($isAllowed) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
} elseif ($origin === '') {
    // No origin header = same-origin request or non-browser client. Echo this
    // deployment's own frontend (credentialed CORS forbids a wildcard).
    header('Access-Control-Allow-Origin: ' . ($frontendUrl !== '' ? $frontendUrl : 'https://flowone.pro'));
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Session-Token, X-Device-Id, X-Account-Id, X-Portal-Token');
header('Access-Control-Max-Age: 86400');

// Security headers
header('X-Content-Type-Options: nosniff');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Generate a CSP nonce for inline styles (avoids unsafe-inline)
$cspNonce = base64_encode(random_bytes(16));
define('CSP_NONCE', $cspNonce);

// The news article proxy (/api/news/proxy) MUST be embeddable in our own
// reader iframe, so it sets its own X-Frame-Options/CSP downstream. For
// every other endpoint we lock framing down to nothing.
$requestUriForFraming = $_SERVER['REQUEST_URI'] ?? '';
$isNewsProxyRequest = (bool) preg_match('#^/(api/)?news/proxy(\?|$|/)#', (string) $requestUriForFraming);
if (!$isNewsProxyRequest) {
    // Allow the deployment's own WebSocket origin (wss://email.<domain>) in
    // connect-src. Derived from FRONTEND_URL so each server is self-consistent.
    $wsConnectSrc = $frontendUrl !== ''
        ? preg_replace('#^http#i', 'ws', $frontendUrl)
        : 'wss://flowone.pro';
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'nonce-{$cspNonce}'; font-src 'self'; img-src 'self' data: blob:; connect-src 'self' {$wsConnectSrc}; frame-ancestors 'none'; report-uri /api/csp-report");
}

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Autoload
require_once __DIR__ . '/../vendor/autoload.php';

use Webmail\Core\Router;
use Webmail\Core\Request;
use Webmail\Core\Response;

// Register log redaction to prevent credential leakage in logs
\Webmail\Helpers\LogRedactor::register();

// Error handling - only convert fatal errors to exceptions, ignore notices
set_error_handler(function ($severity, $message, $file, $line) {
    // Ignore notices, warnings, and deprecation notices (especially IMAP security notices)
    if ($severity === E_NOTICE || $severity === E_WARNING || $severity === E_USER_NOTICE || $severity === E_USER_WARNING || $severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        return true;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e) {
    $logFile = __DIR__ . '/../logs/php_errors.log';
    error_log("Uncaught exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n", 3, $logFile);
    error_log("Stack trace: " . $e->getTraceAsString() . "\n", 3, $logFile);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    // Never expose error details to clients — log them server-side only
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
    ]);
});

// Load configuration. config.php now performs the config.local.php
// override merge internally so every consumer (web, cron, tests,
// scripts) sees the exact same effective config. Do NOT re-merge here.
$config = require __DIR__ . '/../src/config.php';

// Initialize centralized audit logger (sends events to Panel)
\Webmail\Services\AuditLogger::init($config);

// General API rate limiting (per-IP, sliding window via Redis)
try {
    $rateLimiter = new \Webmail\Middleware\ApiRateLimiter($config);
    $rateLimitResult = $rateLimiter->check($_SERVER['REQUEST_URI'] ?? '/');
    if ($rateLimitResult) {
        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: ' . $rateLimitResult['retry_after']);
        echo json_encode([
            'success' => false,
            'message' => 'Too many requests. Please slow down.',
            'retry_after' => $rateLimitResult['retry_after'],
        ]);
        exit;
    }
} catch (\Throwable $e) {
    // Fail-closed: if rate limiter infrastructure fails, reject requests
    error_log('API rate limiter error: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: application/json');
    header('Retry-After: 30');
    echo json_encode([
        'success' => false,
        'message' => 'Service temporarily unavailable. Please try again.',
    ]);
    exit;
}

// NAS availability pre-check — warms the per-request cache so all downstream
// service guards (DriveService, StorageService, etc.) skip NAS paths instantly
// without each one paying the filesystem probe cost.
\Webmail\Services\NasHealthCheck::isAvailable();

// Run pending database migrations (auto-runs on first deployment)
try {
    $migrationService = new \Webmail\Services\MigrationService($config);
    $migrationService->runPendingMigrations();
} catch (\Throwable $e) {
    // Log migration errors but don't break the app
    error_log("Migration error: " . $e->getMessage());
}

// Fail-fast canary check for OAuth encryption configuration
try {
    \Webmail\Services\OAuthCryptor::canaryCheck($config);
} catch (\Throwable $e) {
    error_log('FATAL: OAuth encryption canary failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Server misconfiguration (OAuth encryption). Contact admin.',
    ]);
    exit;
}

// Create router and register routes
$router = new Router();
$registerRoutes = require __DIR__ . '/../routes.php';
$registerRoutes($router, $config);

// Handle request
$request = new Request();
$response = $router->dispatch($request);

// Only send response if it's a valid Response object
// Void handlers (like binary downloads) use exit() directly and return null
if ($response instanceof Response) {
    $response->send();
}

// At this point the browser has the full response and the TCP connection
// is closed (Response::send() calls fastcgi_finish_request() on PHP-FPM /
// LSAPI). Everything below runs in the freed worker process without the
// client waiting on it.

// Drain controller-deferred side-effects (Meilisearch index updates,
// mention parsing, client-activity bumps, etc.) AFTER send() so they
// never block the user-visible click latency. Each callable is run
// inside its own try/catch — see Webmail\Core\Deferred.
\Webmail\Core\Deferred::flush();

// Explicitly flush audit logs after response is sent
// (LSAPI/OpenLiteSpeed may not honor register_shutdown_function for outbound HTTP calls)
$auditLogger = \Webmail\Services\AuditLogger::getInstance();
if ($auditLogger) {
    $auditLogger->flush();
}

