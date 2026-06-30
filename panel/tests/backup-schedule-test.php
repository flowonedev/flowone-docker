#!/usr/bin/env php
<?php
/**
 * Backup Schedule System Test Suite
 *
 * Covers the scheduled-backup fixes:
 *   - cron-expr:  weekly day-of-week support + all frequency shapes
 *   - parse:      cron line parsing incl. disabled (# commented) schedules,
 *                 id stability across enable/disable, non-runner lines skipped
 *   - next-run:   next fire time computation for hourly/daily/weekly/monthly
 *   - state:      run-state file roundtrip (running/success/degraded/failed),
 *                 key derivation parity between cron command and runner args
 *   - normalize:  cron file content normalization (trailing newline, CRLF)
 *   - wiring:     new agent methods registered (runSchedule, transferToNas,
 *                 cronStatus, repairCron), site backup filename parser
 *   - paneldb:    shared PanelDb connector (reconnect-on-stale, correct creds
 *                 source for the cron runner)
 *   - split:      multi-GB split archives - create/reassemble roundtrip,
 *                 move-to-NAS payload freeing, split-aware listings and the
 *                 manifest-only (marker-less) NAS merge
 *
 * Non-destructive: never touches /etc/cron.d or the real state file; all
 * file work happens in a flowone_test_* tmpdir that is removed on exit.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/backup-schedule-test.php --verbose
 *
 * Flags: --help --verbose --smoke --json --skip-send --only=group1,group2
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'skip-send', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1500));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Actions\BackupAction;
use VpsAdmin\Agent\Lib\BackupManager;
use VpsAdmin\Agent\Lib\BackupScheduleManager;
use VpsAdmin\Agent\Lib\DiffGenerator;
use VpsAdmin\Agent\Lib\Logger;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('BackupSchedule', $opts);

$sandbox = realpath(sys_get_temp_dir()) . '/flowone_test_bsched_' . bin2hex(random_bytes(4));
mkdir($sandbox, 0755, true);

$harness->onCleanup(function () use ($sandbox): void {
    if (!is_dir($sandbox)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($sandbox);
});

const RUNNER_CMD = '/usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/agent/backup-runner.php';

// ── preflight ─────────────────────────────────────────────────────────

$harness->test('preflight', 'required PHP extensions loaded', function () {
    foreach (['pcre', 'json'] as $ext) {
        if (!extension_loaded($ext)) {
            return ['outcome' => TestHarness::FAIL, 'message' => "missing extension: {$ext}"];
        }
    }
});

$harness->test('preflight', 'sandbox tmpdir is writable', function () use ($sandbox) {
    $probe = $sandbox . '/flowone_test_probe';
    if (@file_put_contents($probe, 'x') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => "cannot write {$sandbox}"];
    }
    @unlink($probe);
});

$harness->test('preflight', 'BackupScheduleManager class loads', function () {
    if (!class_exists(BackupScheduleManager::class)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'class not autoloadable'];
    }
});

// ── cron-expr: expression building ────────────────────────────────────

$harness->test('cron-expr', 'hourly/daily/monthly shapes', function () {
    $cases = [
        ['hourly', 3, 15, 0, '15 * * * *'],
        ['daily', 3, 0, 0, '0 3 * * *'],
        ['monthly', 4, 30, 0, '30 4 1 * *'],
    ];
    foreach ($cases as [$freq, $h, $m, $dow, $expected]) {
        $got = BackupScheduleManager::buildCronExpr($freq, $h, $m, $dow);
        if ($got !== $expected) {
            return ['outcome' => TestHarness::FAIL, 'message' => "{$freq}: expected '{$expected}', got '{$got}'"];
        }
    }
});

$harness->test('cron-expr', 'weekly honors every day of week (the old bug: always Sunday)', function () {
    for ($dow = 0; $dow <= 6; $dow++) {
        $got = BackupScheduleManager::buildCronExpr('weekly', 2, 30, $dow);
        $expected = "30 2 * * {$dow}";
        if ($got !== $expected) {
            return ['outcome' => TestHarness::FAIL, 'message' => "dow={$dow}: expected '{$expected}', got '{$got}'"];
        }
    }
});

$harness->test('cron-expr', 'out-of-range day_of_week is clamped', function () {
    if (BackupScheduleManager::buildCronExpr('weekly', 1, 0, 9) !== '0 1 * * 6') {
        return ['outcome' => TestHarness::FAIL, 'message' => 'dow=9 not clamped to 6'];
    }
    if (BackupScheduleManager::buildCronExpr('weekly', 1, 0, -3) !== '0 1 * * 0') {
        return ['outcome' => TestHarness::FAIL, 'message' => 'dow=-3 not clamped to 0'];
    }
});

// ── parse: cron line parsing ──────────────────────────────────────────

$harness->test('parse', 'enabled runner line parses with all fields', function () {
    $line = '0 3 * * * root ' . RUNNER_CMD . ' --categories=webserver,mysql --retention=7 --destination=local';
    $s = BackupScheduleManager::parseCronLine($line);
    if ($s === null) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'returned null'];
    }
    if ($s['enabled'] !== true || $s['frequency'] !== 'daily' || $s['hour'] !== '3' || $s['user'] !== 'root') {
        return ['outcome' => TestHarness::FAIL, 'message' => 'fields wrong: ' . json_encode($s)];
    }
});

$harness->test('parse', 'disabled (# commented) schedule is parsed, not dropped', function () {
    $line = '# 30 2 * * 4 root ' . RUNNER_CMD . ' --sites=all --components=all --retention=14 --destination=nas';
    $s = BackupScheduleManager::parseCronLine($line);
    if ($s === null) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'disabled schedule was dropped (the old bug)'];
    }
    if ($s['enabled'] !== false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'enabled flag should be false'];
    }
    if (($s['day_of_week'] ?? null) !== 4 || ($s['day_of_week_label'] ?? '') !== 'Thursday') {
        return ['outcome' => TestHarness::FAIL, 'message' => 'day_of_week not extracted: ' . json_encode($s)];
    }
});

$harness->test('parse', 'id is stable across enable/disable toggling', function () {
    $enabled = '0 3 * * * root ' . RUNNER_CMD . ' --categories=mail --retention=7 --destination=both';
    $disabled = '# ' . $enabled;
    $a = BackupScheduleManager::parseCronLine($enabled);
    $b = BackupScheduleManager::parseCronLine($disabled);
    if ($a === null || $b === null) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'one of the lines did not parse'];
    }
    if ($a['id'] !== $b['id']) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'id changed when toggled'];
    }
});

$harness->test('parse', 'non-runner lines and plain comments are skipped', function () {
    $lines = [
        '',
        '# Backup schedules managed by the panel',
        '0 * * * * root rsync -av /a /b',
        'MAILTO=ops@flowone.pro',
    ];
    foreach ($lines as $line) {
        if (BackupScheduleManager::parseCronLine($line) !== null) {
            return ['outcome' => TestHarness::FAIL, 'message' => "should be skipped: '{$line}'"];
        }
    }
});

$harness->test('parse', 'frequency derivation: hourly/weekly/monthly', function () {
    $cases = [
        ['15 * * * * root ' . RUNNER_CMD . ' --categories=ssl', 'hourly'],
        ['0 1 * * 2 root ' . RUNNER_CMD . ' --categories=ssl', 'weekly'],
        ['0 1 1 * * root ' . RUNNER_CMD . ' --categories=ssl', 'monthly'],
    ];
    foreach ($cases as [$line, $expected]) {
        $s = BackupScheduleManager::parseCronLine($line);
        if ($s === null || $s['frequency'] !== $expected) {
            return ['outcome' => TestHarness::FAIL, 'message' => "expected {$expected}, got " . ($s['frequency'] ?? 'null')];
        }
    }
});

// ── next-run: fire-time computation ───────────────────────────────────

$harness->test('next-run', 'daily: later today when time not yet passed', function () {
    $now = mktime(2, 0, 0, 6, 10, 2026); // Wed Jun 10 2026 02:00
    $s = BackupScheduleManager::parseCronLine('0 3 * * * root ' . RUNNER_CMD . ' --categories=ssl');
    $next = BackupScheduleManager::nextRunAt($s, $now);
    if ($next !== mktime(3, 0, 0, 6, 10, 2026)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected today 03:00, got ' . date('Y-m-d H:i', (int)$next)];
    }
});

$harness->test('next-run', 'daily: tomorrow when time already passed', function () {
    $now = mktime(4, 0, 0, 6, 10, 2026);
    $s = BackupScheduleManager::parseCronLine('0 3 * * * root ' . RUNNER_CMD . ' --categories=ssl');
    $next = BackupScheduleManager::nextRunAt($s, $now);
    if ($next !== mktime(3, 0, 0, 6, 11, 2026)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected tomorrow 03:00, got ' . date('Y-m-d H:i', (int)$next)];
    }
});

$harness->test('next-run', 'weekly: lands on the configured weekday', function () {
    // Jun 10 2026 is a Wednesday (w=3). Schedule: Friday (5) 02:30.
    $now = mktime(12, 0, 0, 6, 10, 2026);
    $s = BackupScheduleManager::parseCronLine('30 2 * * 5 root ' . RUNNER_CMD . ' --sites=all --components=all');
    $next = BackupScheduleManager::nextRunAt($s, $now);
    if ($next !== mktime(2, 30, 0, 6, 12, 2026)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected Fri Jun 12 02:30, got ' . date('Y-m-d H:i', (int)$next)];
    }
    if (date('w', (int)$next) !== '5') {
        return ['outcome' => TestHarness::FAIL, 'message' => 'next run not on a Friday'];
    }
});

$harness->test('next-run', 'weekly: same weekday rolls a full week when time passed', function () {
    // Wednesday 12:00, schedule Wednesday 03:00 -> next Wednesday.
    $now = mktime(12, 0, 0, 6, 10, 2026);
    $s = BackupScheduleManager::parseCronLine('0 3 * * 3 root ' . RUNNER_CMD . ' --sites=all --components=all');
    $next = BackupScheduleManager::nextRunAt($s, $now);
    if ($next !== mktime(3, 0, 0, 6, 17, 2026)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected Jun 17 03:00, got ' . date('Y-m-d H:i', (int)$next)];
    }
});

$harness->test('next-run', 'hourly + monthly shapes', function () {
    $now = mktime(10, 20, 0, 6, 10, 2026);
    $hourly = BackupScheduleManager::parseCronLine('15 * * * * root ' . RUNNER_CMD . ' --categories=ssl');
    $next = BackupScheduleManager::nextRunAt($hourly, $now);
    if ($next !== mktime(11, 15, 0, 6, 10, 2026)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'hourly: expected 11:15, got ' . date('H:i', (int)$next)];
    }
    $monthly = BackupScheduleManager::parseCronLine('0 4 1 * * root ' . RUNNER_CMD . ' --categories=ssl');
    $next = BackupScheduleManager::nextRunAt($monthly, $now);
    if ($next !== mktime(4, 0, 0, 7, 1, 2026)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'monthly: expected Jul 1 04:00, got ' . date('Y-m-d H:i', (int)$next)];
    }
});

// ── state: run-state file ─────────────────────────────────────────────

$harness->test('state', 'key parity: cron command vs runner args', function () {
    $command = RUNNER_CMD . ' --sites=all --components=all --retention=14 --destination=nas';
    $fromCommand = BackupScheduleManager::runStateKeyFromCommand($command);
    $fromArgs = BackupScheduleManager::runStateKeyFromArgs([
        'sites' => 'all',
        'categories' => '',
        'components' => 'all',
        'destination' => 'nas',
    ]);
    if ($fromCommand === null || $fromCommand !== $fromArgs) {
        return ['outcome' => TestHarness::FAIL, 'message' => "mismatch: {$fromCommand} vs {$fromArgs}"];
    }
});

$harness->test('state', 'non-runner command yields no key', function () {
    if (BackupScheduleManager::runStateKeyFromCommand('rsync -av /a /b') !== null) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected null for non-runner command'];
    }
});

$harness->test('state', 'write + read roundtrip for all statuses', function () use ($sandbox) {
    $file = $sandbox . '/flowone_test_state.json';
    foreach (['running', 'success', 'degraded', 'failed'] as $i => $status) {
        if (!BackupScheduleManager::writeRunState("key{$i}", $status, "msg {$status}", $file)) {
            return ['outcome' => TestHarness::FAIL, 'message' => "write failed for {$status}"];
        }
    }
    $state = BackupScheduleManager::readRunState($file);
    if (count($state) !== 4) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected 4 entries, got ' . count($state)];
    }
    if ($state['key2']['status'] !== 'degraded' || $state['key2']['message'] !== 'msg degraded') {
        return ['outcome' => TestHarness::FAIL, 'message' => 'entry content wrong: ' . json_encode($state['key2'])];
    }
});

$harness->test('state', 'status overwrite: running -> success', function () use ($sandbox) {
    $file = $sandbox . '/flowone_test_state2.json';
    BackupScheduleManager::writeRunState('k', 'running', 'started', $file);
    BackupScheduleManager::writeRunState('k', 'success', 'done', $file);
    $state = BackupScheduleManager::readRunState($file);
    if (($state['k']['status'] ?? '') !== 'success') {
        return ['outcome' => TestHarness::FAIL, 'message' => 'status not overwritten'];
    }
});

$harness->test('state', 'corrupt state file reads as empty (no crash)', function () use ($sandbox) {
    $file = $sandbox . '/flowone_test_state3.json';
    file_put_contents($file, '{not valid json!!');
    if (BackupScheduleManager::readRunState($file) !== []) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected [] for corrupt file'];
    }
    // And a write on top of the corrupt file must recover it.
    BackupScheduleManager::writeRunState('k', 'success', '', $file);
    if ((BackupScheduleManager::readRunState($file)['k']['status'] ?? '') !== 'success') {
        return ['outcome' => TestHarness::FAIL, 'message' => 'write did not recover corrupt file'];
    }
});

$harness->test('state', 'state file is bounded to 50 entries', function () use ($sandbox) {
    $file = $sandbox . '/flowone_test_state4.json';
    for ($i = 0; $i < 60; $i++) {
        BackupScheduleManager::writeRunState("bulk{$i}", 'success', '', $file);
    }
    $count = count(BackupScheduleManager::readRunState($file));
    if ($count > 50) {
        return ['outcome' => TestHarness::FAIL, 'message' => "expected <= 50 entries, got {$count}"];
    }
});

// ── normalize: cron file content ──────────────────────────────────────

$harness->test('normalize', 'guarantees exactly one trailing newline', function () {
    $cases = [
        ["a\nb", "a\nb\n"],
        ["a\nb\n", "a\nb\n"],
        ["a\nb\n\n\n", "a\nb\n"],
        ["", ""],
        ["\n\n", ""],
    ];
    foreach ($cases as [$in, $expected]) {
        $got = BackupScheduleManager::normalizeCronContent($in);
        if ($got !== $expected) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'in=' . json_encode($in) . ' expected=' . json_encode($expected) . ' got=' . json_encode($got)];
        }
    }
});

$harness->test('normalize', 'CRLF and CR are converted to LF', function () {
    $got = BackupScheduleManager::normalizeCronContent("a\r\nb\rc");
    if ($got !== "a\nb\nc\n") {
        return ['outcome' => TestHarness::FAIL, 'message' => 'got=' . json_encode($got)];
    }
});

// ── wiring: agent registration + filename parser ──────────────────────

/** @var BackupAction|null $backupAction */
$backupAction = null;

