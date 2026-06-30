<?php
/**
 * FlowOne tenant retention sweep (Phase 3).
 *
 * Deletes files in tenants with retention_days set whose mtime is
 * older than the retention window. Designed to run hourly via cron.
 *
 * Safety guards (mandatory; do not loosen):
 *   - Refuses to run unless phase3_tenant_layout = true
 *   - Refuses to operate on any path outside the resolved tenant root
 *     (Invariants::assertPathInsideTenant on every candidate)
 *   - Refuses to delete the tenant root itself
 *   - Refuses to delete files newer than retention_days
 *   - Refuses to delete probe artefacts (.flowone_*)
 *   - --dry-run prints actions without performing them (default mode
 *     when STDIN is a TTY, to make accidental interactive runs safe)
 *   - --tenant=NAME limits the sweep to one tenant
 *
 * CLI:
 *   php tenant-retention.php [--dry-run] [--apply] [--tenant=NAME]
 *                            [--verbose] [--json]
 *
 * Suggested cron:
 *   17 * * * * /usr/local/lsws/lsphp83/bin/php
 *              /var/www/shared/cron/tenant-retention.php --apply
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "tenant-retention must run from CLI\n");
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
use FlowOne\Storage\HmacSigner;
use FlowOne\Storage\Invariants;
use FlowOne\Storage\MonotonicClock;
use FlowOne\Storage\OperationJournal;
use FlowOne\Storage\TenantResolver;

$opts = parseOpts($argv);
if ($opts['help']) {
    echo getUsage();
    exit(0);
}

$config = Config::load();
if (!($config['phases']['phase3_tenant_layout'] ?? false)) {
    fwrite(STDERR, "phase3_tenant_layout is OFF — refusing to run tenant-retention\n");
    exit(2);
}

// Default to dry-run when run interactively and --apply was not given.
if (!$opts['apply'] && !$opts['dry_run']) {
    if (function_exists('posix_isatty') && @posix_isatty(STDIN)) {
        fwrite(STDERR, "[safety] no --apply or --dry-run; defaulting to --dry-run for interactive run\n");
        $opts['dry_run'] = true;
    } else {
        fwrite(STDERR, "must specify --apply or --dry-run\n");
        exit(2);
    }
}

$signer = HmacSigner::fromKeyFile(
    (string) $config['state']['hmac_key_path'],
    (int) $config['state']['hmac_key_mode_max']
);
$journal = new OperationJournal(
    (string) $config['journal']['path'],
    $signer,
    0
);
$invariants = new Invariants($journal, strict: false);
$resolver = TenantResolver::fromConfig($config);

$targets = $opts['tenant'] !== null
    ? [$opts['tenant']]
    : $resolver->activeNames();

$summary = [
    'mode'          => $opts['dry_run'] ? 'dry-run' : 'apply',
    'started_at'    => date('c'),
    'tenants'       => [],
    'total_scanned' => 0,
    'total_deleted' => 0,
    'total_skipped' => 0,
    'errors'        => 0,
];

foreach ($targets as $tenant) {
    if (!$resolver->exists($tenant)) {
        fwrite(STDERR, "unknown tenant: {$tenant}\n");
        $summary['errors']++;
        continue;
    }
    $retention = $resolver->retentionDaysFor($tenant);
    if ($retention === null) {
        if ($opts['verbose']) {
            fwrite(STDOUT, "[skip] {$tenant}: no retention policy\n");
        }
        continue;
    }
    $result = sweepTenant($tenant, $retention, $resolver, $invariants, $journal, $opts);
    $summary['tenants'][$tenant] = $result;
    $summary['total_scanned'] += $result['scanned'];
    $summary['total_deleted'] += $result['deleted'];
    $summary['total_skipped'] += $result['skipped'];
    $summary['errors']        += $result['errors'];
}

$summary['finished_at'] = date('c');
$journal->record('tenant_retention_run', $summary);

if ($opts['json']) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    renderSummary($summary, $opts);
}
exit($summary['errors'] > 0 ? 1 : 0);

// ────────────────────────────────────────────────────────────────────────

function parseOpts(array $argv): array
{
    $opts = [
        'help'    => false,
        'apply'   => false,
        'dry_run' => false,
        'verbose' => false,
        'json'    => false,
        'tenant'  => null,
    ];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') { $opts['help'] = true; continue; }
        if ($arg === '--apply') { $opts['apply'] = true; continue; }
        if ($arg === '--dry-run') { $opts['dry_run'] = true; continue; }
        if ($arg === '--verbose' || $arg === '-v') { $opts['verbose'] = true; continue; }
        if ($arg === '--json') { $opts['json'] = true; continue; }
        if (str_starts_with($arg, '--tenant=')) {
            $opts['tenant'] = substr($arg, strlen('--tenant='));
            continue;
        }
    }
    return $opts;
}

function getUsage(): string
{
    return <<<TXT
FlowOne Tenant Retention Sweep (Phase 3)

Usage:
  tenant-retention.php --apply           perform deletes
  tenant-retention.php --dry-run         print actions without deleting
  tenant-retention.php --tenant=NAME     restrict to one tenant
  tenant-retention.php --verbose         show per-file decisions
  tenant-retention.php --json            output structured result

Exits 0 on clean run, 1 on any error, 2 on misconfiguration.

TXT;
}

/**
 * @return array{tenant:string,retention_days:int,scanned:int,deleted:int,skipped:int,errors:int,deleted_files:list<string>}
 */
