#!/usr/bin/env php
<?php
/**
 * storage-ctl
 *
 * Operator CLI for the FlowOne storage subsystem.
 *
 * Subcommands (Phase 1 baseline; extended per phase):
 *
 *   status                       print the current health status
 *   freeze [--reason="..."]      create freeze flag (I-12)
 *   unfreeze                     remove freeze flag
 *   journal [--tail=N] [--event=X]  read the operation journal
 *   chaos enable|disable|status  toggle chaos mode (synthetic-tenant only)
 *   helper ping                  smoke-check the privileged helper
 *   invariants check             verify INVARIANTS.md <-> Invariants.php <-> chaos scenarios are in sync
 *   key check                    verify HMAC key file mode + readability
 *
 * Exit code 0 on success, 1 on failure.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "storage-ctl must run from CLI\n");
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
use FlowOne\Storage\HelperClient;
use FlowOne\Storage\HmacSigner;
use FlowOne\Storage\StorageHealth;

$argv0 = basename($argv[0]);
array_shift($argv);
$command = array_shift($argv) ?? '';
$opts = parseOpts($argv);

if ($command === '' || $command === '--help' || $command === '-h') {
    printHelp($argv0);
    exit(0);
}

try {
    switch ($command) {
        case 'status':
            exit(cmdStatus($opts));
        case 'freeze':
            exit(cmdFreeze($opts));
        case 'unfreeze':
            exit(cmdUnfreeze($opts));
        case 'journal':
            exit(cmdJournal($opts));
        case 'chaos':
            exit(cmdChaos($opts));
        case 'helper':
            exit(cmdHelper($opts));
        case 'invariants':
            exit(cmdInvariants($opts));
        case 'key':
            exit(cmdKey($opts));
        case 'tenants':
            exit(cmdTenants($opts));
        case 'budget':
            exit(cmdBudget($opts));
        case 'reclaim':
            exit(cmdReclaim($opts));
        case 'backup':
            exit(cmdBackup($opts));
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
{$argv0} - FlowOne storage operator CLI

Usage:
  {$argv0} status [--json]
  {$argv0} freeze [--reason="..."]
  {$argv0} unfreeze
  {$argv0} journal [--tail=N] [--event=NAME] [--json]
  {$argv0} chaos enable|disable|status
  {$argv0} helper ping
  {$argv0} invariants check
  {$argv0} key check
  {$argv0} tenants list             list configured tenants and roots
  {$argv0} tenants probe [--name=N] run a one-shot rw probe (Phase 3)
  {$argv0} tenants ensure           idempotently create tenant directories
  {$argv0} budget [--json] [--no-cache]  show VPS storage budget snapshot (Phase 6a)
  {$argv0} reclaim status [--json]       show reclaim daemon state (Phase 6c)
  {$argv0} reclaim pause [--reason="..."] tell the reclaim daemon to stand down
  {$argv0} reclaim resume                clear the reclaim pause flag
  {$argv0} backup status [--json]        show backup pipeline state (Phase 7)
  {$argv0} backup pause [--reason="..."] tell the backup runner to stand down
  {$argv0} backup resume                 clear the backup pause flag
  {$argv0} backup verify --date=YYYY-MM-DD [--kind=daily|weekly|monthly]
                                          [--mode=light|sample|full]
  {$argv0} backup drill                   run one restore drill now

Global options:
  --verbose, -v       show full stack trace on errors

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

function cmdStatus(array $opts): int
{
    $health = StorageHealth::fromConfig(null);
    $status = $health->getStatus();
    $arr = $status->toArray();
    if (!empty($opts['json'])) {
        echo json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        renderStatusTable($arr);
    }
    // Exit 0 for healthy AND read-only AND frozen (system is usable).
    // Exit 1 for degraded, offline, quarantined, unknown (operator attention).
    return in_array($arr['status'] ?? '', [
        \FlowOne\Storage\HealthState::HEALTHY,
        \FlowOne\Storage\HealthState::READ_ONLY,
        \FlowOne\Storage\HealthState::FROZEN,
    ], true) ? 0 : 1;
}

function renderStatusTable(array $arr): void
{
    $color = match ($arr['status']) {
        'healthy'     => "\033[32m",
        'degraded'    => "\033[33m",
        'read_only'   => "\033[33m",
        'frozen'      => "\033[36m",
        'quarantined' => "\033[35m",
        'offline'     => "\033[31m",
        default       => "\033[37m",
    };
    $reset = "\033[0m";
    echo "Storage status: {$color}{$arr['status']}{$reset}\n";
    echo "  boot_epoch:   {$arr['boot_epoch']}\n";
    echo "  generation:   {$arr['generation']}\n";
    echo "  source:       {$arr['source']}\n";
    echo "  observed_age: " . number_format($arr['observed_age_sec'], 1) . "s\n";
    echo "  is_stale:     " . ($arr['is_stale'] ? 'YES' : 'no') . "\n";
    if (!empty($arr['root_cause'])) {
        echo "  root_cause:   {$arr['root_cause']}\n";
        if (!empty($arr['root_cause_detail'])) {
            echo "                {$arr['root_cause_detail']}\n";
        }
    }
    if (!empty($arr['checks'])) {
        echo "\nChecks:\n";
        foreach ($arr['checks'] as $name => $c) {
            $cstatus = $c['status'] ?? '?';
            $cmsg = $c['message'] ?? '';
            $icon = match ($cstatus) {
                'ok'      => "\033[32m+\033[0m",
                'warning' => "\033[33m!\033[0m",
                'error'   => "\033[31mx\033[0m",
                'skipped' => "\033[37m-\033[0m",
                default   => '?',
            };
            echo "  {$icon} {$name}: {$cmsg}\n";
        }
    }
    renderPhase2Block($arr);
}

function renderPhase2Block(array $arr): void
{
    $phase2 = $arr['phase2'] ?? null;
    if (!is_array($phase2)) {
        return;
    }
    echo "\nPhase 2 state machine:\n";

    $gate = $phase2['stability_gate'] ?? [];
    if (!empty($gate)) {
        $sat = !empty($gate['satisfied']) ? "\033[32msatisfied\033[0m" : "\033[33mwarming\033[0m";
        printf("  stability_gate: %s (%.1fs of %.0fs)\n",
            $sat,
            (float) ($gate['stable_for_sec'] ?? 0),
            (float) ($gate['min_stable_sec'] ?? 0)
        );
    }

    $rb = $phase2['read_breaker'] ?? [];
    if (!empty($rb)) {
        $state = !empty($rb['open']) ? "\033[31mopen\033[0m" : "\033[32mclosed\033[0m";
        printf("  read_breaker:   %s  (p95=%.3fs / thr=%.2fs, err=%.1f%% / thr=%.1f%%, samples=%d)\n",
            $state,
            (float) ($rb['p95_latency_sec'] ?? 0),
            (float) ($rb['p95_threshold_sec'] ?? 0),
            ((float) ($rb['error_rate'] ?? 0)) * 100,
            ((float) ($rb['error_rate_threshold'] ?? 0)) * 100,
            (int) ($rb['sample_count'] ?? 0)
        );
        if (!empty($rb['open']) && !empty($rb['reason'])) {
            echo "                  reason: {$rb['reason']}\n";
        }
    }

    $cb = $phase2['recovery_breaker'] ?? [];
    if (!empty($cb)) {
        if (!empty($cb['permanent'])) {
            $state = "\033[31mPERMANENT (operator clear required)\033[0m";
        } elseif (!empty($cb['quarantined'])) {
            $state = sprintf("\033[33mquarantined for %ds\033[0m", (int) ($cb['quarantined_for_sec'] ?? 0));
        } else {
            $state = "\033[32mclosed\033[0m";
        }
        printf("  recovery_breaker: %s  (attempts=%d/%d, quarantines=%d/%d)\n",
            $state,
            (int) ($cb['cycle_attempts'] ?? 0),
            (int) ($cb['attempts_budget'] ?? 0) + (int) ($cb['cycle_attempts'] ?? 0),
            (int) ($cb['quarantines_in_window'] ?? 0),
            (int) ($cb['permanent_threshold'] ?? 0)
        );
    }
}

function cmdFreeze(array $opts): int
{
    $config = Config::load();
    $flag = rtrim((string) $config['state']['dir'], '/') . '/' . (string) $config['state']['freeze_flag'];
    $reason = is_string($opts['reason'] ?? null) ? $opts['reason'] : 'operator-initiated';
    $payload = json_encode([
        'reason'      => $reason,
        'frozen_by'   => get_current_user(),
        'frozen_at'   => date('c'),
        'frozen_pid'  => getmypid(),
    ], JSON_PRETTY_PRINT) ?: '{}';
    @mkdir(dirname($flag), 0755, true);
    if (@file_put_contents($flag, $payload) === false) {
        fwrite(STDERR, "could not write freeze flag {$flag}\n");
        return 1;
    }
    echo "Frozen. Reads continue; writes/moves/deletes will refuse.\n";
    echo "Reason: {$reason}\n";
    echo "Flag:   {$flag}\n";
    return 0;
}

function cmdUnfreeze(array $opts): int
{
    $config = Config::load();
    $flag = rtrim((string) $config['state']['dir'], '/') . '/' . (string) $config['state']['freeze_flag'];
    if (!is_file($flag)) {
        echo "Already unfrozen.\n";
        return 0;
    }
    if (!@unlink($flag)) {
        fwrite(STDERR, "could not remove freeze flag {$flag}\n");
        return 1;
    }
    echo "Unfrozen.\n";
    return 0;
}

function cmdJournal(array $opts): int
{
    $config = Config::load();
    $path = (string) $config['journal']['path'];
    if (!is_file($path)) {
        fwrite(STDERR, "journal not found: {$path}\n");
        return 1;
    }
    $tail = (int) ($opts['tail'] ?? 50);
    $eventFilter = is_string($opts['event'] ?? null) ? $opts['event'] : null;

    $signer = HmacSigner::fromKeyFile(
        (string) $config['state']['hmac_key_path'],
        (int) $config['state']['hmac_key_mode_max']
    );

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        fwrite(STDERR, "could not read journal\n");
        return 1;
    }
    $lines = array_slice($lines, -$tail * 4); // overshoot to allow filtering

    $shown = 0;
    foreach ($lines as $line) {
        $verified = $signer->verifyJson($line);
        if ($verified === null) {
            if (!empty($opts['verbose'])) {
                fwrite(STDERR, "[invalid signature] " . substr($line, 0, 80) . "...\n");
            }
            continue;
        }
        if ($eventFilter !== null && ($verified['event'] ?? null) !== $eventFilter) {
            continue;
        }
        if (!empty($opts['json'])) {
            echo json_encode($verified) . "\n";
        } else {
            $ts = $verified['ts'] ?? '?';
            $event = $verified['event'] ?? '?';
            $ctx = isset($verified['context']) ? json_encode($verified['context'], JSON_UNESCAPED_SLASHES) : '{}';
            echo "[{$ts}] {$event} {$ctx}\n";
        }
        $shown++;
        if ($shown >= $tail) {
            break;
        }
    }
    return 0;
}

function cmdChaos(array $opts): int
{
    $sub = $opts['positional'][0] ?? '';
    $config = Config::load();
    $flag = rtrim((string) $config['state']['dir'], '/') . '/' . (string) $config['state']['chaos_flag'];

    switch ($sub) {
        case 'enable':
            @mkdir(dirname($flag), 0755, true);
            @file_put_contents($flag, json_encode([
                'enabled_at' => date('c'),
                'enabled_by' => get_current_user(),
            ]));
            echo "Chaos mode ENABLED.\n";
            echo "  Synthetic tenant: chaos-test\n";
            echo "  Flag: {$flag}\n";
            echo "  WARNING: chaos scenarios will now run against the synthetic\n";
            echo "  tenant subtree only. Run `storage-ctl chaos disable` when done.\n";
            return 0;
        case 'disable':
            if (is_file($flag)) {
                @unlink($flag);
            }
            echo "Chaos mode disabled.\n";
            return 0;
        case 'status':
        case '':
            echo 'Chaos mode: ' . (is_file($flag) ? "ENABLED ({$flag})" : 'disabled') . "\n";
            return 0;
        default:
            fwrite(STDERR, "unknown chaos subcommand: {$sub}\n");
            return 1;
    }
}

function cmdHelper(array $opts): int
{
    $sub = $opts['positional'][0] ?? '';
    if ($sub !== 'ping') {
        fwrite(STDERR, "unknown helper subcommand: {$sub} (only 'ping' supported)\n");
        return 1;
    }
    $client = HelperClient::fromConfig();
    if ($client->ping()) {
        echo "helper: OK\n";
        return 0;
    }
    fwrite(STDERR, "helper: NOT REACHABLE\n");
    return 1;
}

function cmdInvariants(array $opts): int
{
    $sub = $opts['positional'][0] ?? '';
    if ($sub !== 'check') {
        fwrite(STDERR, "unknown invariants subcommand: {$sub} (only 'check' supported)\n");
        return 1;
    }
    $sharedRoot = realpath(__DIR__ . '/..');
    $docPath = $sharedRoot . '/docs/INVARIANTS.md';
    $classPath = $sharedRoot . '/src/Storage/Invariants.php';
    $chaosDir = $sharedRoot . '/tests/chaos';

    $docs = file_get_contents($docPath) ?: '';
    $classSource = file_get_contents($classPath) ?: '';

    $docIds = [];
    if (preg_match_all('/### (I-\d+):/', $docs, $m)) {
        $docIds = $m[1];
    }
    $classIds = [];
    if (preg_match_all('/@invariant (I-\d+)/', $classSource, $m)) {
        $classIds = $m[1];
    }

    $scenarios = is_dir($chaosDir) ? glob($chaosDir . '/scenario_*.php') ?: [] : [];
    $mappedInScenarios = [];
    foreach ($scenarios as $sc) {
        $src = file_get_contents($sc) ?: '';
        if (preg_match_all('/\bI-\d+/', $src, $m)) {
            foreach ($m[0] as $id) {
                $mappedInScenarios[$id] = true;
            }
        }
    }

    $missingAsserts = array_values(array_diff($docIds, $classIds));
    $missingScenarios = array_values(array_diff($docIds, array_keys($mappedInScenarios)));

    $hasFailure = false;
    echo "Documented invariants: " . count($docIds) . "\n";
    echo "With runtime assertions: " . (count($docIds) - count($missingAsserts)) . "\n";
    echo "With chaos coverage:     " . (count($docIds) - count($missingScenarios)) . "\n";
    if (!empty($missingAsserts)) {
        echo "MISSING assertions for: " . implode(', ', $missingAsserts) . "\n";
        $hasFailure = true;
    }
    if (!empty($missingScenarios)) {
        echo "MISSING chaos coverage for: " . implode(', ', $missingScenarios) . "\n";
        // Phase 1: not all scenarios exist yet. Print but don't fail in Phase 1.
        echo "  (this becomes a hard failure once Phase 6 chaos suite lands)\n";
    }
    return $hasFailure ? 1 : 0;
}

function cmdTenants(array $opts): int
{
    $sub = $opts['positional'][0] ?? 'list';
    $resolver = \FlowOne\Storage\TenantResolver::fromConfig();

    if ($sub === 'list') {
        $cfg = Config::load();
        echo "Configured tenants:\n";
        foreach ($resolver->names() as $name) {
            $def = $resolver->definition($name);
            $synthetic = !empty($def['is_synthetic']) ? ' [synthetic]' : '';
            try {
                $root = $resolver->rootFor($name);
                $exists = is_dir($root) ? "\033[32mexists\033[0m" : "\033[33mmissing\033[0m";
            } catch (\Throwable $e) {
                $root = '(unresolvable: ' . $e->getMessage() . ')';
                $exists = "\033[31m??\033[0m";
            }
            $ret = $def['retention_days'] ?? null;
            $retStr = $ret === null ? 'no retention' : "{$ret}d retention";
            printf("  %-16s -> %s  [%s, %s]%s\n",
                $name, $root, $exists, $retStr, $synthetic);
        }
        return 0;
    }

    if ($sub === 'ensure') {
        $cfg = Config::load();
        $signer = HmacSigner::fromKeyFile(
            (string) $cfg['state']['hmac_key_path'],
            (int) $cfg['state']['hmac_key_mode_max']
        );
        $journal = new \FlowOne\Storage\OperationJournal(
            (string) $cfg['journal']['path'], $signer, 0
        );
        $bootstrap = \FlowOne\Storage\TenantBootstrap::fromConfig($cfg, $journal);
        $results = $bootstrap->ensureAll();
        if (empty($results)) {
            echo "Mount unreachable — nothing bootstrapped.\n";
            return 1;
        }
        foreach ($results as $r) {
            $tag = $r['created'] ? "\033[32mcreated\033[0m" : ($r['exists'] ? "\033[32mok\033[0m" : "\033[31mfailed\033[0m");
            $writable = $r['writable'] ? 'rw' : 'ro';
            echo "  {$tag} {$r['tenant']} -> {$r['path']} ({$writable})";
            if (!empty($r['error'])) echo " — error: {$r['error']}";
            echo "\n";
        }
        return 0;
    }

    if ($sub === 'probe') {
        $cfg = Config::load();
        $signer = HmacSigner::fromKeyFile(
            (string) $cfg['state']['hmac_key_path'],
            (int) $cfg['state']['hmac_key_mode_max']
        );
        $journal = new \FlowOne\Storage\OperationJournal(
            (string) $cfg['journal']['path'], $signer, 0
        );
        $prober = \FlowOne\Storage\TenantProber::fromConfig($cfg, $journal);
        $name = is_string($opts['name'] ?? null) ? $opts['name'] : null;
        $result = $name !== null ? $prober->probeOne($name) : $prober->probeNext();
        if ($result === null) {
            echo "No active tenants to probe.\n";
            return 1;
        }
        if (!empty($opts['json'])) {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            $icon = match ($result['status']) {
                'ok'      => "\033[32m+\033[0m",
                'error'   => "\033[31mx\033[0m",
                'skipped' => "\033[37m-\033[0m",
                default   => '?',
            };
            printf("  %s %s (%.3fs): %s\n",
                $icon, $result['tenant'], (float) $result['duration_sec'], $result['message']);
        }
        return $result['status'] === 'ok' ? 0 : 1;
    }

    fwrite(STDERR, "unknown tenants subcommand: {$sub} (list|ensure|probe)\n");
    return 1;
}

function cmdBudget(array $opts): int
{
    // storage-ctl deliberately doesn't carry a DB handle (cross-domain
    // dep we've avoided since Phase 5). The OS-layer numbers (df) and
    // watermark decisions still work without it; the logical layer
    // (drive_files SUM) is reported as `available=false`.
    $budget = \FlowOne\Storage\StorageBudget::build(pdo: null);
    $report = $budget->snapshot(bypassCache: !empty($opts['no-cache']));
    $arr = $report->toArray();

    if (!empty($opts['json'])) {
        echo json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return $report->isCritical() ? 1 : 0;
    }

    $color = match ($report->watermark) {
        \FlowOne\Storage\StorageBudgetReport::WM_CRITICAL => "\033[31m",
        \FlowOne\Storage\StorageBudgetReport::WM_HIGH     => "\033[33m",
        \FlowOne\Storage\StorageBudgetReport::WM_WARN     => "\033[33m",
        default                                           => "\033[32m",
    };
    $reset = "\033[0m";

    echo "Storage budget snapshot:\n";
    printf("  watermark:    %s%s%s\n", $color, strtoupper($report->watermark), $reset);
    printf("  computed at:  %s (%.1fms%s)\n",
        date('c', $report->computedAtUnix),
        $report->computeDurationMs,
        $report->fromCache ? ' from cache' : ''
    );
    echo "  vps layer:\n";
    printf("    mount:      %s\n", $report->vpsMountPoint);
    printf("    total:      %s\n", formatBytes($report->vpsTotalBytes));
    printf("    used:       %s (%.1f%%)\n", formatBytes($report->vpsUsedBytes), $report->vpsUsedPct);
    printf("    free:       %s\n", formatBytes($report->vpsFreeBytes));
    if ($report->driveQuotaBytes !== null) {
        echo "  logical layer (drive_files):\n";
        printf("    quota:      %s\n", formatBytes($report->driveQuotaBytes));
        printf("    used:       %s (%.1f%%)\n",
            formatBytes((int) $report->driveUsedBytes),
            (float) $report->driveUsedPct
        );
        printf("    free:       %s\n", formatBytes((int) $report->driveFreeBytes));
        printf("    hot rows:   %d\n", (int) $report->driveHotRows);
    } else {
        echo "  logical layer: not available (storage-ctl has no DB handle)\n";
        echo "                 run inside the email backend for a full report\n";
    }
    if (!empty($report->reasons)) {
        echo "  reasons:\n";
        foreach ($report->reasons as $r) {
            echo "    - {$r}\n";
        }
    }
    return $report->isCritical() ? 1 : 0;
}

function formatBytes(int $bytes): string
{
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
    $exp = (int) floor(log($bytes, 1024));
    $exp = min($exp, count($units) - 1);
    return sprintf('%.2f %s', $bytes / (1024 ** $exp), $units[$exp]);
}

function cmdKey(array $opts): int
{
    $sub = $opts['positional'][0] ?? '';
    if ($sub !== 'check') {
        fwrite(STDERR, "unknown key subcommand: {$sub} (only 'check' supported)\n");
        return 1;
    }
    try {
        HmacSigner::fromKeyFile(
            (string) Config::get('state.hmac_key_path'),
            (int) Config::get('state.hmac_key_mode_max'),
        );
        echo "key: OK\n";
        return 0;
    } catch (\Throwable $e) {
        fwrite(STDERR, "key: FAIL — " . $e->getMessage() . "\n");
        return 1;
    }
}

function cmdReclaim(array $opts): int
{
    $sub = $opts['positional'][0] ?? 'status';
    $config = Config::load();
    $stateDir  = rtrim((string) $config['state']['dir'], '/');
    $pauseFlag = $stateDir . '/' . (string) ($config['tier']['reclaim']['pause_flag'] ?? 'reclaim.paused');
    $pidFile   = $stateDir . '/' . (string) ($config['tier']['reclaim']['pid_file']   ?? 'reclaim-daemon.pid');

    switch ($sub) {
        case 'status':
            return cmdReclaimStatus($opts, $config, $pauseFlag, $pidFile);
        case 'pause':
            return cmdReclaimPause($opts, $pauseFlag);
        case 'resume':
            return cmdReclaimResume($opts, $pauseFlag);
        default:
            fwrite(STDERR, "unknown reclaim subcommand: {$sub} (use status|pause|resume)\n");
            return 1;
    }
}

function cmdReclaimStatus(array $opts, array $config, string $pauseFlag, string $pidFile): int
{
    $signer = HmacSigner::fromKeyFile(
        (string) $config['state']['hmac_key_path'],
        (int) $config['state']['hmac_key_mode_max']
    );
    $store = \FlowOne\Storage\ReclaimDaemonStateStore::fromConfig($config, $signer);
    $state = $store->read();

    $kill   = !($config['phases']['phase6c_reclaim_daemon'] ?? false);
    $paused = is_file($pauseFlag);
    $running = isDaemonRunning($pidFile);

    if (!empty($opts['json'])) {
        echo json_encode([
            'kill_switch_off' => $kill,
            'paused'          => $paused,
            'pid_alive'       => $running,
            'state_file'      => $store->currentPath(),
            'published'       => $state,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return 0;
    }

    echo "Reclaim daemon\n";
    echo "  kill switch:    " . ($kill ? "\033[31mOFF\033[0m (phase6c_reclaim_daemon=false)" : "\033[32mON\033[0m") . "\n";
    echo "  paused (flag):  " . ($paused ? "\033[33mYES\033[0m ({$pauseFlag})" : "no") . "\n";
    echo "  process alive:  " . ($running ? "\033[32mYES\033[0m" : "\033[33mno\033[0m") . "\n";
    if ($state === null) {
        echo "  published state: (none — daemon hasn't published yet)\n";
        return 0;
    }
    $color = match ($state['state'] ?? '') {
        'idle'       => "\033[32m",
        'warming'    => "\033[33m",
        'reclaiming' => "\033[35m",
        'cooldown'   => "\033[36m",
        'paused'     => "\033[33m",
        default      => "\033[37m",
    };
    $reset = "\033[0m";
    echo "  state:          {$color}" . ($state['state'] ?? '?') . "{$reset}\n";
    echo "  last reason:    " . ($state['last_reason'] ?? '-') . "\n";
    if (($state['last_reclaim_at'] ?? 0) > 0) {
        $secAgo = time() - (int) $state['last_reclaim_at'];
        echo "  last reclaim:   " . date('Y-m-d H:i:s', (int) $state['last_reclaim_at']) . " ({$secAgo}s ago)\n";
    } else {
        echo "  last reclaim:   (never)\n";
    }
    $c = $state['counters'] ?? [];
    if (!empty($c)) {
        echo "  counters (since daemon start):\n";
        echo "    cycles:           " . ($c['cycles']        ?? 0) . "\n";
        echo "    tier-down tiered: " . ($c['tier_tiered']   ?? 0) . " (failed " . ($c['tier_failed'] ?? 0) . ")\n";
        echo "    bytes reclaimed:  " . formatBytes((int) ($c['bytes_total'] ?? 0)) . "\n";
        echo "    sweep swept:      " . ($c['sweep_swept']   ?? 0) . " (failed " . ($c['sweep_failed'] ?? 0) . ")\n";
    }
    if (!empty($state['last_cycle_summary']['stopped_by'])) {
        echo "  last cycle:       stopped_by=" . $state['last_cycle_summary']['stopped_by']
           . ", elapsed=" . ($state['last_cycle_summary']['elapsed_ms'] ?? 0) . "ms\n";
    }
    return 0;
}

function cmdReclaimPause(array $opts, string $pauseFlag): int
{
    $reason = (string) ($opts['reason'] ?? 'operator pause');
    $payload = json_encode([
        'paused_at' => date('c'),
        'reason'    => $reason,
        'by'        => getmyuid(),
    ], JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($pauseFlag, $payload) === false) {
        fwrite(STDERR, "failed to write pause flag {$pauseFlag}\n");
        return 1;
    }
    echo "Reclaim daemon paused. Reason: {$reason}\n";
    echo "Daemon will skip reclaim cycles until: storage-ctl reclaim resume\n";
    return 0;
}

function cmdReclaimResume(array $opts, string $pauseFlag): int
{
    if (!is_file($pauseFlag)) {
        echo "Reclaim daemon was not paused (no flag at {$pauseFlag}).\n";
        return 0;
    }
    if (!@unlink($pauseFlag)) {
        fwrite(STDERR, "failed to remove pause flag {$pauseFlag}\n");
        return 1;
    }
    echo "Reclaim daemon resumed.\n";
    return 0;
}

function cmdBackup(array $opts): int
{
    $sub = $opts['positional'][0] ?? 'status';
    $config = Config::load();
    $stateDir  = rtrim((string) $config['state']['dir'], '/');
    $pauseFlag = $stateDir . '/' . (string) ($config['backup']['pause_flag'] ?? 'backup.paused');

    switch ($sub) {
        case 'status':
            return cmdBackupStatus($opts, $config, $pauseFlag);
        case 'pause':
            return cmdBackupPause($opts, $pauseFlag);
        case 'resume':
            return cmdBackupResume($opts, $pauseFlag);
        case 'verify':
            return cmdBackupVerify($opts, $config);
        case 'drill':
            return cmdBackupDrill($config);
        default:
            fwrite(STDERR, "unknown backup subcommand: {$sub} (use status|pause|resume|verify|drill)\n");
            return 1;
    }
}

function cmdBackupStatus(array $opts, array $config, string $pauseFlag): int
{
    $signer = HmacSigner::fromKeyFile(
        (string) $config['state']['hmac_key_path'],
        (int) $config['state']['hmac_key_mode_max']
    );
    $store = \FlowOne\Storage\BackupStateStore::fromConfig($config, $signer);
    $payload = $store->read();
    $kill   = !($config['phases']['phase7_nas_backup'] ?? false);
    $paused = is_file($pauseFlag);

    if (!empty($opts['json'])) {
        echo json_encode([
            'kill_switch_off' => $kill,
            'paused'          => $paused,
            'state_file'      => $store->currentPath(),
            'published'       => $payload,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return 0;
    }

    echo "Backup pipeline\n";
    echo "  kill switch:   " . ($kill ? "\033[31mOFF\033[0m (phase7_nas_backup=false)" : "\033[32mON\033[0m") . "\n";
    echo "  paused (flag): " . ($paused ? "\033[33mYES\033[0m ({$pauseFlag})" : "no") . "\n";
    if ($payload === null) {
        echo "  published:     (none — no run has completed yet)\n";
        return 0;
    }
    if (isset($payload['last_snapshot_ok'])) {
        $s = $payload['last_snapshot_ok'];
        echo "  last snapshot: " . ($s['kind'] ?? '?') . '/' . ($s['date_key'] ?? '?')
           . " files=" . ($s['files_total'] ?? 0)
           . " bytes=" . formatBytes((int) ($s['bytes_total'] ?? 0))
           . " elapsed=" . ($s['elapsed_ms'] ?? 0) . "ms\n";
    }
    if (isset($payload['last_snapshot_failed'])) {
        $f = $payload['last_snapshot_failed'];
        echo "  last failure:  " . ($f['date_key'] ?? '?') . " — " . ($f['reason'] ?? '?') . "\n";
    }
    if (isset($payload['last_verify'])) {
        $v = $payload['last_verify'];
        $tag = $v['ok'] ? "\033[32mOK\033[0m" : "\033[31mFAIL\033[0m";
        echo "  last verify:   {$tag} " . ($v['snapshot']['kind'] ?? '?') . '/' . ($v['snapshot']['date_key'] ?? '?')
           . " mode=" . ($v['mode'] ?? '?')
           . " checked=" . ($v['checked'] ?? 0)
           . " issues=" . ($v['issue_count'] ?? 0) . "\n";
    }
    if (isset($payload['last_drill'])) {
        $d = $payload['last_drill'];
        $tag = $d['ok'] ? "\033[32mOK\033[0m" : "\033[31mFAIL\033[0m";
        echo "  last drill:    {$tag} " . ($d['snapshot']['date_key'] ?? '?')
           . " file=" . ($d['file'] ?? '?') . "\n";
    }
    if (isset($payload['retention'])) {
        echo "  retention:\n";
        foreach ($payload['retention'] as $kind => $r) {
            echo "    {$kind}: " . ($r['count'] ?? 0)
               . " (oldest=" . ($r['oldest'] ?? '-') . ", newest=" . ($r['newest'] ?? '-') . ")\n";
        }
    }
    return 0;
}

function cmdBackupPause(array $opts, string $pauseFlag): int
{
    $reason = (string) ($opts['reason'] ?? 'operator pause');
    $payload = json_encode([
        'paused_at' => date('c'),
        'reason'    => $reason,
        'by'        => getmyuid(),
    ], JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($pauseFlag, $payload) === false) {
        fwrite(STDERR, "failed to write pause flag {$pauseFlag}\n");
        return 1;
    }
    echo "Backup paused. Reason: {$reason}\n";
    echo "Cron will skip snapshot/retain until: storage-ctl backup resume\n";
    return 0;
}

function cmdBackupResume(array $opts, string $pauseFlag): int
{
    if (!is_file($pauseFlag)) {
        echo "Backup was not paused (no flag at {$pauseFlag}).\n";
        return 0;
    }
    if (!@unlink($pauseFlag)) {
        fwrite(STDERR, "failed to remove pause flag {$pauseFlag}\n");
        return 1;
    }
    echo "Backup resumed.\n";
    return 0;
}

function cmdBackupVerify(array $opts, array $config): int
{
    // Delegate to nas-backup.php verify subcommand so behavior + output
    // stay in lockstep. We could call into the verifier directly but
    // duplication is the worse failure mode here.
    $args = ['verify'];
    foreach (['date', 'kind', 'mode', 'sample'] as $k) {
        if (isset($opts[$k]) && $opts[$k] !== true) $args[] = "--{$k}=" . escapeshellarg((string) $opts[$k]);
    }
    $cmd = '/usr/local/lsws/lsphp83/bin/php /var/www/shared/bin/nas-backup.php ' . implode(' ', $args);
    passthru($cmd, $exit);
    return (int) $exit;
}

function cmdBackupDrill(array $config): int
{
    $cmd = '/usr/local/lsws/lsphp83/bin/php /var/www/shared/bin/nas-backup.php drill';
    passthru($cmd, $exit);
    return (int) $exit;
}

/**
 * Cheap "is the daemon process still running?" check. The PID file is
 * written by reclaim-daemon.php at startup and removed at shutdown,
 * but won't be removed if the process is SIGKILL'd. Verify the PID
 * is actually alive via posix_kill(pid, 0).
 */
function isDaemonRunning(string $pidFile): bool
{
    if (!is_file($pidFile)) return false;
    $pid = (int) trim((string) @file_get_contents($pidFile));
    if ($pid <= 0) return false;
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    // Fallback: /proc check on Linux.
    return is_dir("/proc/{$pid}");
}
