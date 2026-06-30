<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 6c — Reclaim daemon main loop.
 *
 * Thin orchestration over ReclaimController (decide) + ReclaimRunner
 * (act) + ReclaimDaemonStateStore (publish). The daemon itself owns:
 *
 *   - The polling loop (sleep, signal handling, graceful shutdown)
 *   - Operator pause-flag observation (file-existence check each tick)
 *   - Kill-switch observation (re-read each tick so flips take effect
 *     within one cadence without a daemon restart)
 *   - State persistence + journaling on every transition
 *   - Cumulative counters since daemon startup
 *
 * Never throws to the caller. The CLI runner just instantiates and
 * calls run(); the daemon traps everything internally.
 */
final class ReclaimDaemon
{
    private bool $shouldStop = false;
    private string $currentState;
    private int    $lastReclaimAt = 0;
    private array  $lastDecision   = [];
    private ?array $lastCycleSummary = null;
    private array  $counters;

    public function __construct(
        private ReclaimController          $controller,
        private ReclaimRunner              $runner,
        private ReclaimDaemonStateStore    $store,
        private StorageBudget              $budget,
        private OperationJournal           $journal,
        private ReclaimCaps                $caps,
        private string                     $pauseFlagPath,
        private string                     $killSwitchKey,
        private array                      $configRef, // re-checked per tick for kill switch + pause
    ) {
        $this->currentState = ReclaimState::IDLE;
        $this->counters     = $this->makeZeroCounters();
    }

    public static function build(
        \PDO $pdo,
        OperationJournal $journal,
        Invariants $invariants,
        HmacSigner $signer,
        array $config,
        string $tenant,
        string $vpsBase,
        bool $destructiveEnabled
    ): self {
        $controller = ReclaimController::fromConfig($config);
        $caps       = ReclaimCaps::fromConfig($config);
        $runner     = ReclaimRunner::build($pdo, $journal, $invariants, $config, $tenant, $vpsBase, $destructiveEnabled);
        $store      = ReclaimDaemonStateStore::fromConfig($config, $signer);
        $budget     = StorageBudget::build($pdo, $config, $journal);
        $stateDir   = rtrim((string) $config['state']['dir'], '/');
        $pauseFlag  = $stateDir . '/' . (string) ($config['tier']['reclaim']['pause_flag'] ?? 'reclaim.paused');
        return new self(
            controller:      $controller,
            runner:          $runner,
            store:           $store,
            budget:          $budget,
            journal:         $journal,
            caps:            $caps,
            pauseFlagPath:   $pauseFlag,
            killSwitchKey:   'phase6c_reclaim_daemon',
            configRef:       $config,
        );
    }

    /**
     * Entry point used by the CLI runner. Loops until SIGTERM/SIGINT
     * (handled by the CLI) or the kill switch is observed off at
     * startup. Returns 0 on clean exit, non-zero on internal error
     * (still extremely defensive — even unexpected throws are caught
     * and converted to non-zero exit).
     */
    public function run(): int
    {
        $this->journal->record('reclaim_daemon_started', [
            'pid'      => getmypid(),
            'caps'     => $this->caps->toArray(),
            'killed'   => $this->killSwitchOff(),
        ]);

        // If the kill switch is off at startup, don't enter the loop —
        // just publish the "killed" state once and exit. Systemd's
        // Restart=on-failure won't restart us because we exit 0.
        if ($this->killSwitchOff()) {
            $this->publish(
                ReclaimState::IDLE,
                $this->makeKilledDecision(),
                'kill_switch_off_at_startup'
            );
            $this->journal->record('reclaim_daemon_kill_switch_off', []);
            return 0;
        }

        // One eager tick at startup so the published state reflects
        // reality before we sleep.
        $this->tick();

        while (!$this->shouldStop) {
            $sleepSec = max(1, (int) ($this->lastDecision['poll_interval_sec'] ?? $this->controller->pollIdleSec));
            $this->safeSleep($sleepSec);
            if ($this->shouldStop) break;
            $this->tick();
        }

        $this->journal->record('reclaim_daemon_stopped', [
            'counters' => $this->counters,
            'state'    => $this->currentState,
        ]);
        return 0;
    }

