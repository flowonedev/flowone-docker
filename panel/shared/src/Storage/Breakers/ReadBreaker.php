<?php

declare(strict_types=1);

namespace FlowOne\Storage\Breakers;

use FlowOne\Storage\DurableJson;
use FlowOne\Storage\HmacSigner;
use FlowOne\Storage\MonotonicClock;

/**
 * NAS read circuit breaker (Phase 2).
 *
 * Tracks recent read-probe samples and decides whether the system is
 * fast enough to keep serving reads from NAS. Two trip conditions:
 *
 *   1. p95(latency) > p95_threshold_sec
 *   2. error_rate    > error_rate_threshold
 *
 * Either trip flips the breaker OPEN for `hard_cap_sec` max (Phase 2
 * just records OPEN; consumers decide how to react — e.g. shed to VPS
 * hot cache. Phase 5+ wires that in for the drive read path).
 *
 * The breaker re-evaluates every probe cycle; if neither trip
 * condition is true and the breaker has been open for at least
 * `evaluation_window_sec`, it closes again.
 *
 * State persists to /var/lib/flowone/read-breaker.json (DurableJson +
 * HMAC signature) so a daemon restart doesn't lose breaker memory.
 */
final class ReadBreaker
{
    private const STATE_FILE = 'read-breaker.json';

    private float $p95Threshold;
    private float $errorRateThreshold;
    private int   $evalWindowSec;
    private int   $hardCapSec;

    /** @var list<array{ts_mono_ns:int, latency_sec:float, ok:bool}> */
    private array $samples = [];

    private bool   $open = false;
    private ?int   $openedAtMonoNs = null;
    private ?string $openReason   = null;

    private DurableJson $persistence;
    private HmacSigner  $signer;

    public function __construct(
        DurableJson $persistence,
        HmacSigner  $signer,
        float $p95Threshold,
        float $errorRateThreshold,
        int   $evalWindowSec,
        int   $hardCapSec
    ) {
        $this->persistence        = $persistence;
        $this->signer             = $signer;
        $this->p95Threshold       = $p95Threshold;
        $this->errorRateThreshold = $errorRateThreshold;
        $this->evalWindowSec      = max(1, $evalWindowSec);
        $this->hardCapSec         = max($evalWindowSec, $hardCapSec);

        $this->loadPersistedState();
    }

    public static function fromConfig(array $config): self
    {
        $signer = HmacSigner::fromKeyFile(
            (string) $config['state']['hmac_key_path'],
            (int) $config['state']['hmac_key_mode_max']
        );
        $persistence = new DurableJson(
            (string) $config['state']['dir'],
            self::STATE_FILE,
            (string) $config['state']['tmp_suffix'],
            (string) $config['state']['bak_suffix'],
        );
        return new self(
            $persistence,
            $signer,
            (float) $config['read_breaker']['p95_threshold_sec'],
            (float) $config['read_breaker']['error_rate_threshold'],
            (int)   $config['read_breaker']['evaluation_window_sec'],
            (int)   $config['read_breaker']['hard_cap_sec'],
        );
    }

    /**
     * Record a probe outcome. Latency in seconds (positive), ok=true on
     * success, ok=false on any failure.
     */
    public function recordProbe(float $latencySec, bool $ok, ?int $nowMonoNs = null): void
    {
        $this->samples[] = [
            'ts_mono_ns'  => $nowMonoNs ?? MonotonicClock::nowNs(),
            'latency_sec' => max(0.0, $latencySec),
            'ok'          => $ok,
        ];
        $this->trimSamples($nowMonoNs);
    }