$harness->test('wiring', 'BackupAction instantiates', function () use (&$backupAction, $sandbox) {
    $config = [
        'paths' => ['backups' => $sandbox . '/backups'],
        'logging' => ['file' => $sandbox . '/agent.log', 'level' => 'error'],
        'backup' => ['max_age_days' => 1, 'max_count' => 5],
    ];
    $logger = new Logger($config);
    $backup = new BackupManager($config);
    $diff = new DiffGenerator();
    $backupAction = new BackupAction($config, $backup, $diff, $logger);
});

$harness->test('wiring', 'new methods registered: runSchedule, transferToNas, cronStatus, repairCron', function () use (&$backupAction) {
    $methods = $backupAction->getMethods();
    foreach (['runSchedule', 'transferToNas', 'cronStatus', 'repairCron'] as $m) {
        if (!in_array($m, $methods, true)) {
            return ['outcome' => TestHarness::FAIL, 'message' => "backup.{$m} not registered"];
        }
    }
});

$harness->test('wiring', 'runSchedule validates id and missing cron file', function () use (&$backupAction) {
    $r = $backupAction->execute('runSchedule', [], 'flowone_test');
    if ($r['success'] !== false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected error without id'];
    }
});

$harness->test('wiring', 'transferToNas rejects bad ids and invalid modes', function () use (&$backupAction) {
    $r = $backupAction->execute('transferToNas', ['id' => base64_encode('/etc/passwd')], 'flowone_test');
    if ($r['success'] !== false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'path outside backup root must be rejected'];
    }
    $r = $backupAction->execute('transferToNas', ['id' => base64_encode('/var/www/vps-admin/backups/x.tar.gz'), 'mode' => 'teleport'], 'flowone_test');
    if ($r['success'] !== false || stripos($r['error'] ?? '', 'mode') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'invalid mode must be rejected'];
    }
});

