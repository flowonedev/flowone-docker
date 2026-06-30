import { EventEmitter } from 'events'

/**
 * SyncScheduler — single-source-of-truth for sync triggers.
 *
 * Solves the "uncontrolled concurrency" problem: chokidar bursts, periodic timers,
 * IPC handlers, manual sync, and reconnect logic could all kick off concurrent
 * sync cycles. This scheduler enforces:
 *
 *   1. At most ONE sync cycle in flight at any time (mutex).
 *   2. Burst coalescing: a 500ms debounce window collapses many requests into one.
 *   3. Coalescing while busy: if a request arrives while a cycle is in flight,
 *      exactly one follow-up cycle is queued (additional requests collapse into
 *      that single follow-up).
 *
 * The scheduler is engine-agnostic: callers wire it up with `setRunCycle()` and
 * trigger via `request(reason)`. Counters are emitted for observability.
 *
 * Counters surfaced via `getCounters()`:
 *   - cycle_requested:           total requests received
 *   - cycle_started:             cycles actually executed
 *   - cycle_skipped_due_to_inflight: requests folded into the in-flight cycle
 *   - cycle_queued:              follow-up cycles scheduled while busy
 *   - cycle_coalesced:           debounce-window collapses
 *   - cycle_completed_ok:        cycles ending in success
 *   - cycle_completed_err:       cycles ending in error
 */
export interface SyncSchedulerCounters {
  cycle_requested: number
  cycle_started: number
  cycle_skipped_due_to_inflight: number
  cycle_queued: number
  cycle_coalesced: number
  cycle_deferred_gap: number
  cycle_completed_ok: number
  cycle_completed_err: number
  last_reason: string | null
  last_started_at: number | null
  last_completed_at: number | null
  last_duration_ms: number | null
  in_flight: boolean
  has_queued: boolean
}

export type RunCycleFn = (reason: string) => Promise<void>

const DEFAULT_DEBOUNCE_MS = 500
// Wave D.4 — minimum quiet time between consecutive cycles. Without it, a
// cycle that outlasts the periodic timer always has a queued follow-up that
// starts the instant the previous one ends, so the engine never reports idle
// and the UI spinner never stops. User-driven requests (requestImmediate)
// bypass the gap.
const DEFAULT_MIN_GAP_MS = 8_000

export class SyncScheduler extends EventEmitter {
  private runCycle: RunCycleFn | null = null
  private inFlight: Promise<void> | null = null
  private queued = false
  private queuedReason: string | null = null
  // Wave D.4: a queued follow-up that originated from requestImmediate
  // (user action) keeps its gap-bypass privilege when it finally runs.
  private queuedBypassGap = false
  private debounceTimer: NodeJS.Timeout | null = null
  private pendingReason: string | null = null
  private debounceMs: number
  private minGapMs: number
  private gapTimer: NodeJS.Timeout | null = null
  private gapReason: string | null = null
  private paused = false

  private counters: SyncSchedulerCounters = {
    cycle_requested: 0,
    cycle_started: 0,
    cycle_skipped_due_to_inflight: 0,
    cycle_queued: 0,
    cycle_coalesced: 0,
    cycle_deferred_gap: 0,
    cycle_completed_ok: 0,
    cycle_completed_err: 0,
    last_reason: null,
    last_started_at: null,
    last_completed_at: null,
    last_duration_ms: null,
    in_flight: false,
    has_queued: false,
  }

  constructor(opts: { debounceMs?: number; minGapMs?: number } = {}) {
    super()
    this.debounceMs = opts.debounceMs ?? DEFAULT_DEBOUNCE_MS
    this.minGapMs = opts.minGapMs ?? DEFAULT_MIN_GAP_MS
  }

  setRunCycle(fn: RunCycleFn): void {
    this.runCycle = fn
  }

  pause(): void {
    this.paused = true
    // Wave D.4: preserve in-limbo requests (debounce window, gap deferral) as
    // a queued request instead of dropping them, so resume() picks them up.
    if (this.debounceTimer) {
      clearTimeout(this.debounceTimer)
      this.debounceTimer = null
      if (this.pendingReason && !this.queued) {
        this.queued = true
        this.queuedReason = this.pendingReason
        this.queuedBypassGap = false
      }
      this.pendingReason = null
    }
    if (this.gapTimer) {
      clearTimeout(this.gapTimer)
      this.gapTimer = null
      if (!this.queued) {
        this.queued = true
        this.queuedReason = this.gapReason
        this.queuedBypassGap = false
      }
      this.gapReason = null
    }
  }

  resume(): void {
    this.paused = false
    if (this.queued) {
      this.queued = false
      const reason = this.queuedReason || 'resumed'
      this.queuedReason = null
      this.queuedBypassGap = false
      this.request(reason)
    }
  }

  /**
   * Request a sync cycle. Coalesces with any pending request inside the debounce
   * window. If a cycle is already in flight, exactly one follow-up will be queued.
   */
  request(reason: string): void {
    this.counters.cycle_requested += 1
    if (this.paused) return

    if (this.debounceTimer) {
      this.counters.cycle_coalesced += 1
      clearTimeout(this.debounceTimer)
    }
    this.pendingReason = reason
    this.debounceTimer = setTimeout(() => {
      this.debounceTimer = null
      const r = this.pendingReason || reason
      this.pendingReason = null
      this.tryRun(r)
    }, this.debounceMs)
  }

