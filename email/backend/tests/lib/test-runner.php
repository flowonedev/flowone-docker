<?php
/**
 * Shared test runner for the OAuth + IMAP ground-up rewrite test scripts.
 *
 * Per .cursor/rules/server-side-testing.mdc each test script must
 * implement --help, --verbose, --skip-send, --only=, --smoke, --json,
 * a timestamped log under storage/logs/, color output, named tests
 * grouped by section, signal-safe cleanup, per-test timeout, and
 * exit 0/1 semantics. To avoid duplicating ~120 lines of boilerplate
 * across five test scripts, all of that lives here and is loaded
 * with:
 *
 *   require_once __DIR__ . '/lib/test-runner.php';
 *   $runner = new FlowOneTestRunner('oauth-pkce', $argv);
 *   $runner->section('1. CACHE');
 *   $runner->test('cache hit', fn() => ...);
 *   ...
 *   exit($runner->finish());
 *
 * This is test infrastructure only; it must never write to production
 * data.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "test-runner.php is CLI-only\n");
    exit(1);
}

final class FlowOneTestRunner
{
    public string $name;
    public bool $verbose = false;
    public bool $smoke = false;
    public bool $skipSend = false;
    public bool $json = false;
    public array $only = [];
    public array $extra = [];
    public string $logFile;

    private string $currentSection = '';
    private int $total = 0;
    private int $passed = 0;
    private int $failed = 0;
    private int $warned = 0;
    private array $results = [];
    /** @var array<callable> */
    private array $cleanups = [];
    private int $defaultTimeoutSec = 30;

    public function __construct(string $name, array $argv)
    {
        $this->name = $name;

        // Reparse argv ourselves so callers can stack getopt() too.
        foreach ($argv as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $this->printHelp();
                exit(0);
            } elseif ($arg === '--verbose') {
                $this->verbose = true;
            } elseif ($arg === '--smoke') {
                $this->smoke = true;
            } elseif ($arg === '--skip-send') {
                $this->skipSend = true;
            } elseif ($arg === '--json') {
                $this->json = true;
            } elseif (str_starts_with($arg, '--only=')) {
                $this->only = array_filter(array_map('trim', explode(',', substr($arg, 7))));
            } elseif (str_starts_with($arg, '--timeout=')) {
                $this->defaultTimeoutSec = max(1, (int)substr($arg, 10));
            } else {
                $this->extra[] = $arg;
            }
        }

        $logDir = realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $this->logFile = $logDir . DIRECTORY_SEPARATOR . $name . '-' . date('Ymd-His') . '.log';

        // Cleanup-on-signal so a SIGINT/SIGTERM still runs registered cleanups.
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () {
                $this->runCleanups();
                fwrite(STDERR, "\n[test-runner] interrupted; cleanups complete\n");
                exit(130);
            });
            pcntl_signal(SIGTERM, function () {
                $this->runCleanups();
                exit(143);
            });
        }

        $this->log('=== ' . $name . ' — ' . date('Y-m-d H:i:s T') . ' ===');
        $this->log('verbose=' . ($this->verbose ? '1' : '0')
            . ' smoke=' . ($this->smoke ? '1' : '0')
            . ' only=' . (empty($this->only) ? '(all)' : implode(',', $this->only))
            . ' log=' . $this->logFile);
    }

    private function printHelp(): void
    {
        echo "FlowOne test runner — " . $this->name . "\n";
        echo "  --help        Show this banner\n";
        echo "  --verbose     Extra debug output (stack traces, raw payloads)\n";
        echo "  --smoke       Quick health check mode (connectivity only)\n";
        echo "  --skip-send   Skip destructive/external write operations\n";
        echo "  --only=A,B    Run only the named test categories\n";
        echo "  --timeout=N   Per-test wall-clock timeout in seconds (default 30)\n";
        echo "  --json        Emit final results as JSON\n";
    }

    public function section(string $label): void
    {
        $this->currentSection = $label;
        $this->log('--- ' . $label . ' ---');
    }

    /**
     * Whether a section should run, given --only filter.
     *
     * Preflight is dependency setup (DB/Redis/config), not an optional test
     * group, so it ALWAYS runs — otherwise --only=<group> would skip it and
     * every test would fail with "preflight did not complete".
     */
    public function shouldRunSection(string $label): bool
    {
        $needle = strtolower(preg_replace('/^\d+\.\s*/', '', $label));
        if (str_contains($needle, 'preflight')) {
            return true;
        }
        if (empty($this->only)) {
            return true;
        }
        foreach ($this->only as $entry) {
            if (str_contains($needle, strtolower($entry))) {
                return true;
            }
        }
        return false;
    }

    public function addCleanup(callable $fn): void
    {
        $this->cleanups[] = $fn;
    }

    private function runCleanups(): void
    {
        foreach (array_reverse($this->cleanups) as $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $this->log('cleanup error: ' . $e->getMessage());
            }
        }
    }

    public function test(string $name, callable $fn, ?int $timeoutSec = null): void
    {
        $this->total++;
        $timeout = $timeoutSec ?? $this->defaultTimeoutSec;
        $start = microtime(true);

        // pcntl_alarm gives us a hard wall-clock guard so one hanging
        // network call does not block the whole suite.
        $alarmAvailable = function_exists('pcntl_alarm') && function_exists('pcntl_signal');
        if ($alarmAvailable) {
            pcntl_signal(SIGALRM, function () use ($name) {
                throw new \RuntimeException('test timed out: ' . $name);
            });
            pcntl_alarm($timeout);
        }

        try {
            $result = $fn();
            $ms = (int)round((microtime(true) - $start) * 1000);
            if ($result === 'warn' || $result === 'skip') {
                $this->warned++;
                $this->log(sprintf('  [%s]  %s (%dms)', strtoupper($result), $name, $ms));
                $this->results[] = ['name' => $name, 'status' => strtoupper($result), 'ms' => $ms];
            } else {
                $this->passed++;
                $this->log(sprintf('  [PASS]  %s (%dms)', $name, $ms));
                $this->results[] = ['name' => $name, 'status' => 'PASS', 'ms' => $ms];
            }
        } catch (\Throwable $e) {
            $ms = (int)round((microtime(true) - $start) * 1000);
            $this->failed++;
            $this->log(sprintf('  [FAIL]  %s (%dms)', $name, $ms));
            $this->log('          -> ' . $e->getMessage());
            if ($this->verbose) {
                $this->log('          at ' . $e->getFile() . ':' . $e->getLine());
            }
            $this->results[] = [
                'name' => $name,
                'status' => 'FAIL',
                'ms' => $ms,
                'error' => $e->getMessage(),
            ];
        } finally {
            if ($alarmAvailable) {
                pcntl_alarm(0);
            }
        }
    }

    public function log(string $msg): void
    {
        $line = $msg;
        echo $line . "\n";
        @file_put_contents($this->logFile, '[' . date('H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public function finish(): int
    {
        $this->runCleanups();

        $summary = sprintf(
            'Summary: total=%d passed=%d failed=%d warned=%d',
            $this->total, $this->passed, $this->failed, $this->warned
        );
        $this->log('');
        $this->log($summary);

        if ($this->failed > 0) {
            $this->log('Failures:');
            foreach ($this->results as $r) {
                if ($r['status'] === 'FAIL') {
                    $this->log('  - ' . $r['name'] . ': ' . ($r['error'] ?? ''));
                }
            }
        }

        if ($this->json) {
            echo json_encode([
                'name' => $this->name,
                'total' => $this->total,
                'passed' => $this->passed,
                'failed' => $this->failed,
                'warned' => $this->warned,
                'results' => $this->results,
            ], JSON_PRETTY_PRINT) . "\n";
        }

        return $this->failed > 0 ? 1 : 0;
    }

    /**
     * Helper to enforce a precondition; throws if false.
     */
    public function assertTrue($cond, string $msg = 'assertion failed'): void
    {
        if (!$cond) {
            throw new \RuntimeException($msg);
        }
    }

    public function assertEquals($expected, $actual, string $msg = 'mismatch'): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException($msg . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
        }
    }
}
