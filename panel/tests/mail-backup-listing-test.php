#!/usr/bin/env php
<?php
/**
 * Mail Backup Listing / NAS Reconciliation - Test Suite
 *
 * Guards the fix for "email backups vanish from the Email Backups tab while
 * they still show under NAS > Emails".
 *
 * The Email Backups tab lists from the local stub directory
 * ({backupPath}/mail/{domain}/*.tar.gz.meta.json) and only surfaces a
 * NAS-only backup when a local stub exists with nas_uploaded=true. The fix
 * adds BackupAction::reconcileMailStubsFromNas() (and its per-file helper
 * writeMailStubFromNas()) which treat the NAS as the source of truth and
 * (re)create any missing/incomplete stub so listMailBackups() can surface
 * the backup again. This suite exercises that chain end-to-end:
 *
 *   NAS archive (+companion meta / split manifest)
 *     -> writeMailStubFromNas()  (writes local stub, nas_uploaded=true)
 *       -> scanMailBackupDir()   (surfaces it as nas_only=true)
 *
 * Everything runs against a sandbox tmpdir with reflection-built objects, so
 * NO real NAS, mount, or database is required and production data is never
 * touched.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/mail-backup-listing-test.php --verbose
 *
 * Options:
 *   --help               Show this help
 *   --verbose            Extra debug output (PHP stack traces)
 *   --skip-send          No-op here (no external calls); accepted for parity
 *   --only=group,group   Run only specific groups (preflight, reconcile, scan, wiring)
 *   --smoke              Preflight checks only (no business logic)
 *   --json               Output the summary as JSON
 *
 * Exit code: 0 if all pass/warn, 1 if any test failed.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

// ── CLI parsing ─────────────────────────────────────────────────
$opts = getopt('', ['help', 'verbose', 'skip-send', 'only:', 'smoke', 'json']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1900));
    exit(0);
}
$verbose = isset($opts['verbose']);
$smoke   = isset($opts['smoke']);
$json    = isset($opts['json']);
$only    = !empty($opts['only']) ? explode(',', (string) $opts['only']) : [];

// ── Bootstrap agent autoloader ──────────────────────────────────
$agentRoot = require __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Actions\BackupAction;
use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\Logger;

// ── Output / logging ────────────────────────────────────────────
$RED    = "\033[0;31m";
$GREEN  = "\033[0;32m";
$YELLOW = "\033[1;33m";
$NC     = "\033[0m";

$logDir = __DIR__ . '/../../email/backend/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/mail-backup-listing-test-' . date('Ymd-His') . '.log';

$results = ['passed' => 0, 'failed' => 0, 'warnings' => 0, 'rows' => []];

function out(string $msg): void {
    global $logFile, $json;
    if ($json) return; // JSON mode suppresses chatter; final summary printed at end
    echo $msg . "\n";
    @file_put_contents($logFile, date('[H:i:s] ') . preg_replace('/\033\[[0-9;]*m/', '', $msg) . "\n", FILE_APPEND);
}

function shouldRun(string $group): bool {
    global $only;
    return empty($only) || in_array($group, $only, true);
}

function test(string $group, string $name, callable $fn): void {
    global $results, $verbose, $GREEN, $RED, $YELLOW, $NC;
    $start = microtime(true);
    $row = ['group' => $group, 'name' => $name, 'status' => 'FAIL', 'ms' => 0];
    try {
        $r = $fn();
        $row['ms'] = (int) round((microtime(true) - $start) * 1000);
        if ($r === 'warn') {
            $row['status'] = 'WARN';
            $results['warnings']++;
            out("  {$YELLOW}[WARN]{$NC}  {$name} ({$row['ms']}ms)");
        } else {
            $row['status'] = 'PASS';
            $results['passed']++;
            out("  {$GREEN}[PASS]{$NC}  {$name} ({$row['ms']}ms)");
        }
    } catch (\Throwable $e) {
        $row['ms'] = (int) round((microtime(true) - $start) * 1000);
        $row['error'] = $e->getMessage();
        $results['failed']++;
        out("  {$RED}[FAIL]{$NC}  {$name} ({$row['ms']}ms)");
        out("          -> " . $e->getMessage());
        if ($verbose) {
            out("          at " . $e->getFile() . ':' . $e->getLine());
        }
    }
    $results['rows'][] = $row;
}

function assertTrue(bool $cond, string $msg = 'Assertion failed'): void {
    if (!$cond) throw new \RuntimeException($msg);
}

function assertEquals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new \RuntimeException($msg ?: 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

// ── Sandbox setup ───────────────────────────────────────────────
$sandbox = realpath(sys_get_temp_dir()) . '/flowone_test_mail_listing_' . bin2hex(random_bytes(4));
@mkdir($sandbox, 0755, true);
$LOCAL_BACKUP = $sandbox . '/local-backups';   // stands in for {backupPath}
$NAS_EMAILS   = $sandbox . '/nas/backups/emails';
@mkdir($LOCAL_BACKUP, 0755, true);
@mkdir($NAS_EMAILS, 0755, true);

function cleanupSandbox(): void {
    global $sandbox;
    if (!is_dir($sandbox)) return;
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($sandbox, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isDir()) @rmdir($f->getPathname());
        else @unlink($f->getPathname());
    }
    @rmdir($sandbox);
}

register_shutdown_function('cleanupSandbox');
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function () { cleanupSandbox(); exit(130); });
    pcntl_signal(SIGTERM, function () { cleanupSandbox(); exit(143); });
}

// ── Reflection helpers (build a BackupAction without DB/NAS deps) ─
/**
 * Build a BackupAction with only the bits the reconciliation chain needs:
 * a real Logger pointed at the sandbox and an overridden backupPath. The
 * constructor only assigns properties, but we skip it so we don't need
 * BackupManager/DiffGenerator just to test pure filesystem logic.
 */
