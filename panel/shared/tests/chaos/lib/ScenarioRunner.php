<?php

declare(strict_types=1);

namespace FlowOne\Storage\Chaos;

use FlowOne\Storage\ChaosTargetGuard;
use FlowOne\Storage\Config;
use FlowOne\Storage\MonotonicClock;

/**
 * Common scaffolding for a chaos scenario.
 *
 * Usage:
 *
 *   $runner = new ScenarioRunner('vpn_drop', [
 *       'I-9',   // signed-state fallback
 *       'I-14',  // recovery breaker bounds
 *   ]);
 *   $runner->requireLiveAck();   // refuses without --i-understand-this-is-live
 *   $runner->requireChaosEnabled();
 *   $runner->step('cut VPN', function() { ... });
 *   $runner->step('observe degraded within 30s', function() { ... });
 *   $runner->step('restore VPN', function() { ... });
 *   $runner->finish();           // emits JSON report; exit 0/1
 *
 * Pre-flight summary lists exactly what the scenario will mutate and
 * waits 5 seconds for the operator to Ctrl-C.
 */
final class ScenarioRunner
{
    private int $startNs;
    private array $steps = [];
    private array $failures = [];
    private bool $liveAcked = false;

    /**
     * @param list<string> $invariantIds  Invariants this scenario is
     *   meant to exercise. The runner records them in the report so the
     *   CI cross-reference check (`storage-ctl invariants check`) can
     *   confirm every documented invariant has at least one scenario.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $invariantIds,
        private readonly array $argv,
    ) {
        $this->startNs = MonotonicClock::nowNs();
        $this->printHeader();
    }

    public function requireLiveAck(): void
    {
        if (in_array('--i-understand-this-is-live', $this->argv, true)) {
            $this->liveAcked = true;
            return;
        }
        if (in_array('--help', $this->argv, true) || in_array('-h', $this->argv, true)) {
            $this->printHelp();
            exit(0);
        }
        fwrite(STDERR, "\nREFUSED: pass --i-understand-this-is-live to run on the live VPS.\n\n");
        $this->printHelp();
        exit(2);
    }

    public function requireChaosEnabled(): void
    {
        ChaosTargetGuard::fromConfig()->assertEnabled();
    }

    public function expectsTenant(string $tenantKey = ChaosTargetGuard::SYNTHETIC_TENANT_KEY): void
    {
        $arg = $this->arg('tenant');
        if ($arg !== $tenantKey) {
            fwrite(STDERR, "REFUSED: pass --tenant={$tenantKey} explicitly.\n");
            exit(2);
        }
    }

    /**
     * Optional argument value (e.g. --target=staging  -> arg('target')).
     */
    public function arg(string $key): ?string
    {
        foreach ($this->argv as $a) {
            if (str_starts_with($a, "--{$key}=")) {
                return substr($a, strlen("--{$key}="));
            }
        }
        return null;
    }

    public function announceAndDelay(): void
    {
        echo "\n=== CHAOS SCENARIO PRE-FLIGHT ===\n";
        echo "Name:        {$this->name}\n";
        echo "Invariants:  " . implode(', ', $this->invariantIds) . "\n";
        echo "Live VPS:    " . ($this->liveAcked ? 'ACKNOWLEDGED' : 'NO') . "\n";
        echo "Synthetic tenant only: YES (enforced by ChaosTargetGuard)\n";
        echo "Starting in 5 seconds. Press Ctrl-C to abort.\n";
        for ($i = 5; $i > 0; $i--) {
            echo "  {$i}...\n";
            sleep(1);
        }
        echo "\n";
    }

    /**
     * Run a named step. If $fn returns false or throws, the step is recorded
     * as a failure but execution continues so cleanup steps still run.
     */
    public function step(string $label, callable $fn): void
    {
        $start = MonotonicClock::nowNs();
        $ok = false;
        $err = null;
        try {
            $result = $fn();
            $ok = $result !== false;
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }
        $elapsed = MonotonicClock::elapsedSec($start);
        $this->steps[] = ['label' => $label, 'ok' => $ok, 'elapsed_sec' => round($elapsed, 3), 'error' => $err];
        if (!$ok) {
            $this->failures[] = $label;
        }
        $icon = $ok ? "\033[32m+\033[0m" : "\033[31mx\033[0m";
        printf("  %s [%6.2fs] %s%s\n", $icon, $elapsed, $label, $err ? " — {$err}" : '');
    }

    public function finish(): never
    {
        $totalSec = MonotonicClock::elapsedSec($this->startNs);
        $passed = count($this->steps) - count($this->failures);
        echo "\n=== SCENARIO COMPLETE ===\n";
        echo "Steps passed: {$passed}/" . count($this->steps) . "\n";
        echo sprintf("Total time:   %.2fs\n", $totalSec);
        if (!empty($this->failures)) {
            echo "Failed steps:\n";
            foreach ($this->failures as $f) {
                echo "  - {$f}\n";
            }
        }
        $report = [
            'name'         => $this->name,
            'invariants'   => $this->invariantIds,
            'steps'        => $this->steps,
            'failures'     => $this->failures,
            'total_sec'    => round($totalSec, 3),
            'finished_at'  => date('c'),
        ];
        $reportDir = '/var/log/flowone/chaos';
        @mkdir($reportDir, 0755, true);
        $reportPath = $reportDir . '/' . $this->name . '_' . date('Ymd_His') . '.json';
        @file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));
        echo "Report:       {$reportPath}\n";
        exit(empty($this->failures) ? 0 : 1);
    }

    private function printHeader(): void
    {
        echo "\033[1mFlowOne chaos scenario:\033[0m {$this->name}\n";
    }

    private function printHelp(): void
    {
        echo "Usage: php scenario_{$this->name}.php --i-understand-this-is-live --tenant=chaos-test [--target=local|staging]\n\n";
        echo "Required flags:\n";
        echo "  --i-understand-this-is-live   acknowledge running on live VPS\n";
        echo "  --tenant=chaos-test           confirm synthetic tenant target\n";
        echo "Optional:\n";
        echo "  --target=NAME                 scenario-specific target (default: local)\n";
        echo "  --help                        this help\n";
    }
}
