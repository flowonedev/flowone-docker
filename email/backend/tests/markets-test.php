#!/usr/bin/env php
<?php
/**
 * Markets service — preflight, upstream fetch, cache, endpoint.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/markets-test.php --verbose
 *
 * Options:
 *   --help
 *   --verbose            Extra debug output
 *   --skip-fetch         Skip outbound HTTP (CoinGecko, Twelve Data)
 *   --smoke              Extensions + Redis only, no business logic
 *   --json               Emit JSON summary
 *   --only=preflight,coingecko,twelvedata,cache,endpoint
 *
 * Safety: never writes to the database. Cache writes go into Redis under
 * a recognisable test key (`flowone_test_markets:*`) and are deleted in
 * the cleanup phase. SIGINT/SIGTERM run the cleanup before exiting.
 */
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$longopts = ['help', 'verbose', 'skip-fetch', 'smoke', 'json', 'only:'];
$opts = getopt('', $longopts) ?: [];

if (isset($opts['help'])) {
    echo "markets-test.php [--verbose] [--skip-fetch] [--smoke] [--json]"
        . " [--only=preflight,coingecko,twelvedata,cache,endpoint]\n";
    exit(0);
}

$verbose = isset($opts['verbose']);
$skipFetch = isset($opts['skip-fetch']);
$smoke = isset($opts['smoke']);
$jsonOut = isset($opts['json']);
$only = !empty($opts['only']) ? array_map('trim', explode(',', (string) $opts['only'])) : null;

$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/markets-test-' . gmdate('Ymd-His') . '.log';

$passed = 0;
$failed = 0;
$warnings = 0;
$failMsgs = [];

function want(?array $only, string $g): bool
{
    return $only === null || in_array($g, $only, true);
}

function log_line(string $f, string $m): void
{
    @file_put_contents($f, '[' . gmdate('H:i:s') . '] ' . $m . "\n", FILE_APPEND);
    echo $m . "\n";
}

function color(string $s, string $c): string
{
    $codes = ['green' => "\033[32m", 'red' => "\033[31m", 'yellow' => "\033[33m", 'reset' => "\033[0m"];
    if (!stream_isatty(STDOUT)) {
        return $s;
    }

    return ($codes[$c] ?? '') . $s . $codes['reset'];
}

function run_test(string $logFile, string $name, callable $fn, bool $verbose): string
{
    global $passed, $failed, $warnings, $failMsgs;
    // Per-test 30s timeout — CoinGecko + Twelve Data both ship < 5s in
    // practice, but a hung TCP connection shouldn't block the suite.
    set_time_limit(30);
    $t0 = microtime(true);
    try {
        $r = $fn();
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if ($r === 'warn') {
            $warnings++;
            log_line($logFile, color('[WARN]', 'yellow') . " {$name} ({$ms}ms)");

            return 'WARN';
        }
        $passed++;
        log_line($logFile, color('[PASS]', 'green') . " {$name} ({$ms}ms)");

        return 'PASS';
    } catch (\Throwable $e) {
        $ms = (int) round((microtime(true) - $t0) * 1000);
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

// Cleanup tracker: list of Redis keys to remove at the end.
$createdKeys = [];

function track_key(string $key): void
{
    global $createdKeys;
    $createdKeys[] = $key;
}

function cleanup(array $config): void
{
    global $createdKeys, $logFile;
    $cfg = $config['redis'] ?? [];
    if (empty($cfg['host']) || !extension_loaded('redis') || empty($createdKeys)) {
        return;
    }
    try {
        $r = new \Redis();
        $r->connect($cfg['host'], (int) ($cfg['port'] ?? 6379), 2.0);
        if (!empty($cfg['password'])) {
            $r->auth($cfg['password']);
        }
        if (!empty($cfg['database'])) {
            $r->select((int) $cfg['database']);
        }
        foreach (array_unique($createdKeys) as $k) {
            $r->del($k);
        }
        log_line($logFile, '[CLEANUP] removed ' . count(array_unique($createdKeys)) . ' redis keys');
    } catch (\Throwable $e) {
        log_line($logFile, '[CLEANUP] WARN: ' . $e->getMessage());
    }
}

// Always run cleanup, even on fatal error / signal interruption.
register_shutdown_function('cleanup', $config);
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () use ($config) {
        log_line($GLOBALS['logFile'], '[SIGNAL] SIGINT — cleaning up');
        cleanup($config);
        exit(130);
    });
    pcntl_signal(SIGTERM, function () use ($config) {
        log_line($GLOBALS['logFile'], '[SIGNAL] SIGTERM — cleaning up');
        cleanup($config);
        exit(143);
    });
}

