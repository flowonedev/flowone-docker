#!/usr/bin/env php
<?php
/**
 * FlowOne Drive — Performance Baseline Test
 *
 * Metrics M.4 of drive-perf-fix-v2.
 *
 * Asserts that the hot endpoints used by the desktop sync engine respond
 * fast enough to keep the Electron main thread out of timeout territory.
 * If the server starts hanging, this test fires before users notice the
 * Drive UI freezing.
 *
 * Endpoints exercised:
 *   /api/drive/list                     <-- main sync fetch
 *   /api/drive/folders/all              <-- folder structure pull
 *   /api/clients/folder-mapping         <-- time tracker mapping refresh
 *   /api/boards/url-mappings            <-- URL tracker refresh
 *
 * Each endpoint must respond in <1000 ms p95 over the configured number
 * of attempts.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-perf-baseline-test.php \
 *       --email=user@flowone.pro --password=PASS --verbose
 *
 * Options:
 *   --email=EMAIL        Test account email (required)
 *   --password=PASS      Test account password (required)
 *   --attempts=10        How many times to call each endpoint (default 10)
 *   --p95-budget-ms=1000 Max acceptable p95 in ms (default 1000)
 *   --only=group,group   Run only specific groups (drive,clients,boards)
 *   --smoke              Quick health check only (1 call per endpoint)
 *   --verbose            Stack traces + raw responses
 *   --json               Emit machine-readable JSON summary
 *   --help               Show this help
 *
 * Safety:
 *   - Read-only test. No data is created, modified, or deleted on the
 *     server.
 *   - All test artefacts (log file) use the FLOWONE-TEST prefix.
 *   - SIGINT / SIGTERM handlers ensure log file is flushed even on abort.
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', [
    'email:',
    'password:',
    'attempts::',
    'p95-budget-ms::',
    'only:',
    'smoke',
    'verbose',
    'json',
    'help',
]);

if (isset($opts['help']) || empty($opts['email']) || empty($opts['password'])) {
    echo "FlowOne Drive — Performance Baseline Test\n";
    echo "==========================================\n\n";
    echo "Usage:\n";
    echo "  php drive-perf-baseline-test.php --email=USER --password=PASS [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL          Required test account\n";
    echo "  --password=PASS        Required test account password\n";
    echo "  --attempts=10          How many calls per endpoint (default 10)\n";
    echo "  --p95-budget-ms=1000   Max p95 latency in ms (default 1000)\n";
    echo "  --only=drive,clients,boards   Restrict to specific groups\n";
    echo "  --smoke                Quick health check (1 call per endpoint)\n";
    echo "  --verbose              Print raw HTTP details\n";
    echo "  --json                 Print JSON summary at the end\n";
    echo "  --help                 Show this help\n\n";
    echo "Example:\n";
    echo "  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-perf-baseline-test.php \\\n";
    echo "      --email=admin@flowone.pro --password='secret' --verbose\n";
    exit(1);
}

$testEmail    = strtolower($opts['email']);
$testPassword = $opts['password'];
$attempts     = isset($opts['smoke']) ? 1 : (int)($opts['attempts'] ?? 10);
$budgetMs     = (int)($opts['p95-budget-ms'] ?? 1000);
$onlyGroups   = !empty($opts['only']) ? explode(',', $opts['only']) : [];
$verbose      = isset($opts['verbose']);
$jsonOut      = isset($opts['json']);

if ($attempts < 1) $attempts = 1;
if ($attempts > 100) $attempts = 100;

$RED    = "\033[0;31m";
$GREEN  = "\033[0;32m";
$YELLOW = "\033[1;33m";
$NC     = "\033[0m";

if ($jsonOut) { $RED = $GREEN = $YELLOW = $NC = ''; }

$logFile = __DIR__ . '/../storage/logs/drive-perf-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

$totalTests = 0;
$passed     = 0;
$failed     = 0;
$warnings   = 0;
$results    = [];

function logLine(string $msg): void
{
    global $logFile;
    if (!$logFile) return;
    @file_put_contents(
        $logFile,
        date('[H:i:s] ') . strip_tags(preg_replace('/\033\[[0-9;]*m/', '', $msg . "\n")),
        FILE_APPEND | LOCK_EX
    );
}

function out(string $msg): void
{
    global $jsonOut;
    if (!$jsonOut) echo $msg . "\n";
    logLine($msg);
}

// SIGINT / SIGTERM handlers — flush logs even on abort. (server-side-testing.mdc)
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () { out("\n[FLOWONE-TEST] interrupted by signal"); exit(130); });
    pcntl_signal(SIGTERM, function () { out("\n[FLOWONE-TEST] terminated by signal"); exit(143); });
}

function shouldRun(string $group): bool
{
    global $onlyGroups;
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups);
}

/**
 * Pre-flight check — abort early if PHP / extensions / config are wrong.
 */
function preflight(): void
{
    global $config, $verbose;
    $required = ['curl', 'json', 'pdo'];
    foreach ($required as $ext) {
        if (!extension_loaded($ext)) {
            fprintf(STDERR, "[FLOWONE-TEST] missing required PHP extension: %s\n", $ext);
            exit(2);
        }
    }
    if (empty($config['app_url']) && empty($config['base_url'])) {
        fprintf(STDERR, "[FLOWONE-TEST] config missing app_url / base_url\n");
        exit(2);
    }
    if ($verbose) out("[FLOWONE-TEST] pre-flight OK");
}

