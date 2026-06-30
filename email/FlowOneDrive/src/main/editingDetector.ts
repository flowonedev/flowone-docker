import { EventEmitter } from 'events'
import path from 'path'
import { HandleMonitor } from './handleMonitor'
import { WindowMonitor } from './windowMonitor'
import { DocumentTimeTracker } from './documentTimeTracker'
import { SyncEngine } from './syncEngine'

/**
 * Editing Detector
 * 
 * Central coordinator that aggregates all detection sources:
 * 1. Lock files (Office, LibreOffice, AutoCAD) - from FileWatcher
 * 2. File handle monitoring (Windows only) - HandleMonitor
 * 3. Active window title (supplementary) - WindowMonitor
 * 
 * Handles deduplication to ensure we don't double-count when multiple
 * detection methods report the same file.
 */

export interface WatchFolderContext {
  watchFolderId: number
  clientId: number
  clientName: string
  boardId: number | null
  boardName: string | null
  cardId: number | null
  profileName: string | null
}

interface EditingSession {
  filePath: string
  filename: string
  folderId: number | null
  processName: string
  source: 'lockfile' | 'handle' | 'window'
  startTime: number
  lastActivity: number
  activeSources: Set<'lockfile' | 'handle' | 'window'>
  watchFolderContext?: WatchFolderContext | null
}

export interface EditingDetectorConfig {
  handlePollInterval: number
  windowPollInterval: number
  inactivityTimeout: number
  enableHandleMonitor: boolean
  enableWindowMonitor: boolean
}

const DEFAULT_CONFIG: EditingDetectorConfig = {
  handlePollInterval: 3000,    // 3 seconds
  windowPollInterval: 2000,    // 2 seconds
  inactivityTimeout: 300000,   // 5 minutes
  enableHandleMonitor: true,
  enableWindowMonitor: true,
}

/**
 * Extensions and patterns to ignore when tracking file edits
 * These are typically temp files, logs, and system files created by applications
 */
const IGNORED_EXTENSIONS = new Set([
  '.log',      // Log files (Adobe creates these)
  '.tmp',      // Temp files
  '.temp',     // Temp files
  '.bak',      // Backup files
  '.swp',      // Vim swap files
  '.swo',      // Vim swap files
  '.lock',     // Lock files
  '.lck',      // Lock files
  '.db',       // Database files (like Thumbs.db)
  '.ini',      // Config files
  '.dat',      // Data files
  '.cache',    // Cache files
])

/**
 * File name patterns to ignore
 */
const IGNORED_PATTERNS = [
  /^~\$/,                           // Office temp files (~$document.docx)
  /^\./,                            // Hidden files (.DS_Store, etc.)
  /^~.*\.tmp$/i,                    // Office temp files
  /\.ai~$/i,                        // Illustrator backup files
  /-\d{4}-\d{2}-\d{2}\.log$/i,     // Dated log files (gude-2026-01-16.log)
  /^AI_[A-Z0-9]+$/i,               // Adobe Illustrator temp files
  /^Recovery/i,                     // Recovery files
  /\(Recovered\)/i,                // Also skip "[Recovered]" files from tracking as logs
  /^Thumbs\.db$/i,                 // Windows thumbnail cache
  /^desktop\.ini$/i,               // Windows folder settings
  /^\.DS_Store$/i,                 // macOS folder settings
]

/**
 * Check if a file should be ignored for time tracking
 */
function shouldIgnoreFile(filename: string): boolean {
  // Check extension
  const ext = path.extname(filename).toLowerCase()
  if (IGNORED_EXTENSIONS.has(ext)) {
    return true
  }

  // Check filename patterns
  for (const pattern of IGNORED_PATTERNS) {
    if (pattern.test(filename)) {
      return true
    }
  }

  return false
}

export class EditingDetector extends EventEmitter {
  private syncFolder: string
  private config: EditingDetectorConfig
  private handleMonitor: HandleMonitor | null = null
  private windowMonitor: WindowMonitor | null = null
  private timeTracker: DocumentTimeTracker | null = null
  private syncEngine: SyncEngine | null = null

