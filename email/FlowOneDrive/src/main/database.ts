import path from 'path'
import { app } from 'electron'
import BetterSqlite3, { Database as SqliteDatabase, Statement } from 'better-sqlite3'
import { applySchema, normalizePath } from './db/schema'
import { migrateLegacyJsonIfNeeded, type MigrationResult } from './db/migrateLegacyDatabase'
import { DbQueue } from './db/DbQueue'
import { BatchedLogQueue } from './db/BatchedLogQueue'

/**
 * FlowOneDrive local database.
 *
 * Wave B.1 of drive-perf-fix-v2: replaced the electron-store JSON backend with
 * `better-sqlite3` + a schema with proper indexes. The public API is
 * unchanged so callers (mostly `syncEngine.ts`) don't have to migrate.
 *
 * Architecture:
 *
 *   ┌────────────────────────────────────┐
 *   │ Database (this class, public API)  │  ← still synchronous-looking
 *   ├────────────────────────────────────┤
 *   │ DbQueue.enqueue(label, () => fn)   │  ← single-chokepoint serialization
 *   ├────────────────────────────────────┤
 *   │ Prepared statements (cached)       │  ← compiled once, reused
 *   ├────────────────────────────────────┤
 *   │ better-sqlite3 (synchronous C API) │
 *   └────────────────────────────────────┘
 *
 * The methods here keep their original synchronous return types because the
 * sync engine calls them from sync code paths. Internally each call goes
 * through the same prepared statements; per-call latency is recorded in the
 * DbQueue for the perf HUD even though we don't await every call (the queue
 * still tracks them via `enqueue`).
 *
 * For long-running multi-row writes (Wave B.2), call `transaction(() => ...)`
 * which wraps the work in a single SQLite transaction.
 */

export interface SyncedFile {
  id: number
  remoteId: number
  remoteFolderId: number | null
  localPath: string
  filename: string
  checksum: string
  size: number
  mimeType: string
  remoteUpdatedAt: string
  localUpdatedAt: string
  syncStatus: 'synced' | 'pending_upload' | 'pending_download' | 'conflict' | 'error'
  lastSyncAt: string | null
  isPublic: boolean
  publicToken: string | null
  hasPublicLink: boolean
  shareLink: string | null
  nasRelativePath: string | null
  storageLocation: 'nas' | 'local' | 'unknown' | 'pending_migration'
  lastKnownServerChecksum: string | null
}

export interface SyncedFolder {
  id: number
  remoteId: number
  remoteParentId: number | null
  localPath: string
  name: string
  syncStatus: 'synced' | 'pending' | 'error'
  lastSyncAt: string | null
  isPublic: boolean
  publicToken: string | null
  hasPublicLink: boolean
  shareLink: string | null
  color: string | null
  nasRelativePath: string | null
}

export interface SyncLog {
  id: number
  action: string
  itemType: string
  itemId: number | null
  itemName: string
  status: string
  message: string | null
  createdAt: string
  accessMode: 'direct-nas' | 'server-api' | 'offline' | null
}

export interface NasConfig {
  enabled: boolean
  ip: string
  smbShare: string
  nfsPath: string
  userFolder: string
  directAccessEnabled: boolean
}

export interface ConnectionConfig {
  nas: NasConfig
  server: { apiUrl: string; storageType: string; storageSource: string }
  sync: { intervalSeconds: number; conflictStrategy: string }
}

interface OfflineOperation {
  id: number
  type: 'upload' | 'update' | 'delete' | 'create_folder' | 'delete_folder'
  localPath: string
  remotePath?: string
  remoteId?: number
  folderId?: number | null
  filename?: string
  checksum?: string
  size?: number
  mimeType?: string
  createdAt: string
  status: 'pending' | 'in_progress' | 'completed' | 'failed'
  retries: number
  lastError?: string
}

// SQL row → public type mappers (snake_case from SQLite to camelCase API).

