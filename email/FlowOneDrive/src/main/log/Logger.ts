import fs from 'fs'
import path from 'path'
import os from 'os'
import { app } from 'electron'

/**
 * Logger — central logging utility for FlowOneDrive main process.
 *
 * Wave C.4 of drive-perf-fix-v2.
 *
 * Why: today every module does `console.log(\`[XYZ] …\`)` directly.
 * `console.log` is synchronous, unbatched, and writes to stdout AND the
 * devtools (when open) simultaneously. On a NAS reconnect storm the
 * cumulative log volume can stall the event loop on its own.
 *
 * What this gives us:
 *
 *   - **Levels**: error | warn | info | debug | trace, with a runtime
 *     threshold (`info` in production, `debug` in dev) so noisy channels
 *     vanish without code changes.
 *   - **Ring buffer**: last 5000 lines kept in memory. The renderer can
 *     pull a snapshot via IPC instead of tailing console output.
 *   - **Async disk writer**: a single `fs.createWriteStream` is shared by
 *     every logger; writes are buffered by Node and never block the event
 *     loop.
 *   - **Daily rotation**: the file path includes the local-date prefix so
 *     log files don't grow without bound.
 *   - **Sampling**: hot channels (e.g. `chokidar`, `instant-sync`) can
 *     opt into 1-in-N sampling at debug level so a 1000-event burst still
 *     produces a manageable log.
 *
 * Public API mimics `console`: `log.info(...)`, `log.debug(...)`, plus
 * `log.tagged('CHANNEL').info(...)` for module-scoped loggers.
 */

export type LogLevel = 'error' | 'warn' | 'info' | 'debug' | 'trace'

const LEVEL_ORDER: Record<LogLevel, number> = {
  error: 0,
  warn: 1,
  info: 2,
  debug: 3,
  trace: 4,
}

interface RingEntry {
  ts: number
  level: LogLevel
  channel: string
  message: string
}

interface LoggerOptions {
  level?: LogLevel
  ringSize?: number
  logDir?: string
  echoToConsole?: boolean
}

class LoggerCore {
  private level: LogLevel = 'info'
  private ringSize: number
  private ring: RingEntry[] = []
  private writeStream: fs.WriteStream | null = null
  private logFilePath: string | null = null
  private currentDateKey: string | null = null
  private logDir: string
  private echoToConsole: boolean
  // Sampling state per channel: skip the first (N-1) of every N debug+
  // events for that channel.
  private sampleCounters = new Map<string, number>()
  private sampleRates = new Map<string, number>()

  constructor(opts: LoggerOptions = {}) {
    this.ringSize = opts.ringSize ?? 5_000
    this.echoToConsole = opts.echoToConsole ?? true
    this.logDir = opts.logDir ?? this.defaultLogDir()
    this.level = opts.level ?? this.defaultLevel()
    this.ensureWriteStream()
  }

  private defaultLogDir(): string {
    try {
      if (app && app.getPath) {
        return path.join(app.getPath('userData'), 'logs')
      }
    } catch {
      // app not yet ready; fall through
    }
    return path.join(os.homedir(), '.flowone-drive', 'logs')
  }

  private defaultLevel(): LogLevel {
    if (process.env.FLOWONE_LOG_LEVEL) {
      const v = process.env.FLOWONE_LOG_LEVEL.toLowerCase() as LogLevel
      if (v in LEVEL_ORDER) return v
    }
    return process.env.NODE_ENV === 'development' ? 'debug' : 'info'
  }

  setLevel(level: LogLevel): void {
    this.level = level
  }

  getLevel(): LogLevel {
    return this.level
  }

  /**
   * Configure sampling for a channel. `rate=10` means "log 1 in every 10
   * debug+ messages tagged with this channel". Pass `1` to disable sampling
   * (default).
   */
  setSampleRate(channel: string, rate: number): void {
    if (rate <= 1) {
      this.sampleRates.delete(channel)
      this.sampleCounters.delete(channel)
    } else {
      this.sampleRates.set(channel, Math.floor(rate))
    }
  }

  private ensureWriteStream(): void {
    try {
      const dateKey = new Date().toISOString().slice(0, 10) // YYYY-MM-DD
      if (this.writeStream && this.currentDateKey === dateKey) return

      // Rotate: close old, open new.
      if (this.writeStream) {
        try { this.writeStream.end() } catch { /* ignore */ }
      }

      if (!fs.existsSync(this.logDir)) {
        fs.mkdirSync(this.logDir, { recursive: true })
      }
      this.logFilePath = path.join(this.logDir, `flowone-drive-${dateKey}.log`)
      this.writeStream = fs.createWriteStream(this.logFilePath, { flags: 'a' })
      this.currentDateKey = dateKey
    } catch (err) {
      // Disk logging is best-effort. If we can't write, keep the ring
      // buffer + console echo working.
      this.writeStream = null
      this.logFilePath = null
    }
  }

