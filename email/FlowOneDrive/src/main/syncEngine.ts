import axios, { AxiosInstance, AxiosError } from 'axios'
import fs from 'fs'
import path from 'path'
import crypto from 'crypto'
import { Database } from './database'
import { ConfigStore } from './config'
import { setAuthToken, setSessionToken } from './secureStorage'
import { NotificationManager } from './notifications'
import { EventEmitter } from 'events'
import { getNasDiscovery, type AccessMode } from './nas'
import { SyncScheduler, StageMutex } from './sync/SyncScheduler'
import { UploadQueue } from './sync/UploadQueue'
import { intervalRegistry } from './perf/IntervalRegistry'
import {
  existsSafe as remoteExistsSafe,
  probeRemote,
  statSafe as remoteStatSafe,
  copyFileSafe as remoteCopyFileSafe,
  mkdirSafe as remoteMkdirSafe,
  unlinkSafe as remoteUnlinkSafe,
  RemoteFsTimeoutError,
} from './sync/fsRemoteSafe'

// #region agent log helper (dev-only)
const isDev = process.env.NODE_ENV === 'development' || !require('electron').app.isPackaged
function debugLog(location: string, message: string, data: any, hypothesisId: string) {
  if (!isDev) return
  try { console.log(`[SyncDebug:${hypothesisId}] ${location}: ${message}`, typeof data === 'object' ? JSON.stringify(data).substring(0, 200) : data); } catch (e) { }
}
// #endregion

/**
 * Sanitize a file/folder name for the local filesystem.
 * Windows forbids: \ / : * ? " < > |
 * Also trim trailing dots/spaces which Windows silently strips (causing mismatches).
 */
