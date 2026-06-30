<?php

declare(strict_types=1);

namespace FlowOne\Storage;

use FlowOne\Storage\Exceptions\InvariantViolation;

/**
 * Runtime assertions for the architectural invariants defined in
 * shared/docs/INVARIANTS.md. Each method maps to one numbered invariant
 * (I-1 ... I-15).
 *
 * Phase 1 ships the assertion surface and the safety-critical helpers
 * (I-9 signed payload, I-10 boot epoch, I-11 mount lock, I-12 freeze flag,
 * I-13 generation monotonicity). The drive/reclaim/admission invariants
 * (I-1 .. I-7) have placeholder method bodies that will be wired up to
 * real preconditions as the relevant phases ship; they all return TRUE
 * for now so existing callers don't break, but the assertion *exists* and
 * can be hooked. The CI mapping check ensures every documented invariant
 * has a corresponding method here.
 *
 * Behaviour: methods log a structured violation via OperationJournal
 * (when supplied) and return false. In strict mode (config.strict_invariants
 * = true), they throw InvariantViolation instead. Tests and chaos runs
 * enable strict mode to fail loudly.
 *
 * Adding a new invariant:
 *   1. document it in INVARIANTS.md
 *   2. add a method here named assert<Name> with @invariant I-N in the docblock
 *   3. wire at least one chaos scenario
 */
final class Invariants
{
    public function __construct(
        private ?OperationJournal $journal = null,
        private bool $strict = false,
    ) {}

    public static function fromConfig(?OperationJournal $journal = null): self
    {
        $strict = (bool) (Config::get('strict_invariants', false));
        return new self($journal, $strict);
    }

    // ────────────────────────────────────────────────────────────────────
    // Durability invariants
    // ────────────────────────────────────────────────────────────────────

    /**
     * @invariant I-1
     *
     * Hot deletion preconditions. Caller passes in the file row + the
     * outcome of the stability gate. Returns true iff all six conditions
     * hold.
     *
     * @param array<string,mixed> $fileRow  drive_files row.
     * @param array{passing:bool, since_monotonic_sec:float, observed_boot_epoch:int} $stabilityGate
     */
    public function assertHotDeletionPreconditions(array $fileRow, array $stabilityGate, int $currentBootEpoch): bool
    {
        $reasons = [];

        if (($fileRow['cold_present'] ?? 0) != 1) {
            $reasons[] = 'cold_present != 1';
        }
        if (($fileRow['tier_state'] ?? null) !== 'cold') {
            $reasons[] = 'tier_state != cold';
        }
        if (empty($fileRow['cold_xxhash64'])) {
            $reasons[] = 'cold_xxhash64 is empty';
        }
        if (empty($fileRow['cold_verified_this_run'])) {
            $reasons[] = 'cold not re-verified this run';
        }
        if (empty($stabilityGate['passing'])) {
            $reasons[] = 'stability gate not passing';
        } else {
            $minStable = (float) Config::get('stability_gate.min_stable_sec', 60);
            $heldFor = MonotonicClock::elapsedSec((int) ($stabilityGate['since_monotonic_sec'] * 1_000_000_000));
            if ($heldFor < $minStable) {
                $reasons[] = sprintf('stability gate held only %.1fs (need %ds)', $heldFor, $minStable);
            }
        }
        if (($stabilityGate['observed_boot_epoch'] ?? 0) !== $currentBootEpoch || $currentBootEpoch === 0) {
            $reasons[] = sprintf(
                'boot_epoch changed: gate observed %d, current %d',
                (int) ($stabilityGate['observed_boot_epoch'] ?? 0),
                $currentBootEpoch
            );
        }

        if (!empty($reasons)) {
            return $this->fail('I-1', 'Hot deletion preconditions not met', [
                'file_id' => $fileRow['id'] ?? null,
                'reasons' => $reasons,
            ]);
        }
        return true;
    }

    /**
     * @invariant I-2
     *
     * No file row may have hot_present=0 AND cold_present=0 UNLESS
     * tier_state='missing'.
     *
     * @param array<string,mixed> $fileRow
     */
    public function assertNoHalfState(array $fileRow): bool
    {
        $hot = (int) ($fileRow['hot_present'] ?? 0);
        $cold = (int) ($fileRow['cold_present'] ?? 0);
        $state = $fileRow['tier_state'] ?? null;
        if ($hot === 0 && $cold === 0 && $state !== 'missing') {
            return $this->fail('I-2', 'File has neither hot nor cold copy but tier_state != missing', [
                'file_id' => $fileRow['id'] ?? null,
                'tier_state' => $state,
            ]);
        }
        return true;
    }