function mapFile(row: any): SyncedFile {
  return {
    id: row.id,
    remoteId: row.remote_id,
    remoteFolderId: row.remote_folder_id,
    localPath: row.local_path,
    filename: row.filename,
    checksum: row.checksum,
    size: row.size,
    mimeType: row.mime_type,
    remoteUpdatedAt: row.remote_updated_at,
    localUpdatedAt: row.local_updated_at,
    syncStatus: row.sync_status,
    lastSyncAt: row.last_sync_at,
    isPublic: !!row.is_public,
    publicToken: row.public_token,
    hasPublicLink: !!row.has_public_link,
    shareLink: row.share_link,
    nasRelativePath: row.nas_relative_path,
    storageLocation: row.storage_location,
    lastKnownServerChecksum: row.last_known_server_checksum,
  }
}

function mapFolder(row: any): SyncedFolder {
  return {
    id: row.id,
    remoteId: row.remote_id,
    remoteParentId: row.remote_parent_id,
    localPath: row.local_path,
    name: row.name,
    syncStatus: row.sync_status,
    lastSyncAt: row.last_sync_at,
    isPublic: !!row.is_public,
    publicToken: row.public_token,
    hasPublicLink: !!row.has_public_link,
    shareLink: row.share_link,
    color: row.color,
    nasRelativePath: row.nas_relative_path,
  }
}

function mapLog(row: any): SyncLog {
  return {
    id: row.id,
    action: row.action,
    itemType: row.item_type,
    itemId: row.item_id,
    itemName: row.item_name,
    status: row.status,
    message: row.message,
    createdAt: row.created_at,
    accessMode: row.access_mode,
  }
}

function mapOfflineOp(row: any): OfflineOperation {
  return {
    id: row.id,
    type: row.type,
    localPath: row.local_path,
    remotePath: row.remote_path ?? undefined,
    remoteId: row.remote_id ?? undefined,
    folderId: row.folder_id,
    filename: row.filename ?? undefined,
    checksum: row.checksum ?? undefined,
    size: row.size ?? undefined,
    mimeType: row.mime_type ?? undefined,
    createdAt: row.created_at,
    status: row.status,
    retries: row.retries,
    lastError: row.last_error ?? undefined,
  }
}

export class Database {
  private db: SqliteDatabase
  private queue: DbQueue
  private logQueue: BatchedLogQueue
  private stmts: Record<string, Statement> = {}
  private legacyMigration: MigrationResult | null = null

  constructor(opts: { dbPath?: string } = {}) {
    const userData = (() => {
      try { return app.getPath('userData') } catch { return process.cwd() }
    })()
    const dbPath = opts.dbPath ?? path.join(userData, 'sync-database.sqlite')
    this.db = new BetterSqlite3(dbPath)
    applySchema(this.db)
    this.legacyMigration = migrateLegacyJsonIfNeeded(this.db)
    if (this.legacyMigration.performed) {
      console.log(
        `[Database] Migrated legacy JSON → SQLite: ${this.legacyMigration.filesMigrated} files, ` +
        `${this.legacyMigration.foldersMigrated} folders, ${this.legacyMigration.logsMigrated} logs, ` +
        `${this.legacyMigration.offlineOpsMigrated} offline ops`
      )
    }
    this.queue = new DbQueue()
    this.prepareStatements()
    this.logQueue = new BatchedLogQueue(this.db, this.queue)
  }

  async initialize(): Promise<void> {
    console.log('Database initialized (SQLite, WAL)')
  }

  /**
   * Wave B.1 — public access to the underlying queue (used by Perf HUD M.2).
   */
  getQueueMetrics() {
    return this.queue.getMetrics()
  }

  /**
   * Wave B.1 — legacy migration result (one-shot per app install).
   */
  getLegacyMigrationResult(): MigrationResult | null {
    return this.legacyMigration
  }

  /**
   * Run a callback inside a single SQLite transaction. Used by Wave B.2 for
   * multi-row writes that should commit atomically.
   */
  transaction<T>(fn: () => T): T {
    return this.db.transaction(fn)()
  }

  // ============================================================
  // FILE OPERATIONS
  // ============================================================

  getFileByRemoteId(remoteId: number): SyncedFile | null {
    const row = this.stmts.fileByRemoteId.get(remoteId) as any
    return row ? mapFile(row) : null
  }

  getFileByLocalPath(localPath: string): SyncedFile | null {
    const row = this.stmts.fileByLocalPathNorm.get(normalizePath(localPath)) as any
    return row ? mapFile(row) : null
  }