function buildAction(string $backupPath, string $logFile): BackupAction {
    $obj = (new \ReflectionClass(BackupAction::class))->newInstanceWithoutConstructor();

    $logger = new Logger(['logging' => ['file' => $logFile, 'level' => 'error']]);
    $lp = new \ReflectionProperty(BaseAction::class, 'logger');
    $lp->setAccessible(true);
    $lp->setValue($obj, $logger);

    $bp = new \ReflectionProperty(BackupAction::class, 'backupPath');
    $bp->setAccessible(true);
    $bp->setValue($obj, $backupPath);

    return $obj;
}

function callPrivate(object $obj, string $method, array $args = []) {
    $m = new \ReflectionMethod($obj, $method);
    $m->setAccessible(true);
    return $m->invokeArgs($obj, $args);
}

/** Create a fake NAS email archive (+optional companion meta) and return its path. */
function makeNasArchive(string $domain, string $filename, ?array $companionMeta, int $bytes): string {
    global $NAS_EMAILS;
    $dir = "{$NAS_EMAILS}/{$domain}";
    @mkdir($dir, 0755, true);
    $archive = "{$dir}/{$filename}";
    file_put_contents($archive, $bytes > 0 ? str_repeat('M', $bytes) : '');
    if ($companionMeta !== null) {
        file_put_contents("{$archive}.meta.json", json_encode($companionMeta, JSON_PRETTY_PRINT));
    }
    return $archive;
}

function readStub(string $localBackup, string $domain, string $filename): ?array {
    $stub = "{$localBackup}/mail/{$domain}/{$filename}.meta.json";
    if (!file_exists($stub)) return null;
    $data = json_decode((string) file_get_contents($stub), true);
    return is_array($data) ? $data : null;
}

// Recognizable, clearly-fake test domains (never real tenants).
$TS = '2026-06-18_07-20-02';

// ── Banner ──────────────────────────────────────────────────────
out('=================================================================');
out('  Mail Backup Listing / NAS Reconciliation Test Suite');
out('  ' . date('Y-m-d H:i:s T'));
out('  Mode: ' . ($smoke ? 'SMOKE' : 'FULL') . ($json ? ' / JSON' : ''));
out('  Sandbox: ' . $sandbox);
out('  Log: ' . $logFile);
out('=================================================================');

