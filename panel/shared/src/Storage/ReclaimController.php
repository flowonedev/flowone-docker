<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 6c — Reclaim daemon decision function.
 *
 * Pure: given (currentState, lastReclaimAtUnix, budgetReport, paused, killed),
 * return the next ReclaimDecision. No I/O, no side effects, no clocks
 * read internally — the caller passes `nowUnix` so tests are
 * deterministic.
 *
 * Decision table (after pause + kill-switch short-circuits):
 *
 *   IDLE        + watermark in {CLEAR, WARN}  -> stay IDLE       (poll_idle)
 *   IDLE        + watermark in {HIGH, CRIT}   -> WARMING         (poll_warming)
 *   WARMING     + watermark in {CLEAR, WARN}  -> IDLE            (poll_idle)
 *   WARMING     + watermark in {HIGH, CRIT}   -> RECLAIMING      (poll_reclaiming, shouldReclaim=true)
 *   RECLAIMING  + anything                    -> COOLDOWN        (poll_idle, shouldReclaim=false)
 *                                                                  // RECLAIMING always lasts exactly one tick
 *   COOLDOWN    + cooldown elapsed + watermark>=HIGH -> WARMING  (poll_warming)
 *   COOLDOWN    + cooldown elapsed + watermark<HIGH  -> IDLE     (poll_idle)
 *   COOLDOWN    + cooldown not elapsed              -> COOLDOWN  (poll_idle)
 *
 *   PAUSED + paused=true   -> PAUSED (poll_idle, shouldReclaim=false)
 *   PAUSED + paused=false  -> IDLE  (poll_idle, shouldReclaim=false)
 *
 * Kill switch (phase6c off): always returns nextState=IDLE, killed=true,
 * shouldReclaim=false. The daemon CLI separately refuses to loop when
 * the kill switch is off — this is the fail-safe in case it slips
 * through.
 */
final class ReclaimController
{
    public function __construct(
        public readonly int $pollIdleSec       = 60,
        public readonly int $pollWarmingSec    = 15,
        public readonly int $pollReclaimingSec = 5,
        public readonly int $cooldownSec       = 300,
    ) {}

    public static function fromConfig(array $cfg): self
    {
        $r = $cfg['tier']['reclaim'] ?? [];
        return new self(
            pollIdleSec:       max(1, (int) ($r['poll_idle_sec']       ?? 60)),
            pollWarmingSec:    max(1, (int) ($r['poll_warming_sec']    ?? 15)),
            pollReclaimingSec: max(1, (int) ($r['poll_reclaiming_sec'] ?? 5)),
            cooldownSec:       max(0, (int) ($r['cooldown_sec']        ?? 300)),
        );
    }

    public function decide(
        string $currentState,
        int    $lastReclaimAtUnix,
        StorageBudgetReport $report,
        bool   $paused,
        bool   $killed,
        int    $nowUnix
    ): ReclaimDecision {
        $wm = $report->watermark;

        if ($killed) {
            return new ReclaimDecision(
                nextState:       ReclaimState::IDLE,
                pollIntervalSec: $this->pollIdleSec,
                shouldReclaim:   false,
                reason:          'kill_switch_off (phase6c_reclaim_daemon=false)',
                watermark:       $wm,
                paused:          $paused,
                killed:          true,
            );
        }

        if ($paused) {
            return new ReclaimDecision(
                nextState:       ReclaimState::PAUSED,
                pollIntervalSec: $this->pollIdleSec,
                shouldReclaim:   false,
                reason:          'operator_paused (reclaim.paused flag present)',
                watermark:       $wm,
                paused:          true,
                killed:          false,
            );
        }

        $highPressure = ($wm === StorageBudgetReport::WM_HIGH || $wm === StorageBudgetReport::WM_CRITICAL);

        switch ($currentState) {
            case ReclaimState::PAUSED:
                return $this->mk(ReclaimState::IDLE, $this->pollIdleSec, false,
                    'operator_resumed', $wm, $paused);

            case ReclaimState::IDLE:
                if ($highPressure) {
                    return $this->mk(ReclaimState::WARMING, $this->pollWarmingSec, false,
                        "watermark_pressure ({$wm}); raising cadence", $wm, $paused);
                }
                return $this->mk(ReclaimState::IDLE, $this->pollIdleSec, false,
                    "watermark_ok ({$wm})", $wm, $paused);

            case ReclaimState::WARMING:
                if (!$highPressure) {
                    return $this->mk(ReclaimState::IDLE, $this->pollIdleSec, false,
                        "pressure_relieved ({$wm}) before reclaim cycle", $wm, $paused);
                }
                // Confirmed pressure — run a reclaim cycle this tick.
                return $this->mk(ReclaimState::RECLAIMING, $this->pollReclaimingSec, true,
                    "running_reclaim_cycle ({$wm})", $wm, $paused);

            case ReclaimState::RECLAIMING:
                // RECLAIMING always lasts exactly one tick; the daemon
                // calls the runner, then asks for the next decision
                // which transitions to COOLDOWN unconditionally.
                return $this->mk(ReclaimState::COOLDOWN, $this->pollIdleSec, false,
                    'cycle_complete; entering cooldown', $wm, $paused);

            case ReclaimState::COOLDOWN:
                $elapsed = $nowUnix - $lastReclaimAtUnix;
                if ($elapsed < $this->cooldownSec) {
                    $remaining = $this->cooldownSec - $elapsed;
                    return $this->mk(ReclaimState::COOLDOWN, $this->pollIdleSec, false,
                        "cooldown_active ({$remaining}s remaining)", $wm, $paused);
                }
                if ($highPressure) {
                    return $this->mk(ReclaimState::WARMING, $this->pollWarmingSec, false,
                        "cooldown_elapsed; still under pressure ({$wm})", $wm, $paused);
                }
                return $this->mk(ReclaimState::IDLE, $this->pollIdleSec, false,
                    "cooldown_elapsed; pressure relieved ({$wm})", $wm, $paused);

            default:
                // Unknown state: fail safe to IDLE.
                return $this->mk(ReclaimState::IDLE, $this->pollIdleSec, false,
                    "unknown_state_recovery (from='{$currentState}')", $wm, $paused);
        }
    }

    private function mk(string $next, int $poll, bool $reclaim, string $reason, string $wm, bool $paused): ReclaimDecision
    {
        return new ReclaimDecision(
            nextState:       $next,
            pollIntervalSec: $poll,
            shouldReclaim:   $reclaim,
            reason:          $reason,
            watermark:       $wm,
            paused:          $paused,
            killed:          false,
        );
    }
}