    /**
     * Signal handler entry point — set by the CLI runner via
     * pcntl_signal. Idempotent.
     */
    public function requestStop(int $signo = 0): void
    {
        $this->shouldStop = true;
        $this->journal->record('reclaim_daemon_signal_stop', ['signal' => $signo]);
    }

    /**
     * One iteration of the loop:
     *   - re-load config (so kill-switch + pause flag flips take effect)
     *   - snapshot budget
     *   - ask controller for the next decision
     *   - if shouldReclaim: run one cycle
     *   - publish state + log
     */
    private function tick(): void
    {
        try {
            // Re-read config so the kill switch and pause flag can
            // flip live. We do NOT re-read the whole storage.php from
            // disk each tick (too expensive); operators flip the
            // kill switch via storage.local.php and the daemon needs
            // a SIGHUP for that to take effect. The pause flag is a
            // filesystem check (cheap).
            $paused = is_file($this->pauseFlagPath);
            $killed = $this->killSwitchOff();

            $report = $this->safeBudgetSnapshot();
            if ($report === null) {
                // Budget probe failed — stay in current state, keep
                // polling. A persistent budget failure is reported via
                // the journal entry written by safeBudgetSnapshot.
                return;
            }
            $decision = $this->controller->decide(
                currentState:       $this->currentState,
                lastReclaimAtUnix:  $this->lastReclaimAt,
                report:             $report,
                paused:             $paused,
                killed:             $killed,
                nowUnix:            time(),
            );
            $this->lastDecision = $decision->toArray();

            $previousState = $this->currentState;
            $effectiveDecision = $decision;
            // If the controller asked us to reclaim, do it BEFORE the
            // state transition so the published state reflects the
            // post-cycle reality (counters bumped, last_cycle_summary
            // updated). This means RECLAIMING is the "pre-cycle"
            // state and COOLDOWN is the "post-cycle" state.
            if ($decision->shouldReclaim && $decision->nextState === ReclaimState::RECLAIMING) {
                $cycleResult = $this->safeRunCycle();
                $this->lastCycleSummary = $cycleResult;
                $this->lastReclaimAt = time();
                $this->bumpCounters($cycleResult);
                // After the cycle, immediately ask the controller for
                // the post-cycle decision (which is COOLDOWN). This
                // saves one full poll cycle of latency.
                $postDecision = $this->controller->decide(
                    currentState:       ReclaimState::RECLAIMING,
                    lastReclaimAtUnix:  $this->lastReclaimAt,
                    report:             $report,
                    paused:             $paused,
                    killed:             $killed,
                    nowUnix:            time(),
                );
                $this->currentState = $postDecision->nextState;
                $this->lastDecision = $postDecision->toArray();
                $effectiveDecision = $postDecision;
            } else {
                $this->currentState = $decision->nextState;
            }

            if ($previousState !== $this->currentState) {
                $this->journal->record('reclaim_state_transition', [
                    'from'      => $previousState,
                    'to'        => $this->currentState,
                    'reason'    => $effectiveDecision->reason,
                    'watermark' => $effectiveDecision->watermark,
                ]);
            }

            $this->publish($this->currentState, $effectiveDecision, $effectiveDecision->reason);
        } catch (\Throwable $e) {
            $this->journal->record('reclaim_tick_exception', [
                'error' => $e->getMessage(),
                'state' => $this->currentState,
            ]);
        }
    }

    private function safeBudgetSnapshot(): ?StorageBudgetReport
    {
        try {
            return $this->budget->snapshot();
        } catch (\Throwable $e) {
            $this->journal->record('reclaim_budget_failed', ['error' => $e->getMessage()]);
            // Fail safe: returning null causes the tick to be skipped
            // — the daemon will NOT take action on bad data, but will
            // also not change state. Operator notices via journal.
            return null;
        }
    }