// ── 1. Preflight ────────────────────────────────────────────────
if (shouldRun('preflight')) {
    out("\n--- 1. PREFLIGHT ---");

    test('preflight', 'PHP extensions loaded (json required, pcntl optional)', function () {
        if (!extension_loaded('json')) throw new \RuntimeException('json extension missing');
        if (!extension_loaded('pcntl')) return 'warn';
    });

    test('preflight', 'BackupAction.php exists', function () use ($agentRoot) {
        assertTrue(file_exists($agentRoot . '/Actions/BackupAction.php'), 'BackupAction.php not found');
    });

    test('preflight', 'reconcileMailStubsFromNas() + writeMailStubFromNas() exist', function () {
        assertTrue(method_exists(BackupAction::class, 'reconcileMailStubsFromNas'), 'reconcileMailStubsFromNas() missing');
        assertTrue(method_exists(BackupAction::class, 'writeMailStubFromNas'), 'writeMailStubFromNas() missing');
    });

    test('preflight', 'Sandbox is writable', function () use ($sandbox, $LOCAL_BACKUP, $NAS_EMAILS) {
        assertTrue(is_dir($sandbox) && is_writable($sandbox), 'Sandbox not writable');
        assertTrue(is_dir($LOCAL_BACKUP) && is_dir($NAS_EMAILS), 'Sandbox layout incomplete');
    });

    test('preflight', 'BackupAction is reflection-constructible', function () use ($LOCAL_BACKUP, $logFile) {
        $a = buildAction($LOCAL_BACKUP, $logFile);
        assertTrue($a instanceof BackupAction, 'Could not build BackupAction');
    });
}

if ($smoke) {
    goto summary;
}

// ── 2. Stub reconstruction (writeMailStubFromNas) ───────────────
if (shouldRun('reconcile')) {
    out("\n--- 2. STUB RECONSTRUCTION ---");

    test('reconcile', 'Reconstructs stub from NAS companion meta (nas_uploaded forced true)', function () use ($LOCAL_BACKUP, $logFile, $TS) {
        $domain = 'flowone-test-recon.example';
        $file   = "mail_{$domain}_{$TS}.tar.gz";
        $companion = ['domain' => $domain, 'timestamp' => $TS, 'accounts_count' => 7, 'has_mailboxes' => true, 'nas_uploaded' => false];
        $archive = makeNasArchive($domain, $file, $companion, 2048);

        $a = buildAction($LOCAL_BACKUP, $logFile);
        callPrivate($a, 'writeMailStubFromNas', [$archive, $domain]);

        $stub = readStub($LOCAL_BACKUP, $domain, $file);
        assertTrue($stub !== null, 'Stub was not written');
        assertEquals(true, $stub['nas_uploaded'], 'nas_uploaded must be forced true');
        assertEquals($domain, $stub['domain'], 'domain mismatch');
        assertEquals(7, $stub['accounts_count'], 'accounts_count not carried from companion');
        assertEquals(2048, $stub['archive_size'], 'archive_size should fall back to NAS filesize');
        assertEquals($TS, $stub['timestamp'], 'timestamp should be preserved');
    });

    test('reconcile', 'Backfills archive_size from split manifest for zero-byte marker', function () use ($LOCAL_BACKUP, $logFile, $TS) {
        $domain = 'flowone-test-split.example';
        $file   = "mail_{$domain}_{$TS}.tar.gz";
        $archive = makeNasArchive($domain, $file, null, 0); // zero-byte split marker
        file_put_contents("{$archive}.manifest.json", json_encode(['total_size' => 123456, 'parts_count' => 3]));

        $a = buildAction($LOCAL_BACKUP, $logFile);
        callPrivate($a, 'writeMailStubFromNas', [$archive, $domain]);

        $stub = readStub($LOCAL_BACKUP, $domain, $file);
        assertTrue($stub !== null, 'Stub was not written');
        assertEquals(123456, $stub['archive_size'], 'archive_size should come from split manifest total_size');
        assertEquals(true, $stub['nas_uploaded'], 'nas_uploaded must be true');
    });

    test('reconcile', 'Backfills timestamp + domain from filename when no companion meta', function () use ($LOCAL_BACKUP, $logFile, $TS) {
        $domain = 'flowone-test-nometa.example';
        $file   = "mail_{$domain}_{$TS}.tar.gz";
        $archive = makeNasArchive($domain, $file, null, 512);

        $a = buildAction($LOCAL_BACKUP, $logFile);
        callPrivate($a, 'writeMailStubFromNas', [$archive, $domain]);

        $stub = readStub($LOCAL_BACKUP, $domain, $file);
        assertTrue($stub !== null, 'Stub was not written');
        assertEquals($TS, $stub['timestamp'], 'timestamp not parsed from filename');
        assertEquals($domain, $stub['domain'], 'domain not set');
        assertEquals(512, $stub['archive_size'], 'archive_size should be filesize');
        assertEquals(true, $stub['nas_uploaded'], 'nas_uploaded must be true');
    });

    test('reconcile', 'Skips when a local archive already exists (no stub written)', function () use ($LOCAL_BACKUP, $logFile, $TS) {
        $domain = 'flowone-test-local.example';
        $file   = "mail_{$domain}_{$TS}.tar.gz";
        $archive = makeNasArchive($domain, $file, ['domain' => $domain, 'accounts_count' => 1], 256);

        // Pre-seed a local archive for the same filename.
        $localDir = "{$LOCAL_BACKUP}/mail/{$domain}";
        @mkdir($localDir, 0750, true);
        file_put_contents("{$localDir}/{$file}", 'local-archive');

        $a = buildAction($LOCAL_BACKUP, $logFile);
        callPrivate($a, 'writeMailStubFromNas', [$archive, $domain]);

        assertTrue(readStub($LOCAL_BACKUP, $domain, $file) === null, 'No stub should be written when local archive exists');
    });

    test('reconcile', 'Is idempotent: does not overwrite a complete stub (nas_uploaded=true)', function () use ($LOCAL_BACKUP, $logFile, $TS) {
        $domain = 'flowone-test-idem.example';
        $file   = "mail_{$domain}_{$TS}.tar.gz";
        $archive = makeNasArchive($domain, $file, ['domain' => $domain, 'accounts_count' => 9], 256);

        $localDir = "{$LOCAL_BACKUP}/mail/{$domain}";
        @mkdir($localDir, 0750, true);
        $stubPath = "{$localDir}/{$file}.meta.json";
        $sentinel = ['domain' => $domain, 'nas_uploaded' => true, 'sentinel' => 'KEEPME', 'accounts_count' => 42];
        file_put_contents($stubPath, json_encode($sentinel, JSON_PRETTY_PRINT));

        $a = buildAction($LOCAL_BACKUP, $logFile);
        callPrivate($a, 'writeMailStubFromNas', [$archive, $domain]);

        $stub = readStub($LOCAL_BACKUP, $domain, $file);
        assertEquals('KEEPME', $stub['sentinel'] ?? null, 'Complete stub must not be overwritten');
        assertEquals(42, $stub['accounts_count'], 'Complete stub fields must be preserved');
    });

    test('reconcile', 'Repairs an incomplete stub (nas_uploaded=false -> true), preserving fields', function () use ($LOCAL_BACKUP, $logFile, $TS) {
        $domain = 'flowone-test-repair.example';
        $file   = "mail_{$domain}_{$TS}.tar.gz";
        $archive = makeNasArchive($domain, $file, null, 256);

        $localDir = "{$LOCAL_BACKUP}/mail/{$domain}";
        @mkdir($localDir, 0750, true);
        $stubPath = "{$localDir}/{$file}.meta.json";
        file_put_contents($stubPath, json_encode(['domain' => $domain, 'nas_uploaded' => false, 'accounts_count' => 5, 'timestamp' => $TS], JSON_PRETTY_PRINT));

        $a = buildAction($LOCAL_BACKUP, $logFile);
        callPrivate($a, 'writeMailStubFromNas', [$archive, $domain]);

        $stub = readStub($LOCAL_BACKUP, $domain, $file);
        assertEquals(true, $stub['nas_uploaded'], 'Incomplete stub must be repaired to nas_uploaded=true');
        assertEquals(5, $stub['accounts_count'], 'Existing stub fields must be preserved during repair');
    });
}