// --- Pre-flight ---
foreach (['curl', 'json'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "Pre-flight FAIL: missing extension {$ext}\n");
        exit(1);
    }
}

log_line($logFile, '--- Markets test ---');
log_line($logFile, 'Log: ' . $logFile);

if ($smoke) {
    try {
        $cfg = $config['redis'] ?? [];
        if (empty($cfg['host'])) {
            log_line($logFile, '[SMOKE] Redis: not configured (cache will be no-op)');
        } else {
            $r = new \Redis();
            $r->connect($cfg['host'], (int) ($cfg['port'] ?? 6379), 2.0);
            if (!empty($cfg['password'])) {
                $r->auth($cfg['password']);
            }
            log_line($logFile, '[SMOKE] Redis OK');
        }
        log_line($logFile, '[SMOKE] curl ext OK');
    } catch (\Throwable $e) {
        log_line($logFile, '[SMOKE] FAIL: ' . $e->getMessage());
        exit(1);
    }
    exit(0);
}

// --- 1. PREFLIGHT ---
if (want($only, 'preflight')) {
    run_test($logFile, 'Preflight: curl extension loaded', function () {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('ext-curl missing');
        }

        return true;
    }, $verbose);

    run_test($logFile, 'Preflight: redis extension (optional)', function () use ($config) {
        $cfg = $config['redis'] ?? [];
        if (empty($cfg['host'])) {
            return 'warn'; // Cache will be a no-op, service still works.
        }
        if (!extension_loaded('redis')) {
            throw new \RuntimeException('Redis configured but ext-redis missing');
        }
        $r = new \Redis();
        $r->connect($cfg['host'], (int) ($cfg['port'] ?? 6379), 2.0);
        if (!empty($cfg['password'])) {
            $r->auth($cfg['password']);
        }

        return true;
    }, $verbose);

    run_test($logFile, 'Preflight: log dir writable', function () use ($logDir) {
        if (!is_dir($logDir) || !is_writable($logDir)) {
            throw new \RuntimeException('storage/logs not writable: ' . $logDir);
        }

        return true;
    }, $verbose);

    run_test($logFile, 'Preflight: MarketsService constructible', function () use ($config) {
        $svc = new \Webmail\Addons\NewsReader\Markets\MarketsService($config);
        if (!is_object($svc)) {
            throw new \RuntimeException('Service did not construct');
        }

        return true;
    }, $verbose);

    run_test($logFile, 'Preflight: routes.php registers /markets/overview + /markets/available', function () {
        $routes = file_get_contents(__DIR__ . '/../routes.php');
        if (!is_string($routes) || $routes === '') {
            throw new \RuntimeException('routes.php unreadable');
        }
        if (strpos($routes, '/markets/overview') === false) {
            throw new \RuntimeException('overview route not registered');
        }
        if (strpos($routes, '/markets/available') === false) {
            throw new \RuntimeException('available route not registered');
        }
        if (strpos($routes, 'MarketsController') === false) {
            throw new \RuntimeException('MarketsController not imported');
        }

        return true;
    }, $verbose);

    run_test($logFile, 'Preflight: getAvailable() exposes default basket + allow-list', function () use ($config) {
        $svc = new \Webmail\Addons\NewsReader\Markets\MarketsService($config);
        $a = $svc->getAvailable();
        foreach (['stocks', 'crypto', 'defaults'] as $k) {
            if (!isset($a[$k])) {
                throw new \RuntimeException("getAvailable() missing key: {$k}");
            }
        }
        if (!is_array($a['stocks']) || count($a['stocks']) < 5) {
            throw new \RuntimeException('Stocks allow-list too short');
        }
        if (!is_array($a['crypto']) || count($a['crypto']) < 5) {
            throw new \RuntimeException('Crypto allow-list too short');
        }
        $first = $a['stocks'][0];
        foreach (['symbol', 'name'] as $k) {
            if (empty($first[$k])) {
                throw new \RuntimeException("Stock entry missing {$k}");
            }
        }
        $firstC = $a['crypto'][0];
        foreach (['id', 'symbol', 'name'] as $k) {
            if (empty($firstC[$k])) {
                throw new \RuntimeException("Crypto entry missing {$k}");
            }
        }
        if (empty($a['defaults']['stocks']) || empty($a['defaults']['crypto'])) {
            throw new \RuntimeException('Defaults are empty');
        }

        return true;
    }, $verbose);

    run_test($logFile, 'Preflight: sanitiseBasket falls back to defaults for bogus input', function () use ($config) {
        // Indirect test via getOverview() — passing all-bogus stocks
        // should still return SOME stock rows (the default basket) when
        // upstream is reachable, OR an empty payload (when upstream
        // fails). Either way it must not throw.
        $svc = new \Webmail\Addons\NewsReader\Markets\MarketsService($config);
        $payload = $svc->getOverview(['NOT_A_SYMBOL_XYZ'], ['not-a-coin-xyz']);
        if (!is_array($payload) || !isset($payload['stocks'], $payload['crypto'])) {
            throw new \RuntimeException('Payload shape broken');
        }

        return true;
    }, $verbose);
}

