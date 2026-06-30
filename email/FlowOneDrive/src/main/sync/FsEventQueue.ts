import { EventEmitter } from 'events'

/**
 * FsEventQueue — chokidar event backpressure.
 *
 * Why this exists: chokidar fires per-file events. A `git checkout`, an unzip,
 * a NAS reconnect, or a rename storm can flood the main process with thousands
 * of events in a single tick. Today every event triggers a synchronous handler
 * in the SyncEngine, which means a 5000-file rename can stall the event loop
 * for many seconds.
 *
 * Behaviour:
 *   - Dedupe by absolute path: the LATEST event per path wins.
 *   - Quiet-period flush: 250 ms after the last event, drain whatever is queued.
 *   - Hard flush: never wait more than 5 s between drains, even if events keep
 *     arriving.
 *   - Burst mode: if more than `burstThreshold` events arrive in
 *     `burstWindowMs`, raise the debounce to `burstDebounceMs` (5 s) and emit
 *     `burst-start` (renderer can show a banner). Burst mode auto-clears after
 *     `burstClearMs` of quiet.
 *
 * The queue does NOT itself process events. It calls a consumer callback with a
 * batch. The consumer is responsible for handling each event. This separation
 * keeps the queue testable and lets the same instance feed both file change
 * processing AND folder change processing.
 *
 * Wave A.2 of drive-perf-fix-v2.
 */

export type FsEventType = 'add' | 'change' | 'unlink' | 'addDir' | 'unlinkDir'

export interface FsEvent {
  type: FsEventType
  path: string
  ts: number
}

export interface FsEventQueueCounters {
  events_in: number
  events_coalesced: number
  events_emitted: number
  bursts_detected: number
  hard_flushes: number
  consumer_errors: number
  in_burst: boolean
  queue_depth: number
  last_event_at: number | null
  last_drain_at: number | null
  last_drain_size: number
}

export interface FsEventQueueOptions {
  debounceMs?: number
  hardFlushMs?: number
  burstThreshold?: number
  burstWindowMs?: number
  burstDebounceMs?: number
  burstClearMs?: number
}

export type FsEventConsumer = (batch: FsEvent[]) => Promise<void> | void

export class FsEventQueue extends EventEmitter {
  private buffer = new Map<string, FsEvent>()
  private debounceTimer: NodeJS.Timeout | null = null
  private hardFlushTimer: NodeJS.Timeout | null = null
  private burstClearTimer: NodeJS.Timeout | null = null
  private firstUnflushedAt = 0
  private inBurst = false
  private burstWindowStart = 0
  private burstWindowCount = 0
  private consumer: FsEventConsumer | null = null
  private draining = false

  private debounceMs: number
  private hardFlushMs: number
  private burstThreshold: number
  private burstWindowMs: number
  private burstDebounceMs: number
  private burstClearMs: number

  private counters: FsEventQueueCounters = {
    events_in: 0,
    events_coalesced: 0,
    events_emitted: 0,
    bursts_detected: 0,
    hard_flushes: 0,
    consumer_errors: 0,
    in_burst: false,
    queue_depth: 0,
    last_event_at: null,
    last_drain_at: null,
    last_drain_size: 0,
  }

  constructor(opts: FsEventQueueOptions = {}) {
    super()
    this.debounceMs = opts.debounceMs ?? 250
    this.hardFlushMs = opts.hardFlushMs ?? 5_000
    this.burstThreshold = opts.burstThreshold ?? 100
    this.burstWindowMs = opts.burstWindowMs ?? 1_000
    this.burstDebounceMs = opts.burstDebounceMs ?? 5_000
    this.burstClearMs = opts.burstClearMs ?? 5_000
  }

  setConsumer(fn: FsEventConsumer): void {
    this.consumer = fn
  }

  push(type: FsEventType, p: string): void {
    const now = Date.now()
    this.counters.events_in += 1
    this.counters.last_event_at = now

    if (this.buffer.has(p)) {
      this.counters.events_coalesced += 1
    }

    if (this.buffer.size === 0) {
      this.firstUnflushedAt = now
    }

    this.buffer.set(p, { type, path: p, ts: now })
    this.counters.queue_depth = this.buffer.size

    this.maybeBurst(now)
    this.scheduleFlush(now)
  }