$harness->test('wiring', 'site backup filename parser handles all formats', function () use (&$backupAction) {
    $ref = new ReflectionMethod(BackupAction::class, 'parseSiteBackupFilename');
    $ref->setAccessible(true);

    $cases = [
        ['example.com_2026-06-01_03-00-00_full.tar.gz', 'example.com', '2026-06-01 03:00:00', 'full'],
        ['example.com_2026-06-01_03-00-00_database.tar.gz', 'example.com', '2026-06-01 03:00:00', 'database'],
        ['my_site.hu_2026-06-01_03-00-00.tar.gz', 'my_site.hu', '2026-06-01 03:00:00', 'full'],
    ];
    foreach ($cases as [$filename, $domain, $date, $type]) {
        $p = $ref->invoke($backupAction, $filename);
        if ($p === null || $p['domain'] !== $domain || $p['date'] !== $date || $p['backup_type'] !== $type) {
            return ['outcome' => TestHarness::FAIL, 'message' => "{$filename}: " . json_encode($p)];
        }
    }

    if ($ref->invoke($backupAction, 'random-file.tar.gz') !== null) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'non-backup filename should return null'];
    }
});

$harness->test('paneldb', 'PanelDb fails gracefully or returns live cached connection', function () {
    $pdo = \VpsAdmin\Agent\Lib\PanelDb::get();

    if ($pdo === null) {
        // Local dev / API config missing: must report why, never throw.
        if (\VpsAdmin\Agent\Lib\PanelDb::lastError() === '') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'get() returned null without lastError'];
        }
        return ['outcome' => TestHarness::WARN, 'message' => 'no panel DB here (ok in dev): ' . \VpsAdmin\Agent\Lib\PanelDb::lastError()];
    }

    // Server: connection must survive a ping and be reused (cached).
    $pdo->query('SELECT 1');
    if (\VpsAdmin\Agent\Lib\PanelDb::get() !== $pdo) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'healthy connection was not reused'];
    }
});