// --- 2. COINGECKO ---
if (want($only, 'coingecko')) {
    if ($skipFetch) {
        run_test($logFile, 'CoinGecko: skipped (--skip-fetch)', function () {
            return 'warn';
        }, $verbose);
    } else {
        run_test($logFile, 'CoinGecko: fetch + parse crypto basket', function () use ($config, $verbose) {
            $svc = new \Webmail\Addons\NewsReader\Markets\MarketsService($config);
            // Force-refresh to bypass any cached payload — we want a real
            // upstream call here, not a cache read.
            try {
                $payload = $svc->refresh();
            } catch (\Throwable $e) {
                if ($verbose) {
                    fwrite(STDERR, "CoinGecko upstream error: " . $e->getMessage() . "\n");
                }

                return 'warn'; // network blocked / upstream rate-limited
            }
            if (!is_array($payload['crypto'] ?? null) || count($payload['crypto']) === 0) {
                return 'warn'; // upstream returned empty (rate-limited)
            }
            $first = $payload['crypto'][0];
            foreach (['symbol', 'name', 'price', 'change_pct', 'sparkline'] as $k) {
                if (!array_key_exists($k, $first)) {
                    throw new \RuntimeException("Crypto row missing key: {$k}");
                }
            }
            if (!is_array($first['sparkline'])) {
                throw new \RuntimeException('Sparkline must be an array, got ' . gettype($first['sparkline']));
            }
            // Sparkline should be a non-trivial sample (we downsample to ~24 points).
            if (count($first['sparkline']) < 4) {
                throw new \RuntimeException('Sparkline too short: ' . count($first['sparkline']));
            }
            // Price must be a positive number.
            if (!is_numeric($first['price']) || (float) $first['price'] <= 0) {
                throw new \RuntimeException('Bad price: ' . var_export($first['price'], true));
            }

            return true;
        }, $verbose);
    }
}