  private maybeBurst(now: number): void {
    if (this.burstWindowStart === 0 || now - this.burstWindowStart > this.burstWindowMs) {
      this.burstWindowStart = now
      this.burstWindowCount = 1
      return
    }
    this.burstWindowCount += 1
    if (!this.inBurst && this.burstWindowCount > this.burstThreshold) {
      this.inBurst = true
      this.counters.bursts_detected += 1
      this.counters.in_burst = true
      this.emit('burst-start', this.burstWindowCount)
    }
    if (this.inBurst) {
      if (this.burstClearTimer) clearTimeout(this.burstClearTimer)
      this.burstClearTimer = setTimeout(() => {
        this.inBurst = false
        this.counters.in_burst = false
        this.emit('burst-end')
      }, this.burstClearMs)
    }
  }

  private scheduleFlush(now: number): void {
    if (this.debounceTimer) clearTimeout(this.debounceTimer)
    const debounce = this.inBurst ? this.burstDebounceMs : this.debounceMs
    this.debounceTimer = setTimeout(() => this.flush('debounce'), debounce)

    if (!this.hardFlushTimer) {
      const elapsed = now - this.firstUnflushedAt
      const remaining = Math.max(0, this.hardFlushMs - elapsed)
      this.hardFlushTimer = setTimeout(() => this.flush('hard'), remaining)
    }
  }

  /**
   * Force-drain the queue. Useful for shutdown / tests.
   */
  async flushNow(): Promise<void> {
    await this.flush('manual')
  }

  private async flush(cause: 'debounce' | 'hard' | 'manual'): Promise<void> {
    if (this.debounceTimer) {
      clearTimeout(this.debounceTimer)
      this.debounceTimer = null
    }
    if (this.hardFlushTimer) {
      clearTimeout(this.hardFlushTimer)
      this.hardFlushTimer = null
    }
    if (this.draining) {
      // A consumer call is already running; let it finish, the trailing scheduleFlush
      // (re-armed by any new push) will pick up new events.
      return
    }
    if (this.buffer.size === 0) return
    if (cause === 'hard') this.counters.hard_flushes += 1

    const batch = Array.from(this.buffer.values()).sort((a, b) => a.ts - b.ts)
    this.buffer.clear()
    this.firstUnflushedAt = 0
    this.counters.queue_depth = 0
    this.counters.events_emitted += batch.length
    this.counters.last_drain_at = Date.now()
    this.counters.last_drain_size = batch.length

    if (!this.consumer) {
      this.emit('drain', batch)
      return
    }

    this.draining = true
    try {
      await this.consumer(batch)
      this.emit('drain', batch)
    } catch (err) {
      this.counters.consumer_errors += 1
      this.emit('error', err)
    } finally {
      this.draining = false
      // If new events arrived during consumer execution, scheduleFlush will fire again.
      if (this.buffer.size > 0) this.scheduleFlush(Date.now())
    }
  }

  isInBurst(): boolean {
    return this.inBurst
  }

  size(): number {
    return this.buffer.size
  }

  getCounters(): FsEventQueueCounters {
    return { ...this.counters, queue_depth: this.buffer.size, in_burst: this.inBurst }
  }

  resetCounters(): void {
    this.counters = {
      events_in: 0,
      events_coalesced: 0,
      events_emitted: 0,
      bursts_detected: 0,
      hard_flushes: 0,
      consumer_errors: 0,
      in_burst: this.inBurst,
      queue_depth: this.buffer.size,
      last_event_at: this.counters.last_event_at,
      last_drain_at: this.counters.last_drain_at,
      last_drain_size: this.counters.last_drain_size,
    }
  }

  destroy(): void {
    if (this.debounceTimer) clearTimeout(this.debounceTimer)
    if (this.hardFlushTimer) clearTimeout(this.hardFlushTimer)
    if (this.burstClearTimer) clearTimeout(this.burstClearTimer)
    this.debounceTimer = null
    this.hardFlushTimer = null
    this.burstClearTimer = null
    this.buffer.clear()
    this.removeAllListeners()
  }
}