  getAllFiles(): SyncedFile[] {
    return (this.stmts.allFiles.all() as any[]).map(mapFile)
  }

  getFilesByFolder(remoteFolderId: number | null): SyncedFile[] {
    const rows = remoteFolderId === null
      ? this.stmts.filesInRoot.all() as any[]
      : this.stmts.filesInFolder.all(remoteFolderId) as any[]
    return rows.map(mapFile)
  }

  getPendingFiles(): SyncedFile[] {
    return (this.stmts.pendingFiles.all() as any[]).map(mapFile)
  }

  upsertFile(file: Partial<SyncedFile> & { remoteId: number }): void {
    this.queue.enqueue('upsertFile', () => {
      this.stmts.upsertFile.run({
        remoteId: file.remoteId,
        remoteFolderId: file.remoteFolderId ?? null,
        localPath: file.localPath ?? '',
        localPathNorm: normalizePath(file.localPath ?? ''),
        filename: file.filename ?? '',
        checksum: file.checksum ?? '',
        size: file.size ?? 0,
        mimeType: file.mimeType ?? 'application/octet-stream',
        remoteUpdatedAt: file.remoteUpdatedAt ?? '',
        localUpdatedAt: file.localUpdatedAt ?? '',
        syncStatus: file.syncStatus ?? 'synced',
        lastSyncAt: file.lastSyncAt ?? new Date().toISOString(),
        isPublic: file.isPublic ? 1 : 0,
        publicToken: file.publicToken ?? null,
        hasPublicLink: file.hasPublicLink ? 1 : 0,
        shareLink: file.shareLink ?? null,
        nasRelativePath: file.nasRelativePath ?? null,
        storageLocation: file.storageLocation ?? 'unknown',
        lastKnownServerChecksum: file.lastKnownServerChecksum ?? null,
      })
    })
  }

  updateFileStatus(remoteId: number, status: SyncedFile['syncStatus']): void {
    this.queue.enqueue('updateFileStatus', () => {
      this.stmts.updateFileStatus.run(status, new Date().toISOString(), remoteId)
    })
  }

  deleteFile(remoteId: number): void {
    this.queue.enqueue('deleteFile', () => {
      this.stmts.deleteFile.run(remoteId)
    })
  }

  // ============================================================
  // FOLDER OPERATIONS
  // ============================================================

  getFolderByRemoteId(remoteId: number): SyncedFolder | null {
    const row = this.stmts.folderByRemoteId.get(remoteId) as any
    return row ? mapFolder(row) : null
  }

  getFolderByLocalPath(localPath: string): SyncedFolder | null {
    const row = this.stmts.folderByLocalPathNorm.get(normalizePath(localPath)) as any
    return row ? mapFolder(row) : null
  }

  getAllFolders(): SyncedFolder[] {
    return (this.stmts.allFolders.all() as any[]).map(mapFolder)
  }

  getFoldersByParent(remoteParentId: number | null): SyncedFolder[] {
    const rows = remoteParentId === null
      ? this.stmts.foldersInRoot.all() as any[]
      : this.stmts.foldersByParent.all(remoteParentId) as any[]
    return rows.map(mapFolder)
  }

  /**
   * Look up a folder by relative path (e.g. "Documents/Work").
   *
   * The legacy implementation did a string `endsWith` match against every
   * folder's `localPath`. We preserve that semantic here using SQL `LIKE`
   * against the normalized column — still scans the index but at least uses
   * the index, and is bounded by folder count.
   */
  getFolderByPath(relativePath: string): SyncedFolder | null {
    if (!relativePath) return null
    const norm = normalizePath(relativePath)
    const row = (this.stmts.folderByRelativePath.get('%' + norm) as any)
            || (this.stmts.folderByRelativePath.get('%/' + norm) as any)
    return row ? mapFolder(row) : null
  }