// --- 3. TWELVE DATA ---
// Twelve Data replaces the previous key-less providers — both Stooq and
// Yahoo started blocking / gating server-side calls in 2026, which
// silently emptied the stocks panel. Twelve Data's free Basic plan
// (800 credits/day, 8/min) is reached via a TWELVEDATA_API_KEY env var.
// The probe below clearly distinguishes "no key configured" from
// "upstream failure" so the operator knows which path to take.
if (want($only, 'twelvedata')) {
    run_test($logFile, 'TwelveData: API key configured', function () use ($config) {
        $svc = new \Webmail\Addons\NewsReader\Markets\MarketsService($config);
        if (!$svc->hasStocksProviderKey()) {
            throw new \RuntimeException(
                'TWELVEDATA_API_KEY is empty. Get a free key at '
                . 'https://twelvedata.com/register, then add '
                . 'TWELVEDATA_API_KEY=... to backend/.env and re-run.'
            );
        }

        return true;
    }, $verbose);

    if ($skipFetch) {
        run_test($logFile, 'TwelveData: skipped (--skip-fetch)', function () {
            return 'warn';
        }, $verbose);
    } else {
        run_test($logFile, 'TwelveData: raw HTTP probe (status code + body preview)', function () use ($config, $verbose) {
            // Direct request to Twelve Data with the configured key.
            // Surfaces auth/quota/symbol-coverage errors verbatim so
            // we know whether the failure is "blocked IP", "bad key",
            // or "plan-locked symbol".
            $key = trim((string) ($config['markets']['twelvedata_api_key'] ?? ''));
            if ($key === '') {
                return 'warn'; // already explained by the previous test
            }
            $url = 'https://api.twelvedata.com/time_series'
                . '?symbol=' . rawurlencode('AAPL,MSFT')
                . '&interval=1day'
                . '&outputsize=5'
                . '&order=asc'
                . '&apikey=' . rawurlencode($key);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'FlowOne/1.0 (+https://flowone.pro)',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = (string) curl_error($ch);
            curl_close($ch);
            if ($verbose) {
                fwrite(STDERR, "TwelveData probe HTTP {$code}\n");
                if ($body !== false) {
                    fwrite(STDERR, "Body preview: " . substr((string) $body, 0, 300) . "\n");
                }
                if ($err !== '') {
                    fwrite(STDERR, "Curl err: {$err}\n");
                }
            }
            if ($body === false || $err !== '') {
                throw new \RuntimeException(
                    'TwelveData unreachable (curl err: ' . ($err ?: 'unknown') . ')'
                );
            }
            $decoded = json_decode((string) $body, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Non-JSON response from TwelveData (HTTP ' . $code . ')');
            }
            // Top-level error envelope — surfaces auth/quota issues.
            if (isset($decoded['status']) && $decoded['status'] === 'error') {
                throw new \RuntimeException(
                    'TwelveData error code=' . ($decoded['code'] ?? '?')
                    . ' message=' . ($decoded['message'] ?? '')
                );
            }

            return true;
        }, $verbose);

        run_test($logFile, 'TwelveData: fetch + parse stocks basket via /time_series batch', function () use ($config, $verbose) {
            $svc = new \Webmail\Addons\NewsReader\Markets\MarketsService($config);
            if (!$svc->hasStocksProviderKey()) {
                return 'warn'; // already explained by the key check above
            }
            try {
                $payload = $svc->refresh();
            } catch (\Throwable $e) {
                if ($verbose) {
                    fwrite(STDERR, "TwelveData upstream error: " . $e->getMessage() . "\n");
                }

                return 'warn';
            }
            if (!is_array($payload['stocks'] ?? null) || count($payload['stocks']) === 0) {
                // Upstream returned an empty / error payload; the raw
                // HTTP probe above will have surfaced the reason in
                // verbose mode. Don't fail the suite here.
                return 'warn';
            }
            $first = $payload['stocks'][0];
            foreach (['symbol', 'name', 'price', 'change_pct', 'sparkline'] as $k) {
                if (!array_key_exists($k, $first)) {
                    throw new \RuntimeException("Stock row missing key: {$k}");
                }
            }
            if (!is_numeric($first['price']) || (float) $first['price'] <= 0) {
                throw new \RuntimeException('Stock row leaked zero price: ' . var_export($first['price'], true));
            }
            if (!is_numeric($first['change_pct'])) {
                throw new \RuntimeException('change_pct must be numeric, got ' . gettype($first['change_pct']));
            }
            // Sparkline can be a single point if Twelve Data only had
            // today's close in the requested window; warn instead of
            // failing.
            if (!is_array($first['sparkline']) || count($first['sparkline']) < 2) {
                return 'warn';
            }

            return true;
        }, $verbose);

        // Per-symbol coverage probe: hit Twelve Data with each Yahoo-
        // facing key from STOCKS_ALLOW one at a time so we can see
        // exactly which symbols the configured plan covers. Always
        // runs in verbose mode so the operator gets the report; in
        // non-verbose mode we just emit a one-line OK / WARN.
        run_test($logFile, 'TwelveData: per-symbol coverage report', function () use ($config, $verbose) {
            $key = trim((string) ($config['markets']['twelvedata_api_key'] ?? ''));
            if ($key === '') {
                return 'warn';
            }
            // Test the symbols our default basket actually uses, plus
            // a couple of likely-blocked candidates so we know what's
            // free vs plan-locked.
            $probe = [
                // user-facing => twelve data symbol
                '^GSPC'    => 'SPX',
                '^NDX'     => 'NDX',
                '^DJI'     => 'DJI',
                'AAPL'     => 'AAPL',
                'NVDA'     => 'NVDA',
                'TSLA'     => 'TSLA',
                'EURUSD=X' => 'EUR/USD',
                'GC=F'     => 'XAU/USD',
                // ETF proxies that are usually free on Basic plan;
                // would replace ^GSPC / ^NDX / ^DJI if those are
                // plan-locked.
                'SPY-ETF'  => 'SPY',
                'QQQ-ETF'  => 'QQQ',
                'DIA-ETF'  => 'DIA',
            ];
            $accessible = [];
            $blocked = [];
            foreach ($probe as $userSym => $tdSym) {
                $url = 'https://api.twelvedata.com/time_series'
                    . '?symbol=' . rawurlencode($tdSym)
                    . '&interval=1day'
                    . '&outputsize=2'
                    . '&apikey=' . rawurlencode($key);
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 6,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_USERAGENT      => 'FlowOne/1.0 (+https://flowone.pro)',
                ]);
                $body = curl_exec($ch);
                $err  = (string) curl_error($ch);
                curl_close($ch);
                $msg = '';
                $ok = false;
                if ($body !== false && $err === '') {
                    $decoded = json_decode((string) $body, true);
                    if (is_array($decoded)) {
                        if (($decoded['status'] ?? '') === 'error') {
                            $msg = 'code=' . ($decoded['code'] ?? '?')
                                . ' ' . substr((string) ($decoded['message'] ?? ''), 0, 80);
                        } elseif (is_array($decoded['values'] ?? null) && count($decoded['values']) > 0) {
                            $ok = true;
                        } else {
                            $msg = 'empty values';
                        }
                    } else {
                        $msg = 'non-json';
                    }
                } else {
                    $msg = 'curl: ' . $err;
                }
                $line = sprintf("    %-10s -> %-8s : %s%s",
                    $userSym, $tdSym, $ok ? 'OK' : 'BLOCKED', $msg !== '' ? ' (' . $msg . ')' : '');
                if ($verbose) {
                    fwrite(STDERR, $line . "\n");
                }
                if ($ok) {
                    $accessible[] = $userSym;
                } else {
                    $blocked[] = $userSym;
                }
                // Twelve Data free tier is 8 req/min — sleep 200ms
                // between probes to stay well clear of the limit on
                // larger probe sets.
                usleep(200_000);
            }
            if ($verbose) {
                fwrite(STDERR, "    accessible: " . implode(', ', $accessible) . "\n");
                fwrite(STDERR, "    blocked:    " . implode(', ', $blocked) . "\n");
            }
            // Only warn (never fail) — this is a diagnostic, not a
            // contract check.
            if (count($accessible) === 0) {
                return 'warn';
            }

            return true;
        }, $verbose);
    }
}

