import { monitorEventLoopDelay, IntervalHistogram } from 'perf_hooks'

/**
 * EventLoopMonitor — track main-thread event-loop responsiveness.
 *
 * Metrics M.1 of drive-perf-fix-v2.
 *
 * Why: every freeze the user sees is preceded by event-loop lag. This
 * monitor uses Node's built-in `monitorEventLoopDelay` (HDR-style
 * histogram, no JS-side overhead beyond the timer) to record p50/p95/p99
 * and alert when p99 exceeds the freeze threshold.
 *
 * The monitor is started once from `index.ts` and never destroyed;
 * `getStats()` returns the current rolling window for the Perf HUD and
 * `printAlerts()` is invoked on a timer to emit a console line + log entry
 * whenever p99 climbs above 100 ms.
 */

export interface EventLoopStats {
  /** Mean nanoseconds the event loop was blocked between samples. */
  meanMs: number
  /** Max blocked time observed. */
  maxMs: number
  p50Ms: number
  p95Ms: number
  p99Ms: number
  /** Number of samples in the histogram since reset. */
  samples: number
}

class EventLoopMonitor {
  private hist: IntervalHistogram | null = null
  private alertTimer: NodeJS.Timeout | null = null
  private alertThresholdMs: number = 100
  private alertCallback: ((stats: EventLoopStats) => void) | null = null

  start(): void {
    if (this.hist) return
    this.hist = monitorEventLoopDelay({ resolution: 20 })
    this.hist.enable()
  }

  stop(): void {
    if (this.alertTimer) {
      clearInterval(this.alertTimer)
      this.alertTimer = null
    }
    if (this.hist) {
      this.hist.disable()
      this.hist = null
    }
  }

  /**
   * Reset the rolling histogram. Useful after a known long-running task
   * so future stats reflect steady-state.
   */
  reset(): void {
    if (this.hist) this.hist.reset()
  }

  getStats(): EventLoopStats {
    if (!this.hist) {
      return { meanMs: 0, maxMs: 0, p50Ms: 0, p95Ms: 0, p99Ms: 0, samples: 0 }
    }
    const toMs = (ns: number) => Math.round(ns / 10_000) / 100 // 2 decimals
    return {
      meanMs: toMs(this.hist.mean),
      maxMs: toMs(this.hist.max),
      p50Ms: toMs(this.hist.percentile(50)),
      p95Ms: toMs(this.hist.percentile(95)),
      p99Ms: toMs(this.hist.percentile(99)),
      samples: this.hist.percentiles?.size ?? 0,
    }
  }

  /**
   * Begin periodic alerting. The default 10 s cadence matches the plan's
   * "log p99 every 10 s" requirement.
   */
  startAlerting(opts: { cadenceMs?: number; thresholdMs?: number; onAlert?: (stats: EventLoopStats) => void } = {}): void {
    if (this.alertTimer) return
    this.alertThresholdMs = opts.thresholdMs ?? 100
    this.alertCallback = opts.onAlert ?? null
    const cadence = opts.cadenceMs ?? 10_000
    this.alertTimer = setInterval(() => this.tickAlert(), cadence)
  }

  private tickAlert(): void {
    const stats = this.getStats()
    if (stats.p99Ms >= this.alertThresholdMs) {
      const line =
        `[EventLoopMonitor] p99=${stats.p99Ms}ms p95=${stats.p95Ms}ms ` +
        `p50=${stats.p50Ms}ms max=${stats.maxMs}ms — exceeds ${this.alertThresholdMs}ms threshold`
      console.warn(line)
      try {
        const { logger } = require('../log/Logger')
        logger.tagged('EventLoop').warn(line)
      } catch { /* logger may not be available */ }
      if (this.alertCallback) this.alertCallback(stats)
    }
  }
}

export const eventLoopMonitor = new EventLoopMonitor()
