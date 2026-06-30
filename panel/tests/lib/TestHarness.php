<?php

declare(strict_types=1);

namespace VpsAdmin\Tests\Lib;

/**
 * Shared scaffolding for the Provisioner foundation test scripts.
 *
 * Each test script wires up a TestHarness, registers groups of tests,
 * parses common CLI flags, and runs the suite with consistent log
 * format and signal-safe cleanup.
 *
 * Requirements satisfied (per .cursor/rules/server-side-testing.mdc):
 *   - CLI-only guard
 *   - --help, --verbose, --smoke, --only, --json flags
 *   - Section headers per group
 *   - Pass/fail/warn outcomes with elapsed times
 *   - Color-coded terminal output
 *   - Timestamped log file under storage/logs/
 *   - Signal handlers for cleanup on SIGINT/SIGTERM
 *   - Pre-flight checks before any tests
 *   - Exit 0 on all pass, 1 on any fail
 *
 * Test data convention: anything created during a test gets a
 * `flowone_test_` prefix (DB names, users, files) or `[FLOWONE-TEST]`
 * tag (free-form). The harness asserts cleanup leaves zero such
 * artifacts behind.
 */
final class TestHarness
{
    public const PASS = 'PASS';
    public const FAIL = 'FAIL';
    public const WARN = 'WARN';
    public const SKIP = 'SKIP';

    private array $opts;
    private string $logFile;
    /** @var resource */
    private $logHandle;

    /** @var array<string, list<array{name:string, fn:callable}>> */
    private array $groups = [];
    private array $results = [];
    private array $cleanupCallbacks = [];

    public function __construct(
        public readonly string $suiteName,
        array $opts
    ) {
        $this->opts = $opts;

        $logDir = __DIR__ . '/../../../storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($suiteName));
        $this->logFile = $logDir . '/' . $slug . '-' . date('Ymd-His') . '.log';
        $this->logHandle = fopen($this->logFile, 'a');

        $this->installSignalHandlers();
    }

    /**
     * Register a test in a group. Tests run in registration order within their group.
     */
    public function test(string $group, string $name, callable $fn): void
    {
        $this->groups[$group] ??= [];
        $this->groups[$group][] = ['name' => $name, 'fn' => $fn];
    }

    /**
     * Register a cleanup callback that runs even on SIGINT/SIGTERM.
     */
    public function onCleanup(callable $fn): void
    {
        $this->cleanupCallbacks[] = $fn;
    }

    public function isVerbose(): bool
    {
        return isset($this->opts['verbose']);
    }

    public function isSmoke(): bool
    {
        return isset($this->opts['smoke']);
    }

    public function isJson(): bool
    {
        return isset($this->opts['json']);
    }

    /**
     * @return list<string>
     */
    public function onlyGroups(): array
    {
        if (!isset($this->opts['only'])) {
            return [];
        }
        return array_filter(array_map('trim', explode(',', (string) $this->opts['only'])));
    }