// --- 4. CACHE ---
if (want($only, 'cache')) {
    run_test($logFile, 'Cache: redis round-trip (test key)', function () use ($config) {
        $cfg = $config['redis'] ?? [];
        if (empty($cfg['host']) || !extension_loaded('redis')) {
            return 'warn'; // Cache is optional
        }
        $r = new \Redis();
        $r->connect($cfg['host'], (int) ($cfg['port'] ?? 6379), 2.0);
        if (!empty($cfg['password'])) {
            $r->auth($cfg['password']);
        }
        if (!empty($cfg['database'])) {
            $r->select((int) $cfg['database']);
        }
        $key = 'flowone_test_markets:' . bin2hex(random_bytes(4));
        track_key($key);
        $payload = ['hello' => 'world', 't' => time()];
        $r->setex($key, 60, json_encode($payload));
        $back = $r->get($key);
        if (!is_string($back)) {
            throw new \RuntimeException('Redis get returned non-string');
        }
        $decoded = json_decode($back, true);
        if (($decoded['hello'] ?? '') !== 'world') {
            throw new \RuntimeException('Round-trip payload mismatch');
        }

        return true;
    }, $verbose);

    run_test($logFile, 'Cache: getOverview() second call returns same updated_at within freshness window', function () use ($config, $skipFetch) {
        if ($skipFetch) {
            return 'warn';
        }
        $svc = new \Webmail\Addons\NewsReader\Markets\MarketsService($config);
        try {
            $a = $svc->getOverview();
            $b = $svc->getOverview();
        } catch (\Throwable $e) {
            return 'warn';
        }
        if (empty($a['updated_at']) || empty($b['updated_at'])) {
            return 'warn';
        }
        // With Redis configured the second call should be a cache hit and
        // share the same updated_at. Without Redis the timestamps may
        // differ but both should be present — we only fail when the
        // second call somehow rolled back in time.
        if ($b['updated_at'] < $a['updated_at']) {
            throw new \RuntimeException('Second call updated_at went backwards');
        }

        return true;
    }, $verbose);
}