  /**
   * Bypass debounce - run immediately if not in flight, otherwise queue.
   * Used for explicit user actions (e.g. "Sync now" button).
   */
  requestImmediate(reason: string): void {
    this.counters.cycle_requested += 1
    if (this.paused) return
    if (this.debounceTimer) {
      clearTimeout(this.debounceTimer)
      this.debounceTimer = null
      this.pendingReason = null
    }
    // Explicit user action — bypass the inter-cycle gap.
    this.tryRun(reason, { bypassGap: true })
  }

  /**
   * Wait for the currently in-flight cycle (if any) to complete.
   * Useful for tests and graceful shutdown.
   */
  async waitForIdle(): Promise<void> {
    while (this.inFlight) {
      await this.inFlight
    }
  }

  isInFlight(): boolean {
    return this.inFlight !== null
  }

  hasQueued(): boolean {
    return this.queued
  }

  getCounters(): SyncSchedulerCounters {
    return {
      ...this.counters,
      in_flight: this.inFlight !== null,
      has_queued: this.queued,
    }
  }

  resetCounters(): void {
    this.counters = {
      cycle_requested: 0,
      cycle_started: 0,
      cycle_skipped_due_to_inflight: 0,
      cycle_queued: 0,
      cycle_coalesced: 0,
      cycle_deferred_gap: 0,
      cycle_completed_ok: 0,
      cycle_completed_err: 0,
      last_reason: this.counters.last_reason,
      last_started_at: this.counters.last_started_at,
      last_completed_at: this.counters.last_completed_at,
      last_duration_ms: this.counters.last_duration_ms,
      in_flight: this.inFlight !== null,
      has_queued: this.queued,
    }
  }

  private tryRun(reason: string, opts: { bypassGap?: boolean } = {}): void {
    if (this.paused) return
    if (this.inFlight) {
      // Already running. Coalesce into a single follow-up.
      if (!this.queued) {
        this.counters.cycle_queued += 1
        this.queued = true
        this.queuedReason = reason
        this.queuedBypassGap = !!opts.bypassGap
        this.emit('queued', reason)
      } else {
        this.counters.cycle_skipped_due_to_inflight += 1
        // A user-driven request upgrades the pending follow-up to bypass.
        if (opts.bypassGap) this.queuedBypassGap = true
      }
      return
    }

    if (!this.runCycle) {
      // Not configured yet — drop silently.
      return
    }

    // Wave D.4: enforce a minimum quiet period between cycles so the engine
    // visibly settles to idle between back-to-back runs. Deferred, not dropped.
    if (!opts.bypassGap && this.minGapMs > 0 && this.counters.last_completed_at !== null) {
      const sinceLast = Date.now() - this.counters.last_completed_at
      if (sinceLast < this.minGapMs) {
        this.deferAfterGap(reason, this.minGapMs - sinceLast)
        return
      }
    }

    const startedAt = Date.now()
    this.counters.cycle_started += 1
    this.counters.last_reason = reason
    this.counters.last_started_at = startedAt
    this.emit('started', reason)

    const fn = this.runCycle
    this.inFlight = fn(reason)
      .then(() => {
        this.counters.cycle_completed_ok += 1
      })
      .catch(err => {
        this.counters.cycle_completed_err += 1
        this.emit('error', err, reason)
      })
      .finally(() => {
        const completedAt = Date.now()
        this.counters.last_completed_at = completedAt
        this.counters.last_duration_ms = completedAt - startedAt
        this.inFlight = null
        this.emit('completed', reason, completedAt - startedAt)
        if (this.queued && !this.paused) {
          const next = this.queuedReason || 'queued'
          const bypassGap = this.queuedBypassGap
          this.queued = false
          this.queuedReason = null
          this.queuedBypassGap = false
          // Wave D.4: follow-ups honor the inter-cycle gap (tryRun defers them),
          // so a long cycle no longer chains straight into the next one.
          // User-driven follow-ups (Sync Now while busy) keep their bypass.
          this.tryRun(next, { bypassGap })
        }
      })
  }

  /**
   * Wave D.4 — schedule a deferred run once the inter-cycle gap has elapsed.
   * Multiple deferrals coalesce into the single pending gap timer.
   */
  private deferAfterGap(reason: string, delayMs: number): void {
    this.counters.cycle_deferred_gap += 1
    if (this.gapTimer) return
    this.gapReason = reason
    this.gapTimer = setTimeout(() => {
      this.gapTimer = null
      const r = this.gapReason || reason
      this.gapReason = null
      this.tryRun(r)
    }, Math.max(50, delayMs))
  }
}

/**
 * Per-stage sub-mutex - prevents nested re-entry of the same async stage
 * inside a sync cycle (e.g. uploadLocalChanges should never overlap with itself).
 *
 * Unlike SyncScheduler this is a hard lock: requests during in-flight execution
 * are dropped (not queued).
 */
export class StageMutex {
  private locked = false
  private waiters: Array<() => void> = []

  /**
   * Run `fn` with the stage locked. Returns the result of `fn`.
   * If the stage is already locked when called, this RETURNS undefined immediately
   * (does not queue) — chosen to match the prior "if (this.isSyncing) return" pattern.
   */
  async run<T>(fn: () => Promise<T>): Promise<T | undefined> {
    if (this.locked) return undefined
    this.locked = true
    try {
      return await fn()
    } finally {
      this.locked = false
      const next = this.waiters.shift()
      if (next) next()
    }
  }

  isLocked(): boolean {
    return this.locked
  }
}
