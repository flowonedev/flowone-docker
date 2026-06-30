#!/usr/bin/env php
<?php
/**
 * Folder identity regression telemetry (post-cutover).
 *
 * Pre-cutover this script was the dual-write readiness gate. After the
 * canonical-identity cutover the legacy `folder` columns are gone and
 * the dual-write code paths have been deleted, so the gating counters
 * (legacy_reads, legacy_writes, dual_writes, *_route_hits, backfill_pending,
 * streak, cutover_safe) are all dead and have been removed.
 *
 * What remains is a regression guard:
 *
 *   1. invariant_violations    -- evt=allmail_invariant_violation log lines
 *   2. dual_resolve_samples    -- compare-mode resolve runs (denominator)
 *   3. dual_resolve_ok         -- both lookups agreed
 *   4. dual_resolve_divergences -- identity drift  (THE bug to alert on)
 *   5. dual_resolve_partial    -- one side empty   (recoverable)
 *
 * Compare-mode is still wired through BaseController::getResolvedFolder()
 * on a sampled fraction of requests; if it ever reports a divergence we
 * want to know immediately, even though we no longer have a legacy code
 * path to fall back to.
 *
 * Output is a single line:
 *
 *   [DUALWRITE] regression: invariant_violations=0 compare(samples=N ok=N div=0 partial=0)
 *
 * Recommended schedule (post-cutover): weekly, not nightly.
 *   5 2 * * 0 /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/dual-write-readiness.php
 */

require_once __DIR__ . '/bootstrap.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$config = require __DIR__ . '/../src/config.php';

$logTag = '[DUALWRITE]';
$logFile = __DIR__ . '/../storage/logs/dual-write-readiness.log';
$readinessFile = __DIR__ . '/../storage/logs/dual-write-readiness.json';

if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0755, true);
}

function log_line(string $msg): void
{
    global $logFile;
    $line = date('[Y-m-d H:i:s] ') . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

// ---- invariant_violations: count of evt=allmail_invariant_violation in the
// PHP error log over the last 24h. Same scan as pre-cutover. ----
$violations = 0;
try {
    $errorLog = ini_get('error_log') ?: '/var/www/vps-email/backend/logs/php_errors.log';
    if (is_readable($errorLog)) {
        $cutoff = time() - 86400;
        $fp = @fopen($errorLog, 'r');
        if ($fp) {
            while (!feof($fp)) {
                $line = fgets($fp);
                if ($line === false) break;
                if (!str_contains($line, 'allmail_invariant_violation')) {
                    continue;
                }
                if (preg_match('/^\[([^\]]+)\]/', $line, $m)) {
                    $ts = strtotime($m[1]);
                    if ($ts !== false && $ts >= $cutoff) {
                        $violations++;
                    }
                } else {
                    $violations++;
                }
            }
            fclose($fp);
        }
    }
} catch (\Throwable $e) {
    log_line($logTag . ' log scan error: ' . $e->getMessage());
}

// ---- compare-mode resolve telemetry. The healthy steady state is
//      ok >> 0 with divergences and partials at zero. Any non-zero
//      divergence after cutover is a hard alert. ----
$resolveSamples     = 0;
$resolveOk          = 0;
$resolveDivergences = 0;
$resolvePartial     = 0;
try {
    $redis = new \Webmail\Services\RedisCacheService($config);
    if ($redis->isAvailable()) {
        foreach ([
            'telemetry:dual_write:dual_resolve_samples_24h'      => 'resolveSamples',
            'telemetry:dual_write:dual_resolve_ok_24h'           => 'resolveOk',
            'telemetry:dual_write:dual_resolve_divergences_24h'  => 'resolveDivergences',
            'telemetry:dual_write:dual_resolve_partial_24h'      => 'resolvePartial',
        ] as $redisKey => $varName) {
            $val = $redis->get($redisKey);
            if (is_numeric($val)) {
                ${$varName} = (int) $val;
            } elseif (is_array($val) && isset($val['value']) && is_numeric($val['value'])) {
                ${$varName} = (int) $val['value'];
            }
        }
    }
} catch (\Throwable $e) {
    log_line($logTag . ' Redis read error (resolve compare): ' . $e->getMessage());
}

$state = [
    'updated_at'                => gmdate('c'),
    'invariant_violations'      => $violations,
    'dual_resolve_samples'      => $resolveSamples,
    'dual_resolve_ok'           => $resolveOk,
    'dual_resolve_divergences'  => $resolveDivergences,
    'dual_resolve_partial'      => $resolvePartial,
];
@file_put_contents($readinessFile, json_encode($state, JSON_PRETTY_PRINT) . "\n", LOCK_EX);

log_line(sprintf(
    '%s regression: invariant_violations=%d compare(samples=%d ok=%d div=%d partial=%d)',
    $logTag,
    $violations,
    $resolveSamples,
    $resolveOk,
    $resolveDivergences,
    $resolvePartial
));

exit(0);
