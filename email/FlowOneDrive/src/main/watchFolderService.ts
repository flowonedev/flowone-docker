import axios, { AxiosInstance } from 'axios'
import chokidar, { FSWatcher } from 'chokidar'
import path from 'path'
import { ConfigStore } from './config'
import { DocumentTimeTracker } from './documentTimeTracker'
import { EditingDetector, WatchFolderContext } from './editingDetector'
import { existsSafe } from './sync/fsRemoteSafe'

export interface WatchFolderConfig {
  id: number
  name: string
  folderPath: string
  resolvedPath: string
  clientId: number
  clientName: string
  boardId: number | null
  boardName: string | null
  cardId: number | null
  resolved: boolean
  status: 'watching' | 'not_found' | 'pending'
}

interface ActiveWatcher {
  folderId: number
  fullPath: string
  watcher: FSWatcher
  config: WatchFolderConfig
}

const LOCK_FILE_PATTERNS = {
  office: /^~\$/,
  libreoffice: /^\.~lock\..+#$/,
  autocad: /\.(dwl2?)$/i,
}

function isLockFile(filename: string): boolean {
  return Object.values(LOCK_FILE_PATTERNS).some(p => p.test(filename))
}

function resolveRealFilename(lockFilename: string): string | null {
  if (LOCK_FILE_PATTERNS.office.test(lockFilename)) {
    return lockFilename.replace(/^~\$/, '')
  }
  if (LOCK_FILE_PATTERNS.libreoffice.test(lockFilename)) {
    return lockFilename.replace(/^\.~lock\./, '').replace(/#$/, '')
  }
  if (LOCK_FILE_PATTERNS.autocad.test(lockFilename)) {
    return lockFilename.replace(/\.dwl2?$/i, '')
  }
  return null
}

export class WatchFolderService {
  private config: ConfigStore
  private timeTracker: DocumentTimeTracker
  private editingDetector: EditingDetector
  private api: AxiosInstance | null = null

  private folders: WatchFolderConfig[] = []
  private watchers: ActiveWatcher[] = []
  private refreshTimer: NodeJS.Timeout | null = null

  private readonly REFRESH_INTERVAL_MS = 5 * 60 * 1000
  // Debounce / coalesce. Without these, every Settings-tab open triggered a
  // full network fetch + synchronous fs.existsSync sweep on the main thread,
  // which froze the UI for several seconds when any watch-folder path was on
  // an unreachable NAS share.
  private readonly MIN_REFRESH_INTERVAL_MS = 30 * 1000
  private lastFetchAt = 0
  private refreshInFlight: Promise<void> | null = null
  // Fingerprint of the last applied folder set. We reuse running watchers
  // unchanged when the server returns the same list — re-creating chokidar
  // watchers on every refresh did a depth-10 stat-walk per folder.
  private watchersSignature = ''

  constructor(
    config: ConfigStore,
    timeTracker: DocumentTimeTracker,
    editingDetector: EditingDetector
  ) {
    this.config = config
    this.timeTracker = timeTracker
    this.editingDetector = editingDetector
  }

  async start(): Promise<void> {
    console.log('[WatchFolders] Starting watch folder service')

    this.initApi()
    if (!this.api) {
      console.log('[WatchFolders] No API configured, loading from cache')
      this.loadFromCache()
      return
    }

    await this.fetchAndWatch()

    this.refreshTimer = setInterval(() => {
      this.fetchAndWatch()
    }, this.REFRESH_INTERVAL_MS)
  }

  stop(): void {
    console.log('[WatchFolders] Stopping watch folder service')
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer)
      this.refreshTimer = null
    }
    this.stopAllWatchers()
  }

  private initApi(): void {
    const { getAuthToken, getSessionToken } = require('./secureStorage')
    const apiUrl = this.config.get('apiUrl')
    const authToken = getAuthToken()

    if (!apiUrl || !authToken) {
      this.api = null
      return
    }

    const headers: Record<string, string> = {
      Authorization: `Bearer ${authToken}`,
      'Content-Type': 'application/json',
    }
    const sessionToken = getSessionToken()
    if (sessionToken) headers['X-Session-Token'] = sessionToken

    // Wave A.5: 30 s timeout — these are short JSON requests for fetching
    // watch-folder lists / overrides.
    this.api = axios.create({ baseURL: apiUrl, headers, timeout: 30_000 })
  }

  setCredentials(apiUrl: string, authToken: string): void {
    const { getSessionToken } = require('./secureStorage')
    const headers: Record<string, string> = {
      Authorization: `Bearer ${authToken}`,
      'Content-Type': 'application/json',
    }
    const sessionToken = getSessionToken()
    if (sessionToken) headers['X-Session-Token'] = sessionToken

    this.api = axios.create({ baseURL: apiUrl, headers, timeout: 30_000 })
  }

  // ========== FETCH & WATCH ==========

  private async fetchAndWatch(): Promise<void> {
    try {
      await this.fetchFolders()
      this.lastFetchAt = Date.now()
      this.saveToCache()
      await this.startWatchers()
    } catch (err: any) {
      console.error('[WatchFolders] fetchAndWatch error:', err?.message)
    }
  }

  private async fetchFolders(): Promise<void> {
    if (!this.api) return
    try {
      const res = await this.api.get('/api/watch-folders/resolved')
      if (res.data.success) {
        this.folders = (res.data.data || []).map((f: any) => ({
          id: f.id,
          name: f.name,
          folderPath: f.folder_path,
          resolvedPath: f.resolved_path || f.folder_path,
          clientId: f.client_id,
          clientName: f.client_name || '',
          boardId: f.board_id || null,
          boardName: f.board_name || null,
          cardId: f.card_id || null,
          resolved: false,
          status: 'pending' as const,
        }))
        console.log(`[WatchFolders] Fetched ${this.folders.length} resolved watch folders`)
      }
    } catch (err: any) {
      console.error('[WatchFolders] Failed to fetch folders:', err?.message)
    }
  }

  // ========== WATCHERS ==========

  private async startWatchers(): Promise<void> {
    // Validate paths in parallel and with timeouts so a dead NAS share can't
    // block the main thread (each fs.existsSync on a UNC path used to take
    // up to 30 s to time out — this is the freeze on Settings open).
    const validations = await Promise.all(
      this.folders.map(async (folder) => {
        const fullPath = folder.resolvedPath
        const ok = await existsSafe(fullPath, 3_000).catch(() => false)
        return { folder, ok }
      })
    )

    const validFolders: WatchFolderConfig[] = []
    for (const { folder, ok } of validations) {
      if (!ok) {
        console.warn(`[WatchFolders] Removing stale folder (path gone): ${folder.resolvedPath} (${folder.name})`)
        folder.resolved = false
        folder.status = 'not_found'
      } else {
        folder.resolved = true
        folder.status = 'watching'
        validFolders.push(folder)
      }
    }

    // Reuse running watchers when the watched set hasn't changed. Chokidar's
    // initial-scan walks each tree to depth 10 — doing that on every Settings
    // click was wasteful and could thrash the disk.
    const signature = validFolders
      .map(f => `${f.id}:${f.resolvedPath}`)
      .sort()
      .join('|')

    const watchFolderPaths = new Map<string, WatchFolderContext>()
    for (const folder of validFolders) {
      watchFolderPaths.set(folder.resolvedPath, {
        watchFolderId: folder.id,
        clientId: folder.clientId,
        clientName: folder.clientName,
        boardId: folder.boardId,
        boardName: folder.boardName,
        cardId: folder.cardId,
        profileName: null,
      })
    }

    if (signature === this.watchersSignature && this.watchers.length > 0) {
      this.editingDetector.registerWatchFolderPaths(watchFolderPaths)
      return
    }

    this.stopAllWatchers()

    for (const folder of validFolders) {
      const fullPath = folder.resolvedPath

      const usePolling = process.platform === 'darwin' || fullPath.startsWith('\\\\') || fullPath.startsWith('//')
      const watcher = chokidar.watch(fullPath, {
        ignored: /(^|[\/\\])\../,
        persistent: true,
        ignoreInitial: true,
        usePolling,
        interval: usePolling ? 3000 : undefined,
        depth: 10,
      })

      watcher.on('add', (filePath) => this.onFileEvent(filePath, 'add', folder))
      watcher.on('unlink', (filePath) => this.onFileEvent(filePath, 'unlink', folder))

      this.watchers.push({ folderId: folder.id, fullPath, watcher, config: folder })
      console.log(`[WatchFolders] Watching: ${fullPath} (${folder.name})`)
    }

    this.watchersSignature = signature
    this.editingDetector.registerWatchFolderPaths(watchFolderPaths)
    console.log(`[WatchFolders] ${this.watchers.length} active watchers`)
  }

  private stopAllWatchers(): void {
    for (const w of this.watchers) {
      w.watcher.close().catch(() => {})
    }
    this.watchers = []
  }

  private onFileEvent(filePath: string, eventType: 'add' | 'unlink', folder: WatchFolderConfig): void {
    const filename = path.basename(filePath)
    if (!isLockFile(filename)) return

    const realFilename = resolveRealFilename(filename)
    if (!realFilename) return

    const realFilePath = path.join(path.dirname(filePath), realFilename)

    if (eventType === 'add') {
      console.log(`[WatchFolders] Lock detected: ${realFilename} in ${folder.name}`)
      this.editingDetector.onWatchFolderFileOpened(
        realFilePath,
        'Office',
        {
          watchFolderId: folder.id,
          clientId: folder.clientId,
          clientName: folder.clientName,
          boardId: folder.boardId,
          boardName: folder.boardName,
          cardId: folder.cardId,
          profileName: null,
        }
      )
    } else {
      console.log(`[WatchFolders] Lock removed: ${realFilename} in ${folder.name}`)
      this.editingDetector.onLockFileRemoved(realFilePath)
    }
  }

  // ========== REPORT ACTIVITY ==========

  async reportFileActivity(session: {
    filePath: string
    filename: string
    durationSeconds: number
    watchFolderId: number
    clientId: number
    boardId: number | null
    cardId: number | null
    profileName: string | null
  }): Promise<void> {
    if (!this.api) return
    if (session.durationSeconds < 5) return

    const watchFolder = this.watchers.find(w => w.folderId === session.watchFolderId)
    const relativePath = watchFolder
      ? path.relative(watchFolder.fullPath, path.dirname(session.filePath))
      : null

    try {
      await this.api.post('/api/watch-folders/file-activity', {
        watch_folder_id: session.watchFolderId,
        file_name: session.filename,
        file_path: relativePath ? path.join(relativePath, session.filename) : session.filename,
        duration_seconds: session.durationSeconds,
        client_id: session.clientId,
        board_id: session.boardId,
        card_id: session.cardId,
      })
      console.log(`[WatchFolders] Reported file activity: ${session.filename} (${session.durationSeconds}s)`)
    } catch (err: any) {
      console.error('[WatchFolders] Failed to report file activity:', err?.message)
    }
  }

  // ========== CREATE FROM BROWSE ==========

  // ========== CACHE ==========

  private saveToCache(): void {
    this.config.set('watchFolders', {
      cachedFolders: this.folders,
    })
  }

  private loadFromCache(): void {
    const cached = this.config.get('watchFolders')
    if (!cached) return

    this.folders = (cached.cachedFolders || []).map((f: any) => ({
      id: f.id,
      name: f.name,
      folderPath: f.folderPath,
      resolvedPath: f.resolvedPath || f.folderPath,
      clientId: f.clientId,
      clientName: f.clientName || '',
      boardId: f.boardId ?? null,
      boardName: f.boardName ?? null,
      cardId: f.cardId ?? null,
      resolved: false,
      status: 'pending' as const,
    }))
    // Fire-and-forget — caller doesn't need to wait for path validation.
    void this.startWatchers().catch(err => {
      console.error('[WatchFolders] startWatchers from cache failed:', err?.message)
    })
  }

  // ========== MANAGE (change path / remove) ==========

  /**
   * Point a watch folder at a different local directory. Updates the
   * canonical folder_path on the server, then force-refreshes so watchers
   * re-attach to the new path immediately.
   */
  async updateFolderPath(id: number, folderPath: string): Promise<{ success: boolean; error?: string }> {
    if (!this.api) return { success: false, error: 'Not connected' }
    try {
      const res = await this.api.put(`/api/watch-folders/${id}`, { folder_path: folderPath })
      if (!res.data?.success) {
        return { success: false, error: res.data?.error || 'Update failed' }
      }
      await this.refresh(true)
      return { success: true }
    } catch (err: any) {
      const msg = err?.response?.data?.error || err?.message || 'Update failed'
      console.error(`[WatchFolders] updateFolderPath(${id}) failed:`, msg)
      return { success: false, error: msg }
    }
  }

  /**
   * Remove a watch folder (server-side delete — same as removing it in the
   * cloud app). Local files are never touched; only the watcher goes away.
   */
  async deleteFolder(id: number): Promise<{ success: boolean; error?: string }> {
    if (!this.api) return { success: false, error: 'Not connected' }
    try {
      const res = await this.api.delete(`/api/watch-folders/${id}`)
      if (!res.data?.success) {
        return { success: false, error: res.data?.error || 'Delete failed' }
      }
      await this.refresh(true)
      return { success: true }
    } catch (err: any) {
      const msg = err?.response?.data?.error || err?.message || 'Delete failed'
      console.error(`[WatchFolders] deleteFolder(${id}) failed:`, msg)
      return { success: false, error: msg }
    }
  }

  // ========== PUBLIC GETTERS ==========

  getAll(): WatchFolderConfig[] {
    return this.folders
  }

  getById(id: number): WatchFolderConfig | undefined {
    return this.folders.find(f => f.id === id)
  }

  async refresh(force: boolean = false): Promise<void> {
    // Coalesce concurrent callers onto the same in-flight refresh. Without
    // this, opening Settings twice in quick succession used to fire two
    // overlapping network + fs.existsSync sweeps.
    if (this.refreshInFlight) {
      return this.refreshInFlight
    }

    // Throttle: skip the network fetch if we just did one. Watchers and the
    // 5-min background timer keep state fresh; this only protects against
    // tab-flip storms.
    const sinceLast = Date.now() - this.lastFetchAt
    if (!force && this.lastFetchAt > 0 && sinceLast < this.MIN_REFRESH_INTERVAL_MS) {
      return
    }

    this.refreshInFlight = this.fetchAndWatch().finally(() => {
      this.refreshInFlight = null
    })
    return this.refreshInFlight
  }
}
