import fs from 'fs'
import path from 'path'
import type { Database as SqliteDatabase } from 'better-sqlite3'
import { app } from 'electron'
import { normalizePath } from './schema'

/**
 * Idempotent migration of the legacy `electron-store` JSON database into the
 * new SQLite database. Wave B.1.
 *
 * Behaviour:
 *   - Looks for `<userData>/sync-database.json`.
 *   - If absent: nothing to migrate, exits.
 *   - If present and `meta.legacy_migrated == 'true'` already exists in the
 *     SQLite db, exits (already done).
 *   - Otherwise reads the JSON, bulk-inserts every row in a single
 *     transaction, marks `legacy_migrated = true`, and renames the JSON
 *     file to `sync-database.json.legacy.bak`.
 *
 * Crash safety:
 *   - The single transaction means a mid-migration crash leaves the SQLite
 *     db unchanged (rollback) and the JSON file intact for the next run.
 *   - Renaming to `.legacy.bak` happens AFTER the transaction commits, so
 *     we only "lose" the source after the destination is durable.
 */

interface LegacyFile {
  remoteId: number
  remoteFolderId?: number | null
  localPath?: string
  filename?: string
  checksum?: string
  size?: number
  mimeType?: string
  remoteUpdatedAt?: string
  localUpdatedAt?: string
  syncStatus?: string
  lastSyncAt?: string | null
  isPublic?: boolean
  publicToken?: string | null
  hasPublicLink?: boolean
  shareLink?: string | null
  nasRelativePath?: string | null
  storageLocation?: string
  lastKnownServerChecksum?: string | null
}

interface LegacyFolder {
  remoteId: number
  remoteParentId?: number | null
  localPath?: string
  name?: string
  syncStatus?: string
  lastSyncAt?: string | null
  isPublic?: boolean
  publicToken?: string | null
  hasPublicLink?: boolean
  shareLink?: string | null
  color?: string | null
  nasRelativePath?: string | null
}

interface LegacyLog {
  action: string
  itemType: string
  itemId: number | null
  itemName: string
  status: string
  message: string | null
  createdAt: string
  accessMode: string | null
}

interface LegacyOfflineOp {
  type: string
  localPath: string
  remotePath?: string
  remoteId?: number
  folderId?: number | null
  filename?: string
  checksum?: string
  size?: number
  mimeType?: string
  createdAt: string
  status: string
  retries: number
  lastError?: string
}

interface LegacyDb {
  files?: LegacyFile[]
  folders?: LegacyFolder[]
  logs?: LegacyLog[]
  offlineOperations?: LegacyOfflineOp[]
}

export interface MigrationResult {
  performed: boolean
  filesMigrated: number
  foldersMigrated: number
  logsMigrated: number
  offlineOpsMigrated: number
  legacyPath: string | null
  bakPath: string | null
  error?: string
}