    /**
     * @invariant I-3
     *
     * Reads of cold-only files require prior verification.
     *
     * @param array<string,mixed> $fileRow
     */
    public function assertColdAuthoritative(array $fileRow): bool
    {
        $state = $fileRow['tier_state'] ?? null;
        if ($state === 'unverified') {
            return $this->fail('I-3', 'Cold copy serving read while in unverified state', [
                'file_id' => $fileRow['id'] ?? null,
            ]);
        }
        return true;
    }

    /**
     * @invariant I-4
     *
     * tier_state transitions must follow the allowed graph.
     */
    public function assertTransitionAllowed(?string $from, string $to): bool
    {
        static $allowed = [
            'hot'         => ['tiering', 'corrupt', 'missing'],
            'tiering'     => ['unverified', 'corrupt', 'missing', 'hot'],
            'unverified'  => ['cold', 'corrupt', 'missing'],
            'cold'        => ['restoring', 'corrupt', 'missing'],
            'restoring'   => ['hot', 'corrupt', 'missing', 'cold'],
            'corrupt'     => ['hot', 'cold'],
            'missing'     => ['hot', 'cold'],
        ];
        if ($from === null) {
            // Insert (no prior state): any value is acceptable so long as it
            // is a known state.
            $known = array_keys($allowed);
            if (!in_array($to, $known, true)) {
                return $this->fail('I-4', 'Unknown initial tier_state', ['to' => $to]);
            }
            return true;
        }
        if (!isset($allowed[$from])) {
            return $this->fail('I-4', 'Unknown source tier_state', ['from' => $from, 'to' => $to]);
        }
        if (!in_array($to, $allowed[$from], true)) {
            return $this->fail('I-4', 'Disallowed tier_state transition', ['from' => $from, 'to' => $to]);
        }
        return true;
    }

    /**
     * @invariant I-5
     *
     * Every tier_state change must be journaled AND update last_tier_change.
     * Caller-driven: pass the before/after snapshot and the journal entry id
     * (returned by OperationJournal::record if we extend it later — for now
     * just non-null/empty).
     */
    public function assertJournaledChange(?string $beforeState, string $afterState, mixed $journalEntry): bool
    {
        if ($beforeState === $afterState) {
            return true; // no change, nothing to journal
        }
        if (empty($journalEntry)) {
            return $this->fail('I-5', 'tier_state changed without a journal entry', [
                'from' => $beforeState,
                'to' => $afterState,
            ]);
        }
        return true;
    }

    // ────────────────────────────────────────────────────────────────────
    // Request-path invariants
    // ────────────────────────────────────────────────────────────────────

    /**
     * @invariant I-6
     *
     * A storage-related request-path operation took too long. Called by
     * BaseController instrumentation. The threshold is 2 seconds by default.
     */
    public function assertRequestPathFast(string $operation, float $elapsedSec, float $maxSec = 2.0): bool
    {
        if ($elapsedSec > $maxSec) {
            return $this->fail('I-6', 'Request-path operation exceeded maximum latency', [
                'operation'   => $operation,
                'elapsed_sec' => $elapsedSec,
                'max_sec'     => $maxSec,
            ]);
        }
        return true;
    }

    /**
     * @invariant I-7
     *
     * Admission centralised. Phase 6 wires the actual controller; here we
     * just provide the assertion entry so the I-7 mapping is non-empty.
     */
    public function assertAdmissionChecked(bool $admissionWasChecked, string $tenant): bool
    {
        if (!$admissionWasChecked) {
            return $this->fail('I-7', 'Upload proceeded without calling AdmissionController', [
                'tenant' => $tenant,
            ]);
        }
        return true;
    }

    /**
     * @invariant I-8
     *
     * No request thread executes privileged actions. The helper's
     * SO_PEERCRED check is the primary enforcement; this assertion is a
     * defensive check for callers (e.g. controllers) that might attempt
     * to invoke mount/systemctl/nft directly.
     */
    public function assertNotInRequestThread(string $reason = 'privileged action'): bool
    {
        if (PHP_SAPI !== 'cli') {
            return $this->fail('I-8', 'Privileged action invoked from non-CLI context', [
                'sapi' => PHP_SAPI,
                'reason' => $reason,
            ]);
        }
        return true;
    }

    // ────────────────────────────────────────────────────────────────────
    // State / trust invariants
    // ────────────────────────────────────────────────────────────────────