  upsertFolder(folder: Partial<SyncedFolder> & { remoteId: number }): void {
    this.queue.enqueue('upsertFolder', () => {
      this.stmts.upsertFolder.run({
        remoteId: folder.remoteId,
        remoteParentId: folder.remoteParentId ?? null,
        localPath: folder.localPath ?? '',
        localPathNorm: normalizePath(folder.localPath ?? ''),
        name: folder.name ?? '',
        syncStatus: folder.syncStatus ?? 'synced',
        lastSyncAt: folder.lastSyncAt ?? new Date().toISOString(),
        isPublic: folder.isPublic ? 1 : 0,
        publicToken: folder.publicToken ?? null,
        hasPublicLink: folder.hasPublicLink ? 1 : 0,
        shareLink: folder.shareLink ?? null,
        color: folder.color ?? null,
        nasRelativePath: folder.nasRelativePath ?? null,
      })
    })
  }

  deleteFolder(remoteId: number): void {
    this.queue.enqueue('deleteFolder', () => {
      this.stmts.deleteFolder.run(remoteId)
    })
  }

  // ============================================================
  // SYNC LOG
  // ============================================================

  /**
   * Wave B.2: log writes go through `BatchedLogQueue` which buffers them and
   * flushes every 1 s (or on 50-row threshold) as a single transaction with
   * a multi-row INSERT. The original semantics are preserved: each call
   * still produces a row with the same shape.
   */
  logSync(
    action: string,
    itemType: string,
    itemId: number | null,
    itemName: string,
    status: string,
    message?: string,
    accessMode?: 'direct-nas' | 'server-api' | 'offline'
  ): void {
    this.logQueue.push({
      action,
      itemType,
      itemId,
      itemName,
      status,
      message: message ?? null,
      createdAt: new Date().toISOString(),
      accessMode: accessMode ?? null,
    })
  }

  getRecentLogs(limit: number = 50): SyncLog[] {
    return (this.stmts.recentLogs.all(limit) as any[]).map(mapLog)
  }

  clearOldLogs(daysOld: number = 7): void {
    const cutoff = new Date()
    cutoff.setDate(cutoff.getDate() - daysOld)
    this.queue.enqueue('clearOldLogs', () => {
      this.stmts.deleteOldLogs.run(cutoff.toISOString())
    })
  }

  // ============================================================
  // OFFLINE OPERATIONS
  // ============================================================

  queueOfflineOperation(
    operation: Omit<OfflineOperation, 'id' | 'createdAt' | 'status' | 'retries'>
  ): number {
    const result = this.stmts.insertOfflineOp.run({
      type: operation.type,
      localPath: operation.localPath,
      remotePath: operation.remotePath ?? null,
      remoteId: operation.remoteId ?? null,
      folderId: operation.folderId ?? null,
      filename: operation.filename ?? null,
      checksum: operation.checksum ?? null,
      size: operation.size ?? null,
      mimeType: operation.mimeType ?? null,
      createdAt: new Date().toISOString(),
    })
    const id = Number(result.lastInsertRowid)
    console.log(
      `[Database] Queued offline operation #${id}: ${operation.type} - ` +
      `${operation.filename || operation.localPath}`
    )
    return id
  }

  getPendingOfflineOperations(): OfflineOperation[] {
    return (this.stmts.pendingOfflineOps.all() as any[]).map(mapOfflineOp)
  }

  getPendingOfflineCount(): number {
    return (this.stmts.countPendingOfflineOps.get() as { c: number }).c
  }

  updateOfflineOperation(id: number, updates: Partial<OfflineOperation>): void {
    if (updates.status !== undefined) {
      this.stmts.updateOfflineOpStatus.run(updates.status, id)
    }
    if (updates.retries !== undefined) {
      this.stmts.updateOfflineOpRetries.run(updates.retries, id)
    }
    if (updates.lastError !== undefined) {
      this.stmts.updateOfflineOpError.run(updates.lastError, id)
    }
  }

  completeOfflineOperation(id: number): void {
    this.queue.enqueue('completeOfflineOperation', () => {
      this.stmts.deleteOfflineOp.run(id)
      console.log(`[Database] Completed offline operation #${id}`)
    })
  }

  failOfflineOperation(id: number, error: string): void {
    this.queue.enqueue('failOfflineOperation', () => {
      this.stmts.failOfflineOp.run(error, id)
      console.log(`[Database] Failed offline operation #${id}: ${error}`)
    })
  }

  clearOfflineOperations(): void {
    this.queue.enqueue('clearOfflineOperations', () => {
      this.stmts.deleteAllOfflineOps.run()
      console.log('[Database] Cleared all offline operations')
    })
  }