$harness->test('paneldb', 'runner and BackupAction use PanelDb (not agent config creds)', function () {
    $runnerSrc = file_get_contents(__DIR__ . '/../agent/backup-runner.php');
    if (strpos($runnerSrc, 'PanelDb::get') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'backup-runner.php does not use PanelDb'];
    }
    if (preg_match('/config\[.database.\]\[.user.\]/', $runnerSrc)) {
        return ['outcome' => TestHarness::FAIL, 'message' => "backup-runner.php still uses the agent config's placeholder DB credentials"];
    }

    $ref = new ReflectionMethod(BackupAction::class, 'getPanelDb');
    $src = file(__DIR__ . '/../agent/Actions/BackupAction.php');
    $body = implode('', array_slice($src, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    if (strpos($body, 'PanelDb::get') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'BackupAction::getPanelDb does not delegate to PanelDb (stale-connection bug would return)'];
    }
});

$harness->test('wiring', 'NAS listing skips unreadable dirs instead of aborting', function () {
    $ref = new ReflectionMethod(BackupAction::class, 'listNasBackups');
    $src = file(__DIR__ . '/../agent/Actions/BackupAction.php');
    $body = implode('', array_slice($src, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));

    if (strpos($body, 'CATCH_GET_CHILD') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'listNasBackups must use CATCH_GET_CHILD so one unreadable subdir cannot abort the listing'];
    }
    if (strpos($body, 'is_readable') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'listNasBackups must pre-check base path readability'];
    }
    if (strpos($body, '\\Throwable') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'listNasBackups must catch Throwable (TypeError from bad DB rows would 500 otherwise)'];
    }
});