// ── 3. Listing surfaces the reconstructed stub (scanMailBackupDir) ─
if (shouldRun('scan')) {
    out("\n--- 3. LISTING (scanMailBackupDir) ---");

    test('scan', 'Reconstructed stub surfaces as nas_only with correct fields', function () use ($LOCAL_BACKUP, $logFile, $TS) {
        $domain = 'flowone-test-scan.example';
        $file   = "mail_{$domain}_{$TS}.tar.gz";
        $archive = makeNasArchive($domain, $file, ['domain' => $domain, 'timestamp' => $TS, 'accounts_count' => 13, 'has_mailboxes' => true], 4096);

        $a = buildAction($LOCAL_BACKUP, $logFile);
        callPrivate($a, 'writeMailStubFromNas', [$archive, $domain]);

        $entries = callPrivate($a, 'scanMailBackupDir', ["{$LOCAL_BACKUP}/mail/{$domain}", $domain]);
        assertTrue(is_array($entries) && count($entries) === 1, 'Expected exactly one listing entry, got ' . count($entries));

        $e = $entries[0];
        assertEquals(true, $e['nas_only'], 'Entry must be flagged nas_only');
        assertEquals(true, $e['nas_uploaded'], 'Entry must be flagged nas_uploaded');
        assertEquals($domain, $e['domain'], 'domain mismatch');
        assertEquals($file, $e['filename'], 'filename mismatch');
        assertEquals(13, $e['accounts'], 'accounts not surfaced from stub');
        // id is base64 of the expected local archive path (drives inspect/restore/download).
        assertEquals("{$LOCAL_BACKUP}/mail/{$domain}/{$file}", base64_decode($e['id']), 'id must decode to the local archive path');
    });

    test('scan', 'Double reconstruction yields a single stable listing entry (idempotent)', function () use ($LOCAL_BACKUP, $logFile, $TS) {
        $domain = 'flowone-test-twice.example';
        $file   = "mail_{$domain}_{$TS}.tar.gz";
        $archive = makeNasArchive($domain, $file, ['domain' => $domain, 'timestamp' => $TS, 'accounts_count' => 2], 1024);

        $a = buildAction($LOCAL_BACKUP, $logFile);
        callPrivate($a, 'writeMailStubFromNas', [$archive, $domain]);
        $first = file_get_contents("{$LOCAL_BACKUP}/mail/{$domain}/{$file}.meta.json");
        callPrivate($a, 'writeMailStubFromNas', [$archive, $domain]);
        $second = file_get_contents("{$LOCAL_BACKUP}/mail/{$domain}/{$file}.meta.json");

        assertEquals($first, $second, 'Stub must be stable across repeated reconciliation');
        $entries = callPrivate($a, 'scanMailBackupDir', ["{$LOCAL_BACKUP}/mail/{$domain}", $domain]);
        assertTrue(count($entries) === 1, 'Repeated reconciliation must not duplicate listing entries');
    });
}

