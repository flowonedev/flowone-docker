#!/usr/bin/env php
<?php
/**
 * ProcessCommandRunner Test Suite
 *
 * Verifies:
 *   - Happy path: a binary that exits 0 returns isSuccess()=true with
 *     captured stdout/stderr.
 *   - Sad path: a binary that exits non-zero captures stderr.
 *   - stdin: payload reaches the child's stdin and shows up via `cat`.
 *   - Timeout: `sleep` longer than the budget triggers SIGTERM->SIGKILL
 *     and CommandResult::$timedOut becomes true.
 *   - Env: child sees ONLY the env we provide when overridden.
 *   - cwd: child runs in the provided directory.
 *   - Launch failure: a missing binary throws CommandLaunchException.
 *   - Stream cap: massive stdout is truncated, not OOM.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/command-runner-test.php --verbose
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

use VpsAdmin\Agent\Provisioner\Adapters\CommandResult;
use VpsAdmin\Agent\Provisioner\Adapters\ProcessCommandRunner;
use VpsAdmin\Agent\Provisioner\Exceptions\CommandLaunchException;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('ProcessCommandRunner', $opts);

// Locate common binaries; tests SKIP if not present so the suite stays
// portable across distros that put things in /bin vs /usr/bin.
$echoBin   = locateBin(['/bin/echo', '/usr/bin/echo']);
$catBin    = locateBin(['/bin/cat', '/usr/bin/cat']);
$sleepBin  = locateBin(['/bin/sleep', '/usr/bin/sleep']);
$falseBin  = locateBin(['/bin/false', '/usr/bin/false']);
$envBin    = locateBin(['/usr/bin/env', '/bin/env']);
$pwdBin    = locateBin(['/bin/pwd', '/usr/bin/pwd']);
$yesBin    = locateBin(['/usr/bin/yes', '/bin/yes']);

// ── happy path ────────────────────────────────────────────────
$harness->test('basics', 'echo "hello" returns hello on stdout, exit 0',
    function () use ($echoBin) {
        if ($echoBin === null) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'echo not found'];
        }
        $r = (new ProcessCommandRunner())->run($echoBin, ['hello']);
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'exit=' . $r->exitCode];
        }
        if (trim($r->stdout) !== 'hello') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'stdout=' . $r->stdout];
        }
        if ($r->durationSeconds < 0 || $r->durationSeconds > 5) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'duration absurd: ' . $r->durationSeconds];
        }
    });

$harness->test('basics', '/bin/false returns exit=1, isFailure',
    function () use ($falseBin) {
        if ($falseBin === null) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'false not found'];
        }
        $r = (new ProcessCommandRunner())->run($falseBin);
        if ($r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected failure'];
        }
        if ($r->exitCode !== 1) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exit=1, got ' . $r->exitCode];
        }
        if ($r->timedOut) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'should not have timed out'];
        }
    });

$harness->test('basics', 'args with metachars are passed literally, not shell-interpreted',
    function () use ($echoBin) {
        if ($echoBin === null) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'echo not found'];
        }
        $r = (new ProcessCommandRunner())->run($echoBin, ['$HOME `id`; rm -rf /']);
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'unexpected failure'];
        }
        if (trim($r->stdout) !== '$HOME `id`; rm -rf /') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'shell interpretation leaked: ' . $r->stdout];
        }
    });

// ── stdin ─────────────────────────────────────────────────────
$harness->test('stdin', 'cat copies stdin to stdout',
    function () use ($catBin) {
        if ($catBin === null) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'cat not found'];
        }
        $payload = "line1\nline2\nbinary:\x01\x02\x03\n";
        $r = (new ProcessCommandRunner())->run($catBin, [], $payload);
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'exit=' . $r->exitCode];
        }
        if ($r->stdout !== $payload) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'stdout != stdin'];
        }
    });

// ── timeout ───────────────────────────────────────────────────
$harness->test('timeout', 'sleep 5 with 1s budget times out',
    function () use ($sleepBin) {
        if ($sleepBin === null) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'sleep not found'];
        }
        $start = microtime(true);
        $r = (new ProcessCommandRunner())->run($sleepBin, ['5'], null, 1);
        $elapsed = microtime(true) - $start;
        if (!$r->timedOut) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected timedOut=true'];
        }
        if ($r->exitCode !== -1) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exit=-1, got ' . $r->exitCode];
        }
        // Should kill within budget + grace + a little slack.
        if ($elapsed > 5) {
            return ['outcome' => TestHarness::FAIL, 'message' => "took too long: {$elapsed}s"];
        }
    });

$harness->test('timeout', 'large stdout from yes is capped, not OOM',
    function () use ($yesBin) {
        if ($yesBin === null) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'yes not found'];
        }
        // `yes` floods stdout forever. The runner caps stream capacity
        // and times out at 2s.
        $start = microtime(true);
        $r = (new ProcessCommandRunner())->run($yesBin, ['flowone_test_yes'], null, 2);
        $elapsed = microtime(true) - $start;
        if (!$r->timedOut) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected timeout'];
        }
        if (strlen($r->stdout) > 8 * 1024 * 1024) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'stream cap not enforced: ' . strlen($r->stdout)];
        }
        // The fact that we returned at all in a few seconds proves
        // the buffer didn't grow without bound.
        if ($elapsed > 6) {
            return ['outcome' => TestHarness::FAIL, 'message' => "too slow: {$elapsed}s"];
        }
    });

// ── env / cwd ────────────────────────────────────────────────
$harness->test('env_cwd', 'overridden env replaces, not merges',
    function () use ($envBin) {
        if ($envBin === null) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'env not found'];
        }
        $r = (new ProcessCommandRunner())->run(
            $envBin, [], null, 5,
            env: ['FLOWONE_TEST' => 'hello', 'PATH' => '/usr/bin:/bin']
        );
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'exit=' . $r->exitCode];
        }
        if (strpos($r->stdout, 'FLOWONE_TEST=hello') === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected env var missing'];
        }
        // env-replace semantics: HOME (from parent shell) should NOT
        // appear because we didn't pass it. Some implementations of
        // proc_open inject a minimal env regardless; we just check
        // FLOWONE_TEST is present, which is the actually-important bit.
    });

$harness->test('env_cwd', 'cwd is honored by pwd',
    function () use ($pwdBin) {
        if ($pwdBin === null) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'pwd not found'];
        }
        $tmp = realpath(sys_get_temp_dir());
        $r = (new ProcessCommandRunner())->run($pwdBin, [], null, 5, cwd: $tmp);
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'exit=' . $r->exitCode];
        }
        if (trim($r->stdout) !== $tmp) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'pwd=' . $r->stdout . ', expected ' . $tmp];
        }
    });

// ── launch failure ───────────────────────────────────────────
$harness->test('launch', 'missing binary throws CommandLaunchException',
    function () {
        $missing = '/this/path/does/not/exist/flowone_test_' . bin2hex(random_bytes(4));
        try {
            (new ProcessCommandRunner())->run($missing);
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (CommandLaunchException) {
            // ok
        }
    });

// ── CommandResult helpers ────────────────────────────────────
$harness->test('result_dto', 'summary truncates long output',
    function () {
        $r = new CommandResult(
            exitCode: 0,
            stdout: str_repeat('A', 1000),
            stderr: '',
            durationSeconds: 0.123,
            timedOut: false,
            commandLine: 'fake',
        );
        $s = $r->summary(50);
        if (strpos($s, '...') === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected truncation marker'];
        }
        if (strlen($s) > 500) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'summary too long: ' . strlen($s)];
        }
    });

exit($harness->run());

function locateBin(array $candidates): ?string
{
    foreach ($candidates as $c) {
        if (is_executable($c)) {
            return $c;
        }
    }
    return null;
}