function sanitizePathSegment(name: string): string {
  // Replace forbidden Windows characters with a safe Unicode full-width or dash equivalent
  let safe = name
    .replace(/[<>"/\\|?*]/g, '-')
    .replace(/:/g, '\uFF1A')        // fullwidth colon - visually similar, filesystem-safe
    .replace(/[\x00-\x1f]/g, '')    // strip control chars
  // Trim trailing dots/spaces (Windows ignores them, causing path mismatches)
  safe = safe.replace(/[. ]+$/, '')
  return safe || '_'
}

export type SyncStatus = 'idle' | 'syncing' | 'paused' | 'offline' | 'error'

/**
 * Wave B.5 — UploadQueue payload shape.
 * The queue itself is generic; we tag each job so the single worker can
 * dispatch to the right uploader. `lane: 0` = small/interactive,
 * `lane: 1` = bulk/reconciliation.
 */
type UploadJobPayload =
  | {
      kind: 'create-remote'
      localPath: string
      relativePath: string
    }
  | {
      kind: 'update-remote'
      existingFile: any
      localPath: string
    }

interface SyncState {
  status: SyncStatus
  message: string
  progress?: number
  lastSync?: string
  pendingChanges: number
}

// Notification batch tracker for bulk operations
interface NotificationBatch {
  action: 'trashed' | 'uploaded' | 'deleted' | 'synced'
  files: string[]
  folders: string[]
  timer: ReturnType<typeof setTimeout> | null
}

interface RemoteFile {
  id: number
  folder_id: number | null
  original_name: string
  filename: string
  size: number
  mime_type: string
  checksum?: string
  updated_at: string
  created_at: string
  // Sharing fields (from API - uses share_token)
  share_token?: string | null
  share_expires?: string | null
  // NAS direct access fields
  storage_location?: 'local' | 'nas' | 'pending_migration'
  nas_relative_path?: string
}

interface RemoteFolder {
  id: number
  parent_id: number | null
  name: string
  updated_at: string
  // Sharing fields (from API - uses share_token)
  share_token?: string | null
  share_expires?: string | null
  color?: string | null
}

interface EditingStatus {
  filename: string
  folder_id: number | null
  folder_name?: string
  editor_email: string
  started_at: string
  editing_duration: number
}

export class SyncEngine extends EventEmitter {
  private db: Database
  private config: ConfigStore
  private notifications: NotificationManager
  private api: AxiosInstance | null = null
  // Wave A.5: separate axios instance with a long timeout for streamed
  // upload/download requests. Keeps the short-call API at 30 s while letting
  // multi-GB transfers complete.
  private apiUpload: AxiosInstance | null = null
  private syncInterval: NodeJS.Timeout | null = null
  private editingHeartbeatInterval: NodeJS.Timeout | null = null
  private editingStatusPollInterval: NodeJS.Timeout | null = null
  private state: SyncState = {
    status: 'offline',
    message: 'Not connected',
    pendingChanges: 0,
  }
  private isSyncing = false
  private lastSyncedFile: { name: string; time: number } | null = null
  private mainWindow: Electron.BrowserWindow | null = null

  // Wave D.1 — cycle watchdog. A cycle that exceeds this budget is abandoned:
  // isSyncing is force-cleared and the UI gets a terminal state, so a hung
  // await (NAS dropout, stranded queue promise) can never pin the footer on
  // "Syncing..." forever.
  private static readonly CYCLE_TIMEOUT_MS = 10 * 60_000
  // Monotonic cycle counter. A timed-out cycle's body compares its own id
  // against this before touching shared state, so a late-finishing zombie
  // cycle cannot clobber the state of the cycle that superseded it.
  private cycleSeq = 0

  // Wave D.4 — paths the engine itself wrote/deleted recently. The FileWatcher
  // consults this so engine-initiated fs activity (downloads, mirrored remote
  // deletions) does not feed back into another sync cycle.
  private selfWrites: Map<string, number> = new Map()
  private static readonly SELF_WRITE_TTL_MS = 15_000

  // Wave D.5 — delta gate for the remote tree walk. A full walk is 1 + N
  // sequential HTTP calls (~a minute on large accounts); doing it every cycle
  // kept the footer on "Syncing..." ~90% of the time. We instead ask the
  // cheap /api/drive/sync-events endpoint whether anything changed remotely
  // and only walk when it did — plus a periodic forced walk as a safety net
  // for change types the backend does not record (e.g. renames/moves).
  private lastFullWalkAt = 0
  private remoteEventCursor = 0 // unix SECONDS on the server's clock
  private static readonly FULL_WALK_INTERVAL_MS = 5 * 60_000

  // Wave A.1 — Single source of truth for sync triggers (mutex + debounce + coalesce)
  private scheduler: SyncScheduler = new SyncScheduler({ debounceMs: 500 })
  // Per-stage sub-mutexes (prevent re-entry within a single cycle)
  private uploadMutex: StageMutex = new StageMutex()
  private fetchMutex: StageMutex = new StageMutex()
  // Wave B.5 — bounded outbound concurrency w/ priority lanes + retry backoff
  private uploadQueue: UploadQueue<UploadJobPayload> = new UploadQueue({
    concurrency: 3,
    pressureThreshold: 100,
    baseBackoffMs: 1_000,
    maxBackoffMs: 30_000,
    defaultMaxAttempts: 4,
    // Wave D.2: overall per-job deadline. Guarantees `await enqueueUpload(...)`
    // inside a sync cycle settles even if the queue is paused or a job keeps
    // failing, so the cycle can finish (or be watchdog-recovered) cleanly.
    defaultDeadlineMs: 15 * 60_000,
  })

  // Track files currently being edited by this user
  private activeEditing: Map<string, { filename: string; folderId: number | null }> = new Map()

  // Cache of who else is editing (for UI display)
  private otherEditors: EditingStatus[] = []

  // Track if we've already emitted auth-failed to prevent duplicates
  private authFailedEmitted = false

  // Lock map to prevent duplicate folder creation during bulk operations
  private folderCreationLocks: Map<string, Promise<number | null>> = new Map()

  // Notification batching for bulk operations (prevents notification spam)
  private notificationBatches: Map<string, NotificationBatch> = new Map()
  private readonly NOTIFICATION_BATCH_DELAY = 3000 // 3 seconds to collect batch

  // Track if initial sync has completed (folder DB populated from remote)
  private initialSyncComplete = false
  private initialSyncPromise: Promise<void> | null = null

  // NAS direct access properties
  private currentAccessMode: AccessMode = 'server-api'
  private nasConfig: { host: string; port: number; basePath: string; userFolder: string } | null = null
  private nasCredentials: { username: string; password: string } | null = null
  private nasShareConnected: boolean = false
  private initialSyncResolve: (() => void) | null = null

  // Track if offline reconciliation has been done this session
  private offlineReconciliationDone = false

  // Cached folder-to-client mapping (refreshed at most once per 30s)
  private folderClientMappingCache: Record<string, { client_id: number; client_name: string }> = {}
  private folderClientMappingLastFetch = 0
  private static readonly FOLDER_MAPPING_TTL = 30_000

  constructor(db: Database, config: ConfigStore, notifications: NotificationManager) {
    super()
    this.db = db
    this.config = config
    this.notifications = notifications

    const { getAuthToken, getSessionToken } = require('./secureStorage')
    const apiUrl = config.get('apiUrl')
    const authToken = getAuthToken()
    const sessionToken = getSessionToken()
    if (apiUrl && authToken) {
      this.setCredentials(apiUrl, authToken, sessionToken)
    }
  }

  setCredentials(apiUrl: string, authToken: string, sessionToken?: string | null): void {
    const headers: Record<string, string> = {
      Authorization: `Bearer ${authToken}`,
      'Content-Type': 'application/json',
    }

    // Add session token if available (for session tracking)
    if (sessionToken) {
      headers['X-Session-Token'] = sessionToken
    }

    // Wave A.5: 30 s default timeout. Without this the request can hang
    // indefinitely on a TCP black hole (NAS reboot, dropped VPN tunnel),
    // pinning sockets and the sync cycle behind them.
    this.api = axios.create({
      baseURL: apiUrl,
      headers,
      timeout: 30_000,
    })

    // Wave A.5: dedicated long-timeout instance for upload/download bodies.
    // Multi-GB transfers can legitimately take many minutes; we cap at 10 min
    // per single request. Streamed uploads (Wave B.5) will still benefit from
    // axios' progress events and abort signals.
    this.apiUpload = axios.create({
      baseURL: apiUrl,
      headers,
      timeout: 10 * 60_000,
      maxBodyLength: Infinity,
      maxContentLength: Infinity,
    })

    // Reset auth failed flag when setting new credentials
    this.authFailedEmitted = false

    // Add 401 interceptor to handle expired tokens (applied to BOTH instances).
    const authFailureInterceptor = (error: AxiosError) => {
      if (error.response?.status === 401 && !this.authFailedEmitted) {
        console.log('[SyncEngine] 401 Unauthorized - auth token expired or invalid')
        this.authFailedEmitted = true
        this.handleAuthFailure()
      }
      return Promise.reject(error)
    }
    this.api.interceptors.response.use(response => response, authFailureInterceptor)
    this.apiUpload.interceptors.response.use(response => response, authFailureInterceptor)
  }

  /**
   * Set the current NAS access mode
   * Called by AccessModeManager when mode changes
   */
  setAccessMode(mode: AccessMode): void {
    const previousMode = this.currentAccessMode
    this.currentAccessMode = mode
    console.log(`[SyncEngine] Access mode set to: ${mode}`)
    
    // If coming back online, process queued operations
    if (previousMode === 'offline' && mode !== 'offline') {
      console.log('[SyncEngine] Coming back online - processing queued operations')
      this.processOfflineQueue()
    }
  }

  /**
   * Set NAS configuration for direct access
   */
  setNasConfig(config: { host: string; port: number; basePath: string; userFolder: string } | null): void {
    this.nasConfig = config
    this.nasShareConnected = false // Reset connection state when config changes
    console.log('[SyncEngine] NAS config updated:', config ? `${config.host}:${config.port}${config.basePath}` : 'null')
  }

  /**
   * Set NAS credentials for SMB authentication
   */
  setNasCredentials(credentials: { username: string; password: string } | null): void {
    this.nasCredentials = credentials
    this.nasShareConnected = false // Need to reconnect with new credentials
    console.log('[SyncEngine] NAS credentials updated:', credentials ? `user=${credentials.username}` : 'cleared')
  }

  /**
   * Ensure NAS share is connected (Windows only)
   * Uses 'net use' to connect with stored credentials
   */
  private async ensureNasShareConnected(): Promise<boolean> {
    if (this.nasShareConnected) {
      return true
    }

    if (!this.nasConfig) {
      return false
    }

    // Only needed on Windows for UNC paths
    if (process.platform !== 'win32') {
      this.nasShareConnected = true
      return true
    }

    const uncPath = `\\\\${this.nasConfig.host}\\${this.nasConfig.basePath.split('\\').pop() || 'mailflow-drive'}`

    // Wave A.6: probe with a 3 s timeout instead of fs.existsSync — the
    // synchronous variant blocks the main thread for ~30 s when the host is
    // unreachable, which is the most common cause of full-app freezes.
    const probe = await probeRemote(uncPath, 3000)
    if (probe.exists) {
      console.log('[SyncEngine] NAS share already accessible:', uncPath)
      this.nasShareConnected = true
      return true
    }
    if (probe.timedOut) {
      console.warn('[SyncEngine] NAS share probe timed out — continuing with credentialled connect attempt')
    }

    if (!this.nasCredentials) {
      console.log('[SyncEngine] No credentials available for NAS share')
      return false
    }

    try {
      const { exec } = require('child_process')
      const util = require('util')
      const execPromise = util.promisify(exec)

      // Try to disconnect first (ignore errors)
      try {
        await execPromise(`net use ${uncPath} /delete /y`, { timeout: 5000 })
      } catch (e) {
        // Ignore - might not be connected
      }

      // Connect with credentials
      const cmd = `net use ${uncPath} /user:${this.nasCredentials.username} "${this.nasCredentials.password}" /persistent:no`
      console.log('[SyncEngine] Connecting to NAS share:', uncPath)
      await execPromise(cmd, { timeout: 10000 })

      this.nasShareConnected = true
      console.log('[SyncEngine] Successfully connected to NAS share')
      return true
    } catch (error: any) {
      console.error('[SyncEngine] Failed to connect to NAS share:', error.message)
      this.nasShareConnected = false
      return false
    }
  }

  /**
   * Get current access mode
   */
  getAccessMode(): AccessMode {
    return this.currentAccessMode
  }

  /**
   * Emit state change to renderer
   */
  private emitStateChange(): void {
    this.emit('state-changed', this.getStatus())
  }

  /**
   * Wave D.4 — record that the engine itself is writing/deleting `p`.
   * Call immediately before (and, for slow copies, after) the fs operation;
   * the FileWatcher then ignores the resulting chokidar events within the TTL.
   */
  markSelfWrite(p: string): void {
    if (this.selfWrites.size > 4096) this.pruneSelfWrites()
    this.selfWrites.set(path.normalize(p), Date.now())
  }

  /**
   * Wave D.4 — true if `p` was recently written by the engine itself.
   * Consulted by the FileWatcher to break the download -> fs event -> new
   * sync cycle feedback loop.
   */
  isSelfWrite(p: string): boolean {
    const key = path.normalize(p)
    const ts = this.selfWrites.get(key)
    if (ts === undefined) return false
    if (Date.now() - ts > SyncEngine.SELF_WRITE_TTL_MS) {
      this.selfWrites.delete(key)
      return false
    }
    return true
  }

  private pruneSelfWrites(): void {
    const cutoff = Date.now() - SyncEngine.SELF_WRITE_TTL_MS
    for (const [key, ts] of this.selfWrites) {
      if (ts < cutoff) this.selfWrites.delete(key)
    }
  }

  /**
   * Get count of pending offline operations
   */
  getPendingOfflineCount(): number {
    return this.db.getPendingOfflineCount()
  }

  /**
   * Queue a file operation for offline sync
   */
  private async queueOfflineFileOperation(
    eventType: string,
    filePath: string,
    relativePath: string,
    filename: string
  ): Promise<void> {
    const existingFile = this.db.getFileByLocalPath(filePath)

    if (eventType === 'add' || eventType === 'change') {
      if (!fs.existsSync(filePath)) return

      const stat = fs.statSync(filePath)
      // Wave B.4: streaming async checksum.
      const checksum = await this.calculateChecksum(filePath)
      
      if (existingFile) {
        // Update operation
        if (checksum !== existingFile.checksum) {
          this.db.queueOfflineOperation({
            type: 'update',
            localPath: filePath,
            remoteId: existingFile.remoteId,
            folderId: existingFile.remoteFolderId,
            filename,
            checksum,
            size: stat.size,
            mimeType: existingFile.mimeType
          })
          
          this.state.message = `Queued for upload: ${filename}`
          this.state.pendingChanges = this.db.getPendingOfflineCount()
          this.emitStateChange()
        }
      } else {
        // New file - queue upload
        const parentFolder = this.db.getFolderByLocalPath(path.dirname(filePath))
        
        this.db.queueOfflineOperation({
          type: 'upload',
          localPath: filePath,
          folderId: parentFolder?.remoteId || null,
          filename,
          checksum,
          size: stat.size,
          mimeType: this.getMimeType(filename)
        })
        
        this.state.message = `Queued for upload: ${filename}`
        this.state.pendingChanges = this.db.getPendingOfflineCount()
        this.emitStateChange()
      }
    } else if (eventType === 'unlink') {
      if (existingFile) {
        this.db.queueOfflineOperation({
          type: 'delete',
          localPath: filePath,
          remoteId: existingFile.remoteId,
          filename
        })
        
        this.state.message = `Queued for deletion: ${filename}`
        this.state.pendingChanges = this.db.getPendingOfflineCount()
        this.emitStateChange()
      }
    }
  }

  /**
   * Process offline queue when connection is restored
   */
  private async processOfflineQueue(): Promise<void> {
    const operations = this.db.getPendingOfflineOperations()
    
    if (operations.length === 0) {
      console.log('[SyncEngine] No offline operations to process')
      return
    }
    
    console.log(`[SyncEngine] Processing ${operations.length} offline operations...`)
    this.state.status = 'syncing'
    this.state.message = `Syncing ${operations.length} offline changes...`
    this.emitStateChange()
    
    let successCount = 0
    let failCount = 0
    
    for (const operation of operations) {
      // Skip if too many retries
      if (operation.retries >= 3) {
        console.log(`[SyncEngine] Skipping operation #${operation.id} - too many retries`)
        continue
      }
      
      try {
        this.db.updateOfflineOperation(operation.id, { status: 'in_progress' })
        
        switch (operation.type) {
          case 'upload':
            await this.processOfflineUpload(operation)
            break
          case 'update':
            await this.processOfflineUpdate(operation)
            break
          case 'delete':
            await this.processOfflineDelete(operation)
            break
          case 'create_folder':
            await this.processOfflineFolderCreate(operation)
            break
          case 'delete_folder':
            await this.processOfflineFolderDelete(operation)
            break
        }
        
        this.db.completeOfflineOperation(operation.id)
        successCount++
        
      } catch (error: any) {
        console.error(`[SyncEngine] Failed to process offline operation #${operation.id}:`, error.message)
        this.db.failOfflineOperation(operation.id, error.message)
        failCount++
      }
    }
    
    console.log(`[SyncEngine] Offline queue processed: ${successCount} success, ${failCount} failed`)
    
    this.state.status = 'idle'
    this.state.message = `Synced ${successCount} offline changes`
    this.state.pendingChanges = this.db.getPendingOfflineCount()
    this.state.lastSync = new Date().toISOString()
    this.emitStateChange()
  }

  private async processOfflineUpload(operation: any): Promise<void> {
    if (!fs.existsSync(operation.localPath)) {
      throw new Error('File no longer exists')
    }
    
    const syncFolder = this.config.get('syncFolder')
    const relativePath = path.relative(syncFolder, operation.localPath)
    
    await this.createRemoteFile(operation.localPath, relativePath)
  }

  private async processOfflineUpdate(operation: any): Promise<void> {
    if (!fs.existsSync(operation.localPath)) {
      throw new Error('File no longer exists')
    }
    
    const existingFile = this.db.getFileByRemoteId(operation.remoteId)
    if (!existingFile) {
      // File was deleted from DB, treat as new upload
      return this.processOfflineUpload(operation)
    }
    
    await this.uploadFileVersioned(existingFile, operation.localPath)
  }

  private async processOfflineDelete(operation: any): Promise<void> {
    if (operation.remoteId) {
      await this.api!.post(`/api/drive/files/${operation.remoteId}/trash`)
      this.db.deleteFile(operation.remoteId)
    }
  }

  private async processOfflineFolderCreate(operation: any): Promise<void> {
    const folderName = path.basename(operation.localPath)
    const parentFolder = this.db.getFolderByLocalPath(path.dirname(operation.localPath))
    
    const response = await this.api!.post('/api/drive/folders', {
      name: folderName,
      parent_id: parentFolder?.remoteId || null
    })
    
    if (response.data?.data?.id) {
      this.db.upsertFolder({
        remoteId: response.data.data.id,
        remoteParentId: parentFolder?.remoteId || null,
        localPath: operation.localPath,
        name: folderName,
        syncStatus: 'synced'
      })
    }
  }

  private async processOfflineFolderDelete(operation: any): Promise<void> {
    if (operation.remoteId) {
      await this.api!.post(`/api/drive/folders/${operation.remoteId}/trash`)
      this.db.deleteFolder(operation.remoteId)
    }
  }

  /**
   * Queue a folder operation for offline sync
   */
  private queueOfflineFolderOperation(
    eventType: string,
    folderPath: string,
    folderName: string
  ): void {
    const existingFolder = this.db.getFolderByLocalPath(folderPath)
    
    if (eventType === 'add') {
      if (!existingFolder) {
        const parentFolder = this.db.getFolderByLocalPath(path.dirname(folderPath))
        
        this.db.queueOfflineOperation({
          type: 'create_folder',
          localPath: folderPath,
          folderId: parentFolder?.remoteId || null,
          filename: folderName
        })
        
        this.state.message = `Queued folder: ${folderName}`
        this.state.pendingChanges = this.db.getPendingOfflineCount()
        this.emitStateChange()
      }
    } else if (eventType === 'unlink') {
      if (existingFolder) {
        this.db.queueOfflineOperation({
          type: 'delete_folder',
          localPath: folderPath,
          remoteId: existingFolder.remoteId,
          filename: folderName
        })
        
        this.state.message = `Queued folder deletion: ${folderName}`
        this.state.pendingChanges = this.db.getPendingOfflineCount()
        this.emitStateChange()
      }
    }
  }

  /**
   * Get MIME type from filename
   */
  private getMimeType(filename: string): string {
    const ext = path.extname(filename).toLowerCase()
    const mimeTypes: Record<string, string> = {
      '.pdf': 'application/pdf',
      '.doc': 'application/msword',
      '.docx': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      '.xls': 'application/vnd.ms-excel',
      '.xlsx': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      '.ppt': 'application/vnd.ms-powerpoint',
      '.pptx': 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
      '.txt': 'text/plain',
      '.csv': 'text/csv',
      '.jpg': 'image/jpeg',
      '.jpeg': 'image/jpeg',
      '.png': 'image/png',
      '.gif': 'image/gif',
      '.svg': 'image/svg+xml',
      '.mp3': 'audio/mpeg',
      '.mp4': 'video/mp4',
      '.zip': 'application/zip',
      '.rar': 'application/x-rar-compressed',
      '.7z': 'application/x-7z-compressed',
    }
    return mimeTypes[ext] || 'application/octet-stream'
  }

  /**
   * Handle authentication failure - emit event and stop sync
   */
  private handleAuthFailure(): void {
    console.log('[SyncEngine] Handling auth failure - stopping sync and notifying')

    // Stop sync operations
    this.stopSync()

    // Clear credentials from secure storage
    setAuthToken(null)
    setSessionToken(null)

    // Set offline state
    this.state = {
      status: 'offline',
      message: 'Authentication expired - please log in again',
      pendingChanges: 0,
    }

    // Emit event for main process to handle
    this.emit('auth-failed')

    // Also notify renderer directly
    this.mainWindow?.webContents.send('auth-failed')
  }

  setMainWindow(window: Electron.BrowserWindow | null): void {
    this.mainWindow = window
  }

  getStatus(): SyncState {
    return { ...this.state }
  }

  startSync(): void {
    if (!this.api) {
      this.state = { status: 'offline', message: 'Not logged in', pendingChanges: 0 }
      return
    }

    this.state.status = 'idle'
    this.state.message = 'Ready'

    // Wave A.1: wire the scheduler with the cycle runner
    this.scheduler.setRunCycle((reason) => this.runSyncCycle(reason))

    // Wave B.5: wire the upload queue worker once.
    this.uploadQueue.setWorker((job) => this.runUploadJob(job.payload))

    // Wave D.3: stopSync()/pauseSync() leave the scheduler and upload queue
    // paused. A later startSync() (re-login, SSO clone, token refresh) must
    // un-pause both, otherwise the startup request below is silently dropped
    // and sync stays dead until the app restarts.
    this.scheduler.resume()
    this.uploadQueue.resume()

    // Initial sync (immediate, bypass debounce)
    this.scheduler.requestImmediate('startup')

    // Wave C.3: route every periodic timer through the IntervalRegistry so
    // we have one place that knows what's running, can skip-fire when a tick
    // is already in flight, and can drain everything at shutdown.
    const intervalSec = this.config.get('syncInterval')
    intervalRegistry.set('sync.periodic', intervalSec * 1000, () => {
      if (this.config.get('syncEnabled')) {
        this.scheduler.request('periodic')
      }
    })

    this.startCollaboratorPolling(10_000)
  }

  stopSync(): void {
    intervalRegistry.clear('sync.periodic')
    if (this.syncInterval) {
      clearInterval(this.syncInterval)
      this.syncInterval = null
    }
    this.scheduler.pause()
    // Wave B.5: pause outbound work; in-flight jobs are awaited but no new
    // ones will be picked up until resumeSync().
    this.uploadQueue.pause()
    this.stopCollaboratorPolling()
    this.state = { status: 'offline', message: 'Disconnected', pendingChanges: 0 }
  }

  pauseSync(): void {
    this.state.status = 'paused'
    this.state.message = 'Sync paused'
    this.scheduler.pause()
    this.uploadQueue.pause()
  }

  resumeSync(): void {
    if (this.state.status === 'paused') {
      this.state.status = 'idle'
      this.state.message = 'Ready'
      this.scheduler.resume()
      this.uploadQueue.resume()
      this.scheduler.requestImmediate('user-resume')
    }
  }

  /**
   * Public entry point for triggering a sync.
   * All callers (IPC, watcher, timer, manual) go through the scheduler so we
   * never run two cycles concurrently. Returns a promise that resolves when the
   * triggered (or coalesced) cycle completes.
   *
   * Wave A.1.
   */
  syncNow(reason: string = 'syncNow'): Promise<void> {
    if (this.state.status === 'paused' || !this.api) {
      return Promise.resolve()
    }
    this.scheduler.requestImmediate(reason)
    return this.scheduler.waitForIdle()
  }

  /**
   * Request a sync via the scheduler. Coalesces with other requests in the
   * 500ms debounce window. Used by the FsEventQueue, reconnect logic, etc.
   */
  requestSync(reason: string): void {
    if (!this.api || this.state.status === 'paused') return
    this.scheduler.request(reason)
  }

  /**
   * Counters for the perf HUD / metrics.
   */
  getSchedulerCounters() {
    return this.scheduler.getCounters()
  }

  /**
   * Wave B.5 — UploadQueue counters for the perf HUD / metrics.
   */
  getUploadQueueCounters() {
    return this.uploadQueue.getCounters()
  }

  /**
   * Wave B.5 — Enqueue an outbound upload job. The single worker dispatches
   * to the right concrete uploader based on `payload.kind`.
   * `lane=0` for user-driven (instant) uploads, `lane=1` for bulk
   * reconciliation jobs so they cannot starve interactive saves.
   */
  enqueueUpload(payload: UploadJobPayload, lane: 0 | 1 = 0): Promise<void> {
    return this.uploadQueue.enqueue(payload, { lane })
  }

  /**
   * Wave B.5 — actual job worker. Falls back to direct method calls so we
   * keep all the existing logic (hash skip, NAS path, axios timeouts) intact.
   */
  private async runUploadJob(payload: UploadJobPayload): Promise<void> {
    if (payload.kind === 'create-remote') {
      await this.createRemoteFile(payload.localPath, payload.relativePath)
      return
    }
    if (payload.kind === 'update-remote') {
      await this.uploadFileVersioned(payload.existingFile, payload.localPath)
      return
    }
  }

  /**
   * Internal cycle runner — invoked by the SyncScheduler with the request reason.
   * Replaces the original `syncNow` body. Mutex, paused, and authentication
   * gates are enforced here as a defense-in-depth check, even though the
   * scheduler itself prevents concurrent invocation.
   *
   * Wave D.1: the body is raced against a hard watchdog. If a cycle hangs
   * (TCP black hole, stranded queue promise, NAS dropout) the watchdog
   * force-clears `isSyncing`, pushes a terminal state to the renderer, and
   * lets the scheduler run future cycles. The abandoned body carries its
   * cycle id and will not touch shared state once superseded.
   */
  private async runSyncCycle(reason: string): Promise<void> {
    if (this.isSyncing || this.state.status === 'paused' || !this.api) {
      return
    }

    // Wave B.5: backpressure. If we still have a large outbound queue, the
    // previous cycle's uploads haven't drained yet. Skip this cycle so we
    // don't pile on more work and starve the renderer.
    if (this.uploadQueue.shouldThrottle() && reason !== 'startup' && reason !== 'user-resume') {
      console.log(
        `[SYNC] Throttling cycle "${reason}" — uploadQueue depth=${this.uploadQueue.depth()}`
      )
      return
    }

    const cycleId = ++this.cycleSeq
    this.isSyncing = true
    // Wave D.5: the visible 'syncing' state is set inside the body only when
    // there is real work (full walk / pending uploads), so cheap no-change
    // delta cycles don't blink the footer spinner every 30 seconds.

    let watchdogTimer: NodeJS.Timeout | null = null
    const watchdog = new Promise<'timeout'>((resolve) => {
      watchdogTimer = setTimeout(() => resolve('timeout'), SyncEngine.CYCLE_TIMEOUT_MS)
    })

    // The body never rejects (it handles its own errors), so the race settles
    // either with 'done' or with the watchdog's 'timeout'.
    const outcome = await Promise.race([
      this.runSyncCycleBody(reason, cycleId).then(() => 'done' as const),
      watchdog,
    ])
    if (watchdogTimer) clearTimeout(watchdogTimer)

    if (outcome === 'timeout') {
      console.error(
        `[SYNC] Watchdog: cycle "${reason}" exceeded ${SyncEngine.CYCLE_TIMEOUT_MS}ms — abandoning it and recovering`
      )
      this.db.logSync('sync', 'system', null, 'sync', 'error', `Cycle watchdog timeout (${reason})`)
      if (cycleId === this.cycleSeq) {
        this.isSyncing = false
        this.state.status = 'error'
        this.state.message = 'Sync timed out'
        this.emitStateChange()
      }
    }
  }

  /**
   * Wave D.5 — decide whether this cycle needs the expensive full remote
   * tree walk. Forced for the first sync of the session, explicit user
   * requests, and every FULL_WALK_INTERVAL_MS (safety net for change types
   * the backend does not record as sync events, e.g. renames/moves and
   * shared-folder content). Otherwise the cheap sync-events probe decides.
   * Fails open: a probe error always results in a full walk.
   */
  private async checkRemoteDelta(
    reason: string
  ): Promise<{ walk: boolean; why: string; serverTime: number | null }> {
    let forcedWhy: string | null = null
    if (!this.initialSyncComplete) {
      forcedWhy = 'initial-sync'
    } else if (
      reason === 'startup' ||
      reason === 'user-resume' ||
      reason === 'syncNow' ||
      reason.startsWith('ipc:') ||
      reason.startsWith('local-api:')
    ) {
      forcedWhy = `user:${reason}`
    } else if (Date.now() - this.lastFullWalkAt >= SyncEngine.FULL_WALK_INTERVAL_MS) {
      forcedWhy = 'periodic-full-walk'
    }

    try {
      // Probe even on forced walks — we need the server clock for the cursor.
      const resp = await this.api!.get('/api/drive/sync-events', {
        params: { since: this.remoteEventCursor || Math.floor(Date.now() / 1000) - 60, limit: 1 },
      })
      const events = resp.data.data?.events || []
      const serverTime =
        typeof resp.data.data?.server_time === 'number' ? resp.data.data.server_time : null

      if (forcedWhy) return { walk: true, why: forcedWhy, serverTime }
      if (events.length > 0) return { walk: true, why: 'remote-events', serverTime }

      // Nothing changed remotely — advance the cursor so the window stays small.
      if (serverTime !== null) this.remoteEventCursor = serverTime
      return { walk: false, why: 'no-remote-changes', serverTime }
    } catch (err: any) {
      console.warn('[SYNC] sync-events probe failed, falling back to full walk:', err?.message || err)
      return { walk: true, why: forcedWhy || 'probe-failed', serverTime: null }
    }
  }

  private async runSyncCycleBody(reason: string, cycleId: number): Promise<void> {
    try {
      const wasFirstSync = !this.initialSyncComplete

      // 1. Fetch remote changes (populates local DB with folder structure) —
      // but only when the delta probe (or a forced reason) says it's needed.
      const delta = await this.checkRemoteDelta(reason)
      if (delta.walk) {
        this.state.status = 'syncing'
        this.state.message = 'Syncing...'
        this.emitStateChange()

        await this.fetchRemoteChanges()
        this.lastFullWalkAt = Date.now()
        if (delta.serverTime !== null) this.remoteEventCursor = delta.serverTime
      } else {
        console.log(`[SYNC] Skipping remote tree walk (${delta.why}) — cycle "${reason}"`)
      }

      // Mark initial sync as complete AFTER folders are fetched
      // This ensures local folder DB is populated before processing local file changes
      if (!this.initialSyncComplete) {
        this.initialSyncComplete = true
        console.log('[SYNC] Initial sync complete - folder structure loaded')
        // Resolve any waiting promises
        if (this.initialSyncResolve) {
          this.initialSyncResolve()
          this.initialSyncResolve = null
          this.initialSyncPromise = null
        }
      }

      // 2. Upload local changes
      await this.uploadLocalChanges()

      // 3. Check for shared folder changes
      await this.checkSharedFolderChanges()

      // 4. On first sync of session, reconcile any files added while app was offline
      if (wasFirstSync) {
        await this.reconcileLocalFiles()
      }

      // Wave D.1: superseded by the watchdog — a newer cycle owns the state now.
      if (cycleId !== this.cycleSeq) return

      this.state.status = 'idle'
      // Keep individual file message if synced recently (within 30 seconds)
      if (this.lastSyncedFile && (Date.now() - this.lastSyncedFile.time) < 30000) {
        this.state.message = `Synced: ${this.lastSyncedFile.name}`
      } else {
        this.state.message = 'All files synced'
        this.lastSyncedFile = null
      }
      this.state.lastSync = new Date().toISOString()
      this.state.pendingChanges = 0

    } catch (error: any) {
      console.error('Sync error:', error)
      this.db.logSync('sync', 'system', null, 'sync', 'error', error.message)
      if (cycleId !== this.cycleSeq) return
      this.state.status = 'error'
      this.state.message = error.message || 'Sync failed'
    } finally {
      if (cycleId === this.cycleSeq) {
        this.isSyncing = false
        // Wave C.1: emit terminal state so renderer's push subscription catches
        // the idle/error transition without a follow-up poll.
        this.emitStateChange()
      }
    }
  }

  /**
   * Wait for the initial sync to complete (folder structure loaded from remote)
   * This prevents race conditions when processing local file changes before
   * the folder structure is known
   */
  private async waitForInitialSync(): Promise<void> {
    if (this.initialSyncComplete) {
      return
    }

    // Create promise if not exists
    if (!this.initialSyncPromise) {
      this.initialSyncPromise = new Promise<void>((resolve) => {
        this.initialSyncResolve = resolve
      })
    }

    console.log('[SYNC] Waiting for initial sync to complete...')

    // Wait with timeout (max 30 seconds)
    const timeout = new Promise<void>((_, reject) =>
      setTimeout(() => reject(new Error('Initial sync timeout')), 30000)
    )

    try {
      await Promise.race([this.initialSyncPromise, timeout])
    } catch (e) {
      console.warn('[SYNC] Initial sync wait timed out, proceeding anyway')
    }
  }

  private async fetchRemoteChanges(): Promise<void> {
    const syncFolder = this.config.get('syncFolder')

    // Fetch all folders
    const foldersResponse = await this.api!.get('/api/drive/folders/all')
    const remoteFolders: RemoteFolder[] = foldersResponse.data.data?.folders || []

    // Create local folder structure
    for (const folder of remoteFolders) {
      await this.syncFolder(folder, syncFolder, remoteFolders)
    }

    // Fetch root files
    await this.syncFilesInFolder(null, syncFolder)

    // Fetch files in each folder
    for (const folder of remoteFolders) {
      const localFolderPath = this.getFolderLocalPath(folder, syncFolder, remoteFolders)
      await this.syncFilesInFolder(folder.id, localFolderPath)
    }
  }

  private async syncFolder(folder: RemoteFolder, syncFolder: string, allFolders: RemoteFolder[]): Promise<void> {
    const localPath = this.getFolderLocalPath(folder, syncFolder, allFolders)

    // Create folder if it doesn't exist
    if (!fs.existsSync(localPath)) {
      this.markSelfWrite(localPath)
      fs.mkdirSync(localPath, { recursive: true })
      this.db.logSync('create', 'folder', folder.id, folder.name, 'success')
    }

    // Update database - include sharing info from API (uses share_token)
    this.db.upsertFolder({
      remoteId: folder.id,
      remoteParentId: folder.parent_id,
      localPath,
      name: folder.name,
      syncStatus: 'synced',
      // Include sharing fields - API returns share_token
      isPublic: !!folder.share_token,
      publicToken: folder.share_token || null,
      hasPublicLink: !!folder.share_token,
      shareLink: null,
      color: folder.color || null,
    })
  }

  private getFolderLocalPath(folder: RemoteFolder, syncFolder: string, allFolders: RemoteFolder[]): string {
    const pathParts: string[] = [sanitizePathSegment(folder.name)]
    let current = folder

    while (current.parent_id) {
      const parent = allFolders.find(f => f.id === current.parent_id)
      if (parent) {
        pathParts.unshift(sanitizePathSegment(parent.name))
        current = parent
      } else {
        break
      }
    }

    return path.join(syncFolder, ...pathParts)
  }

  private async syncFilesInFolder(folderId: number | null, localFolderPath: string): Promise<void> {
    try {
      const response = await this.api!.get('/api/drive', {
        params: { folder_id: folderId || '' },
      })

      const remoteFiles: RemoteFile[] = response.data.data?.files || []

      for (const remoteFile of remoteFiles) {
        await this.syncFile(remoteFile, localFolderPath)
      }

      // Check for deleted files (files in DB but not on remote)
      const localFiles = this.db.getFilesByFolder(folderId)
      for (const localFile of localFiles) {
        const stillExists = remoteFiles.find(rf => rf.id === localFile.remoteId)
        if (!stillExists) {
          // File was deleted remotely
          await this.deleteLocalFile(localFile)
        }
      }
    } catch (error) {
      console.error(`Failed to sync folder ${folderId}:`, error)
    }
  }

  private async syncFile(remoteFile: RemoteFile, localFolderPath: string): Promise<void> {
    const localPath = path.join(localFolderPath, sanitizePathSegment(remoteFile.original_name))
    const existingFile = this.db.getFileByRemoteId(remoteFile.id)

    // Check if we need to download
    let needsDownload = false

    if (!fs.existsSync(localPath)) {
      // File doesn't exist locally - need to download
      needsDownload = true
    } else if (existingFile) {
      // File exists locally AND has a database entry - compare checksums.
      // Wave A.7 + B.4: fetch stat once, reuse for the hash-skip fast path,
      // then stream-hash only when the fast path can't decide.
      const localStat = fs.statSync(localPath)
      const localChecksum = await this.calculateChecksumIfChanged(localPath, existingFile, localStat)
      if (remoteFile.checksum && localChecksum !== remoteFile.checksum) {
        const remoteDate = new Date(remoteFile.updated_at)
        const localDate = localStat.mtime

        if (remoteDate > localDate) {
          needsDownload = true
        } else if (localDate > remoteDate) {
          // Local is newer - mark for upload
          this.db.updateFileStatus(remoteFile.id, 'pending_upload')
          return
        }
      }
    } else {
      // File exists locally but NO database entry - must hash to compare.
      const localChecksum = await this.calculateChecksum(localPath)
      if (remoteFile.checksum && localChecksum !== remoteFile.checksum) {
        // Local file is different from remote - need to decide which to keep
        const localStat = fs.statSync(localPath)
        const remoteDate = new Date(remoteFile.updated_at)
        const localDate = localStat.mtime

        if (remoteDate > localDate) {
          // Remote is newer - download
          needsDownload = true
        }
        // If local is newer or same, just register it (don't download)
      }
      // If checksums match, just register the file without downloading
    }

    if (needsDownload) {
      await this.downloadFile(remoteFile, localPath)
    }

    // Update database - include sharing info from API (uses share_token)
    // Wave B.4: only stream-hash if remote didn't supply a checksum.
    const finalChecksum = remoteFile.checksum || (await this.calculateChecksum(localPath))
    this.db.upsertFile({
      remoteId: remoteFile.id,
      remoteFolderId: remoteFile.folder_id,
      localPath,
      filename: remoteFile.original_name,
      checksum: finalChecksum,
      size: remoteFile.size,
      mimeType: remoteFile.mime_type,
      remoteUpdatedAt: remoteFile.updated_at,
      localUpdatedAt: fs.existsSync(localPath) ? fs.statSync(localPath).mtime.toISOString() : undefined,
      syncStatus: 'synced',
      // Include sharing fields - API returns share_token
      isPublic: !!remoteFile.share_token,
      publicToken: remoteFile.share_token || null,
      hasPublicLink: !!remoteFile.share_token,
      shareLink: null,
      // NAS direct access fields
      nasRelativePath: remoteFile.nas_relative_path || undefined,
      storageLocation: remoteFile.storage_location || 'local',
    })
  }

  private async downloadFile(remoteFile: RemoteFile, localPath: string): Promise<void> {
    try {
      this.state.status = 'syncing'
      this.state.message = `Downloading ${remoteFile.original_name}...`

      // Ensure directory exists
      const dir = path.dirname(localPath)
      if (!fs.existsSync(dir)) {
        this.markSelfWrite(dir)
        fs.mkdirSync(dir, { recursive: true })
      }

      // Wave D.4: this download is engine-initiated; the watcher must not
      // treat the resulting fs events as a local change.
      this.markSelfWrite(localPath)

      // Try direct NAS access if available and file is on NAS
      if (this.currentAccessMode === 'direct-nas' && 
          remoteFile.storage_location === 'nas' && 
          remoteFile.nas_relative_path && 
          this.nasConfig) {
        try {
          await this.downloadFromNas(remoteFile, localPath)
          this.db.logSync('download', 'file', remoteFile.id, remoteFile.original_name, 'success', 'direct-nas')
          this.state.status = 'idle'
          this.state.message = `Downloaded: ${remoteFile.original_name} (NAS)`
          this.state.lastSync = new Date().toISOString()
          return
        } catch (nasError: any) {
          console.warn(`[SyncEngine] Direct NAS download failed for ${remoteFile.original_name}, falling back to API:`, nasError.message)
          // Fall through to API download
        }
      }

      // Standard API download — uses long-timeout instance (Wave A.5).
      const response = await this.apiUpload!.get(`/api/drive/files/${remoteFile.id}/download`, {
        responseType: 'arraybuffer',
      })

      // Wave D.4: re-mark — the HTTP fetch above can outlive the initial mark.
      this.markSelfWrite(localPath)
      fs.writeFileSync(localPath, Buffer.from(response.data))
      this.db.logSync('download', 'file', remoteFile.id, remoteFile.original_name, 'success', 'server-api')
      
      // Clear the downloading message after success
      this.state.status = 'idle'
      this.state.message = `Downloaded: ${remoteFile.original_name}`
      this.state.lastSync = new Date().toISOString()
    } catch (error: any) {
      console.error(`Failed to download ${remoteFile.original_name}:`, error)
      this.db.logSync('download', 'file', remoteFile.id, remoteFile.original_name, 'error', error.message)
      this.state.status = 'error'
      this.state.message = `Failed to download ${remoteFile.original_name}`
    }
  }

  /**
   * Download file directly from NAS mount
   * Uses SMB/NFS mount path on the local machine
   */
  private async downloadFromNas(remoteFile: RemoteFile, localPath: string): Promise<void> {
    if (!this.nasConfig || !remoteFile.nas_relative_path) {
      throw new Error('NAS config or file path not available')
    }

    // Ensure NAS share is connected with credentials
    const connected = await this.ensureNasShareConnected()
    if (!connected) {
      throw new Error('Could not connect to NAS share')
    }

    // Build the full NAS path
    // The NAS is mounted locally, so we can access it directly via filesystem
    // nasConfig.basePath is the mount point (e.g., \\NAS\share or /mnt/nas)
    // remoteFile.nas_relative_path is the path relative to user's folder
    const nasFullPath = path.join(
      this.nasConfig.basePath,
      this.nasConfig.userFolder,
      remoteFile.nas_relative_path
    )

    console.log(`[SyncEngine] Direct NAS download: ${nasFullPath} -> ${localPath}`)

    // Wave A.6: bounded-time probe + async copy. Prevents NAS dropouts from
    // pinning the main thread on `fs.existsSync` / `fs.copyFileSync`.
    const probe = await probeRemote(nasFullPath, 3000)
    if (probe.timedOut) {
      throw new RemoteFsTimeoutError('access', nasFullPath, 3000)
    }
    if (!probe.exists) {
      throw new Error(`NAS file not found: ${nasFullPath}`)
    }

    // Copy file from NAS to local sync folder (60 s budget for the transfer).
    // Wave D.4: re-mark before and after — large copies can outlive the
    // self-write TTL, and chokidar only emits once the write stabilises.
    this.markSelfWrite(localPath)
    await remoteCopyFileSafe(nasFullPath, localPath, 60_000)
    this.markSelfWrite(localPath)

    // Verify checksum if available (Wave B.4: streaming).
    if (remoteFile.checksum) {
      const localChecksum = await this.calculateChecksum(localPath)
      if (localChecksum !== remoteFile.checksum) {
        console.warn(`[SyncEngine] Checksum mismatch for ${remoteFile.original_name}, NAS file may be out of sync`)
        // Don't throw - file was copied successfully, just log the warning
      }
    }
  }

  private async deleteLocalFile(localFile: any): Promise<void> {
    try {
      if (fs.existsSync(localFile.localPath)) {
        // Wave D.4: engine-initiated deletion (mirroring a remote delete) —
        // the watcher must not feed the unlink event back into a sync cycle.
        this.markSelfWrite(localFile.localPath)
        fs.unlinkSync(localFile.localPath)
      }
      this.db.deleteFile(localFile.remoteId)
      this.db.logSync('delete', 'file', localFile.remoteId, localFile.filename, 'success', 'Deleted remotely')
    } catch (error: any) {
      console.error(`Failed to delete local file ${localFile.filename}:`, error)
    }
  }

  private async uploadLocalChanges(): Promise<void> {
    const pendingFiles = this.db.getPendingFiles()
    const toUpload = pendingFiles.filter((f: any) => f.syncStatus === 'pending_upload')
    if (toUpload.length === 0) return

    // Wave D.5: cycles no longer pre-set 'syncing'; flip it here when there
    // is real outbound work so the footer reflects actual transfers.
    if (this.state.status !== 'syncing') {
      this.state.status = 'syncing'
      this.emitStateChange()
    }

    for (const file of toUpload) {
      await this.uploadFile(file)
    }
  }

  private async uploadFile(file: any): Promise<void> {
    try {
      if (!fs.existsSync(file.localPath)) {
        this.db.deleteFile(file.remoteId)
        return
      }

      this.state.message = `Uploading ${file.filename}...`

      // Try direct NAS upload if available
      if (this.currentAccessMode === 'direct-nas' && this.nasConfig) {
        try {
          await this.uploadToNas(file)
          this.db.updateFileStatus(file.remoteId, 'synced')
          this.db.logSync('upload', 'file', file.remoteId, file.filename, 'success', 'direct-nas')
          return
        } catch (nasError: any) {
          console.warn(`[SyncEngine] Direct NAS upload failed for ${file.filename}, falling back to API:`, nasError.message)
          // Fall through to API upload
        }
      }

      // Standard API upload
      const fileBuffer = fs.readFileSync(file.localPath)
      const formData = new FormData()
      formData.append('file', new Blob([fileBuffer]), file.filename)
      if (file.remoteFolderId) {
        formData.append('folder_id', file.remoteFolderId.toString())
      }
      formData.append('source', 'electron') // Identify upload source for activity log

      // Use versioned upload to handle existing files (long-timeout instance)
      const response = await this.apiUpload!.post('/api/drive/upload-versioned', formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      })

      this.db.updateFileStatus(file.remoteId, 'synced')
      this.db.logSync('upload', 'file', file.remoteId, file.filename, 'success', 'server-api')
      // Note: Backend already creates sync event, no need to duplicate
    } catch (error: any) {
      console.error(`Failed to upload ${file.filename}:`, error)
      this.db.updateFileStatus(file.remoteId, 'error')
      this.db.logSync('upload', 'file', file.remoteId, file.filename, 'error', error.message)
    }
  }

  /**
   * Upload file directly to NAS mount and register with server
   */
  private async uploadToNas(file: any): Promise<void> {
    if (!this.nasConfig) {
      throw new Error('NAS config not available')
    }

    // Ensure NAS share is connected with credentials
    const connected = await this.ensureNasShareConnected()
    if (!connected) {
      throw new Error('Could not connect to NAS share')
    }

    // Build the NAS path - use folder structure based on remoteFolderId
    const folderPath = await this.buildNasFolderPath(file.remoteFolderId)
    const nasRelativePath = path.join(folderPath, sanitizePathSegment(file.filename))
    const nasFullPath = path.join(
      this.nasConfig.basePath,
      this.nasConfig.userFolder,
      nasRelativePath
    )

    console.log(`[SyncEngine] Direct NAS upload: ${file.localPath} -> ${nasFullPath}`)

    // Wave A.6: ensure NAS directory exists with a 10 s budget.
    const nasDir = path.dirname(nasFullPath)
    const nasDirProbe = await probeRemote(nasDir, 3000)
    if (nasDirProbe.timedOut) {
      throw new RemoteFsTimeoutError('access', nasDir, 3000)
    }
    if (!nasDirProbe.exists) {
      await remoteMkdirSafe(nasDir, 10_000)
    }

    // Wave B.4: streaming async checksum.
    const checksum = await this.calculateChecksum(file.localPath)
    const stat = fs.statSync(file.localPath)

    // Copy file to NAS (60 s budget — covers most file sizes; very large
    // files will fall back to API path which streams).
    await remoteCopyFileSafe(file.localPath, nasFullPath, 60_000)

    // Verify copy was successful
    const verify = await probeRemote(nasFullPath, 3000)
    if (verify.timedOut) {
      throw new RemoteFsTimeoutError('access', nasFullPath, 3000)
    }
    if (!verify.exists) {
      throw new Error('File copy to NAS failed - file not found after copy')
    }

    // Register the file with the server (metadata only)
    await this.registerNasFileWithServer({
      filename: file.filename,
      nasRelativePath,
      folderId: file.remoteFolderId,
      checksum,
      size: stat.size,
      mimeType: file.mimeType || 'application/octet-stream'
    })

    // Update local database with NAS path
    this.db.upsertFile({
      ...file,
      nasRelativePath,
      storageLocation: 'nas',
      checksum,
      syncStatus: 'synced'
    })
  }

  /**
   * Build folder path on NAS based on folder ID
   */
  private async buildNasFolderPath(folderId: number | null): Promise<string> {
    if (!folderId) {
      return '' // Root folder
    }

    // Build path from folder hierarchy
    const localFolder = this.db.getFolderByRemoteId(folderId)
    if (localFolder?.nasRelativePath) {
      return localFolder.nasRelativePath
    }

    // Fallback: fetch folder path from server if not in local DB
    try {
      const response = await this.api!.get(`/api/drive/folders/${folderId}`)
      if (response.data?.success && response.data?.data?.path) {
        const folderNames = response.data.data.path.map((p: any) => sanitizePathSegment(p.name))
        return path.join(...folderNames)
      }
    } catch (err) {
      console.warn(`[SyncEngine] Could not fetch folder path for ID ${folderId}`)
    }

    return '' // Default to root
  }

  /**
   * Register a file uploaded directly to NAS with the server
   */
  private async registerNasFileWithServer(fileInfo: {
    filename: string
    nasRelativePath: string
    folderId: number | null
    checksum: string
    size: number
    mimeType: string
  }): Promise<any> {
    const response = await this.api!.post('/api/drive/files/register', {
      filename: fileInfo.filename,
      nas_relative_path: fileInfo.nasRelativePath,
      folder_id: fileInfo.folderId,
      checksum: fileInfo.checksum,
      size: fileInfo.size,
      mime_type: fileInfo.mimeType,
      storage_location: 'nas',
      source: 'electron-direct'
    })

    if (!response.data?.success) {
      throw new Error(response.data?.message || 'Failed to register file with server')
    }

    return response.data.data
  }

  private async checkSharedFolderChanges(): Promise<void> {
    try {
      const response = await this.api!.get('/api/drive/shared-with-me')
      const sharedFolders = response.data.data?.folders || []

      for (const folder of sharedFolders) {
        // Check for recent changes
        const recentUpdate = new Date(folder.updated_at || folder.shared_at)
        const lastCheck = this.config.get('lastSyncCursor')

        if (!lastCheck || recentUpdate > new Date(lastCheck)) {
          // Notify about shared folder changes
          if (this.config.get('notificationsEnabled')) {
            this.notifications.showSharedFolderChange(
              folder.name,
              folder.owner_email || 'Someone',
              'updated files in'
            )
          }
        }
      }

      // Update sync cursor
      this.config.set('lastSyncCursor', new Date().toISOString())
    } catch (error) {
      console.error('Failed to check shared folder changes:', error)
    }
  }

  /**
   * Reconcile local files that were added while the app was offline.
   * Scans the sync folder and uploads any files not tracked in the database.
   * This runs once per session after the initial sync completes.
   */
  private async reconcileLocalFiles(): Promise<void> {
    if (this.offlineReconciliationDone) {
      return // Already done this session
    }

    const syncFolder = this.config.get('syncFolder')
    if (!syncFolder || !fs.existsSync(syncFolder)) {
      console.log('[RECONCILE] No sync folder configured or folder does not exist')
      return
    }

    console.log('[RECONCILE] Starting offline file reconciliation...')
    this.state.message = 'Checking for offline changes...'

    const filesToUpload: { localPath: string; relativePath: string }[] = []
    const foldersToCreate: { localPath: string; relativePath: string }[] = []

    // Wave B.3: async, batched recursive scan.
    //
    // Previously this was a single-tick `fs.readdirSync` recursion. On large
    // sync folders that pinned the event loop for multiple seconds at startup,
    // freezing the UI and blocking the SyncScheduler from accepting other
    // triggers.
    //
    // Now we walk via `fs.promises.readdir` and yield to the event loop
    // every `YIELD_EVERY` entries with `setImmediate`. Each DB lookup (which
    // is synchronous via better-sqlite3) is preceded by a yield-counter check
    // so even a deep tree can't stall the loop for more than ~50 ms at a time.
    const YIELD_EVERY = 100
    let inspected = 0
    const yieldIfNeeded = async () => {
      inspected += 1
      if (inspected % YIELD_EVERY === 0) {
        await new Promise<void>((resolve) => setImmediate(resolve))
      }
    }

    const scanDirectory = async (dirPath: string): Promise<void> => {
      let entries: import('fs').Dirent[]
      try {
        entries = await fs.promises.readdir(dirPath, { withFileTypes: true })
      } catch (error: any) {
        console.error(`[RECONCILE] Error scanning directory ${dirPath}:`, error.message)
        return
      }

      for (const entry of entries) {
        await yieldIfNeeded()

        const fullPath = path.join(dirPath, entry.name)
        const relativePath = path.relative(syncFolder, fullPath)

        // Skip hidden files and common ignore patterns
        if (this.shouldIgnoreForReconciliation(entry.name, relativePath)) {
          continue
        }

        if (entry.isDirectory()) {
          // SQLite path-indexed lookup (B.1 added folders_local_path_norm idx).
          const existingFolder = this.db.getFolderByLocalPath(fullPath)
          if (!existingFolder) {
            foldersToCreate.push({ localPath: fullPath, relativePath })
          }
          // Recurse — `await` so we maintain depth-first order without
          // unbounded concurrency that could swamp the libuv pool.
          await scanDirectory(fullPath)
        } else if (entry.isFile()) {
          const existingFile = this.db.getFileByLocalPath(fullPath)
          if (!existingFile) {
            filesToUpload.push({ localPath: fullPath, relativePath })
          }
        }
      }
    }

    // Scan the sync folder (now async).
    await scanDirectory(syncFolder)

    const totalItems = foldersToCreate.length + filesToUpload.length
    if (totalItems === 0) {
      console.log('[RECONCILE] No offline changes detected')
      this.offlineReconciliationDone = true
      return
    }

    console.log(`[RECONCILE] Found ${foldersToCreate.length} folders and ${filesToUpload.length} files to sync`)

    // Create folders first (in order of path depth to ensure parents exist)
    foldersToCreate.sort((a, b) => a.relativePath.split(path.sep).length - b.relativePath.split(path.sep).length)

    let processedCount = 0
    for (const folder of foldersToCreate) {
      processedCount++
      this.state.message = `Syncing offline changes (${processedCount}/${totalItems})...`
      console.log(`[RECONCILE] Creating folder: ${folder.relativePath}`)

      try {
        await this.ensureFolderPath(folder.localPath)
      } catch (error: any) {
        console.error(`[RECONCILE] Failed to create folder ${folder.relativePath}:`, error.message)
      }
    }

    // Upload files via UploadQueue (Wave B.5).
    // Bulk lane (1) so they cannot starve interactive saves on lane 0.
    // We collect promises and await in parallel up to the queue's concurrency
    // bound so the loop itself doesn't serialize work that could overlap.
    const uploadPromises: Promise<void>[] = []
    for (const file of filesToUpload) {
      processedCount++
      const filename = path.basename(file.localPath)
      this.state.message = `Syncing offline changes (${processedCount}/${totalItems}): ${filename}`
      console.log(`[RECONCILE] Queueing file: ${file.relativePath}`)

      uploadPromises.push(
        this.enqueueUpload(
          { kind: 'create-remote', localPath: file.localPath, relativePath: file.relativePath },
          1
        )
          .then(() => {
            this.db.logSync('upload', 'file', null, filename, 'success', 'Offline reconciliation')
          })
          .catch((error: any) => {
            console.error(`[RECONCILE] Failed to upload ${file.relativePath}:`, error.message)
            this.db.logSync('upload', 'file', null, filename, 'error', `Reconciliation failed: ${error.message}`)
          })
      )
    }
    await Promise.allSettled(uploadPromises)

    // Show notification about reconciled files
    if (totalItems > 0 && this.config.get('notificationsEnabled')) {
      const parts: string[] = []
      if (filesToUpload.length > 0) {
        parts.push(`${filesToUpload.length} file${filesToUpload.length > 1 ? 's' : ''}`)
      }
      if (foldersToCreate.length > 0) {
        parts.push(`${foldersToCreate.length} folder${foldersToCreate.length > 1 ? 's' : ''}`)
      }
      this.notifications.show(
        'Offline Changes Synced',
        `${parts.join(' and ')} added while offline ${filesToUpload.length === 1 && foldersToCreate.length === 0 ? 'was' : 'were'} uploaded to cloud.`
      )
    }

    console.log(`[RECONCILE] Completed - synced ${totalItems} items`)
    this.offlineReconciliationDone = true
  }

  /**
   * Force reconciliation of local files (can be triggered manually)
   * Resets the flag to allow re-running reconciliation
   */
  async forceReconcileLocalFiles(): Promise<{ folders: number; files: number }> {
    this.offlineReconciliationDone = false
    await this.reconcileLocalFiles()
    return { folders: 0, files: 0 } // Stats already logged
  }

  /**
   * Check if a file/folder should be ignored during reconciliation
   */
  private shouldIgnoreForReconciliation(name: string, relativePath: string): boolean {
    // Hidden files and folders
    if (name.startsWith('.')) return true

    // Common system/temp files
    const ignorePatterns = [
      /^~\$/,                    // Office lock files
      /\.tmp$/i,
      /\.temp$/i,
      /~$/,
      /\.swp$/,
      /\.lock$/,
      /^desktop\.ini$/i,
      /^thumbs\.db$/i,
      /^\.DS_Store$/,
      /\.bak$/i,
      /\.backup$/i,
      /\.old$/i,
      /\.orig$/i,
      /\.part$/i,
      /\.crdownload$/i,
      /^node_modules$/,
      /^\.git$/,
    ]

    for (const pattern of ignorePatterns) {
      if (pattern.test(name) || pattern.test(relativePath)) {
        return true
      }
    }

    return false
  }

  // Handle local file changes from FileWatcher - INSTANT UPLOAD
  async handleLocalChange(eventType: string, filePath: string): Promise<void> {
    const syncFolder = this.config.get('syncFolder')
    const relativePath = path.relative(syncFolder, filePath)
    const filename = path.basename(filePath)

    // If offline, queue operation for later
    if (this.currentAccessMode === 'offline') {
      await this.queueOfflineFileOperation(eventType, filePath, relativePath, filename)
      return
    }

    if (!this.api) return

    // Wait for initial sync to complete before processing new files
    // This ensures the folder structure is known before uploading
    if (!this.initialSyncComplete && eventType === 'add') {
      console.log(`[SYNC] Waiting for initial sync before uploading: ${path.basename(filePath)}`)
      await this.waitForInitialSync()
    }

    if (eventType === 'add' || eventType === 'change') {
      const existingFile = this.db.getFileByLocalPath(filePath)

      if (existingFile) {
        // INSTANT UPLOAD - don't just mark, upload immediately.
        // Wave A.7 + B.4: skip the MD5 hash entirely if size+mtime match the
        // prior record (most common case during quick saves), otherwise
        // stream-hash via fs.createReadStream.
        const newChecksum = await this.calculateChecksumIfChanged(filePath, existingFile)
        if (newChecksum !== existingFile.checksum) {
          console.log(`[INSTANT] Uploading changed file: ${filename}`)
          this.state.status = 'syncing'
          this.state.message = `Uploading ${filename}...`

          try {
            // Wave B.5: route through the bounded UploadQueue (lane 0 = interactive)
            await this.enqueueUpload(
              { kind: 'update-remote', existingFile, localPath: filePath },
              0
            )
            this.state.status = 'idle'
            this.state.message = `Synced: ${filename}`
            this.state.lastSync = new Date().toISOString()
            this.lastSyncedFile = { name: filename, time: Date.now() }
            this.db.logSync('upload', 'file', existingFile.remoteId, filename, 'success', 'Instant sync')

            // Emit event for UI update
            this.emitActivity({
              action: 'uploaded',
              type: 'file',
              name: filename,
              by: 'You',
              at: new Date().toISOString(),
            })

            // Notify renderer to refresh file list
            this.emitFilesChanged(existingFile.remoteFolderId)
          } catch (error: any) {
            console.error(`Failed instant upload:`, error)
            this.state.status = 'error'
            this.state.message = `Failed: ${filename}`
            this.db.logSync('upload', 'file', existingFile.remoteId, filename, 'error', error.message)
          }
        }
      } else {
        // New file - create on remote immediately (Wave B.5: routed through UploadQueue lane 0)
        console.log(`[INSTANT] Creating new file: ${filename}`)
        this.state.status = 'syncing'
        this.state.message = `Uploading ${filename}...`
        await this.enqueueUpload(
          { kind: 'create-remote', localPath: filePath, relativePath },
          0
        )
        const newFile = this.db.getFileByLocalPath(filePath)
        this.state.status = 'idle'
        this.state.message = `Synced: ${filename}`
        this.state.lastSync = new Date().toISOString()
        this.lastSyncedFile = { name: filename, time: Date.now() }

        this.emitActivity({
          action: 'created',
          type: 'file',
          name: filename,
          by: 'You',
          at: new Date().toISOString(),
        })

        // Notify renderer to refresh file list
        this.emitFilesChanged(newFile?.remoteFolderId ?? null)
      }
    } else if (eventType === 'unlink') {
      const existingFile = this.db.getFileByLocalPath(filePath)
      if (existingFile) {
        // IMPORTANT: Wait before trashing - editors often delete then recreate files
        // This prevents accidental trashing when editing
        console.log(`[INSTANT] File removed, waiting to confirm deletion: ${filename}`)

        // Wait 2 seconds to see if file comes back (editor save pattern)
        await new Promise(resolve => setTimeout(resolve, 2000))

        // Check if file exists again
        if (fs.existsSync(filePath)) {
          console.log(`[INSTANT] File reappeared, not trashing: ${filename}`)
          return
        }

        console.log(`[INSTANT] Confirmed deletion, moving to trash: ${filename}`)
        const folderId = existingFile.remoteFolderId
        try {
          // If file is on NAS and we have direct access, move to trash on NAS too
          if (this.currentAccessMode === 'direct-nas' && 
              this.nasConfig && 
              existingFile.storageLocation === 'nas' && 
              existingFile.nasRelativePath) {
            try {
              await this.trashFileOnNas(existingFile)
            } catch (nasError: any) {
              console.warn(`[SyncEngine] NAS trash failed for ${filename}, server will handle cleanup:`, nasError.message)
              // Server trash will still work - NAS file cleanup can happen later
            }
          }

          // Move to trash on server (handles metadata and NAS cleanup if not already done)
          await this.api.post(`/api/drive/files/${existingFile.remoteId}/trash`)
          this.db.deleteFile(existingFile.remoteId)
          this.db.logSync('trash', 'file', existingFile.remoteId, filename, 'success', 'Moved to trash')

          this.emitActivity({
            action: 'trashed',
            type: 'file',
            name: filename,
            by: 'You',
            at: new Date().toISOString(),
          })

          // Queue batched notification (prevents spam on bulk delete)
          this.queueBatchedNotification('trashed', 'file', filename)

          // Notify renderer to refresh file list
          this.emitFilesChanged(folderId)
        } catch (error: any) {
          console.error(`Failed to trash remote file:`, error)
        }
      }
    }
  }

  // Upload file with versioning (creates new version if file exists)
  private async uploadFileVersioned(existingFile: any, localPath: string): Promise<void> {
    // Wave A.7: re-use prior hash when (size, mtime) are unchanged. By the
    // time we get here from `handleLocalChange`, we've already determined
    // the file changed, but for the upload re-tries / retry-from-error path
    // this still spares a redundant full-file read.
    const stat = fs.statSync(localPath)
    const newChecksum = await this.calculateChecksumIfChanged(localPath, existingFile, stat)

    // Try direct NAS upload if available and file is stored on NAS
    if (this.currentAccessMode === 'direct-nas' && 
        this.nasConfig && 
        existingFile.storageLocation === 'nas' && 
        existingFile.nasRelativePath) {
      try {
        await this.updateFileOnNas(existingFile, localPath, newChecksum)
        return
      } catch (nasError: any) {
        console.warn(`[SyncEngine] Direct NAS update failed for ${existingFile.filename}, falling back to API:`, nasError.message)
        // Fall through to API upload
      }
    }

    // Standard API upload with versioning
    const fileBuffer = fs.readFileSync(localPath)
    const formData = new FormData()
    formData.append('file', new Blob([fileBuffer]), existingFile.filename)
    if (existingFile.remoteFolderId) {
      formData.append('folder_id', existingFile.remoteFolderId.toString())
    }
    formData.append('source', 'electron') // Identify upload source for activity log

    const response = await this.apiUpload!.post('/api/drive/upload-versioned', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })

    // Update local checksum
    this.db.upsertFile({
      ...existingFile,
      checksum: newChecksum,
      localUpdatedAt: stat.mtime.toISOString(),
      syncStatus: 'synced',
    })
    // Note: Backend already creates sync event, no need to duplicate
  }

  /**
   * Update an existing file on NAS directly
   */
  private async updateFileOnNas(existingFile: any, localPath: string, newChecksum: string): Promise<void> {
    if (!this.nasConfig || !existingFile.nasRelativePath) {
      throw new Error('NAS config or file path not available')
    }

    // Ensure NAS share is connected with credentials
    const connected = await this.ensureNasShareConnected()
    if (!connected) {
      throw new Error('Could not connect to NAS share')
    }

    const nasFullPath = path.join(
      this.nasConfig.basePath,
      this.nasConfig.userFolder,
      existingFile.nasRelativePath
    )

    console.log(`[SyncEngine] Direct NAS update: ${localPath} -> ${nasFullPath}`)

    // Ask the server to archive the CURRENT content as a version BEFORE we
    // overwrite the bytes in place. Without this the old content is gone
    // forever and version history is fake. A snapshot failure aborts the
    // direct-NAS path; the caller falls back to the API upload, which
    // versions server-side.
    await this.api!.post(`/api/drive/files/${existingFile.remoteId}/versions/snapshot`, {})

    // Wave A.6: bounded async copy with verification probe.
    await remoteCopyFileSafe(localPath, nasFullPath, 60_000)

    const verify = await probeRemote(nasFullPath, 3000)
    if (verify.timedOut) {
      throw new RemoteFsTimeoutError('access', nasFullPath, 3000)
    }
    if (!verify.exists) {
      throw new Error('File copy to NAS failed')
    }

    const stat = fs.statSync(localPath)

    // Update metadata on server
    await this.api!.put(`/api/drive/files/${existingFile.remoteId}/metadata`, {
      checksum: newChecksum,
      size: stat.size,
      source: 'electron-direct'
    })

    // Update local database
    this.db.upsertFile({
      ...existingFile,
      checksum: newChecksum,
      localUpdatedAt: stat.mtime.toISOString(),
      syncStatus: 'synced',
      lastKnownServerChecksum: newChecksum
    })

    this.db.logSync('upload', 'file', existingFile.remoteId, existingFile.filename, 'success', 'direct-nas')
  }

  /**
   * Move a file to trash on NAS (rename with .trash suffix to preserve data)
   */
  private async trashFileOnNas(existingFile: any): Promise<void> {
    if (!this.nasConfig || !existingFile.nasRelativePath) {
      throw new Error('NAS config or file path not available')
    }

    // Ensure NAS share is connected with credentials
    const connected = await this.ensureNasShareConnected()
    if (!connected) {
      throw new Error('Could not connect to NAS share')
    }

    const nasFullPath = path.join(
      this.nasConfig.basePath,
      this.nasConfig.userFolder,
      existingFile.nasRelativePath
    )

    console.log(`[SyncEngine] Trashing file on NAS: ${nasFullPath}`)

    // Wave A.6: bounded probe — never block main thread on a dead share.
    const probe = await probeRemote(nasFullPath, 3000)
    if (probe.timedOut) {
      throw new RemoteFsTimeoutError('access', nasFullPath, 3000)
    }
    if (!probe.exists) {
      console.log(`[SyncEngine] NAS file already removed: ${nasFullPath}`)
      return
    }

    // Move to .trash folder on NAS (or append .trashed timestamp)
    const trashFolder = path.join(
      this.nasConfig.basePath,
      this.nasConfig.userFolder,
      '.trash'
    )

    const trashFolderProbe = await probeRemote(trashFolder, 3000)
    if (trashFolderProbe.timedOut) {
      throw new RemoteFsTimeoutError('access', trashFolder, 3000)
    }
    if (!trashFolderProbe.exists) {
      await remoteMkdirSafe(trashFolder, 10_000)
    }

    const timestamp = new Date().toISOString().replace(/[:.]/g, '-')
    const trashedName = `${existingFile.filename}.${timestamp}.trashed`
    const trashPath = path.join(trashFolder, trashedName)

    await fs.promises.rename(nasFullPath, trashPath)
    console.log(`[SyncEngine] Moved to NAS trash: ${trashPath}`)
  }

  // Handle folder changes
  async handleLocalFolderChange(eventType: string, folderPath: string): Promise<void> {
    const folderName = path.basename(folderPath)

    // If offline, queue operation for later
    if (this.currentAccessMode === 'offline') {
      this.queueOfflineFolderOperation(eventType, folderPath, folderName)
      return
    }

    if (!this.api) return

    if (eventType === 'add') {
      // Wait for initial sync to complete before creating folders
      // This ensures parent folder structure is known from the remote
      if (!this.initialSyncComplete) {
        console.log(`[SYNC] Waiting for initial sync before creating folder: ${folderName}`)
        await this.waitForInitialSync()
      }

      console.log(`[INSTANT] Creating folder: ${folderName}`)
      try {
        // FIXED: Use ensureFolderPath which handles:
        // 1. Locking to prevent duplicate creation
        // 2. Parent folder creation (recursive)
        // 3. DB checks to avoid duplicates
        // This ensures proper folder hierarchy even during bulk copy operations
        const folderId = await this.ensureFolderPath(folderPath)

        if (folderId) {
          console.log(`[INSTANT] Folder ensured: ${folderName} (ID: ${folderId})`)

          // Get parent ID for the event emission
          const folder = this.db.getFolderByLocalPath(folderPath)
          this.emitFilesChanged(folder?.remoteParentId || null)
        } else {
          console.warn(`[INSTANT] Could not ensure folder: ${folderName}`)
        }
      } catch (error: any) {
        console.error(`Failed to create folder:`, error)
      }
    } else if (eventType === 'unlink') {
      console.log(`[INSTANT] Folder deleted, moving to trash: ${folderName}`)
      const existingFolder = this.db.getFolderByLocalPath(folderPath)
      if (existingFolder) {
        try {
          // Move to trash instead of permanent delete (safer - user can recover)
          await this.api.post(`/api/drive/folders/${existingFolder.remoteId}/trash`)
          this.db.deleteFolder(existingFolder.remoteId)
          this.db.logSync('trash', 'folder', existingFolder.remoteId, folderName, 'success', 'Moved to trash')

          // Queue batched notification (prevents spam on bulk delete)
          this.queueBatchedNotification('trashed', 'folder', folderName)

          // Notify renderer to refresh file list
          this.emitFilesChanged(existingFolder.remoteParentId)
        } catch (error: any) {
          console.error(`Failed to trash folder:`, error)

          // Check if trashing was blocked due to board reference (403)
          const errorMessage = error.response?.data?.message || error.message || ''
          if (error.response?.status === 403 || errorMessage.includes('linked to board')) {
            // Show notification to user
            this.notifications.show(
              'Cannot Delete Folder',
              errorMessage || `"${folderName}" is linked to a board and cannot be deleted.`,
              () => {
                // On click, open the app window
                if (this.mainWindow) {
                  this.mainWindow.show()
                  this.mainWindow.focus()
                }
              }
            )
          }
        }
      }
    }
  }

  // Activity event emitter
  private activityListeners: ((activity: any) => void)[] = []

  onActivity(listener: (activity: any) => void): () => void {
    this.activityListeners.push(listener)
    return () => {
      this.activityListeners = this.activityListeners.filter(l => l !== listener)
    }
  }

  private emitActivity(activity: any): void {
    this.activityListeners.forEach(listener => listener(activity))
  }

  /**
   * Queue a notification for batching - prevents notification spam on bulk operations
   * After NOTIFICATION_BATCH_DELAY ms, shows a single summary notification
   */
  private queueBatchedNotification(action: 'trashed' | 'uploaded' | 'deleted' | 'synced', type: 'file' | 'folder', name: string): void {
    let batch = this.notificationBatches.get(action)

    if (!batch) {
      batch = {
        action,
        files: [],
        folders: [],
        timer: null
      }
      this.notificationBatches.set(action, batch)
    }

    // Add to appropriate list
    if (type === 'file') {
      batch.files.push(name)
    } else {
      batch.folders.push(name)
    }

    // Clear existing timer and set new one
    if (batch.timer) {
      clearTimeout(batch.timer)
    }

    batch.timer = setTimeout(() => {
      this.showBatchedNotification(action)
    }, this.NOTIFICATION_BATCH_DELAY)
  }

  /**
   * Show the batched notification summary
   */
  private showBatchedNotification(action: 'trashed' | 'uploaded' | 'deleted' | 'synced'): void {
    const batch = this.notificationBatches.get(action)
    if (!batch) return

    const fileCount = batch.files.length
    const folderCount = batch.folders.length
    const totalCount = fileCount + folderCount

    if (totalCount === 0) {
      this.notificationBatches.delete(action)
      return
    }

    // Build notification message
    let title = ''
    let body = ''

    const actionVerb = {
      trashed: 'Moved to Trash',
      uploaded: 'Uploaded',
      deleted: 'Deleted',
      synced: 'Synced'
    }[action]

    if (totalCount === 1) {
      // Single item - show specific name
      const itemName = batch.files[0] || batch.folders[0]
      const itemType = batch.files.length > 0 ? 'File' : 'Folder'
      title = `${itemType} ${actionVerb}`
      body = `"${itemName}" was ${action === 'trashed' ? 'moved to trash' : action}.${action === 'trashed' ? ' You can restore it from the web app.' : ''}`
    } else {
      // Multiple items - show summary
      title = `${totalCount} Items ${actionVerb}`
      const parts: string[] = []
      if (fileCount > 0) parts.push(`${fileCount} file${fileCount > 1 ? 's' : ''}`)
      if (folderCount > 0) parts.push(`${folderCount} folder${folderCount > 1 ? 's' : ''}`)
      body = `${parts.join(' and ')} ${action === 'trashed' ? 'moved to trash' : action}.${action === 'trashed' ? ' Restore from web app.' : ''}`
    }

    this.notifications.show(title, body, () => {
      if (this.mainWindow) {
        this.mainWindow.show()
        this.mainWindow.focus()
      }
    })

    // Clear the batch
    this.notificationBatches.delete(action)
  }

  // Get recent activity from the database
  getRecentActivity(limit: number = 50): any[] {
    return this.db.getRecentLogs(limit)
  }

  // Check for collaborator changes and return notifications
  async checkCollaboratorChanges(): Promise<any[]> {
    if (!this.api) return []

    try {
      const response = await this.api.get('/api/sync/shared-activity', {
        params: { since: this.config.get('lastCollaboratorCheck') || new Date(Date.now() - 60000).toISOString() }
      })

      const changes: any[] = []
      const activity = response.data.data?.activity || []

      for (const item of activity) {
        for (const file of item.files || []) {
          // Only notify if not changed by current user
          const userEmail = this.config.get('userEmail')
          if (file.last_modified_by && file.last_modified_by !== userEmail) {
            changes.push({
              action: 'modified',
              type: 'file',
              name: file.original_name,
              folder: item.folder?.folder_name,
              by: file.last_modified_by,
              at: file.updated_at,
            })

            // Show desktop notification
            if (this.config.get('notificationsEnabled')) {
              this.notifications.showCollaboratorChange(
                file.original_name,
                file.last_modified_by,
                item.folder?.folder_name
              )
            }
          }
        }
      }

      // Update last check time
      this.config.set('lastCollaboratorCheck', new Date().toISOString())

      return changes
    } catch (error) {
      console.error('Failed to check collaborator changes:', error)
      return []
    }
  }

  // Start polling for collaborator changes
  private collaboratorInterval: NodeJS.Timeout | null = null

  startCollaboratorPolling(intervalMs: number = 10000): void {
    this.stopCollaboratorPolling()
    // Wave C.3: registered + skip-fire so a slow `checkCollaboratorChanges`
    // call cannot stack with itself.
    intervalRegistry.set('sync.collaborator-polling', intervalMs, async () => {
      try {
        await this.checkCollaboratorChanges()
      } catch (err) {
        console.error('[SyncEngine] collaborator polling error:', err)
      }
    })
  }

  stopCollaboratorPolling(): void {
    intervalRegistry.clear('sync.collaborator-polling')
    if (this.collaboratorInterval) {
      clearInterval(this.collaboratorInterval)
      this.collaboratorInterval = null
    }
  }

  /**
   * Find a folder by traversing path parts from root
   * e.g., ["Boards", "Personal Developments", "subfolder"] will find the folder named "subfolder" 
   * that is a child of "Personal Developments" which is a child of "Boards"
   */
  private findFolderByPath(remoteFolders: RemoteFolder[], pathParts: string[]): RemoteFolder | null {
    if (pathParts.length === 0) return null

    let currentParentId: number | null = null
    let currentFolder: RemoteFolder | null = null

    for (const part of pathParts) {
      currentFolder = remoteFolders.find(f =>
        f.name.toLowerCase() === part.toLowerCase() && f.parent_id === currentParentId
      ) || null

      if (!currentFolder) {
        return null // Path doesn't exist
      }
      currentParentId = currentFolder.id
    }

    return currentFolder
  }

  /**
   * Ensure all parent folders exist on remote before uploading a file.
   * Uses locking to prevent duplicate folder creation during bulk operations.
   * @param localFolderPath - The local path of the folder to ensure exists
   * @returns The remote folder ID, or null for root
   */
  private async ensureFolderPath(localFolderPath: string): Promise<number | null> {
    // Check for existing lock (another request is creating this folder)
    const existingLock = this.folderCreationLocks.get(localFolderPath)
    if (existingLock) {
      console.log(`[SYNC] Waiting for folder creation: ${path.basename(localFolderPath)}`)
      return existingLock  // Wait for the other request to finish
    }

    // Create and store the lock promise
    const createPromise = this._ensureFolderPathInternal(localFolderPath)
    this.folderCreationLocks.set(localFolderPath, createPromise)

    try {
      return await createPromise
    } finally {
      this.folderCreationLocks.delete(localFolderPath)
    }
  }

  /**
   * Internal implementation of ensureFolderPath - recursively creates folders
   */
  private async _ensureFolderPathInternal(localFolderPath: string): Promise<number | null> {
    const syncFolder = this.config.get('syncFolder')

    // Normalize paths for comparison
    const normalizedPath = path.normalize(localFolderPath)
    const normalizedSyncFolder = path.normalize(syncFolder)

    // #region agent log H2
    debugLog('_ensureFolderPathInternal', 'ENSURE_FOLDER_ENTRY', { localFolderPath, syncFolder, normalizedPath, normalizedSyncFolder, isSyncRoot: normalizedPath === normalizedSyncFolder }, 'H2');
    // #endregion

    // If it's the sync root, no folder needed
    if (normalizedPath === normalizedSyncFolder || !localFolderPath) {
      // #region agent log H2
      debugLog('_ensureFolderPathInternal', 'RETURNING_NULL_IS_ROOT', { localFolderPath, normalizedPath, normalizedSyncFolder }, 'H2');
      // #endregion
      return null
    }

    // Get the relative path from sync folder (e.g., "Boards/Personal Developments/subfolder")
    const relativePath = path.relative(syncFolder, localFolderPath)
    const pathParts = relativePath.split(path.sep).filter(p => p)
    const folderName = pathParts[pathParts.length - 1]

    // Fetch fresh folder list from server to ensure we have correct IDs
    // This prevents using stale folder IDs from local DB
    let remoteFolders: RemoteFolder[] = []
    try {
      const foldersResponse = await this.api!.get('/api/drive/folders/all')
      remoteFolders = foldersResponse.data.data?.folders || []
    } catch (e) {
      console.warn('[SYNC] Failed to fetch folders from server, using local DB')
    }

    // Try to find this exact folder on the server by traversing the path
    const serverFolder = this.findFolderByPath(remoteFolders, pathParts)
    if (serverFolder) {
      console.log(`[SYNC] Found existing folder on server: ${folderName} (ID: ${serverFolder.id})`)
      // Update local DB with correct info
      this.db.upsertFolder({
        remoteId: serverFolder.id,
        remoteParentId: serverFolder.parent_id,
        localPath: localFolderPath,
        name: serverFolder.name,
        syncStatus: 'synced',
      })
      return serverFolder.id
    }

    // Folder doesn't exist on server - find parent folder ID from server
    let parentId: number | null = null
    if (pathParts.length > 1) {
      const parentPathParts = pathParts.slice(0, -1)
      const parentFolder = this.findFolderByPath(remoteFolders, parentPathParts)
      if (parentFolder) {
        parentId = parentFolder.id
        console.log(`[SYNC] Found parent folder on server: ${parentFolder.name} (ID: ${parentId})`)
      } else {
        // Parent doesn't exist - need to create it first (recursion)
        const parentLocalPath = path.join(syncFolder, ...parentPathParts)
        parentId = await this.ensureFolderPath(parentLocalPath)
      }
    }

    // Check again in local DB (another parallel process might have created it)
    const recheckFolder = this.db.getFolderByLocalPath(localFolderPath)
    if (recheckFolder?.remoteId) {
      // Verify this ID exists on server
      const verifyFolder = remoteFolders.find(f => f.id === recheckFolder.remoteId)
      if (verifyFolder) {
        return recheckFolder.remoteId
      }
    }

    // Create this folder on remote
    console.log(`[SYNC] Creating folder: ${folderName} (parent ID: ${parentId ?? 'root'})`)

    // #region agent log H5
    debugLog('_ensureFolderPathInternal', 'CREATING_FOLDER_ON_REMOTE', { folderName, parentId, localFolderPath }, 'H5');
    // #endregion

    try {
      const response = await this.api!.post('/api/drive/folders', {
        name: folderName,
        parent_id: parentId,
      })

      // #region agent log H5
      debugLog('_ensureFolderPathInternal', 'FOLDER_CREATE_RESPONSE', { success: response.data.success, folder: response.data.data?.folder ? { id: response.data.data.folder.id, name: response.data.data.folder.name } : null }, 'H5');
      // #endregion

      if (response.data.success) {
        const newFolder = response.data.data?.folder
        if (newFolder) {
          // IMPORTANT: Use parent_id from API response, not local parentId variable
          // The API's findOrCreateFolder may return an existing folder with different parent
          const actualParentId = newFolder.parent_id !== undefined ? newFolder.parent_id : parentId
          this.db.upsertFolder({
            remoteId: newFolder.id,
            remoteParentId: actualParentId,
            localPath: localFolderPath,
            name: newFolder.name || folderName,
            syncStatus: 'synced',
          })
          console.log(`[SYNC] Folder created: ${folderName} (ID: ${newFolder.id}, parent: ${actualParentId})`)
          return newFolder.id
        }
      }
    } catch (error: any) {
      // Folder might already exist (created by another parallel request or already on server)
      console.warn(`[SYNC] Folder creation failed for ${folderName}: ${error.message}`)
      // #region agent log H5
      debugLog('_ensureFolderPathInternal', 'FOLDER_CREATE_ERROR', { folderName, error: error.message }, 'H5');
      // #endregion

      // Try to find it in the DB one more time (might have been created by handleLocalFolderChange)
      const finalCheck = this.db.getFolderByLocalPath(localFolderPath)
      if (finalCheck?.remoteId) {
        return finalCheck.remoteId
      }

      // Folder creation failed - try to fetch from server and find by name + parent
      // This handles cases where folder exists on server but not in local DB
      try {
        console.log(`[SYNC] Fetching folders from server to find existing: ${folderName}`)
        const foldersResponse = await this.api!.get('/api/drive/folders/all')
        const remoteFolders: RemoteFolder[] = foldersResponse.data.data?.folders || []

        // Find folder with matching name AND parent_id
        const existingFolder = remoteFolders.find(f =>
          f.name === folderName && f.parent_id === parentId
        )

        if (existingFolder) {
          console.log(`[SYNC] Found existing folder on server: ${folderName} (ID: ${existingFolder.id}, parent: ${existingFolder.parent_id})`)
          // Store in local DB with correct path
          this.db.upsertFolder({
            remoteId: existingFolder.id,
            remoteParentId: existingFolder.parent_id,
            localPath: localFolderPath,
            name: existingFolder.name,
            syncStatus: 'synced',
          })
          return existingFolder.id
        } else {
          console.warn(`[SYNC] Folder not found on server either: ${folderName} with parent ${parentId}`)
        }
      } catch (fetchError: any) {
        console.error(`[SYNC] Failed to fetch folders from server: ${fetchError.message}`)
      }
    }

    // #region agent log H1
    debugLog('_ensureFolderPathInternal', 'RETURNING_NULL_FINAL', { localFolderPath, folderName }, 'H1');
    // #endregion
    return null
  }

  private async createRemoteFile(localPath: string, relativePath: string): Promise<{ folder_id: number | null } | null> {
    try {
      const filename = path.basename(localPath)
      const parentDir = path.dirname(relativePath)
      const syncFolder = this.config.get('syncFolder')

      // #region agent log H1-H4
      debugLog('createRemoteFile', 'FILE_UPLOAD_START', { filename, localPath, relativePath, parentDir, syncFolder, parentDirCheck: { isEmpty: !parentDir, isDot: parentDir === '.' } }, 'H1-H4');
      // #endregion

      // FIXED: Ensure parent folder exists before uploading (prevents files going to root)
      let folderId: number | null = null
      if (parentDir && parentDir !== '.') {
        const parentLocalPath = path.join(syncFolder, parentDir)
        // #region agent log H1
        debugLog('createRemoteFile', 'CALLING_ENSURE_FOLDER', { parentLocalPath, parentDir }, 'H1');
        // #endregion
        folderId = await this.ensureFolderPath(parentLocalPath)
        // #region agent log H1
        debugLog('createRemoteFile', 'ENSURE_FOLDER_RESULT', { parentLocalPath, folderId, isNull: folderId === null }, 'H1');
        // #endregion

        if (!folderId) {
          console.warn(`[SYNC] Could not ensure folder path for: ${parentDir} - file may go to root`)
        }
      } else {
        // #region agent log H4
        debugLog('createRemoteFile', 'SKIPPED_FOLDER_CHECK', { parentDir, reason: 'parentDir empty or dot' }, 'H4');
        // #endregion
      }

      const fileBuffer = fs.readFileSync(localPath)
      const formData = new FormData()
      formData.append('file', new Blob([fileBuffer]), filename)
      if (folderId) {
        formData.append('folder_id', folderId.toString())
      }
      formData.append('source', 'electron') // Identify upload source for activity log

      const response = await this.apiUpload!.post('/api/drive/upload', formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      })

      if (response.data.success && response.data.data?.file) {
        const remoteFile = response.data.data.file
        // Wave B.4: streaming async checksum for newly created remote file.
        const localChecksum = await this.calculateChecksum(localPath)
        this.db.upsertFile({
          remoteId: remoteFile.id,
          remoteFolderId: folderId,
          localPath,
          filename,
          checksum: localChecksum,
          size: remoteFile.size,
          mimeType: remoteFile.mime_type,
          remoteUpdatedAt: remoteFile.updated_at,
          localUpdatedAt: fs.statSync(localPath).mtime.toISOString(),
          syncStatus: 'synced',
          // API returns share_token
          isPublic: !!remoteFile.share_token,
          publicToken: remoteFile.share_token || null,
          hasPublicLink: !!remoteFile.share_token,
          shareLink: null,
        })
        this.db.logSync('upload', 'file', remoteFile.id, filename, 'success', 'New file')
        // Note: Backend already creates sync event, no need to duplicate
        return { folder_id: folderId }
      }
      return null
    } catch (error: any) {
      console.error(`Failed to create remote file:`, error)
      this.db.logSync('upload', 'file', null, path.basename(localPath), 'error', error.message)
      return null
    }
  }

  // Shared folder-client mapping fetch with TTL cache
  private async getFolderClientMapping(): Promise<Record<string, { client_id: number; client_name: string }>> {
    const now = Date.now()
    if (now - this.folderClientMappingLastFetch < SyncEngine.FOLDER_MAPPING_TTL && Object.keys(this.folderClientMappingCache).length > 0) {
      return this.folderClientMappingCache
    }
    if (!this.api) return this.folderClientMappingCache
    try {
      const res = await this.api.get('/api/clients/folder-mapping')
      if (res.data.success) {
        this.folderClientMappingCache = res.data.data?.mapping || {}
        this.folderClientMappingLastFetch = now
      }
    } catch {
      // Keep stale cache on failure
    }
    return this.folderClientMappingCache
  }

  async getFiles(folderId?: number): Promise<{ files: any[], folders: any[] }> {
    if (this.api) {
      try {
        // Fetch drive listing and folder-client mapping in parallel
        const [response, folderClientMapping] = await Promise.all([
          this.api.get('/api/drive', { params: { folder_id: folderId ?? '' } }),
          this.getFolderClientMapping(),
        ])

        const data = response.data?.data || {}

        const files = (data.files || []).map((f: any) => {
          const clientInfo = f.folder_id ? folderClientMapping[String(f.folder_id)] : null
          return {
            remoteId: f.id,
            remoteFolderId: f.folder_id,
            filename: f.original_name || f.filename,
            size: f.size,
            mimeType: f.mime_type,
            remoteUpdatedAt: f.updated_at,
            isPublic: !!f.share_token,
            hasPublicLink: !!f.share_token,
            publicToken: f.share_token,
            clientId: clientInfo?.client_id || null,
            clientName: clientInfo?.client_name || null,
          }
        })

        const folders = (data.folders || []).map((f: any) => {
          const clientInfo = folderClientMapping[String(f.id)]
          return {
            remoteId: f.id,
            remoteParentId: f.parent_id,
            name: f.name,
            color: f.color,
            isPublic: !!f.share_token,
            hasPublicLink: !!f.share_token,
            publicToken: f.share_token,
            clientId: clientInfo?.client_id || null,
            clientName: clientInfo?.client_name || null,
          }
        })

        return { files, folders }
      } catch (error) {
        console.error('[SyncEngine] getFiles API error, falling back to local DB:', error)
      }
    }
    const files = this.db.getFilesByFolder(folderId ?? null)
    const folders = this.db.getFoldersByParent(folderId ?? null)
    return { files, folders }
  }

  async getQuota(): Promise<{ used: number, total: number | null, percentage: number } | null> {
    if (!this.api) return null

    try {
      // Fetch quota from the API - the drive list endpoint returns quota
      const response = await this.api.get('/api/drive')
      const quota = response.data.data?.quota

      if (quota) {
        return {
          used: quota.used || 0,
          total: quota.total || null, // null = unlimited
          percentage: quota.percentage || 0
        }
      }
      return null
    } catch (error) {
      console.error('Failed to fetch quota:', error)
      return null
    }
  }

  // Get ALL folders from the server (for folder tree in sidebar)
  async getAllFolders(): Promise<any[]> {
    if (!this.api) {
      return this.db.getAllFolders()
    }

    try {
      // Fetch folders and client mapping in parallel (mapping uses TTL cache)
      const [response, folderClientMapping] = await Promise.all([
        this.api.get('/api/drive/folders/all'),
        this.getFolderClientMapping(),
      ])

      if (response.data?.success) {
        const folders = response.data.data?.folders || []
        return folders.map((f: any) => {
          const clientMapping = folderClientMapping[f.id?.toString()]
          return {
            id: f.id,
            remoteId: f.id,
            remoteParentId: f.parent_id || null,
            name: f.name,
            color: f.color || null,
            syncStatus: 'synced',
            lastSyncAt: f.updated_at,
            clientId: clientMapping?.client_id || null,
            clientName: clientMapping?.client_name || null,
          }
        })
      }

      return this.db.getAllFolders()
    } catch (error) {
      console.error('[SyncEngine] getAllFolders error:', error)
      return this.db.getAllFolders()
    }
  }

  async getTrash(): Promise<{ files: any[], folders: any[] }> {
    if (!this.api) {
      console.log('getTrash: No API instance')
      return { files: [], folders: [] }
    }

    try {
      console.log('Fetching trash from API...')
      const response = await this.api.get('/api/drive/trash')
      console.log('Trash API response:', JSON.stringify(response.data, null, 2))
      const data = response.data.data || response.data || {}
      console.log('Trash data:', { files: data.files?.length || 0, folders: data.folders?.length || 0 })
      return {
        files: data.files || [],
        folders: data.folders || []
      }
    } catch (error: any) {
      console.error('Failed to fetch trash:', error.message || error)
      return { files: [], folders: [] }
    }
  }

  /**
   * Wave B.4: streaming MD5.
   *
   * The previous implementation read the entire file into a Buffer via
   * `fs.readFileSync` and called `update(buffer)`. For a 100 MB file this
   * stalls the main thread for several seconds *and* allocates a 100 MB
   * Buffer. Streaming via `fs.createReadStream` reads in 64 KB chunks
   * concurrently with the hash work, lets the OS prefetch, and never holds
   * the full file in memory.
   *
   * Returns '' on failure (preserves the legacy behaviour so callers don't
   * need to add try/catch around every checksum lookup).
   *
   * Future direction (F.1): replace MD5 with xxh3 quick + full fingerprints.
   */
  private async calculateChecksum(filePath: string): Promise<string> {
    return new Promise<string>((resolve) => {
      try {
        this.hashRecomputes += 1
        const hash = crypto.createHash('md5')
        const stream = fs.createReadStream(filePath, { highWaterMark: 64 * 1024 })
        stream.on('data', (chunk) => hash.update(chunk))
        stream.on('end', () => resolve(hash.digest('hex')))
        stream.on('error', () => resolve(''))
      } catch {
        resolve('')
      }
    })
  }

  // Wave A.7 — hash skip metrics
  private hashRecomputes = 0
  private hashSkips = 0

  /**
   * Wave A.7: hash fast-path.
   *
   * In steady state, most files in a sync cycle haven't changed since the last
   * cycle. Recomputing MD5 against every file (each `fs.readFileSync` of the
   * full file) is the dominant CPU cost on large folders. This helper returns
   * the cached checksum when (size, mtime, prior_hash) all match — saving the
   * full-file read entirely.
   *
   * Inputs:
   *   - localPath: file path to check.
   *   - dbFile:    the existing DB record, if any. Provides the prior
   *                size / mtime / checksum.
   *   - statHint:  optional pre-fetched fs.Stats. Avoids a redundant `statSync`
   *                if the caller already has one.
   *
   * Returns the existing checksum when the file is unchanged; otherwise
   * recomputes via `calculateChecksum`.
   *
   * Future direction (F.1): replace the MD5 fall-through with xxh3 quick + full
   * fingerprints; this helper's API stays stable.
   */
  private async calculateChecksumIfChanged(
    localPath: string,
    dbFile: { size?: number; localUpdatedAt?: string; checksum?: string } | null | undefined,
    statHint?: fs.Stats
  ): Promise<string> {
    if (!dbFile?.checksum) {
      return this.calculateChecksum(localPath)
    }
    let stat: fs.Stats | null = statHint || null
    if (!stat) {
      try {
        stat = fs.statSync(localPath)
      } catch {
        return this.calculateChecksum(localPath)
      }
    }
    const sameSize = typeof dbFile.size === 'number' && dbFile.size === stat.size
    const sameMtime = typeof dbFile.localUpdatedAt === 'string'
      && dbFile.localUpdatedAt === stat.mtime.toISOString()
    if (sameSize && sameMtime) {
      this.hashSkips += 1
      return dbFile.checksum
    }
    return this.calculateChecksum(localPath)
  }

  /**
   * Hash skip metrics for the perf HUD (Wave A.7).
   */
  getHashSkipMetrics(): { recomputes: number; skips: number; rate: number } {
    const total = this.hashRecomputes + this.hashSkips
    return {
      recomputes: this.hashRecomputes,
      skips: this.hashSkips,
      rate: total === 0 ? 0 : this.hashSkips / total,
    }
  }

  // ========================
  // FILE EDITING STATUS
  // ========================

  /**
   * Called by FileWatcher when a lock file is created/deleted
   * Reports editing status to the backend API
   */
  async setFileEditingStatus(filename: string, relativeFolderPath: string | null, isEditing: boolean): Promise<void> {
    if (!this.api) return

    // Get the folder ID from the relative path
    let folderId: number | null = null
    if (relativeFolderPath) {
      const folder = this.db.getFolderByPath(relativeFolderPath)
      folderId = folder?.remoteId || null
    }

    const key = `${relativeFolderPath || 'root'}/${filename}`

    if (isEditing) {
      // Track locally
      this.activeEditing.set(key, { filename, folderId })

      // Emit to renderer immediately
      this.emitSelfEditingUpdate()

      // Start heartbeat if not running
      this.startEditingHeartbeat()

      // Report to backend
      try {
        await this.api.post('/api/drive/editing-status', {
          filename,
          folder_id: folderId,
          is_editing: true
        })
        console.log(`[EDITING] Started editing: ${filename}`)
      } catch (error: any) {
        console.error('[EDITING] Failed to report editing status:', error.message)
      }
    } else {
      // Remove from tracking
      this.activeEditing.delete(key)

      // Emit to renderer immediately
      this.emitSelfEditingUpdate()

      // Stop heartbeat if no more active editing
      if (this.activeEditing.size === 0) {
        this.stopEditingHeartbeat()
      }

      // Clear from backend
      try {
        await this.api.delete('/api/drive/editing-status', {
          data: { filename, folder_id: folderId }
        })
        console.log(`[EDITING] Stopped editing: ${filename}`)
      } catch (error: any) {
        console.error('[EDITING] Failed to clear editing status:', error.message)
      }
    }
  }

  /**
   * Start periodic heartbeat to keep editing sessions alive.
   *
   * Wave C.3: registered with the IntervalRegistry; the tick is a no-op
   * when there are no active editing sessions, so the timer keeps existing
   * (visibility into "no work happening") without doing pointless API calls.
   */
  private startEditingHeartbeat(): void {
    if (this.editingHeartbeatInterval) return

    intervalRegistry.set('sync.editing-heartbeat', 120_000, async () => {
      if (!this.api || this.activeEditing.size === 0) return

      for (const [_, { filename, folderId }] of this.activeEditing) {
        try {
          await this.api.post('/api/drive/editing-status/heartbeat', {
            filename,
            folder_id: folderId
          })
        } catch (error) {
          // Silent fail - heartbeat is not critical
        }
      }
    })
  }

  private stopEditingHeartbeat(): void {
    intervalRegistry.clear('sync.editing-heartbeat')
    if (this.editingHeartbeatInterval) {
      clearInterval(this.editingHeartbeatInterval)
      this.editingHeartbeatInterval = null
    }
  }

  /**
   * Start polling for other editors in shared folders.
   *
   * Wave C.3: routed through IntervalRegistry with skip-fire so an
   * occasional slow `pollOtherEditors` (network issues) cannot stack with
   * itself.
   */
  startEditingStatusPolling(): void {
    if (this.editingStatusPollInterval) return

    intervalRegistry.set('sync.editing-status-poll', 10_000, async () => {
      try {
        await this.pollOtherEditors()
      } catch (err) {
        console.error('[SyncEngine] editing status poll error:', err)
      }
    })

    // Initial poll
    this.pollOtherEditors()
  }

  /**
   * Stop polling for editing status
   */
  stopEditingStatusPolling(): void {
    intervalRegistry.clear('sync.editing-status-poll')
    if (this.editingStatusPollInterval) {
      clearInterval(this.editingStatusPollInterval)
      this.editingStatusPollInterval = null
    }
  }

  /**
   * Fetch who else is editing files in shared folders
   */
  private async pollOtherEditors(): Promise<void> {
    if (!this.api) return

    try {
      const response = await this.api.get('/api/drive/editing-status/shared')
      const data = response.data.data || response.data
      const editors: EditingStatus[] = data.editors || []

      // Check for new editors and notify
      for (const editor of editors) {
        const key = `${editor.folder_id || 'root'}/${editor.filename}`
        const wasEditing = this.otherEditors.some(
          e => e.filename === editor.filename && e.folder_id === editor.folder_id
        )

        if (!wasEditing) {
          // New editor started - show notification
          this.notifications.showFileEditing(
            editor.filename,
            editor.editor_email,
            editor.folder_name || 'My Drive'
          )

          // Emit event to renderer
          this.emitEditingUpdate(editors)
        }
      }

      this.otherEditors = editors
    } catch (error: any) {
      console.error('[EDITING] Failed to poll editors:', error.message)
    }
  }

  /**
   * Get current editing status for a specific folder
   */
  async getEditingStatus(folderId?: number | null): Promise<EditingStatus[]> {
    if (!this.api) return []

    try {
      const params = folderId !== undefined ? { folder_id: folderId } : {}
      const response = await this.api.get('/api/drive/editing-status', { params })
      const data = response.data.data || response.data
      return data.editors || []
    } catch (error) {
      console.error('[EDITING] Failed to get editing status:', error)
      return []
    }
  }

  /**
   * Get cached list of other editors
   */
  getOtherEditors(): EditingStatus[] {
    return this.otherEditors
  }

  /**
   * Get files currently being edited by THIS user (self)
   */
  getSelfEditing(): Array<{ filename: string; folderId: number | null }> {
    return Array.from(this.activeEditing.values())
  }

  /**
   * Emit self-editing status to renderer
   */
  private emitSelfEditingUpdate(): void {
    const editing = this.getSelfEditing()
    console.log('[EDITING] Emitting to renderer:', editing)
    this.mainWindow?.webContents.send('self-editing-update', editing)
  }

  /**
   * Emit editing update event to renderer
   */
  private emitEditingUpdate(editors: EditingStatus[]): void {
    // Will be connected to IPC in index.ts
    if ((global as any).mainWindow) {
      (global as any).mainWindow.webContents.send('editing-update', editors)
    }
  }

  /**
   * Emit files-changed event to renderer for instant UI refresh
   */
  emitFilesChanged(folderId?: number | null): void {
    console.log('[SYNC] Emitting files-changed to renderer, folderId:', folderId)
    this.mainWindow?.webContents.send('files-changed', { folderId })
  }

  /**
   * Cleanup on logout/disconnect
   */
  async clearAllEditingStatus(): Promise<void> {
    // Clear all local editing sessions
    for (const [_, { filename, folderId }] of this.activeEditing) {
      try {
        await this.api?.delete('/api/drive/editing-status', {
          data: { filename, folder_id: folderId }
        })
      } catch {
        // Silent fail
      }
    }

    this.activeEditing.clear()
    this.stopEditingHeartbeat()
    this.stopEditingStatusPolling()
  }

  /**
   * Clear editing status for a specific file (user manually stopped)
   */
  async clearSelfEditing(filename: string): Promise<boolean> {
    console.log(`[EDITING] clearSelfEditing called for: ${filename}`)

    // Find the entry by filename
    for (const [key, value] of this.activeEditing) {
      if (value.filename === filename || key.endsWith(filename)) {
        console.log(`[EDITING] Found editing entry to clear: ${key}`)

        // Clear from server
        try {
          await this.api?.delete('/api/drive/editing-status', {
            data: { filename: value.filename, folder_id: value.folderId }
          })
          console.log(`[EDITING] Cleared editing status from server for: ${value.filename}`)
        } catch (error: any) {
          console.error(`[EDITING] Failed to clear editing status from server:`, error?.message)
        }

        // Remove from local tracking
        this.activeEditing.delete(key)

        // Emit update to renderer
        this.emitSelfEditingUpdate()

        return true
      }
    }

    console.log(`[EDITING] File not found in activeEditing: ${filename}`)
    return false
  }

  /**
   * Get remote folder ID for a relative path
   * Used for time tracking to associate documents with clients
   */
  getFolderIdForPath(relativePath: string): number | null {
    if (!relativePath) return null

    const folder = this.db.getFolderByPath(relativePath)
    return folder?.remoteId || null
  }

  // ========================
  // URL MAPPINGS (Website Time Tracking)
  // ========================

  /**
   * Load URL mappings from backend for website time tracking
   * Returns array of domains mapped to boards/clients
   */
  async loadUrlMappings(): Promise<any[]> {
    if (!this.api) {
      console.log('[SyncEngine] No API instance for URL mappings')
      return []
    }

    try {
      console.log('[SyncEngine] Loading URL mappings from API...')
      const response = await this.api.get('/api/boards/url-mappings')
      console.log('[SyncEngine] URL mappings API response:', response.status)
      console.log('[SyncEngine] URL mappings API data:', JSON.stringify(response.data))

      if (response.data?.success) {
        const mappings = response.data.data?.mappings || []
        console.log(`[SyncEngine] Loaded ${mappings.length} URL mappings`)
        if (mappings.length > 0) {
          console.log('[SyncEngine] First mapping:', JSON.stringify(mappings[0]))
        }
        return mappings
      } else {
        console.log('[SyncEngine] URL mappings API returned success=false:', response.data?.message)
        return []
      }
    } catch (error: any) {
      console.error('[SyncEngine] Failed to load URL mappings:', error.message)
      if (error.response) {
        console.error('[SyncEngine] API error response:', error.response.status, error.response.data)
      }
      return []
    }
  }
}

