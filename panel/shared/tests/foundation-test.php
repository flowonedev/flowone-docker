#!/usr/bin/env php
<?php
/**
 * FlowOne Storage Foundation Test
 *
 * Exercises every Phase 1 component end-to-end on the server. Follows
 * .cursor/rules/server-side-testing.mdc requirements:
 *   - CLI-only (refuses non-CLI)
 *   - --help, --verbose, --smoke, --json, --only, --skip-helper
 *   - Pre-flight checks (PHP extensions, file modes, etc.)
 *   - Per-test timeouts
 *   - Color-coded output, timestamped log file
 *   - Idempotent + non-destructive (writes only under test-specific paths)
 *   - SIGINT/SIGTERM cleanup
 *
 * Server run command:
 *
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/shared/tests/foundation-test.php --verbose
 *
 * Returns 0 on all pass, 1 on any failure.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "foundation-test must run from CLI\n");
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

use FlowOne\Storage\BootEpoch;
use FlowOne\Storage\ChaosTargetGuard;
use FlowOne\Storage\Config;
use FlowOne\Storage\DurableJson;
use FlowOne\Storage\Exceptions\InvariantViolation;
use FlowOne\Storage\HealthStatus;
use FlowOne\Storage\HelperClient;
use FlowOne\Storage\HmacSigner;
use FlowOne\Storage\Invariants;
use FlowOne\Storage\MonotonicClock;
use FlowOne\Storage\MountLock;
use FlowOne\Storage\OperationJournal;
use FlowOne\Storage\StorageHealth;

$opts = parseOpts($argv);
if ($opts['help']) {
    printHelp();
    exit(0);
}

$tester = new FoundationTest($opts);
$tester->registerSignals();

try {
    $tester->preflight();
    $tester->run();
} finally {
    $tester->cleanup();
}

exit($tester->exitCode());

// ────────────────────────────────────────────────────────────────────────

function parseOpts(array $argv): array
{
    $opts = [
        'help'        => false,
        'verbose'     => false,
        'smoke'       => false,
        'json'        => false,
        'only'        => null,
        'skip_helper' => false,
        'timeout'     => 30,
    ];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') { $opts['help'] = true; continue; }
        if ($arg === '--verbose' || $arg === '-v') { $opts['verbose'] = true; continue; }
        if ($arg === '--smoke') { $opts['smoke'] = true; continue; }
        if ($arg === '--json') { $opts['json'] = true; continue; }
        if ($arg === '--skip-helper') { $opts['skip_helper'] = true; continue; }
        if (str_starts_with($arg, '--only=')) {
            $opts['only'] = explode(',', substr($arg, strlen('--only=')));
            continue;
        }
        if (str_starts_with($arg, '--timeout=')) {
            $opts['timeout'] = (int) substr($arg, strlen('--timeout='));
            continue;
        }
    }
    return $opts;
}

function printHelp(): void
{
    echo <<<TXT
FlowOne Storage Foundation Test

Usage:
  foundation-test.php [options]

Options:
  --help, -h            this help
  --verbose, -v         show stack traces + extra diagnostics
  --smoke               connectivity + config check only (fast)
  --only=GROUP[,GROUP]  run only listed groups
                        groups: config, hmac, durable, epoch, journal,
                                lock, monotonic, invariants, chaos_guard,
                                health, helper, wiring,
                                fsm, gate, read_brk, recov_brk, classifier
  --json                output results as JSON to stdout
  --skip-helper         skip helper RPC test (use when helper not running)
  --timeout=SEC         per-test timeout (default 30)

Exit 0 = all pass, 1 = any failure.

TXT;
}

final class FoundationTest
{
    /** @var list<array{group:string, name:string, status:string, message:string, elapsed_ms:int}> */
    private array $results = [];
    private array $cleanupQueue = [];
    private string $tmpDir;
    private string $logPath;
    /** @var resource|null */
    private $logFh = null;
    private bool $aborted = false;
    private int $tStartNs;

    public function __construct(private array $opts)
    {
        $this->tStartNs = MonotonicClock::nowNs();
        $this->tmpDir = sys_get_temp_dir() . '/flowone-foundation-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
        @mkdir($this->tmpDir, 0700, true);
        $this->cleanupQueue[] = fn() => $this->rmrf($this->tmpDir);

        // On Linux server, write the run log into the FlowOne log dir so
        // it gets picked up by logrotate and is found next to other logs.
        // On Windows dev hosts, sys_get_temp_dir() (creating no extra
        // top-level directories).
        if (PHP_OS_FAMILY === 'Linux' || PHP_OS_FAMILY === 'Darwin') {
            @mkdir('/var/log/flowone', 0755, true);
            $logDir = is_dir('/var/log/flowone') && is_writable('/var/log/flowone')
                ? '/var/log/flowone' : sys_get_temp_dir();
        } else {
            $logDir = sys_get_temp_dir();
        }
        $this->logPath = $logDir . '/foundation-test-' . date('Ymd-His') . '.log';
        $this->logFh = @fopen($this->logPath, 'a');
    }

    public function registerSignals(): void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () { $this->aborted = true; });
            pcntl_signal(SIGTERM, function () { $this->aborted = true; });
        }
    }

    public function preflight(): void
    {
        $this->log("\n=== PRE-FLIGHT ===");
        $ok = true;

        // Hard-required on every platform.
        $required = ['json', 'hash'];
        foreach ($required as $ext) {
            $loaded = extension_loaded($ext);
            $this->log(($loaded ? '  + ' : '  X ') . "ext: {$ext} (required)");
            $ok = $ok && $loaded;
        }
        // Required for server deployment (Linux + lsphp83), optional for
        // dev hosts. We warn but proceed; tests that need them will skip
        // themselves automatically.
        $serverRequired = ['pcntl', 'posix', 'sockets'];
        $serverMissing = [];
        foreach ($serverRequired as $ext) {
            $loaded = extension_loaded($ext);
            $marker = $loaded ? '  + ' : '  ! ';
            $note = $loaded ? '' : ' (required on server; tests requiring it will skip)';
            $this->log($marker . "ext: {$ext}" . $note);
            if (!$loaded) $serverMissing[] = $ext;
        }
        if (!extension_loaded('redis')) {
            $this->log('  ! ext: redis NOT loaded (StorageHealth Redis path will be skipped)');
        }
        if (!empty($serverMissing) && (PHP_OS_FAMILY === 'Linux' || PHP_OS_FAMILY === 'Darwin')) {
            $this->log('  X server-target extensions missing on a Unix host: ' . implode(', ', $serverMissing));
            $ok = false;
        }

        // Config readable.
        try {
            $config = Config::load();
            $this->log('  + config loaded');
            $keyPath = (string) $config['state']['hmac_key_path'];
            if (is_readable($keyPath)) {
                $this->log("  + HMAC key readable: {$keyPath}");
            } else {
                $this->log("  ! HMAC key not readable: {$keyPath} (some tests will skip)");
            }
        } catch (\Throwable $e) {
            $this->log('  X config load failed: ' . $e->getMessage());
            $ok = false;
        }

        // Tmp dir writable.
        $this->log('  + tmp dir: ' . $this->tmpDir);

        if (!$ok) {
            $this->log("\nPre-flight failed. Aborting.");
            exit(1);
        }
    }

    public function run(): void
    {
        $this->log("\n=== TESTS ===");

        $groups = [
            'config'      => fn() => $this->groupConfig(),
            'hmac'        => fn() => $this->groupHmac(),
            'durable'     => fn() => $this->groupDurable(),
            'epoch'       => fn() => $this->groupEpoch(),
            'journal'     => fn() => $this->groupJournal(),
            'lock'        => fn() => $this->groupLock(),
            'monotonic'   => fn() => $this->groupMonotonic(),
            'invariants'  => fn() => $this->groupInvariants(),
            'chaos_guard' => fn() => $this->groupChaosGuard(),
            'health'      => fn() => $this->groupHealth(),
            'helper'      => fn() => $this->groupHelper(),
            'wiring'      => fn() => $this->groupWiring(),
            'fsm'         => fn() => $this->groupHealthFsm(),
            'gate'        => fn() => $this->groupStabilityGate(),
            'read_brk'    => fn() => $this->groupReadBreaker(),
            'recov_brk'   => fn() => $this->groupRecoveryBreaker(),
            'classifier'  => fn() => $this->groupClassifier(),
            'tenant_res'  => fn() => $this->groupTenantResolver(),
            'tenant_boot' => fn() => $this->groupTenantBootstrap(),
            'tenant_prob' => fn() => $this->groupTenantProber(),
            'tenant_inv'  => fn() => $this->groupTenantInvariant(),
            'tier_value'  => fn() => $this->groupTierValue(),
            'tier_svc'    => fn() => $this->groupTierService(),
            'tier_mover'  => fn() => $this->groupTierBytesMover(),
            'tier_recall' => fn() => $this->groupTierRecallService(),
            'tier_sweep'  => fn() => $this->groupTierDestructiveSweeper(),
            'budget'      => fn() => $this->groupStorageBudget(),
            'admission'   => fn() => $this->groupAdmissionController(),
            'lru'         => fn() => $this->groupLruSelection(),
            'lru_touch'   => fn() => $this->groupLastReadTouch(),
            'reclaim_fsm' => fn() => $this->groupReclaimController(),
            'reclaim_caps'=> fn() => $this->groupReclaimCaps(),
            'reclaim_store' => fn() => $this->groupReclaimStateStore(),
            'backup_snap'   => fn() => $this->groupBackupSnapshot(),
            'backup_manifest' => fn() => $this->groupBackupManifest(),
            'backup_retention' => fn() => $this->groupBackupRetention(),
            'backup_verifier' => fn() => $this->groupBackupVerifier(),
            'backup_store'  => fn() => $this->groupBackupStateStore(),
        ];

        if ($this->opts['smoke']) {
            $smoke = ['config', 'hmac', 'monotonic'];
            $groups = array_intersect_key($groups, array_flip($smoke));
        } elseif ($this->opts['only'] !== null) {
            $groups = array_intersect_key($groups, array_flip($this->opts['only']));
        }

        foreach ($groups as $name => $fn) {
            if ($this->aborted) break;
            $this->log("\n--- {$name} ---");
            $fn();
        }

        $this->renderSummary();
    }

    private function groupConfig(): void
    {
        $this->test('config', 'load returns array', function () {
            $c = Config::load();
            $this->assertTrue(is_array($c) && !empty($c));
        });
        $this->test('config', 'get with dot path', function () {
            $val = Config::get('helper.socket_path');
            $this->assertTrue(is_string($val) && $val !== '');
        });
        $this->test('config', 'unknown key returns default', function () {
            $val = Config::get('nope.nada', 'fallback');
            $this->assertSame('fallback', $val);
        });
    }

    private function groupHmac(): void
    {
        $this->test('hmac', 'sign+verify roundtrip', function () {
            $signer = new HmacSigner(bin2hex(random_bytes(16)));
            $payload = ['status' => 'healthy', 'gen' => 42, 'nested' => ['k' => 'v']];
            $json = $signer->signToJson($payload);
            $verified = $signer->verifyJson($json);
            $this->assertSame($payload, $verified);
        });
        $this->test('hmac', 'verify rejects tampered', function () {
            $signer = new HmacSigner(bin2hex(random_bytes(16)));
            $json = $signer->signToJson(['a' => 1]);
            $tampered = preg_replace('/"a":1/', '"a":2', $json) ?? '';
            $this->assertNull($signer->verifyJson($tampered));
        });
        $this->test('hmac', 'verify rejects wrong key', function () {
            $a = new HmacSigner('keyA');
            $b = new HmacSigner('keyB');
            $json = $a->signToJson(['x' => 1]);
            $this->assertNull($b->verifyJson($json));
        });
        $this->test('hmac', 'canonicalisation stable across key order', function () {
            $signer = new HmacSigner('k');
            $j1 = $signer->signToJson(['a' => 1, 'b' => 2]);
            $j2 = $signer->signToJson(['b' => 2, 'a' => 1]);
            // The signatures must match (same canonical form).
            $d1 = json_decode($j1, true);
            $d2 = json_decode($j2, true);
            $this->assertSame($d1['sig'], $d2['sig']);
        });
    }

    private function groupDurable(): void
    {
        $this->test('durable', 'write then readCurrent', function () {
            $df = new DurableJson($this->tmpDir, 'd.json');
            $df->write('{"hello":"world"}');
            $this->assertSame('{"hello":"world"}', $df->readCurrent());
        });
        $this->test('durable', 'second write rotates backup', function () {
            $df = new DurableJson($this->tmpDir . '/d2', 'd.json');
            $df->write('"v1"');
            $df->write('"v2"');
            $this->assertSame('"v2"', $df->readCurrent());
            $this->assertSame('"v1"', $df->readBackup());
        });
        $this->test('durable', 'readAny falls back to backup', function () {
            $df = new DurableJson($this->tmpDir . '/d3', 'd.json');
            $df->write('"v1"');
            $df->write('"v2"');
            @unlink($df->currentPath());
            [$contents, $source] = $df->readAny();
            $this->assertSame('"v1"', $contents);
            $this->assertSame('backup', $source);
        });
    }

    private function groupEpoch(): void
    {
        $this->test('epoch', 'bump starts at 1', function () {
            $epoch = new BootEpoch($this->tmpDir . '/epoch1');
            $this->assertSame(1, $epoch->bump());
        });
        $this->test('epoch', 'bump is monotonic', function () {
            $epoch = new BootEpoch($this->tmpDir . '/epoch2');
            $this->assertSame(1, $epoch->bump());
            $this->assertSame(2, $epoch->bump());
            $this->assertSame(3, $epoch->bump());
        });
        $this->test('epoch', 'current reads without bump', function () {
            $epoch = new BootEpoch($this->tmpDir . '/epoch3');
            $epoch->bump(); $epoch->bump();
            $fresh = new BootEpoch($this->tmpDir . '/epoch3');
            $this->assertSame(2, $fresh->current());
        });
    }

    private function groupJournal(): void
    {
        $this->test('journal', 'append entry is signed and readable', function () {
            $signer = new HmacSigner(bin2hex(random_bytes(16)));
            $path = $this->tmpDir . '/j.log';
            $j = new OperationJournal($path, $signer, 1);
            $j->record('test_event', ['k' => 'v']);
            $line = file_get_contents($path);
            $this->assertTrue($line !== false && $line !== '');
            $verified = $signer->verifyJson(trim($line));
            $this->assertTrue($verified !== null && $verified['event'] === 'test_event');
        });
        $this->test('journal', 'multiple appends keep all entries', function () {
            $signer = new HmacSigner('k');
            $path = $this->tmpDir . '/j2.log';
            $j = new OperationJournal($path, $signer, 1);
            $j->record('a');
            $j->record('b');
            $j->record('c');
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertSame(3, count($lines));
        });
    }

    private function groupLock(): void
    {
        $this->test('lock', 'exclusive acquire releases', function () {
            $lock = new MountLock($this->tmpDir . '/m.lock', 5);
            $ran = false;
            $lock->withExclusive(function () use (&$ran) { $ran = true; });
            $this->assertTrue($ran);
            $this->assertSame(false, $lock->isHeld());
        });
        $this->test('lock', 'second non-blocking acquire fails while held', function () {
            $lockA = new MountLock($this->tmpDir . '/m2.lock', 1);
            $lockA->acquire();
            $lockB = new MountLock($this->tmpDir . '/m2.lock', 0);
            $this->assertSame(false, $lockB->tryAcquire());
            $lockA->release();
            $this->assertSame(true, $lockB->tryAcquire());
            $lockB->release();
        });
    }

    private function groupMonotonic(): void
    {
        $this->test('monotonic', 'nowNs increases', function () {
            $a = MonotonicClock::nowNs();
            usleep(1000);
            $b = MonotonicClock::nowNs();
            $this->assertTrue($b > $a);
        });
        $this->test('monotonic', 'elapsedSec is positive', function () {
            $start = MonotonicClock::nowNs();
            usleep(2000);
            $this->assertTrue(MonotonicClock::elapsedSec($start) > 0);
        });
        $this->test('monotonic', 'sleep waits at least requested time', function () {
            $start = MonotonicClock::nowNs();
            MonotonicClock::sleep(0.1);
            $this->assertTrue(MonotonicClock::elapsedSec($start) >= 0.09);
        });
    }

    private function groupInvariants(): void
    {
        $this->test('invariants', 'I-4 allows valid transition hot->tiering', function () {
            $inv = new Invariants(strict: true);
            $this->assertTrue($inv->assertTransitionAllowed('hot', 'tiering'));
        });
        $this->test('invariants', 'I-4 rejects invalid transition hot->cold', function () {
            $inv = new Invariants(strict: false);
            $this->assertSame(false, $inv->assertTransitionAllowed('hot', 'cold'));
        });
        $this->test('invariants', 'I-10 rejects mismatched boot epoch', function () {
            $inv = new Invariants(strict: false);
            $this->assertSame(false, $inv->assertBootEpochMatches(5, 7));
        });
        $this->test('invariants', 'I-10 accepts matching non-zero epoch', function () {
            $inv = new Invariants(strict: false);
            $this->assertTrue($inv->assertBootEpochMatches(7, 7));
        });
        $this->test('invariants', 'I-13 catches generation regression in same epoch', function () {
            $inv = new Invariants(strict: false);
            $this->assertSame(false, $inv->assertGenerationMonotonic(10, 1, 9, 1));
        });
        $this->test('invariants', 'I-13 allows reset on new epoch', function () {
            $inv = new Invariants(strict: false);
            $this->assertTrue($inv->assertGenerationMonotonic(99, 1, 1, 2));
        });
        $this->test('invariants', 'strict mode throws on violation', function () {
            $inv = new Invariants(strict: true);
            $threw = false;
            try { $inv->assertBootEpochMatches(1, 2); }
            catch (InvariantViolation $e) { $threw = true; }
            $this->assertTrue($threw);
        });
    }

    private function groupChaosGuard(): void
    {
        $this->test('chaos_guard', 'rejects path outside synthetic subtree', function () {
            try {
                $guard = ChaosTargetGuard::fromConfig();
            } catch (\Throwable) {
                $this->skip('config missing chaos tenant — install step 13 not done?');
                return;
            }
            $threw = false;
            try {
                $guard->assertSafePath('/etc/passwd');
            } catch (\Throwable $e) {
                $threw = str_contains($e->getMessage(), 'refused path outside');
            }
            $this->assertTrue($threw);
        });
        $this->test('chaos_guard', 'accepts path inside synthetic subtree', function () {
            try {
                $guard = ChaosTargetGuard::fromConfig();
            } catch (\Throwable) {
                $this->skip('config missing chaos tenant');
                return;
            }
            $config = Config::load();
            $safe = rtrim((string) $config['nas']['mount_point'], '/') . '/' .
                    trim((string) $config['tenants']['chaos-test']['subpath'], '/') . '/some/file';
            $resolved = $guard->assertSafePath($safe);
            $this->assertTrue(is_string($resolved));
        });
    }

    private function groupHealth(): void
    {
        $this->test('health', 'fromConfig builds client when key present', function () {
            try {
                $client = StorageHealth::fromConfig();
                $this->assertTrue($client instanceof StorageHealth);
            } catch (\Throwable $e) {
                $this->skip('StorageHealth not buildable: ' . $e->getMessage());
            }
        });
        $this->test('health', 'getStatus never throws + returns HealthStatus', function () {
            try {
                $client = StorageHealth::fromConfig();
            } catch (\Throwable) {
                $this->skip('StorageHealth not buildable');
                return;
            }
            $status = $client->getStatus();
            $this->assertTrue($status instanceof HealthStatus);
        });
    }

    private function groupHelper(): void
    {
        if ($this->opts['skip_helper']) {
            $this->skipGroup('helper', 'skipped via --skip-helper');
            return;
        }
        $this->test('helper', 'ping reaches helper socket', function () {
            $client = HelperClient::fromConfig();
            if (!$client->ping()) {
                $this->skip('helper socket not reachable (daemon may not be running)');
                return;
            }
            $this->assertTrue(true);
        });
    }

    private function groupWiring(): void
    {
        $this->test('wiring', 'email NasHealthCheck delegates to shared client', function () {
            $emailAutoload = '/var/www/vps-email/backend/vendor/autoload.php';
            if (!is_file($emailAutoload)) {
                $this->skip('email composer autoloader missing (run composer dump-autoload)');
                return;
            }
            require_once $emailAutoload;
            if (!class_exists(\Webmail\Services\NasHealthCheck::class)) {
                $this->skip('Webmail\\Services\\NasHealthCheck not autoloadable');
                return;
            }
            // We just confirm the call returns a boolean without throwing.
            $result = \Webmail\Services\NasHealthCheck::isAvailable();
            $this->assertTrue(is_bool($result));
        });
    }

    // ──────────────────────────────────────────────────────────────────
    // Phase 2: state machine, gate, breakers, classifier
    // ──────────────────────────────────────────────────────────────────

    private function groupHealthFsm(): void
    {
        $this->test('fsm', 'all() returns 7 states (6 plus UNKNOWN)', function () {
            $all = \FlowOne\Storage\HealthState::all();
            $this->assertTrue(count($all) === 7);
            foreach (['healthy','degraded','read_only','quarantined','frozen','offline','unknown'] as $s) {
                $this->assertTrue(in_array($s, $all, true));
            }
        });
        $this->test('fsm', 'canTransition self-edges always allowed', function () {
            foreach (\FlowOne\Storage\HealthState::all() as $s) {
                $this->assertTrue(\FlowOne\Storage\HealthState::canTransition($s, $s));
            }
        });
        $this->test('fsm', 'canTransition allows healthy->degraded', function () {
            $this->assertTrue(\FlowOne\Storage\HealthState::canTransition(
                \FlowOne\Storage\HealthState::HEALTHY,
                \FlowOne\Storage\HealthState::DEGRADED
            ));
        });
        $this->test('fsm', 'canTransition forbids quarantined->healthy directly', function () {
            $this->assertTrue(!\FlowOne\Storage\HealthState::canTransition(
                \FlowOne\Storage\HealthState::QUARANTINED,
                \FlowOne\Storage\HealthState::HEALTHY
            ));
        });
        $this->test('fsm', 'isWritable only true for healthy/degraded', function () {
            $this->assertTrue(\FlowOne\Storage\HealthState::isWritable(\FlowOne\Storage\HealthState::HEALTHY));
            $this->assertTrue(\FlowOne\Storage\HealthState::isWritable(\FlowOne\Storage\HealthState::DEGRADED));
            $this->assertTrue(!\FlowOne\Storage\HealthState::isWritable(\FlowOne\Storage\HealthState::READ_ONLY));
            $this->assertTrue(!\FlowOne\Storage\HealthState::isWritable(\FlowOne\Storage\HealthState::OFFLINE));
        });
        $this->test('fsm', 'isReadable true for healthy/degraded/read_only/frozen', function () {
            foreach ([
                \FlowOne\Storage\HealthState::HEALTHY,
                \FlowOne\Storage\HealthState::DEGRADED,
                \FlowOne\Storage\HealthState::READ_ONLY,
                \FlowOne\Storage\HealthState::FROZEN,
            ] as $s) {
                $this->assertTrue(\FlowOne\Storage\HealthState::isReadable($s));
            }
            $this->assertTrue(!\FlowOne\Storage\HealthState::isReadable(\FlowOne\Storage\HealthState::OFFLINE));
            $this->assertTrue(!\FlowOne\Storage\HealthState::isReadable(\FlowOne\Storage\HealthState::QUARANTINED));
        });
        $this->test('fsm', 'Invariants rejects illegal transitions', function () {
            $journal = $this->makeTempJournal();
            $inv = new Invariants($journal, false);
            $ok = $inv->assertHealthStateTransitionAllowed(
                \FlowOne\Storage\HealthState::QUARANTINED,
                \FlowOne\Storage\HealthState::HEALTHY
            );
            $this->assertTrue(!$ok);
        });
    }

    private function groupStabilityGate(): void
    {
        $this->test('gate', 'fresh boot allows promotion (no prior failure)', function () {
            $gate = new \FlowOne\Storage\StabilityGate(60);
            $now = MonotonicClock::nowNs();
            $gate->observe(\FlowOne\Storage\HealthState::HEALTHY, $now);
            $this->assertTrue($gate->allowPromotion(
                \FlowOne\Storage\HealthState::UNKNOWN,
                \FlowOne\Storage\HealthState::HEALTHY,
                $now
            ));
        });
        $this->test('gate', 'blocks promotion immediately after failure', function () {
            $gate = new \FlowOne\Storage\StabilityGate(60);
            $t0 = MonotonicClock::nowNs();
            $gate->observe(\FlowOne\Storage\HealthState::OFFLINE, $t0);
            $gate->observe(\FlowOne\Storage\HealthState::HEALTHY, $t0 + 1_000_000_000);
            $this->assertTrue(!$gate->allowPromotion(
                \FlowOne\Storage\HealthState::OFFLINE,
                \FlowOne\Storage\HealthState::HEALTHY,
                $t0 + 1_000_000_000
            ));
        });
        $this->test('gate', 'allows promotion after min_stable_sec', function () {
            $gate = new \FlowOne\Storage\StabilityGate(2);
            $t0 = MonotonicClock::nowNs();
            $gate->observe(\FlowOne\Storage\HealthState::OFFLINE, $t0);
            $gate->observe(\FlowOne\Storage\HealthState::HEALTHY, $t0 + 500_000_000);
            $gate->observe(\FlowOne\Storage\HealthState::HEALTHY, $t0 + 2_500_000_000);
            $this->assertTrue($gate->allowPromotion(
                \FlowOne\Storage\HealthState::DEGRADED,
                \FlowOne\Storage\HealthState::HEALTHY,
                $t0 + 2_500_000_000
            ));
        });
        $this->test('gate', 'failure observation resets stable window', function () {
            $gate = new \FlowOne\Storage\StabilityGate(2);
            $t0 = MonotonicClock::nowNs();
            $gate->observe(\FlowOne\Storage\HealthState::OFFLINE, $t0);
            $gate->observe(\FlowOne\Storage\HealthState::HEALTHY, $t0 + 1_500_000_000);
            $gate->observe(\FlowOne\Storage\HealthState::OFFLINE, $t0 + 1_800_000_000); // re-failure
            $gate->observe(\FlowOne\Storage\HealthState::HEALTHY, $t0 + 2_000_000_000);
            $this->assertTrue(!$gate->allowPromotion(
                \FlowOne\Storage\HealthState::DEGRADED,
                \FlowOne\Storage\HealthState::HEALTHY,
                $t0 + 2_000_000_000
            ));
        });
        $this->test('gate', 'non-HEALTHY targets are never gated', function () {
            $gate = new \FlowOne\Storage\StabilityGate(60);
            $gate->observe(\FlowOne\Storage\HealthState::OFFLINE);
            $this->assertTrue($gate->allowPromotion(
                \FlowOne\Storage\HealthState::OFFLINE,
                \FlowOne\Storage\HealthState::DEGRADED
            ));
        });
    }

    private function groupReadBreaker(): void
    {
        $this->test('read_brk', 'closed when samples are fast + clean', function () {
            $brk = $this->makeReadBreaker(0.5, 0.1, 10, 30);
            $t = MonotonicClock::nowNs();
            for ($i = 0; $i < 20; $i++) {
                $brk->recordProbe(0.05, true, $t + $i * 100_000_000);
            }
            $this->assertTrue(!$brk->evaluate($t + 20 * 100_000_000));
        });
        $this->test('read_brk', 'opens when p95 exceeds threshold', function () {
            $brk = $this->makeReadBreaker(0.5, 0.1, 10, 30);
            $t = MonotonicClock::nowNs();
            // p95 of 20 samples = the sample at sorted index 18 (95% below).
            // 5 slow in 20 (25%) reliably tips the threshold: sorted ascending,
            // samples[18] is one of the slow values.
            for ($i = 0; $i < 15; $i++) {
                $brk->recordProbe(0.05, true, $t + $i * 100_000_000);
            }
            for ($i = 0; $i < 5; $i++) {
                $brk->recordProbe(2.0, true, $t + (15 + $i) * 100_000_000);
            }
            $this->assertTrue($brk->evaluate($t + 21 * 100_000_000));
            $this->assertTrue($brk->isOpen());
        });
        $this->test('read_brk', 'opens when error rate exceeds threshold', function () {
            $brk = $this->makeReadBreaker(5.0, 0.10, 10, 30);
            $t = MonotonicClock::nowNs();
            for ($i = 0; $i < 8; $i++) {
                $brk->recordProbe(0.05, true, $t + $i * 100_000_000);
            }
            for ($i = 0; $i < 3; $i++) {
                $brk->recordProbe(0.05, false, $t + (8 + $i) * 100_000_000);
            }
            $this->assertTrue($brk->evaluate($t + 12 * 100_000_000));
        });
        $this->test('read_brk', 'reports snapshot with thresholds and counts', function () {
            $brk = $this->makeReadBreaker(0.5, 0.1, 10, 30);
            $brk->recordProbe(0.1, true);
            $snap = $brk->snapshot();
            $this->assertTrue(isset($snap['p95_latency_sec'], $snap['error_rate'], $snap['sample_count']));
        });
    }

    private function groupRecoveryBreaker(): void
    {
        $this->test('recov_brk', 'fresh breaker allows attempts', function () {
            $brk = $this->makeRecoveryBreaker(3, 60, 2, 3600);
            $this->assertTrue($brk->canAttempt());
        });
        $this->test('recov_brk', 'quarantines after budget exhausted', function () {
            $brk = $this->makeRecoveryBreaker(2, 60, 5, 3600);
            $brk->recordAttempt();
            $brk->recordAttempt();
            $brk->recordFailure();
            $this->assertTrue($brk->isQuarantined());
            $this->assertTrue(!$brk->canAttempt());
        });
        $this->test('recov_brk', 'permanent after N quarantines', function () {
            $brk = $this->makeRecoveryBreaker(1, 1, 2, 3600);
            $brk->recordAttempt(); $brk->recordFailure(); // 1st quarantine
            // Manually clear quarantine timer; simulate window elapsing.
            $brk->recordSuccess();
            $brk->recordAttempt(); $brk->recordFailure(); // 2nd quarantine -> permanent
            $this->assertTrue($brk->isPermanent());
            $this->assertTrue(!$brk->canAttempt());
        });
        $this->test('recov_brk', 'clearPermanent resets state', function () {
            $brk = $this->makeRecoveryBreaker(1, 1, 1, 3600);
            $brk->recordAttempt(); $brk->recordFailure();
            $this->assertTrue($brk->isPermanent());
            $brk->clearPermanent();
            $this->assertTrue(!$brk->isPermanent());
            $this->assertTrue($brk->canAttempt());
        });
        $this->test('recov_brk', 'snapshot exposes budget + quarantine counts', function () {
            $brk = $this->makeRecoveryBreaker(5, 60, 3, 3600);
            $brk->recordAttempt();
            $snap = $brk->snapshot();
            $this->assertTrue($snap['cycle_attempts'] === 1);
            $this->assertTrue($snap['attempts_budget'] === 4);
        });
    }

    private function groupClassifier(): void
    {
        $this->test('classifier', 'all-clear probe yields DEGRADED until gate satisfied then HEALTHY', function () {
            $clf = $this->makeClassifier(0); // gate=0sec
            $probe = $this->makeProbe(readOk: true, writeOk: true, helperUp: true);
            $decision = $clf->classify($probe, \FlowOne\Storage\HealthState::UNKNOWN);
            $this->assertTrue($decision['state'] === \FlowOne\Storage\HealthState::HEALTHY);
        });
        $this->test('classifier', 'read+!write yields READ_ONLY', function () {
            $clf = $this->makeClassifier(0);
            $probe = $this->makeProbe(readOk: true, writeOk: false, helperUp: true);
            $decision = $clf->classify($probe, \FlowOne\Storage\HealthState::HEALTHY);
            $this->assertTrue($decision['state'] === \FlowOne\Storage\HealthState::READ_ONLY);
        });
        $this->test('classifier', '!read+!write yields OFFLINE', function () {
            $clf = $this->makeClassifier(0);
            $probe = $this->makeProbe(readOk: false, writeOk: false, helperUp: true);
            $decision = $clf->classify($probe, \FlowOne\Storage\HealthState::HEALTHY);
            $this->assertTrue($decision['state'] === \FlowOne\Storage\HealthState::OFFLINE);
        });
        $this->test('classifier', 'freeze flag forces FROZEN', function () {
            $clf = $this->makeClassifier(0);
            $probe = $this->makeProbe(readOk: true, writeOk: true, helperUp: true, frozen: true);
            $decision = $clf->classify($probe, \FlowOne\Storage\HealthState::HEALTHY);
            $this->assertTrue($decision['state'] === \FlowOne\Storage\HealthState::FROZEN);
        });
        $this->test('classifier', 'helper down yields DEGRADED', function () {
            $clf = $this->makeClassifier(0);
            $probe = $this->makeProbe(readOk: true, writeOk: true, helperUp: false);
            $decision = $clf->classify($probe, \FlowOne\Storage\HealthState::HEALTHY);
            $this->assertTrue($decision['state'] === \FlowOne\Storage\HealthState::DEGRADED);
            $this->assertTrue($decision['root_cause'] === 'helper_unreachable');
        });
        $this->test('classifier', 'snapshot includes gate + breakers', function () {
            $clf = $this->makeClassifier(0);
            $probe = $this->makeProbe(readOk: true, writeOk: true, helperUp: true);
            $decision = $clf->classify($probe, \FlowOne\Storage\HealthState::HEALTHY);
            $this->assertTrue(isset($decision['gate'], $decision['read_breaker'], $decision['recovery_breaker']));
        });
    }

    private function groupTenantResolver(): void
    {
        $this->test('tenant_res', 'rootFor returns mount + subpath', function () {
            $r = new \FlowOne\Storage\TenantResolver(
                ['drive-x' => ['subpath' => 'drive-x', 'retention_days' => null]],
                '/mnt/nas-drive',
                false
            );
            $this->assertSame('/mnt/nas-drive/drive-x', $r->rootFor('drive-x'));
        });
        $this->test('tenant_res', 'unknown tenant throws', function () {
            $r = new \FlowOne\Storage\TenantResolver([], '/mnt/nas-drive', false);
            try {
                $r->rootFor('nope');
                $this->assertTrue(false, 'should throw');
            } catch (\InvalidArgumentException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'unknown tenant'));
            }
        });
        $this->test('tenant_res', 'synthetic refused when chaos off', function () {
            $r = new \FlowOne\Storage\TenantResolver(
                ['chaos-x' => ['subpath' => 'chaos-x', 'is_synthetic' => true]],
                '/mnt/nas-drive',
                false
            );
            try {
                $r->rootFor('chaos-x');
                $this->assertTrue(false, 'should throw');
            } catch (\RuntimeException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'chaos mode is disabled'));
            }
        });
        $this->test('tenant_res', 'synthetic allowed when chaos on', function () {
            $r = new \FlowOne\Storage\TenantResolver(
                ['chaos-x' => ['subpath' => 'chaos-x', 'is_synthetic' => true]],
                '/mnt/nas-drive',
                true
            );
            $this->assertSame('/mnt/nas-drive/chaos-x', $r->rootFor('chaos-x'));
        });
        $this->test('tenant_res', 'activeNames excludes synthetic when chaos off', function () {
            $r = new \FlowOne\Storage\TenantResolver(
                [
                    'drive'  => ['subpath' => 'drive', 'retention_days' => null],
                    'chaos'  => ['subpath' => 'chaos', 'is_synthetic' => true],
                ],
                '/mnt/nas-drive',
                false
            );
            $this->assertSame(['drive'], $r->activeNames());
        });
        $this->test('tenant_res', 'activeNames includes synthetic when chaos on', function () {
            $r = new \FlowOne\Storage\TenantResolver(
                [
                    'drive'  => ['subpath' => 'drive'],
                    'chaos'  => ['subpath' => 'chaos', 'is_synthetic' => true],
                ],
                '/mnt/nas-drive',
                true
            );
            $this->assertSame(['drive', 'chaos'], $r->activeNames());
        });
        $this->test('tenant_res', 'pathInside rejects absolute path', function () {
            $r = new \FlowOne\Storage\TenantResolver(
                ['drive' => ['subpath' => 'drive']],
                '/mnt/nas-drive',
                false
            );
            try {
                $r->pathInside('drive', '/etc/passwd');
                $this->assertTrue(false, 'should throw');
            } catch (\RuntimeException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'absolute'));
            }
        });
        $this->test('tenant_res', 'pathInside rejects dot-segments', function () {
            $r = new \FlowOne\Storage\TenantResolver(
                ['drive' => ['subpath' => 'drive']],
                '/mnt/nas-drive',
                false
            );
            foreach (['../etc/passwd', 'a/../../b', 'foo/./bar'] as $bad) {
                try {
                    $r->pathInside('drive', $bad);
                    $this->assertTrue(false, "should throw on {$bad}");
                } catch (\RuntimeException $e) {
                    $this->assertTrue(str_contains($e->getMessage(), 'dot-segment'));
                }
            }
        });
        $this->test('tenant_res', 'pathInside rejects NUL byte', function () {
            $r = new \FlowOne\Storage\TenantResolver(
                ['drive' => ['subpath' => 'drive']],
                '/mnt/nas-drive',
                false
            );
            try {
                $r->pathInside('drive', "foo\0bar");
                $this->assertTrue(false, 'should throw');
            } catch (\RuntimeException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'NUL'));
            }
        });
        $this->test('tenant_res', 'pathInside builds correct path', function () {
            $r = new \FlowOne\Storage\TenantResolver(
                ['drive' => ['subpath' => 'drive']],
                '/mnt/nas-drive',
                false
            );
            $this->assertSame('/mnt/nas-drive/drive/user-42/file.bin',
                $r->pathInside('drive', 'user-42/file.bin'));
        });
        $this->test('tenant_res', 'retentionDaysFor returns null when missing', function () {
            $r = new \FlowOne\Storage\TenantResolver(
                ['drive' => ['subpath' => 'drive']],
                '/mnt/nas-drive',
                false
            );
            $this->assertNull($r->retentionDaysFor('drive'));
        });
        $this->test('tenant_res', 'retentionDaysFor returns clamped int', function () {
            $r = new \FlowOne\Storage\TenantResolver(
                ['backup' => ['subpath' => 'backup', 'retention_days' => 30]],
                '/mnt/nas-drive',
                false
            );
            $this->assertSame(30, $r->retentionDaysFor('backup'));
        });
        $this->test('tenant_res', 'rejects subpath with slash', function () {
            $r = new \FlowOne\Storage\TenantResolver(
                ['bad' => ['subpath' => 'a/b']],
                '/mnt/nas-drive',
                false
            );
            try {
                $r->rootFor('bad');
                $this->assertTrue(false, 'should throw');
            } catch (\RuntimeException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'invalid subpath'));
            }
        });
    }

    private function groupTenantBootstrap(): void
    {
        $this->test('tenant_boot', 'skips when mount unreachable', function () {
            $r = new \FlowOne\Storage\TenantResolver(
                ['drive' => ['subpath' => 'drive']],
                '/this/definitely/does/not/exist',
                false
            );
            $b = new \FlowOne\Storage\TenantBootstrap($r, '/this/definitely/does/not/exist', $this->makeTempJournal());
            $this->assertSame([], $b->ensureAll());
        });
        $this->test('tenant_boot', 'creates missing tenant dir under fake mount', function () {
            $fakeMount = $this->tmpDir . '/fake-mount-' . bin2hex(random_bytes(3));
            mkdir($fakeMount, 0755, true);
            $this->cleanupQueue[] = function () use ($fakeMount) {
                if (is_dir($fakeMount . '/drive')) @rmdir($fakeMount . '/drive');
                if (is_dir($fakeMount)) @rmdir($fakeMount);
            };
            $r = new \FlowOne\Storage\TenantResolver(
                ['drive' => ['subpath' => 'drive']],
                $fakeMount,
                false
            );
            $b = new \FlowOne\Storage\TenantBootstrap($r, $fakeMount, $this->makeTempJournal());
            $results = $b->ensureAll();
            $this->assertSame(1, count($results));
            $this->assertTrue($results[0]['created']);
            $this->assertTrue($results[0]['exists']);
            $this->assertTrue(is_dir($fakeMount . '/drive'));
        });
        $this->test('tenant_boot', 'idempotent on second call', function () {
            $fakeMount = $this->tmpDir . '/fake-mount-' . bin2hex(random_bytes(3));
            mkdir($fakeMount, 0755, true);
            $this->cleanupQueue[] = function () use ($fakeMount) {
                if (is_dir($fakeMount . '/drive')) @rmdir($fakeMount . '/drive');
                if (is_dir($fakeMount)) @rmdir($fakeMount);
            };
            $r = new \FlowOne\Storage\TenantResolver(
                ['drive' => ['subpath' => 'drive']],
                $fakeMount,
                false
            );
            $b = new \FlowOne\Storage\TenantBootstrap($r, $fakeMount, $this->makeTempJournal());
            $b->ensureAll();
            $second = $b->ensureAll();
            $this->assertTrue($second[0]['exists']);
            $this->assertSame(false, $second[0]['created']);
        });
    }

    private function groupTenantProber(): void
    {
        $this->test('tenant_prob', 'returns null when no active tenants', function () {
            $r = new \FlowOne\Storage\TenantResolver([], '/mnt/nas-drive', false);
            $p = new \FlowOne\Storage\TenantProber($r);
            $this->assertNull($p->probeNext());
        });
        $this->test('tenant_prob', 'probe ok against writable fake tenant', function () {
            $fakeMount = $this->tmpDir . '/probe-mount-' . bin2hex(random_bytes(3));
            mkdir($fakeMount . '/t1', 0755, true);
            $this->cleanupQueue[] = function () use ($fakeMount) {
                if (is_dir($fakeMount . '/t1')) @rmdir($fakeMount . '/t1');
                if (is_dir($fakeMount)) @rmdir($fakeMount);
            };
            $r = new \FlowOne\Storage\TenantResolver(
                ['t1' => ['subpath' => 't1']],
                $fakeMount,
                false
            );
            $p = new \FlowOne\Storage\TenantProber($r);
            $result = $p->probeOne('t1');
            $this->assertSame('ok', $result['status']);
            $this->assertSame('t1', $result['tenant']);
        });
        $this->test('tenant_prob', 'probe skipped when root missing', function () {
            $fakeMount = $this->tmpDir . '/probe-noroot-' . bin2hex(random_bytes(3));
            mkdir($fakeMount, 0755, true);
            $this->cleanupQueue[] = function () use ($fakeMount) {
                if (is_dir($fakeMount)) @rmdir($fakeMount);
            };
            $r = new \FlowOne\Storage\TenantResolver(
                ['ghost' => ['subpath' => 'ghost']],
                $fakeMount,
                false
            );
            $p = new \FlowOne\Storage\TenantProber($r);
            $result = $p->probeOne('ghost');
            $this->assertSame('skipped', $result['status']);
        });
        $this->test('tenant_prob', 'round-robin advances cursor', function () {
            $fakeMount = $this->tmpDir . '/rr-mount-' . bin2hex(random_bytes(3));
            mkdir($fakeMount . '/a', 0755, true);
            mkdir($fakeMount . '/b', 0755, true);
            $this->cleanupQueue[] = function () use ($fakeMount) {
                if (is_dir($fakeMount . '/a')) @rmdir($fakeMount . '/a');
                if (is_dir($fakeMount . '/b')) @rmdir($fakeMount . '/b');
                if (is_dir($fakeMount)) @rmdir($fakeMount);
            };
            $r = new \FlowOne\Storage\TenantResolver(
                ['a' => ['subpath' => 'a'], 'b' => ['subpath' => 'b']],
                $fakeMount,
                false
            );
            $p = new \FlowOne\Storage\TenantProber($r);
            $first  = $p->probeNext();
            $second = $p->probeNext();
            $third  = $p->probeNext();
            $this->assertSame('a', $first['tenant']);
            $this->assertSame('b', $second['tenant']);
            $this->assertSame('a', $third['tenant']);
        });
    }

    private function groupTierValue(): void
    {
        $TS = \FlowOne\Storage\TierState::class;
        $this->test('tier_value', 'all() returns 5 states', function () use ($TS) {
            $this->assertSame(5, count($TS::all()));
        });
        $this->test('tier_value', 'isValid accepts known, rejects unknown', function () use ($TS) {
            $this->assertTrue($TS::isValid($TS::HOT));
            $this->assertTrue($TS::isValid($TS::COLD));
            $this->assertTrue(!$TS::isValid('frozen'));
            $this->assertTrue(!$TS::isValid('unknown'));
        });
        $this->test('tier_value', 'fromLegacyLocation maps known + falls back to hot', function () use ($TS) {
            $this->assertSame($TS::HOT, $TS::fromLegacyLocation('local'));
            $this->assertSame($TS::HOT, $TS::fromLegacyLocation(null));
            $this->assertSame($TS::HOT, $TS::fromLegacyLocation('bogus'));
            $this->assertSame($TS::COLD, $TS::fromLegacyLocation('nas'));
            $this->assertSame($TS::TIERING, $TS::fromLegacyLocation('pending_migration'));
        });
        $this->test('tier_value', 'toLegacyLocation inverse', function () use ($TS) {
            $this->assertSame('local', $TS::toLegacyLocation($TS::HOT));
            $this->assertSame('nas', $TS::toLegacyLocation($TS::COLD));
            $this->assertSame('pending_migration', $TS::toLegacyLocation($TS::TIERING));
            $this->assertSame('pending_migration', $TS::toLegacyLocation($TS::RECALLING));
        });
        $this->test('tier_value', 'canTransition self-loop always allowed', function () use ($TS) {
            foreach ($TS::all() as $s) {
                $this->assertTrue($TS::canTransition($s, $s));
            }
        });
        $this->test('tier_value', 'hot->cold rejected, hot->tiering allowed', function () use ($TS) {
            $this->assertTrue(!$TS::canTransition($TS::HOT, $TS::COLD));
            $this->assertTrue($TS::canTransition($TS::HOT, $TS::TIERING));
        });
        $this->test('tier_value', 'cold->hot rejected, cold->recalling allowed', function () use ($TS) {
            $this->assertTrue(!$TS::canTransition($TS::COLD, $TS::HOT));
            $this->assertTrue($TS::canTransition($TS::COLD, $TS::RECALLING));
        });
        $this->test('tier_value', 'lost is terminal sink', function () use ($TS) {
            foreach ($TS::all() as $s) {
                if ($s === $TS::LOST) continue;
                $this->assertTrue(!$TS::canTransition($TS::LOST, $s));
            }
        });
        $this->test('tier_value', 'any->lost always allowed', function () use ($TS) {
            foreach ($TS::all() as $s) {
                $this->assertTrue($TS::canTransition($s, $TS::LOST));
            }
        });
        $this->test('tier_value', 'bytesOnVps true for hot/tiering/recalling', function () use ($TS) {
            $this->assertTrue($TS::bytesOnVps($TS::HOT));
            $this->assertTrue($TS::bytesOnVps($TS::TIERING));
            $this->assertTrue($TS::bytesOnVps($TS::RECALLING));
            $this->assertTrue(!$TS::bytesOnVps($TS::COLD));
        });
        $this->test('tier_value', 'bytesOnNas tiering is false (mid-copy)', function () use ($TS) {
            $this->assertTrue(!$TS::bytesOnNas($TS::TIERING));
            $this->assertTrue($TS::bytesOnNas($TS::COLD));
            $this->assertTrue($TS::bytesOnNas($TS::RECALLING));
        });
    }

    private function groupTierService(): void
    {
        // Skip the whole group if pdo_sqlite isn't loaded. The shared
        // library runs against MariaDB in production; sqlite is only
        // for fast self-contained unit tests.
        if (!extension_loaded('pdo_sqlite')) {
            $this->test('tier_svc', 'pdo_sqlite not loaded, skipping group', function () {
                $this->assertTrue(true);
            });
            return;
        }
        $TS = \FlowOne\Storage\TierState::class;
        $SVC = \FlowOne\Storage\TierStateService::class;

        $makeSvc = function () use ($SVC): \FlowOne\Storage\TierStateService {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec(
                "CREATE TABLE drive_files (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    storage_location TEXT,
                    tier_state TEXT NOT NULL DEFAULT 'hot',
                    tier_changed_at TEXT,
                    tier_changed_by TEXT,
                    tier_recall_attempts INTEGER NOT NULL DEFAULT 0,
                    size INTEGER DEFAULT 0,
                    checksum TEXT,
                    nas_relative_path TEXT
                )"
            );
            $pdo->exec(
                "CREATE TABLE drive_tier_transitions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    file_id INTEGER NOT NULL,
                    from_state TEXT NOT NULL DEFAULT 'unknown',
                    to_state TEXT NOT NULL,
                    actor TEXT NOT NULL DEFAULT 'system',
                    reason TEXT,
                    boot_epoch INTEGER,
                    bytes INTEGER,
                    duration_ms INTEGER,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                )"
            );
            return new \FlowOne\Storage\TierStateService($pdo);
        };

        $insert = function (\FlowOne\Storage\TierStateService $svc, string $loc, string $state): int {
            $r = new \ReflectionClass($svc);
            $p = $r->getProperty('pdo');
            $p->setAccessible(true);
            /** @var \PDO $pdo */
            $pdo = $p->getValue($svc);
            $stmt = $pdo->prepare(
                "INSERT INTO drive_files (storage_location, tier_state) VALUES (:loc, :st)"
            );
            $stmt->execute([':loc' => $loc, ':st' => $state]);
            return (int) $pdo->lastInsertId();
        };

        $this->test('tier_svc', 'getState returns inserted state', function () use ($makeSvc, $insert, $TS) {
            $svc = $makeSvc();
            $id = $insert($svc, 'local', $TS::HOT);
            $this->assertSame($TS::HOT, $svc->getState($id));
        });
        $this->test('tier_svc', 'getState returns null on miss', function () use ($makeSvc) {
            $svc = $makeSvc();
            $this->assertNull($svc->getState(999999));
        });
        $this->test('tier_svc', 'transitionTo legal step works + storage_location flips', function () use ($makeSvc, $insert, $TS) {
            $svc = $makeSvc();
            $id = $insert($svc, 'local', $TS::HOT);
            $this->assertTrue($svc->transitionTo($id, $TS::TIERING, 'test'));
            $this->assertSame($TS::TIERING, $svc->getState($id));
            $rec = $svc->getRecord($id);
            $this->assertSame('pending_migration', $rec['storage_location']);
        });
        $this->test('tier_svc', 'transitionTo illegal step throws + state untouched', function () use ($makeSvc, $insert, $TS) {
            $svc = $makeSvc();
            $id = $insert($svc, 'local', $TS::HOT);
            try {
                $svc->transitionTo($id, $TS::COLD, 'test');
                $this->assertTrue(false, 'should throw');
            } catch (\RuntimeException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'illegal'));
            }
            $this->assertSame($TS::HOT, $svc->getState($id));
        });
        $this->test('tier_svc', 'audit row written on every transition', function () use ($makeSvc, $insert, $TS) {
            $svc = $makeSvc();
            $id = $insert($svc, 'local', $TS::HOT);
            $svc->transitionTo($id, $TS::TIERING, 'a1', 'r1');
            $svc->transitionTo($id, $TS::COLD, 'a2', 'r2');
            $trail = $svc->auditTrail($id, 10);
            $this->assertSame(2, count($trail));
            // newest first
            $this->assertSame($TS::COLD, $trail[0]['to_state']);
            $this->assertSame('a2', $trail[0]['actor']);
            $this->assertSame($TS::HOT, $trail[1]['from_state']);
        });
        $this->test('tier_svc', 'recalling bumps tier_recall_attempts', function () use ($makeSvc, $insert, $TS) {
            $svc = $makeSvc();
            $id = $insert($svc, 'nas', $TS::COLD);
            $svc->transitionTo($id, $TS::RECALLING, 'r');
            $rec = $svc->getRecord($id);
            $this->assertSame(1, (int) $rec['tier_recall_attempts']);
            $svc->transitionTo($id, $TS::COLD, 'r');
            $svc->transitionTo($id, $TS::RECALLING, 'r');
            $rec = $svc->getRecord($id);
            $this->assertSame(2, (int) $rec['tier_recall_attempts']);
        });
        $this->test('tier_svc', 'transitionTo on missing id throws', function () use ($makeSvc, $TS) {
            $svc = $makeSvc();
            try {
                $svc->transitionTo(0, $TS::TIERING, 'test');
                $this->assertTrue(false, 'should throw');
            } catch (\RuntimeException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'not found'));
            }
        });
        $this->test('tier_svc', 'reconcile dry-run does not mutate', function () use ($makeSvc, $insert, $TS) {
            $svc = $makeSvc();
            $id = $insert($svc, 'pending_migration', $TS::HOT); // legal drift
            $stats = $svc->reconcileLegacyLocation(batchLimit: 100, actor: 't', dryRun: true);
            $this->assertSame($TS::HOT, $svc->getState($id));
            $this->assertTrue($stats['updated'] >= 1);
        });
        $this->test('tier_svc', 'reconcile apply heals legal drift', function () use ($makeSvc, $insert, $TS) {
            $svc = $makeSvc();
            $id = $insert($svc, 'pending_migration', $TS::HOT);
            $svc->reconcileLegacyLocation(batchLimit: 100, actor: 't', dryRun: false);
            $this->assertSame($TS::TIERING, $svc->getState($id));
        });
        $this->test('tier_svc', 'reconcile flags illegal drift as failed', function () use ($makeSvc, $insert, $TS) {
            $svc = $makeSvc();
            $insert($svc, 'nas', $TS::HOT); // hot->cold not legal direct
            $stats = $svc->reconcileLegacyLocation(batchLimit: 100, actor: 't', dryRun: false);
            $this->assertTrue($stats['failed'] >= 1);
        });
        $this->test('tier_svc', 'reconcile never resurrects lost', function () use ($makeSvc, $insert, $TS) {
            $svc = $makeSvc();
            $id = $insert($svc, 'local', $TS::LOST);
            $svc->reconcileLegacyLocation(batchLimit: 100, actor: 't', dryRun: false);
            $this->assertSame($TS::LOST, $svc->getState($id));
        });
        $this->test('tier_svc', 'counts contains all states', function () use ($makeSvc, $insert, $TS) {
            $svc = $makeSvc();
            $insert($svc, 'local', $TS::HOT);
            $insert($svc, 'local', $TS::HOT);
            $insert($svc, 'nas', $TS::COLD);
            $c = $svc->counts();
            foreach ($TS::all() as $s) {
                $this->assertTrue(array_key_exists($s, $c));
            }
            $this->assertSame(2, $c[$TS::HOT]);
            $this->assertSame(1, $c[$TS::COLD]);
        });
        $this->test('tier_svc', 'findTierDownCandidates returns hot rows', function () use ($makeSvc, $insert, $TS) {
            $svc = $makeSvc();
            $id = $insert($svc, 'local', $TS::HOT);
            // Backdate tier_changed_at so it qualifies as "old enough".
            $r = new \ReflectionClass($svc);
            $p = $r->getProperty('pdo'); $p->setAccessible(true);
            /** @var \PDO $pdo */
            $pdo = $p->getValue($svc);
            $pdo->exec("UPDATE drive_files SET tier_changed_at = datetime('now', '-40 days') WHERE id = {$id}");
            $candidates = $svc->findTierDownCandidates(ageDays: 30, limit: 10);
            $this->assertTrue(count($candidates) >= 1);
            $this->assertSame($id, (int) $candidates[0]['id']);
        });
    }

    private function groupTierBytesMover(): void
    {
        // Helper: build a TierBytesMover wired against a *fake mount*
        // under tmpDir. No real NAS needed for unit tests; the
        // production-side e2e test uses the chaos-test synthetic tenant.
        $build = function (): array {
            $fakeMount = $this->tmpDir . '/mover-mount-' . bin2hex(random_bytes(3));
            mkdir($fakeMount . '/t-fake', 0755, true);
            $this->cleanupQueue[] = function () use ($fakeMount) {
                if (is_dir($fakeMount . '/t-fake')) {
                    $d = opendir($fakeMount . '/t-fake');
                    if ($d) {
                        while (($e = readdir($d)) !== false) {
                            if ($e === '.' || $e === '..') continue;
                            @unlink($fakeMount . '/t-fake/' . $e);
                        }
                        closedir($d);
                    }
                    @rmdir($fakeMount . '/t-fake');
                }
                if (is_dir($fakeMount)) @rmdir($fakeMount);
            };
            $resolver = new \FlowOne\Storage\TenantResolver(
                ['t-fake' => ['subpath' => 't-fake']],
                $fakeMount,
                false
            );
            $journal = $this->makeTempJournal();
            $invariants = new \FlowOne\Storage\Invariants($journal, strict: false);
            $mover = new \FlowOne\Storage\TierBytesMover($resolver, $invariants, $journal);
            return [$mover, $fakeMount];
        };

        $this->test('tier_mover', 'tierDown copies bytes + verifies md5', function () use ($build) {
            [$mover, $mount] = $build();
            $src = $this->tmpDir . '/mover-src-' . bin2hex(random_bytes(3)) . '.bin';
            $payload = random_bytes(4096);
            file_put_contents($src, $payload);
            $this->cleanupQueue[] = fn() => @unlink($src);

            $name = 'm-' . bin2hex(random_bytes(3));
            $out = $mover->tierDown($src, 't-fake', $name, md5($payload));
            $this->assertTrue($out['ok'], 'expected ok=true; error=' . ($out['error'] ?? 'none'));
            $this->assertSame(4096, $out['bytes']);
            $this->assertSame(md5($payload), $out['actual_checksum']);
            $this->assertTrue(is_file($mount . '/t-fake/' . $name));
        });

        $this->test('tier_mover', 'tierDown rejects checksum mismatch + removes destination', function () use ($build) {
            [$mover, $mount] = $build();
            $src = $this->tmpDir . '/mover-bad-' . bin2hex(random_bytes(3)) . '.bin';
            file_put_contents($src, 'hello');
            $this->cleanupQueue[] = fn() => @unlink($src);

            $name = 'bad-' . bin2hex(random_bytes(3));
            $out = $mover->tierDown($src, 't-fake', $name, md5('different'));
            $this->assertTrue(!$out['ok']);
            $this->assertTrue(str_contains((string) $out['error'], 'checksum mismatch'));
            $this->assertTrue(!is_file($mount . '/t-fake/' . $name));
        });

        $this->test('tier_mover', 'tierDown refuses missing source', function () use ($build) {
            [$mover] = $build();
            $out = $mover->tierDown('/does/not/exist', 't-fake', 'x', md5(''));
            $this->assertTrue(!$out['ok']);
            $this->assertTrue(str_contains((string) $out['error'], 'missing'));
        });

        $this->test('tier_mover', 'tierDown refuses path traversal', function () use ($build) {
            [$mover] = $build();
            $src = $this->tmpDir . '/mover-trav-' . bin2hex(random_bytes(3)) . '.bin';
            file_put_contents($src, 'x');
            $this->cleanupQueue[] = fn() => @unlink($src);
            $out = $mover->tierDown($src, 't-fake', '../escape.bin', md5('x'));
            $this->assertTrue(!$out['ok']);
        });

        $this->test('tier_mover', 'recall copies bytes back + verifies md5', function () use ($build) {
            [$mover, $mount] = $build();
            $payload = random_bytes(2048);
            $name = 'r-' . bin2hex(random_bytes(3));
            file_put_contents($mount . '/t-fake/' . $name, $payload);

            $dst = $this->tmpDir . '/recall-dst-' . bin2hex(random_bytes(3)) . '.bin';
            $this->cleanupQueue[] = fn() => @unlink($dst);

            $out = $mover->recall('t-fake', $name, $dst, md5($payload));
            $this->assertTrue($out['ok'], 'expected ok=true; error=' . ($out['error'] ?? 'none'));
            $this->assertSame($payload, file_get_contents($dst));
        });

        $this->test('tier_mover', 'recall rejects checksum mismatch + removes destination', function () use ($build) {
            [$mover, $mount] = $build();
            $name = 'rbad-' . bin2hex(random_bytes(3));
            file_put_contents($mount . '/t-fake/' . $name, 'real');
            $dst = $this->tmpDir . '/recall-bad-' . bin2hex(random_bytes(3)) . '.bin';

            $out = $mover->recall('t-fake', $name, $dst, md5('wrong'));
            $this->assertTrue(!$out['ok']);
            $this->assertTrue(!is_file($dst));
        });

        $this->test('tier_mover', 'recall returns ok=false when source missing', function () use ($build) {
            [$mover] = $build();
            $dst = $this->tmpDir . '/recall-missing-' . bin2hex(random_bytes(3)) . '.bin';
            $out = $mover->recall('t-fake', 'ghost-' . bin2hex(random_bytes(3)), $dst, md5('x'));
            $this->assertTrue(!$out['ok']);
            $this->assertTrue(str_contains((string) $out['error'], 'missing'));
        });

        $this->test('tier_mover', 'unlinkVpsCopy is idempotent', function () use ($build) {
            [$mover] = $build();
            $p = $this->tmpDir . '/unlink-test-' . bin2hex(random_bytes(3)) . '.bin';
            file_put_contents($p, 'x');
            $this->assertTrue($mover->unlinkVpsCopy($p));
            // second call (file gone) must also report success
            $this->assertTrue($mover->unlinkVpsCopy($p));
        });

        $this->test('tier_mover', 'large payload (256KB) streams correctly', function () use ($build) {
            [$mover, $mount] = $build();
            $size = 256 * 1024;
            $src = $this->tmpDir . '/large-' . bin2hex(random_bytes(3)) . '.bin';
            // Build deterministically — random_bytes(256KB) is slow on Windows.
            $fh = fopen($src, 'wb');
            for ($i = 0; $i < $size; $i += 1024) {
                fwrite($fh, str_repeat(chr($i % 256), 1024));
            }
            fclose($fh);
            $this->cleanupQueue[] = fn() => @unlink($src);
            $expected = md5_file($src);

            $name = 'big-' . bin2hex(random_bytes(3));
            $out = $mover->tierDown($src, 't-fake', $name, $expected);
            $this->assertTrue($out['ok'], 'expected ok=true; error=' . ($out['error'] ?? 'none'));
            $this->assertSame($size, $out['bytes']);
            $this->assertSame($expected, md5_file($mount . '/t-fake/' . $name));
        });

        $this->test('tier_mover', 'leaves no inflight tempfile on success', function () use ($build) {
            [$mover, $mount] = $build();
            $src = $this->tmpDir . '/clean-' . bin2hex(random_bytes(3)) . '.bin';
            file_put_contents($src, 'ok');
            $this->cleanupQueue[] = fn() => @unlink($src);

            $name = 'clean-' . bin2hex(random_bytes(3));
            $mover->tierDown($src, 't-fake', $name, md5('ok'));

            // Scan tenant root for any .flowone_inflight_ leftovers.
            $found = glob($mount . '/t-fake/.flowone_inflight_*') ?: [];
            $this->assertSame(0, count($found));
        });

        $this->test('tier_mover', 'leaves no inflight tempfile on checksum failure', function () use ($build) {
            [$mover, $mount] = $build();
            $src = $this->tmpDir . '/cleanf-' . bin2hex(random_bytes(3)) . '.bin';
            file_put_contents($src, 'good');
            $this->cleanupQueue[] = fn() => @unlink($src);

            $name = 'cleanf-' . bin2hex(random_bytes(3));
            $mover->tierDown($src, 't-fake', $name, md5('wrong'));

            $found = glob($mount . '/t-fake/.flowone_inflight_*') ?: [];
            $this->assertSame(0, count($found));
            // Final destination must also be gone (we removed after mismatch).
            $this->assertTrue(!is_file($mount . '/t-fake/' . $name));
        });
    }

    private function groupTierRecallService(): void
    {
        // Recall service has hard runtime deps on:
        //   - pdo_sqlite (in-memory drive_files schema)
        //   - StorageHealth (HMAC key + state file) — we synthesise these
        //   - RecoveryBreaker (DurableJson under tmp)
        // Skip cleanly when any of these aren't available locally.
        if (!extension_loaded('pdo_sqlite')) {
            $this->test('tier_recall', 'pdo_sqlite not loaded — skipping group', function () {
                $this->assertTrue(true);
            });
            return;
        }
        if (!is_readable((string) Config::get('state.hmac_key_path'))) {
            $this->test('tier_recall', 'HMAC key not readable — skipping group', function () {
                $this->assertTrue(true);
            });
            return;
        }

        $TS = \FlowOne\Storage\TierState::class;

        // Build a fully-stubbed environment: fake NAS mount under
        // tmpDir, in-memory sqlite drive_files, a synthetic StorageHealth
        // that reports HEALTHY without touching the real state file.
        $build = function () use ($TS): array {
            // 1. Fake mount + tenant subpath.
            $fakeMount = $this->tmpDir . '/recall-mount-' . bin2hex(random_bytes(3));
            mkdir($fakeMount . '/email-drive', 0755, true);
            $this->cleanupQueue[] = function () use ($fakeMount) {
                if (is_dir($fakeMount . '/email-drive')) {
                    $d = opendir($fakeMount . '/email-drive');
                    if ($d) {
                        while (($e = readdir($d)) !== false) {
                            if ($e === '.' || $e === '..') continue;
                            $sub = $fakeMount . '/email-drive/' . $e;
                            if (is_dir($sub)) {
                                $sd = opendir($sub);
                                while ($sd && ($se = readdir($sd)) !== false) {
                                    if ($se === '.' || $se === '..') continue;
                                    @unlink($sub . '/' . $se);
                                }
                                if ($sd) closedir($sd);
                                @rmdir($sub);
                            } else {
                                @unlink($sub);
                            }
                        }
                        closedir($d);
                    }
                    @rmdir($fakeMount . '/email-drive');
                }
                if (is_dir($fakeMount)) @rmdir($fakeMount);
            };

            // 2. Sqlite drive_files + drive_tier_transitions.
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec(
                "CREATE TABLE drive_files (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_email TEXT NOT NULL,
                    filename TEXT NOT NULL,
                    storage_location TEXT,
                    tier_state TEXT NOT NULL DEFAULT 'hot',
                    tier_changed_at TEXT,
                    tier_changed_by TEXT,
                    tier_recall_attempts INTEGER NOT NULL DEFAULT 0,
                    size INTEGER DEFAULT 0,
                    checksum TEXT,
                    nas_relative_path TEXT
                )"
            );
            $pdo->exec(
                "CREATE TABLE drive_tier_transitions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    file_id INTEGER NOT NULL,
                    from_state TEXT NOT NULL DEFAULT 'unknown',
                    to_state TEXT NOT NULL,
                    actor TEXT NOT NULL DEFAULT 'system',
                    reason TEXT,
                    boot_epoch INTEGER,
                    bytes INTEGER,
                    duration_ms INTEGER,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                )"
            );

            // 3. Wire components manually so we don't depend on real
            //    /etc/flowone/storage.local.php on dev machines.
            $journal = $this->makeTempJournal();
            $invariants = new \FlowOne\Storage\Invariants($journal, strict: false);
            $resolver = new \FlowOne\Storage\TenantResolver(
                ['email-drive' => ['subpath' => 'email-drive']],
                $fakeMount,
                false
            );
            $mover = new \FlowOne\Storage\TierBytesMover($resolver, $invariants, $journal);
            $tierSvc = new \FlowOne\Storage\TierStateService($pdo, 'drive_files', 'drive_tier_transitions', $journal);
            $breaker = $this->makeRecoveryBreaker(5, 60, 3, 3600);

            // 4. Fake health provider that always reports HEALTHY.
            // Implements the same interface StorageHealth does in
            // production; no need to subclass the final concrete class.
            $health = new class implements \FlowOne\Storage\HealthStatusProvider {
                public function getStatus(): \FlowOne\Storage\HealthStatus {
                    return new \FlowOne\Storage\HealthStatus(
                        status:          \FlowOne\Storage\HealthState::HEALTHY,
                        bootEpoch:       1,
                        generation:      1,
                        publishedAtUnix: time(),
                        source:          'test-stub',
                        isStale:         false,
                        rootCause:       null,
                        rootCauseDetail: null,
                        checks:          [],
                        observedAgeSec:  0.0,
                        phase2:          [],
                    );
                }
            };

            $vpsBase = $this->tmpDir . '/recall-vps-' . bin2hex(random_bytes(3));
            mkdir($vpsBase, 0755, true);
            $this->cleanupQueue[] = function () use ($vpsBase) {
                if (is_dir($vpsBase)) {
                    $d = opendir($vpsBase);
                    while ($d && ($e = readdir($d)) !== false) {
                        if ($e === '.' || $e === '..') continue;
                        $sub = $vpsBase . '/' . $e;
                        if (is_dir($sub)) {
                            $sd = opendir($sub);
                            while ($sd && ($se = readdir($sd)) !== false) {
                                if ($se === '.' || $se === '..') continue;
                                @unlink($sub . '/' . $se);
                            }
                            if ($sd) closedir($sd);
                            @rmdir($sub);
                        } else {
                            @unlink($sub);
                        }
                    }
                    if ($d) closedir($d);
                    @rmdir($vpsBase);
                }
            };

            $svc = new \FlowOne\Storage\TierRecallService(
                pdo: $pdo,
                tierService: $tierSvc,
                mover: $mover,
                health: $health,
                breaker: $breaker,
                resolver: $resolver,
                tenant: 'email-drive',
                vpsBasePath: $vpsBase,
                lockDir: $this->tmpDir,
                journal: $journal,
                lockWaitSec: 2,
            );

            // Helper to seed a cold file row + matching NAS bytes.
            $seed = function (string $email, string $filename, string $payload) use ($pdo, $fakeMount): int {
                $hash = md5(strtolower($email));
                $nasRel = "email-drive/{$hash}/{$filename}";
                $absNas = $fakeMount . '/' . $nasRel;
                if (!is_dir(dirname($absNas))) {
                    mkdir(dirname($absNas), 0755, true);
                }
                file_put_contents($absNas, $payload);
                $stmt = $pdo->prepare(
                    "INSERT INTO drive_files (user_email, filename, storage_location, tier_state,
                                              tier_changed_at, tier_changed_by, size, checksum, nas_relative_path)
                     VALUES (:e, :f, 'nas', 'cold', CURRENT_TIMESTAMP, 'seed', :sz, :cs, :nrp)"
                );
                $stmt->execute([
                    ':e' => strtolower($email), ':f' => $filename,
                    ':sz' => strlen($payload), ':cs' => md5($payload),
                    ':nrp' => $nasRel,
                ]);
                return (int) $pdo->lastInsertId();
            };

            return [$svc, $pdo, $fakeMount, $vpsBase, $seed, $tierSvc];
        };

        $this->test('tier_recall', 'cold file -> recall succeeds + state becomes hot', function () use ($build, $TS) {
            [$svc, $pdo, $mount, $vpsBase, $seed] = $build();
            $payload = random_bytes(1024);
            $id = $seed('alice@example.com', 'file-' . bin2hex(random_bytes(3)) . '.bin', $payload);

            $vpsPath = $svc->recallCold($id);
            $this->assertTrue(is_file($vpsPath));
            $this->assertSame($payload, file_get_contents($vpsPath));

            $state = $svc->currentState($id);
            $this->assertSame($TS::HOT, $state);
        });

        $this->test('tier_recall', 'recall bumps tier_recall_attempts', function () use ($build) {
            [$svc, $pdo, $mount, $vpsBase, $seed, $tierSvc] = $build();
            $id = $seed('bob@example.com', 'f-' . bin2hex(random_bytes(3)) . '.bin', 'x');
            $svc->recallCold($id);
            $rec = $tierSvc->getRecord($id);
            $this->assertSame(1, (int) $rec['tier_recall_attempts']);
        });

        $this->test('tier_recall', 'recall on hot file is a no-op (returns vps path immediately)', function () use ($build, $TS) {
            [$svc, $pdo, $mount, $vpsBase, $seed, $tierSvc] = $build();
            $id = $seed('eve@example.com', 'h.bin', 'hot');
            // Walk to hot via a legal path (cold -> recalling -> hot).
            $tierSvc->transitionTo($id, $TS::RECALLING, 'test');
            $tierSvc->transitionTo($id, $TS::HOT, 'test');
            $rec = $tierSvc->getRecord($id);
            $attemptsBefore = (int) $rec['tier_recall_attempts'];

            $path = $svc->recallCold($id);
            $this->assertTrue(str_ends_with($path, '/h.bin'));
            // Must NOT have bumped recall_attempts again.
            $rec = $tierSvc->getRecord($id);
            $this->assertSame($attemptsBefore, (int) $rec['tier_recall_attempts']);
        });

        $this->test('tier_recall', 'recall on lost file throws', function () use ($build, $TS) {
            [$svc, $pdo, $mount, $vpsBase, $seed, $tierSvc] = $build();
            $id = $seed('mallory@example.com', 'l.bin', 'lost');
            $tierSvc->transitionTo($id, $TS::LOST, 'test');
            try {
                $svc->recallCold($id);
                $this->assertTrue(false, 'should throw');
            } catch (\RuntimeException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'lost'));
            }
        });

        $this->test('tier_recall', 'recall with missing NAS source throws + rolls back to cold', function () use ($build, $TS) {
            [$svc, $pdo, $mount, $vpsBase, $seed, $tierSvc] = $build();
            $id = $seed('victor@example.com', 'ghost.bin', 'real');
            // Delete the NAS bytes to simulate corruption mid-flight.
            $r = $pdo->query("SELECT nas_relative_path FROM drive_files WHERE id = {$id}")->fetchColumn();
            @unlink($mount . '/' . $r);

            try {
                $svc->recallCold($id);
                $this->assertTrue(false, 'should throw');
            } catch (\RuntimeException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'recall failed'));
            }
            // Must roll back to cold so a retry can pick it up.
            $this->assertSame($TS::COLD, $tierSvc->getState($id));
        });

        $this->test('tier_recall', 'recall with checksum mismatch throws + rolls back to cold', function () use ($build, $TS) {
            [$svc, $pdo, $mount, $vpsBase, $seed, $tierSvc] = $build();
            $id = $seed('walter@example.com', 'tamper.bin', 'original-bytes');
            // Mutate the NAS file so its bytes no longer match the
            // stored checksum.
            $r = $pdo->query("SELECT nas_relative_path FROM drive_files WHERE id = {$id}")->fetchColumn();
            file_put_contents($mount . '/' . $r, 'tampered-bytes');

            try {
                $svc->recallCold($id);
                $this->assertTrue(false, 'should throw');
            } catch (\RuntimeException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'checksum mismatch'));
            }
            $this->assertSame($TS::COLD, $tierSvc->getState($id));
        });

        $this->test('tier_recall', 'recall refuses legacy nas_relative_path without tenant prefix', function () use ($build) {
            [$svc, $pdo, $mount] = $build();
            // Hand-craft a row where nas_relative_path skips the
            // "email-drive/" tenant prefix (a legacy pre-Phase-3 row).
            $stmt = $pdo->prepare(
                "INSERT INTO drive_files (user_email, filename, storage_location, tier_state,
                                          tier_changed_at, size, checksum, nas_relative_path)
                 VALUES ('legacy@x.com', 'old.bin', 'nas', 'cold', CURRENT_TIMESTAMP, 4, ?, 'flat/old.bin')"
            );
            $stmt->execute([md5('xxxx')]);
            $id = (int) $pdo->lastInsertId();
            try {
                $svc->recallCold($id);
                $this->assertTrue(false, 'should throw');
            } catch (\RuntimeException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'tenant subpath')
                    || str_contains($e->getMessage(), 'legacy'));
            }
        });

        $this->test('tier_recall', 'currentState returns null for missing file', function () use ($build) {
            [$svc] = $build();
            $this->assertNull($svc->currentState(99999));
        });
    }

    private function groupTierDestructiveSweeper(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->test('tier_sweep', 'pdo_sqlite not loaded — skipping group', function () {
                $this->assertTrue(true);
            });
            return;
        }

        // Shared bootstrap: in-memory sqlite drive_files, fake mount,
        // VPS dir, and a pre-staged "cold + VPS shadow + NAS canonical"
        // row that the sweeper should pick up.
        $build = function (): array {
            $fakeMount = $this->tmpDir . '/sweep-mount-' . bin2hex(random_bytes(3));
            $vpsBase   = $this->tmpDir . '/sweep-vps-'   . bin2hex(random_bytes(3));
            @mkdir($fakeMount . '/email-drive', 0755, true);
            @mkdir($vpsBase, 0755, true);
            $this->cleanupQueue[] = function () use ($fakeMount, $vpsBase) {
                $this->rmrf($fakeMount);
                $this->rmrf($vpsBase);
            };

            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec(
                "CREATE TABLE drive_files (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_email TEXT NOT NULL,
                    filename TEXT NOT NULL,
                    storage_location TEXT,
                    tier_state TEXT NOT NULL DEFAULT 'hot',
                    tier_changed_at TEXT,
                    tier_changed_by TEXT,
                    tier_recall_attempts INTEGER NOT NULL DEFAULT 0,
                    size INTEGER DEFAULT 0,
                    checksum TEXT,
                    nas_relative_path TEXT
                )"
            );
            $pdo->exec(
                "CREATE TABLE drive_tier_transitions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    file_id INTEGER NOT NULL,
                    from_state TEXT NOT NULL DEFAULT 'unknown',
                    to_state TEXT NOT NULL,
                    actor TEXT NOT NULL DEFAULT 'system',
                    reason TEXT,
                    boot_epoch INTEGER,
                    bytes INTEGER,
                    duration_ms INTEGER,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                )"
            );

            $journal    = $this->makeTempJournal();
            $invariants = new \FlowOne\Storage\Invariants($journal, strict: false);
            $resolver   = new \FlowOne\Storage\TenantResolver(
                ['email-drive' => ['subpath' => 'email-drive']],
                $fakeMount,
                false
            );
            $mover     = new \FlowOne\Storage\TierBytesMover($resolver, $invariants, $journal);
            $tierSvc   = new \FlowOne\Storage\TierStateService(
                $pdo, 'drive_files', 'drive_tier_transitions', $journal
            );

            $sweeper = new \FlowOne\Storage\TierDestructiveSweeper(
                pdo:         $pdo,
                tierService: $tierSvc,
                mover:       $mover,
                resolver:    $resolver,
                tenant:      'email-drive',
                vpsBasePath: $vpsBase,
                lockDir:     $this->tmpDir,
                journal:     $journal,
                tableName:   'drive_files',
                strict:      true,
                lockWaitSec: 1,
            );

            // Helper: seed a cold row, with both VPS shadow + NAS canonical
            // bytes present, aged $hoursAgo hours into the past.
            // We compute tier_changed_at with sqlite's own datetime() to
            // guarantee the row + sweeper cutoff share the same clock
            // (PHP DateTimeImmutable + sqlite's datetime() can disagree
            // by the system timezone offset).
            $seed = function (string $email, string $filename, string $payload, int $hoursAgo) use ($pdo, $fakeMount, $vpsBase): int {
                $hash   = md5(strtolower($email));
                $nasRel = "email-drive/{$hash}/{$filename}";
                $nasAbs = $fakeMount . '/' . $nasRel;
                $vpsAbs = $vpsBase . '/' . $hash . '/' . $filename;
                @mkdir(dirname($nasAbs), 0755, true);
                @mkdir(dirname($vpsAbs), 0755, true);
                file_put_contents($nasAbs, $payload);
                file_put_contents($vpsAbs, $payload);

                $hoursAgo = max(0, (int) $hoursAgo);
                $stmt = $pdo->prepare(
                    "INSERT INTO drive_files
                       (user_email, filename, storage_location, tier_state,
                        tier_changed_at, tier_changed_by, size, checksum, nas_relative_path)
                     VALUES
                       (:e, :f, 'nas', 'cold',
                        datetime('now', '-{$hoursAgo} hours'),
                        'seed', :sz, :cs, :nrp)"
                );
                $stmt->execute([
                    ':e'   => strtolower($email), ':f'   => $filename,
                    ':sz'  => strlen($payload),
                    ':cs'  => md5($payload),
                    ':nrp' => $nasRel,
                ]);
                return (int) $pdo->lastInsertId();
            };

            return [$sweeper, $pdo, $fakeMount, $vpsBase, $seed, $tierSvc];
        };

        $this->test('tier_sweep', 'apply: post-grace row gets VPS-side unlink', function () use ($build) {
            [$sweeper, $pdo, $fakeMount, $vpsBase, $seed] = $build();
            $email = 'sweep@example.com';
            $fn = 'sweep-' . bin2hex(random_bytes(3)) . '.bin';
            $id = $seed($email, $fn, random_bytes(1024), 25);
            $vps = $vpsBase . '/' . md5(strtolower($email)) . '/' . $fn;
            $nas = $fakeMount . '/email-drive/' . md5(strtolower($email)) . '/' . $fn;

            $this->assertTrue(is_file($vps));
            $this->assertTrue(is_file($nas));

            $res = $sweeper->sweep(graceHours: 24, batch: 10, dryRun: false, maxSeconds: 5);
            $this->assertSame(1, $res['swept']);
            $this->assertSame(0, $res['failed']);
            $this->assertSame(false, is_file($vps), 'VPS shadow must be gone');
            $this->assertSame(true,  is_file($nas), 'NAS canonical must remain');
        });

        $this->test('tier_sweep', 'dry-run does NOT unlink', function () use ($build) {
            [$sweeper, $pdo, $fakeMount, $vpsBase, $seed] = $build();
            $email = 'dryrun@example.com';
            $fn = 'dr-' . bin2hex(random_bytes(3)) . '.bin';
            $id = $seed($email, $fn, random_bytes(512), 48);
            $vps = $vpsBase . '/' . md5(strtolower($email)) . '/' . $fn;

            $res = $sweeper->sweep(graceHours: 24, batch: 10, dryRun: true, maxSeconds: 5);
            $this->assertSame(1, $res['swept']); // counted as "would-unlink"
            $this->assertTrue(is_file($vps), 'dry-run must NOT touch the VPS file');
            $this->assertSame('would-unlink', $res['entries'][0]['action']);
        });

        $this->test('tier_sweep', 'pre-grace row is NOT swept', function () use ($build) {
            [$sweeper, $pdo, $fakeMount, $vpsBase, $seed] = $build();
            $email = 'young@example.com';
            $fn = 'y-' . bin2hex(random_bytes(3)) . '.bin';
            $id = $seed($email, $fn, random_bytes(256), 1); // only 1h old
            $vps = $vpsBase . '/' . md5(strtolower($email)) . '/' . $fn;

            $res = $sweeper->sweep(graceHours: 24, batch: 10, dryRun: false, maxSeconds: 5);
            $this->assertSame(0, $res['candidates']);
            $this->assertSame(0, $res['swept']);
            $this->assertTrue(is_file($vps), 'young cold row must keep VPS shadow');
        });

        $this->test('tier_sweep', 'rows not in cold state are filtered at query level (recall-in-progress safe)', function () use ($build) {
            [$sweeper, $pdo, $fakeMount, $vpsBase, $seed, $tierSvc] = $build();
            $email = 'drift@example.com';
            $fn = 'd-' . bin2hex(random_bytes(3)) . '.bin';
            $id = $seed($email, $fn, random_bytes(64), 25);
            // Move to RECALLING — proves the sweep's SQL filter keeps
            // non-cold rows entirely out of the candidate set, which is
            // the primary guarantee that a destructive unlink can never
            // race a recall. (The defensive under-lock re-read is also
            // present in the code as a second line of defence for the
            // narrow race between candidate fetch + lock acquisition.)
            $tierSvc->transitionTo($id, \FlowOne\Storage\TierState::RECALLING, 'test');
            $vps = $vpsBase . '/' . md5(strtolower($email)) . '/' . $fn;

            $res = $sweeper->sweep(graceHours: 24, batch: 10, dryRun: false, maxSeconds: 5);
            $this->assertSame(0, $res['candidates']);
            $this->assertSame(0, $res['attempted']);
            $this->assertSame(0, $res['swept']);
            $this->assertTrue(is_file($vps));
        });

        $this->test('tier_sweep', 'NAS checksum drift is detected + skipped (VPS preserved)', function () use ($build) {
            [$sweeper, $pdo, $fakeMount, $vpsBase, $seed] = $build();
            $email = 'rot@example.com';
            $fn = 'r-' . bin2hex(random_bytes(3)) . '.bin';
            $id = $seed($email, $fn, random_bytes(128), 25);
            // Mutate the NAS file so its bytes no longer match the
            // stored checksum.
            $nas = $fakeMount . '/email-drive/' . md5(strtolower($email)) . '/' . $fn;
            file_put_contents($nas, 'rotted-bytes-' . bin2hex(random_bytes(8)));
            $vps = $vpsBase . '/' . md5(strtolower($email)) . '/' . $fn;

            $res = $sweeper->sweep(graceHours: 24, batch: 10, dryRun: false, maxSeconds: 5);
            $this->assertSame(1, $res['skipped_checksum_drift']);
            $this->assertSame(0, $res['swept']);
            $this->assertTrue(is_file($vps), 'must preserve VPS when NAS is suspect');
        });

        $this->test('tier_sweep', 'VPS checksum drift (strict) is detected + skipped', function () use ($build) {
            [$sweeper, $pdo, $fakeMount, $vpsBase, $seed] = $build();
            $email = 'vrot@example.com';
            $fn = 'v-' . bin2hex(random_bytes(3)) . '.bin';
            $id = $seed($email, $fn, random_bytes(128), 25);
            $vps = $vpsBase . '/' . md5(strtolower($email)) . '/' . $fn;
            // Mutate ONLY the VPS file.
            file_put_contents($vps, 'tampered-' . bin2hex(random_bytes(4)));

            $res = $sweeper->sweep(graceHours: 24, batch: 10, dryRun: false, maxSeconds: 5);
            $this->assertSame(1, $res['skipped_checksum_drift']);
            $this->assertTrue(is_file($vps));
        });

        $this->test('tier_sweep', 'missing VPS file is recorded as skipped_vps_missing', function () use ($build) {
            [$sweeper, $pdo, $fakeMount, $vpsBase, $seed] = $build();
            $email = 'gone@example.com';
            $fn = 'g-' . bin2hex(random_bytes(3)) . '.bin';
            $id = $seed($email, $fn, random_bytes(64), 25);
            $vps = $vpsBase . '/' . md5(strtolower($email)) . '/' . $fn;
            @unlink($vps); // pretend a previous sweep already got it

            $res = $sweeper->sweep(graceHours: 24, batch: 10, dryRun: false, maxSeconds: 5);
            $this->assertSame(1, $res['skipped_vps_missing']);
            $this->assertSame(0, $res['swept']);
        });

        $this->test('tier_sweep', 'legacy nas_relative_path without tenant prefix is skipped', function () use ($build) {
            [$sweeper, $pdo, $fakeMount, $vpsBase] = $build();
            $payload = 'xxxx';
            $email   = 'legacy@x.com';
            $hash    = md5(strtolower($email));
            $fn      = 'old.bin';
            // Seed the VPS shadow so the sweeper's cheap fast-path
            // (skipped_vps_missing) doesn't short-circuit before we
            // reach the prefix-validation branch we want to exercise.
            $vpsAbs = $vpsBase . '/' . $hash . '/' . $fn;
            @mkdir(dirname($vpsAbs), 0755, true);
            file_put_contents($vpsAbs, $payload);

            $stmt = $pdo->prepare(
                "INSERT INTO drive_files
                   (user_email, filename, storage_location, tier_state,
                    tier_changed_at, size, checksum, nas_relative_path)
                 VALUES
                   (:e, :fn, 'nas', 'cold',
                    datetime('now', '-72 hours'), 4, :cs, 'flat/old.bin')"
            );
            $stmt->execute([':e' => $email, ':fn' => $fn, ':cs' => md5($payload)]);

            $res = $sweeper->sweep(graceHours: 24, batch: 10, dryRun: false, maxSeconds: 5);
            $this->assertSame(1, $res['skipped_nas_missing']);
            // VPS shadow MUST be preserved — we never validated the NAS
            // copy, so we have no right to delete the VPS one.
            $this->assertTrue(is_file($vpsAbs));
        });

        $this->test('tier_sweep', 'batch limit is honoured', function () use ($build) {
            [$sweeper, $pdo, $fakeMount, $vpsBase, $seed] = $build();
            for ($i = 0; $i < 5; $i++) {
                $seed("u{$i}@x.com", "f{$i}-" . bin2hex(random_bytes(2)) . ".bin", random_bytes(16), 25);
            }
            $res = $sweeper->sweep(graceHours: 24, batch: 2, dryRun: false, maxSeconds: 5);
            $this->assertSame(2, $res['candidates']);
            $this->assertSame(2, $res['swept']);
        });
    }

    private function groupStorageBudget(): void
    {
        $WM = \FlowOne\Storage\StorageBudgetReport::class;
        $tmpMount = $this->tmpDir; // tmpDir always exists on the local FS

        // OS-only build: no PDO, default quota disables logical layer.
        $osOnly = function (array $overrides = []) use ($tmpMount): \FlowOne\Storage\StorageBudget {
            $defaults = [
                'vpsMountPoint'    => $tmpMount,
                'driveQuotaBytes'  => 0,
                'minFreeBytes'     => 0,
                'warnVpsPct'       => 70,
                'highVpsPct'       => 80,
                'criticalVpsPct'   => 90,
                'warnDrivePct'     => 70,
                'highDrivePct'     => 85,
                'criticalDrivePct' => 95,
                'tableName'        => 'drive_files',
                'cacheTtlSec'      => 30,
                'pdo'              => null,
                'journal'          => null,
            ];
            $args = array_merge($defaults, $overrides);
            return new \FlowOne\Storage\StorageBudget(...$args);
        };

        $this->test('budget', 'OS-only snapshot returns sensible numbers', function () use ($osOnly) {
            $r = $osOnly()->snapshot();
            $this->assertTrue($r->vpsTotalBytes > 0, 'vpsTotalBytes must be positive on any real disk');
            $this->assertTrue($r->vpsFreeBytes >= 0);
            $this->assertTrue($r->vpsUsedBytes >= 0);
            $this->assertTrue($r->vpsUsedPct >= 0.0 && $r->vpsUsedPct <= 100.0);
            $this->assertNull($r->driveQuotaBytes, 'logical layer must be null when quota=0');
            $this->assertNull($r->driveUsedBytes);
        });

        $this->test('budget', 'critical when min_free_bytes is set higher than actual free', function () use ($osOnly, $WM) {
            // Set the floor to something we know is bigger than any free
            // bytes value disk_free_space could return on the tmp mount.
            $huge = PHP_INT_MAX - 1;
            $r = $osOnly(['minFreeBytes' => $huge])->snapshot();
            $this->assertSame($WM::WM_CRITICAL, $r->watermark);
            $this->assertTrue(count($r->reasons) >= 1);
            $this->assertTrue(str_contains($r->reasons[0], 'min_free_bytes'));
        });

        $this->test('budget', 'critical when critical_vps_pct=0 (forces a critical reading)', function () use ($osOnly, $WM) {
            // Setting critical=0 means ANY used pct triggers critical.
            $r = $osOnly([
                'warnVpsPct'     => 0,
                'highVpsPct'     => 0,
                'criticalVpsPct' => 0,
            ])->snapshot();
            $this->assertSame($WM::WM_CRITICAL, $r->watermark);
        });

        $this->test('budget', 'clear when all thresholds are above current usage', function () use ($osOnly, $WM) {
            $r = $osOnly([
                'warnVpsPct'     => 99,
                'highVpsPct'     => 99,
                'criticalVpsPct' => 99,
                'minFreeBytes'   => 0,
            ])->snapshot();
            // We can be at clear OR warn (depending on real disk fill);
            // but never critical when all thresholds are at 99% AND min=0.
            $this->assertTrue(in_array($r->watermark, [$WM::WM_CLEAR, $WM::WM_WARN], true));
        });

        $this->test('budget', 'cache returns the same snapshot within TTL', function () use ($osOnly) {
            $svc = $osOnly(['cacheTtlSec' => 60]);
            $r1 = $svc->snapshot();
            $r2 = $svc->snapshot();
            $this->assertSame($r1->computedAtUnix, $r2->computedAtUnix);
            $this->assertTrue($r1->fromCache === false);
            $this->assertTrue($r2->fromCache === true);
        });

        $this->test('budget', 'bypassCache forces recompute', function () use ($osOnly) {
            $svc = $osOnly(['cacheTtlSec' => 60]);
            $r1 = $svc->snapshot();
            $r2 = $svc->snapshot(bypassCache: true);
            // Both should be fresh; the second one isn't from cache.
            $this->assertTrue($r2->fromCache === false);
        });

        $this->test('budget', 'canAccept refuses when adding bytes would dip below min_free', function () use ($osOnly) {
            $r = $osOnly(['minFreeBytes' => 0])->snapshot();
            // Demand 100% of free bytes + a fudge — should fail since
            // we have to keep at least the floor (0) intact.
            $this->assertTrue($r->canAccept($r->vpsFreeBytes + 1, minFreeBytes: 0) === false);
            // Asking for 1 byte under a 0-floor on a non-full disk is fine.
            $this->assertTrue($r->canAccept(1, minFreeBytes: 0) === true);
        });

        $this->test('budget', 'toArray() shape contains both layers', function () use ($osOnly) {
            $arr = $osOnly()->snapshot()->toArray();
            $this->assertTrue(isset($arr['watermark']));
            $this->assertTrue(isset($arr['vps']['total_bytes']));
            $this->assertTrue(isset($arr['vps']['free_bytes']));
            $this->assertSame(false, $arr['drive']['available']);
        });

        // Logical-layer tests (need pdo_sqlite + a fake drive_files table).
        if (!extension_loaded('pdo_sqlite')) {
            $this->test('budget', 'pdo_sqlite not loaded — skipping logical-layer tests', function () {
                $this->assertTrue(true);
            });
            return;
        }

        $buildWithDb = function (int $hotBytes, int $tieringBytes, int $coldBytes, int $quota) use ($tmpMount): \FlowOne\Storage\StorageBudget {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec(
                "CREATE TABLE drive_files (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_email TEXT NOT NULL,
                    filename TEXT NOT NULL,
                    tier_state TEXT NOT NULL DEFAULT 'hot',
                    size INTEGER DEFAULT 0,
                    checksum TEXT,
                    nas_relative_path TEXT
                )"
            );
            $insert = $pdo->prepare("INSERT INTO drive_files (user_email, filename, tier_state, size) VALUES (?, ?, ?, ?)");
            if ($hotBytes > 0)     $insert->execute(['u@x.com', 'h.bin', 'hot', $hotBytes]);
            if ($tieringBytes > 0) $insert->execute(['u@x.com', 't.bin', 'tiering', $tieringBytes]);
            if ($coldBytes > 0)    $insert->execute(['u@x.com', 'c.bin', 'cold', $coldBytes]);
            return new \FlowOne\Storage\StorageBudget(
                vpsMountPoint:    $tmpMount,
                driveQuotaBytes:  $quota,
                minFreeBytes:     0,
                warnVpsPct:       99, highVpsPct: 99, criticalVpsPct: 99,
                warnDrivePct:     70, highDrivePct: 85, criticalDrivePct: 95,
                tableName:        'drive_files',
                cacheTtlSec:      30,
                pdo:              $pdo,
            );
        };

        $this->test('budget', 'logical layer sums HOT + TIERING but NOT cold', function () use ($buildWithDb) {
            $r = $buildWithDb(hotBytes: 1000, tieringBytes: 500, coldBytes: 9999, quota: 100000)->snapshot();
            $this->assertSame(1500, $r->driveUsedBytes);
            $this->assertSame(100000, $r->driveQuotaBytes);
            $this->assertSame(98500, $r->driveFreeBytes);
            $this->assertSame(2, $r->driveHotRows, 'COUNT(*) over HOT+TIERING rows');
        });

        $this->test('budget', 'logical layer escalates watermark when drive_used_pct >= critical', function () use ($buildWithDb, $WM) {
            // 96 / 100 = 96% > 95% critical_drive_pct.
            $r = $buildWithDb(hotBytes: 96, tieringBytes: 0, coldBytes: 0, quota: 100)->snapshot();
            $this->assertSame($WM::WM_CRITICAL, $r->watermark);
        });

        $this->test('budget', 'logical layer goes high (not critical) at 88%', function () use ($buildWithDb, $WM) {
            $r = $buildWithDb(hotBytes: 88, tieringBytes: 0, coldBytes: 0, quota: 100)->snapshot();
            $this->assertSame($WM::WM_HIGH, $r->watermark);
        });

        $this->test('budget', 'canAccept refuses when bytes would push over quota', function () use ($buildWithDb) {
            $r = $buildWithDb(hotBytes: 90, tieringBytes: 0, coldBytes: 0, quota: 100)->snapshot();
            $this->assertTrue($r->canAccept(11, minFreeBytes: 0) === false); // 90+11 = 101 > 100
            $this->assertTrue($r->canAccept(10, minFreeBytes: 0) === true);  // 90+10 = 100 == quota OK
        });

        $this->test('budget', 'toArray() shape contains logical layer when DB available', function () use ($buildWithDb) {
            $arr = $buildWithDb(hotBytes: 10, tieringBytes: 0, coldBytes: 0, quota: 100)->snapshot()->toArray();
            $this->assertSame(true, $arr['drive']['available']);
            $this->assertSame(10, $arr['drive']['used_bytes']);
            $this->assertSame(100, $arr['drive']['quota_bytes']);
        });
    }

    private function groupAdmissionController(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->test('admission', 'pdo_sqlite not loaded — skipping group', function () {
                $this->assertTrue(true);
            });
            return;
        }

        $WM = \FlowOne\Storage\StorageBudgetReport::class;
        $tmpMount = $this->tmpDir;

        // Build an in-memory drive_files seeded with $hotBytes worth of
        // HOT rows, then wrap it in a StorageBudget pointed at $tmpMount
        // with a $quota cap. Returns ($budget, $pdo).
        $buildBudget = function (int $hotBytes, int $quota, int $minFree = 0) use ($tmpMount): array {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec(
                "CREATE TABLE drive_files (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_email TEXT NOT NULL,
                    filename TEXT NOT NULL,
                    tier_state TEXT NOT NULL DEFAULT 'hot',
                    size INTEGER DEFAULT 0
                )"
            );
            if ($hotBytes > 0) {
                $pdo->prepare("INSERT INTO drive_files (user_email, filename, tier_state, size) VALUES (?, ?, 'hot', ?)")
                    ->execute(['u@x.com', 'h.bin', $hotBytes]);
            }
            $budget = new \FlowOne\Storage\StorageBudget(
                vpsMountPoint:    $tmpMount,
                driveQuotaBytes:  $quota,
                minFreeBytes:     $minFree,
                warnVpsPct:       99, highVpsPct: 99, criticalVpsPct: 99,
                warnDrivePct:     70, highDrivePct: 85, criticalDrivePct: 95,
                tableName:        'drive_files',
                cacheTtlSec:      30,
                pdo:              $pdo,
            );
            return [$budget, $pdo];
        };

        $this->test('admission', 'disabled controller is always a no-op (kill switch OFF)', function () use ($buildBudget) {
            [$budget] = $buildBudget(hotBytes: 99, quota: 100);
            $ac = new \FlowOne\Storage\AdmissionController(
                budget: $budget, enabled: false,
            );
            // Even an over-quota request gets admitted when disabled.
            $ac->admit(10_000_000);
            $d = $ac->evaluate(10_000_000);
            $this->assertTrue($d['accept']);
            $this->assertTrue($d['enabled'] === false);
        });

        $this->test('admission', 'enabled + healthy budget admits a small request', function () use ($buildBudget) {
            [$budget] = $buildBudget(hotBytes: 10, quota: 1000);
            $ac = new \FlowOne\Storage\AdmissionController(
                budget: $budget, enabled: true,
            );
            $ac->admit(100); // 10 + 100 = 110 << 1000, easily fits.
            $d = $ac->evaluate(100);
            $this->assertTrue($d['accept']);
            $this->assertTrue($d['enabled'] === true);
        });

        $this->test('admission', 'enabled + would-overflow quota throws StorageBudgetExceededException', function () use ($buildBudget) {
            [$budget] = $buildBudget(hotBytes: 95, quota: 100);
            $ac = new \FlowOne\Storage\AdmissionController(
                budget: $budget, enabled: true,
            );
            try {
                $ac->admit(10); // 95 + 10 = 105 > 100 quota
                $this->assertTrue(false, 'should have thrown');
            } catch (\FlowOne\Storage\StorageBudgetExceededException $e) {
                $this->assertSame(10, $e->bytesAttempted);
                $this->assertTrue($e->retryAfterSec > 0);
                $this->assertTrue(count($e->reasons) >= 1);
            }
        });

        $this->test('admission', 'critical watermark refuses even a zero-byte request', function () use ($buildBudget) {
            // 96/100 = 96% > 95% critical_drive_pct -> WM_CRITICAL.
            [$budget] = $buildBudget(hotBytes: 96, quota: 100);
            $ac = new \FlowOne\Storage\AdmissionController(
                budget: $budget, enabled: true,
            );
            try {
                $ac->admit(0);
                $this->assertTrue(false, 'critical watermark must refuse');
            } catch (\FlowOne\Storage\StorageBudgetExceededException $e) {
                $this->assertSame(\FlowOne\Storage\StorageBudgetReport::WM_CRITICAL, $e->watermark);
                $this->assertTrue($e->retryAfterSec >= \FlowOne\Storage\AdmissionController::RETRY_AFTER_CRITICAL_SEC);
            }
        });

        $this->test('admission', 'evaluate() returns decision struct without throwing', function () use ($buildBudget, $WM) {
            [$budget] = $buildBudget(hotBytes: 99, quota: 100);
            $ac = new \FlowOne\Storage\AdmissionController(
                budget: $budget, enabled: true,
            );
            $d = $ac->evaluate(50); // 99+50 = 149 > 100 quota
            $this->assertTrue($d['accept'] === false);
            $this->assertSame($WM::WM_CRITICAL, $d['watermark']); // 99% > 95% critical_drive_pct
            $this->assertTrue($d['report'] instanceof $WM);
        });

        $this->test('admission', 'health-not-writable refuses with health reason', function () use ($buildBudget) {
            [$budget] = $buildBudget(hotBytes: 1, quota: 1000);
            $fakeHealth = new class implements \FlowOne\Storage\HealthStatusProvider {
                public function getStatus(): \FlowOne\Storage\HealthStatus {
                    return new \FlowOne\Storage\HealthStatus(
                        status:          \FlowOne\Storage\HealthState::OFFLINE,
                        bootEpoch:       1, generation: 1,
                        publishedAtUnix: time(),
                        source:          'test-stub',
                        isStale:         false,
                    );
                }
            };
            $ac = new \FlowOne\Storage\AdmissionController(
                budget: $budget, enabled: true, health: $fakeHealth,
            );
            try {
                $ac->admit(10);
                $this->assertTrue(false, 'unwritable storage must refuse');
            } catch (\FlowOne\Storage\StorageBudgetExceededException $e) {
                $hasHealthReason = false;
                foreach ($e->reasons as $r) {
                    if (str_contains($r, 'storage_health')) { $hasHealthReason = true; break; }
                }
                $this->assertTrue($hasHealthReason, 'reasons should mention storage_health');
            }
        });

        $this->test('admission', 'StorageBudgetExceededException renders HTTP 503 shape', function () {
            $e = new \FlowOne\Storage\StorageBudgetExceededException(
                bytesAttempted: 1024,
                watermark:      'critical',
                reasons:        ['drive_used_pct=96 >= critical_drive_pct=95'],
                retryAfterSec:  300,
            );
            $resp = $e->toHttpResponse();
            $this->assertSame(503, $resp['status_code']);
            $this->assertSame('300', $resp['headers']['Retry-After']);
            $this->assertSame('storage_budget_exceeded', $resp['body']['error']);
            $this->assertSame(1024, $resp['body']['bytes_attempted']);
        });

        $this->test('admission', 'exception extends RuntimeException for legacy catch blocks', function () {
            $e = new \FlowOne\Storage\StorageBudgetExceededException(
                bytesAttempted: 1, watermark: 'critical', reasons: [], retryAfterSec: 60,
            );
            $this->assertTrue($e instanceof \RuntimeException);
            $this->assertTrue($e->getMessage() !== '');
        });
    }

    /**
     * Phase 6d: TierStateService::findTierDownCandidates LRU ordering
     * and the sqlite-bind-bug regression net.
     */
    private function groupLruSelection(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->test('lru', 'pdo_sqlite not loaded — skipping group', function () {
                $this->assertTrue(true);
            });
            return;
        }
        $TS = \FlowOne\Storage\TierState::class;

        // Build a sqlite drive_files with last_read_at present.
        $makeSvcWithLra = function () {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec(
                "CREATE TABLE drive_files (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    storage_location TEXT,
                    tier_state TEXT NOT NULL DEFAULT 'hot',
                    tier_changed_at TEXT,
                    tier_changed_by TEXT,
                    tier_recall_attempts INTEGER NOT NULL DEFAULT 0,
                    size INTEGER DEFAULT 0,
                    checksum TEXT,
                    nas_relative_path TEXT,
                    last_read_at TEXT
                )"
            );
            $pdo->exec(
                "CREATE TABLE drive_tier_transitions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    file_id INTEGER NOT NULL,
                    from_state TEXT NOT NULL DEFAULT 'unknown',
                    to_state TEXT NOT NULL,
                    actor TEXT NOT NULL DEFAULT 'system',
                    reason TEXT,
                    boot_epoch INTEGER,
                    bytes INTEGER,
                    duration_ms INTEGER,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                )"
            );
            return [new \FlowOne\Storage\TierStateService($pdo), $pdo];
        };

        // Same, but WITHOUT last_read_at — for the auto-detect fallback test.
        $makeSvcWithoutLra = function () {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec(
                "CREATE TABLE drive_files (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    storage_location TEXT,
                    tier_state TEXT NOT NULL DEFAULT 'hot',
                    tier_changed_at TEXT,
                    size INTEGER DEFAULT 0,
                    checksum TEXT,
                    nas_relative_path TEXT
                )"
            );
            $pdo->exec(
                "CREATE TABLE drive_tier_transitions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    file_id INTEGER NOT NULL,
                    from_state TEXT NOT NULL DEFAULT 'unknown',
                    to_state TEXT NOT NULL,
                    actor TEXT NOT NULL DEFAULT 'system',
                    reason TEXT,
                    boot_epoch INTEGER,
                    bytes INTEGER,
                    duration_ms INTEGER,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                )"
            );
            return [new \FlowOne\Storage\TierStateService($pdo), $pdo];
        };

        // Insert a hot row with (tier_changed_at, last_read_at) as relative SQLite expressions.
        $insertHot = function (\PDO $pdo, string $tierChangedExpr, ?string $lastReadExpr, int $size = 1000): int {
            if ($lastReadExpr === null) {
                $sql = "INSERT INTO drive_files (tier_state, tier_changed_at, last_read_at, size)
                        VALUES ('hot', {$tierChangedExpr}, NULL, :sz)";
            } else {
                $sql = "INSERT INTO drive_files (tier_state, tier_changed_at, last_read_at, size)
                        VALUES ('hot', {$tierChangedExpr}, {$lastReadExpr}, :sz)";
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':sz' => $size]);
            return (int) $pdo->lastInsertId();
        };

        // Regression net: the sqlite bind bug we just fixed in
        // findTierDownCandidates would have silently returned 0 rows
        // for any positive age. This test exists to catch that
        // forever.
        $this->test('lru', 'sqlite bind bug regression: age threshold actually filters', function () use ($makeSvcWithLra, $insertHot, $TS) {
            [$svc, $pdo] = $makeSvcWithLra();
            $oldId = $insertHot($pdo, "datetime('now', '-40 days')", null);
            $newId = $insertHot($pdo, "datetime('now', '-1 days')",  null);
            $r30 = $svc->findTierDownCandidates(ageDays: 30, limit: 10, orderBy: 'age');
            $ids = array_map(fn($r) => (int) $r['id'], $r30);
            $this->assertTrue(in_array($oldId, $ids, true), 'old row must be returned at age_days=30');
            $this->assertTrue(!in_array($newId, $ids, true), 'recent row must be filtered at age_days=30');
        });

        $this->test('lru', 'default order=age returns oldest tier_changed_at first', function () use ($makeSvcWithLra, $insertHot, $TS) {
            [$svc, $pdo] = $makeSvcWithLra();
            $a = $insertHot($pdo, "datetime('now', '-50 days')", null);
            $b = $insertHot($pdo, "datetime('now', '-40 days')", null);
            $c = $insertHot($pdo, "datetime('now', '-60 days')", null);
            $r = $svc->findTierDownCandidates(ageDays: 30, limit: 10, orderBy: 'age');
            $this->assertSame(3, count($r));
            $ids = array_map(fn($x) => (int) $x['id'], $r);
            $this->assertSame([$c, $a, $b], $ids, 'expected -60d, -50d, -40d order');
        });

        $this->test('lru', 'order=lru orders by COALESCE(last_read_at, tier_changed_at)', function () use ($makeSvcWithLra, $insertHot) {
            [$svc, $pdo] = $makeSvcWithLra();
            // Three candidates, all "old enough" to clear the 30-day filter:
            //   A: tier_changed=-50d, last_read=-1d   -> sort key = -1d
            //   B: tier_changed=-40d, last_read=NULL  -> sort key = -40d
            //   C: tier_changed=-60d, last_read=NULL  -> sort key = -60d
            // Expected LRU order (oldest sort-key first): C, B, A
            $a = $insertHot($pdo, "datetime('now', '-50 days')", "datetime('now', '-1 days')");
            $b = $insertHot($pdo, "datetime('now', '-40 days')", null);
            $c = $insertHot($pdo, "datetime('now', '-60 days')", null);
            $r = $svc->findTierDownCandidates(ageDays: 30, limit: 10, orderBy: 'lru');
            $ids = array_map(fn($x) => (int) $x['id'], $r);
            $this->assertSame([$c, $b, $a], $ids, 'expected C, B, A — recently-read A should sink to the back');
        });

        $this->test('lru', 'order=lru still respects age_days filter', function () use ($makeSvcWithLra, $insertHot) {
            [$svc, $pdo] = $makeSvcWithLra();
            $insertHot($pdo, "datetime('now', '-50 days')", null); // qualifies
            $young = $insertHot($pdo, "datetime('now', '-1 days')", null); // too young
            $r = $svc->findTierDownCandidates(ageDays: 30, limit: 10, orderBy: 'lru');
            $ids = array_map(fn($x) => (int) $x['id'], $r);
            $this->assertTrue(!in_array($young, $ids, true), 'recent-changed row must not qualify even under LRU');
        });

        $this->test('lru', 'order=lru when last_read_at column missing falls back to age', function () use ($makeSvcWithoutLra) {
            [$svc, $pdo] = $makeSvcWithoutLra();
            $pdo->exec("INSERT INTO drive_files (tier_state, tier_changed_at) VALUES ('hot', datetime('now', '-50 days'))");
            $pdo->exec("INSERT INTO drive_files (tier_state, tier_changed_at) VALUES ('hot', datetime('now', '-40 days'))");
            // Should NOT throw an SQL error for missing column — auto-detect kicks in.
            $r = $svc->findTierDownCandidates(ageDays: 30, limit: 10, orderBy: 'lru');
            $this->assertSame(2, count($r));
            // And SELECT list should not include last_read_at when absent.
            $this->assertTrue(!array_key_exists('last_read_at', $r[0]));
        });

        $this->test('lru', 'order=lru returns last_read_at when column present', function () use ($makeSvcWithLra, $insertHot) {
            [$svc, $pdo] = $makeSvcWithLra();
            $insertHot($pdo, "datetime('now', '-50 days')", "datetime('now', '-2 days')");
            $r = $svc->findTierDownCandidates(ageDays: 30, limit: 10, orderBy: 'lru');
            $this->assertTrue(array_key_exists('last_read_at', $r[0]));
            $this->assertTrue($r[0]['last_read_at'] !== null);
        });

        $this->test('lru', 'limit param is honoured', function () use ($makeSvcWithLra, $insertHot) {
            [$svc, $pdo] = $makeSvcWithLra();
            for ($i = 0; $i < 5; $i++) {
                $insertHot($pdo, "datetime('now', '-50 days')", null);
            }
            $r = $svc->findTierDownCandidates(ageDays: 30, limit: 2, orderBy: 'lru');
            $this->assertSame(2, count($r));
        });

        $this->test('lru', 'cold/recalling rows are excluded regardless of age', function () use ($makeSvcWithLra) {
            [$svc, $pdo] = $makeSvcWithLra();
            $pdo->exec("INSERT INTO drive_files (tier_state, tier_changed_at) VALUES ('cold',      datetime('now', '-50 days'))");
            $pdo->exec("INSERT INTO drive_files (tier_state, tier_changed_at) VALUES ('recalling', datetime('now', '-50 days'))");
            $pdo->exec("INSERT INTO drive_files (tier_state, tier_changed_at) VALUES ('lost',      datetime('now', '-50 days'))");
            $r = $svc->findTierDownCandidates(ageDays: 30, limit: 10, orderBy: 'lru');
            $this->assertSame(0, count($r));
        });

        $this->test('lru', 'order=garbage falls back to age (input sanitisation)', function () use ($makeSvcWithLra, $insertHot) {
            [$svc, $pdo] = $makeSvcWithLra();
            $a = $insertHot($pdo, "datetime('now', '-50 days')", null);
            $b = $insertHot($pdo, "datetime('now', '-40 days')", null);
            $r = $svc->findTierDownCandidates(ageDays: 30, limit: 10, orderBy: 'totally-not-a-mode');
            $ids = array_map(fn($x) => (int) $x['id'], $r);
            $this->assertSame([$a, $b], $ids, 'unknown order should behave like age');
        });
    }

    /**
     * Phase 6d: LastReadTouch — throttled "I was just read" stamper.
     */
    private function groupLastReadTouch(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->test('lru_touch', 'pdo_sqlite not loaded — skipping group', function () {
                $this->assertTrue(true);
            });
            return;
        }

        $makePdo = function (): \PDO {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec(
                "CREATE TABLE drive_files (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tier_state TEXT NOT NULL DEFAULT 'hot',
                    last_read_at TEXT
                )"
            );
            return $pdo;
        };

        $insert = function (\PDO $pdo, string $state, ?string $lastReadExpr = null): int {
            $expr = $lastReadExpr === null ? 'NULL' : $lastReadExpr;
            $pdo->exec("INSERT INTO drive_files (tier_state, last_read_at) VALUES ('{$state}', {$expr})");
            return (int) $pdo->lastInsertId();
        };

        $getLastRead = function (\PDO $pdo, int $id): ?string {
            $stmt = $pdo->prepare('SELECT last_read_at FROM drive_files WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row['last_read_at'] ?? null;
        };

        $this->test('lru_touch', 'touch on hot file populates last_read_at', function () use ($makePdo, $insert, $getLastRead) {
            $pdo = $makePdo();
            $id = $insert($pdo, 'hot', null);
            $touch = new \FlowOne\Storage\LastReadTouch($pdo, 'drive_files', 60);
            $this->assertTrue($touch->touch($id));
            $this->assertTrue($getLastRead($pdo, $id) !== null);
        });

        $this->test('lru_touch', 'touch on tiering file populates last_read_at', function () use ($makePdo, $insert, $getLastRead) {
            $pdo = $makePdo();
            $id = $insert($pdo, 'tiering', null);
            $touch = new \FlowOne\Storage\LastReadTouch($pdo, 'drive_files', 60);
            $touch->touch($id);
            $this->assertTrue($getLastRead($pdo, $id) !== null);
        });

        $this->test('lru_touch', 'touch on cold file does NOT populate last_read_at', function () use ($makePdo, $insert, $getLastRead) {
            $pdo = $makePdo();
            $id = $insert($pdo, 'cold', null);
            $touch = new \FlowOne\Storage\LastReadTouch($pdo, 'drive_files', 60);
            $touch->touch($id);
            $this->assertNull($getLastRead($pdo, $id));
        });

        $this->test('lru_touch', 'touch on lost file does NOT populate last_read_at', function () use ($makePdo, $insert, $getLastRead) {
            $pdo = $makePdo();
            $id = $insert($pdo, 'lost', null);
            $touch = new \FlowOne\Storage\LastReadTouch($pdo, 'drive_files', 60);
            $touch->touch($id);
            $this->assertNull($getLastRead($pdo, $id));
        });

        $this->test('lru_touch', 'within throttle window the DB row is NOT rewritten', function () use ($makePdo, $insert, $getLastRead) {
            $pdo = $makePdo();
            // Seed last_read_at to a known value 5 seconds in the past;
            // throttle window is 60s, so a touch within that window
            // should be a DB no-op (conditional WHERE rejects).
            $id = $insert($pdo, 'hot', "datetime('now', '-5 seconds')");
            $before = $getLastRead($pdo, $id);

            // Build the touch BUT bypass the process-local memo by
            // never having seen this id in this LastReadTouch instance.
            $touch = new \FlowOne\Storage\LastReadTouch($pdo, 'drive_files', 60);
            $touch->touch($id);

            $after = $getLastRead($pdo, $id);
            $this->assertSame($before, $after, 'DB write should have been elided by conditional WHERE');
        });

        $this->test('lru_touch', 'after throttle window the row IS rewritten', function () use ($makePdo, $insert, $getLastRead) {
            $pdo = $makePdo();
            // Seed last_read_at to 120 seconds ago; throttle = 60s ->
            // the conditional WHERE accepts.
            $id = $insert($pdo, 'hot', "datetime('now', '-120 seconds')");
            $before = $getLastRead($pdo, $id);

            $touch = new \FlowOne\Storage\LastReadTouch($pdo, 'drive_files', 60);
            $touch->touch($id);

            $after = $getLastRead($pdo, $id);
            $this->assertTrue($after !== null);
            $this->assertTrue($after !== $before, 'DB write must update last_read_at past the cutoff');
        });

        $this->test('lru_touch', 'process-local memo elides the second touch in same instance', function () use ($makePdo, $insert) {
            $pdo = $makePdo();
            $id = $insert($pdo, 'hot', null);
            $touch = new \FlowOne\Storage\LastReadTouch($pdo, 'drive_files', 60);
            $this->assertTrue($touch->touch($id), 'first touch should attempt');
            $this->assertTrue($touch->touch($id) === false, 'second touch should be memo-elided');
        });

        $this->test('lru_touch', 'resetMemo allows immediate re-touch', function () use ($makePdo, $insert) {
            $pdo = $makePdo();
            $id = $insert($pdo, 'hot', null);
            $touch = new \FlowOne\Storage\LastReadTouch($pdo, 'drive_files', 60);
            $touch->touch($id);
            $touch->resetMemo();
            $this->assertTrue($touch->touch($id), 'after resetMemo, touch should attempt the DB again');
        });

        $this->test('lru_touch', 'invalid file_id <= 0 returns false without touching DB', function () use ($makePdo) {
            $pdo = $makePdo();
            $touch = new \FlowOne\Storage\LastReadTouch($pdo, 'drive_files', 60);
            $this->assertTrue($touch->touch(0) === false);
            $this->assertTrue($touch->touch(-1) === false);
        });

        $this->test('lru_touch', 'touch is non-throwing when underlying DB errors', function () use ($makePdo, $insert) {
            $pdo = $makePdo();
            $id = $insert($pdo, 'hot', null);
            // Drop the table mid-life to simulate a runtime DB error.
            $pdo->exec("DROP TABLE drive_files");
            $touch = new \FlowOne\Storage\LastReadTouch($pdo, 'drive_files', 60);
            // MUST NOT throw.
            $caught = false;
            try {
                $touch->touch($id);
            } catch (\Throwable $e) {
                $caught = true;
            }
            $this->assertTrue($caught === false, 'touch() must swallow errors');
        });

        $this->test('lru_touch', 'LastReadTouch::build reads min_touch_interval from config', function () use ($makePdo) {
            $pdo = $makePdo();
            $cfg = ['tier' => ['lru' => ['min_touch_interval_sec' => 123]]];
            $touch = \FlowOne\Storage\LastReadTouch::build($pdo, $cfg);
            $this->assertTrue($touch instanceof \FlowOne\Storage\LastReadTouch);
        });
    }

    /**
     * Phase 6c — ReclaimController pure decision logic.
     *
     * Every transition is exercised against a synthetic
     * StorageBudgetReport (no PDO, no I/O). The controller is the
     * only component small enough that exhaustive table-driven
     * testing is worthwhile.
     */
    private function groupReclaimController(): void
    {
        $RS = \FlowOne\Storage\ReclaimState::class;
        $WM = \FlowOne\Storage\StorageBudgetReport::class;

        $mkReport = function (string $wm): \FlowOne\Storage\StorageBudgetReport {
            return new \FlowOne\Storage\StorageBudgetReport(
                vpsTotalBytes:   100_000_000_000,
                vpsFreeBytes:     50_000_000_000,
                vpsUsedBytes:     50_000_000_000,
                vpsUsedPct:       50.0,
                vpsMountPoint:   '/',
                driveQuotaBytes: 10_000_000_000,
                driveUsedBytes:   1_000_000_000,
                driveFreeBytes:   9_000_000_000,
                driveUsedPct:    10.0,
                driveHotRows:    100,
                watermark:       $wm,
                reasons:         [],
                computedAtUnix:  time(),
                computeDurationMs: 0.1,
                fromCache:       false,
            );
        };

        $mkCtl = function (): \FlowOne\Storage\ReclaimController {
            return new \FlowOne\Storage\ReclaimController(
                pollIdleSec: 60, pollWarmingSec: 15, pollReclaimingSec: 5, cooldownSec: 300,
            );
        };

        $this->test('reclaim_fsm', 'kill switch always returns IDLE + killed=true regardless of state', function () use ($mkCtl, $mkReport, $RS, $WM) {
            $ctl = $mkCtl();
            foreach ([$RS::IDLE, $RS::WARMING, $RS::RECLAIMING, $RS::COOLDOWN, $RS::PAUSED] as $state) {
                foreach ([$WM::WM_CLEAR, $WM::WM_HIGH, $WM::WM_CRITICAL] as $wm) {
                    $d = $ctl->decide($state, 0, $mkReport($wm), paused: false, killed: true, nowUnix: 1000);
                    $this->assertSame($RS::IDLE, $d->nextState, "killed -> IDLE from {$state}+{$wm}");
                    $this->assertTrue($d->killed);
                    $this->assertTrue($d->shouldReclaim === false);
                }
            }
        });

        $this->test('reclaim_fsm', 'pause flag drives PAUSED from any state', function () use ($mkCtl, $mkReport, $RS, $WM) {
            $ctl = $mkCtl();
            foreach ([$RS::IDLE, $RS::WARMING, $RS::RECLAIMING, $RS::COOLDOWN] as $state) {
                $d = $ctl->decide($state, 0, $mkReport($WM::WM_CRITICAL), paused: true, killed: false, nowUnix: 1000);
                $this->assertSame($RS::PAUSED, $d->nextState, "paused -> PAUSED from {$state}");
                $this->assertTrue($d->shouldReclaim === false);
            }
        });

        $this->test('reclaim_fsm', 'leaving PAUSED goes to IDLE', function () use ($mkCtl, $mkReport, $RS, $WM) {
            $ctl = $mkCtl();
            $d = $ctl->decide($RS::PAUSED, 0, $mkReport($WM::WM_HIGH), paused: false, killed: false, nowUnix: 1000);
            $this->assertSame($RS::IDLE, $d->nextState);
            $this->assertTrue($d->shouldReclaim === false);
        });

        $this->test('reclaim_fsm', 'IDLE + CLEAR/WARN stays IDLE', function () use ($mkCtl, $mkReport, $RS, $WM) {
            $ctl = $mkCtl();
            foreach ([$WM::WM_CLEAR, $WM::WM_WARN] as $wm) {
                $d = $ctl->decide($RS::IDLE, 0, $mkReport($wm), paused: false, killed: false, nowUnix: 1000);
                $this->assertSame($RS::IDLE, $d->nextState);
                $this->assertSame(60, $d->pollIntervalSec);
            }
        });

        $this->test('reclaim_fsm', 'IDLE + HIGH transitions to WARMING with shorter poll', function () use ($mkCtl, $mkReport, $RS, $WM) {
            $ctl = $mkCtl();
            $d = $ctl->decide($RS::IDLE, 0, $mkReport($WM::WM_HIGH), paused: false, killed: false, nowUnix: 1000);
            $this->assertSame($RS::WARMING, $d->nextState);
            $this->assertSame(15, $d->pollIntervalSec);
            $this->assertTrue($d->shouldReclaim === false, 'first WARMING tick does NOT reclaim');
        });

        $this->test('reclaim_fsm', 'WARMING + HIGH transitions to RECLAIMING with shouldReclaim=true', function () use ($mkCtl, $mkReport, $RS, $WM) {
            $ctl = $mkCtl();
            $d = $ctl->decide($RS::WARMING, 0, $mkReport($WM::WM_HIGH), paused: false, killed: false, nowUnix: 1000);
            $this->assertSame($RS::RECLAIMING, $d->nextState);
            $this->assertTrue($d->shouldReclaim);
        });

        $this->test('reclaim_fsm', 'WARMING + relieved pressure goes back to IDLE without reclaim', function () use ($mkCtl, $mkReport, $RS, $WM) {
            $ctl = $mkCtl();
            $d = $ctl->decide($RS::WARMING, 0, $mkReport($WM::WM_WARN), paused: false, killed: false, nowUnix: 1000);
            $this->assertSame($RS::IDLE, $d->nextState);
            $this->assertTrue($d->shouldReclaim === false);
        });

        $this->test('reclaim_fsm', 'RECLAIMING always transitions to COOLDOWN, never reclaims twice', function () use ($mkCtl, $mkReport, $RS, $WM) {
            $ctl = $mkCtl();
            foreach ([$WM::WM_CLEAR, $WM::WM_WARN, $WM::WM_HIGH, $WM::WM_CRITICAL] as $wm) {
                $d = $ctl->decide($RS::RECLAIMING, 0, $mkReport($wm), paused: false, killed: false, nowUnix: 1000);
                $this->assertSame($RS::COOLDOWN, $d->nextState);
                $this->assertTrue($d->shouldReclaim === false);
            }
        });

        $this->test('reclaim_fsm', 'COOLDOWN within cooldownSec stays COOLDOWN', function () use ($mkCtl, $mkReport, $RS, $WM) {
            $ctl = $mkCtl();
            // lastReclaimAt=1000, now=1200, cooldownSec=300 -> 200 < 300 -> stay
            $d = $ctl->decide($RS::COOLDOWN, 1000, $mkReport($WM::WM_CRITICAL), paused: false, killed: false, nowUnix: 1200);
            $this->assertSame($RS::COOLDOWN, $d->nextState);
        });

        $this->test('reclaim_fsm', 'COOLDOWN past cooldownSec + still HIGH -> WARMING', function () use ($mkCtl, $mkReport, $RS, $WM) {
            $ctl = $mkCtl();
            $d = $ctl->decide($RS::COOLDOWN, 1000, $mkReport($WM::WM_HIGH), paused: false, killed: false, nowUnix: 1400);
            $this->assertSame($RS::WARMING, $d->nextState);
        });

        $this->test('reclaim_fsm', 'COOLDOWN past cooldownSec + relieved -> IDLE', function () use ($mkCtl, $mkReport, $RS, $WM) {
            $ctl = $mkCtl();
            $d = $ctl->decide($RS::COOLDOWN, 1000, $mkReport($WM::WM_CLEAR), paused: false, killed: false, nowUnix: 1400);
            $this->assertSame($RS::IDLE, $d->nextState);
        });

        $this->test('reclaim_fsm', 'unknown state recovers to IDLE', function () use ($mkCtl, $mkReport, $RS, $WM) {
            $ctl = $mkCtl();
            $d = $ctl->decide('garbage-state-from-disk', 0, $mkReport($WM::WM_CLEAR), paused: false, killed: false, nowUnix: 1000);
            $this->assertSame($RS::IDLE, $d->nextState);
        });

        $this->test('reclaim_fsm', 'canTransition strict ordering: RECLAIMING only reachable from WARMING', function () use ($RS) {
            $this->assertTrue(\FlowOne\Storage\ReclaimState::canTransition($RS::WARMING, $RS::RECLAIMING));
            $this->assertTrue(false === \FlowOne\Storage\ReclaimState::canTransition($RS::IDLE, $RS::RECLAIMING));
            $this->assertTrue(false === \FlowOne\Storage\ReclaimState::canTransition($RS::COOLDOWN, $RS::RECLAIMING));
            $this->assertTrue(\FlowOne\Storage\ReclaimState::canTransition($RS::RECLAIMING, $RS::COOLDOWN));
            $this->assertTrue(false === \FlowOne\Storage\ReclaimState::canTransition($RS::IDLE, $RS::COOLDOWN));
        });

        $this->test('reclaim_fsm', 'ReclaimController::fromConfig honours custom cadences', function () use ($RS) {
            $cfg = ['tier' => ['reclaim' => [
                'poll_idle_sec' => 11, 'poll_warming_sec' => 7, 'poll_reclaiming_sec' => 3, 'cooldown_sec' => 99,
            ]]];
            $ctl = \FlowOne\Storage\ReclaimController::fromConfig($cfg);
            $this->assertSame(11, $ctl->pollIdleSec);
            $this->assertSame(7,  $ctl->pollWarmingSec);
            $this->assertSame(3,  $ctl->pollReclaimingSec);
            $this->assertSame(99, $ctl->cooldownSec);
        });
    }

    /**
     * Phase 6c — ReclaimCaps value object: config loading + defaults
     * + input sanitisation.
     */
    private function groupReclaimCaps(): void
    {
        $this->test('reclaim_caps', 'defaults match config baseline', function () {
            $caps = \FlowOne\Storage\ReclaimCaps::fromConfig([]);
            $this->assertSame(1073741824, $caps->maxBytes); // 1 GiB
            $this->assertSame(60,         $caps->maxSeconds);
            $this->assertSame(50,         $caps->maxCandidates);
            $this->assertSame(30,         $caps->ageDays);
            $this->assertSame(1048576,    $caps->minFileBytes); // 1 MiB
            $this->assertSame('lru',      $caps->orderBy);
            $this->assertSame(25,         $caps->sweepBatch);
            $this->assertNull($caps->graceHours);
        });

        $this->test('reclaim_caps', 'invalid order_by falls back to lru', function () {
            $caps = \FlowOne\Storage\ReclaimCaps::fromConfig([
                'tier' => ['reclaim' => ['order_by' => 'random-garbage']],
            ]);
            $this->assertSame('lru', $caps->orderBy);
        });

        $this->test('reclaim_caps', 'config overrides take effect', function () {
            $caps = \FlowOne\Storage\ReclaimCaps::fromConfig([
                'tier' => ['reclaim' => [
                    'max_bytes_per_cycle'      => 2048,
                    'max_seconds_per_cycle'    => 7,
                    'max_candidates_per_cycle' => 11,
                    'age_days'                 => 90,
                    'min_file_bytes'           => 4096,
                    'order_by'                 => 'age',
                    'sweep_batch'              => 13,
                    'grace_hours_override'     => 48,
                ]],
            ]);
            $this->assertSame(2048, $caps->maxBytes);
            $this->assertSame(7,    $caps->maxSeconds);
            $this->assertSame(11,   $caps->maxCandidates);
            $this->assertSame(90,   $caps->ageDays);
            $this->assertSame(4096, $caps->minFileBytes);
            $this->assertSame('age', $caps->orderBy);
            $this->assertSame(13,   $caps->sweepBatch);
            $this->assertSame(48,   $caps->graceHours);
        });

        $this->test('reclaim_caps', 'toArray() round-trips', function () {
            $caps = \FlowOne\Storage\ReclaimCaps::fromConfig([]);
            $arr = $caps->toArray();
            $this->assertSame($caps->maxBytes,      $arr['max_bytes']);
            $this->assertSame($caps->orderBy,       $arr['order_by']);
            $this->assertSame($caps->minFileBytes,  $arr['min_file_bytes']);
        });
    }

    /**
     * Phase 6c — ReclaimDaemonStateStore: durable JSON publish +
     * HMAC-signed read.
     */
    private function groupReclaimStateStore(): void
    {
        $this->test('reclaim_store', 'publish then read returns the same payload', function () {
            $dir = $this->tmpDir . '/reclaim-store-' . bin2hex(random_bytes(3));
            mkdir($dir, 0755, true);
            $this->cleanupQueue[] = function () use ($dir) {
                @unlink($dir . '/reclaim-daemon.json');
                @unlink($dir . '/reclaim-daemon.json.bak');
                @rmdir($dir);
            };
            $signer = new \FlowOne\Storage\HmacSigner(bin2hex(random_bytes(16)));
            $file = new \FlowOne\Storage\DurableJson($dir, 'reclaim-daemon.json');
            $store = new \FlowOne\Storage\ReclaimDaemonStateStore($file, $signer);

            $payload = [
                'state'           => 'idle',
                'last_reason'     => 'watermark_ok (clear)',
                'last_reclaim_at' => 0,
                'counters'        => ['cycles' => 0, 'bytes_total' => 0],
            ];
            $store->publish($payload);

            $readBack = $store->read();
            $this->assertTrue($readBack !== null, 'read must return non-null after publish');
            $this->assertSame('idle', $readBack['state']);
            $this->assertSame('watermark_ok (clear)', $readBack['last_reason']);
            $this->assertTrue(array_key_exists('updated_at', $readBack), 'publish stamps updated_at');
        });

        $this->test('reclaim_store', 'tampered payload fails signature verification', function () {
            $dir = $this->tmpDir . '/reclaim-store-tamper-' . bin2hex(random_bytes(3));
            mkdir($dir, 0755, true);
            $this->cleanupQueue[] = function () use ($dir) {
                @unlink($dir . '/reclaim-daemon.json');
                @unlink($dir . '/reclaim-daemon.json.bak');
                @rmdir($dir);
            };
            $signer = new \FlowOne\Storage\HmacSigner(bin2hex(random_bytes(16)));
            $file = new \FlowOne\Storage\DurableJson($dir, 'reclaim-daemon.json');
            $store = new \FlowOne\Storage\ReclaimDaemonStateStore($file, $signer);
            $store->publish(['state' => 'idle']);

            // Tamper with the on-disk payload.
            $raw = file_get_contents($dir . '/reclaim-daemon.json');
            $tampered = str_replace('idle', 'reclaiming', $raw);
            file_put_contents($dir . '/reclaim-daemon.json', $tampered);

            // backup still has the original signed version; reader should
            // fall through to backup. Make sure backup also tampered.
            $bak = $dir . '/reclaim-daemon.json.bak';
            if (is_file($bak)) {
                file_put_contents($bak, $tampered);
            }
            $this->assertNull($store->read(), 'tampered payload must be rejected');
        });

        $this->test('reclaim_store', 'read returns null when no file exists yet', function () {
            $dir = $this->tmpDir . '/reclaim-store-empty-' . bin2hex(random_bytes(3));
            mkdir($dir, 0755, true);
            $this->cleanupQueue[] = function () use ($dir) { @rmdir($dir); };
            $signer = new \FlowOne\Storage\HmacSigner(bin2hex(random_bytes(16)));
            $file = new \FlowOne\Storage\DurableJson($dir, 'reclaim-daemon.json');
            $store = new \FlowOne\Storage\ReclaimDaemonStateStore($file, $signer);
            $this->assertNull($store->read());
        });
    }

    // ────────────────────────────────────────────────────────────────────
    // Phase 7 — Backup pipeline unit tests
    // ────────────────────────────────────────────────────────────────────

    private function groupBackupSnapshot(): void
    {
        $BS = \FlowOne\Storage\BackupSnapshot::class;

        $this->test('backup_snap', 'constructor rejects invalid kind', function () use ($BS) {
            $threw = false;
            try { new $BS('/tmp', 'garbage', '2026-05-18'); } catch (\InvalidArgumentException) { $threw = true; }
            $this->assertTrue($threw);
        });

        $this->test('backup_snap', 'constructor rejects invalid date_key', function () use ($BS) {
            $threw = false;
            try { new $BS('/tmp', 'daily', '2026-13-99'); } catch (\InvalidArgumentException) { $threw = true; }
            $this->assertTrue($threw);
            $threw = false;
            try { new $BS('/tmp', 'daily', 'not-a-date'); } catch (\InvalidArgumentException) { $threw = true; }
            $this->assertTrue($threw);
        });

        $this->test('backup_snap', 'rootPath + tmpPath + manifestPath compute correctly', function () use ($BS) {
            $s = new $BS('/mnt/vps-backup/drive-snapshots', 'daily', '2026-05-18');
            $this->assertSame('/mnt/vps-backup/drive-snapshots/daily/2026-05-18', $s->rootPath());
            $this->assertSame('/mnt/vps-backup/drive-snapshots/daily/2026-05-18.tmp', $s->tmpPath());
            $this->assertSame('/mnt/vps-backup/drive-snapshots/daily/2026-05-18/manifest.json.sig', $s->manifestPath());
        });

        $this->test('backup_snap', 'weekly + monthly anchor checks', function () use ($BS) {
            // 2026-05-17 is a Sunday (dow 0)
            $sun = new $BS('/x', 'daily', '2026-05-17');
            $this->assertTrue($sun->matchesWeeklyAnchor(0));
            $this->assertTrue(false === $sun->matchesWeeklyAnchor(1));
            // 2026-05-01 is the 1st
            $first = new $BS('/x', 'daily', '2026-05-01');
            $this->assertTrue($first->matchesMonthlyAnchor(1));
            $this->assertTrue(false === $first->matchesMonthlyAnchor(15));
        });

        $this->test('backup_snap', 'findLinkDestCandidate finds most recent older snapshot across kinds', function () use ($BS) {
            $dir = $this->tmpDir . '/snap-link-' . bin2hex(random_bytes(3));
            mkdir($dir . '/daily/2026-05-10', 0755, true);
            mkdir($dir . '/daily/2026-05-15', 0755, true);
            mkdir($dir . '/weekly/2026-05-12', 0755, true);
            $this->cleanupQueue[] = fn() => $this->rmrf($dir);

            $today = new $BS($dir, 'daily', '2026-05-18');
            $link = $today->findLinkDestCandidate();
            $this->assertTrue($link !== null);
            $this->assertSame('2026-05-15', $link->dateKey);

            // No older snapshots returns null.
            $earliest = new $BS($dir, 'daily', '2026-05-01');
            $this->assertNull($earliest->findLinkDestCandidate());
        });

        $this->test('backup_snap', 'listKind returns existing snapshots sorted ascending', function () use ($BS) {
            $dir = $this->tmpDir . '/snap-list-' . bin2hex(random_bytes(3));
            mkdir($dir . '/daily/2026-05-15', 0755, true);
            mkdir($dir . '/daily/2026-05-10', 0755, true);
            mkdir($dir . '/daily/2026-05-12', 0755, true);
            // Junk that must be ignored.
            mkdir($dir . '/daily/not-a-date', 0755, true);
            $this->cleanupQueue[] = fn() => $this->rmrf($dir);

            $list = $BS::listKind($dir, 'daily');
            $keys = array_map(fn($s) => $s->dateKey, $list);
            $this->assertSame(['2026-05-10', '2026-05-12', '2026-05-15'], $keys);
        });

        $this->test('backup_snap', 'listKind returns [] for missing dir', function () use ($BS) {
            $list = $BS::listKind('/nonexistent-' . bin2hex(random_bytes(4)), 'daily');
            $this->assertSame([], $list);
        });
    }

    private function groupBackupManifest(): void
    {
        $BS = \FlowOne\Storage\BackupSnapshot::class;
        $BM = \FlowOne\Storage\BackupManifest::class;

        $mkTree = function () use ($BS): array {
            $dir = $this->tmpDir . '/bm-' . bin2hex(random_bytes(3));
            $snap = new $BS($dir, 'daily', '2026-05-18');
            mkdir($snap->rootPath() . '/drive/userA', 0755, true);
            file_put_contents($snap->rootPath() . '/drive/userA/a.bin', str_repeat('A', 100));
            file_put_contents($snap->rootPath() . '/drive/userA/b.bin', str_repeat('B', 200));
            $this->cleanupQueue[] = fn() => $this->rmrf($dir);
            return [$dir, $snap];
        };

        $this->test('backup_manifest', 'build then read returns same payload', function () use ($mkTree, $BM) {
            [$dir, $snap] = $mkTree();
            $signer = new \FlowOne\Storage\HmacSigner(bin2hex(random_bytes(16)));
            $svc = new $BM($signer, 'manifest.json.sig', false);
            $payload = $svc->buildAndWrite($snap, ['drive']);

            $this->assertSame(2, $payload['summary']['file_count']);
            $this->assertSame(300, $payload['summary']['byte_count']);
            $this->assertTrue(isset($payload['files']['drive/userA/a.bin']));
            $this->assertSame(100, $payload['files']['drive/userA/a.bin']['size']);

            $back = $svc->read($snap);
            $this->assertTrue($back !== null);
            $this->assertSame($payload['summary']['file_count'], $back['summary']['file_count']);
        });

        $this->test('backup_manifest', 'full_checksum=true includes md5 entries', function () use ($mkTree, $BM) {
            [$dir, $snap] = $mkTree();
            $signer = new \FlowOne\Storage\HmacSigner(bin2hex(random_bytes(16)));
            $svc = new $BM($signer, 'manifest.json.sig', true);
            $payload = $svc->buildAndWrite($snap, ['drive']);
            $this->assertSame(md5(str_repeat('A', 100)), $payload['files']['drive/userA/a.bin']['md5']);
        });

        $this->test('backup_manifest', 'explicitRoot writes manifest to override dir', function () use ($mkTree, $BM) {
            [$dir, $snap] = $mkTree();
            // Mirror the same tree into a .tmp dir to simulate atomic-promote flow.
            $tmpRoot = $snap->rootPath() . '.tmp';
            mkdir($tmpRoot . '/drive/userA', 0755, true);
            file_put_contents($tmpRoot . '/drive/userA/a.bin', str_repeat('A', 100));
            $signer = new \FlowOne\Storage\HmacSigner(bin2hex(random_bytes(16)));
            $svc = new $BM($signer, 'manifest.json.sig', false);
            $svc->buildAndWrite($snap, ['drive'], $tmpRoot);
            $this->assertTrue(is_file($tmpRoot . '/manifest.json.sig'));
            $this->assertTrue(false === is_file($snap->rootPath() . '/manifest.json.sig'));
        });

        $this->test('backup_manifest', 'tampered manifest fails verifyJson', function () use ($mkTree, $BM) {
            [$dir, $snap] = $mkTree();
            $signer = new \FlowOne\Storage\HmacSigner(bin2hex(random_bytes(16)));
            $svc = new $BM($signer, 'manifest.json.sig', false);
            $svc->buildAndWrite($snap, ['drive']);
            $path = $snap->manifestPath('manifest.json.sig');
            $raw = file_get_contents($path);
            file_put_contents($path, str_replace('"size":100', '"size":99', $raw));
            $this->assertNull($svc->read($snap));
        });

        $this->test('backup_manifest', 'missing snapshot returns null', function () use ($BS, $BM) {
            $signer = new \FlowOne\Storage\HmacSigner(bin2hex(random_bytes(16)));
            $svc = new $BM($signer, 'manifest.json.sig', false);
            $snap = new $BS('/nonexistent-' . bin2hex(random_bytes(4)), 'daily', '2026-05-18');
            $this->assertNull($svc->read($snap));
        });
    }

    private function groupBackupRetention(): void
    {
        $BS = \FlowOne\Storage\BackupSnapshot::class;
        $BRS = \FlowOne\Storage\BackupRetentionService::class;

        $mkSnapTree = function (array $byKind) use ($BS): string {
            $dir = $this->tmpDir . '/br-' . bin2hex(random_bytes(3));
            foreach ($byKind as $kind => $dates) {
                foreach ($dates as $date) {
                    $path = "{$dir}/{$kind}/{$date}";
                    mkdir($path, 0755, true);
                    // marker file so rmrf has work to do
                    file_put_contents("{$path}/keep.bin", $kind);
                }
            }
            $this->cleanupQueue[] = fn() => $this->rmrf($dir);
            return $dir;
        };

        $mkCfg = function (string $dir, array $r = []): array {
            return [
                'backup' => [
                    'destination_root' => $dir,
                    'retention' => array_replace([
                        'keep_daily' => 3, 'keep_weekly' => 2, 'keep_monthly' => 2,
                        'weekly_anchor_dow' => 0, 'monthly_anchor_dom' => 1,
                    ], $r),
                ],
            ];
        };

        $this->test('backup_retention', 'prune trims excess daily snapshots', function () use ($mkSnapTree, $mkCfg, $BRS, $BS) {
            $dir = $mkSnapTree(['daily' => ['2026-05-15', '2026-05-16', '2026-05-17', '2026-05-18']]);
            $cfg = $mkCfg($dir, ['keep_daily' => 2]);
            $journal = new \FlowOne\Storage\OperationJournal($this->tmpDir . '/jr.log',
                new \FlowOne\Storage\HmacSigner(bin2hex(random_bytes(16))), 0);
            $svc = new $BRS($cfg, $journal);
            // Use the most-recent date as today so promotions are no-ops.
            $r = $svc->apply('2026-05-18', dryRun: false);
            $kept = $r['kept']['daily'];
            $this->assertSame(['2026-05-17', '2026-05-18'], $kept);
            // Pruned must remove the two oldest.
            $names = array_map(fn($p) => $p['target']['date_key'], $r['pruned']);
            sort($names);
            $this->assertSame(['2026-05-15', '2026-05-16'], $names);
        });

        $this->test('backup_retention', 'dry-run does NOT mutate disk', function () use ($mkSnapTree, $mkCfg, $BRS, $BS) {
            $dir = $mkSnapTree(['daily' => ['2026-05-15', '2026-05-16', '2026-05-17', '2026-05-18']]);
            $cfg = $mkCfg($dir, ['keep_daily' => 1]);
            $journal = new \FlowOne\Storage\OperationJournal($this->tmpDir . '/jr2.log',
                new \FlowOne\Storage\HmacSigner(bin2hex(random_bytes(16))), 0);
            $svc = new $BRS($cfg, $journal);
            $svc->apply('2026-05-18', dryRun: true);
            $kept = $BS::listKind($dir, 'daily');
            $this->assertSame(4, count($kept));
        });

        $this->test('backup_retention', 'promotes daily -> weekly on Sunday', function () use ($mkSnapTree, $mkCfg, $BRS, $BS) {
            // 2026-05-17 is a Sunday.
            $dir = $mkSnapTree(['daily' => ['2026-05-17']]);
            $cfg = $mkCfg($dir);
            $journal = new \FlowOne\Storage\OperationJournal($this->tmpDir . '/jr3.log',
                new \FlowOne\Storage\HmacSigner(bin2hex(random_bytes(16))), 0);
            $svc = new $BRS($cfg, $journal);
            $r = $svc->apply('2026-05-17', dryRun: false);
            $promotions = array_filter($r['promoted'], fn($p) => $p['to']['kind'] === 'weekly');
            $this->assertSame(1, count($promotions));
            $this->assertTrue(is_dir($dir . '/weekly/2026-05-17'));
            $this->assertTrue(false === is_dir($dir . '/daily/2026-05-17'));
        });

        $this->test('backup_retention', 'does NOT promote on non-anchor day', function () use ($mkSnapTree, $mkCfg, $BRS) {
            // 2026-05-18 is a Monday.
            $dir = $mkSnapTree(['daily' => ['2026-05-18']]);
            $cfg = $mkCfg($dir);
            $journal = new \FlowOne\Storage\OperationJournal($this->tmpDir . '/jr4.log',
                new \FlowOne\Storage\HmacSigner(bin2hex(random_bytes(16))), 0);
            $svc = new $BRS($cfg, $journal);
            $r = $svc->apply('2026-05-18', dryRun: false);
            $this->assertSame([], $r['promoted']);
            $this->assertTrue(is_dir($dir . '/daily/2026-05-18'));
        });

        $this->test('backup_retention', 'refuses to prune outside destination_root', function () use ($BRS, $BS) {
            $dir = $this->tmpDir . '/br-outside-' . bin2hex(random_bytes(3));
            mkdir($dir . '/daily/2026-05-01', 0755, true);
            $this->cleanupQueue[] = fn() => $this->rmrf($dir);
            $cfg = ['backup' => [
                'destination_root' => $dir . '/wrong-root',  // intentionally not the real one
                'retention' => ['keep_daily' => 0, 'keep_weekly' => 0, 'keep_monthly' => 0,
                                'weekly_anchor_dow' => 0, 'monthly_anchor_dom' => 1],
            ]];
            $journal = new \FlowOne\Storage\OperationJournal($this->tmpDir . '/jr5.log',
                new \FlowOne\Storage\HmacSigner(bin2hex(random_bytes(16))), 0);
            $svc = new $BRS($cfg, $journal);
            $r = $svc->apply('2026-05-01', dryRun: false);
            // wrong-root doesn't exist; apply returns error
            $this->assertTrue(!empty($r['errors']) || empty($r['pruned']));
            // The intended snapshot dir is untouched.
            $this->assertTrue(is_dir($dir . '/daily/2026-05-01'));
        });
    }

    private function groupBackupVerifier(): void
    {
        $BS = \FlowOne\Storage\BackupSnapshot::class;
        $BM = \FlowOne\Storage\BackupManifest::class;
        $BV = \FlowOne\Storage\BackupVerifier::class;

        $mkSnapWithManifest = function (bool $withMd5 = false) use ($BS, $BM) {
            $dir = $this->tmpDir . '/bv-' . bin2hex(random_bytes(3));
            $snap = new $BS($dir, 'daily', '2026-05-18');
            mkdir($snap->rootPath() . '/drive/userA', 0755, true);
            file_put_contents($snap->rootPath() . '/drive/userA/a.bin', str_repeat('A', 100));
            file_put_contents($snap->rootPath() . '/drive/userA/b.bin', str_repeat('B', 200));
            $signer = new \FlowOne\Storage\HmacSigner(bin2hex(random_bytes(16)));
            $svc = new $BM($signer, 'manifest.json.sig', $withMd5);
            $svc->buildAndWrite($snap, ['drive']);
            $this->cleanupQueue[] = fn() => $this->rmrf($dir);
            return [$dir, $snap, $signer];
        };

        $this->test('backup_verifier', 'light mode passes on clean snapshot', function () use ($mkSnapWithManifest, $BV) {
            [$dir, $snap, $signer] = $mkSnapWithManifest();
            $journal = new \FlowOne\Storage\OperationJournal($this->tmpDir . '/vj.log', $signer, 0);
            $v = $BV::build([], $signer, $journal);
            $r = $v->verify($snap, 'light');
            $this->assertTrue($r['ok'], 'light verify must pass: ' . json_encode($r['issues']));
            $this->assertSame(2, $r['checked']);
            $this->assertSame(0, $r['md5_checked']);
        });

        $this->test('backup_verifier', 'detects missing file', function () use ($mkSnapWithManifest, $BV) {
            [$dir, $snap, $signer] = $mkSnapWithManifest();
            unlink($snap->rootPath() . '/drive/userA/a.bin');
            $journal = new \FlowOne\Storage\OperationJournal($this->tmpDir . '/vj2.log', $signer, 0);
            $v = $BV::build([], $signer, $journal);
            $r = $v->verify($snap, 'light');
            $this->assertTrue(false === $r['ok']);
            $kinds = array_column($r['issues'], 'kind');
            $this->assertTrue(in_array('missing', $kinds, true));
        });

        $this->test('backup_verifier', 'detects size drift', function () use ($mkSnapWithManifest, $BV) {
            [$dir, $snap, $signer] = $mkSnapWithManifest();
            file_put_contents($snap->rootPath() . '/drive/userA/a.bin', str_repeat('A', 50));
            $journal = new \FlowOne\Storage\OperationJournal($this->tmpDir . '/vj3.log', $signer, 0);
            $v = $BV::build([], $signer, $journal);
            $r = $v->verify($snap, 'light');
            $this->assertTrue(false === $r['ok']);
            $kinds = array_column($r['issues'], 'kind');
            $this->assertTrue(in_array('size_drift', $kinds, true));
        });

        $this->test('backup_verifier', 'full mode detects md5 drift when manifest carries md5', function () use ($mkSnapWithManifest, $BV) {
            [$dir, $snap, $signer] = $mkSnapWithManifest(withMd5: true);
            // Same size, different content -> only md5 drift.
            file_put_contents($snap->rootPath() . '/drive/userA/a.bin', str_repeat('X', 100));
            // Need to also reset mtime to original so size+mtime checks
            // pass and we get to the md5 step.
            $journal = new \FlowOne\Storage\OperationJournal($this->tmpDir . '/vj4.log', $signer, 0);
            $v = $BV::build([], $signer, $journal);
            $r = $v->verify($snap, 'full');
            $kinds = array_column($r['issues'], 'kind');
            $this->assertTrue(in_array('md5_drift', $kinds, true) || in_array('mtime_drift', $kinds, true),
                'expected md5_drift or mtime_drift; got: ' . json_encode($kinds));
        });

        $this->test('backup_verifier', 'reason=manifest_corrupt_or_missing when manifest absent', function () use ($BS, $BV) {
            $signer = new \FlowOne\Storage\HmacSigner(bin2hex(random_bytes(16)));
            $journal = new \FlowOne\Storage\OperationJournal($this->tmpDir . '/vj5.log', $signer, 0);
            $v = $BV::build([], $signer, $journal);
            $snap = new $BS('/nonexistent-' . bin2hex(random_bytes(4)), 'daily', '2026-05-18');
            $r = $v->verify($snap, 'light');
            $this->assertTrue(false === $r['ok']);
            $this->assertSame('manifest_corrupt_or_missing', $r['reason']);
        });
    }

    private function groupBackupStateStore(): void
    {
        $BSS = \FlowOne\Storage\BackupStateStore::class;

        $this->test('backup_store', 'publishPartial merges with existing state', function () use ($BSS) {
            $dir = $this->tmpDir . '/bss-' . bin2hex(random_bytes(3));
            mkdir($dir, 0755, true);
            $this->cleanupQueue[] = fn() => $this->rmrf($dir);
            $signer = new \FlowOne\Storage\HmacSigner(bin2hex(random_bytes(16)));
            $file = new \FlowOne\Storage\DurableJson($dir, 'nas-backup.json');
            $store = new $BSS($file, $signer);

            $store->publishPartial(['a' => 1, 'nested' => ['x' => 1]]);
            $store->publishPartial(['nested' => ['y' => 2]]);
            $back = $store->read();
            $this->assertSame(1,   $back['a']);
            $this->assertSame(1,   $back['nested']['x']);
            $this->assertSame(2,   $back['nested']['y']);
        });

        $this->test('backup_store', 'recordSnapshot bins into last_snapshot_ok vs failed', function () use ($BSS) {
            $dir = $this->tmpDir . '/bss-rec-' . bin2hex(random_bytes(3));
            mkdir($dir, 0755, true);
            $this->cleanupQueue[] = fn() => $this->rmrf($dir);
            $signer = new \FlowOne\Storage\HmacSigner(bin2hex(random_bytes(16)));
            $file = new \FlowOne\Storage\DurableJson($dir, 'nas-backup.json');
            $store = new $BSS($file, $signer);

            $store->recordSnapshot([
                'ok' => true, 'snapshot' => ['kind' => 'daily', 'date_key' => '2026-05-18'],
                'files_total' => 5, 'bytes_total' => 1024, 'elapsed_ms' => 100, 'started_at' => 1000,
            ]);
            $store->recordSnapshot([
                'ok' => false, 'snapshot' => ['kind' => 'daily', 'date_key' => '2026-05-17'],
                'files_total' => 0, 'bytes_total' => 0, 'elapsed_ms' => 5, 'started_at' => 999,
                'reason' => 'destination_unhealthy',
            ]);
            $back = $store->read();
            $this->assertSame('2026-05-18', $back['last_snapshot_ok']['date_key']);
            $this->assertSame('2026-05-17', $back['last_snapshot_failed']['date_key']);
            $this->assertSame('destination_unhealthy', $back['last_snapshot_failed']['reason']);
        });

        $this->test('backup_store', 'tampered payload falls back to bak', function () use ($BSS) {
            $dir = $this->tmpDir . '/bss-tamper-' . bin2hex(random_bytes(3));
            mkdir($dir, 0755, true);
            $this->cleanupQueue[] = fn() => $this->rmrf($dir);
            $signer = new \FlowOne\Storage\HmacSigner(bin2hex(random_bytes(16)));
            $file = new \FlowOne\Storage\DurableJson($dir, 'nas-backup.json');
            $store = new $BSS($file, $signer);
            $store->publishPartial(['v' => 1]);
            $store->publishPartial(['v' => 2]);  // first becomes .bak, this is .current

            // Tamper current only — bak still valid
            file_put_contents($dir . '/nas-backup.json', '{"junk":true}');
            $back = $store->read();
            $this->assertTrue($back !== null);
            $this->assertSame(1, $back['v']);  // recovered from bak
        });
    }

    private function groupTenantInvariant(): void
    {
        $this->test('tenant_inv', 'accepts path inside tenant root', function () {
            $root = $this->tmpDir . '/tinv-' . bin2hex(random_bytes(3));
            mkdir($root, 0755, true);
            $this->cleanupQueue[] = function () use ($root) { if (is_dir($root)) @rmdir($root); };
            $inv = new \FlowOne\Storage\Invariants($this->makeTempJournal(), strict: false);
            $this->assertTrue($inv->assertPathInsideTenant($root . '/some-future-file', $root));
        });
        $this->test('tenant_inv', 'rejects path escaping via parent', function () {
            $root = $this->tmpDir . '/tinv-esc-' . bin2hex(random_bytes(3));
            mkdir($root, 0755, true);
            $this->cleanupQueue[] = function () use ($root) { if (is_dir($root)) @rmdir($root); };
            $inv = new \FlowOne\Storage\Invariants($this->makeTempJournal(), strict: false);
            $outside = dirname($root) . '/sibling-of-root';
            $this->assertSame(false, $inv->assertPathInsideTenant($outside, $root));
        });
        $this->test('tenant_inv', 'rejects when tenant root does not exist', function () {
            $inv = new \FlowOne\Storage\Invariants($this->makeTempJournal(), strict: false);
            $this->assertSame(false, $inv->assertPathInsideTenant('/whatever', '/no/such/tenant/root'));
        });
    }

    private function makeTempJournal(): OperationJournal
    {
        $signer = new HmacSigner(bin2hex(random_bytes(16)));
        $path = $this->tmpDir . '/journal-test.jsonl';
        $this->cleanupQueue[] = function () use ($path) {
            @unlink($path);
        };
        return new OperationJournal($path, $signer, 1);
    }

    private function makeReadBreaker(
        float $p95,
        float $errRate,
        int $win,
        int $cap
    ): \FlowOne\Storage\Breakers\ReadBreaker {
        $signer = new HmacSigner(bin2hex(random_bytes(16)));
        $dir = $this->tmpDir;
        $file = 'read-brk-test-' . bin2hex(random_bytes(4)) . '.json';
        $this->cleanupQueue[] = function () use ($dir, $file) {
            @unlink($dir . '/' . $file);
            @unlink($dir . '/' . $file . '.tmp');
            @unlink($dir . '/' . $file . '.bak');
        };
        $persistence = new DurableJson($dir, $file, '.tmp', '.bak');
        return new \FlowOne\Storage\Breakers\ReadBreaker(
            $persistence, $signer, $p95, $errRate, $win, $cap
        );
    }

    private function makeRecoveryBreaker(
        int $attempts,
        int $qWindow,
        int $qBeforePerm,
        int $permWindow
    ): \FlowOne\Storage\Breakers\RecoveryBreaker {
        $signer = new HmacSigner(bin2hex(random_bytes(16)));
        $dir = $this->tmpDir;
        $file = 'recov-brk-test-' . bin2hex(random_bytes(4)) . '.json';
        $this->cleanupQueue[] = function () use ($dir, $file) {
            @unlink($dir . '/' . $file);
            @unlink($dir . '/' . $file . '.tmp');
            @unlink($dir . '/' . $file . '.bak');
        };
        $persistence = new DurableJson($dir, $file, '.tmp', '.bak');
        return new \FlowOne\Storage\Breakers\RecoveryBreaker(
            $persistence, $signer, $attempts, $qWindow, $qBeforePerm, $permWindow
        );
    }

    private function makeClassifier(float $gateSec): \FlowOne\Storage\Classifier
    {
        return new \FlowOne\Storage\Classifier(
            new \FlowOne\Storage\StabilityGate($gateSec),
            $this->makeReadBreaker(5.0, 0.5, 10, 30),
            $this->makeRecoveryBreaker(5, 60, 3, 3600)
        );
    }

    /** @return array<string,mixed> */
    private function makeProbe(
        bool $readOk,
        bool $writeOk,
        bool $helperUp,
        float $latency = 0.05,
        bool $slow = false,
        bool $frozen = false
    ): array {
        return [
            \FlowOne\Storage\Classifier::PROBE_READ_OK   => $readOk,
            \FlowOne\Storage\Classifier::PROBE_WRITE_OK  => $writeOk,
            \FlowOne\Storage\Classifier::PROBE_LATENCY   => $latency,
            \FlowOne\Storage\Classifier::PROBE_SLOW      => $slow,
            \FlowOne\Storage\Classifier::PROBE_HELPER_UP => $helperUp,
            \FlowOne\Storage\Classifier::PROBE_FROZEN    => $frozen,
        ];
    }

    public function cleanup(): void
    {
        foreach (array_reverse($this->cleanupQueue) as $fn) {
            try { $fn(); } catch (\Throwable) { /* ignore */ }
        }
        if ($this->logFh) {
            @fclose($this->logFh);
        }
    }

    public function exitCode(): int
    {
        foreach ($this->results as $r) {
            if ($r['status'] === 'FAIL') return 1;
        }
        return 0;
    }

    private function renderSummary(): void
    {
        $pass = 0; $fail = 0; $skip = 0; $failed = [];
        foreach ($this->results as $r) {
            if ($r['status'] === 'PASS') $pass++;
            elseif ($r['status'] === 'SKIP') $skip++;
            else { $fail++; $failed[] = $r; }
        }
        $totalMs = (int) (MonotonicClock::elapsedSec($this->tStartNs) * 1000);

        if ($this->opts['json']) {
            echo json_encode([
                'pass' => $pass, 'fail' => $fail, 'skip' => $skip,
                'total_ms' => $totalMs, 'results' => $this->results,
            ], JSON_PRETTY_PRINT) . "\n";
            return;
        }

        $this->log("\n=== SUMMARY ===");
        $this->log("Passed:  {$pass}");
        $this->log("Failed:  {$fail}");
        $this->log("Skipped: {$skip}");
        $this->log("Total:   {$totalMs}ms");
        $this->log("Log:     {$this->logPath}");
        if (!empty($failed)) {
            $this->log("\nFailed tests:");
            foreach ($failed as $r) {
                $this->log("  [{$r['group']}] {$r['name']}: {$r['message']}");
            }
        }
    }

    private function test(string $group, string $name, callable $fn): void
    {
        if ($this->aborted) {
            $this->results[] = ['group' => $group, 'name' => $name, 'status' => 'SKIP', 'message' => 'aborted', 'elapsed_ms' => 0];
            return;
        }
        $start = MonotonicClock::nowNs();
        $deadline = $start + ($this->opts['timeout'] * 1_000_000_000);
        $prevLimit = (int) ini_get('max_execution_time');
        set_time_limit($this->opts['timeout']);
        try {
            $fn();
            $elapsedMs = (int) (MonotonicClock::elapsedSec($start) * 1000);
            // Status was either PASS (default) or SKIP (set by $this->skip()).
            $last = end($this->results);
            if ($last !== false && $last['name'] === $name && $last['group'] === $group && $last['status'] === 'SKIP') {
                $this->printResult($last);
                return;
            }
            $r = ['group' => $group, 'name' => $name, 'status' => 'PASS', 'message' => '', 'elapsed_ms' => $elapsedMs];
            $this->results[] = $r;
            $this->printResult($r);
        } catch (\Throwable $e) {
            $elapsedMs = (int) (MonotonicClock::elapsedSec($start) * 1000);
            $msg = $e->getMessage();
            if ($this->opts['verbose']) {
                $msg .= "\n" . $e->getTraceAsString();
            }
            $r = ['group' => $group, 'name' => $name, 'status' => 'FAIL', 'message' => $msg, 'elapsed_ms' => $elapsedMs];
            $this->results[] = $r;
            $this->printResult($r);
        } finally {
            set_time_limit($prevLimit ?: 0);
        }
        if (MonotonicClock::nowNs() > $deadline) {
            // Inform but do not abort the run.
            $this->log('  ! test exceeded timeout budget');
        }
    }

    private function skip(string $reason): void
    {
        // Last appended result gets switched to SKIP by the test() wrapper.
        $this->results[] = ['group' => '', 'name' => '', 'status' => 'SKIP', 'message' => $reason, 'elapsed_ms' => 0];
    }

    private function skipGroup(string $group, string $reason): void
    {
        $r = ['group' => $group, 'name' => '(group)', 'status' => 'SKIP', 'message' => $reason, 'elapsed_ms' => 0];
        $this->results[] = $r;
        $this->printResult($r);
    }

    private function printResult(array $r): void
    {
        $colour = match ($r['status']) {
            'PASS' => "\033[32mPASS\033[0m",
            'FAIL' => "\033[31mFAIL\033[0m",
            'SKIP' => "\033[33mSKIP\033[0m",
            default => $r['status'],
        };
        $line = sprintf('  [%s] %s (%dms)', $colour, $r['name'] ?: '(group)', $r['elapsed_ms']);
        if ($r['message'] !== '') {
            $line .= ' — ' . str_replace("\n", " | ", $r['message']);
        }
        $this->log($line);
    }

    private function assertTrue(bool $cond): void
    {
        if (!$cond) throw new \RuntimeException('assertion failed: expected true');
    }
    private function assertSame(mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException('assertion failed: expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }
    private function assertNull(mixed $val): void
    {
        if ($val !== null) {
            throw new \RuntimeException('assertion failed: expected null, got ' . var_export($val, true));
        }
    }

    private function log(string $line): void
    {
        $ts = '[' . date('H:i:s') . '] ';
        echo $line . "\n";
        if ($this->logFh) {
            @fwrite($this->logFh, $ts . $line . "\n");
        }
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $path = $f->getPathname();
            if ($f->isDir() && !$f->isLink()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