    /**
     * Re-evaluate state. Call after every recordProbe(). Returns true
     * if the breaker is currently OPEN (consumers should consider
     * falling back to local cache).
     */
    public function evaluate(?int $nowMonoNs = null): bool
    {
        $now = $nowMonoNs ?? MonotonicClock::nowNs();
        $this->trimSamples($now);

        $p95   = $this->p95LatencySec();
        $errRate = $this->errorRate();

        $shouldOpen = ($p95 > $this->p95Threshold) || ($errRate > $this->errorRateThreshold);

        if ($shouldOpen && !$this->open) {
            $this->open = true;
            $this->openedAtMonoNs = $now;
            $this->openReason = ($p95 > $this->p95Threshold)
                ? sprintf('p95 latency %.2fs > %.2fs', $p95, $this->p95Threshold)
                : sprintf('error rate %.2f%% > %.2f%%', $errRate * 100, $this->errorRateThreshold * 100);
            $this->persist();
        } elseif ($this->open) {
            $openForSec = $this->openedAtMonoNs === null
                ? 0
                : ($now - $this->openedAtMonoNs) / 1_000_000_000;
            $minOpen = $this->evalWindowSec;
            $maxOpen = $this->hardCapSec;
            if ($openForSec >= $maxOpen) {
                $this->close('hard_cap_reached');
            } elseif (!$shouldOpen && $openForSec >= $minOpen) {
                $this->close('thresholds_recovered');
            }
        }

        return $this->open;
    }

    public function isOpen(): bool { return $this->open; }
    public function openReason(): ?string { return $this->openReason; }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(?int $nowMonoNs = null): array
    {
        $now = $nowMonoNs ?? MonotonicClock::nowNs();
        return [
            'open'              => $this->open,
            'reason'            => $this->openReason,
            'opened_for_sec'    => $this->openedAtMonoNs === null
                ? null
                : round(($now - $this->openedAtMonoNs) / 1_000_000_000, 1),
            'sample_count'      => count($this->samples),
            'p95_latency_sec'   => round($this->p95LatencySec(), 4),
            'error_rate'        => round($this->errorRate(), 4),
            'p95_threshold_sec' => $this->p95Threshold,
            'error_rate_threshold' => $this->errorRateThreshold,
        ];
    }

    private function close(string $reason): void
    {
        $this->open = false;
        $this->openedAtMonoNs = null;
        $this->openReason = $reason;
        $this->persist();
    }

    private function p95LatencySec(): float
    {
        if (count($this->samples) === 0) {
            return 0.0;
        }
        $latencies = array_map(fn($s) => $s['latency_sec'], $this->samples);
        sort($latencies);
        $idx = (int) ceil(count($latencies) * 0.95) - 1;
        $idx = max(0, min(count($latencies) - 1, $idx));
        return (float) $latencies[$idx];
    }

    private function errorRate(): float
    {
        $n = count($this->samples);
        if ($n === 0) {
            return 0.0;
        }
        $errors = 0;
        foreach ($this->samples as $s) {
            if (!$s['ok']) {
                $errors++;
            }
        }
        return $errors / $n;
    }

    private function trimSamples(?int $nowMonoNs): void
    {
        $now = $nowMonoNs ?? MonotonicClock::nowNs();
        $windowNs = $this->evalWindowSec * 1_000_000_000;
        $cutoff = $now - $windowNs;
        $this->samples = array_values(array_filter(
            $this->samples,
            fn($s) => $s['ts_mono_ns'] >= $cutoff
        ));
    }

    private function loadPersistedState(): void
    {
        $raw = $this->persistence->readAny();
        if ($raw === null) {
            return;
        }
        try {
            $payload = $this->signer->verifyJson($raw);
            if ($payload === null) {
                // Signature mismatch — drop the persisted state silently.
                return;
            }
            $this->open = (bool) ($payload['open'] ?? false);
            $this->openReason = isset($payload['reason']) ? (string) $payload['reason'] : null;
            // openedAt is wall-time persisted; on load we treat "now" as
            // the open instant so the hard-cap window restarts. This is
            // conservative — at worst the breaker stays open one extra
            // window after a daemon restart.
            $this->openedAtMonoNs = $this->open ? MonotonicClock::nowNs() : null;
        } catch (\Throwable) {
            // Best-effort load only.
        }
    }

    private function persist(): void
    {
        try {
            $payload = [
                'open'              => $this->open,
                'reason'            => $this->openReason,
                'opened_at_unix'    => $this->open ? time() : null,
                'persisted_at_unix' => time(),
            ];
            $json = $this->signer->signToJson($payload);
            $this->persistence->write($json);
        } catch (\Throwable) {
            // Persistence is best-effort; in-memory state remains authoritative.
        }
    }
}