  // ============================================================
  // LIFECYCLE
  // ============================================================

  async drain(): Promise<void> {
    await this.logQueue.flush()
    await this.queue.drain()
  }

  close(): void {
    try {
      // Best-effort synchronous flush of any pending logs before close.
      this.logQueue.destroy()
      this.db.close()
    } catch (err) {
      console.warn('[Database] Error closing SQLite db:', err)
    }
  }

  // ============================================================
  // BULK OPERATIONS — Wave B.2
  // ============================================================

  /**
   * Wave B.2: insert/update many files in a single transaction.
   *
   * Replaces `for (const f of files) db.upsertFile(f)` (which was N separate
   * transactions) with one transaction containing N statement runs against
   * the same prepared statement. Reduces a 500-file sync from ~500 fsyncs
   * to one.
   *
   * `lastSyncAt` defaults to "now" for any row that omits it.
   */
  upsertFilesBulk(files: Array<Partial<SyncedFile> & { remoteId: number }>): void {
    if (files.length === 0) return
    const now = new Date().toISOString()
    const stmt = this.stmts.upsertFile
    const txn = this.db.transaction((rows: Array<Partial<SyncedFile> & { remoteId: number }>) => {
      for (const file of rows) {
        stmt.run({
          remoteId: file.remoteId,
          remoteFolderId: file.remoteFolderId ?? null,
          localPath: file.localPath ?? '',
          localPathNorm: normalizePath(file.localPath ?? ''),
          filename: file.filename ?? '',
          checksum: file.checksum ?? '',
          size: file.size ?? 0,
          mimeType: file.mimeType ?? 'application/octet-stream',
          remoteUpdatedAt: file.remoteUpdatedAt ?? '',
          localUpdatedAt: file.localUpdatedAt ?? '',
          syncStatus: file.syncStatus ?? 'synced',
          lastSyncAt: file.lastSyncAt ?? now,
          isPublic: file.isPublic ? 1 : 0,
          publicToken: file.publicToken ?? null,
          hasPublicLink: file.hasPublicLink ? 1 : 0,
          shareLink: file.shareLink ?? null,
          nasRelativePath: file.nasRelativePath ?? null,
          storageLocation: file.storageLocation ?? 'unknown',
          lastKnownServerChecksum: file.lastKnownServerChecksum ?? null,
        })
      }
    })
    this.queue.enqueue('upsertFilesBulk', () => txn(files))
  }

  /**
   * Wave B.2: insert/update many folders in a single transaction.
   * Same rationale as upsertFilesBulk.
   */
  upsertFoldersBulk(folders: Array<Partial<SyncedFolder> & { remoteId: number }>): void {
    if (folders.length === 0) return
    const now = new Date().toISOString()
    const stmt = this.stmts.upsertFolder
    const txn = this.db.transaction((rows: Array<Partial<SyncedFolder> & { remoteId: number }>) => {
      for (const folder of rows) {
        stmt.run({
          remoteId: folder.remoteId,
          remoteParentId: folder.remoteParentId ?? null,
          localPath: folder.localPath ?? '',
          localPathNorm: normalizePath(folder.localPath ?? ''),
          name: folder.name ?? '',
          syncStatus: folder.syncStatus ?? 'synced',
          lastSyncAt: folder.lastSyncAt ?? now,
          isPublic: folder.isPublic ? 1 : 0,
          publicToken: folder.publicToken ?? null,
          hasPublicLink: folder.hasPublicLink ? 1 : 0,
          shareLink: folder.shareLink ?? null,
          color: folder.color ?? null,
          nasRelativePath: folder.nasRelativePath ?? null,
        })
      }
    })
    this.queue.enqueue('upsertFoldersBulk', () => txn(folders))
  }

  /**
   * Wave B.2: bulk-update sync status. Useful for "mark these 200 files as
   * synced after a successful upload batch".
   */
  updateFileStatusBulk(remoteIds: number[], status: SyncedFile['syncStatus']): void {
    if (remoteIds.length === 0) return
    const stmt = this.stmts.updateFileStatus
    const now = new Date().toISOString()
    const txn = this.db.transaction((ids: number[]) => {
      for (const id of ids) stmt.run(status, now, id)
    })
    this.queue.enqueue('updateFileStatusBulk', () => txn(remoteIds))
  }

