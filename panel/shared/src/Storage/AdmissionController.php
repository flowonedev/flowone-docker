<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 6b — Admission control for new-bytes operations.
 *
 * Wraps StorageBudget (6a) with the policy decisions every upload
 * site needs:
 *
 *   - Is the budget healthy enough to accept N more bytes RIGHT NOW?
 *   - If not, what watermark are we at, why, and how long should the
 *     client wait before retrying?
 *
 * Designed for the simplest possible call-site change: the existing
 * upload code already has `if (!$this->hasQuota(...))` checks. We
 * sit right next to those with a `admit(int $bytes)` call that:
 *
 *   - returns silently when the budget is OK, OR
 *   - throws StorageBudgetExceededException when it isn't
 *
 * Kill switch:
 *   When phase6b_admission_control is OFF, admit() is a no-op (no
 *   budget query, no exceptions). Production behaviour is unchanged
 *   until an operator flips the flag.
 *
 * NOT in this phase:
 *   - Triggering reclaim/tier-down inline (that's Phase 6c)
 *   - Per-user budget enforcement (DriveService::hasQuota already does it)
 *   - Soft-mode admission (queue for tier-down, accept anyway) — could
 *     be added later, but for now the contract is "accept or refuse"
 *
 * Decision rules:
 *   - watermark === critical                 -> REFUSE (always)
 *   - budget->canAccept(bytes) === false     -> REFUSE
 *   - StorageHealth not writable (OFFLINE/QUARANTINED/READ_ONLY/FROZEN)
 *     AND we'd need to write to NAS                -> REFUSE
 *   - everything else                              -> ACCEPT
 *
 * The retry hint we return is bounded:
 *   - HIGH watermark    -> 60s   (reclaim has time to catch up)
 *   - CRITICAL watermark-> 300s  (operator might need to intervene)
 *   - health unwritable -> 120s  (NAS may come back)
 */
final class AdmissionController
{
    public const RETRY_AFTER_HIGH_SEC     = 60;
    public const RETRY_AFTER_CRITICAL_SEC = 300;
    public const RETRY_AFTER_UNHEALTHY_SEC = 120;

    public function __construct(
        private StorageBudget $budget,
        private bool $enabled,
        private ?HealthStatusProvider $health = null,
        private int $perRequestBytesFloor = 0,
        private ?OperationJournal $journal = null,
    ) {}

    /**
     * Build an AdmissionController from the shared config + caller-
     * supplied PDO (so the budget can see the logical layer). When
     * phase6b_admission_control is OFF in config, returns a disabled
     * instance that admit() short-circuits as a no-op.
     */
    public static function build(?\PDO $pdo, ?array $storageConfig = null, ?OperationJournal $journal = null): self
    {
        $cfg = $storageConfig ?? Config::load();
        $enabled = (bool) ($cfg['phases']['phase6b_admission_control'] ?? false);
        $budget  = StorageBudget::build($pdo, $cfg, $journal);

        // StorageHealth is optional; admission control still functions
        // on budget alone, just without NAS-writability gating.
        $health = null;
        try {
            $health = StorageHealth::fromConfig(null);
        } catch (\Throwable) {
            // Missing HMAC key / state file in dev — skip the gate.
        }

        return new self(
            budget:               $budget,
            enabled:              $enabled,
            health:               $health,
            perRequestBytesFloor: (int) ($cfg['tier']['budget']['min_free_bytes'] ?? 0),
            journal:              $journal,
        );
    }

    /**
     * Throws StorageBudgetExceededException if the upload of $bytes
     * cannot be admitted RIGHT NOW. Returns silently when accepted.
     *
     * No-op when the kill switch is OFF (default in prod until flipped).
     */
    public function admit(int $bytes): void
    {
        if (!$this->enabled) {
            return;
        }
        $decision = $this->evaluate($bytes);
        if ($decision['accept']) {
            return;
        }
        $exc = new StorageBudgetExceededException(
            bytesAttempted: $bytes,
            watermark:      $decision['watermark'],
            reasons:        $decision['reasons'],
            retryAfterSec:  $decision['retry_after_sec'],
            report:         $decision['report'],
        );
        $this->journal?->record('admission_refused', [
            'bytes'           => $bytes,
            'watermark'       => $decision['watermark'],
            'reasons'         => $decision['reasons'],
            'retry_after_sec' => $decision['retry_after_sec'],
        ]);
        throw $exc;
    }

    /**
     * Pure-data version of admit(): returns a structured decision
     * without throwing. Useful for tests, telemetry, and any caller
     * that wants to render the watermark inline without try/catch.
     *
     * @return array{
     *     accept:bool,
     *     watermark:string,
     *     reasons:list<string>,
     *     retry_after_sec:int,
     *     report:?StorageBudgetReport,
     *     enabled:bool,
     * }
     */
    public function evaluate(int $bytes): array
    {
        if (!$this->enabled) {
            return [
                'accept'         => true,
                'watermark'      => StorageBudgetReport::WM_CLEAR,
                'reasons'        => ['admission control disabled (phase6b_admission_control=false)'],
                'retry_after_sec'=> 0,
                'report'         => null,
                'enabled'        => false,
            ];
        }

        $report = $this->budget->snapshot();
        $reasons = [];
        $accept = true;
        $retryAfter = 0;

        if ($report->isCritical()) {
            $accept = false;
            $retryAfter = max($retryAfter, self::RETRY_AFTER_CRITICAL_SEC);
            $reasons[] = 'watermark=critical';
            foreach ($report->reasons as $r) {
                $reasons[] = 'budget: ' . $r;
            }
        }

        if (!$report->canAccept($bytes, $this->perRequestBytesFloor)) {
            if ($accept) {
                $retryAfter = max($retryAfter, self::RETRY_AFTER_HIGH_SEC);
                $reasons[] = 'would push storage past safe limits';
            }
            $accept = false;
        }

        if ($this->health !== null) {
            try {
                $hs = $this->health->getStatus();
                if (!HealthState::isWritable($hs->status)) {
                    $accept = false;
                    $retryAfter = max($retryAfter, self::RETRY_AFTER_UNHEALTHY_SEC);
                    $reasons[] = "storage_health={$hs->status} (not writable)";
                }
            } catch (\Throwable $e) {
                // Don't fail admission on a health-check exception —
                // log it, treat as unknown-but-permissible. Reclaim
                // daemon (6c) will surface persistent NAS problems.
                $this->journal?->record('admission_health_check_failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($accept) {
            $reasons[] = 'all gates passed';
        }

        return [
            'accept'         => $accept,
            'watermark'      => $report->watermark,
            'reasons'        => $reasons,
            'retry_after_sec'=> $retryAfter,
            'report'         => $report,
            'enabled'        => true,
        ];
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
