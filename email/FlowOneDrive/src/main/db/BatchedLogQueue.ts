import type { Database as SqliteDatabase, Statement } from 'better-sqlite3'
import { DbQueue } from './DbQueue'

/**
 * BatchedLogQueue — coalesce log writes into a single multi-row INSERT.
 *
 * Wave B.2 of drive-perf-fix-v2.
 *
 * `logSync` was being called many times per sync cycle (per upload, per
 * download, per fail). Each call was a separate `INSERT ... ; trim ...`
 * round-trip. Even at SQLite-fast speeds, 200 cycles × 8 logs each is
 * 1600 prepared-statement runs that the engine has to wait for.
 *
 * This queue:
 *   - Buffers log rows in memory.
 *   - Flushes every 1 s (or when buffer hits 50 rows) inside a single
 *     transaction with a multi-row INSERT.
 *   - Drains synchronously at shutdown so logs from the last cycle are
 *     persisted before close().
 *
 * Failure modes:
 *   - If SQLite write fails, we drop the rows and emit a console.error.
 *     Logs are observability data — losing some is acceptable, especially
 *     during a crash.
 */

export interface PendingLog {
  action: string
  itemType: string
  itemId: number | null
  itemName: string
  status: string
  message: string | null
  createdAt: string
  accessMode: string | null
}

export class BatchedLogQueue {
  private buffer: PendingLog[] = []
  private flushTimer: NodeJS.Timeout | null = null
  private destroyed = false
  private inserter: Statement
  private trimmer: Statement
  private counter: Statement

  constructor(
    private db: SqliteDatabase,
    private queue: DbQueue,
    private flushIntervalMs = 1_000,
    private maxBatchSize = 50,
    private retentionLimit = 500,
  ) {
    this.inserter = db.prepare(`
      INSERT INTO logs (action, item_type, item_id, item_name, status, message, created_at, access_mode)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    `)
    this.trimmer = db.prepare(
      `DELETE FROM logs WHERE id IN (SELECT id FROM logs ORDER BY id ASC LIMIT ?)`
    )
    this.counter = db.prepare(`SELECT COUNT(*) AS c FROM logs`)
  }

  push(entry: PendingLog): void {
    if (this.destroyed) return
    this.buffer.push(entry)
    if (this.buffer.length >= this.maxBatchSize) {
      this.flushSoon()
      return
    }
    if (!this.flushTimer) {
      this.flushTimer = setTimeout(() => this.flushSoon(), this.flushIntervalMs)
    }
  }

  /**
   * Force-drain whatever is buffered, blocking the current async flow.
   * Used by Database.drain() at shutdown.
   */
  async flush(): Promise<void> {
    if (this.flushTimer) {
      clearTimeout(this.flushTimer)
      this.flushTimer = null
    }
    if (this.buffer.length === 0) return
    const rows = this.buffer
    this.buffer = []
    await this.queue.enqueue('flushLogs', () => {
      try {
        this.runFlush(rows)
      } catch (err) {
        console.error('[BatchedLogQueue] flush failed, dropping', rows.length, 'rows:', err)
      }
    })
  }

  destroy(): void {
    if (this.flushTimer) {
      clearTimeout(this.flushTimer)
      this.flushTimer = null
    }
    this.destroyed = true
  }

  /**
   * Schedule the next flush via the DbQueue. Doesn't block the caller.
   */
  private flushSoon(): void {
    if (this.flushTimer) {
      clearTimeout(this.flushTimer)
      this.flushTimer = null
    }
    if (this.buffer.length === 0) return
    const rows = this.buffer
    this.buffer = []
    this.queue.enqueue('flushLogs', () => {
      try {
        this.runFlush(rows)
      } catch (err) {
        console.error('[BatchedLogQueue] flush failed, dropping', rows.length, 'rows:', err)
      }
    })
  }

  /**
   * Inner flush — runs inside a single transaction so the inserts and the
   * trim happen as one durable unit.
   */
  private runFlush(rows: PendingLog[]): void {
    if (rows.length === 0) return
    const txn = this.db.transaction(() => {
      for (const r of rows) {
        this.inserter.run(
          r.action, r.itemType, r.itemId, r.itemName, r.status,
          r.message, r.createdAt, r.accessMode
        )
      }
      const total = (this.counter.get() as { c: number }).c
      if (total > this.retentionLimit) {
        this.trimmer.run(total - this.retentionLimit)
      }
    })
    txn()
  }
}