    /**
     * @invariant I-9
     *
     * Any state payload must be HMAC-verified before trust. The boolean
     * return of HmacSigner::verify is the source of truth; this assertion
     * surfaces a structured violation when verification fails.
     */
    public function assertSignedPayload(bool $verified, string $source): bool
    {
        if (!$verified) {
            return $this->fail('I-9', 'State payload failed HMAC verification', [
                'source' => $source,
            ]);
        }
        return true;
    }

    /**
     * @invariant I-10
     *
     * Queued actions must carry the daemon's current boot epoch.
     */
    public function assertBootEpochMatches(int $actionEpoch, int $currentEpoch): bool
    {
        if ($actionEpoch !== $currentEpoch || $currentEpoch === 0) {
            return $this->fail('I-10', 'Action carries stale boot_epoch', [
                'action_epoch' => $actionEpoch,
                'current_epoch' => $currentEpoch,
            ]);
        }
        return true;
    }

    /**
     * @invariant I-11
     *
     * Mount operations require holding the MountLock.
     */
    public function assertMountLockHeld(MountLock $lock): bool
    {
        if (!$lock->isHeld()) {
            return $this->fail('I-11', 'Mount operation attempted without MountLock', []);
        }
        return true;
    }

    /**
     * @invariant I-12
     *
     * Freeze flag must be checked before destructive operations.
     */
    public function assertNotFrozen(): bool
    {
        $config = Config::load();
        $flag = rtrim((string) $config['state']['dir'], '/') . '/' . (string) $config['state']['freeze_flag'];
        if (is_file($flag)) {
            return $this->fail('I-12', 'Destructive operation attempted while system is frozen', [
                'flag_path' => $flag,
            ]);
        }
        return true;
    }

    /**
     * @invariant I-13
     *
     * Within a boot epoch, generation strictly increases.
     */
    public function assertGenerationMonotonic(
        int $previousGeneration,
        int $previousEpoch,
        int $currentGeneration,
        int $currentEpoch
    ): bool {
        if ($currentEpoch !== $previousEpoch) {
            return true; // new epoch resets
        }
        if ($currentGeneration < $previousGeneration) {
            return $this->fail('I-13', 'Generation decreased within same boot epoch', [
                'epoch' => $currentEpoch,
                'previous_generation' => $previousGeneration,
                'current_generation' => $currentGeneration,
            ]);
        }
        return true;
    }

    // ────────────────────────────────────────────────────────────────────
    // Recovery invariants
    // ────────────────────────────────────────────────────────────────────

    /**
     * @invariant I-14
     *
     * Recovery breaker bounds: ≤5 attempts per quarantine, ≤3 quarantines
     * per 24h before permanent open.
     */
    public function assertRecoveryWithinBounds(int $attemptsThisQuarantine, int $quarantinesLast24h): bool
    {
        $config = Config::load();
        $maxAttempts = (int) ($config['recovery_breaker']['attempts_per_quarantine'] ?? 5);
        $maxQuarantines = (int) ($config['recovery_breaker']['quarantines_before_permanent'] ?? 3);
        if ($attemptsThisQuarantine > $maxAttempts) {
            return $this->fail('I-14', 'Recovery attempts exceeded per-quarantine limit', [
                'attempts' => $attemptsThisQuarantine,
                'max' => $maxAttempts,
            ]);
        }
        if ($quarantinesLast24h > $maxQuarantines) {
            return $this->fail('I-14', 'Quarantines exceeded daily limit (breaker should be permanent-open)', [
                'quarantines' => $quarantinesLast24h,
                'max' => $maxQuarantines,
            ]);
        }
        return true;
    }

    /**
     * @invariant I-15
     *
     * Read breaker may not stay open longer than hard_cap_sec.
     */
    public function assertReadBreakerWithinHardCap(float $openForSec): bool
    {
        $hardCap = (float) Config::get('read_breaker.hard_cap_sec', 600);
        if ($openForSec > $hardCap) {
            return $this->fail('I-15', 'Read breaker open longer than hard cap', [
                'open_for_sec' => $openForSec,
                'hard_cap_sec' => $hardCap,
            ]);
        }
        return true;
    }

    // ────────────────────────────────────────────────────────────────────
    // Stability gate (composes I-1 preconditions; see INVARIANTS.md)
    // ────────────────────────────────────────────────────────────────────