  private shouldSample(channel: string, level: LogLevel): boolean {
    // Errors and warnings are never sampled.
    if (level === 'error' || level === 'warn') return false
    const rate = this.sampleRates.get(channel)
    if (!rate || rate <= 1) return false
    const next = (this.sampleCounters.get(channel) ?? 0) + 1
    this.sampleCounters.set(channel, next % rate)
    return next % rate !== 0
  }

  log(level: LogLevel, channel: string, args: unknown[]): void {
    if (LEVEL_ORDER[level] > LEVEL_ORDER[this.level]) return
    if (this.shouldSample(channel, level)) return

    const ts = Date.now()
    const text = args
      .map((a) => {
        if (typeof a === 'string') return a
        try { return JSON.stringify(a) } catch { return String(a) }
      })
      .join(' ')

    const entry: RingEntry = { ts, level, channel, message: text }
    this.pushRing(entry)
    this.echo(entry)
    this.persist(entry)
  }

  private pushRing(entry: RingEntry): void {
    this.ring.push(entry)
    if (this.ring.length > this.ringSize) {
      this.ring.splice(0, this.ring.length - this.ringSize)
    }
  }

  private echo(entry: RingEntry): void {
    if (!this.echoToConsole) return
    const formatted = `[${entry.level.toUpperCase()}][${entry.channel}] ${entry.message}`
    switch (entry.level) {
      case 'error': console.error(formatted); break
      case 'warn':  console.warn(formatted);  break
      default:      console.log(formatted);    break
    }
  }

  private persist(entry: RingEntry): void {
    this.ensureWriteStream()
    if (!this.writeStream) return
    const line = `${new Date(entry.ts).toISOString()} ${entry.level.toUpperCase().padEnd(5)} [${entry.channel}] ${entry.message}\n`
    try {
      this.writeStream.write(line)
    } catch {
      // If a single write fails, keep going. The ring buffer still has it.
    }
  }

  /**
   * Read the ring buffer (newest at the end). Cheap; returns a fresh array
   * so callers can sort / filter without affecting the log.
   */
  ring_snapshot(limit = 1000): RingEntry[] {
    if (limit >= this.ring.length) return [...this.ring]
    return this.ring.slice(this.ring.length - limit)
  }

  flush(): void {
    if (this.writeStream) {
      try { this.writeStream.end() } catch { /* ignore */ }
      this.writeStream = null
    }
  }
}

export interface TaggedLogger {
  error: (...args: unknown[]) => void
  warn:  (...args: unknown[]) => void
  info:  (...args: unknown[]) => void
  debug: (...args: unknown[]) => void
  trace: (...args: unknown[]) => void
}

class Logger {
  private core: LoggerCore

  constructor(opts: LoggerOptions = {}) {
    this.core = new LoggerCore(opts)
  }

  setLevel(level: LogLevel): void { this.core.setLevel(level) }
  getLevel(): LogLevel { return this.core.getLevel() }
  setSampleRate(channel: string, rate: number): void {
    this.core.setSampleRate(channel, rate)
  }
  ring(limit?: number) { return this.core.ring_snapshot(limit) }
  flush(): void { this.core.flush() }

  error(...args: unknown[]): void { this.core.log('error', 'app', args) }
  warn (...args: unknown[]): void { this.core.log('warn',  'app', args) }
  info (...args: unknown[]): void { this.core.log('info',  'app', args) }
  debug(...args: unknown[]): void { this.core.log('debug', 'app', args) }
  trace(...args: unknown[]): void { this.core.log('trace', 'app', args) }

  /**
   * Module-scoped logger. Use this in each module so logs are searchable
   * by channel:
   *
   *   const log = logger.tagged('SyncEngine')
   *   log.info('cycle started')
   */
  tagged(channel: string): TaggedLogger {
    return {
      error: (...a: unknown[]) => this.core.log('error', channel, a),
      warn:  (...a: unknown[]) => this.core.log('warn',  channel, a),
      info:  (...a: unknown[]) => this.core.log('info',  channel, a),
      debug: (...a: unknown[]) => this.core.log('debug', channel, a),
      trace: (...a: unknown[]) => this.core.log('trace', channel, a),
    }
  }
}

export const logger = new Logger()

// Default sample rates for known noisy channels (Wave C.4).
logger.setSampleRate('LockWatcher', 50)
logger.setSampleRate('InstantSync', 10)
logger.setSampleRate('BrowserMonitor', 20)
logger.setSampleRate('TimeTracker.FolderMapping', 50)
