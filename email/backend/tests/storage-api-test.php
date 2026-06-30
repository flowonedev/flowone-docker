#!/usr/bin/env php
<?php
/**
 * Phase 8 — Storage signals API test.
 *
 * Exercises StorageController in isolation:
 *   1. controller class is loadable
 *   2. route file references the controller
 *   3. unauth callers are 401'd
 *   4. /storage/status returns a structurally valid payload
 *   5. /storage/files/{id}/tier validates id and ownership
 *   6. /admin/storage/dashboard is gated by is_admin
 *
 * Runs in two modes:
 *   --smoke    no DB touch; just lint+structure
 *   default    requires --email to test live endpoints against DB
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/storage-api-test.php \
 *       --email=admin@flowone.pro --verbose
 *
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/storage-api-test.php --smoke
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', ['email::', 'only:', 'smoke', 'verbose', 'skip-send', 'json', 'help']);

if (isset($opts['help'])) {
    echo "FlowOne Storage API Test Suite\n";
    echo "==============================\n\n";
    echo "Usage:\n";
    echo "  php storage-api-test.php [--email=EMAIL] [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL    Test account (required unless --smoke)\n";
    echo "  --only=GROUPS    Comma-separated: lint,structure,unauth,status,tier,admin\n";
    echo "  --smoke          Quick lint + structure checks only (no DB)\n";
    echo "  --skip-send      Compat flag; no-op (no destructive ops here)\n";
    echo "  --json           Output results as JSON\n";
    echo "  --verbose        Show extra debug info\n";
    echo "  --help           Show this help\n";
    exit(0);
}

$verbose   = isset($opts['verbose']);
$smokeOnly = isset($opts['smoke']);
$jsonOut   = isset($opts['json']);
$onlyGroups= isset($opts['only']) ? explode(',', $opts['only']) : [];
$testEmail = $opts['email'] ?? null;

if (!$smokeOnly && !$testEmail) {
    fwrite(STDERR, "ERROR: --email=EMAIL required (or pass --smoke for a no-DB run).\n");
    exit(1);
}

function shouldRun(string $group): bool {
    global $onlyGroups, $smokeOnly;
    if ($smokeOnly) return in_array($group, ['lint', 'structure'], true);
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups, true);
}

// ── Logging ─────────────────────────────────────────────────────
$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$logFile = $logDir . '/storage-api-test-' . date('Ymd-His') . '.log';

$totalTests = 0; $passed = 0; $failed = 0; $warnings = 0;
$results = [];

function out(string $msg): void {
    global $logFile, $jsonOut;
    if ($jsonOut) {
        @file_put_contents($logFile, date('[H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
        return;
    }
    echo $msg . "\n";
    @file_put_contents($logFile, date('[H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function test(string $name, callable $fn): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose;
    $totalTests++;
    $start = microtime(true);
    try {
        $rc = $fn();
        $elapsed = (int) round((microtime(true) - $start) * 1000);
        if ($rc === 'warn') {
            $warnings++;
            out("  \033[33m[WARN]\033[0m  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'WARN', 'ms' => $elapsed];
        } else {
            $passed++;
            out("  \033[32m[PASS]\033[0m  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'PASS', 'ms' => $elapsed];
        }
    } catch (\Throwable $e) {
        $elapsed = (int) round((microtime(true) - $start) * 1000);
        $failed++;
        out("  \033[31m[FAIL]\033[0m  {$name} ({$elapsed}ms)");
        out("          -> " . $e->getMessage());
        if ($verbose) {
            out("          at " . $e->getFile() . ':' . $e->getLine());
        }
        $results[] = ['name' => $name, 'status' => 'FAIL', 'ms' => $elapsed, 'error' => $e->getMessage()];
    }
}

function assert_true(bool $c, string $m = 'assertion failed'): void {
    if (!$c) throw new \RuntimeException($m);
}
function assert_equals($e, $a, string $m = ''): void {
    if ($e !== $a) throw new \RuntimeException(($m ?: 'mismatch') . ': expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}
function assert_array_has(array $a, string $k, string $m = ''): void {
    if (!array_key_exists($k, $a)) throw new \RuntimeException(($m ?: "missing key") . ": $k");
}
function vlog(string $msg): void {
    global $verbose;
    if ($verbose) out("          [v] $msg");
}

// ── Pre-flight ──────────────────────────────────────────────────
out("=== PRE-FLIGHT ===");
$controllerPath = __DIR__ . '/../src/Controllers/StorageController.php';
$routesPath     = __DIR__ . '/../routes.php';
assert_true(is_file($controllerPath), "StorageController.php missing: $controllerPath");
assert_true(is_file($routesPath), "routes.php missing");
out("  + controller: $controllerPath");
out("  + routes:     $routesPath");

if (!$smokeOnly) {
    out("  + email:      $testEmail");
}

// ── Tests ──────────────────────────────────────────────────────
out("\n=== TESTS ===\n");

// 1. LINT
if (shouldRun('lint')) {
    out("--- lint ---");
    test('StorageController syntax is valid', function () use ($controllerPath) {
        $out = []; $rc = 0;
        @exec('php -l ' . escapeshellarg($controllerPath) . ' 2>&1', $out, $rc);
        if ($rc !== 0) throw new \RuntimeException('php -l failed: ' . implode("\n", $out));
    });
    test('routes.php syntax is valid', function () use ($routesPath) {
        $out = []; $rc = 0;
        @exec('php -l ' . escapeshellarg($routesPath) . ' 2>&1', $out, $rc);
        if ($rc !== 0) throw new \RuntimeException('php -l failed: ' . implode("\n", $out));
    });
}

// 2. STRUCTURE
if (shouldRun('structure')) {
    out("\n--- structure ---");
    test('StorageController class is loadable', function () {
        assert_true(class_exists(\Webmail\Controllers\StorageController::class),
            'class not autoloadable');
    });
    test('StorageController has status/fileTier/adminDashboard methods', function () {
        $rc = new \ReflectionClass(\Webmail\Controllers\StorageController::class);
        assert_true($rc->hasMethod('status'),         'status missing');
        assert_true($rc->hasMethod('fileTier'),       'fileTier missing');
        assert_true($rc->hasMethod('adminDashboard'), 'adminDashboard missing');
    });
    test('routes.php references StorageController and registers 3 routes', function () use ($routesPath) {
        $body = file_get_contents($routesPath);
        assert_true(str_contains($body, 'use Webmail\Controllers\StorageController;'),
            'StorageController not imported in routes.php');
        assert_true(str_contains($body, "/storage/status"),       'status route missing');
        assert_true(str_contains($body, "/storage/files/{id}/tier"), 'tier route missing');
        assert_true(str_contains($body, "/admin/storage/dashboard"), 'admin route missing');
    });
    test('controller extends BaseController', function () {
        $rc = new \ReflectionClass(\Webmail\Controllers\StorageController::class);
        $parent = $rc->getParentClass();
        assert_true($parent && $parent->getName() === \Webmail\Controllers\BaseController::class,
            'must extend BaseController');
    });
}

// Helper: build a Request with route params (since the constructor
// takes no args and reads from $_SERVER, we use setParam after).
function makeRequest(array $routeParams = [], string $method = 'GET', string $path = '/'): \Webmail\Core\Request {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI']    = $path;
    $_GET = [];
    $req = new \Webmail\Core\Request();
    foreach ($routeParams as $k => $v) {
        $req->setParam($k, (string) $v);
    }
    return $req;
}

// 3. UNAUTH
if (!$smokeOnly && shouldRun('unauth')) {
    out("\n--- unauth (401) ---");
    test('status() returns 401 without auth header', function () use ($config) {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $controller = new \Webmail\Controllers\StorageController($config);
        $resp = $controller->status(makeRequest([], 'GET', '/storage/status'));
        $payload = json_decode($resp->getContent(), true);
        assert_equals(401, $resp->getStatusCode(), 'expected 401');
        assert_true(!empty($payload['error'] ?? $payload['message'] ?? ''), 'should have error msg');
    });
    test('fileTier() returns 401 without auth', function () use ($config) {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $controller = new \Webmail\Controllers\StorageController($config);
        $resp = $controller->fileTier(makeRequest(['id' => 1], 'GET', '/storage/files/1/tier'));
        assert_equals(401, $resp->getStatusCode());
    });
    test('adminDashboard() returns 401 without auth', function () use ($config) {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $controller = new \Webmail\Controllers\StorageController($config);
        $resp = $controller->adminDashboard(makeRequest([], 'GET', '/admin/storage/dashboard'));
        assert_equals(401, $resp->getStatusCode());
    });
}

// Helper: forge auth header for a given email so requireAuth() accepts it.
// SessionService validates via JWT — we sign with the exact same instance
// the controller will use.
function forgeAuthFor(array $config, string $email): void {
    $jwtCfg = $config['jwt'] ?? [];
    $imapKey = $config['imap_encryption_key'] ?? '';
    $session = new \Webmail\Services\SessionService($jwtCfg, $imapKey);
    $token = $session->createToken($email);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
}

// 4. STATUS endpoint (authed)
if (!$smokeOnly && shouldRun('status')) {
    out("\n--- status (authed) ---");
    test('status() returns structurally valid payload', function () use ($config, $testEmail) {
        forgeAuthFor($config, $testEmail);
        $controller = new \Webmail\Controllers\StorageController($config);
        $resp = $controller->status(makeRequest([], 'GET', '/storage/status'));
        assert_equals(200, $resp->getStatusCode());
        $body = json_decode($resp->getContent(), true);
        // Response wraps payload under "data" via Response::success
        $payload = $body['data'] ?? $body;
        assert_array_has($payload, 'available', 'status payload missing `available`');
        if ($payload['available']) {
            assert_array_has($payload, 'budget',  'missing budget');
            assert_array_has($payload, 'reclaim', 'missing reclaim');
            assert_array_has($payload, 'backup',  'missing backup');
            vlog('watermark=' . ($payload['budget']['watermark'] ?? 'null'));
        } else {
            vlog('storage library unavailable: ' . ($payload['reason'] ?? '?'));
        }
    });
}

// 5. TIER endpoint (authed)
if (!$smokeOnly && shouldRun('tier')) {
    out("\n--- file tier (authed) ---");
    test('fileTier() rejects id=0 with 400', function () use ($config, $testEmail) {
        forgeAuthFor($config, $testEmail);
        $controller = new \Webmail\Controllers\StorageController($config);
        $resp = $controller->fileTier(makeRequest(['id' => 0], 'GET', '/storage/files/0/tier'));
        assert_equals(400, $resp->getStatusCode());
    });
    test('fileTier() returns 404 for non-existent id', function () use ($config, $testEmail) {
        forgeAuthFor($config, $testEmail);
        $controller = new \Webmail\Controllers\StorageController($config);
        $resp = $controller->fileTier(makeRequest(['id' => 999999999], 'GET', '/storage/files/999999999/tier'));
        assert_equals(404, $resp->getStatusCode());
    });
    test('fileTier() returns tier_state for an owned file', function () use ($config, $testEmail) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT id, tier_state FROM drive_files WHERE user_email = ? LIMIT 1');
        $stmt->execute([$testEmail]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            vlog('no drive_files row for ' . $testEmail . ' — skipping ownership round-trip');
            return 'warn';
        }
        forgeAuthFor($config, $testEmail);
        $controller = new \Webmail\Controllers\StorageController($config);
        $resp = $controller->fileTier(makeRequest(['id' => $row['id']], 'GET', '/storage/files/' . $row['id'] . '/tier'));
        assert_equals(200, $resp->getStatusCode());
        $body = json_decode($resp->getContent(), true);
        $payload = $body['data'] ?? $body;
        assert_equals((int)$row['id'], (int)$payload['id'], 'id round-trip mismatch');
        assert_array_has($payload, 'tier_state');
        $valid = ['hot', 'tiering', 'cold', 'recalling', 'lost'];
        assert_true(in_array($payload['tier_state'], $valid, true),
            'invalid tier_state: ' . $payload['tier_state']);
    });
}

// 6. ADMIN endpoint
if (!$smokeOnly && shouldRun('admin')) {
    out("\n--- admin dashboard ---");
    test('adminDashboard() is 403 for non-admin', function () use ($config) {
        $fakeEmail = 'definitely-not-an-admin-' . bin2hex(random_bytes(4)) . '@flowone.pro';
        forgeAuthFor($config, $fakeEmail);
        $controller = new \Webmail\Controllers\StorageController($config);
        $resp = $controller->adminDashboard(makeRequest([], 'GET', '/admin/storage/dashboard'));
        assert_equals(403, $resp->getStatusCode(), 'expected 403 admin only');
    });
    test('adminDashboard() returns full payload for admin (if testEmail is admin)', function () use ($config, $testEmail) {
        try {
            $svc = new \Webmail\Addons\Team\Services\ColleagueService($config);
            $isAdmin = $svc->isAdmin($testEmail);
        } catch (\Throwable $e) {
            vlog('ColleagueService unavailable: ' . $e->getMessage());
            return 'warn';
        }
        if (!$isAdmin) {
            vlog($testEmail . ' is not an admin — skipping admin payload assertions');
            return 'warn';
        }
        forgeAuthFor($config, $testEmail);
        $controller = new \Webmail\Controllers\StorageController($config);
        $resp = $controller->adminDashboard(makeRequest([], 'GET', '/admin/storage/dashboard'));
        assert_equals(200, $resp->getStatusCode());
        $body = json_decode($resp->getContent(), true);
        $payload = $body['data'] ?? $body;
        assert_array_has($payload, 'available');
        if ($payload['available']) {
            assert_array_has($payload, 'budget');
            assert_array_has($payload, 'reclaim');
            assert_array_has($payload, 'backup');
            assert_array_has($payload, 'tier_counts');
            assert_array_has($payload, 'phase_flags');
            assert_array_has($payload, 'paths');
            vlog('tier_counts: ' . count($payload['tier_counts']) . ' buckets');
        } else {
            vlog('storage library unavailable: ' . ($payload['reason'] ?? '?'));
        }
    });
}

// ── Summary ─────────────────────────────────────────────────────
out("\n=== SUMMARY ===");
out("Passed:   $passed");
out("Failed:   $failed");
out("Warnings: $warnings");

if ($failed > 0) {
    out("\nFailed tests:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out("  - {$r['name']}: " . ($r['error'] ?? ''));
        }
    }
}

if ($jsonOut) {
    echo json_encode([
        'passed'   => $passed,
        'failed'   => $failed,
        'warnings' => $warnings,
        'results'  => $results,
    ], JSON_PRETTY_PRINT) . "\n";
}

exit($failed === 0 ? 0 : 1);