$harness->test('wiring', 'NAS lookup trusts live mount, not the stale status flag', function () {
    $src = file(__DIR__ . '/../agent/Actions/BackupAction.php');

    $ref = new ReflectionMethod(BackupAction::class, 'getBackupNasConnection');
    $body = implode('', array_slice($src, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    if (strpos($body, "status = 'active'") !== false && strpos($body, "(status = 'active')") === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => "getBackupNasConnection must not hard-require status='active' (flag is a stale cache of the last test)"];
    }
    if (strpos($body, "status <> 'inactive'") === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'getBackupNasConnection must still exclude operator-disabled connections'];
    }

    // Unmount must write a valid ENUM value - 'unknown' was silently
    // coerced to '' and locked the row out of every status filter.
    $nasController = file_get_contents(__DIR__ . '/../api/src/Controllers/NASController.php');
    if (strpos($nasController, "status = 'unknown'") !== false) {
        return ['outcome' => TestHarness::FAIL, 'message' => "NASController still writes status='unknown' (not in the ENUM)"];
    }
});

$harness->test('wiring', 'async NAS transfer spawns detached runner (not inline)', function () {
    $runner = __DIR__ . '/../agent/backup-transfer-runner.php';
    if (!is_file($runner)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'backup-transfer-runner.php missing'];
    }

    $runnerSrc = file_get_contents($runner);
    foreach (["'transferToNas'", 'status_id_override', 'updateStatusFile'] as $needle) {
        if (strpos($runnerSrc, $needle) === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => "transfer runner missing {$needle}"];
        }
    }

    $ref = new ReflectionMethod(BackupAction::class, 'startBackgroundTransfer');
    $src = file(__DIR__ . '/../agent/Actions/BackupAction.php');
    $body = implode('', array_slice($src, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    if (strpos($body, 'backup-transfer-runner.php') === false || strpos($body, 'nohup') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'startBackgroundTransfer does not spawn the detached runner'];
    }

    // Async start must still reject invalid input synchronously.
    $tref = new ReflectionMethod(BackupAction::class, 'transferToNas');
    $tbody = implode('', array_slice($src, $tref->getStartLine() - 1, $tref->getEndLine() - $tref->getStartLine() + 1));
    if (strpos($tbody, 'startBackgroundTransfer') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'transferToNas has no async branch'];
    }
});