  // Active editing sessions - keyed by normalized file path
  private sessions: Map<string, EditingSession> = new Map()

  // Watch folder path -> context mapping for handle/window monitor events
  private watchFolderPathMap: Map<string, WatchFolderContext> = new Map()

  // Inactivity checker
  private inactivityTimer: NodeJS.Timeout | null = null

  // Grace period for window unfocus (don't stop tracking immediately when switching apps)
  private unfocusGraceTimers: Map<string, NodeJS.Timeout> = new Map()
  private readonly UNFOCUS_GRACE_PERIOD = 10000 // 10 seconds grace when switching apps

  // Callback fired when a watch folder editing session ends
  onWatchSessionEnd: ((session: { filePath: string; filename: string; durationSeconds: number; watchFolderId: number; clientId: number; boardId: number | null; cardId: number | null; profileName: string | null }) => void) | null = null

  constructor(
    syncFolder: string,
    config: Partial<EditingDetectorConfig> = {}
  ) {
    super()
    this.syncFolder = syncFolder
    this.config = { ...DEFAULT_CONFIG, ...config }
  }

  /**
   * Set the time tracker instance (called from index.ts)
   */
  setTimeTracker(tracker: DocumentTimeTracker): void {
    this.timeTracker = tracker
  }

  /**
   * Set the sync engine instance (called from index.ts)
   */
  setSyncEngine(engine: SyncEngine): void {
    this.syncEngine = engine
  }

  /**
   * Start all monitors
   */
  start(): void {
    console.log('[EditingDetector] Starting editing detection')
    console.log(`[EditingDetector] Config:`, this.config)

    // Start handle monitor (Windows only)
    if (this.config.enableHandleMonitor && process.platform === 'win32') {
      this.handleMonitor = new HandleMonitor(this.syncFolder, this.config.handlePollInterval)

      this.handleMonitor.on('file-opened', (filePath, processName) => {
        this.handleFileOpened(filePath, processName, 'handle')
      })

      this.handleMonitor.on('file-closed', (filePath, processName) => {
        this.handleFileClosed(filePath, 'handle')
      })

      this.handleMonitor.start()
    }

    // Start window monitor
    if (this.config.enableWindowMonitor) {
      this.windowMonitor = new WindowMonitor(this.syncFolder, this.config.windowPollInterval)

      this.windowMonitor.on('file-focused', (filePath, processName) => {
        this.handleFileOpened(filePath, processName, 'window')
      })

      this.windowMonitor.on('file-unfocused', (filePath) => {
        this.handleFileClosed(filePath, 'window')
      })

      this.windowMonitor.start()
    }

    // Wave C.3: registered + work-present gated. The check is a no-op when
    // there are no active editing sessions. Listed in IntervalRegistry so
    // the Perf HUD can show whether it actually ticks.
    const { intervalRegistry } = require('./perf/IntervalRegistry')
    intervalRegistry.set('editing-detector.inactive-sweep', 30_000, () => {
      if (this.sessions.size === 0) return
      this.checkInactiveSessions()
    })
  }

  /**
   * Stop all monitors
   */
  stop(): void {
    console.log('[EditingDetector] Stopping editing detection')

    this.handleMonitor?.stop()
    this.handleMonitor = null

    this.windowMonitor?.stop()
    this.windowMonitor = null

    const { intervalRegistry } = require('./perf/IntervalRegistry')
    intervalRegistry.clear('editing-detector.inactive-sweep')

    if (this.inactivityTimer) {
      clearInterval(this.inactivityTimer)
      this.inactivityTimer = null
    }

    // Clear all grace timers
    for (const timer of this.unfocusGraceTimers.values()) {
      clearTimeout(timer)
    }
    this.unfocusGraceTimers.clear()

    // Close all active sessions
    for (const [normalizedPath, session] of this.sessions) {
      this.closeSession(normalizedPath)
    }
    this.sessions.clear()
  }