function sweepTenant(
    string $tenant,
    int $retentionDays,
    TenantResolver $resolver,
    Invariants $invariants,
    OperationJournal $journal,
    array $opts
): array {
    $start = MonotonicClock::nowNs();
    $result = [
        'tenant'         => $tenant,
        'retention_days' => $retentionDays,
        'scanned'        => 0,
        'deleted'        => 0,
        'skipped'        => 0,
        'errors'         => 0,
        'deleted_files'  => [],
    ];

    try {
        $root = $resolver->rootFor($tenant);
    } catch (\Throwable $e) {
        fwrite(STDERR, "[error] tenant {$tenant}: " . $e->getMessage() . "\n");
        $result['errors']++;
        return $result;
    }
    if (!is_dir($root)) {
        if ($opts['verbose']) {
            fwrite(STDOUT, "[skip] {$tenant}: root {$root} does not exist\n");
        }
        return $result;
    }
    $rootReal = realpath($root) ?: $root;

    $cutoffUnix = time() - ($retentionDays * 86400);

    $rii = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($rootReal, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($rii as $file) {
        $result['scanned']++;
        $path = $file->getPathname();
        $base = basename($path);

        // Refuse to touch tenant root itself.
        if ($path === $rootReal) {
            $result['skipped']++;
            continue;
        }
        // Skip probe artefacts so the sweep never races the prober.
        if (str_starts_with($base, '.flowone_')) {
            $result['skipped']++;
            continue;
        }
        // Defence in depth: every candidate goes through the path
        // safety invariant before any unlink/rmdir.
        if (!$invariants->assertPathInsideTenant($path, $rootReal)) {
            $result['errors']++;
            continue;
        }

        $mtime = @filemtime($path);
        if ($mtime === false) {
            $result['errors']++;
            continue;
        }
        if ($mtime >= $cutoffUnix) {
            $result['skipped']++;
            continue;
        }

        // Empty directories left after file deletions are cleaned too.
        $isDir = $file->isDir();
        $isEmptyDir = $isDir && isEmptyDirectory($path);
        if ($isDir && !$isEmptyDir) {
            $result['skipped']++;
            continue;
        }

        if ($opts['dry_run']) {
            $result['deleted_files'][] = $path;
            $result['deleted']++;
            if ($opts['verbose']) {
                $age = (int) ((time() - $mtime) / 86400);
                fwrite(STDOUT, "[would-delete] {$path} (age {$age}d)\n");
            }
            continue;
        }

        $ok = $isDir ? @rmdir($path) : @unlink($path);
        if (!$ok) {
            $result['errors']++;
            $err = error_get_last()['message'] ?? 'unknown';
            $journal->record('tenant_retention_delete_failed', [
                'tenant' => $tenant, 'path' => $path, 'error' => $err,
            ]);
            continue;
        }
        $result['deleted']++;
        $result['deleted_files'][] = $path;
    }

    $journal->record('tenant_retention_swept', [
        'tenant'         => $tenant,
        'retention_days' => $retentionDays,
        'mode'           => $opts['dry_run'] ? 'dry-run' : 'apply',
        'scanned'        => $result['scanned'],
        'deleted'        => $result['deleted'],
        'skipped'        => $result['skipped'],
        'errors'         => $result['errors'],
        'elapsed_sec'    => round(MonotonicClock::elapsedSec($start), 2),
    ]);
    return $result;
}

function isEmptyDirectory(string $path): bool
{
    $h = @opendir($path);
    if ($h === false) {
        return false;
    }
    while (($e = readdir($h)) !== false) {
        if ($e !== '.' && $e !== '..') {
            closedir($h);
            return false;
        }
    }
    closedir($h);
    return true;
}

function renderSummary(array $s, array $opts): void
{
    echo "Tenant retention sweep: {$s['mode']}\n";
    echo "  started:  {$s['started_at']}\n";
    echo "  finished: {$s['finished_at']}\n";
    foreach ($s['tenants'] as $name => $t) {
        echo "  {$name} (retention {$t['retention_days']}d): " .
             "scanned={$t['scanned']} deleted={$t['deleted']} " .
             "skipped={$t['skipped']} errors={$t['errors']}\n";
    }
    echo "Total: scanned={$s['total_scanned']} deleted={$s['total_deleted']} " .
         "skipped={$s['total_skipped']} errors={$s['errors']}\n";
}