    /**
     * Run all registered tests. Returns process exit code (0 = success).
     */
    public function run(): int
    {
        $start = microtime(true);
        $only = $this->onlyGroups();
        $smoke = $this->isSmoke();

        if (!$this->isJson()) {
            $this->banner("FlowOne Provisioner Foundation Tests :: {$this->suiteName}");
            $this->info("Log: {$this->logFile}");
            if ($only) {
                $this->info('Filtering to groups: ' . implode(',', $only));
            }
            if ($smoke) {
                $this->info('SMOKE mode: pre-flight only, skipping body');
            }
        }

        $totalPass = $totalFail = $totalWarn = $totalSkip = 0;

        foreach ($this->groups as $group => $tests) {
            // `preflight` is the universal setup group across every suite -
            // it wires up shared `$pdo` / `$db` / `$action` state by reference
            // that every other group depends on. Filtering it out with
            // `--only=foo` would leave those references null and the targeted
            // group would crash on the first `prepare()` / `execute()` call.
            // Always run preflight regardless of the filter; if it fails the
            // dependent groups will still surface their own failures.
            $isPreflight = $group === 'preflight';
            if ($only && !$isPreflight && !in_array($group, $only, true)) {
                continue;
            }
            if ($smoke && !$isPreflight) {
                continue;
            }

            if (!$this->isJson()) {
                $this->section("--- {$group} ---");
            }

            foreach ($tests as $entry) {
                $name = $entry['name'];
                $fn = $entry['fn'];
                $tStart = microtime(true);
                $outcome = self::PASS;
                $message = '';

                try {
                    $result = $fn();
                    if (is_array($result)) {
                        $outcome = $result['outcome'] ?? self::PASS;
                        $message = $result['message'] ?? '';
                    }
                } catch (\Throwable $e) {
                    $outcome = self::FAIL;
                    $message = $e->getMessage();
                    if ($this->isVerbose()) {
                        $message .= "\n" . $e->getTraceAsString();
                    }
                }

                $elapsed = (int) round((microtime(true) - $tStart) * 1000);
                $this->record($group, $name, $outcome, $message, $elapsed);

                switch ($outcome) {
                    case self::PASS: $totalPass++; break;
                    case self::FAIL: $totalFail++; break;
                    case self::WARN: $totalWarn++; break;
                    case self::SKIP: $totalSkip++; break;
                }
            }
        }

        // Print summary BEFORE cleanup. runCleanup() closes the log file
        // handle, and any subsequent fwrite() to it raises a fatal TypeError
        // on PHP 8.x (closed resource is not a valid stream resource), which
        // would kill the script with exit code 255 before we hit our own
        // `return $totalFail === 0 ? 0 : 1;` below.
        $total = $totalPass + $totalFail + $totalWarn + $totalSkip;
        $elapsed = (int) round((microtime(true) - $start) * 1000);

        if ($this->isJson()) {
            echo json_encode([
                'suite' => $this->suiteName,
                'total' => $total,
                'pass' => $totalPass,
                'fail' => $totalFail,
                'warn' => $totalWarn,
                'skip' => $totalSkip,
                'elapsed_ms' => $elapsed,
                'log' => $this->logFile,
                'results' => $this->results,
            ], JSON_PRETTY_PRINT) . "\n";
        } else {
            $this->section('--- Summary ---');
            echo sprintf(
                "  Total: %d  Pass: %d  Fail: %d  Warn: %d  Skip: %d  (%dms)\n",
                $total, $totalPass, $totalFail, $totalWarn, $totalSkip, $elapsed
            );
            if ($totalFail > 0) {
                echo "\nFailed tests:\n";
                foreach ($this->results as $r) {
                    if ($r['outcome'] === self::FAIL) {
                        echo "  - [{$r['group']}] {$r['name']}: {$r['message']}\n";
                    }
                }
            }
            echo "\nLog: {$this->logFile}\n";
        }

        $this->runCleanup();

        return $totalFail === 0 ? 0 : 1;
    }

    private function record(string $group, string $name, string $outcome, string $message, int $elapsedMs): void
    {
        $this->results[] = [
            'group' => $group,
            'name' => $name,
            'outcome' => $outcome,
            'message' => $message,
            'elapsed_ms' => $elapsedMs,
        ];

        $line = sprintf(
            "[%s] [%s] %s (%dms)%s\n",
            date('H:i:s'),
            $outcome,
            $name,
            $elapsedMs,
            $message ? '  -- ' . str_replace(["\n", "\r"], ' / ', $message) : ''
        );
        $this->writeLog($line);

        if ($this->isJson()) {
            return;
        }
        $color = match ($outcome) {
            self::PASS => "\033[32m",
            self::FAIL => "\033[31m",
            self::WARN => "\033[33m",
            self::SKIP => "\033[90m",
            default => "\033[0m",
        };
        $reset = "\033[0m";
        echo sprintf(
            "  %s%-4s%s %-60s %5dms%s\n",
            $color, $outcome, $reset,
            $this->truncate($name, 60),
            $elapsedMs,
            $message && $this->isVerbose()
                ? ' -- ' . $this->truncate($message, 200)
                : ''
        );
    }

    private function runCleanup(): void
    {
        foreach ($this->cleanupCallbacks as $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $this->writeLog("[CLEANUP-FAIL] " . $e->getMessage() . "\n");
            }
        }
        if (is_resource($this->logHandle)) {
            fclose($this->logHandle);
        }
    }

    /**
     * Safe wrapper for log writes. PHP 8.x raises a fatal TypeError on
     * fwrite() to a closed resource - centralizing the guard here ensures
     * a single forgotten close path can never kill the test runner.
     */
    private function writeLog(string $line): void
    {
        if (is_resource($this->logHandle)) {
            @fwrite($this->logHandle, $line);
        }
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        $shutdown = function (int $signo): void {
            $this->info("Received signal {$signo}, cleaning up and exiting");
            $this->runCleanup();
            exit(130);
        };
        pcntl_signal(SIGINT, $shutdown);
        pcntl_signal(SIGTERM, $shutdown);
    }

    public function banner(string $text): void
    {
        $bar = str_repeat('=', max(60, strlen($text) + 4));
        echo "\n{$bar}\n  {$text}\n{$bar}\n";
        $this->writeLog("{$bar}\n  {$text}\n{$bar}\n");
    }

    public function section(string $text): void
    {
        echo "\n\033[1m{$text}\033[0m\n";
        $this->writeLog("\n{$text}\n");
    }

    public function info(string $text): void
    {
        echo "  {$text}\n";
        $this->writeLog("  {$text}\n");
    }

    public function logFile(): string
    {
        return $this->logFile;
    }

    private function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max - 3) . '...';
    }
}