  // ============================================================
  // PREPARED STATEMENTS — built once at construction
  // ============================================================

  private prepareStatements(): void {
    const db = this.db
    const fileColumns = `
      id, remote_id, remote_folder_id, local_path, filename, checksum,
      size, mime_type, remote_updated_at, local_updated_at, sync_status,
      last_sync_at, is_public, public_token, has_public_link, share_link,
      nas_relative_path, storage_location, last_known_server_checksum
    `
    const folderColumns = `
      id, remote_id, remote_parent_id, local_path, name, sync_status,
      last_sync_at, is_public, public_token, has_public_link, share_link,
      color, nas_relative_path
    `

    this.stmts.fileByRemoteId = db.prepare(`SELECT ${fileColumns} FROM files WHERE remote_id = ?`)
    this.stmts.fileByLocalPathNorm = db.prepare(`SELECT ${fileColumns} FROM files WHERE local_path_norm = ?`)
    this.stmts.allFiles = db.prepare(`SELECT ${fileColumns} FROM files ORDER BY filename COLLATE NOCASE`)
    this.stmts.filesInFolder = db.prepare(`SELECT ${fileColumns} FROM files WHERE remote_folder_id = ? ORDER BY filename COLLATE NOCASE`)
    this.stmts.filesInRoot = db.prepare(`SELECT ${fileColumns} FROM files WHERE remote_folder_id IS NULL ORDER BY filename COLLATE NOCASE`)
    this.stmts.pendingFiles = db.prepare(`
      SELECT ${fileColumns} FROM files
      WHERE sync_status IN ('pending_upload','pending_download','conflict')
    `)

    this.stmts.upsertFile = db.prepare(`
      INSERT INTO files (
        remote_id, remote_folder_id, local_path, local_path_norm, filename,
        checksum, size, mime_type, remote_updated_at, local_updated_at,
        sync_status, last_sync_at,
        is_public, public_token, has_public_link, share_link,
        nas_relative_path, storage_location, last_known_server_checksum
      ) VALUES (
        @remoteId, @remoteFolderId, @localPath, @localPathNorm, @filename,
        @checksum, @size, @mimeType, @remoteUpdatedAt, @localUpdatedAt,
        @syncStatus, @lastSyncAt,
        @isPublic, @publicToken, @hasPublicLink, @shareLink,
        @nasRelativePath, @storageLocation, @lastKnownServerChecksum
      )
      ON CONFLICT(remote_id) DO UPDATE SET
        remote_folder_id           = excluded.remote_folder_id,
        local_path                 = excluded.local_path,
        local_path_norm            = excluded.local_path_norm,
        filename                   = excluded.filename,
        checksum                   = excluded.checksum,
        size                       = excluded.size,
        mime_type                  = excluded.mime_type,
        remote_updated_at          = excluded.remote_updated_at,
        local_updated_at           = excluded.local_updated_at,
        sync_status                = excluded.sync_status,
        last_sync_at               = excluded.last_sync_at,
        is_public                  = excluded.is_public,
        public_token               = excluded.public_token,
        has_public_link            = excluded.has_public_link,
        share_link                 = excluded.share_link,
        nas_relative_path          = excluded.nas_relative_path,
        storage_location           = excluded.storage_location,
        last_known_server_checksum = excluded.last_known_server_checksum
    `)

    this.stmts.updateFileStatus = db.prepare(
      `UPDATE files SET sync_status = ?, last_sync_at = ? WHERE remote_id = ?`
    )
    this.stmts.deleteFile = db.prepare(`DELETE FROM files WHERE remote_id = ?`)

    this.stmts.folderByRemoteId = db.prepare(`SELECT ${folderColumns} FROM folders WHERE remote_id = ?`)
    this.stmts.folderByLocalPathNorm = db.prepare(`SELECT ${folderColumns} FROM folders WHERE local_path_norm = ?`)
    this.stmts.allFolders = db.prepare(`SELECT ${folderColumns} FROM folders ORDER BY name COLLATE NOCASE`)
    this.stmts.foldersByParent = db.prepare(`SELECT ${folderColumns} FROM folders WHERE remote_parent_id = ? ORDER BY name COLLATE NOCASE`)
    this.stmts.foldersInRoot = db.prepare(`SELECT ${folderColumns} FROM folders WHERE remote_parent_id IS NULL ORDER BY name COLLATE NOCASE`)
    this.stmts.folderByRelativePath = db.prepare(
      `SELECT ${folderColumns} FROM folders WHERE local_path_norm LIKE ? LIMIT 1`
    )

    this.stmts.upsertFolder = db.prepare(`
      INSERT INTO folders (
        remote_id, remote_parent_id, local_path, local_path_norm, name,
        sync_status, last_sync_at,
        is_public, public_token, has_public_link, share_link,
        color, nas_relative_path
      ) VALUES (
        @remoteId, @remoteParentId, @localPath, @localPathNorm, @name,
        @syncStatus, @lastSyncAt,
        @isPublic, @publicToken, @hasPublicLink, @shareLink,
        @color, @nasRelativePath
      )
      ON CONFLICT(remote_id) DO UPDATE SET
        remote_parent_id  = excluded.remote_parent_id,
        local_path        = excluded.local_path,
        local_path_norm   = excluded.local_path_norm,
        name              = excluded.name,
        sync_status       = excluded.sync_status,
        last_sync_at      = excluded.last_sync_at,
        is_public         = excluded.is_public,
        public_token      = excluded.public_token,
        has_public_link   = excluded.has_public_link,
        share_link        = excluded.share_link,
        color             = excluded.color,
        nas_relative_path = excluded.nas_relative_path
    `)
    this.stmts.deleteFolder = db.prepare(`DELETE FROM folders WHERE remote_id = ?`)

    this.stmts.insertLog = db.prepare(`
      INSERT INTO logs (action, item_type, item_id, item_name, status, message, created_at, access_mode)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    `)
    this.stmts.recentLogs = db.prepare(`
      SELECT id, action, item_type, item_id, item_name, status, message, created_at, access_mode
      FROM logs ORDER BY id DESC LIMIT ?
    `)
    this.stmts.countLogs = db.prepare(`SELECT COUNT(*) AS c FROM logs`)
    this.stmts.trimLogs = db.prepare(
      `DELETE FROM logs WHERE id IN (SELECT id FROM logs ORDER BY id ASC LIMIT ?)`
    )
    this.stmts.deleteOldLogs = db.prepare(`DELETE FROM logs WHERE created_at < ?`)

    this.stmts.insertOfflineOp = db.prepare(`
      INSERT INTO offline_operations (
        type, local_path, remote_path, remote_id, folder_id, filename,
        checksum, size, mime_type, created_at, status, retries
      ) VALUES (
        @type, @localPath, @remotePath, @remoteId, @folderId, @filename,
        @checksum, @size, @mimeType, @createdAt, 'pending', 0
      )
    `)
    this.stmts.pendingOfflineOps = db.prepare(`
      SELECT id, type, local_path, remote_path, remote_id, folder_id, filename,
             checksum, size, mime_type, created_at, status, retries, last_error
      FROM offline_operations
      WHERE status IN ('pending', 'failed')
      ORDER BY id ASC
    `)
    this.stmts.countPendingOfflineOps = db.prepare(
      `SELECT COUNT(*) AS c FROM offline_operations WHERE status IN ('pending','failed')`
    )
    this.stmts.updateOfflineOpStatus = db.prepare(`UPDATE offline_operations SET status = ? WHERE id = ?`)
    this.stmts.updateOfflineOpRetries = db.prepare(`UPDATE offline_operations SET retries = ? WHERE id = ?`)
    this.stmts.updateOfflineOpError = db.prepare(`UPDATE offline_operations SET last_error = ? WHERE id = ?`)
    this.stmts.deleteOfflineOp = db.prepare(`DELETE FROM offline_operations WHERE id = ?`)
    this.stmts.failOfflineOp = db.prepare(
      `UPDATE offline_operations SET status = 'failed', retries = retries + 1, last_error = ? WHERE id = ?`
    )
    this.stmts.deleteAllOfflineOps = db.prepare(`DELETE FROM offline_operations`)
  }
}
