<?php

declare(strict_types=1);

namespace FlowOne\Storage;

use FlowOne\Storage\Breakers\ReadBreaker;
use FlowOne\Storage\Breakers\RecoveryBreaker;

/**
 * Phase 2 state machine classifier.
 *
 * Consumes the raw outcome of one monitor probe cycle and produces a
 * structured classification — the six-state HealthState, a root cause,
 * and the snapshots of the gate / breakers that influenced the decision.
 *
 * Inputs are expressed as a small struct-like array (see ProbeResult
 * constants below) so the monitord can stay procedural and pass results
 * in without coupling.
 *
 * Decision precedence (highest first):
 *   1. FROZEN flag present                  -> FROZEN
 *   2. Recovery breaker permanent           -> QUARANTINED
 *   3. NFS unreachable / rw fully failing   -> OFFLINE
 *   4. Reads OK but writes failing          -> READ_ONLY
 *   5. Read breaker open OR slow probe OR
 *      helper unreachable                    -> DEGRADED
 *   6. All clear AND stability gate satisfied -> HEALTHY
 *   7. Otherwise (warming up)               -> DEGRADED
 */
final class Classifier
{
    public const PROBE_READ_OK   = 'read_ok';
    public const PROBE_WRITE_OK  = 'write_ok';
    public const PROBE_LATENCY   = 'latency_sec';
    public const PROBE_SLOW      = 'slow';
    public const PROBE_HELPER_UP = 'helper_up';
    public const PROBE_FROZEN    = 'frozen';

    private StabilityGate    $gate;
    private ReadBreaker      $readBreaker;
    private RecoveryBreaker  $recoveryBreaker;

    public function __construct(
        StabilityGate $gate,
        ReadBreaker $readBreaker,
        RecoveryBreaker $recoveryBreaker
    ) {
        $this->gate            = $gate;
        $this->readBreaker     = $readBreaker;
        $this->recoveryBreaker = $recoveryBreaker;
    }

    /**
     * @param array<string,mixed> $probe   ProbeResult-shaped data
     * @param string              $current Currently-published state
     * @return array{state:string,root_cause:?string,root_cause_detail:?string,gate:array,read_breaker:array,recovery_breaker:array}
     */
    public function classify(array $probe, string $current, ?int $nowMonoNs = null, ?int $nowUnix = null): array
    {
        $nowMonoNs = $nowMonoNs ?? MonotonicClock::nowNs();
        $nowUnix   = $nowUnix   ?? time();

        $readOk    = (bool) ($probe[self::PROBE_READ_OK]   ?? false);
        $writeOk   = (bool) ($probe[self::PROBE_WRITE_OK]  ?? false);
        $latency   = (float)($probe[self::PROBE_LATENCY]   ?? 0.0);
        $slow      = (bool) ($probe[self::PROBE_SLOW]      ?? false);
        $helperUp  = (bool) ($probe[self::PROBE_HELPER_UP] ?? false);
        $frozen    = (bool) ($probe[self::PROBE_FROZEN]    ?? false);

        // Feed the read breaker.
        $this->readBreaker->recordProbe($latency, $readOk, $nowMonoNs);
        $readBreakerOpen = $this->readBreaker->evaluate($nowMonoNs);

        $rootCause = null;
        $rootCauseDetail = null;

        // 1. Operator freeze wins over everything.
        if ($frozen) {
            $state = HealthState::FROZEN;
            $rootCause = 'operator_freeze';
            $rootCauseDetail = 'freeze.flag present in state dir';
        }
        // 2. Permanent recovery quarantine.
        elseif ($this->recoveryBreaker->isPermanent()) {
            $state = HealthState::QUARANTINED;
            $rootCause = 'recovery_quarantine_permanent';
            $rootCauseDetail = 'recovery breaker tripped permanently — operator clear required';
        }
        // 3. NAS fully unreachable.
        elseif (!$readOk && !$writeOk) {
            $state = HealthState::OFFLINE;
            $rootCause = 'nas_unreachable';
            $rootCauseDetail = 'read and write probes both failed';
        }
        // 4. Reads work, writes don't — degraded into READ_ONLY.
        elseif ($readOk && !$writeOk) {
            $state = HealthState::READ_ONLY;
            $rootCause = 'nas_writes_failing';
            $rootCauseDetail = 'rw probe failed but read probe succeeded';
        }
        // 5. Read breaker / slow probe / helper down -> degraded.
        elseif ($readBreakerOpen || $slow || !$helperUp) {
            $state = HealthState::DEGRADED;
            if ($readBreakerOpen) {
                $rootCause = 'read_breaker_open';
                $rootCauseDetail = (string) $this->readBreaker->openReason();
            } elseif ($slow) {
                $rootCause = 'nas_slow';
                $rootCauseDetail = sprintf('probe latency %.2fs over threshold', $latency);
            } else {
                $rootCause = 'helper_unreachable';
                $rootCauseDetail = 'storage-helper socket did not respond to ping';
            }
        }
        // 6. All probes clean — candidate for HEALTHY, subject to stability gate.
        else {
            $state = HealthState::HEALTHY;
        }

        $this->gate->observe($state, $nowMonoNs);

        // Apply the stability gate to HEALTHY promotions.
        if ($state === HealthState::HEALTHY
            && !$this->gate->allowPromotion($current, $state, $nowMonoNs)
        ) {
            $state = HealthState::DEGRADED;
            $rootCause = 'stability_gate_warming';
            $rootCauseDetail = 'awaiting min_stable_sec of continuous health before HEALTHY';
        }

        return [
            'state'             => $state,
            'root_cause'        => $rootCause,
            'root_cause_detail' => $rootCauseDetail,
            'gate'              => $this->gate->snapshot($nowMonoNs),
            'read_breaker'      => $this->readBreaker->snapshot($nowMonoNs),
            'recovery_breaker'  => $this->recoveryBreaker->snapshot($nowUnix),
        ];
    }
}