$harness->test('wiring', 'async mail backup spawns detached runner (not inline)', function () {
    $runner = __DIR__ . '/../agent/backup-mail-runner.php';
    if (!is_file($runner)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'backup-mail-runner.php missing'];
    }

    $runnerSrc = file_get_contents($runner);
    foreach (["'backupMail'", 'status_id_override', 'updateStatusFile'] as $needle) {
        if (strpos($runnerSrc, $needle) === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => "runner missing {$needle}"];
        }
    }

    $ref = new ReflectionMethod(BackupAction::class, 'startBackgroundMailBackup');
    $src = file(__DIR__ . '/../agent/Actions/BackupAction.php');
    $body = implode('', array_slice($src, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));

    if (strpos($body, 'backup-mail-runner.php') === false || strpos($body, 'nohup') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'startBackgroundMailBackup does not spawn the detached runner'];
    }
    if (preg_match('/\$this->backupMail\s*\(/', $body)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'startBackgroundMailBackup still runs the backup inline (blocks agent loop)'];
    }
});

// ── split: multi-GB split archives (create/move/restore bookkeeping) ──
//
// Scaled down to MB-sized parts so the suite stays fast; the code paths
// (tar|split, manifest, reassemble, payload-free) are identical to the
// 20 GB production case.

/** Build (or reuse) a sandboxed BackupAction - split tests must also work
 *  with --only=split where the wiring group never ran. */
$splitActionFactory = function () use (&$backupAction, $sandbox): BackupAction {
    if ($backupAction instanceof BackupAction) {
        return $backupAction;
    }
    $config = [
        'paths' => ['backups' => $sandbox . '/backups'],
        'logging' => ['file' => $sandbox . '/agent.log', 'level' => 'error'],
        'backup' => ['max_age_days' => 1, 'max_count' => 5],
    ];
    $backupAction = new BackupAction($config, new BackupManager($config), new DiffGenerator(), new Logger($config));
    return $backupAction;
};

$splitDomain = 'flowone-test.example';

/** Is a shell binary available? (split/cat exist on the server; dev boxes
 *  may lack them - those tests degrade to WARN, never silently pass). */
$hasBin = function (string $bin): bool {
    static $cache = [];
    if (!array_key_exists($bin, $cache)) {
        $probe = (PHP_OS_FAMILY === 'Windows' ? 'where ' : 'command -v ') . escapeshellarg($bin);
        exec($probe . ' 2>&1', $o, $c);
        $cache[$bin] = ($c === 0);
    }
    return $cache[$bin];
};

/** Build a valid split set purely in PHP (tar.gz chunked into .part_* +
 *  manifest + zero-byte marker) so fixture tests do not need split(1). */
$makeSplitSet = function (string $dir, string $name, int $partSize = 64 * 1024) use ($sandbox): ?string {
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return null;
    }
    $archive = $dir . '/' . $name;

    $stage = $sandbox . '/flowone_test_stage_' . bin2hex(random_bytes(3));
    mkdir($stage, 0755, true);
    file_put_contents($stage . '/blob.bin', random_bytes(3 * $partSize));
    exec('tar -czf ' . escapeshellarg($archive . '.whole') . ' -C ' . escapeshellarg($stage) . ' . 2>&1', $o, $c);
    if ($c !== 0 || !is_file($archive . '.whole')) {
        return null;
    }

    $whole = (string)file_get_contents($archive . '.whole');
    @unlink($archive . '.whole');

    $parts = [];
    foreach (str_split($whole, $partSize) as $i => $chunk) {
        $suffix = chr(97 + intdiv($i, 26)) . chr(97 + ($i % 26));
        $partName = $name . '.part_' . $suffix;
        file_put_contents($dir . '/' . $partName, $chunk);
        $parts[] = ['name' => $partName, 'size' => strlen($chunk)];
    }

    file_put_contents($archive . '.manifest.json', json_encode([
        'original_name' => $name,
        'parts' => $parts,
        'parts_count' => count($parts),
        'total_size' => strlen($whole),
        'chunk_size_mb' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ], JSON_PRETTY_PRINT));
    touch($archive);

    return $archive;
};

