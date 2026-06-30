/**
 * DbQueue — single-chokepoint serialization for all SQLite access.
 *
 * Wave B.1 of drive-perf-fix-v2.
 *
 * Why this exists, even though `better-sqlite3` is already synchronous:
 *
 *   - It gives us a single instrumentation point for per-call latency,
 *     so the perf HUD can surface DB write latency p95 (M.2).
 *   - It prevents stacked long transactions: if a 2 s bulk transaction is
 *     running, every other DB call lines up behind it instead of competing
 *     for the same JS stack frame slot.
 *   - It lets the caller `await` DB work even though the underlying driver
 *     is sync, which means async code paths don't accidentally hold the
 *     event loop while waiting on the DB.
 *   - It provides a swap-in seam for F.2 (worker-thread DB): callers stay
 *     unchanged when we move SQLite into a worker.
 *
 * Behaviour:
 *   - `enqueue(fn)` runs `fn` strictly after every previously-enqueued task.
 *   - Errors are caught locally so one failing task doesn't break the chain.
 *   - The original error is re-thrown to the awaiter via the per-task promise.
 *   - `wrap` builds a queued version of an existing function, so repository
 *     methods can keep their natural sync signature inside but expose async.
 */

export interface DbQueueMetrics {
  enqueued: number
  completed: number
  errored: number
  totalTimeMs: number
  maxTimeMs: number
  lastLabel: string | null
}

export class DbQueue {
  private chain: Promise<unknown> = Promise.resolve()
  private metrics: DbQueueMetrics = {
    enqueued: 0,
    completed: 0,
    errored: 0,
    totalTimeMs: 0,
    maxTimeMs: 0,
    lastLabel: null,
  }
  private timings: number[] = []
  private readonly maxTimingSamples = 256

  /**
   * Enqueue a synchronous task and await its result.
   * The task runs strictly after every previously-enqueued task has finished.
   */
  enqueue<T>(label: string, fn: () => T): Promise<T> {
    this.metrics.enqueued += 1
    this.metrics.lastLabel = label

    const next: Promise<T> = this.chain.then(() => {
      const start = Date.now()
      try {
        const result = fn()
        const elapsed = Date.now() - start
        this.recordTiming(elapsed)
        this.metrics.completed += 1
        return result
      } catch (err) {
        this.metrics.errored += 1
        throw err
      }
    })

    // Keep the chain alive even after a rejection.
    this.chain = next.catch(() => {})
    return next
  }

  /**
   * Enqueue an already-async task. Useful when the work itself yields to other
   * I/O (network, fs.promises) but still needs serialization against DB ops.
   */
  enqueueAsync<T>(label: string, fn: () => Promise<T>): Promise<T> {
    this.metrics.enqueued += 1
    this.metrics.lastLabel = label

    const next: Promise<T> = this.chain.then(async () => {
      const start = Date.now()
      try {
        const result = await fn()
        const elapsed = Date.now() - start
        this.recordTiming(elapsed)
        this.metrics.completed += 1
        return result
      } catch (err) {
        this.metrics.errored += 1
        throw err
      }
    })

    this.chain = next.catch(() => {})
    return next
  }

  /**
   * Build a queued wrapper around a synchronous function so callers can
   * `await wrapped(...)`. Keeps the original signature except for the return
   * type, which becomes a promise.
   */
  wrap<A extends any[], R>(label: string, fn: (...args: A) => R): (...args: A) => Promise<R> {
    return (...args: A) => this.enqueue(label, () => fn(...args))
  }

  getMetrics(): DbQueueMetrics & { p95Ms: number; sampleCount: number } {
    const sorted = [...this.timings].sort((a, b) => a - b)
    const p95Index = Math.max(0, Math.ceil(sorted.length * 0.95) - 1)
    return {
      ...this.metrics,
      p95Ms: sorted.length ? sorted[p95Index] : 0,
      sampleCount: sorted.length,
    }
  }

  resetMetrics(): void {
    this.metrics = {
      enqueued: 0,
      completed: 0,
      errored: 0,
      totalTimeMs: 0,
      maxTimeMs: 0,
      lastLabel: this.metrics.lastLabel,
    }
    this.timings = []
  }

  /**
   * Wait until the queue is fully drained. Useful for graceful shutdown so we
   * don't tear down the DB while a write is mid-flight.
   */
  async drain(): Promise<void> {
    // Enqueue a no-op and await the chain; this naturally serialises behind
    // every prior task.
    await this.enqueue('drain', () => undefined)
  }

  private recordTiming(elapsed: number): void {
    this.metrics.totalTimeMs += elapsed
    if (elapsed > this.metrics.maxTimeMs) this.metrics.maxTimeMs = elapsed
    this.timings.push(elapsed)
    if (this.timings.length > this.maxTimingSamples) {
      this.timings.shift()
    }
  }
}