    /**
     * Six-condition stability gate (see INVARIANTS.md#stability-gate).
     * Returns true iff ALL conditions hold simultaneously.
     *
     * Phase 1: the breakers and freeze flag are wired; status / generation
     * stability come from the StorageHealth pair. Phase 2 will replace the
     * status string check with the full 6-state enum.
     */
    public function stabilityGateOpen(
        HealthStatus $current,
        HealthStatus $sampledMinAgo,
        bool $recoveryBreakerClosed,
        bool $readBreakerClosed,
    ): bool {
        if (!$current->isHealthy()) {
            return false;
        }
        if (!$sampledMinAgo->isHealthy()) {
            return false;
        }
        if ($current->bootEpoch !== $sampledMinAgo->bootEpoch || $current->bootEpoch === 0) {
            return false;
        }
        if ($current->generation !== $sampledMinAgo->generation) {
            return false;
        }
        if (!$recoveryBreakerClosed || !$readBreakerClosed) {
            return false;
        }
        // Freeze flag check.
        $config = Config::load();
        $flag = rtrim((string) $config['state']['dir'], '/') . '/' . (string) $config['state']['freeze_flag'];
        if (is_file($flag)) {
            return false;
        }
        return true;
    }

    /**
     * Phase 3: enforce that any caller-provided absolute path stays
     * inside its declared tenant root. Distinct from TenantResolver's
     * own lexical/realpath check — this is the defence-in-depth hook
     * that callers (drive uploads, backup writers, retention sweep,
     * tier-down worker) invoke RIGHT BEFORE the destructive operation
     * with the path they actually computed.
     *
     * Logged under "tenant-safety" tag; no I-N number because the
     * canonical INVARIANTS.md is about file durability and request
     * safety, not the tenant layout.
     */
    public function assertPathInsideTenant(string $absolutePath, string $tenantRoot): bool
    {
        $rootReal = realpath($tenantRoot);
        if ($rootReal === false) {
            return $this->fail('tenant-safety', 'tenant root does not exist', [
                'tenant_root' => $tenantRoot,
                'path'        => $absolutePath,
            ]);
        }
        // Walk up to the first existing ancestor of $absolutePath so
        // realpath() can resolve symlinks even when the leaf doesn't
        // exist yet (e.g. about-to-be-created file).
        $existing = $absolutePath;
        while ($existing !== '' && $existing !== '/' && !file_exists($existing)) {
            $existing = dirname($existing);
        }
        $real = file_exists($existing) ? realpath($existing) : false;
        if ($real === false) {
            return $this->fail('tenant-safety', 'could not resolve path ancestor', [
                'tenant_root' => $rootReal,
                'path'        => $absolutePath,
            ]);
        }
        // Normalise separators so the prefix check works on Windows
        // (no-op on Linux/macOS, where production runs).
        $realN = str_replace('\\', '/', $real);
        $rootRealN = str_replace('\\', '/', $rootReal);
        if (!str_starts_with($realN . '/', $rootRealN . '/')) {
            return $this->fail('tenant-safety', 'path escapes tenant root', [
                'tenant_root' => $rootRealN,
                'real_path'   => $realN,
                'path'        => $absolutePath,
            ]);
        }
        return true;
    }

    /**
     * Phase 2: enforce the 6-state HealthState transition graph.
     *
     * Distinct from I-4 (assertTransitionAllowed) which guards file
     * tier_state transitions. This guards the SERVICE health state
     * published by the monitor daemon (HEALTHY/DEGRADED/READ_ONLY/
     * QUARANTINED/FROZEN/OFFLINE).
     *
     * Logged under the "health-fsm" tag for clarity; not numbered
     * because the original 15 invariants in INVARIANTS.md are about
     * file durability and request-path safety, not the service state
     * machine. Treat this as a defence-in-depth assertion local to the
     * classifier.
     */
    public function assertHealthStateTransitionAllowed(string $from, string $to): bool
    {
        if (!HealthState::canTransition($from, $to)) {
            return $this->fail('health-fsm', 'Illegal HealthState transition', [
                'from' => $from,
                'to'   => $to,
                'allowed_from_here' => HealthState::transitions()[$from] ?? [],
            ]);
        }
        return true;
    }

    /**
     * Common violation handler. Logs structured event to journal (if
     * present) AND error_log. Throws when in strict mode.
     */
    private function fail(string $id, string $message, array $context): bool
    {
        $context['invariant'] = $id;
        $context['message'] = $message;

        $this->journal?->record('invariant_violation', $context);
        error_log("[INVARIANT-VIOLATION {$id}] {$message} " . json_encode($context, JSON_UNESCAPED_SLASHES));

        if ($this->strict) {
            throw new InvariantViolation($id, $message, $context);
        }
        return false;
    }
}