/**
 * Tiny HTTP client with hard timeout. Returns ['code', 'ms', 'body'].
 */
function httpRequest(string $url, string $method, array $headers = [], ?string $body = null, int $timeoutSeconds = 30): array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeoutSeconds));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $start = microtime(true);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ms   = (int)round((microtime(true) - $start) * 1000);
    curl_close($ch);
    return [
        'code' => $code,
        'ms'   => $ms,
        'body' => $resp ?: '',
        'err'  => $err,
    ];
}

function percentile(array $values, float $p): float
{
    if (empty($values)) return 0;
    sort($values);
    $idx = (int)floor(($p / 100) * (count($values) - 1));
    return (float)$values[$idx];
}

/**
 * Authenticate against the running app to obtain a session cookie / token.
 * Read-only test; no data is mutated.
 */
function authenticate(): array
{
    global $config, $testEmail, $testPassword, $verbose;
    $base = rtrim($config['app_url'] ?? $config['base_url'], '/');
    $url  = "$base/api/auth/login";
    $resp = httpRequest($url, 'POST', [
        'Content-Type: application/json',
    ], json_encode([
        'email'    => $testEmail,
        'password' => $testPassword,
    ]), 15);

    if ($resp['code'] < 200 || $resp['code'] >= 300) {
        out("[FLOWONE-TEST] login failed code={$resp['code']} body={$resp['body']}");
        exit(1);
    }
    $data = json_decode($resp['body'], true) ?: [];
    $token = $data['token'] ?? $data['access_token'] ?? null;
    if (!$token) {
        out("[FLOWONE-TEST] login response did not include token; body={$resp['body']}");
        exit(1);
    }
    if ($verbose) out("[FLOWONE-TEST] auth OK ({$resp['ms']}ms)");
    return [
        'base'  => $base,
        'token' => $token,
    ];
}

function testEndpoint(string $name, string $url, array $headers, int $attempts, int $budgetMs): array
{
    global $passed, $failed, $totalTests, $verbose, $RED, $GREEN, $YELLOW, $NC;
    $totalTests++;

    $samples = [];
    $errors  = 0;
    for ($i = 0; $i < $attempts; $i++) {
        $resp = httpRequest($url, 'GET', $headers, null, 30);
        if ($resp['code'] !== 200) {
            $errors++;
            if ($verbose) out("  attempt #" . ($i + 1) . " code={$resp['code']} ms={$resp['ms']} err={$resp['err']}");
        }
        $samples[] = $resp['ms'];
    }
    sort($samples);
    $p50 = percentile($samples, 50);
    $p95 = percentile($samples, 95);
    $p99 = percentile($samples, 99);
    $max = end($samples);

    $ok = ($errors === 0) && ($p95 <= $budgetMs);
    if ($ok) {
        $passed++;
        $color = $GREEN;
        $tag = '[PASS]';
    } else {
        $failed++;
        $color = $RED;
        $tag = '[FAIL]';
    }
    out(sprintf(
        '%s%s %s%s n=%d errors=%d p50=%dms p95=%dms p99=%dms max=%dms (budget p95<=%dms)',
        $color, $tag, $name, $NC, count($samples), $errors, $p50, $p95, $p99, $max, $budgetMs
    ));
    return [
        'name'    => $name,
        'url'     => $url,
        'samples' => $samples,
        'p50'     => $p50,
        'p95'     => $p95,
        'p99'     => $p99,
        'max'     => $max,
        'errors'  => $errors,
        'ok'      => $ok,
        'budget'  => $budgetMs,
    ];
}

// ── Run ───────────────────────────────────────────────────────────

preflight();
out('[FLOWONE-TEST] drive-perf-baseline starting attempts=' . $attempts . ' budget_p95=' . $budgetMs . 'ms');

$auth = authenticate();
$headers = [
    'Authorization: Bearer ' . $auth['token'],
    'Accept: application/json',
];

$endpoints = [
    'drive'   => [
        ['drive.list',         "{$auth['base']}/api/drive/list"],
        ['drive.folders.all',  "{$auth['base']}/api/drive/folders/all"],
    ],
    'clients' => [
        ['clients.folder-mapping', "{$auth['base']}/api/clients/folder-mapping"],
    ],
    'boards'  => [
        ['boards.url-mappings',    "{$auth['base']}/api/boards/url-mappings"],
    ],
];

$results = [];
try {
    foreach ($endpoints as $group => $list) {
        if (!shouldRun($group)) continue;
        out("\n--- " . strtoupper($group) . " ---");
        foreach ($list as [$label, $url]) {
            $results[] = testEndpoint($label, $url, $headers, $attempts, $budgetMs);
        }
    }
} finally {
    out("\nSummary: passed=$passed failed=$failed total=$totalTests");
    if ($jsonOut) {
        echo json_encode([
            'passed'  => $passed,
            'failed'  => $failed,
            'total'   => $totalTests,
            'budget'  => $budgetMs,
            'attempts'=> $attempts,
            'results' => $results,
        ], JSON_PRETTY_PRINT) . "\n";
    }
    if ($logFile) out("Log: $logFile");
}

exit($failed === 0 ? 0 : 1);
