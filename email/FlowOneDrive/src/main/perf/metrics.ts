/**
 * Metrics — lightweight in-process metrics for the Perf HUD.
 *
 * Metrics M.2 of drive-perf-fix-v2.
 *
 * Why: histogram-grade observability without a heavyweight library. The
 * shape mirrors Prometheus / OTel concepts so we can swap implementations
 * later without touching call sites:
 *
 *   - histogram(name).observe(ms)  → p50/p95/p99 over a rolling window
 *   - counter(name).inc(by=1)      → monotonic count
 *   - gauge(name).set(value)       → instantaneous numeric value
 *   - rate(name).mark(by=1)        → events / minute (5 min sliding window)
 *
 * `snapshot()` returns a JSON-serializable map for the Perf HUD IPC.
 *
 * The histogram uses a fixed-size circular buffer of 1024 samples — when
 * the buffer fills, oldest samples are evicted. That gives us p99 of
 * roughly the last few minutes of activity in production cadence, with
 * O(n log n) on read (sort) which is acceptable since reads happen on
 * Perf HUD tick (every 1–2 s).
 */

const HISTOGRAM_CAPACITY = 1024

class Histogram {
  private samples: number[] = new Array(HISTOGRAM_CAPACITY)
  private idx = 0
  private len = 0

  observe(ms: number): void {
    this.samples[this.idx] = ms
    this.idx = (this.idx + 1) % HISTOGRAM_CAPACITY
    if (this.len < HISTOGRAM_CAPACITY) this.len += 1
  }

  percentiles(): { p50: number; p95: number; p99: number; max: number; mean: number; count: number } {
    if (this.len === 0) return { p50: 0, p95: 0, p99: 0, max: 0, mean: 0, count: 0 }
    const sorted = this.samples.slice(0, this.len).sort((a, b) => a - b)
    const pick = (p: number) => sorted[Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length))]
    const sum = sorted.reduce((s, v) => s + v, 0)
    return {
      p50: pick(50),
      p95: pick(95),
      p99: pick(99),
      max: sorted[sorted.length - 1],
      mean: Math.round((sum / sorted.length) * 100) / 100,
      count: sorted.length,
    }
  }
}

class Counter {
  private value = 0
  inc(by = 1): void { this.value += by }
  get(): number { return this.value }
  reset(): void { this.value = 0 }
}

class Gauge {
  private value = 0
  set(v: number): void { this.value = v }
  inc(by = 1): void { this.value += by }
  dec(by = 1): void { this.value -= by }
  get(): number { return this.value }
}

/**
 * Rate tracker — events per minute over a sliding 5-minute window.
 * Buckets are 10 s wide so the window has 30 buckets.
 */
class RateTracker {
  private static readonly BUCKET_MS = 10_000
  private static readonly WINDOW_BUCKETS = 30
  private buckets: number[] = new Array(RateTracker.WINDOW_BUCKETS).fill(0)
  private bucketTimestamps: number[] = new Array(RateTracker.WINDOW_BUCKETS).fill(0)

  mark(by = 1): void {
    const now = Date.now()
    const slot = Math.floor(now / RateTracker.BUCKET_MS) % RateTracker.WINDOW_BUCKETS
    if (this.bucketTimestamps[slot] < Math.floor(now / RateTracker.BUCKET_MS)) {
      this.buckets[slot] = 0
      this.bucketTimestamps[slot] = Math.floor(now / RateTracker.BUCKET_MS)
    }
    this.buckets[slot] += by
  }

  perMinute(): number {
    const now = Date.now()
    const cutoff = Math.floor((now - 60_000) / RateTracker.BUCKET_MS)
    let total = 0
    for (let i = 0; i < RateTracker.WINDOW_BUCKETS; i++) {
      if (this.bucketTimestamps[i] >= cutoff) total += this.buckets[i]
    }
    return total
  }

  per5Min(): number {
    const now = Date.now()
    const cutoff = Math.floor((now - 5 * 60_000) / RateTracker.BUCKET_MS)
    let total = 0
    for (let i = 0; i < RateTracker.WINDOW_BUCKETS; i++) {
      if (this.bucketTimestamps[i] >= cutoff) total += this.buckets[i]
    }
    return total
  }
}

class MetricsRegistry {
  private histograms = new Map<string, Histogram>()
  private counters = new Map<string, Counter>()
  private gauges = new Map<string, Gauge>()
  private rates = new Map<string, RateTracker>()

  histogram(name: string): Histogram {
    let h = this.histograms.get(name)
    if (!h) { h = new Histogram(); this.histograms.set(name, h) }
    return h
  }

  counter(name: string): Counter {
    let c = this.counters.get(name)
    if (!c) { c = new Counter(); this.counters.set(name, c) }
    return c
  }

  gauge(name: string): Gauge {
    let g = this.gauges.get(name)
    if (!g) { g = new Gauge(); this.gauges.set(name, g) }
    return g
  }

  rate(name: string): RateTracker {
    let r = this.rates.get(name)
    if (!r) { r = new RateTracker(); this.rates.set(name, r) }
    return r
  }

  /**
   * JSON-serializable snapshot for the Perf HUD IPC.
   */
  snapshot(): Record<string, unknown> {
    const out: Record<string, unknown> = {
      histograms: {},
      counters: {},
      gauges: {},
      rates: {},
    }
    for (const [k, v] of this.histograms) (out.histograms as any)[k] = v.percentiles()
    for (const [k, v] of this.counters) (out.counters as any)[k] = v.get()
    for (const [k, v] of this.gauges) (out.gauges as any)[k] = v.get()
    for (const [k, v] of this.rates)  (out.rates as any)[k]  = { perMin: v.perMinute(), per5Min: v.per5Min() }
    return out
  }
}

export const metrics = new MetricsRegistry()