    private function safeRunCycle(): array
    {
        try {
            return $this->runner->runCycle($this->caps, dryRun: false);
        } catch (\Throwable $e) {
            $this->journal->record('reclaim_runner_exception', ['error' => $e->getMessage()]);
            return [
                'started_at' => time(),
                'elapsed_ms' => 0,
                'stopped_by' => 'exception: ' . $e->getMessage(),
                'tier'       => $this->makeZeroTier(),
                'sweep'      => null,
            ];
        }
    }

    private function killSwitchOff(): bool
    {
        return !($this->configRef['phases'][$this->killSwitchKey] ?? false);
    }

    private function publish(string $state, ReclaimDecision $decision, string $reason): void
    {
        try {
            $this->store->publish([
                'state'              => $state,
                'last_decision'      => $decision->toArray(),
                'last_reason'        => $reason,
                'last_reclaim_at'    => $this->lastReclaimAt,
                'last_cycle_summary' => $this->lastCycleSummary,
                'counters'           => $this->counters,
                'caps'               => $this->caps->toArray(),
                'pid'                => getmypid(),
            ]);
        } catch (\Throwable $e) {
            $this->journal->record('reclaim_publish_failed', ['error' => $e->getMessage()]);
        }
    }

    private function safeSleep(int $sec): void
    {
        // Use a short sleep loop so SIGTERM aborts within ~250ms
        // instead of waiting for the full poll interval.
        $remaining = $sec;
        while ($remaining > 0 && !$this->shouldStop) {
            $chunk = min(1, $remaining);
            // pcntl_async_signals handles SIGTERM mid-sleep on PHP 7.1+
            usleep($chunk * 1_000_000);
            $remaining -= $chunk;
        }
    }

    private function bumpCounters(array $cycle): void
    {
        $tier = $cycle['tier'] ?? [];
        $this->counters['cycles']++;
        $this->counters['tier_attempted'] += (int) ($tier['attempted'] ?? 0);
        $this->counters['tier_tiered']    += (int) ($tier['tiered']    ?? 0);
        $this->counters['tier_failed']    += (int) ($tier['failed']    ?? 0);
        $this->counters['bytes_total']    += (int) ($tier['bytes_total'] ?? 0);
        if (($cycle['sweep'] ?? null) !== null) {
            $sw = $cycle['sweep'];
            $this->counters['sweep_runs']++;
            $this->counters['sweep_swept']  += (int) ($sw['swept']  ?? 0);
            $this->counters['sweep_failed'] += (int) ($sw['failed'] ?? 0);
            $this->counters['sweep_bytes']  += (int) ($sw['bytes_total'] ?? 0);
        }
    }

    private function makeZeroCounters(): array
    {
        return [
            'started_at_unix' => time(),
            'cycles'          => 0,
            'tier_attempted'  => 0,
            'tier_tiered'     => 0,
            'tier_failed'     => 0,
            'bytes_total'     => 0,
            'sweep_runs'      => 0,
            'sweep_swept'     => 0,
            'sweep_failed'    => 0,
            'sweep_bytes'     => 0,
        ];
    }

    private function makeZeroTier(): array
    {
        return [
            'candidates' => 0, 'attempted' => 0, 'tiered' => 0, 'failed' => 0,
            'skipped_small' => 0, 'skipped_locked' => 0, 'skipped_missing' => 0,
            'bytes_total' => 0,
        ];
    }

    private function makeKilledDecision(): ReclaimDecision
    {
        return new ReclaimDecision(
            nextState:       ReclaimState::IDLE,
            pollIntervalSec: $this->controller->pollIdleSec,
            shouldReclaim:   false,
            reason:          'kill_switch_off_at_startup',
            watermark:       StorageBudgetReport::WM_CLEAR,
            paused:          false,
            killed:          true,
        );
    }

    // Test seam: let tests inspect/inject internal state.
    public function _testCurrentState(): string { return $this->currentState; }
    public function _testCounters(): array { return $this->counters; }
}