export function migrateLegacyJsonIfNeeded(db: SqliteDatabase): MigrationResult {
  const result: MigrationResult = {
    performed: false,
    filesMigrated: 0,
    foldersMigrated: 0,
    logsMigrated: 0,
    offlineOpsMigrated: 0,
    legacyPath: null,
    bakPath: null,
  }

  // Already migrated?
  const flag = db
    .prepare(`SELECT value FROM meta WHERE key = 'legacy_migrated'`)
    .get() as { value: string } | undefined
  if (flag?.value === 'true') return result

  // Find legacy JSON.
  let userDataDir: string
  try {
    userDataDir = app.getPath('userData')
  } catch {
    // app may not be ready in tests.
    return result
  }

  const legacyPath = path.join(userDataDir, 'sync-database.json')
  if (!fs.existsSync(legacyPath)) {
    db.prepare(`INSERT OR REPLACE INTO meta(key, value) VALUES ('legacy_migrated', 'true')`).run()
    return result
  }

  result.legacyPath = legacyPath

  let raw: string
  try {
    raw = fs.readFileSync(legacyPath, 'utf8')
  } catch (err: any) {
    result.error = `Failed to read legacy JSON: ${err.message}`
    return result
  }

  let legacy: LegacyDb
  try {
    legacy = JSON.parse(raw) as LegacyDb
  } catch (err: any) {
    result.error = `Failed to parse legacy JSON: ${err.message}`
    return result
  }

  const insertFile = db.prepare(`
    INSERT OR IGNORE INTO files (
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
  `)

  const insertFolder = db.prepare(`
    INSERT OR IGNORE INTO folders (
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
  `)

  const insertLog = db.prepare(`
    INSERT INTO logs (action, item_type, item_id, item_name, status, message, created_at, access_mode)
    VALUES (@action, @itemType, @itemId, @itemName, @status, @message, @createdAt, @accessMode)
  `)

  const insertOfflineOp = db.prepare(`
    INSERT INTO offline_operations (
      type, local_path, remote_path, remote_id, folder_id, filename,
      checksum, size, mime_type, created_at, status, retries, last_error
    ) VALUES (
      @type, @localPath, @remotePath, @remoteId, @folderId, @filename,
      @checksum, @size, @mimeType, @createdAt, @status, @retries, @lastError
    )
  `)

  const txn = db.transaction(() => {
    for (const f of legacy.files || []) {
      if (!f.remoteId) continue
      insertFile.run({
        remoteId: f.remoteId,
        remoteFolderId: f.remoteFolderId ?? null,
        localPath: f.localPath ?? '',
        localPathNorm: normalizePath(f.localPath ?? ''),
        filename: f.filename ?? '',
        checksum: f.checksum ?? '',
        size: f.size ?? 0,
        mimeType: f.mimeType ?? 'application/octet-stream',
        remoteUpdatedAt: f.remoteUpdatedAt ?? '',
        localUpdatedAt: f.localUpdatedAt ?? '',
        syncStatus: f.syncStatus ?? 'synced',
        lastSyncAt: f.lastSyncAt ?? null,
        isPublic: f.isPublic ? 1 : 0,
        publicToken: f.publicToken ?? null,
        hasPublicLink: f.hasPublicLink ? 1 : 0,
        shareLink: f.shareLink ?? null,
        nasRelativePath: f.nasRelativePath ?? null,
        storageLocation: f.storageLocation ?? 'unknown',
        lastKnownServerChecksum: f.lastKnownServerChecksum ?? null,
      })
      result.filesMigrated += 1
    }

    for (const f of legacy.folders || []) {
      if (!f.remoteId) continue
      insertFolder.run({
        remoteId: f.remoteId,
        remoteParentId: f.remoteParentId ?? null,
        localPath: f.localPath ?? '',
        localPathNorm: normalizePath(f.localPath ?? ''),
        name: f.name ?? '',
        syncStatus: f.syncStatus ?? 'synced',
        lastSyncAt: f.lastSyncAt ?? null,
        isPublic: f.isPublic ? 1 : 0,
        publicToken: f.publicToken ?? null,
        hasPublicLink: f.hasPublicLink ? 1 : 0,
        shareLink: f.shareLink ?? null,
        color: f.color ?? null,
        nasRelativePath: f.nasRelativePath ?? null,
      })
      result.foldersMigrated += 1
    }

    for (const l of legacy.logs || []) {
      insertLog.run({
        action: l.action,
        itemType: l.itemType,
        itemId: l.itemId,
        itemName: l.itemName,
        status: l.status,
        message: l.message,
        createdAt: l.createdAt,
        accessMode: l.accessMode,
      })
      result.logsMigrated += 1
    }

    for (const op of legacy.offlineOperations || []) {
      insertOfflineOp.run({
        type: op.type,
        localPath: op.localPath,
        remotePath: op.remotePath ?? null,
        remoteId: op.remoteId ?? null,
        folderId: op.folderId ?? null,
        filename: op.filename ?? null,
        checksum: op.checksum ?? null,
        size: op.size ?? null,
        mimeType: op.mimeType ?? null,
        createdAt: op.createdAt,
        status: op.status,
        retries: op.retries,
        lastError: op.lastError ?? null,
      })
      result.offlineOpsMigrated += 1
    }

    db.prepare(
      `INSERT OR REPLACE INTO meta(key, value) VALUES ('legacy_migrated', 'true')`
    ).run()
  })

  try {
    txn()
  } catch (err: any) {
    result.error = `Migration transaction failed: ${err.message}`
    return result
  }

  // Rename legacy file AFTER successful commit.
  const bakPath = legacyPath + '.legacy.bak'
  try {
    fs.renameSync(legacyPath, bakPath)
    result.bakPath = bakPath
  } catch (err: any) {
    console.warn(`[migrateLegacyDatabase] Could not rename legacy file: ${err.message}`)
  }

  result.performed = true
  return result
}
