import { EventEmitter } from 'events'

/**
 * UploadQueue — bounded-concurrency, priority-aware queue for outbound work.
 *
 * Wave B.5 of drive-perf-fix-v2.
 *
 * Why this exists: today, when a sync cycle decides to push 200 changed
 * files, it iterates `for (const f of pending) await uploadFile(f)`. While
 * that's serial-by-design, in burst conditions multiple sync cycles can
 * overlap (mitigated in A.1 but not eliminated for legacy code paths) and
 * the engine can fan out into uncoordinated async chains. There is no
 * priority and no backpressure into SyncScheduler.
 *
 * Behaviour:
 *   - Concurrency is bounded (default 3). The `concurrency` setting is
 *     readable / writable at runtime so Settings can expose it later.
 *   - Two priority lanes: lane 0 (small / interactive) is drained before
 *     lane 1 (bulk). This prevents a 1 GB upload from blocking 50 small
 *     ones queued behind it.
 *   - Per-job exponential backoff: failed jobs go back to the lane with a
 *     `runAfter` timestamp; the worker picks the next eligible job.
 *   - Backpressure: when queue depth exceeds `pressureThreshold`, callers
 *     can ask `shouldThrottle()` to skip e.g. the next sync cycle.
 *
 * The queue does NOT know how to upload. The owner provides a
 * `worker(job)` async function. This keeps the queue testable and lets the
 * same instance feed both file uploads and folder creations later.
 */

export type UploadLane = 0 | 1

export interface UploadJob<TPayload = any> {
  id: string
  lane: UploadLane
  payload: TPayload
  attempts: number
  maxAttempts: number
  enqueuedAt: number
  runAfter: number
}

export interface UploadQueueCounters {
  enqueued_total: number
  succeeded_total: number
  failed_total: number
  retried_total: number
  expired_total: number
  in_flight: number
  lane0_depth: number
  lane1_depth: number
  total_depth: number
  last_error: string | null
}

export interface UploadQueueOptions {
  concurrency?: number
  pressureThreshold?: number
  baseBackoffMs?: number
  maxBackoffMs?: number
  defaultMaxAttempts?: number
  /**
   * Wave D.2 — overall per-job deadline (queue wait + all attempts/backoffs).
   * When it expires, the awaited enqueue() promise REJECTS so callers
   * (sync cycles) can never hang on a stranded job. 0 disables.
   */
  defaultDeadlineMs?: number
}

export type UploadWorker<T> = (job: UploadJob<T>) => Promise<void>

interface JobSettler {
  resolve: () => void
  reject: (err: Error) => void
  deadlineTimer: NodeJS.Timeout | null
}

export class UploadQueue<TPayload = any> extends EventEmitter {
  private lane0: UploadJob<TPayload>[] = []
  private lane1: UploadJob<TPayload>[] = []
  private inFlight = 0
  private worker: UploadWorker<TPayload> | null = null
  private idSeq = 0
  private paused = false
  private destroyed = false
  private wakeupTimer: NodeJS.Timeout | null = null

  // Wave D.2 — per-job promise settlement. One Map entry per pending job
  // instead of two EventEmitter listeners per enqueue (which hit the
  // max-listener warning and O(n^2) dispatch on bulk reconciliation, and —
  // critically — never settled when the queue was paused or destroyed).
  private settlers = new Map<string, JobSettler>()

  private concurrency: number
  private pressureThreshold: number
  private baseBackoffMs: number
  private maxBackoffMs: number
  private defaultMaxAttempts: number
  private defaultDeadlineMs: number

  private counters: UploadQueueCounters = {
    enqueued_total: 0,
    succeeded_total: 0,
    failed_total: 0,
    retried_total: 0,
    expired_total: 0,
    in_flight: 0,
    lane0_depth: 0,
    lane1_depth: 0,
    total_depth: 0,
    last_error: null,
  }

  constructor(opts: UploadQueueOptions = {}) {
    super()
    this.concurrency = opts.concurrency ?? 3
    this.pressureThreshold = opts.pressureThreshold ?? 100
    this.baseBackoffMs = opts.baseBackoffMs ?? 1_000
    this.maxBackoffMs = opts.maxBackoffMs ?? 60_000
    this.defaultMaxAttempts = opts.defaultMaxAttempts ?? 5
    this.defaultDeadlineMs = opts.defaultDeadlineMs ?? 15 * 60_000
  }

