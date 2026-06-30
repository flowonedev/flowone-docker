#!/usr/bin/env php
<?php
/**
 * Weather chip system test - covers schema, IP geocode, Open-Meteo, shared cache, controller wiring.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/weather-system-test.php --verbose
 *
 * Options:
 *   --help                Usage
 *   --verbose             Stack traces + extra output
 *   --skip-send           Do NOT call live Open-Meteo / ipapi (uses cached rows only)
 *   --smoke               Extensions + config + DB connectivity only
 *   --json                JSON summary on stdout
 *   --only=g1,g2          Run only listed groups: ext,config,db,schema,utils,cache,sharing,api,wiring
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$longopts = ['help', 'verbose', 'skip-send', 'smoke', 'json', 'only:'];
$opts = getopt('', $longopts) ?: [];

if (isset($opts['help'])) {
    echo "weather-system-test.php [--verbose] [--skip-send] [--smoke] [--json] [--only=ext,config,db,schema,utils,cache,sharing,api,wiring]\n";
    exit(0);
}

$verbose  = isset($opts['verbose']);
$skipSend = isset($opts['skip-send']);
$smoke    = isset($opts['smoke']);
$jsonOut  = isset($opts['json']);
$only     = !empty($opts['only']) ? array_map('trim', explode(',', (string)$opts['only'])) : null;

$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/weather-test-' . gmdate('Ymd-His') . '.log';

$passed = 0; $failed = 0; $warnings = 0; $failMsgs = [];

// Recognizable prefix so test data is never confused with production data
const TEST_USER_PREFIX = 'flowone-weather-test-';

// Track all rows we create so cleanup can wipe them even on hard failure
$createdEmails  = [];
$createdBuckets = [];

function want(?array $only, string $g): bool { return $only === null || in_array($g, $only, true); }

function log_line(string $f, string $m): void {
    @file_put_contents($f, '[' . gmdate('H:i:s') . '] ' . $m . "\n", FILE_APPEND);
    echo $m . "\n";
}

function color(string $s, string $c): string {
    $codes = ['green' => "\033[32m", 'red' => "\033[31m", 'yellow' => "\033[33m", 'reset' => "\033[0m"];
    if (!stream_isatty(STDOUT)) return $s;
    return ($codes[$c] ?? '') . $s . $codes['reset'];
}

function run_test(string $logFile, string $name, callable $fn, bool $verbose): string {
    global $passed, $failed, $warnings, $failMsgs;
    set_time_limit(30);
    $t0 = microtime(true);
    try {
        $r = $fn();
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if ($r === 'warn') {
            $warnings++;
            log_line($logFile, color('[WARN]', 'yellow') . " {$name} ({$ms}ms)");
            return 'WARN';
        }
        $passed++;
        log_line($logFile, color('[PASS]', 'green') . " {$name} ({$ms}ms)");
        return 'PASS';
    } catch (\Throwable $e) {
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $failed++;
        $msg = $e->getMessage();
        $failMsgs[] = "{$name}: {$msg}";
        log_line($logFile, color('[FAIL]', 'red') . " {$name} ({$ms}ms) {$msg}");
        if ($verbose) {
            log_line($logFile, $e->getTraceAsString());
        }
        return 'FAIL';
    }
}

// =============================================================================
// Pre-flight
// =============================================================================
foreach (['pdo', 'pdo_mysql', 'json', 'mbstring', 'curl'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "Pre-flight FAIL: missing extension {$ext}\n");
        exit(1);
    }
}

log_line($logFile, "--- 0. PRE-FLIGHT ---");
log_line($logFile, "Extensions: pdo pdo_mysql json mbstring curl OK");
log_line($logFile, "Log file: {$logFile}");

if ($smoke) {
    log_line($logFile, '[SMOKE] extensions OK, app.env=' . ($config['app']['env'] ?? 'unset'));
    try {
        \Webmail\Core\Database::getConnection($config)->query('SELECT 1');
        log_line($logFile, '[SMOKE] DB reachable OK');
    } catch (\Throwable $e) {
        log_line($logFile, '[SMOKE] DB unreachable: ' . $e->getMessage());
        exit(1);
    }
    exit(0);
}

// =============================================================================
// Helpers
// =============================================================================
function db(array $config): \PDO { return \Webmail\Core\Database::getConnection($config); }

function cleanup_test_data(array $config, array $createdEmails, array $createdBuckets, string $logFile): void {
    try {
        $db = db($config);
        if (!empty($createdEmails)) {
            $placeholders = implode(',', array_fill(0, count($createdEmails), '?'));
            $stmt = $db->prepare("DELETE FROM user_locations WHERE user_email IN ($placeholders)");
            $stmt->execute(array_map('strtolower', $createdEmails));
        }
        foreach ($createdBuckets as [$lat, $lon]) {
            $stmt = $db->prepare('DELETE FROM weather_cache WHERE lat_bucket = ? AND lon_bucket = ?');
            $stmt->execute([$lat, $lon]);
        }
        log_line($logFile, '[CLEANUP] removed test rows: '
            . count($createdEmails) . ' user_locations, '
            . count($createdBuckets) . ' weather_cache');
    } catch (\Throwable $e) {
        log_line($logFile, '[CLEANUP] error: ' . $e->getMessage());
    }
}

// Always clean up, even on hard failure or SIGINT
register_shutdown_function(function () use (&$config, &$createdEmails, &$createdBuckets, $logFile) {
    cleanup_test_data($config, $createdEmails, $createdBuckets, $logFile);
});
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () { exit(130); });
    pcntl_signal(SIGTERM, function () { exit(143); });
}

// =============================================================================
// 1. EXT (sanity)
// =============================================================================
if (want($only, 'ext')) {
    log_line($logFile, "--- 1. EXT ---");
    run_test($logFile, 'curl extension reports HTTPS support', function () {
        $info = curl_version();
        $features = $info['features'] ?? 0;
        if (!in_array('https', $info['protocols'] ?? [], true)) {
            throw new \RuntimeException('curl built without HTTPS');
        }
        return true;
    }, $verbose);
}

// =============================================================================
// 2. CONFIG
// =============================================================================
if (want($only, 'config')) {
    log_line($logFile, "--- 2. CONFIG ---");
    run_test($logFile, 'app config loaded', function () use ($config) {
        if (!is_array($config) || empty($config['db'])) {
            throw new \RuntimeException('config missing db');
        }
        return true;
    }, $verbose);
}

// =============================================================================
// 3. DB connectivity
// =============================================================================
if (want($only, 'db')) {
    log_line($logFile, "--- 3. DB ---");
    run_test($logFile, 'DB reachable', function () use ($config) {
        db($config)->query('SELECT 1');
        return true;
    }, $verbose);
}

// =============================================================================
// 4. SCHEMA (migration applied)
// =============================================================================
if (want($only, 'schema')) {
    log_line($logFile, "--- 4. SCHEMA ---");
    run_test($logFile, 'weather_cache table exists with expected columns', function () use ($config) {
        $rows = db($config)->query('SHOW COLUMNS FROM weather_cache')->fetchAll(\PDO::FETCH_COLUMN);
        $required = ['id','lat_bucket','lon_bucket','weather_code','temperature_c','is_day','payload_json','fetched_at'];
        foreach ($required as $c) {
            if (!in_array($c, $rows, true)) {
                throw new \RuntimeException("weather_cache missing column: $c");
            }
        }
        return true;
    }, $verbose);

    run_test($logFile, 'user_locations table exists with expected columns', function () use ($config) {
        $rows = db($config)->query('SHOW COLUMNS FROM user_locations')->fetchAll(\PDO::FETCH_COLUMN);
        $required = ['user_email','lat_bucket','lon_bucket','latitude','longitude','city','country_code','geo_fetched_at'];
        foreach ($required as $c) {
            if (!in_array($c, $rows, true)) {
                throw new \RuntimeException("user_locations missing column: $c");
            }
        }
        return true;
    }, $verbose);

    run_test($logFile, 'weather_cache has unique (lat_bucket, lon_bucket)', function () use ($config) {
        $rows = db($config)->query("SHOW INDEX FROM weather_cache WHERE Key_name = 'uniq_bucket'")
            ->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows) < 2) {
            throw new \RuntimeException('uniq_bucket index missing or single-column');
        }
        return true;
    }, $verbose);
}

// =============================================================================
// 5. UTILS (bucketing, public-IP detection)
// =============================================================================
if (want($only, 'utils')) {
    log_line($logFile, "--- 5. UTILS ---");

    // We reach the private helpers via reflection so they stay private but still testable.
    $svc = new \Webmail\Services\WeatherService($config);
    $ref = new \ReflectionClass($svc);

    run_test($logFile, 'bucket() rounds to 0.1 deg', function () use ($svc, $ref) {
        $m = $ref->getMethod('bucket'); $m->setAccessible(true);
        $cases = [
            [47.4979, 47.5],
            [19.0402, 19.0],
            [47.55, 47.6],
            [-0.04, 0.0],
            [-0.06, -0.1],
        ];
        foreach ($cases as [$in, $expected]) {
            $got = round($m->invoke($svc, $in), 2);
            if ($got !== (float)$expected) {
                throw new \RuntimeException("bucket($in) = $got, expected $expected");
            }
        }
        return true;
    }, $verbose);

    run_test($logFile, 'isPublicIp rejects private/loopback ranges', function () use ($svc, $ref) {
        $m = $ref->getMethod('isPublicIp'); $m->setAccessible(true);
        $private = ['127.0.0.1', '10.0.0.5', '192.168.1.1', '172.16.0.1', '::1', '0.0.0.0'];
        foreach ($private as $ip) {
            if ($m->invoke($svc, $ip)) {
                throw new \RuntimeException("isPublicIp falsely accepted $ip");
            }
        }
        if (!$m->invoke($svc, '8.8.8.8')) {
            throw new \RuntimeException('isPublicIp falsely rejected 8.8.8.8');
        }
        return true;
    }, $verbose);
}

// =============================================================================
// 6. CACHE behaviour (single user, TTL boundaries)
// =============================================================================
if (want($only, 'cache')) {
    log_line($logFile, "--- 6. CACHE ---");

    run_test($logFile, 'getForUser populates user_locations + weather_cache for public IP', function () use ($config, $skipSend, &$createdEmails, &$createdBuckets) {
        if ($skipSend) return 'warn';

        $svc = new \Webmail\Services\WeatherService($config);
        $email = TEST_USER_PREFIX . bin2hex(random_bytes(4)) . '@flowone.local';
        $createdEmails[] = $email;

        // Cloudflare's public DNS - reliably public, ipapi will geolocate it
        $result = $svc->getForUser($email, '1.1.1.1');

        if (empty($result) || !is_array($result)) {
            throw new \RuntimeException('empty result');
        }
        if ($result['available'] !== true) {
            // ipapi or Open-Meteo could be down; warn rather than fail so the suite stays green offline.
            return 'warn';
        }
        if (!is_numeric($result['temperature_c'])) {
            throw new \RuntimeException('temperature not numeric: ' . var_export($result['temperature_c'], true));
        }

        $db = db($config);
        $stmt = $db->prepare('SELECT lat_bucket, lon_bucket FROM user_locations WHERE user_email = ?');
        $stmt->execute([strtolower($email)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('user_locations row not created');
        }
        $createdBuckets[] = [(float)$row['lat_bucket'], (float)$row['lon_bucket']];

        $stmt = $db->prepare('SELECT COUNT(*) FROM weather_cache WHERE lat_bucket = ? AND lon_bucket = ?');
        $stmt->execute([$row['lat_bucket'], $row['lon_bucket']]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new \RuntimeException('weather_cache row not created for this bucket');
        }
        return true;
    }, $verbose);

    run_test($logFile, 'getForUser response includes 7-day forecast array', function () use ($config, $skipSend, &$createdEmails, &$createdBuckets) {
        if ($skipSend) return 'warn';

        $svc = new \Webmail\Services\WeatherService($config);
        $email = TEST_USER_PREFIX . bin2hex(random_bytes(4)) . '@flowone.local';
        $createdEmails[] = $email;

        $result = $svc->getForUser($email, '1.1.1.1');
        if (!$result['available']) return 'warn';

        if (!isset($result['forecast']) || !is_array($result['forecast'])) {
            throw new \RuntimeException('forecast key missing or not an array');
        }
        if (count($result['forecast']) < 5) {
            throw new \RuntimeException('expected at least 5 days, got ' . count($result['forecast']));
        }
        foreach (['date', 'weather_code', 'max', 'min'] as $k) {
            if (!array_key_exists($k, $result['forecast'][0])) {
                throw new \RuntimeException("forecast[0] missing key: $k");
            }
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$result['forecast'][0]['date'])) {
            throw new \RuntimeException('forecast date format unexpected: ' . $result['forecast'][0]['date']);
        }

        $latBucket = round($result['latitude'] / 0.1) * 0.1;
        $lonBucket = round($result['longitude'] / 0.1) * 0.1;
        $createdBuckets[] = [$latBucket, $lonBucket];

        return true;
    }, $verbose);

    run_test($logFile, 'fresh cache row is reused (no extra row created on second call)', function () use ($config, $skipSend, &$createdEmails, &$createdBuckets) {
        if ($skipSend) return 'warn';

        $svc = new \Webmail\Services\WeatherService($config);
        $email = TEST_USER_PREFIX . bin2hex(random_bytes(4)) . '@flowone.local';
        $createdEmails[] = $email;

        $a = $svc->getForUser($email, '1.1.1.1');
        if (!$a['available']) return 'warn';

        $db = db($config);
        $stmt = $db->prepare('SELECT fetched_at FROM weather_cache WHERE lat_bucket = ? AND lon_bucket = ?');
        $stmt->execute([round($a['latitude'] / 0.1) * 0.1, round($a['longitude'] / 0.1) * 0.1]);
        $firstFetched = $stmt->fetchColumn();
        $createdBuckets[] = [round($a['latitude'] / 0.1) * 0.1, round($a['longitude'] / 0.1) * 0.1];

        // Second call within TTL should NOT touch fetched_at
        $svc->getForUser($email, '1.1.1.1');
        $stmt->execute([round($a['latitude'] / 0.1) * 0.1, round($a['longitude'] / 0.1) * 0.1]);
        $secondFetched = $stmt->fetchColumn();

        if ($firstFetched !== $secondFetched) {
            throw new \RuntimeException("cache was refreshed unexpectedly: $firstFetched -> $secondFetched");
        }
        return true;
    }, $verbose);
}

// =============================================================================
// 7. SHARING (multiple users in same bucket share one weather_cache row)
//    This is the core rate-limit guarantee.
// =============================================================================
if (want($only, 'sharing')) {
    log_line($logFile, "--- 7. SHARING ---");

    run_test($logFile, 'two users in the same bucket share a single weather_cache row', function () use ($config, &$createdEmails, &$createdBuckets) {
        $db = db($config);
        $svc = new \Webmail\Services\WeatherService($config);

        $latBucket = 47.5; $lonBucket = 19.0; // Budapest-ish
        $createdBuckets[] = [$latBucket, $lonBucket];

        // Pre-seed a fresh cache row so the test runs offline (skip-send safe)
        $stmt = $db->prepare('INSERT INTO weather_cache (lat_bucket, lon_bucket, weather_code, temperature_c, is_day, payload_json, fetched_at) VALUES (?, ?, 0, 18.5, 1, NULL, NOW()) ON DUPLICATE KEY UPDATE fetched_at = NOW(), temperature_c = 18.5, weather_code = 0');
        $stmt->execute([$latBucket, $lonBucket]);

        // Two test users, hand-rolled location rows pointing at the same bucket
        $u1 = TEST_USER_PREFIX . bin2hex(random_bytes(4)) . '@flowone.local';
        $u2 = TEST_USER_PREFIX . bin2hex(random_bytes(4)) . '@flowone.local';
        $createdEmails[] = $u1; $createdEmails[] = $u2;

        $insLoc = $db->prepare(
            'INSERT INTO user_locations (user_email, lat_bucket, lon_bucket, latitude, longitude, city, country_code, resolved_from_ip, geo_fetched_at)
             VALUES (?, ?, ?, 47.4979, 19.0402, "TestCity", "HU", "203.0.113.1", NOW())'
        );
        $insLoc->execute([strtolower($u1), $latBucket, $lonBucket]);
        $insLoc->execute([strtolower($u2), $latBucket, $lonBucket]);

        $countBefore = (int)$db->query('SELECT COUNT(*) FROM weather_cache WHERE lat_bucket = ' . $latBucket . ' AND lon_bucket = ' . $lonBucket)->fetchColumn();
        if ($countBefore !== 1) {
            throw new \RuntimeException("pre-condition: expected 1 cache row, got $countBefore");
        }

        $r1 = $svc->getForUser($u1, '203.0.113.1');
        $r2 = $svc->getForUser($u2, '203.0.113.1');

        $countAfter = (int)$db->query('SELECT COUNT(*) FROM weather_cache WHERE lat_bucket = ' . $latBucket . ' AND lon_bucket = ' . $lonBucket)->fetchColumn();
        if ($countAfter !== 1) {
            throw new \RuntimeException("post-condition: expected exactly 1 cache row shared, got $countAfter");
        }

        if ((float)$r1['temperature_c'] !== 18.5 || (float)$r2['temperature_c'] !== 18.5) {
            throw new \RuntimeException("both users should read 18.5, got u1={$r1['temperature_c']} u2={$r2['temperature_c']}");
        }

        return true;
    }, $verbose);

    run_test($logFile, 'stale row + claimRefresh: only first caller claims, second sees stale', function () use ($config, &$createdBuckets) {
        $db = db($config);
        $svc = new \Webmail\Services\WeatherService($config);
        $ref = new \ReflectionClass($svc);
        $claim = $ref->getMethod('claimRefresh');
        $claim->setAccessible(true);

        $latBucket = 47.6; $lonBucket = 19.1;
        $createdBuckets[] = [$latBucket, $lonBucket];

        // Insert a row already stale (1 hour old)
        $stmt = $db->prepare('INSERT INTO weather_cache (lat_bucket, lon_bucket, weather_code, temperature_c, is_day, payload_json, fetched_at) VALUES (?, ?, 1, 12.0, 1, NULL, DATE_SUB(NOW(), INTERVAL 1 HOUR)) ON DUPLICATE KEY UPDATE fetched_at = DATE_SUB(NOW(), INTERVAL 1 HOUR)');
        $stmt->execute([$latBucket, $lonBucket]);

        $stmt = $db->prepare('SELECT fetched_at FROM weather_cache WHERE lat_bucket = ? AND lon_bucket = ?');
        $stmt->execute([$latBucket, $lonBucket]);
        $fetched = $stmt->fetchColumn();

        $first  = $claim->invoke($svc, $latBucket, $lonBucket, $fetched);
        $second = $claim->invoke($svc, $latBucket, $lonBucket, $fetched);

        if (!$first) {
            throw new \RuntimeException('first claim should succeed');
        }
        if ($second) {
            throw new \RuntimeException('second claim should fail (already claimed)');
        }
        return true;
    }, $verbose);
}

// =============================================================================
// 8. API surface (controller exposes the expected public method)
// =============================================================================
if (want($only, 'api')) {
    log_line($logFile, "--- 8. API ---");

    run_test($logFile, 'WeatherController has public method current()', function () {
        $r = new \ReflectionClass(\Webmail\Controllers\WeatherController::class);
        if (!$r->hasMethod('current') || !$r->getMethod('current')->isPublic()) {
            throw new \RuntimeException('WeatherController::current missing or not public');
        }
        return true;
    }, $verbose);

    run_test($logFile, 'WeatherService has public getForUser()', function () {
        $r = new \ReflectionClass(\Webmail\Services\WeatherService::class);
        if (!$r->hasMethod('getForUser') || !$r->getMethod('getForUser')->isPublic()) {
            throw new \RuntimeException('WeatherService::getForUser missing or not public');
        }
        return true;
    }, $verbose);
}

// =============================================================================
// 9. WIRING (route registered, controller imported, frontend artefacts present)
// =============================================================================
if (want($only, 'wiring')) {
    log_line($logFile, "--- 9. WIRING ---");

    run_test($logFile, 'routes.php imports WeatherController', function () {
        $body = (string)@file_get_contents(__DIR__ . '/../routes.php');
        if (!str_contains($body, 'use Webmail\\Controllers\\WeatherController')) {
            throw new \RuntimeException('use statement missing in routes.php');
        }
        return true;
    }, $verbose);

    run_test($logFile, 'routes.php registers GET /weather/current', function () {
        $body = (string)@file_get_contents(__DIR__ . '/../routes.php');
        if (!str_contains($body, "/weather/current")) {
            throw new \RuntimeException('/weather/current route not found');
        }
        return true;
    }, $verbose);

    run_test($logFile, 'migration 156_weather_cache.sql is present', function () {
        $path = __DIR__ . '/../migrations/156_weather_cache.sql';
        if (!file_exists($path)) {
            throw new \RuntimeException("missing: $path");
        }
        $body = (string)file_get_contents($path);
        if (!str_contains($body, 'weather_cache') || !str_contains($body, 'user_locations')) {
            throw new \RuntimeException('migration does not declare both tables');
        }
        return true;
    }, $verbose);

    run_test($logFile, 'frontend WeatherChip + store + meteocons present (when src is co-located)', function () {
        // On the VPS we typically only have backend + built frontend dist/, so the
        // raw Vue source may not exist here. Skip-warn in that case rather than fail.
        $frontendRoot = __DIR__ . '/../../frontend/src';
        if (!is_dir($frontendRoot)) {
            return 'warn';
        }

        $required = [
            $frontendRoot . '/components/shared/WeatherChip.vue',
            $frontendRoot . '/stores/weather.js',
            $frontendRoot . '/assets/meteocons/clear-day.svg',
            $frontendRoot . '/assets/meteocons/not-available.svg',
        ];
        foreach ($required as $p) {
            if (!file_exists($p)) {
                throw new \RuntimeException("missing: $p");
            }
        }
        $header = (string)@file_get_contents($frontendRoot . '/components/shared/AppHeader.vue');
        if (!str_contains($header, "import WeatherChip")) {
            throw new \RuntimeException('AppHeader.vue does not import WeatherChip');
        }
        if (!str_contains($header, "<WeatherChip")) {
            throw new \RuntimeException('AppHeader.vue does not render <WeatherChip>');
        }
        return true;
    }, $verbose);
}

// =============================================================================
// Summary
// =============================================================================
$summary = [
    'passed'   => $passed,
    'failed'   => $failed,
    'warnings' => $warnings,
    'failed_tests' => $failMsgs,
    'log_file' => $logFile,
];

log_line($logFile, "");
log_line($logFile, "==================================================");
log_line($logFile, "  passed:   " . color((string)$passed, 'green'));
log_line($logFile, "  failed:   " . color((string)$failed, $failed ? 'red' : 'green'));
log_line($logFile, "  warnings: " . color((string)$warnings, $warnings ? 'yellow' : 'green'));
if ($failMsgs) {
    log_line($logFile, "FAILED:");
    foreach ($failMsgs as $m) {
        log_line($logFile, "  - " . $m);
    }
}
log_line($logFile, "==================================================");

if ($jsonOut) {
    echo json_encode($summary, JSON_PRETTY_PRINT) . "\n";
}

exit($failed ? 1 : 0);