  /**
   * Register resolved watch folder paths so handle/window events can be
   * routed to the correct watch folder context automatically.
   * Called by WatchFolderService after starting watchers.
   */
  registerWatchFolderPaths(paths: Map<string, WatchFolderContext>): void {
    this.watchFolderPathMap = new Map()
    const folderPaths: string[] = []

    for (const [folderPath, ctx] of paths) {
      this.watchFolderPathMap.set(path.normalize(folderPath).toLowerCase(), ctx)
      folderPaths.push(folderPath)
    }

    // Feed the paths to both monitors so they scan watch folders too
    if (this.handleMonitor) {
      this.handleMonitor.setAdditionalFolders(folderPaths)
    }
    if (this.windowMonitor) {
      this.windowMonitor.setAdditionalFolders(folderPaths)
    }

    console.log(`[EditingDetector] Registered ${paths.size} watch folder paths for handle/window monitoring`)
  }

  /**
   * Look up watch folder context for a file path
   */
  private findWatchFolderContext(filePath: string): WatchFolderContext | null {
    const normalized = path.normalize(filePath).toLowerCase()
    for (const [folderPath, ctx] of this.watchFolderPathMap) {
      if (normalized.startsWith(folderPath + path.sep) || normalized.startsWith(folderPath + '/')) {
        return ctx
      }
    }
    return null
  }

  /**
   * Called by FileWatcher when an Office lock file is detected
   */
  onLockFileDetected(filePath: string, processName: string = 'Office'): void {
    this.handleFileOpened(filePath, processName, 'lockfile')
  }

  /**
   * Called by FileWatcher when an Office lock file is removed
   */
  onLockFileRemoved(filePath: string): void {
    this.handleFileClosed(filePath, 'lockfile')
  }

  /**
   * Called by WatchFolderService when a lock file is detected in a watched folder
   */
  onWatchFolderFileOpened(filePath: string, processName: string, context: WatchFolderContext): void {
    const normalizedPath = this.normalizePath(filePath)
    const filename = path.basename(filePath)

    if (shouldIgnoreFile(filename)) return

    let session = this.sessions.get(normalizedPath)
    if (session) {
      session.lastActivity = Date.now()
      return
    }

    session = {
      filePath,
      filename,
      folderId: null,
      processName,
      source: 'lockfile',
      startTime: Date.now(),
      lastActivity: Date.now(),
      activeSources: new Set(['lockfile']),
      watchFolderContext: context,
    }

    this.sessions.set(normalizedPath, session)
    console.log(`[EditingDetector] Watch folder file opened: ${filename} (${context.clientName})`)

    if (this.timeTracker) {
      this.timeTracker.documentOpenedWithClient(
        filePath,
        filename,
        context.clientId,
        context.clientName,
        context.boardId,
        context.cardId,
        'local_watch'
      )
    }

    this.emit('editing-started', filePath, processName, 'lockfile')
  }

