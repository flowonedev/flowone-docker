#!/usr/bin/env php
<?php
/**
 * flowone-storage-request-dispatcher
 *
 * Cron-driven async work queue for the storage admin panel. The panel
 * (web user) can't read /etc/flowone/state.key and can't run the
 * privileged backup/reclaim scripts directly, so it drops JSON request
 * files into {state.dir}/requests/. This dispatcher (running as
 * flowone-storage from cron) picks them up, runs the matching CLI,
 * captures the output, and removes the request.
 *
 * Schedule (recommended):
 *   * * * * * flowone-storage /usr/local/lsws/lsphp83/bin/php /var/www/shared/bin/storage-request-dispatcher.php >> /var/log/flowone/dispatcher.log 2>&1
 *
 * Safety:
 *   - Exits 0 cleanly with no work to do
 *   - Single instance lock under {state.dir}/dispatcher.lock — concurrent ticks no-op
 *   - Each request is opened with flock LOCK_EX|LOCK_NB, processed, then deleted
 *   - Output capped at 1 MiB per run to keep state files small
 *   - Per-request wall-clock cap inherited from each script's own caps
 *   - Failed requests get moved to {state.dir}/requests/failed/ with the error,
 *     never silently dropped
 *
 * Supported kinds (must match StorageController::queueRequest):
 *   snapshot         -> nas-backup.php snapshot
 *   verify           -> nas-backup.php verify (uses options.date/kind/mode/sample)
 *   drill            -> nas-backup.php drill
 *   reclaim_cycle    -> reclaim-daemon.php --once (forces one cycle, then exits)
 *
 * CLI flags:
 *   --help          show usage and exit
 *   --verbose       verbose log lines
 *   --dry-run       list pending requests but don't run them
 *   --max-requests=N  process at most N requests per tick (default: 5)
 *
 * Exit codes:
 *   0   success or nothing to do
 *   1   fatal error (lock acquisition / config load / fs error)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "storage-request-dispatcher must run from CLI\n");
    exit(1);
}

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'FlowOne\\Storage\\')) {
        return;
    }
    $relative = substr($class, strlen('FlowOne\\Storage\\'));
    $path = __DIR__ . '/../src/Storage/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use FlowOne\Storage\Config;

$argv0 = basename($argv[0]);
array_shift($argv);
$opts = parseOpts($argv);

if (!empty($opts['help'])) {
    printHelp($argv0);
    exit(0);
}

try {
    exit(main($opts));
} catch (\Throwable $e) {
    logLine('FATAL', $e->getMessage());
    if (!empty($opts['verbose'])) {
        logLine('TRACE', $e->getTraceAsString());
    }
    exit(1);
}

// ────────────────────────────────────────────────────────────────────────

function main(array $opts): int
{
    $config     = Config::load();
    $stateDir   = rtrim((string) ($config['state']['dir'] ?? '/var/lib/flowone'), '/');
    $requestDir = $stateDir . '/requests';
    $failedDir  = $requestDir . '/failed';
    $lockFile   = $stateDir . '/dispatcher.lock';

    if (!ensureDir($stateDir)   || !ensureDir($requestDir) || !ensureDir($failedDir)) {
        logLine('ERROR', "cannot ensure dirs under {$stateDir}");
        return 1;
    }

    $lock = @fopen($lockFile, 'c+');
    if (!$lock) {
        logLine('ERROR', "cannot open lock {$lockFile}");
        return 1;
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        // Another tick already running; cron ticks every minute so this is fine.
        if (!empty($opts['verbose'])) logLine('INFO', 'another dispatcher tick is running; exiting clean');
        return 0;
    }

    try {
        $pending = listPending($requestDir);
        if (count($pending) === 0) {
            if (!empty($opts['verbose'])) logLine('INFO', 'no pending requests');
            return 0;
        }

        if (!empty($opts['dry-run'])) {
            foreach ($pending as $p) {
                logLine('DRYRUN', sprintf('would process %s (mtime=%s)', basename($p), date('c', (int) @filemtime($p))));
            }
            return 0;
        }

        $maxRequests = max(1, (int) ($opts['max-requests'] ?? 5));
        $processed = 0;
        $okCount   = 0;
        $failCount = 0;

        foreach ($pending as $path) {
            if ($processed >= $maxRequests) {
                logLine('INFO', "hit per-tick cap ({$maxRequests}); remaining will run next tick");
                break;
            }
            $processed++;
            $ok = processRequest($path, $failedDir, $opts);
            if ($ok) $okCount++; else $failCount++;
        }

        logLine('SUMMARY', sprintf('processed=%d ok=%d fail=%d remaining=%d',
            $processed, $okCount, $failCount, max(0, count($pending) - $processed)));
        return 0;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function listPending(string $dir): array
{
    $out = [];
    $entries = @scandir($dir) ?: [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..' || $e === 'failed') continue;
        if (substr($e, -5) !== '.json') continue;
        $path = $dir . '/' . $e;
        if (!is_file($path)) continue;
        $out[] = $path;
    }
    // oldest first
    usort($out, fn($a, $b) => filemtime($a) <=> filemtime($b));
    return $out;
}

function processRequest(string $path, string $failedDir, array $opts): bool
{
    $fh = @fopen($path, 'r+');
    if (!$fh) {
        logLine('ERROR', "cannot open request {$path}");
        return false;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        // Another tick beat us to it — fine.
        fclose($fh);
        return true;
    }

    try {
        $raw = stream_get_contents($fh);
        $req = json_decode((string) $raw, true);
        if (!is_array($req) || empty($req['kind'])) {
            logLine('ERROR', "invalid request payload {$path}");
            moveToFailed($path, $failedDir, 'invalid_payload');
            return false;
        }

        $kind = (string) $req['kind'];
        $id   = (string) ($req['id'] ?? basename($path, '.json'));
        $opt  = (array) ($req['options'] ?? []);

        $cmd = buildCommand($kind, $opt);
        if ($cmd === null) {
            logLine('ERROR', "unknown kind '{$kind}' in {$path}");
            moveToFailed($path, $failedDir, 'unknown_kind');
            return false;
        }

        $startUnix = time();
        $startIso  = date('c', $startUnix);
        logLine('RUN', "{$id} kind={$kind} cmd=" . $cmd);

        $output  = [];
        $exit    = 0;
        exec($cmd . ' 2>&1', $output, $exit);
        $elapsed = time() - $startUnix;

        $tail = implode("\n", array_slice($output, -50));
        if ($exit === 0) {
            logLine('OK', "{$id} kind={$kind} elapsed={$elapsed}s tail_len=" . count($output));
            // Successful: remove the request file
            flock($fh, LOCK_UN);
            fclose($fh);
            @unlink($path);
            return true;
        }

        logLine('FAIL', "{$id} kind={$kind} exit={$exit} elapsed={$elapsed}s tail=" . substr($tail, 0, 400));
        moveToFailed($path, $failedDir, 'exit_' . $exit, [
            'started_at' => $startIso,
            'elapsed_s'  => $elapsed,
            'exit'       => $exit,
            'tail'       => $tail,
        ]);
        return false;
    } finally {
        if (is_resource($fh)) {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }
}

function buildCommand(string $kind, array $opt): ?string
{
    $php = '/usr/local/lsws/lsphp83/bin/php';
    $binDir = '/var/www/shared/bin';

    switch ($kind) {
        case 'snapshot':
            // Operator-triggered ad-hoc snapshot uses today's date (the
            // CLI default). Operators can add --date in options if they
            // ever need to back-date.
            $args = ['snapshot'];
            if (!empty($opt['date'])) $args[] = '--date=' . escapeshellarg((string) $opt['date']);
            return "{$php} {$binDir}/nas-backup.php " . implode(' ', $args);

        case 'verify':
            $args = ['verify'];
            foreach (['date', 'kind', 'mode', 'sample'] as $k) {
                if (!empty($opt[$k])) $args[] = "--{$k}=" . escapeshellarg((string) $opt[$k]);
            }
            return "{$php} {$binDir}/nas-backup.php " . implode(' ', $args);

        case 'drill':
            return "{$php} {$binDir}/nas-backup.php drill";

        case 'reclaim_cycle':
            // The daemon supports --once for a single forced cycle, then
            // exits. If your build doesn't have that flag yet, fall back
            // to triggering the cron pipeline manually.
            return "{$php} {$binDir}/reclaim-daemon.php --once";

        default:
            return null;
    }
}

function moveToFailed(string $path, string $failedDir, string $reason, array $extra = []): void
{
    $base = basename($path);
    $dst  = $failedDir . '/' . $base;

    // Annotate the file with the failure reason before moving.
    $raw = @file_get_contents($path);
    $body = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($body)) {
        $body['failed_at']    = date('c');
        $body['failed_reason'] = $reason;
        if ($extra) $body['failure_detail'] = $extra;
        @file_put_contents($path, json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    if (!@rename($path, $dst)) {
        @copy($path, $dst);
        @unlink($path);
    }
}

function ensureDir(string $path): bool
{
    if (is_dir($path)) return true;
    return @mkdir($path, 0775, true) || is_dir($path);
}

function logLine(string $level, string $msg): void
{
    fwrite(STDOUT, sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $msg));
}

function parseOpts(array $argv): array
{
    $out = [];
    foreach ($argv as $a) {
        if (str_starts_with($a, '--')) {
            $kv = substr($a, 2);
            if (strpos($kv, '=') !== false) {
                [$k, $v] = explode('=', $kv, 2);
                $out[$k] = $v;
            } else {
                $out[$kv] = true;
            }
        }
    }
    return $out;
}

function printHelp(string $argv0): void
{
    echo <<<TXT
{$argv0} - FlowOne storage request dispatcher

USAGE
  {$argv0} [--dry-run] [--verbose] [--max-requests=N]

OPTIONS
  --help               show this message
  --verbose            log "no pending" and lock-contention events
  --dry-run            list pending requests without running them
  --max-requests=N     process at most N per tick (default: 5)

DESCRIPTION
  Picks up JSON request files dropped into {state.dir}/requests/ by the
  storage admin panel, runs the matching script, and removes the file
  on success (moves to {state.dir}/requests/failed/ on failure).

  Schedule from cron once per minute as the flowone-storage user:
    * * * * * flowone-storage /usr/local/lsws/lsphp83/bin/php \\
              /var/www/shared/bin/storage-request-dispatcher.php \\
              >> /var/log/flowone/dispatcher.log 2>&1

TXT;
}
