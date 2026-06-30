/**
 * IntervalRegistry — central record of every periodic timer in the main
 * process.
 *
 * Wave C.3 of drive-perf-fix-v2.
 *
 * Why: today there are 13+ scattered `setInterval` calls (sync timer,
 * collaborator polling, time tracker sync, lock file watcher poll,
 * notifications batch flush, NAS access mode check, etc.). Many do
 * redundant work in tight cadence and there is no visibility into how
 * often each fires or how long each tick takes.
 *
 * The registry adds three behaviors without forcing a rewrite of every
 * caller:
 *
 *   1. **Naming and metrics**: every interval is registered with a
 *      label, so the Perf HUD (Wave C.5) can show "interval.X.tick" and
 *      "interval.X.tick_duration_ms". Console snapshot on startup.
 *   2. **Skip-fire on overlap**: the previous tick is awaited before the
 *      next one runs. If the user's machine pauses (laptop sleep) and
 *      catch-up timer fires fire, we run *one* and drop the rest.
 *   3. **Pause / resume**: a single call lets shutdown drain every timer
 *      cleanly without each module having to track its own handle.
 *
 * Usage:
 *   const stop = intervalRegistry.set('sync.periodic', 60_000, async () => {
 *     await syncEngine.requestSync('periodic')
 *   })
 *   // later
 *   stop()
 */

export type IntervalTickFn = () => void | Promise<void>

export interface IntervalSnapshot {
  name: string
  intervalMs: number
  ticks: number
  skipped: number
  errors: number
  lastTickMs: number | null
  lastDurationMs: number | null
  paused: boolean
}

interface RegisteredInterval {
  name: string
  intervalMs: number
  fn: IntervalTickFn
  handle: NodeJS.Timeout | null
  inFlight: boolean
  ticks: number
  skipped: number
  errors: number
  lastTickMs: number | null
  lastDurationMs: number | null
  paused: boolean
}

class IntervalRegistry {
  private intervals = new Map<string, RegisteredInterval>()

  /**
   * Register and start a named interval. Returns a stop function that
   * clears the timer and removes the entry from the registry.
   *
   * If a name is reused, the existing interval is stopped and replaced.
   */
  set(name: string, intervalMs: number, fn: IntervalTickFn): () => void {
    if (this.intervals.has(name)) {
      this.clear(name)
    }

    const entry: RegisteredInterval = {
      name,
      intervalMs,
      fn,
      handle: null,
      inFlight: false,
      ticks: 0,
      skipped: 0,
      errors: 0,
      lastTickMs: null,
      lastDurationMs: null,
      paused: false,
    }

    const tick = async () => {
      if (entry.paused) return
      if (entry.inFlight) {
        entry.skipped += 1
        return
      }
      entry.inFlight = true
      const start = Date.now()
      entry.lastTickMs = start
      try {
        await entry.fn()
      } catch (err) {
        entry.errors += 1
        console.error(`[IntervalRegistry] tick error in "${name}":`, err)
      } finally {
        entry.inFlight = false
        entry.ticks += 1
        entry.lastDurationMs = Date.now() - start
      }
    }

    entry.handle = setInterval(tick, intervalMs)
    this.intervals.set(name, entry)
    return () => this.clear(name)
  }

  /**
   * Pause a single interval (do not fire) without clearing it. Useful
   * for "no work to do" gating — the timer keeps existing in metrics.
   */
  pause(name: string): void {
    const entry = this.intervals.get(name)
    if (entry) entry.paused = true
  }

  resume(name: string): void {
    const entry = this.intervals.get(name)
    if (entry) entry.paused = false
  }

  /**
   * Stop and remove an interval.
   */
  clear(name: string): void {
    const entry = this.intervals.get(name)
    if (!entry) return
    if (entry.handle) clearInterval(entry.handle)
    entry.handle = null
    this.intervals.delete(name)
  }

  /**
   * Stop every registered interval. Use during shutdown.
   */
  clearAll(): void {
    for (const name of Array.from(this.intervals.keys())) {
      this.clear(name)
    }
  }

  /**
   * Snapshot of every registered interval. Cheap; safe to call from
   * IPC handlers / Perf HUD.
   */
  snapshot(): IntervalSnapshot[] {
    return Array.from(this.intervals.values()).map((e) => ({
      name: e.name,
      intervalMs: e.intervalMs,
      ticks: e.ticks,
      skipped: e.skipped,
      errors: e.errors,
      lastTickMs: e.lastTickMs,
      lastDurationMs: e.lastDurationMs,
      paused: e.paused,
    }))
  }

  /**
   * Pretty-print every registered interval to stdout. Called once after
   * boot so we have a record of what's running on the main thread.
   */
  printSnapshot(): void {
    const snap = this.snapshot()
    if (snap.length === 0) {
      console.log('[IntervalRegistry] No registered intervals')
      return
    }
    console.log('[IntervalRegistry] Registered intervals:')
    for (const s of snap) {
      console.log(
        `  - ${s.name}: every ${s.intervalMs}ms` +
          ` (ticks=${s.ticks} skipped=${s.skipped} errors=${s.errors}` +
          ` lastDur=${s.lastDurationMs ?? '-'}ms paused=${s.paused})`
      )
    }
  }
}

export const intervalRegistry = new IntervalRegistry()
