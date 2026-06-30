#!/usr/bin/env php
<?php
/**
 * flowone-nas-backup
 *
 * Phase 7 — Backup pipeline CLI. One process does one job at a time:
 *
 *   nas-backup.php snapshot [--date=YYYY-MM-DD] [--dry-run] [--apply]
 *       Create one snapshot. Default: today (UTC). --dry-run lists
 *       what rsync WOULD do; --apply (or omitting --dry-run on a
 *       healthy run) actually writes.
 *
 *   nas-backup.php verify --date=YYYY-MM-DD [--kind=daily|weekly|monthly]
 *                         [--mode=light|sample|full] [--sample=N]
 *       Re-check a snapshot against its manifest.
 *
 *   nas-backup.php retain [--apply|--dry-run] [--date=YYYY-MM-DD]
 *       Apply retention rotation + pruning.
 *
 *   nas-backup.php drill
 *       Run one automated restore drill.
 *
 *   nas-backup.php status [--json]
 *       Print the published state.
 *
 *   nas-backup.php --help
 *       Show this message.
 *
 * Kill switch: refuses to write when phase7_nas_backup=false. Verify,
 * retain, drill, and status work regardless (read-only operations are
 * always safe).
 *
 * Pause flag: touch /var/lib/flowone/backup.paused to halt writes
 *   without restarting cron; remove to resume. Verify/status remain
 *   unaffected.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "nas-backup must run from CLI\n");
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

use FlowOne\Storage\BackupRestoreDriller;
use FlowOne\Storage\BackupRetentionService;
use FlowOne\Storage\BackupRunner;
use FlowOne\Storage\BackupSnapshot;
use FlowOne\Storage\BackupStateStore;
use FlowOne\Storage\BackupVerifier;
use FlowOne\Storage\Config;
use FlowOne\Storage\HmacSigner;
use FlowOne\Storage\OperationJournal;

$argv0 = basename($argv[0]);
array_shift($argv);
$command = array_shift($argv) ?? '';
$opts = parseOpts($argv);

if ($command === '' || $command === '--help' || $command === '-h' || !empty($opts['help'])) {
    printHelp($argv0);
    exit(0);
}

$config  = Config::load();
$signer  = HmacSigner::fromKeyFile(
    (string) $config['state']['hmac_key_path'],
    (int)    $config['state']['hmac_key_mode_max']
);
$journal = new OperationJournal((string) $config['journal']['path'], $signer, 0);
$state   = BackupStateStore::fromConfig($config, $signer);

try {
    switch ($command) {
        case 'snapshot': exit(cmdSnapshot($config, $signer, $journal, $state, $opts));
        case 'verify':   exit(cmdVerify($config, $signer, $journal, $state, $opts));
        case 'retain':   exit(cmdRetain($config, $journal, $state, $opts));
        case 'drill':    exit(cmdDrill($config, $signer, $journal, $state));
        case 'status':   exit(cmdStatus($state, $config, $opts));
        default:
            fwrite(STDERR, "unknown command: {$command}\n");
            printHelp($argv0);
            exit(1);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[error] " . $e->getMessage() . "\n");
    if (!empty($opts['verbose'])) {
        fwrite(STDERR, $e->getTraceAsString() . "\n");
    }
    exit(1);
}

// ────────────────────────────────────────────────────────────────────────

function printHelp(string $argv0): void
{
    echo <<<TXT
{$argv0} - FlowOne NAS backup pipeline (Phase 7)

Usage:
  {$argv0} snapshot [--date=YYYY-MM-DD] [--dry-run] [--apply]
  {$argv0} verify   --date=YYYY-MM-DD [--kind=daily|weekly|monthly]
                                       [--mode=light|sample|full] [--sample=N]
  {$argv0} retain   [--dry-run] [--apply] [--date=YYYY-MM-DD]
  {$argv0} drill
  {$argv0} status   [--json]

Kill switch: phase7_nas_backup
Pause flag:  touch /var/lib/flowone/backup.paused

TXT;
}

function parseOpts(array $argv): array
{
    $opts = ['positional' => []];
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--')) {
            $eq = strpos($arg, '=');
            if ($eq !== false) {
                $opts[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
            } else {
                $opts[substr($arg, 2)] = true;
            }
        } elseif ($arg === '-v') {
            $opts['verbose'] = true;
        } else {
            $opts['positional'][] = $arg;
        }
    }
    return $opts;
}

function killSwitchOn(array $config): bool
{
    return (bool) ($config['phases']['phase7_nas_backup'] ?? false);
}

function pauseFlagPath(array $config): string
{
    return rtrim((string) $config['state']['dir'], '/') . '/'
         . (string) ($config['backup']['pause_flag'] ?? 'backup.paused');
}

function refuseWrite(array $config, string $command): ?string
{
    if (!killSwitchOn($config)) {
        return "phase7_nas_backup=false — refusing to {$command}";
    }
    $pf = pauseFlagPath($config);
    if (is_file($pf)) {
        return "operator pause flag present: {$pf} — refusing to {$command}";
    }
    return null;
}

function cmdSnapshot(array $config, HmacSigner $signer, OperationJournal $journal, BackupStateStore $state, array $opts): int
{
    $dryRun = !empty($opts['dry-run']);
    if (!$dryRun) {
        $refusal = refuseWrite($config, 'snapshot');
        if ($refusal !== null) {
            fwrite(STDERR, "[nas-backup] {$refusal}\n");
            // Exit 0 so cron doesn't loop-alert on a deliberate freeze.
            return 0;
        }
    }
    $runner = BackupRunner::build($config, $signer, $journal);
    $result = $runner->run(
        dateKey: $opts['date'] ?? null,
        dryRun:  $dryRun,
    );
    if (!$dryRun) {
        $state->recordSnapshot($result);
        $state->recordRetentionSummary((string) $config['backup']['destination_root']);
    }
    renderSnapshotResult($result, $dryRun);
    return $result['ok'] ? 0 : 1;
}

function cmdVerify(array $config, HmacSigner $signer, OperationJournal $journal, BackupStateStore $state, array $opts): int
{
    $date = $opts['date'] ?? null;
    if ($date === null) { fwrite(STDERR, "--date=YYYY-MM-DD is required\n"); return 1; }
    $kind = (string) ($opts['kind'] ?? BackupSnapshot::KIND_DAILY);
    $mode = (string) ($opts['mode'] ?? 'light');
    $sampleSize = isset($opts['sample']) ? max(1, (int) $opts['sample']) : null;

    $snapshot = new BackupSnapshot(
        (string) $config['backup']['destination_root'], $kind, (string) $date
    );
    $verifier = BackupVerifier::build($config, $signer, $journal);
    $result = $verifier->verify($snapshot, $mode, $sampleSize);
    $state->recordVerify($result);
    renderVerifyResult($result);
    return $result['ok'] ? 0 : 2;
}

function cmdRetain(array $config, OperationJournal $journal, BackupStateStore $state, array $opts): int
{
    $dryRun = empty($opts['apply']);
    if (!$dryRun) {
        $refusal = refuseWrite($config, 'retain');
        if ($refusal !== null) {
            fwrite(STDERR, "[nas-backup] {$refusal}\n");
            return 0;
        }
    }
    $svc = BackupRetentionService::build($config, $journal);
    $result = $svc->apply($opts['date'] ?? null, $dryRun);
    if (!$dryRun) {
        $state->recordRetentionSummary((string) $config['backup']['destination_root']);
    }
    renderRetentionResult($result);
    return empty($result['errors']) ? 0 : 1;
}

function cmdDrill(array $config, HmacSigner $signer, OperationJournal $journal, BackupStateStore $state): int
{
    // Drill is read-only on the snapshot store + writes only to tmp_dir,
    // so the kill switch + pause flag do NOT block it.
    $drill = BackupRestoreDriller::build($config, $signer, $journal);
    $result = $drill->run();
    $state->recordDrill($result);
    renderDrillResult($result);
    return $result['ok'] ? 0 : 1;
}

function cmdStatus(BackupStateStore $state, array $config, array $opts): int
{
    $payload = $state->read();
    if (!empty($opts['json'])) {
        echo json_encode([
            'kill_switch_off' => !killSwitchOn($config),
            'paused'          => is_file(pauseFlagPath($config)),
            'state_file'      => $state->currentPath(),
            'published'       => $payload,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return 0;
    }
    renderStatus($payload, $config);
    return 0;
}

function renderSnapshotResult(array $r, bool $dryRun): void
{
    $color = $r['ok'] ? "\033[32m" : "\033[31m";
    $reset = "\033[0m";
    $tag   = $dryRun ? '[dry-run]' : ($r['ok'] ? '[ok]' : '[fail]');
    echo "{$color}{$tag}{$reset} snapshot {$r['snapshot']['kind']}/{$r['snapshot']['date_key']}: ";
    echo "files=" . ($r['files_total'] ?? 0)
       . " bytes=" . formatBytesP7((int) ($r['bytes_total'] ?? 0))
       . " elapsed=" . ($r['elapsed_ms'] ?? 0) . "ms";
    if (!$r['ok']) echo " — reason: " . ($r['reason'] ?? '?');
    echo "\n";
}

function renderVerifyResult(array $r): void
{
    $color = $r['ok'] ? "\033[32m" : "\033[31m";
    $reset = "\033[0m";
    $tag   = $r['ok'] ? '[ok]' : '[fail]';
    echo "{$color}{$tag}{$reset} verify {$r['snapshot']['kind']}/{$r['snapshot']['date_key']} "
       . "mode={$r['mode']} checked={$r['checked']} md5_checked={$r['md5_checked']}";
    if (!empty($r['issues'])) {
        echo " issues=" . count($r['issues']);
        if (!empty($r['issues_truncated'])) echo " (+{$r['issues_truncated']} truncated)";
    }
    echo "\n";
    if (!$r['ok']) {
        foreach (array_slice($r['issues'], 0, 10) as $issue) {
            echo "  - [{$issue['kind']}] {$issue['path']}\n";
        }
    }
}

function renderRetentionResult(array $r): void
{
    $tag = $r['dry_run'] ? '[dry-run]' : '[apply]';
    echo "{$tag} retention\n";
    if (!empty($r['promoted'])) {
        echo "  promoted:\n";
        foreach ($r['promoted'] as $p) {
            echo "    {$p['from']['kind']}/{$p['from']['date_key']} -> {$p['to']['kind']}/{$p['to']['date_key']}"
               . ($p['ok'] ? '' : ' [FAILED: ' . $p['reason'] . ']') . "\n";
        }
    }
    if (!empty($r['pruned'])) {
        echo "  pruned:\n";
        foreach ($r['pruned'] as $p) {
            echo "    {$p['target']['kind']}/{$p['target']['date_key']}"
               . ($p['ok'] ? '' : ' [FAILED: ' . $p['reason'] . ']') . "\n";
        }
    }
    echo "  kept:\n";
    foreach ($r['kept'] as $kind => $list) {
        echo "    {$kind}: " . count($list) . " (" . implode(', ', array_slice($list, -3)) . ")\n";
    }
    if (!empty($r['errors'])) {
        echo "  errors:\n";
        foreach ($r['errors'] as $e) echo "    - {$e}\n";
    }
}

function renderDrillResult(array $r): void
{
    $color = $r['ok'] ? "\033[32m" : "\033[31m";
    $reset = "\033[0m";
    $tag = $r['ok'] ? '[ok]' : '[fail]';
    echo "{$color}{$tag}{$reset} drill snapshot=" . ($r['snapshot']['date_key'] ?? '?')
       . " file=" . ($r['file'] ?? '?')
       . " bytes=" . formatBytesP7((int) ($r['bytes'] ?? 0))
       . " elapsed=" . ($r['elapsed_ms'] ?? 0) . "ms";
    if (!$r['ok']) echo " — reason: " . ($r['reason'] ?? '?');
    echo "\n";
}

function renderStatus(?array $payload, array $config): void
{
    $kill = !killSwitchOn($config);
    $paused = is_file(pauseFlagPath($config));
    echo "Backup pipeline\n";
    echo "  kill switch:   " . ($kill ? "\033[31mOFF\033[0m" : "\033[32mON\033[0m") . "\n";
    echo "  paused (flag): " . ($paused ? "\033[33mYES\033[0m" : "no") . "\n";
    if ($payload === null) {
        echo "  published:     (none — no run has completed yet)\n";
        return;
    }
    if (isset($payload['last_snapshot_ok'])) {
        $s = $payload['last_snapshot_ok'];
        echo "  last snapshot: {$s['kind']}/{$s['date_key']} files=" . ($s['files_total'] ?? 0)
           . " bytes=" . formatBytesP7((int) ($s['bytes_total'] ?? 0))
           . " elapsed=" . ($s['elapsed_ms'] ?? 0) . "ms\n";
    }
    if (isset($payload['last_snapshot_failed'])) {
        $f = $payload['last_snapshot_failed'];
        echo "  last failure:  {$f['date_key']} — " . ($f['reason'] ?? '?') . "\n";
    }
    if (isset($payload['last_verify'])) {
        $v = $payload['last_verify'];
        $tag = $v['ok'] ? "\033[32mOK\033[0m" : "\033[31mFAIL\033[0m";
        echo "  last verify:   {$tag} {$v['snapshot']['kind']}/{$v['snapshot']['date_key']} mode={$v['mode']} checked={$v['checked']} issues={$v['issue_count']}\n";
    }
    if (isset($payload['last_drill'])) {
        $d = $payload['last_drill'];
        $tag = $d['ok'] ? "\033[32mOK\033[0m" : "\033[31mFAIL\033[0m";
        echo "  last drill:    {$tag} " . ($d['snapshot']['date_key'] ?? '?') . " file=" . ($d['file'] ?? '?') . "\n";
    }
    if (isset($payload['retention'])) {
        echo "  retention:\n";
        foreach ($payload['retention'] as $kind => $r) {
            echo "    {$kind}: {$r['count']} (oldest={$r['oldest']}, newest={$r['newest']})\n";
        }
    }
}

function formatBytesP7(int $bytes): string
{
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $exp = (int) floor(log($bytes, 1024));
    $exp = min($exp, count($units) - 1);
    return sprintf('%.2f %s', $bytes / (1024 ** $exp), $units[$exp]);
}