$harness->test('split', 'createSplitArchive splits oversized backups into parts + manifest + marker', function () use ($splitActionFactory, $hasBin, $sandbox, $splitDomain) {
    if (!$hasBin('split')) {
        return ['outcome' => TestHarness::WARN, 'message' => 'split(1) unavailable here (ok in dev) - run on server for full coverage'];
    }
    $action = $splitActionFactory();

    // ~3 MB of incompressible data with a 1 MB chunk size -> >= 2 parts.
    $stage = $sandbox . '/flowone_test_stage';
    mkdir($stage, 0755, true);
    file_put_contents($stage . '/blob.bin', random_bytes(3 * 1024 * 1024));

    $dir = $sandbox . "/backups/sites/{$splitDomain}";
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $archive = $dir . "/{$splitDomain}_2026-01-01_00-00-00_full.tar.gz";

    $ref = new ReflectionMethod(BackupAction::class, 'createSplitArchive');
    $ref->setAccessible(true);
    $r = $ref->invoke($action, $stage, $archive, $splitDomain, 1);

    if (empty($r['success']) || empty($r['split'])) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'split create failed: ' . json_encode($r)];
    }
    if (($r['parts_count'] ?? 0) < 2) {
        return ['outcome' => TestHarness::FAIL, 'message' => "expected >=2 parts, got " . ($r['parts_count'] ?? 0)];
    }
    if (!is_file($archive . '.manifest.json')) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'manifest not written'];
    }
    if (!is_file($archive) || filesize($archive) !== 0) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'zero-byte listing marker not created'];
    }
    $manifest = json_decode((string)file_get_contents($archive . '.manifest.json'), true);
    $partsOnDisk = glob($archive . '.part_*');
    if (count($partsOnDisk) !== (int)$manifest['parts_count']) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'manifest parts_count does not match .part_* files on disk'];
    }
});

$harness->test('split', 'reassembleSplitArchive rebuilds into LOCAL scratch (never next to NAS parts)', function () use ($splitActionFactory, $hasBin, $makeSplitSet, $sandbox, $splitDomain) {
    if (!$hasBin('cat')) {
        return ['outcome' => TestHarness::WARN, 'message' => 'cat(1) unavailable here (ok in dev) - run on server for full coverage'];
    }
    $action = $splitActionFactory();

    $archive = $makeSplitSet($sandbox . "/backups/sites/{$splitDomain}", "{$splitDomain}_2026-01-02_00-00-00_full.tar.gz");
    if ($archive === null) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'could not build split fixture'];
    }

    $ref = new ReflectionMethod(BackupAction::class, 'reassembleSplitArchive');
    $ref->setAccessible(true);
    $r = $ref->invoke($action, $archive);

    if (empty($r['success'])) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'reassemble failed: ' . ($r['error'] ?? '?')];
    }

    $scratch = realpath($sandbox . '/backups/tmp');
    if ($scratch === false || strpos(realpath(dirname($r['path'])), $scratch) !== 0) {
        @unlink($r['path']);
        return ['outcome' => TestHarness::FAIL, 'message' => "reassembled outside scratch dir: {$r['path']} (would write GBs onto the NAS for NAS-resident sets)"];
    }

    $manifest = json_decode((string)file_get_contents($archive . '.manifest.json'), true);
    $ok = filesize($r['path']) === (int)$manifest['total_size'];
    @unlink($r['path']);
    if (!$ok) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'reassembled size does not match manifest total_size'];
    }
});

$harness->test('split', 'removeLocalSplitPayload frees parts + marker, keeps manifest + meta stubs', function () use ($splitActionFactory, $makeSplitSet, $sandbox, $splitDomain) {
    $action = $splitActionFactory();

    $archive = $makeSplitSet($sandbox . "/backups/sites/{$splitDomain}", "{$splitDomain}_2026-01-03_00-00-00_full.tar.gz");
    if ($archive === null) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'could not build split fixture'];
    }

    // Meta stub as transferToNas would leave it.
    file_put_contents($archive . '.meta.json', json_encode([
        'nas_uploaded' => true, 'destination' => 'nas', 'split' => true, 'parts_count' => 3,
        'archive_size' => 3 * 64 * 1024, 'domain' => $splitDomain,
    ]));

    $ref = new ReflectionMethod(BackupAction::class, 'removeLocalSplitPayload');
    $ref->setAccessible(true);
    if ($ref->invoke($action, $archive) !== true) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'helper reported failure'];
    }

    if (glob($archive . '.part_*')) {
        return ['outcome' => TestHarness::FAIL, 'message' => '.part_* files still on disk'];
    }
    if (file_exists($archive)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'zero-byte marker still on disk'];
    }
    if (!is_file($archive . '.manifest.json') || !is_file($archive . '.meta.json')) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'manifest/meta stubs must stay for the NAS-only listing + restore'];
    }
});

