<?php

namespace Webmail\Services;

/**
 * Folder state machine for the All Mail / mailbox scan pipeline.
 *
 * Five states, kept in Redis transiently in Wave 1 and persisted on the
 * folder_index row in Wave 2.
 *
 *   healthy     - retrieved == total, 0 bad_uids, no fallback transitions
 *   degraded    - retrieved < total, or bad_uids > 0, or any fallback fired
 *   quarantined - circuit breaker tripped; suppressed for cooldown window
 *   ignored     - operator-marked (admin opt-out); never re-scanned
 *   deleted     - upstream IMAP removal (no longer in imap_list); kept 30d
 *                 for historical refs (pins, labels, conversation history)
 *
 * State transitions emit a single structured log line so the corpus can be
 * grepped after the fact.
 */
final class FolderStateMachine
{
    public const HEALTHY = 'healthy';
    public const DEGRADED = 'degraded';
    public const QUARANTINED = 'quarantined';
    public const IGNORED = 'ignored';
    public const DELETED = 'deleted';

    /** Allowed transitions. Anything not listed is rejected. */
    private const TRANSITIONS = [
        self::HEALTHY => [self::DEGRADED, self::QUARANTINED, self::IGNORED, self::DELETED],
        self::DEGRADED => [self::HEALTHY, self::QUARANTINED, self::IGNORED, self::DELETED],
        self::QUARANTINED => [self::HEALTHY, self::DEGRADED, self::IGNORED, self::DELETED],
        self::IGNORED => [self::HEALTHY, self::DELETED],
        self::DELETED => [self::HEALTHY],
    ];

    /** TTL for transient Redis state in Wave 1. 7 days. */
    private const STATE_TTL = 604800;

    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    /**
     * All known states.
     *
     * @return string[]
     */
    public static function allStates(): array
    {
        return [self::HEALTHY, self::DEGRADED, self::QUARANTINED, self::IGNORED, self::DELETED];
    }

    /**
     * Validate a state value.
     */
    public static function isValid(string $state): bool
    {
        return in_array($state, self::allStates(), true);
    }

    /**
     * Read the current state of a folder. Returns 'healthy' if we have no
     * record and Redis is unavailable; returns the persisted state otherwise.
     */
    public function get(string $accountKey, string $folderPath): string
    {
        if (!$this->redis->isAvailable()) {
            return self::HEALTHY;
        }
        $raw = $this->redis->get($this->key($accountKey, $folderPath));
        if (is_array($raw) && isset($raw['state']) && self::isValid($raw['state'])) {
            return $raw['state'];
        }
        if (is_string($raw) && self::isValid($raw)) {
            return $raw;
        }
        return self::HEALTHY;
    }

    /**
     * Read the full record (state + metadata).
     *
     * @return array{state:string, last_attempt_at:?int, retry_after:?int, reason:?string}
     */
    public function inspect(string $accountKey, string $folderPath): array
    {
        $default = [
            'state' => self::HEALTHY,
            'last_attempt_at' => null,
            'retry_after' => null,
            'reason' => null,
        ];
        if (!$this->redis->isAvailable()) {
            return $default;
        }
        $raw = $this->redis->get($this->key($accountKey, $folderPath));
        if (!is_array($raw)) {
            return $default;
        }
        $state = $raw['state'] ?? self::HEALTHY;
        if (!self::isValid($state)) {
            $state = self::HEALTHY;
        }
        return [
            'state' => $state,
            'last_attempt_at' => isset($raw['last_attempt_at']) ? (int) $raw['last_attempt_at'] : null,
            'retry_after' => isset($raw['retry_after']) ? (int) $raw['retry_after'] : null,
            'reason' => isset($raw['reason']) ? (string) $raw['reason'] : null,
        ];
    }

    /**
     * Transition a folder to a new state. Emits one structured log line on
     * change; emits nothing if the state is unchanged. Rejects illegal
     * transitions.
     *
     * @param array $context Optional extra context for the log line
     *                      (account_id, folder_id, fallback_stage, etc.).
     */
    public function transition(
        string $accountKey,
        string $folderPath,
        string $toState,
        array $context = []
    ): bool {
        if (!self::isValid($toState)) {
            return false;
        }

        $current = $this->inspect($accountKey, $folderPath);
        $fromState = $current['state'];

        if ($fromState === $toState) {
            // Refresh metadata even when state is unchanged so retry_after
            // and last_attempt_at stay current.
            $this->persist($accountKey, $folderPath, $toState, $context);
            return true;
        }

        // Reject illegal transitions; log them so we can audit code paths.
        $allowed = self::TRANSITIONS[$fromState] ?? [];
        if (!in_array($toState, $allowed, true)) {
            StructuredLog::emit('state_transition_rejected', array_merge($context, [
                'folder_path' => $folderPath,
                'from_state' => $fromState,
                'to_state' => $toState,
                'reason' => 'illegal_transition',
            ]));
            return false;
        }

        $this->persist($accountKey, $folderPath, $toState, $context);

        StructuredLog::emit('state_transition', array_merge($context, [
            'folder_path' => $folderPath,
            'from_state' => $fromState,
            'to_state' => $toState,
        ]));

        return true;
    }

    /**
     * Force-set state without transition checks. For tests and admin tooling.
     */
    public function forceSet(string $accountKey, string $folderPath, string $state, array $context = []): void
    {
        if (!self::isValid($state)) {
            return;
        }
        $this->persist($accountKey, $folderPath, $state, $context);
    }

    /**
     * Clear any state record for a folder (e.g. when it's removed from
     * the IMAP server permanently and we've finished processing the
     * tombstone).
     */
    public function clear(string $accountKey, string $folderPath): void
    {
        if (!$this->redis->isAvailable()) {
            return;
        }
        $this->redis->delete($this->key($accountKey, $folderPath));
    }

    private function persist(string $accountKey, string $folderPath, string $state, array $context): void
    {
        if (!$this->redis->isAvailable()) {
            return;
        }
        $payload = [
            'state' => $state,
            'last_attempt_at' => time(),
            'retry_after' => isset($context['retry_after']) ? (int) $context['retry_after'] : null,
            'reason' => isset($context['reason']) ? (string) $context['reason'] : null,
        ];
        $this->redis->set($this->key($accountKey, $folderPath), $payload, self::STATE_TTL);
    }

    private function key(string $accountKey, string $folderPath): string
    {
        return 'folder_state:' . $accountKey . ':' . CircuitBreaker::normalizePath($folderPath);
    }
}
