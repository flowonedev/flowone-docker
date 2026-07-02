#!/usr/bin/env php
<?php
/**
 * Drive vs optional shared storage lib — regression test.
 *
 * The Docker web image ships /var/www/shared EMPTY (the FlowOne\Storage lib
 * is a native-server deploy). Every use of the lib in the email request path
 * must therefore be guarded by class_exists() — a bare constant dereference
 * like \FlowOne\Storage\TierState::COLD autoloads the class and fatals,
 * which 500'd every drive download/preview/thumbnail on Docker boxes
 * (rows have tier_state since migration 167 defaults it to 'hot').
 *
 * This test is static-analysis style: no DB writes, no external calls,
 * idempotent, safe to run anywhere (native or container).
 *
 * Run:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/drive-shared-lib-optional-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "drive-shared-lib-optional-test must run from CLI\n");
    exit(2);
}

$opts = getopt('', ['help', 'verbose', 'json', 'smoke', 'only::', 'skip-send']);
if (isset($opts['help'])) {
    echo "Usage: drive-shared-lib-optional-test.php [--verbose] [--json] [--smoke] [--only=static,literals]\n";
    echo "Asserts the drive request path never hard-references FlowOne\\Storage.\n";
    echo "  --smoke     only check the source files exist and parse\n";
    echo "  --skip-send accepted for interface parity (no external ops here)\n";
    exit(0);
}
$verbose = isset($opts['verbose']);
$json = isset($opts['json']);
$only = isset($opts['only']) ? array_filter(explode(',', (string)$opts['only'])) : [];

$root = dirname(__DIR__);
$logDir = $root . '/storage/logs';
@mkdir($logDir, 0775, true);
$logFile = $logDir . '/drive-shared-lib-optional-test-' . date('Ymd-His') . '.log';
$results = [];

$run = function (string $group, string $name, callable $fn) use (&$results, $only, $verbose, $logFile) {
    if ($only && !in_array($group, $only, true)) return;
    $t0 = microtime(true);
    try {
        $fn();
        $status = 'PASS'; $err = '';
    } catch (Throwable $e) {
        $status = 'FAIL'; $err = $e->getMessage();
    }
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $line = sprintf('[%s] [%s] %s (%dms)%s', date('H:i:s'), $status, $name, $ms, $err ? ' — ' . $err : '');
    @file_put_contents($logFile, $line . "\n", FILE_APPEND);
    $color = $status === 'PASS' ? "\033[32m" : "\033[31m";
    echo "  {$color}[{$status}]\033[0m {$name} ({$ms}ms)" . ($err && $verbose ? "\n         {$err}" : ($err ? ' — ' . $err : '')) . "\n";
    $results[] = ['group' => $group, 'name' => $name, 'status' => $status, 'error' => $err, 'ms' => $ms];
};

$requestPathFiles = [
    $root . '/src/Services/DriveService.php',
    $root . '/src/Controllers/DriveController.php',
    $root . '/src/Controllers/StorageController.php',
    $root . '/src/Services/NasHealthCheck.php',
];

echo "--- 1. STATIC ---\n";
$run('static', 'request-path sources exist and parse', function () use ($requestPathFiles) {
    foreach ($requestPathFiles as $f) {
        if (!is_file($f)) throw new RuntimeException("missing: {$f}");
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1');
        if (strpos((string)$out, 'No syntax errors') === false) {
            throw new RuntimeException("syntax error in {$f}: " . trim((string)$out));
        }
    }
});

if (!isset($opts['smoke'])) {
    $run('static', 'no bare FlowOne\\Storage constant dereference in request path', function () use ($requestPathFiles) {
        foreach ($requestPathFiles as $f) {
            $src = php_strip_whitespace($f); // drops comments
            // ::CONSTANT on a FlowOne\Storage class (uppercase after ::) would
            // autoload and fatal where the lib isn't deployed. ::class and
            // method calls (lowercase) are lazy/guarded and fine.
            if (preg_match('/FlowOne\\\\Storage\\\\\w+::([A-Z_]+)\b(?!\s*\()/', $src, $m)
                && $m[1] !== 'class') {
                throw new RuntimeException(basename($f) . " dereferences ::{$m[1]} — fatals when shared lib absent");
            }
        }
    });

    echo "--- 2. LITERALS ---\n";
    $run('literals', 'DriveService tier literals match migration 167 enum', function () use ($root) {
        $svc = file_get_contents($root . '/src/Services/DriveService.php');
        foreach (["TIER_COLD = 'cold'", "TIER_RECALLING = 'recalling'"] as $needle) {
            if (strpos($svc, $needle) === false) {
                throw new RuntimeException("DriveService missing literal: {$needle}");
            }
        }
        $mig = file_get_contents($root . '/migrations/167_drive_tier_state.sql');
        foreach (["'cold'", "'recalling'"] as $state) {
            if (strpos($mig, $state) === false) {
                throw new RuntimeException("migration 167 enum missing {$state} — literals drifted");
            }
        }
    });
}

$pass = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$fail = count($results) - $pass;
echo "\nSummary: total=" . count($results) . " passed={$pass} failed={$fail}\n";
foreach ($results as $r) {
    if ($r['status'] === 'FAIL') echo "  FAILED: {$r['name']} — {$r['error']}\n";
}
echo "Log: {$logFile}\n";
if ($json) echo json_encode(['results' => $results, 'passed' => $pass, 'failed' => $fail]) . "\n";
exit($fail > 0 ? 1 : 0);