  /**
   * Handle file opened from any source
   */
  private handleFileOpened(
    filePath: string,
    processName: string,
    source: 'lockfile' | 'handle' | 'window'
  ): void {
    const normalizedPath = this.normalizePath(filePath)
    const filename = path.basename(filePath)

    // Skip files that should be ignored (logs, temp files, etc.)
    if (shouldIgnoreFile(filename)) {
      console.log(`[EditingDetector] Ignoring file (filtered): ${filename}`)
      return
    }

    // Check if we already have a session for this file
    let session = this.sessions.get(normalizedPath)

    // Cancel any pending grace timer for this file (user came back)
    const pendingTimer = this.unfocusGraceTimers.get(normalizedPath)
    if (pendingTimer) {
      clearTimeout(pendingTimer)
      this.unfocusGraceTimers.delete(normalizedPath)
      console.log(`[EditingDetector] Cancelled grace timer - user returned to: ${filename}`)
    }

    if (session) {
      // Session exists - add this source
      if (!session.activeSources.has(source)) {
        session.activeSources.add(source)
        console.log(`[EditingDetector] Added source '${source}' to existing session: ${filename}`)
        console.log(`[EditingDetector] Active sources: ${Array.from(session.activeSources).join(', ')}`)
      }

      // Update last activity
      session.lastActivity = Date.now()

      // Don't notify time tracker again - already tracking
      return
    }

    // Check if this file is inside a registered watch folder
    const watchCtx = this.findWatchFolderContext(filePath)

    if (watchCtx) {
      // Route through watch folder path -- reuse onWatchFolderFileOpened logic
      session = {
        filePath,
        filename,
        folderId: null,
        processName,
        source,
        startTime: Date.now(),
        lastActivity: Date.now(),
        activeSources: new Set([source]),
        watchFolderContext: watchCtx,
      }

      this.sessions.set(normalizedPath, session)

      console.log(`[EditingDetector] ========== WATCH FOLDER FILE OPENED ==========`)
      console.log(`[EditingDetector] File: ${filename}`)
      console.log(`[EditingDetector] Process: ${processName}`)
      console.log(`[EditingDetector] Source: ${source}`)
      console.log(`[EditingDetector] Client: ${watchCtx.clientName}`)
      console.log(`[EditingDetector] ================================================`)

      if (this.timeTracker) {
        this.timeTracker.documentOpenedWithClient(
          filePath,
          filename,
          watchCtx.clientId,
          watchCtx.clientName,
          watchCtx.boardId,
          watchCtx.cardId,
          'local_watch'
        )
      }

      this.emit('editing-started', filePath, processName, source)
      return
    }

    // Standard sync folder path
    const relativePath = path.relative(this.syncFolder, path.dirname(filePath))
    const folderId = this.syncEngine?.getFolderIdForPath(relativePath) || null

    session = {
      filePath,
      filename,
      folderId,
      processName,
      source,
      startTime: Date.now(),
      lastActivity: Date.now(),
      activeSources: new Set([source]),
    }

    this.sessions.set(normalizedPath, session)

    console.log(`[EditingDetector] ========== FILE OPENED ==========`)
    console.log(`[EditingDetector] File: ${filename}`)
    console.log(`[EditingDetector] Process: ${processName}`)
    console.log(`[EditingDetector] Source: ${source}`)
    console.log(`[EditingDetector] Folder ID: ${folderId}`)
    console.log(`[EditingDetector] ================================`)

    if (this.timeTracker) {
      this.timeTracker.documentOpened(filePath, filename, folderId)
    }

    if (this.syncEngine) {
      this.syncEngine.setFileEditingStatus(filename, relativePath || null, true)
    }

    this.emit('editing-started', filePath, processName, source)
  }

  /**
   * Handle file closed from a specific source
   */
  private handleFileClosed(filePath: string, source: 'lockfile' | 'handle' | 'window'): void {
    const normalizedPath = this.normalizePath(filePath)
    const session = this.sessions.get(normalizedPath)

    if (!session) {
      // No session for this file - ignore
      return
    }

    // Remove this source
    session.activeSources.delete(source)

    console.log(`[EditingDetector] Source '${source}' closed for: ${session.filename}`)
    console.log(`[EditingDetector] Remaining sources: ${Array.from(session.activeSources).join(', ') || 'none'}`)

    // If no more sources report the file as open, close the session
    if (session.activeSources.size === 0) {
      // For WINDOW source, use a grace period before closing
      // This prevents on/off flickering when briefly switching apps
      if (source === 'window') {
        // Cancel any existing grace timer for this file
        const existingTimer = this.unfocusGraceTimers.get(normalizedPath)
        if (existingTimer) {
          clearTimeout(existingTimer)
        }

        console.log(`[EditingDetector] Starting ${this.UNFOCUS_GRACE_PERIOD / 1000}s grace period for: ${session.filename}`)

        // Start grace period timer
        const graceTimer = setTimeout(() => {
          this.unfocusGraceTimers.delete(normalizedPath)
          const currentSession = this.sessions.get(normalizedPath)
          // Only close if session still exists and still has no active sources
          if (currentSession && currentSession.activeSources.size === 0) {
            console.log(`[EditingDetector] Grace period expired, closing: ${session.filename}`)
            this.closeSession(normalizedPath)
          }
        }, this.UNFOCUS_GRACE_PERIOD)

        this.unfocusGraceTimers.set(normalizedPath, graceTimer)
      } else {
        // For lockfile/handle sources, close immediately
        this.closeSession(normalizedPath)
      }
    }
  }