  setWorker(fn: UploadWorker<TPayload>): void {
    this.worker = fn
  }

  setConcurrency(n: number): void {
    this.concurrency = Math.max(1, Math.floor(n))
    this.tickle()
  }

  pause(): void { this.paused = true }
  resume(): void { this.paused = false; this.tickle() }

  /**
   * Enqueue a job. The returned promise resolves when the job completes
   * (success or final failure after retries).
   *
   * Wave D.2: the promise is GUARANTEED to settle — on success, on final
   * failure, on deadline expiry, or on queue destroy. It can no longer hang
   * forever while the queue is paused, which used to strand the sync cycle
   * awaiting it (UI stuck on "Syncing...").
   */
  enqueue(
    payload: TPayload,
    opts: { lane?: UploadLane; maxAttempts?: number; deadlineMs?: number } = {}
  ): Promise<void> {
    if (this.destroyed) {
      return Promise.reject(new Error('UploadQueue is destroyed'))
    }

    const job: UploadJob<TPayload> = {
      id: `u${++this.idSeq}`,
      lane: opts.lane ?? 0,
      payload,
      attempts: 0,
      maxAttempts: opts.maxAttempts ?? this.defaultMaxAttempts,
      enqueuedAt: Date.now(),
      runAfter: 0,
    }

    return new Promise<void>((resolve, reject) => {
      const deadlineMs = opts.deadlineMs ?? this.defaultDeadlineMs
      const deadlineTimer = deadlineMs > 0
        ? setTimeout(() => this.expireJob(job.id, deadlineMs), deadlineMs)
        : null
      this.settlers.set(job.id, { resolve, reject, deadlineTimer })

      this.counters.enqueued_total += 1
      const lane = job.lane === 0 ? this.lane0 : this.lane1
      lane.push(job)
      this.updateDepths()
      this.tickle()
    })
  }

  /**
   * Settle a pending job promise exactly once. No-op if already settled
   * (e.g. job finished after its deadline expired).
   */
  private settle(id: string, err?: Error): void {
    const settler = this.settlers.get(id)
    if (!settler) return
    this.settlers.delete(id)
    if (settler.deadlineTimer) clearTimeout(settler.deadlineTimer)
    if (err) settler.reject(err)
    else settler.resolve()
  }

  /**
   * Wave D.2 — overall deadline expired. Drop the job from its lane (if it
   * is still queued / waiting for backoff) and reject the awaited promise.
   * An in-flight attempt is left to finish; its late result is ignored.
   */
  private expireJob(id: string, deadlineMs: number): void {
    this.lane0 = this.lane0.filter(j => j.id !== id)
    this.lane1 = this.lane1.filter(j => j.id !== id)
    this.updateDepths()
    this.counters.expired_total += 1
    const err = new Error(`UploadQueue job ${id} exceeded deadline of ${deadlineMs}ms`)
    this.counters.last_error = err.message
    this.emit('job-expired', id, err)
    this.settle(id, err)
  }

  /**
   * Backpressure signal for SyncScheduler. When true, the scheduler should
   * skip its next cycle (or convert it into a no-op) until the queue drains.
   */
  shouldThrottle(): boolean {
    return this.depth() >= this.pressureThreshold
  }

  depth(): number {
    return this.lane0.length + this.lane1.length
  }

  isInFlight(): boolean {
    return this.inFlight > 0
  }

  getCounters(): UploadQueueCounters {
    return {
      ...this.counters,
      in_flight: this.inFlight,
      lane0_depth: this.lane0.length,
      lane1_depth: this.lane1.length,
      total_depth: this.depth(),
    }
  }

  /**
   * Wait for the queue to fully drain. Used by tests and graceful shutdown.
   */
  async waitForIdle(timeoutMs?: number): Promise<void> {
    if (this.depth() === 0 && this.inFlight === 0) return
    return new Promise<void>((resolve, reject) => {
      const startedAt = Date.now()
      const checker = setInterval(() => {
        if (this.depth() === 0 && this.inFlight === 0) {
          clearInterval(checker)
          resolve()
        } else if (timeoutMs && Date.now() - startedAt > timeoutMs) {
          clearInterval(checker)
          reject(new Error(`UploadQueue waitForIdle timeout after ${timeoutMs}ms`))
        }
      }, 100)
    })
  }

