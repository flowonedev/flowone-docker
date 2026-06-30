#!/usr/bin/env php
<?php
/**
 * LiveKit / browser chaos tests (reconnect, dup-tab, kick, waiting room, workshop mode).
 *
 * This PHP wrapper enforces the FlowOne server-side-testing CLI contract (flags,
 * logging, exit codes) and delegates the actual browser work to the Playwright
 * orchestrator under [livekit-chaos/run.js](./livekit-chaos/run.js).
 *
 * Run on a machine with Node 18+ and Chromium-capable Playwright. The suite is
 * gated behind RUN_MEETING_LIVEKIT_CHAOS=1 so it cannot accidentally fire on
 * production cron.
 *
 * Run command:
 *   RUN_MEETING_LIVEKIT_CHAOS=1 \
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/meeting-livekit-chaos-test.php \
 *       --base-url=https://flowone.pro --verbose
 *
 * CLI flags:
 *   --help                Show usage
 *   --verbose             Stream Playwright output to stdout
 *   --skip-send           Pass --skip-send to Node runner (use existing room)
 *   --smoke               Run only @smoke-tagged scenarios
 *   --json                Emit JSON summary
 *   --only=group1,...     Pass-through to runner (e.g. waiting_room_flow,kick_disconnects)
 *   --base-url=URL        Override FLOWONE_BASE_URL
 *   --timeout=SECONDS     Kill the runner if it exceeds the timeout (default 1800)
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';

$opts = getopt('', [
    'help',
    'verbose',
    'skip-send',
    'smoke',
    'json',
    'only:',
    'project:',
    'base-url:',
    'timeout:',
]) ?: [];

$verbose  = isset($opts['verbose']);
$smoke    = isset($opts['smoke']);
$json     = isset($opts['json']);
$skipSend = isset($opts['skip-send']);
$only     = $opts['only']     ?? null;
$project  = $opts['project']  ?? null;
$baseUrl  = $opts['base-url'] ?? null;
$timeout  = isset($opts['timeout']) ? max(60, (int)$opts['timeout']) : 1800;

if (isset($opts['help'])) {
    fwrite(STDOUT,
        "meeting-livekit-chaos-test.php — Phase C2 browser chaos suite\n" .
        "\n" .
        "Required env:\n" .
        "  RUN_MEETING_LIVEKIT_CHAOS=1     Gate flag, must be set to actually run.\n" .
        "  FLOWONE_BASE_URL                Target URL (or pass --base-url=).\n" .
        "  FLOWONE_ADMIN_EMAIL             Admin user for fixture provisioning.\n" .
        "  FLOWONE_ADMIN_PASSWORD          Admin password.\n" .
        "  FLOWONE_CRM_CLIENT_ID           CRM client id to attach portal-call fixtures to.\n" .
        "\n" .
        "Options:\n" .
        "  --help                  Show this help\n" .
        "  --verbose               Stream Playwright output line-by-line\n" .
        "  --skip-send             Re-use FLOWONE_GUEST_TOKEN / FLOWONE_ADMIN_TOKEN instead of provisioning\n" .
        "  --smoke                 Only run @smoke-tagged scenarios\n" .
        "  --json                  Emit JSON summary on stdout (no banner)\n" .
        "  --only=a,b,c            Run only matching scenarios (e.g. waiting_room_flow,kick_disconnects)\n" .
        "  --project=name          Limit to Playwright project (desktop-chromium or mobile-ios)\n" .
        "  --base-url=URL          Override FLOWONE_BASE_URL\n" .
        "  --timeout=SECONDS       Hard kill the runner after N seconds (default 1800)\n" .
        "\n" .
        "Examples:\n" .
        "  RUN_MEETING_LIVEKIT_CHAOS=1 php meeting-livekit-chaos-test.php --smoke --json\n" .
        "  RUN_MEETING_LIVEKIT_CHAOS=1 php meeting-livekit-chaos-test.php --only=waiting_room_flow --verbose\n"
    );
    exit(0);
}

$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/meeting-livekit-chaos-' . gmdate('Ymd-His') . '.log';

$emit = function (string $msg) use ($logFile): void {
    @file_put_contents($logFile, '[' . gmdate('H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
    fwrite(STDOUT, $msg . "\n");
};

$emitJson = function (array $payload) use ($logFile): void {
    @file_put_contents(
        $logFile,
        '[' . gmdate('H:i:s') . '] ' . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND
    );
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n");
};

if (!getenv('RUN_MEETING_LIVEKIT_CHAOS')) {
    if ($smoke || $json) {
        $emitJson(['status' => 'skipped', 'reason' => 'RUN_MEETING_LIVEKIT_CHAOS not set']);
    } else {
        $emit('[SKIP] RUN_MEETING_LIVEKIT_CHAOS not set — Phase C2 suite gated for safety.');
        $emit('Log: ' . $logFile);
    }
    exit(0);
}

$runnerDir = __DIR__ . '/livekit-chaos';
if (!is_dir($runnerDir) || !is_file($runnerDir . '/run.js')) {
    fwrite(STDERR, "Phase C2 runner missing at {$runnerDir}/run.js\n");
    exit(1);
}

// Load .env so the PHP pre-flight sees the same vars the Node runner gets.
// We only inject keys that are not already set in the real environment to
// avoid stomping on intentional overrides from the shell.
$envFile = $runnerDir . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if ($k === '') continue;
        if ((strlen($v) >= 2) && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
        }
        if (getenv($k) === false && empty($_ENV[$k])) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
        }
    }
}

// Pre-flight: Node + Playwright
$preflightFails = [];

$nodeVersion = trim((string)shell_exec('node --version 2>&1'));
if (!preg_match('/^v(\d+)\./', $nodeVersion, $m) || (int)$m[1] < 18) {
    $preflightFails[] = 'Node 18+ is required (found: ' . ($nodeVersion ?: 'not installed') . ')';
}

if (!is_dir($runnerDir . '/node_modules/@playwright/test')) {
    $preflightFails[] = 'Playwright not installed — run: (cd ' . $runnerDir . ' && npm install && npx playwright install chromium)';
}

foreach (['FLOWONE_ADMIN_EMAIL', 'FLOWONE_ADMIN_PASSWORD', 'FLOWONE_CRM_CLIENT_ID'] as $envKey) {
    if (!getenv($envKey) && empty($_ENV[$envKey])) {
        $preflightFails[] = "Missing env: {$envKey}";
    }
}

if ($baseUrl) {
    putenv('FLOWONE_BASE_URL=' . $baseUrl);
    $_ENV['FLOWONE_BASE_URL'] = $baseUrl;
}

if (!getenv('FLOWONE_BASE_URL') && empty($_ENV['FLOWONE_BASE_URL'])) {
    $preflightFails[] = 'Missing env: FLOWONE_BASE_URL (or use --base-url=)';
}

if ($preflightFails) {
    if ($json) {
        $emitJson(['status' => 'preflight-fail', 'issues' => $preflightFails, 'log' => $logFile]);
    } else {
        $emit('Pre-flight FAIL:');
        foreach ($preflightFails as $f) {
            $emit('  - ' . $f);
        }
        $emit('Log: ' . $logFile);
    }
    exit(1);
}

// Build runner args
$runnerArgs = [];
if ($verbose)   $runnerArgs[] = '--verbose';
if ($smoke)     $runnerArgs[] = '--smoke';
if ($json)      $runnerArgs[] = '--json';
if ($skipSend)  $runnerArgs[] = '--skip-send';
if ($only)      $runnerArgs[] = '--only=' . $only;
if ($project)   $runnerArgs[] = '--project=' . $project;
if ($baseUrl)   $runnerArgs[] = '--base-url=' . $baseUrl;

$cmd = 'node ' . escapeshellarg($runnerDir . '/run.js');
foreach ($runnerArgs as $a) {
    $cmd .= ' ' . escapeshellarg($a);
}

$emit('Starting Phase C2 runner: ' . $cmd);
$emit('Timeout: ' . $timeout . 's');

// Run with hard timeout via `timeout` if available; else fall back to proc_open + wall-clock.
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$proc = proc_open($cmd, $descriptors, $pipes, $runnerDir, $_ENV);
if (!is_resource($proc)) {
    $emit('[FAIL] failed to spawn node runner');
    exit(1);
}
fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$stdout = '';
$stderr = '';
$t0 = microtime(true);
$kill = false;

while (true) {
    $status = proc_get_status($proc);
    if (!$status['running']) break;
    if ((microtime(true) - $t0) > $timeout) {
        $kill = true;
        proc_terminate($proc, 9);
        $emit('[FAIL] runner exceeded timeout (' . $timeout . 's); killed');
        break;
    }
    $r = [$pipes[1], $pipes[2]];
    $w = null; $e = null;
    if (stream_select($r, $w, $e, 1, 0) > 0) {
        foreach ($r as $stream) {
            $chunk = fread($stream, 8192);
            if ($chunk === false || $chunk === '') continue;
            if ($stream === $pipes[1]) {
                $stdout .= $chunk;
                if ($verbose) fwrite(STDOUT, $chunk);
            } else {
                $stderr .= $chunk;
                if ($verbose) fwrite(STDERR, $chunk);
            }
        }
    }
    usleep(50_000);
}

$stdout .= (string)stream_get_contents($pipes[1]);
$stderr .= (string)stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($proc);

$elapsedMs = (int)round((microtime(true) - $t0) * 1000);
@file_put_contents($logFile, "\n--- STDOUT ---\n" . $stdout . "\n--- STDERR ---\n" . $stderr . "\n", FILE_APPEND);

if ($json) {
    // Try to parse the runner's own JSON summary; fall back to a wrapper summary.
    $runnerJson = null;
    $lastBrace = strrpos($stdout, '}');
    $firstBrace = strpos($stdout, '{');
    if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
        $maybe = substr($stdout, $firstBrace, $lastBrace - $firstBrace + 1);
        $decoded = json_decode($maybe, true);
        if (is_array($decoded)) $runnerJson = $decoded;
    }
    $summary = $runnerJson ?? [
        'status' => $exit === 0 ? 'pass' : 'fail',
        'exitCode' => $exit,
    ];
    $summary['log'] = $logFile;
    $summary['elapsedMs'] = $elapsedMs;
    if ($kill) $summary['killed'] = true;
    $emitJson($summary);
} else {
    $emit('Exit code: ' . $exit . ' (' . $elapsedMs . 'ms)');
    $emit('Log: ' . $logFile);
    if ($exit !== 0 && !$verbose) {
        $emit('--- stderr (tail) ---');
        $lines = preg_split('/\r?\n/', $stderr);
        foreach (array_slice($lines, -20) as $l) {
            $emit($l);
        }
    }
}

exit($exit === 0 ? 0 : 1);