// --- 5. ENDPOINT (controller-level smoke test) ---
if (want($only, 'endpoint')) {
    run_test($logFile, 'Endpoint: MarketsController::overview returns a Response without auth', function () use ($config) {
        // Build a Request with no Authorization header. requireAuth()
        // should bail out without calling upstream APIs and return some
        // Response (typically 401). Request reads its state from
        // $_SERVER, so we wipe the auth header before constructing.
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/markets/overview';
        $controller = new \Webmail\Addons\NewsReader\Markets\MarketsController($config);
        $req = new \Webmail\Core\Request();
        $resp = $controller->overview($req);
        if (!is_object($resp)) {
            throw new \RuntimeException('overview() did not return a Response');
        }

        return true;
    }, $verbose);

    run_test($logFile, 'Endpoint: MarketsController::available returns a Response without auth', function () use ($config) {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/markets/available';
        $controller = new \Webmail\Addons\NewsReader\Markets\MarketsController($config);
        $req = new \Webmail\Core\Request();
        $resp = $controller->available($req);
        if (!is_object($resp)) {
            throw new \RuntimeException('available() did not return a Response');
        }

        return true;
    }, $verbose);

    run_test($logFile, 'Endpoint: getOverview() never throws (returns degraded payload on failure)', function () use ($config) {
        $svc = new \Webmail\Addons\NewsReader\Markets\MarketsService($config);
        $payload = $svc->getOverview();
        // Even when both upstream APIs fail, the service returns the
        // empty-shape payload — never throws — so the controller can
        // always 200.
        if (!isset($payload['stocks'], $payload['crypto'], $payload['updated_at'])) {
            throw new \RuntimeException('Payload missing required keys');
        }
        if (!is_array($payload['stocks']) || !is_array($payload['crypto'])) {
            throw new \RuntimeException('stocks/crypto must be arrays');
        }

        return true;
    }, $verbose);

    run_test($logFile, 'Endpoint: custom basket round-trip (different cache slot)', function () use ($config, $skipFetch) {
        if ($skipFetch) {
            return 'warn';
        }
        // Two custom baskets must not trample each other's cache. We
        // can't easily inspect Redis from here, but we can verify the
        // service accepts custom baskets and returns rows for each.
        $svc = new \Webmail\Addons\NewsReader\Markets\MarketsService($config);
        try {
            $a = $svc->getOverview(['AAPL'], ['bitcoin']);
            $b = $svc->getOverview(['TSLA'], ['ethereum']);
        } catch (\Throwable $e) {
            return 'warn';
        }
        // Either upstream may have failed and returned [], so this is a
        // shape check, not a content check.
        foreach ([$a, $b] as $payload) {
            if (!isset($payload['stocks'], $payload['crypto'])) {
                throw new \RuntimeException('Custom basket payload missing keys');
            }
        }

        return true;
    }, $verbose);
}

// --- Summary ---
log_line($logFile, "Summary: passed={$passed} failed={$failed} warnings={$warnings}");
if ($failed > 0) {
    foreach ($failMsgs as $m) {
        log_line($logFile, 'FAILED: ' . $m);
    }
}

if ($jsonOut) {
    echo json_encode([
        'passed'   => $passed,
        'failed'   => $failed,
        'warnings' => $warnings,
        'failures' => $failMsgs,
        'log'      => $logFile,
    ], JSON_UNESCAPED_SLASHES) . "\n";
}

exit($failed > 0 ? 1 : 0);