  destroy(): void {
    this.destroyed = true
    if (this.wakeupTimer) {
      clearTimeout(this.wakeupTimer)
      this.wakeupTimer = null
    }
    this.lane0 = []
    this.lane1 = []
    // Wave D.2: never strand awaiting callers — reject everything pending.
    const pendingIds = Array.from(this.settlers.keys())
    for (const id of pendingIds) {
      this.settle(id, new Error('UploadQueue destroyed'))
    }
    this.removeAllListeners()
  }

  /**
   * Pull the next runnable job (respecting `runAfter` and lane priority).
   * Returns null if no job is currently runnable.
   */
  private nextRunnableJob(now: number): UploadJob<TPayload> | null {
    const fromLane = (lane: UploadJob<TPayload>[]): UploadJob<TPayload> | null => {
      for (let i = 0; i < lane.length; i++) {
        if (lane[i].runAfter <= now) {
          return lane.splice(i, 1)[0]
        }
      }
      return null
    }
    const j0 = fromLane(this.lane0)
    if (j0) return j0
    return fromLane(this.lane1)
  }

  /**
   * Try to start as many jobs as `concurrency` allows.
   */
  private tickle(): void {
    if (this.destroyed) return
    if (this.paused) return
    if (!this.worker) return

    while (this.inFlight < this.concurrency) {
      const now = Date.now()
      const job = this.nextRunnableJob(now)
      if (!job) {
        // Maybe a job is waiting for backoff — schedule a wakeup for the
        // earliest runAfter so we don't spin.
        this.scheduleWakeupForBackoff(now)
        break
      }
      this.runJob(job)
    }
    this.updateDepths()
  }

  private scheduleWakeupForBackoff(now: number): void {
    if (this.wakeupTimer) return
    const allWaiting = [...this.lane0, ...this.lane1]
    if (allWaiting.length === 0) return
    const nextRun = allWaiting.reduce((m, j) => Math.min(m, j.runAfter), Infinity)
    if (!isFinite(nextRun)) return
    const delay = Math.max(50, nextRun - now)
    this.wakeupTimer = setTimeout(() => {
      this.wakeupTimer = null
      this.tickle()
    }, delay)
  }

  private async runJob(job: UploadJob<TPayload>): Promise<void> {
    if (!this.worker) return
    this.inFlight += 1
    job.attempts += 1
    this.emit('job-start', job.id, job)

    try {
      await this.worker(job)
      this.counters.succeeded_total += 1
      this.emit('job-success', job.id, job)
      this.settle(job.id)
    } catch (err: any) {
      this.counters.last_error = err?.message || String(err)
      // Wave D.2: a job whose promise already settled (deadline expiry,
      // destroy) is abandoned — do not keep retrying work nobody awaits.
      const abandoned = !this.settlers.has(job.id)
      if (!abandoned && job.attempts < job.maxAttempts) {
        this.counters.retried_total += 1
        const backoff = Math.min(
          this.maxBackoffMs,
          this.baseBackoffMs * Math.pow(2, job.attempts - 1)
        )
        job.runAfter = Date.now() + backoff
        const lane = job.lane === 0 ? this.lane0 : this.lane1
        lane.push(job)
        this.emit('job-retry', job.id, job, err, backoff)
      } else if (!abandoned) {
        this.counters.failed_total += 1
        this.emit('job-failed', job.id, err, job)
        this.settle(job.id, err instanceof Error ? err : new Error(String(err)))
      }
    } finally {
      this.inFlight -= 1
      this.updateDepths()
      // Loop: continue draining the queue.
      setImmediate(() => this.tickle())
    }
  }

  private updateDepths(): void {
    this.counters.in_flight = this.inFlight
    this.counters.lane0_depth = this.lane0.length
    this.counters.lane1_depth = this.lane1.length
    this.counters.total_depth = this.depth()
  }
}
