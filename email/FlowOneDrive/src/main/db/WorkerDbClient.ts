import { Worker, MessageChannel, MessagePort } from 'worker_threads'
import path from 'path'

/**
 * WorkerDbClient — RPC client for moving SQLite into a worker thread.
 *
 * Future F.2 of drive-perf-fix-v2 (scaffold).
 *
 * The plan calls this out as "only if metrics show stalling". The
 * existing `DbQueue` already serializes access and gives us a single
 * chokepoint. When `metrics.histogram('db.queue').p95` regularly exceeds
 * ~50 ms in production we can flip the engine over to this client
 * without touching the call sites in `database.ts`:
 *
 *   const worker = new WorkerDbClient(databasePath)
 *   await worker.start()
 *   const result = await worker.exec('upsertFile', [file])
 *
 * On the worker side, a separate file (`db.worker.ts`) opens
 * `better-sqlite3`, applies the same schema as the main thread, and
 * answers messages with the same prepared statements.
 *
 * What this scaffold provides today:
 *   - Stable RPC envelope (`{id, method, args}` request,
 *     `{id, ok, value, error}` response).
 *   - Pending-promise registry keyed by request id.
 *   - Cleanup on worker exit / error.
 *
 * It does NOT yet ship a worker file, because the migration is a
 * sizeable refactor and the plan explicitly defers it. When the time
 * comes, drop a `db.worker.ts` next to this file and update the
 * `workerPath` constant.
 */

interface PendingCall {
  resolve: (value: unknown) => void
  reject: (err: Error) => void
}

interface DbResponse {
  id: number
  ok: boolean
  value?: unknown
  error?: string
}

export interface WorkerDbClientOptions {
  workerScript?: string
  databasePath: string
}

export class WorkerDbClient {
  private worker: Worker | null = null
  private port: MessagePort | null = null
  private nextId = 1
  private pending = new Map<number, PendingCall>()
  private opts: WorkerDbClientOptions

  constructor(opts: WorkerDbClientOptions) {
    this.opts = opts
  }

  async start(): Promise<void> {
    const workerPath = this.opts.workerScript ?? path.join(__dirname, 'db.worker.js')
    const channel = new MessageChannel()
    this.port = channel.port1

    this.worker = new Worker(workerPath, {
      workerData: {
        databasePath: this.opts.databasePath,
        port: channel.port2,
      },
      transferList: [channel.port2],
    })

    this.worker.on('error', (err) => this.failAll(err))
    this.worker.on('exit', (code) => {
      if (code !== 0) this.failAll(new Error(`db worker exited with code ${code}`))
    })

    this.port.on('message', (msg: DbResponse) => {
      const pending = this.pending.get(msg.id)
      if (!pending) return
      this.pending.delete(msg.id)
      if (msg.ok) pending.resolve(msg.value)
      else pending.reject(new Error(msg.error ?? 'db worker error'))
    })
  }

  /**
   * Stop the worker and reject all pending calls.
   */
  async stop(): Promise<void> {
    if (this.worker) {
      try { await this.worker.terminate() } catch { /* ignore */ }
      this.worker = null
    }
    if (this.port) {
      try { this.port.close() } catch { /* ignore */ }
      this.port = null
    }
    this.failAll(new Error('db worker stopped'))
  }

  /**
   * Invoke a method on the worker. Parameters and return value must be
   * structured-clone-safe.
   */
  exec<T = unknown>(method: string, args: unknown[] = []): Promise<T> {
    if (!this.port) {
      return Promise.reject(new Error('db worker not started'))
    }
    const id = this.nextId++
    return new Promise<T>((resolve, reject) => {
      this.pending.set(id, { resolve: resolve as any, reject })
      this.port!.postMessage({ id, method, args })
    })
  }

  private failAll(err: Error): void {
    for (const [, pending] of this.pending) {
      try { pending.reject(err) } catch { /* ignore */ }
    }
    this.pending.clear()
  }
}