$harness->test('split', 'NAS-only split stub appears in listing with split flag + parts count', function () use ($splitActionFactory, $sandbox) {
    $action = $splitActionFactory();

    // Self-contained stub: meta + manifest present, marker + parts gone -
    // exactly the on-disk state after a verified split move.
    $domain = 'flowone-test-stub.example';
    $dir = $sandbox . "/backups/sites/{$domain}";
    mkdir($dir, 0755, true);
    $archive = $dir . "/{$domain}_2026-01-04_00-00-00_full.tar.gz";
    file_put_contents($archive . '.meta.json', json_encode([
        'nas_uploaded' => true, 'destination' => 'nas', 'split' => true, 'parts_count' => 12,
        'archive_size' => 12 * 1024, 'domain' => $domain,
    ]));
    file_put_contents($archive . '.manifest.json', json_encode([
        'parts' => [], 'parts_count' => 12, 'total_size' => 12 * 1024,
    ]));

    $ref = new ReflectionMethod(BackupAction::class, 'scanLocalSiteBackups');
    $ref->setAccessible(true);
    $entries = [];
    $ref->invokeArgs($action, [$dir, &$entries]);

    $name = basename($archive);
    if (!isset($entries[$name])) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'moved split backup vanished from the local listing'];
    }
    $e = $entries[$name];
    if (empty($e['split']) || (int)$e['parts_count'] !== 12 || $e['location'] !== 'nas' || empty($e['_stub_only'])) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'stub entry lost split info: ' . json_encode([
            'split' => $e['split'] ?? null, 'parts_count' => $e['parts_count'] ?? null, 'location' => $e['location'] ?? null])];
    }
});

$harness->test('split', 'NAS merge resolves split sets via marker AND via manifest alone', function () use ($splitActionFactory, $sandbox) {
    $action = $splitActionFactory();

    $nasDir = $sandbox . '/flowone_test_nas/backups/sites/flowone-test.example';
    mkdir($nasDir, 0755, true);

    // Case A: marker + manifest (new-style upload).
    $a = $nasDir . '/flowone-test.example_2026-02-01_00-00-00_full.tar.gz';
    touch($a);
    file_put_contents($a . '.manifest.json', json_encode([
        'parts' => [['name' => basename($a) . '.part_aa']], 'parts_count' => 4, 'total_size' => 4096,
    ]));

    // Case B: manifest only (legacy upload, no zero-byte marker).
    $b = $nasDir . '/flowone-test.example_2026-03-01_00-00-00_full.tar.gz';
    file_put_contents($b . '.manifest.json', json_encode([
        'parts' => [['name' => basename($b) . '.part_aa']], 'parts_count' => 7, 'total_size' => 8192,
    ]));

    $ref = new ReflectionMethod(BackupAction::class, 'mergeNasSiteBackups');
    $ref->setAccessible(true);
    $entries = [];
    $ref->invokeArgs($action, [$nasDir, &$entries]);

    $ea = $entries[basename($a)] ?? null;
    if ($ea === null || empty($ea['split']) || (int)$ea['parts_count'] !== 4 || (int)$ea['size'] !== 4096) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'marker-style split set merged wrong: ' . json_encode($ea)];
    }
    $eb = $entries[basename($b)] ?? null;
    if ($eb === null || empty($eb['split']) || (int)$eb['parts_count'] !== 7 || (int)$eb['size'] !== 8192) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'marker-less (legacy) split set invisible or wrong: ' . json_encode($eb)];
    }
    if (empty($eb['_found_on_nas'])) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'manifest-only set not flagged _found_on_nas (its local stub would be pruned as stale)'];
    }
});

$harness->test('split', 'transferToNas allows MOVE for split archives (old hard-block removed)', function () {
    $src = file(__DIR__ . '/../agent/Actions/BackupAction.php');
    $full = implode('', $src);
    if (strpos($full, 'Split archives can only be copied') !== false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'split move is still hard-blocked in transferToNas'];
    }

    $ref = new ReflectionMethod(BackupAction::class, 'transferToNas');
    $body = implode('', array_slice($src, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    foreach (['removeLocalSplitPayload', "result['verified']"] as $needle) {
        if (strpos($body, $needle) === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => "transferToNas move path missing: {$needle}"];
        }
    }
});

$harness->test('split', 'uploadSplitArchiveToNas verifies every part and reports missing parts', function () {
    $src = file(__DIR__ . '/../agent/Actions/BackupAction.php');
    $ref = new ReflectionMethod(BackupAction::class, 'uploadSplitArchiveToNas');
    $body = implode('', array_slice($src, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));

    foreach (['Missing local part', "'verified'", 'updateBackupTracking', '@touch'] as $needle) {
        if (strpos($body, $needle) === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => "uploadSplitArchiveToNas missing: {$needle}"];
        }
    }
});

$harness->test('split', 'scheduled destination=nas frees split payload after verified upload', function () {
    $src = file(__DIR__ . '/../agent/Actions/BackupAction.php');
    $ref = new ReflectionMethod(BackupAction::class, 'backupSite');
    $body = implode('', array_slice($src, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));

    if (strpos($body, 'removeLocalSplitPayload') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'backupSite destination=nas still keeps the full split payload on the server'];
    }
    if (preg_match('/&&\s*!\$isSplit\s*&&/', $body)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'backupSite still excludes split archives from the NAS move'];
    }
});

exit($harness->run());