// ── 4. Wiring / regression guards (static) ──────────────────────
if (shouldRun('wiring')) {
    out("\n--- 4. WIRING GUARDS ---");

    test('wiring', 'listMailBackups() invokes reconcileMailStubsFromNas()', function () use ($agentRoot) {
        $src = file_get_contents($agentRoot . '/Actions/BackupAction.php');
        $listPos = strpos($src, 'private function listMailBackups(');
        assertTrue($listPos !== false, 'listMailBackups() not found');
        $callPos = strpos($src, 'reconcileMailStubsFromNas($domain)', $listPos);
        $nextFn  = strpos($src, 'private function scanMailBackupDir(', $listPos);
        assertTrue($callPos !== false && ($nextFn === false || $callPos < $nextFn),
            'listMailBackups() must call reconcileMailStubsFromNas($domain) before scanning');
    });

    test('wiring', 'reconciliation degrades gracefully when NAS unavailable (guards present)', function () use ($agentRoot) {
        $src = file_get_contents($agentRoot . '/Actions/BackupAction.php');
        $start = strpos($src, 'private function reconcileMailStubsFromNas(');
        assertTrue($start !== false, 'reconcileMailStubsFromNas() not found');
        $body = substr($src, $start, 2200);
        assertTrue(str_contains($body, 'getBackupNasConnection()'), 'must resolve NAS via getBackupNasConnection()');
        assertTrue(str_contains($body, 'isMounted('), 'must verify the mount is live');
        assertTrue(str_contains($body, 'catch (\\Throwable'), 'must swallow errors so listing never breaks');
    });
}

// ── Summary ─────────────────────────────────────────────────────
summary:

$total = $results['passed'] + $results['failed'] + $results['warnings'];

if ($json) {
    echo json_encode([
        'total'    => $total,
        'passed'   => $results['passed'],
        'failed'   => $results['failed'],
        'warnings' => $results['warnings'],
        'log'      => $logFile,
        'rows'     => $results['rows'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    out("\n=================================================================");
    if ($results['failed'] === 0) {
        out("  {$GREEN}ALL PASSED{$NC}: {$results['passed']} passed, {$results['warnings']} warnings / {$total} total");
    } else {
        out("  {$RED}RESULT{$NC}: {$results['passed']} passed, {$results['failed']} FAILED, {$results['warnings']} warnings / {$total} total");
        out("\n  {$RED}FAILED TESTS:{$NC}");
        foreach ($results['rows'] as $r) {
            if ($r['status'] === 'FAIL') {
                out("    x [{$r['group']}] {$r['name']}");
                if (!empty($r['error'])) out("      {$r['error']}");
            }
        }
    }
    out("  Log: {$logFile}");
    out('=================================================================');
}

exit($results['failed'] > 0 ? 1 : 0);