  /**
   * Close a session and notify time tracker
   */
  private closeSession(normalizedPath: string): void {
    const session = this.sessions.get(normalizedPath)
    if (!session) return

    const duration = Math.round((Date.now() - session.startTime) / 1000)

    console.log(`[EditingDetector] ========== FILE CLOSED ==========`)
    console.log(`[EditingDetector] File: ${session.filename}`)
    console.log(`[EditingDetector] Duration: ${duration}s`)
    console.log(`[EditingDetector] ================================`)

    // Notify time tracker
    if (this.timeTracker) {
      this.timeTracker.documentClosed(session.filePath)
    }

    // Fire watch session end callback for file-activity notification
    if (session.watchFolderContext && this.onWatchSessionEnd) {
      this.onWatchSessionEnd({
        filePath: session.filePath,
        filename: session.filename,
        durationSeconds: duration,
        watchFolderId: session.watchFolderContext.watchFolderId,
        clientId: session.watchFolderContext.clientId,
        boardId: session.watchFolderContext.boardId,
        cardId: session.watchFolderContext.cardId,
        profileName: session.watchFolderContext.profileName,
      })
    }

    // Notify sync engine (only for synced folder files, not watch folders)
    if (!session.watchFolderContext && this.syncEngine) {
      const relativePath = path.relative(this.syncFolder, path.dirname(session.filePath))
      this.syncEngine.setFileEditingStatus(session.filename, relativePath || null, false)
    }

    // Remove session
    this.sessions.delete(normalizedPath)

    // Emit event
    this.emit('editing-ended', session.filePath, session.processName, duration)
  }

  /**
   * Check for inactive sessions and close them
   */
  private checkInactiveSessions(): void {
    const now = Date.now()

    for (const [normalizedPath, session] of this.sessions) {
      // If only window monitor is tracking and file is inactive, close it
      // (Window monitor loses track when user switches apps)
      if (session.activeSources.size === 1 &&
        session.activeSources.has('window') &&
        now - session.lastActivity > this.config.inactivityTimeout) {
        console.log(`[EditingDetector] Closing inactive window-only session: ${session.filename}`)
        this.closeSession(normalizedPath)
      }
    }
  }

  /**
   * Normalize file path for consistent map keys
   */
  private normalizePath(filePath: string): string {
    return path.normalize(filePath).toLowerCase()
  }

  /**
   * Get current editing sessions (for UI)
   */
  getActiveSessions(): Array<{
    filename: string
    processName: string
    source: string
    duration: number
    folderId: number | null
    watchFolder: { watchFolderId: number; clientId: number; clientName: string; boardId: number | null; boardName: string | null; cardId: number | null } | null
  }> {
    const now = Date.now()

    return Array.from(this.sessions.values()).map(session => ({
      filename: session.filename,
      processName: session.processName,
      source: Array.from(session.activeSources).join('+'),
      duration: Math.round((now - session.startTime) / 1000),
      folderId: session.folderId,
      watchFolder: session.watchFolderContext ? {
        watchFolderId: session.watchFolderContext.watchFolderId,
        clientId: session.watchFolderContext.clientId,
        clientName: session.watchFolderContext.clientName,
        boardId: session.watchFolderContext.boardId,
        boardName: session.watchFolderContext.boardName,
        cardId: session.watchFolderContext.cardId,
      } : null,
    }))
  }

  /**
   * Check if a file is currently being edited
   */
  isFileBeingEdited(filePath: string): boolean {
    const normalizedPath = this.normalizePath(filePath)
    return this.sessions.has(normalizedPath)
  }

  /**
   * Manually stop tracking a file (user action)
   */
  stopTracking(filename: string): boolean {
    for (const [normalizedPath, session] of this.sessions) {
      if (session.filename === filename || normalizedPath.endsWith(filename.toLowerCase())) {
        this.closeSession(normalizedPath)
        return true
      }
    }
    return false
  }
}

